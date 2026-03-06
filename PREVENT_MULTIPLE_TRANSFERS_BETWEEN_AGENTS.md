# Prevent Double Agent-to-Agent Transfer

## Problem

The current `Request_agentauth_transfercallagent()` in `ECCPConn.class.php` (lines 3436-3456) validates the target agent using only the dialer's internal state:

```php
if ($infoTargetAgent['oncall']) {           // Dialer internal: has _llamada object?
    $sTargetStatus = 'oncall';
} elseif ($infoTargetAgent['num_pausas'] > 0) {  // Dialer internal: break/hold count
    $sTargetStatus = 'paused';
} elseif ($infoTargetAgent['estado_consola'] != 'logged-in') {  // Dialer internal: session state
    $sTargetStatus = 'offline';
} else {
    $sTargetStatus = 'online';              // Passes validation
}
```

This has three gaps:

### Gap 1: Race Condition (No Mutual Exclusion)

Two agents can simultaneously initiate a transfer to the same target agent. Both pass the `oncall=FALSE` check because there is no lock:

```
Agent A (ECCPWorkerProcess-0)          Agent B (ECCPWorkerProcess-1)
  |                                       |
  | infoSeguimientoAgente(Target)         |
  |   -> oncall=FALSE                     | infoSeguimientoAgente(Target)
  |   -> status=online                    |   -> oncall=FALSE (still!)
  |                                       |   -> status=online
  | Redirect(customer -> Target)          |
  |                                       | Redirect(customer -> Target)  <-- DOUBLE!
```

### Gap 2: No Asterisk Device State Check

The dialer's internal `oncall` flag is updated asynchronously via AMI events. After a previous transfer, the target agent's phone may already be ringing (`RINGING=6`) or in use (`INUSE=2`), but the dialer's `oncall` hasn't caught up yet.

The `resumenSeguimiento()` method already provides `queue_status` (the max `AST_DEVICE_*` across all queues), but the transfer validation **ignores it entirely**.

### Gap 3: No Direct Asterisk Query

Even the cached `queue_status` can lag behind. A direct AMI `ExtensionState` query gives the real-time device state, but is never called during transfer validation.

### Agent Type Note

Agent type agents (app_agent_pool) logged in via `AgentLogin()` maintain a session call, but `oncall` is computed as `!is_null($this->_llamada)` which correctly distinguishes "waiting in pool" (`oncall=FALSE`) from "on a call" (`oncall=TRUE`). The `queue_status` field correctly reflects the Asterisk device state via the `Agent:XXXX` StateInterface.

---

## Architecture Background

### Process Model

```
ECCPWorkerProcess-0  ─┐
ECCPWorkerProcess-1  ─┤── (TuberiaMensaje RPC/msg) ──> AMIEventProcess (single process)
ECCPWorkerProcess-N  ─┘                                     |
                                                        _listaAgentes (authoritative state)
                                                        _queueshadow (Asterisk queue state)
```

- Each ECCP client connection spawns an `ECCPWorkerProcess` with its own AMI connection (`$this->_ami`)
- `AMIEventProcess` is a **single-process event loop** (not multi-threaded) — all RPC handlers execute sequentially, providing inherent atomicity
- Communication uses TuberiaMensaje:
  - **RPC** (synchronous): `$this->_tuberia->AMIEventProcess_methodName($args)` → calls `rpc_methodName` handler
  - **Async message**: `$this->_tuberia->msg_AMIEventProcess_methodName($args)` → calls `msg_methodName` handler

### Existing State Tracking Patterns

AMIEventProcess already tracks in-progress operations using arrays:

```php
private $_agentesEnAtxferComplete = array();  // line 47 - suppress Agentlogoff during attended transfer
private $_agentesEnConsultation = array();    // line 48 - track consultation phase
```

These use the pattern: `isset()` to check, `time()` as value, `unset()` to clear. We follow this exact pattern.

### Alarm Mechanism

`_agregarAlarma($timeout, $callback, $arglist)` (line 3856) provides timeout-based callbacks within AMIEventProcess. Returns an alarm key. `_cancelarAlarma($k)` (line 3864) cancels by key. `_ejecutarAlarmas()` (line 3869) runs in the main loop.

