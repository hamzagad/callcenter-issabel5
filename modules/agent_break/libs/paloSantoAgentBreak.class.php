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
  $Id: paloSantoAgentBreak.class.php $ */

require_once('libs/paloSantoDB.class.php');

class paloSantoAgentBreak
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
     * Read break records for agents within a date range.
     *
     * @param string $sTipo Report type: 'D' for Detailed, 'G' for General
     * @param string $sFechaInicio Start date in format 'yyyy-mm-dd hh:mm:ss'
     * @param string $sFechaFin End date in format 'yyyy-mm-dd hh:mm:ss'
     * @param bool $bHoldIncluded Whether to include Hold type breaks
     * @return array|null Array of break records, or NULL on error
     */
    function leerRegistrosBreak($sTipo, $sFechaInicio, $sFechaFin, $bHoldIncluded = false)
    {
        if (!in_array($sTipo, array('D', 'G'))) {
            $this->errMsg = _tr('(internal) Invalid detail flag, must be D or G');
            return NULL;
        }
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

        // Build query with optional Hold filter
        $holdFilter = $bHoldIncluded ? '' : "AND break.tipo = 'B'";

        $sqlRegistros = <<<SQL_REGISTROS
SELECT
    agent.id,
    agent.number,
    agent.name,
    break.id AS break_id,
    break.name AS break_name,
    audit.datetime_init,
    IF(audit.datetime_end IS NULL, NOW(), audit.datetime_end) AS datetime_end,
    TIME_TO_SEC(IF(audit.duration IS NULL, TIMEDIFF(NOW(), audit.datetime_init), audit.duration)) AS duration,
    IF(audit.datetime_end IS NULL, 'ACTIVE', '') AS estado
FROM audit
JOIN agent ON audit.id_agent = agent.id
JOIN break ON audit.id_break = break.id
WHERE audit.datetime_init BETWEEN ? AND ?
    AND audit.id_break IS NOT NULL
    $holdFilter
ORDER BY agent.name, break.name, audit.datetime_init
SQL_REGISTROS;

        $recordset = $this->_DB->fetchTable($sqlRegistros, TRUE, array($sFechaInicio, $sFechaFin));
        if (!is_array($recordset)) {
            $this->errMsg = $this->_DB->errMsg;
            return NULL;
        }

        // Track potentially corrupted records (open breaks with newer records)
        $ultimoActivo = array();
        foreach (array_keys($recordset) as $i) {
            $agentBreakKey = $recordset[$i]['id'] . '_' . $recordset[$i]['break_id'];

            if (isset($ultimoActivo[$agentBreakKey]) &&
                $recordset[$i]['datetime_init'] > $ultimoActivo[$agentBreakKey]['datetime_init']) {
                // Previous record is corrupted
                $iPosCorrompido = $ultimoActivo[$agentBreakKey]['index'];
                $recordset[$iPosCorrompido]['estado'] = 'CORRUPTED';
                $recordset[$iPosCorrompido]['datetime_end'] = NULL;
                $recordset[$iPosCorrompido]['duration'] = 0;
                unset($ultimoActivo[$agentBreakKey]);
            }
            if ($recordset[$i]['estado'] == 'ACTIVE') {
                $ultimoActivo[$agentBreakKey] = array(
                    'index' => $i,
                    'datetime_init' => $recordset[$i]['datetime_init'],
                );
            }
        }

        // Check for future records that would indicate corruption
        foreach ($ultimoActivo as $agentBreakKey => $agentActivo) {
            list($agentId, $breakId) = explode('_', $agentBreakKey);
            $tuple = $this->_DB->getFirstRowQuery(
                'SELECT COUNT(*) AS N FROM audit ' .
                'WHERE id_agent = ? AND id_break = ? AND datetime_init > ?',
                TRUE, array($agentId, $breakId, $agentActivo['datetime_init']));
            if (!is_array($tuple)) {
                $this->errMsg = $this->_DB->errMsg;
                return NULL;
            }
            if ($tuple['N'] > 0) {
                $iPosCorrompido = $agentActivo['index'];
                $recordset[$iPosCorrompido]['estado'] = 'CORRUPTED';
                $recordset[$iPosCorrompido]['datetime_end'] = NULL;
                $recordset[$iPosCorrompido]['duration'] = 0;
            }
        }

        // For detailed report, return recordset as-is
        if ($sTipo == 'D') return $recordset;

        // For general report, aggregate by agent + break type
        $agrupacion = array();
        foreach ($recordset as $tupla) {
            $key = $tupla['id'] . '_' . $tupla['break_id'];
            if (!isset($agrupacion[$key])) {
                $agrupacion[$key] = $tupla;
            } else {
                $agrupacion[$key]['duration'] += $tupla['duration'];
                if ($agrupacion[$key]['datetime_init'] > $tupla['datetime_init'])
                    $agrupacion[$key]['datetime_init'] = $tupla['datetime_init'];
                if ($agrupacion[$key]['datetime_end'] < $tupla['datetime_end'])
                    $agrupacion[$key]['datetime_end'] = $tupla['datetime_end'];
                // Keep ACTIVE status if any break is still active
                if ($tupla['estado'] == 'ACTIVE')
                    $agrupacion[$key]['estado'] = $tupla['estado'];
            }
        }
        return array_values($agrupacion);
    }
}
?>
