<?php
/* vim: set expandtab tabstop=4 softtabstop=4 shiftwidth=4:
  Codificación: UTF-8
  +----------------------------------------------------------------------+
  | Issabel version 1.1                                                  |
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
  $Id: paloSantoDB.class.php, Thu 20 May 2021 07:59:25 AM EDT, nicolas@issabel.com
*/
// La siguiente clase es una clase prototipo... Usela bajo su propio riesgo
// EN: The following class is a prototype class... Use it at your own risk
class paloDB {

    var $conn;          // Referencia a la conexion activa a la DB // EN: Reference to active DB connection
    var $connStatus;    // Se asigna a VERDADERO si ocurrió error de DB // EN: Assigned to TRUE if DB error occurred
    var $errMsg;        // Texto del mensaje de error // EN: Error message text
    var $engine;        // Base de datos // EN: Database
    private $_forceintcast = FALSE;

    /**
     * Constructor de la clase, recibe como parámetro el DSN de PEAR a usar
     * para la conexión a la base de datos. El DSN debe de indicar como base
     * por omisión la base donde se encuentran los ACLs.
     * En caso de éxito, se asigna FALSE a $this->connStatus.
     * En caso de error, se asigna VERDADERO a $this->connStatus y se asigna
     * en $this->errMsg la cadena de error de conexión.
     *
     * EN: Class constructor, receives as parameter the PEAR DSN to use for
     * database connection. The DSN must indicate as default base the base
     * where ACLs are located. On success, FALSE is assigned to
     * $this->connStatus. On error, TRUE is assigned to $this->connStatus
     * and the connection error string is assigned to $this->errMsg.
     *
     * @param string    $dsn    cadena de conexión, de la forma "mysql://user:password@dbhost/baseomision"
     *                      EN: connection string, in the form "mysql://user:password@dbhost/database"
     */
    function __construct($dsn) // Creo que aqui debo pasar el dsn // EN: I think I must pass the dsn here
    {
        $this->_forceintcast = (explode(".", phpversion()) >= array(5, 3, 0));

        // Creo una conexion y hago login en la base de datos
        // EN: Create a connection and login to the database
        $this->conn = NULL;
        $this->errMsg = "";
        if (is_object($dsn)) {
            $this->conn = $dsn;
            $this->connStatus = FALSE;
        } else {
            $dsninfo = $this->parseDSN($dsn);
            $engine  = $dsninfo['dbsyntax'];

            if($engine=='sqlite3'){
                $dsn = "sqlite:".$dsninfo['database'];
                $this->engine = $engine;
            }
            else if($engine=='mysql' || $engine=='pgsql'){
                $dsn = "$engine:dbname=".$dsninfo['database'].";host=".$dsninfo['hostspec'];
                $this->engine = $engine;
            }
            if($engine=='mysql') { $dsn.=";charset=utf8mb4"; }

            $user       = $dsninfo['username'];
            $password   = $dsninfo['password'];

            try {
                $this->connStatus = false;  //logica negativa
                $this->conn = new PDO($dsn, $user, $password);
            } catch (PDOException $e) {
                $this->errMsg = "Error de conexion a la base de datos - " . $e->getMessage(); // EN: Database connection error
                $this->connStatus = true;
                $this->conn = NULL;
            }
        }
    }

    /**
     * Procedimiento para indicar la desconexión de la base de datos a PEAR
     *
     * EN: Procedure to indicate database disconnection to PEAR
     */
    function disconnect()
    {
        //Esta funcion deberia ser borrada pues se cambio de PEAR A PDO
        // EN: This function should be deleted since we changed from PEAR to PDO
        //if (!is_null($this->conn)) $this->conn->disconnect();
    }

