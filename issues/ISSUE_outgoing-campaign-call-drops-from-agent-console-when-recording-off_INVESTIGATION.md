# Investigation: Outgoing campaign call disappears from the agent console the instant it connects (queue recording off)

| | |
|---|---|
| **Status** | **FIXED on the live system** 2026-09-01 01:20 (`/opt/issabel/dialer/AMIEventProcess.class.php`, backup `.bak-20260901-localopt`). Repo copy and `CHANGES.md` **not** updated. Path audit in §7.2 found and corrected a blind-transfer conflict before applying. |
| **Severity** | High — the call is live but the dialer thinks it ended: console goes idle, `current_calls` row deleted, `calls` row written as `ShortCall` / `duration 0`, campaign stats never see the call, agent looks free to the predictor |
| **Observed** | 2026-09-01 00:33:40, 00:34:32 and 00:37:48 (3 for 3 — every outgoing campaign call in the session) |
| **Investigated** | 2026-09-01 |
| **Trigger** | Outgoing campaign whose queue has **call recording disabled** |
| **Components** | `setup/dialer_process/dialer/AMIEventProcess.class.php` (`msg_Hangup`), `Llamada.class.php` (`llamadaFinalizaSeguimiento`), `modules/agent_console/index.php` (bar state) |

> **Evidence provenance.** Everything below is re-derivable, today, from `/opt/issabel/dialer/dialerd.log`
> (dialer restarted 00:22:37, PID 3859) and `/var/log/asterisk/full`. Line numbers are as of
> 2026-09-01 00:49. Both files rotate — preserve this document, not the logs.

---

## 1. Observation Summary

On an outgoing campaign, the agent console's status bar turns **green** ("Connected to call") for a
fraction of a second and then falls back to **blue** ("No active call"), while the agent and the
customer keep talking normally.

Reported to happen **only when call recording is disabled** on the queue attached to the outgoing
campaign. That correlation is real and is the key to the whole thing (§5).

Worst case measured: occurrence 3 — the dialer declared the call over at **00:37:48**; the two parties
stayed bridged until **00:46:32**. **8 min 44 s** of live conversation that the dialer recorded as a
0-second `ShortCall`.

## 2. Reproduction

Deterministic — it reproduced on 3 of 3 attempts.

1. Outgoing campaign `test` (id 1) pointing at queue **502**.
2. Queue 502 with recording **off** — `asterisk.queues_details` has `monitor-format` = `''` for id 502
   (queue 501, used for the incoming control call, has `monitor-format` = `wav`).
3. Log an agent in (static `Agent/1001` on `SIP/101`), start the campaign, answer the call on the
   customer handset.
4. Bar goes green, then blue within the same second. Audio keeps working.

## 3. Root cause

### 3.1 The mechanism in one line

Asterisk **optimises the `Local` channel pair out of the bridge** as soon as the agent answers. Both
halves hang up. The `;1` half carries the *call's own* `Uniqueid`, so `msg_Hangup()` matches it against
the tracked call and finalises a call that is still up.

### 3.2 The sequence

The dialer originates the campaign call as `Local/<number>@from-internal`. So for the whole call the
dialer's tracked `uniqueid` **is the Local channel's uniqueid** — e.g. `1788212017.23` is
`Local/0100100102@from-internal-00000000;1`, while the real customer channel is
`SIP/120Issabel4-00000003` (captured separately into `Llamada::actualchannel`).

When the agent answers, Asterisk collapses the Local pair and swaps the real trunk channel into the
conversation bridge:

```
/var/log/asterisk/full:64248
[00:33:40] bridge.c: Move-swap optimizing Local/0100100102@from-internal-00000000;1 <-- SIP/120Issabel4-00000003.
[00:33:40] Channel SIP/120Issabel4-00000003 swapped with Local/0100100102@from-internal-00000000;1 into 'simple_bridge' basic-bridge <b561ac9b-...>
[00:33:40] Executing [h@from-internal:1] Hangup("Local/0100100102@from-internal-00000000;2", "")
[00:33:40] Executing [h@from-internal:1] Hangup("Local/0100100102@from-internal-00000000;1", "")
```

