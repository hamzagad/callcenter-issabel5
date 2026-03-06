# Issabel Call Center - Change Log

---

## 38. Integrate Predictive Dialer into Fair-Rotation Path
**Date**: 2026-03-06

**File**: `setup/dialer_process/dialer/CampaignProcess.class.php`

**Issue**: The fair-rotation path (`_processCampaignWithAllocation`) ignored the `dialer_predictivo` configuration setting. It placed exactly 1 call per allocated free agent, never anticipating busy agents about to finish their calls. The Erlang-based predictive logic only existed in the dead legacy method (`_actualizarLlamadasCampania`), so enabling "Predictive Dialer Behavior" in callcenter_config had no effect on call placement.

**Example of the problem**:
```
Queue has 3 free agents + 2 busy agents about to finish calls
Campaign allocated 3 agents via fair rotation

Without predictive (before fix):
  Calls placed = 3 (only free agents)
  2 agents finish calls → idle until next cycle

With predictive (after fix):
  Calls placed = 3 + 2 = 5 (free + predicted)
  2 agents finish calls → calls already waiting for them
```

**Fix**: Added predictive dialer boost in `_processCampaignWithAllocation` (Pass 2), applied after agent allocation and before the max_canales cap:

1. Gets fresh queue prediction data via `infoPrediccionCola()`
2. Runs `predecirNumeroLlamadas()` with Erlang probability if campaign has enough samples (`num_completadas >= 10`)
3. Calculates boost: `AGENTES_POR_DESOCUPAR - CLIENTES_ESPERA - already_claimed`
4. Adds boost to `iNumLlamadasColocar` before max_canales cap

**Shared-queue double-counting prevention**: New property `$_predictiveSlotsUsed` tracks how many predictive slots each queue has given out per cycle. When Campaign A claims 2 predictive slots from a shared queue, Campaign B sees 2 fewer available.

**Order of operations**:
```
1. iNumLlamadasColocar = numAllocatedAgents        (fair rotation)
2. + predictive boost                               (NEW)
3. Cap by max_canales                               (trunk limit)
4. Subtract pending OriginateResponse               (avoid over-placing)
5. Overcommit (ASR-based)                           (compensate failures)
6. Re-cap by max_canales                            (trunk hard limit)
```

**Activation conditions**:
- `dialer.predictivo = 1` (enabled in callcenter_config)
- Campaign has at least 1 allocated agent this cycle
- Erlang prediction requires `num_completadas >= 10` completed calls; otherwise falls back to basic agent counting

**Debug logs** (when dialer_debug enabled):
```bash
# Watch predictive boost events
grep -E "predictive boost|impulso predictivo" /opt/issabel/dialer/dialerd.log | tail -30

# Compare allocated agents vs total calls placed
grep -E "allocated agents|FINAL iNumLlamadasColocar" /opt/issabel/dialer/dialerd.log | tail -30

# Verify shared-queue double-counting prevention
grep -E "already_claimed|ya_reclamados" /opt/issabel/dialer/dialerd.log | tail -20
```

---

## 37. Cap Overcommit by max_canales (Trunk Capacity Limit)
**Date**: 2026-03-06

**File**: `setup/dialer_process/dialer/CampaignProcess.class.php`

**Issue**: When "Enable Overcommit of Outgoing Calls" is enabled, the overcommit logic inflates the number of calls to place based on the ASR (Answer Seizure Ratio), but does not re-check the campaign's `max_canales` setting afterward. Since `max_canales` represents the physical trunk capacity, overcommit can push calls beyond what the trunk can handle, causing trunk saturation, high call failure rates, and unnecessary data/traffic consumption.

**Example of the problem**:
```
7 agents logged in
Trunk capacity: max_canales = 8
ASR = 50%

Before fix:
  Calls to place = 7
  Overcommit: 7 / 0.5 = 14 calls placed
  Trunk can only handle 8 → 6 calls fail due to trunk saturation
  Failed calls lower ASR further → vicious cycle

After fix:
  Calls to place = 7
  Overcommit: 7 / 0.5 = 14
  Re-cap: min(14, 8) = 8 calls placed
  Trunk handles all 8 → ~4 succeed, within trunk limits
```

