<?php
/* vim: set expandtab tabstop=4 softtabstop=4 shiftwidth=4:
  Codificación: UTF-8
  +----------------------------------------------------------------------+
  | Issabel version 0.5                                                  |
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
  $Id: paloSantoAgentJourney.class.php $ */

require_once('libs/paloSantoDB.class.php');

class paloSantoAgentJourney
{
    private $_DB;
    var $errMsg;

    function __construct(&$pDB)
    {
        if (is_object($pDB)) {
            $this->_DB =& $pDB;
            $this->errMsg = $this->_DB->errMsg;
        } else {
            $dsn = (string)$pDB;
            $this->_DB = new paloDB($dsn);

            if (!$this->_DB->connStatus) {
                $this->errMsg = $this->_DB->errMsg;
            }
        }
    }

    /**
     * Get list of agents for dropdown
     * @return array|null Array of agents or NULL on error
     */
    function getAgents()
    {
        $recordset = $this->_DB->fetchTable(
            'SELECT id, number, name, estatus FROM agent ORDER BY estatus, number',
            TRUE);
        if (!is_array($recordset)) {
            $this->errMsg = '(internal) Failed to fetch agents - '.$this->_DB->errMsg;
            return NULL;
        }
        return $recordset;
    }

    /**
     * Get agent journey (all events) within a date range
     *
     * @param string $sFechaInicio Start date in format 'yyyy-mm-dd hh:mm:ss'
     * @param string $sFechaFin End date in format 'yyyy-mm-dd hh:mm:ss'
     * @param int|null $idAgent Agent ID to filter (NULL for all agents)
     * @param bool $bHoldIncluded Whether to include Hold type breaks
     * @return array|null Array of events or NULL on error
     */
    function getAgentJourney($sFechaInicio, $sFechaFin, $idAgent = NULL, $bHoldIncluded = false)
    {
        $sRegexp = '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/';
        if (!preg_match($sRegexp, $sFechaInicio)) {
            $this->errMsg = _tr('(internal) Invalid start date, must be yyyy-mm-dd hh:mm:ss');
            return NULL;
        }
        if (!preg_match($sRegexp, $sFechaFin)) {
            $this->errMsg = _tr('(internal) Invalid end date, must be yyyy-mm-dd hh:mm:ss');
            return NULL;
        }
        if ($sFechaFin < $sFechaInicio) {
            $t = $sFechaFin;
            $sFechaFin = $sFechaInicio;
            $sFechaInicio = $t;
        }

        // Build agent filter condition
        $agentFilter = '';
        $params = array();

        if ($idAgent !== NULL) {
            $agentFilter = 'AND agent.id = ?';
        }

        // Build hold filter for break events
        $holdFilter = $bHoldIncluded ? '' : "AND break.tipo = 'B'";

        // Login events
        $sqlLogin = "
            SELECT agent.id, agent.number, agent.name,
                audit.datetime_init AS event_time,
                'LOGIN' AS event_type,
                NULL AS event_detail,
                NULL AS duration
            FROM audit
            JOIN agent ON audit.id_agent = agent.id
            WHERE audit.id_break IS NULL
                AND audit.datetime_init BETWEEN ? AND ?
                $agentFilter";

        // Logout events
        $sqlLogout = "
            SELECT agent.id, agent.number, agent.name,
                audit.datetime_end AS event_time,
                'LOGOUT' AS event_type,
                NULL AS event_detail,
                TIME_TO_SEC(audit.duration) AS duration
            FROM audit
            JOIN agent ON audit.id_agent = agent.id
            WHERE audit.id_break IS NULL
                AND audit.datetime_end IS NOT NULL
                AND audit.datetime_end BETWEEN ? AND ?
                $agentFilter";

        // Break events (single row)
        $sqlBreak = "
            SELECT agent.id, agent.number, agent.name,
                audit.datetime_init AS event_time,
                'BREAK' AS event_type,
                break.name AS event_detail,
                TIME_TO_SEC(IFNULL(audit.duration, TIMEDIFF(NOW(), audit.datetime_init))) AS duration
            FROM audit
            JOIN agent ON audit.id_agent = agent.id
            JOIN break ON audit.id_break = break.id
            WHERE audit.id_break IS NOT NULL
                AND audit.datetime_init BETWEEN ? AND ?
                $holdFilter
                $agentFilter";

        // Incoming call events
        $sqlIncoming = "
            SELECT agent.id, agent.number, agent.name,
                call_entry.datetime_init AS event_time,
                'INCOMING_CALL' AS event_type,
                CONCAT(queue_call_entry.queue, ' <- ', IFNULL(call_entry.callerid, 'Unknown')) AS event_detail,
                call_entry.duration AS duration
            FROM call_entry
            JOIN agent ON call_entry.id_agent = agent.id
            JOIN queue_call_entry ON call_entry.id_queue_call_entry = queue_call_entry.id
            WHERE call_entry.id_agent IS NOT NULL
                AND call_entry.datetime_init BETWEEN ? AND ?
                $agentFilter";

        // Outgoing call events
        $sqlOutgoing = "
            SELECT agent.id, agent.number, agent.name,
                calls.start_time AS event_time,
                'OUTGOING_CALL' AS event_type,
                CONCAT(calls.phone, ' (', IFNULL(calls.status, 'Unknown'), ')') AS event_detail,
                calls.duration AS duration
            FROM calls
            JOIN agent ON calls.id_agent = agent.id
            WHERE calls.id_agent IS NOT NULL
                AND calls.start_time BETWEEN ? AND ?
                $agentFilter";

        // Combine all queries with UNION
        $sqlFull = "($sqlLogin) UNION ALL ($sqlLogout) UNION ALL ($sqlBreak) UNION ALL ($sqlIncoming) UNION ALL ($sqlOutgoing) ORDER BY event_time ASC";

        // Build parameters array (each query needs date range + optional agent)
        for ($i = 0; $i < 5; $i++) {
            $params[] = $sFechaInicio;
            $params[] = $sFechaFin;
            if ($idAgent !== NULL) {
                $params[] = $idAgent;
            }
        }

        $recordset = $this->_DB->fetchTable($sqlFull, TRUE, $params);
        if (!is_array($recordset)) {
            $this->errMsg = $this->_DB->errMsg;
            return NULL;
        }

        return $recordset;
    }
}
?>
