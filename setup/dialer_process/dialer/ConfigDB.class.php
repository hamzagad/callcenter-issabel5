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
  $Id: ClaseCampania.class.php,v 1.48 2009/03/26 13:46:58 alex Exp $ */

// Enumeración para informar fuente de conexión Asterisk
// Enumeration to inform Asterisk connection source
define('ASTCONN_CRED_DESCONOCIDO', 0);  // No se ha seteado todavía
                                         // Not set yet
define('ASTCONN_CRED_CONF', 1);         // Credenciales provienen de manager.conf
                                         // Credentials come from manager.conf
define('ASTCONN_CRED_DB', 2);           // Credenciales provienen de DB
                                         // Credentials come from DB

/**
 * La clase ConfigDB maneja las claves de configuración obtenidas desde la base
 * de datos, y provee métodos para reconocer si la configuración ha cambiado
 * en tiempo de ejecución. Cada uno de las configuraciones es accesible como
 * una propiedad del objeto.
 *
 * The ConfigDB class manages configuration keys obtained from the database,
 * and provides methods to recognize if the configuration has changed at runtime.
 * Each configuration is accessible as a property of the object.
 */
class ConfigDB
{
    private $_dbConn;
    private $_log;
    
	/* Variables conocidas que serán leídas de la base de datos. Para cada
	 * variable de configuración, se reconoce la siguiente información:
	 * descripcion:		Propósito de la variable en el programa
	 * regex:			Si no es NULL, la variable debe cumplir este regex
	 * valor_omision:	Valor a usar si no se ha asignado otro en la base
	 * valor_viejo:		El valor que tenía la variable antes de la verificación
	 * valor_actual:	El valor que tiene la variable ahora en la base de datos
	 * mostrar_valor:	Si FALSO, el valor se reemplaza en el log por asteriscos
	 * cast:			Tipo de dato PHP a usar para la conversión desde string.
	 *
	 * Known variables that will be read from the database. For each configuration
	 * variable, the following information is recognized:
	 * descripcion:		Purpose of the variable in the program
	 * regex:			If not NULL, the variable must match this regex
	 * valor_omision:	Value to use if no other has been assigned in the database
	 * valor_viejo:		The value that the variable had before verification
	 * valor_actual:	The value that the variable has now in the database
	 * mostrar_valor:	If FALSE, the value is replaced in the log by asterisks
	 * cast:			PHP data type to use for conversion from string.
	 */
	private $_infoConfig = array(
		// Variables concernientes a la conexión a Asterisk
		// Variables related to Asterisk connection
		'asterisk'	=>	array(
			'asthost'	=>	array(
				'descripcion'	=>	'host de Asterisk Manager',
				                // Asterisk Manager host
				'regex'			=>	NULL,
				'valor_omision'	=>	'127.0.0.1',
				'valor_viejo'	=>	NULL,
				'valor_actual'	=>	NULL,
				'mostrar_valor'	=>	TRUE,
				'cast'			=>	'string',
			),
			'astuser'	=>	array(
				'descripcion'	=>	'usuario de Asterisk Manager',
				                // Asterisk Manager user
				'regex'			=>	NULL,
				'valor_omision'	=>	'',
				'valor_viejo'	=>	NULL,
				'valor_actual'	=>	NULL,
				'mostrar_valor'	=>	FALSE,
				'cast'			=>	'string',
			),
			'astpass'	=>	array(
				'descripcion'	=>	'contraseña de Asterisk Manager',
				                // Asterisk Manager password
				'regex'			=>	NULL,
				'valor_omision'	=>	'',
				'valor_viejo'	=>	NULL,
				'valor_actual'	=>	NULL,
				'mostrar_valor'	=>	FALSE,
				'cast'			=>	'string',
			),
			'duracion_sesion' => array(
				'descripcion'	=>	'duración de sesión Asterisk',
				                // Asterisk session duration
				'regex'			=>	'^\d+$',
				'valor_omision'	=>	0,
				'valor_viejo'	=>	NULL,
				'valor_actual'	=>	NULL,
				'mostrar_valor'	=>	TRUE,
				'cast'			=>	'int',
			),
		),
		// Valores que afectan al comportamiento del marcador
		// Values that affect dialer behavior
		'dialer'	=>	array(
			'llamada_corta'	=>	array(
				'descripcion'	=>	'umbral de llamada corta',
				                // short call threshold
				'regex'			=>	'^\d+$',
				'valor_omision'	=>	10,
				'valor_viejo'	=>	NULL,
				'valor_actual'	=>	NULL,
				'mostrar_valor'	=>	TRUE,
				'cast'			=>	'int',
			),
			'tiempo_contestar' => array(
				'descripcion'	=>	'tiempo de contestado (inicial)',
				                // answer time (initial)
				'regex'			=>	'^\d+$',
				'valor_omision'	=>	8,
				'valor_viejo'	=>	NULL,
				'valor_actual'	=>	NULL,
				'mostrar_valor'	=>	TRUE,
				'cast'			=>	'int',
			),
			'debug'			=>	array(
				'descripcion'	=>	'mensajes de depuración',
				                // debug messages
				'regex'			=>	'^(0|1)$',
				'valor_omision'	=>	0,
				'valor_viejo'	=>	NULL,
				'valor_actual'	=>	NULL,
				'mostrar_valor'	=>	TRUE,
				'cast'			=>	'bool',
			),
			'allevents'		=>	array(
				'descripcion'	=>	'depuración de todos los eventos AMI',
				                // debugging of all AMI events
				'regex'			=>	'^(0|1)$',
				'valor_omision'	=>	0,
				'valor_viejo'	=>	NULL,
				'valor_actual'	=>	NULL,
				'mostrar_valor'	=>	TRUE,
				'cast'			=>	'bool',
			),
            'overcommit'    =>  array(
				'descripcion'	=>	'sobre-colocación de llamadas',
				                // call overcommitment
				'regex'			=>	'^(0|1)$',
				'valor_omision'	=>	0,
				'valor_viejo'	=>	NULL,
				'valor_actual'	=>	NULL,
				'mostrar_valor'	=>	TRUE,
				'cast'			=>	'bool',
			),
            'qos'           =>  array(
				'descripcion'	=>	'porcentaje de atención',
				                // service level percentage
				'regex'			=>	'^0\.\d+$',
				'valor_omision'	=>	0.97,
				'valor_viejo'	=>	NULL,
				'valor_actual'	=>	NULL,
				'mostrar_valor'	=>	TRUE,
				'cast'			=>	'float',
			),
            'predictivo'    =>  array(
                'descripcion'   =>  'predicción de llamadas por terminar',
                                // prediction of calls about to end
                'regex'         =>  '^(0|1)$',
                'valor_omision' =>  1,
                'valor_viejo'   =>  NULL,
                'valor_actual'  =>  NULL,
                'mostrar_valor' =>  TRUE,
                'cast'          =>  'bool',
            ),
            'timeout_originate' => array(
                'descripcion'   =>  'tiempo de espera de marcado de llamadas',
                                // call origination timeout
                'regex'         =>  '^\d+$',
                'valor_omision' =>  0,
                'valor_viejo'   =>  NULL,
                'valor_actual'  =>  NULL,
                'mostrar_valor' =>  TRUE,
                'cast'          =>  'int',
            ),
		'forzar_sobrecolocar' => array(
                'descripcion'   =>  'Forzar Más llamadas por agente',
                                // Force More calls per agent
                'regex'         =>  '^\d+$',
                'valor_omision' =>  0,
                'valor_viejo'   =>  NULL,
                'valor_actual'  =>  NULL,
                'mostrar_valor' =>  TRUE,
                'cast'          =>  'int',
            ),

		),
	);

