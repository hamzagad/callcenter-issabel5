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

### 13. Disable Transfer Button While Call Is On Hold
**Files**:
- `modules/agent_console/themes/default/js/javascript.js` (holdenter/holdexit handlers, agentlinked guard, initialize_client_state)
- `modules/agent_console/index.php` (backend guard in transfer handler)

**Issue**: The Transfer button remained enabled while a call was on hold. Agents could attempt to transfer a parked call, which is not a valid operation. Additionally, refreshing the page while on hold would re-enable the Transfer button.

**Fix - Part 1: Frontend Hold Event Handlers** (`javascript.js`)

Disable Transfer button when entering hold and re-enable when exiting:

```javascript
case 'holdenter':
    estadoCliente.onhold = true;
    $('#btn_hold').button('option', 'label', respuesta[i].txt_btn_hold);
    $('#btn_transfer').button('disable');
    break;
case 'holdexit':
    estadoCliente.onhold = false;
    $('#btn_hold').button('option', 'label', respuesta[i].txt_btn_hold);
    $('#btn_transfer').button('enable');
    break;
```

**Fix - Part 2: Agentlinked Guard** (`javascript.js`)

The `agentlinked` event enables all buttons (Hold, Transfer, Hangup). On page refresh, events are processed in order: `holdenter` first, then `agentlinked`. Without a guard, `agentlinked` would re-enable Transfer even though the call is on hold:

```javascript
case 'agentlinked':
    $('#btn_hangup').button('enable');
    $('#btn_hold').button('enable');
    if (!estadoCliente.onhold) {
        $('#btn_transfer').button('enable');
    }
```

**Fix - Part 3: Page Refresh State** (`javascript.js`)

Added hold check in `initialize_client_state()` to disable Transfer before the first checkStatus cycle:

```javascript
if (estadoCliente.onhold) {
    $('#btn_transfer').button('disable');
}
```

**Fix - Part 4: Backend Guard** (`index.php`)

Added server-side validation to reject transfer requests while on hold:

```php
if ($estado['onhold']) {
    $respuesta['action'] = 'error';
    $respuesta['message'] = _tr('Cannot transfer while call is on hold');
}
```

**Impact**:
- Transfer button is disabled while call is on hold
- Transfer button is re-enabled when hold ends
- Page refresh preserves the correct button state
- Backend rejects transfer attempts during hold as a safety net

---

### 14. Stale Channel After Hold Breaks Attended Transfer and Hangup

**Files**:
- `setup/dialer_process/dialer/Llamada.class.php` (`llamadaRegresaHold()`)
- `setup/dialer_process/dialer/AMIEventProcess.class.php` (`msg_Link()`)

**Issue**: Two related bugs caused by hold recovery not properly updating channel tracking fields in `Llamada`:

1. **Callback extension (SIP/IAX2/PJSIP)**: After using Hold then attempting attended transfer, the transfer failed with Error 500 ("Channel specified does not exist"). The `Atxfer` AMI action used the stale `actualAgentChannel` from before hold (e.g., `SIP/101-000000a4`) instead of the new channel created during hold recovery (e.g., `SIP/101-000000a5`).

2. **Agent type (app_agent_pool)**: After using Hold, performing attended transfer, and then clicking Hangup when the consultation call returned, hangup failed with Error 500 ("No such channel"). The `agentchannel` field was overwritten from `Agent/1001` to `Local/1001@agents-0000004d;1` during hold recovery, breaking the `preg_match('|^Agent/\d+$|')` pattern check in the hangup handler that routes to the attended transfer completion path.

**Root Cause**: `llamadaRegresaHold()` received the raw channel from `_identificarCanalAgenteLink()` and stored it in `_agentchannel`, but:
- For Agent type: overwrote the logical name (`Agent/1001`) with the actual Local channel, breaking pattern matching
- For both types: never updated `_actualAgentChannel`, leaving it stale from before hold

**Fix - Part 1: Update `llamadaRegresaHold()` signature** (`Llamada.class.php`)

Added `$sActualAgentChannel` parameter to properly update both channel fields, consistent with how `llamadaEnlazadaAgente()` works:

```php
public function llamadaRegresaHold($ami, $iTimestamp, $sAgentChannel = NULL,
    $uniqueid_agente = NULL, $sActualAgentChannel = NULL)
{
    if (!is_null($sAgentChannel)) $this->_agentchannel = $sAgentChannel;
    if (!is_null($sActualAgentChannel)) {
        $this->_actualAgentChannel = $sActualAgentChannel;
    } elseif (!is_null($sAgentChannel)) {
        $this->_actualAgentChannel = $sAgentChannel;
    }
```

**Fix - Part 2: Pass correct channel values from call site** (`AMIEventProcess.class.php`)

Changed the `llamadaRegresaHold()` call in `msg_Link()` to pass `$sChannel` (logical name: `Agent/1001` or `SIP/101`) as `_agentchannel` and `$sAgentChannel` (actual channel: `Local/1001@agents-xxx` or `SIP/101-xxx`) as `_actualAgentChannel`:

```php
$llamada->llamadaRegresaHold($this->_ami,
    $params['local_timestamp_received'], $sChannel,
    ($llamada->uniqueid == $params['Uniqueid1']) ? $params['Uniqueid2'] : $params['Uniqueid1'],
    $sAgentChannel);
```

**After fix, channel values are consistent with initial link**:

| Field | Agent type | Callback type |
|-------|-----------|---------------|
| `_agentchannel` | `Agent/1001` (logical) | `SIP/101` (logical) |
| `_actualAgentChannel` | `Local/1001@agents-xxx` (actual) | `SIP/101-xxx` (actual) |

**Impact**:
- Callback agents can perform attended transfer after using Hold
- Agent type agents can complete attended transfer (Hangup) after using Hold
- Channel tracking remains consistent across hold/unhold cycles

---

### 15. ConsultationEnd Not Detected After Hold for Callback Agents

**Files**:
- `setup/dialer_process/dialer/AMIEventProcess.class.php` (`msg_Link()`)

**Issue**: For callback extension agents, if Hold was used before initiating attended transfer, the Hold and Transfer buttons stayed disabled after the consultation call ended (colleague hung up). The `ConsultationEnd` event was never emitted, so the frontend never re-enabled the buttons.

**Root Cause**: The existing consultation end detection (added in section 12) relied on a Bridge event where `Channel1 == Channel2`. This only works when the agent's channel was the **first** to enter the bridge (saved as Channel2 in `msg_BridgeEnter`).

After hold recovery, the bridge is rebuilt with the **caller's channel** entering first (saved as Channel2). When the agent returns from consultation:
- `Channel1` = `SIP/101-000000e8` (agent, current BridgeEnter)
- `Channel2` = `SIP/120Issabel4-000000e6` (caller, saved from hold recovery)
- `Channel1 != Channel2` → ConsultationEnd never fires

Without hold, the agent's channel is typically saved first during the initial bridge creation, so `Channel1 == Channel2` when the agent re-enters after consultation. The hold recovery changes the bridge creation order.

**Fix**: Added a fallback consultation end detection in `msg_Link()` that does not depend on bridge channel ordering. When a Bridge event links an already-linked call back to the same agent AND that agent is in consultation, emit `ConsultationEnd`:

```php
// Detect agent returning from consultation to the original call.
// After hold recovery, Channel1 != Channel2 (bridge saved the caller's
// channel, not the agent's), so the Channel1==Channel2 check above
// does not fire. Instead, detect by: call already linked to same agent
// AND agent is in consultation.
if (!is_null($llamada) && !is_null($llamada->timestamp_link) &&
    !is_null($llamada->agente) && $llamada->agente->channel == $sChannel &&
    !is_null($sChannel) && isset($this->_agentesEnConsultation[$sChannel])) {
    unset($this->_agentesEnConsultation[$sChannel]);
    $this->_tuberia->msg_ECCPProcess_emitirEventos(array(
        array('ConsultationEnd', array($sChannel))
    ));
    return FALSE;
}
```

This check fires when:
- The call is already linked to the same agent (`timestamp_link` set, `agente->channel == $sChannel`)
- The agent is tracked as being in consultation (`_agentesEnConsultation[$sChannel]`)
- A new Bridge event re-links the agent to the original call

**Impact**:
- Hold and Transfer buttons correctly re-enable after consultation ends, regardless of whether Hold was used before the transfer
- The existing `Channel1 == Channel2` detection remains as the primary path for the non-hold case

---

### 16. Agent Type Hold Recovery Fix for Post-Attended-Transfer Calls

**Files**:
- `setup/dialer_process/dialer/ECCPConn.class.php` (Request_agentauth_unhold - Redirect-based unhold for atxfer case)
- `setup/dialer_process/dialer/AMIEventProcess.class.php` (rpc_iniciarHoldAgente, rpc_esAgenteEnAtxferComplete, msg_Link, msg_Agentlogin, msg_ParkedCallGiveUp)
- `setup/dialer_process/dialer/Llamada.class.php` (atxfer_hold property)
- `/etc/asterisk/extensions_custom.conf` (atxfer-consult holdwait, atxfer-unhold context)
- `setup/installer.php` (dialplan template update - pending)

**Issue 1**: After an Agent type attended transfer consultation failed (colleague declined/hung up) and the agent was re-bridged to the customer via `Bridge()` in `atxfer-consult`, pressing Hold then End Hold caused ~5 second delay before the call resumed. The dialer repeatedly attempted `Originate(Local/1001@agents)` which failed because the agent pool device state remained UNAVAILABLE (Status=5) for ~5 seconds after `Bridge()` ended.

