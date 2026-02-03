<?php
/* vim: set expandtab tabstop=4 softtabstop=4 shiftwidth=4:
  Codificación: UTF-8
  +----------------------------------------------------------------------+
  | Issabel version 0.5                                                  |f
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

/**
 * Esta biblioteca contiene funciones que existen en Issabel 2 pero no en 
 * Elastix 1.6. De esta manera se puede programar asumiendo un entorno 
 * equivalente a Elastix 2. Por medio de las verificaciones de function_exists()
 * se evita declarar la función cuando se ejecuta realmente en Issabel 2.
 */

// Función de conveniencia para pedir traducción de texto, si existe
if (!function_exists('_tr')) {
function _tr($s)
{
    global $arrLang;
    return isset($arrLang[$s]) ? $arrLang[$s] : $s;
}
}

/**
* Funcion que sirve para obtener los valores de los parametros de los campos en los
* formularios, Esta funcion verifiva si el parametro viene por POST y si no lo encuentra
* trata de buscar por GET para poder retornar algun valor, si el parametro ha consultar no
* no esta en request retorna null.
*
* Ejemplo: $nombre = getParameter('nombre');
*/
if (!function_exists('getParameter')) {
    function getParameter($parameter)
    {
        if(isset($_POST[$parameter]))
            return $_POST[$parameter];
        else if(isset($_GET[$parameter]))
            return $_GET[$parameter];
        else
            return null;
    }
}

/**
 * Función para obtener la clave MySQL de usuarios bien conocidos de Elastix.
 * Los usuarios conocidos hasta ahora son 'root' (sacada de /etc/issabel.conf)
 * y 'asteriskuser' (sacada de /etc/amportal.conf)
 *
 * @param   string  $sNombreUsuario     Nombre de usuario para interrogar
 * @param   string  $ruta_base          Ruta base para inclusión de librerías
 *
 * @return  mixed   NULL si no se reconoce usuario, o la clave en plaintext
 */
if (!function_exists('obtenerClaveConocidaMySQL')) {
function obtenerClaveConocidaMySQL($sNombreUsuario, $ruta_base='')
{
    require_once $ruta_base.'libs/paloSantoConfig.class.php';
    switch ($sNombreUsuario) {
    case 'root':
        $pConfig = new paloConfig("/etc", "issabel.conf", "=", "[[:space:]]*=[[:space:]]*");
        $listaParam = $pConfig->leer_configuracion(FALSE);
        if (isset($listaParam['mysqlrootpwd'])) 
            return $listaParam['mysqlrootpwd']['valor'];
        else return 'iSsAbEl.2o17'; // Compatibility for updates where /etc/issabel.conf is not available
        break;
    case 'asteriskuser':
        $pConfig = new paloConfig("/etc", "amportal.conf", "=", "[[:space:]]*=[[:space:]]*");
        $listaParam = $pConfig->leer_configuracion(FALSE);
        if (isset($listaParam['AMPDBPASS']))
            return $listaParam['AMPDBPASS']['valor'];
        break;
    }
    return NULL;
}
}

/**
 * Función para construir un DSN para conectarse a varias bases de datos 
 * frecuentemente utilizadas en Issabel. Para cada base de datos reconocida, se
 * busca la clave en /etc/issabel.conf o en /etc/amportal.conf según corresponda.
 *
 * @param   string  $sNombreUsuario     Nombre de usuario para interrogar
 * @param   string  $sNombreDB          Nombre de base de datos para DNS
 * @param   string  $ruta_base          Ruta base para inclusión de librerías
 *
 * @return  mixed   NULL si no se reconoce usuario, o el DNS con clave resuelta
 */
if (!function_exists('generarDSNSistema')) {
function generarDSNSistema($sNombreUsuario, $sNombreDB, $ruta_base='')
{
    require_once $ruta_base.'libs/paloSantoConfig.class.php';
    switch ($sNombreUsuario) {
    case 'root':
        $sClave = obtenerClaveConocidaMySQL($sNombreUsuario, $ruta_base);
        if (is_null($sClave)) return NULL;
        return 'mysql://root:'.$sClave.'@localhost/'.$sNombreDB;
    case 'asteriskuser':
        $pConfig = new paloConfig("/etc", "amportal.conf", "=", "[[:space:]]*=[[:space:]]*");
        $listaParam = $pConfig->leer_configuracion(FALSE);
        return $listaParam['AMPDBENGINE']['valor']."://".
               $listaParam['AMPDBUSER']['valor']. ":".
               $listaParam['AMPDBPASS']['valor']. "@".
               $listaParam['AMPDBHOST']['valor']. "/".$sNombreDB;
    }
    return NULL;
}
}