### AMI ExtensionState

Defined in `AMIClientConn.class.php` (line 100-101):
```php
'ExtensionState' => array('Exten' => TRUE, 'Context' => TRUE, 'ActionID' => FALSE)
```

Returns `Status` field with **bitmask** values (different from `AST_DEVICE_*` queue constants):
- `0` = Idle (available)
- `1` = InUse
- `2` = Busy
- `4` = Unavailable
- `8` = Ringing
- `16` = OnHold
- `-1` = Not found (extension/hint not configured)

These are bitmask flags, so `Status=9` means InUse+Ringing. Check with `$iStatus & $BUSY_MASK`.

---

## Solution Design

### Approach: Atomic Reserve-and-Validate RPC + ExtensionState Belt-and-Suspenders

```
ECCPWorkerProcess                           AMIEventProcess
      |                                          |
      |  RPC: reservarAgenteParaTransferencia    |
      |  ─────────────────────────────────────>  |
      |                                          |  1. Check _agentesEnTransferPendiente (race lock)
      |                                          |  2. Check estado_consola (logged-in?)
      |                                          |  3. Check oncall (on a call?)
      |                                          |  4. Check num_pausas (on break?)
      |                                          |  5. Check queue_status (Asterisk device state)
      |                                          |  6. ALL PASS → set lock + 30s alarm
      |  <─────────────────────────────────────  |
      |  {success: true}                         |
      |                                          |
      |  ExtensionState (direct AMI query)       |
      |  ─────> Asterisk ─────>                  |
      |  <───── Status=0 (Idle) <─────           |
      |                                          |
      |  AMI Redirect (transfer the call)        |
      |  ─────> Asterisk ─────>                  |
      |  <───── Success <─────                   |
      |                                          |
      |  msg: finalizarTransferencia             |
      |  ─────────────────────────────────────>  |  Clears source agent call + reservation lock
```

### Race Condition Prevention

Since AMIEventProcess is single-process, the RPC handler `_reservarAgenteParaTransferencia` executes atomically:

```
Agent A's RPC arrives  →  validates  →  sets lock  →  returns success
Agent B's RPC arrives  →  finds lock →  returns 409 "Transfer already in progress"
```

No mutexes needed — the event loop serialization guarantees it.

---

## Implementation Details

### Files to Modify

| File | Changes |
|------|---------|
| `/opt/issabel/dialer/AMIEventProcess.class.php` | New tracking array, atomic reservation RPC, timeout/event cleanup, handler registration, dump output |
| `/opt/issabel/dialer/ECCPConn.class.php` | Replace validation block with atomic RPC, add `_checkExtensionState()`, release reservation on failure |

### AMIEventProcess.class.php Changes

#### 1. New tracking array (after line 48)

```php
private $_agentesEnTransferPendiente = array(); // Agents with pending blind transfer (prevent double transfer)
```

Structure:
```php
$_agentesEnTransferPendiente[$sTargetAgent] = array(
    'timestamp'    => time(),
    'source_agent' => $sSourceAgent,
    'alarm_key'    => $sAlarmKey,   // from _agregarAlarma() for 30s timeout
);
```

#### 2. Register handlers (lines 126-139)

Add to async message handlers (line 126-128):
```php
foreach (array('quitarBreakAgente',
    'llamadaSilenciada', 'llamadaSinSilencio', 'finalizarTransferencia',
    'marcarConsultationIniciada',
    'liberarReservaTransferencia') as $k)   // <-- ADD
    $this->_tuberia->registrarManejador('*', $k, array($this, "msg_$k"));
```

Add to RPC handlers (line 130-139):
```php
foreach (array('prepararAtxferComplete',
    ...
    'esAgenteEnAtxferComplete',
    'esAgenteEnConsultation',
    'reservarAgenteParaTransferencia') as $k)   // <-- ADD
    $this->_tuberia->registrarManejador('*', $k, array($this, "rpc_$k"));
```

