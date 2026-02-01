# TODO - Call Center Issabel 5

This document tracks all TODO and FIXME items found in the callcenter-issabel5 repository, organized by category and priority.

Last Updated: 2026-01-29

## Summary

- **Total TODOs**: ~75 items
- **Critical**: 8 items
- **High Priority**: 15 items
- **Medium Priority**: 25 items
- **Low Priority**: 27 items

---

## Critical / High Priority

### Security & Authentication
- [ ] **ECCP Authentication Security** (`setup/dialer_process/dialer/ECCPConn.class.php:301`)
  - FIXME: Clarify whether sending password hash is more secure than plaintext in unencrypted connection
  - Both can be sniffed, need to implement proper encryption (TLS/SSL)
  - Status: Currently accepts both hashed and plaintext passwords

- [ ] **ECCP Client Authorization** (`setup/dialer_process/dialer/ECCPConn.class.php:305`)
  - TODO: Implement agent authorization storage in `eccp_authorized_clients` table
  - Restrict which agents can login through which ECCP clients
  - Status: Not implemented

### Agent Console Core Functionality
- [ ] **Break Time Accumulation** (`modules/agent_console/index.php:701-702`)
  - TODO: Display accumulated break time since session start
  - TODO: Implement session variable tracking break start/end times
  - Status: Not implemented, affects agent accuracy metrics

- [ ] **Call Duration Tracking** (`modules/agent_console/index.php:767`)
  - TODO: Display elapsed time in current call
  - Status: Not implemented

- [ ] **Login Validation** (`modules/agent_console/libs/paloSantoConsola.class.php:375`)
  - TODO: Return mismatch if agent successfully logs in to wrong console
  - Prevents agents from logging into incorrect console
  - Status: Not implemented

### Dialer Reliability
- [ ] **Asterisk Restart Detection** (`setup/dialer_process/dialer/CampaignProcess.class.php:209`)
  - TODO: Detect Asterisk restart and re-synchronize all dialer state
  - Current behavior: May continue with stale state information
  - Status: Not implemented

- [ ] **Agent Conflict Detection** (`setup/dialer_process/dialer/CampaignProcess.class.php:537`)
  - TODO: Implement code to detect agent conflicts
  - Prevents double-booking of agents across campaigns
  - Status: Not implemented

- [ ] **Park Call Handling** (`setup/dialer_process/dialer/SQLWorkerProcess.class.php:884`)
  - TODO: Determine what happens with parked calls
  - Status: Unclear, may cause data inconsistencies

---

## High Priority

### Monitoring & Real-time Updates
- [ ] **Hold Time Tracking** (`modules/campaign_monitoring/index.php:556, 573, 806`)
  - TODO: Track when hold pause starts
  - TODO: Implement scheduled call pause handling
  - TODO: Expose scheduled appointment pause time
  - Status: Partially implemented

- [ ] **New Queue Appearance** (`modules/rep_incoming_calls_monitoring/themes/default/js/javascript.js:106`)
  - TODO: Handle appearance of new queues in monitoring
  - Status: Not implemented

- [ ] **New Agent in Queue** (`modules/rep_agents_monitoring/themes/default/js/javascript.js:234`)
  - TODO: Handle appearance of agents in new queues
  - Status: Not implemented

- [ ] **Agent Break Events** (`modules/agent_console/themes/default/js/javascript.js:563, 586`)
  - TODO: Define agentbreakenter and agentbreakexit events
  - Would enable real-time break status updates
  - Status: Not implemented, relies on polling

### Campaign Management
- [ ] **Campaign Scheduling** (`setup/dialer_process/dialer/AMIEventProcess.class.php:572`)
  - TODO: Purge outgoing campaigns outside scheduled hours
  - Status: Not implemented

- [ ] **Configurable Timeouts** (`setup/dialer_process/dialer/CampaignProcess.class.php:915`)
  - TODO: Make timeout value (currently 30) configurable per campaign or in DB
  - Status: Hardcoded to 30 seconds

- [ ] **Auto-calculation Window** (`setup/dialer_process/dialer/CampaignProcess.class.php:580`)
  - TODO: Auto-calculate history window (currently 30 minutes hardcoded)
  - Status: Hardcoded value

### Configuration Management
- [ ] **Configuration Consolidation** (`setup/dialer_process/dialer/ECCPConn.class.php:1381, 1384`)
  - TODO: Find elegant way to have single configuration definition
  - TODO: Move `/etc/amportal.conf` path to configuration
  - Status: Duplicated code, hardcoded paths

