# Fair Rotation for Shared Agents - Implementation Plan

## Context

When multiple campaigns share the same agents, the first campaign to process always claims all shared agents, leaving nothing for other campaigns. This creates unfair distribution.

**Current Problem:**
```
Campaign A: agents [1001, 1002, 1003]
Campaign B: agents [1001, 1002, 1003]
Campaign C: agents [1001, 1002, 1003]  ← All share same agents!

Every cycle:
- Campaign A processes first → claims all agents → 3 calls
- Campaign B processes second → all claimed → 0 calls
- Campaign C processes third → all claimed → 0 calls

B and C NEVER get to use the agents.
```

**Desired Behavior (N-way rotation):**
```
Cycle 1: Campaign A gets agents → 3 calls
Cycle 2: Campaign B gets agents → 3 calls  (ROTATED!)
Cycle 3: Campaign C gets agents → 3 calls  (ROTATED!)
Cycle 4: Campaign A gets agents → 3 calls  (back to A)
...

Pattern: A → B → C → A → B → C → ...
```

## Solution: Two-Pass Processing with Rotation Index

Keep everything in single CampaignProcess (no multi-worker, no HubProcess changes).

### Key Concept

1. **Pass 1**: Discover which campaigns want which agents (collect intentions)
2. **Allocate**: For shared agents, use rotation index to determine whose turn
3. **Pass 2**: Process campaigns with their allocated agents
4. **Advance**: Increment rotation index for used agents

## Implementation

### File: `/opt/issabel/dialer/CampaignProcess.class.php`

#### Step 1: Add Properties

```php
// After existing properties (around line 91)

/* Agents claimed by campaigns in current review cycle
 * Agentes reclamados por campañas en el ciclo de revisión actual */
private $_agentesReclamados = array();  // [agent => campaign_id] - KEEP EXISTING

/* N-way rotation tracking (persistent across cycles)
 * Seguimiento de rotación N-vías (persistente entre ciclos) */
private $_agentRotation = array();  // [agent => ['campaigns'=>[A,B,C], 'index'=>0]]

/* Campaign intentions for current cycle (Pass 1)
 * Intenciones de campaña para el ciclo actual (Paso 1) */
private $_campaignIntentions = array();  // [campaign_id => [agent1, agent2, ...]]

/* Allocated agents after rotation resolution
 * Agentes asignados después de la resolución de rotación */
private $_allocatedAgents = array();  // [campaign_id => [agent1, agent2, ...]]
```

#### Step 2: Replace Campaign Loop with Two-Pass Processing

Replace the entire campaign foreach loop in `_actualizarCampanias()` (around lines 528-558) with:

```php
// ============================================================
// PASS 1: Collect campaign intentions (which agents they want)
// PASO 1: Recopilar intenciones de campaña (qué agentes quieren)
// ============================================================
$this->_campaignIntentions = array();
$this->_agentesReclamados = array();

foreach ($listaCampanias['outgoing'] as $campaignData) {
    $queueInfo = $this->_tuberia->AMIEventProcess_infoPrediccionCola($campaignData['queue']);
    if (is_null($queueInfo)) {
        $oPredictor = new Predictor($this->_ami);
        if ($oPredictor->examinarColas(array($campaignData['queue']))) {
            $queueInfo = $oPredictor->infoPrediccionCola($campaignData['queue']);
        }
    }

    if (!is_null($queueInfo) && isset($queueInfo['AGENTES_LIBRES_LISTA'])) {
        $normalizedAgents = array();
        foreach ($queueInfo['AGENTES_LIBRES_LISTA'] as $agentInterface) {
            $normalizedAgent = $agentInterface;
            if (!is_null($this->_compat)) {
                $tmp = $this->_compat->normalizeAgentFromInterface($agentInterface);
                if (!is_null($tmp)) $normalizedAgent = $tmp;
            }
            $normalizedAgents[] = $normalizedAgent;
        }
        $this->_campaignIntentions[$campaignData['id']] = $normalizedAgents;
    }
}

// ============================================================
// ALLOCATE: Resolve shared agents using N-way rotation
// ASIGNAR: Resolver agentes compartidos usando rotación N-vías
// ============================================================
$this->_allocatedAgents = $this->_resolveAgentRotation($this->_campaignIntentions);

// ============================================================
// PASS 2: Process campaigns with allocated agents
// PASO 2: Procesar campañas con agentes asignados
// ============================================================
foreach ($listaCampanias['outgoing'] as $campaignData) {
    $oPredictor = new Predictor($this->_ami);
    $this->_processCampaignWithAllocation($campaignData, $oPredictor);

    // Dispatch pending events / Despachar eventos pendientes
    $this->_ociosoSinEventos = !$this->_multiplex->procesarPaquetes();
    $this->_multiplex->procesarActividad(0);
    $this->_iTimestampUltimaRevisionCampanias = time();
}
```

