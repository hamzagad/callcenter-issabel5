# Issabel Call Center - Change Log

## Version 4.0.0.7+ Bug Fixes

### 1. Campaign Statistics Sync Fix
**File**: `modules/campaign_out/libs/paloSantoCampaignCC.class.php`

**Issue**: `campaign_out` showed stale `num_completadas` after dialer restart mid-call

**Fix**: Query `calls` table directly instead of using cached `campaign.num_completadas`

Changed SQL query to count completed calls from the `calls` table in real-time:
```php
$sPeticionSQL = <<<SQL_SELECT_CAMPAIGNS
SELECT c.id, c.name, c.trunk, c.context, c.queue, c.datetime_init, c.datetime_end, c.daytime_init,
    c.daytime_end, c.script, c.retries, c.promedio,
    (SELECT COUNT(*) FROM calls WHERE id_campaign = c.id AND status = 'Success') AS num_completadas,
    c.estatus, c.max_canales, c.id_url, c.id_url2, c.id_url3
FROM campaign c
SQL_SELECT_CAMPAIGNS;
```

---

### 2. Agent Console Stuck After Hangup Fix
**File**: `setup/dialer_process/dialer/AMIEventProcess.class.php` (msg_Hangup ~line 2381-2385)

**Issue**: For local extension calls, pressing Hangup terminated client but agent stayed "Connected to call"

**Root Cause**: Call uniqueid (Local channel) differs from SIP channel uniqueid

**Fix**: Added fallback search by `actualchannel`:
```php
// After searching by uniqueid and uniqueidlink, try actualchannel
if (is_null($llamada)) {
    $llamada = $this->_listaLlamadas->buscar('actualchannel', $params['Channel']);
}
```

**Technical Details**: For local extension calls (e.g., calling 103), the call's uniqueid is set to `Local/103@from-internal;1` but the SIP/103 channel that hangs up has a different uniqueid. The actualchannel stores the remote party's real channel (e.g., SIP/103-xxx).

---

### 3. Agent Login Cancellation Fix
**File**: `setup/dialer_process/dialer/Agente.class.php`

**Issue**: Cancelling agent login left agent in inconsistent state

**Fix**: Properly handle login channel hangup during login process

Ensures that when an agent cancels their login (hangs up the phone during the login ringing phase), the agent state is properly reset and the agent can attempt to login again without issues.

---

### 4. Call Status Initialization Bug Fix
**File**: `setup/dialer_process/dialer/Llamada.class.php` (line 629)

**Issue**: Phone number not appearing in "Placing calls" section during customer ringing in campaign monitoring

**Root Cause**: Backwards null check - status stayed empty string instead of being set to 'Placing'

**Fix**: Changed `if (!is_null($this->status))` to `if (is_null($this->status))`

```php
// BEFORE (WRONG): Sets status to 'Placing' only if already has a value
if (!is_null($this->status)) $this->status = 'Placing';

// AFTER (CORRECT): Sets status to 'Placing' only if empty
if (is_null($this->status)) $this->status = 'Placing';
```

**Impact**: This bug prevented outgoing campaign calls from appearing in the "Placing calls:" section of campaign monitoring while the customer's phone was ringing. The call status remained empty string `''` instead of `'Placing'`, causing it to be excluded from the display.

---

### 5. Agent Queue Status Bug Fix
**File**: `setup/dialer_process/dialer/Agente.class.php` (line 564)

**Issue**: `estadoEnCola()` function had backwards ternary operator logic

**Fix**: Reversed ternary operator to return actual status when queue exists

```php
// BEFORE (WRONG): Returns NOTINQUEUE when agent IS in queue
return isset($this->_estado_agente_colas[$queue]) ? AST_DEVICE_NOTINQUEUE : $this->_estado_agente_colas[$queue];

// AFTER (CORRECT): Returns actual status when agent is in queue
return isset($this->_estado_agente_colas[$queue]) ? $this->_estado_agente_colas[$queue] : AST_DEVICE_NOTINQUEUE;
```

**Impact**: This bug made it impossible to detect when an agent's phone was ringing (AST_DEVICE_RINGING state). The function always returned NOTINQUEUE (-1) when the agent was in a queue, instead of returning the actual device status (1-8).

---

### 6. Agent Ringing Status Display Fix
**Files**:
- `setup/dialer_process/dialer/Agente.class.php` (resumenSeguimiento ~line 488-512)
- `setup/dialer_process/dialer/ECCPConn.class.php` (getcampaignstatus_setagent ~line 2276-2291)
- `modules/campaign_monitoring/lang/en.lang`
- `modules/campaign_monitoring/lang/es.lang`
- `modules/campaign_monitoring/themes/default/js/javascript.js` (frontend fixes ~lines 467-513)

**Issue**: In campaign monitoring, callback extension agents showed status "Free" (green) while their phone was ringing, instead of showing "Ringing" status. Regular Agent type also had subtle timing issues with status color changes.

**Root Cause**:
1. Frontend had incorrect logic that was inferring agent status from call events instead of trusting backend AMI events
2. Backend ECCP protocol only sent 4 status values: 'online', 'oncall', 'paused', 'offline' - never 'ringing'
3. Agent status determination only checked if agent had a call assigned (`oncall`), not if phone was actually ringing
4. QueueMemberStatus events with Status=6 (AST_DEVICE_RINGING) were received but not exposed to frontend

**Fix - Part 1: Frontend Cleanup** (`javascript.js`)

Removed **four blocks** of incorrect frontend logic that was overriding backend status:

```javascript
// REMOVED: Setting all free agents to "Ringing" when calls appeared (lines 467-488)
// This incorrectly changed agent color from green to pistachio during customer ringing

// REMOVED: Setting all free agents to "Ringing" on page reload (lines 494-513)
// Same incorrect behavior on page refresh

// REMOVED: Setting "Ringing" agents to "Free" when calls ended (lines 470-491)
// Frontend was inferring status from call events instead of trusting backend

// REMOVED: Setting other agents to "Free" when one becomes "Busy" (lines 434-459)
// Incorrect multi-agent status assumptions
```

**Impact of Frontend Fix**: Frontend now **only displays status received from backend** via ECCP protocol, never infers or changes status based on call events.

**Fix - Part 2: Backend Queue Status Exposure** (`Agente.class.php`)

Added `queue_status` field to agent info that exposes the highest device status from all queues:

```php
public function resumenSeguimiento() {
    // Get highest queue status (6=RINGING is higher priority than 1=NOT_INUSE)
    $max_queue_status = AST_DEVICE_UNKNOWN;
    foreach ($this->_estado_agente_colas as $queue => $status) {
        if ($status > $max_queue_status) $max_queue_status = $status;
    }

    return array(
        ...
        'queue_status' => $max_queue_status,  // NEW: Expose device status from QueueMemberStatus events
        ...
    );
}
```