**Root Cause**: When the agent pressed Hold during the post-consultation bridge:
1. Call was parked → `Bridge()` ended → agent went to `atxfer-complete` → `AgentLogin`
2. `AgentLogin` caused agent pool device state to go UNAVAILABLE for ~5 seconds
3. End Hold triggered `Originate(Local/1001@agents)` → `AgentRequest`
4. `AgentRequest` failed repeatedly with OriginateResponse: Failure until device state updated

**Issue 2**: After Issue 1 fix, when `Bridge()` retrieved the parked call, `ParkedCallGiveUp` event fired and the call disappeared from agent console ("No active call") while audio still worked between agent and caller.

**Root Cause**: When `Bridge()` in `atxfer-unhold` "stole" the parked channel from parking, Asterisk fired `ParkedCallGiveUp` before `BridgeEnter`. The `msg_ParkedCallGiveUp` handler treated this as "caller hung up while on hold" and called both `llamadaRegresaHold` AND `llamadaFinalizaSeguimiento` → `AgentUnlinked` event sent to frontend → call disappeared.

**Issue 3**: After a **successful** attended transfer (colleague accepted), the next incoming call had broken hold functionality. End Hold worked but the button stayed stuck at "End Hold". Clicking Hangup disconnected the call but the agent console stayed stuck at "connected to call".

**Root Cause**: During successful attended transfer, when the agent hung up during `Dial()` in `atxfer-consult`, the `UserEvent(ConsultationEnd)` never fired because the agent's channel was in hangup state. The `_agentesEnConsultation` array entry was never cleared. On the next call:
1. Agent pressed Hold → call parked, Originate succeeded
2. BridgeEnter fired → `msg_Link` checked `_agentesEnConsultation` → found stale entry
3. `msg_Link` treated this as "ConsultationEnd via Link" instead of hold recovery
4. Hold recovery code was skipped (`return FALSE`) → call stayed in `OnHold` status → frontend never got `holdexit` event → button stuck

**Fix - Part 1: Redirect-Based Unhold for Atxfer Case** (`ECCPConn.class.php`)

Keep the agent in `Wait()` instead of `AgentLogin` after `Bridge()` ends during hold. Use `Redirect` + `Bridge()` to retrieve the parked call directly, bypassing `AgentRequest`:

```php
// Check if agent is in atxfer hold-wait state (Agent type only)
$isAtxferHoldWait = FALSE;
if (preg_match('|^Agent/(\d+)$|', $sAgente, $regs)) {
    $isAtxferHoldWait = $this->_tuberia->AMIEventProcess_esAgenteEnAtxferComplete($sAgente);
}

if ($isAtxferHoldWait && !empty($infoSeguimiento['login_channel'])) {
    // Agent is in Wait() in atxfer-consult holdwait - use Redirect + Bridge
    $agentChannel = $infoSeguimiento['login_channel'];
    $this->_ami->SetVar($agentChannel, 'ATXFER_PARKED_CHAN', $infoLlamada['actualchannel']);
    $r = $this->_ami->Redirect($agentChannel, NULL, 's', 'atxfer-unhold', '1');
} else {
    // Original Originate mechanism for normal hold
    // ... existing code ...
}
```

**Fix - Part 2: Dialplan Changes** (`extensions_custom.conf`)

Modified `atxfer-consult` to check `ATXFER_ON_HOLD` variable after `Bridge()` and go to holdwait instead of atxfer-complete:

```ini
[atxfer-consult]
exten => _X.,1,NoOp(Issabel CallCenter: Attended Transfer - Consulting ${EXTEN})
 same => n,Set(__ATXFER_HELD_CHAN=${ATXFER_HELD_CHAN})
 same => n,Set(AGENT_NUM=${ATXFER_AGENT_NUM})
 same => n,Dial(Local/${EXTEN}@from-internal,120,gF(issabel-atxfer-bridge^s^1))
 same => n,NoOp(Issabel CallCenter: Consultation ended - reconnecting with caller)
 same => n,Bridge(${ATXFER_HELD_CHAN})
 same => n,GotoIf($["${ATXFER_ON_HOLD}" = "yes"]?holdwait)
 same => n,Goto(atxfer-complete,${AGENT_NUM},1)
 same => n(holdwait),Set(ATXFER_ON_HOLD=)
 same => n,UserEvent(AtxferHoldWait,Agent: Agent/${AGENT_NUM})
 same => n,Wait(300)
 same => n,Goto(atxfer-complete,${AGENT_NUM},1)
```

Added new `atxfer-unhold` context that retrieves parked call via `Bridge()`:

```ini
[atxfer-unhold]
exten => s,1,NoOp(Issabel CallCenter: Agent retrieving call from hold via Bridge)
 same => n,Bridge(${ATXFER_PARKED_CHAN})
 same => n,GotoIf($["${ATXFER_ON_HOLD}" = "yes"]?holdwait)
 same => n,Goto(atxfer-complete,${AGENT_NUM},1)
 same => n(holdwait),Set(ATXFER_ON_HOLD=)
 same => n,UserEvent(AtxferHoldWait,Agent: Agent/${AGENT_NUM})
 same => n,Wait(300)
 same => n,Goto(atxfer-unhold,s,1)
```