**Fix**: Added a max_canales re-cap after the overcommit inflation in both code paths:
- **Fair-rotation path** (`_processCampaignWithAllocation`): re-caps after overcommit at ~line 1007
- **Legacy path** (`_revisarLlamadasCampania`): re-caps after overcommit at ~line 1435

The re-cap uses the raw `max_canales` value (not `effectiveMaxCanales`) since it represents the absolute trunk hardware limit. Overcommit still provides benefit up to that ceiling.

---

## 36. Conditional RINGING-as-Free Based on Predictive Dialer Config
**Date**: 2026-03-06

**Files**:
- `setup/dialer_process/dialer/QueueShadow.class.php`
- `setup/dialer_process/dialer/Predictor.class.php`
- `setup/dialer_process/dialer/AMIEventProcess.class.php`
- `setup/dialer_process/dialer/CampaignProcess.class.php`

**Issue**: When a call is transferred to a callback agent (SIP/PJSIP), the phone rings for several seconds until the agent answers. During this time, `AST_DEVICE_RINGING` was counted as "free" in the campaign prediction logic (`infoPrediccionCola`), so campaigns could originate calls for an agent who was actually handling a transferred call — wasting calls and causing abandoned calls.

**Fix**: Made the RINGING-as-free behavior conditional on the `dialer_predictivo` config flag:
- **Predictive ON** (`dialer.predictivo = 1`): counts both `AST_DEVICE_NOT_INUSE` and `AST_DEVICE_RINGING` as free (current behavior preserved)
- **Predictive OFF** (`dialer.predictivo = 0`): counts only `AST_DEVICE_NOT_INUSE` as free (safer for transfers)

**Technical Details**:
- Added `$predictive` parameter (default `true`) to `infoPrediccionCola()` in both `QueueShadow` and `Predictor`
- `CampaignProcess` passes `$this->_configDB->dialer_predictivo` at all 4 call sites
- `AMIEventProcess.rpc_infoPrediccionCola()` unpacks and forwards the flag via the RPC mechanism
- Added TODO comments noting that RINGING-as-free should be deeply analyzed even for predictive mode
- Log output includes `predictive=YES/NO` for debugging

**Verification**:
```bash
grep -E "AGENT_FREE|predictive" /opt/issabel/dialer/dialerd.log | tail -30
```

---

## 35. Check Extension Registration Before Callback Login
**Date**: 2026-03-06

**Files**:
- `setup/dialer_process/dialer/ECCPConn.class.php`
- `modules/agent_console/libs/ECCP.class.php`
- `modules/agent_console/libs/paloSantoConsola.class.php`
- `modules/agent_console/index.php`
- `modules/agent_console/lang/en.lang`
- `modules/agent_console/lang/es.lang`
- `setup/dialer_process/dialer/eccp-examples/getextensionstatus.php` (new)

**Issue**: A callback extension type agent (SIP/PJSIP/IAX2) could log in even if their extension was NOT registered in Asterisk. This allowed login attempts from non-existent or offline extensions.

**Fix**: Added ECCP request `getextensionstatus` to check extension registration:
1. New ECCP server request: `Request_eccpauth_getextensionstatus` in ECCPConn.class.php
2. New ECCP client method: `ECCP::getextensionstatus($extension)` in ECCP.class.php
3. New agent console method: `PaloSantoConsola::extensionEstaRegistrada($sAgentFormat)`
4. Validation in `manejarLogin_doLogin()` blocks login with error "Extension is not registered" / "La extensión no está registrada"
5. ECCP example file created: `eccp-examples/getextensionstatus.php`

**Technical Details**:
- Extension registration check uses the dialer's existing AMI connection via ECCP
- For SIP: checks `sip show peer <extension>` for Status OK/Registered
- For PJSIP: checks `pjsip show endpoint <extension>` for State/Status Reachable
- For IAX2: checks `iax2 show peer <extension>` for Status OK/Registered
- Extension must be registered (not just configured) to allow callback login
- Validation occurs BEFORE checking if extension is used by Agent type session

**ECCP Request**:
```xml
<request>
    <getextensionstatus>
        <extension>SIP/101</extension>
    </getextensionstatus>
</request>
```