    private $_fuenteCredAst = ASTCONN_CRED_DESCONOCIDO;

	// Constructor del objeto de configuración
	// Constructor of the configuration object
    function __construct(&$dbConn, &$log)
    {
        $this->_dbConn = $dbConn;
        $this->_log = $log;
		$this->leerConfiguracionDesdeDB();
		$this->limpiarCambios();
    }

    function setDBConn($dbConn) { $this->_dbConn = $dbConn; }

	// Leer todas las variables desde la base de datos
	// Read all variables from the database
	public function leerConfiguracionDesdeDB()
	{
		$log = $this->_log;

		// Inicializar con los valores por omisión
		// Initialize with default values
		foreach ($this->_infoConfig as $seccion => $infoSeccion) {
			foreach ($infoSeccion as $clave => $infoValor) {
				$this->_infoConfig[$seccion][$clave]['valor_actual'] =
					$this->_infoConfig[$seccion][$clave]['valor_omision'];
			}
		}

    	// Leer valores de configuración desde la base de datos
    	// Read configuration values from the database
        $listaConfig = array();
        foreach ($this->_dbConn->query('SELECT config_key, config_value FROM valor_config') as $tupla) {
        	$listaConfig[$tupla['config_key']] = $tupla['config_value'];
        }
		foreach ($this->_infoConfig as $seccion => $infoSeccion) {
			foreach ($infoSeccion as $clave => $infoValor) {
				$sClaveDB = "$seccion.$clave";
				if (isset($listaConfig[$sClaveDB])) {
					$this->_infoConfig[$seccion][$clave]['valor_actual'] = $listaConfig[$sClaveDB]; 
				}
			}
		}

    	// Caso especial: obtener valores de usuario/clave AMI
    	// Special case: obtain AMI user/password values
        if ((	$this->_infoConfig['asterisk']['asthost']['valor_actual'] == '127.0.0.1' || 
        		$this->_infoConfig['asterisk']['asthost']['valor_actual'] == 'localhost') &&
            $this->_infoConfig['asterisk']['astuser']['valor_actual'] == '' && 
            $this->_infoConfig['asterisk']['astpass']['valor_actual'] == '') {

            // Base de datos no tiene usuario explícito, se lee de manager.conf
            // Database has no explicit user, read from manager.conf
            if ($this->_fuenteCredAst != ASTCONN_CRED_CONF)
                $log->output("INFO: AMI login no se ha configurado, se busca en configuración de Asterisk... | EN: AMI login not configured, searching in Asterisk configuration...");
            $amiConfig = $this->_leerConfigManager();
            if (is_array($amiConfig)) {
                if ($this->_fuenteCredAst != ASTCONN_CRED_CONF)
                    $log->output("INFO: usando configuración de Asterisk para AMI login. | EN: using Asterisk configuration for AMI login.");
                $this->_infoConfig['asterisk']['astuser']['valor_actual'] = $amiConfig[0];
                $this->_infoConfig['asterisk']['astpass']['valor_actual'] = $amiConfig[1];
                $this->_fuenteCredAst = ASTCONN_CRED_CONF;
            }
        } else {
            if ($this->_fuenteCredAst == ASTCONN_CRED_DESCONOCIDO)
                $log->output("INFO: AMI login configurado en DB... | EN: AMI login configured in DB...");
            $this->_fuenteCredAst = ASTCONN_CRED_DB;
        }
        
        // Validar los valores de la base de datos
        // Validate the database values
		foreach ($this->_infoConfig as $seccion => $infoSeccion) {
			foreach ($infoSeccion as $clave => $infoValor) {
				if (!is_null($this->_infoConfig[$seccion][$clave]['valor_actual'])) {
					// Se ha leído algún valor desde la base de datos
					// Some value has been read from the database
					if (is_null($infoValor['regex']) ||
						preg_match('/'.$infoValor['regex'].'/', $infoValor['valor_actual'])) {
						if (is_null($infoValor['valor_viejo'])) {
							$log->output('INFO: usando '.$infoValor['descripcion'].': '.
								($infoValor['mostrar_valor'] ? $infoValor['valor_actual'] : '*****').
								' | EN: using '.$infoValor['descripcion'].': '.
								($infoValor['mostrar_valor'] ? $infoValor['valor_actual'] : '*****'));
						}
					} else {
						// El valor no pasa el regex
						// The value does not pass the regex
						if (is_null($infoValor['valor_viejo']))
							$log->output('ERR: valor para '.$infoValor['descripcion'].' inválido: '.$infoValor['valor_actual'].
								' | EN: invalid value for '.$infoValor['descripcion'].': '.$infoValor['valor_actual']);
						$this->_infoConfig[$seccion][$clave]['valor_actual'] =
							$this->_infoConfig[$seccion][$clave]['valor_omision'];
						if (is_null($infoValor['valor_viejo']))
							$log->output('INFO: usando '.$infoValor['descripcion'].' (por omisión): '.
							($infoValor['mostrar_valor'] ? $this->_infoConfig[$seccion][$clave]['valor_actual'] : '*****').
							' | EN: using '.$infoValor['descripcion'].' (default): '.
							($infoValor['mostrar_valor'] ? $this->_infoConfig[$seccion][$clave]['valor_actual'] : '*****'));
					}
				} else {
					// Asignación inicial de las variables
					// Initial assignment of variables
					$this->_infoConfig[$seccion][$clave]['valor_actual'] =
						$this->_infoConfig[$seccion][$clave]['valor_omision'];
					$log->output('INFO: usando '.$infoValor['descripcion'].' (por omisión): '.
						($infoValor['mostrar_valor'] ? $this->_infoConfig[$seccion][$clave]['valor_actual'] : '*****').
						' | EN: using '.$infoValor['descripcion'].' (default): '.
						($infoValor['mostrar_valor'] ? $this->_infoConfig[$seccion][$clave]['valor_actual'] : '*****'));
				}
			}
		}
	}