**Fix - Part 3: ECCP Protocol Enhancement** (`ECCPConn.class.php`)

Modified agent status determination to check queue device status:

```php
} elseif ($infoAgente['estado_consola'] == 'logged-in') {
    // Check if agent is ringing (queue status = 6 = AST_DEVICE_RINGING)
    if (isset($infoAgente['queue_status']) && $infoAgente['queue_status'] == 6) {
        $sAgentStatus = 'ringing';  // NEW: Fifth status value
    } else {
        $sAgentStatus = 'online';
    }
}
```

**Fix - Part 4: i18n Support**

Added translation for new 'ringing' status:
- English: `'ringing' => 'Ringing'`
- Spanish: `'ringing' => 'Timbrando'`

**Technical Details**:

The fix leverages the existing QueueMemberStatus AMI event infrastructure:
1. When queue dials an agent, Asterisk sends `QueueMemberStatus` event with `Status=6` (AST_DEVICE_RINGING)
2. `AMIEventProcess::msg_QueueMemberStatus()` receives event and calls `$a->actualizarEstadoEnCola($queue, 6)`
3. Agent stores status in `$this->_estado_agente_colas[$queue] = 6`
4. **NEW**: `resumenSeguimiento()` exposes this as `queue_status` field
5. **NEW**: ECCP checks `queue_status==6` and sends `'ringing'` to frontend
6. Frontend receives 'ringing', translates it, and displays appropriate status/color

**Call Flow for Outgoing Campaigns** (Callback Extension):
1. Dialer originates call → Customer phone rings → Agent shows "Free" (green)
2. Customer answers → Call enters queue → `QueueCallerJoin` event
3. Queue dials agent → `QueueMemberStatus` with Status=6 → **Agent shows "Ringing"** ✅
4. Agent answers → `Bridge` event → Call assigned → Agent shows "Busy" (yellow) ✅

**Impact**:
- Callback extension agents now correctly show "Ringing" status when their phone is ringing
- Regular Agent type also benefits from more accurate status display based on actual device state
- Frontend status is now completely driven by backend AMI events, eliminating race conditions and incorrect state assumptions
- Campaign monitoring display is more reliable and accurate

---

### 7. Agent Console Duplicate Name Display Fix
**Files**:
- `modules/agent_console/index.php` (lines 893-900)
- `modules/agent_console/lang/en.lang` (line 72)

**Issue**: In agent console Information section for outgoing calls, the second CSV column appeared twice - once as "Names:" (hardcoded) and once with its actual column name.

**Example**: CSV with `phone,name,Company` showed:
```
Names:    John Doe    (hardcoded field)
name:     John Doe    (dynamic attribute loop)
Company:  Acme Inc
```

**Root Cause**: Legacy code assumed second column (index 1) is always customer name. The hardcoded "Names:" field displayed `call_attributes[1]`, while the dynamic loop also displayed all attributes including index 1.

**Fix**:

**Part 1**: Changed label from "Names" (plural) to "Name" (singular) in `lang/en.lang`:
```php
// BEFORE:
'Names' => 'Names',

// AFTER:
'Names' => 'Name',
```

**Part 2**: Skip index 1 in dynamic attribute loop for outgoing calls in `index.php`:
```php
$atributos = array();
foreach ($infoLlamada['call_attributes'] as $iOrden => $atributo) {
    // Skip index 1 (2nd column) for outgoing calls - it's shown in the hardcoded "Name:" field
    if ($infoLlamada['calltype'] == 'outgoing' && $iOrden == 1) {
        continue;
    }
    // ... rest of the loop
}
```

**Result**: CSV with `phone,name,Company` now displays:
```
Campaign:         Test Out1
Internal Call ID: outgoing-1-XX
Phone number:     0100100100
Name:             John Doe       (hardcoded field, singular)
Company:          Acme Inc       (dynamic loop starts from 3rd column)
```

**Impact**: Eliminates duplicate name display in agent console Information section. The second column is shown once in the hardcoded "Name:" field, and dynamic attributes start from the third column onwards.

---

## Technical Implementation Notes

### Call Status Flow (Outgoing Campaigns)
1. Call is created → Status is NULL
2. Originate starts → Status set to 'Placing' (if NULL)
3. Customer phone rings → Status remains 'Placing'
4. Customer answers → Call enters queue → Status set to 'OnQueue'
5. Agent assigned and answers → Status set to 'Success'

### Call Display Logic (Campaign Monitoring)
- **Placing calls section**: Shows calls with status in `['Placing', 'Dialing', 'Ringing', 'OnQueue']`
- **Agent info section**: Shows calls that have an assigned agent (after customer answers and call is bridged to agent)

### Device Status Constants (app_agent_pool)
```php
AST_DEVICE_NOTINQUEUE = -1  // Not in any queue
AST_DEVICE_UNKNOWN    = 0   // Unknown state
AST_DEVICE_NOT_INUSE  = 1   // Free/Available
AST_DEVICE_INUSE      = 2   // In a call
AST_DEVICE_BUSY       = 3   // Busy
AST_DEVICE_INVALID    = 4   // Invalid
AST_DEVICE_UNAVAILABLE= 5   // Unavailable
AST_DEVICE_RINGING    = 6   // Phone ringing
AST_DEVICE_RINGINUSE  = 7   // Ringing while in use
AST_DEVICE_ONHOLD     = 8   // On hold
```

---

## Files Modified

### Dialer Backend
- `setup/dialer_process/dialer/Llamada.class.php` - Fixed call status initialization
- `setup/dialer_process/dialer/Agente.class.php` - Fixed agent queue status check, login cancellation, and added queue_status field
- `setup/dialer_process/dialer/AMIEventProcess.class.php` - Added actualchannel fallback in msg_Hangup
- `setup/dialer_process/dialer/ECCPConn.class.php` - Added 'ringing' status based on queue device state

### Web Frontend
- `modules/campaign_out/libs/paloSantoCampaignCC.class.php` - Fixed campaign statistics query
- `modules/campaign_monitoring/themes/default/js/javascript.js` - Removed incorrect frontend status inference logic
- `modules/campaign_monitoring/lang/en.lang` - Added 'ringing' status translation
- `modules/campaign_monitoring/lang/es.lang` - Added 'ringing' status translation
- `modules/agent_console/index.php` - Fixed duplicate name display by skipping 2nd column in dynamic loop for outgoing calls
- `modules/agent_console/lang/en.lang` - Changed 'Names' to 'Name' (singular)

---

