# Plan: Add "Transfer to Agent" Feature to Agent Console

## Context

Currently the agent console supports two transfer types:
1. **Blind Transfer** (`transfercall`) - transfers call immediately to an extension
2. **Attended Transfer** (`atxfercall`) - allows consultation with target before transferring

This plan adds a third transfer type: **Transfer to Agent** - which transfers the call to another logged-in agent after verifying their availability.

### Requirements
- Target agent must be logged in (online status)
- Target agent must be free (not on a call, not paused)
- Transfer should be a blind transfer (no consultation phase)
- Agent selection via dropdown with agent names displayed
- Proper error handling and user feedback
- ECCP protocol extension for agent-to-agent transfer
- Logging in both English and Spanish
- Current agent should NOT appear in the dropdown
- Show all active agents (estatus='A')

## Implementation Plan

### Phase 1: Backend - ECCP Protocol Extension

**File: `/opt/issabel/dialer/ECCPConn.class.php`**

1. Add new ECCP request handler: `Request_agentauth_transfercallagent()`

   Location: After existing `Request_agentauth_atxfercall()` (around line 3360)

   Key logic:
   ```php
   private function Request_agentauth_transfercallagent($comando)
   {
       // Validate agent_number and target_agent_number
       // Get source agent's call info
       // Check target agent status (must be 'online', not 'oncall' or 'paused')
       // Get target agent's extension/channel
       // Perform AMI Redirect to transfer call
       // Log and register transfer
   }
   ```

2. Register the new request in the request dispatcher

   Location: In `procesarComandoAutenticado()` method

   Add case for `'transfercallagent'`

**File: `/usr/src/callcenter-issabel5/setup/dialer_process/dialer/ECCPConn.class.php`**
- Same changes for the source repo

### Phase 2: Frontend - ECCP Client Method

**File: `/var/www/html/modules/agent_console/libs/ECCP.class.php`**

Add new method (after `atxfercall()`, around line 513):
```php
public function transfercallagent($targetAgentNumber)
{
    $xml_request = new SimpleXMLElement("<request />");
    $xml_cmdRequest = $xml_request->addChild('transfercallagent');
    $xml_cmdRequest->addChild('agent_number', $this->_agentNumber);
    $xml_cmdRequest->addChild('agent_hash', $this->agentHash($this->_agentNumber, $this->_agentPass));
    $xml_cmdRequest->addChild('target_agent_number', $targetAgentNumber);
    $xml_response = $this->send_request($xml_request);
    return $xml_response->transfercallagent_response;
}
```

**File: `/usr/src/callcenter-issabel5/modules/agent_console/libs/ECCP.class.php`**
- Same changes for the source repo

### Phase 3: Business Logic Layer

**File: `/var/www/html/modules/agent_console/libs/paloSantoConsola.class.php`**

Add new method (after `transferirLlamada()`, around line 788):
```php
function transferirLlamadaAgente($sTargetAgent)
{
    try {
        $oECCP = $this->_obtenerConexion('ECCP');
        $respuesta = $oECCP->transfercallagent($sTargetAgent);
        if (isset($respuesta->failure)) {
            $this->errMsg = _tr('Unable to transfer call to agent').' - '.$this->_formatoErrorECCP($respuesta);
            return FALSE;
        }
        return TRUE;
    } catch (Exception $e) {
        $this->errMsg = '(internal) transfercallagent: '.$e->getMessage();
        return FALSE;
    }
}
```

Reuse existing `listarAgentes()` method which already returns agents in format `Agent/9000 - Agent Name`:
```php
function listarAgentes($agenttype = NULL)
{
    $oDB = $this->_obtenerConexion('call_center');
    $listaWhere = array("estatus = 'A'");
    // ... existing implementation
    // Returns: ['Agent/9000' => 'Agent/9000 - John Doe', ...]
}
```

**File: `/usr/src/callcenter-issabel5/modules/agent_console/libs/paloSantoConsola.class.php`**
- Same changes for the source repo