**Fix - Part 3: Hold Initiation** (`AMIEventProcess.class.php`)

Set `ATXFER_ON_HOLD=yes` channel variable and `atxfer_hold` flag when Agent type presses Hold during atxfer bridge:

```php
$bIsAgent = ($a->type == 'Agent');
$bIsAtxfer = isset($this->_agentesEnAtxferComplete[$sAgente]);
$sLoginChan = $a->login_channel;
if ($bIsAgent && $bIsAtxfer && !empty($sLoginChan)) {
    $this->_ami->SetVar($sLoginChan, 'ATXFER_ON_HOLD', 'yes');
    $a->llamada->atxfer_hold = TRUE;
}
```

**Fix - Part 4: New RPC to Check Atxfer State** (`AMIEventProcess.class.php`)

Added synchronous RPC `esAgenteEnAtxferComplete` so ECCPConn can check if agent is in atxfer hold-wait:

```php
public function rpc_esAgenteEnAtxferComplete($sFuente, $sDestino, $sNombreMensaje, $iTimestamp, $datos)
{
    list($sAgente) = $datos;
    $this->_tuberia->enviarRespuesta($sFuente,
        isset($this->_agentesEnAtxferComplete[$sAgente]));
}
```

**Fix - Part 5: ParkedCallGiveUp Fix** (`AMIEventProcess.class.php`, `Llamada.class.php`)

Skip `llamadaFinalizaSeguimiento` when `atxfer_hold` flag is set (Bridge will reconnect, not caller hangup):

```php
// Llamada.class.php - add property
var $atxfer_hold = FALSE;

// AMIEventProcess.class.php - msg_ParkedCallGiveUp
if ($llamada->status == 'OnHold') {
    $llamada->llamadaRegresaHold($this->_ami, $params['local_timestamp_received']);
    if ($llamada->atxfer_hold) {
        $llamada->atxfer_hold = FALSE;
        // Bridge will reconnect - don't finalize
    } else {
        $llamada->llamadaFinalizaSeguimiento(...);
    }
}
```

**Fix - Part 6: Clear Stale Consultation State** (`AMIEventProcess.class.php`)

Clear `_agentesEnConsultation` when agent re-logs after successful transfer:

```php
// In msg_Agentlogin after clearing _agentesEnAtxferComplete
if (isset($this->_agentesEnConsultation[$sAgente])) {
    unset($this->_agentesEnConsultation[$sAgente]);
    $this->_tuberia->msg_ECCPProcess_emitirEventos(array(
        array('ConsultationEnd', array($sAgente))
    ));
}
```

**Fix - Part 7: Hold Recovery Priority** (`AMIEventProcess.class.php`)

Modified consultation end detection in `msg_Link` to check `$llamada->status != 'OnHold'` so hold recovery takes precedence over stale consultation state:

```php
if (!is_null($llamada) && !is_null($llamada->timestamp_link) &&
    !is_null($llamada->agente) && $llamada->agente->channel == $sChannel &&
    !is_null($sChannel) && isset($this->_agentesEnConsultation[$sChannel]) &&
    $llamada->status != 'OnHold') {
    // ConsultationEnd via Link - only if NOT on hold
}
```

**Event Flow After Fix**:
1. **Hold during atxfer**: ATXFER_ON_HOLD=yes set → customer parked → Bridge() ends → dialplan checks variable → agent enters Wait(300)
2. **Unhold**: ECCPConn detects atxfer state → sets ATXFER_PARKED_CHAN → Redirects agent to atxfer-unhold → Bridge() retrieves customer
3. **BridgeEnter**: msg_Link fires → detects hold recovery → llamadaRegresaHold called → hold state cleared
4. **Successful transfer + next call hold**: msg_Agentlogin clears stale _agentesEnConsultation → next call's hold recovery works properly

**Impact**:
- End Hold works immediately after atxfer consultation fails (no 5-second delay)
- Call remains visible in agent console after hold recovery (no "No active call")
- Hold works correctly on calls after successful attended transfer (no stuck button)
- Agent type hold/unhold cycles work reliably in all atxfer scenarios

---

### 17. Attended Transfer Consultation Hangup and Customer Hangup Fixes

**Files**:
- `setup/dialer_process/dialer/ECCPConn.class.php` (Request_agentauth_hangup - two-path logic)
- `setup/dialer_process/dialer/AMIEventProcess.class.php` (_terminarConsultaSiClienteCuelga, rpc_esAgenteEnConsultation)
- `modules/agent_console/themes/default/js/javascript.js` (consultationend handler)
- `/etc/asterisk/extensions_custom.conf` (atxfer-cancel-consult context)
- `setup/installer.php` (atxfer-cancel-consult context for new installations)

**Issue 1**: When the agent clicked Hangup during an attended transfer consultation (before the colleague answered), the consultation call was correctly terminated but the customer call disappeared from the agent console. The customer remained on hold with no way to reconnect.