Both Local halves hang up with `Cause 16 Normal Clearing`. **The call is not over** — the media path is
now `SIP/120Issabel4-00000003` ↔ `SIP/101-00000002` in bridge `b561ac9b-…`, and Asterisk keeps the queue
member `INUSE`: the next `QueueMemberStatus` for `Agent/1001` (Status=1, NOT_INUSE) does not arrive until
the customer really hangs up.

### 3.3 Where the dialer goes wrong

`setup/dialer_process/dialer/AMIEventProcess.class.php:3249-3278` — the `Local/` guard at the top of
`msg_Hangup()`:

```php
if (strpos($params['Channel'], 'Local/')===0) {
    // Normally Local channel hangups are ignored because the real trunk
    // channel hangup handles cleanup. However, when the trunk fails
    // (e.g., CHANUNAVAIL) but the dialplan routes the call to a queue
    // where an agent answers, the Local channel hangup is the only
    // event that can finalize the call.
    $bLocalTracked = FALSE;
    if (!is_null($this->_listaLlamadas->buscar('uniqueid', $params['Uniqueid']))) {
        $bLocalTracked = TRUE;                                   // <-- always true here
    } elseif (...agent callback channel guard...) {
    } elseif (...actualchannel...) {
    }
    if (!$bLocalTracked) { ...ignore...; return FALSE; }
    $this->_log->output('DEBUG: '.__METHOD__.
        ': Local channel hangup matches tracked call, processing normally'. ...);
}
```

The exception was written for a **pre-answer** failure (trunk `CHANUNAVAIL`, dialplan falls through to a
queue) where the Local hangup really is the only completion event. But the test it uses —
*"does a tracked call have this Uniqueid?"* — is true for **every** outgoing campaign call, at every
moment of its life, because the campaign call *is* originated on a Local channel. The optimisation
hangup passes the test and drops straight into `_procesarLlamadaColgada()`.

There is a sibling guard right below it (`preg_match('/^Local\/\d+@agents/')`) that already protects the
agent-side Local pair — `Local/1001@agents-…;1` is optimised out in exactly the same way at the same
moment, and *is* correctly ignored. The customer-side Local channel has no equivalent guard.

### 3.4 Raw evidence — occurrence 1, uniqueid `1788212017.23`

```
dialerd.log:10646  00:33:38  _asignarCanalRemotoReal: captured real remote channel: SIP/120Issabel4-00000003
dialerd.log:11116  00:33:40  msg_AgentConnect                                   <- queue connects the agent
dialerd.log:11195  00:33:40  msg_Link: call lookup result: llamada=FOUND(uid=1788212017.23) ... sChannel=Agent/1001
                             -> llamadaEnlazadaAgente()  -> AgentLinked  -> console GREEN
dialerd.log:11291  00:33:40  msg_Hangup: ignoro hangup local                    <- the ;2 half, correctly ignored
dialerd.log:11292  00:33:40  msg_AgentComplete   (Reason: caller, TalkTime: 0)
dialerd.log:11396  00:33:40  msg_Hangup: Local channel hangup matches tracked call, processing normally
                             | uniqueid=1788212017.23 channel=Local/0100100102@from-internal-00000000;1
dialerd.log:11446  00:33:40  _sqlupdatecalls: status => ShortCall, duration => 0.017386913299561
                             -> AgentUnlinked -> console BLUE
```

Elapsed green-to-blue: `0.0174 s` — the "fraction of a second".

`msg_AgentComplete` is a red herring: it only forwards to `QueueShadow` (statistics) and does not
finalise anything. The single fatal event is the `Local/...;1` Hangup.

### 3.5 The dialer itself notices, 5 minutes later

Occurrence 3, during the periodic `Agents` reconciliation:

```
dialerd.log:19889  00:42:38  WARN: AMIEventProcess::msg_AgentsComplete agente Agent/1001 en llamada
                             con canal SIP/120Issabel4-00000006 pero no hay (todavía) llamada monitoreada.
                             | EN: agent Agent/1001 on call with channel SIP/120Issabel4-00000006 but
                             there is no (yet) monitored call.
```

Asterisk says the agent is talking to `SIP/120Issabel4-00000006`; the dialer has no call for them. It
logs the inconsistency and does nothing about it. That call ran until 00:46:32.