#### 3. Core reservation method (after ~line 1790)

```php
/**
 * Atomically validate target agent availability AND acquire transfer reservation.
 * This runs in AMIEventProcess (single process), so check-and-set is inherently atomic.
 *
 * Validar atómicamente la disponibilidad del agente destino Y adquirir reserva de transferencia.
 * Esto corre en AMIEventProcess (proceso único), así que verificar-y-establecer es inherentemente atómico.
 */
private function _reservarAgenteParaTransferencia($sSourceAgent, $sTargetAgent)
{
    $this->_log->output('INFO: '.__METHOD__.": Validating transfer reservation: source=$sSourceAgent, target=$sTargetAgent | ES: Validando reserva de transferencia: origen=$sSourceAgent, destino=$sTargetAgent");

    // 1. Find target agent
    $a = $this->_listaAgentes->buscar('agentchannel', $sTargetAgent);
    if (is_null($a)) {
        $this->_log->output('ERR: '.__METHOD__.": Target agent not found: $sTargetAgent | ES: Agente destino no encontrado: $sTargetAgent");
        return array('success' => false, 'error_code' => 404,
            'error_msg' => 'Target agent not found | Agente destino no encontrado',
            'status' => 'not_found');
    }

    // 2. Check if another transfer is already pending to this agent (RACE CONDITION PREVENTION)
    if (isset($this->_agentesEnTransferPendiente[$sTargetAgent])) {
        $pendingInfo = $this->_agentesEnTransferPendiente[$sTargetAgent];
        $this->_log->output('WARN: '.__METHOD__.": Transfer to $sTargetAgent already in progress from {$pendingInfo['source_agent']} (since ".date('H:i:s', $pendingInfo['timestamp']).") | ES: Transferencia a $sTargetAgent ya en progreso desde {$pendingInfo['source_agent']}");
        return array('success' => false, 'error_code' => 409,
            'error_msg' => 'Transfer to target agent already in progress | Transferencia al agente destino ya en progreso',
            'status' => 'transfer_pending');
    }

    // 3. Check agent is logged in
    if ($a->estado_consola != 'logged-in') {
        $this->_log->output('ERR: '.__METHOD__.": Target agent not logged in: $sTargetAgent (estado_consola={$a->estado_consola}) | ES: Agente destino no conectado: $sTargetAgent");
        return array('success' => false, 'error_code' => 417,
            'error_msg' => 'Target agent is not logged in | Agente destino no está conectado',
            'status' => 'offline');
    }

    // 4. Check agent is not on a call (dialer internal state)
    if (!is_null($a->llamada)) {
        $this->_log->output('ERR: '.__METHOD__.": Target agent is on call: $sTargetAgent | ES: Agente destino está en llamada: $sTargetAgent");
        return array('success' => false, 'error_code' => 417,
            'error_msg' => 'Target agent is busy | Agente destino está ocupado',
            'status' => 'oncall');
    }

    // 5. Check agent is not paused (break or hold)
    if ($a->num_pausas > 0) {
        $this->_log->output('ERR: '.__METHOD__.": Target agent is paused: $sTargetAgent (num_pausas={$a->num_pausas}) | ES: Agente destino está en pausa: $sTargetAgent");
        return array('success' => false, 'error_code' => 417,
            'error_msg' => 'Target agent is on pause | Agente destino está en pausa',
            'status' => 'paused');
    }

    // 6. Check Asterisk device state via cached queue_status
    $max_queue_status = AST_DEVICE_UNKNOWN;
    foreach ($a->colas_actuales as $queue) {
        $status = $a->estadoEnCola($queue);
        if ($status > $max_queue_status) $max_queue_status = $status;
    }
    $busyDeviceStates = array(AST_DEVICE_INUSE, AST_DEVICE_BUSY, AST_DEVICE_RINGING, AST_DEVICE_RINGINUSE, AST_DEVICE_ONHOLD);
    if (in_array($max_queue_status, $busyDeviceStates)) {
        $statusNames = array(
            AST_DEVICE_INUSE => 'INUSE', AST_DEVICE_BUSY => 'BUSY',
            AST_DEVICE_RINGING => 'RINGING', AST_DEVICE_RINGINUSE => 'RINGINUSE',
            AST_DEVICE_ONHOLD => 'ONHOLD'
        );
        $sStatusName = isset($statusNames[$max_queue_status]) ? $statusNames[$max_queue_status] : 'UNKNOWN_'.$max_queue_status;
        $this->_log->output('ERR: '.__METHOD__.": Target agent device is busy: $sTargetAgent (queue_status=$max_queue_status/$sStatusName) | ES: Dispositivo del agente destino ocupado: $sTargetAgent (estado_cola=$max_queue_status/$sStatusName)");
        return array('success' => false, 'error_code' => 417,
            'error_msg' => "Target agent device is busy ($sStatusName) | Dispositivo del agente destino ocupado ($sStatusName)",
            'status' => 'device_busy', 'queue_status' => $max_queue_status);
    }

    // 7. ALL CHECKS PASSED - Atomically set reservation with 30s timeout
    $sAlarmKey = $this->_agregarAlarma(30, array($this, '_timeoutReservaTransferencia'), array($sTargetAgent));
    $this->_agentesEnTransferPendiente[$sTargetAgent] = array(
        'timestamp'    => time(),
        'source_agent' => $sSourceAgent,
        'alarm_key'    => $sAlarmKey,
    );

    $this->_log->output('INFO: '.__METHOD__.": Transfer reservation ACQUIRED for target=$sTargetAgent by source=$sSourceAgent (alarm=$sAlarmKey, queue_status=$max_queue_status) | ES: Reserva de transferencia ADQUIRIDA para destino=$sTargetAgent por origen=$sSourceAgent");

    return array('success' => true, 'status' => 'reserved');
}
```

