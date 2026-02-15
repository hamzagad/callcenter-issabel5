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
  $Id:  $ */
$DocumentRoot = "/var/www/html";

require_once("$DocumentRoot/libs/paloSantoInstaller.class.php");
require_once("$DocumentRoot/libs/paloSantoDB.class.php");

$tmpDir = '/tmp/new_module/callcenter';  # in this folder the load module extract the package content
#generar el archivo db de campañas // EN: Generate campaign db file
$return=1;
$path_script_db="$tmpDir/setup/call_center.sql";
$datos_conexion['user']     = "asterisk";
$datos_conexion['password'] = "asterisk";
$datos_conexion['locate']   = "";
$oInstaller = new Installer();

if (file_exists($path_script_db))
{
    //STEP 1: Create database call_center
    $return=0;
    $return=$oInstaller->createNewDatabaseMySQL($path_script_db,"call_center",$datos_conexion);

    // STEP 1.1: Ensure asterisk user has permissions on call_center database
    $pDBRoot = new paloDB('mysql://root:'.MYSQL_ROOT_PASSWORD.'@localhost/mysql');
    $pDBRoot->genQuery("GRANT ALL ON call_center.* TO asterisk@localhost IDENTIFIED BY 'asterisk'");
    $pDBRoot->genQuery("FLUSH PRIVILEGES");
    $pDBRoot->disconnect();
    fputs(STDERR, "INFO: Granted permissions to asterisk@localhost on call_center database | Es: Permisos concedidos a asterisk@localhost en base de datos call_center\n");

    $pDB = new paloDB ('mysql://root:'.MYSQL_ROOT_PASSWORD.'@localhost/call_center');
    quitarColumnaSiExiste($pDB, 'call_center', 'agent', 'queue');
    crearColumnaSiNoExiste($pDB, 'call_center', 'calls',
        'dnc',
        "ADD COLUMN dnc int(1) NOT NULL DEFAULT '0'");
    crearColumnaSiNoExiste($pDB, 'call_center', 'call_entry',
        'id_campaign',
        "ADD COLUMN id_campaign  int unsigned, ADD FOREIGN KEY (id_campaign) REFERENCES campaign_entry (id)");
    crearColumnaSiNoExiste($pDB, 'call_center', 'calls',
        'date_init',
        "ADD COLUMN date_init  date, ADD COLUMN date_end  date, ADD COLUMN time_init  time, ADD COLUMN time_end  time");
    crearColumnaSiNoExiste($pDB, 'call_center', 'calls',
        'agent',
        "ADD COLUMN agent varchar(32)");
    crearColumnaSiNoExiste($pDB, 'call_center', 'call_entry',
        'trunk',
        "ADD COLUMN trunk varchar(50) NOT NULL");
    crearColumnaSiNoExiste($pDB, 'call_center', 'calls',
        'failure_cause',
        "ADD COLUMN failure_cause int(10) unsigned default null, ADD COLUMN failure_cause_txt varchar(32) default null");
    crearColumnaSiNoExiste($pDB, 'call_center', 'calls',
        'datetime_originate',
        "ADD COLUMN datetime_originate datetime default NULL");
    crearColumnaSiNoExiste($pDB, 'call_center', 'agent',
        'eccp_password',
        "ADD COLUMN eccp_password varchar(128) default NULL");
    crearColumnaSiNoExiste($pDB, 'call_center', 'campaign',
        'id_url',
        "ADD COLUMN id_url int unsigned, ADD FOREIGN KEY (id_url) REFERENCES campaign_external_url (id)");
    crearColumnaSiNoExiste($pDB, 'call_center', 'campaign_entry',
        'id_url',
        "ADD COLUMN id_url int unsigned, ADD FOREIGN KEY (id_url) REFERENCES campaign_external_url (id)");
    crearColumnaSiNoExiste($pDB, 'call_center', 'calls',
        'trunk',
        "ADD COLUMN trunk varchar(50)");
    crearColumnaSiNoExiste($pDB, 'call_center', 'agent',
        'type',
        "ADD COLUMN type enum('Agent','SIP','PJSIP','IAX2') DEFAULT 'Agent' NOT NULL AFTER id");
    // Ensure PJSIP is in the enum for existing installations
    $pDB->genQuery("ALTER TABLE agent MODIFY type enum('Agent','SIP','PJSIP','IAX2') DEFAULT 'Agent' NOT NULL");
    crearColumnaSiNoExiste($pDB, 'call_center', 'calls',
        'scheduled',
        "ALTER TABLE calls ADD COLUMN scheduled BOOLEAN NOT NULL DEFAULT 0");
    crearColumnaSiNoExiste($pDB, 'call_center', 'audit',
        'login_extension',
        "ADD COLUMN login_extension varchar(20) default NULL");

    crearIndiceSiNoExiste($pDB, 'call_center', 'audit',
        'agent_break_datetime',
        "ADD KEY agent_break_datetime (id_agent, id_break, datetime_init)");
    crearIndiceSiNoExiste($pDB, 'call_center', 'calls',
        'datetime_init',
        "ADD KEY datetime_init (start_time)");
    crearIndiceSiNoExiste($pDB, 'call_center', 'calls',
        'datetime_entry_queue',
        "ADD KEY datetime_entry_queue (start_time)");
    crearIndiceSiNoExiste($pDB, 'call_center', 'call_entry',
        'datetime_init',
        "ADD KEY datetime_init (datetime_init)");
    crearIndiceSiNoExiste($pDB, 'call_center', 'call_entry',
        'datetime_entry_queue',
        "ADD KEY datetime_entry_queue (datetime_init)");
    crearIndiceSiNoExiste($pDB, 'call_center', 'dont_call',
        'callerid',
        "ADD KEY callerid (caller_id)");
    crearIndiceSiNoExiste($pDB, 'call_center', 'agent',
        'agent_type',
        "ADD KEY `agent_type` (`estatus`,`type`,`number`)");
    crearIndiceSiNoExiste($pDB, 'call_center', 'calls',
        'campaign_date_schedule',
        "ADD KEY `campaign_date_schedule` (`id_campaign`, `date_init`, `date_end`, `time_init`, `time_end`)");

    // Actualizar longitud de campos trunk y ChannelClient a 50 caracteres
    // EN: Update length of trunk and ChannelClient fields to 50 characters
    actualizarLongitudCampo($pDB, 'call_center', 'call_entry', 'trunk', 50);
    actualizarLongitudCampo($pDB, 'call_center', 'call_progress_log', 'trunk', 50);
    actualizarLongitudCampo($pDB, 'call_center', 'calls', 'trunk', 50);
    actualizarLongitudCampo($pDB, 'call_center', 'campaign', 'trunk', 50);
    actualizarLongitudCampo($pDB, 'call_center', 'current_call_entry', 'ChannelClient', 50);
    actualizarLongitudCampo($pDB, 'call_center', 'current_calls', 'ChannelClient', 50);

    // Asegurarse de que todo agente tiene una contraseña de ECCP
    // EN: Ensure that every agent has an ECCP password
    $pDB->genQuery('UPDATE agent SET eccp_password = SHA1(CONCAT(NOW(), RAND(), number)) WHERE eccp_password IS NULL');

    $pDB->disconnect();
}

