# Issabel Dialer Logic Documentation

## Table of Contents
1. [Outgoing Campaign Call Flow](#outgoing-campaign-call-flow)
2. [3-Pass Call Placement Architecture](#3-pass-call-placement-architecture)
3. [Fair Rotation Algorithm](#fair-rotation-algorithm)
4. [Configuration Options](#configuration-options)
5. [Agent Status Management](#agent-status-management)
6. [Orphaned Call Cleanup](#orphaned-call-cleanup)
7. [How Balancing, Predictive and Overcommit Interact](#how-balancing-predictive-and-overcommit-interact)
8. [Bug Fixes](#bug-fixes)

---

## Outgoing Campaign Call Flow

### Standard Flow (Predictive/Progressive Dialing)

#### Flow chart: one cycle over every campaign

Every 3 seconds the dialer runs one review cycle over all its active outgoing
campaigns. Campaigns that share a queue share its agents, so a cycle works in
three passes: each campaign first lists the free agents it could use, a
rotation then gives each free agent to one campaign, and only then does each
campaign dial, within two budgets, its agents and its channels. The chart
names functions, not line numbers, which move; they are all in
`CampaignProcess.class.php` unless another class is named.

```
┌─ ONE CYCLE, EVERY 3 s (INTERVALO_REVISION_CAMPANIAS) ────────────────────────┐
│ CampaignProcess::_actualizarCampanias(), over every active outgoing campaign │
└───────────────────────────────────────┬──────────────────────────────────────┘
                                        ▼
┌─ 0: HOUSEKEEPING ────────────────────────────────────────────────────────────┐
│ orphans   Placing or Ringing for over 5 min                → Failure         │
│           Success or OnHold for over 2 h with no end_time  → Hangup          │
│ expiry    campaigns past datetime_end                      → estatus 'I'     │
│ load      outgoing campaigns with estatus 'A', today inside datetime_init    │
│           ..datetime_end and now inside daytime_init..daytime_end,           │
│           in database order (the query has no ORDER BY)                      │
│ announce  new campaigns to AMIEventProcess. Incoming campaigns stop here:    │
│           they place no calls                                                │
└───────────────────────────────────────┬──────────────────────────────────────┘
                                        ▼
┌─ RESERVATION PRE-PASS, over every campaign's scheduled rows ─────────────────┐
│ reserved  = agents with a scheduled call due within 30 s, of ANY active      │
│             campaign (_listarAgentesAgendadosReserva(); _agentesAgendables()│
│             reserves each of them, which pauses them in every queue)         │
│ Pass 1 drops them from every campaign's free list: a reserved agent belongs  │
│ to its scheduled call, and the queue's Paused flag reaches the free list     │
│ only a cycle later                                                           │
└───────────────────────────────────────┬──────────────────────────────────────┘
                                        ▼
┌─ PASS 1: INTENTIONS, each campaign C, from its own queue snapshot ───────────┐
│ raw_max[C]       = max_canales                       (empty or 0: no limit)  │
│ active[C]        = C's calls Placing, Ringing, OnQueue or OnHold, or         │
│                    connected (Success with no end_time)                      │
│ effective_max[C] = max(0, raw_max[C] - active[C])  the channels C has left   │
│ wants[C]         = the free members of C's queue: device NOT_INUSE, not      │
│                    paused, not on a live call (AGENTES_LIBRES_LISTA of       │
│                    QueueShadow::infoPrediccionCola())                        │
│                    Campaigns on one queue want the same agents.              │
└───────────────────────────────────────┬──────────────────────────────────────┘
                                        ▼
┌─ PASS 2: FAIR ROTATION, _resolveAgentRotation() ─────────────────────────────┐
│ each free agent once, in the order the campaigns listed them:                │
│   wanted by 1 campaign   → that campaign, while it holds fewer than          │
│                            raw_max[C] agents                                 │
│   wanted by 2+ campaigns → the agent's own turn: its campaigns sorted by     │
│                            id, starting at its index; a campaign that        │
│                            already holds raw_max[C] agents is passed over;   │
│                            all full → nobody. The index moves on by one      │
│                            every cycle, given or not                         │
│                            (_getRotationWinnerWithCapacity())                │
│ capped by RAW max_canales: a call still dialing does not shut a campaign     │
│ out of the rotation; the channels left are applied in Pass 3                 │
│ result: allocated[C], each free agent in at most one campaign                │
└───────────────────────────────────────┬──────────────────────────────────────┘
                                        ▼
┌─ PREDICTIVE SPLIT, _computePredictiveShares(), only with predictive on ──────┐
│ per queue; eligible = its campaigns with allocated[C] > 0                    │
│ P[C]   = busy agents C's Erlang model expects free before a new call is      │
│          answered (Predictor::predecirNumeroLlamadas(); 0 below 10           │
│          completed calls)                                                    │
│ cap[C] = max(0, P[C] - callers waiting in the queue)                         │
│ total  = the largest cap[C], shared in proportion to allocated[C], none      │
│          above its cap[C]; leftover slots go one by one in the queue's       │
│          own turn (_splitPredictiveSlots())                                  │
│ result: boost[C]                                                             │
└───────────────────────────────────────┬──────────────────────────────────────┘
                                        ▼
┌─ PASS 3: DIAL, _processCampaignWithAllocation(), each C in database order ───┐
│ scheduled      = due scheduled calls of C, read and placed BEFORE the        │
│                  no-agent exit (_actualizarLlamadasAgendables()): a due      │
│                  callback is placed whatever this cycle allocated, because  │
│                  it dials its own agent directly                             │
│ allocated[C] = 0 and nothing scheduled ──► skip C (it only checks whether    │
│                                            its rows are used up)             │
│ want           = allocated[C] + boost[C]                                     │
│ agent budget   = want - pending   (a scheduled call takes no queue agent)    │
│                  pending = calls of ANY campaign on C's queue still          │
│                  waiting for Asterisk's Originate result                     │
│ channel budget = effective_max[C] - scheduled                                │
│ N              = min(agent budget, channel budget)                           │
│ overcommit on and N > 0 → N = N / max(ASR, 0.20), at most effective_max[C]   │
│                  ASR = the answered share of the calls that finished in      │
│                  the last 30 min, used once there are 10 of them             │
│ N = 0 and nothing scheduled ──► skip C (the same check)                      │
│ numbers  N rows: in-window rows first, then rows with no window, fewest      │
│          retries first; repeats, DNC numbers and dial strings already in     │
│          flight are dropped                                                  │
│ each     status Placing, retries + 1 → Originate through AMIEventProcess     │
│ finish   no callable row left → campaign estatus 'T' (finished): callable    │
│          when not answered, not in progress, retries left, not DNC, and      │
│          either no window and no agent, or a full window not over -          │
│          checked by _checkCampaignDataExhausted(), called at the no-agents   │
│          exit, the no-budget exit and the in-cycle guard                     │
└───────────────────────────────────────┬──────────────────────────────────────┘
                                        ▼
           next campaign; after the last one, the cycle's sweep: reserved
           agents with no schedulable call left anywhere are released
           (_liberarReservasHuerfanas()), and the dialer waits for the
           next cycle
```

Reading the chart:

- **The rotation decides who may dial, not who answers.** An answered call
  enters the Asterisk queue, which hands it to whichever member is free at
  that moment; the queue knows nothing of campaigns. See
  [How Balancing, Predictive and Overcommit Interact](#how-balancing-predictive-and-overcommit-interact).
- **The turns of shared agents often move together.** Each agent keeps its
  own turn, but agents that were free in the same cycles have the same index,
  so in one cycle one campaign is first in line for all of them, and in the
  next cycle the other (example 1).
- **`max_canales` works twice.** Raw, it caps how many agents the rotation
  gives a campaign; effective (less its live calls), it caps how many calls
  the campaign places. A campaign can be given more agents than it has
  channels left for, and the extra agents sit that cycle out (example 1,
  cycle 1).
- **Pending originates are counted per queue**, so one campaign's calls that
  are still dialing also shrink the agent budget of every other campaign on
  that queue (G4), including calls placed earlier in the same cycle: a call
  counts as pending as soon as Asterisk accepts its Originate.
- **The agent lists are taken once, in Pass 1**, and the campaigns then dial
  one after another, so the last campaign dials on the oldest snapshot (G9).

**Example 1: two campaigns on one queue.** Queue 500 has agents 4001-4004.
Campaign A (id 1, `max_canales` 2) already has a call talking to 4004;
campaign B (id 2, `max_canales` 5) has none. 4001-4003 are free. Predictive
and overcommit are off, and every call placed below is still dialing 6 s
later.

| cycle | Pass 1: effective_max | Pass 2: first in line, then allocated | Pass 3: A (dials first) | Pass 3: B |
|---|---|---|---|---|
| 1 | A 2-1 = 1, B 5 | A for every agent: 4001 → A, 4002 → A, 4003 → B (A holds its raw max of 2) | want 2, channel budget 1: **1 call** | want 1, minus 1 pending (A's call): 0 |
| 2 | A 2-2 = 0, B 5 | B: 4001, 4002, 4003 → B | no agent: skip | want 3, minus 1 pending: **2 calls** |
| 3 | A 0, B 5-2 = 3 | A: 4001, 4002 → A, 4003 → B | want 2, minus 3 pending: 0 | want 1, minus 3 pending: 0 |

From cycle 2 on, three calls are dialing for the three free agents, and A is
at its `max_canales`. Cycle 1 placed one call for three free agents. One of
A's two agents had no call, because the rotation gave A two agents (its raw
max) while A had one channel left. B's agent had none either, because A's
call, accepted moments before, already counted as pending on the queue.
Cycle 2 caught up.

**Example 2: the predictive split.** Both campaigns on one queue are
predictive. The rotation gave A 3 agents and B 1. Of the queue's busy agents,
A's model expects 4 to free in time and B's expects 2 (each campaign has its
own call length and answer time). 1 caller is waiting.

```
cap        A = 4 - 1 = 3            B = 2 - 1 = 1
total      the largest cap = 3
floors     A = floor(3 x 3/4) = 2   B = floor(3 x 1/4) = 0
leftover   1, in the queue's turn: A this cycle (A 3, B 0),
           B the next cycle with the same inputs (A 2, B 1)
```

#### Step by step: one campaign's cycle, then one call

```
1. Campaign Process: Collect campaign intentions (Pass 1)
   └─> _countActiveCalls() counts DB calls in Placing/Ringing/OnQueue/OnHold, and connected ones
   └─> effective_max_canales = max_canales - active_calls
   └─> QueueShadow::infoPrediccionCola() returns:
       - AGENTES_LIBRES (status: NOT_INUSE only)
       - AGENTES_LIBRES_LISTA (list of free agent interfaces for rotation)
       - AGENTES_POR_DESOCUPAR (busy agents, predicted to finish soon)
       - CLIENTES_ESPERA (calls already in queue)

2. Fair Rotation: Resolve agent allocation (Pass 2)
   └─> _resolveAgentRotation() allocates free agents to campaigns
   └─> Capped by each campaign's raw max_canales (its channels left apply in step 3)
   └─> Shared agents rotated fairly across campaigns

3. Process campaigns with allocation (Pass 3)
   └─> iNumLlamadasColocar = numAllocatedAgents
   └─> + predictive boost (if enabled)
   └─> Capped by effective_max_canales
   └─> - scheduled calls: channel budget only (they dial their own agent)
   └─> + overcommit adjustment (if enabled, re-capped by effective_max_canales)

4. Originate calls
   └─> Status: 'Placing'

5. Customer phone rings
   └─> Status: 'Ringing'

6. Customer answers
   └─> Status: 'OnQueue'
   └─> Call enters queue via msg_Join() → llamadaEntraEnCola()

7. Queue assigns call to agent
   └─> Link/Bridge event → msg_Link() → llamadaEnlazadaAgente()
   └─> Status: 'Success'
   └─> Agent assigned to call
```

### Scheduled Calls Flow (Agent-Specific Calls)

```
1. Reservation pre-pass, before any campaign's free-agent list is taken
   └─> _listarAgentesAgendadosReserva() lists the agents with a scheduled call
       due within 30 s; AMIEventProcess::_agentesAgendables() marks them
       'reserved' (pausing them in every queue), and Pass 1 drops them from
       every campaign's free list

2. Campaign Process: check for scheduled calls, before the no-agent exit
   └─> _actualizarLlamadasAgendables() queries calls table WHERE agent IS NOT NULL

3. Place scheduled call
   └─> Agent must be: logged-in, no active call, no pending scheduled call, only 1 pause (reserved)
   └─> Call is linked to specific agent via agente_agendado property
   └─> Scheduled calls are placed BEFORE regular calls in the same cycle
   └─> Their count is subtracted from the channel budget only: they consume
       channels not yet reflected in Pass 1's _countActiveCalls, but they dial
       their own agent directly and take no queue agent

4. Flow continues as standard, but only assigned agent can take this call
```

---

## 3-Pass Call Placement Architecture

The campaign process runs every 3 seconds (`INTERVALO_REVISION_CAMPANIAS`). Each cycle uses a 3-pass approach:

### Pass 1: Collect Campaign Intentions (CampaignProcess.class.php:674-744)

For each active campaign:

1. **Count active calls** via `_countActiveCalls()` — queries DB for calls in `Placing/Ringing/OnQueue/OnHold`, and connected calls (`Success` with no `end_time`)
2. **Calculate effective_max_canales** = `max_canales - active_calls` (for channel budget)
3. **Store raw max_canales** — original `max_canales` value (for rotation)
4. **Get free agent list** via `QueueShadow::infoPrediccionCola()` → `AGENTES_LIBRES_LISTA`
5. **Store intentions**: which agents each campaign wants

```php
// CampaignProcess.class.php:691-700
$activeCalls = $this->_countActiveCalls($campaignData['id']);
$effectiveMaxCanales = max(0, $maxCanales - $activeCalls);
$this->_campaignMaxCanales[$campaignData['id']] = $effectiveMaxCanales;
$this->_campaignRawMaxCanales[$campaignData['id']] = $maxCanales;  // For rotation
```

### Pass 2: Fair Rotation (CampaignProcess.class.php:749)

Resolves which agents go to which campaign when multiple campaigns share the same queue/agents:

```php
$this->_allocatedAgents = $this->_resolveAgentRotation(
    $this->_campaignIntentions,
    $this->_campaignRawMaxCanales  // RAW max_canales, not effective_max
);
```

**Key:** Rotation uses **raw** `max_canales` (not reduced by active calls). This ensures agents aren't pre-excluded just because calls are in transit ("Placing"). The channels left (`effective_max_canales`) limit the calls in Pass 3, so a campaign can be given more agents than it can dial for in that cycle (example 1 under [Standard Flow](#standard-flow-predictiveprogressive-dialing)).

See [Fair Rotation Algorithm](#fair-rotation-algorithm) for details.

### Pass 3: Process Each Campaign (CampaignProcess.class.php:1207-1609)

For each campaign (due scheduled calls are read and placed before the no-agent
exit, so a campaign with no allocated agents still places its due callbacks):

```php
// Base: one call per allocated agent
$iNumLlamadasColocar = $numAllocatedAgents;

// + Predictive boost (if enabled)
$iNumLlamadasColocar += $iPredictiveBoost;

// === TWO-BUDGET CALL LIMITING ===
// Budget 1 (Agent): allocated agents - calls in transit to agents.
// A scheduled call dials its own agent directly and takes no queue agent,
// so it is not subtracted here.
$iPendingOriginate = $this->_contarLlamadasEsperandoRespuesta($queue);
$iScheduledThisCycle = count($listaLlamadasAgendadas);
$iAgentBudget = $iNumLlamadasColocar - $iPendingOriginate;

// Budget 2 (Channel): effective_max - scheduled calls
$iChannelBudget = $effectiveMaxCanales - $iScheduledThisCycle;

// Final calls = minimum of both budgets
$iNumLlamadasColocar = min($iAgentBudget, $iChannelBudget);

// + Overcommit (if enabled, then re-capped by effective_max_canales)
```

**Two-budget explanation:**
- **Agent budget:** Prevents placing calls for agents already being dialed. Calls pending OriginateResponse are "Placing" in DB but agents still show as NOT_INUSE until queue entry. Subtracting these prevents over-dialing.
- **Channel budget:** Hard cap on trunk capacity (max_canales - active_calls - scheduled)
- Both budgets must be satisfied — final count is the minimum

**Key variables:**
```php
$_campaignIntentions     // [campaign_id => [agent1, agent2, ...]]
$_allocatedAgents        // [campaign_id => [agent1, agent2, ...]]
$_agentRotation          // [agent => ['key'=>'ids', 'campaigns'=>[...], 'index'=>N]]
$_campaignMaxCanales     // [campaign_id => effective_max_canales] - channel budget
$_campaignRawMaxCanales  // [campaign_id => raw_max_canales] - for rotation only
$_predictiveBoostShare   // [campaign_id => int] - this cycle's share of the queue split
$_queueSlotRotation      // [queue => ['key'=>'ids', 'campaigns'=>[...], 'index'=>N]]
```

---

## Fair Rotation Algorithm

### Purpose

When multiple campaigns share the same queue (and thus the same agents), the rotation algorithm ensures fair distribution of agents across campaigns while respecting each campaign's `max_canales` limit.

### How It Works (CampaignProcess.class.php:804-889)

#### Step 1: Build Reverse Map
```
Agent/4001 → [Campaign A]           (unique)
Agent/4002 → [Campaign A, Campaign B]  (shared)
Agent/4003 → [Campaign B]           (unique)
```

#### Step 2: One Pass Over the Agents
The agents are handed out in one loop, in the order of the map (the campaigns
in database order, each campaign's agents in queue order), unique and shared
alike. `$maxCanales` here is each campaign's **raw** `max_canales`
(`_campaignRawMaxCanales`), not the effective one:

```php
if (count($campaigns) == 1) {
    // Unique: to its campaign, while that holds fewer than raw max_canales
    $campaignId = $campaigns[0];
    if ($allocationCount[$campaignId] < $maxCanales[$campaignId]) {
        $allocated[$campaignId][] = $agent;
        $allocationCount[$campaignId]++;
    }
} else {
    // Shared: the agent's own turn
    $winningCampaign = $this->_getRotationWinnerWithCapacity($agent, $campaigns, $maxCanales, $allocationCount);
}
```

A shared agent keeps its own rotation state, `$_agentRotation[$agent]`: its
campaigns sorted by id and an index, reset to 0 when the set of campaigns
that want it changes. `_getRotationWinnerWithCapacity()` starts at the index,
passes over a campaign that already holds its raw `max_canales` agents, and
gives the agent to the first one with room, or to nobody when all are full.
The index then moves on by one, whether the agent was given or not:

```php
$rotation['index']++;  // persists across cycles
```

Because unique and shared agents come in one loop, a shared agent listed early
can fill a campaign up to its raw `max_canales` before that campaign's unique
agents come up; those are then left unallocated in that cycle.

### Example: 2 Campaigns, 1 Shared Agent

```
Cycle 1: Agent/4002 → Campaign A (rotation index 0)
Cycle 2: Agent/4002 → Campaign B (rotation index 1)
Cycle 3: Agent/4002 → Campaign A (rotation index 2 % 2 = 0)
...
```

### Single Campaign Behavior

With only one campaign, rotation degenerates to simple allocation limited by raw `max_canales`. The channel budget applied in Pass 3 ensures the final call count respects the effective (reduced) channel capacity.

---

## Configuration Options

### 1. Enable Overcommit of Outgoing Calls

**Database field:** `dialer.overcommit`
**Location:** CampaignProcess.class.php:1335-1384

**Purpose:** Compensate for calls that fail to connect by placing additional calls.

**How it works:**
1. Calculates ASR (Answer Seizure Ratio) from last 30 minutes:
   ```php
   ASR = successful_calls / total_calls_attempted
   ```

2. Adjusts call count:
   ```php
   $ASR_safe = max($ASR, 0.20);  // Minimum 20% to prevent excessive overcommit
   $iNumLlamadasColocar = round($iNumLlamadasColocar / $ASR_safe);
   ```

3. **Re-caps by effective_max_canales** after adjustment to respect trunk capacity

4. Requirements:
   - At least 10 calls in the history window
   - ASR > 0

**Example:**
- 5 free agents, max_canales=10
- ASR = 50% (half of calls fail)
- Overcommit places: 5 / 0.5 = 10 calls
- Expected result: ~5 successful connections for 5 agents

**Status:** Correct on its own, for a single campaign. The overcommit factor is
applied *after* fair rotation and is re-capped only by the channel budget, never
by the campaign's agent share - see
[How Balancing, Predictive and Overcommit Interact](#how-balancing-predictive-and-overcommit-interact).

---

### 2. Enable Predictive Dialer Behavior

**Database field:** `dialer.predictivo`
**Location:** CampaignProcess.class.php:1086-1198 (per-queue split), :1262-1274 (share read)

**Purpose:** Predict when busy agents will finish calls and place calls preemptively.

**How it works:**

1. Uses **Erlang probability distribution** (Predictor.class.php):
   ```php
   function predecirNumeroLlamadas($infoCola, $prob_atencion, $avg_duracion, $avg_contestar) {
       foreach ($infoCola['AGENTES_POR_DESOCUPAR'] as $tiempo_en_llamada) {
           $iTiempoTotal = $avg_contestar + $tiempo_en_llamada;
           $iProbabilidad = $this->_probabilidadErlangAcumulada(
               $iTiempoTotal, 1, 1 / $avg_duracion);
           if ($iProbabilidad >= $prob_atencion)
               $n++;  // Count as available
       }
   }
   ```

2. The boost is computed and split per queue **before** the campaigns are
   processed (`_computePredictiveShares()`, :1086). The queue total stays what
   it was - the busiest eligible campaign's prediction minus the queue's
   waiting callers, floored at 0 - and is shared between the eligible
   campaigns in proportion to the agents rotation gave each, capped by each
   campaign's own prediction, with rounding leftovers rotating per queue:
   ```php
   $iT = max over eligible campaigns of max(0, P_c - waiting);
   $shares = $this->_splitPredictiveSlots($iT, $weights, $caps, $queueCampaigns, $rotation);
   ```

3. Considers:
   - **avg_duracion**: Average call duration from campaign history
   - **avg_contestar**: Average time for customer to answer
   - **prob_atencion** (QoS): Service quality threshold (default: 97%)

4. Requirements:
   - Campaign must have MIN_MUESTRAS (10) completed calls for Erlang prediction
   - Below MIN_MUESTRAS there is no prediction at all: `predecirNumeroLlamadas()`
     with no probability counts 0 agents about to free (`Predictor.class.php:277`),
     so the campaign gets no boost

**Mathematical Model:**
```
P(agent_free_before_customer_answers) = Erlang_CDF(
    time_total = avg_answer_time + current_call_time,
    k = 1,
    lambda = 1 / avg_call_duration
)

If P >= 97% → count agent as available
```

**Status:** The Erlang model is correct. The predicted slots are now split
fairly between the campaigns sharing the queue (fixed in 5.2.19, G2 below);
the interactions still open are in
[How Balancing, Predictive and Overcommit Interact](#how-balancing-predictive-and-overcommit-interact).

---

### 3. max_canales (Maximum Channels per Campaign)

**Database field:** `campaign.max_canales`

**Purpose:** Limit the maximum number of concurrent calls for a campaign (trunk capacity limit).

**How it works:**
- In Pass 1, `effective_max_canales = max_canales - active_calls` is calculated
- `active_calls` counts calls in statuses: Placing, Ringing, OnQueue, OnHold,
  and connected calls (Success with no `end_time`)
- The **raw** `max_canales` limits agent allocation in Pass 2 (rotation)
- `effective_max_canales` caps `iNumLlamadasColocar` in Pass 3: the channel
  budget, and the re-cap after overcommit
- An empty or zero `max_canales` means no limit at all (G6)

**Example:** max_canales=3, 1 call in OnQueue, 4 free agents:
- effective_max = 3 - 1 = 2
- Rotation allocates at most 3 agents (the raw max)
- At most 2 new calls can be placed; the third allocated agent waits for a
  later cycle

---

## Agent Status Management

### Device Status Constants (Predictor.class.php)
```php
AST_DEVICE_NOTINQUEUE = -1  // Not a queue member
AST_DEVICE_UNKNOWN    = 0   // Unknown state
AST_DEVICE_NOT_INUSE  = 1   // Free/Available
AST_DEVICE_INUSE      = 2   // On a call
AST_DEVICE_BUSY       = 3   // Busy (DND, etc.)
AST_DEVICE_INVALID    = 4   // Invalid device
AST_DEVICE_UNAVAILABLE= 5   // Device unavailable
AST_DEVICE_RINGING    = 6   // Phone is ringing
AST_DEVICE_RINGINUSE  = 7   // On call + another ringing
AST_DEVICE_ONHOLD     = 8   // Call on hold
```

### Free Agent Detection

**Both modes:** only `AST_DEVICE_NOT_INUSE` (1) counts as free. A member whose
phone is ringing is being offered a call, the queue will not ring them again
under `ringinuse=no`, and nothing subtracts the call already ringing them.

### Status Updates

Agent status is updated by **AMI events**, specifically:

1. **QueueMemberStatus** (AMIEventProcess.class.php)
   ```php
   public function msg_QueueMemberStatus($params) {
       $sAgente = $params['Location'];  // or $params['Interface'] for Asterisk 13+
       $a = $this->_listaAgentes->buscar('agentchannel', $sAgente);
       if (!is_null($a)) {
           $a->actualizarEstadoEnCola($params['Queue'], $params['Status']);
       }
   }
   ```

2. **Call Assignment** (Llamada.class.php)
   ```php
   public function llamadaEnlazadaAgente($timestamp, $agent, ...) {
       $this->agente = $agent;
       $this->agente->asignarLlamadaAtendida($this, $uniqueid_agente);
       $this->status = 'Success';
       $this->timestamp_link = $timestamp;
   }
   ```

### Frontend Status Display

**Location:** `/var/www/html/modules/campaign_monitoring/themes/default/js/javascript.js`

**Correct behavior:**
- Frontend should ONLY display status received from backend
- Frontend should NEVER infer or change agent status based on call events
- Agent status updates come via ECCP protocol from AMIEventProcess

---

## Orphaned Call Cleanup

### At Startup (CampaignProcess.class.php:159-204)

Empties `current_calls` and `current_call_entry`, closes open incoming calls
(`fin-monitoreo`), then clears every outgoing call a previous abnormal
termination left in progress:
```sql
UPDATE calls SET status = 'Failure', failure_cause = 0,
    failure_cause_txt = 'Orphaned call at startup'
WHERE status IN ('Placing', 'Ringing', 'OnQueue');

UPDATE calls SET status = 'Hangup', end_time = CASE
    WHEN start_time IS NOT NULL THEN start_time ELSE ? END
WHERE (status = 'OnHold' OR (status = 'Success' AND end_time IS NULL));
```

### Periodic Cleanup (CampaignProcess.class.php:2174, :2210)

Runs at the start of every campaign cycle.

`_cleanOrphanedPlacingCalls()` clears calls still dialing after 5 minutes
(300 seconds), which got stuck on a missed OriginateResponse:
```sql
UPDATE calls SET status = 'Failure', failure_cause = 0,
    failure_cause_txt = 'Orphaned call - timeout'
WHERE status IN ('Placing', 'Ringing') AND datetime_originate < ?
```

`_cleanOrphanedConnectedCalls()` closes connected calls with no `end_time`
after 2 hours, whose Hangup was missed:
```sql
UPDATE calls SET status = 'Hangup', end_time = start_time
WHERE (status = 'Success' OR status = 'OnHold')
    AND end_time IS NULL AND start_time IS NOT NULL AND start_time < ?
```

When the AMI connection comes back after an Asterisk restart, both run at
once with a timeout of 0 (`:343-344`): no call can have survived the restart.

---

## How Balancing, Predictive and Overcommit Interact

Each of the three features is correct on its own. This section records how they
behave **together**, which is where the open problems are. It is written as
input for a future improvement, not as a description of a broken system.

### The order of operations

Balancing runs first, the per-queue predictive split adds to its result, and
overcommit multiplies that:

```
allocated_agents = fair rotation result          (rotation,        :749)
        + predictive_boost_share                 (queue split, :763, read :1263)
        - pending_originate                      (agent budget,   :1284-1287)
   min  effective_max_canales - scheduled        (channel budget, :1295-1298)
        / ASR   (floor 0.20)                     (overcommit,     :1335-1353)
   cap  effective_max_canales                    (re-cap,         :1355-1370)
```

So the final count is roughly `(allocated + boost) / ASR`, capped by the
channel budget. Prediction and overcommit **compound**: they do not add, they
multiply.

**The key point for any future work:** fair rotation decides which campaign may
*dial* for an agent. It does not decide which campaign *gets* that agent. When
the call is answered it enters the Asterisk queue, and the queue hands it to
whichever agent is free at that instant - the queue knows nothing about
campaigns or about the allocation made 15 seconds earlier. Everything below
follows from that gap.

### Confirmed correct - no need to re-investigate

- **Rotation of shared agents.** Each genuinely free shared agent is given to
  exactly one campaign per cycle, and the turn advances. If the winner is at
  `max_canales` the next campaign in rotation order takes it.
- **An agent busy on another queue's call cannot be counted as "about to
  become free".** `AGENTES_POR_DESOCUPAR` counts a member whose per-queue
  `LinkStart` is set and whose device is busy or ringing
  (`QueueShadow.class.php:396-397`), and `LinkStart` is set only by
  `AgentConnect` **on that specific queue**. An agent talking on queue A shows
  `INUSE` in queue B but with `LinkStart = NULL`, so queue B's prediction skips
  them. This is the conservative direction. Since 5.2.21 the queue refresh
  keeps `LinkStart` (see Bug Fixes).
- **Busy and paused agents are excluded everywhere.** Device status is global
  and Asterisk emits `QueueMemberStatus` for every queue the member belongs to,
  so an agent busy anywhere is not `NOT_INUSE` anywhere. Paused members are
  skipped by `infoPrediccionCola()` in every queue.
- **The two-budget limiting itself** (agent budget vs channel budget) behaves as
  documented above for a single campaign per queue.

### Open interactions

Two outgoing campaigns may share one queue - the GUI allows it and it is the
normal multi-campaign setup - so **every member is shared between them**. All
of the following apply to that case.

**G1. Overcommit is not bounded by the fair share.** (`:1335-1384`)
The ASR division happens after `min(agent_budget, channel_budget)` and is
re-capped only by `effective_max_canales`, never by the campaign's allocated
agent count. Two campaigns on one queue therefore dial in proportion to their
*ASR*, not to their allocation: the campaign with the **worse** ASR places more
calls, and since all those calls compete for the same agents, it statistically
wins more agents than rotation gave it.
*Idea:* cap the post-overcommit count by a per-campaign ceiling such as
`allocated_agents / ASR`, in addition to the channel budget.

**G2. Predictive slots are first-come, not rotated.** (fixed by
`_computePredictiveShares()`, `:1086`)
**Fixed in 5.2.19.** The boost is now computed and split per queue in
`_computePredictiveShares()` (`:1086`), before the campaigns are processed: the
queue total - the busiest eligible campaign's prediction minus the queue's
waiting callers - is shared between the eligible campaigns in proportion to the
agents rotation gave each, capped by each campaign's own prediction, with
rounding leftovers rotating per queue over every campaign on that queue,
eligible or not (`_splitPredictiveSlots()`, `:993`).

**G3. Waiting callers are subtracted only from that same first campaign.**
(fed by `QueueShadow.class.php:370`; resolved in `_computePredictiveShares()`, `:1086`)
**Resolved by the same change (5.2.19):** `CLIENTES_ESPERA` is read once per
queue in `_computePredictiveShares()` and subtracted before the split, so it
lowers the queue total once instead of one campaign's whole boost.

**G4. Pending originates are counted per queue but subtracted per campaign.**
(`:1284-1286`, `AMIEventProcess::rpc_contarLlamadasEsperandoRespuesta`)
The counter walks every call whose campaign's queue matches, so it returns the
whole queue's in-flight calls - and that number is then subtracted from *each*
campaign's agent budget. With two campaigns on one queue, campaign A's calls in
transit also shrink campaign B's budget, even when A placed them earlier in the
same cycle: a call counts as pending as soon as Asterisk accepts its Originate
(example 1 under [Standard Flow](#standard-flow-predictiveprogressive-dialing),
cycle 1). This is the opposite direction of G1, so a shared queue can over-dial
and under-dial at the same time.
*Idea:* count pending originates per campaign instead of per queue.

**G5. The overcommit re-cap ignores scheduled calls.** (`:1357-1370`)
The channel budget subtracts `$iScheduledThisCycle` (`:1297`), but the re-cap
resets the count back up to `effectiveMaxCanales`, which does not. A cycle that
also places scheduled calls can exceed `max_canales` by the number of scheduled
calls.
*Idea:* re-cap by `$iChannelBudget` rather than `$effectiveMaxCanales`.

**G6. An empty or zero `max_canales` removes every cap.** (`:685-687`)
It becomes `PHP_INT_MAX`, so rotation stops limiting allocation and the
overcommit re-cap does nothing. The only remaining limit is the ASR floor of
0.20, i.e. up to 5x the agent count per cycle.
*Idea:* fall back to a configured global default instead of "unlimited".

**G7. There is no global trunk cap.** (`max_canales` and `_countActiveCalls()`
at `:2233` are both per campaign)
N campaigns on one trunk can attempt N x `max_canales` channels. Nothing in the
dialer knows the trunk's real channel count.
*Idea:* a dialer-wide or per-trunk concurrent-call ceiling checked before
originate.

**G8. RINGING counts as free in predictive mode.**
(`QueueShadow.class.php:396-404`)
**Fixed in 5.3.27.** A member whose phone is ringing is not a free agent in
any mode, in both QueueShadow and the Predictor fallback: only
`AST_DEVICE_NOT_INUSE` is free. Every Issabel-generated queue runs
`ringinuse=no`, so the queue will not offer that member another call, and no
counter subtracts the call already ringing them - counting them free made
rotation allocate an agent nobody could dial, and the boost and the overcommit
multiplied that inflated base. A member on a live call still counts busy and
about to free (the 5.3.25 rule, unchanged); a merely-ringing member is neither,
and is free again as soon as the device reads NOT_INUSE.

One limit the fix leaves: the ringing member's caller is still counted in
`CLIENTES_ESPERA` and subtracted from the boost, so with busy agents about
to free a campaign whose callback agents are ringing places up to one call
per ringing agent too few, and none at all when no agent is idle.
Agent-type members never ring (app_agent_pool publishes no RINGING), so it
does not apply to them, and the error is on the safe side - too few calls,
not abandoned ones. The exact count is tracked in `TODO.md` as
"Exact Predictive Count for Ringing Callback Agents".

**G9. The agent list is captured once, but campaigns are processed one at a
time.** (intentions collected at `:674-744`, campaigns processed from `:769`)
DB queries and originates happen between campaigns, so the last campaign in the
loop dials on the oldest snapshot. The predictive shares are computed once per
queue before the loop (`:1086`); the base allocation is not re-checked either.
*Idea:* re-check that an allocated agent is still free immediately before
originate.

### Scope notes

- **Incoming campaigns are out of scope by design.** An incoming queue cannot be
  used by an outgoing campaign, and the operating policy is to give each
  direction its own agents. Rotation only ever loops over outgoing campaigns
  (`:682`, `:749`, `:769`), so it holds nothing back for inbound work. If an
  agent were nevertheless a member in both directions, the mechanisms under
  "Confirmed correct" would still exclude them correctly while they are busy;
  the only residual exposure is the moment-of-dial race described in G8/G9.
- **Reserving an agent for a scheduled call pauses them in every queue.**
  `Agente::setReserved()` sends `QueuePause` without a `Queue` field
  (`AMIClientConn.class.php:164-168` marks `Queue` optional), and Asterisk then
  pauses the member in all queues they belong to. This is intended behaviour.

---

## Bug Fixes

### Fix: Two-Budget Call Limiting (2026-03-10)

**Problem 1 (Under-dialing):** The dialer could never reach `max_canales` concurrent calls. With `max_canales=3`, it would oscillate between 1 and 2 active calls.

**Problem 2 (Over-dialing after initial fix):** Removing the pending-call check entirely caused the dialer to place more calls than available agents. With 3 agents, it might place 5+ calls because agents still showed as free during the "Placing" phase.

**Root Cause - The double-counting bug:**
Calls in "Placing" status were counted **twice**:
1. In `_countActiveCalls()` — DB query reduced `effective_max_canales`, which limited agent allocation in rotation
2. In `_contarLlamadasEsperandoRespuesta()` — AMI memory count was subtracted again from `iNumLlamadasColocar`

A call between Originate and OriginateResponse is both "Placing" in DB and pending in AMI — same call reduced budget by 2.

**Root Cause - Why simple removal caused over-dialing:**
When rotation uses `effective_max_canales` (already reduced by active calls), agents with calls in transit are excluded from allocation. But if we use raw `max_canales` for rotation, agents get allocated — then we MUST subtract pending calls to prevent placing multiple calls for the same agent.

**Solution: Two Independent Budgets**

The fix uses separate budgets for channels and agents:

```php
// Pass 1: Store both raw and effective max_canales
$this->_campaignRawMaxCanales[$campaignId] = $maxCanales;           // For rotation
$this->_campaignMaxCanales[$campaignId] = $effectiveMaxCanales;      // For channel cap

// Pass 2: Use RAW max_canales (don't pre-limit by active calls)
$this->_allocatedAgents = $this->_resolveAgentRotation(
    $this->_campaignIntentions,
    $this->_campaignRawMaxCanales  // Not _campaignMaxCanales!
);

// Pass 3: Apply both budgets independently
$iPendingOriginate = $this->_contarLlamadasEsperandoRespuesta($queue);
$iScheduledThisCycle = count($listaLlamadasAgendadas);

// Budget 1: Agent availability
$iAgentBudget = $allocatedAgents + $predictiveBoost - $iPendingOriginate - $iScheduledThisCycle;

// Budget 2: Channel availability
$iChannelBudget = $effectiveMaxCanales - $iScheduledThisCycle;

// Final = minimum of both
$iNumLlamadasColocar = min($iAgentBudget, $iChannelBudget);
```

**Why this works:**
- **Rotation uses raw max_canales:** Agents aren't pre-excluded just because calls are in "Placing" — rotation sees true campaign capacity
- **Agent budget subtracts pending:** After rotation, we subtract calls in transit (`_contarLlamadasEsperandoRespuesta`) to ensure we don't place multiple calls for same agent
- **Channel budget uses effective_max:** We still respect `max_canales - active_calls` as the hard trunk capacity limit

**Example trace (max_canales=3, 5 free agents, 1 Placing call):**

| Phase | Calculation | Result |
|-------|-------------|--------|
| Pass 1 | raw_max=3, active=1, effective=2 | Both stored |
| Pass 2 | rotation wants 5 agents, capped by raw_max=3 | Allocates 3 |
| Pass 3 | agent_budget=3-1(pending)=2, channel_budget=2-0=2 | Places 2 calls ✓ |

**Example trace (max_canales=5, 3 free agents, 0 active, first cycle):**

| Phase | Calculation | Result |
|-------|-------------|--------|
| Pass 1 | raw_max=5, active=0, effective=5 | Both stored |
| Pass 2 | rotation wants 3 agents, capped by raw_max=5 | Allocates 3 |
| Pass 3 | agent_budget=3-0=3, channel_budget=5-0=5 | Places 3 calls ✓ |

**Example trace (max_canales=5, 3 free agents, 3 Placing from previous cycle):**

| Phase | Calculation | Result |
|-------|-------------|--------|
| Pass 1 | raw_max=5, active=3, effective=2 | Both stored |
| Pass 2 | rotation wants 3 agents, capped by raw_max=5 | Allocates 3 |
| Pass 3 | agent_budget=3-3(pending)=0, channel_budget=2-0=2 | Places 0 calls ✓ |

### Fix: Premature Agent Assignment for Callback Extensions

**Problem:** For outgoing campaigns using callback extensions as trunks, agents were being assigned during the dial phase (while customer phone was still ringing), instead of after customer answered.

**Root Cause:** `msg_Link()` in AMIEventProcess.class.php was assigning agents as soon as Bridge/Link event occurred, which happens when:
- For callback extensions: When extension starts dialing customer
- For regular agents: When agent phone rings

**Fix:** AMIEventProcess.class.php
```php
// For outgoing campaigns, only assign agent if call has entered queue
if ($llamada->tipo_llamada == 'outgoing' &&
    in_array($llamada->status, array('Placing', 'Dialing', 'Ringing'))) {
    return FALSE;  // Don't assign yet, customer hasn't answered
}
```

### Fix: Frontend Status Inference Removed

- Removed: Setting all free agents to "Ringing" when calls appear
- Removed: Setting "Ringing" agents to "Free" when calls end
- Removed: Changing other agents to "Free" when one becomes "Busy"
- Result: Frontend now trusts backend for all status updates

### Fix: setReserved() Fatal on PHP 7.4 (2026-08-30)

**Problem:** Reserving an agent for a scheduled (agent-specific) outgoing call
raised a fatal `ArgumentCountError` and would take `AMIEventProcess` down with
it.

**Root cause:** `Agente::setReserved()` called `_incrementarPausas($ami)` with
one argument, but that method has required three since commit `c9a1c5d`
(2017-06-02, "Record pause reason in queue_log"), which added `$reason` and
`$nombre_pausa` and updated `setBreak()` and `setHold()` - but not
`setReserved()`. On PHP 5 this was only a "Missing argument" warning and
execution continued; PHP 7.1+ raises `ArgumentCountError`, and the dialer
installs no `set_exception_handler` and catches no `Throwable`.

**Trigger:** a `calls` row with `agent IS NOT NULL` inside its schedulable
window while that agent is logged in:
`_actualizarLlamadasAgendables()` -> `AMIEventProcess::_agentesAgendables()` ->
`Agente::setReserved()`.

**Fix:** `Agente.class.php` - pass the missing arguments:

```php
$this->_incrementarPausas($ami, NULL, 'Reserved');
```

`$reason` is unused inside `_incrementarPausas()`; only `$nombre_pausa` is used,
as the `Reason` field of the AMI `QueuePause` action, so the pause is now
recorded as `Reserved`.

### Fix: Queue Refresh Keeps the Call Start (2026-10-21)

**Problem:** After each queue refresh, a predictive campaign stopped counting
its busy agents as about to free, so it under-dialed until each busy agent took
a new call. The refresh re-reads every queue with a QueueStatus enumeration -
every 5 minutes (`SQLWorkerProcess::_verificarActualizacionAgentes()`), on each
ECCP `refreshagents` request and on every Asterisk reload - and each one
dropped the call start of every member on a call at that moment.

**Root Cause:** `QueueShadow::msg_QueueMember()` tested
`isset($this->_queues[$Queue][$Location])`, missing the `members` level its own
writes use, so the test was always true for an existing queue. Every re-read
took the new-member branch and rebuilt each member with `LinkStart = NULL`.
`infoPrediccionCola()` (`:400-402`) adds a busy member to
`AGENTES_POR_DESOCUPAR` only when `LinkStart` is set, so the Erlang model saw
no busy agent after a refresh and the campaign's predictive slots fell to 0
(`CampaignProcess.class.php:1170`).

**Fix:** `QueueShadow.class.php:90` - the isset now includes the `members`
level, so a re-read of an existing member keeps its `LinkStart` (the branch
whose comment already said so) and still refreshes `Status` and `Paused`. A
member's first enumeration, an agent login (`msg_QueueMemberAdded`) and
`AgentComplete` are unchanged. ITH scenario `callcenter.27` forces a refresh
during a busy agent's call and requires the predictive boost to hold after it.

### Fix: An On-Call Member Stuck at RINGING Counts Busy (2026-10-23)

**Problem:** chan_sip publishes the 180-Ringing device state as NOT-CACHABLE,
and Asterisk's device-state thread re-publishes that message from a lagging
dispatcher. When the lag outlives the answer, the stale RINGING lands after
the answer's INUSE and nothing else publishes for the device until hangup, so
an answered, bridged member reads RINGING for the whole call. The dialer then
counted that member free in predictive mode and never added them to
`AGENTES_POR_DESOCUPAR`, so the Erlang model saw a busy agent as free.

**Root Cause:** The free test and the about-to-free test in
`infoPrediccionCola()` each matched on the device state alone - RINGING free,
only INUSE/BUSY/RINGINUSE about to free - ignoring the dialer's own
live-call knowledge.

**Fix:** A member is on a live call when it has the dialer's live-call marker
- `LinkStart` in QueueShadow (`:396-397`), the bridged-channel scan in
Predictor (`:263-264`) - and its device state is INUSE, BUSY, RINGINUSE or
RINGING. Such a member is never free and always about to free, with its call
age. The device-state half of the rule stays so a member whose
`AgentComplete` was lost (or whose call the scan missed) is free again as soon
as its device reads NOT_INUSE; a member that is only ringing is unchanged
(G8). ITH scenarios `callcenter.29` and `ccpro.12` feed the dialer's own
classes the production event sequence, without placing a call.

---

## Summary

The Issabel dialer implements a predictive dialing system with:

1. **3-pass architecture:** Intention collection → Fair rotation → Call placement
2. **Fair agent rotation:** N-way rotation for campaigns sharing queues/agents
3. **Two-budget call limiting:** Separate agent and channel budgets prevent both under-dialing and over-dialing
4. **Statistical prediction:** Erlang-based prediction of agent availability
5. **Adaptive behavior:** ASR-based overcommit to compensate for failed calls
6. **Scheduled call support:** Agent-specific calls with reservation, integrated into the budget
7. **Orphan protection:** Startup and periodic cleanup of stuck "Placing" calls
8. **Flexible agent models:** Support for Agent, SIP, IAX2, PJSIP types
9. **Real-time monitoring:** AMI event-driven status updates

Points 2, 4 and 5 are correct individually but interact in ways that are not yet
resolved when several campaigns share one queue. See
[How Balancing, Predictive and Overcommit Interact](#how-balancing-predictive-and-overcommit-interact)
for the arithmetic, the parts confirmed correct, and the seven open items with
improvement ideas (G1 and G4-G9; G2 and G3 were fixed in 5.2.19).
