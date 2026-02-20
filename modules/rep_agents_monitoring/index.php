<?php
  /* vim: set expandtab tabstop=4 softtabstop=4 shiftwidth=4:
  Codificación: UTF-8
  +----------------------------------------------------------------------+
  | Issabel version 1.5.2-3.1                                               |
  | http://www.issabel.org                                               |
  +----------------------------------------------------------------------+
  | Copyright (c) 2006 Palosanto Solutions S. A.                         |
  +----------------------------------------------------------------------+
  | The contents of this file are subject to the General Public License  |
  | (GPL) Version 2 (the "License"); you may not use this file except in |
  | compliance with the License. You may obtain a copy of the License at |
  | http://www.opensource.org/licenses/gpl-license.php                   |
  |                                                                      |
  | Software distributed under the License is distributed on an "AS IS"  |
  | basis, WITHOUT WARRANTY OF ANY KIND, either express or implied. See  |
  | the License for the specific language governing rights and           |
  | limitations under the License.                                       |
  +----------------------------------------------------------------------+
  | The Initial Developer of the Original Code is PaloSanto Solutions    |
  +----------------------------------------------------------------------+
  $Id: index.php,v 1.1.1.1 2009/07/27 09:10:19 dlopez Exp $ */

require_once 'libs/paloSantoGrid.class.php';

function _moduleContent(&$smarty, $module_name)
{
    global $arrConf;
    global $arrLang;

    require_once "modules/agent_console/libs/issabel2.lib.php";
    require_once "modules/agent_console/libs/paloSantoConsola.class.php";
    require_once "modules/agent_console/libs/JSON.php";
    require_once "modules/$module_name/configs/default.conf.php";

    // Directorio de este módulo
    $sDirScript = dirname($_SERVER['SCRIPT_FILENAME']);

    // Se fusiona la configuración del módulo con la configuración global
    $arrConf = array_merge($arrConf, $arrConfModule);

    /* Se pide el archivo de inglés, que se elige a menos que el sistema indique
       otro idioma a usar. Así se dispone al menos de la traducción al inglés
       si el idioma elegido carece de la cadena.
     */
    load_language_module($module_name);

    // Asignación de variables comunes y directorios de plantillas
    $sDirPlantillas = (isset($arrConf['templates_dir']))
        ? $arrConf['templates_dir'] : 'themes';
    $sDirLocalPlantillas = "$sDirScript/modules/$module_name/".$sDirPlantillas.'/'.$arrConf['theme'];
    $smarty->assign("MODULE_NAME", $module_name);

    $sAction = '';
    $sContenido = '';

    $sAction = getParameter('action');
    if (!in_array($sAction, array('', 'checkStatus')))
        $sAction = '';

    $oPaloConsola = new PaloSantoConsola();
    switch ($sAction) {
    case 'checkStatus':
        $sContenido = manejarMonitoreo_checkStatus($module_name, $smarty, $sDirLocalPlantillas, $oPaloConsola);
        break;
    case '':
    default:
        $sContenido = manejarMonitoreo_HTML($module_name, $smarty, $sDirLocalPlantillas, $oPaloConsola);
        break;
    }
    $oPaloConsola->desconectarTodo();

    return $sContenido;
}