    private function _bindParameters(&$sth, &$param)
    {
        // Use native datatype to figure PDO::PARAM_* . Workaround for PHP bug #44639
        for ($i = 0; $i < count($param); $i++) {
            $data_type = PDO::PARAM_STR;
            switch (gettype($param[$i])) {
            case 'NULL':
                $data_type = PDO::PARAM_NULL;
                break;
            case 'integer':
                $data_type = PDO::PARAM_INT;
                break;
            case 'boolean':
                $data_type = PDO::PARAM_BOOL;
                break;
            case 'string':
                /* La sentencia LIMIT '1' es ilegal en MySQL debido a las
                 * comillas. Por lo tanto, las cadenas numéricas deben insertarse
                 * como enteros. Sin embargo se debe evitar la conversión si la
                 * cadena numérica tiene un cero por delante, para evitar el
                 * truncamiento de dicho cero. Véase bug Elastix #1694. Además
                 * debe evitarse la conversión a entero si la máquina no puede
                 * representar el número indicado como int debido a que excede
                 * INT_MAX. Véase bug Elastix #2477.
                 * EN: The statement LIMIT '1' is illegal in MySQL due to quotes.
                 * Therefore, numeric strings must be inserted as integers. However,
                 * conversion should be avoided if the numeric string has a leading
                 * zero, to avoid truncation of said zero. See Elastix bug #1694.
                 * Also, conversion to integer should be avoided if the machine
                 * cannot represent the indicated number as int because it exceeds
                 * INT_MAX. See Elastix bug #2477.*/
                $data_type =
                    (ctype_digit("{$param[$i]}") &&
                        ($param[$i][0] != '0' || strlen($param[$i]) == 1) &&
                        ((string)((int)$param[$i]) === $param[$i]))
                    ? PDO::PARAM_INT : PDO::PARAM_STR;

                /* Some versions of PHP try to bind numeric string as PARAM_STR
                 * even if PARAM_INT specified. Affects PHP 5.4.x in CentOS 7.
                 */
                if ($this->_forceintcast && $data_type == PDO::PARAM_INT)
                    $param[$i] = (int)$param[$i];
                break;
            }
            $sth->bindValue($i + 1, $param[$i], $data_type);
        }
    }

    /**
     * Procedimiento para ejecutar una sentencia SQL(DML Data Manipulation Language) que no devuelve filas de resultados.
     * En caso de error, se asigna mensaje a $this->errMsg
     * Nota: Solo usado para hacer manipulacion de los datos de la base.
     *
     * EN: Procedure to execute an SQL statement (DML Data Manipulation Language)
     * that does not return result rows. On error, message is assigned to
     * $this->errMsg. Note: Only used to manipulate database data.
     *
     * @param string $query Sentencia SQL a ejecutar // EN: SQL statement to execute
     * @param mixed  $param NULL, o parámetros a pasar a query parametrizado
     *                      EN: NULL, or parameters to pass to parameterized query
     *
     * @return bool VERDADERO en caso de éxito, FALSO en caso de error
     *              EN: TRUE on success, FALSE on error
     */
    function genQuery($query, $param = NULL)
    {
        // Revisar existencia de conexión activa
        // EN: Check for active connection existence
        if ($this->connStatus) {
            return FALSE;
        } else {
            $this->errMsg = "";
            if (is_array($param)) {
                $sth = $this->conn->prepare($query);
                if (!is_object($sth)) {
                    $this->errMsg = "Error de conexion al preparar peticion - ".print_r($this->conn->errorInfo(), 1);
                    return FALSE;
                }
                try {
                    $this->_bindParameters($sth, $param);
                    $result = $sth->execute();
                    if (!$result) $this->errMsg = "Error de conexion a la base de datos - " . print_r($sth->errorInfo(), 1);
                    return $result;
                } catch(PDOException $e){
                    $this->errMsg = "Error de conexion a la base de datos - " . $e->getMessage();
                    return FALSE;
                }
            } else {
                try{
                    if($this->conn->query($query))
                        return TRUE;
                    else{
                        $this->errMsg = "Query Error: $query - ".print_r($this->conn->errorInfo(), 1);
                        return FALSE;
                    }
                }catch(PDOException $e){
                    $this->errMsg = "Error de conexion a la base de datos - " . $e->getMessage();
                    return FALSE;
                }
            }
        }
    }

