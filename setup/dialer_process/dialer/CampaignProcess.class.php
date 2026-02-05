<?php
/* vim: set expandtab tabstop=4 softtabstop=4 shiftwidth=4:
  Codificación: UTF-8
  Encoding: UTF-8
  +----------------------------------------------------------------------+
  | Issabel version 1.2-2                                               |
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
  $Id: DialerProcess.class.php,v 1.48 2009/03/26 13:46:58 alex Exp $ */

// Número mínimo de muestras para poder confiar en predicciones de marcador
// Minimum number of samples to trust dialer predictions
define('MIN_MUESTRAS', 10);
define('INTERVALO_REVISION_CAMPANIAS', 3);

class CampaignProcess extends TuberiaProcess
{
    private $DEBUG = FALSE; // VERDADERO si se activa la depuración
                           // TRUE if debugging is enabled

    private $_log;      // Log abierto por framework de demonio
                        // Log opened by daemon framework
    private $_dsn;      // Cadena que representa el DSN, estilo PDO
                        // String representing the DSN, PDO style
    private $_db;       // Conexión a la base de datos, PDO
                        // Database connection, PDO
    private $_ami = NULL;       // Conexión AMI a Asterisk
                                // AMI connection to Asterisk
    private $_configDB; // Objeto de configuración desde la base de datos
                        // Configuration object from the database

    // Contadores para actividades ejecutadas regularmente
    // Counters for regularly executed activities
    private $_iTimestampUltimaRevisionCampanias = 0;    // Última revisión de campañas
                                                        // Last campaign review
    private $_iTimestampUltimaRevisionConfig = 0;       // Última revisión de configuración
                                                        // Last configuration review

    // Lista de campañas y colas que ya fueron avisadas a AMIEventProcess
    // List of campaigns and queues already notified to AMIEventProcess
    private $_campaniasAvisadas = array(
        'incoming'          =>  array(),
        'outgoing'          =>  array(),
        'incoming_id_queue' =>  array(),
    );

    // VERDADERO si existe tabla asterisk.trunks y se deben buscar troncales allí
    // TRUE if asterisk.trunks table exists and trunks must be searched there
    private $_existeTrunksFPBX = FALSE;

    /* Caché de información que fue leída para las troncales directas usadas en
     * marcación de campañas salientes desde la base de datos de FreePBX.
     * Cache of information read for direct trunks used in
     * outgoing campaign dialing from the FreePBX database.
     */
    private $_plantillasMarcado;

    // Estimación de la versión de Asterisk que se usa
    // Estimate of the Asterisk version being used
    private $_asteriskVersion = array(1, 4, 0, 0);
    private $_compat = NULL; // AsteriskCompat instance for version-aware behavior

    /* VERDADERO si al momento de verificar actividad en tubería, no habían
     * mensajes pendientes. Sólo cuando se esté ocioso se intentarán verificar
     * nuevas llamadas de la campaña.
     * TRUE if at the time of checking pipe activity there were no pending
     * messages. Only when idle will new campaign calls be verified.
     */
    private $_ociosoSinEventos = TRUE;

    /* Si se setea a VERDADERO, el programa intenta finalizar y no deben
     * colocarse nuevas llamadas.
     * If set to TRUE, the program attempts to finalize and no new calls
     * should be placed.
     */
    private $_finalizandoPrograma = FALSE;

    public function inicioPostDemonio($infoConfig, &$oMainLog)
    {
    	$this->_log = $oMainLog;
        $this->_multiplex = new MultiplexServer(NULL, $this->_log);
        $this->_tuberia->registrarMultiplexHijo($this->_multiplex);
        $this->_tuberia->setLog($this->_log);

        // Interpretar la configuración del demonio
        // Interpret daemon configuration
        $this->_dsn = $this->_interpretarConfiguracion($infoConfig);
        if (!$this->_iniciarConexionDB()) return FALSE;

        // Leer el resto de la configuración desde la base de datos
        // Read the rest of the configuration from the database
        try {
            $this->_configDB = new ConfigDB($this->_db, $this->_log);
        } catch (PDOException $e) {
            $this->_log->output("FATAL: no se puede leer configuración DB - ".$e->getMessage()." | EN: cannot read DB configuration - ".$e->getMessage());
            return FALSE;
        }

        // Recuperarse de cualquier fin anormal anterior
        // Recover from any previous abnormal termination
        try {
            $this->_db->query('DELETE FROM current_calls WHERE 1');
            $this->_db->query('DELETE FROM current_call_entry WHERE 1');
            $this->_db->query("UPDATE call_entry SET status = 'fin-monitoreo' WHERE datetime_end IS NULL AND status <> 'fin-monitoreo'");
        } catch (PDOException $e) {
            $this->_log->output("FATAL: error al limpiar tablas current_calls - ".$e->getMessage()." | EN: error when cleaning current_calls tables - ".$e->getMessage());
        	return FALSE;
        }

        // Detectar capacidades de FreePBX y de call_center
        // Detect FreePBX and call_center capabilities
        $this->_detectarTablaTrunksFPBX();

        // Iniciar la conexión Asterisk
        // Start Asterisk connection
        if (!$this->_iniciarConexionAMI()) return FALSE;

        // Registro de manejadores de eventos desde AMIEventProcess
        // Register event handlers from AMIEventProcess
        foreach (array('verificarFinLlamadasAgendables',) as $k)
            $this->_tuberia->registrarManejador('AMIEventProcess', $k, array($this, "msg_$k"));

        // Registro de manejadores de eventos desde HubProcess
        // Register event handlers from HubProcess
        $this->_tuberia->registrarManejador('HubProcess', 'finalizando', array($this, "msg_finalizando"));

        $this->DEBUG = $this->_configDB->dialer_debug;
        return TRUE;
    }

    private function _interpretarConfiguracion($infoConfig)
    {
        $dbHost = 'localhost';
        $dbUser = 'asterisk';
        $dbPass = 'asterisk';
        if (isset($infoConfig['database']) && isset($infoConfig['database']['dbhost'])) {
            $dbHost = $infoConfig['database']['dbhost'];
            $this->_log->output('Usando host de base de datos: '.$dbHost.' | EN: Using database host: '.$dbHost);
        } else {
            $this->_log->output('Usando host (por omisión) de base de datos: '.$dbHost.' | EN: Using (default) database host: '.$dbHost);
        }
        if (isset($infoConfig['database']) && isset($infoConfig['database']['dbuser']))
            $dbUser = $infoConfig['database']['dbuser'];
        if (isset($infoConfig['database']) && isset($infoConfig['database']['dbpass']))
            $dbPass = $infoConfig['database']['dbpass'];

        return array("mysql:host=$dbHost;dbname=call_center", $dbUser, $dbPass);
    }

    private function _iniciarConexionDB()
    {
    	try {
    		$this->_db = new PDO($this->_dsn[0], $this->_dsn[1], $this->_dsn[2]);
            $this->_db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->_db->setAttribute(PDO::ATTR_EMULATE_PREPARES, FALSE);
            return TRUE;
    	} catch (PDOException $e) {
            $this->_db = NULL;
            $this->_log->output("FATAL: no se puede conectar a DB - ".$e->getMessage()." | EN: cannot connect to DB - ".$e->getMessage());
    		return FALSE;
    	}
    }

    /**
     * Procedimiento que detecta la existencia de la tabla asterisk.trunks. Si
     * existe, la información de troncales está almacenada allí, y no en la
     * tabla globals. Esto se cumple en versiones recientes de FreePBX.
     * Procedure that detects the existence of the asterisk.trunks table. If
     * it exists, the trunk information is stored there, not in the globals
     * table. This is true in recent versions of FreePBX.
     *
     * @return void
     */
    private function _detectarTablaTrunksFPBX()
    {
        $dbConn = $this->_abrirConexionFreePBX();
        if (is_null($dbConn)) return;

        try {
        	$recordset = $dbConn->prepare("SHOW TABLES LIKE 'trunks'");
            $recordset->execute();
            $item = $recordset->fetch(PDO::FETCH_COLUMN, 0);
            $recordset->closeCursor();
            if ($item != 'trunks') {
                // Probablemente error de que asterisk.trunks no existe
                // Probably error that asterisk.trunks does not exist
            	$this->_log->output("INFO: tabla asterisk.trunks no existe, se asume FreePBX viejo. | EN: asterisk.trunks table does not exist, assuming old FreePBX.");
            } else {
                // asterisk.trunks existe
                // asterisk.trunks exists
                $this->_log->output("INFO: tabla asterisk.trunks sí existe, se asume FreePBX reciente. | EN: asterisk.trunks table does exist, assuming recent FreePBX.");
                $this->_existeTrunksFPBX = TRUE;
            }
        } catch (PDOException $e) {
        	$this->_log->output("ERR: al consultar tabla de troncales: ".implode(' - ', $e->errorInfo)." | EN: querying trunk table: ".implode(' - ', $e->errorInfo));
        }
        $dbConn = NULL;
    }