// Detect Asterisk major version for conditional installation
$output = shell_exec('asterisk -rx "core show version" 2>/dev/null');
$astMajor = 18; // default
if (preg_match('/Asterisk (\d+)/', $output, $m)) {
    $astMajor = (int)$m[1];
}
fputs(STDERR, "INFO: Detected Asterisk $astMajor for installer decisions\n");

instalarContextosEspeciales($astMajor);

if ($astMajor >= 12) {
    // app_agent_pool: install [agent-defaults] template and convert agents to section format
    instalarAgentDefaultsTemplate();
    convertirAgentsConf($astMajor);
} else {
    // chan_agent: convert agents to legacy format with passwords from database
    fputs(STDERR, "INFO: Skipping [agent-defaults] template (chan_agent on Asterisk $astMajor)\n");
    convertirAgentsConf($astMajor);
}

exit($return);

function quitarColumnaSiExiste($pDB, $sDatabase, $sTabla, $sColumna)
{
    $sPeticionSQL = <<<EXISTE_COLUMNA
SELECT COUNT(*)
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?
EXISTE_COLUMNA;
    $r = $pDB->getFirstRowQuery($sPeticionSQL, FALSE, array($sDatabase, $sTabla, $sColumna));
    if (!is_array($r)) {
        fputs(STDERR, "ERR: al verificar tabla $sTabla.$sColumna - ".$pDB->errMsg." | EN: ERR: al verificar tabla $sTabla.$sColumna\n");
        return;
    }
    if ($r[0] > 0) {
        fputs(STDERR, "INFO: Se encuentra $sTabla.$sColumna en base de datos $sDatabase, se ejecuta: | EN: INFO: Found $sTabla.$sColumna in database $sDatabase, executing:\n");
        $sql = "ALTER TABLE $sTabla DROP COLUMN $sColumna";
        fputs(STDERR, "\t$sql\n");
        $r = $pDB->genQuery($sql);
        if (!$r) fputs(STDERR, "ERR: ".$pDB->errMsg."\n");
    } else {
        fputs(STDERR, "INFO: No existe $sTabla.$sColumna en base de datos $sDatabase. No se hace nada. | EN: INFO: $sTabla.$sColumna does not exist in database $sDatabase. Nothing done.\n");
    }
}