### 8. Attended Transfer Fix for Agent Type (app_agent_pool)
**Files**:
- `setup/dialer_process/dialer/ECCPConn.class.php` (Request_agentauth_hangup, Request_agentauth_transfer, _registrarTransferencia)
- `setup/dialer_process/dialer/AMIEventProcess.class.php` (msg_Agentlogin, msg_Agentlogoff, msg_prepararAtxferComplete, msg_BridgeEnter)
- `setup/dialer_process/dialer/Llamada.class.php` (actualAgentChannel property)
- `setup/dialer_process/dialer/AMIClientConn.class.php` (Bridge AMI action)
- `/etc/asterisk/extensions_custom.conf` (atxfer-complete context)
- `setup/installer.php` (atxfer-complete context for new installations)

**Issue**: Attended transfer did not work for Agent type agents (app_agent_pool):
1. Transfer initiation failed because AMI Atxfer was called on `Agent/XXXX` (a device state interface, not a real channel)
2. Transfer completion failed because hanging up the agent's channel terminated the entire AgentLogin session

**Root Cause Analysis**:

For Agent type agents using app_agent_pool:
- `Agent/XXXX` is a device state interface, NOT a real Asterisk channel
- The actual phone channel is `login_channel` (e.g., `SIP/101-00000xxx`) running AgentLogin application
- AMI commands like Atxfer must be called on the real `login_channel`, not `Agent/XXXX`
- When completing the transfer, redirecting the agent's channel causes Asterisk to send `Agentlogoff` event, which closes the ECCP session

**Fix - Part 1: Transfer Initiation** (`ECCPConn.class.php`)

Modified `Request_agentauth_transfer()` to use `login_channel` for Agent type:
```php
// Get agent info to determine agent type and login_channel
$infoAgente = $this->_tuberia->AMIEventProcess_infoSeguimientoAgente($sAgente);

// For Agent type (app_agent_pool), use login_channel which is the actual SIP/PJSIP phone
if (!is_null($infoAgente) && !empty($infoAgente['login_channel'])) {
    $transferChannel = $infoAgente['login_channel'];
} else {
    $transferChannel = $infoLlamada['agentchannel'];
}
$r = $this->_ami->Atxfer($transferChannel, $sExtension.'#', 'from-internal', 1);
```

**Fix - Part 2: Transfer Completion - Agentlogoff Suppression** (`AMIEventProcess.class.php`)

Added mechanism to suppress `Agentlogoff` event during transfer completion:

1. Added property to track agents completing transfers:
```php
private $_agentesEnAtxferComplete = array();
```

2. Added message handler to set flag before redirect:
```php
public function msg_prepararAtxferComplete($sFuente, $sDestino, $sNombreMensaje, $iTimestamp, $datos)
{
    $sAgente = $datos[0];
    $this->_agentesEnAtxferComplete[$sAgente] = time();
}
```

3. Modified `msg_Agentlogoff()` to check flag and suppress logoff:
```php
// Check if this agent is completing an attended transfer
if (isset($this->_agentesEnAtxferComplete[$sAgente])) {
    $this->_log->output('DEBUG: SUPPRESSING Agentlogoff for '.$sAgente);
    return FALSE;  // Skip logoff - agent will re-enter AgentLogin
}
```

4. Modified `msg_Agentlogin()` to clear flag after re-login:
```php
$isAtxferRelogin = isset($this->_agentesEnAtxferComplete[$sAgente]);
if ($isAtxferRelogin) {
    unset($this->_agentesEnAtxferComplete[$sAgente]);
}
```

**Fix - Part 3: Transfer Completion - Hangup Handler** (`ECCPConn.class.php`)

Modified `Request_agentauth_hangup()` to handle attended transfer completion:
```php
if ($isAttendedTransfer) {
    // Signal AMIEventProcess to suppress the upcoming Agentlogoff event
    $this->_tuberia->msg_AMIEventProcess_prepararAtxferComplete($sAgente);

    // Redirect agent's channel to atxfer-complete context
    // This causes agent to leave consultation bridge (Atxfer completes),
    // then the context runs AgentLogin to keep agent logged in
    $r = $this->_ami->Redirect(
        $loginChannel,        // Channel: agent's login_channel
        '',                   // ExtraChannel: not used
        $agentNumber,         // Exten: agent number (e.g., 1001)
        'atxfer-complete',    // Context
        1                     // Priority
    );
}
```

**Fix - Part 4: Dialplan Context** (`extensions_custom.conf`)

Added `atxfer-complete` context that re-enters AgentLogin:
```ini
[atxfer-complete]
exten => _X.,1,NoOp(Attended transfer completion - agent ${EXTEN} re-entering AgentLogin)
exten => _X.,n,AgentLogin(${EXTEN})
exten => _X.,n,Macro(hangupcall,)
```

**Fix - Part 5: Callback Agent Hangup Fix** (`ECCPConn.class.php`)

For callback agents (SIP/IAX2/PJSIP type), the `agentchannel` may be stored as just the device name (e.g., `SIP/101`) without the unique call ID suffix. Added check to use `actualchannel` when `agentchannel` lacks unique ID:
```php
elseif ($agentFields['type'] != 'Agent' && strpos($hangchannel, '-') === false) {
    $hangchannel = $infoLlamada['actualchannel'];
}
```

**Event Flow for Attended Transfer Completion**:
1. Agent in AgentLogin, on consultation call with target (102)
2. Hangup clicked → ECCPConn signals AMIEventProcess: "Agent/1001 atxfer completing"
3. ECCPConn redirects SIP/101 to atxfer-complete/1001
4. Agent leaves consultation bridge → Asterisk completes Atxfer (caller connects to 102)
5. Asterisk fires Agentlogoff for Agent/1001
6. msg_Agentlogoff checks flag → **LOGOFF SUPPRESSED**
7. SIP/101 enters atxfer-complete context → runs AgentLogin(1001)
8. Asterisk fires Agentlogin for Agent/1001
9. msg_Agentlogin handles it → agent is back to logged-in state, clears flag
10. Agent session remains active, ready for next call

**Impact**:
- Agent type agents can now perform attended transfers
- Agent session is preserved after transfer completion
- Agent returns to idle state ready for next call
- Callback agent hangup also works correctly

---

## Files Modified

### Dialer Backend
- `setup/dialer_process/dialer/Llamada.class.php` - Fixed call status initialization, added actualAgentChannel property
- `setup/dialer_process/dialer/Agente.class.php` - Fixed agent queue status check, login cancellation, and added queue_status field
- `setup/dialer_process/dialer/AMIEventProcess.class.php` - Added actualchannel fallback in msg_Hangup, added Agentlogoff suppression for attended transfer
- `setup/dialer_process/dialer/ECCPConn.class.php` - Added 'ringing' status, fixed attended transfer initiation and completion
- `setup/dialer_process/dialer/AMIClientConn.class.php` - Added Bridge AMI action definition