#### Step 3: Add N-Way Rotation Resolution Method

```php
/**
 * Resolve agent allocation using N-way rotation.
 * Each shared agent rotates among all campaigns that want it.
 *
 * Resolver asignación de agentes usando rotación N-vías.
 * Cada agente compartido rota entre todas las campañas que lo quieren.
 *
 * @param array $intentions [campaign_id => [agents...]]
 * @return array [campaign_id => [allocated_agents...]]
 */
private function _resolveAgentRotation($intentions)
{
    $allocated = array();
    $agentToCampaigns = array();  // [agent => [campaign_ids...]]

    // Build reverse map: which campaigns want each agent
    // Construir mapa inverso: qué campañas quieren cada agente
    foreach ($intentions as $campaignId => $agents) {
        $allocated[$campaignId] = array();
        foreach ($agents as $agent) {
            if (!isset($agentToCampaigns[$agent])) {
                $agentToCampaigns[$agent] = array();
            }
            $agentToCampaigns[$agent][] = $campaignId;
        }
    }

    // Allocate each agent / Asignar cada agente
    foreach ($agentToCampaigns as $agent => $campaigns) {
        if (count($campaigns) == 1) {
            // Only one campaign wants this agent - assign directly
            // Solo una campaña quiere este agente - asignar directamente
            $allocated[$campaigns[0]][] = $agent;
            $this->_agentesReclamados[$agent] = $campaigns[0];
        } else {
            // Multiple campaigns want this agent - use rotation
            // Múltiples campañas quieren este agente - usar rotación
            $winningCampaign = $this->_getRotationWinner($agent, $campaigns);
            $allocated[$winningCampaign][] = $agent;
            $this->_agentesReclamados[$agent] = $winningCampaign;

            if ($this->DEBUG) {
                $this->_log->output("DEBUG: ".__METHOD__.
                    " agent $agent shared by campaigns [".implode(',', $campaigns).
                    "] -> assigned to campaign $winningCampaign (rotation) | ".
                    "agente $agent compartido por campañas [".implode(',', $campaigns).
                    "] -> asignado a campaña $winningCampaign (rotación)");
            }
        }
    }

    return $allocated;
}

/**
 * Get the winning campaign for a shared agent using N-way rotation.
 * Obtener la campaña ganadora para un agente compartido usando rotación N-vías.
 *
 * @param string $agent The agent identifier
 * @param array $campaigns List of campaign IDs that want this agent
 * @return int The campaign ID that wins this rotation
 */
private function _getRotationWinner($agent, $campaigns)
{
    sort($campaigns);  // Ensure consistent order / Asegurar orden consistente
    $campaignsKey = implode(',', $campaigns);

    // Initialize or update rotation tracking for this agent
    // Inicializar o actualizar seguimiento de rotación para este agente
    if (!isset($this->_agentRotation[$agent]) ||
        $this->_agentRotation[$agent]['key'] !== $campaignsKey) {
        // New agent or campaign set changed - initialize rotation
        // Nuevo agente o conjunto de campañas cambió - inicializar rotación
        $this->_agentRotation[$agent] = array(
            'key' => $campaignsKey,
            'campaigns' => $campaigns,
            'index' => 0,
        );
    }

    // Get current winner based on rotation index
    // Obtener ganador actual basado en índice de rotación
    $rotation = &$this->_agentRotation[$agent];
    $winnerIndex = $rotation['index'] % count($rotation['campaigns']);
    $winner = $rotation['campaigns'][$winnerIndex];

    // Advance rotation for next cycle / Avanzar rotación para próximo ciclo
    $rotation['index']++;

    if ($this->DEBUG) {
        $this->_log->output("DEBUG: ".__METHOD__.
            " agent $agent rotation: index={$rotation['index']}, ".
            "campaigns=[".implode(',', $campaigns)."], winner=$winner | ".
            "agente $agent rotación: índice={$rotation['index']}, ".
            "campañas=[".implode(',', $campaigns)."], ganador=$winner");
    }

    return $winner;
}
```