    public function procedimientoDemonio()
    {
        // Verificar posible desconexión de la base de datos
        // Verify possible database disconnection
        if (is_null($this->_db)) {
            $this->_log->output('INFO: intentando volver a abrir conexión a DB... | EN: trying to reopen DB connection...');
            if (!$this->_iniciarConexionDB()) {
                $this->_log->output('ERR: no se puede restaurar conexión a DB, se espera... | EN: cannot restore DB connection, waiting...');
                usleep(5000000);
            } else {
                $this->_log->output('INFO: conexión a DB restaurada, se reinicia operación normal. | EN: DB connection restored, resuming normal operation.');
                $this->_configDB->setDBConn($this->_db);
            }
        }

        // Verificar si la conexión AMI sigue siendo válida
        // Verify if the AMI connection is still valid
        if (!is_null($this->_ami) && is_null($this->_ami->sKey)) $this->_ami = NULL;
        if (is_null($this->_ami) && !$this->_finalizandoPrograma) {
            if (!$this->_iniciarConexionAMI()) {
                $this->_log->output('ERR: no se puede restaurar conexión a Asterisk, se espera... | EN: cannot restore Asterisk connection, waiting...');
                if (!is_null($this->_db)) {
                    if ($this->_multiplex->procesarPaquetes())
                        $this->_multiplex->procesarActividad(0);
                    else $this->_multiplex->procesarActividad(5);
                } else {
                    usleep(5000000);
                }
            } else {
                $this->_log->output('INFO: conexión a Asterisk restaurada, se reinicia operación normal. | EN: Asterisk connection restored, resuming normal operation.');

                /* TODO: si el Asterisk ha sido reiniciado, probablemente ha
                 * olvidado la totalidad de las llamadas en curso, así como los
                 * agentes que estaban logoneados. Es necesario implementar una
                 * verificación de si los agentes están logoneados, y resetear
                 * todo el estado del marcador si la información interna del
                 * marcador está desactualizada.
                 * TODO: if Asterisk has been restarted, it has probably forgotten
                 * all ongoing calls as well as agents that were logged in. It is
                 * necessary to implement a check for whether agents are logged in,
                 * and reset all dialer state if the dialer's internal information
                 * is outdated. */
            }
        }

        // Actualizar la generación de llamadas para las campañas
        // Update call generation for campaigns
        if (!is_null($this->_db)) {
            try {
                if (!$this->_finalizandoPrograma) {
                    // Verificar si se ha cambiado la configuración
                    // Verify if the configuration has been changed
                    $this->_verificarCambioConfiguracion();

                    if ($this->_ociosoSinEventos) {
                        if (!is_null($this->_ami)) $this->_actualizarCampanias();
                    }
                }

                // Rutear todos los mensajes pendientes entre tareas
                // Route all pending messages between tasks
                $this->_ociosoSinEventos = !$this->_multiplex->procesarPaquetes();
                $this->_multiplex->procesarActividad($this->_ociosoSinEventos ? 1 : 0);
            } catch (PDOException $e) {
                $this->_log->output('ERR: '.__METHOD__.
                    ': no se puede realizar operación de base de datos: '.
                    implode(' - ', $e->errorInfo).' | EN: cannot perform database operation: '.
                    implode(' - ', $e->errorInfo));
                $this->_log->output("ERR: traza de pila: \n".$e->getTraceAsString()." | EN: stack trace: \n".$e->getTraceAsString());
                if ($e->errorInfo[0] == 'HY000' && $e->errorInfo[1] == 2006) {
                    // Códigos correspondientes a pérdida de conexión de base de datos
                    // Codes corresponding to database connection loss
                    $this->_log->output('WARN: '.__METHOD__.
                        ': conexión a DB parece ser inválida, se cierra... | EN: DB connection appears invalid, closing...');
                    $this->_db = NULL;
                }
            }
        }

    	return TRUE;
    }

    public function limpiezaDemonio($signum)
    {
        // Mandar a cerrar todas las conexiones activas
        // Order to close all active connections
        $this->_multiplex->finalizarServidor();

        // Desconectarse de la base de datos
        // Disconnect from the database
        $this->_configDB = NULL;
    	if (!is_null($this->_db)) {
            $this->_log->output('INFO: desconectando de la base de datos... | EN: disconnecting from database...');
    		$this->_db = NULL;
    	}
    }

    private function _iniciarConexionAMI()
    {
        if (!is_null($this->_ami)) {
            $this->_log->output('INFO: Desconectando de sesión previa de Asterisk... | EN: Disconnecting from previous Asterisk session...');
            $this->_ami->disconnect();
            $this->_ami = NULL;
        }
        $astman = new AMIClientConn($this->_multiplex, $this->_log);

        $this->_log->output('INFO: Iniciando sesión de control de Asterisk... | EN: Starting Asterisk control session...');
        if (!$astman->connect(
                $this->_configDB->asterisk_asthost,
                $this->_configDB->asterisk_astuser,
                $this->_configDB->asterisk_astpass)) {
            $this->_log->output("FATAL: no se puede conectar a Asterisk Manager | EN: cannot connect to Asterisk Manager");
            return FALSE;
        } else {
            // Averiguar la versión de Asterisk que se usa
            // Find out the Asterisk version being used
            $this->_asteriskVersion = array(1, 4, 0, 0);
            $r = $astman->CoreSettings(); // Sólo disponible en Asterisk >= 1.6.0
                                          // Only available in Asterisk >= 1.6.0
            if ($r['Response'] == 'Success' && isset($r['AsteriskVersion'])) {
                $this->_asteriskVersion = explode('.', $r['AsteriskVersion']);
                $this->_log->output("INFO: CoreSettings reporta Asterisk ".implode('.', $this->_asteriskVersion)." | EN: CoreSettings reports Asterisk ".implode('.', $this->_asteriskVersion));
            } else {
                $this->_log->output("INFO: no hay soporte CoreSettings en Asterisk Manager, se asume Asterisk 1.4.x. | EN: no CoreSettings support in Asterisk Manager, assuming Asterisk 1.4.x.");
            }
            $this->_compat = new AsteriskCompat($this->_asteriskVersion);

            /* CampaignProcess no tiene manejadores de eventos AMI. Aunque el
             * objeto Predictor hace uso de eventos para recoger el resultado
             * de QueueStatus, tales eventos caen fuera del filtro manipulado
             * por Events(), y por lo tanto siempre se emiten.
             * CampaignProcess has no AMI event handlers. Although the Predictor
             * object uses events to collect the QueueStatus result, such events
             * fall outside the filter manipulated by Events(), and therefore
             * are always emitted. */
            $astman->Events('off');

            $this->_ami = $astman;
            return TRUE;
        }
    }

    private function _verificarCambioConfiguracion()
    {
        $iTimestamp = time();
        if ($iTimestamp - $this->_iTimestampUltimaRevisionConfig > 3) {
            $this->_configDB->leerConfiguracionDesdeDB();
            $listaVarCambiadas = $this->_configDB->listaVarCambiadas();
            if (count($listaVarCambiadas) > 0) {
                if (in_array('dialer_debug', $listaVarCambiadas))
                    $this->DEBUG = $this->_configDB->dialer_debug;
                $this->_configDB->limpiarCambios();
            }
            $this->_iTimestampUltimaRevisionConfig = $iTimestamp;
        }
    }