#### 4. Timeout cleanup method

```php
/**
 * Safety-net timeout: clear stale transfer reservation after 30 seconds.
 * Tiempo límite de seguridad: limpiar reserva de transferencia obsoleta después de 30 segundos.
 */
private function _timeoutReservaTransferencia($sTargetAgent)
{
    if (isset($this->_agentesEnTransferPendiente[$sTargetAgent])) {
        $info = $this->_agentesEnTransferPendiente[$sTargetAgent];
        $this->_log->output('WARN: '.__METHOD__.": Transfer reservation EXPIRED for target=$sTargetAgent (source={$info['source_agent']}, age=".(time() - $info['timestamp'])."s) | ES: Reserva de transferencia EXPIRADA para destino=$sTargetAgent (origen={$info['source_agent']})");
        unset($this->_agentesEnTransferPendiente[$sTargetAgent]);
    }
}
```

#### 5. Explicit release method

```php
/**
 * Explicitly release a transfer reservation (called when Redirect fails or ExtensionState rejects).
 * Liberar explícitamente una reserva de transferencia (llamado cuando Redirect falla o ExtensionState rechaza).
 */
private function _liberarReservaTransferencia($sTargetAgent)
{
    if (isset($this->_agentesEnTransferPendiente[$sTargetAgent])) {
        $info = $this->_agentesEnTransferPendiente[$sTargetAgent];
        $this->_cancelarAlarma($info['alarm_key']);
        unset($this->_agentesEnTransferPendiente[$sTargetAgent]);
        $this->_log->output('INFO: '.__METHOD__.": Transfer reservation RELEASED for target=$sTargetAgent (source={$info['source_agent']}) | ES: Reserva de transferencia LIBERADA para destino=$sTargetAgent (origen={$info['source_agent']})");
    } else {
        $this->_log->output('DEBUG: '.__METHOD__.": No transfer reservation found for target=$sTargetAgent (already cleared) | ES: No se encontró reserva de transferencia para destino=$sTargetAgent (ya limpiada)");
    }
}
```

#### 6. RPC and message handler wrappers