**Issue 2**: When the customer hung up during an attended transfer consultation, the Hangup button was disabled and the agent could not terminate the ongoing consultation call. Additionally, after the colleague hung up the consultation, Hold and Transfer buttons became incorrectly enabled even though the original caller had already hung up.

**Root Cause - Issue 1**: The Hangup handler had a single code path for attended transfer: it hung up the customer channel and redirected the agent to `atxfer-complete`. This correctly completed the transfer but when used during active consultation (before completing the transfer), it orphaned the customer still on hold.

**Root Cause - Issue 2**:
1. The `_procesarLlamadaColgada` handler had no logic to detect customer hangup during active consultation and terminate the consulting call
2. The `consultationend` JS handler re-enabled Hold and Transfer buttons unconditionally, so when the consultation ended after the customer had already hung up, the buttons were incorrectly enabled

**Fix - Part 1: Two-Path Hangup Handler** (`ECCPConn.class.php`)

The hangup handler now distinguishes between two states using a new synchronous RPC `esAgenteEnConsultation`:

**Path A - Active consultation** (Dial() still in progress, agent consulting colleague):
- Redirect agent's `login_channel` to new `atxfer-cancel-consult` dialplan context
- Context fires `UserEvent(ConsultationEnd)` then `Bridge(${ATXFER_HELD_CHAN})` to reconnect agent directly to the customer
- Call tracking is **preserved** (no `_finalizarTransferencia` called)
- Transfer DB record is cleared (transfer was cancelled)

**Path B - No active consultation** (consultation ended, transfer not completed):
- Hang up customer channel (parked in `atxfer-hold` with MusicOnHold)
- Redirect agent to `atxfer-complete` to re-enter AgentLogin
- Call `_finalizarTransferencia` to finalize call tracking and release agent

```php
$isInConsultation = $this->_tuberia->AMIEventProcess_esAgenteEnConsultation($sAgente);

if ($isInConsultation) {
    // Cancel consultation - redirect to atxfer-cancel-consult which
    // terminates consulting call and Bridge()s agent back to customer
    $r = $this->_ami->Redirect($loginChannel, '', 's', 'atxfer-cancel-consult', 1);
    // Clear transfer DB record (transfer was cancelled)
} else {
    // Complete transfer path - hang up customer, redirect agent to atxfer-complete
    $this->_ami->Hangup($infoLlamada['actualchannel']);
    $this->_tuberia->AMIEventProcess_prepararAtxferComplete($sAgente);
    $r = $this->_ami->Redirect($loginChannel, '', $agentNumber, 'atxfer-complete', 1);
    $this->_tuberia->msg_AMIEventProcess_finalizarTransferencia($sAgente);
}
```

**Fix - Part 2: New Dialplan Context** (`extensions_custom.conf`, `installer.php`)

Added `atxfer-cancel-consult` context that cancels consultation and reconnects agent to the held customer:

```ini
[atxfer-cancel-consult]
exten => s,1,NoOp(Issabel CallCenter: Cancelling consultation - reconnecting agent to caller)
 same => n,UserEvent(ConsultationEnd,Agent: Agent/${ATXFER_AGENT_NUM})
 same => n,Bridge(${ATXFER_HELD_CHAN})
 same => n,GotoIf($["${ATXFER_ON_HOLD}" = "yes"]?holdwait)
 same => n,Goto(atxfer-complete,${ATXFER_AGENT_NUM},1)
 same => n(holdwait),Set(ATXFER_ON_HOLD=)
 same => n,UserEvent(AtxferHoldWait,Agent: Agent/${ATXFER_AGENT_NUM})
 same => n,Wait(300)
 same => n,Goto(atxfer-cancel-consult,s,1)
```

The `UserEvent(ConsultationEnd)` fires before `Bridge()`, which allows the dialer to detect the event and update internal state before the bridge is established.

**Fix - Part 3: New RPC `esAgenteEnConsultation`** (`AMIEventProcess.class.php`)

Added synchronous RPC so ECCPConn can determine the agent's consultation state at the time of the Hangup click:

```php
public function rpc_esAgenteEnConsultation($sFuente, $sDestino,
    $sNombreMensaje, $iTimestamp, $datos)
{
    list($sAgente) = $datos;
    $this->_tuberia->enviarRespuesta($sFuente,
        isset($this->_agentesEnConsultation[$sAgente]));
}
```

**Fix - Part 4: Auto-Terminate Consultation on Customer Hangup** (`AMIEventProcess.class.php`)

Added `_terminarConsultaSiClienteCuelga()` called from `_procesarLlamadaColgada` when the customer channel hangs up. If the agent is in active consultation, the agent is redirected to `atxfer-complete` which terminates the Dial() in `atxfer-consult` and re-enters AgentLogin:

```php
private function _terminarConsultaSiClienteCuelga($llamada)
{
    if (is_null($llamada->agente)) return;
    $sAgente = $llamada->agente->channel;
    if (!isset($this->_agentesEnConsultation[$sAgente])) return;
    if ($llamada->agente->type != 'Agent') return;

    $this->_prepararAtxferComplete($sAgente);
    $r = $this->_ami->Redirect($loginChannel, '', $agentNumber, 'atxfer-complete', 1);
    unset($this->_agentesEnConsultation[$sAgente]);
    $this->_tuberia->msg_ECCPProcess_emitirEventos(array(
        array('ConsultationEnd', array($sAgente))
    ));
}
```

**Fix - Part 5: `consultationend` Button State Guard** (`javascript.js`)

The `consultationend` handler now only re-enables buttons if the agent still has an active call (`estadoCliente.callid != null`). `callid` is used instead of `campaign_id` because incoming queue calls have `callid` set but `campaign_id == null`. Also re-enables the Hangup button since `do_hangup()` disables it when clicked:

```javascript
case 'consultationend':
    // Use callid (not campaign_id) because incoming queue calls
    // without a campaign have callid set but campaign_id == null.
    if (estadoCliente.callid != null) {
        $('#btn_hangup').button('enable');
        $('#btn_hold').button('enable');
        $('#btn_transfer').button('enable');
    }
    break;
```

**Event Flow - Cancel Consultation (Issue 1 fix)**:
1. Agent clicks Hangup during consultation
2. ECCPConn checks `esAgenteEnConsultation` → returns true
3. Redirects `login_channel` to `atxfer-cancel-consult`
4. Context fires `UserEvent(ConsultationEnd)` → dialer clears tracking
5. `Bridge(${ATXFER_HELD_CHAN})` retrieves customer from MusicOnHold and connects agent directly
6. Agent and customer resume their conversation

**Event Flow - Customer Hangup During Consultation (Issue 2 fix)**:
1. Customer hangs up while agent is consulting colleague
2. `_procesarLlamadaColgada` → calls `_terminarConsultaSiClienteCuelga`
3. Agent is redirected to `atxfer-complete` → terminates Dial() in `atxfer-consult`
4. `ConsultationEnd` event emitted → JS receives it but `callid` is null → buttons stay disabled
5. Agent session is cleanly finalized

**Impact**:
- Clicking Hangup during active consultation reconnects agent to customer instead of orphaning them
- Agent console distinguishes between consultation and post-consultation states
- Customer hangup during consultation auto-terminates the consulting call
- Hold and Transfer buttons do not incorrectly enable after customer has already hung up

---

### 18. Disable Attended Transfer Option for Agent Type in UI

**Files**:
- `modules/agent_console/index.php` (IS_AGENT_TYPE template variable)
- `modules/agent_console/themes/default/agent_console.tpl` (conditional rendering of radio buttons)

**Issue**: The Transfer dialog showed both "Blind transfer" and "Attended transfer" radio buttons for all agent types. For Agent type (app_agent_pool), attended transfer has a complex implementation with known edge cases. It should only be available for callback extension agents (SIP/IAX2/PJSIP).

**Fix - Part 1: Template Variable** (`index.php`)

Added `IS_AGENT_TYPE` boolean to the Smarty template assignment:

```php
'IS_AGENT_TYPE' => (strpos($_SESSION['callcenter']['agente'], 'Agent/') === 0),
```

Agent channel is stored as `Agent/XXXX` for app_agent_pool agents and `SIP/XXXX`, `IAX2/XXXX`, or `PJSIP/XXXX` for callback extension agents.

**Fix - Part 2: Conditional Radio Buttons** (`agent_console.tpl`)

Wrapped the transfer type radio button row in a Smarty `{if}` block:

```smarty
{if !$IS_AGENT_TYPE}
<tr>
    <td>
        <div align="center" id="transfer_type_radio">
            <input type="radio" id="transfer_type_blind" .../>
            <input type="radio" id="transfer_type_attended" .../>
        </div>
    </td>
</tr>
{/if}
```

When the radio buttons are absent, `$('#transfer_type_attended').is(':checked')` in `do_transfer()` returns `false`, so transfers always use blind transfer for Agent type. jQuery's `.buttonset()` on a missing element is a no-op, so no JS changes are needed.

**Impact**:
- Agent type agents only see the extension input when clicking Transfer (no radio buttons)
- Callback extension agents (SIP/IAX2/PJSIP) continue to see both blind and attended transfer options
- No backend changes needed; the frontend change is sufficient

---

---

### 19. Total Break Time Column in Agents Monitoring
**Files**:
- `modules/rep_agents_monitoring/index.php` (consultarTiempoBreakAgentes, construirDatosJSON, grid column, SSE event handler)
- `modules/rep_agents_monitoring/themes/default/js/javascript.js` (sec_breaks tracking, isbreakpause flag, cronómetro update)
- `modules/rep_agents_monitoring/lang/*.lang` (BREAK, READY, RINGING, CALL translations)
- `modules/agent_console/libs/paloSantoConsola.class.php` (pause_class field in agent state)