- [ ] **Monitoring Prefix** (`setup/dialer_process/dialer/SQLWorkerProcess.class.php:831`)
  - TODO: Configure monitoring prefix
  - Status: Not implemented

### Database & ORM
- [ ] **Incremental Retries** (`setup/dialer_process/dialer/SQLWorkerProcess.class.php:674`)
  - TODO: Review if inc_retries is necessary for campaigns
  - Status: Unclear if needed

- [ ] **Call Info Duplication** (`setup/dialer_process/dialer/ECCPConn.class.php:2455`)
  - FIXME: Compatibility requires mixing callinfo and agent fields
  - Status: Workaround in place

---

## Medium Priority

### ECCP Protocol Enhancements
- [ ] **Form Field Length** (`setup/dialer_process/dialer/ECCPConn.class.php:904`)
  - TODO: Allow specifying input field length
  - Status: Uses default values

- [ ] **Form Value Support** (`setup/dialer_process/dialer/ECCPConn.class.php:922`)
  - TODO: Support form for value per something (dated 2011-02-02)
  - Status: Not implemented

- [ ] **Database Field Length** (`setup/dialer_process/dialer/ECCPConn.class.php:1181`)
  - TODO: Extract maximum field length from database schema
  - Status: Not implemented

- [ ] **Client Channel Handling** (`setup/dialer_process/dialer/ECCPConn.class.php:2425`)
  - TODO: Handle case where clientchannel is defined (identical to actualchannel)
  - Status: Not implemented

- [ ] **Campaign Transfer** (`setup/dialer_process/dialer/ECCPConn.class.php:2891`)
  - TODO: Allow sending call to different campaign
  - Status: Hardcoded to current campaign

- [ ] **Agent Attributes** (`setup/dialer_process/dialer/ECCPHelper.lib.php:104`)
  - TODO: Expand when arbitrary attributes table is available
  - Status: Limited implementation

### Reporting & Data Display
- [ ] **Contact Formatting** (`modules/agent_console/index.php:961, 1810`)
  - TODO: Proper formatting for contact calls
  - Status: Not implemented

- [ ] **Customer Name Display** (`modules/agent_console/index.php:979, 1834`)
  - TODO: Code assumes attribute 1 is customer name
  - TODO: Design method to format customer name properly
  - Status: Makes assumption about schema

- [ ] **Campaign Form Label** (`modules/agent_console/index.php:1279`)
  - TODO: Assign label from campaign form
  - Status: Empty label

- [ ] **Agent List Dropdown** (`modules/rep_agent_information/index.php:111`)
  - TODO: Replace with dropdown of agents in selected queue
  - Status: Not implemented

- [ ] **Error Handling** (`modules/calls_per_hour/index.php:140`)
  - TODO: Handle errors when retrieving calls
  - Status: No error handling

- [ ] **Error Handling** (`modules/graphic_calls/index.php:143`)
  - TODO: Handle errors when retrieving calls
  - Status: No error handling

- [ ] **Recording File Convention** (`modules/calls_detail/libs/paloSantoCallsDetail.class.php:462`)
  - TODO: Rename according to campaign convention
  - Status: Uses basename

- [ ] **Configurable Limit** (`modules/calls_detail/libs/paloSantoCallsDetail.class.php:456`)
  - TODO: Make configurable
  - Status: Hardcoded value

### Internationalization
- [ ] **Agent Console i18n** (`modules/rep_agents_monitoring/themes/default/js/javascript.js:197`)
  - TODO: Internationalize LOGOUT status label
  - Status: English only

- [ ] **Form Placeholder i18n** (`modules/campaign_out/index.php:875`)
  - TODO: Internationalize "FORMULARIO" placeholder
  - Status: Spanish only

- [ ] **Form Placeholder i18n** (`modules/campaign_in/index.php:719`)
  - TODO: Internationalize "FORMULARIO" placeholder
  - Status: Spanish only

- [ ] **Report i18n** (`modules/rep_agent_information/index.php:100`)
  - TODO: Internationalize and add to template
  - Status: Not in template

- [ ] **Upload i18n** (`modules/client/libs/paloSantoUploadFile.class.php:193`)
  - TODO: Internationalize error messages
  - Status: Spanish only

- [ ] **Query Error Display** (`modules/callcenter_config/libs/paloSantoConfiguration.class.php:68`)
  - TODO: Determine what to display if query fails
  - Status: Unclear