**ECCP Response**:
```xml
<response>
    <getextensionstatus_response>
        <extension>SIP/101</extension>
        <registered>yes</registered>
    </getextensionstatus_response>
</response>
```

---

## 34. Prevent Callback Extension Login if Extension Used by Agent Type Session
**Date**: 2026-03-06

**Files**:
- `modules/agent_console/libs/paloSantoConsola.class.php`
- `modules/agent_console/index.php`
- `modules/agent_console/lang/en.lang`
- `modules/agent_console/lang/es.lang`

**Issue**: A callback extension type agent (SIP/PJSIP/IAX2) could log in using an extension number that was already actively being used by an Agent type login session, causing conflicts.

**Example**:
- Agent/1001 logs in with extension SIP/101
- SIP/101 (callback type) can then also log in with extension SIP/101
- Result: Two sessions using the same physical extension

**Fix**: Added validation check in `manejarLogin_doLogin()` that:
1. Detects when callback login is attempted
2. Extracts the extension number from the callback format (e.g., SIP/101 → 101)
3. Queries the audit table to check if an Agent type agent is actively logged in with that extension
4. Blocks login with error "Extension is already in use by another agent" / "La extensión ya está siendo usada por otro agente"

**Technical Details**:
- New method: `PaloSantoConsola::extensionUsadaPorAgente($sExtensionNum)`
- Query checks: `audit.datetime_end IS NULL` (active session) + `agent.type = 'Agent'` + `login_extension LIKE %extension_number%`
- Agent type login is NOT affected - only blocks callback types when extension conflicts

---

## 33. Agent-to-Agent Transfer Feature
**Date**: 2026-03-06

**Files**:
- `setup/dialer_process/dialer/ECCPConn.class.php`
- `modules/agent_console/libs/ECCP.class.php`
- `modules/agent_console/libs/paloSantoConsola.class.php`
- `modules/agent_console/index.php`
- `modules/agent_console/themes/default/js/javascript.js`
- `modules/agent_console/themes/default/agent_console.tpl`
- `modules/agent_console/lang/en.lang`
- `modules/agent_console/lang/es.lang`
- `setup/dialer_process/dialer/ECCP_Protocol.md`
- `setup/dialer_process/dialer/eccp_examples/transfercallagent.eccp` (new)

**Feature**: Added "Transfer to Agent" functionality to the agent console, allowing agents to transfer their active calls to another logged-in agent. This is the third transfer type, alongside existing "Blind transfer" (to extension) and "Attended transfer" (to extension).

**New Capabilities**:
- Agents can transfer calls to other logged-in agents with availability verification
- Target agent must be online (logged in), not on a call, and not on pause
- Transfer is executed as a blind transfer (no consultation phase)
- Agent dropdown shows "Agent/9000 - Agent Name" format for easy selection
- Current agent is excluded from the dropdown to prevent self-transfer

**UI Changes**:
- Transfer dialog now has 3 radio buttons: "Blind transfer", "Attended transfer", "Transfer to agent"
- When "Transfer to agent" is selected, an agent dropdown appears (extension input is hidden)
- When other transfer types are selected, the extension input appears (agent dropdown is hidden)

**Backend Implementation**:
- New ECCP command: `transfercallagent` (requires agent authentication)
- Target agent status validation: checks online, oncall, and paused states
- For Agent type: uses `[agents]` context with `AgentRequest()` application
- For callback types (SIP/PJSIP/IAX2): uses `from-internal` context
- Transfer is registered in database with target agent number

**Error Handling**:
- "Target agent is busy" - target agent is on a call
- "Target agent is not logged in" - target agent is offline
- "Target agent is on pause" - target agent is on break
- "Cannot transfer while call is on hold" - source agent has call on hold
- "Invalid or missing target agent" - no agent selected

**ECCP Protocol**:
```xml
<request id="timestamp.random">
    <transfercallagent>
        <agent_number>Agent/9000</agent_number>
        <agent_hash>XXX</agent_hash>
        <target_agent_number>Agent/9001</target_agent_number>
    </transfercallagent>
</request>
```

**Documentation**: See `TRANSFER_TO_AGENTS.md` for complete implementation details and test steps.