function crearColumnaSiNoExiste($pDB, $sDatabase, $sTabla, $sColumna, $sColumnaDef)
{
    $sPeticionSQL = <<<EXISTE_COLUMNA
SELECT COUNT(*)
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?
EXISTE_COLUMNA;
    $r = $pDB->getFirstRowQuery($sPeticionSQL, FALSE, array($sDatabase, $sTabla, $sColumna));
    if (!is_array($r)) {
        fputs(STDERR, "ERR: al verificar tabla $sTabla.$sColumna - ".$pDB->errMsg." | EN: ERR: al verificar tabla $sTabla.$sColumna\n");
        return;
    }
    if ($r[0] <= 0) {
        fputs(STDERR, "INFO: No se encuentra $sTabla.$sColumna en base de datos $sDatabase, se ejecuta: | EN: INFO: $sTabla.$sColumna not found in database $sDatabase, executing:\n");
        $sql = "ALTER TABLE $sTabla $sColumnaDef";
        fputs(STDERR, "\t$sql\n");
        $r = $pDB->genQuery($sql);
        if (!$r) fputs(STDERR, "ERR: ".$pDB->errMsg."\n");
    } else {
        fputs(STDERR, "INFO: Ya existe $sTabla.$sColumna en base de datos $sDatabase. | EN: INFO: $sTabla.$sColumna already exists in database $sDatabase.\n");
    }
}

function crearIndiceSiNoExiste($pDB, $sDatabase, $sTabla, $sIndice, $sIndiceDef)
{
    $sPeticionSQL = <<<EXISTE_INDICE
SELECT COUNT(*)
FROM INFORMATION_SCHEMA.STATISTICS
WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ?
EXISTE_INDICE;
    $r = $pDB->getFirstRowQuery($sPeticionSQL, FALSE, array($sDatabase, $sTabla, $sIndice));
    if (!is_array($r)) {
        fputs(STDERR, "ERR: al verificar tabla $sTabla.$sIndice - ".$pDB->errMsg." | EN: ERR: al verificar índice $sTabla.$sIndice\n");
        return;
    }
    if ($r[0] <= 0) {
        fputs(STDERR, "INFO: No se encuentra $sTabla.$sIndice en base de datos $sDatabase, se ejecuta: | EN: INFO: $sTabla.$sIndice not found in database $sDatabase, executing:\n");
        $sql = "ALTER TABLE $sTabla $sIndiceDef";
        fputs(STDERR, "\t$sql\n");
        $r = $pDB->genQuery($sql);
        if (!$r) fputs(STDERR, "ERR: ".$pDB->errMsg."\n");
    } else {
        fputs(STDERR, "INFO: Ya existe $sTabla.$sIndice en base de datos $sDatabase. | EN: INFO: $sTabla.$sIndice already exists in database $sDatabase.\n");
    }
}