    /* Leer el estado de /etc/asterisk/manager.conf y obtener el primer usuario
     * que puede usar el dialer. Devuelve NULL en caso de error, o tupla
     * user,password para conexión en localhost.
     *
     * Read the status of /etc/asterisk/manager.conf and obtain the first user
     * that can use the dialer. Returns NULL on error, or tuple
     * user,password for localhost connection. */
    private function _leerConfigManager()
    {
		$log = $this->_log;

    	$sNombreArchivo = '/etc/asterisk/manager.conf';
        if (!file_exists($sNombreArchivo)) {
        	$log->output("WARN: $sNombreArchivo no se encuentra. | EN: $sNombreArchivo not found.");
            return NULL;
        }
        if (!is_readable($sNombreArchivo)) {
            $log->output("WARN: $sNombreArchivo no puede leerse por usuario de marcador. | EN: $sNombreArchivo cannot be read by dialer user.");
            return NULL;        	
        }
        //$infoConfig = parse_ini_file($sNombreArchivo, TRUE);
        $infoConfig = $this->parse_ini_file_literal($sNombreArchivo);
        if (is_array($infoConfig)) {
            foreach ($infoConfig as $login => $infoLogin) {
            	if ($login != 'general') {
            		if (isset($infoLogin['secret']) && 
            			isset($infoLogin['read']) && 
            			isset($infoLogin['write'])) {
            			return array($login, $infoLogin['secret']);
            		}
            	}
            }
        } else {
            $log->output("ERR: $sNombreArchivo no puede parsearse correctamente. | EN: $sNombreArchivo cannot be parsed correctly.");
        }
        return NULL;
    }

