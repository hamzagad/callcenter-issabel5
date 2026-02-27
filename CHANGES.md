# Issabel Call Center - Change Log

---

## 27. Shift-Based Counters in Agent Console
**Date**: 2026-02-27

**Files**:
- `modules/agent_console/index.php`
- `modules/agent_console/themes/default/agent_console.tpl`
- `modules/agent_console/themes/default/css/issabel-callcenter.css`
- `modules/agent_console/themes/default/js/javascript.js`

**Feature**: Added real-time shift-based counters in the agent console that display cumulative time tracking for the current shift:

- **Green**: Total Login Time - Shows how long the agent has been logged in during the current shift
- **Red**: Total Break Time - Shows cumulative break/pause time during the current shift
- **Orange**: Total Hold Time - Shows cumulative hold time across all calls during the current shift

**Implementation Details**:
- Counters are calculated from the agent's shift start time
- Data is fetched via SSE (Server-Sent Events) for real-time updates
- Visual indicators use color coding for quick status recognition
- Timers update in real-time using JavaScript intervals

**Technical Changes**:
- Added `getShiftCounters()` method in index.php to calculate shift-based metrics
- Added SSE endpoint for streaming counter updates
- Added CSS styles for counter display with color-coded backgrounds
- Added JavaScript timer logic for real-time counter updates

---

## 26. Total Hold Time Column in Agents Monitoring
**Date**: 2026-02-27

**Files**:
- `modules/rep_agents_monitoring/index.php`
- `modules/rep_agents_monitoring/themes/default/js/javascript.js`
- `modules/rep_agents_monitoring/lang/*.lang`

**Feature**: Added new column "Total hold time" in the Agents Monitoring report to display accumulated hold time per agent during the current shift.

---

## 25. Outgoing Campaigns Module Enhancements
**Date**: 2026-02-27

**Files**:
- `modules/campaign_out/index.php`
- `modules/campaign_out/libs/paloSantoCampaignCC.class.php`

**Feature**: Added two new columns in the outgoing campaigns module for better campaign visibility and management.

---

## 24. Fair Agent Distribution Over Campaigns
**Date**: 2026-02-26

**File**: `setup/dialer_process/dialer/CampaignProcess.class.php`

**Issue**: When multiple campaigns share the same agents (same queue), the first campaign to process always claims all shared agents, leaving nothing for other campaigns. This creates unfair distribution where Campaign B and C never get to use the agents.

**Example of the problem**:
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

**Fix**: Implemented two-pass processing with N-way rotation:

1. **Pass 1**: Collect which campaigns want which agents (intentions)
2. **Allocate**: For shared agents, use rotation index to determine whose turn
3. **Pass 2**: Process campaigns with their allocated agents
4. **Advance**: Increment rotation index for next cycle

**New behavior**:
```
Cycle 1: Campaign A gets agents → 3 calls
Cycle 2: Campaign B gets agents → 3 calls  (ROTATED!)
Cycle 3: Campaign C gets agents → 3 calls  (ROTATED!)
Cycle 4: Campaign A gets agents → 3 calls  (back to A)

Pattern: A → B → C → A → B → C → ...
```

**Technical Details**:
- New properties added: `$_agentRotation`, `$_campaignIntentions`, `$_allocatedAgents`
- New methods: `_resolveAgentRotation()`, `_getRotationWinner()`, `_processCampaignWithAllocation()`
- Rotation state persists across cycles but resets if campaign set changes
- Each agent rotates independently based on how many campaigns share it
- Unique agents (only wanted by one campaign) are assigned directly without rotation

**Debug logs** (when dialer_debug enabled):
```bash
tail -f /opt/issabel/dialer/dialerd.log | grep -E "rotation|allocated|winner|Pass 1"
grep -E "assigned to campaign.*rotation" /opt/issabel/dialer/dialerd.log | tail -30
```

---

## 23. Agent Conflict Detection
**Date**: 2026-02-26

**File**: `setup/dialer_process/dialer/CampaignProcess.class.php`

**Issue**: When multiple campaigns use the same queue (and thus share the same agents), the dialer could over-place calls. Each campaign independently saw all free agents and tried to place calls for all of them, resulting in more calls than agents available.

**Example of the problem**:
```
Queue Q has 3 free agents: [1001, 1002, 1003]
Campaign A uses Q → sees 3 free agents → places 3 calls
Campaign B uses Q → sees 3 free agents → places 3 calls
Campaign C uses Q → sees 3 free agents → places 3 calls

Result: 9 calls placed but only 3 agents available!
```