### Phase 4: Web Handler

**File: `/var/www/html/modules/agent_console/index.php`**

Add new action handler (after `manejarSesionActiva_transfer()`, around line 1223):
```php
function manejarSesionActiva_transferagent($module_name, $smarty, $sDirLocalPlantillas, $oPaloConsola, $estado)
{
    $respuesta = array(
        'action'    =>  'transferagent',
        'message'   =>  '(no message)',
    );
    $sTargetAgent = getParameter('target_agent');
    if ($estado['onhold']) {
        $respuesta['action'] = 'error';
        $respuesta['message'] = _tr('Cannot transfer while call is on hold');
    } elseif (is_null($sTargetAgent) || empty($sTargetAgent)) {
        $respuesta['action'] = 'error';
        $respuesta['message'] = _tr('Invalid or missing target agent');
    } else {
        $bExito = $oPaloConsola->transferirLlamadaAgente($sTargetAgent);
        if (!$bExito) {
            $respuesta['action'] = 'error';
            $respuesta['message'] = _tr('Error while transferring call to agent').' - '.$oPaloConsola->errMsg;
        }
    }
    $json = new Services_JSON();
    Header('Content-Type: application/json');
    return $json->encode($respuesta);
}
```

Add action to the switch statement in `manejarSesionActiva()`:
```php
case 'transferagent':    // Transfer to another agent
    return manejarSesionActiva_transferagent($module_name, $smarty, $sDirLocalPlantillas, $oPaloConsola, $estado);
```

**File: `/usr/src/callcenter-issabel5/modules/agent_console/index.php`**
- Same changes for the source repo

### Phase 5: Frontend UI - JavaScript

**File: `/var/www/html/modules/agent_console/themes/default/js/javascript.js`**

1. Modify transfer dialog to include 3 transfer options (around line 146):
   - Change to 3 radio buttons: blind, attended, agent
   - Add agent dropdown (hidden by default, shown when "agent" selected)

2. Update `do_transfer()` function (around line 729):
   ```javascript
   function do_transfer()
   {
       var transferType = $('input[name="transfer_type"]:checked').val();
       var postData = {
           menu:       module_name,
           rawmode:    'yes',
           action:     'transfer',
           extension:  $('#transfer_extension').val(),
           atxfer:     $('#transfer_type_attended').is(':checked')
       };

       if (transferType == 'agent') {
           postData.action = 'transferagent';
           postData.target_agent = $('#transfer_agent').val();
       }

       $.post('index.php?menu=' + module_name + '&rawmode=yes', postData,
       function (respuesta) {
           verificar_error_session(respuesta);
           if (respuesta['action'] == 'error') {
               mostrar_mensaje_error(respuesta['message']);
           } else if (respuesta['consultation']) {
               $('#btn_hold').button('disable');
               $('#btn_transfer').button('disable');
           }
       }, 'json');
   }
   ```

3. Show/hide agent dropdown based on transfer type selection

**File: `/usr/src/callcenter-issabel5/modules/agent_console/themes/default/js/javascript.js`**
- Same changes for the source repo

### Phase 6: Frontend UI - Template

**File: `/var/www/html/modules/agent_console/themes/default/agent_console.tpl`**