### Web Frontend
- `modules/campaign_out/libs/paloSantoCampaignCC.class.php` - Fixed campaign statistics query
- `modules/campaign_monitoring/themes/default/js/javascript.js` - Removed incorrect frontend status inference logic
- `modules/campaign_monitoring/lang/en.lang` - Added 'ringing' status translation
- `modules/campaign_monitoring/lang/es.lang` - Added 'ringing' status translation
- `modules/agent_console/index.php` - Fixed duplicate name display by skipping 2nd column in dynamic loop for outgoing calls
- `modules/agent_console/lang/en.lang` - Changed 'Names' to 'Name' (singular)

### Asterisk Dialplan
- `/etc/asterisk/extensions_custom.conf` - Added atxfer-complete context for transfer completion

### Installer
- `setup/installer.php` - Added atxfer-complete context to extensions_custom.conf during installation

---

### 8. Real-Time Agent Ringing Status in Agents Monitoring
**Files**:
- `setup/dialer_process/dialer/ECCPProxyConn.class.php` (added notificarEvento_AgentStateChange)
- `setup/dialer_process/dialer/Agente.class.php` (modified actualizarEstadoEnCola)
- `setup/dialer_process/dialer/SQLWorkerProcess.class.php` (added agentstatechange handler)
- `modules/agent_console/libs/paloSantoConsola.class.php` (agentstatechange event parsing)
- `modules/rep_agents_monitoring/index.php` (agentstatechange event handling)
- `modules/rep_agents_monitoring/themes/default/js/javascript.js` (ringing status display)
- `modules/rep_agents_monitoring/images/agent-ringing.gif` (ringing animation)
- `modules/rep_agents_monitoring/lang/*.lang` (i18n for READY, RINGING, CALL, BREAK)

**Issue**: Agents Monitoring module only showed "Ready" (green) when agent's phone was ringing, with no real-time update when status changed to/from ringing. The module only detected ringing on page reload, not during live monitoring.

**Root Cause**:
1. No ECCP event was emitted when agent's device status changed to/from ringing
2. QueueMemberStatus AMI events updated internal state but didn't notify connected clients
3. The web module relied on periodic polling (every 30 seconds) which was too slow for ringing status
4. Ringing typically lasts only a few seconds, so changes were missed between polls

**Fix - Part 1: New ECCP Event** (`ECCPProxyConn.class.php`)

Added `notificarEvento_AgentStateChange()` to emit a new event type when agent status changes:
```php
function notificarEvento_AgentStateChange($sAgente, $sNewStatus, $sQueue)
{
    $xml_response = new SimpleXMLElement('<event />');
    $xml_stateChange = $xml_response->addChild('agentstatechange');

    $xml_stateChange->addChild('agent_number', str_replace('&', '&amp;', $sAgente));
    $xml_stateChange->addChild('status', $sNewStatus);  // 'ringing' or 'online'
    $xml_stateChange->addChild('queue', str_replace('&', '&amp;', $sQueue));

    $s = $xml_response->asXML();
    $this->multiplexSrv->encolarDatosEscribir($this->sKey, $s);
}
```

**Fix - Part 2: Emit Event on Device Status Change** (`Agente.class.php`)

Modified `actualizarEstadoEnCola()` to detect and emit events when ringing status changes:
```php
public function actualizarEstadoEnCola($queue, $status)
{
    $oldStatus = isset($this->_estado_agente_colas[$queue]) ? $this->_estado_agente_colas[$queue] : AST_DEVICE_UNKNOWN;
    $this->_estado_agente_colas[$queue] = $status;

    // Emit event when status changes to/from ringing (for real-time UI updates)
    if ($this->estado_consola == 'logged-in' && $oldStatus != $status) {
        $isNowRinging = ($status == AST_DEVICE_RINGING || $status == AST_DEVICE_RINGINUSE);
        $wasRinging = ($oldStatus == AST_DEVICE_RINGING || $oldStatus == AST_DEVICE_RINGINUSE);

        if ($isNowRinging || $wasRinging) {
            $sNewStatus = $isNowRinging ? 'ringing' : 'online';
            $this->_tuberia->msg_SQLWorkerProcess_AgentStateChange(
                $this->channel, $sNewStatus, $queue
            );
        }
    }
}
```

**Fix - Part 3: Route Event Through Dialer** (`SQLWorkerProcess.class.php`)

Registered handler and added event routing:
```php
// Register handler
foreach (array(..., 'AgentStateChange',) as $k)
    $this->_tuberia->registrarManejador('AMIEventProcess', $k, array($this, "msg_$k"));

// Handler method
public function msg_AgentStateChange($sFuente, $sDestino, $sNombreMensaje, $iTimestamp, $datos)
{
    $this->_encolarAccionPendiente('_agentStateChange', $datos);
}

// Event builder
private function _agentStateChange($sAgente, $sNewStatus, $sQueue)
{
    $eventos_forward[] = array('AgentStateChange', array($sAgente, $sNewStatus, $sQueue));
    $eventos[] = array('ECCPProcess', 'emitirEventos', array($eventos_forward));
    return $eventos;
}
```

**Fix - Part 4: Parse Event in ECCP Client** (`paloSantoConsola.class.php`)

Added event parsing:
```php
case 'agentstatechange':
    $evento['status'] = (string)$evt->status;
    $evento['queue'] = (string)$evt->queue;
    break;
```

**Fix - Part 5: Handle Event in Web Module** (`rep_agents_monitoring/index.php`)

Added event handler to update UI in real-time:
```php
case 'agentstatechange':
    $sQueue = $evento['queue'];
    $sNewStatus = $evento['status'];
    if (isset($estadoMonitor[$sQueue][$sCanalAgente])) {
        $jsonKey = 'queue-'.$sQueue.'-member-'.$sNumeroAgente;
        if (isset($jsonData[$jsonKey]) && $jsonData[$jsonKey]['status'] != $sNewStatus) {
            // Update monitor state, JSON data, and emit to client
            $estadoMonitor[$sQueue][$sCanalAgente]['agentstatus'] = $sNewStatus;
            $jsonData[$jsonKey]['status'] = $sNewStatus;
            $jsonData[$jsonKey]['sec_laststatus'] = 0;
            $respuesta[$jsonKey] = $jsonData[$jsonKey];
        }
    }
    break;
```

**Fix - Part 6: Frontend Display** (`javascript.js` + images + i18n)