**Fix**: Added agent conflict detection that tracks which agents have been claimed by campaigns during each review cycle:
- New property `$_agentesReclamados` tracks claimed agents per cycle
- When a campaign processes, it checks which agents are already claimed
- Only unclaimed agents are counted as available
- Claimed agents are subtracted from the prediction count

**Technical Details**:
- Agent interfaces are normalized (e.g., `Local/1001@agents` → `Agent/1001`) for consistent tracking
- The claimed agents map is reset at the start of each campaign review cycle
- Debug logging shows which agents are claimed and by which campaign

**Note**: This feature was the foundation that led to the Fair Rotation feature above. With conflict detection alone, the first campaign still gets all agents. Fair Rotation ensures equitable distribution.

---

## 22. Fix Outgoing Campaigns Panel Date Filtering
**Date**: 2026-02-25

**File**: `modules/agent_console/libs/paloSantoConsola.class.php`

**Issue**: The Outgoing Campaigns Panel (`rep_outgoing_campaigns_panel`) displayed incorrect call counts (e.g., "Total calls: 0" or significantly lower numbers) compared to the Calls Detail report. The panel was filtering calls using `datetime_entry_queue`, which is only populated when a call actually enters the queue (i.e., the remote party answered and was bridged to an agent). Calls with outcomes like `NoAnswer` or `Failure` have `datetime_entry_queue = NULL` and were excluded from all counts.

**Fix**: Changed the date filter column from `datetime_entry_queue` to `fecha_llamada` in both SQL queries within `getOutgoingCallStatsByDatetimeRange()`. This matches how the Calls Detail report (`calls_detail`) filters outgoing calls and ensures all call records are included in the panel statistics regardless of whether they reached the queue.

**Technical Details**:
- The `fecha_llamada` column is set when the call record is created/scheduled, so it is always populated
- The `datetime_entry_queue` column is only set when a call enters the queue after the remote party answers
- Two queries were updated: the main status count query and the queued calls count query
- The status mapping logic (Success, Abandoned, NoAnswer, Failure, etc.) remains unchanged

---

## 21. New Configuration: Dump Related Asterisk Events
**Date**: 2026-02-25

**Files**:
- `modules/callcenter_config/index.php`
- `modules/callcenter_config/libs/paloSantoConfiguration.class.php`
- `modules/callcenter_config/lang/en.lang`
- `modules/callcenter_config/themes/default/form.tpl`
- `setup/dialer_process/dialer/ConfigDB.class.php`
- `setup/dialer_process/dialer/AMIEventProcess.class.php`
- `setup/dialer_process/dialer/SQLWorkerProcess.class.php`

**Issue**: When dialer debug is enabled, VarSet AMI events flood the log (623+ entries), making it difficult to find relevant debug information.

**Fix**: Added new configuration option "Dump related Asterisk events" to control VarSet event logging:
- When **disabled** (default): VarSet events are processed for MIXMONITOR_FILENAME tracking but not logged
- When **enabled**: All VarSet events are logged to `/opt/issabel/dialer/dialerd.log`

**Technical Details**:
- Config key: `dialer.relatedevents` stored in `valor_config` table
- Default value: `0` (disabled)
- Follows same pattern as existing `dialer.allevents` option
- VarSet processing for MIXMONITOR_FILENAME continues regardless of this setting

**Usage**: Call Center > Configuration > check/uncheck "Dump related Asterisk events"

---

## 20. On-Hold Status Display in Agents Monitoring
**Date**: 2026-02-20

**Files**:
- `modules/agent_console/libs/paloSantoConsola.class.php`
- `modules/rep_agents_monitoring/index.php`
- `modules/rep_agents_monitoring/themes/default/js/javascript.js`
- `modules/rep_agents_monitoring/lang/en.lang`

**Issue**: When an agent put a customer on hold, the Agents Monitoring panel continued to show the call icon without any indication that the call was on hold.

**Fix**: Added `onhold` field to the agent info array. When `onhold` is true, append "HOLD" label after the call/break icon for both `oncall` and `paused` states.

---

## 19. Shift-Based Filtering for Agents Monitoring Stats
**Date**: 2026-02-20

**Files**:
- `modules/rep_agents_monitoring/index.php`
- `modules/rep_agents_monitoring/themes/default/js/javascript.js`
- `modules/rep_agents_monitoring/lang/en.lang`

**Issue**: All statistics in Agents Monitoring (break time, login time, talk time, call count) used a hardcoded full-day range (00:00:00–23:59:59 today). There was no way to filter stats to a specific work shift.

**Fix**: Added shift filter UI with From/To hour dropdowns. Supports overnight shifts spanning midnight. Preferences saved to localStorage.