```php
public function rpc_reservarAgenteParaTransferencia($sFuente, $sDestino,
    $sNombreMensaje, $iTimestamp, $datos)
{
    if ($this->DEBUG) {
        $this->_log->output('DEBUG: '.__METHOD__.' recibido/received: '.print_r($datos, 1));
    }
    list($sSourceAgent, $sTargetAgent) = $datos;
    $this->_tuberia->enviarRespuesta($sFuente,
        $this->_reservarAgenteParaTransferencia($sSourceAgent, $sTargetAgent));
}

public function msg_liberarReservaTransferencia($sFuente, $sDestino,
    $sNombreMensaje, $iTimestamp, $datos)
{
    if ($this->DEBUG) {
        $this->_log->output('DEBUG: '.__METHOD__.' recibido/received: '.print_r($datos, 1));
    }
    list($sTargetAgent) = $datos;
    $this->_liberarReservaTransferencia($sTargetAgent);
}
```

#### 7. Event-based cleanup in `_finalizarTransferencia()` (after line 1192)

When the source agent is released after a successful transfer, sweep the pending reservations to find and clear the one this source agent created:

```php
// Clear transfer reservation if source agent had one pending
// Limpiar reserva de transferencia si el agente origen tenía una pendiente
foreach ($this->_agentesEnTransferPendiente as $sTarget => $info) {
    if ($info['source_agent'] == $sAgente) {
        $this->_cancelarAlarma($info['alarm_key']);
        unset($this->_agentesEnTransferPendiente[$sTarget]);
        $this->_log->output('INFO: '.__METHOD__.": Transfer reservation auto-cleared for target=$sTarget (source=$sAgente finalized) | ES: Reserva de transferencia auto-limpiada para destino=$sTarget (origen=$sAgente finalizado)");
        break;
    }
}
```

#### 8. Event-based cleanup in `msg_QueueMemberStatus()` (after line 3398)

When the target agent's device state changes to INUSE (call bridged) or back to NOT_INUSE (call rejected/failed), clear the reservation:

```php
// If target agent has pending transfer reservation and device state resolved, clear it
// Si el agente destino tiene reserva de transferencia pendiente y el estado del dispositivo se resolvió, limpiarla
if (isset($this->_agentesEnTransferPendiente[$sAgente])) {
    if ($params['Status'] == AST_DEVICE_INUSE || $params['Status'] == AST_DEVICE_NOT_INUSE) {
        $info = $this->_agentesEnTransferPendiente[$sAgente];
        $this->_cancelarAlarma($info['alarm_key']);
        unset($this->_agentesEnTransferPendiente[$sAgente]);
        $this->_log->output('INFO: '.__METHOD__.": Transfer reservation cleared for target=$sAgente via QueueMemberStatus (Status={$params['Status']}) | ES: Reserva de transferencia limpiada para destino=$sAgente via QueueMemberStatus (Status={$params['Status']})");
    }
}
```

#### 9. Dump in `_dumpstatus()` (before line 3853)

```php
// Dump pending transfer reservations
$this->_log->output('INFO: '.__METHOD__.' agentes con transferencia pendiente / agents with pending transfer: '.
    (count($this->_agentesEnTransferPendiente) > 0
        ? print_r($this->_agentesEnTransferPendiente, TRUE)
        : '(ninguno/none)'));
```

---

### ECCPConn.class.php Changes

#### 1. Replace validation block (lines 3427-3456)

**Remove** the current target agent status check:
```php
// OLD: lines 3427-3456 (removed)
$infoTargetAgent = $this->_tuberia->AMIEventProcess_infoSeguimientoAgente($sTargetAgent);
// ... manual oncall/paused/offline checks ...
```

