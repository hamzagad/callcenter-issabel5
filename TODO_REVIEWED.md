# TODO Review - Call Center Issabel 5

This document is a reviewed and re-evaluated version of TODO.md, with updated status based on CHANGES.md and current codebase analysis.

**Review Date**: 2026-01-31
**Reviewed Version**: 4.0.0.10+

---

## Review Summary

| Category | Original Count | Completed | Still Open | Obsolete/Deferred |
|----------|----------------|-----------|------------|-------------------|
| Critical | 8 | 1 | 6 | 1 |
| High Priority | 15 | 2 | 12 | 1 |
| Medium Priority | 25 | 0 | 22 | 3 |
| Low Priority | 27 | 0 | 10 | 17 |
| **Total** | **75** | **3** | **50** | **22** |

### Key Findings

1. **Agent Ringing Status** - COMPLETED in v4.0.0.7 and v4.0.0.9
2. **Park/Hold Call Handling** - PARTIALLY ADDRESSED in v4.0.0.10
3. **Third-party library TODOs** - Recommended to defer (17 items from jQuery Layout)
4. **Security items** - Still critical priority, unchanged

---

## Status Legend

- `[ ]` - Open / Not Started
- `[~]` - Partially Implemented
- `[x]` - Completed
- `[D]` - Deferred / Won't Fix (technical debt acceptable)
- `[O]` - Obsolete / No Longer Relevant

---

## Critical Priority

### Security & Authentication

- [ ] **ECCP Authentication Security** (`ECCPConn.class.php:333-341`)
  - FIXME: Password sent over unencrypted connection (plaintext or hash)
  - **Status**: OPEN - Still a security concern
  - **Recommendation**: Implement TLS/SSL for ECCP connections
  - **Priority**: CRITICAL - Security vulnerability

- [ ] **ECCP Client Authorization** (`ECCPConn.class.php:341-343`)
  - TODO: Implement `eccp_authorized_clients` table for agent authorization
  - **Status**: OPEN - Table exists but not enforced
  - **Recommendation**: Implement IP/client-based authorization
  - **Priority**: HIGH (downgraded from Critical - not exploitable remotely)

### Dialer Reliability

- [ ] **Asterisk Restart Detection** (`CampaignProcess.class.php:244-250`)
  - TODO: Detect Asterisk restart and re-synchronize dialer state
  - **Status**: OPEN - Can cause stale state after Asterisk restart
  - **Recommendation**: Monitor AMI connection, re-query state on reconnect
  - **Priority**: CRITICAL - Affects system reliability

- [ ] **Agent Conflict Detection** (`CampaignProcess.class.php:625-626`)
  - TODO: Detect agent conflicts (double-booking across campaigns)
  - **Status**: OPEN - Placeholder code only
  - **Recommendation**: Implement check before agent assignment
  - **Priority**: HIGH (downgraded - workaround: proper queue config)

- [~] **Park Call Handling** (`SQLWorkerProcess.class.php:971-972`)
  - TODO: What happens with parked calls?
  - **Status**: PARTIALLY ADDRESSED in v4.0.0.10
  - Fixed: Agent stuck state when customer hangs up from hold
  - Fixed: Parking slot announcement issue
  - Remaining: Edge cases may still exist
  - **Priority**: MEDIUM (downgraded - main issues fixed)

### Agent Console Core

- [ ] **Break Time Accumulation** (`agent_console/index.php:701-702`)
  - TODO: Display accumulated break time since session start
  - **Status**: OPEN - No implementation
  - **Recommendation**: Track break timestamps in session, calculate cumulative
  - **Priority**: HIGH - Affects agent metrics accuracy

- [ ] **Call Duration Tracking** (`agent_console/index.php:767`)
  - TODO: Display elapsed time in current call
  - **Status**: OPEN - No timer displayed
  - **Recommendation**: JavaScript timer starting from call answer event
  - **Priority**: HIGH - User experience feature

- [ ] **Login Validation** (`paloSantoConsola.class.php:375`)
  - TODO: Return mismatch if agent logs into wrong console
  - **Status**: OPEN - No validation
  - **Recommendation**: Compare expected vs actual agent number
  - **Priority**: MEDIUM (downgraded - edge case scenario)

---

## High Priority

### Monitoring & Real-time Updates

- [x] **Agent Ringing Status** (Multiple files)
  - TODO: Show ringing status in campaign and agents monitoring
  - **Status**: COMPLETED in v4.0.0.7 and v4.0.0.9
  - Implemented: `agentstatechange` ECCP event, frontend handlers

- [ ] **Hold Time Tracking** (`campaign_monitoring/index.php:556, 573, 806`)
  - TODO: Track hold pause start time, scheduled call pause, expose times
  - **Status**: OPEN - Partially commented code exists
  - **Recommendation**: Store hold start timestamp, calculate duration
  - **Priority**: MEDIUM (downgraded - UI improvement only)