---

## 18. Total Break Time Column in Agents Monitoring
**Date**: 2026-02-20

**Files**:
- `modules/rep_agents_monitoring/index.php`
- `modules/rep_agents_monitoring/themes/default/js/javascript.js`
- `modules/rep_agents_monitoring/lang/*.lang`
- `modules/agent_console/libs/paloSantoConsola.class.php`

**Issue**: The Agents Monitoring report had no column to display total break time per agent.

**Fix**: Added `consultarTiempoBreakAgentes()` that queries the `audit` table for break sessions. Added "Total break time" column to the monitoring grid with real-time timer updates.

---

## 17. PHP 5.4 Compatibility Fix
**Date**: 2026-02-20

**Files**:
- `setup/dialer_process/dialer/AMIEventProcess.class.php`
- `setup/dialer_process/dialer/Llamada.class.php`

**Issue**: Two uses of the null coalescing operator `??` (introduced in PHP 7.0) made the code incompatible with PHP 5.4.

**Fix**: Replaced with `isset()` ternary expressions.

---

## 16. Multiple Fixes and Attended Transfer Disabled for Agent Type
**Date**: 2026-02-17

**Files**:
- `modules/agent_console/index.php`
- `modules/agent_console/themes/default/agent_console.tpl`

**Issue**: The Transfer dialog showed both "Blind transfer" and "Attended transfer" radio buttons for all agent types. For Agent type (app_agent_pool), attended transfer has known edge cases.

**Fix**: Added `IS_AGENT_TYPE` boolean to conditionally hide attended transfer option for Agent type agents.

---

## 15. End Hold Delay Fix After Attended Transfer
**Date**: 2026-02-17

**Files**:
- `setup/dialer_process/dialer/ECCPConn.class.php`
- `setup/dialer_process/dialer/AMIEventProcess.class.php`
- `setup/dialer_process/dialer/Llamada.class.php`
- `/etc/asterisk/extensions_custom.conf`

**Issue**: After an Agent type attended transfer consultation failed, pressing Hold then End Hold caused ~5 second delay.

**Fix**: Keep the agent in `Wait()` instead of `AgentLogin` after `Bridge()` ends during hold. Use `Redirect` + `Bridge()` to retrieve the parked call directly.

---

## 14. Attended Transfer Status Handling Improvements
**Date**: 2026-02-15

**Files**:
- `setup/dialer_process/dialer/AMIEventProcess.class.php`
- `setup/dialer_process/dialer/ECCPConn.class.php`
- `setup/dialer_process/dialer/ECCPProxyConn.class.php`
- `modules/agent_console/themes/default/js/javascript.js`

**Feature**: Added consultation state tracking and button state management during attended transfer. Hold and Transfer buttons are disabled during consultation and re-enabled when consultation ends.

---

## 13. New Custom Context for Callback Extension Attended Transfer
**Date**: 2026-02-15

**Files**:
- `/etc/asterisk/extensions_custom.conf`
- `setup/installer.php`

**Feature**: Added `cbext-atxfer` context that dials device directly to avoid busy tone delay when callback agent's attended transfer target declines.

---

## 12. Agent Hold Feature Bug Fixes
**Date**: 2026-02-15

**Files**:
- `setup/dialer_process/dialer/AMIEventProcess.class.php`
- `setup/dialer_process/dialer/AMIClientConn.class.php`
- `setup/dialer_process/dialer/Llamada.class.php`
- `setup/dialer_process/dialer/ECCPConn.class.php`

**Issue 1**: Agent stuck in hold state when customer hangs up
**Issue 2**: Customer hears parking slot number on subsequent holds
**Issue 3**: Anonymous CallerID when retrieving call from hold

**Fix**: Updated AMI field names for Asterisk 13+ compatibility, suppressed parking slot announcement, added CallerID to originate call.

---

## 11. Attended Transfer Fix for Agent Type (app_agent_pool)
**Date**: 2026-02-06

**Files**:
- `setup/dialer_process/dialer/ECCPConn.class.php`
- `setup/dialer_process/dialer/AMIEventProcess.class.php`
- `setup/dialer_process/dialer/Llamada.class.php`
- `setup/dialer_process/dialer/AMIClientConn.class.php`
- `/etc/asterisk/extensions_custom.conf`
- `setup/installer.php`

**Issue**: Attended transfer did not work for Agent type agents (app_agent_pool):
1. Transfer initiation failed because AMI Atxfer was called on `Agent/XXXX` (not a real channel)
2. Transfer completion failed because hanging up terminated the AgentLogin session