#### Step 4: Add Campaign Processing with Allocation

```php
/**
 * Process campaign calls using pre-allocated agents.
 * Procesar llamadas de campaña usando agentes pre-asignados.
 */
private function _processCampaignWithAllocation($campaignInfo, $oPredictor)
{
    $campaignId = $campaignInfo['id'];

    // Get allocated agents for this campaign
    // Obtener agentes asignados para esta campaña
    $allocatedAgents = isset($this->_allocatedAgents[$campaignId])
        ? $this->_allocatedAgents[$campaignId]
        : array();

    $numAllocatedAgents = count($allocatedAgents);

    if ($numAllocatedAgents == 0) {
        if ($this->DEBUG) {
            $this->_log->output("DEBUG: ".__METHOD__.
                " (campaign $campaignId) no agents allocated this cycle | ".
                "(campaña $campaignId) ningún agente asignado en este ciclo");
        }
        return FALSE;
    }

    // Build trunk dial pattern / Construir patrón de marcado de troncal
    $trunkData = $this->_construirPlantillaMarcado($campaignInfo['trunk']);
    if (is_null($trunkData)) {
        return FALSE;
    }

    // Get schedulable calls / Obtener llamadas programables
    $scheduledCalls = $this->_actualizarLlamadasAgendables($campaignInfo, $trunkData);

    // Calculate how many calls to place (limited by allocated agents)
    // Calcular cuántas llamadas colocar (limitado por agentes asignados)
    $numCallsToPlace = $numAllocatedAgents;

    // Apply max_canales limit if set / Aplicar límite max_canales si está configurado
    if (!is_null($campaignInfo['max_canales']) && $campaignInfo['max_canales'] > 0) {
        if ($numCallsToPlace > $campaignInfo['max_canales']) {
            $numCallsToPlace = $campaignInfo['max_canales'];
        }
    }

    // Subtract calls waiting for OriginateResponse
    // Restar llamadas esperando OriginateResponse
    $numWaitingResponse = count($scheduledCalls) +
        $this->_contarLlamadasEsperandoRespuesta($campaignInfo['queue']);
    if ($numCallsToPlace > $numWaitingResponse) {
        $numCallsToPlace -= $numWaitingResponse;
    } else {
        $numCallsToPlace = 0;
    }

    if ($this->DEBUG) {
        $this->_log->output("DEBUG: ".__METHOD__.
            " (campaign $campaignId queue {$campaignInfo['queue']}) ".
            "allocated agents: $numAllocatedAgents, calls to place: $numCallsToPlace | ".
            "(campaña $campaignId cola {$campaignInfo['queue']}) ".
            "agentes asignados: $numAllocatedAgents, llamadas a colocar: $numCallsToPlace");
    }

    if (count($scheduledCalls) <= 0 && $numCallsToPlace <= 0) {
        return FALSE;
    }

    // Place scheduled calls first / Colocar llamadas programadas primero
    foreach ($scheduledCalls as $scheduledCall) {
        $this->_colocarLlamadaAgendada($campaignInfo, $scheduledCall, $trunkData);
    }

    // Place regular calls / Colocar llamadas regulares
    if ($numCallsToPlace > 0) {
        $this->_colocarLlamadas($campaignInfo, $numCallsToPlace, $trunkData);
    }

    return TRUE;
}
```