**Replace with** atomic reservation + ExtensionState:
```php
// === STEP 1: Atomic validation + reservation via AMIEventProcess RPC ===
// This single RPC call atomically: validates all dialer-side state + acquires transfer lock
// Esta única llamada RPC atómicamente: valida todo el estado del dialer + adquiere bloqueo de transferencia
$this->_log->output('INFO: '.__METHOD__.": Requesting transfer reservation: source=$sAgente, target=$sTargetAgent | ES: Solicitando reserva de transferencia: origen=$sAgente, destino=$sTargetAgent");

$reserveResult = $this->_tuberia->AMIEventProcess_reservarAgenteParaTransferencia($sAgente, $sTargetAgent);

if (is_null($reserveResult) || !$reserveResult['success']) {
    $sErrorCode = is_null($reserveResult) ? 500 : $reserveResult['error_code'];
    $sErrorMsg = is_null($reserveResult) ? 'Internal communication error | Error interno de comunicación' : $reserveResult['error_msg'];
    $sStatus = is_null($reserveResult) ? 'error' : $reserveResult['status'];
    $this->_log->output('ERR: '.__METHOD__.": Transfer reservation DENIED: status=$sStatus, target=$sTargetAgent | ES: Reserva de transferencia DENEGADA: estado=$sStatus, destino=$sTargetAgent");
    $this->_agregarRespuestaFallo($xml_transferResponse, $sErrorCode, $sErrorMsg);
    return $xml_response;
}

$this->_log->output('INFO: '.__METHOD__.": Transfer reservation granted, checking Asterisk device state | ES: Reserva de transferencia concedida, verificando estado de dispositivo Asterisk");

// === STEP 2: Belt-and-suspenders - Direct Asterisk device state check ===
// This queries Asterisk directly via AMI ExtensionState for real-time device state
// Esto consulta Asterisk directamente vía AMI ExtensionState para estado de dispositivo en tiempo real
$bDeviceStateOk = $this->_checkExtensionState($sTargetAgent, $xml_transferResponse);
if (!$bDeviceStateOk) {
    // Release the reservation since we're aborting the transfer
    // Liberar la reserva ya que abortamos la transferencia
    $this->_log->output('WARN: '.__METHOD__.": ExtensionState check FAILED, releasing reservation for $sTargetAgent | ES: Verificación ExtensionState FALLÓ, liberando reserva para $sTargetAgent");
    $this->_tuberia->msg_AMIEventProcess_liberarReservaTransferencia($sTargetAgent);
    return $xml_response;
}

$this->_log->output('INFO: '.__METHOD__.": All checks passed for transfer to $sTargetAgent | ES: Todas las verificaciones pasaron para transferencia a $sTargetAgent");

// Get target agent info for extension extraction (validated as existing by reservation RPC)
// Obtener info del agente destino para extracción de extensión (ya validado como existente por RPC de reserva)
$infoTargetAgent = $this->_tuberia->AMIEventProcess_infoSeguimientoAgente($sTargetAgent);
```

The rest of the method (lines 3458+) that uses `$infoTargetAgent` for extension extraction remains unchanged.

#### 2. New method `_checkExtensionState()` (after `_registrarTransferencia`, ~line 3552)

