# Fix: Transferred Calls Not Appearing in Receiving Agent's Console

## Context

When an agent performs a blind transfer to another agent, the receiving agent's console does not display the transferred call. This causes:
- The Hangup button remains disabled
- If the agent hangs up from their phone device, the agent session is terminated instead of just ending the call

### Root Cause

In `/opt/issabel/dialer/AMIEventProcess.class.php`, the `msg_Link()` method handles call-agent linking when a bridge is formed.

**The bug is at line 2771:**

```php
if (!is_null($llamada->timestamp_link)) return FALSE;   // Múltiple link se ignora
```

This line prevents any call that was previously linked (has `timestamp_link`) from being linked again to a different agent.

### Why This Happens

The transfer flow:
1. Source agent answers call → `timestamp_link` is set when call first links
2. Transfer initiated → `_finalizarTransferencia()` called
3. `llamadaFinalizaSeguimiento()` clears `agente` but **does NOT clear `timestamp_link`**
4. AMI Redirect executes, caller bridges to target agent
5. `msg_Link()` fires
6. Call is found by uniqueid (customer channel uniqueid doesn't change)
7. Falls through to line 2771
8. **Line 2771 returns FALSE** - call is ignored, target agent never gets assignment

## Solution

Modify line 2771 to detect the transfer scenario and reassign the call to the target agent.

### Detection Criteria

A transferred call is identified by:
- `timestamp_link` is NOT null (call was previously linked)
- `agente` IS null (source agent was already released by `_finalizarTransferencia()`)

### Files to Modify

| File | Lines | Change |
|------|-------|--------|
| `/opt/issabel/dialer/AMIEventProcess.class.php` | 2771 | Replace simple `return FALSE` with transfer detection and reassignment |

## Implementation

### At line 2771, replace:

**Current code:**
```php
if (!is_null($llamada->timestamp_link)) return FALSE;   // Múltiple link se ignora | EN: Multiple links are ignored
```

**New code:**
```php
// Detect agent-to-agent transfer: call was previously linked (timestamp_link set)
// but source agent was released (agente === NULL)
if (!is_null($llamada->timestamp_link)) {
    if (is_null($llamada->agente)) {
        // TRANSFER SCENARIO: Reassign to target agent
        $this->_log->output('INFO: '.__METHOD__.': transfer detected - reassigning call '.
            $llamada->uniqueid.' to agent '.$sChannel.
            ' | ES: transferencia detectada - reasignando llamada '.
            $llamada->uniqueid.' al agente '.$sChannel);

        // Find and validate target agent
        $a = $this->_listaAgentes->buscar('agentchannel', $sChannel);
        if (!is_null($a) && $a->estado_consola == 'logged-in'
            && is_null($a->llamada) && $a->num_pausas == 0) {

            // Determine remote channel (customer) from Link event
            $sRemChannel = ($llamada->actualchannel == $params['Channel1'])
                ? $params['Channel2'] : $params['Channel1'];
            $sUniqueidAgente = ($llamada->uniqueid == $params['Uniqueid1'])
                ? $params['Uniqueid2'] : $params['Uniqueid1'];

            // Reassign call to target agent (sends AgentLinked event)
            $llamada->llamadaEnlazadaAgente(
                $params['local_timestamp_received'], $a,
                $sRemChannel, $sUniqueidAgente,
                $sChannel, $sAgentChannel);

            $this->_log->output('INFO: '.__METHOD__.': call successfully reassigned to '.
                $a->channel.' | ES: llamada reasignada exitosamente a '.$a->channel);

            // Clear transfer reservation
            $this->_liberarReservaTransferencia($a->channel);

            return FALSE;
        } else {
            // Target agent not available - finalize call tracking
            $this->_log->output('WARN: '.__METHOD__.': transfer rejected - target agent '.
                $sChannel.' not available | ES: transferencia rechazada - agente destino '.
                $sChannel.' no disponible');
            $llamada->llamadaFinalizaSeguimiento(
                $params['local_timestamp_received'],
                $this->_config['dialer']['llamada_corta']);
            return FALSE;
        }
    }
    // Otherwise, normal multiple link - ignore
    return FALSE;
}
```

### Key Implementation Details

1. **Transfer detection**: `!is_null($llamada->timestamp_link) && is_null($llamada->agente)`
   - Call was previously linked to source agent
   - Source agent was already released (`agente === NULL`)

2. **Target validation**: Check agent is logged-in, not on call, not paused

3. **Remote channel extraction**: Link event has two channels - extract the customer channel

4. **Uses existing `llamadaEnlazadaAgente()`**: This method already handles:
   - Assigning call to agent (`$a->asignarLlamadaAtendida()`)
   - Database updates (current_calls, calls/call_entry)
   - Sending AgentLinked event to ECCP

5. **Cleanup**: Calls `_liberarReservaTransferencia()` to clear the transfer lock

## Verification

### Test Steps

1. **Setup**: Two agents logged in (Agent A: SIP/1001, Agent B: SIP/1002)
2. **Incoming call**: Call arrives and is assigned to Agent A
3. **Blind transfer**: Agent A transfers to Agent B
4. **Verify**:
   - Agent A's console shows call released
   - Agent B's console receives and displays the transferred call with active call controls
   - Hangup button is enabled on Agent B's console
   - Call can be completed normally

### Log Verification

```bash
# Check for transfer detection
grep "transfer detected - reassigning call" /opt/issabel/dialer/dialerd.log

# Check for successful reassignment
grep "call successfully reassigned" /opt/issabel/dialer/dialerd.log

# Check for AgentLinked events to target agent
grep "AgentLinked" /opt/issabel/dialer/dialerd.log | tail -20

# Verify database - current_calls should show target agent
mysql -u root -p$(grep mysqlrootpwd /etc/issabel.conf | cut -d= -f2) call_center -e \
  "SELECT id_agent, status, datetime_init FROM current_calls ORDER BY id DESC LIMIT 5;"
```

### Edge Cases to Test

| Scenario | Expected Behavior |
|----------|-------------------|
| Transfer to logged-out agent | Call finalized with warning |
| Transfer to busy agent | Call finalized with warning |
| Transfer to paused agent | Call finalized with warning |
| Transfer during hold | Call transfers correctly |
| Multiple rapid transfers | No race conditions (reservation prevents) |

## References

### Existing Code Patterns

| Method | File | Lines | Purpose |
|--------|------|-------|---------|
| `llamadaEnlazadaAgente()` | `/opt/issabel/dialer/Llamada.class.php` | 877-978 | Assign call to agent, send AgentLinked event |
| `asignarLlamadaAtendida()` | `/opt/issabel/dialer/Agente.class.php` | 579-585 | Set agent's `_llamada` property |
| `_finalizarTransferencia()` | `/opt/issabel/dialer/AMIEventProcess.class.php` | 1156-1207 | Release source agent after transfer |
| `_liberarReservaTransferencia()` | `/opt/issabel/dialer/AMIEventProcess.class.php` | 1923-1933 | Clear transfer reservation lock |
