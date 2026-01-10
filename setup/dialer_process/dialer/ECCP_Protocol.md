# ECCP Protocol v0.1

**Revision:** 5
**Status:** Beta

---

## Objective

The ECCP protocol is an XML-based protocol designed to provide a communications API through a TCP port, allowing external applications to communicate with the Issabel call center engine.

The lack of a communication mechanism between the call center engine and client applications that allows transmission of asynchronous events was one of the motivations for creating ECCP. Before ECCP, a client application had to send periodic requests to the server to learn about asynchronous events.

Additionally, standardization of the communications protocol with the call center engine was necessary to allow scalability and organization.

---

## Conventions Used in This Document

Throughout most of this document, when referring to "attributes" and "elements", these terms relate to their definition within the XML language.

When the word "server" is mentioned alone, it refers to the call center server that comes with the Issabel call center module. This program is also called "dialerd".

All dates are in `yyyy-mm-dd` format and all times are in `hh:mm:ss` format. The timezone used is always the one configured on the server running the dialerd program.

The word 'CallCenter' (case-sensitive) refers to the Issabel CallCenter module.

---

## Architecture

```
-------------      -----     ---------------------------
|           |     /     \    |                         |
| Server    |-----  Net  ----| Client                  |
| (dialerd) |     \     /    | (e.g., Agent Console)   |
|           |      -----     |                         |
-------------                ---------------------------
```

---

## TCP/IP Communications

Communication uses TCP on port **20005**.

---

## Sessions

The ECCP protocol is session-oriented, with the ability to support multiple communication sessions, thus serving several client applications simultaneously.

A session is established after the client application has successfully authenticated. The session remains active until the client application terminates it or an inactivity timeout occurs.

The inactivity timeout is **5 minutes**, after which the server terminates the session.

---

## Separation Between Client and Agent

The server communicates with client applications, and it is the client application that manages agent entry into the system. A client application can manage one or more agents.

This opens the door to two scenarios explained below.

### Scenario 1: Agent consoles connecting directly to the server

Here each agent console manages the entry of a single agent. For the server, each agent console is seen as a client application.

```
                         --------------------------------
                /--------| Agent console (agent 1)      |
               /         --------------------------------
------------  /          --------------------------------
| Server   |------------ | Agent console (agent 2)      |
------------  \          --------------------------------
               \         --------------------------------
                \--------| Agent console (agent 3)      |
                         --------------------------------
```

In the example above, the server has started 3 sessions, one for each client application (each agent console). This scheme is the simplest to implement.

### Scenario 2: Agent consoles connecting through an intermediary client application

Here the intermediary client application manages the entry of multiple agents. In this scenario, from the server's perspective, the intermediary application is seen as a client application, while the agent consoles are not.

```
                                                --------------------------------
                                        /-------| Agent console (agent 1)      |
                                       /        --------------------------------
-----------   ----------------------  /         --------------------------------
| Server  |--| Intermediary App.   |----------- | Agent console (agent 2)      |
-----------   ----------------------  \         --------------------------------
                                       \        --------------------------------
                                        \-------| Agent console (agent 3)      |
                                                --------------------------------
```

In the example above, the server has started **ONE SESSION** instead of three.

We refer to this intermediary application as a *concurrency server*. A concurrency server is useful in several ways. One is that it frees resources from the Issabel server, as the concurrency server can be installed on a remote machine.

The concurrency server can also serve as a cache server to decongest requests to the Issabel server. For example, it could cache campaign information.

---

## Security Considerations

To maintain control over client applications connecting to the server, the server maintains a registry of authorized IPs. If a client attempts to connect from an unauthorized IP address, the server simply closes the connection with a "Connection refused" message and writes an error message to the respective log.

---

## Packet Types

There are three types of packets in the ECCP protocol:
- **Event**
- **Request**
- **Response**

Each packet is represented as a well-formed XML document, whose tags are defined below.

### Event

An event is generated by an asynchronous occurrence on the Server.

```xml
<event>
...
</event>
```

**IMPORTANT:** For future protocol extension, additional events may be defined beyond those described in this document, as well as additional tags and attributes in defined events. An ECCP client MUST IGNORE any event it doesn't know how to handle. Similarly, the client MUST IGNORE any tag or attribute it doesn't know how to handle in received events.

### Request

A request is a message sent by the client application to the server. The server will respond with the appropriate response.