function manejarMonitoreo_HTML($module_name, $smarty, $sDirLocalPlantillas, $oPaloConsola)
{
    global $arrLang;

    $smarty->assign(array(
        'FRAMEWORK_TIENE_TITULO_MODULO' => existeSoporteTituloFramework(),
        'icon'                          => 'modules/'.$module_name.'/images/call.png',
        'title'                         =>  _tr('Agent Monitoring'),
    ));

    // Parse shift filter parameters (default: full day 00-23)
    $shiftFrom = getParameter('shift_from');
    $shiftTo = getParameter('shift_to');
    if (is_null($shiftFrom) || $shiftFrom === '') $shiftFrom = 0;
    if (is_null($shiftTo) || $shiftTo === '') $shiftTo = 23;
    $shiftFrom = (int)$shiftFrom;
    $shiftTo = (int)$shiftTo;
    $shiftRange = calculateShiftDatetimeRange($shiftFrom, $shiftTo);

    /*
     * Un agente puede pertenecer a múltiples colas, y puede o no estar
     * atendiendo una llamada, la cual puede haber llegado de como máximo una
     * cola. Hay 3 cronómetros que se pueden actualizar:
     *
     * último estado:   el tiempo transcurrido desde el último cambio de estado
     * total de login:  el tiempo durante el cual el agente ha estado logoneado
     * total de llamadas: el tiempo que el agente pasa atendiendo llamadas
     *
     * Para el monitoreo de este módulo, los estados en que puede estar
     * una fila (que muestra un agente en una cola) pueden ser los siguientes:
     *
     * offline: el tiempo total de login y el tiempo de llamadas no se
     *  actualizan. Si el cliente estuvo en otro estado previamente
     *  (lastsessionend) entonces se actualiza regularmente el cronómetro de
     *  último estado. De otro modo el cronómetro de último estado está vacío.
     * online: se actualiza el tiempo total de login y el tiempo de último
     *  estado, y el tiempo total de llamadas no se actualiza. El cronómetro de
     *  último estado cuenta desde el inicio de sesión.
     * paused: igual que online, pero el cronómentro de último estado cuenta
     *  desde el inicio de la pausa.
     * oncall: se actualiza el tiempo total de login. El cronómetro de último
     *  estado cuenta desde el inicio de la llamada únicamente para la cola que
     *  proporcionó la llamada que atiende el agente actualmente. De otro modo
     *  el cronómetro no se actualiza. De manera similar, el total de tiempo de
     *  llamadas se actualiza únicamente para la cola que haya proporcionado la
     *  llamada que atiende el agente.
     *
     * El estado del cliente consiste en un arreglo de tantos elementos como
     * agentes haya pertenecientes a cada cola. Si un agente pertenece a más de
     * una cola, hay un elemento por cada pertenencia del mismo agente a cada
     * cola. Cada elemento es una estructura que contiene los siguientes
     * valores:
     *
     * status:          {offline|online|oncall|paused}
     * sec_laststatus:  integer|null
     * sec_calls:       integer
     * logintime:       integer
     * num_calls:       integer
     * oncallupdate:    boolean
     *
     * Cada elemento del arreglo se posiciona por 'queue-{NUM_COLA}-member-{NUM_AGENTE}'
     *
     * El estado enviado por el cliente para detectar cambios es también un
     * arreglo con el mismo número de elementos que el arreglo anterior,
     * posicionado de la misma manera. Cada elemento es una estructura que
     * contiene los siguientes valores:
     *
     * status:          {offline|online|oncall|paused}
     * oncallupdate:    boolean
     */
    $estadoMonitor = $oPaloConsola->listarEstadoMonitoreoAgentes();
    if (!is_array($estadoMonitor)) {
        $smarty->assign(array(
            'mb_title'  =>  'ERROR',
            'mb_message'    =>  $oPaloConsola->errMsg,
        ));
        return '';
    }
    ksort($estadoMonitor);

    $breakData = consultarTiempoBreakAgentes($shiftRange['start'], $shiftRange['end']);
    $jsonData = construirDatosJSON($estadoMonitor, $breakData);

    $arrData = array();
    $tuplaTotal = NULL;
    $sPrevQueue = NULL;
    foreach ($jsonData as $jsonKey => $jsonRow) {
        list($d1, $sQueue, $d2, $sTipoAgente, $sNumeroAgente) = explode('-', $jsonKey);

        $sEstadoTag = '(unimplemented)';
        switch ($jsonRow['status']) {
        case 'offline':
            $sEstadoTag = _tr('LOGOUT');
            break;
        case 'online':
            $sEstadoTag = '<img src="modules/'.$module_name.'/images/ready.png" border="0" alt="'._tr('READY').'"/>';
            break;
        case 'ringing':
            $sEstadoTag = '<img src="modules/'.$module_name.'/images/agent-ringing.gif" border="0" alt="'._tr('RINGING').'"/>';
            break;
        case 'oncall':
            $sEstadoTag = '<img src="modules/'.$module_name.'/images/call.png" border="0" alt="'._tr('CALL').'"/>';
            break;
        case 'paused':
            $sEstadoTag = '<img src="modules/'.$module_name.'/images/break.png" border="0" alt="'._tr('BREAK').'"/>';
            if (!is_null($jsonRow['pausename']))
                $sEstadoTag .= '<span>'.htmlentities($jsonRow['pausename'], ENT_COMPAT, 'UTF-8').'</span>';
            break;
        }
        $sEstadoTag = '<span id="'.$jsonKey.'-statuslabel">'.$sEstadoTag.'</span>';
        $sEstadoTag .= '&nbsp;<span id="'.$jsonKey.'-sec_laststatus">';
        if (!is_null($jsonRow['sec_laststatus'])) {
        	$sEstadoTag .= timestamp_format($jsonRow['sec_laststatus']);
        }
        $sEstadoTag .= '</span>';

        // Estado a mostrar en HTML se deriva del estado JSON
        // EN: Status to display in HTML is derived from JSON status
        if ($sPrevQueue != $sQueue) {
            if (!is_null($tuplaTotal)) {
            	// Emitir fila de totales para la cola ANTERIOR
            	// EN: Emit totals row for the PREVIOUS queue
                $jsTotalKey = 'queue-'.$sPrevQueue;
                $arrData[] = array(
                    '<b>'._tr('TOTAL').'</b>',
                    '&nbsp;',
                    '<b>'._tr('Agents').': '.$tuplaTotal['num_agents'].'</b>',
                    '&nbsp;',
                    '<b><span id="'.$jsTotalKey.'-num_calls">'.$tuplaTotal['num_calls'].'</span></b>',
                    '<b><span id="'.$jsTotalKey.'-logintime">'.timestamp_format($tuplaTotal['logintime']).'</span></b>',
                    '<b><span id="'.$jsTotalKey.'-sec_calls">'.timestamp_format($tuplaTotal['sec_calls']).'</span></b>',
                    '<b><span id="'.$jsTotalKey.'-sec_breaks">'.timestamp_format($tuplaTotal['sec_breaks']).'</span></b>',
                );
            }

            // Reiniciar totales aquí
            // EN: Reset totals here
            $tuplaTotal = array(
                'num_agents'    =>  0,
                'logintime'     =>  0,
                'num_calls'     =>  0,
                'sec_calls'     =>  0,
                'sec_breaks'    =>  0,
            );
        }
        $tuplaTotal['num_agents']++;
        $tuplaTotal['logintime'] += $jsonRow['logintime'];
        $tuplaTotal['num_calls'] += $jsonRow['num_calls'];
        $tuplaTotal['sec_calls'] += $jsonRow['sec_calls'];
        $tuplaTotal['sec_breaks'] += $jsonRow['sec_breaks'];
        $tupla = array(
            ($sPrevQueue == $sQueue) ? '' : $sQueue,
            $jsonRow['agentchannel'],
            htmlentities($jsonRow['agentname'], ENT_COMPAT, 'UTF-8'),
            $sEstadoTag,
            '<span id="'.$jsonKey.'-num_calls">'.$jsonRow['num_calls'].'</span>',
            '<span id="'.$jsonKey.'-logintime">'.timestamp_format($jsonRow['logintime']).'</span>',
            '<span id="'.$jsonKey.'-sec_calls">'.timestamp_format($jsonRow['sec_calls']).'</span>',
            '<span id="'.$jsonKey.'-sec_breaks">'.timestamp_format($jsonRow['sec_breaks']).'</span>',
        );
        $arrData[] = $tupla;
        $sPrevQueue = $sQueue;
    }
    // Emitir fila de totales para la cola ÚLTIMA
    $jsTotalKey = 'queue-'.$sPrevQueue;
    $arrData[] = array(
        '<b>'._tr('TOTAL').'</b>',
        '&nbsp;',
        '<b>'._tr('Agents').': '.$tuplaTotal['num_agents'].'</b>',
        '&nbsp;',
        '<b><span id="'.$jsTotalKey.'-num_calls">'.$tuplaTotal['num_calls'].'</span></b>',
        '<b><span id="'.$jsTotalKey.'-logintime">'.timestamp_format($tuplaTotal['logintime']).'</span></b>',
        '<b><span id="'.$jsTotalKey.'-sec_calls">'.timestamp_format($tuplaTotal['sec_calls']).'</span></b>',
        '<b><span id="'.$jsTotalKey.'-sec_breaks">'.timestamp_format($tuplaTotal['sec_breaks']).'</span></b>',
    );

    // No es necesario emitir el nombre del agente la inicialización JSON
    foreach (array_keys($jsonData) as $k) unset($jsonData[$k]['agentname']);

    // Extraer la información que el navegador va a usar para actualizar
    $estadoCliente = array();
    foreach (array_keys($jsonData) as $k) {
        $estadoCliente[$k] = array(
            'status'        =>  $jsonData[$k]['status'],
            'oncallupdate'  =>  $jsonData[$k]['oncallupdate'],
        );
    }
    $estadoHash = generarEstadoHash($module_name, $estadoCliente);

    $oGrid  = new paloSantoGrid($smarty);
    $oGrid->pagingShow(FALSE);
    $json = new Services_JSON();
    $INITIAL_CLIENT_STATE = $json->encode($jsonData);
    $sJsonInitialize = <<<JSON_INITIALIZE
<script type="text/javascript">
$(function() {
    initialize_client_state($INITIAL_CLIENT_STATE, '$estadoHash');
});
</script>
JSON_INITIALIZE;

    // Build shift filter HTML
    $sHoursOptions = '';
    for ($h = 0; $h < 24; $h++) {
        $sHourVal = sprintf('%02d', $h);
        $sHoursOptions .= '<option value="'.$sHourVal.'">'.$sHourVal.':00</option>';
    }
    $sShiftFilterHTML = '<div id="shiftFilterPanel" style="margin-bottom: 10px; padding: 8px; background: #f5f5f5; border: 1px solid #ddd; border-radius: 4px;">'
        . '<label for="shiftFromHour" style="font-weight: bold;">'._tr('Shift From').':</label> '
        . '<select id="shiftFromHour" style="margin-right: 15px;">'.$sHoursOptions.'</select>'
        . '<label for="shiftToHour" style="font-weight: bold;">'._tr('Shift To').':</label> '
        . '<select id="shiftToHour" style="margin-right: 15px;">'.$sHoursOptions.'</select>'
        . '<button id="applyShiftFilter" type="button" class="ui-button ui-widget ui-state-default ui-corner-all" style="padding: 4px 12px;">'._tr('Apply').'</button>'
        . '<span id="shiftRangeIndicator" style="margin-left: 15px; font-style: italic; color: #666;"></span>'
        . '</div>';

    return $sShiftFilterHTML . $oGrid->fetchGrid(array(
            'title'     =>  _tr('Agents Monitoring'),
            'icon'      =>  _tr('images/list.png'),
            'width'     =>  '99%',
            'start'     =>  1,
            'end'       =>  1,
            'total'     =>  1,
            'url'       =>  array('menu' => $module_name),
            'columns'   =>  array(
                array('name'    =>  _tr('Queue')),
                array('name'    =>  _tr('Number')),
                array('name'    =>  _tr('Agent')),
                array('name'    =>  _tr('Current status')),
                array('name'    =>  _tr('Total calls')),
                array('name'    =>  _tr('Total login time')),
                array('name'    =>  _tr('Total talk time')),
                array('name'    =>  _tr('Total break time')),
            ),
        ), $arrData, $arrLang).
        $sJsonInitialize;
}

