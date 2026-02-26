# Parallel Campaign Processing Implementation Plan

## Context

Currently, the dialer processes all outgoing campaigns sequentially in a single `CampaignProcess` instance. This limits throughput and prevents campaigns from running simultaneously.

**Problem Example:**
```
Campaign A (queue 615): Agents 1001, 1002, 1003, 1004, 1005  (5 agents)
Campaign B (queue 616): Agents 1001, 1002, 1003, 1006, 1007  (5 agents)
                        Shared: 1001, 1002, 1003
                        Total UNIQUE agents: 7
```

**Required behavior:**
- Both campaigns process **simultaneously** (true parallel)
- Maximum calls = **7** (unique agents), NOT 10 (5+5)
- Each agent receives exactly ONE call per cycle

**Why centralized claims are mandatory:**
Without coordination, parallel workers would each see 5 free agents and generate 10 calls for 7 agents. The ONLY way to handle shared agents in parallel is centralized atomic claims.

## Implementation: Multi-process Workers with Centralized TTL Claims

### Phase 1: Configuration and Dynamic Process Spawning

#### 1.1 Add Configuration Option

**File:** `/opt/issabel/dialer/dialerd.conf`

```ini
[dialer]
; Number of parallel campaign worker processes (1 = sequential/current behavior)
campaign_workers = 1
```

#### 1.2 Move CampaignProcess to Dynamic Spawning

**File:** `/opt/issabel/dialer/HubProcess.class.php`

```php
// Line 41-42: Remove CampaignProcess from fixed tasks
private $_tareasFijas = array('AMIEventProcess', 'ECCPProcess', 'SQLWorkerProcess');

// New property (after line 58)
private $_campaignWorkerCount = 1;

// In inicioPostDemonio() (after reading config):
$this->_campaignWorkerCount = isset($infoConfig['dialer']['campaign_workers'])
    ? max(1, (int)$infoConfig['dialer']['campaign_workers']) : 1;

// In procedimientoDemonio() (after existing dynamic process checks):
if ($this->_revisarTareasDinamicasActivas('CampaignProcess', $this->_campaignWorkerCount))
    $bHayNuevasTareas = TRUE;
```

### Phase 2: Centralized Agent Claims with TTL and Fair Rotation

#### 2.1 Add Agent Claim Tracking to HubProcess

**File:** `/opt/issabel/dialer/HubProcess.class.php`

```php
// New properties (after line 58)
private $_agentesReclamados = array();    // [agent => ['campaign'=>id, 'expires'=>timestamp]]
private $_agentLastUsedBy = array();      // [agent => campaign_id] - persistent, tracks last cycle
private $_agentRotationIndex = array();   // [agent => index] - for fair rotation among 3+ campaigns

// New message handler
public function msg_claimAgents($sFuente, $sDestino, $sNombreMensaje, $iTimestamp, $datos)
{
    // $datos = ['campaign_id' => X, 'agents' => [...]]
    $now = microtime(TRUE);
    $ttl = 5.0;  // Claims expire after 5 seconds (> cycle time of 3s)
    $campaignId = $datos['campaign_id'];
    $result = array(
        'claimed' => array(),
        'conflicts' => 0,
        'deprioritized' => array()  // Agents where this campaign should wait
    );

    // Auto-cleanup expired claims and update rotation tracking
    foreach ($this->_agentesReclamados as $agent => $info) {
        if ($info['expires'] < $now) {
            // Before expiring, record which campaign used this agent
            $this->_agentLastUsedBy[$agent] = $info['campaign'];
            unset($this->_agentesReclamados[$agent]);
        }
    }

    // Process new claims with fair rotation
    foreach ($datos['agents'] as $agent) {
        if (isset($this->_agentesReclamados[$agent])) {
            // Already claimed this cycle by another campaign
            $result['conflicts']++;
            if ($this->DEBUG) {
                $this->_log->output("DEBUG: ".__METHOD__.
                    " agent $agent already claimed by campaign ".
                    $this->_agentesReclamados[$agent]['campaign'].
                    ", conflict for campaign $campaignId");
            }
        } else if ($this->_shouldDeprioritize($agent, $campaignId)) {
            // This campaign used this agent last cycle - deprioritize
            // Don't claim yet, let other campaigns have a chance
            $result['deprioritized'][] = $agent;
            if ($this->DEBUG) {
                $this->_log->output("DEBUG: ".__METHOD__.
                    " agent $agent deprioritized for campaign $campaignId".
                    " (used last cycle, giving others a chance)");
            }
        } else {
            // Claim granted
            $this->_agentesReclamados[$agent] = array(
                'campaign' => $campaignId,
                'expires' => $now + $ttl
            );
            $result['claimed'][] = $agent;
        }
    }

    return $result;
}

/**
 * Check if campaign should be deprioritized for this agent (fair rotation)
 * Returns true if this campaign used the agent last cycle AND other campaigns
 * might want it (giving them a chance to claim first)
 */
private function _shouldDeprioritize($agent, $campaignId)
{
    // If this campaign didn't use the agent last cycle, no deprioritization
    if (!isset($this->_agentLastUsedBy[$agent])) {
        return false;
    }
    if ($this->_agentLastUsedBy[$agent] != $campaignId) {
        return false;
    }

    // This campaign used the agent last cycle - deprioritize to allow rotation
    return true;
}

/**
 * Claim deprioritized agents that no other campaign wanted
 * Called by campaign after initial claim, before generating calls
 */
public function msg_claimDeprioritized($sFuente, $sDestino, $sNombreMensaje, $iTimestamp, $datos)
{
    // $datos = ['campaign_id' => X, 'agents' => [...deprioritized agents...]]
    $now = microtime(TRUE);
    $ttl = 5.0;
    $campaignId = $datos['campaign_id'];
    $result = array('claimed' => array(), 'conflicts' => 0);

    foreach ($datos['agents'] as $agent) {
        if (isset($this->_agentesReclamados[$agent])) {
            // Another campaign claimed it - that's the fair rotation working!
            $result['conflicts']++;
        } else {
            // No one else wanted it - grant to original campaign
            $this->_agentesReclamados[$agent] = array(
                'campaign' => $campaignId,
                'expires' => $now + $ttl
            );
            $result['claimed'][] = $agent;
        }
    }

    return $result;
}
```