## 4. Consequences beyond the status bar

Everything `Llamada::llamadaFinalizaSeguimiento()` does runs ~17 ms into the call:

| Effect | Where |
|---|---|
| `current_calls` row deleted → call vanishes from the Outgoing Campaign Panel | `Llamada.class.php` `llamadaFinalizaSeguimiento()` |
| `calls.status = 'ShortCall'`, `duration = 0`, `end_time = start_time` | `Llamada.class.php:1229-1232` (`duration 0.017 <= llamada_corta 1`) |
| `campania->actualizarEstadisticas()` **skipped** — the call never enters campaign statistics | `Llamada.class.php:1236` (else-branch only) |
| `AgentUnlinked` ECCP event with the short-call flag → console bar to blue, call info panel cleared | `Llamada.class.php:1252-1259` → `modules/agent_console/index.php:1929` |
| The `Agente` object loses its `llamada` → the predictor sees the agent as free while they are still talking | `Agente::asignarLlamadaAtendida()` unwound |
| The real `SIP/…` Hangup arrives later and matches nothing — no correction ever happens | `dialerd.log` 00:34:35, `msg_Hangup` for `SIP/120Issabel4-00000004`, no further processing |

Database state after the three test calls:

```
mysql> SELECT id,status,start_time,end_time,duration FROM call_center.calls;
1  ShortCall  00:33:40  00:33:40  0
2  ShortCall  00:34:32  00:34:32  0
3  ShortCall  00:37:48  00:37:48  0     <- the real call lasted 8m44s
```

## 5. Why recording hides the bug

`[sub-record-check]` decides recording per queue. With recording off the recorder Gosub is skipped:

```
/var/log/asterisk/full:64216
[00:33:39] Executing [q@sub-record-check:1] GosubIf("Local/0100100102@from-internal-00000000;1",
                                                    "0?recq,1(q,502,0100100102)")
```

The `0?` is the whole story. With recording **on** the same line reads `1?…` and
`extensions_additional.conf:2083` runs

```
exten => recq,n,MixMonitor(${MONITOR_FILENAME}.${MIXMON_FORMAT},${MONITOR_OPTIONS},${MIXMON_POST})
```

**on that same `Local/<number>@from-internal-XXXXXXXX;1` channel** — precisely the channel Asterisk would
otherwise swap out. Asterisk will not optimise a Local channel out of a bridge while it carries an
audiohook: the audio has to keep flowing through the channel for the recorder to tap it. The Local pair
therefore stays in the media path for the whole call, no Local `Hangup` arrives while the call is up, and
the console stays green.

Confidence split, stated honestly:

- **Proven in this log:** recording off → `Move-swap optimizing` → premature finalisation, 3 occurrences
  out of 3. Queue 502 `monitor-format` empty, `GosubIf` takes the `0?` branch each time
  (`full:64216`, `full:64458`, `full:64718`).
- **Inferred:** that MixMonitor's audiohook is what suppresses the optimisation. This log contains no
  outgoing campaign call *with* recording on to compare against (the only recorded call in it, 00:32:55
  queue 501, is inbound and never has a Local channel on the customer leg). It matches standard Asterisk
  behaviour and matches the operator's report exactly. Cheap confirmation is in §8.

Note this also means **incoming campaigns are unaffected**: the customer arrives on a real channel and
never gets a customer-side Local pair. The agent-side pair is already guarded.

## 6. Why the "positive control" call behaves

The inbound call at 00:32:55 (queue 501, recording on) shows the intended shape — `AgentConnect` 00:33:05,
`Link` 00:33:05, and one `Hangup` at 00:33:07 when the caller really hung up. Nothing spurious.

## 7. Proposed fix

**One guard, in the `Local/` block of `msg_Hangup()`**, between the `!$bLocalTracked` early return and the
"processing normally" log (`AMIEventProcess.class.php:3274-3277`).

The discriminator: a bridge optimisation only ever happens **after** both sides are bridged, and it leaves
the call's real (non-Local) `actualchannel` alive. A genuine "the Local channel is all there is" hangup —
the `CHANUNAVAIL` case the original exception was written for — has no real `actualchannel` and no link
yet. And a hangup during a transfer must keep going to the `transfer_pending` dispatch below (§7.2).

