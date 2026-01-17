# Call Center Module - Test Scenarios

## Overview

Comprehensive test scenarios for Issabel Call Center module covering:
- Agent login (password-based)
- Callback extension login
- Outgoing campaigns
- Incoming campaigns
- Agent console functionality
- Campaign monitoring

---

## Prerequisites

### Test Environment Setup
1. **Extensions**: Create at least 3 SIP/PJSIP extensions (e.g., 101, 102, 103)
2. **Agents**: Create 2 agents of type "Agent" (e.g., 8001, 8002)
3. **Callback Extensions**: Create 2 callback agents linked to SIP extensions
4. **Queue**: Create at least 1 queue with static/dynamic members
5. **Trunk**: Configure outbound trunk for external calls (or use loopback for testing)
6. **Softphones**: Register extensions on softphones/webRTC clients

---

## Test Scenario 1: Agent Login (Password-Based)

### TC1.1 - Successful Agent Login
| Step | Action | Expected Result |
|------|--------|-----------------|
| 1 | Navigate to Agent Console | Login form displayed |
| 2 | Select Agent (e.g., Agent/8001) | Agent selected in dropdown |
| 3 | Enter correct password | Password field accepts input |
| 4 | Click Login | Phone rings on associated extension |
| 5 | Answer phone | Agent status changes to "Online/Available" |
| 6 | Check Campaign Monitoring | Agent appears as online with green status |

### TC1.2 - Failed Agent Login (Wrong Password)
| Step | Action | Expected Result |
|------|--------|-----------------|
| 1 | Select Agent | Agent selected |
| 2 | Enter wrong password | Password entered |
| 3 | Click Login | Error: "Invalid agent password" |
| 4 | Agent remains logged out | No phone ring, status unchanged |

### TC1.3 - Agent Logout
| Step | Action | Expected Result |
|------|--------|-----------------|
| 1 | With agent logged in, click Logout | Agent phone hangs up |
| 2 | Agent status changes | Status shows "Logged out" |
| 3 | Check Campaign Monitoring | Agent moves to bottom of list (offline) |

---

## Test Scenario 2: Callback Extension Login

### TC2.1 - Successful Callback Login
| Step | Action | Expected Result |
|------|--------|-----------------|
| 1 | Navigate to Agent Console | Login form displayed |
| 2 | Check "Callback" checkbox | Extension/password fields appear |
| 3 | Select callback agent | Agent selected |
| 4 | Enter extension (e.g., 101) | Extension entered |
| 5 | Enter callback password | Password entered |
| 6 | Click Login | Extension 101 rings |
| 7 | Answer phone | Agent logged in, status "Available" |

### TC2.2 - Callback Logout
| Step | Action | Expected Result |
|------|--------|-----------------|
| 1 | With callback agent logged in | Agent online |
| 2 | Click Logout | Agent logged out |
| 3 | Extension phone hangs up | Call terminated |

---

## Test Scenario 3: Outgoing Campaign

### TC3.1 - Create Outgoing Campaign
| Step | Action | Expected Result |
|------|--------|-----------------|
| 1 | Navigate to Campaign Out > New Campaign | Form displayed |
| 2 | Enter campaign name | Name accepted |
| 3 | Select queue | Queue selected |
| 4 | Set trunk | Trunk selected |
| 5 | Set caller ID | CallerID configured |
| 6 | Set schedule (immediate) | Schedule set |
| 7 | Save campaign | Campaign created, status "Inactive" |

### TC3.2 - Upload Contact List
| Step | Action | Expected Result |
|------|--------|-----------------|
| 1 | Go to Campaign Lists | List view displayed |
| 2 | Click "Upload new list" | Upload form shown |
| 3 | Select CSV file with phone numbers | File selected |
| 4 | Map columns (phone required) | Columns mapped |
| 5 | Upload | Contacts imported, count shown |

### TC3.3 - Start Campaign and Receive Calls
| Step | Action | Expected Result |
|------|--------|-----------------|
| 1 | Login agent to queue | Agent available |
| 2 | Activate campaign | Campaign status "Active" |
| 3 | Wait for dialer | Dialer places outbound call |
| 4 | Call connects to customer | Call bridged to agent |
| 5 | Agent console shows call info | Phone number, campaign name displayed |
| 6 | Agent talks and hangs up | Call logged in calls_detail |

### TC3.4 - Campaign Form Filling
| Step | Action | Expected Result |
|------|--------|-----------------|
| 1 | Campaign has form attached | Form fields visible during call |
| 2 | Agent fills form fields | Data entered |
| 3 | Agent clicks "Save" | Form data saved to database |
| 4 | Check form_data_recolected table | Data stored correctly |

### TC3.5 - Pause/Resume Campaign
| Step | Action | Expected Result |
|------|--------|-----------------|
| 1 | With active campaign, click Pause | No new calls placed |
| 2 | Current calls continue | Existing calls not affected |
| 3 | Click Resume | Dialing resumes |

---

## Test Scenario 4: Incoming Campaign

### TC4.1 - Create Incoming Campaign
| Step | Action | Expected Result |
|------|--------|-----------------|
| 1 | Navigate to Campaign In > New Campaign | Form displayed |
| 2 | Enter campaign name | Name accepted |
| 3 | Select queue | Queue selected |
| 4 | Attach form (optional) | Form selected |
| 5 | Save | Campaign created |

### TC4.2 - Receive Incoming Call
| Step | Action | Expected Result |
|------|--------|-----------------|
| 1 | Agent logged into queue | Agent available |
| 2 | External call to queue DID | Call enters queue |
| 3 | Call routes to agent | Agent phone rings |
| 4 | Agent answers | Call connected |
| 5 | Agent console shows caller info | CallerID displayed |
| 6 | Call ends | Logged in call_entry table |

