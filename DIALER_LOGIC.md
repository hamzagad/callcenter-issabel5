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

```
1. Campaign Process: Collect campaign intentions (Pass 1)
   └─> _countActiveCalls() counts DB calls in Placing/Ringing/OnQueue/OnHold
   └─> effective_max_canales = max_canales - active_calls
   └─> QueueShadow::infoPrediccionCola() returns:
       - AGENTES_LIBRES (status: NOT_INUSE only; or NOT_INUSE+RINGING if predictive)
       - AGENTES_LIBRES_LISTA (list of free agent interfaces for rotation)
       - AGENTES_POR_DESOCUPAR (busy agents, predicted to finish soon)
       - CLIENTES_ESPERA (calls already in queue)

2. Fair Rotation: Resolve agent allocation (Pass 2)
   └─> _resolveAgentRotation() allocates free agents to campaigns
   └─> Respects effective_max_canales per campaign
   └─> Shared agents rotated fairly across campaigns

3. Process campaigns with allocation (Pass 3)
   └─> iNumLlamadasColocar = numAllocatedAgents
   └─> + predictive boost (if enabled)
   └─> Capped by effective_max_canales
   └─> - scheduled calls placed in this cycle
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
1. Campaign Process: Check for scheduled calls
   └─> _actualizarLlamadasAgendables() queries calls table WHERE agent IS NOT NULL

2. Agent reservation
   └─> AMIEventProcess::_agentesAgendables() marks agent as 'reserved'
   └─> Agent must be: logged-in, no active call, no pending scheduled call, only 1 pause (reserved)

3. Place scheduled call
   └─> Call is linked to specific agent via agente_agendado property
   └─> Scheduled calls are placed BEFORE regular calls in the same cycle
   └─> Their count is subtracted from regular call budget (they consume channels
       not yet reflected in Pass 1's _countActiveCalls)

4. Flow continues as standard, but only assigned agent can take this call
```

---

## 3-Pass Call Placement Architecture

The campaign process runs every 3 seconds (`INTERVALO_REVISION_CAMPANIAS`). Each cycle uses a 3-pass approach:

### Pass 1: Collect Campaign Intentions (CampaignProcess.class.php:664-735)

For each active campaign:

1. **Count active calls** via `_countActiveCalls()` — queries DB for calls in `Placing/Ringing/OnQueue/OnHold`
2. **Calculate effective_max_canales** = `max_canales - active_calls` (for channel budget)
3. **Store raw max_canales** — original `max_canales` value (for rotation)
4. **Get free agent list** via `QueueShadow::infoPrediccionCola()` → `AGENTES_LIBRES_LISTA`
5. **Store intentions**: which agents each campaign wants

```php
// CampaignProcess.class.php:681-690
$activeCalls = $this->_countActiveCalls($campaignData['id']);
$effectiveMaxCanales = max(0, $maxCanales - $activeCalls);
$this->_campaignMaxCanales[$campaignData['id']] = $effectiveMaxCanales;
$this->_campaignRawMaxCanales[$campaignData['id']] = $maxCanales;  // For rotation
```

### Pass 2: Fair Rotation (CampaignProcess.class.php:739)

Resolves which agents go to which campaign when multiple campaigns share the same queue/agents:

```php
$this->_allocatedAgents = $this->_resolveAgentRotation(
    $this->_campaignIntentions,
    $this->_campaignRawMaxCanales  // RAW max_canales, not effective_max
);
```

**Key:** Rotation uses **raw** `max_canales` (not reduced by active calls). This ensures agents aren't pre-excluded just because calls are in transit ("Placing"). The per-agent limiting happens later in Pass 3.

