# Issabel Dialer Logic Documentation

## Table of Contents
1. [Outgoing Campaign Call Flow](#outgoing-campaign-call-flow)
2. [Call-Per-Agent Control (1-to-1 Approach)](#call-per-agent-control)
3. [Configuration Options](#configuration-options)
4. [Agent Status Management](#agent-status-management)

---

## Outgoing Campaign Call Flow

### Standard Flow (Predictive/Progressive Dialing)

```
1. Campaign Process: Check available agents
   └─> QueueShadow::infoPrediccionCola() counts:
       - AGENTES_LIBRES (status: NOT_INUSE or RINGING)
       - AGENTES_POR_DESOCUPAR (busy agents, predicted to finish soon)
       - CLIENTES_ESPERA (calls already in queue)

2. Calculate calls to place
   └─> iNumLlamadasColocar = AGENTES_LIBRES + AGENTES_POR_DESOCUPAR - CLIENTES_ESPERA

3. Originate calls
   └─> Status: 'Placing'

4. Customer phone rings
   └─> Status: 'Ringing'

5. Customer answers
   └─> Status: 'OnQueue'
   └─> Call enters queue via msg_Join() → llamadaEntraEnCola()

6. Queue assigns call to agent
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

4. Flow continues as standard, but only assigned agent can take this call
```

---

## Call-Per-Agent Control

### How 1-to-1 Ratio is Maintained

The dialer ensures **one call per available agent** through:

#### 1. Agent Counting (QueueShadow.class.php:318-357)
```php
function infoPrediccionCola($queue) {
    foreach ($this->_queues[$queue]['members'] as $miembro) {
        if ($miembro['Paused']) continue;

        // Count free agents
        if (in_array($miembro['Status'], array(AST_DEVICE_NOT_INUSE, AST_DEVICE_RINGING)))
            $iNumLlamadasColocar['AGENTES_LIBRES']++;

        // Count agents about to finish
        if (in_array($miembro['Status'], array(AST_DEVICE_INUSE, AST_DEVICE_BUSY, AST_DEVICE_RINGINUSE)))
            $iNumLlamadasColocar['AGENTES_POR_DESOCUPAR'][] = time_on_call;
    }
    return $iNumLlamadasColocar;
}
```

#### 2. Call Limiting (CampaignProcess.class.php:531-560)
```php
// Calculate maximum calls to place
$iMaxPredecidos = $resumenPrediccion['AGENTES_LIBRES'] +
                  $resumenPrediccion['AGENTES_POR_DESOCUPAR'] -
                  $resumenPrediccion['CLIENTES_ESPERA'];

if ($iMaxPredecidos < 0) $iMaxPredecidos = 0;
if (is_null($iNumLlamadasColocar) || $iNumLlamadasColocar > $iMaxPredecidos)
    $iNumLlamadasColocar = $iMaxPredecidos;

// Subtract calls already originated but waiting for answer
$iNumEsperanRespuesta = count($listaLlamadasAgendadas) +
                        $this->_contarLlamadasEsperandoRespuesta($queue);
if ($iNumLlamadasColocar > $iNumEsperanRespuesta) {
    $iNumLlamadasColocar -= $iNumEsperanRespuesta;
} else {
    $iNumLlamadasColocar = 0;
}
```

**IMPORTANT:** Agent assignment timing in `msg_Link()` does NOT control the 1-to-1 ratio. The ratio is controlled by:
- Free agent counting via QueueMemberStatus events
- Originated call counting
- Call placement limiting based on available agents

---

## Configuration Options

### 1. Enable Overcommit of Outgoing Calls

**Database field:** `dialer.overcommit`
**Location:** CampaignProcess.class.php:577-607

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

3. Requirements:
   - At least 10 calls in the history window
   - ASR > 0

**Example:**
- 5 free agents
- ASR = 50% (half of calls fail)
- Overcommit places: 5 / 0.5 = 10 calls
- Expected result: ~5 successful connections for 5 agents

**Status:** ✅ WORKING CORRECTLY

---

### 2. Enable Predictive Dialer Behavior

**Database field:** `dialer.predictivo`
**Location:** CampaignProcess.class.php:518-523

**Purpose:** Predict when busy agents will finish calls and place calls preemptively.

**How it works:**

1. Uses **Erlang probability distribution** (Predictor.class.php:225-241):
   ```php
   function predecirNumeroLlamadas($infoCola, $prob_atencion, $avg_duracion, $avg_contestar) {
       foreach ($infoCola['AGENTES_POR_DESOCUPAR'] as $tiempo_en_llamada) {
           $iTiempoTotal = $avg_contestar + $tiempo_en_llamada;

           // Probability that agent will finish call in time
           $iProbabilidad = $this->_probabilidadErlangAcumulada(
               $iTiempoTotal,
               1,
               1 / $avg_duracion
           );

           if ($iProbabilidad >= $prob_atencion)
               $n++;  // Count as available
       }
   }
   ```

2. Considers:
   - **avg_duracion**: Average call duration from campaign history
   - **avg_contestar**: Average time for customer to answer
   - **prob_atencion** (QoS): Service quality threshold (default: 97%)

3. Requirements:
   - Campaign must have MIN_MUESTRAS completed calls for statistical accuracy
   - Sufficient historical data for averages

**Mathematical Model:**
```
P(agent_free_before_customer_answers) = Erlang_CDF(
    time_total = avg_answer_time + current_call_time,
    k = 1,
    λ = 1 / avg_call_duration
)

If P >= 97% → count agent as available
```

**Status:** ✅ WORKING CORRECTLY

---

## Agent Status Management

### Device Status Constants (Predictor.class.php:24-33)
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

### Status Updates

Agent status is updated by **AMI events**, specifically:

1. **QueueMemberStatus** (AMIEventProcess.class.php:2786-2815)
   ```php
   public function msg_QueueMemberStatus($params) {
       $sAgente = $params['Location'];  // or $params['Interface'] for Asterisk 13+
       $a = $this->_listaAgentes->buscar('agentchannel', $sAgente);
       if (!is_null($a)) {
           $a->actualizarEstadoEnCola($params['Queue'], $params['Status']);
       }
   }
   ```

2. **Call Assignment** (Llamada.class.php:815-827)
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

**Fixed Issues:**
- ❌ Removed: Setting all free agents to "Ringing" when calls appear
- ❌ Removed: Setting "Ringing" agents to "Free" when calls end
- ❌ Removed: Changing other agents to "Free" when one becomes "Busy"
- ✅ Result: Frontend now trusts backend for all status updates

---

## Modifications for Callback Extension Support

### Issue: Premature Agent Assignment

**Problem:** For outgoing campaigns using callback extensions as trunks, agents were being assigned during the dial phase (while customer phone was still ringing), instead of after customer answered.

**Root Cause:** `msg_Link()` in AMIEventProcess.class.php was assigning agents as soon as Bridge/Link event occurred, which happens when:
- For callback extensions: When extension starts dialing customer
- For regular agents: When agent phone rings

**Fix:** AMIEventProcess.class.php:2230-2240
```php
// For outgoing campaigns, only assign agent if call has entered queue (customer answered)
// Don't assign during dial phase when customer is still ringing
if ($llamada->tipo_llamada == 'outgoing' &&
    in_array($llamada->status, array('Placing', 'Dialing', 'Ringing'))) {
    if ($this->DEBUG) {
        $this->_log->output('DEBUG: '.__METHOD__.': llamada saliente en estado '.
            $llamada->status.', no se asigna agente todavía (cliente aún no contestó)');
    }
    return FALSE;
}
```

**Impact:** This modification does NOT affect:
- Call-per-agent ratio (controlled by agent counting, not assignment timing)
- Call scheduling logic (based on free agent count and originated call count)
- Overcommit behavior (based on ASR calculation)
- Predictive dialing (based on Erlang probability)

**What changed:**
- Agent assignment now happens ONLY after customer answers (status changes to 'OnQueue')
- Customer phone number appears in "Placing calls" section while customer phone is ringing
- After customer answers, call enters queue, then agent is assigned and number moves to agent section

---

## Summary

The Issabel dialer implements a sophisticated predictive dialing system with:

1. **Precise call control:** 1 call per available agent (or more with overcommit)
2. **Statistical prediction:** Erlang-based prediction of agent availability
3. **Adaptive behavior:** ASR-based overcommit to compensate for failed calls
4. **Flexible agent models:** Support for both app_agent_pool and callback extensions
5. **Real-time monitoring:** AMI event-driven status updates

All modifications maintain backward compatibility with the core scheduling logic while fixing display and assignment timing issues for modern deployment scenarios.