```php
/**
 * Check Asterisk device state for target agent via AMI ExtensionState command.
 * Returns TRUE if the device is available, FALSE if busy (and populates error response).
 * Fails open (returns TRUE) if AMI query fails — non-fatal, proceed with transfer.
 *
 * Verifica el estado del dispositivo Asterisk del agente destino vía AMI ExtensionState.
 * Devuelve TRUE si disponible, FALSE si ocupado (y genera respuesta de error).
 * Falla abierto (devuelve TRUE) si la consulta AMI falla — no fatal, proceder con transferencia.
 */
private function _checkExtensionState($sTargetAgent, $xml_transferResponse)
{
    // Determine extension and context based on agent type
    // Determinar extensión y contexto según tipo de agente
    if (strpos($sTargetAgent, 'Agent/') === 0) {
        // Agent type: check agent number in 'agents' context
        $regs = NULL;
        if (preg_match('|^Agent/(\d+)|', $sTargetAgent, $regs)) {
            $sExten = $regs[1];
            $sContext = 'agents';
        } else {
            $this->_log->output('WARN: '.__METHOD__.": Cannot parse Agent number from $sTargetAgent, skipping ExtensionState check | ES: No se puede parsear número de Agent de $sTargetAgent, omitiendo verificación ExtensionState");
            return TRUE; // Cannot parse, fail-open
        }
    } else {
        // Callback type (SIP/PJSIP/IAX2): extract extension number, check in from-internal
        $regs = NULL;
        if (preg_match('|^\w+/(\d+)|', $sTargetAgent, $regs)) {
            $sExten = $regs[1];
            $sContext = 'from-internal';
        } else {
            $this->_log->output('WARN: '.__METHOD__.": Cannot parse extension from $sTargetAgent, skipping ExtensionState check | ES: No se puede parsear extensión de $sTargetAgent, omitiendo verificación ExtensionState");
            return TRUE; // Cannot parse, fail-open
        }
    }

    $this->_log->output('INFO: '.__METHOD__.": Querying ExtensionState for $sExten@$sContext (agent=$sTargetAgent) | ES: Consultando ExtensionState para $sExten@$sContext (agente=$sTargetAgent)");

    $r = $this->_ami->ExtensionState($sExten, $sContext);
    if ($r['Response'] != 'Success') {
        $sMsg = isset($r['Message']) ? $r['Message'] : 'unknown';
        $this->_log->output('WARN: '.__METHOD__.": ExtensionState query failed for $sExten@$sContext: $sMsg — proceeding with transfer (fail-open) | ES: Consulta ExtensionState falló para $sExten@$sContext: $sMsg — procediendo con transferencia (falla abierta)");
        return TRUE; // Fail-open: don't block transfer if AMI query fails
    }

    $iStatus = (int)$r['Status'];
    $this->_log->output('INFO: '.__METHOD__.": ExtensionState result for $sExten@$sContext: Status=$iStatus | ES: Resultado ExtensionState para $sExten@$sContext: Status=$iStatus");

    // ExtensionState uses bitmask values (different from AST_DEVICE_* queue constants):
    // 0=Idle, 1=InUse, 2=Busy, 4=Unavailable, 8=Ringing, 16=OnHold, -1=Not found
    // These are bitmask flags, so Status=9 means InUse+Ringing
    $BUSY_MASK = 1 | 2 | 8 | 16; // InUse | Busy | Ringing | OnHold
    if ($iStatus > 0 && ($iStatus & $BUSY_MASK)) {
        $aFlags = array();
        if ($iStatus & 1)  $aFlags[] = 'InUse';
        if ($iStatus & 2)  $aFlags[] = 'Busy';
        if ($iStatus & 8)  $aFlags[] = 'Ringing';
        if ($iStatus & 16) $aFlags[] = 'OnHold';
        $sFlagStr = implode('+', $aFlags);

        $this->_log->output('ERR: '.__METHOD__.": Asterisk device state check FAILED for $sExten@$sContext: Status=$iStatus ($sFlagStr) | ES: Verificación de estado de dispositivo FALLÓ para $sExten@$sContext: Status=$iStatus ($sFlagStr)");
        $this->_agregarRespuestaFallo($xml_transferResponse, 417,
            "Target agent device is busy ($sFlagStr) | Dispositivo del agente destino ocupado ($sFlagStr)");
        return FALSE;
    }

    $this->_log->output('INFO: '.__METHOD__.": ExtensionState check PASSED for $sExten@$sContext: Status=$iStatus (Idle) | ES: Verificación ExtensionState PASÓ para $sExten@$sContext: Status=$iStatus (Disponible)");
    return TRUE;
}
```

#### 3. Release reservation on Redirect failure (line ~3526)

In the existing `if ($r['Response'] != 'Success')` block, add the release call:

```php
if ($r['Response'] != 'Success') {
    // Release the transfer reservation on Redirect failure
    // Liberar la reserva de transferencia por falla en Redirect
    $this->_log->output('WARN: '.__METHOD__.": Redirect failed, releasing transfer reservation for $sTargetAgent | ES: Redirect falló, liberando reserva de transferencia para $sTargetAgent");
    $this->_tuberia->msg_AMIEventProcess_liberarReservaTransferencia($sTargetAgent);

    // ... existing error logging and response code ...
}
```

---

## Complete Event Flows

### Successful Transfer