### Queue Management
- [ ] **Queue Status Recovery** (`setup/dialer_process/dialer/QueueShadow.class.php:248, 303`)
  - TODO: Determine what can be done for queue status recovery
  - Status: Not implemented

- [ ] **Agent Pause Status** (`setup/dialer_process/dialer/AMIEventProcess.class.php:2879`)
  - TODO: Handle $params['Paused'] which indicates if agent is paused
  - Status: Not implemented

- [ ] **Follow-up Behavior** (`setup/dialer_process/dialer/AMIEventProcess.class.php:2910`)
  - TODO: Follow-up termination behavior only adequate if certain conditions
  - Status: Needs clarification

- [ ] **Timeout Display** (`setup/dialer_process/dialer/AMIEventProcess.class.php:3178`)
  - TODO: Timeout could be used to show countdown timer
  - Status: Not implemented

### Code Quality & Duplication
- [ ] **Duplicate Extension Listing** (`modules/agent_console/libs/paloSantoConsola.class.php:219`)
  - TODO: Duplicates ECCPConn::_listarExtensiones in dialer
  - Status: Code duplication

- [ ] **Campaign Contacts** (`modules/campaign_in/libs/paloSantoIncomingCampaign.class.php:436`)
  - TODO: Add SQL when campaign contacts are implemented
  - Status: Placeholder

- [ ] **ECCP Proxy** (`setup/dialer_process/dialer/ECCPProxyConn.class.php:85`)
  - TODO: Future phase implementation needed
  - Status: Placeholder

---

## Low Priority

### Third-Party Libraries (jQuery Layout Plugin)

The following TODOs are from the third-party jQuery Layout library (`modules/agent_console/themes/default/js/jquery.layout-1.4.0.js`). These should be addressed by upgrading to a maintained version or replacing with a modern alternative:

- jQuery 2.x compatibility (line 28)
- Method usability improvements (lines 1241-1242)
- State management plugin integration (line 1673)
- iframe fix consideration (line 2667)
- Drag cancellation handling (line 2678)
- jQuery bug workaround cleanup (line 3105)
- onshow callback placement (line 3637)
- sizePane race condition (line 3645)
- Trigger event unbind necessity (line 3710)
- iframe width CSS question (line 4269)
- outerWidth setter method (line 4270)
- North pane assumption (line 4519)
- Toggler position verification (line 4565)
- Button unbinding (line 5866)
- Browser zoom handling (lines 5894-5895)

### Third-Party Libraries (Handlebars)

- [ ] **Handlebars Compilation** (`modules/campaign_monitoring/themes/default/js/handlebars-1.3.0.js:375`)
  - TODO: Remove checkRevision line and break up compilePartial
  - Status: Third-party library issue

### Input Validation

- [ ] **Form Escaping Validation** (`modules/campaign_out/index.php:797`)
  - TODO: Validate this function to determine what needs escaping
  - Status: Needs review

- [ ] **Form Escaping Validation** (`modules/campaign_in/index.php:641`)
  - TODO: Validate this function to determine what needs escaping
  - Status: Needs review

### Monitoring Console Features

- [ ] **Monitoring Queue List** (`modules/agent_console/libs/paloSantoConsola.class.php:946`)
  - TODO: Return queue list in monitoring console
  - Status: Not implemented

- [ ] **Monitoring Implementation** (`modules/agent_console/libs/paloSantoConsola.class.php:940, 943`)
  - TODO: Implement for monitoring console (2 instances)
  - Status: Not implemented

---

## Next Steps

1. Address Critical security issues (ECCP authentication)
2. Implement core agent console functionality (break time, call duration)
3. Add reliability features (Asterisk restart detection, agent conflict detection)
4. Improve monitoring real-time capabilities
5. Work on internationalization for wider adoption
6. Consider replacing outdated third-party libraries

## Notes

- Many TODOs date back to 2011-2012, indicating technical debt
- Some TODOs are placeholders for features that may never be implemented
- Consider prioritizing based on actual user impact and security implications
- Some TODOs may be obsolete due to subsequent changes in the codebase

---

## References

- Main repository: `/usr/share/issabel/repos/callcenter-issabel5`
- Installation location: `/opt/issabel/dialer/` and `/var/www/html/modules/`
- Documentation: See `CLAUDE.md` for architecture and development notes
- Change history: See `CHANGES.md` for recent modifications