    private function _actualizarCampanias()
    {
        // Revisar las campañas cada 3 segundos
        // Review campaigns every 3 seconds
        $iTimestamp = time();
        if ($iTimestamp - $this->_iTimestampUltimaRevisionCampanias >= INTERVALO_REVISION_CAMPANIAS) {

            /* Se actualiza timestamp de revisión aquí por si no se puede
             * actualizar más tarde debido a una excepción de DB.
             * Review timestamp is updated here in case it cannot be updated
             * later due to a DB exception. */
            $this->_iTimestampUltimaRevisionCampanias = $iTimestamp;

            $sFecha = date('Y-m-d', $iTimestamp);
            $sHora = date('H:i:s', $iTimestamp);
            $listaCampanias = array(
                'incoming'  =>  array(),
                'outgoing'  =>  array(),
            );

            // Desactivar todas las campañas que sigan activas y que hayan superado
            // la fecha final de duración de campaña
            // Deactivate all campaigns still active that have exceeded the campaign end date
            $sPeticionDesactivarCaducas = <<<PETICION_DESACTIVAR_CADUCAS
UPDATE campaign SET estatus = "I" WHERE datetime_end < ? AND estatus = "A"
PETICION_DESACTIVAR_CADUCAS;
            $sth = $this->_db->prepare($sPeticionDesactivarCaducas);
            $sth->execute(array($sFecha));

            // Leer la lista de campañas salientes que entran en actividad ahora
            // Read the list of outgoing campaigns that are becoming active now
            $sPeticionCampanias = <<<PETICION_CAMPANIAS_SALIENTES
SELECT id, name, trunk, context, queue, max_canales, num_completadas,
    promedio, desviacion, retries, datetime_init, datetime_end, daytime_init,
    daytime_end
FROM campaign
WHERE datetime_init <= ? AND datetime_end >= ? AND estatus = "A"
    AND (
            (daytime_init < daytime_end AND daytime_init <= ? AND daytime_end >= ?)
        OR  (daytime_init > daytime_end AND (daytime_init <= ? OR daytime_end >= ?)
    )
)
PETICION_CAMPANIAS_SALIENTES;
            $recordset = $this->_db->prepare($sPeticionCampanias);
            $recordset->execute(array($sFecha, $sFecha, $sHora, $sHora, $sHora, $sHora));
            $recordset->setFetchMode(PDO::FETCH_ASSOC);
            foreach ($recordset as $tupla) {
            	$listaCampanias['outgoing'][] = $tupla;
            }

            // Desactivar todas las campañas que sigan activas y que hayan superado
            // la fecha final de duración de campaña
            // Deactivate all campaigns still active that have exceeded the campaign end date
            $sPeticionDesactivarCaducas = <<<PETICION_DESACTIVAR_CADUCAS
UPDATE campaign_entry SET estatus = "I" WHERE datetime_end < ? AND estatus = "A"
PETICION_DESACTIVAR_CADUCAS;
            $sth = $this->_db->prepare($sPeticionDesactivarCaducas);
            $sth->execute(array($sFecha));

            // Leer la lista de campañas entrantes que entran en actividad ahora
            // Read the list of incoming campaigns that are becoming active now
            $sPeticionCampanias = <<<PETICION_CAMPANIAS_ENTRANTES
SELECT c.id, c.name, c.id_queue_call_entry, q.queue, c.datetime_init, c.datetime_end, c.daytime_init,
    c.daytime_end
FROM campaign_entry c, queue_call_entry q
WHERE q.id = c.id_queue_call_entry AND c.datetime_init <= ?
    AND c.datetime_end >= ? AND c.estatus = "A"
    AND (
            (c.daytime_init < c.daytime_end AND c.daytime_init <= ? AND c.daytime_end > ?)
        OR  (c.daytime_init > c.daytime_end AND (? < c.daytime_init OR c.daytime_end < ?)
    )
)
PETICION_CAMPANIAS_ENTRANTES;
            $recordset = $this->_db->prepare($sPeticionCampanias);
            $recordset->execute(array($sFecha, $sFecha, $sHora, $sHora, $sHora, $sHora));
            $recordset->setFetchMode(PDO::FETCH_ASSOC);
            foreach ($recordset as $tupla) {
                $listaCampanias['incoming'][] = $tupla;
            }

            // Construir lista de campañas y colas que no han sido todavía avisadas
            // Build list of campaigns and queues not yet notified
            $listaCampaniasAvisar = array(
                'incoming'              =>  array(),    // Nuevas campañas entrantes
                                                        // New incoming campaigns
                'outgoing'              =>  array(),    // Nuevas campañas salientes
                                                        // New outgoing campaigns
                'incoming_queue_new'    =>  array(),    // Nuevas colas definidas como entrantes
                                                        // New queues defined as incoming
                'incoming_queue_old'    =>  array(),    // Colas que ya no están definidas como entrantes
                                                        // Queues no longer defined as incoming
            );
            foreach ($listaCampanias as $t => $l) {
                $listaIdx = array();
                foreach ($l as $tupla) {
                    $listaIdx[] = $tupla['id'];
                	if (!in_array($tupla['id'], $this->_campaniasAvisadas[$t])) {
                		$listaCampaniasAvisar[$t][$tupla['id']] = $tupla;
                	}
                }
                $this->_campaniasAvisadas[$t] = $listaIdx;
            }

            // Leer la lista de colas entrantes que pueden o no tener una campaña
            // Read the list of incoming queues that may or may not have a campaign
            $listaColasActivas = array();
            $listaColasInactivas = array();
            foreach (
                $this->_db->query('SELECT id, queue, estatus FROM queue_call_entry')
                as $tupla) {
                if ($tupla['estatus'] == 'A') {
                    $listaColasActivas[$tupla['id']] = array(
                        'id'    =>  $tupla['id'],
                        'queue' =>  $tupla['queue'],
                    );
                } else {
                    $listaColasInactivas[$tupla['id']] = array(
                        'id'    =>  $tupla['id'],
                        'queue' =>  $tupla['queue'],
                    );
                }
            }
            $listaIdColas = array_keys($listaColasActivas);
            foreach (array_diff($listaIdColas, $this->_campaniasAvisadas['incoming_id_queue']) as $id)
                $listaCampaniasAvisar['incoming_queue_new'][$id] = $listaColasActivas[$id];
            foreach (array_diff($this->_campaniasAvisadas['incoming_id_queue'], $listaIdColas) as $id) {
                if (isset($listaColasInactivas[$id])) {
                    $listaCampaniasAvisar['incoming_queue_old'][$id] = $listaColasInactivas[$id];
                } else {
                    $this->_log->output("WARN: ".__METHOD__." no se encuentra queue_call_entry(id=$id) | EN: queue_call_entry(id=$id) not found");
                }
            }
            if (count($listaCampaniasAvisar['incoming_queue_new']) != 0 ||
                count($listaCampaniasAvisar['incoming_queue_old']) != 0)
                $this->_campaniasAvisadas['incoming_id_queue'] = $listaIdColas;

            // Mandar a avisar a AMIEventProcess sobre las campañas y colas activas
            if (!(count($listaCampaniasAvisar['incoming']) == 0 &&
                count($listaCampaniasAvisar['outgoing']) == 0 &&
                count($listaCampaniasAvisar['incoming_queue_new']) == 0 &&
                count($listaCampaniasAvisar['incoming_queue_old']) == 0))
                $this->_tuberia->AMIEventProcess_nuevasCampanias($listaCampaniasAvisar);

            /* Se actualiza timestamp de revisión aquí por si no se puede
             * actualizar más tarde debido a una excepción de DB.
             * Review timestamp is updated here in case it cannot be updated
             * later due to a DB exception. */
            $this->_iTimestampUltimaRevisionCampanias = time();

            // Generar las llamadas para todas las campañas salientes activas
            // Generate calls for all active outgoing campaigns
            foreach ($listaCampanias['outgoing'] as $tuplaCampania) {
                /* Se debe crear el predictor para cada campaña porque la
                 * generación de llamadas toma tiempo debido a las consultas a
                 * la base de datos, y para cuando pasa a la siguiente campaña
                 * que usa esa cola, la información podría estar obsoleta.
                 * The predictor must be created for each campaign because call
                 * generation takes time due to database queries, and by the
                 * time it moves to the next campaign using that queue, the
                 * information may be obsolete. */
                $oPredictor = new Predictor($this->_ami);
                $this->_actualizarLlamadasCampania($tuplaCampania, $oPredictor);

                /* Debido a las consultas a la base de datos realizadas para
                 * generar las llamadas a la campaña, es posible que se acumulen
                 * eventos pendientes de AMIEventProcess. Se despachan algunos
                 * eventos aquí para paliar la acumulación.
                 * Due to the database queries performed to generate campaign
                 * calls, AMIEventProcess pending events may accumulate. Some
                 * events are dispatched here to alleviate the accumulation. */
                $this->_ociosoSinEventos = !$this->_multiplex->procesarPaquetes();
                $this->_multiplex->procesarActividad(0);

                /* Se actualiza timestamp de revisión aquí por si no se puede
                 * actualizar más tarde debido a una excepción de DB.
                 * Review timestamp is updated here in case it cannot be updated
                 * later due to a DB exception. */
                $this->_iTimestampUltimaRevisionCampanias = time();
            }
        }
    }