- [ ] **New Queue Appearance** (`rep_incoming_calls_monitoring/js/javascript.js:106`)
  - TODO: Handle dynamic queue appearance in monitoring
  - **Status**: OPEN - Requires page reload for new queues
  - **Recommendation**: Poll for queue list changes, add to UI dynamically
  - **Priority**: LOW (downgraded - rare scenario)

- [ ] **New Agent in Queue** (`rep_agents_monitoring/js/javascript.js:234`)
  - TODO: Handle agents appearing in new queues dynamically
  - **Status**: OPEN - Similar to new queue issue
  - **Priority**: LOW (downgraded - rare scenario)

- [ ] **Agent Break Events** (`agent_console/js/javascript.js:563, 586`)
  - TODO: Define `agentbreakenter` and `agentbreakexit` events
  - **Status**: OPEN - Would enable real-time break status
  - **Recommendation**: Emit ECCP events on break state changes
  - **Priority**: MEDIUM - Enables better break tracking

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
  - **Recommendation**: Create central config class
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
  - **Recommendation**: Accept as technical debt
  - **Priority**: N/A - Won't fix

---

## Medium Priority

### ECCP Protocol Enhancements

- [ ] **Form Field Length** (`ECCPConn.class.php:986-987`)
  - TODO: Allow specifying input field length
  - **Status**: OPEN - Uses defaults
  - **Priority**: LOW (downgraded - edge case)

- [ ] **Form Value Support** (`ECCPConn.class.php:1008-1012`)
  - TODO: Form support for default value (dated 2011)
  - **Status**: OPEN - Very old TODO
  - **Priority**: LOW (downgraded - 15 years old, not requested)

- [ ] **Database Field Length** (`ECCPConn.class.php:1286-1287`)
  - TODO: Extract max field length from database schema
  - **Status**: OPEN - No dynamic validation
  - **Priority**: LOW (downgraded - edge case)

- [ ] **Client Channel Handling** (`ECCPConn.class.php:2532`)
  - TODO: Handle when clientchannel is defined
  - **Status**: OPEN - Unused path
  - **Priority**: LOW (downgraded - rarely triggered)

- [ ] **Campaign Transfer** (`ECCPConn.class.php:2998`)
  - TODO: Allow sending call to different campaign
  - **Status**: OPEN - Hardcoded to current campaign
  - **Priority**: MEDIUM - Could be useful feature

- [ ] **Agent Attributes** (`ECCPHelper.lib.php:104`)
  - TODO: Expand when arbitrary attributes table available
  - **Status**: OPEN - Limited implementation
  - **Priority**: LOW (downgraded - schema change needed)

### Reporting & Data Display

- [ ] **Contact Formatting** (`agent_console/index.php:961, 1810`)
  - TODO: Proper formatting for contact calls
  - **Status**: OPEN - Raw display
  - **Priority**: LOW (downgraded - cosmetic)

- [~] **Customer Name Display** (`agent_console/index.php:979, 1834`)
  - TODO: Code assumes attribute 1 is customer name
  - **Status**: PARTIALLY ADDRESSED in v4.0.0.7
  - Fixed: Duplicate name display
  - Remaining: Assumption about schema structure
  - **Priority**: LOW (downgraded - acceptable assumption)

- [ ] **Campaign Form Label** (`agent_console/index.php:1279`)
  - TODO: Assign label from campaign form
  - **Status**: OPEN - Empty label
  - **Priority**: LOW (downgraded - cosmetic)

- [ ] **Agent List Dropdown** (`rep_agent_information/index.php:111`)
  - TODO: Replace with dropdown of agents in selected queue
  - **Status**: OPEN - Current implementation works
  - **Priority**: LOW (downgraded - UI improvement)

- [ ] **Error Handling - Calls Per Hour** (`calls_per_hour/index.php:140`)
  - TODO: Handle errors when retrieving calls
  - **Status**: OPEN - No error handling
  - **Priority**: MEDIUM - Reliability

- [ ] **Error Handling - Graphic Calls** (`graphic_calls/index.php:143`)
  - TODO: Handle errors when retrieving calls
  - **Status**: OPEN - No error handling
  - **Priority**: MEDIUM - Reliability

- [ ] **Recording File Convention** (`paloSantoCallsDetail.class.php:462`)
  - TODO: Rename according to campaign convention
  - **Status**: OPEN - Uses basename
  - **Priority**: LOW (downgraded - works as-is)

- [ ] **Configurable Limit** (`paloSantoCallsDetail.class.php:456`)
  - TODO: Make configurable
  - **Status**: OPEN - Hardcoded
  - **Priority**: LOW (downgraded - rarely needed)

### Internationalization

- [ ] **Agent Status i18n** (`rep_agents_monitoring/js/javascript.js:197`)
  - TODO: Internationalize LOGOUT status label
  - **Status**: OPEN - English only
  - **Priority**: MEDIUM - Affects non-English users

- [ ] **Form Placeholder i18n** (`campaign_out/index.php:875`, `campaign_in/index.php:719`)
  - TODO: Internationalize "FORMULARIO" placeholder
  - **Status**: OPEN - Spanish only
  - **Priority**: MEDIUM - Affects non-Spanish users

