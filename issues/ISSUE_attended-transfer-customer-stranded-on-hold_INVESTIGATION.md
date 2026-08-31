# Investigation: Attended transfer strands the customer on hold when the consult fails instantly

| | |
|---|---|
| **Status** | Diagnosed and **FIXED** - see `CHANGES_PRE.md` #70 (2026-08-30) |
| **Severity** | High — customer is left alone on Music On Hold for up to **30 minutes**, agent's call disappears from the console |
| **Observed** | 2026-08-30 01:41:12 and 01:43:52 (two back-to-back), plus "randomly a couple of times before" reported by the operator |
| **Investigated** | 2026-08-30 |
| **Fixed** | 2026-08-30, Option A generalised into a shared `[atxfer-rebridge]` Gosub - see §7 and §10 |
| **Affects** | `[cbxfer-consult]` (confirmed) and by inspection `[cbxfer-cancel-consult]`, `[atxfer-consult]`, `[atxfer-cancel-consult]` |
| **Components** | `/etc/asterisk/extensions_custom.conf`, `setup/installer.php`, `ECCPConn.class.php`, `AMIEventProcess.class.php` |

> **Evidence provenance.** `/opt/issabel/dialer/dialerd.log` was **cleared on 2026-08-30 (~12:52)**, so the
> dialer-side lines quoted in §4 are transcriptions taken during the investigation and can no longer be
> re-derived from the live log. Everything in §3, §5 and §6 was **re-extracted from `/var/log/asterisk/full`
> on 2026-08-30 13:0x** and is still verifiable there until that file rotates. Preserve this document; the
> logs are the ephemeral part.

---

## 1. Observation Summary

An agent on a callback-type login (`SIP/101`) is talking to an external customer. The agent starts an
**attended transfer** to a colleague. The consultation fails immediately (colleague's extension is not
registered). Instead of the agent being reconnected to the customer:

- the **agent's channel hangs up** — the call vanishes from the agent console;
- the **customer stays alive**, alone, inside `[atxfer-hold]`'s `MusicOnHold(,1800)`;
- nothing in the dialer reclaims them. They hear hold music for **1800 seconds** unless they hang up.

In the two captured occurrences the customer was only released because the test handset hung up
(after 10 s and 9 s respectively).

## 2. Reproduction

Deterministic-enough repro (fails roughly 2 times out of 3, see §5):

1. Make sure a colleague extension is **defined but not registered** — e.g. `SIP/103` with no phone on it.
   `DB(DEVICE/103/dial)` must still return a real device string (`SIP/103`), **not** empty.
2. Log an agent in as a **callback** agent (`SIP/101`) and take an inbound queue call from an external
   caller (through the trunk, so the held party is a trunk channel).
3. In the agent console press **Attended transfer** and enter `103`.
4. Watch: the consult `Dial()` fails instantly, the agent's leg hangs up, and the customer is left on MOH.

The essential ingredient is that `Dial()` fails **without allocating a channel and without any network
round trip** — see §5.

## 3. Root cause — Defect A: a thread race between the two halves of one AMI Redirect

### 3.1 The mechanism

`ECCPConn::transfer()` starts a callback attended transfer with a **single** AMI `Redirect` that carries an
`ExtraChannel` — `setup/dialer_process/dialer/ECCPConn.class.php:3536-3546`:

```php
            // Redirect both channels simultaneously:
            // - Agent's device channel -> cbxfer-consult (dials the colleague)
            // - External caller        -> atxfer-hold (music on hold)
            $r = $this->_ami->Redirect(
                $transferChannel,       // Channel: agent's SIP/PJSIP/IAX2 channel
                $clientChannel,         // ExtraChannel: external caller
                $sExtension,            // Exten: target extension number
                'cbxfer-consult',       // Context: callback consultation context
                1,                      // Priority
                's',                    // ExtraExten: hold context uses 's'
                'atxfer-hold',          // ExtraContext: caller MOH context
                1                       // ExtraPriority
            );
```

"Simultaneously" is the problem. Asterisk's `action_redirect` performs an **async goto on each channel
independently**; the two channels then run in **separate PBX threads with no synchronisation between
them**. Nothing guarantees that the customer has left the old bridge — let alone reached `[atxfer-hold]` —
by the time the agent's thread reaches its `Bridge()`.

The agent's side, `/etc/asterisk/extensions_custom.conf:94-108` (`[cbxfer-consult]`), runs 14 priorities.
The reconnect is priority 13:

```
[cbxfer-consult]
exten => _X.,1,NoOp(Issabel CallCenter: Callback attended transfer - consulting ${EXTEN})
 same => n,Set(__ATXFER_HELD_CHAN=${ATXFER_HELD_CHAN})            ; 2
 same => n,Set(AGENT_ID=${ATXFER_AGENT_ID})                       ; 3
 same => n,Set(CLEAN_EXTEN=${FILTER(0123456789,${EXTEN})})        ; 4
 same => n,ExecIf($["${CLEAN_EXTEN}" = ""]?Set(CLEAN_EXTEN=${EXTEN}))          ; 5
 same => n,Set(DIAL_DEVICE=${DB(DEVICE/${CLEAN_EXTEN}/dial)})                  ; 6
 same => n,ExecIf($["${DIAL_DEVICE:0:5}" = "PJSIP"]?Set(DIAL_DEVICE=${PJSIP_DIAL_CONTACTS(${CLEAN_EXTEN})}))  ; 7
 same => n,ExecIf($["${DIAL_DEVICE}" = ""]?Set(DIAL_DEVICE=Local/${CLEAN_EXTEN}@from-internal/n))             ; 8
 same => n,NoOp(Issabel CallCenter: Callback consult dial: ${DIAL_DEVICE})     ; 9
 same => n,Dial(${DIAL_DEVICE},120,gF(atxfer-bridge^s^1)U(cbxfer-consult-answered^${AGENT_ID}))  ; 10
 same => n,NoOp(... consultation ended DIALSTATUS=${DIALSTATUS} - reconnecting with caller)      ; 11
 same => n,UserEvent(ConsultationEnd,Agent: ${AGENT_ID},Status: ${DIALSTATUS})                   ; 12
 same => n,Bridge(${ATXFER_HELD_CHAN})                                                          ; 13  <-- races
 same => n,Hangup()                                                                             ; 14
```

The customer's side is only three priorities long, `extensions_custom.conf:33-38`:

```
[atxfer-hold]
exten => s,1,NoOp(Issabel CallCenter: Attended Transfer - Caller on hold)
 same => n,Answer()
 same => n,MusicOnHold(,1800)
 same => n,NoOp(Issabel CallCenter: Attended transfer hold expired - releasing caller)
 same => n,Hangup()
```

If the agent's `Dial()` at priority 10 returns **instantly**, priorities 11→14 execute in microseconds and
`Bridge()` fires while the customer is still inside the *old* bridge. `Bridge()` cannot add a channel that is
still in another bridge, so it returns immediately, the agent falls through to `Hangup()` at 14, and the
customer's thread — arriving a moment later — walks into `[atxfer-hold]` with nobody left to bridge to.

### 3.2 Raw evidence — FAILURE at 2026-08-30 01:41:12

`/var/log/asterisk/full`, lines 228401-228426. Thread `21271` = agent, thread `21267` = customer.
Read the thread ids and the ordering of the two `left 'simple_bridge'` lines:

```
[2026-08-30 01:41:12] VERBOSE[21271][C-00000002] bridge_channel.c: Channel SIP/101-00000002 left 'simple_bridge' basic-bridge <5466d439-e4fc-43c4-a893-268511d758b6>
[2026-08-30 01:41:12] VERBOSE[21271][C-00000002] pbx.c: Executing [103@cbxfer-consult:1] NoOp("SIP/101-00000002", "Issabel CallCenter: Callback attended transfer - consulting 103") in new stack
[2026-08-30 01:41:12] VERBOSE[21271][C-00000002] pbx.c: Executing [103@cbxfer-consult:2] Set("SIP/101-00000002", "__ATXFER_HELD_CHAN=SIP/120Issabel4-00000001") in new stack
[2026-08-30 01:41:12] VERBOSE[21271][C-00000002] pbx.c: Executing [103@cbxfer-consult:3] Set("SIP/101-00000002", "AGENT_ID=SIP/101") in new stack
[2026-08-30 01:41:12] VERBOSE[21271][C-00000002] pbx.c: Executing [103@cbxfer-consult:4] Set("SIP/101-00000002", "CLEAN_EXTEN=103") in new stack
[2026-08-30 01:41:12] VERBOSE[21271][C-00000002] pbx.c: Executing [103@cbxfer-consult:5] ExecIf("SIP/101-00000002", "0?Set(CLEAN_EXTEN=103)") in new stack
[2026-08-30 01:41:12] VERBOSE[21271][C-00000002] pbx.c: Executing [103@cbxfer-consult:6] Set("SIP/101-00000002", "DIAL_DEVICE=SIP/103") in new stack
[2026-08-30 01:41:12] WARNING[21271][C-00000002] pjsip/dialplan_functions.c: Specified endpoint '103' was not found
[2026-08-30 01:41:12] VERBOSE[21271][C-00000002] pbx.c: Executing [103@cbxfer-consult:7] ExecIf("SIP/101-00000002", "0?Set(DIAL_DEVICE=)") in new stack
[2026-08-30 01:41:12] VERBOSE[21271][C-00000002] pbx.c: Executing [103@cbxfer-consult:8] ExecIf("SIP/101-00000002", "0?Set(DIAL_DEVICE=Local/103@from-internal/n)") in new stack
[2026-08-30 01:41:12] VERBOSE[21271][C-00000002] pbx.c: Executing [103@cbxfer-consult:9] NoOp("SIP/101-00000002", "Issabel CallCenter: Callback consult dial: SIP/103") in new stack
[2026-08-30 01:41:12] VERBOSE[21271][C-00000002] pbx.c: Executing [103@cbxfer-consult:10] Dial("SIP/101-00000002", "SIP/103,120,gF(atxfer-bridge^s^1)U(cbxfer-consult-answered^SIP/101)") in new stack
[2026-08-30 01:41:12] NOTICE[21271][C-00000002] app_dial.c: Unable to create channel of type 'SIP' (cause 20 - Subscriber absent)
[2026-08-30 01:41:12] VERBOSE[21271][C-00000002] app_dial.c: Everyone is busy/congested at this time (1:0/0/1)
[2026-08-30 01:41:12] VERBOSE[21271][C-00000002] pbx.c: Executing [103@cbxfer-consult:11] NoOp("SIP/101-00000002", "Issabel CallCenter: Callback consultation ended DIALSTATUS=CHANUNAVAIL - reconnecting with caller") in new stack
[2026-08-30 01:41:12] VERBOSE[21271][C-00000002] pbx.c: Executing [103@cbxfer-consult:12] UserEvent("SIP/101-00000002", "ConsultationEnd,Agent: SIP/101,Status: CHANUNAVAIL") in new stack
[2026-08-30 01:41:12] VERBOSE[21271][C-00000002] pbx.c: Executing [103@cbxfer-consult:13] Bridge("SIP/101-00000002", "SIP/120Issabel4-00000001") in new stack
[2026-08-30 01:41:12] VERBOSE[21271][C-00000002] pbx.c: Executing [103@cbxfer-consult:14] Hangup("SIP/101-00000002", "") in new stack
[2026-08-30 01:41:12] VERBOSE[21271][C-00000002] pbx.c: Spawn extension (cbxfer-consult, 103, 14) exited non-zero on 'SIP/101-00000002'
[2026-08-30 01:41:12] VERBOSE[21267][C-00000002] bridge_channel.c: Channel SIP/120Issabel4-00000001 left 'simple_bridge' basic-bridge <5466d439-e4fc-43c4-a893-268511d758b6>
[2026-08-30 01:41:12] VERBOSE[21267][C-00000002] pbx.c: Executing [s@atxfer-hold:1] NoOp("SIP/120Issabel4-00000001", "Issabel CallCenter: Attended Transfer - Caller on hold") in new stack
[2026-08-30 01:41:12] VERBOSE[21267][C-00000002] pbx.c: Executing [s@atxfer-hold:2] Answer("SIP/120Issabel4-00000001", "") in new stack
[2026-08-30 01:41:12] VERBOSE[21267][C-00000002] pbx.c: Executing [s@atxfer-hold:3] MusicOnHold("SIP/120Issabel4-00000001", ",1800") in new stack
[2026-08-30 01:41:12] VERBOSE[21267][C-00000002] res_musiconhold.c: Started music on hold, class 'Primasoft', on channel 'SIP/120Issabel4-00000001'
[2026-08-30 01:41:22] VERBOSE[21267][C-00000002] res_musiconhold.c: Stopped music on hold on SIP/120Issabel4-00000001
[2026-08-30 01:41:22] VERBOSE[21267][C-00000002] pbx.c: Spawn extension (atxfer-hold, s, 3) exited non-zero on 'SIP/120Issabel4-00000001'
```

