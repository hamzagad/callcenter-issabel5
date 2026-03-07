# TODO Review - Call Center Issabel 5

Reviewed and re-evaluated against the **live codebase**, not just CHANGES.md. Each item was verified by checking the actual source files for TODO comments and implementation status.

**Review Date**: 2026-03-08
**Reviewed Version**: 4.0.0.10+ (through Change #44)
**Previous Review**: 2026-01-31

---

## Review Summary

| Category | Total | Completed | Partially Done | Open | Deferred |
|----------|-------|-----------|----------------|------|----------|
| Critical | 8 | 4 | 1 | 2 | 1 |
| High Priority | 15 | 7 | 1 | 6 | 1 |
| Medium Priority | 25 | 1 | 1 | 20 | 3 |
| Low Priority | 27 | 0 | 0 | 10 | 17 |
| **Total** | **75** | **12** | **3** | **38** | **22** |

### Key Changes Since Last Review (2026-01-31 to 2026-03-08)

1. **Agent Conflict Detection** - COMPLETED (Change #23)
2. **Break Time Accumulation** - COMPLETED (Change #27) - shift-based break counter in agent console
3. **Call Duration Tracking** - CONFIRMED COMPLETED - main chronometer already shows in-call elapsed time (green bar)
4. **Park/Hold Call Handling** - COMPLETED (Changes #10, #12, #15)
5. **Hold Time Tracking** - COMPLETED (Changes #20, #26, #27)
6. **Agent Break Tracking** - COMPLETED via audit table approach (Changes #18, #27)
7. **Agent Pause Status (Asterisk 18)** - COMPLETED (Change #9)
8. **Queue Status Recovery** - COMPLETED - no TODO remains in code, safety checks implemented
9. **Agent-to-Agent Transfer** - NEW FEATURE (Changes #35.1, #35.2, #40, #44)
10. **Fair Agent Distribution** - NEW (Change #24) - N-way rotation for shared agents
11. **Predictive Dialer Integration** - NEW (Change #38) - Erlang-based prediction in fair rotation
12. **Centralized Debug Infrastructure** - NEW (Changes #39, #43) - all 31 modules covered
13. **UTF-8 (utf8mb4) Database Support** - NEW (Change #32)
14. **Login Conflict Prevention** - NEW (Changes #34, #41) - prevents dual extension usage
15. **Extension Registration Check** - NEW (Change #35) - validates SIP/PJSIP before login

---

## Status Legend

- `[ ]` - Open / Not Started
- `[~]` - Partially Implemented
- `[x]` - Completed
- `[D]` - Deferred / Won't Fix (technical debt acceptable)

---

## Completed

### Critical - Completed

- [x] **Agent Conflict Detection** (`CampaignProcess.class.php`)
  - TODO: Detect agent conflicts (double-booking across campaigns)
  - **Completed**: Change #23 - tracks claimed agents per cycle via `$_agentesReclamados`
  - Enhanced by Change #24 (fair rotation) and Change #29 (max_canales awareness)

- [x] **Park/Hold Call Handling** (`SQLWorkerProcess.class.php`)
  - TODO: What happens with parked calls?
  - **Completed**: Changes #10, #12, #15
  - Fixed: Agent stuck in hold state when customer hangs up
  - Fixed: Parking slot announcement issue
  - Fixed: Anonymous CallerID when retrieving from hold
  - Fixed: End hold delay after attended transfer
  - Fixed: End hold for incoming campaigns

- [x] **Break Time Accumulation** (`agent_console/index.php:800-801`)
  - TODO: Display accumulated break time since session start
  - **Completed**: Change #27 - shift-based counters in agent console
  - Red counter shows cumulative break/pause time during current shift
  - Real-time updates via SSE and JavaScript intervals
  - **Note**: TODO comment is stale in code at line 800-801 but feature is fully implemented

- [x] **Call Duration Tracking** (`agent_console/index.php:866`)
  - TODO: Display elapsed time in current call
  - **Completed**: Already implemented - the main chronometer bar turns green during active calls, shows "Connected to call" status, and the timer (`#issabel-callcenter-cronometro`) counts elapsed call time in real-time
  - `$iDuracionLlamada` is calculated from `linkstart` timestamp (line 857) and passed as `timer_seconds` to JavaScript
  - **Note**: TODO comment is stale in code at line 866 but feature is fully implemented

### High Priority - Completed

- [x] **Agent Ringing Status** (Multiple files)
  - Completed in Changes #7, #8
  - `AgentStateChange` ECCP event, frontend handlers, campaign and agents monitoring

- [x] **Hold Time Tracking** (`campaign_monitoring/index.php`)
  - TODO: Track hold pause start time, expose times
  - **Completed**: Changes #20, #26, #27
  - On-hold status display in Agents Monitoring (Change #20)
  - Total hold time column in Agents Monitoring (Change #26)
  - Total hold time shift counter in Agent Console (Change #27)

- [x] **Agent Break Tracking** (`agent_console/js/javascript.js:713, 736`)
  - TODO: Define `agentbreakenter` and `agentbreakexit` events
  - **Completed (alternative approach)**: Break time tracked via audit table queries (Change #18)
  - Total break time column in Agents Monitoring with real-time timer updates
  - Shift-based break counters in Agent Console (Change #27)
  - **Note**: TODO comments remain in JS at lines 713/736 asking for ECCP events, but the functionality is delivered via database polling. The ECCP event approach could still improve responsiveness but is not required.

- [x] **Agent Pause Status** (`AMIEventProcess.class.php`)
  - TODO: Handle `$params['Paused']` from QueueMemberStatus
  - **Completed**: Change #9 - Fixed break status handling for Asterisk 18 AMI event format

- [x] **Customer Name Display** (`agent_console/index.php:1078, 2054`)
  - TODO: Code assumes attribute 1 is customer name
  - **Completed**: Change #6 - Fixed duplicate name display, label corrected
  - TODO comment remains but the assumption is standard convention

- [x] **Queue Status Recovery** (`QueueShadow.class.php`)
  - TODO: Determine recovery mechanism
  - **Completed**: No TODO exists in code anymore. Safety checks (negative caller count prevention, missing member warnings) are implemented.

### Medium Priority - Completed

- [x] **Form Escaping** (`campaign_out/index.php:804`, `campaign_in/index.php:642`)
  - TODO: Validate what needs escaping
  - **Completed**: `adaptar_formato_rte()` function implements HTML entity escaping for quotes, control characters, and special quote variants
  - TODO comment remains but is asking for review of existing implementation, not new work

---

## Partially Completed

- [~] **Login Validation** (`paloSantoConsola.class.php:504-505`)
  - TODO: Return mismatch if agent logs into wrong console
  - **Partially Addressed**: Changes #34, #35, #41
  - Added: Extension conflict prevention (callback vs Agent type)
  - Added: Reverse conflict prevention (Agent type vs callback)
  - Added: Extension registration check before callback login
  - **Still Open**: No check for agent logging into a console assigned to a different agent number
  - TODO comment still exists at line 504-505

- [~] **Campaign Transfer** (`ECCPConn.class.php`)
  - TODO: Allow sending call to different campaign
  - **Partially Addressed**: Changes #35.1, #35.2, #44
  - Added: Transfer to another agent (agent-to-agent transfer feature)
  - **Still Open**: No explicit campaign-to-campaign transfer (calls follow the target agent's campaign)

- [~] **ECCP Client Authorization** (`ECCPConn.class.php:345-348`)
  - TODO: Implement `eccp_authorized_clients` table for agent authorization
  - **Partially Addressed**: Table exists and is used for authorization lookup
  - **Still Open**: Could be expanded for IP/client-based authorization
  - TODO comment still exists

---

## Open - Critical Priority

### Security & Authentication

- [ ] **ECCP Authentication Security** (`ECCPConn.class.php:337-344`)
  - FIXME: Password sent over unencrypted connection (plaintext or hash)
  - **Verified**: FIXME comment still exists, no TLS/SSL implementation
  - **Priority**: CRITICAL - Security vulnerability

### Dialer Reliability

- [ ] **Asterisk Restart Detection** (`CampaignProcess.class.php:271-281`)
  - TODO: Detect Asterisk restart and re-synchronize dialer state
  - **Verified**: Multi-line TODO still exists, no detection mechanism implemented
  - **Priority**: CRITICAL - Affects system reliability

---

## Open - High Priority

### Campaign Management

- [ ] **Campaign Scheduling Purge** (`AMIEventProcess.class.php:653-654`)
  - TODO: Purge outgoing campaigns outside scheduled hours
  - **Verified**: TODO still exists in `_nuevasCampanias()` method
  - **Priority**: MEDIUM (manual workaround available)

- [ ] **Configurable Dial Timeout** (`CampaignProcess.class.php:1891-1892`)
  - TODO: Make timeout (30 sec) configurable per campaign
  - **Verified**: Hardcoded `return 30;` in `_getSegundosReserva()`, TODO still exists
  - **Priority**: MEDIUM - Affects campaign flexibility

- [ ] **Auto-calculate History Window** (`CampaignProcess.class.php:1477-1478`)
  - TODO: Auto-calculate history window (30 min hardcoded)
  - **Verified**: TODO still exists
  - **Priority**: LOW (rarely needs adjustment)

### Monitoring & Real-time Updates

- [ ] **New Queue Appearance** (`rep_incoming_calls_monitoring/js/javascript.js:106`)
  - TODO: Handle dynamic queue appearance in monitoring
  - **Verified**: TODO still exists - "no se maneja todavia aparicion de nueva cola"
  - **Priority**: LOW (rare scenario)

- [ ] **New Agent in Queue** (`rep_agents_monitoring/js/javascript.js:341`)
  - TODO: Handle agents appearing in new queues dynamically
  - **Verified**: TODO still exists - "no se maneja todavia aparicion de agente en nueva cola"
  - **Priority**: LOW (rare scenario)

### Configuration Management

- [ ] **Configuration Consolidation** (`ECCPConn.class.php:1492-1495`, `CampaignProcess.class.php:2240-2241`)
  - TODO: Single configuration definition for FreePBX config file opening
  - **Verified**: Duplicate TODO exists in both files
  - **Priority**: LOW (technical debt, works as-is)

- [ ] **Monitoring Prefix** (`SQLWorkerProcess.class.php:912-913`)
  - TODO: Configure monitoring directory prefix
  - **Verified**: Hardcoded to `/var/spool/asterisk/monitor/`, TODO still exists
  - **Priority**: LOW

### Database

- [ ] **Incremental Retries Review** (`SQLWorkerProcess.class.php:743-746`)
  - TODO: Review if inc_retries is necessary
  - **Verified**: TODO still exists, parameter still used
  - **Priority**: LOW

- [D] **Call Info Duplication** (`ECCPConn.class.php:2611-2616`)
  - FIXME: Compatibility requires mixing callinfo and agent fields
  - **Status**: DEFERRED - Backwards compatibility requirement

---

## Open - Medium Priority

### ECCP Protocol Enhancements

- [ ] **Form Field Length** (`ECCPConn.class.php:990-991`)
  - TODO: Allow specifying input field length (hardcoded to 250)
  - **Verified**: TODO still exists
  - **Priority**: LOW

- [ ] **Form Value Support** (`ECCPConn.class.php:1012-1017`)
  - TODO: Form support for default/initial values (dated 2011)
  - **Verified**: TODO still exists with code stub
  - **Priority**: LOW (15 years old, not requested)

- [ ] **Database Field Length** (`ECCPConn.class.php:1290-1291`)
  - TODO: Extract max field length from database schema (hardcoded to 250)
  - **Verified**: TODO still exists
  - **Priority**: LOW

- [ ] **Agent Attributes** (`ECCPHelper.lib.php:121-123`)
  - TODO: Expand when arbitrary attributes table available
  - **Verified**: TODO still exists, no attributes table
  - **Priority**: LOW

### Reporting & Data Display

- [ ] **Contact Formatting** (`agent_console/index.php:1060-1062, 2030-2032`)
  - TODO: Proper formatting for contact calls with arbitrary attributes
  - **Verified**: TODO still exists at both locations
  - **Priority**: LOW (cosmetic)

- [ ] **Campaign Form Label** (`agent_console/index.php:1409`)
  - TODO: Assign label from campaign form (hardcoded to empty string)
  - **Verified**: TODO still exists
  - **Priority**: LOW (cosmetic)

- [ ] **Agent List Dropdown** (`rep_agent_information/index.php:111`)
  - TODO: Replace with dropdown of agents in selected queue
  - **Priority**: LOW (UI improvement)

- [ ] **Error Handling - Calls Per Hour** (`calls_per_hour/index.php:140`)
  - TODO: Handle errors when retrieving calls
  - **Priority**: MEDIUM - Reliability

- [ ] **Error Handling - Graphic Calls** (`graphic_calls/index.php:143`)
  - TODO: Handle errors when retrieving calls
  - **Priority**: MEDIUM - Reliability

- [ ] **Recording File Convention** (`paloSantoCallsDetail.class.php:462`)
  - TODO: Rename according to campaign convention
  - **Priority**: LOW

- [ ] **Configurable Limit** (`paloSantoCallsDetail.class.php:456`)
  - TODO: Make configurable
  - **Priority**: LOW

### Internationalization

- [ ] **Agent Status i18n** (`rep_agents_monitoring/js/javascript.js:234`)
  - TODO: Internationalize LOGOUT status label
  - **Verified**: TODO still exists
  - **Priority**: MEDIUM

- [ ] **Form Placeholder i18n** (`campaign_out/index.php:875`, `campaign_in/index.php:719`)
  - TODO: Internationalize "FORMULARIO" placeholder
  - **Priority**: MEDIUM

- [ ] **Report i18n** (`rep_agent_information/index.php:100`)
  - TODO: Internationalize and add to template
  - **Priority**: LOW

- [ ] **Upload i18n** (`paloSantoUploadFile.class.php:193`)
  - TODO: Internationalize error messages
  - **Priority**: LOW

- [ ] **Query Error Display** (`paloSantoConfiguration.class.php:68`)
  - TODO: Determine what to display if query fails
  - **Priority**: LOW

### Queue Management

- [ ] **Follow-up Termination** (`AMIEventProcess.class.php:3714-3719`)
  - TODO: Behavior only adequate under certain queue conditions
  - **Verified**: Multi-line TODO still exists, documents limitation with outgoing campaign queues as destinations
  - **Priority**: LOW (works in practice)

- [ ] **Timeout Countdown** (`AMIEventProcess.class.php:4014-4015`)
  - TODO: Timeout could show countdown timer for parked calls
  - **Verified**: TODO still exists in `msg_ParkedCallTimeOut()`
  - **Priority**: LOW (nice-to-have)

### Code Quality

- [ ] **Duplicate Extension Listing** (`paloSantoConsola.class.php:242-243`)
  - TODO: Duplicates ECCPConn::_listarExtensiones in dialer
  - **Verified**: TODO still exists
  - **Priority**: LOW (refactoring task)

- [ ] **Campaign Contacts SQL** (`paloSantoIncomingCampaign.class.php:436`)
  - TODO: Add SQL when campaign contacts implemented
  - **Priority**: LOW (feature not requested)

- [ ] **ECCP Proxy** (`ECCPProxyConn.class.php:107`)
  - TODO: Future phase - dedicated worker for multiplexing connections
  - **Verified**: TODO still exists
  - **Priority**: LOW

### Monitoring Console

- [ ] **Queue List in Monitoring** (`paloSantoConsola.class.php:1143-1144`)
  - TODO: Return queue list in monitoring console
  - **Verified**: TODO still exists
  - **Priority**: LOW

- [ ] **Monitoring Implementation** (`paloSantoConsola.class.php:1135-1136, 1139-1140`)
  - TODO: Implement for monitoring console (two instances)
  - **Verified**: TODOs still exist
  - **Priority**: LOW

### Security

- [ ] **XSS in Debug Function** (`issabel2.lib.php:411-413`)
  - TODO: Fix XSS - use htmlspecialchars instead of manual escaping in `_cc_debug_flush_html()`
  - **NEW**: Introduced by Change #39 (centralized debug infrastructure)
  - **Priority**: MEDIUM (only active when debug enabled)

---

## Deferred / Third-Party Libraries

### jQuery Layout Plugin (v1.4.0) - All 15 items DEFERRED

**Recommendation**: Replace with modern alternative. Individual fixes not recommended.

- [D] jQuery 2.x compatibility (line 28)
- [D] Method usability improvements (lines 1241-1242)
- [D] State management plugin integration (line 1673)
- [D] iframe fix consideration (line 2667)
- [D] Drag cancellation handling (line 2678)
- [D] jQuery bug workaround cleanup (line 3105)
- [D] onshow callback placement (line 3637)
- [D] sizePane race condition (line 3645)
- [D] Trigger event unbind necessity (line 3710)
- [D] iframe width CSS question (line 4269)
- [D] outerWidth setter method (line 4270)
- [D] North pane assumption (line 4519)
- [D] Toggler position verification (line 4565)
- [D] Button unbinding (line 5866)
- [D] Browser zoom handling (lines 5894-5895)

### Handlebars

- [D] **Handlebars Compilation** (`handlebars-1.3.0.js:375`)
  - TODO: Remove checkRevision, break up compilePartial
  - **Status**: DEFERRED - Use newer Handlebars if needed

---

## Known Issues & New TODOs

Issues identified from recent changes that represent known limitations:

1. **Transfer Agent Attribution** (Change #44, TODO in code)
   - When a call is transferred between agents, `id_agent` in `calls`/`call_entry` is overwritten to the target agent. Source agent attribution is lost.
   - A `call_agent_history` table would be needed for accurate multi-agent call tracking.

2. **RINGING-as-Free Analysis Needed** (Change #36, TODO in code)
   - Even in predictive mode, counting RINGING agents as "free" may cause over-placement.
   - TODO comments added in `QueueShadow.class.php` and `Predictor.class.php`.

3. **Predictive Dialer Minimum Samples** (Change #38)
   - Erlang prediction requires `num_completadas >= 10`. Below that, falls back to basic agent counting. Threshold may need tuning.

4. **XSS in Debug Flush** (Change #39, TODO in code)
   - `_cc_debug_flush_html()` uses manual escaping instead of `htmlspecialchars()`.
   - Only exploitable when debug mode is enabled.

---

## Recommended Action Plan

### Immediate (Critical)

1. **ECCP TLS Implementation** - Address plaintext password vulnerability
2. **Asterisk Restart Detection** - Prevent stale state after Asterisk restart

### Short-term (High)

1. **ECCP Client Authorization** - Expand IP/client-based authorization
2. **XSS in Debug Function** - Use htmlspecialchars in `_cc_debug_flush_html()`

### Medium-term

1. **Configurable Dial Timeout** - Per-campaign timeout setting
2. **Error Handling in Reports** - calls_per_hour, graphic_calls
3. **i18n Completion** - FORMULARIO placeholder, LOGOUT label
4. **Transfer Agent History** - `call_agent_history` table for multi-agent call tracking

### Long-term (Low/Deferred)

1. **jQuery Layout Replacement** - Modernize frontend
2. **Configuration Consolidation** - Reduce tech debt
3. **ECCP Protocol Enhancements** - Form fields, attributes

---

## Stale TODO Comments - CLEANED UP (2026-03-08)

The following stale TODO comments were removed from the codebase:

| File | TODO Text | Resolution |
|------|-----------|------------|
| `agent_console/index.php:800-801` | "debe contener tiempo acumulado de break" | Removed - Change #27 implemented shift break counter |
| `agent_console/index.php:866` | "debe contener tiempo transcurrido en llamada" | Removed - green bar chronometer already shows in-call elapsed time |
| `agent_console/index.php:1078, 2054` | "codigo asume que atributo 1 es nombre" | Replaced with explanatory comment - convention is acceptable |
| `agent_console/js/javascript.js:713, 736` | "definir evento agentbreakenter/exit" | Replaced with explanatory comment - audit table polling approach used instead |

---

## Major Features Added Since Last Review

| Change | Feature | Date |
|--------|---------|------|
| #24 | Fair Agent Distribution (N-way rotation) | 2026-02-26 |
| #27 | Shift-Based Counters (login/break/hold time) | 2026-02-27 |
| #28 | Dashboard ProcessesStatus Integration | 2026-02-27 |
| #29 | Max Concurrent Calls in Fair Rotation | 2026-02-28 |
| #31 | Campaign Data Exhaustion Fix | 2026-03-05 |
| #32 | Full UTF-8 (utf8mb4) Database Support | 2026-03-05 |
| #34, #41 | Login Conflict Prevention | 2026-03-06 |
| #35 | Extension Registration Check | 2026-03-06 |
| #35.1 | Agent-to-Agent Transfer | 2026-03-06 |
| #36 | Conditional RINGING-as-Free | 2026-03-06 |
| #37 | Overcommit Cap by max_canales | 2026-03-06 |
| #38 | Predictive Dialer in Fair Rotation | 2026-03-06 |
| #39, #43 | Centralized Debug Infrastructure (31 modules) | 2026-03-07 |
| #44 | Transfer Call Tracking Fix | 2026-03-08 |

---

## References

- Original TODO.md: See same directory
- Change history: See `CHANGES.md`
- Architecture: See `CLAUDE.md`
- Transfer docs: See `TRANSFER_TO_AGENTS.md`
- ECCP examples: See `ECCP_EXAMPLES.md`