```php
            if (!$bLocalTracked) {
                $this->_log->output('DEBUG: '.__METHOD__.': ignoro hangup local | EN: ignoring local hangup');
                return FALSE;
            }

            /* Asterisk saca el par de canales Local del puente en cuanto ambos
             * lados quedan enlazados ("bridge.c: Move-swap optimizing
             * Local/<num>@from-internal-XXXXXXXX;1 <-- SIP/<troncal>-YYYYYYYY").
             * Las dos patas cuelgan entonces con Cause 16 mientras el canal real
             * del troncal sigue conversando con el agente. En una llamada de
             * campaña saliente marcada por plan de marcado la pata ;1 lleva el
             * Uniqueid de la llamada misma, así que la comprobación de arriba la
             * reconoce y la llamada se daría por terminada estando todavía en
             * curso. Si la llamada está enlazada a un agente, no está en medio de
             * una transferencia, y tiene un canal real (no Local) distinto del que
             * cuelga, esto es la optimización y no el fin de la llamada: se ignora.
             * El Hangup del canal real la finaliza después, encontrado por el
             * índice actualchannel más abajo. */
            /* EN: Asterisk optimizes the Local channel pair out of the bridge as
             * soon as both sides are linked ("bridge.c: Move-swap optimizing
             * Local/<num>@from-internal-XXXXXXXX;1 <-- SIP/<trunk>-YYYYYYYY").
             * Both halves then hang up with Cause 16 while the real trunk channel
             * keeps talking to the agent. On an outgoing campaign call dialed
             * through the dialplan the ;1 half carries the call's own Uniqueid, so
             * the check above matches it and the call would be finalized while it
             * is still up. If the call is linked to an agent, is not in the middle
             * of a transfer, and has a real (non-Local) channel other than the one
             * hanging up, this is the optimization and not the end of the call:
             * ignore it. The real channel's own Hangup finalizes the call later,
             * matched by the actualchannel index below. */
            $llamadaLocal = $this->_listaLlamadas->buscar('uniqueid', $params['Uniqueid']);
            if (!is_null($llamadaLocal)
                    && !$llamadaLocal->transfer_pending
                    && !is_null($llamadaLocal->agente)
                    && !is_null($llamadaLocal->timestamp_link)
                    && !is_null($llamadaLocal->actualchannel)
                    && strpos($llamadaLocal->actualchannel, 'Local/') !== 0
                    && $llamadaLocal->actualchannel != $params['Channel']) {
                $this->_log->output('DEBUG: '.__METHOD__.
                    ': Local channel optimized out of the bridge, call continues on '.
                    $llamadaLocal->actualchannel.', ignoring hangup'.
                    ' | uniqueid='.$params['Uniqueid'].' channel='.$params['Channel'].
                    ' | ES: canal Local optimizado fuera del puente, la llamada sigue en '.
                    $llamadaLocal->actualchannel.', se ignora el hangup');
                return FALSE;
            }

            $this->_log->output('DEBUG: '.__METHOD__.
                ': Local channel hangup matches tracked call, processing normally'. ...);
```

`php -l` clean against `AMIEventProcess.class.php` (PHP 7.4.33). All four properties resolve:
`transfer_pending`, `agente`, `timestamp_link` are public `var`s (`Llamada.class.php:151, 38, 164`),
`actualchannel` comes from `__get()` (`Llamada.class.php:279`).

### 7.1 Scope of the defect (narrower than it first looks)

The Local dial string is only used when the campaign has **no pinned trunk** —
`CampaignProcess::_construirPlantillaMarcado()` returns `Local/$OUTNUM$@from-internal` in that case, and
`SIP/<trunk>/<number>`, `PJSIP/…`, `IAX2/…`, `DAHDI/…` otherwise
(`CampaignProcess.class.php:1628-1660`). With a pinned trunk there is no Local channel at all,
`channel == actualchannel`, and neither the bug nor the guard can fire. Scheduled/manual calls share
`_ejecutarOriginate()` and therefore share both the defect and the fix.

### 7.2 Path audit — every branch below the guard