function actualizarLongitudCampo($pDB, $sDatabase, $sTabla, $sColumna, $iNuevaLongitud)
{
    // Verificar longitud actual de la columna
    // EN: Verify current length of column
    $sPeticionSQL = <<<VERIFICAR_LONGITUD
SELECT CHARACTER_MAXIMUM_LENGTH
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?
VERIFICAR_LONGITUD;
    $r = $pDB->getFirstRowQuery($sPeticionSQL, FALSE, array($sDatabase, $sTabla, $sColumna));
    if (!is_array($r)) {
        fputs(STDERR, "ERR: al verificar longitud de $sTabla.$sColumna - ".$pDB->errMsg." | EN: ERR: al verificar longitud de $sTabla.$sColumna\n");
        return;
    }
    if (isset($r[0]) && $r[0] < $iNuevaLongitud) {
        $tipo = $pDB->getFirstRowQuery(
            "SELECT DATA_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?",
            FALSE,
            array($sDatabase, $sTabla, $sColumna)
        );
        $nulo = $pDB->getFirstRowQuery(
            "SELECT IS_NULLABLE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?",
            FALSE,
            array($sDatabase, $sTabla, $sColumna)
        );
        $nullClause = (is_array($nulo) && strtoupper($nulo[0]) == 'YES') ? 'NULL' : 'NOT NULL';
        $sql = "ALTER TABLE $sTabla MODIFY COLUMN $sColumna " . $tipo[0] . "($iNuevaLongitud) $nullClause";
        fputs(STDERR, "INFO: Actualizando longitud de $sTabla.$sColumna a $iNuevaLongitud caracteres | EN: INFO: Updating length of $sTabla.$sColumna to $iNuevaLongitud characters\n");
        fputs(STDERR, "\t$sql\n");
        $r = $pDB->genQuery($sql);
        if (!$r) fputs(STDERR, "ERR: ".$pDB->errMsg."\n");
    } else {
        fputs(STDERR, "INFO: La longitud de $sTabla.$sColumna ya es adecuada o no existe. | EN: INFO: The length of $sTabla.$sColumna is already adequate or does not exist.\n");
    }
}

/**
 * Procedimiento que instala algunos contextos especiales requeridos para algunas
 * funcionalidades del CallCenter.
 *
 * EN: Function that installs some special contexts required for some
 * CallCenter functionalities.
 */