- [ ] **Report i18n** (`rep_agent_information/index.php:100`)
  - TODO: Internationalize and add to template
  - **Status**: OPEN - Not in template
  - **Priority**: LOW (downgraded - minor issue)

- [ ] **Upload i18n** (`paloSantoUploadFile.class.php:193`)
  - TODO: Internationalize error messages
  - **Status**: OPEN - Spanish only
  - **Priority**: LOW (downgraded - error messages only)

- [ ] **Query Error Display** (`paloSantoConfiguration.class.php:68`)
  - TODO: Determine what to display if query fails
  - **Status**: OPEN - Unclear behavior
  - **Priority**: LOW (downgraded - edge case)

### Queue Management

- [ ] **Queue Status Recovery** (`QueueShadow.class.php:248, 303`)
  - TODO: Determine recovery mechanism
  - **Status**: OPEN - No recovery
  - **Priority**: LOW (downgraded - restart resolves)

- [ ] **Agent Pause Status** (`AMIEventProcess.class.php:3033-3034`)
  - TODO: Handle `$params['Paused']` from QueueMemberStatus
  - **Status**: OPEN - Available but unused
  - **Priority**: MEDIUM - Could improve pause tracking

- [ ] **Follow-up Termination** (`AMIEventProcess.class.php:3065-3069`)
  - TODO: Behavior only adequate under certain conditions
  - **Status**: OPEN - Needs clarification
  - **Priority**: LOW (downgraded - works in practice)

- [ ] **Timeout Countdown** (`AMIEventProcess.class.php:3337-3338`)
  - TODO: Timeout could show countdown timer
  - **Status**: OPEN - Feature request
  - **Priority**: LOW (downgraded - nice-to-have)

### Code Quality

- [ ] **Duplicate Extension Listing** (`paloSantoConsola.class.php:219`)
  - TODO: Duplicates ECCPConn::_listarExtensiones
  - **Status**: OPEN - Code duplication
  - **Priority**: LOW (downgraded - refactoring task)

- [ ] **Campaign Contacts SQL** (`paloSantoIncomingCampaign.class.php:436`)
  - TODO: Add SQL when campaign contacts implemented
  - **Status**: OPEN - Placeholder
  - **Priority**: LOW (downgraded - feature not requested)

- [ ] **ECCP Proxy** (`ECCPProxyConn.class.php:85`)
  - TODO: Future phase implementation
  - **Status**: OPEN - Placeholder
  - **Priority**: LOW (downgraded - future consideration)

### Input Validation

- [ ] **Form Escaping** (`campaign_out/index.php:797`, `campaign_in/index.php:641`)
  - TODO: Validate what needs escaping
  - **Status**: OPEN - Needs security review
  - **Priority**: MEDIUM - Potential XSS

### Monitoring Console

- [ ] **Queue List in Monitoring** (`paloSantoConsola.class.php:946`)
  - TODO: Return queue list in monitoring console
  - **Status**: OPEN - Not implemented
  - **Priority**: LOW (downgraded - feature request)

- [ ] **Monitoring Implementation** (`paloSantoConsola.class.php:940, 943`)
  - TODO: Implement for monitoring console
  - **Status**: OPEN - Placeholder
  - **Priority**: LOW (downgraded - feature request)

---

## Low Priority / Deferred

### Third-Party Libraries - jQuery Layout Plugin

These TODOs are from the third-party jQuery Layout library v1.4.0. **Recommendation**: Either upgrade to a maintained version or replace with a modern alternative. Individual fixes are not recommended.

**Status**: DEFERRED - All 15 items

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

### Third-Party Libraries - Handlebars

- [D] **Handlebars Compilation** (`handlebars-1.3.0.js:375`)
  - TODO: Remove checkRevision, break up compilePartial
  - **Status**: DEFERRED - Use newer Handlebars if needed
  - **Priority**: N/A

---

## Recommended Action Plan

### Immediate (Critical/High)

1. **ECCP TLS Implementation** - Address security vulnerability
2. **Asterisk Restart Detection** - Improve system reliability
3. **Break Time Accumulation** - High user impact
4. **Call Duration Timer** - High user impact

### Short-term (Medium)

1. **Agent Break Events** - Enable better tracking
2. **Configurable Dial Timeout** - Campaign flexibility
3. **Error Handling in Reports** - Reliability
4. **i18n for Status Labels** - User experience

### Long-term (Low/Deferred)

1. **jQuery Layout Replacement** - Modernize frontend
2. **Configuration Consolidation** - Reduce tech debt
3. **ECCP Protocol Enhancements** - Feature additions

---

## Notes

- Many TODOs date back to 2011-2012 (15 years old)
- jQuery Layout library TODOs should not be fixed individually
- Security items should take precedence over features
- Some TODOs represent features that may never be needed
- v4.0.0.7-4.0.0.10 addressed several important issues

---

## References

- Original TODO.md: See same directory
- Change history: See `CHANGES.md`
- Architecture: See `CLAUDE.md`
- Repository: `/usr/share/issabel/repos/callcenter-issabel5`