The request includes an attribute called `id`. This id is a character string used to identify the response. This minimizes the possibility of problems caused by race conditions. The identifier consists of two parts joined by a period: the first part is the UNIX timestamp and the second part is a random 6-character integer.

Example identifier: `1292899827.394772`

```xml
<request id="1292899827.394772">
...
</request>
```

### Response

A request can produce one or more responses. Responses are related to requests through the request identifier.

```xml
<response id="identifier">
...
</response>
```

**IMPORTANT:** For future protocol extension, additional tags and attributes may be defined in responses. The ECCP client MUST IGNORE any tag or attribute it doesn't know how to handle in received responses.

---

## Request Authentication

Some requests listed in this manual order updates to the system state that should only be authorized for a particular agent. When a request requires authentication, the method used is:

1. Each agent has an assigned password known by the server.
2. The "login" request, if successful, returns random text as content of the `app_cookie` tag. This text is called the cookie.
3. Every request that must be authenticated must have two mandatory arguments: `agent_number` and `agent_hash`.
4. The `agent_number` parameter is the agent name, like `Agent/9000`.
5. The `agent_hash` parameter is a value resulting from applying MD5 hash to the concatenation of the cookie text, the agent name, and the agent password, in this order.

Example for agent `Agent/9000` with password `secretkey`:
```
s = cookie + "Agent/9000" + "secretkey"
agent_hash = MD5(s)
```

---

## Error Messages

Requests defined in this document can report errors as follows:

```xml
<response id="identifier">
  <failure>
    <code>XXX</code>
    <message>Some message</message>
  </failure>
</response>
```

Or:

```xml
<response id="identifier">
  <request_response>
    <failure>
      <code>XXX</code>
      <message>Some message</message>
    </failure>
  </request_response>
</response>
```

The difference is that the first method indicates format or protocol errors occurring before specific request processing, while the second method indicates an error within specific request processing.

### Common Error Codes

| Code | Meaning |
|------|---------|
| 400 | Bad request |
| 401 | Unauthorized |
| 404 | Object not found |
| 417 | Expected condition fails |
| 500 | Internal program error |
| 501 | Not implemented |

---

## CallCenter Operating Model

Call assignment in CallCenter is implemented through Asterisk queues. Each queue is identified by a number and has two types of actors: agents and calls.

### Agent Types

Agents can be **dynamic** or **static**:

- **Dynamic agents** can log in or out of the queue arbitrarily and are almost always real extensions. Their channels are of type `SIP/4321`, `PJSIP/4321`, or `IAX2/4321`.

- **Static agents** (Agent type) always belong to the queue but can be in an unavailable state. For CallCenter operation, static agents use channels of type `Agent/9000`. In Asterisk 12+, these agents use the **app_agent_pool** module, with queue members registered as `Local/XXXX@agents` with `StateInterface=Agent:XXXX`.

Both static and dynamic agents can belong to multiple queues.

### Agent Login Process

**For Static Agents (Agent type):**
1. The extension dials the agent login number
2. A password prompt is played
3. After correct password entry, the agent is logged into the queue
4. The extension plays hold music until a call is assigned
5. To end the session, hangup the extension or use the Hangup AMI command on the login channel

**For Dynamic Agents (SIP/PJSIP/IAX2):**
1. A QueueAdd is executed for each queue of interest
2. No password is required internally
3. When added to the queue, the extension remains on-hook until a call is assigned
4. To end the session, execute QueueRemove via AMI

### Campaign Types

CallCenter supports two main operating modes:

- **Outgoing campaigns**: Configure a new campaign with a list of phone numbers to dial. The system dials each number, associating the queue where agents wait.

- **Incoming campaigns**: Configure the central and dial plans so external calls terminate in a queue reserved for incoming campaigns.

---

## Events List

### Event "agentlinked"

Generated when an agent has been linked with a call that entered the queue. Internally, the Link/Bridge event has been received for the call.