function instalarContextosEspeciales($astMajor = 18)
{
	$sArchivo = '/etc/asterisk/extensions_custom.conf';
    $sInicioContenido = "; BEGIN ISSABEL CALL-CENTER CONTEXTS DO NOT REMOVE THIS LINE\n";
    $sFinalContenido =  "; END ISSABEL CALL-CENTER CONTEXTS DO NOT REMOVE THIS LINE\n";

    // Cargar el archivo, notando el inicio y el final del área de contextos de callcenter
    // EN: Load the file, noting the start and end of the callcenter contexts area
    $bEncontradoInicio = $bEncontradoFinal = FALSE;
    $contenido = array();
    foreach (file($sArchivo) as $sLinea) {
    	if ($sLinea == $sInicioContenido) {
    		$bEncontradoInicio = TRUE;
        } elseif ($sLinea == $sFinalContenido) {
            $bEncontradoFinal = TRUE;
    	} elseif (!$bEncontradoInicio || $bEncontradoFinal) {
            if (substr($sLinea, strlen($sLinea) - 1) != "\n")
                $sLinea .= "\n";
    	    $contenido[] = $sLinea;
    	}
    }
    if ($bEncontradoInicio xor $bEncontradoFinal) {
    	fputs(STDERR, "ERR: no se puede localizar correctamente segmento de contextos de Call Center | EN: ERR: cannot correctly locate Call Center contexts segment\n");
    } else {
    	$contenido[] = $sInicioContenido;

        // [llamada_agendada] - Scheduled callback context (works on all versions)
        $sContextos = '
; Scheduled call context for callback campaigns
[llamada_agendada]
exten => _X.,1,NoOP("Issabel CallCenter: AGENTCHANNEL=${AGENTCHANNEL}")
exten => _X.,n,NoOP("Issabel CallCenter: QUEUE_MONITOR_FORMAT=${QUEUE_MONITOR_FORMAT}")
exten => _X.,n,GotoIf($["${QUEUE_MONITOR_FORMAT}" = ""]?skiprecord)
exten => _X.,n,Set(CALLFILENAME=${STRFTIME(${EPOCH},,%Y%m%d-%H%M%S)}-${UNIQUEID})
exten => _X.,n,MixMonitor(${MIXMON_DIR}${CALLFILENAME}.${MIXMON_FORMAT},,${MIXMON_POST})
exten => _X.,n,Set(CDR(userfield)=audio:${CALLFILENAME}.${MIXMON_FORMAT})
exten => _X.,n(skiprecord),Dial(${AGENTCHANNEL},300,tw)
exten => h,1,Macro(hangupcall,)
';

        // Callback agent attended transfer context (SIP/IAX2/PJSIP types)
        // Dials device directly to avoid 20-second busy tone delay when target declines
        $sContextos .= '
; Attended transfer context for callback agents (SIP/IAX2/PJSIP)
; Dials device directly to avoid busy tone delay from from-internal failure handling
[cbext-atxfer]
exten => _X.,1,NoOp(Issabel CallCenter: Callback attended transfer routing for ${EXTEN})
 same => n,Set(CLEAN_EXTEN=${FILTER(0123456789,${EXTEN})})
 same => n,ExecIf($["${CLEAN_EXTEN}" = ""]?Set(CLEAN_EXTEN=${EXTEN}))
 same => n,Set(DIAL_DEVICE=${DB(DEVICE/${CLEAN_EXTEN}/dial)})
 same => n,GotoIf($["${DIAL_DEVICE}" != ""]?direct)
 same => n(fallback),NoOp(Issabel CallCenter: No device found for ${CLEAN_EXTEN} - routing via from-internal)
 same => n,Dial(Local/${CLEAN_EXTEN}@from-internal/n,120)
 same => n,Hangup()
 same => n(direct),NoOp(Issabel CallCenter: Direct device dial: ${DIAL_DEVICE})
 same => n,GotoIf($["${DIAL_DEVICE:0:5}" = "PJSIP"]?pjsip)
 same => n,Dial(${DIAL_DEVICE},120)
 same => n,Hangup()
 same => n(pjsip),Set(PJSIP_CONTACTS=${PJSIP_DIAL_CONTACTS(${CLEAN_EXTEN})})
 same => n,ExecIf($["${PJSIP_CONTACTS}" = ""]?Set(PJSIP_CONTACTS=${DIAL_DEVICE}))
 same => n,NoOp(Issabel CallCenter: PJSIP dial: ${PJSIP_CONTACTS})
 same => n,Dial(${PJSIP_CONTACTS},120)
 same => n,Hangup()
';

        // app_agent_pool contexts (Asterisk 12+):
        // [agent-login]: login via context (no Asterisk password prompt)
        // [atxfer-complete]: re-enter AgentLogin after attended transfer
        // [agents]: AgentRequest() for incoming queue calls
        // On Asterisk 11 (chan_agent), login is done via direct Originate to
        // the AgentLogin application which prompts for the agents.conf password
        if ($astMajor >= 12) {
            $sContextos .= '
; app_agent_pool contexts (Asterisk 12+)
; Agent type login, request, and attended transfer contexts
[agent-login]
exten => _X.,1,NoOp(Issabel CallCenter: Agent Login for ${EXTEN})
 same => n,AgentLogin(${EXTEN})
 same => n,Macro(hangupcall,)

[atxfer-complete]
exten => _X.,1,NoOp(Issabel CallCenter: Attended transfer completion - agent ${EXTEN} re-entering AgentLogin)
 same => n,AgentLogin(${EXTEN},s)
 same => n,Macro(hangupcall,)

[agents]
exten => _X.,1,NoOp(Issabel CallCenter: Connecting to Agent ${EXTEN})
 same => n,AgentRequest(${EXTEN})
 same => n,Macro(hangupcall,)

; Attended transfer contexts for Agent type (app_agent_pool)
; Used when DTMF hooks are lost after Local channel optimization
[atxfer-hold]
exten => s,1,NoOp(Issabel CallCenter: Attended Transfer - Caller on hold)
 same => n,Answer()
 same => n,MusicOnHold(default)

[atxfer-consult]
exten => _X.,1,NoOp(Issabel CallCenter: Attended Transfer - Consulting ${EXTEN})
 same => n,Set(__ATXFER_HELD_CHAN=${ATXFER_HELD_CHAN})
 same => n,Set(AGENT_NUM=${ATXFER_AGENT_NUM})
 same => n,Dial(Local/${EXTEN}@from-internal,120,gF(atxfer-bridge^s^1))
 same => n,NoOp(Issabel CallCenter: Consultation ended DIALSTATUS=${DIALSTATUS} - reconnecting with caller)
 same => n,UserEvent(ConsultationEnd,Agent: Agent/${AGENT_NUM})
 same => n,Bridge(${ATXFER_HELD_CHAN})
 same => n,Goto(atxfer-complete,${AGENT_NUM},1)

[atxfer-bridge]
exten => s,1,NoOp(Issabel CallCenter: Transfer complete - bridging target with held caller)
 same => n,Bridge(${ATXFER_HELD_CHAN})
 same => n,Hangup()
';
        } else {
            fputs(STDERR, "INFO: Skipping app_agent_pool contexts (chan_agent on Asterisk $astMajor)\n");
        }

        $contenido[] = $sContextos;
        $contenido[] = $sFinalContenido;
        file_put_contents($sArchivo, $contenido);
        chown($sArchivo, 'asterisk'); chgrp($sArchivo, 'asterisk');
    }
}