    private function _actualizarLlamadasCampania($infoCampania, $oPredictor)
    {
        $iTimeoutOriginate = $this->_configDB->dialer_timeout_originate;
        if (is_null($iTimeoutOriginate) || $iTimeoutOriginate <= 0)
            $iTimeoutOriginate = NULL;
        else $iTimeoutOriginate *= 1000; // convertir a milisegundos
                                        // convert to milliseconds

        // Construir patrón de marcado a partir de trunk de campaña
        // Build dialing pattern from campaign trunk
        $datosTrunk = $this->_construirPlantillaMarcado($infoCampania['trunk']);
        if (is_null($datosTrunk)) {
            $this->_log->output("ERR: no se puede construir plantilla de marcado a partir de trunk '{$infoCampania['trunk']}'! | EN: cannot build dial template from trunk '{$infoCampania['trunk']}'!");
            $this->_log->output("ERR: Revise los mensajes previos. Si el problema es un tipo de trunk no manejado, ".
                "se requiere informar este tipo de trunk y/o actualizar su versión de CallCenter | EN: Review previous messages. If the problem is an unhandled trunk type, ".
                "this trunk type needs to be reported and/or update your CallCenter version");
            return FALSE;
        }

        // Leer cuántas llamadas (como máximo) se pueden hacer por campaña
        // Read how many calls (at most) can be made per campaign
        $iNumLlamadasColocar = $infoCampania['max_canales'];
        if (!is_null($iNumLlamadasColocar) && $iNumLlamadasColocar <= 0)
            $iNumLlamadasColocar = NULL;

        // Listar todas las llamadas agendables para la campaña
        // List all schedulable calls for the campaign
        $listaLlamadasAgendadas = $this->_actualizarLlamadasAgendables($infoCampania, $datosTrunk);

        // Averiguar cuantas llamadas se pueden hacer (por predicción), y tomar
        // el menor valor de entre máx campaña y predictivo.
        // Find out how many calls can be made (by prediction), and take the
        // lower value between campaign max and predictive.
        if ($this->DEBUG) {
            $this->_log->output('DEBUG: '.__METHOD__.' verificando agentes libres... | EN: checking free agents...');
        }

        // Parámetros requeridos para predicción de colocación de llamadas
        // Parameters required for call placement prediction
        $infoCola = $this->_tuberia->AMIEventProcess_infoPrediccionCola($infoCampania['queue']);
        if (is_null($infoCola)) {
            if ($oPredictor->examinarColas(array($infoCampania['queue']))) {
                $infoCola = $oPredictor->infoPrediccionCola($infoCampania['queue']);
            }
        }

        if (is_null($infoCola)) {
            $this->_log->output('ERR: '.__METHOD__." no se puede obtener información de ".
                "estado de cola para (campania {$infoCampania['id']} ".
                "cola {$infoCampania['queue']}). | EN: cannot get queue status information for (campaign {$infoCampania['id']} queue {$infoCampania['queue']}).");
            return FALSE;
        }

        $resumenPrediccion = ($this->_configDB->dialer_predictivo && ($infoCampania['num_completadas'] >= MIN_MUESTRAS))
            ? $oPredictor->predecirNumeroLlamadas($infoCola,
                $this->_configDB->dialer_qos,
                $infoCampania['promedio'],
                $this->_leerTiempoContestar($infoCampania['id']))
            : $oPredictor->predecirNumeroLlamadas($infoCola);
        if ($this->DEBUG) {
            $this->_log->output('DEBUG: '.__METHOD__." (campania/campaign {$infoCampania['id']} ".
                "cola/queue {$infoCampania['queue']}): resumen de predicción / prediction summary:\n".
                    "\tagentes libres / free agents.........: {$resumenPrediccion['AGENTES_LIBRES']}\n".
                    "\tagentes por desocuparse / agents about to become free: {$resumenPrediccion['AGENTES_POR_DESOCUPAR']}\n".
                    "\tclientes en espera / clients waiting.....: {$resumenPrediccion['CLIENTES_ESPERA']}");
        }
        $iMaxPredecidos = $resumenPrediccion['AGENTES_LIBRES'] + $resumenPrediccion['AGENTES_POR_DESOCUPAR'] - $resumenPrediccion['CLIENTES_ESPERA'];

        if ($iMaxPredecidos < 0) $iMaxPredecidos = 0;
        if (is_null($iNumLlamadasColocar) || $iNumLlamadasColocar > $iMaxPredecidos)
            $iNumLlamadasColocar = $iMaxPredecidos;

        // TODO: colocar código de detección de conflicto de agentes
        // TODO: place agent conflict detection code

$pepe = $iNumLlamadasColocar;


        // En Asterisk13 el Originate Response llega tarde, luego de que el llamado termina, no podemos considerar que está pendiente
        // In Asterisk13 the Originate Response arrives late, after the call ends, we cannot consider it pending
        // Por ese motivo comentamos por ahora este bloque, para ver cual es el mejor curso de accion en el futuro y evitar
        // For this reason we comment out this block for now, to see what the best course of action is in the future and avoid
        // colocar llamados cuando hay algunos pendientes todavia.
        // placing calls when there are still some pending.

        if ($iNumLlamadasColocar > 0) {

            // El valor de llamadas predichas no toma en cuenta las llamadas que han
            // sido generadas pero todavía no se recibe su OriginateResponse. Para
            // evitar sobrecolocar mientras las primeras llamadas esperan ser
            // contestadas, se cuentan tales llamadas y se resta.
            // The value of predicted calls does not take into account calls that have
            // been generated but whose OriginateResponse has not yet been received. To
            // avoid over-placing while the first calls wait to be answered, such calls
            // are counted and subtracted.

            $iNumEsperanRespuesta = count($listaLlamadasAgendadas) + $this->_contarLlamadasEsperandoRespuesta($infoCampania['queue']);

            if ($iNumLlamadasColocar > $iNumEsperanRespuesta) {
                $iNumLlamadasColocar -= $iNumEsperanRespuesta;
            } else { 
                $iNumLlamadasColocar = 0;
            }
        }

        if (count($listaLlamadasAgendadas) <= 0 && $iNumLlamadasColocar <= 0) {
            if ($this->DEBUG) {
                $this->_log->output("DEBUG: ".__METHOD__." (campania {$infoCampania['id']} cola ".
                    "{$infoCampania['queue']}) no hay agentes libres ni a punto ".
                    "de desocuparse! | EN: (campaign {$infoCampania['id']} queue ".
                    "{$infoCampania['queue']}) no free agents or about to become free!");
            }
            return FALSE;
        }

        if ($this->DEBUG) {
            $this->_log->output("DEBUG: ".__METHOD__." (campania {$infoCampania['id']} cola ".
                "{$infoCampania['queue']}) se pueden colocar un máximo de ".
                "$iNumLlamadasColocar llamadas... | EN: can place maximum of ".
                "$iNumLlamadasColocar calls...");
        }

        if ($iNumLlamadasColocar > 0 && $this->_configDB->dialer_overcommit) {
            // Para compensar por falla de llamadas, se intenta colocar más de la cuenta. El porcentaje
            // de llamadas a sobre-colocar se determina a partir de la historia pasada de la campaña.
            // To compensate for call failures, we try to place more than the count. The percentage
            // of calls to over-place is determined from the campaign's past history.
            $iVentanaHistoria = 60 * 30; // TODO: se puede autocalcular?
                                        // TODO: can it be auto-calculated?
            $sPeticionASR =
                'SELECT COUNT(*) AS total, SUM(IF(status = "Failure" OR status = "NoAnswer", 0, 1)) AS exito ' .
                'FROM calls ' .
                'WHERE id_campaign = ? AND status IS NOT NULL ' .
                    'AND status <> "Placing" ' .
                    'AND fecha_llamada IS NOT NULL ' .
                    'AND fecha_llamada >= ?';
            $recordset = $this->_db->prepare($sPeticionASR);
            $recordset->execute(array($infoCampania['id'], date('Y-m-d H:i:s', time() - $iVentanaHistoria)));
            $tupla = $recordset->fetch(PDO::FETCH_ASSOC);
            $recordset->closeCursor();

            // Sólo considerar para más de 10 llamadas colocadas durante ventana
            // Only consider for more than 10 calls placed during window
            if ($tupla['total'] >= 10 && $tupla['exito'] > 0) {
                $ASR = $tupla['exito'] / $tupla['total'];
                $ASR_safe = $ASR;
                if ($ASR_safe < 0.20) $ASR_safe = 0.20;
                $iNumLlamadasColocar = (int)round($iNumLlamadasColocar / $ASR_safe);
                if ($this->DEBUG) {
                    $this->_log->output(
                        "DEBUG: (campania {$infoCampania['id']} cola {$infoCampania['queue']}) ".
                        "en los últimos $iVentanaHistoria seg. tuvieron éxito " .
                        "{$tupla['exito']} de {$tupla['total']} llamadas colocadas (ASR=".(sprintf('%.2f', $ASR * 100))."%). Se colocan " .
                        "$iNumLlamadasColocar para compensar. | EN: (campaign {$infoCampania['id']} queue {$infoCampania['queue']}) ".
                        "in the last $iVentanaHistoria sec. " .
                        "{$tupla['exito']} of {$tupla['total']} placed calls succeeded (ASR=".(sprintf('%.2f', $ASR * 100))."%). Placing " .
                        "$iNumLlamadasColocar to compensate.");
                }
            }
        }


        // Leer tantas llamadas como fueron elegidas. Sólo se leen números con
        // status == NULL y bandera desactivada
        // Read as many calls as were selected. Only numbers with status == NULL
        // and flag deactivated are read
        $listaLlamadas = $listaLlamadasAgendadas;
        $iNumTotalLlamadas = count($listaLlamadas);
        if ($iNumLlamadasColocar > 0) {
            $sFechaSys = date('Y-m-d');
            $sHoraSys = date('H:i:s');
            $sPeticionLlamadas = <<<PETICION_LLAMADAS
(SELECT 1 AS dummy_priority, id_campaign, id, phone, date_init AS dummy_date_init,
    time_init AS dummy_time_init, date_end AS dummy_date_end,
    time_end AS dummy_time_end, retries
FROM calls
WHERE id_campaign = ?
    AND (status IS NULL
        OR status NOT IN ("Success", "Placing", "Ringing", "OnQueue", "OnHold"))
    AND retries < ?
    AND dnc = 0
    AND (? BETWEEN date_init AND date_end AND ? BETWEEN time_init AND time_end)
    AND agent IS NULL)
UNION
(SELECT 2 AS dummy_priority, id_campaign, id, phone, date_init AS dummy_date_init,
    time_init AS dummy_time_init, date_end AS dummy_date_end,
    time_end AS dummy_time_end, retries
FROM calls
WHERE id_campaign = ?
    AND (status IS NULL
        OR status NOT IN ("Success", "Placing", "Ringing", "OnQueue", "OnHold"))
    AND retries < ?
    AND dnc = 0
    AND date_init IS NULL AND date_end IS NULL AND time_init IS NULL AND time_end IS NULL
    AND agent IS NULL)
ORDER BY dummy_priority, retries, dummy_date_end, dummy_time_end, dummy_date_init, dummy_time_init, id
LIMIT 0,?
PETICION_LLAMADAS;
            $recordset = $this->_db->prepare($sPeticionLlamadas);
            $recordset->execute(array(
                $infoCampania['id'],
                $infoCampania['retries'],
                $sFechaSys, $sHoraSys,
                $infoCampania['id'],
                $infoCampania['retries'],
                $iNumLlamadasColocar));
            $recordset->setFetchMode(PDO::FETCH_ASSOC);
            $pid = posix_getpid();
            foreach ($recordset as $tupla) {
                $iNumTotalLlamadas++;
                $sKey = sprintf('%d-%d-%d', $pid, $infoCampania['id'], $tupla['id']);
                $sCanalTrunk = str_replace('$OUTNUM$', $tupla['phone'], $datosTrunk['TRUNK']);

                /* Para poder monitorear el evento Onnewchannel, se depende de
                 * la cadena de marcado para identificar cuál de todos los eventos
                 * es el correcto. Si una llamada generada produce la misma cadena
                 * de marcado que una que ya se monitorea, o que otra en la misma
                 * lista, ocurrirán confusiones entre los eventos. Se filtran las
                 * llamadas que tengan cadenas de marcado repetidas.
                 * To monitor the Onnewchannel event, we depend on the dial string
                 * to identify which of all events is the correct one. If a generated
                 * call produces the same dial string as one already being monitored,
                 * or another in the same list, confusion will occur between events.
                 * Calls with repeated dial strings are filtered out. */
                if (!isset($listaLlamadas[$tupla['phone']])) {
                    // Llamada no repetida, se procesa normalmente
                    // Non-repeated call, processed normally
                    $tupla['actionid'] = $sKey;
                    $tupla['dialstring'] = $sCanalTrunk;
                    $tupla['agent'] = NULL; // Marcar la llamada como no agendada
                                            // Mark call as not scheduled
                	$listaLlamadas[$tupla['phone']] = $tupla;
                } else {
                	// Se ha encontrado en la lectura un número de teléfono repetido
                    // A repeated phone number was found in the reading
                    $this->_log->output("INFO: se ignora llamada $sKey con DialString ".
                        "$sCanalTrunk - mismo DialString usado por llamada a punto de originar. | EN: call $sKey with DialString ".
                        "$sCanalTrunk ignored - same DialString used by call about to originate.");
                }
            }
        }

        if ($iNumTotalLlamadas <= 0) {
            /* Debido a que ahora las llamadas pueden agendarse a una hora
             * específica, puede ocurrir que la lista de llamadas por realizar
             * esté vacía porque hay llamadas agendadas, pero fuera del horario
             * indicado por la hora del sistema. Si la cuenta del query de abajo
             * devuelve al menos una llamada, se interrumpe el procesamiento y
             * se sale.
             * Because calls can now be scheduled for a specific time, it may
             * happen that the list of calls to make is empty because there are
             * scheduled calls, but outside the time indicated by the system time.
             * If the count of the query below returns at least one call, processing
             * is interrupted and we exit.
             */
            $sPeticionTotal =
                'SELECT COUNT(*) AS N FROM calls '.
                'WHERE id_campaign = ? '.
                    'AND (status IS NULL OR status NOT IN ("Success", "Placing", "Ringing", "OnQueue", "OnHold")) '.
                    'AND retries < ? '.
                    'AND dnc = 0';
            $recordset = $this->_db->prepare($sPeticionTotal);
            $recordset->execute(array($infoCampania['id'], $infoCampania['retries']));
            $iNumTotal = $recordset->fetch(PDO::FETCH_COLUMN, 0);
            $recordset->closeCursor();
            if (!is_null($iNumTotal) && $iNumTotal > 0) {
                if ($this->DEBUG) {
                    $this->_log->output("DEBUG: ".__METHOD__." (campania {$infoCampania['id']} ".
                        "cola {$infoCampania['queue']}) no hay llamadas a ".
                        "colocar; $iNumTotal llamadas agendadas pero fuera de ".
                        "horario. | EN: (campaign {$infoCampania['id']} ".
                        "queue {$infoCampania['queue']}) no calls to ".
                        "place; $iNumTotal scheduled calls but outside ".
                        "time range.");
                }
                return FALSE;
            }
        }

        /* Verificar si las llamadas están colocadas en la lista de Do Not Call.
         * Esto puede ocurrir incluso si la bandera dnc es 0, si la lista se
         * actualiza luego de cargar la lista de llamadas salientes.
         * Check if calls are on the Do Not Call list. This can happen even if
         * the dnc flag is 0, if the list is updated after loading the outgoing
         * call list. */
        $recordset = $this->_db->prepare(
            'SELECT COUNT(*) FROM dont_call WHERE caller_id = ? AND status = "A"');
        $sth = $this->_db->prepare(
            'UPDATE calls SET dnc = 1 WHERE id_campaign = ? AND id = ?');
        foreach (array_keys($listaLlamadas) as $k) {
            $recordset->execute(array($k));
            $iNumDNC = $recordset->fetch(PDO::FETCH_COLUMN, 0);
            $recordset->closeCursor();
            if ($iNumDNC > 0) {
            	if ($this->DEBUG) {
            		$this->_log->output('DEBUG: '.__METHOD__." (campania {$infoCampania['id']} ".
                        "número $k encontrado en lista DNC, no se marcará. | EN: number $k found in DNC list, will not be dialed.");
            	}
                $sth->execute(array($infoCampania['id'], $tupla['id']));
                unset($listaLlamadas[$k]);
            }
        }

        /* Mandar los teléfonos a punto de marcar a AMIEventProcess. Se espera
         * de vuelta una lista de los números que ya están repetidos y en proceso
         * de marcado.
         * Send the phones about to be dialed to AMIEventProcess. We expect back
         * a list of numbers that are already repeated and in the process of being
         * dialed. */
        if (count($listaLlamadas) > 0) {
            $listaKeyRepetidos = $this->_tuberia->AMIEventProcess_nuevasLlamadasMarcar($listaLlamadas);
            foreach ($listaKeyRepetidos as $k) {
            	$sKey = $listaLlamadas[$k]['actionid'];
                $sCanalTrunk = $listaLlamadas[$k]['dialstring'];
                $this->_log->output("INFO: se ignora llamada $sKey con DialString ".
                    "$sCanalTrunk - mismo DialString usado por llamada monitoreada. | EN: call $sKey with DialString ".
                    "$sCanalTrunk ignored - same DialString used by monitored call.");
                unset($listaLlamadas[$k]);
            }
        }

        // Peticiones preparadas
        // Prepared statements
        $sPeticionLlamadaColocada = <<<SQL_LLAMADA_COLOCADA
UPDATE calls SET status = 'Placing', datetime_originate = ?, fecha_llamada = NULL,
    datetime_entry_queue = NULL, start_time = NULL, end_time = NULL,
    duration_wait = NULL, duration = NULL, failure_cause = NULL,
    failure_cause_txt = NULL, uniqueid = NULL, id_agent = NULL,
    retries = retries + 1
WHERE id_campaign = ? AND id = ?
SQL_LLAMADA_COLOCADA;
        $sth_placing = $this->_db->prepare($sPeticionLlamadaColocada);

        // Generar realmente todas las llamadas leídas
        // Actually generate all the calls read
        $queue_monitor_format = NULL;
        while (count($listaLlamadas) > 0) {
            $tupla = array_shift($listaLlamadas);

            $listaVars = array(
                'ID_CAMPAIGN'   =>  $infoCampania['id'],
                'ID_CALL'       =>  $tupla['id'],
                'NUMBER'        =>  $tupla['phone'],
                'QUEUE'         =>  $infoCampania['queue'],
                'CONTEXT'       =>  $infoCampania['context'],
            );
            if (!is_null($tupla['agent'])) {
                $listaVars['AGENTCHANNEL'] = $tupla['agent'];
                if (is_null($queue_monitor_format))
                    $queue_monitor_format = $this->_formatoGrabacionCola($infoCampania['queue']);
                $listaVars['QUEUE_MONITOR_FORMAT'] = $queue_monitor_format;
            }
            $sCadenaVar = $this->_construirCadenaVariables($listaVars);
            if ($this->DEBUG) {
                $this->_log->output("DEBUG: ".__METHOD__." generando llamada | EN: generating call\n".
                    "\tClave/Key....... {$tupla['actionid']}\n" .
                    "\tAgente/Agent...... ".(is_null($tupla['agent']) ? '(ninguno/none)' : $tupla['agent'])."\n" .
                    "\tDestino/Destination..... {$tupla['phone']}\n" .
                    "\tCola/Queue........ {$infoCampania['queue']}\n" .
                    "\tContexto/Context.... {$infoCampania['context']}\n" .
                    "\tVar. Contexto/Context Var $sCadenaVar\n" .
                    "\tTrunk....... ".(is_null($infoCampania['trunk']) ? '(por plan de marcado/by dial plan)' : $infoCampania['trunk'])."\n" .
                    "\tPlantilla/Template... ".$datosTrunk['TRUNK']."\n" .
                    "\tCaller ID... ".(isset($datosTrunk['CID']) ? $datosTrunk['CID'] : "(no definido/not defined)")."\n".
                    "\tCadena de marcado/Dial string... {$tupla['dialstring']}\n".
                    "\tTimeout marcado/Dial timeout..... ".(is_null($iTimeoutOriginate) ? '(por omisión/default)' : $iTimeoutOriginate.' ms.'));
            }

            /* La actualización de la llamada a estado Placing en la base de
             * datos debe realizarse ANTES de ejecutar el Originate, y también
             * debe de lanzarse el evento de progreso ANTES del originate. Se
             * confía en que AMIEventProcess lanzará el evento de Failure si el
             * Originate falla.
             *
             * La notificación de progreso de llamada se realiza a través de
             * AMIEventProcess para garantizar el orden de eventos y de escrituras
             * en la tabla call_progress_log.
             *
             * Si ocurre una excepción de base de datos aquí, se la deja
             * propagar luego de rollback.
             * The update of the call to Placing status in the database must be
             * done BEFORE executing the Originate, and the progress event must
             * also be launched BEFORE the originate. We trust that AMIEventProcess
             * will launch the Failure event if the Originate fails.
             *
             * Call progress notification is done through AMIEventProcess to
             * guarantee the order of events and writes in the call_progress_log
             * table.
             *
             * If a database exception occurs here, it is left to propagate after
             * rollback. */
            try {
                $this->_db->beginTransaction();

                $iTimestampInicioOriginate = time();
                $sth_placing->execute(array(
                    date('Y-m-d H:i:s', $iTimestampInicioOriginate),
                    $infoCampania['id'],
                    $tupla['id']
                ));

                $this->_db->commit();
            } catch (PDOException $e) {
                if (!is_null($this->_db)) {
                    $this->_db->rollBack();
                }

                // Se deshace AMIEventProcess_nuevasLlamadasMarcar sin marcar
                // Undo AMIEventProcess_nuevasLlamadasMarcar without dialing
                $this->_log->output('WARN: '.__METHOD__.' abortando '.
                    count($listaLlamadas).' llamadas sin marcar debido a excepción de DB... | EN: aborting '.
                    count($listaLlamadas).' undialed calls due to DB exception...');
                $llamadasAbortar = array($tupla['actionid']);
                foreach ($listaLlamadas as $t) $llamadasAbortar[] = $t['actionid'];
                $this->_tuberia->msg_AMIEventProcess_abortarNuevasLlamadasMarcar($llamadasAbortar);

                throw $e;
            }

            // Mandar a ejecutar la llamada a través de AMIEventProcess
            // Send to execute the call through AMIEventProcess
            $this->_tuberia->AMIEventProcess_ejecutarOriginate(
                $tupla['actionid'], $iTimeoutOriginate, $iTimestampInicioOriginate,
                (is_null($tupla['agent']) ? $infoCampania['context'] : 'llamada_agendada'),
                (isset($datosTrunk['CID']) ? $datosTrunk['CID'] : $tupla['phone']),
                $sCadenaVar, (is_null($tupla['retries']) ? 0 : $tupla['retries']) + 1,
                $infoCampania['trunk']);
        }

        /* Si se llega a este punto, se presume que, con agentes disponibles, y
         * campaña activa, se terminaron las llamadas. Por lo tanto la campaña
         * ya ha terminado
         * If we reach this point, it is presumed that, with available agents,
         * and active campaign, the calls have finished. Therefore the campaign
         * has already finished. */
        if ($iNumLlamadasColocar > 0 && $iNumTotalLlamadas <= 0) {
        	$this->_log->output('INFO: marcando campaña como finalizada: '.$infoCampania['id'].' | EN: marking campaign as finished: '.$infoCampania['id']);
            $sth = $this->_db->prepare('UPDATE campaign SET estatus = "T" WHERE id = ?');
            $sth->execute(array($infoCampania['id']));
        }
    }