    private function parse_ini_file_literal($sNombreArchivo)
    {
    	$h = fopen($sNombreArchivo, 'r');
        if (!$h) return FALSE;
        $r = array();
        $seccion = NULL;
        while (!feof($h)) {
        	$s = fgets($h);
            $s = rtrim($s, " \r\n");
            $regs = NULL;
            if (preg_match('/^\s*\[(\w+)\]/', $s, $regs)) {
            	$seccion = $regs[1];
            } elseif (preg_match('/^(\w+)\s*=\s*(.*)/', $s, $regs)) {
            	if (is_null($seccion))
                    $r[$regs[1]] = $regs[2];
                else
                    $r[$seccion][$regs[1]] = $regs[2];
            }
        }
        fclose($h);
        return $r;
    }

	// Reporte de la lista de variables cambiadas
	// Report the list of changed variables
	public function listaVarCambiadas()
	{
		$l = array();
		foreach ($this->_infoConfig as $seccion => $infoSeccion) {
			foreach ($infoSeccion as $clave => $infoValor) {
				$sClave = "{$seccion}_{$clave}";
				if ($infoValor['valor_viejo'] != $infoValor['valor_actual']) {
					$l[] = $sClave;
				}
			}
		}
		return $l;
	}

	// Olvidar los valores viejos de las variables luego de cargar valores nuevos
	// Forget old variable values after loading new values
	public function limpiarCambios()
	{
		foreach ($this->_infoConfig as $seccion => $infoSeccion) {
			foreach ($infoSeccion as $clave => $infoValor) {
				$sClave = "{$seccion}_{$clave}";
				$this->_infoConfig[$seccion][$clave]['valor_viejo'] = $infoValor['valor_actual'];
			}
		}
	}

	// Obtener el valor de la variable
	// Get the value of the variable
	public function __get($s)
	{
		foreach ($this->_infoConfig as $seccion => $infoSeccion) {
			foreach ($infoSeccion as $clave => $infoValor) {
				$sClave = "{$seccion}_{$clave}";
				if ($s == $sClave) switch ($infoValor['cast']) {
				case 'string':	return $infoValor['valor_actual'];
				case 'int':		return (int)$infoValor['valor_actual'];
				case 'float':	return (float)$infoValor['valor_actual'];
				case 'bool':	return (bool)$infoValor['valor_actual'];
				}
			}
		}
		$log = $this->_log;
		$log->output("ERR: referencia inválida a propiedad: ConfigDB::$s | EN: invalid property reference: ConfigDB::$s");			
		foreach (debug_backtrace() as $traceElement) {
			$sNombreFunc = $traceElement['function'];
			if (isset($traceElement['type'])) {
				$sNombreFunc = $traceElement['class'].'::'.$sNombreFunc;
				if ($traceElement['type'] == '::')
					$sNombreFunc = '(static) '.$sNombreFunc;
			}
			$log->output("\ten {$traceElement['file']}:{$traceElement['line']} en función {$sNombreFunc}() | EN: at {$traceElement['file']}:{$traceElement['line']} in function {$sNombreFunc}()");
		}			
		return NULL;
	}
}
?>