See [Fair Rotation Algorithm](#fair-rotation-algorithm) for details.

### Pass 3: Process Each Campaign (CampaignProcess.class.php:967-1394)

For each campaign with allocated agents:

```php
// Base: one call per allocated agent
$iNumLlamadasColocar = $numAllocatedAgents;

// + Predictive boost (if enabled)
$iNumLlamadasColocar += $iPredictiveBoost;

// === TWO-BUDGET CALL LIMITING ===
// Budget 1 (Agent): allocated agents - calls in transit to agents
$iPendingOriginate = $this->_contarLlamadasEsperandoRespuesta($queue);
$iScheduledThisCycle = count($listaLlamadasAgendadas);
$iAgentBudget = $iNumLlamadasColocar - $iPendingOriginate - $iScheduledThisCycle;

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
$_predictiveSlotsUsed    // [queue => count] - prevents predictive double-counting
```

---

## Fair Rotation Algorithm

### Purpose

When multiple campaigns share the same queue (and thus the same agents), the rotation algorithm ensures fair distribution of agents across campaigns while respecting each campaign's `max_canales` limit.

### How It Works (CampaignProcess.class.php:793-878)

#### Step 1: Build Reverse Map
```
Agent/4001 → [Campaign A]           (unique)
Agent/4002 → [Campaign A, Campaign B]  (shared)
Agent/4003 → [Campaign B]           (unique)
```

#### Step 2: Allocate Unique Agents
Agents wanted by only one campaign are allocated directly, up to that campaign's `effective_max_canales`:

```php
if (count($campaigns) == 1) {
    $campaignId = $campaigns[0];
    if ($allocationCount[$campaignId] < $maxCanales[$campaignId]) {
        $allocated[$campaignId][] = $agent;
        $allocationCount[$campaignId]++;
    }
}
```

#### Step 3: Allocate Shared Agents with Rotation
Agents wanted by multiple campaigns use persistent rotation state:

```php
// _getRotationWinnerWithCapacity() cycles through campaigns in order
// Advances index each cycle so next time a different campaign wins
$rotation['index']++;  // persists across cycles
```

If the winning campaign is at capacity, it tries the next campaign in rotation order.

### Example: 2 Campaigns, 1 Shared Agent

```
Cycle 1: Agent/4002 → Campaign A (rotation index 0)
Cycle 2: Agent/4002 → Campaign B (rotation index 1)
Cycle 3: Agent/4002 → Campaign A (rotation index 2 % 2 = 0)
...
```

### Single Campaign Behavior

With only one campaign, rotation degenerates to simple allocation limited by raw `max_canales`. The agent budget applied in Pass 3 ensures the final call count respects the effective (reduced) channel capacity.

---

## Configuration Options

### 1. Enable Overcommit of Outgoing Calls

**Database field:** `dialer.overcommit`
**Location:** CampaignProcess.class.php:1134-1185

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
**Location:** CampaignProcess.class.php:1019-1073

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

2. Predictive boost is calculated per queue and tracked to prevent double-counting when multiple campaigns share a queue:
   ```php
   $iPredictiveBoost = $predictiveAgents - $waitingClients - $alreadyClaimed;
   $this->_predictiveSlotsUsed[$sQueue] += $iPredictiveBoost;
   ```

3. Considers:
   - **avg_duracion**: Average call duration from campaign history
   - **avg_contestar**: Average time for customer to answer
   - **prob_atencion** (QoS): Service quality threshold (default: 97%)

4. Requirements:
   - Campaign must have MIN_MUESTRAS (10) completed calls for Erlang prediction
   - Without enough samples, falls back to simple prediction without Erlang formula

**Mathematical Model:**
```
P(agent_free_before_customer_answers) = Erlang_CDF(
    time_total = avg_answer_time + current_call_time,
    k = 1,
    lambda = 1 / avg_call_duration
)

If P >= 97% → count agent as available
```

**Status:** The Erlang model is correct. The *distribution* of the predicted
slots is not balanced: the first campaign processed claims all of them - see
[How Balancing, Predictive and Overcommit Interact](#how-balancing-predictive-and-overcommit-interact).

---

### 3. max_canales (Maximum Channels per Campaign)

**Database field:** `campaign.max_canales`

**Purpose:** Limit the maximum number of concurrent calls for a campaign (trunk capacity limit).

**How it works:**
- In Pass 1, `effective_max_canales = max_canales - active_calls` is calculated
- `active_calls` counts calls in statuses: Placing, Ringing, OnQueue, OnHold
- `effective_max_canales` limits agent allocation in Pass 2 (rotation)
- Also caps `iNumLlamadasColocar` in Pass 3 (including after overcommit)

**Example:** max_canales=3, 1 call in OnQueue:
- effective_max = 3 - 1 = 2
- Rotation allocates at most 2 agents
- At most 2 new calls can be placed

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

**Non-predictive mode:** Only `AST_DEVICE_NOT_INUSE` (1) counts as free
**Predictive mode:** `AST_DEVICE_NOT_INUSE` (1) and `AST_DEVICE_RINGING` (6) count as free

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

### At Startup (CampaignProcess.class.php:150-170)

Clears ALL calls stuck in "Placing" status from a previous abnormal termination:
```sql
UPDATE calls SET status = 'Failure', failure_cause = 0,
    failure_cause_txt = 'Orphaned call at startup'
WHERE status = 'Placing'
```

### Periodic Cleanup (_cleanOrphanedPlacingCalls, CampaignProcess.class.php:1960)

Runs every campaign cycle. Clears "Placing" calls older than 5 minutes (300 seconds):
```sql
UPDATE calls SET status = 'Failure', failure_cause = 0,
    failure_cause_txt = 'Orphaned call at startup'
WHERE status = 'Placing' AND datetime_originate < ?
```

This handles calls that got stuck due to missed OriginateResponse events.

---

## How Balancing, Predictive and Overcommit Interact

Each of the three features is correct on its own. This section records how they
behave **together**, which is where the open problems are. It is written as
input for a future improvement, not as a description of a broken system.

### The order of operations

Balancing runs first, prediction adds to its result, and overcommit multiplies
that:

```
allocated_agents = fair rotation result          (Pass 2, per campaign)
        + predictive_boost                       (added,      :1019-1060)
        - pending_originate - scheduled          (agent budget,   :1077-1088)
   min  effective_max_canales - scheduled        (channel budget, :1090-1104)
        / ASR   (floor 0.20)                     (overcommit,     :1134-1154)
   cap  effective_max_canales                    (re-cap,         :1158-1163)
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
  become free".** `AGENTES_POR_DESOCUPAR` requires a per-queue `LinkStart`
  (`QueueShadow.class.php:400-402`), and `LinkStart` is set only by
  `AgentConnect` **on that specific queue**. An agent talking on queue A shows
  `INUSE` in queue B but with `LinkStart = NULL`, so queue B's prediction skips
  them. This is the conservative direction.
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

**G1. Overcommit is not bounded by the fair share.** (`:1134-1185`)
The ASR division happens after `min(agent_budget, channel_budget)` and is
re-capped only by `effective_max_canales`, never by the campaign's allocated
agent count. Two campaigns on one queue therefore dial in proportion to their
*ASR*, not to their allocation: the campaign with the **worse** ASR places more
calls, and since all those calls compete for the same agents, it statistically
wins more agents than rotation gave it.
*Idea:* cap the post-overcommit count by a per-campaign ceiling such as
`allocated_agents / ASR`, in addition to the channel budget.

**G2. Predictive slots are first-come, not rotated.** (`:1050-1058`)
`_predictiveSlotsUsed[$queue]` (`:1058`) is claimed by whichever campaign is processed
first; the second campaign on the same queue computes
`boost = predicted - waiting - already_claimed` and gets 0. Campaign order is
the DB list order and is stable, so the same campaign wins the boost every
cycle.
*Idea:* rotate the predicted slots the way agents are rotated, or split them in
proportion to each campaign's allocation.

**G3. Waiting callers are subtracted only from that same first campaign.**
(`:1046`, fed by `QueueShadow.class.php:370`)
`CLIENTES_ESPERA` is queue-wide but is applied inside the per-campaign boost, so
it only ever reduces the campaign that claimed the boost.
*Idea:* subtract the waiting callers once at queue level, before splitting.

**G4. Pending originates are counted per queue but subtracted per campaign.**
(`:1085-1087`, `AMIEventProcess::rpc_contarLlamadasEsperandoRespuesta`)
The counter walks every call whose campaign's queue matches, so it returns the
whole queue's in-flight calls - and that number is then subtracted from *each*
campaign's agent budget. With two campaigns on one queue, campaign A's calls in
transit also shrink campaign B's budget. This is the opposite direction of G1,
so a shared queue can over-dial and under-dial at the same time.
*Idea:* count pending originates per campaign instead of per queue.

**G5. The overcommit re-cap ignores scheduled calls.** (`:1158-1163`)
The channel budget subtracts `$iScheduledThisCycle` (`:1098`), but the re-cap
resets the count back up to `effectiveMaxCanales`, which does not. A cycle that
also places scheduled calls can exceed `max_canales` by the number of scheduled
calls.
*Idea:* re-cap by `$iChannelBudget` rather than `$effectiveMaxCanales`.

**G6. An empty or zero `max_canales` removes every cap.** (`:675-677`)
It becomes `PHP_INT_MAX`, so rotation stops limiting allocation and the
overcommit re-cap does nothing. The only remaining limit is the ASR floor of
0.20, i.e. up to 5x the agent count per cycle.
*Idea:* fall back to a configured global default instead of "unlimited".

**G7. There is no global trunk cap.** (`max_canales` and `_countActiveCalls()`
at `:2019` are both per campaign)
N campaigns on one trunk can attempt N x `max_canales` channels. Nothing in the
dialer knows the trunk's real channel count.
*Idea:* a dialer-wide or per-trunk concurrent-call ceiling checked before
originate.

**G8. RINGING counts as free in predictive mode.**
(`QueueShadow.class.php:389-392`)
An agent whose phone is already ringing still enters `AGENTES_LIBRES_LISTA`, so
rotation allocates them again; the boost and the overcommit then multiply that
inflated base. Tracked separately in `TODO.md` as "RINGING-as-Free Analysis".
*Idea:* keep RINGING out of the rotation list while still allowing it in the
prediction, so it inflates the estimate but not the allocation.

**G9. The agent list is captured once, but campaigns are processed one at a
time.** (intentions collected at `:664-735`, campaigns processed from `:748`)
DB queries and originates happen between campaigns, so the last campaign in the
loop dials on the oldest snapshot. The predictive boost re-queries the queue
fresh (`:1023-1031`); the base allocation does not.
*Idea:* re-check that an allocated agent is still free immediately before
originate.

### Scope notes

- **Incoming campaigns are out of scope by design.** An incoming queue cannot be
  used by an outgoing campaign, and the operating policy is to give each
  direction its own agents. Rotation only ever loops over outgoing campaigns
  (`:672`, `:739`, `:751`), so it holds nothing back for inbound work. If an
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
for the arithmetic, the parts confirmed correct, and the nine open items with
improvement ideas.