    /* Leer el formato de grabación de la cola indicada por el parámetro, la cual
     * está indicada en queues_additional.conf
     * Read the recording format of the queue indicated by the parameter, which
     * is indicated in queues_additional.conf */
    private function _formatoGrabacionCola($sCola)
    {
    	$sColaActual = NULL;
        foreach (file('/etc/asterisk/queues_additional.conf') as $s) {
    		$regs = NULL;
            if (preg_match('/^\[(\d+)\]/', $s, $regs)) {
    			$sColaActual = $regs[1];
    		} elseif ($sColaActual == $sCola && preg_match('/^monitor-format=(\w+)/', $s, $regs)) {
    			return $regs[1];
    		}
    	}
        return NULL;
    }

    private function _actualizarLlamadasAgendables($infoCampania, $datosTrunk)
    {
        $listaAgentesAgendados = $this->_listarAgentesAgendadosReserva($infoCampania['id']);
        if (count($listaAgentesAgendados) <= 0) return array();

        if ($this->DEBUG) {
            $this->_log->output('DEBUG: '.__METHOD__.': lista de agentes con llamadas agendadas | EN: list of agents with scheduled calls: '.
                print_r($listaAgentesAgendados, 1));
        }
        $resultado = $this->_tuberia->AMIEventProcess_agentesAgendables($listaAgentesAgendados);
        if ($this->DEBUG) {
            $this->_log->output('DEBUG: '.__METHOD__.': resultado de agentesAgendables | EN: result of agentesAgendables: '.
                print_r($resultado, 1));
        }

        // Leer una llamada para cada agente que se puede usar en agendamiento
        // Read one call for each agent that can be used in scheduling
        $listaLlamadas = array();
        $pid = posix_getpid();
        foreach ($resultado as $sAgente) {
            $tupla = $this->_listarLlamadasAgendables($infoCampania['id'], $sAgente);
            if (is_array($tupla)) {
                $tupla['actionid'] = sprintf('%d-%d-%d', $pid, $infoCampania['id'], $tupla['id']);
                $tupla['dialstring'] = str_replace('$OUTNUM$', $tupla['phone'], $datosTrunk['TRUNK']);

                /* Para poder monitorear el evento Onnewchannel, se depende de la
                 * cadena de marcado para identificar cuál de todos los eventos es
                 * el correcto. Si una llamada generada produce la misma cadena de
                 * marcado que una que ya se monitorea, o que otra en la misma
                 * lista, ocurrirán confusiones entre los eventos. Se filtran las
                 * llamadas que tengan cadenas de marcado repetidas.
                 * To monitor the Onnewchannel event, we depend on the dial string
                 * to identify which of all events is the correct one. If a generated
                 * call produces the same dial string as one already being monitored,
                 * or another in the same list, confusion will occur between events.
                 * Calls with repeated dial strings are filtered out. */

                if (!isset($listaLlamadas[$tupla['phone']])) {
                    // Llamada no repetida, se procesa normalmente
                    // Non-repeated call, processed normally
                    $listaLlamadas[$tupla['phone']] = $tupla;
                } else {
                    // Se ha encontrado en la lectura un número de teléfono repetido
                    // A repeated phone number was found in the reading
                    $this->_log->output("INFO: se ignora llamada {$tupla['actionid']} ".
                        "con DialString {$tupla['dialstring']} - mismo DialString ".
                        "usado por llamada a punto de originar. | EN: ignoring call {$tupla['actionid']} ".
                        "with DialString {$tupla['dialstring']} - same DialString ".
                        "used by call about to originate.");
                }
            } else {
                $this->_log->output('WARN: '.__METHOD__.': no se encontró '.
                    'llamada agendada esperada para agente: '.$sAgente.' | EN: expected scheduled call not found for agent: '.$sAgente);
            }
        }

        return $listaLlamadas;
    }