function timestamp_format($i)
{
	return sprintf('%02d:%02d:%02d',
        ($i - ($i % 3600)) / 3600,
        (($i - ($i % 60)) / 60) % 60,
        $i % 60);
}

function calculateShiftDatetimeRange($fromHour, $toHour)
{
    $today = date('Y-m-d');
    $yesterday = date('Y-m-d', strtotime('-1 day'));
    $fromHour = max(0, min(23, (int)$fromHour));
    $toHour = max(0, min(23, (int)$toHour));

    if ($fromHour > $toHour) {
        // Overnight shift: yesterday's fromHour to today's toHour
        $datetimeStart = $yesterday . ' ' . sprintf('%02d:00:00', $fromHour);
        $datetimeEnd = $today . ' ' . sprintf('%02d:59:59', $toHour);
    } else {
        // Same-day shift
        $datetimeStart = $today . ' ' . sprintf('%02d:00:00', $fromHour);
        $datetimeEnd = $today . ' ' . sprintf('%02d:59:59', $toHour);
    }
    return array('start' => $datetimeStart, 'end' => $datetimeEnd);
}

function consultarTiempoBreakAgentes($datetimeStart = NULL, $datetimeEnd = NULL)
{
    global $arrConf;
    $result = array('breakTimes' => array(), 'holdNames' => array());

    try {
        $pDB = new PDO(
            'mysql:host=localhost;dbname=call_center;charset=utf8',
            'asterisk', 'asterisk'
        );
        $pDB->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    } catch (PDOException $e) {
        return $result;
    }

    // Default to full day if no shift range provided
    if (is_null($datetimeStart) || is_null($datetimeEnd)) {
        $sToday = date('Y-m-d');
        $datetimeStart = "$sToday 00:00:00";
        $datetimeEnd = "$sToday 23:59:59";
    }

    // Query cumulative completed break time per agent (excluding Hold)
    $sql = "SELECT CONCAT(agent.type, '/', agent.number) AS agentchannel, " .
           "SUM(UNIX_TIMESTAMP(audit.datetime_end) - UNIX_TIMESTAMP(audit.datetime_init)) AS sec_breaks " .
           "FROM audit " .
           "INNER JOIN break ON break.id = audit.id_break " .
           "INNER JOIN agent ON agent.id = audit.id_agent " .
           "WHERE break.tipo = 'B' " .
           "AND audit.datetime_end IS NOT NULL " .
           "AND audit.datetime_init >= :start " .
           "AND audit.datetime_init <= :end " .
           "GROUP BY agent.id";
    $stmt = $pDB->prepare($sql);
    $stmt->execute(array(':start' => $datetimeStart, ':end' => $datetimeEnd));
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $result['breakTimes'][$row['agentchannel']] = (int)$row['sec_breaks'];
    }

    // Query Hold-type break names
    $sql2 = "SELECT name FROM break WHERE tipo = 'H' AND status = 'A'";
    $stmt2 = $pDB->query($sql2);
    while ($row = $stmt2->fetch(PDO::FETCH_ASSOC)) {
        $result['holdNames'][] = $row['name'];
    }

    $pDB = null;
    return $result;
}