/**
 * Create the [agent-defaults] template in agents.conf for app_agent_pool (Asterisk 12+).
 * This template is inherited by all agent definitions.
 */
function instalarAgentDefaultsTemplate()
{
    $sArchivo = '/etc/asterisk/agents.conf';
    $sTemplate = "[agent-defaults](!)\n" .
                 "musiconhold=default\n" .
                 "ackcall=no\n" .
                 "autologoff=0\n" .
                 "wrapuptime=0\n\n";

    // EN: Check if file exists and if template already exists
    // Verificar si el archivo existe y si la plantilla ya existe
    if (file_exists($sArchivo)) {
        $contenido = file_get_contents($sArchivo);
        if (strpos($contenido, '[agent-defaults](!)') !== false) {
            fputs(STDERR, "INFO: [agent-defaults] template already exists in agents.conf | EN: INFO: plantilla [agent-defaults] ya existe en agents.conf\n");
            return;
        }
        // EN: Append template at the end
        // Agregar plantilla al final
        file_put_contents($sArchivo, $contenido . $sTemplate);
    } else {
        // EN: Create new file with template
        // Crear nuevo archivo con plantilla
        file_put_contents($sArchivo, $sTemplate);
    }
    chown($sArchivo, 'asterisk');
    chgrp($sArchivo, 'asterisk');
    fputs(STDERR, "INFO: Created [agent-defaults] template in agents.conf | Es: INFO: Plantilla [agent-defaults] creada en agents.conf\n");
}

/**
 * Convert existing agents.conf entries to the correct format for the detected
 * Asterisk version. Handles upgrade (Ast 11->13/18) and downgrade scenarios.
 *
 * chan_agent (Ast 11):       agent => number,password,name
 * app_agent_pool (Ast 12+): [number](agent-defaults)\nfullname=name
 *
 * Reads agent passwords from the call_center database to populate the
 * chan_agent format, since app_agent_pool entries do not store passwords.
 */
