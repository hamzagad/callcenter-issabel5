# Investigation: Outgoing Campaign Panel Trunk Display Delay

## Observation Summary

When a new call is dialed in an outgoing campaign, the trunk used for that call is not shown in the "Outgoing Campaign Panel" immediately. The trunk appears only after another update event occurs. A manual page refresh (F5) immediately shows the correct trunk.

## Root Cause Analysis

### Primary Issue: Trunk not set on Llamada object for outgoing calls

**File:** `/opt/issabel/dialer/Llamada.class.php`

The trunk property is not set on the Llamada object when `marcarLlamada()` is called:

1. **Line 583-623** - `marcarLlamada()` receives `$trunk` as parameter
2. The trunk is included in the progress notification (`$paramProgreso['trunk']`)
3. **BUT** the trunk is NOT set on `$this->_trunk`
4. The `_trunk` is only set when `channel` or `actualchannel` is set (lines 414-439)
5. When a call is in "Placing" status, there's no channel yet
6. When `resumenLlamada()` is called (line 509-543), it returns `$this->trunk` which is NULL

### Secondary Issue: computeStateHash() doesn't include trunk

**File:** `/var/www/html/modules/rep_outgoing_campaigns_panel/index.php`

The `computeStateHash()` function (lines 362-400) only includes:
- Status counts
- Stats
- Active calls: count + callid + callstatus (line 386)
- Agents: count + agentchannel + status + onhold flag

It does NOT include trunk in the fingerprint. So when trunk changes from NULL to a value, the hash doesn't change, and the frontend doesn't receive an update.

## Log References

### Dialer Side
- `/opt/issabel/dialer/Llamada.class.php:583-623` - `marcarLlamada()` method
- `/opt/issabel/dialer/Llamada.class.php:509-543` - `resumenLlamada()` method
- `/opt/issabel/dialer/Llamada.class.php:410-443` - Channel setter that sets `_trunk`

### Web Side
- `/var/www/html/modules/rep_outgoing_campaigns_panel/index.php:362-400` - `computeStateHash()`
- `/var/www/html/modules/rep_outgoing_campaigns_panel/index.php:412-428` - `formatoLlamadaNoConectada()`

## Suspected Files/Functions

| File | Function | Issue |
|------|----------|-------|
| `/opt/issabel/dialer/Llamada.class.php` | `marcarLlamada()` | Trunk not set on object |
| `/opt/issabel/dialer/Llamada.class.php` | `resumenLlamada()` | Returns NULL trunk |
| `/var/www/html/modules/rep_outgoing_campaigns_panel/index.php` | `computeStateHash()` | Doesn't include trunk in hash |

## Suggested Fix Proposal

### Fix 1: Set trunk in marcarLlamada() (Primary - REQUIRED)

In `/opt/issabel/dialer/Llamada.class.php`, in the `marcarLlamada()` method around line 598, add:

```php
// Set trunk on the object so resumenLlamada() includes it
// ES: Establecer trunk en el objeto para que resumenLlamada() lo incluya
if (!is_null($trunk)) {
    $this->_trunk = $trunk;
}
```

This should be added after line 598 (after the `$paramProgreso` array definition and before or after the `msg_SQLWorkerProcess_notificarProgresoLlamada` call).

### Fix 2: Include trunk in computeStateHash() (Optional - RECOMMENDED)

In `/var/www/html/modules/rep_outgoing_campaigns_panel/index.php`, modify line 386:

**Before:**
```php
$fingerprint .= ',' . (isset($call['callid']) ? $call['callid'] : '') . ':' . (isset($call['callstatus']) ? $call['callstatus'] : '');
```

**After:**
```php
$fingerprint .= ',' . (isset($call['callid']) ? $call['callid'] : '') . ':' . (isset($call['callstatus']) ? $call['callstatus'] : '') . ':' . (isset($call['trunk']) ? $call['trunk'] : '');
```

This ensures the state hash changes when trunk is updated.

## Data Flow Summary

```
CampaignProcess (trunk known)
    |
    v
AMIEventProcess._ejecutarOriginate(trunk)
    |
    v
Llamada.marcarLlamada(trunk)  <-- BUG: trunk NOT set on $this->_trunk
    |                               only sent to progress notification
    v
SQLWorkerProcess.notificarProgresoLlamada(trunk) --> Database
    |
    v
ECCPProcess.emitirEventos --> ECCPProxyConn --> Frontend
    |
    v
Frontend requests getcampaignstatus
    |
    v
ECCPConn.getcampaignstatus --> AMIEventProcess._reportarInfoLlamadasCampania
    |
    v
Llamada.resumenLlamada() <-- Returns NULL trunk because _trunk not set
    |
    v
Frontend displays call WITHOUT trunk
    |
    v (later when channel is set)
Llamada.__set('channel', ...) --> Sets _trunk from channel regex
    |
    v
Next status request returns trunk correctly
```

## Test Steps

1. Start an outgoing campaign with calls
2. Open the "Outgoing Campaign Panel" page
3. Watch for new calls being dialed (status "Placing" or "Ringing")
4. **Bug:** Trunk column should show "-" instead of the actual trunk
5. After the call connects or another update occurs, trunk appears
6. **Fix verification:** Trunk should appear immediately when call is in "Placing" status

### Log Collection Commands

```bash
# Watch dialer logs for call progress
tail -f /opt/issabel/dialer/dialerd.log | grep -E "(trunk|Placing|resumenLlamada)"

# Watch for ECCP getcampaignstatus requests
tail -f /opt/issabel/dialer/dialerd.log | grep -i "getcampaignstatus"
```