function construirDatosJSON(&$estadoMonitor, $breakData = array())
{
    $iTimestampActual = time();
    $jsonData = array();
    foreach ($estadoMonitor as $sQueue => $agentList) {
        ksort($agentList);
        foreach ($agentList as $sAgentChannel => $infoAgente) {
            $iTimestampEstado = NULL;
            $jsonKey = 'queue-'.$sQueue.'-member-'.strtolower(str_replace('/', '-', $sAgentChannel));

            switch ($infoAgente['agentstatus']) {
            case 'offline':
                if (!is_null($infoAgente['lastsessionend']))
                    $iTimestampEstado = strtotime($infoAgente['lastsessionend']);
                break;
            case 'online':
            case 'ringing':
                if (!is_null($infoAgente['lastsessionstart']))
                    $iTimestampEstado = strtotime($infoAgente['lastsessionstart']);
                break;
            case 'oncall':
                if (!is_null($infoAgente['linkstart']))
                    $iTimestampEstado = strtotime($infoAgente['linkstart']);
                break;
            case 'paused':
                if (!is_null($infoAgente['lastpausestart']))
                    $iTimestampEstado = strtotime($infoAgente['lastpausestart']);
                break;
            }

            // Preparar estado inicial JSON
            $jsonData[$jsonKey] = array(
                'agentchannel'      =>  $sAgentChannel,
                'agentname'         =>  $infoAgente['agentname'],
                'status'            =>  $infoAgente['agentstatus'],
                'sec_laststatus'    =>  is_null($iTimestampEstado) ? NULL : ($iTimestampActual - $iTimestampEstado),
                'sec_calls'         =>  $infoAgente['sec_calls'] +
                    (is_null($infoAgente['linkstart'])
                        ? 0
                        : $iTimestampActual - strtotime($infoAgente['linkstart'])),
                'logintime'         =>  $infoAgente['logintime'] + (
                    (is_null($infoAgente['lastsessionend']) && !is_null($infoAgente['lastsessionstart']))
                        ? $iTimestampActual - strtotime($infoAgente['lastsessionstart'])
                        : 0),
                'num_calls'         =>  $infoAgente['num_calls'],
                'oncallupdate'      =>  !is_null($infoAgente['linkstart']),
                'pausename'         =>  $infoAgente['pausename'],
            );

            // Break time tracking
            $sec_breaks_completed = isset($breakData['breakTimes'][$sAgentChannel])
                ? $breakData['breakTimes'][$sAgentChannel] : 0;
            $isbreakpause = false;
            if ($infoAgente['agentstatus'] == 'paused' && !is_null($infoAgente['pausename'])) {
                $holdNames = isset($breakData['holdNames']) ? $breakData['holdNames'] : array();
                $isbreakpause = !in_array($infoAgente['pausename'], $holdNames);
            }
            $sec_breaks = $sec_breaks_completed;
            if ($isbreakpause && !is_null($infoAgente['lastpausestart'])) {
                $sec_breaks += max(0, $iTimestampActual - strtotime($infoAgente['lastpausestart']));
            }
            $jsonData[$jsonKey]['sec_breaks'] = $sec_breaks;
            $jsonData[$jsonKey]['sec_breaks_completed'] = $sec_breaks_completed;
            $jsonData[$jsonKey]['isbreakpause'] = $isbreakpause;
        }
    }
    return $jsonData;
}