    /**
     * Procedimiento que recupera todas las filas resultado de una
     * petición SQL que devuelve una o más filas.
     *
     * EN: Procedure that retrieves all result rows from an SQL request
     * that returns one or more rows.
     *
     * @param   string  $query          Cadena de la petición SQL
     *                                  EN: SQL request string
     * @param   bool    $arr_colnames   VERDADERO si se desea que cada tupla tenga por
     *                                  índice el nombre de columna
     *                                  EN: TRUE if you want each tuple to have column
     *                                  name as index
     * @param mixed  $param NULL, o parámetros a pasar a query parametrizado
     *                  EN: NULL, or parameters to pass to parameterized query
     *
     * @return  mixed   Matriz de las filas de recordset en éxito, o FALSE en error
     *                  EN: Matrix of recordset rows on success, or FALSE on error
     */
    function fetchTable($query, $arr_colnames = FALSE, $param = NULL)
    {
        if ($this->connStatus) {
            return FALSE;
        } else {
            $this->errMsg = "";
            try{
                if (is_array($param)) {
                    $result = $this->conn->prepare($query);
                    if (!is_object($result)) {
                        $this->errMsg = "Error de conexion al preparar peticion - ".print_r($this->conn->errorInfo(), 1);
                        return FALSE;
                    }
                    $this->_bindParameters($result, $param);
                    $r = $result->execute();
                    if (!$r) {
                        $this->errMsg = "Error de conexion a la base de datos - " . print_r($result->errorInfo(), 1);
                        return FALSE;
                    }
                } else {
                    $result = $this->conn->query($query);
                }
                $arrResult = array();
                //while($row = $result->fetchRow($arr_colnames ? DB_FETCHMODE_OBJECT : DB_FETCHMODE_DEFAULT)) {
                if($result!=null){
                    while($row = $result->fetch($arr_colnames ? PDO::FETCH_OBJ : PDO::FETCH_NUM)) {
                        $arrResult[] = (array)$row;
                    }
                }else{
                    $this->errMsg = "Query Error $query";
                    //echo "Query Error $query";
                    return FALSE;
                }
                return $arrResult;
            }catch(PDOException $e){
                $this->errMsg = "Query Error: " . $e->getMessage();
                return FALSE;
            }
        }
    }

    /**
     * Procedimiento para recuperar una sola fila del query que devuelve una o
     * más filas. Devuelve una fila con campos si el query devuelve al menos
     * una fila, un arreglo vacía si el query no devuelve ninguna fila, o FALSE
     * en caso de error.
     *
     * EN: Procedure to retrieve a single row from the query that returns one
     * or more rows. Returns a row with fields if the query returns at least
     * one row, an empty array if the query returns no rows, or FALSE on error.
     *
     * @param   string  $query          Cadena de la petición SQL // EN: SQL request string
     * @param   bool    $arr_colnames   VERDADERO si se desea que la tupla tenga por
     *                                  índice el nombre de columna
     *                                  EN: TRUE if you want the tuple to have column
     *                                  name as index
     * @param mixed  $param NULL, o parámetros a pasar a query parametrizado
     *                  EN: NULL, or parameters to pass to parameterized query
     *
     * @return  mixed   tupla del recordset en éxito, o FALSE en error
     *                  EN: Recordset tuple on success, or FALSE on error
     */
    function getFirstRowQuery($query, $arr_colnames = FALSE, $param = NULL)
    {
        $matriz = $this->fetchTable($query, $arr_colnames, $param);
        if (is_array($matriz)) {
            if (count($matriz) > 0) {
                return $matriz[0];
            } else {
                return array();
            }
        } else {
            return FALSE;
        }
    }

     /**
     * Procedimiento para ejecutar una sentencia SQL(DDL Data Definition Language, DCL Data Control Language) que no devuelve filas de resultados.
     * En caso de error, se asigna mensaje a $this->errMsg
     * Nota: Solo usado para crear definiones de la metadata y permisos.
     *
     * EN: Procedure to execute an SQL statement (DDL Data Definition Language,
     * DCL Data Control Language) that does not return result rows. On error,
     * message is assigned to $this->errMsg. Note: Only used to create metadata
     * definitions and permissions.
     *
     * @param string $query Sentencia SQL a ejecutar // EN: SQL statement to execute
     * @param mixed  $param NULL, o parámetros a pasar a query parametrizado
     *                      EN: NULL, or parameters to pass to parameterized query
     *
     * @return bool VERDADERO en caso de éxito, FALSO en caso de error
     *              EN: TRUE on success, FALSE on error
     */
    function genExec($query, $param = NULL)
    {
        // Revisar existencia de conexión activa
        // EN: Check for active connection existence
        if ($this->connStatus) {
            return false;
        } else {
            $this->errMsg = "";
            if (is_array($param)) {
                $sth = $this->conn->prepare($query);
                if (!is_object($sth)) {
                    $this->errMsg = "Error de conexion al preparar peticion - ".print_r($this->conn->errorInfo(), 1);
                    return FALSE;
                }
                try {
                    $this->_bindParameters($sth, $param);
                    $result = $sth->execute();
                    if (!$result) $this->errMsg = "Error de conexion a la base de datos - " . print_r($sth->errorInfo(), 1);
                    return $result;
                } catch(PDOException $e){
                    $this->errMsg = "Error de conexion a la base de datos - " . $e->getMessage();
                    return FALSE;
                }
            } else {
                try{
                    if($this->conn->exec($query)===FALSE){
			$this->errMsg = "Query Error: $query";
			return FALSE;
		    }
		    else{
			return TRUE;
		    }
                }catch(PDOException $e){
                    $this->errMsg = "Error de conexion a la base de datos - " . $e->getMessage();
                    return FALSE;
                }
            }
        }
    }