**Informational elements:**
- `agent_number`: Agent linked with the call (format: `Agent/9000`)
- `remote_channel`: Channel representing the linked call
- `call_type`: `incoming` or `outgoing`
- `campaign_id`: Campaign database ID (required for outgoing, optional for incoming)
- `call_id`: Call database ID
- `phone`: Number dialed or Caller-ID received
- `status`: Call status (`Success` for outgoing, `activa` for incoming)
- `uniqueid`: Internal Asterisk ID for the remote call
- `datetime_originate`: (Outgoing only) Date/time when dialing started
- `datetime_originateresponse`: (Outgoing only) Date/time when dial response was received
- `datetime_join`: Date/time when call entered the queue
- `datetime_linkstart`: Date/time when call was linked to agent
- `retries`: (Optional) Number of retry attempts
- `trunk`: Trunk through which call was received
- `queue`: (Incoming only) Queue that received the call
- `call_attributes`: (Optional) Attributes associated with the call
- `matching_contacts`: (Optional, incoming only) List of contacts matching Caller-ID
- `call_survey`: (Optional) List of forms with collected data
- `campaignlog_id`: Log record ID for campaign monitoring

**Example (incoming call):**
```xml
<event>
    <agentlinked>
        <agent_number>Agent/9000</agent_number>
        <remote_channel>SIP/1065-00000001</remote_channel>
        <calltype>incoming</calltype>
        <call_id>13</call_id>
        <campaign_id>1</campaign_id>
        <phone>1065</phone>
        <status>activa</status>
        <uniqueid>1296517374.1</uniqueid>
        <datetime_join>2011-01-31 18:42:55</datetime_join>
        <datetime_linkstart>2011-01-31 18:42:55</datetime_linkstart>
        <trunk>SIP/1065</trunk>
        <queue>8001</queue>
    </agentlinked>
</event>
```

### Event "agentunlinked"

Generated when the link between an agent and a previously linked call is broken. Internally, the Hangup event from the remote side has been received.

**Informational elements:**
- `agent_number`: Agent unlinked from the call
- `call_type`: `incoming` or `outgoing`
- `campaign_id`: (Optional) Campaign database ID
- `call_id`: Call database ID
- `phone`: Number dialed or Caller-ID received
- `datetime_linkend`: Date/time when call was disconnected from agent
- `duration`: Call duration in seconds
- `shortcall`: Flag set to 1 if call is too short per system configuration
- `campaignlog_id`: Log record ID

### Event "agentloggedin"

Generated when the indicated agent has entered the system. This separation as an event allows the loginagent request response to return immediately while the agent takes time entering the password.

**Informational elements:**
- `agent`: Agent that logged in (format: `Agent/9000`)

```xml
<event>
    <agentloggedin>
        <agent>Agent/9000</agent>
    </agentloggedin>
</event>
```

### Event "agentloggedout"

Generated when the indicated agent has been logged out of the system, either by agent request (logoutagent) or because the associated extension was hung up or communication was lost.

**Informational elements:**
- `agent`: Agent that was logged out

### Event "agentfailedlogin"

Generated when the indicated agent attempted to log in but the attempt failed. A possible cause is that the agent didn't enter the queue entry password.

**Informational elements:**
- `agent`: Agent that attempted to log in

### Event "pausestart"

Generated when the indicated agent enters a pause or hold.

**Informational elements:**
- `agent_number`: Agent that entered pause
- `pause_class`: `break` or `hold`
- `pause_type`: Pause ID (only for `break`)
- `pause_name`: Pause name (only for `break`)
- `pause_start`: Date recorded as pause start

### Event "pauseend"

Generated when the indicated agent exits a pause or hold.

**Informational elements:**
- `agent_number`: Agent that exited pause
- `pause_class`: `break` or `hold`
- `pause_type`: Pause ID (only for `break`)
- `pause_name`: Pause name (only for `break`)
- `pause_start`: Date recorded as pause start
- `pause_end`: Date recorded as pause end
- `pause_duration`: Pause duration in seconds

### Event "callprogress"

Generated to indicate call progress, specifically the state transition of the referenced call. This event is not normally generated for all clients. To receive this event, the ECCP client must execute the "callprogress" request with the "enable" flag set to 1.

**Informational elements:**
- `datetime_entry`: Date/time of state transition
- `campaign_type`: `incoming` or `outgoing`
- `campaign_id`: Campaign database ID
- `call_id`: Call database ID
- `new_status`: New call state (`Placing`, `Dialing`, `Ringing`, `OnQueue`, `Failure`, `OnHold`, `OffHold`)
- `retry`: Dial attempt number (0 for incoming)
- `uniqueid`: Asterisk unique ID
- `trunk`: Trunk used
- `phone`: Phone number
- `queue`: Queue number