The whole agent side — 14 priorities, a failed `Dial()`, a `Bridge()` and a `Hangup()` — completes **before
the customer's channel has even left the old bridge**. The customer then sits in MOH alone; the run ends at
01:41:22 only because the far end hung up 10 seconds later.

The second occurrence at **01:43:52** (log lines 228555-228579) is byte-for-byte the same shape with
`SIP/101-00000004` / `SIP/120Issabel4-00000003`.

### 3.3 Raw evidence — the positive control, 2026-08-29 20:01:47

Same code, same instant `Dial()` failure, **opposite thread ordering, opposite outcome**
(`/var/log/asterisk/full` lines 208149-208176). Note the customer leaves the bridge **first** here:

```
[2026-08-29 20:01:47] VERBOSE[13756][C-00000035] bridge_channel.c: Channel PJSIP/PJSIP120Issabel4-00000176 left 'simple_bridge' basic-bridge <d70592a1-...>
[2026-08-29 20:01:47] VERBOSE[13782][C-00000038] bridge_channel.c: Channel PJSIP/102-0000017a left 'simple_bridge' basic-bridge <d70592a1-...>
...
[2026-08-29 20:01:47] VERBOSE[13782][C-00000038] pbx.c: Executing [103@cbxfer-consult:10] Dial("PJSIP/102-0000017a", "SIP/103,120,gF(...)U(...)") in new stack
[2026-08-29 20:01:47] NOTICE[13782][C-00000038] app_dial.c: Unable to create channel of type 'SIP' (cause 20 - Subscriber absent)
[2026-08-29 20:01:47] VERBOSE[13782][C-00000038] app_dial.c: Everyone is busy/congested at this time (1:0/0/1)
[2026-08-29 20:01:47] VERBOSE[13782][C-00000038] pbx.c: Executing [103@cbxfer-consult:12] UserEvent("PJSIP/102-0000017a", "ConsultationEnd,Agent: PJSIP/102,Status: CHANUNAVAIL") in new stack
[2026-08-29 20:01:47] VERBOSE[13782][C-00000038] pbx.c: Executing [103@cbxfer-consult:13] Bridge("PJSIP/102-0000017a", "PJSIP/PJSIP120Issabel4-00000176") in new stack
[2026-08-29 20:01:47] VERBOSE[13756][C-00000035] pbx.c: Executing [s@atxfer-hold:1] NoOp("PJSIP/PJSIP120Issabel4-00000176", "Issabel CallCenter: Attended Transfer - Caller on hold") in new stack
[2026-08-29 20:01:47] VERBOSE[13756][C-00000035] pbx.c: Executing [s@atxfer-hold:2] Answer("PJSIP/PJSIP120Issabel4-00000176", "") in new stack
[2026-08-29 20:01:47] VERBOSE[13756][C-00000035] pbx.c: Executing [s@atxfer-hold:3] MusicOnHold("PJSIP/PJSIP120Issabel4-00000176", ",1800") in new stack
[2026-08-29 20:01:47] VERBOSE[13756][C-00000035] pbx.c: Spawn extension (atxfer-hold, s, 3) exited non-zero on 'Surrogate/PJSIP/PJSIP120Issabel4-00000176'
[2026-08-29 20:01:47] VERBOSE[13788][C-00000038] bridge_channel.c: Channel PJSIP/PJSIP120Issabel4-00000176 joined 'simple_bridge' basic-bridge <42f99f55-3e51-487f-b4b0-dbab8270021b>
[2026-08-29 20:01:47] VERBOSE[13782][C-00000038] bridge_channel.c: Channel PJSIP/102-0000017a joined 'simple_bridge' basic-bridge <42f99f55-...>
```

