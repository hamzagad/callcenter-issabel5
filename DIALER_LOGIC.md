# Issabel Dialer Logic Documentation

## Table of Contents
1. [Outgoing Campaign Call Flow](#outgoing-campaign-call-flow)
2. [3-Pass Call Placement Architecture](#3-pass-call-placement-architecture)
3. [Fair Rotation Algorithm](#fair-rotation-algorithm)
4. [Configuration Options](#configuration-options)
5. [Agent Status Management](#agent-status-management)
6. [Orphaned Call Cleanup](#orphaned-call-cleanup)
7. [Bug Fixes](#bug-fixes)

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

### Pass 1: Collect Campaign Intentions (CampaignProcess.class.php:580-644)

For each active campaign:

1. **Count active calls** via `_countActiveCalls()` — queries DB for calls in `Placing/Ringing/OnQueue/OnHold`
2. **Calculate effective_max_canales** = `max_canales - active_calls` (for channel budget)
3. **Store raw max_canales** — original `max_canales` value (for rotation)
4. **Get free agent list** via `QueueShadow::infoPrediccionCola()` → `AGENTES_LIBRES_LISTA`
5. **Store intentions**: which agents each campaign wants

```php
// CampaignProcess.class.php:597-607
$activeCalls = $this->_countActiveCalls($campaignData['id']);
$effectiveMaxCanales = max(0, $maxCanales - $activeCalls);
$this->_campaignMaxCanales[$campaignData['id']] = $effectiveMaxCanales;
$this->_campaignRawMaxCanales[$campaignData['id']] = $maxCanales;  // For rotation
```

### Pass 2: Fair Rotation (CampaignProcess.class.php:656)

Resolves which agents go to which campaign when multiple campaigns share the same queue/agents:

```php
$this->_allocatedAgents = $this->_resolveAgentRotation(
    $this->_campaignIntentions,
    $this->_campaignRawMaxCanales  // RAW max_canales, not effective_max
);
```

**Key:** Rotation uses **raw** `max_canales` (not reduced by active calls). This ensures agents aren't pre-excluded just because calls are in transit ("Placing"). The per-agent limiting happens later in Pass 3.

See [Fair Rotation Algorithm](#fair-rotation-algorithm) for details.

### Pass 3: Process Each Campaign (CampaignProcess.class.php:930-1340)

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

### How It Works (CampaignProcess.class.php:704-791)

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
**Location:** CampaignProcess.class.php:1087-1138

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

**Status:** WORKING CORRECTLY

---

### 2. Enable Predictive Dialer Behavior

**Database field:** `dialer.predictivo`
**Location:** CampaignProcess.class.php:978-1033

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

**Status:** WORKING CORRECTLY

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

### At Startup (CampaignProcess.class.php:150-164)

Clears ALL calls stuck in "Placing" status from a previous abnormal termination:
```sql
UPDATE calls SET status = 'Failure', failure_cause = 0,
    failure_cause_txt = 'Orphaned call at startup'
WHERE status = 'Placing'
```

### Periodic Cleanup (CampaignProcess.class.php:2367-2389)

Runs every campaign cycle. Clears "Placing" calls older than 5 minutes (300 seconds):
```sql
UPDATE calls SET status = 'Failure', failure_cause = 0,
    failure_cause_txt = 'Orphaned call at startup'
WHERE status = 'Placing' AND datetime_originate < ?
```

This handles calls that got stuck due to missed OriginateResponse events.

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