    /**
     * Procedimiento para obtener el ultimo id en AUTO_INCREMENT
     * en un insert
     *
     * EN: Procedure to get the last AUTO_INCREMENT id in an insert
     *
     * @param niguno // EN: none
     *
     * @return string Valor del id ultimo generado // EN: Value of the last generated id
     */
    function getLastInsertId($objSequence = NULL)
    {
        if(is_null($objSequence)){
            try{
                $id = $this->conn->lastInsertId();
                return $id;
            }
            catch(PDOException $e){
                $this->errMsg = "Error al obtener el ultimo id insertado - " . $e->getMessage();
                return FALSE;
            }
        }
        else{
            try{
                $id = $this->conn->lastInsertId($objSequence);
                return $id;
            }
            catch(PDOException $e){
                $this->errMsg = "Error al obtener el ultimo id insertado - " . $e->getMessage();
                return FALSE;
            }
        }
    }

    /**
     * Procedimiento para iniciar una Transaccción
     * EN: Procedure to start a Transaction
     * @param niguno // EN: none
     *
     * @return nada // EN: nothing
     */
    function beginTransaction()
    {
        $this->conn->beginTransaction();
    }

     /**
     * Procedimiento de rollBack para Transaccción
     * EN: RollBack procedure for Transaction
     * @param niguno // EN: none
     *
     * @return nada // EN: nothing
     */
    function rollBack()
    {
        $this->conn->rollBack();
    }

    /**
     * Procedimiento de commit para Transaccción
     * EN: Commit procedure for Transaction
     * @param niguno // EN: none
     *
     * @return nada // EN: nothing
     */
    function commit()
    {
        $this->conn->commit();
    }

    /**
     * Procedimiento para escapar comillas y encerrar entre
     * comillas un valor de texto para una sentencia SQL
     *
     * EN: Procedure to escape quotes and enclose in quotes a text value
     * for an SQL statement
     *
     * @param string  $sVal    Cadena de texto a escapar // EN: Text string to escape
     *
     * @return string Valor con las comillas escapadas // EN: Value with escaped quotes
     */
    function DBCAMPO($sVal)
    {
//        if (get_magic_quotes_gpc()) $sVal = stripslashes($sVal);
        $sVal = preg_replace("/\\\\/", "\\\\", "$sVal");
        $sVal = preg_replace("/'/", "\\'", "$sVal");
        return "'$sVal'";
    }

    /**
     * Procedimiento para construir un INSERT para una tabla.
     * Se espera el nombre de la tabla en $sTabla, y un arreglo
     * asociativo en $arrValores, el cual consiste en <clave> => <valor>
     * donde <clave> es la columna a modificar, y <valor> es la
     * expresión a asignar a la columna. No se insertan comillas simples,
     * así que se debe usar DBCAMPO($val) si se requieren comillas
     * simples o escapes de comillas.
     *
     * EN: Procedure to build an INSERT for a table. The table name is
     * expected in $sTabla, and an associative array in $arrValores,
     * which consists of <key> => <value> where <key> is the column to
     * modify, and <value> is the expression to assign to the column.
     * Single quotes are not inserted, so DBCAMPO($val) must be used if
     * single quotes or quote escapes are required.
     *
     * @param string    $sTabla     Nombre de la tabla de la base de datos // EN: Database table name
     * @param array     $arrValores Arreglo asociativo de columna => expresion // EN: Associative array of column => expression
     *
     * @return string   Cadena que representa al INSERT generado // EN: String that represents the generated INSERT
     */
    function construirInsert($sTabla, $arrValores)
    {
        $sCampos = "";
        $sValores = "";
        foreach ($arrValores as $sCol => $sVal) {
            if ($sCampos != "") $sCampos .= ", ";
            if ($sValores != "") $sValores .= ", ";
            $sCampos .= $sCol;
            if (is_null($sVal)) {
                $sValores .= "NULL";
            } else {
                $sValores .= $sVal;
            }
        }
        $insert = "INSERT INTO $sTabla ($sCampos) VALUES ($sValores)";
        return $insert;
    }