The `Surrogate/...` line is the signature of a **successful** reconnect: `Bridge()` yanked the customer
straight out of `MusicOnHold` and both channels joined a new `simple_bridge`.

A third variant at **2026-08-28 20:56:28** shows the agent leaving the bridge first *and still succeeding*,
because there `Dial(Local/102@from-internal/n)` **did** allocate a channel — that extra work gave the
customer's thread the microseconds it needed to reach `[atxfer-hold]` before priority 13.

### 3.4 What `Bridge()` actually returned — and why the log is silent

Extracted from `/usr/sbin/asterisk`, `bridge_exec` sets `BRIDGERESULT` to one of four values and only
**two of them print anything**:

| `BRIDGERESULT` | log message |
|---|---|
| `NONEXISTENT` | `features.c: Bridge failed because channel '%s' does not exist` |
| `LOOP` | `Unable to bridge channel %s with itself` |
| `FAILURE` | *(silent)* |
| `SUCCESS` | *(silent)* |

`/var/log/asterisk/full` contains exactly **8** `Bridge failed because channel ... does not exist` lines and
**none of them is at 01:41:12 or 01:43:52**. The customer channel demonstrably existed. Therefore both
failures returned **`BRIDGERESULT=FAILURE`** — the completely silent case.

The dialplan never reads `BRIDGERESULT`, so today this failure produces **zero diagnostics on either side**.
That alone is worth fixing: any fix must log the value.

## 4. Root cause — Defect B: the dialer's safety net is disarmed one priority before the failure

The dialer *does* have a recovery path for "the agent's channel died while a consultation was in progress":
`AMIEventProcess::_manejarHangupLoginChannelEnConsulta()`,
`setup/dialer_process/dialer/AMIEventProcess.class.php:3450`. Its "colleague was still ringing" branch is
exactly the recovery this bug needs (`:3485-3491`):

```php
        } else {
            // Colleague was still ringing - the customer is stuck in
            // atxfer-hold's MusicOnHold with nobody left to talk to.
            if (!is_null($llamada) && !empty($llamada->actualchannel)) {
                $this->_ami->Hangup($llamada->actualchannel);
            }
            if (!is_null($llamada)) $this->_procesarLlamadaColgada($llamada, $params);
        }
```

It is reached from the Hangup handler, gated at `AMIEventProcess.class.php:3287`:

```php
        if (!is_null($a) && (isset($this->_agentesEnConsultation[$a->channel])
                || isset($this->_agentesEnAtxferComplete[$a->channel]))) {
            $this->_manejarHangupLoginChannelEnConsulta($a, $llamada, $params);
```

But `UserEvent(ConsultationEnd)` fires at **priority 12**, and its handler
(`AMIEventProcess.class.php:2140-2142`) unconditionally clears that very flag:

```php
            $this->_consultaTerminadaEn[$sAgente] = microtime(TRUE);

            unset($this->_agentesEnConsultation[$sAgente]);
            unset($this->_agentesConsultaContestada[$sAgente]);
```

So by the time the agent's `Hangup` at priority 14 reaches the dialer, the guard at `:3287` is already
false and the net is skipped:

| prio | dialplan action | dialer effect |
|---|---|---|
| 12 | `UserEvent(ConsultationEnd)` | `_agentesEnConsultation[SIP/101]` **cleared** |
| 13 | `Bridge()` | fails silently (`BRIDGERESULT=FAILURE`) |
| 14 | `Hangup()` | guard at `:3287` is false → **recovery never runs** |

**Confirmed at the time of the investigation** (dialer log has since been cleared — transcribed):

```
[2026-08-30 01:41:12.696] ConsultationEnd UserEvent received for agent=SIP/101 was_in_consultation=YES was_in_atxfercomplete=NO
```