### Event "queuemembership"

Generated when the set of queues to which an agent belongs has changed.

**Informational elements:**
- `agentchannel`: Agent's queue channel
- `status`: Agent status (`online`, `offline`, `oncall`, `paused`)
- `callid`: Call database ID (if on call)
- `callnumber`: Client phone number (if on call)
- `queues`: List of queues the agent belongs to

---

## Requests List

### Request "getrequestlist"

Lists all requests known by this version of the program.

**Arguments:** None

**Response:**
- `requests`: Element containing one or more `request` elements

### Request "login"

Authenticates a client application and allows it to establish a communication session with the server.

**Arguments:**
- `username`: Username assigned to client
- `password`: Password for username (plaintext or MD5 hash)

**Response:**
- `app_cookie`: Random text string generated as part of login

```xml
<request id="1">
    <login>
        <username>agentconsole</username>
        <password>agentconsole</password>
    </login>
</request>
```

### Request "logout"

Terminates the session between client application and server.

**Arguments:** None

### Request "filterbyagent"

Tells the server that the client is only interested in events for the indicated agent.

**Arguments:**
- `agent_number`: Agent to filter (format: `Agent/9000`) or `any` to remove filter

### Request "getagentstatus"

Reports the current availability status of the indicated agent.

**Arguments:**
- `agent_number`: Agent to query

**Response:**
- `status`: One of `offline`, `online`, `oncall`, `paused`
- `channel`: Asterisk channel used for agent login
- `extension`: Extension used for login
- `onhold`: 1 if agent has initiated hold, 0 otherwise
- `pauseinfo`: Pause information (only when paused)
- `remote_channel`: Call channel linked to agent (if on call)
- `callinfo`: Call information (if on call)

### Request "getmultipleagentstatus"

Optimization of "getagentstatus" that queries multiple agents at once.

**Arguments:**
- `agents`: List of `agent_number` elements

### Request "loginagent"

Initiates connection of an extension, under a specific agent identity, to a queue.

**Arguments:**
- `agent_number`: Agent number to log in (format: `Agent/9000`)
- `agent_hash`: Authentication hash
- `extension`: Extension number to use
- `password`: (Optional) Agent telephone key
- `timeout`: (Optional) Maximum inactivity interval in seconds

**Response status values:**
- `logging`: Call has been dialed and awaits response
- `logged-in`: Agent was already logged in via ECCP on same extension
- `logged-out`: Cannot start login process

### Request "logoutagent"

Terminates an agent's session in their queues.

**Arguments:**
- `agent_number`: Agent number
- `agent_hash`: Authentication hash

### Request "getqueuescript"

Retrieves text associated with an incoming campaign queue.

**Arguments:**
- `queue`: Queue number

**Response:**
- `script`: Free text to present in agent's graphical interface

### Request "getcampaignlist"

Lists all system campaigns.

**Arguments:**
- `campaign_type`: (Optional) `incoming` or `outgoing`
- `status`: (Optional) `active`, `inactive`, `finished`
- `filtername`: (Optional) Filter campaign name
- `datetime_start`: (Optional) Start date filter
- `datetime_end`: (Optional) End date filter
- `offset`: (Optional) Record offset
- `limit`: (Optional) Maximum records to return

### Request "getcampaigninfo"

Retrieves static information about a campaign.

**Arguments:**
- `campaign_type`: `incoming` or `outgoing`
- `campaign_id`: Campaign ID

**Response includes:**
- `name`, `type`, `startdate`, `enddate`
- `working_time_starttime`, `working_time_endtime`
- `queue`, `retries`, `context`, `maxchan`, `trunk`
- `status`, `urltemplate`, `urlopentype`, `script`
- `forms`: List of forms for data collection

### Request "getcampaignstatus"

Retrieves dynamic status of an active campaign.

**Arguments:**
- `campaign_type`: `incoming` or `outgoing`
- `campaign_id`: Campaign ID

### Request "getcampaignqueuewait"

Gets queue wait statistics for a campaign.

### Request "getcallinfo"

Retrieves detailed information about a specific call.

**Arguments:**
- `campaign_type`: `incoming` or `outgoing`
- `campaign_id`: Campaign ID
- `call_id`: Call ID

### Request "setcontact"

Associates a contact with an incoming call when multiple contacts match the Caller-ID.