The guard sits above the whole dispatch in `msg_Hangup()`, so each branch it could shadow was checked.
**One of them was a genuine conflict and forced the `transfer_pending` term in the condition.**

| Path below the guard | Reachable when the guard fires? | Verdict |
|---|---|---|
| **Blind transfer, `transfer_pending && agente != NULL` → `llamadaTransferidaDesdeAgente()`** (`3321-3330`) | **YES — was a real conflict.** `Llamada.class.php:1140-1142` states outright that a Local channel hangup arrives for outgoing calls during a transfer, and this branch is the safety net that releases the source agent when the hangup beats the console's `_finalizarTransferencia()`. Without the `transfer_pending` term the guard would swallow it and strand the source agent "on call". | **Fixed by `!$llamadaLocal->transfer_pending`.** With that term the branch is reached exactly as today. |
| Blind transfer, customer channel hangup (`3331-3338`) | No — requires `Channel == actualchannel`; the guard requires them to differ. | Unreachable overlap |
| Blind transfer, intermediate channel (`3339-3348`) | No — `transfer_pending` excluded. | Unaffected |
| **Attended-transfer consultation** → `_manejarHangupLoginChannelEnConsulta()` (`3311-3315`) | No. That branch needs `$a` from `_listaAgentes->buscar('uniqueidlink', …)`, which is only consulted **when the uniqueid lookup returns NULL** (`3282-3296`). The guard requires the same lookup to **succeed**. Mutually exclusive. | Unaffected |
| `_procesarLlamadaColgada()` → `OnHold`, parked caller hung up (`3403-3437`) | No — that sub-branch requires `Channel == actualchannel`; the guard requires them to differ. When the guard fires, the existing code takes the sibling "ignoring Hangup for call being sent to HOLD" branch. | Identical outcome |
| `_procesarLlamadaColgada()` → `timestamp_link IS NULL`, pre-answer failure (the original `CHANUNAVAIL` exception) | No — the guard requires `timestamp_link` **and** a non-NULL non-Local `actualchannel`. In that scenario the trunk channel never existed, so `actualchannel` is NULL (`Llamada.class.php:419-421, 700`). | Preserved |
| `_terminarConsultaSiClienteCuelga()` (`3441`) | No — it means "the customer hung up", but the guard only fires when the customer's real channel is *not* the one hanging up. | Correct to skip |
| Auxiliary-channel failure branch (`3352-3372`) | No — only reached when no call matched at all. | Unaffected |
| `if ($this->_finalizandoPrograma) $this->_verificarFinalizacionLlamadas()` | Skipped by the `return FALSE`, exactly as the existing "ignoro hangup local" return does. Harmless: that function only completes shutdown once every agent is logged out **and** `_listaLlamadas` is empty, and the guard removes no call. | Consistent with existing ignore path |

### 7.3 Why the rest is safe

- **The call still finalises.** `msg_Hangup()` already falls back to
  `_listaLlamadas->buscar('actualchannel', $params['Channel'])` (`3299-3301`). Verified against the live
  log: the trunk's own Hangup arrives (`dialerd.log` 00:34:35) with
  `Channel => SIP/120Issabel4-00000004` — byte-identical to the string stored in `actualchannel` at
  00:34:29 (`dialerd.log:13135`). Step 1 (`uniqueid`) misses, step 2 (`uniqueidlink`) misses because that
  index holds the **agent leg's** uniqueid (`Agente.class.php:320-323`, `ListaAgentes.class.php:36`), and
  step 3 hits.
- **`actualchannel` cannot drift after the link.** `msg_Link` only assigns it when it is NULL (`3126-3127`);
  a later different candidate is logged as "remote channel conflict … ignored because it is after Link"
  and discarded (`3132-3144`). Confirmed in the log at `dialerd.log:11265`.
- **The guard fails safe.** The bridge join always precedes the optimisation, so `agente`/`timestamp_link`
  are set by the time the hangup arrives. If they somehow were not, the guard simply does not fire and the
  current behaviour applies — the failure direction is "finalise as today", never "leak a call".