## Files to Modify

| File | Changes |
|------|---------|
| `/opt/issabel/dialer/CampaignProcess.class.php` | Add rotation tracking properties, two-pass processing, `_resolverRotacionAgentes()`, `_getRotationWinner()`, `_actualizarLlamadasCampaniaConAsignacion()` |

**No changes needed to:**
- HubProcess
- dialerd.conf
- QueueShadow
- Predictor

## Example Flow: N-Way Rotation (3 Campaigns)

```
Campaign A (ID=5): agents [1001, 1002, 1003]
Campaign B (ID=7): agents [1001, 1002, 1003]
Campaign C (ID=9): agents [1001, 1002, 1003]

All three campaigns share the same agents!

═══════════════════════════════════════════════════════════════════
CYCLE 1:
═══════════════════════════════════════════════════════════════════
PASS 1 - Collect intentions:
  Campaign 5 wants: [1001, 1002, 1003]
  Campaign 7 wants: [1001, 1002, 1003]
  Campaign 9 wants: [1001, 1002, 1003]

ALLOCATE - Rotation resolution:
  Agent 1001: shared by [5,7,9], rotation index=0 → winner=5
  Agent 1002: shared by [5,7,9], rotation index=0 → winner=5
  Agent 1003: shared by [5,7,9], rotation index=0 → winner=5

  Allocation: Campaign 5=[1001,1002,1003], Campaign 7=[], Campaign 9=[]

PASS 2 - Process:
  Campaign 5: 3 agents allocated → 3 calls
  Campaign 7: 0 agents allocated → 0 calls
  Campaign 9: 0 agents allocated → 0 calls

RESULT: A=3, B=0, C=0

═══════════════════════════════════════════════════════════════════
CYCLE 2:
═══════════════════════════════════════════════════════════════════
ALLOCATE - Rotation resolution:
  Agent 1001: shared by [5,7,9], rotation index=1 → winner=7
  Agent 1002: shared by [5,7,9], rotation index=1 → winner=7
  Agent 1003: shared by [5,7,9], rotation index=1 → winner=7

  Allocation: Campaign 5=[], Campaign 7=[1001,1002,1003], Campaign 9=[]

RESULT: A=0, B=3, C=0 (ROTATED to B!)

═══════════════════════════════════════════════════════════════════
CYCLE 3:
═══════════════════════════════════════════════════════════════════
ALLOCATE - Rotation resolution:
  Agent 1001: shared by [5,7,9], rotation index=2 → winner=9
  Agent 1002: shared by [5,7,9], rotation index=2 → winner=9
  Agent 1003: shared by [5,7,9], rotation index=2 → winner=9

  Allocation: Campaign 5=[], Campaign 7=[], Campaign 9=[1001,1002,1003]

RESULT: A=0, B=0, C=3 (ROTATED to C!)

═══════════════════════════════════════════════════════════════════
CYCLE 4:
═══════════════════════════════════════════════════════════════════
ALLOCATE - Rotation resolution:
  Agent 1001: shared by [5,7,9], rotation index=3 (3%3=0) → winner=5
  Agent 1002: shared by [5,7,9], rotation index=3 (3%3=0) → winner=5
  Agent 1003: shared by [5,7,9], rotation index=3 (3%3=0) → winner=5

RESULT: A=3, B=0, C=0 (Back to A!)

═══════════════════════════════════════════════════════════════════
PATTERN: A → B → C → A → B → C → A → B → C → ...

True N-way round-robin among ALL campaigns sharing agents!
═══════════════════════════════════════════════════════════════════
```

## Example: Mixed Shared and Unique Agents