function manejarMonitoreo_checkStatus($module_name, $smarty, $sDirLocalPlantillas, $oPaloConsola)
{
    $respuesta = array();
    setupSSESession();

    // Estado del lado del cliente
    $estadoHash = getParameter('clientstatehash');
    if (!is_null($estadoHash)) {
        $estadoCliente = isset($_SESSION[$module_name]['estadoCliente'])
            ? $_SESSION[$module_name]['estadoCliente']
            : array();
    } else {
        $estadoCliente = getParameter('clientstate');
        if (!is_array($estadoCliente)) return;
    }
    foreach (array_keys($estadoCliente) as $k)
        $estadoCliente[$k]['oncallupdate'] = ($estadoCliente[$k]['oncallupdate'] == 'true');

    // Parse shift filter parameters (default: full day 00-23)
    $shiftFrom = getParameter('shift_from');
    $shiftTo = getParameter('shift_to');
    if (is_null($shiftFrom) || $shiftFrom === '') $shiftFrom = 0;
    if (is_null($shiftTo) || $shiftTo === '') $shiftTo = 23;
    $shiftFrom = (int)$shiftFrom;
    $shiftTo = (int)$shiftTo;
    $shiftRange = calculateShiftDatetimeRange($shiftFrom, $shiftTo);

    // Modo a funcionar: Long-Polling, o Server-sent Events
    $bSSE = detectSSEMode();
    initSSE($bSSE);

    // Verificar hash correcto
    if (!is_null($estadoHash) && $estadoHash != $_SESSION[$module_name]['estadoClienteHash']) {
    	$respuesta['estadoClienteHash'] = 'mismatch';
        jsonflush($bSSE, $respuesta);
        $oPaloConsola->desconectarTodo();
        return;
    }

    // Estado del lado del servidor
    $estadoMonitor = $oPaloConsola->listarEstadoMonitoreoAgentes();
    if (!is_array($estadoMonitor)) {
        $respuesta['error'] = $oPaloConsola->errMsg;
        jsonflush($bSSE, $respuesta);
    	$oPaloConsola->desconectarTodo();
        return;
    }

    // Acumular inmediatamente las filas que son distintas en estado
    ksort($estadoMonitor);
    $breakData = consultarTiempoBreakAgentes($shiftRange['start'], $shiftRange['end']);
    $jsonData = construirDatosJSON($estadoMonitor, $breakData);
    foreach ($jsonData as $jsonKey => $jsonRow) {
    	if (isset($estadoCliente[$jsonKey])) {
    		if ($estadoCliente[$jsonKey]['status'] != $jsonRow['status'] ||
                $estadoCliente[$jsonKey]['oncallupdate'] != $jsonRow['oncallupdate']) {
                $respuesta[$jsonKey] = $jsonRow;
                $estadoCliente[$jsonKey]['status'] = $jsonRow['status'];
                $estadoCliente[$jsonKey]['oncallupdate'] = $jsonRow['oncallupdate'];
                unset($respuesta[$jsonKey]['agentname']);
            }
    	}
    }

    $iTimeoutPoll = $oPaloConsola->recomendarIntervaloEsperaAjax();
    do {
        $oPaloConsola->desconectarEspera();

        // Re-poll full state to catch changes not reflected in events (e.g., ringing status)
        $estadoMonitorActual = $oPaloConsola->listarEstadoMonitoreoAgentes();
        if (is_array($estadoMonitorActual)) {
            ksort($estadoMonitorActual);
            $breakData = consultarTiempoBreakAgentes($shiftRange['start'], $shiftRange['end']);
            $jsonDataActual = construirDatosJSON($estadoMonitorActual, $breakData);
            foreach ($jsonDataActual as $jsonKey => $jsonRow) {
                if (isset($estadoCliente[$jsonKey])) {
                    if ($estadoCliente[$jsonKey]['status'] != $jsonRow['status'] ||
                        $estadoCliente[$jsonKey]['oncallupdate'] != $jsonRow['oncallupdate']) {
                        $respuesta[$jsonKey] = $jsonRow;
                        $estadoCliente[$jsonKey]['status'] = $jsonRow['status'];
                        $estadoCliente[$jsonKey]['oncallupdate'] = $jsonRow['oncallupdate'];
                        unset($respuesta[$jsonKey]['agentname']);
                    }
                }
            }
            // Update local state for event handling
            $estadoMonitor = $estadoMonitorActual;
            $jsonData = $jsonDataActual;
        }

        // Se inicia espera larga con el navegador...
        session_commit();
        $iTimestampInicio = time();

        while (connection_status() == CONNECTION_NORMAL && count($respuesta) <= 0
            && time() - $iTimestampInicio <  $iTimeoutPoll) {

            $listaEventos = $oPaloConsola->esperarEventoSesionActiva();
            if (is_null($listaEventos)) {
                $respuesta['error'] = $oPaloConsola->errMsg;
                jsonflush($bSSE, $respuesta);
                $oPaloConsola->desconectarTodo();
                return;
            }

            // Re-poll state after each event wait to catch ringing/device status changes
            // that don't generate events (QueueMemberStatus -> queue_status changes)
            $estadoMonitorActual = $oPaloConsola->listarEstadoMonitoreoAgentes();
            if (is_array($estadoMonitorActual)) {
                ksort($estadoMonitorActual);
                $breakData = consultarTiempoBreakAgentes($shiftRange['start'], $shiftRange['end']);
                $jsonDataActual = construirDatosJSON($estadoMonitorActual, $breakData);
                foreach ($jsonDataActual as $jsonKey => $jsonRow) {
                    if (isset($estadoCliente[$jsonKey])) {
                        if ($estadoCliente[$jsonKey]['status'] != $jsonRow['status'] ||
                            $estadoCliente[$jsonKey]['oncallupdate'] != $jsonRow['oncallupdate']) {
                            $respuesta[$jsonKey] = $jsonRow;
                            $estadoCliente[$jsonKey]['status'] = $jsonRow['status'];
                            $estadoCliente[$jsonKey]['oncallupdate'] = $jsonRow['oncallupdate'];
                            unset($respuesta[$jsonKey]['agentname']);
                        }
                    }
                }
                $estadoMonitor = $estadoMonitorActual;
                $jsonData = $jsonDataActual;
            }

            $iTimestampActual = time();
            foreach ($listaEventos as $evento) {
                $sNumeroAgente = $sCanalAgente = $evento['agent_number'];
                $sNumeroAgente = strtolower(str_replace('/', '-', $sCanalAgente));

            	switch ($evento['event']) {
            	case 'agentloggedin':
                    foreach (array_keys($estadoMonitor) as $sQueue) {
                        if (isset($estadoMonitor[$sQueue][$sCanalAgente])) {
                        	$jsonKey = 'queue-'.$sQueue.'-member-'.$sNumeroAgente;
                            if (isset($jsonData[$jsonKey]) && $jsonData[$jsonKey]['status'] == 'offline') {

                                // Estado en el estado de monitor
                                $estadoMonitor[$sQueue][$sCanalAgente]['agentstatus'] = 'online';
                                $estadoMonitor[$sQueue][$sCanalAgente]['lastsessionstart'] = date('Y-m-d H:i:s', $iTimestampActual);
                                $estadoMonitor[$sQueue][$sCanalAgente]['lastsessionend'] = NULL;
                                if (!is_null($estadoMonitor[$sQueue][$sCanalAgente]['lastpausestart']) &&
                                    is_null($estadoMonitor[$sQueue][$sCanalAgente]['lastpauseend'])) {
                                	$estadoMonitor[$sQueue][$sCanalAgente]['lastpauseend'] = date('Y-m-d H:i:s', $iTimestampActual);
                                }
                                $estadoMonitor[$sQueue][$sCanalAgente]['linkstart'] = NULL;

                                // Estado en la estructura JSON
                                $jsonData[$jsonKey]['status'] = $estadoMonitor[$sQueue][$sCanalAgente]['agentstatus'];
                                $jsonData[$jsonKey]['sec_laststatus'] = 0;
                                $jsonData[$jsonKey]['oncallupdate'] = FALSE;

                                // Break time: agent just logged in, no active break
                                $jsonData[$jsonKey]['isbreakpause'] = false;
                                $jsonData[$jsonKey]['sec_breaks'] = $jsonData[$jsonKey]['sec_breaks_completed'];

                                // Estado del cliente
                                $estadoCliente[$jsonKey]['status'] = $jsonData[$jsonKey]['status'];
                                $estadoCliente[$jsonKey]['oncallupdate'] = $jsonData[$jsonKey]['oncallupdate'];

                                // Estado a emitir al cliente
                                $respuesta[$jsonKey] = $jsonData[$jsonKey];
                                unset($respuesta[$jsonKey]['agentname']);
                            }
                        }
                    }
                    break;
                case 'agentloggedout':
                    foreach (array_keys($estadoMonitor) as $sQueue) {
                        if (isset($estadoMonitor[$sQueue][$sCanalAgente])) {
                            $jsonKey = 'queue-'.$sQueue.'-member-'.$sNumeroAgente;
                            if (isset($jsonData[$jsonKey]) && $jsonData[$jsonKey]['status'] != 'offline') {

                                // Estado en el estado de monitor
                                $estadoMonitor[$sQueue][$sCanalAgente]['agentstatus'] = 'offline';
                                $estadoMonitor[$sQueue][$sCanalAgente]['lastsessionend'] = date('Y-m-d H:i:s', $iTimestampActual);
                                if (!is_null($estadoMonitor[$sQueue][$sCanalAgente]['lastpausestart']) &&
                                    is_null($estadoMonitor[$sQueue][$sCanalAgente]['lastpauseend'])) {
                                    $estadoMonitor[$sQueue][$sCanalAgente]['lastpauseend'] = date('Y-m-d H:i:s', $iTimestampActual);
                                }
                                $estadoMonitor[$sQueue][$sCanalAgente]['linkstart'] = NULL;
                                if (!is_null($estadoMonitor[$sQueue][$sCanalAgente]['lastsessionstart'])) {
                                    $iTimestampInicio = strtotime($estadoMonitor[$sQueue][$sCanalAgente]['lastsessionstart']);
                                    $iDuracionSesion =  $iTimestampActual - $iTimestampInicio;
                                    if ($iDuracionSesion >= 0) {
                                    	$estadoMonitor[$sQueue][$sCanalAgente]['logintime'] += $iDuracionSesion;
                                    }
                                }

                                // Estado en la estructura JSON
                                $jsonData[$jsonKey]['status'] = $estadoMonitor[$sQueue][$sCanalAgente]['agentstatus'];
                                $jsonData[$jsonKey]['sec_laststatus'] = 0;
                                $jsonData[$jsonKey]['oncallupdate'] = FALSE;
                                $jsonData[$jsonKey]['logintime'] = $estadoMonitor[$sQueue][$sCanalAgente]['logintime'];

                                // Break time: if agent was on a break-type pause, add its duration
                                if ($jsonData[$jsonKey]['isbreakpause']
                                    && !is_null($estadoMonitor[$sQueue][$sCanalAgente]['lastpausestart'])) {
                                    $iBreakDur = $iTimestampActual - strtotime($estadoMonitor[$sQueue][$sCanalAgente]['lastpausestart']);
                                    if ($iBreakDur > 0) $jsonData[$jsonKey]['sec_breaks_completed'] += $iBreakDur;
                                }
                                $jsonData[$jsonKey]['isbreakpause'] = false;
                                $jsonData[$jsonKey]['sec_breaks'] = $jsonData[$jsonKey]['sec_breaks_completed'];

                                // Estado del cliente
                                $estadoCliente[$jsonKey]['status'] = $jsonData[$jsonKey]['status'];
                                $estadoCliente[$jsonKey]['oncallupdate'] = $jsonData[$jsonKey]['oncallupdate'];

                                // Estado a emitir al cliente
                                $respuesta[$jsonKey] = $jsonData[$jsonKey];
                                unset($respuesta[$jsonKey]['agentname']);
                            }
                        }
                    }
                    break;
                case 'pausestart':
                    foreach (array_keys($estadoMonitor) as $sQueue) {
                        if (isset($estadoMonitor[$sQueue][$sCanalAgente])) {
                            $jsonKey = 'queue-'.$sQueue.'-member-'.$sNumeroAgente;
                            if (isset($jsonData[$jsonKey]) && $jsonData[$jsonKey]['status'] != 'offline') {

                                // Estado en el estado de monitor
                                if ($estadoMonitor[$sQueue][$sCanalAgente]['agentstatus'] != 'oncall')
                                    $estadoMonitor[$sQueue][$sCanalAgente]['agentstatus'] = 'paused';
                                $estadoMonitor[$sQueue][$sCanalAgente]['lastpausestart'] = date('Y-m-d H:i:s', $iTimestampActual);
                                $estadoMonitor[$sQueue][$sCanalAgente]['lastpauseend'] = NULL;

                                // Estado en la estructura JSON
                                $jsonData[$jsonKey]['status'] = $estadoMonitor[$sQueue][$sCanalAgente]['agentstatus'];
                                if ($jsonData[$jsonKey]['status'] == 'oncall') {
                                    if (!is_null($estadoMonitor[$sQueue][$sCanalAgente]['linkstart'])) {
                                        $iTimestampInicio = strtotime($estadoMonitor[$sQueue][$sCanalAgente]['linkstart']);
                                        $iDuracionLlamada = $iTimestampActual - $iTimestampInicio;
                                        if ($iDuracionLlamada >= 0) {
                                            $jsonData[$jsonKey]['sec_laststatus'] = $iDuracionLlamada;
                                            $jsonData[$jsonKey]['sec_calls'] =
                                                $estadoMonitor[$sQueue][$sCanalAgente]['sec_calls'] + $iDuracionLlamada;
                                        }
                                    }
                                } else {
                                    $jsonData[$jsonKey]['sec_laststatus'] = 0;
                                }
                                $jsonData[$jsonKey]['logintime'] = $estadoMonitor[$sQueue][$sCanalAgente]['logintime'];
                                if (!is_null($estadoMonitor[$sQueue][$sCanalAgente]['lastsessionstart'])) {
                                    $iTimestampInicio = strtotime($estadoMonitor[$sQueue][$sCanalAgente]['lastsessionstart']);
                                    $iDuracionSesion =  $iTimestampActual - $iTimestampInicio;
                                    if ($iDuracionSesion >= 0) {
                                        $jsonData[$jsonKey]['logintime'] += $iDuracionSesion;
                                    }
                                }

                                // Estado del cliente
                                $estadoCliente[$jsonKey]['status'] = $jsonData[$jsonKey]['status'];
                                $estadoCliente[$jsonKey]['oncallupdate'] = $jsonData[$jsonKey]['oncallupdate'];

                                // Nombre de la pausa
                                $jsonData[$jsonKey]['pausename'] = $evento['pause_name'];

                                // Break time: determine if this is a break-type pause
                                $holdNames = isset($breakData['holdNames']) ? $breakData['holdNames'] : array();
                                $jsonData[$jsonKey]['isbreakpause'] = ($jsonData[$jsonKey]['status'] == 'paused'
                                    && !in_array($evento['pause_name'], $holdNames));
                                $jsonData[$jsonKey]['sec_breaks'] = $jsonData[$jsonKey]['sec_breaks_completed'];
                                if ($jsonData[$jsonKey]['isbreakpause']) {
                                    // Break just started, duration is 0 at this instant
                                    $jsonData[$jsonKey]['sec_breaks'] += max(0,
                                        $iTimestampActual - strtotime($estadoMonitor[$sQueue][$sCanalAgente]['lastpausestart']));
                                }

                                // Estado a emitir al cliente
                                $respuesta[$jsonKey] = $jsonData[$jsonKey];
                                unset($respuesta[$jsonKey]['agentname']);
                            }
                        }
                    }
                    break;
                case 'pauseend':
                    // Determine if the ended pause was break-type (check before modifying state)
                    $bBreakPauseEnded = false;
                    $iBreakPauseDuration = 0;
                    foreach (array_keys($estadoMonitor) as $sCheckQueue) {
                        if (isset($estadoMonitor[$sCheckQueue][$sCanalAgente])) {
                            $sCheckKey = 'queue-'.$sCheckQueue.'-member-'.$sNumeroAgente;
                            if (isset($jsonData[$sCheckKey]) && $jsonData[$sCheckKey]['isbreakpause']) {
                                $bBreakPauseEnded = true;
                                if (!is_null($estadoMonitor[$sCheckQueue][$sCanalAgente]['lastpausestart'])) {
                                    $iBreakPauseDuration = max(0,
                                        $iTimestampActual - strtotime($estadoMonitor[$sCheckQueue][$sCanalAgente]['lastpausestart']));
                                }
                                break;
                            }
                        }
                    }

                    foreach (array_keys($estadoMonitor) as $sQueue) {
                        if (isset($estadoMonitor[$sQueue][$sCanalAgente])) {
                            $jsonKey = 'queue-'.$sQueue.'-member-'.$sNumeroAgente;
                            if (isset($jsonData[$jsonKey]) && $jsonData[$jsonKey]['status'] != 'offline') {

                                // Estado en el estado de monitor
                                if ($estadoMonitor[$sQueue][$sCanalAgente]['agentstatus'] != 'oncall')
                                    $estadoMonitor[$sQueue][$sCanalAgente]['agentstatus'] = 'online';
                                $estadoMonitor[$sQueue][$sCanalAgente]['lastpauseend'] = date('Y-m-d H:i:s', $iTimestampActual);

                                // Estado en la estructura JSON
                                $jsonData[$jsonKey]['status'] = $estadoMonitor[$sQueue][$sCanalAgente]['agentstatus'];
                                if ($jsonData[$jsonKey]['status'] == 'oncall') {
                                    if (!is_null($estadoMonitor[$sQueue][$sCanalAgente]['linkstart'])) {
                                        $iTimestampInicio = strtotime($estadoMonitor[$sQueue][$sCanalAgente]['linkstart']);
                                        $iDuracionLlamada = $iTimestampActual - $iTimestampInicio;
                                        if ($iDuracionLlamada >= 0) {
                                            $jsonData[$jsonKey]['sec_laststatus'] = $iDuracionLlamada;
                                            $jsonData[$jsonKey]['sec_calls'] =
                                                $estadoMonitor[$sQueue][$sCanalAgente]['sec_calls'] + $iDuracionLlamada;
                                        }
                                    }
                                } else {
                                    $jsonData[$jsonKey]['sec_laststatus'] =
                                        $iTimestampActual - strtotime($estadoMonitor[$sQueue][$sCanalAgente]['lastsessionstart']);
                                }
                                $jsonData[$jsonKey]['logintime'] = $estadoMonitor[$sQueue][$sCanalAgente]['logintime'];
                                if (!is_null($estadoMonitor[$sQueue][$sCanalAgente]['lastsessionstart'])) {
                                    $iTimestampInicio = strtotime($estadoMonitor[$sQueue][$sCanalAgente]['lastsessionstart']);
                                    $iDuracionSesion =  $iTimestampActual - $iTimestampInicio;
                                    if ($iDuracionSesion >= 0) {
                                        $jsonData[$jsonKey]['logintime'] += $iDuracionSesion;
                                    }
                                }

                                // Break time: add completed break duration if it was break-type
                                if ($bBreakPauseEnded && $iBreakPauseDuration > 0) {
                                    $jsonData[$jsonKey]['sec_breaks_completed'] += $iBreakPauseDuration;
                                }
                                $jsonData[$jsonKey]['isbreakpause'] = false;
                                $jsonData[$jsonKey]['sec_breaks'] = $jsonData[$jsonKey]['sec_breaks_completed'];

                                // Estado del cliente
                                $estadoCliente[$jsonKey]['status'] = $jsonData[$jsonKey]['status'];
                                $estadoCliente[$jsonKey]['oncallupdate'] = $jsonData[$jsonKey]['oncallupdate'];

                                // Estado a emitir al cliente
                                $respuesta[$jsonKey] = $jsonData[$jsonKey];
                                unset($respuesta[$jsonKey]['agentname']);
                            }
                        }
                    }
                    break;
                case 'agentlinked':
                    // Averiguar la cola por la que entró la llamada nueva
                    $sCallQueue = $evento['queue'];
                    if (is_null($sCallQueue)) {
                    	$infoCampania = $oPaloConsola->leerInfoCampania(
                            $evento['call_type'],
                            $evento['campaign_id']);
                        if (!is_null($infoCampania)) $sCallQueue = $infoCampania['queue'];
                    }

                    foreach (array_keys($estadoMonitor) as $sQueue) {
                        if (isset($estadoMonitor[$sQueue][$sCanalAgente])) {
                            $jsonKey = 'queue-'.$sQueue.'-member-'.$sNumeroAgente;
                            if (isset($jsonData[$jsonKey]) && $jsonData[$jsonKey]['status'] != 'offline') {

                                // Estado en el estado de monitor
                                $estadoMonitor[$sQueue][$sCanalAgente]['agentstatus'] = 'oncall';
                                $estadoMonitor[$sQueue][$sCanalAgente]['linkstart'] = NULL;
                                if ($sCallQueue == $sQueue) {
                                    $estadoMonitor[$sQueue][$sCanalAgente]['num_calls']++;
                                    $estadoMonitor[$sQueue][$sCanalAgente]['linkstart'] = $evento['datetime_linkstart'];
                                }

                                // Estado en la estructura JSON
                                $jsonData[$jsonKey]['status'] = $estadoMonitor[$sQueue][$sCanalAgente]['agentstatus'];
                                $jsonData[$jsonKey]['sec_laststatus'] =
                                    is_null($estadoMonitor[$sQueue][$sCanalAgente]['linkstart'])
                                        ? NULL
                                        : $iTimestampActual - strtotime($estadoMonitor[$sQueue][$sCanalAgente]['linkstart']);
                                $jsonData[$jsonKey]['num_calls'] = $estadoMonitor[$sQueue][$sCanalAgente]['num_calls'];
                                $jsonData[$jsonKey]['sec_calls'] = $estadoMonitor[$sQueue][$sCanalAgente]['sec_calls'] +
                                    (is_null($jsonData[$jsonKey]['sec_laststatus'])
                                        ? 0
                                        : $jsonData[$jsonKey]['sec_laststatus']);
                                $jsonData[$jsonKey]['oncallupdate'] = !is_null($estadoMonitor[$sQueue][$sCanalAgente]['linkstart']);
                                $jsonData[$jsonKey]['logintime'] = $estadoMonitor[$sQueue][$sCanalAgente]['logintime'];
                                if (!is_null($estadoMonitor[$sQueue][$sCanalAgente]['lastsessionstart'])) {
                                    $iTimestampInicio = strtotime($estadoMonitor[$sQueue][$sCanalAgente]['lastsessionstart']);
                                    $iDuracionSesion =  $iTimestampActual - $iTimestampInicio;
                                    if ($iDuracionSesion >= 0) {
                                        $jsonData[$jsonKey]['logintime'] += $iDuracionSesion;
                                    }
                                }

                                // Break time: recalculate at event time, freeze timer during call
                                $jsonData[$jsonKey]['sec_breaks'] = $jsonData[$jsonKey]['sec_breaks_completed'];
                                if ($jsonData[$jsonKey]['isbreakpause']
                                    && !is_null($estadoMonitor[$sQueue][$sCanalAgente]['lastpausestart'])
                                    && is_null($estadoMonitor[$sQueue][$sCanalAgente]['lastpauseend'])) {
                                    $jsonData[$jsonKey]['sec_breaks'] += max(0,
                                        $iTimestampActual - strtotime($estadoMonitor[$sQueue][$sCanalAgente]['lastpausestart']));
                                }
                                $jsonData[$jsonKey]['isbreakpause'] = false;

                                // Estado del cliente
                                $estadoCliente[$jsonKey]['status'] = $jsonData[$jsonKey]['status'];
                                $estadoCliente[$jsonKey]['oncallupdate'] = $jsonData[$jsonKey]['oncallupdate'];

                                // Estado a emitir al cliente
                                $respuesta[$jsonKey] = $jsonData[$jsonKey];
                                unset($respuesta[$jsonKey]['agentname']);
                            }
                        }
                    }
                    break;
                case 'agentunlinked':
                    foreach (array_keys($estadoMonitor) as $sQueue) {
                        if (isset($estadoMonitor[$sQueue][$sCanalAgente])) {
                            $jsonKey = 'queue-'.$sQueue.'-member-'.$sNumeroAgente;
                            if (isset($jsonData[$jsonKey]) && $jsonData[$jsonKey]['status'] != 'offline') {

                                // Estado en el estado de monitor
                                $estadoMonitor[$sQueue][$sCanalAgente]['agentstatus'] =
                                    (!is_null($estadoMonitor[$sQueue][$sCanalAgente]['lastpausestart']) && is_null($estadoMonitor[$sQueue][$sCanalAgente]['lastpauseend']))
                                    ? 'paused' : 'online';
                                if (!is_null($estadoMonitor[$sQueue][$sCanalAgente]['linkstart'])) {
                                	$iTimestampInicio = strtotime($estadoMonitor[$sQueue][$sCanalAgente]['linkstart']);
                                    $iDuracionLlamada = $iTimestampActual - $iTimestampInicio;
                                    if ($iDuracionLlamada >= 0) {
                                    	$estadoMonitor[$sQueue][$sCanalAgente]['sec_calls'] += $iDuracionLlamada;
                                    }
                                }
                                $estadoMonitor[$sQueue][$sCanalAgente]['linkstart'] = NULL;

                                // Estado en la estructura JSON
                                $jsonData[$jsonKey]['status'] = $estadoMonitor[$sQueue][$sCanalAgente]['agentstatus'];
                                if ($jsonData[$jsonKey]['status'] == 'paused') {
                                    $jsonData[$jsonKey]['sec_laststatus'] =
                                        $iTimestampActual - strtotime($estadoMonitor[$sQueue][$sCanalAgente]['lastpausestart']);
                                } else {
                                    $jsonData[$jsonKey]['sec_laststatus'] =
                                        $iTimestampActual - strtotime($estadoMonitor[$sQueue][$sCanalAgente]['lastsessionstart']);
                                }
                                $jsonData[$jsonKey]['num_calls'] = $estadoMonitor[$sQueue][$sCanalAgente]['num_calls'];
                                $jsonData[$jsonKey]['sec_calls'] = $estadoMonitor[$sQueue][$sCanalAgente]['sec_calls'];
                                $jsonData[$jsonKey]['oncallupdate'] = FALSE;
                                $jsonData[$jsonKey]['logintime'] = $estadoMonitor[$sQueue][$sCanalAgente]['logintime'];
                                if (!is_null($estadoMonitor[$sQueue][$sCanalAgente]['lastsessionstart'])) {
                                    $iTimestampInicio = strtotime($estadoMonitor[$sQueue][$sCanalAgente]['lastsessionstart']);
                                    $iDuracionSesion =  $iTimestampActual - $iTimestampInicio;
                                    if ($iDuracionSesion >= 0) {
                                        $jsonData[$jsonKey]['logintime'] += $iDuracionSesion;
                                    }
                                }

                                // Break time: check if agent returns to a break-type pause
                                if ($jsonData[$jsonKey]['status'] == 'paused') {
                                    $sPauseName = isset($jsonData[$jsonKey]['pausename']) ? $jsonData[$jsonKey]['pausename'] : null;
                                    $holdNames = isset($breakData['holdNames']) ? $breakData['holdNames'] : array();
                                    $jsonData[$jsonKey]['isbreakpause'] = !is_null($sPauseName) && !in_array($sPauseName, $holdNames);
                                } else {
                                    $jsonData[$jsonKey]['isbreakpause'] = false;
                                }
                                $jsonData[$jsonKey]['sec_breaks'] = $jsonData[$jsonKey]['sec_breaks_completed'];
                                if ($jsonData[$jsonKey]['isbreakpause']
                                    && !is_null($estadoMonitor[$sQueue][$sCanalAgente]['lastpausestart'])
                                    && is_null($estadoMonitor[$sQueue][$sCanalAgente]['lastpauseend'])) {
                                    $jsonData[$jsonKey]['sec_breaks'] += max(0,
                                        $iTimestampActual - strtotime($estadoMonitor[$sQueue][$sCanalAgente]['lastpausestart']));
                                }

                                // Estado del cliente
                                $estadoCliente[$jsonKey]['status'] = $jsonData[$jsonKey]['status'];
                                $estadoCliente[$jsonKey]['oncallupdate'] = $jsonData[$jsonKey]['oncallupdate'];

                                // Estado a emitir al cliente
                                $respuesta[$jsonKey] = $jsonData[$jsonKey];
                                unset($respuesta[$jsonKey]['agentname']);
                            }
                        }
                    }
                    break;
                case 'agentstatechange':
                    // Real-time agent state change (e.g., ringing status)
                    $sQueue = $evento['queue'];
                    $sNewStatus = $evento['status'];
                    if (isset($estadoMonitor[$sQueue][$sCanalAgente])) {
                        $jsonKey = 'queue-'.$sQueue.'-member-'.$sNumeroAgente;
                        if (isset($jsonData[$jsonKey]) && $jsonData[$jsonKey]['status'] != $sNewStatus) {
                            // Update monitor state
                            $estadoMonitor[$sQueue][$sCanalAgente]['agentstatus'] = $sNewStatus;

                            // Update JSON data
                            $jsonData[$jsonKey]['status'] = $sNewStatus;
                            $jsonData[$jsonKey]['sec_laststatus'] = 0;

                            // Update client state
                            $estadoCliente[$jsonKey]['status'] = $sNewStatus;

                            // Emit to client
                            $respuesta[$jsonKey] = $jsonData[$jsonKey];
                            unset($respuesta[$jsonKey]['agentname']);
                        }
                    }
                    break;
            	}
            }


        }
        if (count($respuesta) > 0) {
            @session_start();
            $estadoHash = generarEstadoHash($module_name, $estadoCliente);
            $respuesta['estadoClienteHash'] = $estadoHash;
            session_commit();
        }
        jsonflush($bSSE, $respuesta);

        $respuesta = array();

    } while ($bSSE && connection_status() == CONNECTION_NORMAL);
    $oPaloConsola->desconectarTodo();
}


?>