**Issue**: The Agents Monitoring report had no column to display total break time per agent. Only login time, call time, and call count were shown.

**Fix - Part 1: Database Query** (`index.php`)

Added `consultarTiempoBreakAgentes($datetimeStart, $datetimeEnd)` that queries the `audit` table for break sessions:

```php
// Queries audit WHERE id_break IS NOT NULL (pause/break sessions)
// AND tipo_break IN ('B') - only regular breaks, not Hold (tipo 'H')
// SUM(LEAST(datetime_end, :end) - GREATEST(datetime_init, :start))
// to clip session durations to the requested time window
```

Break tipo mapping: `'B'` = regular break (counted), `'H'` = Hold type (excluded from break time).

**Fix - Part 2: JSON Data** (`index.php`)

Added `sec_breaks` (total break seconds) and `isbreakpause` (bool: agent is currently on a break-type pause) to `construirDatosJSON()` output. `isbreakpause` is used by the frontend to determine whether to increment the live break timer.

**Fix - Part 3: Grid Column**

Added "Total break time" column to the monitoring grid, alongside the existing login time and call time columns.

**Fix - Part 4: SSE Event Handler**

Updated the `pausestart`/`pauseend` event handlers to track `isbreakpause` state so the real-time break timer starts/stops correctly when agent status changes.

**Fix - Part 5: Frontend Timer** (`javascript.js`)

Added `sec_breaks` to the cronómetro update loop. Timer only increments when `estadoCliente[k]['isbreakpause']` is true (agent is on a regular break, not on hold).

**Impact**:
- Supervisors can see total accumulated break time per agent in real-time
- Break timer increments live when agent is on break and pauses when on call or hold
- Queue totals row shows summed break time across all agents in the queue

---

### 20. Shift-Based Filtering for Agents Monitoring Stats
**Files**:
- `modules/rep_agents_monitoring/index.php` (calculateShiftDatetimeRange, consultarTiempoLoginAgentes, consultarLlamadasAgentes, shift param parsing, shift filter HTML)
- `modules/rep_agents_monitoring/themes/default/js/javascript.js` (localStorage, shift UI, SSE params)
- `modules/rep_agents_monitoring/lang/en.lang` (Shift From, Shift To, Apply)

**Issue**: All statistics in Agents Monitoring (break time, login time, talk time, call count) used a hardcoded full-day range (00:00:00–23:59:59 today). There was no way to filter stats to a specific work shift.

**Fix - Part 1: Shift Range Calculation** (`index.php`)

Added `calculateShiftDatetimeRange($fromHour, $toHour)`:
- Same-day shift (e.g., 08–17): `today HH:00:00` to `today HH:59:59`
- Overnight shift (e.g., 22–06): `yesterday HH:00:00` to `today HH:59:59`

**Fix - Part 2: Shift-Filtered Login Time Query** (`index.php`)

Added `consultarTiempoLoginAgentes($datetimeStart, $datetimeEnd)` querying the `audit` table (`id_break IS NULL` = login sessions). Uses `GREATEST`/`LEAST` to clip sessions that overlap shift boundaries:

```sql
SUM(
    UNIX_TIMESTAMP(LEAST(COALESCE(audit.datetime_end, :active_end), :end))
    - UNIX_TIMESTAMP(GREATEST(audit.datetime_init, :start))
) AS logintime
```

`$sActiveEnd` is calculated in PHP (`date('Y-m-d H:i:s')` capped at shift end) instead of using SQL `NOW()`, so active sessions are counted up to the current moment but not beyond the shift end.

**Fix - Part 3: Shift-Filtered Call Stats Query** (`index.php`)

Added `consultarLlamadasAgentes($datetimeStart, $datetimeEnd)` querying both:
- `call_entry` table (incoming calls: `datetime_init` within shift, `duration IS NOT NULL`)
- `calls` table (outgoing calls: `start_time` within shift, `duration IS NOT NULL`)

Results are merged per `agentchannel` in PHP.

**Fix - Part 4: Shift Filter UI** (`index.php`, `javascript.js`, `en.lang`)

Added shift filter bar above the monitoring grid with:
- From/To hour dropdowns (00–23)
- Apply button that reloads the page with `shift_from`/`shift_to` URL params
- Range indicator label (e.g., "Today 08:00 – 17:59" or "Yesterday 22:00 – Today 06:59")
- Preferences saved to `localStorage` and restored on page load

SSE/checkStatus requests include `shift_from` and `shift_to` parameters so break time DB re-polls use the same shift range throughout the session.

**Default**: 00–23 (full day, behavior identical to previous hardcoded range).

**Impact**:
- Supervisors can filter all stats to a specific work shift
- Overnight shifts spanning midnight are supported
- Shift preference persists across page reloads via localStorage

---