if (!function_exists('load_language_module')) {
function load_language_module($module_id, $ruta_base='')
{
    $lang = get_language($ruta_base);
    include_once $ruta_base."modules/$module_id/lang/en.lang";
    $lang_file_module = $ruta_base."modules/$module_id/lang/$lang.lang";
    if ($lang != 'en' && file_exists("$lang_file_module")) {
        $arrLangEN = $arrLangModule;
        include_once "$lang_file_module";
        $arrLangModule = array_merge($arrLangEN, $arrLangModule);
    }

    global $arrLang;
    global $arrLangModule;
    $arrLang = array_merge($arrLang,$arrLangModule);
}
}

/**
 * Las siguientes son funciones de compatibilidad para que el módulo haga uso de
 * funcionalidad de Elastix 2, mientras que siga funcionando con Elastix 1.6.
 */

/**
 * Procedimiento para interrogar si el framework contiene soporte de mostrar el
 * título del formulario como parte de la plantilla del framework. Esta 
 * verificación es necesaria para evitar mostrar títulos duplicados en los 
 * formularios
 * 
 * @return bool VERDADERO si el soporte existe, FALSO si no.
 */
function existeSoporteTituloFramework()
{
	global $arrConf;
    
    if (!isset($arrConf['mainTheme'])) return FALSE;
    $bExisteSoporteTitulo = FALSE;
    foreach (array(
        "themes/{$arrConf['mainTheme']}/_common/index.tpl",
        "themes/{$arrConf['mainTheme']}/_common/_menu.tpl",
    ) as $sArchivo) {
    	$h = fopen($sArchivo, 'r');
        if ($h) {
            while (!feof($h)) {
            	if (strpos(fgets($h), '$title') !== FALSE) {
            		$bExisteSoporteTitulo = TRUE;
                    break;
            	}
            }
        	fclose($h);
        }
        if ($bExisteSoporteTitulo) return $bExisteSoporteTitulo;
    }
    return $bExisteSoporteTitulo;
}

/**
 * SSE (Server-Sent Events) Helper Functions for PHP-FPM compatibility
 * These functions handle output buffering and flushing required for SSE
 * to work correctly with PHP-FPM's FastCGI protocol.
 */

/**
 * Detect SSE mode from request parameter
 * @return bool True if SSE mode requested
 */
if (!function_exists('detectSSEMode')) {
function detectSSEMode() {
    $sModoEventos = getParameter('serverevents');
    return (!is_null($sModoEventos) && $sModoEventos);
}
}

/**
 * Setup long-polling/SSE session parameters
 */
if (!function_exists('setupSSESession')) {
function setupSSESession() {
    ignore_user_abort(true);
    set_time_limit(0);
}
}

/**
 * Initialize SSE connection with PHP-FPM compatible settings
 * @param bool $bSSE True for SSE mode, false for JSON polling
 */
if (!function_exists('initSSE')) {
function initSSE($bSSE) {
    if ($bSSE) {
        // Clear all output buffers
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');

        @ini_set('output_buffering', 'off');
        @ini_set('zlib.output_compression', 0);
        @ini_set('implicit_flush', 1);
        ob_implicit_flush(1);

        // 4KB padding to prime browser buffer
        printflush(":" . str_repeat(' ', 4096) . "\n");
        printflush("retry: 5000\n");
    } else {
        header('Content-Type: application/json');
    }
}
}

/**
 * Print and flush output (PHP-FPM compatible)
 * @param string $s Output string
 */
if (!function_exists('printflush')) {
function printflush($s) {
    print $s;
    while (ob_get_level() > 0) {
        ob_end_flush();
    }
    flush();
}
}

/**
 * JSON encode and flush with optional SSE format
 * @param bool $bSSE True for SSE format, false for raw JSON
 * @param mixed $data Data to encode
 */
if (!function_exists('jsonflush')) {
function jsonflush($bSSE, $respuesta){
    $json = new Services_JSON();
    $r = $json->encode($respuesta);
    if ($bSSE)
        printflush("data: $r\n\n");
    else printflush($r);
}
}

/**
 * Generate and store state hash for incremental updates
 * @param string $module_name Module identifier
 * @param array $estadoCliente Client state array
 * @return string MD5 hash of state
 */
if (!function_exists('generarEstadoHash')) {
function generarEstadoHash($module_name, $estadoCliente) {
    $estadoHash = md5(serialize($estadoCliente));
    $_SESSION[$module_name]['estadoCliente'] = $estadoCliente;
    $_SESSION[$module_name]['estadoClienteHash'] = $estadoHash;
    return $estadoHash;
}
}
?>