**Arguments:**
- `call_id`: Call ID
- `contact_id`: Contact ID to associate

### Request "saveformdata"

Saves form data collected for a call.

**Arguments:**
- `campaign_type`: `incoming` or `outgoing`
- `call_id`: Call ID
- `forms`: Form data to save

### Request "hold"

Places the current call on hold.

**Arguments:**
- `agent_number`: Agent number
- `agent_hash`: Authentication hash

### Request "unhold"

Removes the current call from hold.

**Arguments:**
- `agent_number`: Agent number
- `agent_hash`: Authentication hash

### Request "transfercall"

Performs a blind transfer of the current call.

**Arguments:**
- `agent_number`: Agent number
- `agent_hash`: Authentication hash
- `extension`: Destination extension

### Request "atxfercall"

Performs an attended transfer of the current call.

**Arguments:**
- `agent_number`: Agent number
- `agent_hash`: Authentication hash
- `extension`: Destination extension

### Request "pauseagent"

Pauses the agent (enters break).

**Arguments:**
- `agent_number`: Agent number
- `agent_hash`: Authentication hash
- `pause_type`: Pause/break type ID

### Request "unpauseagent"

Unpauses the agent (exits break).

**Arguments:**
- `agent_number`: Agent number
- `agent_hash`: Authentication hash

### Request "getpauses"

Lists all available pause/break types.

**Arguments:** None

**Response:**
- `pauses`: List of `pause` elements with `id`, `name`, `description`

### Request "hangup"

Hangs up the current call for the agent.

**Arguments:**
- `agent_number`: Agent number
- `agent_hash`: Authentication hash

### Request "schedulecall"

Schedules a callback to a number at a specific time.

**Arguments:**
- `agent_number`: Agent number
- `agent_hash`: Authentication hash
- `schedule`: Date/time to schedule (format: `yyyy-mm-dd hh:mm:ss`)
- `phone`: Phone number to call
- `sameagent`: (Optional) 1 to assign to same agent, 0 for any agent
- `newphone`: (Optional) New phone number if different from original

### Request "getagentqueues"

Gets list of queues an agent belongs to.

**Arguments:**
- `agent_number`: Agent number

### Request "getmultipleagentqueues"

Gets queue membership for multiple agents at once.

**Arguments:**
- `agents`: List of `agent_number` elements

### Request "getagentactivitysummary"

Gets activity summary for an agent within a date range.

**Arguments:**
- `agent_number`: Agent number
- `datetime_start`: Start date
- `datetime_end`: End date

### Request "getchanvars"

Gets channel variables for the current call.

**Arguments:**
- `agent_number`: Agent number
- `agent_hash`: Authentication hash
- `variables`: (Optional) List of specific variables to retrieve

### Request "callprogress"

Enables or disables callprogress events for this session.

**Arguments:**
- `enable`: 1 to enable, 0 to disable

### Request "campaignlog"

Retrieves campaign activity log for monitoring.

**Arguments:**
- `campaign_type`: `incoming` or `outgoing`
- `campaign_id`: Campaign ID
- `datetime_start`: (Optional) Start date filter
- `datetime_end`: (Optional) End date filter
- `lastN`: (Optional) Get last N entries

### Request "getincomingqueuelist"

Lists all incoming queues.

**Arguments:** None

### Request "getincomingqueuestatus"

Gets status of incoming queues.

**Arguments:**
- `queue`: (Optional) Specific queue number

### Request "pingagent"

Keeps agent session alive when using timeout.

**Arguments:**
- `agent_number`: Agent number
- `agent_hash`: Authentication hash

---

## Notes on app_agent_pool (Asterisk 12+)

The Agent type in modern Asterisk (12+) uses the **app_agent_pool** module instead of the deprecated **chan_agent**.

Key differences:
- Agents are defined in `/etc/asterisk/agents.conf` using section format with template inheritance
- Queue members are registered as `Local/XXXX@agents` with `StateInterface=Agent:XXXX`
- Agent login uses the `AgentLogin()` application via the `agent-login` context
- Call delivery uses `AgentRequest()` via the `agents` context
- Logout is performed by hanging up the login channel (no `Agentlogoff` command)

The ECCP protocol handles these differences internally, so client applications use the same `Agent/XXXX` format regardless of Asterisk version.

---

## Version History

- **v0.1 Rev 5**: Initial documented version with app_agent_pool support for Asterisk 12+
