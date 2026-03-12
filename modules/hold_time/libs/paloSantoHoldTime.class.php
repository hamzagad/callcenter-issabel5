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
  $Id: new_campaign.php $ */

include_once("libs/paloSantoDB.class.php");

class paloSantoHoldTime
{
    var $_DB; // instancia de la clase paloDB // EN: paloDB class instance
    var $errMsg;

    function __construct(&$pDB)
    {
        // Se recibe como parámetro una referencia a una conexión paloDB
        // EN: A reference to a paloDB connection is received as a parameter
        if (is_object($pDB)) {
            $this->_DB =& $pDB;
            $this->errMsg = $this->_DB->errMsg;
        } else {
            $dsn = (string)$pDB;
            $this->_DB = new paloDB($dsn);

            if (!$this->_DB->connStatus) {
                $this->errMsg = $this->_DB->errMsg;
                // debo llenar alguna variable de error
                // EN: I must fill some error variable
            } else {
                // debo llenar alguna variable de error
                // EN: I must fill some error variable
            }
        }
    }

    /**
     * Procedimiento para construir un histograma de los tiempos de espera de las
     * llamadas en la cola de atención.
     * EN: Procedure to build a histogram of hold times for calls in the service queue.
     *
     * @param   string  $sTipoLlamada   Tipo de llamada a mostrar: 'incoming', 'outgoing'
     *                              EN: Call type to show: 'incoming', 'outgoing'
     * @param   string  $sEstadoLlamada Estado de las llamadas a considerar. Los
     *                              EN: Status of calls to consider. Values are
     *                  valores son NULL para cualquier llamada, 'Success' para
     *                              EN: NULL for any call, 'Success' for
     *                  las llamadas conectadas, 'NoAnswer' para llamadas que no
     *                              EN: connected calls, 'NoAnswer' for calls that
     *                  se conectaron pero que entraron en la cola (sólo llamadas
     *                              EN: did not connect but entered the queue (only
     *                  salientes), o 'Abandoned' para llamadas cuyo lado remoto
     *                              EN: outgoing calls), or 'Abandoned' for calls whose
     *                  cerró mientras esperaban en la cola.
     *                              EN: remote side closed while waiting in queue.
     * @param   string  $sFechaInicio   Inicio de periodo yyyy-mm-dd hh:mm:ss
     *                              EN: Period start yyyy-mm-dd hh:mm:ss
     * @param   string  $sFechaFinal    Final de periodo yyyy-mm-dd hh:mm:ss
     *                              EN: Period end yyyy-mm-dd hh:mm:ss
     * @param   int     Tamaño del intervalo del histograma. Como caso especial
     *                              EN: Histogram interval size. As a special case
     *                  el primer intervalo incluye al 0. Por omisión es 10.
     *                              EN: the first interval includes 0. Default is 10.
     * @param   int     Valor más allá del cual se coloca las contribuciones en
     *                              EN: Value beyond which contributions are placed
     *                  el último intervalo. Por omisión es 61.
     *                              EN: in the last interval. Default is 61.
     *
     * @return  mixed   NULL en caso de error, o la siguiente estructura:
     *                  EN: NULL on error, or the following structure:
     */
    function leerHistogramaEsperaCola($sTipoLlamada, $sEstadoLlamada, $sFechaInicio,
        $sFechaFinal, $iLongDiv = 10, $iValorDivFinal = 61)
    {
    	$sRegexpFecha = '/^\d{4}-\d{2}-\d{2}$/';
        if (!(preg_match($sRegexpFecha, $sFechaInicio) && preg_match($sRegexpFecha, $sFechaFinal))) {
            $this->errMsg = _tr('Invalid start or end date');
        	return NULL;
        }
        if (!in_array($sTipoLlamada, array('incoming', 'outgoing'))) {
            $this->errMsg = _tr('Invalid call type');
        	return NULL;
        }
        if (!in_array($sEstadoLlamada, array(NULL, 'Success', 'NoAnswer', 'Abandoned'))) {
            $this->errMsg = _tr('Invalid call state');
            return NULL;
        }
        if ($sFechaInicio > $sFechaFinal) { $t = $sFechaInicio; $sFechaInicio = $sFechaFinal; $sFechaFinal = $t; }
        
        $sFechaInicio .= ' 00:00:00';
        $sFechaFinal .= ' 23:59:59';

        // Construir petición WHERE
        // EN: Build WHERE request
        $whereCond = array('datetime_entry_queue >= ?');
        $paramSQL = array($sFechaInicio);
        if (!is_null($sEstadoLlamada)) {
            switch ($sEstadoLlamada) {
            case 'Success':
                $paramSQL[] = ($sTipoLlamada == 'incoming') ? 'terminada' : 'Success';
                break;
            case 'NoAnswer':
                $paramSQL[] = ($sTipoLlamada == 'incoming') ? 'abandonada' : 'NoAnswer';
                break;
            case 'Abandoned':
                $paramSQL[] = ($sTipoLlamada == 'incoming') ? 'abandonada' : 'Abandoned';
                break;
            }
            $whereCond[] = 'status = ?';
        }
        $sWhereCond = implode(' AND ', $whereCond);
        
        if ($sTipoLlamada == 'incoming') {
        	$sPeticionSQL = <<<SQL_INCOMING
SELECT queue_call_entry.queue, call_entry.duration_wait, COUNT(*) AS N
FROM call_entry, queue_call_entry
WHERE $sWhereCond AND call_entry.datetime_end <= ?
    AND call_entry.id_queue_call_entry = queue_call_entry.id
    AND call_entry.status IS NOT NULL
    AND call_entry.duration_wait IS NOT NULL
GROUP BY queue, duration_wait
SQL_INCOMING;
        } else {
            $sPeticionSQL = <<<SQL_OUTGOING
SELECT campaign.queue, calls.duration_wait, COUNT(*) AS N
FROM calls, campaign
WHERE $sWhereCond AND calls.end_time <= ?
    AND calls.id_campaign = campaign.id
    AND calls.status IS NOT NULL
    AND calls.duration_wait IS NOT NULL
GROUP BY queue, duration_wait
SQL_OUTGOING;
        }
        $paramSQL[] = $sFechaFinal;

        $arr_result = $this->_DB->fetchTable($sPeticionSQL, TRUE, $paramSQL);
        if (!is_array($arr_result)) {
            $this->errMsg = $this->_DB->errMsg;
            return NULL;
        }
        
        $resultado = array();
        $iPosMaxDiv = (int)(($iValorDivFinal) / $iLongDiv);
        foreach ($arr_result as $tupla) {
        	
            // Inicializar la estructura del histograma
            // EN: Initialize histogram structure
            if (!isset($resultado[$tupla['queue']])) {
                $resultado[$tupla['queue']] = array(
                    'hist'          =>  array_fill(0, $iPosMaxDiv + 1, 0),
                    'total_calls'   =>  0,
                    'total_wait'    =>  0,
                    'max_wait'      =>  0,
                );
        	}
            
            // Posición de la contribución de la muestra al histograma
            // EN: Position of sample contribution to histogram
            if ($tupla['duration_wait'] >= $iValorDivFinal)
                $iPos = $iPosMaxDiv;
            elseif ($tupla['duration_wait'] <= 0)
                $iPos = 0;
            else
                $iPos = (int)(($tupla['duration_wait'] - 1) / $iLongDiv);
            $resultado[$tupla['queue']]['hist'][$iPos] += $tupla['N'];
            
            // Actualización de los totales
            // EN: Update totals
            $resultado[$tupla['queue']]['total_calls'] += $tupla['N'];
            $resultado[$tupla['queue']]['total_wait'] += $tupla['duration_wait'] * $tupla['N'];
            if ($tupla['duration_wait'] > $resultado[$tupla['queue']]['max_wait'])
                $resultado[$tupla['queue']]['max_wait'] = $tupla['duration_wait'];
        }
        
        return $resultado;
    }
}
?>