```
1. ECCPWorkerProcess → RPC reservarAgenteParaTransferencia(source, target)
   AMIEventProcess: validates all state + acquires lock → returns success
   LOG: "Transfer reservation ACQUIRED for target=Agent/1002 by source=Agent/1001"

2. ECCPWorkerProcess → AMI ExtensionState(1002, agents)
   Asterisk: returns Status=0 (Idle)
   LOG: "ExtensionState check PASSED for 1002@agents: Status=0 (Idle)"

3. ECCPWorkerProcess → AMI Redirect(customer_channel → target)
   Asterisk: redirects call to target agent
   LOG: "Initiating transfer: SIP/trunk-xxx -> 1002@agents"

4. ECCPWorkerProcess → msg finalizarTransferencia(source)
   AMIEventProcess: releases source agent + clears reservation lock
   LOG: "Transfer reservation auto-cleared for target=Agent/1002 (source=Agent/1001 finalized)"

5. Target agent answers → QueueMemberStatus fires with Status=INUSE
   AMIEventProcess: also clears reservation (redundant but harmless)
```

### Redirect Fails

```
1. RPC reservarAgenteParaTransferencia → success (lock acquired)
2. ExtensionState → passes
3. AMI Redirect → fails
4. ECCPWorkerProcess → msg liberarReservaTransferencia(target)
   LOG: "Transfer reservation RELEASED for target=Agent/1002"
```

### ExtensionState Rejects

```
1. RPC reservarAgenteParaTransferencia → success (lock acquired)
2. ExtensionState → Status=1 (InUse) → FAILS
3. ECCPWorkerProcess → msg liberarReservaTransferencia(target)
   LOG: "ExtensionState check FAILED, releasing reservation"
```

### Double Transfer (Race Condition Prevented)

```
Agent A's RPC → AMIEventProcess validates → lock set → returns success
Agent B's RPC → AMIEventProcess finds lock → returns 409
   LOG: "Transfer to Agent/1002 already in progress from Agent/1001"
```

### Stale Lock (Safety Net)

```
1. Lock acquired at T=0
2. Something goes wrong, no cleanup fires
3. T=30s: _ejecutarAlarmas() fires _timeoutReservaTransferencia
   LOG: "Transfer reservation EXPIRED for target=Agent/1002"
```

---

## Verification

```bash
# Restart dialer after changes
systemctl restart issabeldialer

# Watch the complete transfer reservation flow
grep -E "reservation|reserva|ExtensionState|transfer_pending|device_busy" /opt/issabel/dialer/dialerd.log | tail -50

# Test double transfer rejection (error 409)
grep -E "409|already in progress|ya en progreso" /opt/issabel/dialer/dialerd.log | tail -20

# Test device state rejection (queue_status or ExtensionState)
grep -E "device.*busy|FAILED|DENIED" /opt/issabel/dialer/dialerd.log | tail -20

# Test timeout cleanup
grep -E "EXPIRED|expirada" /opt/issabel/dialer/dialerd.log | tail -10

# Watch full transfer lifecycle
grep -E "ACQUIRED|RELEASED|PASSED|FAILED|auto-cleared|EXPIRED" /opt/issabel/dialer/dialerd.log | tail -30
```

### Test Steps

1. **Normal transfer** — Log in Agent/1001 and Agent/1002. Transfer a call from 1001 to 1002. Should succeed. Logs show: reservation ACQUIRED → ExtensionState PASSED → Redirect success → reservation auto-cleared.

2. **Double transfer** — Two agent consoles, both transfer to same target simultaneously. Second should get error 409 "Transfer already in progress".

3. **Busy target (queue_status)** — Transfer to an agent whose phone is already ringing from a campaign call. Should fail with "device is busy (RINGING)".

4. **Busy target (ExtensionState)** — Transfer to an agent who is in a non-campaign call (device INUSE but dialer doesn't know). ExtensionState should catch it.

5. **Agent type transfer** — Transfer to Agent/XXXX type agent. Verify ExtensionState uses `agents` context.

6. **Callback type transfer** — Transfer to SIP/XXXX type agent. Verify ExtensionState uses `from-internal` context.

7. **Timeout cleanup** — Start a transfer, immediately kill the source agent's ECCP connection (simulate crash). After 30s, the lock should auto-expire.