- **Console operations keep working on the surviving channel.** Hangup from the console targets
  `infoLlamada['actualchannel']` (`ECCPConn.class.php:2443, 2541-2544`), hold/park uses
  `Llamada::asyncPark($this->actualchannel)` (`Llamada.class.php:1374-1382`), and the atxfer failure path
  uses `$llamada->actualchannel` (`AMIEventProcess.class.php:3510`).
- **No double-finalisation.** The post-swap `BridgeEnter`/`Link` for the trunk channel is already discarded
  by the existing conflict branch (`dialerd.log:11265`).
- **Backstop already exists.** If a trunk Hangup were ever lost, `_cleanOrphanedConnectedCalls()` sweeps
  `Success`/`OnHold` rows with `end_time IS NULL` older than 2 h (`CampaignProcess.class.php:1996-2017`),
  and the startup cleanup clears them unconditionally (`CampaignProcess.class.php:176-192`). A live call
  is far inside that window.

### 7.4 Intended behaviour changes (not regressions, but they will be visible)

- **`_countActiveCalls()` becomes accurate.** It counts `status='Success' AND end_time IS NULL`
  (`CampaignProcess.class.php:2019-2035`). Today a connected call leaves that set after ~17 ms, so the
  campaign under-counts its own live calls and can over-dial. After the fix the call counts for its real
  duration, so a campaign will place **fewer simultaneous calls** than it does today — the correct number.
  Related to `ISSUE_outgoing-campaign-calls-count-is-less-than-should`.
- **`msg_AgentsComplete` stops warning.** With the call retained, `actualchannel` matches Asterisk's
  `TalkingToChan`, so the consistency check at `AMIEventProcess.class.php:4252-4275` goes quiet.
- **Dialer shutdown waits for real calls.** `_verificarFinalizacionLlamadas()` holds shutdown until
  `_listaLlamadas` is empty. Today a recording-off campaign call empties it at connect; after the fix
  `systemctl stop issabeldialer` waits for the conversation to end, which is the documented intent
  ("waiting for all monitored calls to finish") and already the behaviour when recording is on.

### 7.5 Pre-existing weakness noticed, not introduced

The Asterisk-restart cleanup at `AMIEventProcess.class.php:200-215` passes `$llamada->channel` (the Local
name) into `_procesarLlamadaColgada()`. For an `OnHold` call the finalise test is
`Channel == actualchannel`, which that value never satisfies, so a parked call would not be finalised on
an Asterisk restart. This affects every dialplan-dialled campaign call today whenever recording is on; the
fix does not cause it, but it widens the window in which such a call exists. Worth a separate look.

### Optional hardening (separate change, not required for the fix)

`msg_AgentsComplete` already detects the mismatch it cannot currently repair
(`AMIEventProcess.class.php:4252-4275`, the WARN at `dialerd.log:19889`). Adopting the orphaned channel
back into `_listaLlamadas` there would be a genuine safety net for *any* future path that loses a call
mid-conversation. Out of scope here.

## 8. Test steps

1. Apply the guard, `systemctl restart issabeldialer` (no effect on active Asterisk calls).
2. **Recording off** (queue `monitor-format` empty) — run one outgoing campaign call, answer it, talk for
   ~20 s, hang up from the customer end.
   - Bar must stay **green** for the whole call and go blue only at the real hangup.
   - `dialerd.log` must show `Local channel optimized out of the bridge, call continues on SIP/…` and
     must **not** show `Local channel hangup matches tracked call` for that uniqueid.
   - `calls` row: `status = Hangup`, `duration ≈ 20`, `end_time` at the real hangup.
3. **Recording on** — repeat, confirm no regression (bar green throughout, `.wav` written, correct
   duration).
4. **Regression check for the original exception** — point the campaign at a trunk that returns
   `CHANUNAVAIL` and let the dialplan fall through to a queue. The call must still finalise; nothing may
   hang in `current_calls`.
5. **Console operations mid-call, recording off** — press Hangup on the console; verify the call ends
   (this exercises `actualchannel` after the Local pair is gone). Same for Hold and Transfer.