    /**
     * Procedimiento para construir un REPLACE para una tabla
     * Se espera el nombre de la tabla en $sTabla, y un arreglo
     * asociativo en $arrValores, el cual consiste en <clave> => <valor>
     * donde <clave> es la columna a modificar, y <valor> es la
     * expresión a asignar a la columna. No se insertan comillas simples,
     * así que se debe usar DBCAMPO($val) si se requieren comillas
     * simples o escapes de comillas.
     *
     * EN: Procedure to build a REPLACE for a table. The table name is
     * expected in $sTabla, and an associative array in $arrValores,
     * which consists of <key> => <value> where <key> is the column to
     * modify, and <value> is the expression to assign to the column.
     * Single quotes are not inserted, so DBCAMPO($val) must be used if
     * single quotes or quote escapes are required.
     *
     * @param string    $sTabla     Nombre de la tabla de la base de datos // EN: Database table name
     * @param array     $arrValores Arreglo asociativo de columna => expresion // EN: Associative array of column => expression
     *
     * @return string   Cadena que representa al REPLACE generado // EN: String that represents the generated REPLACE
     */
    function construirReplace($sTabla, $arrValores)
    {
        $sCampos = "";
        $sValores = "";
        foreach ($arrValores as $sCol => $sVal) {
            if ($sCampos != "") $sCampos .= ", ";
            if ($sValores != "") $sValores .= ", ";
            $sCampos .= $sCol;
            if (is_null($sVal)) {
                $sValores .= "NULL";
            } else {
                $sValores .= $sVal;
            }
        }
        return "REPLACE INTO $sTabla ($sCampos) VALUES ($sValores)";
    }

    /**
     * Procedimiento para construir un UPDATE para una tabla.
     * El parámetro $sTabla contiene la tabla a modificar. El parámetro
     * $arrValores contiene un arreglo asociativo de la forma
     * <nombre_de_columna> => <expresion_valor>. El parámetro $where
     * tiene, si no es NULL, un arreglo asociativo que se interpreta de
     * la siguiente manera: una clave numérica indica que el valor es
     * una expresión compleja construida a la discreción del código que
     * llamó a construirUpdate(). Una clave de texto se asume el nombre
     * de una columna, con una especificación de igualdad a la expresión
     * indicada, si no es NULL, o equivalente a NULL.
     *
     * EN: Procedure to build an UPDATE for a table. The $sTabla parameter
     * contains the table to modify. The $arrValores parameter contains an
     * associative array of the form <column_name> => <value_expression>.
     * The $where parameter has, if not NULL, an associative array that is
     * interpreted as follows: a numeric key indicates that the value is a
     * complex expression built at the discretion of the code that called
     * construirUpdate(). A text key is assumed to be the name of a column,
     * with an equality specification to the indicated expression, if not
     * NULL, or equivalent to NULL.
     *
     * @param string    $sTabla     Nombre de la tabla de la base de datos // EN: Database table name
     * @param array     $arrValores Arreglo asociativo de columna => expresion // EN: Associative array of column => expression
     * @param array     $where      Arreglo que describe las condiciones WHERE // EN: Array that describes WHERE conditions
     *
     * @return string Cadena que representa al UPDATE generado // EN: String that represents the generated UPDATE
     */
    function construirUpdate($sTabla, $arrValores, $where = NULL)
    {
        $sValores = "";
        $sCondicion = "";

        // Si la condicion $where es un arreglo, se construye
        // lista AND con los valores de igualdad. Si no, se
        // asume una condición WHERE directa.
        // EN: If the $where condition is an array, an AND list is built
        // with equality values. Otherwise, a direct WHERE condition is assumed.
        if (!is_null($where)) {
            $sPredicado = "";
            if (is_array($where)) {
                foreach ($where as $sCol => $sVal) {
                    if ($sPredicado != "") $sPredicado .= " AND ";
                    if (is_integer($sCol))
                        $sPredicado .= "$sVal";   // Se asume condición compleja // EN: Complex condition assumed
                    else if (is_null($sVal))
                        $sPredicado .= "$sCol IS NULL";
                    else $sPredicado .= "$sCol = $sVal"; // Se asume igualdad // EN: Equality assumed
                }
            } else {
                $sPredicado = $where;
            }
            $sCondicion = "WHERE $sPredicado";
        }

        // Construir la lista de valores nuevos a modificar
        // EN: Build the list of new values to modify
        foreach ($arrValores as $sCol => $sVal) {
            if ($sValores != "") $sValores .= ", ";
            if (is_null($sVal))
                $sValores .= "$sCol = NULL";
            else $sValores .= "$sCol = $sVal";
        }

        return "UPDATE $sTabla SET $sValores $sCondicion";
    }