    /**
     * Procedimiento para obtener el número de segundos de reserva de una campaña
     * Procedure to obtain the number of reservation seconds for a campaign
     */
    private function _getSegundosReserva($idCampaign)
    {
        return 30;  // TODO: volver configurable en DB o por campaña
                    // TODO: make configurable in DB or per campaign
    }

    /**
     * Función para listar todos los agentes que tengan al menos una llamada
     * agendada, ahora, o en los siguientes RESERVA segundos, donde RESERVA se
     * reporta por getSegundosReserva().
     * Function to list all agents that have at least one scheduled call, now,
     * or in the next RESERVA seconds, where RESERVA is reported by
     * getSegundosReserva().
     *
     * @return array    Lista de agentes
     *                  List of agents
     */
    private function _listarAgentesAgendadosReserva($id_campania)
    {
        $listaAgentes = array();
        $iSegReserva = $this->_getSegundosReserva($id_campania);
        $sFechaSys = date('Y-m-d');
        $iTimestamp = time();
        $sHoraInicio = date('H:i:s', $iTimestamp);
        $sHoraFinal = date('H:i:s', $iTimestamp + $iSegReserva);

        // Listar todos los agentes que tienen alguna llamada agendada dentro del horario
        // List all agents that have a scheduled call within the time range
        $sPeticionAgentesAgendados = <<<PETICION_AGENTES_AGENDADOS
SELECT DISTINCT agent FROM calls, campaign
WHERE calls.id_campaign = ?
    AND (calls.status IS NULL OR calls.status NOT IN ("Success", "Placing", "Ringing", "OnQueue", "OnHold"))
    AND calls.dnc = 0
    AND calls.date_init <= ? AND calls.date_end >= ? AND calls.time_init <= ? AND calls.time_end >= ?
    AND calls.retries < campaign.retries
    AND calls.id_campaign = campaign.id
    AND calls.agent IS NOT NULL
PETICION_AGENTES_AGENDADOS;
        $recordset = $this->_db->prepare($sPeticionAgentesAgendados);
        $recordset->execute(array(
            $id_campania,
            $sFechaSys,
            $sFechaSys,
            $sHoraFinal,
            $sHoraInicio));
        $listaAgentes = $recordset->fetchAll(PDO::FETCH_COLUMN, 0);
        return $listaAgentes;
    }

    /**
     * Función para contar todas las llamadas agendadas para el agente indicado,
     * clasificadas en llamadas agendables AHORA, y llamadas que caen en RESERVA.
     * Function to count all calls scheduled for the indicated agent, classified
     * into schedulable calls NOW, and calls that fall into RESERVE.
     *
     * @return array Tupla de la forma array(AHORA => x, RESERVA => y)
     *               Tuple of the form array(NOW => x, RESERVE => y)
     */
    private function _contarLlamadasAgendablesReserva($id_campania, $sAgent)
    {
        $cuentaLlamadas = array('AHORA' => 0, 'RESERVA' => 0);
        $iSegReserva = $this->_getSegundosReserva($id_campania);
        $sFechaSys = date('Y-m-d');
        $iTimestamp = time();
        $sHoraInicio = date('H:i:s', $iTimestamp);
        $sHoraFinal = date('H:i:s', $iTimestamp + $iSegReserva);

    $sPeticionLlamadasAgente = <<<PETICION_LLAMADAS_AGENTE
SELECT COUNT(*) AS TOTAL, SUM(IF(calls.time_init > ?, 1, 0)) AS RESERVA
FROM calls, campaign
WHERE calls.id_campaign = ?
    AND calls.agent = ?
    AND (calls.status IS NULL OR calls.status NOT IN ("Success", "Placing", "Ringing", "OnQueue", "OnHold"))
    AND calls.dnc = 0
    AND calls.date_init <= ? AND calls.date_end >= ? AND calls.time_init <= ? AND calls.time_end >= ?
    AND calls.retries < campaign.retries
    AND calls.id_campaign = campaign.id
PETICION_LLAMADAS_AGENTE;
        $recordset = $this->_db->prepare($sPeticionLlamadasAgente);
        $recordset->execute(array(
                $sHoraInicio,
                $id_campania,
                $sAgent,
                $sFechaSys,
                $sFechaSys,
                $sHoraFinal,
                $sHoraInicio));
        $tupla = $recordset->fetch(PDO::FETCH_NUM);
        $recordset->closeCursor();
        $cuentaLlamadas['RESERVA'] = $tupla[1];
        $cuentaLlamadas['AHORA'] = $tupla[0] - $cuentaLlamadas['RESERVA'];
        return $cuentaLlamadas;
    }

