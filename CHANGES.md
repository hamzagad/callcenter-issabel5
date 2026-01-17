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

## Version History

- **4.0.0.7** - Bug fixes for campaign monitoring display, agent console hangup, statistics sync, and login cancellation
- **4.0.0.6** - app_agent_pool migration (PHP 8 compatibility, agent password login)