Modify transfer dialog (around line 146):
```html
<div id="issabel-callcenter-seleccion-transfer" title="{$TITLE_TRANSFER_DIALOG}">
    <form>
        <table border="0" cellpadding="0" style="width: 100%;">
            <tr>
                <td>
                    <div id="transfer_type_radio">
                        <input type="radio" id="transfer_type_blind" name="transfer_type" value="blind" checked="checked"/>
                        <label for="transfer_type_blind">{$LBL_TRANSFER_BLIND}</label>
                        {if !$IS_AGENT_TYPE}
                        <input type="radio" id="transfer_type_attended" name="transfer_type" value="attended" />
                        <label for="transfer_type_attended">{$LBL_TRANSFER_ATTENDED}</label>
                        {/if}
                        <input type="radio" id="transfer_type_agent" name="transfer_type" value="agent" />
                        <label for="transfer_type_agent">{$LBL_TRANSFER_AGENT}</label>
                    </div>
                </td>
            </tr>
            <tr id="transfer_extension_row">
                <td><input
                    name="transfer_extension"
                    id="transfer_extension"
                    class="ui-widget-content ui-corner-all"
                    style="width: 100%" /></td>
            </tr>
            <tr id="transfer_agent_row" style="display: none;">
                <td><select
                    name="transfer_agent"
                    id="transfer_agent"
                    class="ui-widget-content ui-corner-all"
                    style="width: 100%">
                    {html_options options=$LISTA_AGENTES selected=$SELECTED_AGENT}
                    </select>
                </td>
            </tr>
        </table>
    </form>
</div>
```

**Important:** Agent list format: `"Agent/9000 - John Doe"` (number + name)

**File: `/usr/src/callcenter-issabel5/modules/agent_console/themes/default/agent_console.tpl`**
- Same changes for the source repo

### Phase 7: Language Strings

**File: `/var/www/html/modules/agent_console/lang/en.lang`**

Add new language strings:
```
LBL_TRANSFER_AGENT=Transfer to Agent
LBL_TRANSFER_BLIND=Blind Transfer
LBL_TRANSFER_ATTENDED=Attended Transfer
ERR_TARGET_AGENT_BUSY=Target agent is busy
ERR_TARGET_AGENT_OFFLINE=Target agent is not logged in
ERR_TARGET_AGENT_PAUSED=Target agent is on pause
```

**File: `/usr/src/callcenter-issabel5/modules/agent_console/lang/en.lang`**
- Same changes for the source repo

### Phase 8: ECCP Protocol Documentation

**File: `/usr/src/callcenter-issabel5/setup/dialer_process/dialer/ECCP_Protocol.md`**

Add documentation for new `transfercallagent` request:

```markdown
### transfercallagent

Transfer the agent's current call to another logged-in agent.

**Request:**
```xml
<request id="timestamp.random">
    <transfercallagent>
        <agent_number>Agent/9000</agent_number>
        <agent_hash>...</agent_hash>
        <target_agent_number>Agent/9001</target_agent_number>
    </transfercallagent>
</request>
```

**Response:**
```xml
<response id="...">
    <transfercallagent_response>
        <success/>
    </transfercallagent_response>
</response>
```

**Error Conditions:**
- 400: Bad request (missing parameters)
- 404: Agent not found
- 417: Agent not in call / Target agent not available
- 500: Transfer failed
```

### Phase 9: ECCP Example File

**File: `/usr/src/callcenter-issabel5/setup/dialer_process/dialer/eccp_examples/transfercallagent.eccp`** (create if not exists)

Create test file for the new ECCP command:
```xml
<request id="123456">
    <transfercallagent>
        <agent_number>Agent/9000</agent_number>
        <agent_hash>MD5_HASH_HERE</agent_hash>
        <target_agent_number>Agent/9001</target_agent_number>
    </transfercallagent>
</request>
```

### Phase 10: Agent List Loading

**File: `/var/www/html/modules/agent_console/index.php`**

Modify initial page load to populate agent list:

In the main section that prepares Smarty variables:
```php
// Load agent list for transfer to agent feature
$oPaloConsola = new paloSantoConsola($pDB, $dbAsterisk);
$LISTA_AGENTES = $oPaloConsola->listarAgentes();

// Filter out the current logged-in agent from the list
$currentAgent = isset($_SESSION['callcenter']['agente']) ? $_SESSION['callcenter']['agente'] : '';
if (isset($LISTA_AGENTES[$currentAgent])) {
    unset($LISTA_AGENTES[$currentAgent]);
}

$smarty->assign('LISTA_AGENTES', $LISTA_AGENTES);
```

**Note:** Agent list shows format `Agent/9000 - Agent Name` for all active agents (estatus='A'), excluding the current agent.

### Phase 11: Language Strings (Spanish)