```
Campaign A: agents [1001, 1002, 1003, 1004]  (1004 unique to A)
Campaign B: agents [1001, 1002, 1005]        (1005 unique to B)
Campaign C: agents [1001, 1006]              (1006 unique to C)

Shared agents:
  1001: shared by A, B, C (3-way rotation)
  1002: shared by A, B (2-way rotation)

Unique agents:
  1003: only A
  1004: only A
  1005: only B
  1006: only C

═══════════════════════════════════════════════════════════════════
CYCLE 1:
═══════════════════════════════════════════════════════════════════
  Agent 1001: [A,B,C] index=0 → A
  Agent 1002: [A,B] index=0 → A
  Agent 1003: [A] only → A
  Agent 1004: [A] only → A
  Agent 1005: [B] only → B
  Agent 1006: [C] only → C

  Allocation: A=[1001,1002,1003,1004], B=[1005], C=[1006]
  RESULT: A=4 calls, B=1 call, C=1 call

═══════════════════════════════════════════════════════════════════
CYCLE 2:
═══════════════════════════════════════════════════════════════════
  Agent 1001: [A,B,C] index=1 → B
  Agent 1002: [A,B] index=1 → B
  Agent 1003: [A] only → A
  Agent 1004: [A] only → A
  Agent 1005: [B] only → B
  Agent 1006: [C] only → C

  Allocation: A=[1003,1004], B=[1001,1002,1005], C=[1006]
  RESULT: A=2 calls, B=3 calls, C=1 call

═══════════════════════════════════════════════════════════════════
CYCLE 3:
═══════════════════════════════════════════════════════════════════
  Agent 1001: [A,B,C] index=2 → C
  Agent 1002: [A,B] index=2 (2%2=0) → A
  Agent 1003: [A] only → A
  Agent 1004: [A] only → A
  Agent 1005: [B] only → B
  Agent 1006: [C] only → C

  Allocation: A=[1002,1003,1004], B=[1005], C=[1001,1006]
  RESULT: A=3 calls, B=1 call, C=2 calls

Each agent rotates independently based on how many campaigns share it!
```

## Verification

### Test Setup
1. Create 3 campaigns with THE SAME agents (to test N-way rotation)
2. Create campaigns with partially overlapping agents (to test independent rotation)
3. Activate all with contacts
4. Watch for 6+ cycles (18+ seconds) to see full rotation

### Expected Behavior
- Shared agents rotate among ALL campaigns that want them
- Each campaign gets roughly 1/N of cycles for N-way shared agents
- Unique agents always go to their campaign
- Each agent rotates independently

### Log Commands
```bash
# Watch N-way rotation in action
tail -f /opt/issabel/dialer/dialerd.log | grep -E "rotation|allocated|winner"

# Check rotation index progression
grep -E "rotation: index=" /opt/issabel/dialer/dialerd.log | tail -30

# See allocation per campaign
grep -E "allocated agents:" /opt/issabel/dialer/dialerd.log | tail -20

# Verify rotation pattern (should see A→B→C→A→B→C...)
grep -E "agent.*→ assigned to campaign" /opt/issabel/dialer/dialerd.log | tail -30
```

### Test N-Way Rotation
```bash
# For 3 campaigns (5,7,9) sharing agents, expect:
# Cycle 1: winner=5
# Cycle 2: winner=7
# Cycle 3: winner=9
# Cycle 4: winner=5 (wraps around)

grep "winner=" /opt/issabel/dialer/dialerd.log | awk '{print $NF}' | tail -12
# Should show: 5,5,5, 7,7,7, 9,9,9, 5,5,5 (if 3 agents)
```

## Rollback

To disable N-way rotation, replace the two-pass processing with the original foreach loop:

1. Remove the `_campaniaIntenciones`, `_agentesAsignados`, `_agentRotation` properties
2. Restore original `_actualizarCampanias()` foreach loop
3. Remove `_resolverRotacionAgentes()`, `_getRotationWinner()`, `_actualizarLlamadasCampaniaConAsignacion()`

The system will revert to "first campaign wins" behavior.