and the Hangup AMI event that followed:

```
[Channel] => SIP/101-00000002, [Context] => cbxfer-consult, [Priority] => 14,
[Cause] => 20, [Cause-txt] => Subscriber absent
```

`_manejarHangupLoginChannelEnConsulta` appeared **nowhere** in `dialerd.log` for either failure.

### 4.1 Constraint: the unconditional `unset` is deliberate — do not naively reorder

`AMIEventProcess.class.php:2114-2139` documents *why* the clear is unconditional. It defends a **different**
race: `_agentesEnConsultation` is set by an async message (`marcarConsultationIniciada`) sent just before the
AMI Redirect, so when the colleague declines instantly the `ConsultationEnd` UserEvent can arrive **before**
that message. Gating the cleanup on the flag already being set would lose the event, leave the flag set
forever, and stick the console's "Cancel transfer" cue until the page is reloaded.

Moving `UserEvent(ConsultationEnd)` after the `Bridge()` is **also wrong**: on the (normal) success path the
reconnected conversation can last minutes, and the console would show "in consultation" for its whole
duration.

**Conclusion: leave the dialer's ConsultationEnd ordering alone.** The fix belongs in the dialplan, where a
backstop cannot be disarmed by an event ordering. The dialer's net stays useful for the scenarios it was
written for (the agent's phone genuinely dying mid-consult), which this change does not touch.

## 5. Quantified evidence — the trigger is an instantly-failing `Dial()`

Every `[cbxfer-consult]` consultation in the retained Asterisk log was classified by (a) whether the
consult `Dial()` failed **instantly**, i.e. `app_dial.c: Unable to create channel ...` on the same thread
within 3 lines of the `Dial()`, and (b) whether the held channel subsequently joined a new
`simple_bridge` (reconnected) or not (stranded).

| consult `Dial()` behaviour | Bridge attempts | reconnected | **stranded** |
|---|---|---|---|
| **instant-fail** — no channel allocated, no network round trip | 3 | 1 | **2 (67%)** |
| ran normally — a channel was created (rang / busy / congested) | 18 | 18 | 0 |
| **total** | **21** | **19** | **2 (9.5%)** |

The separation is perfect: **every** stranding is in the instant-fail bucket, and **nothing** outside it has
ever stranded. Within the instant-fail bucket it is ~2 in 3 — which is exactly what a genuine thread race
looks like: heavily loaded, but not deterministic.

Per-case detail (timestamp = priority 10, `Dial()`):

```
2026-08-28 20:54:16 | 103 | SIP/103                                  | ran          | RECONNECTED
2026-08-28 20:54:24 | 103 | SIP/103                                  | ran          | RECONNECTED
2026-08-28 20:55:16 | 103 | SIP/103                                  | ran          | RECONNECTED
2026-08-28 20:55:59 | 103 | SIP/103                                  | ran          | RECONNECTED
2026-08-28 20:56:28 | 102 | Local/102@from-internal/n                | ran          | RECONNECTED
2026-08-28 20:56:35 | 103 | SIP/103                                  | ran          | RECONNECTED
2026-08-28 21:01:45 | 103 | SIP/103                                  | ran          | RECONNECTED
2026-08-28 21:01:57 | 102 | Local/102@from-internal/n                | ran          | RECONNECTED
2026-08-28 21:02:02 | 103 | SIP/103                                  | ran          | RECONNECTED
2026-08-28 21:04:57 | 102 | Local/102@from-internal/n                | ran          | RECONNECTED
2026-08-29 20:01:47 | 103 | SIP/103                                  | INSTANT-FAIL | RECONNECTED   <- control
2026-08-29 20:04:18 | 101 | SIP/101                                  | ran          | RECONNECTED
2026-08-29 20:05:10 | 101 | SIP/101                                  | ran          | RECONNECTED
2026-08-29 20:44:04 | 102 | PJSIP/102/sip:102@192.168.1.77:61859;ob  | ran          | RECONNECTED
2026-08-29 20:44:15 | 102 | PJSIP/102/sip:102@192.168.1.77:61859;ob  | ran          | RECONNECTED
2026-08-30 00:13:54 | 102 | Local/102@from-internal/n                | ran          | RECONNECTED
2026-08-30 01:41:12 | 103 | SIP/103                                  | INSTANT-FAIL | STRANDED      <- BUG
2026-08-30 01:43:52 | 103 | SIP/103                                  | INSTANT-FAIL | STRANDED      <- BUG
2026-08-30 02:10:09 | 107 | Local/107@from-internal/n                | ran          | RECONNECTED
2026-08-30 02:17:17 | 107 | PJSIP/107/sip:dtju1vi5@192.168.1.77:...  | ran          | RECONNECTED
2026-08-30 12:57:01 | 102 | PJSIP/102/sip:102@192.168.1.77:54818;ob  | ran          | RECONNECTED
```

### 5.1 Why "instant-fail" is the accelerant

`Dial(SIP/103)` against an **unregistered chan_sip peer** fails **synchronously inside the channel driver**:
`Unable to create channel of type 'SIP' (cause 20 - Subscriber absent)`. No channel is allocated, no SIP
message leaves the box, no timer runs. That is the fastest possible return from `Dial()` and it is what lets
the agent's thread run all 14 priorities inside one scheduler slice.

The exposed combination is therefore:

1. a **callback**-type agent (the `[cbxfer-consult]` path), and
2. a target extension whose `DB(DEVICE/<n>/dial)` resolves to a **real device string** (`SIP/…`,
   `PJSIP/…`, `IAX2/…`) — *not* the empty-string fallback to `Local/<n>@from-internal/n` at priority 8, and
3. that device **unregistered / has no contact**, so the driver refuses to allocate, and
4. the agent's thread winning the scheduling race (≈2 times in 3).

Note the interaction with priority 7: for PJSIP targets `PJSIP_DIAL_CONTACTS()` returns empty when there is
no contact, priority 8 then substitutes `Local/…@from-internal/n`, and the Local channel allocation is slow
enough to hide the race. **chan_sip targets have no such cushion** — `DB(DEVICE/103/dial)` returns `SIP/103`
whether or not 103 is registered. That is why every observed stranding is a chan_sip target.

### 5.2 Blast-radius amplifier

`MusicOnHold(,1800)` in `[atxfer-hold]` means a stranded customer is held for **30 minutes**. Worth
revisiting independently of this bug — a few minutes would bound the damage of any future stranding.

## 6. Blast radius — five `Bridge(${ATXFER_HELD_CHAN})` sites

`extensions_custom.conf` is generated from `setup/installer.php`; both must be changed together.

| # | live `extensions_custom.conf` | `setup/installer.php` | context | what follows the Bridge | exposure |
|---|---|---|---|---|---|
| 1 | 47 | 370 | `[atxfer-consult]` | `GotoIf(ATXFER_ON_HOLD)` → `Goto(atxfer-complete,…)` | same race, Agent type (`app_agent_pool`). Not yet observed failing — its `Dial(Local/…)` always allocates a channel (§5.1), which hides it. |
| 2 | 73 | 396 | `[atxfer-cancel-consult]` | `GotoIf(ATXFER_ON_HOLD)` → `Goto(atxfer-complete,…)` | lower risk: a human clicks Cancel, so time has passed since the Redirect |
| 3 | 83 | 406 | `[atxfer-bridge]` | `Hangup()` | reached **after** the colleague answered, i.e. a completed transfer. A `NONEXISTENT` here is legitimate — see §6.1. |
| 4 | **107** | **430** | **`[cbxfer-consult]`** | `Hangup()` | **CONFIRMED BROKEN — primary fix site** |
| 5 | 118 | 441 | `[cbxfer-cancel-consult]` | `Hangup()` | same shape as #4; lower risk (human-timed) but the same defect |

A sixth `Bridge()` exists at `extensions_custom.conf:62` (`[atxfer-unhold]`, `Bridge(${ATXFER_PARKED_CHAN})`).
It is driven by a single-channel Originate out of park, not by a dual-channel Redirect, so it is **not**
exposed to this race. Out of scope, but re-check it if the fix is generalised.

**Sites #1 and #2 must NOT get a copy-pasted version of the #4 fix** — they have real dialplan logic after
the `Bridge()`, and a retry block that terminates in `Hangup()` would break Agent-type transfers. Each site
needs a version tailored to its own tail.

### 6.1 Not a bug: `[atxfer-bridge]` NONEXISTENT

```
[2026-08-29 20:05:30] VERBOSE[13889][C-0000003d] features.c: Bridge failed because channel 'PJSIP/PJSIP120Issabel4-0000017e' does not exist
```

This and the other seven `features.c` lines in the log are the customer having genuinely hung up before the
transfer completed. `NONEXISTENT` is the correct outcome there; do not "fix" it.

## 7. Proposed fix

> **Outcome:** Option A was applied on 2026-08-30, factored into a single shared
> `[atxfer-rebridge]` context that all four Redirect-driven sites `Gosub` into,
> rather than a block copy-pasted per site. The `ATXFER_ON_HOLD` value is passed
> as `ARG2` at the two Agent-type sites so the `SoftHangup` backstop can never
> force-release a *parked* caller - see the note in §10. Details in `CHANGES_PRE.md` #70.

Two options were considered; **Option A was recommended** — it is confined to one file and has the smaller
blast radius.

### Option A (recommended) — bounded retry with a backstop, dialplan only

Replace priority 13 of `[cbxfer-consult]` (live `extensions_custom.conf:107`, `installer.php:430`) with:

```
 same => n,Set(XFER_TRIES=0)
 same => n(rebridge),Bridge(${ATXFER_HELD_CHAN})
 same => n,GotoIf($["${BRIDGERESULT}" = "SUCCESS"]?done)
 same => n,GotoIf($["${BRIDGERESULT}" = "NONEXISTENT"]?done)
 same => n,GotoIf($["${BRIDGERESULT}" = "LOOP"]?done)
 same => n,Set(XFER_TRIES=$[${XFER_TRIES} + 1])
 same => n,GotoIf($[${XFER_TRIES} > 20]?giveup)
 same => n,Wait(0.1)
 same => n,Goto(rebridge)
 same => n(giveup),NoOp(Issabel CallCenter: Could not reconnect agent to held caller after ${XFER_TRIES} tries, BRIDGERESULT=${BRIDGERESULT} - releasing caller)
 same => n,SoftHangup(${ATXFER_HELD_CHAN})
 same => n(done),Hangup()
```

Why this works, and its honest limits:

- **What it waits for is a pure dialplan hop.** The customer's thread only has to run `[atxfer-hold]`
  priorities 1-3 — microseconds. Retrying at 100 ms for up to 2 s is not "hoping"; it is orders of
  magnitude more slack than the window needs. It is nonetheless a **bounded retry, not a hard
  synchronisation primitive** — see Option B for that.
- **`BRIDGERESULT` is finally captured and logged.** Today the failure is completely silent (§3.4).
- **`SUCCESS` exits the loop correctly** — the reconnection genuinely happened and the conversation has
  since ended.
- **`NONEXISTENT`/`LOOP` exit immediately** — the customer really is gone, or a self-bridge was attempted;
  retrying either is pointless.
- **`SoftHangup` is the last-resort backstop.** It **drops the customer** — which is a worse outcome than a
  reconnect, but a far better one than 30 minutes of music with nobody there. It also makes the dialer
  finalize the call through its ordinary customer-hangup path, so the console and CDR stay consistent
  without touching Defect B's disarmed net. **This branch must be verified in testing** (see §8, test 4).
- **`Wait(0.1)` on an already-answered agent channel is safe** — the agent hears silence; the total added
  latency on the failure path is under 2 s, versus a hangup today.

`SHARED()`, `SoftHangup()` and `CHANNEL_EXISTS()` were all verified present on this box (Asterisk 18.19.0).

Apply the same shape, **tailored to each tail**, to sites #5 (`[cbxfer-cancel-consult]`), then #1 and #2 —
where `(done)` must fall through to the existing `GotoIf($["${ATXFER_ON_HOLD}" = "yes"]?holdwait)` rather
than to `Hangup()`. Leave site #3 (`[atxfer-bridge]`) alone (§6.1).

### Option B — fully deterministic handshake (larger change)

If a bounded retry is not acceptable, synchronise explicitly:

1. `[atxfer-hold]`, new priority 2: `Set(SHARED(ATXFER_HELD_READY)=1)`.
2. `[cbxfer-consult]` waits on `${SHARED(ATXFER_HELD_READY,${ATXFER_HELD_CHAN})}` before bridging.
3. **The flag must be cleared by the dialer, not by the dialplan.** A `Set(SHARED(...)=)` at
   `[cbxfer-consult]` priority 2 would itself race the customer's thread and could wipe a flag that had
   just been set. Instead `ECCPConn` issues, on the same AMI connection **before** the `Redirect`:
   `$this->_ami->SetVar($clientChannel, 'SHARED(ATXFER_HELD_READY)', '');`
   AMI actions on one connection are processed in order, so this is strictly ordered before the Redirect —
   no race. It must be added at **both** Redirect sites: `ECCPConn.class.php:3462` (Agent type) and
   `:3536` (callback).

Option B still needs Option A's `BRIDGERESULT` check and `SoftHangup` backstop for the case where the
customer hangs up during the handshake, so it is strictly more work, in two files instead of one.

### Rejected: a naive retry loop with no `BRIDGERESULT` check

An earlier draft looped on `Bridge()` without inspecting `BRIDGERESULT`. Rejected because it cannot
distinguish "customer gone" (`NONEXISTENT` — must stop) from "customer not ready" (`FAILURE` — must retry),
it spins forever on a hung-up customer, and it leaves the failure just as undiagnosable as today.

### Also rejected: reordering `UserEvent(ConsultationEnd)` — see §4.1.

## 8. Test steps

Pre-condition: extension `103` **defined but not registered** (`asterisk -rx "sip show peer 103"` →
`Status : UNKNOWN` / unreachable), agent `SIP/101` logged in as **callback**, an inbound external call
answered through queue 502.

| # | scenario | expected after the fix |
|---|---|---|
| 1 | Attended transfer to unregistered `103`, repeat **10 times** | agent reconnected to the customer every time; no run leaves the customer alone on MOH |
| 2 | Attended transfer to a **registered** colleague, colleague answers, agent completes | unchanged — transfer completes through `[atxfer-bridge]` |
| 3 | Attended transfer to a registered colleague, agent cancels while it rings | unchanged — agent reconnected via `[cbxfer-cancel-consult]` |
| 4 | Attended transfer to unregistered `103`, **customer hangs up during the consult** | `BRIDGERESULT=NONEXISTENT` → immediate `Hangup()`, no 2 s spin; call finalized once in the dialer, sane disposition in `call_center.call_entry` |
| 5 | Agent-type agent (`Agent/1001`) attended transfer, complete and cancel | unchanged — sites #1/#2 keep their `ATXFER_ON_HOLD` / `atxfer-complete` tails |
| 6 | Force the backstop (e.g. by shortening the retry bound during a test) | `SoftHangup` fires, `giveup` NoOp is in the log, customer released, call finalized — **not** left on MOH |

Apply with `asterisk -rx "dialplan reload"` — **never** `systemctl restart asterisk`, it drops live calls.

### Log collection

```bash
# Full transfer trace for one attempt
grep -E "cbxfer-consult|atxfer-hold|atxfer-bridge|simple_bridge|Surrogate|Unable to create channel" \
  /var/log/asterisk/full | tail -60

# Did Bridge() fail, and how? (after the fix this is no longer silent)
grep -E "BRIDGERESULT|Could not reconnect agent to held caller|features.c: Bridge failed" \
  /var/log/asterisk/full | tail -20

# Stranding detector: a customer left in atxfer-hold with no matching new bridge
grep -E "Executing \[s@atxfer-hold:3\] MusicOnHold" /var/log/asterisk/full | tail -20

# Dialer side
grep -E "ConsultationEnd|ConsultationAnswered|_manejarHangupLoginChannelEnConsulta|transfer" \
  /opt/issabel/dialer/dialerd.log | tail -60

# Live state during a test
asterisk -rx "core show channels"
asterisk -rx "bridge show all"
asterisk -rx "sip show peer 103" | grep -i status
```

## 9. File and line reference (verified 2026-08-30)

`setup/dialer_process/dialer/*` in the repo is **byte-identical** to `/opt/issabel/dialer/*` for both files
below (`diff -q` clean).

| File | Line | What |
|---|---|---|
| `/etc/asterisk/extensions_custom.conf` | 33 | `[atxfer-hold]` — `MusicOnHold(,1800)` at prio 3 |
| `/etc/asterisk/extensions_custom.conf` | 94 | `[cbxfer-consult]` — the broken context |
| `/etc/asterisk/extensions_custom.conf` | 107 | the racing `Bridge(${ATXFER_HELD_CHAN})`, prio 13 |
| `/etc/asterisk/extensions_custom.conf` | 47, 73, 83, 107, 118 | all five `Bridge(${ATXFER_HELD_CHAN})` sites |
| `setup/installer.php` | 357, 458 | `[atxfer-hold]`, `[cbxfer-consult]` generators (post-fix) |
| `setup/installer.php` | 382 | `[atxfer-rebridge]`, the shared reconnect context (post-fix) |
| `setup/installer.php` | 411, 437, 447, 471, 482 | the same five sites: four now `Gosub`, 447 (`[atxfer-bridge]`) still a direct `Bridge()` |
| `setup/dialer_process/dialer/ECCPConn.class.php` | 3526-3527 | `SetVar` of `ATXFER_HELD_CHAN` / `ATXFER_AGENT_ID` |
| `setup/dialer_process/dialer/ECCPConn.class.php` | **3536-3546** | the dual-channel `Redirect` that creates the race |
| `setup/dialer_process/dialer/ECCPConn.class.php` | 3462-3470 | the Agent-type equivalent `Redirect` |
| `setup/dialer_process/dialer/AMIEventProcess.class.php` | 2109-2142 | `ConsultationEnd` handler; `:2142` is the disarming `unset` |
| `setup/dialer_process/dialer/AMIEventProcess.class.php` | 2114-2139 | the comment explaining why that `unset` is unconditional (§4.1) |
| `setup/dialer_process/dialer/AMIEventProcess.class.php` | **3287** | the guard that is false by the time Hangup arrives |
| `setup/dialer_process/dialer/AMIEventProcess.class.php` | 3450-3510 | `_manejarHangupLoginChannelEnConsulta()` — the recovery that never ran |

## 10. Open items

Resolved by `CHANGES_PRE.md` #70 (2026-08-30):

- [x] Fix applied - shared `[atxfer-rebridge]` retry context, live and in `setup/installer.php`.
- [x] Option A chosen, applied to sites #4, #5, #1 and #2, each keeping its own tail.
- [x] Mirrored into `setup/installer.php`; the `[atxfer-hold]`..`[cbxfer-done]` region of the
      generator is byte-identical to the live `/etc/asterisk/extensions_custom.conf`.
- [x] `CHANGES_PRE.md` entry added (#70).
- [x] Tests 4 and 6 verified. The `SoftHangup` backstop was exercised with scratch contexts
      against a real channel in `[atxfer-hold]`'s MusicOnHold: it releases the caller in the
      same shape as an ordinary caller hangup, which is what the dialer's finalization expects.
      The `ARG2="yes"` guard was verified to suppress that release for a parked caller.

Still open:

- [ ] `[atxfer-hold]`'s `MusicOnHold(,1800)` still has no orphan check, and is now also out of
      step with the 900 s hold cap from Change #69. Tracked in `TODO.md`
      ("Attended-Transfer Hold Has No Orphan Check"). §5.2.
- [ ] Sites #1/#2 (`[atxfer-consult]`, `[atxfer-cancel-consult]`) have still never been observed
      failing; §5.1 explains why. They now use the same retry, but with the release backstop
      suppressed while `ATXFER_ON_HOLD=yes`: when the agent presses Hold during a consultation
      the caller is *parked* (Change #69) rather than held in `[atxfer-hold]`, and `Bridge()` on
      a parked channel legitimately succeeds - that is how `[atxfer-unhold]` retrieves it - so a
      failure there is an expected outcome, not a stranding.