**Fair Rotation Logic:**
1. Track which campaign used each agent last cycle (`$_agentLastUsedBy`)
2. If Campaign A used agent X last cycle and tries to claim again:
   - Deprioritize (don't claim immediately)
   - Let other campaigns (B, C, D) have a chance to claim
3. After a short delay, Campaign A can claim deprioritized agents that no one else wanted
4. Works for any number of campaigns sharing an agent

**Example with 3 campaigns sharing agent 1001:**
```
Cycle 1: Campaign A claims 1001 first → uses it
Cycle 2: Campaign A deprioritized → Campaign B claims 1001 → uses it
Cycle 3: Campaign B deprioritized → Campaign C claims 1001 → uses it
Cycle 4: Campaign C deprioritized → Campaign A claims 1001 → uses it
...
```

#### 2.2 Register Message Handlers

**File:** `/opt/issabel/dialer/HubProcess.class.php`

```php
// In inicioPostDemonio():
$this->_tuberia->registrarManejador('*', 'claimAgents', array($this, 'msg_claimAgents'));
$this->_tuberia->registrarManejador('*', 'claimDeprioritized', array($this, 'msg_claimDeprioritized'));
```

### Phase 3: Round-Robin Campaign Distribution

Workers pull campaigns from a centralized queue in HubProcess. This ensures optimal load balancing - fast workers process more campaigns.

#### 3.1 Add Campaign Queue to HubProcess

**File:** `/opt/issabel/dialer/HubProcess.class.php`

```php
// New properties (after line 58)
private $_campaniasEnCola = array();      // Queue of campaigns to process
private $_campaniasCicloTimestamp = 0;    // Current cycle timestamp

// New message handler: Get next campaign for worker
public function msg_getNextCampaign($sFuente, $sDestino, $sNombreMensaje, $iTimestamp, $datos)
{
    // $datos = ['cycle_ts' => timestamp]

    // If new cycle, queue is managed by first request (populated by CampaignProcess)
    if (empty($this->_campaniasEnCola)) {
        return null;  // No more campaigns in this cycle
    }

    // Return next campaign from queue
    return array_shift($this->_campaniasEnCola);
}

// New message handler: Populate campaign queue at cycle start
public function msg_setCampaignQueue($sFuente, $sDestino, $sNombreMensaje, $iTimestamp, $datos)
{
    // $datos = ['campaigns' => [...], 'cycle_ts' => timestamp]

    // Only accept if this is a new cycle (prevents duplicate population)
    if ($datos['cycle_ts'] > $this->_campaniasCicloTimestamp) {
        $this->_campaniasEnCola = $datos['campaigns'];
        $this->_campaniasCicloTimestamp = $datos['cycle_ts'];
        return true;
    }
    return false;  // Already populated by another worker
}
```

#### 3.2 Register Message Handlers

**File:** `/opt/issabel/dialer/HubProcess.class.php`

```php
// In inicioPostDemonio():
$this->_tuberia->registrarManejador('*', 'getNextCampaign', array($this, 'msg_getNextCampaign'));
$this->_tuberia->registrarManejador('*', 'setCampaignQueue', array($this, 'msg_setCampaignQueue'));
```

#### 3.3 Modify CampaignProcess for Round-Robin

**File:** `/opt/issabel/dialer/CampaignProcess.class.php`

```php
// New property
private $_workerId = 0;

// In inicioPostDemonio():
if (preg_match('/CampaignProcess-(\d+)$/', $this->_tuberia->getNombreTarea(), $m)) {
    $this->_workerId = (int)$m[1];
}
$this->_log->output("INFO: CampaignProcess worker {$this->_workerId} started");
```

#### 3.4 Replace foreach with Round-Robin Pull

**File:** `/opt/issabel/dialer/CampaignProcess.class.php`

Replace the campaign foreach loop in `_actualizarCampanias()`:

```php
// Get campaign list (all workers do this, but only first populates queue)
$cicloTimestamp = microtime(TRUE);

// Worker-0 populates the queue (or first worker to reach this point)
if ($this->_workerId == 0) {
    $campaignIds = array();
    foreach ($listaCampanias['outgoing'] as $tuplaCampania) {
        $campaignIds[] = $tuplaCampania['id'];
    }
    $this->_tuberia->HubProcess_setCampaignQueue(array(
        'campaigns' => $campaignIds,
        'cycle_ts' => $cicloTimestamp,
    ));
}

// All workers pull campaigns until queue is empty
while (true) {
    $nextCampaignId = $this->_tuberia->HubProcess_getNextCampaign(array(
        'cycle_ts' => $cicloTimestamp,
    ));

    if (is_null($nextCampaignId)) {
        break;  // No more campaigns in queue
    }

    // Find campaign data by ID
    $tuplaCampania = null;
    foreach ($listaCampanias['outgoing'] as $c) {
        if ($c['id'] == $nextCampaignId) {
            $tuplaCampania = $c;
            break;
        }
    }

    if (is_null($tuplaCampania)) {
        continue;  // Campaign not found (shouldn't happen)
    }

    // Process this campaign
    $oPredictor = new Predictor($this->_ami);
    $this->_actualizarLlamadasCampania($tuplaCampania, $oPredictor);

    // Dispatch pending events
    $this->_multiplex->procesarPaquetes();
    $this->_multiplex->procesarActividad(0);
}
```

### Phase 4: Replace Local Claims with Centralized RPC (with Fair Rotation)

#### 4.1 Modify Conflict Detection in CampaignProcess

**File:** `/opt/issabel/dialer/CampaignProcess.class.php`

Replace the existing local `$_agentesReclamados` logic (lines 634-680) with:

```php
// Agent conflict detection via centralized HubProcess (atomic claim with fair rotation)
if (isset($infoCola['AGENTES_LIBRES_LISTA']) && count($infoCola['AGENTES_LIBRES_LISTA']) > 0) {
    // Normalize all agent interfaces
    $agentesNormalizados = array();
    foreach ($infoCola['AGENTES_LIBRES_LISTA'] as $sAgentInterface) {
        $sNormalizado = $sAgentInterface;
        if (!is_null($this->_compat)) {
            $tmp = $this->_compat->normalizeAgentFromInterface($sAgentInterface);
            if (!is_null($tmp)) $sNormalizado = $tmp;
        }
        $agentesNormalizados[] = $sNormalizado;
    }

    // Phase 1: Initial claim (some agents may be deprioritized for fair rotation)
    $claimResult = $this->_tuberia->HubProcess_claimAgents(array(
        'campaign_id' => $infoCampania['id'],
        'agents' => $agentesNormalizados,
    ));

    $totalConflicts = 0;
    $totalClaimed = 0;

    if (is_array($claimResult)) {
        $totalConflicts = $claimResult['conflicts'];
        $totalClaimed = count($claimResult['claimed']);

        // Phase 2: Try to claim deprioritized agents (fair rotation)
        // These are agents this campaign used last cycle - we gave others a chance first
        if (!empty($claimResult['deprioritized'])) {
            // Small delay to let other campaigns claim first (fair rotation window)
            usleep(50000);  // 50ms

            $depriResult = $this->_tuberia->HubProcess_claimDeprioritized(array(
                'campaign_id' => $infoCampania['id'],
                'agents' => $claimResult['deprioritized'],
            ));

            if (is_array($depriResult)) {
                $totalClaimed += count($depriResult['claimed']);
                $totalConflicts += $depriResult['conflicts'];

                if ($this->DEBUG && $depriResult['conflicts'] > 0) {
                    $this->_log->output("DEBUG: ".__METHOD__." (campaign {$infoCampania['id']}) ".
                        "fair rotation: {$depriResult['conflicts']} agents went to other campaigns");
                }
            }
        }
    }

    // Reduce call count by agents we couldn't claim
    $agentesNoDisponibles = count($agentesNormalizados) - $totalClaimed;
    if ($agentesNoDisponibles > 0) {
        $iNumLlamadasColocarOriginal = $iNumLlamadasColocar;
        $iNumLlamadasColocar -= $agentesNoDisponibles;
        if ($iNumLlamadasColocar < 0) $iNumLlamadasColocar = 0;

        if ($this->DEBUG) {
            $this->_log->output("DEBUG: ".__METHOD__." (campaign {$infoCampania['id']} ".
                "queue {$infoCampania['queue']}) calls: $iNumLlamadasColocarOriginal → $iNumLlamadasColocar ".
                "(claimed: $totalClaimed, conflicts: $totalConflicts, rotated: ".
                ($agentesNoDisponibles - $totalConflicts).")");
        }
    }
}
```

#### 4.2 Remove Local Agent Tracking

**File:** `/opt/issabel/dialer/CampaignProcess.class.php`

```php
// DELETE these lines:
private $_agentesReclamados = array();     // Line ~91
$this->_agentesReclamados = array();       // Line ~529 (reset)
// And the entire local conflict detection block (lines 634-680)
```

## Files to Modify

| File | Changes |
|------|---------|
| `/opt/issabel/dialer/dialerd.conf` | Add `campaign_workers` config option |
| `/opt/issabel/dialer/HubProcess.class.php` | Dynamic spawning, `msg_claimAgents()` with TTL + fair rotation, `msg_claimDeprioritized()`, `msg_getNextCampaign()`, `msg_setCampaignQueue()`, agent rotation tracking |
| `/opt/issabel/dialer/CampaignProcess.class.php` | Worker ID, round-robin pull loop, two-phase RPC claims (initial + deprioritized), remove local `$_agentesReclamados` |

## Architecture Diagram

```
┌─────────────────────────────────────────────────────────────────────────┐
│                            HubProcess                                   │
│                                                                         │
│  $_campaniasEnCola = [3, 4, 5, 6, 7...]   ← Campaign queue (FIFO)      │
│                                                                         │
│  $_agentesReclamados = [                   ← Agent claims with TTL      │
│    'Agent/1001' => ['campaign'=>5, 'expires'=>...],                     │
│    'Agent/1002' => ['campaign'=>5, 'expires'=>...],                     │
│  ]                                                                      │
│                                                                         │
│  msg_getNextCampaign() ─── Returns next campaign from queue             │
│  msg_claimAgents() ─────── ATOMIC: check + claim + return conflicts     │
└─────────────────────────────────┬───────────────────────────────────────┘
                                  │
         ┌────────────────────────┼────────────────────────┐
         │                        │                        │
         ▼                        ▼                        ▼
┌────────────────┐      ┌────────────────┐      ┌────────────────┐
│ CampaignProc-0 │      │ CampaignProc-1 │      │ CampaignProc-2 │
│                │      │                │      │                │
│ while(true):   │      │ while(true):   │      │ while(true):   │
│  getNext() ────│──────│── getNext() ───│──────│── getNext()    │
│  process()     │      │  process()     │      │  process()     │
│  claimAgents() │      │  claimAgents() │      │  claimAgents() │
└────────────────┘      └────────────────┘      └────────────────┘

Workers PULL campaigns when ready (round-robin, load-balanced)
```

## Example: Round-Robin with Fair Rotation for Shared Agents

```
Campaign A (ID=1): agents [1001, 1002, 1003, 1004]  (1004 unique)
Campaign B (ID=2): agents [1001, 1002, 1003, 1005]  (1005 unique)
Campaign C (ID=3): agents [1001, 1002, 1006]        (1006 unique)
                   Shared: [1001, 1002] by all three
                   Shared: [1003] by A and B only

═══════════════════════════════════════════════════════════════════
CYCLE 1 (first run, no history):
═══════════════════════════════════════════════════════════════════
Campaign A processes first:
  - claimAgents([1001,1002,1003,1004])
  - No history → all granted
  - Claims: [1001,1002,1003,1004] → 4 calls

Campaign B processes:
  - claimAgents([1001,1002,1003,1005])
  - 1001,1002,1003 already claimed → conflicts
  - Claims: [1005] → 1 call

Campaign C processes:
  - claimAgents([1001,1002,1006])
  - 1001,1002 already claimed → conflicts
  - Claims: [1006] → 1 call

CYCLE 1 RESULT: A=4 calls, B=1 call, C=1 call (6 total)

═══════════════════════════════════════════════════════════════════
CYCLE 2 (fair rotation kicks in):
═══════════════════════════════════════════════════════════════════
Campaign A processes:
  - claimAgents([1001,1002,1003,1004])
  - 1001,1002,1003 used by A last cycle → DEPRIORITIZED
  - Immediate claims: [1004] only
  - Deprioritized: [1001,1002,1003] (wait 50ms for others)

Campaign B processes:
  - claimAgents([1001,1002,1003,1005])
  - 1001,1002 not used by B last cycle → can claim!
  - 1003 not used by B last cycle → can claim!
  - Claims: [1001,1002,1003,1005] → 4 calls

Campaign C processes:
  - claimAgents([1001,1002,1006])
  - 1001,1002 already claimed by B → conflicts
  - Claims: [1006] → 1 call

Campaign A (after 50ms delay):
  - claimDeprioritized([1001,1002,1003])
  - All claimed by B → conflicts
  - Additional claims: none

CYCLE 2 RESULT: A=1 call, B=4 calls, C=1 call (6 total)
               Agents 1001,1002,1003 ROTATED from A to B ✓

═══════════════════════════════════════════════════════════════════
CYCLE 3 (rotation continues):
═══════════════════════════════════════════════════════════════════
Campaign B: 1001,1002,1003 deprioritized (used last cycle)
Campaign C: can claim 1001,1002 (didn't use last cycle)
Campaign A: can claim 1003 (didn't use last cycle)

CYCLE 3 RESULT: A=2 calls, B=1 call, C=3 calls (6 total)
               Shared agents rotated to C ✓

═══════════════════════════════════════════════════════════════════
PATTERN: Fair rotation among all campaigns sharing agents
         Each campaign gets shared agents roughly 1/N of the time
         Unique agents always go to their campaign
═══════════════════════════════════════════════════════════════════
```

## Backward Compatibility

When `campaign_workers = 1` (default):
- Single CampaignProcess-0 spawns
- All campaigns processed sequentially (current behavior)
- Agent claims still go through HubProcess RPC (unified code path)
- Overhead: ~0.6ms per campaign (negligible)

## Verification

### Test Setup
1. Add `campaign_workers = 3` to dialerd.conf
2. Restart dialer: `systemctl restart issabeldialer`
3. Create campaigns with:
   - 2-3 campaigns sharing THE SAME agents (to test fair rotation)
   - Several single-agent campaigns (unique agents)
4. Activate all campaigns with contacts
5. Let run for multiple cycles (9+ seconds) to observe rotation

### Expected Behavior
- 3 CampaignProcess-N instances spawn
- Round-robin distribution: workers pull campaigns when ready
- **Fair rotation**: shared agents alternate between campaigns each cycle
- Unique agents always go to their campaign
- Total calls per cycle = unique agents (not sum of per-queue)

### Log Commands
```bash
# Verify workers spawned
grep -E "CampaignProcess.*worker.*started" /opt/issabel/dialer/dialerd.log

# Check round-robin distribution (workers pulling campaigns)
grep -E "getNextCampaign|setCampaignQueue" /opt/issabel/dialer/dialerd.log

# Check agent claims, deprioritization, and fair rotation
grep -E "claimAgents|deprioritized|fair rotation|went to other campaigns" /opt/issabel/dialer/dialerd.log

# Check call counts per campaign (should vary as agents rotate)
grep -E "calls:.*claimed:.*conflicts:.*rotated:" /opt/issabel/dialer/dialerd.log

# Monitor errors
grep -E "ERR:|WARN:" /opt/issabel/dialer/dialerd.log | tail -20
```

### Fair Rotation Test
```bash
# Watch shared agent rotation across cycles (run for 15+ seconds)
tail -f /opt/issabel/dialer/dialerd.log | grep -E "campaign.*agent.*(claimed|deprioritized|rotated)"

# Expected pattern for agent 1001 shared by campaigns 5,7,9:
# Cycle 1: campaign 5 claims 1001
# Cycle 2: campaign 5 deprioritized, campaign 7 claims 1001
# Cycle 3: campaign 7 deprioritized, campaign 9 claims 1001
# Cycle 4: campaign 9 deprioritized, campaign 5 claims 1001
# ...
```

## Rollback Plan

If issues arise:
1. Set `campaign_workers = 1` in dialerd.conf
2. Restart: `systemctl restart issabeldialer`
3. Returns to sequential single-worker mode