**Fix**: For Agent type, use `login_channel` for AMI commands. Added `atxfer-complete` context that re-enters AgentLogin after transfer completion.

---

## 10. Fix End Hold for Incoming Campaign
**Date**: 2026-02-06

**File**: `setup/dialer_process/dialer/ECCPConn.class.php`

**Fix**: Corrected logic for incoming campaign hold recovery that was incorrectly treating the operation as an internal transfer.

---

## 9. Fix Agent Break Status Update for Asterisk 18
**Date**: 2026-02-06

**File**: `setup/dialer_process/dialer/AMIEventProcess.class.php`

**Fix**: Updated break status handling to work correctly with Asterisk 18 AMI event format changes.

---

## 8. Real-Time Agent Ringing Status in Agents Monitoring
**Date**: Earlier

**Files**:
- `setup/dialer_process/dialer/ECCPProxyConn.class.php`
- `setup/dialer_process/dialer/Agente.class.php`
- `setup/dialer_process/dialer/SQLWorkerProcess.class.php`
- `modules/agent_console/libs/paloSantoConsola.class.php`
- `modules/rep_agents_monitoring/index.php`
- `modules/rep_agents_monitoring/themes/default/js/javascript.js`
- `modules/rep_agents_monitoring/images/agent-ringing.gif`
- `modules/rep_agents_monitoring/lang/*.lang`

**Issue**: Agents Monitoring module only showed "Ready" when agent's phone was ringing.

**Fix**: Added new ECCP event `AgentStateChange` emitted when agent status changes to/from ringing. Frontend updates in real-time via SSE.

---

## 7. Agent Ringing Status Display Fix
**Date**: Earlier

**Files**:
- `setup/dialer_process/dialer/Agente.class.php`
- `setup/dialer_process/dialer/ECCPConn.class.php`
- `modules/campaign_monitoring/lang/en.lang`
- `modules/campaign_monitoring/lang/es.lang`
- `modules/campaign_monitoring/themes/default/js/javascript.js`

**Issue**: In campaign monitoring, callback extension agents showed status "Free" while their phone was ringing.

**Fix**: Added `queue_status` field to agent info. Modified ECCP to send `'ringing'` status when `queue_status==6`. Removed incorrect frontend status inference logic.

---

## 6. Agent Console Duplicate Name Display Fix
**Date**: Earlier

**Files**:
- `modules/agent_console/index.php`
- `modules/agent_console/lang/en.lang`

**Issue**: In agent console Information section for outgoing calls, the second CSV column appeared twice.

**Fix**: Changed label from "Names" to "Name" (singular). Skip index 1 in dynamic attribute loop for outgoing calls.

---

## 5. Campaign Statistics Sync Fix
**Date**: Earlier

**File**: `modules/campaign_out/libs/paloSantoCampaignCC.class.php`

**Issue**: `campaign_out` showed stale `num_completadas` after dialer restart mid-call

**Fix**: Query `calls` table directly instead of using cached `campaign.num_completadas`

---

## 4. Agent Console Stuck After Hangup Fix
**Date**: Earlier

**File**: `setup/dialer_process/dialer/AMIEventProcess.class.php`

**Issue**: For local extension calls, pressing Hangup terminated client but agent stayed "Connected to call"

**Fix**: Added fallback search by `actualchannel` in msg_Hangup handler.

---

## 3. Agent Login Cancellation Fix
**Date**: Earlier

**File**: `setup/dialer_process/dialer/Agente.class.php`

**Issue**: Cancelling agent login left agent in inconsistent state

**Fix**: Properly handle login channel hangup during login process.

---

## 2. Call Status Initialization Bug Fix
**Date**: Earlier

**File**: `setup/dialer_process/dialer/Llamada.class.php`

**Issue**: Phone number not appearing in "Placing calls" section during customer ringing in campaign monitoring

**Fix**: Changed `if (!is_null($this->status))` to `if (is_null($this->status))`

---

## 1. Agent Queue Status Bug Fix
**Date**: Earlier

**File**: `setup/dialer_process/dialer/Agente.class.php`

**Issue**: `estadoEnCola()` function had backwards ternary operator logic

**Fix**: Reversed ternary operator to return actual status when queue exists.

---

## Technical Reference

### Call Status Flow (Outgoing Campaigns)
1. Call is created → Status is NULL
2. Originate starts → Status set to 'Placing' (if NULL)
3. Customer phone rings → Status remains 'Placing'
4. Customer answers → Call enters queue → Status set to 'OnQueue'
5. Agent assigned and answers → Status set to 'Success'

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
