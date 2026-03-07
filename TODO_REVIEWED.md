# TODO Review - Call Center Issabel 5

This document is a reviewed and re-evaluated version of TODO.md, with updated status based on CHANGES.md and current codebase analysis.

**Review Date**: 2026-03-08
**Reviewed Version**: 4.0.0.10+ (through Change #44)
**Previous Review**: 2026-01-31

---

## Review Summary

| Category | Total | Completed | Partially Done | Open | Deferred |
|----------|-------|-----------|----------------|------|----------|
| Critical | 8 | 3 | 2 | 2 | 1 |
| High Priority | 15 | 5 | 3 | 6 | 1 |
| Medium Priority | 25 | 0 | 1 | 21 | 3 |
| Low Priority | 27 | 0 | 0 | 10 | 17 |
| **Total** | **75** | **8** | **6** | **39** | **22** |

### Key Changes Since Last Review (2026-01-31 to 2026-03-08)

1. **Agent Conflict Detection** - COMPLETED (Change #23) - was Critical, placeholder only
2. **Break Time Accumulation** - COMPLETED (Change #27) - shift-based counters in agent console
3. **Park/Hold Call Handling** - SUBSTANTIALLY COMPLETE (Changes #10, #12, #15, #20, #26, #27)
4. **Hold Time Tracking** - PARTIALLY COMPLETE (Changes #20, #26, #27) - monitoring and agent console
5. **Agent-to-Agent Transfer** - NEW FEATURE (Changes #35.1, #35.2, #40, #44)
6. **Login Conflict Prevention** - NEW (Changes #34, #41) - prevents dual extension usage
7. **Extension Registration Check** - NEW (Change #35) - validates SIP/PJSIP registration before login
8. **Fair Agent Distribution** - NEW (Change #24) - N-way rotation for shared agents
9. **Predictive Dialer Integration** - NEW (Change #38) - Erlang-based prediction in fair rotation
10. **Centralized Debug Infrastructure** - NEW (Changes #39, #43) - all 31 modules covered
11. **UTF-8 (utf8mb4) Database Support** - NEW (Change #32) - full Unicode/emoji support
12. **Campaign Data Exhaustion Fix** - NEW (Change #31) - campaigns now finish correctly

---

## Status Legend

- `[ ]` - Open / Not Started
- `[~]` - Partially Implemented
- `[x]` - Completed
- `[D]` - Deferred / Won't Fix (technical debt acceptable)
- `[O]` - Obsolete / No Longer Relevant

---

## Completed

### Critical / High Priority - Completed

- [x] **Agent Ringing Status** (Multiple files)
  - Completed in Changes #7, #8
  - `AgentStateChange` ECCP event, frontend handlers, campaign and agents monitoring

- [x] **Agent Conflict Detection** (`CampaignProcess.class.php:625-626`)
  - TODO: Detect agent conflicts (double-booking across campaigns)
  - **Completed**: Change #23 - tracks claimed agents per cycle via `$_agentesReclamados`
  - Further enhanced by Change #24 (fair rotation) and Change #29 (max_canales awareness)

- [x] **Break Time Accumulation** (`agent_console/index.php:701-702`)
  - TODO: Display accumulated break time since session start
  - **Completed**: Change #27 - shift-based counters in agent console
  - Red counter shows cumulative break/pause time during current shift
  - Real-time updates via SSE and JavaScript intervals

- [x] **Park Call Handling** (`SQLWorkerProcess.class.php:971-972`)
  - TODO: What happens with parked calls?
  - **Completed**: Changes #10, #12, #15
  - Fixed: Agent stuck in hold state when customer hangs up (Change #12)
  - Fixed: Parking slot announcement issue (Change #12)
  - Fixed: Anonymous CallerID when retrieving from hold (Change #12)
  - Fixed: End hold delay after attended transfer (Change #15)
  - Fixed: End hold for incoming campaigns (Change #10)

- [x] **Customer Name Display** (`agent_console/index.php:979, 1834`)
  - TODO: Code assumes attribute 1 is customer name
  - **Completed**: Change #6 - Fixed duplicate name display, label corrected
  - Remaining assumption about schema structure is acceptable (standard convention)

### High Priority - Completed

- [x] **Hold Time Tracking in Monitoring** (`campaign_monitoring/index.php:556, 573, 806`)
  - TODO: Track hold pause start time, expose times
  - **Completed**: Changes #20, #26, #27
  - On-hold status display in Agents Monitoring (Change #20)
  - Total hold time column in Agents Monitoring (Change #26)
  - Total hold time shift counter in Agent Console (Change #27)

- [x] **Agent Break Events** (`agent_console/js/javascript.js:563, 586`)
  - TODO: Define `agentbreakenter` and `agentbreakexit` events
  - **Completed (alternative approach)**: Change #18 - break time tracked via audit table queries
  - Total break time column in Agents Monitoring with real-time timer updates
  - Shift-based break counters in Agent Console (Change #27)
  - Note: Implemented via database polling rather than dedicated ECCP events

- [x] **Agent Pause Status** (`AMIEventProcess.class.php:3033-3034`)
  - TODO: Handle `$params['Paused']` from QueueMemberStatus
  - **Completed**: Change #9 - Fixed break status handling for Asterisk 18 AMI event format

---

## Partially Completed

- [~] **Login Validation** (`paloSantoConsola.class.php:375`)
  - TODO: Return mismatch if agent logs into wrong console
  - **Partially Addressed**: Changes #34, #35, #41
  - Added: Extension conflict prevention (callback vs Agent type, Change #34)
  - Added: Reverse conflict prevention (Agent type vs callback, Change #41)
  - Added: Extension registration check before callback login (Change #35)
  - Remaining: No check for agent logging into a console assigned to a different agent number

- [~] **Campaign Transfer** (`ECCPConn.class.php:2998`)
  - TODO: Allow sending call to different campaign
  - **Partially Addressed**: Changes #35.1, #35.2, #44
  - Added: Transfer to another agent (same or different campaign)
  - Remaining: No explicit campaign-to-campaign transfer (calls follow the target agent's campaign)

- [~] **Agent Status i18n** (`rep_agents_monitoring/js/javascript.js:197`)
  - TODO: Internationalize LOGOUT status label
  - **Partially Addressed**: Changes #19, #20 added new i18n labels for monitoring features
  - Remaining: LOGOUT label itself still not internationalized in JavaScript

---

## Open - Critical Priority

### Security & Authentication

- [ ] **ECCP Authentication Security** (`ECCPConn.class.php:333-341`)
  - FIXME: Password sent over unencrypted connection (plaintext or hash)
  - **Status**: OPEN - Still a security concern
  - **Recommendation**: Implement TLS/SSL for ECCP connections
  - **Priority**: CRITICAL - Security vulnerability

### Dialer Reliability

- [ ] **Asterisk Restart Detection** (`CampaignProcess.class.php:244-250`)
  - TODO: Detect Asterisk restart and re-synchronize dialer state
  - **Status**: OPEN - Can cause stale state after Asterisk restart
  - **Recommendation**: Monitor AMI connection, re-query state on reconnect
  - **Priority**: CRITICAL - Affects system reliability

---

## Open - High Priority

### Agent Console Core

- [ ] **Call Duration Tracking** (`agent_console/index.php:767`)
  - TODO: Display elapsed time in current call
  - **Status**: OPEN - No per-call timer displayed
  - Note: Change #27 added shift-level cumulative counters (login/break/hold time), but not a per-call elapsed timer
  - **Recommendation**: JavaScript timer starting from call answer event
  - **Priority**: HIGH - User experience feature

### Monitoring & Real-time Updates

- [ ] **New Queue Appearance** (`rep_incoming_calls_monitoring/js/javascript.js:106`)
  - TODO: Handle dynamic queue appearance in monitoring
  - **Status**: OPEN - Requires page reload for new queues
  - **Priority**: LOW (downgraded - rare scenario)

- [ ] **New Agent in Queue** (`rep_agents_monitoring/js/javascript.js:234`)
  - TODO: Handle agents appearing in new queues dynamically
  - **Status**: OPEN - Similar to new queue issue
  - **Priority**: LOW (downgraded - rare scenario)

### Campaign Management

- [ ] **Campaign Scheduling Purge** (`AMIEventProcess.class.php:632-633`)
  - TODO: Purge outgoing campaigns outside scheduled hours
  - **Status**: OPEN - Campaigns run continuously
  - **Recommendation**: Check schedule in campaign loop, pause outside hours
  - **Priority**: MEDIUM (downgraded - manual workaround available)

- [ ] **Configurable Dial Timeout** (`CampaignProcess.class.php:1090-1091`)
  - TODO: Make timeout (30 sec) configurable per campaign
  - **Status**: OPEN - Hardcoded to 30 seconds
  - **Recommendation**: Add column to campaign table, expose in UI
  - **Priority**: MEDIUM - Affects campaign flexibility

- [ ] **Auto-calculate History Window** (`CampaignProcess.class.php:682-683`)
  - TODO: Auto-calculate history window (30 min hardcoded)
  - **Status**: OPEN - Static value
  - **Priority**: LOW (downgraded - rarely needs adjustment)

### Configuration Management

- [ ] **Configuration Consolidation** (`ECCPConn.class.php:1488, 1491`)
  - TODO: Single configuration definition, move paths to config
  - **Status**: OPEN - Duplicated in multiple files
  - **Priority**: LOW (downgraded - technical debt, works as-is)

- [ ] **Monitoring Prefix** (`SQLWorkerProcess.class.php:911-912`)
  - TODO: Configure monitoring prefix
  - **Status**: OPEN - Hardcoded
  - **Priority**: LOW (downgraded - rarely needed)

### Database

- [ ] **Incremental Retries Review** (`SQLWorkerProcess.class.php:742-744`)
  - TODO: Review if inc_retries is necessary
  - **Status**: OPEN - Code review needed
  - **Priority**: LOW (downgraded - works as-is)

- [D] **Call Info Duplication** (`ECCPConn.class.php:2562`)
  - FIXME: Compatibility requires mixing callinfo and agent fields
  - **Status**: DEFERRED - Backwards compatibility requirement
  - **Priority**: N/A - Won't fix

### ECCP Client Authorization

- [ ] **ECCP Client Authorization** (`ECCPConn.class.php:341-343`)
  - TODO: Implement `eccp_authorized_clients` table for agent authorization
  - **Status**: OPEN - Table exists but not enforced
  - **Recommendation**: Implement IP/client-based authorization
  - **Priority**: HIGH (downgraded from Critical - not exploitable remotely)

---

## Open - Medium Priority

### ECCP Protocol Enhancements

- [ ] **Form Field Length** (`ECCPConn.class.php:986-987`)
  - TODO: Allow specifying input field length
  - **Priority**: LOW

- [ ] **Form Value Support** (`ECCPConn.class.php:1008-1012`)
  - TODO: Form support for default value (dated 2011)
  - **Priority**: LOW (15 years old, not requested)

- [ ] **Database Field Length** (`ECCPConn.class.php:1286-1287`)
  - TODO: Extract max field length from database schema
  - **Priority**: LOW

- [ ] **Client Channel Handling** (`ECCPConn.class.php:2532`)
  - TODO: Handle when clientchannel is defined
  - **Priority**: LOW (rarely triggered)

- [ ] **Agent Attributes** (`ECCPHelper.lib.php:104`)
  - TODO: Expand when arbitrary attributes table available
  - **Priority**: LOW (schema change needed)

### Reporting & Data Display

- [ ] **Contact Formatting** (`agent_console/index.php:961, 1810`)
  - TODO: Proper formatting for contact calls
  - **Priority**: LOW (cosmetic)

- [ ] **Campaign Form Label** (`agent_console/index.php:1279`)
  - TODO: Assign label from campaign form
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

- [ ] **Form Placeholder i18n** (`campaign_out/index.php:875`, `campaign_in/index.php:719`)
  - TODO: Internationalize "FORMULARIO" placeholder
  - **Priority**: MEDIUM - Affects non-Spanish users

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

- [ ] **Queue Status Recovery** (`QueueShadow.class.php:248, 303`)
  - TODO: Determine recovery mechanism
  - **Priority**: LOW (restart resolves)

- [ ] **Follow-up Termination** (`AMIEventProcess.class.php:3065-3069`)
  - TODO: Behavior only adequate under certain conditions
  - **Priority**: LOW (works in practice)

- [ ] **Timeout Countdown** (`AMIEventProcess.class.php:3337-3338`)
  - TODO: Timeout could show countdown timer
  - **Priority**: LOW (nice-to-have)

### Code Quality

- [ ] **Duplicate Extension Listing** (`paloSantoConsola.class.php:219`)
  - TODO: Duplicates ECCPConn::_listarExtensiones
  - **Priority**: LOW (refactoring task)

- [ ] **Campaign Contacts SQL** (`paloSantoIncomingCampaign.class.php:436`)
  - TODO: Add SQL when campaign contacts implemented
  - **Priority**: LOW (feature not requested)

- [ ] **ECCP Proxy** (`ECCPProxyConn.class.php:85`)
  - TODO: Future phase implementation
  - **Priority**: LOW

### Input Validation

- [ ] **Form Escaping** (`campaign_out/index.php:797`, `campaign_in/index.php:641`)
  - TODO: Validate what needs escaping
  - **Priority**: MEDIUM - Potential XSS

### Monitoring Console

- [ ] **Queue List in Monitoring** (`paloSantoConsola.class.php:946`)
  - TODO: Return queue list in monitoring console
  - **Priority**: LOW

- [ ] **Monitoring Implementation** (`paloSantoConsola.class.php:940, 943`)
  - TODO: Implement for monitoring console
  - **Priority**: LOW

---

## Deferred / Third-Party Libraries

### jQuery Layout Plugin (v1.4.0) - All 15 items DEFERRED

**Recommendation**: Either upgrade to a maintained version or replace with a modern alternative. Individual fixes are not recommended.

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

## Known Issues Discovered During Review

These are new issues identified from the CHANGES.md that represent known limitations or TODOs introduced by recent work:

1. **Transfer Agent Attribution** (Change #44)
   - When a call is transferred between agents, `id_agent` in `calls`/`call_entry` is overwritten to the target agent. Source agent attribution is lost.
   - A `call_agent_history` table would be needed for accurate multi-agent call tracking.

2. **RINGING-as-Free Analysis Needed** (Change #36)
   - Even in predictive mode, counting RINGING agents as "free" may cause over-placement. Deep analysis recommended.
   - TODO comments added in `QueueShadow.class.php` and `Predictor.class.php`.

3. **Predictive Dialer Minimum Samples** (Change #38)
   - Erlang prediction requires `num_completadas >= 10`. Below that threshold, falls back to basic agent counting. This threshold may need tuning.

---

## Recommended Action Plan

### Immediate (Critical)

1. **ECCP TLS Implementation** - Address security vulnerability (plaintext passwords)
2. **Asterisk Restart Detection** - Improve system reliability (stale state after restart)

### Short-term (High)

1. **Call Duration Timer** - Per-call elapsed time display in agent console
2. **ECCP Client Authorization** - Enforce `eccp_authorized_clients` table
3. **Form Escaping Review** - Security audit for XSS in campaign forms

### Medium-term

1. **Configurable Dial Timeout** - Per-campaign timeout setting
2. **Error Handling in Reports** - calls_per_hour, graphic_calls
3. **i18n Completion** - FORMULARIO placeholder, LOGOUT label
4. **Transfer Agent History** - `call_agent_history` table for multi-agent call tracking

### Long-term (Low/Deferred)

1. **jQuery Layout Replacement** - Modernize frontend
2. **Configuration Consolidation** - Reduce tech debt
3. **ECCP Protocol Enhancements** - Form fields, client channels

---

## Major Features Added Since Last Review

These were not in the original TODO but represent significant new functionality delivered:

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

## Notes

- Many original TODOs date back to 2011-2012 (15 years old)
- jQuery Layout library TODOs should not be fixed individually
- Security items should take precedence over features
- Some TODOs represent features that may never be needed
- Significant progress since last review: 5 new completions, 3 partial, 14+ new features delivered
- The codebase has undergone major improvements in agent management, campaign fairness, transfer handling, and observability

---

## References

- Original TODO.md: See same directory
- Change history: See `CHANGES.md`
- Architecture: See `CLAUDE.md`
- Transfer docs: See `TRANSFER_TO_AGENTS.md`
- ECCP examples: See `ECCP_EXAMPLES.md`