### 21. On-Hold Status Display in Agents Monitoring
**Files**:
- `modules/agent_console/libs/paloSantoConsola.class.php` (onhold field from ECCP XML)
- `modules/rep_agents_monitoring/index.php` (onhold in JSON, HTML rendering, state change detection)
- `modules/rep_agents_monitoring/themes/default/js/javascript.js` (onhold change detection, HOLD label)
- `modules/rep_agents_monitoring/lang/en.lang` (HOLD translation)

**Issue**: When an agent put a customer on hold, the Agents Monitoring panel continued to show the call icon without any indication that the call was on hold.

**Fix - Part 1: ECCP Data Capture** (`paloSantoConsola.class.php`)

Added `onhold` field to the agent info array in `listarEstadoMonitoreoAgentes()`:

```php
'onhold' => isset($estadoAgente->onhold) ? (bool)(int)$estadoAgente->onhold : false,
```

The ECCP protocol already sends `<onhold>1</onhold>` in agent status XML. This field was previously ignored.

**Fix - Part 2: State Propagation** (`index.php`)

- Added `'onhold' => $infoAgente['onhold']` to `construirDatosJSON()` JSON output
- HTML status rendering: when `onhold` is true, append `<span>HOLD</span>` after the call/break icon for both `oncall` and `paused` states
- Added `onhold` to server-side `$estadoCliente` tracking so state changes are detected
- All 3 re-poll change detection blocks check `onhold` alongside `status` and `oncallupdate`

**Fix - Part 3: Frontend Update** (`javascript.js`)

Updated `manejarRespuestaStatus()` change detection to include `onhold`:

```javascript
if (estadoCliente[k]['status'] != respuesta[k]['status'] ||
        estadoCliente[k]['onhold'] != respuesta[k]['onhold']) {
    // Rebuild status label
    case 'oncall':
        // ... call icon ...
        if (respuesta[k]['onhold']) statuslabel.append($('<span></span>').text('HOLD'));
        break;
    case 'paused':
        // ... break icon ...
        if (respuesta[k]['onhold']) statuslabel.append($('<span></span>').text('HOLD'));
        else if (typeof respuesta[k].pausename == 'string') statuslabel.append(...);
        break;
}
```

**Impact**:
- Agents Monitoring shows call icon + "HOLD" text when agent puts customer on hold
- Hold state is reflected in real-time via SSE without page reload
- Both `oncall` and `paused` states support on-hold indication

---

### 22. PHP 5.4 Compatibility Fix (Null Coalescing Operator)
**Files**:
- `setup/dialer_process/dialer/AMIEventProcess.class.php` (line ~1479)
- `setup/dialer_process/dialer/Llamada.class.php` (line ~1304)

**Issue**: Two uses of the null coalescing operator `??` (introduced in PHP 7.0) were found in dialer debug log lines, making the code incompatible with PHP 5.4 installations.

**Fix**: Replaced both occurrences with `isset()` ternary expressions:

```php
// BEFORE (PHP 7.0+ only):
" park_exten=" . ($a->llamada->park_exten ?? 'NULL')
" park_exten=" . ($this->_park_exten ?? 'NULL')

// AFTER (PHP 5.4 compatible):
" park_exten=" . (isset($a->llamada->park_exten) ? $a->llamada->park_exten : 'NULL')
" park_exten=" . (isset($this->_park_exten) ? $this->_park_exten : 'NULL')
```

Both occurrences were inside debug log string concatenation for park extension logging in the hold feature.

**Impact**: Dialer daemon now runs on PHP 5.4 installations without syntax errors in hold-related code paths.

---

## Version History

- **4.0.0.22** - PHP 5.4 compatibility fix (replace ?? with isset ternary)
- **4.0.0.21** - On-hold status display in Agents Monitoring
- **4.0.0.20** - Shift-based filtering for Agents Monitoring stats (break, login, talk time, call count)
- **4.0.0.19** - Total Break Time column in Agents Monitoring
- **4.0.0.18** - Disable attended transfer UI option for Agent type agents
- **4.0.0.17** - Attended transfer consultation hangup fix and customer hangup during consultation fix
- **4.0.0.16** - Agent type hold recovery fix for post-attended-transfer calls
- **4.0.0.15** - Fix stale channel after hold breaking attended transfer/hangup, fix ConsultationEnd not detected after hold
- **4.0.0.13** - Hold/Transfer button state management during attended transfer consultation
- **4.0.0.12** - Attended transfer busy tone delay fix for callback agents
- **4.0.0.11** - Attended transfer fix for incoming campaigns (DTMF hook loss after Local channel optimization)
- **4.0.0.9** - Real-time agent ringing status in Agents Monitoring module
- **4.0.0.8** - Attended transfer fix for Agent type (app_agent_pool), callback agent hangup fix
- **4.0.0.7** - Bug fixes for campaign monitoring display, agent console hangup, statistics sync, and login cancellation
- **4.0.0.6** - app_agent_pool migration (PHP 8 compatibility, agent password login)