Added ringing case in status update handler, copied ringing GIF image, and added translations:
- English: READY, RINGING, CALL, BREAK
- Spanish: LISTO, TIMBRANDO, EN LLAMADA, EN PAUSA
- French: PRÊT, SONNERIE, EN APPEL, EN PAUSE
- Russian: ГОТОВ, ЗВОНИТ, НА ЗВОНКЕ, НА ПЕРЕРЫВЕ
- Persian: آماده, زنگ می‌زند, در تماس, در استراحت

**Event Flow**:
1. Asterisk sends `QueueMemberStatus` AMI event with Status=6 (ringing)
2. `AMIEventProcess::msg_QueueMemberStatus()` calls `$a->actualizarEstadoEnCola($queue, 6)`
3. Agent detects status change to ringing → emits `AgentStateChange` event
4. Event routes through SQLWorkerProcess → ECCPProcess → ECCPProxyConn
5. Web client receives event via SSE in real-time
6. Frontend updates agent status to show ringing animation
7. When agent answers → Status changes to 1 (not in use) → event emits with 'online'
8. Frontend updates back to "Ready" status

**Impact**:
- Agents Monitoring now shows real-time ringing status
- No page reload needed to see status changes
- Ringing detection is instant (within milliseconds of AMI event)
- Module behavior matches Campaign Monitoring implementation
- Dialer restart required to apply changes

---

### 9. Agent Hold Feature Bug Fixes
**Files**:
- `setup/dialer_process/dialer/AMIEventProcess.class.php` (msg_ParkedCallGiveUp)
- `setup/dialer_process/dialer/AMIClientConn.class.php` (Park AMI action)
- `setup/dialer_process/dialer/Llamada.class.php` (mandarLlamadaHold)
- `setup/dialer_process/dialer/ECCPConn.class.php` (Request_agentauth_unhold)

**Issue 1: Agent Stuck in Hold State When Customer Hangs Up**

When an agent put a customer on hold and the customer hung up, the agent got stuck in the hold state and could not take new calls until manually logged out and back in.

**Root Cause**: The `msg_ParkedCallGiveUp()` handler had two problems:
1. Used wrong field name `UniqueID` instead of `ParkeeUniqueid` (Asterisk 13+ AMI format change)
2. Called `llamadaRegresaHold()` to clear hold state but didn't call `llamadaFinalizaSeguimiento()` to properly end the call

**Fix**: Updated `msg_ParkedCallGiveUp()` to:
```php
// AMI 13+ uses ParkeeUniqueid, older versions use UniqueID
$uniqueid = isset($params['ParkeeUniqueid']) ? $params['ParkeeUniqueid'] : $params['UniqueID'];
$llamada = $this->_listaLlamadas->buscar('uniqueid', $uniqueid);
if (is_null($llamada)) return;

if ($llamada->status == 'OnHold') {
    // First clear the hold state and close the hold audit record
    $llamada->llamadaRegresaHold($this->_ami, $params['local_timestamp_received']);

    // Then finalize the call since the customer has hung up
    $llamada->llamadaFinalizaSeguimiento(
        $params['local_timestamp_received'],
        $this->_config['dialer']['llamada_corta']);
}
```

---

**Issue 2: Customer Hears Parking Slot Number on Subsequent Holds**

When putting a call on hold more than once during the same call, the customer heard "71" (the parking slot number) on subsequent holds.

**Root Cause**: The Park AMI action was using the old `Channel2` parameter instead of `AnnounceChannel` (Asterisk 13+ format), and the announcement was being sent to the wrong channel.

**Fix - Part 1**: Updated Park action definition in `AMIClientConn.class.php`:
```php
'Park' =>
    array('Channel' => TRUE, 'AnnounceChannel' => FALSE,
        'Timeout' => array('required' => FALSE, 'cast' => 'int'),
        'Parkinglot' => FALSE),
```

**Fix - Part 2**: Updated `mandarLlamadaHold()` in `Llamada.class.php` to not pass announce channel:
```php
public function mandarLlamadaHold($ami, $sFuente, $timestamp)
{
    $callable = array($this, '_cb_Park');
    $call_params = array($sFuente, $ami, $timestamp);
    // Don't pass AnnounceChannel to suppress parking slot announcement to customer
    $ami->asyncPark(
        $callable, $call_params,
        $this->actualchannel);
}
```

---

**Issue 3: Anonymous CallerID When Retrieving Call from Hold**

When an agent ended hold to retrieve the parked call, their phone showed "anonymous@anonymous.invalid" instead of the original caller's number.

**Root Cause**: The Originate call in `Request_agentauth_unhold()` didn't set a CallerID.

**Fix**: Added CallerID using the `callnumber` field from call info:
```php
// Set CallerID to show original caller info when retrieving from hold
$sCallerID = NULL;
if (isset($infoLlamada['callnumber']) && !empty($infoLlamada['callnumber'])) {
    $sCallerID = '"'.$infoLlamada['callnumber'].'" <'.$infoLlamada['callnumber'].'>';
}

$r = $this->_ami->Originate(
    $sCanalOrigen,               // channel
    $infoLlamada['park_exten'],  // extension
    'from-internal',             // context
    '1',                         // priority
    NULL, NULL, NULL,            // Application, Data, Timeout
    $sCallerID,                  // CallerID
    NULL, NULL,                  // Variable, Account
    TRUE,                        // async
    $sActionID
);
```

**Impact**:
- Agents no longer get stuck when customers hang up while on hold
- Customers don't hear the parking slot number announcement
- Agents see the caller's number when retrieving calls from hold
- Hold feature now works reliably for multiple hold/unhold cycles

---

### 10. Attended Transfer Fix for Incoming Campaigns (DTMF Hook Loss)
**Files**:
- `setup/dialer_process/dialer/ECCPConn.class.php` (Request_agentauth_atxfercall, Request_agentauth_hangcall)
- `setup/dialer_process/dialer/AMIEventProcess.class.php` (prepararAtxferComplete changed to synchronous RPC)
- `setup/dialer_process/dialer/AMIClientConn.class.php` (Redirect action extended with ExtraExten/ExtraContext/ExtraPriority)
- `/etc/asterisk/extensions_custom.conf` (3 new contexts: issabel-atxfer-hold, issabel-atxfer-consult, issabel-atxfer-bridge)
- `setup/installer.php` (new contexts for fresh installations)

**Issue**: Attended transfer did not work for incoming campaign calls with Agent type (app_agent_pool). When the agent clicked "Attended Transfer", two DTMF sounds were heard but no transfer occurred. Outgoing campaigns worked but had a race condition causing agent session drop on transfer completion.

**Root Cause Analysis**:

The AMI `Atxfer` action uses DTMF emulation internally: it queues DTMF frames for the `*2` feature code followed by the target extension digits and `#` terminator. These DTMF frames are matched against **bridge DTMF hooks** set by the Queue application's `t` option.

For Agent type (app_agent_pool), Asterisk performs **Local channel optimization**: the `Local/1001@agents;1` intermediary channel is removed and the agent's SIP phone (e.g., `SIP/101`) is swapped directly into the queue bridge. During this swap, the bridge creates a new `bridge_channel` structure for `SIP/101` — but the DTMF hooks that were registered on the original `Local;1` bridge_channel are **not carried over**.

Confirmed via Asterisk debug (bridge_channel.c):
```
DTMF feature string on SIP/101-0000017e is now '*2'
No DTMF feature hooks on SIP/101-0000017e match '*2'
Playing DTMF stream '*2' out to SIP/120Issabel4-00000181
```

The DTMF passes through as audio to the external caller instead of triggering the attended transfer feature.

**Why outgoing campaigns worked**: The bridge structure differs between incoming and outgoing calls, and the DTMF hooks happen to survive optimization in the outgoing case.

**Fix - Part 1: Replace Atxfer with Redirect for Agent Type** (`ECCPConn.class.php`)

For Agent type agents, the attended transfer now uses AMI `Redirect` with `ExtraChannel` instead of `Atxfer`. This bypasses the DTMF hook mechanism entirely by explicitly moving both channels to new dialplan contexts:

```php
// For Agent type (app_agent_pool), use Redirect with ExtraChannel
if (!is_null($infoAgente) && !empty($infoAgente['login_channel'])) {
    $transferChannel = $infoAgente['login_channel'];
    $clientChannel = $infoLlamada['actualchannel'];
    $agentNumber = substr($sAgente, strpos($sAgente, '/') + 1);

    // Set channel variables for the dialplan
    $this->_ami->SetVar($transferChannel, 'ATXFER_HELD_CHAN', $clientChannel);
    $this->_ami->SetVar($transferChannel, 'ATXFER_AGENT_NUM', $agentNumber);

    // Suppress Agentlogoff that fires when agent leaves AgentLogin bridge
    $this->_tuberia->AMIEventProcess_prepararAtxferComplete($sAgente);

    // Redirect both channels simultaneously
    $r = $this->_ami->Redirect(
        $transferChannel,           // Agent's SIP phone -> consultation
        $clientChannel,             // External caller -> MOH
        $sExtension,                // Target extension
        'issabel-atxfer-consult',   // Agent dials target here
        1,
        's',                        // Hold context uses 's' extension
        'issabel-atxfer-hold',      // Caller hears MOH here
        1
    );
} else {
    // For non-Agent types or Asterisk 11/13, use Atxfer (DTMF hooks intact)
    $r = $this->_ami->Atxfer($transferChannel, $sExtension.'#', 'from-internal', 1);
}
```

**Fix - Part 2: Extended Redirect AMI Definition** (`AMIClientConn.class.php`)

Added optional `ExtraExten`, `ExtraContext`, `ExtraPriority` parameters to the Redirect action definition. Existing 5-argument Redirect calls are unaffected since new parameters are optional:

```php
'Redirect' =>
    array('Channel' => TRUE, 'ExtraChannel' => FALSE, 'Exten' => TRUE,
        'Context' => TRUE, 'Priority' => TRUE,
        'ExtraExten' => FALSE, 'ExtraContext' => FALSE, 'ExtraPriority' => FALSE),
```

**Fix - Part 3: Dialplan Contexts** (`extensions_custom.conf`)

Three new contexts handle the attended transfer flow:

```ini
; External caller hears MOH while agent consults with target
[issabel-atxfer-hold]
exten => s,1,NoOp(Issabel CallCenter: Attended Transfer - Caller on hold)
 same => n,Answer()
 same => n,MusicOnHold(default)

; Agent dials target for consultation
; g option: if target hangs up, agent reconnects with held caller via Bridge()
; F() option: when agent completes transfer (Hangup), target bridges with held caller
[issabel-atxfer-consult]
exten => _X.,1,NoOp(Issabel CallCenter: Attended Transfer - Consulting ${EXTEN})
 same => n,Set(__ATXFER_HELD_CHAN=${ATXFER_HELD_CHAN})
 same => n,Set(AGENT_NUM=${ATXFER_AGENT_NUM})
 same => n,Dial(Local/${EXTEN}@from-internal,120,gF(issabel-atxfer-bridge^s^1))
 same => n,NoOp(Issabel CallCenter: Consultation ended - reconnecting with caller)
 same => n,Bridge(${ATXFER_HELD_CHAN})
 same => n,Goto(atxfer-complete,${AGENT_NUM},1)

; Target bridges with held caller after transfer completion
[issabel-atxfer-bridge]
exten => s,1,NoOp(Issabel CallCenter: Transfer complete - bridging target with held caller)
 same => n,Bridge(${ATXFER_HELD_CHAN})
 same => n,Hangup()
```

**Fix - Part 4: Race Condition Fix** (`AMIEventProcess.class.php`, `ECCPConn.class.php`)

Changed `prepararAtxferComplete` from async message (`msg_AMIEventProcess_prepararAtxferComplete`) to synchronous RPC (`AMIEventProcess_prepararAtxferComplete`). The async message was not processed before the Redirect fired, so the Agentlogoff suppression flag was not set in time.

```php
// AMIEventProcess.class.php - Changed from msg handler to RPC handler
public function rpc_prepararAtxferComplete($sFuente, $sDestino,
    $sNombreMensaje, $iTimestamp, $datos)
{
    $this->_tuberia->enviarRespuesta($sFuente, call_user_func_array(
        array($this, '_prepararAtxferComplete'), $datos));
}

// ECCPConn.class.php - Changed from async to synchronous call
// BEFORE: $this->_tuberia->msg_AMIEventProcess_prepararAtxferComplete($sAgente);
// AFTER:
$this->_tuberia->AMIEventProcess_prepararAtxferComplete($sAgente);
```

**Transfer Flow (Agent Type)**:
1. Agent clicks "Attended Transfer" to extension 102
2. ECCPConn sets channel variables (`ATXFER_HELD_CHAN`, `ATXFER_AGENT_NUM`) on agent's SIP phone
3. ECCPConn sets `prepararAtxferComplete` flag via synchronous RPC (suppresses Agentlogoff)
4. AMI Redirect moves both channels: SIP/101 → `issabel-atxfer-consult`, caller → `issabel-atxfer-hold`
5. Agent hears extension 102 ringing, caller hears MOH
6. When agent clicks "Hangup" to complete: SIP/101 is redirected to `atxfer-complete`, Dial() F() option fires → target bridges with held caller
7. Agent re-enters AgentLogin via `atxfer-complete` context, session preserved