6. **Blind transfer — the branch the audit in §7.2 nearly broke. Test with recording ON, where the
   Local pair is still in the path at transfer time.** Agent A takes an outgoing campaign call, transfers
   it to agent B.
   - Agent A must be released and go idle; agent B must pick the call up.
   - `dialerd.log` must show `hangup during transfer - using lightweight release` (or the transfer
     completing via `_finalizarTransferencia`) — it must **not** show
     `Local channel optimized out of the bridge` for that call while `transfer_pending` is set.
   - Neither agent may end up stuck "on call".
   - Repeat with recording off, and repeat as an attended transfer.

### Log collection

```bash
# Did Asterisk optimize the Local pair out, and when?
grep -nE "Move-swap optimizing|swapped with Local/" /var/log/asterisk/full

# Is queue recording on for this queue? (0? = off, 1? = on)
grep -n "recq,1(q,<QUEUE>" /var/log/asterisk/full

# The bug signature, per call
grep -nE "msg_AgentConnect|Local channel hangup matches tracked call|Local channel optimized out|ShortCall" \
    /opt/issabel/dialer/dialerd.log

# Full trace for one call
grep -n "<UNIQUEID>" /opt/issabel/dialer/dialerd.log /var/log/asterisk/full

# The dialer noticing the inconsistency after the fact
grep -n "pero no hay (todavía) llamada monitoreada" /opt/issabel/dialer/dialerd.log

# Damage in the database
mysql -uroot -p<pw> call_center -e \
  "SELECT id,status,start_time,end_time,duration FROM calls ORDER BY id DESC LIMIT 20;"
```

## 9. File and line reference (verified 2026-09-01)

| File | Lines | What |
|---|---|---|
| `setup/dialer_process/dialer/AMIEventProcess.class.php` | 3249-3278 | the `Local/` guard in `msg_Hangup()` — **the defect** |
| " | 3280-3301 | the three-step call lookup; 3299-3301 is the `actualchannel` fallback the fix relies on |
| " | 3390+ | `_procesarLlamadaColgada()` |
| " | 2461-2468 | `_asignarCanalRemotoReal()` — sets `actualchannel` from `Dial`'s `Destination` |
| " | 4349-4359 | `msg_AgentComplete()` — forwards to `QueueShadow` only, finalises nothing |
| " | 4252-4275 | the reconciliation that spots, but does not repair, the orphaned call |
| `setup/dialer_process/dialer/Llamada.class.php` | 419-421, 425-441 | `channel` / `actualchannel` setters |
| " | 885-989 | `llamadaEnlazadaAgente()` — sets `agente` + `timestamp_link` |
| " | 1155-1270 | `llamadaFinalizaSeguimiento()` — `ShortCall` branch (1229-1232) and `AgentUnlinked` (1252-1259) |
| `modules/agent_console/index.php` | 1797, 1842 | `agentlinked` / `agentunlinked` ECCP events |
| " | 1925-1933 | those events → `calltype` in `$estadoCliente` |
| " | 2037-2062 | `describirEstadoBarra()` |
| " | 1963-1990 | bar text + CSS class per state |
| `modules/agent_console/themes/default/css/issabel-callcenter.css` | 42-56 | `-activo` `#06640D` green, `-ocioso` `#094895` blue |
| `/etc/asterisk/extensions_additional.conf` | 2051, 2082-2086 | `[sub-record-check]` `q` / `recq`, `MixMonitor` |

## 10. Open items

- ~~Confirm the MixMonitor/audiohook half of §5 with one recorded outgoing campaign call.~~
  **CONFIRMED 2026-09-01 01:13:38** — queue 502 switched to `monitor-format=wav`, one campaign call:
  `full:65661` `GosubIf(... "1?recq,1(q,502,...)")` → `full:65663`
  `MixMonitor("Local/0100100102@from-internal-00000006;1", ".../q-502-...-011338-1788214416.219.wav,b,")`.
  **No `Move-swap optimizing` line for that call**, no `Local channel hangup matches tracked call`, and
  `calls` id 4 = `status Success, duration 16`. The audiohook on the `;1` half is what suppresses the
  optimisation. §5 is now proven, not inferred.
- Decide whether the §7 optional hardening in `msg_AgentsComplete` is worth doing on its own.
- The three `ShortCall` rows in `call_center.calls` from this session are bad data from the bug, not
  real short calls.