    /**
     * Procedimiento para listar la primera llamada agendable para la campaña y el
     * agente indicados.
     * Procedure to list the first schedulable call for the indicated campaign
     * and agent.
     */
    private function _listarLlamadasAgendables($id_campania, $sAgente)
    {
        $sFechaSys = date('Y-m-d');
        $sHoraSys = date('H:i:s');

        $sPeticionLlamadasAgente = <<<PETICION_LLAMADAS_AGENTE
SELECT calls.id_campaign, calls.id, calls.phone, calls.agent, calls.retries
FROM calls, campaign
WHERE calls.id_campaign = ?
    AND calls.agent = ?
    AND (calls.status IS NULL OR calls.status NOT IN ("Success", "Placing", "Ringing", "OnQueue", "OnHold"))
    AND calls.dnc = 0
    AND calls.date_init <= ? AND calls.date_end >= ? AND calls.time_init <= ? AND calls.time_end >= ?
    AND calls.retries < campaign.retries
    AND calls.id_campaign = campaign.id
ORDER BY calls.retries, calls.date_end, calls.time_end, calls.date_init, calls.time_init
LIMIT 0,1
PETICION_LLAMADAS_AGENTE;
        $recordset = $this->_db->prepare($sPeticionLlamadasAgente);
        $recordset->execute(array(
            $id_campania,
            $sAgente,
            $sFechaSys,
            $sFechaSys,
            $sHoraSys,
            $sHoraSys));
        $tupla = $recordset->fetch(PDO::FETCH_ASSOC);
        $recordset->closeCursor();
        return $tupla;
    }

    /**
     * Procedimiento que construye una plantilla de marcado a partir de una
     * definición de trunk. Una plantilla de marcado es una cadena de texto de
     * la forma 'blablabla$OUTNUM$blabla' donde $OUTNUM$ es el lugar en que
     * debe constar el número saliente que va a marcarse. Por ejemplo, para
     * trunks de canales ZAP, la plantilla debe ser algo como Zap/g0/$OUTNUM$
     * Procedure that builds a dialing template from a trunk definition. A dialing
     * template is a text string of the form 'blablabla$OUTNUM$blabla' where
     * $OUTNUM$ is the place where the outgoing number to be dialed must appear.
     * For example, for ZAP channel trunks, the template should be something like
     * Zap/g0/$OUTNUM$
     *
     * @param   string  $sTrunk     Patrón que define el trunk a usar por la campaña
     *                             Pattern that defines the trunk to be used by the campaign
     *
     * @return  mixed   La cadena de plantilla de marcado, o NULL en error
     *                  The dial template string, or NULL on error
     */
    private function _construirPlantillaMarcado($sTrunk)
    {
        if (is_null($sTrunk)) {
            // La campaña requiere marcar por plan de marcado FreePBX
            // The campaign requires dialing through FreePBX dial plan
            return array('TRUNK' => 'Local/$OUTNUM$@from-internal');
        } elseif (stripos($sTrunk, '$OUTNUM$') !== FALSE) {
            // Este es un trunk personalizado que provee $OUTNUM$ ya preparado
            // This is a custom trunk that provides $OUTNUM$ already prepared
            return array('TRUNK' => $sTrunk);
        } elseif (strpos($sTrunk, 'SIP/') === 0
            || stripos($sTrunk, 'Zap/') === 0
            || stripos($sTrunk, 'DAHDI/') === 0
            || strpos($sTrunk,  'IAX/') === 0
            || strpos($sTrunk, 'IAX2/') === 0) {
            // Este es un trunk Zap o SIP. Se debe concatenar el prefijo de marcado
            // (si existe), y a continuación el número a marcar.
            // This is a Zap or SIP trunk. The dialing prefix must be concatenated
            // (if it exists), followed by the number to dial.
            $infoTrunk = $this->_leerPropiedadesTrunk($sTrunk);
            if (is_null($infoTrunk)) return NULL;

            // SIP/TRUNKLABEL/<PREFIX>$OUTNUM$
            $sPlantilla = $sTrunk.'/';
            if (isset($infoTrunk['PREFIX'])) $sPlantilla .= $infoTrunk['PREFIX'];
            $sPlantilla .= '$OUTNUM$';

            // Agregar información de Caller ID, si está disponible
            // Add Caller ID information, if available
            $plantilla = array('TRUNK' => $sPlantilla);
            if (isset($infoTrunk['CID']) && trim($infoTrunk['CID']) != '')
                $plantilla['CID'] = $infoTrunk['CID'];
            return $plantilla;
        } else {
            $this->_log->output("ERR: trunk '$sTrunk' es un tipo de trunk desconocido. Actualice su versión de CallCenter. | EN: trunk '$sTrunk' is an unknown trunk type. Update your CallCenter version.");
            return NULL;
        }
    }

    /**
     * Procedimiento que lee las propiedades del trunk indicado a partir de la
     * base de datos de FreePBX. Este procedimiento puede tomar algo de tiempo,
     * porque se requiere la información de /etc/amportal.conf para obtener las
     * credenciales para conectarse a la base de datos.
     * Procedure that reads the properties of the indicated trunk from the FreePBX
     * database. This procedure may take some time because it requires information
     * from /etc/amportal.conf to obtain credentials to connect to the database.
     *
     * @param   string  $sTrunk     Trunk sobre la cual leer información de DB
     *                             Trunk from which to read DB information
     *
     * @return  mixed   NULL en caso de error, o arreglo de propiedades
     *                  NULL on error, or array of properties
     */
    private function _leerPropiedadesTrunk($sTrunk)
    {
        /* Para evitar excesivas conexiones, se mantiene un cache de la información leída
         * acerca de un trunk durante los últimos 30 segundos.
         * To avoid excessive connections, a cache of the information read about a trunk
         * is maintained for the last 30 seconds.
         */
        if (isset($this->_plantillasMarcado[$sTrunk])) {
            if (time() - $this->_plantillasMarcado[$sTrunk]['TIMESTAMP'] >= 30)
                unset($this->_plantillasMarcado[$sTrunk]);
        }
        if (isset($this->_plantillasMarcado[$sTrunk])) {
            return $this->_plantillasMarcado[$sTrunk]['PROPIEDADES'];
        }

        $dbConn = $this->_abrirConexionFreePBX();
        if (is_null($dbConn)) return NULL;

        $infoTrunk = NULL;
        $sTrunkConsulta = $sTrunk;

        try {
            if ($this->_existeTrunksFPBX) {
                /* Consulta directa de las opciones del trunk indicado. Se debe
                 * separar la tecnología del nombre de la troncal, y consultar en
                 * campos separados en la tabla asterisk.trunks
                 * Direct query of the options of the indicated trunk. The technology
                 * must be separated from the trunk name, and queried in separate
                 * fields in the asterisk.trunks table. */
                $camposTrunk = explode('/', $sTrunkConsulta, 2);
                if (count($camposTrunk) < 2) {
                    $this->_log->output("ERR: trunk '$sTrunkConsulta' no se puede interpretar, se espera formato TECH/CHANNELID | EN: trunk '$sTrunkConsulta' cannot be interpreted, TECH/CHANNELID format expected");
                    $dbConn = NULL;
                    return NULL;
                }

                // Formas posibles de localizar la información deseada de troncales
                // Possible ways to locate the desired trunk information
                $listaIntentos = array(
                    array(
                        'tech'      => strtolower($camposTrunk[0]),
                        'channelid' => $camposTrunk[1]
                    ),
                );
                if ($listaIntentos[0]['tech'] == 'dahdi') {
                    $listaIntentos[] = array(
                        'tech'      => 'zap',
                        'channelid' => $camposTrunk[1]
                    );
                }
                $sPeticionSQL =
                    'SELECT outcid AS CID, dialoutprefix AS PREFIX '.
                    'FROM trunks WHERE tech = ? AND channelid = ?';
                $recordset = $dbConn->prepare($sPeticionSQL);
                foreach ($listaIntentos as $tuplaIntento) {
                    $recordset->execute(array($tuplaIntento['tech'], $tuplaIntento['channelid']));
                    $tupla = $recordset->fetch(PDO::FETCH_ASSOC);
                    $recordset->closeCursor();
                    if ($tupla) {
                        $infoTrunk = array();
                        if ($tupla['CID'] != '') $infoTrunk['CID'] = $tupla['CID'];
                        if ($tupla['PREFIX'] != '') $infoTrunk['PREFIX'] = $tupla['PREFIX'];
                        $this->_plantillasMarcado[$sTrunk] = array(
                            'TIMESTAMP'     =>  time(),
                            'PROPIEDADES'   =>  $infoTrunk,
                        );
                        break;
                    }
                }
            } else {
                /* Buscar cuál de las opciones describe el trunk indicado. En FreePBX,
                 * la información de los trunks está guardada en la tabla 'globals',
                 * donde globals.value tiene el nombre del trunk buscado, y
                 * globals.variable es de la forma OUT_NNNNN. El valor de NNN se usa
                 * para consultar el resto de las variables
                 * Find which of the options describes the indicated trunk. In FreePBX,
                 * trunk information is stored in the 'globals' table, where
                 * globals.value has the name of the sought trunk, and globals.variable
                 * is of the form OUT_NNNNN. The value of NNN is used to query the
                 * rest of the variables.
                 */
                $recordset = $dbConn->prepare("SELECT variable FROM globals WHERE value = ? AND variable LIKE 'OUT_%'");
                $recordset->execute(array($sTrunkConsulta));
                $sVariable = $recordset->fetch(PDO::FETCH_COLUMN, 0);
                $recordset->closeCursor();
                if (!$sVariable && strpos($sTrunkConsulta, 'DAHDI') !== 0) {
                    $this->_log->output("ERR: al consultar información de trunk '$sTrunkConsulta' en FreePBX (1) - trunk no se encuentra! | EN: querying trunk information '$sTrunkConsulta' in FreePBX (1) - trunk not found!");
                    $dbConn = NULL;
                    return NULL;
                }

                if (!$sVariable && strpos($sTrunkConsulta, 'DAHDI') === 0) {
                    /* Podría ocurrir que esta versión de FreePBX todavía guarda la
                     * información sobre troncales DAHDI bajo nombres ZAP. Para
                     * encontrarla, se requiere de transformación antes de la consulta.
                     * It may happen that this version of FreePBX still stores DAHDI
                     * trunk information under ZAP names. To find it, transformation
                     * is required before the query.
                     */
                    $sTrunkConsulta = str_replace('DAHDI', 'ZAP', $sTrunk);
                    $recordset->execute(array($sTrunkConsulta));
                    $sVariable = $recordset->fetch(PDO::FETCH_COLUMN, 0);
                    $recordset->closeCursor();
                    if (!$sVariable) {
                        $this->_log->output("ERR: al consultar información de trunk '$sTrunkConsulta' en FreePBX (1) - trunk no se encuentra! | EN: querying trunk information '$sTrunkConsulta' in FreePBX (1) - trunk not found!");
                        $dbConn = NULL;
                        return NULL;
                    }
                }

                $regs = NULL;
                if (!preg_match('/^OUT_([[:digit:]]+)$/', $sVariable, $regs)) {
                    $this->_log->output("ERR: al consultar información de trunk '$sTrunkConsulta' en FreePBX (1) - se esperaba OUT_NNN pero se encuentra $sVariable - versión incompatible de FreePBX? | EN: querying trunk information '$sTrunkConsulta' in FreePBX (1) - expected OUT_NNN but found $sVariable - incompatible FreePBX version?");
                } else {
                    $iNumTrunk = $regs[1];

                    // Consultar todas las variables asociadas al trunk
                    // Query all variables associated with the trunk
                    $sPeticionSQL = 'SELECT variable, value FROM globals WHERE variable LIKE ?';
                    $recordset = $dbConn->prepare($sPeticionSQL);
                    $recordset->execute(array('OUT%_'.$iNumTrunk));
                    $recordset->setFetchMode(PDO::FETCH_ASSOC);
                    $infoTrunk = array();
                    $sRegExp = '/^OUT(.+)_'.$iNumTrunk.'$/';
                    foreach ($recordset as $tupla) {
                        $regs = NULL;
                        if (preg_match($sRegExp, $tupla['variable'], $regs)) {
                            $sValor = trim($tupla['value']);
                            if ($sValor != '') $infoTrunk[$regs[1]] = $sValor;
                        }
                    }
                    $this->_plantillasMarcado[$sTrunk] = array(
                        'TIMESTAMP'     =>  time(),
                        'PROPIEDADES'   =>  $infoTrunk,
                    );
                }
            }
        } catch (PDOException $e) {
        	$this->_log->output(
                "ERR: al consultar información de trunk '$sTrunkConsulta' en FreePBX - ".
                implode(' - ', $e->errorInfo)." | EN: querying trunk information '$sTrunkConsulta' in FreePBX - ".
                implode(' - ', $e->errorInfo));
        }

        $dbConn = NULL;
        return $infoTrunk;
    }