**Cancellation scenarios**:
- Target doesn't answer: Dial() returns, Bridge() reconnects agent with held caller, then agent re-enters AgentLogin
- Target hangs up during consultation: same as above (Dial `g` option)
- Client hangs up while on hold: Bridge() fails silently, target/agent are cleaned up normally

**Backward Compatibility**:
- Non-Agent types (SIP/IAX2/PJSIP callback): still use Atxfer (DTMF hooks are intact)
- Asterisk 11/13: no `login_channel` available → Atxfer path used
- Existing Redirect calls (blind transfer, hangup handler): unaffected by new optional parameters

---

### 11. Attended Transfer Busy Tone Delay Fix for Callback Agents
**Files**:
- `setup/dialer_process/dialer/ECCPConn.class.php` (Request_agentauth_atxfercall)
- `/etc/asterisk/extensions_custom.conf` (cbext-atxfer context)
- `setup/installer.php` (cbext-atxfer context for new installations)

**Issue**: When a callback agent (SIP/IAX2/PJSIP type) initiated an attended transfer and the colleague declined, the agent heard a busy tone for ~20 seconds while the client stayed on hold. Agent type (app_agent_pool) worked fine - only callback type had this issue.

**Root Cause**: The `Atxfer` AMI action routes through `from-internal` context. When the target declines, IssabelPBX's `macro-exten-vm` reaches the `s-BUSY` handler which executes `Busy(20)` - playing 20 seconds of busy tone before hanging up. Only then does Asterisk detect the transfer failed and reconnect the agent.

**Fix - Part 1: Custom Dialplan Context** (`extensions_custom.conf`, `installer.php`)

Created `cbext-atxfer` context that dials the device directly:

```ini
; Attended transfer context for callback agents (SIP/IAX2/PJSIP)
; Dials device directly to avoid busy tone delay from from-internal failure handling
[cbext-atxfer]
exten => _X.,1,NoOp(Issabel CallCenter: Callback attended transfer routing for ${EXTEN})
 same => n,Set(CLEAN_EXTEN=${FILTER(0123456789,${EXTEN})})
 same => n,ExecIf($["${CLEAN_EXTEN}" = ""]?Set(CLEAN_EXTEN=${EXTEN}))
 same => n,Set(DIAL_DEVICE=${DB(DEVICE/${CLEAN_EXTEN}/dial)})
 same => n,GotoIf($["${DIAL_DEVICE}" != ""]?direct)
 same => n(fallback),NoOp(Issabel CallCenter: No device found - routing via from-internal)
 same => n,Dial(Local/${CLEAN_EXTEN}@from-internal/n,120)
 same => n,Hangup()
 same => n(direct),NoOp(Issabel CallCenter: Direct device dial: ${DIAL_DEVICE})
 same => n,GotoIf($["${DIAL_DEVICE:0:5}" = "PJSIP"]?pjsip)
 same => n,Dial(${DIAL_DEVICE},120)
 same => n,Hangup()
 same => n(pjsip),Set(PJSIP_CONTACTS=${PJSIP_DIAL_CONTACTS(${CLEAN_EXTEN})})
 same => n,ExecIf($["${PJSIP_CONTACTS}" = ""]?Set(PJSIP_CONTACTS=${DIAL_DEVICE}))
 same => n,Dial(${PJSIP_CONTACTS},120)
 same => n,Hangup()
```

**Error handling**:
- `FILTER` strips non-digit chars; if result is empty, falls back to raw `EXTEN`
- `DB(DEVICE/EXT/dial)` returns empty for external numbers → falls through to `from-internal`
- `PJSIP_DIAL_CONTACTS` returns empty if not registered → falls back to `DIAL_DEVICE`
- All paths end with `Hangup()` - no busy/congestion tones on failure
- NoOp logging at each branch for debugging

**Fix - Part 2: Set TRANSFER_CONTEXT** (`ECCPConn.class.php`)

Modified `Request_agentauth_atxfercall()` callback branch to use custom context:

```php
// Set TRANSFER_CONTEXT to use custom context that dials device directly
// This avoids the 20-second busy tone delay when target declines
$this->_ami->SetVar($transferChannel, 'TRANSFER_CONTEXT', 'cbext-atxfer');

$r = $this->_ami->Atxfer(
    $transferChannel,
    $sExtension.'#',    // exten
    'cbext-atxfer',     // context - use custom context to avoid busy tone delay
    1);                 // priority
```

**Transfer Flow (Callback Agent)**:
1. Agent clicks "Attended Transfer" to extension 102
2. ECCPConn sets `TRANSFER_CONTEXT=cbext-atxfer`
3. AMI Atxfer dials 102 via cbext-atxfer context
4. Context looks up `DEVICE/102/dial` → gets `SIP/102` or `PJSIP/102`
5. Direct Dial() to device - no from-internal routing
6. If target declines: Dial() returns BUSY immediately, Hangup() fires
7. Asterisk detects transfer failure → instantly reconnects agent with caller (no busy tone)

**Backward Compatibility**:
- Agent type (app_agent_pool): unchanged - uses Redirect, not Atxfer
- External numbers: falls through to `from-internal` (no DEVICE entry)
- Non-PJSIP: direct Dial() to `SIP/XXX` or `IAX2/XXX`
- PJSIP: uses `PJSIP_DIAL_CONTACTS()` for proper AOR lookup with fallback

**Impact**:
- Callback agents no longer hear 20-second busy tone when target declines
- Transfer failure reconnection is instant
- Agent type transfers continue to work unchanged
- External numbers still route through from-internal

---

### 12. Hold/Transfer Button State During Attended Transfer Consultation
**Files**:
- `setup/dialer_process/dialer/AMIEventProcess.class.php` (consultation tracking, Channel1=Channel2 detection, UserEvent handler)
- `setup/dialer_process/dialer/ECCPConn.class.php` (consultation flag in atxfercall response)
- `setup/dialer_process/dialer/ECCPProxyConn.class.php` (ConsultationStart/End event notifications)
- `setup/dialer_process/dialer/ECCPProcess.class.php` (emitirEventos handler for AMIEventProcess)
- `modules/agent_console/libs/paloSantoConsola.class.php` (consultationstart/end event parsing, consultation return from transferirLlamada)
- `modules/agent_console/index.php` (consultation events in checkStatus, consultation flag in transfer response)
- `modules/agent_console/themes/default/js/javascript.js` (consultationstart/end handlers, button disable on consultation response)
- `/etc/asterisk/extensions_custom.conf` (UserEvent in atxfer-consult context for Agent type)