**File: `/var/www/html/modules/agent_console/lang/es.lang`**

Add Spanish translations:
```
LBL_TRANSFER_AGENT=Transferir a Agente
LBL_TRANSFER_BLIND=Transferencia a Ciegas
LBL_TRANSFER_ATTENDED=Transferencia Asistida
ERR_TARGET_AGENT_BUSY=Agente destino está ocupado
ERR_TARGET_AGENT_OFFLINE=Agente destino no está conectado
ERR_TARGET_AGENT_PAUSED=Agente destino está en pausa
ERR_CANNOT_TRANSFER_HOLD=No se puede transferir mientras la llamada está en espera
ERR_TARGET_AGENT_NOT_AVAILABLE=Agente destino no disponible - %s
```

**File: `/usr/src/callcenter-issabel5/modules/agent_console/lang/es.lang`**
- Same changes for the source repo

## Critical Files to Modify

| File Path | Purpose |
|-----------|---------|
| `/opt/issabel/dialer/ECCPConn.class.php` | ECCP server handler (backend) |
| `/usr/src/callcenter-issabel5/setup/dialer_process/dialer/ECCPConn.class.php` | ECCP server handler (source repo) |
| `/var/www/html/modules/agent_console/libs/ECCP.class.php` | ECCP client method |
| `/usr/src/callcenter-issabel5/modules/agent_console/libs/ECCP.class.php` | ECCP client method (source repo) |
| `/var/www/html/modules/agent_console/libs/paloSantoConsola.class.php` | Business logic |
| `/usr/src/callcenter-issabel5/modules/agent_console/libs/paloSantoConsola.class.php` | Business logic (source repo) |
| `/var/www/html/modules/agent_console/index.php` | Web handler |
| `/usr/src/callcenter-issabel5/modules/agent_console/index.php` | Web handler (source repo) |
| `/var/www/html/modules/agent_console/themes/default/js/javascript.js` | Frontend JS |
| `/usr/src/callcenter-issabel5/modules/agent_console/themes/default/js/javascript.js` | Frontend JS (source repo) |
| `/var/www/html/modules/agent_console/themes/default/agent_console.tpl` | UI template |
| `/usr/src/callcenter-issabel5/modules/agent_console/themes/default/agent_console.tpl` | UI template (source repo) |
| `/var/www/html/modules/agent_console/lang/en.lang` | Language strings (English) |
| `/usr/src/callcenter-issabel5/modules/agent_console/lang/en.lang` | Language strings (English - source) |
| `/var/www/html/modules/agent_console/lang/es.lang` | Language strings (Spanish) |
| `/usr/src/callcenter-issabel5/modules/agent_console/lang/es.lang` | Language strings (Spanish - source) |

## Verification Steps

1. **Test ECCP Protocol:**
   ```bash
   # Connect to dialer and test new ECCP command
   nc localhost 20005 < test_transfercallagent.eccp
   ```

2. **Test Agent Transfer:**
   - Login Agent A (Agent/9000)
   - Login Agent B (Agent/9001)
   - Place a call to Agent A
   - Agent A clicks Transfer, selects "Transfer to Agent"
   - Agent A selects Agent B from dropdown
   - Verify call is transferred to Agent B
   - Verify Agent A is released

3. **Test Error Conditions:**
   - Target agent offline → should show error
   - Target agent on call → should show error
   - Target agent paused → should show error
   - Source agent on hold → should show error

4. **Log Verification:**
   ```bash
   tail -f /opt/issabel/dialer/dialerd.log | grep -i "transfercallagent"
   ```

5. **Database Verification:**
   - Check `calls` table for transfer records
   - Verify agent status is correctly updated

## Logging Requirements

All new code must include logs in both English and Spanish using the pattern:
```php
$this->_log->output('INFO: mensaje en español | EN: English message');
```

Key log points:
- When receiving transfercallagent request
- When checking target agent status
- When performing AMI Redirect
- On transfer success/failure
- On validation errors