    function parseDSN($dsn)
    {
        $parsed = array(
            'phptype'  => false,
            'dbsyntax' => false,
            'username' => false,
            'password' => false,
            'protocol' => false,
            'hostspec' => false,
            'port'     => false,
            'socket'   => false,
            'database' => false,
        );

        if (is_array($dsn)) {
            $dsn = array_merge($parsed, $dsn);
            if (!$dsn['dbsyntax']) {
                $dsn['dbsyntax'] = $dsn['phptype'];
            }
            return $dsn;
        }

        // Find phptype and dbsyntax
        if (($pos = strpos($dsn, '://')) !== false) {
            $str = substr($dsn, 0, $pos);
            $dsn = substr($dsn, $pos + 3);
        } else {
            $str = $dsn;
            $dsn = null;
        }

        // Get phptype and dbsyntax
        // $str => phptype(dbsyntax)
        if (preg_match('|^(.+?)\((.*?)\)$|', $str, $arr)) {
            $parsed['phptype']  = $arr[1];
            $parsed['dbsyntax'] = !$arr[2] ? $arr[1] : $arr[2];
        } else {
            $parsed['phptype']  = $str;
            $parsed['dbsyntax'] = $str;
        }

        if (!isset($dsn)) {
            return $parsed;
        }

        // Get (if found): username and password
        // $dsn => username:password@protocol+hostspec/database
        if (($at = strrpos($dsn,'@')) !== false) {
            $str = substr($dsn, 0, $at);
            $dsn = substr($dsn, $at + 1);
            if (($pos = strpos($str, ':')) !== false) {
                $parsed['username'] = rawurldecode(substr($str, 0, $pos));
                $parsed['password'] = rawurldecode(substr($str, $pos + 1));
            } else {
                $parsed['username'] = rawurldecode($str);
            }
        }

        // Find protocol and hostspec

        if (preg_match('|^([^(]+)\((.*?)\)/?(.*?)$|', $dsn, $match)) {
            // $dsn => proto(proto_opts)/database
            $proto       = $match[1];
            $proto_opts  = $match[2] ? $match[2] : false;
            $dsn         = $match[3];

        } else {
            // $dsn => protocol+hostspec/database (old format)
            if (strpos($dsn, '+') !== false) {
                list($proto, $dsn) = explode('+', $dsn, 2);
            }
            if (strpos($dsn, '/') !== false) {
                list($proto_opts, $dsn) = explode('/', $dsn, 2);
            } else {
                $proto_opts = $dsn;
                $dsn = null;
            }
        }

        // process the different protocol options
        $parsed['protocol'] = (!empty($proto)) ? $proto : 'tcp';
        $proto_opts = rawurldecode($proto_opts);
        if (strpos($proto_opts, ':') !== false) {
            list($proto_opts, $parsed['port']) = explode(':', $proto_opts);
        }
        if ($parsed['protocol'] == 'tcp') {
            $parsed['hostspec'] = $proto_opts;
        } elseif ($parsed['protocol'] == 'unix') {
            $parsed['socket'] = $proto_opts;
        }

        // Get dabase if any
        // $dsn => database
        if ($dsn) {
            if (($pos = strpos($dsn, '?')) === false) {
                // /database
                $parsed['database'] = rawurldecode($dsn);
            } else {
                // /database?param1=value1&param2=value2
                $parsed['database'] = rawurldecode(substr($dsn, 0, $pos));
                $dsn = substr($dsn, $pos + 1);
                if (strpos($dsn, '&') !== false) {
                    $opts = explode('&', $dsn);
                } else { // database?param1=value1
                    $opts = array($dsn);
                }
                foreach ($opts as $opt) {
                    list($key, $value) = explode('=', $opt);
                    if (!isset($parsed[$key])) {
                        // don't allow params overwrite
                        $parsed[$key] = rawurldecode($value);
                    }
                }
            }
        }

        return $parsed;
    }
}
?>