    // TODO: encontrar manera elegante de tener una sola definición
    // TODO: find an elegant way to have a single definition
    private function _abrirConexionFreePBX()
    {
        $sNombreConfig = '/etc/amportal.conf';  // TODO: vale la pena poner esto en config?
                                                // TODO: is it worth putting this in config?

        // De algunas pruebas se desprende que parse_ini_file no puede parsear
        // /etc/amportal.conf, de forma que se debe abrir directamente.
        // Some tests show that parse_ini_file cannot parse /etc/amportal.conf,
        // so it must be opened directly.
        $dbParams = array();
        $hConfig = fopen($sNombreConfig, 'r');
        if (!$hConfig) {
            $this->_log->output('ERR: no se puede abrir archivo '.$sNombreConfig.' para lectura de parámetros FreePBX. | EN: cannot open file '.$sNombreConfig.' for FreePBX parameter reading.');
            return NULL;
        }
        while (!feof($hConfig)) {
            $sLinea = fgets($hConfig);
            if ($sLinea === FALSE) break;
            $sLinea = trim($sLinea);
            if ($sLinea == '') continue;
            if ($sLinea[0] == '#') continue;

            $regs = NULL;
            if (preg_match('/^([[:alpha:]]+)[[:space:]]*=[[:space:]]*(.*)$/', $sLinea, $regs)) switch ($regs[1]) {
            case 'AMPDBHOST':
            case 'AMPDBUSER':
            case 'AMPDBENGINE':
            case 'AMPDBPASS':
                $dbParams[$regs[1]] = $regs[2];
                break;
            }
        }
        fclose($hConfig); unset($hConfig);

        // Abrir la conexión a la base de datos, si se tienen todos los parámetros
        // Open database connection if all parameters are available
        if (count($dbParams) < 4) {
            $this->_log->output('ERR: archivo '.$sNombreConfig.
                ' de parámetros FreePBX no tiene todos los parámetros requeridos para conexión. | EN: file '.$sNombreConfig.
                ' of FreePBX parameters does not have all required parameters for connection.');
            return NULL;
        }
        if ($dbParams['AMPDBENGINE'] != 'mysql' && $dbParams['AMPDBENGINE'] != 'mysqli') {
            $this->_log->output('ERR: archivo '.$sNombreConfig.
                ' de parámetros FreePBX especifica AMPDBENGINE='.$dbParams['AMPDBENGINE'].
                ' que no ha sido probado. | EN: file '.$sNombreConfig.
                ' of FreePBX parameters specifies AMPDBENGINE='.$dbParams['AMPDBENGINE'].
                ' which has not been tested.');
            return NULL;
        }
        try {
            $dbConn = new PDO("mysql:host={$dbParams['AMPDBHOST']};dbname=asterisk",
                $dbParams['AMPDBUSER'], $dbParams['AMPDBPASS']);
            $dbConn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $dbConn->setAttribute(PDO::ATTR_EMULATE_PREPARES, FALSE);
            return $dbConn;
        } catch (PDOException $e) {
            $this->_log->output("ERR: no se puede conectar a DB de FreePBX - ".
                $e->getMessage()." | EN: cannot connect to FreePBX DB - ".
                $e->getMessage());
            return NULL;
        }
    }

    private function _leerTiempoContestar($idCampaign)
    {
    	return $this->_tuberia->AMIEventProcess_leerTiempoContestar($idCampaign);
    }

    /* Contar el número de llamadas que se colocaron en la cola $queue y que han
     * sido originadas, pero todavía esperan respuesta
     * Count the number of calls placed in queue $queue that have been originated
     * but are still waiting for response */
    private function _contarLlamadasEsperandoRespuesta($queue)
    {
        return $this->_tuberia->AMIEventProcess_contarLlamadasEsperandoRespuesta($queue);
    }

    // Construir la cadena de variables, con separador adecuado según versión Asterisk
    // Build variable string with appropriate separator according to Asterisk version
    private function _construirCadenaVariables($listaVar)
    {
        // "ID_CAMPAIGN={$infoCampania->id}|ID_CALL={$tupla->id}|NUMBER={$tupla->phone}|QUEUE={$infoCampania->queue}|CONTEXT={$infoCampania->context}",
        $lista = array();
        foreach ($listaVar as $sKey => $sVal) {
            $lista[] = "{$sKey}={$sVal}";
        }
        return $this->_construirListaParametros($lista);
    }

    private function _construirListaParametros($listaVar)
    {
        $sSeparador = !is_null($this->_compat)
            ? $this->_compat->getVariableSeparator()
            : ',';
        return implode($sSeparador, $listaVar);
    }

    /**************************************************************************/

    public function msg_verificarFinLlamadasAgendables($sFuente, $sDestino,
        $sNombreMensaje, $iTimestamp, $datos)
    {
        if ($this->DEBUG) {
            $this->_log->output('DEBUG: '.__METHOD__.' - datos/data: '.print_r($datos, 1));
        }
        call_user_func_array(array($this, '_verificarFinLlamadasAgendables'), $datos);
    }

    public function msg_finalizando($sFuente, $sDestino, $sNombreMensaje, $iTimestamp, $datos)
    {
        $this->_log->output('INFO: recibido mensaje de finalización, se detienen campañas... | EN: received shutdown message, stopping campaigns...');
        $this->_tuberia->msg_HubProcess_finalizacionTerminada();
        $this->_finalizandoPrograma = TRUE;
    }

    /**************************************************************************/

    private function _verificarFinLlamadasAgendables($sAgente, $id_campania)
    {
        $l = $this->_contarLlamadasAgendablesReserva($id_campania, $sAgente);
        if ($l['AHORA'] == 0 && $l['RESERVA'] == 0) {
            /* Por ahora el agente ya no tiene llamadas agendables y se debe
             * reducir la cuenta de pausas del agente. Si la cuenta es 1,
             * entonces se debe quitar la pausa real.
             * For now the agent no longer has schedulable calls and the agent's
             * pause count must be reduced. If the count is 1, then the real pause
             * must be removed. */
            if ($this->DEBUG) {
                $this->_log->output('DEBUG: '.__METHOD__.': el siguiente agente '.
                    'no tiene más llamadas agendadas: '.$sAgente.' | EN: the following agent '.
                    'has no more scheduled calls: '.$sAgente);
            }
            $this->_tuberia->msg_AMIEventProcess_quitarReservaAgente($sAgente);
        }
    }
}
?>