**Issue**: During an attended transfer, the Hold and Transfer buttons remained enabled while the agent was consulting with the target. If the consultation call was rejected or hung up by the colleague, the buttons should re-enable when the agent returns to the original caller. Instead, the buttons stayed in their original state throughout.

**Root Cause**: No mechanism existed to communicate the consultation call state (start/end) from the dialer to the agent console UI. The ECCP protocol had no events for consultation status, and the transfer AJAX response didn't indicate that a consultation was in progress.

**Fix - Part 1: Consultation Tracking** (`AMIEventProcess.class.php`)

Added `_agentesEnConsultation` array to track agents currently in attended transfer consultation:

```php
private $_agentesEnConsultation = array();
```

Added `msg_marcarConsultationIniciada` handler that sets the flag and emits a `ConsultationStart` event:

```php
public function _marcarConsultationIniciada($sAgente)
{
    $this->_agentesEnConsultation[$sAgente] = time();
    $this->_tuberia->msg_ECCPProcess_emitirEventos(array(
        array('ConsultationStart', array($sAgente))
    ));
}
```

**Fix - Part 2: Consultation End Detection - Callback Type** (`AMIEventProcess.class.php`)

For callback agents using Atxfer, when the consultation fails and the agent returns to the original bridge, Asterisk fires a `Bridge` event where `Channel1 == Channel2` (both are the agent's channel). This is detected early in `msg_Link`:

```php
if ($params['Channel1'] == $params['Channel2'] && !is_null($sChannel)) {
    if (isset($this->_agentesEnConsultation[$sChannel])) {
        unset($this->_agentesEnConsultation[$sChannel]);
        $this->_tuberia->msg_ECCPProcess_emitirEventos(array(
            array('ConsultationEnd', array($sChannel))
        ));
        return FALSE;
    }
    return FALSE;
}
```

**Fix - Part 3: Consultation End Detection - Agent Type** (`AMIEventProcess.class.php`, `extensions_custom.conf`)

For Agent type using Redirect-based transfer, consultation end is detected via Asterisk UserEvent emitted from the `atxfer-consult` dialplan context:

```ini
; In atxfer-consult context, after Dial() returns (target declined/hung up):
same => n,UserEvent(ConsultationEnd,Agent: Agent/${AGENT_NUM})
```

```php
public function msg_UserEvent($sEvent, $params, $sServer, $iPort)
{
    if (!isset($params['UserEvent'])) return FALSE;
    if ($params['UserEvent'] == 'ConsultationEnd' && isset($params['Agent'])) {
        $sAgente = trim($params['Agent']);
        if (isset($this->_agentesEnConsultation[$sAgente])) {
            unset($this->_agentesEnConsultation[$sAgente]);
            $this->_tuberia->msg_ECCPProcess_emitirEventos(array(
                array('ConsultationEnd', array($sAgente))
            ));
        }
    }
    return FALSE;
}
```

**Fix - Part 4: ECCP Event Pipeline** (`ECCPProcess.class.php`, `ECCPProxyConn.class.php`)

Registered `emitirEventos` handler for messages from `AMIEventProcess` (previously only registered for `SQLWorkerProcess`):

```php
foreach (array('recordingMute', 'recordingUnmute', 'emitirEventos') as $k)
    $this->_tuberia->registrarManejador('AMIEventProcess', $k, array($this, "msg_$k"));
```

Added `notificarEvento_ConsultationStart()` and `notificarEvento_ConsultationEnd()` methods to send XML events to connected ECCP clients.

**Fix - Part 5: Immediate Button Disable via Transfer Response** (`ECCPConn.class.php`, `paloSantoConsola.class.php`, `index.php`, `javascript.js`)

Because the `ConsultationStart` ECCP event may arrive between checkStatus polls (timing issue), the consultation flag is also returned directly in the transfer AJAX response:

```php
// ECCPConn.class.php - Add consultation flag to atxfercall response
$xml_transferResponse->addChild('consultation', 'true');

// paloSantoConsola.class.php - Parse consultation flag
if ($bAtxfer && isset($respuesta->consultation) && (string)$respuesta->consultation == 'true') {
    return 'consultation';
}

// index.php - Include in JSON response
} elseif ($bExito === 'consultation') {
    $respuesta['consultation'] = true;
}

// javascript.js - Disable buttons on consultation response
} else if (respuesta['consultation']) {
    $('#btn_hold').button('disable');
    $('#btn_transfer').button('disable');
}
```

**Fix - Part 6: checkStatus Event Handlers** (`paloSantoConsola.class.php`, `index.php`, `javascript.js`)

Added `consultationstart` and `consultationend` event parsing and handling throughout the event pipeline:

```javascript
// javascript.js
case 'consultationstart':
    $('#btn_hold').button('disable');
    $('#btn_transfer').button('disable');
    break;
case 'consultationend':
    $('#btn_hold').button('enable');
    $('#btn_transfer').button('enable');
    break;
```

**Event Flow (Callback Type)**:
1. Agent clicks Attended Transfer → ECCPConn sends `msg_marcarConsultationIniciada`
2. Transfer response includes `consultation=true` → JS disables buttons immediately
3. Target declines → Asterisk fires Bridge event with Channel1=Channel2
4. `msg_Link` detects consultation end → emits `ConsultationEnd` event
5. Event reaches JS via checkStatus → buttons re-enabled

**Event Flow (Agent Type)**:
1. Agent clicks Attended Transfer → ECCPConn sends `msg_marcarConsultationIniciada`
2. Transfer response includes `consultation=true` → JS disables buttons immediately
3. Target declines → `atxfer-consult` Dial() returns → UserEvent(ConsultationEnd) fires
4. `msg_UserEvent` detects consultation end → emits `ConsultationEnd` event
5. Event reaches JS via checkStatus → buttons re-enabled

**Impact**:
- Hold and Transfer buttons are disabled during attended transfer consultation for both Agent and callback types
- Buttons are re-enabled when the consultation call is rejected or hung up by the colleague
- Dual delivery mechanism (transfer response + ECCP event) ensures reliable button state change regardless of timing

---

## Version History

- **4.0.0.13** - Hold/Transfer button state management during attended transfer consultation
- **4.0.0.12** - Attended transfer busy tone delay fix for callback agents
- **4.0.0.11** - Attended transfer fix for incoming campaigns (DTMF hook loss after Local channel optimization)
- **4.0.0.9** - Real-time agent ringing status in Agents Monitoring module
- **4.0.0.8** - Attended transfer fix for Agent type (app_agent_pool), callback agent hangup fix
- **4.0.0.7** - Bug fixes for campaign monitoring display, agent console hangup, statistics sync, and login cancellation
- **4.0.0.6** - app_agent_pool migration (PHP 8 compatibility, agent password login)