function convertirAgentsConf($astMajor)
{
    $sArchivo = '/etc/asterisk/agents.conf';
    if (!file_exists($sArchivo)) return;

    $contenido = file($sArchivo);
    if (!is_array($contenido)) return;

    $bUsaChanAgent = ($astMajor < 12);

    // Collect existing agents from the file in both formats
    $agentesEncontrados = array(); // number => name
    $formatoActual = 'unknown';
    $currentAgentId = NULL;
    $currentAgentName = '';
    $bTieneFormatoChanAgent = FALSE;
    $bTieneFormatoAppAgentPool = FALSE;

    foreach ($contenido as $sLinea) {
        $sLinea = trim($sLinea);
        // app_agent_pool format: [number](agent-defaults)
        if (preg_match('/^\[(\d+)\](\(agent-defaults\))?$/', $sLinea, $regs)) {
            if ($currentAgentId !== NULL) {
                $agentesEncontrados[$currentAgentId] = $currentAgentName;
            }
            $currentAgentId = $regs[1];
            $currentAgentName = '';
            $bTieneFormatoAppAgentPool = TRUE;
            continue;
        }
        if ($currentAgentId !== NULL && preg_match('/^fullname\s*=\s*(.*)$/', $sLinea, $regs)) {
            $currentAgentName = $regs[1];
            continue;
        }
        if ($currentAgentId !== NULL && preg_match('/^\[/', $sLinea)) {
            $agentesEncontrados[$currentAgentId] = $currentAgentName;
            $currentAgentId = NULL;
        }
        // chan_agent format: agent => number,password,name
        if (preg_match('/^agent\s*=>\s*(\d+),([^,]*),(.*)$/', $sLinea, $regs)) {
            $agentesEncontrados[$regs[1]] = $regs[3];
            $bTieneFormatoChanAgent = TRUE;
        }
    }
    if ($currentAgentId !== NULL) {
        $agentesEncontrados[$currentAgentId] = $currentAgentName;
    }

    if (count($agentesEncontrados) == 0) {
        fputs(STDERR, "INFO: No agent entries found in agents.conf, nothing to convert\n");
        return;
    }

    // Check if conversion is needed
    if ($bUsaChanAgent && !$bTieneFormatoAppAgentPool) {
        fputs(STDERR, "INFO: agents.conf already in chan_agent format, no conversion needed\n");
        return;
    }
    if (!$bUsaChanAgent && !$bTieneFormatoChanAgent) {
        fputs(STDERR, "INFO: agents.conf already in app_agent_pool format, no conversion needed\n");
        return;
    }

    fputs(STDERR, "INFO: Converting agents.conf entries to ".
        ($bUsaChanAgent ? 'chan_agent' : 'app_agent_pool')." format\n");

    // For chan_agent format, we need passwords from the database
    $agentPasswords = array();
    if ($bUsaChanAgent) {
        try {
            $pDB = new paloDB('mysql://root:'.MYSQL_ROOT_PASSWORD.'@localhost/call_center');
            $result = $pDB->fetchTable("SELECT number, password FROM agent WHERE estatus = 'A'", TRUE);
            if (is_array($result)) {
                foreach ($result as $row) {
                    $agentPasswords[$row['number']] = $row['password'];
                }
            }
            $pDB->disconnect();
        } catch (Exception $e) {
            fputs(STDERR, "WARN: Cannot read agent passwords from database: ".$e->getMessage()."\n");
        }
    }

    // Rebuild agents.conf: keep header/comments/general/template, replace agent entries
    $contenidoNuevo = array();
    $bEnSeccionAgente = FALSE;
    $bYaAgregados = FALSE;

    foreach ($contenido as $sLinea) {
        $sLineaTrim = trim($sLinea);

        // Skip existing agent entries (both formats)
        if (preg_match('/^\[(\d+)\](\(agent-defaults\))?$/', $sLineaTrim)) {
            $bEnSeccionAgente = TRUE;
            continue;
        }
        if ($bEnSeccionAgente) {
            if (preg_match('/^\[/', $sLineaTrim)) {
                $bEnSeccionAgente = FALSE;
                // Fall through to process this line normally
            } else {
                continue; // Skip lines within agent section
            }
        }
        if (preg_match('/^agent\s*=>\s*\d+,/', $sLineaTrim)) {
            continue; // Skip chan_agent format lines
        }

        $contenidoNuevo[] = $sLinea;
    }

    // Append all agents in the correct format at the end
    foreach ($agentesEncontrados as $number => $name) {
        if ($bUsaChanAgent) {
            $pass = isset($agentPasswords[$number]) ? $agentPasswords[$number] : '';
            $contenidoNuevo[] = "agent => {$number},{$pass},{$name}\n";
            fputs(STDERR, "INFO:   Converted agent {$number} to chan_agent format\n");
        } else {
            $contenidoNuevo[] = "\n[{$number}](agent-defaults)\n";
            $contenidoNuevo[] = "fullname={$name}\n";
            fputs(STDERR, "INFO:   Converted agent {$number} to app_agent_pool format\n");
        }
    }

    $hArchivo = fopen($sArchivo, 'w');
    if (!$hArchivo) {
        fputs(STDERR, "ERR: Cannot write agents.conf\n");
        return;
    }
    foreach ($contenidoNuevo as $sLinea) fwrite($hArchivo, $sLinea);
    fclose($hArchivo);
    chown($sArchivo, 'asterisk');
    chgrp($sArchivo, 'asterisk');
    fputs(STDERR, "INFO: agents.conf conversion complete (".count($agentesEncontrados)." agents)\n");
}
?>