---

## Test Scenario 5: Agent Console Features

### TC5.1 - Hold/Unhold
| Step | Action | Expected Result |
|------|--------|-----------------|
| 1 | During active call, click Hold | Customer hears MOH |
| 2 | Agent status shows "On Hold" | Status updated |
| 3 | Click Unhold | Call resumed |

### TC5.2 - Transfer (Blind)
| Step | Action | Expected Result |
|------|--------|-----------------|
| 1 | During call, click Transfer | Transfer dialog appears |
| 2 | Enter destination extension | Extension entered |
| 3 | Click Transfer | Call transferred, agent freed |

### TC5.3 - Transfer (Attended)
| Step | Action | Expected Result |
|------|--------|-----------------|
| 1 | During call, click Attended Transfer | Agent calls destination |
| 2 | Speak with destination | Two calls active |
| 3 | Complete transfer | Original caller connected to destination |

### TC5.4 - Conference
| Step | Action | Expected Result |
|------|--------|-----------------|
| 1 | During call, click Conference | Conference dialog appears |
| 2 | Add third party | Three-way call established |

### TC5.5 - Break/Pause
| Step | Action | Expected Result |
|------|--------|-----------------|
| 1 | Agent available, click Break | Break selection appears |
| 2 | Select break type | Agent paused in queue |
| 3 | Status shows break reason | "Break - Lunch" etc. |
| 4 | Click End Break | Agent available again |

### TC5.6 - Hangup
| Step | Action | Expected Result |
|------|--------|-----------------|
| 1 | During call, click Hangup | Call terminated |
| 2 | Agent status returns to Available | Ready for next call |

---

## Test Scenario 6: Campaign Monitoring

### TC6.1 - Real-time Agent Status
| Step | Action | Expected Result |
|------|--------|-----------------|
| 1 | Open Campaign Monitoring | Dashboard displayed |
| 2 | Agent logs in | Agent appears online (green) |
| 3 | Agent receives call | Status changes to "On call" |
| 4 | Agent goes on break | Status shows break type |
| 5 | Agent logs out | Agent moves to bottom (gray) |

### TC6.2 - Agent Sorting
| Step | Action | Expected Result |
|------|--------|-----------------|
| 1 | Multiple agents logged in | Online agents at top |
| 2 | Some agents offline | Offline agents at bottom |
| 3 | Agent logs out | Moves to bottom automatically |
| 4 | Agent logs in | Moves to top automatically |

### TC6.3 - Campaign Statistics
| Step | Action | Expected Result |
|------|--------|-----------------|
| 1 | Select active campaign | Stats displayed |
| 2 | Check calls placed | Count accurate |
| 3 | Check calls answered | Count accurate |
| 4 | Check pending calls | Remaining contacts shown |

---

## Test Scenario 7: Dialer Service

### TC7.1 - Service Start
| Step | Action | Expected Result |
|------|--------|-----------------|
| 1 | `systemctl start issabeldialer` | Service starts |
| 2 | Check status | Active (running) |
| 3 | Check log `/opt/issabel/dialer/dialerd.log` | No errors |

### TC7.2 - Service Stop (Graceful Logout)
| Step | Action | Expected Result |
|------|--------|-----------------|
| 1 | Agents logged in | Agents active |
| 2 | `systemctl stop issabeldialer` | Service stopping |
| 3 | Agents logged out automatically | All agents disconnected |
| 4 | No orphan calls | Active calls handled |

### TC7.3 - Service Restart
| Step | Action | Expected Result |
|------|--------|-----------------|
| 1 | `systemctl restart issabeldialer` | Service restarts |
| 2 | Agents need to re-login | Expected behavior |
| 3 | Campaigns resume | Dialing continues |

---

## Test Scenario 8: Reports Verification

### TC8.1 - Calls Detail Report
| Step | Action | Expected Result |
|------|--------|-----------------|
| 1 | Navigate to Calls Detail | Report displayed |
| 2 | Filter by date range | Calls filtered |
| 3 | Verify call records | Matches actual calls made |

### TC8.2 - Agent Login/Logout Report
| Step | Action | Expected Result |
|------|--------|-----------------|
| 1 | Navigate to Login/Logout | Report displayed |
| 2 | Check agent sessions | Login/logout times accurate |
| 3 | Check break times | Break durations recorded |

### TC8.3 - Calls Per Agent Report
| Step | Action | Expected Result |
|------|--------|-----------------|
| 1 | Navigate to Calls Per Agent | Report displayed |
| 2 | Check call counts | Per-agent totals accurate |

---

## Test Scenario 9: Agent Options Session Control

### TC9 - Call Center - Agent Options
| Step | Action | Expected Result |
|------|--------|-----------------|
| 1 | Login with Agent | Logging successfully |
| 2 | Login with SIP cb extension | Logging successfully |
| 3 | Login with PJSIP cb extension | Logging successfully |
| 4 | Disconnect them one by one | Should be disconnected succesfully without orphand calls |

---

## Test Data

### Sample CSV for Outgoing Campaign
```csv
phone,name,company
5551234567,John Doe,Acme Inc
5559876543,Jane Smith,Tech Corp
5555551212,Bob Wilson,Sales Ltd
```

### Test Credentials
| Type | ID | Password |
|------|-----|----------|
| Agent | 8001 | 1234 |
| Agent | 8002 | 5678 |
| Callback | cb_101 | callback123 |

---

## Pass/Fail Criteria

- **Pass**: All steps complete with expected results
- **Fail**: Any step produces unexpected result or error
- **Blocked**: Prerequisites not met

---

## Notes

- Test during low-traffic periods
- Clear test data after testing
- Document any deviations from expected results
- Screenshot errors for bug reports
