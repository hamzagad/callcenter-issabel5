# ECCP Protocol v0.1
**Revision 5 | Status: Beta**

XML-based protocol for communication between external applications and the Issabel Call Center engine via TCP port **20005**.

---

## Table of Contents
1. [Architecture](#architecture)
2. [Sessions](#sessions)
3. [Packet Types](#packet-types)
4. [Authentication](#authentication)
5. [Error Codes](#error-codes)
6. [Agent Types](#agent-types)
7. [Events](#events)
8. [Requests](#requests)

---

## Architecture

```
Server (dialerd) <--TCP:20005--> Client (Agent Console)
```

**Concurrency Server**: An intermediary application can manage multiple agents through a single session, reducing server load.

---

## Sessions

- Session established after successful authentication
- Inactivity timeout: **5 minutes**
- Authorized IPs only (others get "Connection refused")

---

## Packet Types

### Event
```xml
<event>
    <event_name>...</event_name>
</event>
```

### Request
```xml
<request id="timestamp.random">
    <request_name>...</request_name>
</request>
```

### Response
```xml
<response id="request_id">
    <request_name_response>...</request_name_response>
</response>
```

---

## Authentication

Requests requiring agent authentication use:
- `agent_number`: Agent identifier (e.g., `Agent/9000`)
- `agent_hash`: `MD5(app_cookie + agent_number + agent_password)`

The `app_cookie` is returned by the `login` request.

---

## Error Codes

| Code | Meaning |
|------|---------|
| 400 | Bad request |
| 401 | Unauthorized |
| 404 | Not found |
| 417 | Condition failed |
| 500 | Internal error |
| 501 | Not implemented |

```xml
<response id="X">
    <failure>
        <code>XXX</code>
        <message>Error message</message>
    </failure>
</response>
```

---

## Agent Types

| Type | Channel Format | Queue Member | Login Method |
|------|---------------|--------------|--------------|
| Agent (static) | `Agent/9000` | `Local/9000@agents` | AgentLogin() via `agent-login` context |
| SIP (dynamic) | `SIP/1001` | `SIP/1001` | QueueAdd |
| PJSIP (dynamic) | `PJSIP/1001` | `PJSIP/1001` | QueueAdd |
| IAX2 (dynamic) | `IAX2/1001` | `IAX2/1001` | QueueAdd |

**Note**: Agent type uses **app_agent_pool** (Asterisk 12+). Agents defined in `/etc/asterisk/agents.conf` with template inheritance.

---

## Events

### agentlinked
Agent connected to a call.

| Field | Description |
|-------|-------------|
| agent_number | `Agent/9000` |
| remote_channel | Call channel |
| calltype | `incoming` / `outgoing` |
| campaign_id | Campaign ID |
| call_id | Call ID |
| phone | Phone number / Caller-ID |
| status | `Success` / `activa` |
| uniqueid | Asterisk unique ID |
| datetime_join | Queue entry time |
| datetime_linkstart | Link time |
| trunk | Trunk used |
| queue | Queue (incoming only) |
| call_attributes | List of attributes |
| matching_contacts | Matching contacts (incoming) |
| call_survey | Form data collected |

### agentunlinked
Agent disconnected from call.

| Field | Description |
|-------|-------------|
| agent_number | Agent ID |
| calltype | `incoming` / `outgoing` |
| call_id | Call ID |
| datetime_linkend | Disconnect time |
| duration | Call duration (seconds) |
| shortcall | 1 if short call |

### agentloggedin
Agent logged into system.
```xml
<event><agentloggedin><agent>Agent/9000</agent></agentloggedin></event>
```

### agentloggedout
Agent logged out of system.

### agentfailedlogin
Agent login attempt failed.

### pausestart
Agent entered pause/break.

| Field | Description |
|-------|-------------|
| agent_number | Agent ID |
| pause_class | `break` / `hold` |
| pause_type | Break ID |
| pause_name | Break name |
| pause_start | Start time |

### pauseend
Agent exited pause/break. Includes `pause_end` and `pause_duration`.

### callprogress
Call state transition (requires enabling via `callprogress` request).

| new_status | Description |
|------------|-------------|
| Placing | Call being placed |
| Dialing | Dialing in progress |
| Ringing | Call ringing |
| OnQueue | Call in queue |
| Failure | Call failed |
| OnHold | Call on hold |
| OffHold | Call off hold |

### queuemembership
Agent's queue membership changed.

---

## Requests

### Session Management

#### login
```xml
<request id="1">
    <login>
        <username>agentconsole</username>
        <password>agentconsole</password>
    </login>
</request>
```
Response includes `app_cookie` for authentication.

#### logout
Terminates session and closes TCP connection.

#### filterbyagent
Filter events to specific agent only.
```xml
<filterbyagent><agent_number>Agent/9000</agent_number></filterbyagent>
```
Use `any` to receive all events.

---

### Agent Operations

#### loginagent
Initiates agent login to queue.

| Argument | Required | Description |
|----------|----------|-------------|
| agent_number | Yes | `Agent/9000` |
| agent_hash | Yes | Auth hash |
| extension | Yes | Extension to ring |
| timeout | No | Inactivity timeout (sec) |

Response status: `logging`, `logged-in`, `logged-out`

#### logoutagent
Logs agent out of queues.

#### getagentstatus
Get agent's current status.

| Response Field | Description |
|----------------|-------------|
| status | `offline`, `online`, `oncall`, `paused` |
| channel | Login channel |
| extension | Login extension |
| onhold | 1 if on hold |
| pauseinfo | Pause details (if paused) |
| callinfo | Call details (if on call) |

#### getmultipleagentstatus
Query multiple agents at once.

#### pauseagent
Put agent on break.
```xml
<pauseagent>
    <agent_number>Agent/9000</agent_number>
    <agent_hash>XXX</agent_hash>
    <pause_type>2</pause_type>
</pauseagent>
```

#### unpauseagent
Remove agent from break.

#### pingagent
Keep agent session alive (when using timeout).

---

### Call Operations

#### hangup
Hang up agent's current call.

#### hold
Place current call on hold.

#### unhold
Remove call from hold.

#### transfercall
Blind transfer to extension.
```xml
<transfercall>
    <agent_number>Agent/9000</agent_number>
    <agent_hash>XXX</agent_hash>
    <extension>1001</extension>
</transfercall>
```

#### atxfercall
Attended transfer to extension.

#### schedulecall
Schedule callback.

| Argument | Description |
|----------|-------------|
| schedule | `yyyy-mm-dd hh:mm:ss` |
| phone | Number to call |
| sameagent | 1 = same agent, 0 = any |
| newphone | New number (optional) |

---

### Campaign Operations

#### getcampaignlist
List campaigns with optional filters.

| Filter | Values |
|--------|--------|
| campaign_type | `incoming`, `outgoing` |
| status | `active`, `inactive`, `finished` |

#### getcampaigninfo
Get static campaign information (name, dates, queue, forms, script).

#### getcampaignstatus
Get dynamic campaign status.

| Response Section | Description |
|------------------|-------------|
| statuscount | Call counts by status |
| agents | Agent list with status |
| activecalls | Unassigned active calls |
| stats | total_sec, max_duration |

**statuscount fields:**
- `total`, `pending`, `placing`, `ringing`, `onqueue`
- `success`, `onhold`, `failure`, `shortcall`
- `noanswer`, `abandoned`, `finished`, `losttrack`

#### getcampaignqueuewait
Queue wait time histogram (5-second intervals).

---

### Call Information

#### getcallinfo
Get details about a specific call.

#### setcontact
Associate contact with incoming call (when multiple match Caller-ID).

#### saveformdata
Save form data for a call.
```xml
<saveformdata>
    <campaign_type>outgoing</campaign_type>
    <call_id>25</call_id>
    <forms>
        <form id="1">
            <field id="1">value1</field>
            <field id="2">value2</field>
        </form>
    </forms>
</saveformdata>
```

#### getchanvars
Get Asterisk channel variables for current call.

---

### Queue Operations

#### getqueuescript
Get script text for incoming queue.

#### getagentqueues
Get queues an agent belongs to.

#### getmultipleagentqueues
Get queue membership for multiple agents.

#### getincomingqueuelist
List all incoming queues.

#### getincomingqueuestatus
Get status of incoming queues.

---

### Monitoring

#### callprogress (request)
Enable/disable callprogress events.
```xml
<callprogress><enable>1</enable></callprogress>
```

#### campaignlog
Get campaign activity log.

| Argument | Description |
|----------|-------------|
| campaign_type | `incoming`, `outgoing` |
| campaign_id | Campaign ID |
| datetime_start | Start filter |
| datetime_end | End filter |
| lastN | Last N entries |

Log entry types: `Placing`, `Dialing`, `Ringing`, `OnQueue`, `Success`, `Hangup`, `Failure`, `Abandoned`, `ShortCall`, `NoAnswer`, `OnHold`, `OffHold`

---

### Other

#### getrequestlist
List all available requests.

#### getpauses
List all break/pause types.

#### getagentactivitysummary
Get agent activity summary for date range.

---

## app_agent_pool Notes (Asterisk 12+)

The Agent type uses **app_agent_pool** instead of deprecated **chan_agent**:

- Agents defined in `/etc/asterisk/agents.conf`:
  ```ini
  [agent-defaults](!)
  musiconhold=Silence
  ackcall=no
  autologoff=0
  wrapuptime=0

  [1001](agent-defaults)
  fullname=Agent Name
  ```

- Queue members: `Local/XXXX@agents` with `StateInterface=Agent:XXXX`
- Login context: `agent-login` (runs `AgentLogin()`)
- Call delivery context: `agents` (runs `AgentRequest()`)
- Logout: Hangup login channel (no `Agentlogoff` AMI command)
- QueuePause: Uses `Local/XXXX@agents` interface

---

*Document translated from Spanish original with app_agent_pool modifications for Asterisk 12+*