**Bug Fix**: Agent type agents require agent NUMBER (e.g., 1002) for `AgentRequest()`, not extension (e.g., 102). The code now correctly extracts the agent number from the agent string (Agent/1002 -> 1002) when transferring to Agent type agents.

---

## 33.1. Fix: Agent Type Transfer Using Correct Agent Number
**Date**: 2026-03-06

**Files**:
- `setup/dialer_process/dialer/ECCPConn.class.php`

**Bug 1**: Transferring calls to Agent type agents (app_agent_pool) failed with "Agent 'XXX' does not exist" error.

**Root Cause**: For Agent type agents like Agent/1002 using extension 102:
- The agent NUMBER is 1002 (used by AgentRequest)
- The agent EXTENSION is 102 (the device/phone number)
- `AgentRequest(102)` fails because agent ID '102' doesn't exist
- `AgentRequest(1002)` correctly routes to Agent/1002

**Bug 2**: Transfer was stored in database using extension number instead of agent number for Agent type agents.

**Fix**: Modified `Request_agentauth_transfercallagent()` to:
1. Extract agent number from agent string using regex: `Agent/(\d+)` -> 1002
2. Use agent number for `AgentRequest()` when transferring to Agent type agents
3. Use agent number in database transfer field for Agent type agents
4. Continue using extension for callback agent types (SIP/PJSIP/IAX2)

**Log Change**:
- Before: `AgentRequest("SIP/...", "102")` - fails
- After: `AgentRequest("SIP/...", "1002")` - succeeds

**Database Change**:
- Before: `transfer` field stores "102" (extension)
- After: `transfer` field stores "1002" (agent number) for Agent type agents

---

## 32. New ECCP Example Files
**Date**: 2026-03-06

**Files**:
- `setup/dialer_process/dialer/eccp-examples/callprogress.php`
- `setup/dialer_process/dialer/eccp-examples/getcampaigninfo.php`
- `setup/dialer_process/dialer/eccp-examples/filterbyagent.php`
- `setup/dialer_process/dialer/eccp-examples/saveformdata.php`

**Feature**: Added 4 new ECCP example files to complete documentation coverage for all actively used ECCP methods. The ECCP examples directory now includes examples for all 28 methods that are actively used in the codebase (100% coverage, up from 86%).

**New Examples**:
- `callprogress.php` - Enable/disable call progress event notifications (no auth required)
- `getcampaigninfo.php` - Retrieve campaign configuration including forms and scripts (no auth required)
- `filterbyagent.php` - Filter ECCP events to only receive events for a specific agent (requires agent authentication)
- `saveformdata.php` - Save form data collected during calls (requires agent authentication)

**Usage Examples**:
```bash
# Enable call progress tracking
su - asterisk -c "/opt/issabel/dialer/eccp-examples/callprogress.php 1"

# Get campaign information
su - asterisk -c "/opt/issabel/dialer/eccp-examples/getcampaigninfo.php outgoing 1"

# Filter events by agent
su - asterisk -c "/opt/issabel/dialer/eccp-examples/filterbyagent.php Agent/9000 password"

# Save form data
su - asterisk -c "/opt/issabel/dialer/eccp-examples/saveformdata.php Agent/9000 password outgoing 123 1 10:value1 11:value2"
```

**Documentation**: See `ECCP_EXAMPLES.md` for complete ECCP method coverage analysis and usage details.

---

## 31. Fix Campaign Staying Active After Data Exhaustion
**Date**: 2026-03-05

**File**: `setup/dialer_process/dialer/CampaignProcess.class.php`

**Issue**: Outgoing campaigns could remain in "Active" status even after all callable data was exhausted. This happened when the last calls completed while no agents were available (busy, logged out, or not allocated), because the "mark as finished" check only ran when agents were available to place calls.

**Root cause**: The finish check (`estatus = "T"`) in `_processCampaignWithAllocation()` required `$iNumLlamadasColocar > 0` (agents available). Two early-return paths exited before reaching this check:
1. No agents allocated this cycle
2. No free agents and no scheduled calls

**Fix**: Added `_checkCampaignDataExhausted()` method that runs at both early-return points. It independently checks whether the campaign has any remaining callable records and no active calls in progress, and marks the campaign as finished if data is exhausted — regardless of agent availability.

---

## 30. Fix Outgoing/Incoming Campaigns Panel Reports
**Date**: 2026-03-05

**Files**:
- `modules/agent_console/libs/paloSantoConsola.class.php`
- `setup/dialer_process/dialer/CampaignProcess.class.php`

**Issue**: The Outgoing and Incoming Campaigns Panel reports were filtering calls incorrectly. ECCP returns time-only strings (`HH:MM:SS`) for today's calls (date prefix stripped in `ECCPConn._agregarCallInfo`), but these were being compared against full datetime strings (`YYYY-MM-DD HH:MM:SS`). This caused calls to be incorrectly excluded from the panel results.

**Fix**: Added date normalization before comparison — when `callStartTime` is a time-only string (8 chars or less, no date prefix), today's date is prepended for proper datetime comparison. Applied to both outgoing and incoming campaign panel methods.

**Also included**: Added extra debug logging to the campaign fair rotation logic (rotation start, agent map, allocation results, per-campaign processing).

---

## 29. Max Concurrent Calls Awareness in Fair Rotation
**Date**: 2026-02-28

**File**: `setup/dialer_process/dialer/CampaignProcess.class.php`

**Issue**: When using fair rotation with shared agents, if a campaign won more agents than its `max_canales` (max concurrent calls) allowed, the extra agents were wasted - they weren't given to other campaigns that could use them.

**Example of the problem**:
```
10 shared agents available
Campaign 1: max_canales = 5
Campaign 2: max_canales = 5

Cycle 1 (Campaign 1 wins rotation for all 10 agents):
  - Campaign 1 allocated: 10 agents
  - Campaign 1 places: min(10, 5) = 5 calls (capped by max_canales)
  - 5 agents WASTED (not given to Campaign 2)
```

**Fix**: Enhanced the fair rotation allocation to be max_canales-aware:
- Track allocation count per campaign during agent distribution
- When a campaign wins an agent but has reached its max_canales limit, skip to the next campaign in rotation order
- Continue until finding a campaign with capacity or all campaigns are at limit

**New behavior**:
```
10 shared agents available
Campaign 1: max_canales = 5
Campaign 2: max_canales = 5

Cycle 1:
  - Agents 1-5: Campaign 1 wins, has capacity → allocated to Campaign 1
  - Agent 6: Campaign 1 wins, but at limit (5/5) → skip to Campaign 2 → allocated
  - Agent 7: Campaign 1 wins, but at limit → skip to Campaign 2 → allocated
  - Agents 8-10: Same pattern → allocated to Campaign 2

  Result: Campaign 1 gets 5, Campaign 2 gets 5 (NO agents wasted!)
```

**Technical Details**:
- New property: `$_campaignMaxCanales` stores max_canales per campaign
- New method: `_getRotationWinnerWithCapacity()` finds next campaign with available capacity
- Pass 1 now collects max_canales for each campaign
- Rotation still advances normally to maintain fairness across cycles

**Debug logs** (when dialer_debug enabled):
```bash
# Watch max_canales-aware allocation
tail -f /opt/issabel/dialer/dialerd.log | grep -E "max_canales|skipped.*at max_canales"

# Check skipped allocations
grep "skipped.*at max_canales" /opt/issabel/dialer/dialerd.log | tail -20
```

---

## 28. Dialer Service in Dashboard ProcessesStatus Applet
**Date**: 2026-02-27

**Files**:
- `build/5.0/install-issabel-callcenter.sh`
- `build/5.0/remove-issabel-callcenter.sh`
- `setup/icon_headphones.png`

**Feature**: Added the Issabel Call Center Service (Dialer) to the Issabel Dashboard's ProcessesStatus widget, allowing administrators to monitor and control the dialer service from the main dashboard.

**Changes**:
- Installation script now patches `/var/www/html/modules/dashboard/applets/ProcessesStatus/index.php` to add:
  - Dialer icon mapping (`icon_headphones.png`)
  - Service control mapping for start/stop/restart
  - Status detection using `/opt/issabel/dialer/dialerd.pid`
- Removal script removes all patches and the icon file
- Patching is idempotent (safe to run multiple times)

**Dashboard Display**:
- Service name: "Issabel Call Center Service"
- Icon: headphones icon
- Controls: Start, Stop, Restart, Enable, Disable

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
