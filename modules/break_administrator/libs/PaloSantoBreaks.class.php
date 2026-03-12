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

/* Clase que implementa breaks */
/* EN: Class that implements breaks */
class PaloSantoBreaks
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
     * Procedimiento para obtener la cantidad de los breaks existentes. Si
     * se especifica id, el listado contendrá únicamente el break
     * indicada por el valor. De otro modo, se listarán todas los breaks.
     * EN: Procedure to get the count of existing breaks. If an id is specified,
     * EN: the list will contain only the break indicated by the value. Otherwise,
     * EN: all breaks will be listed.
     *
     * @param int       $id_break    Si != NULL, indica el id del break a recoger
     *                              EN: If != NULL, indicates the break id to retrieve
     * @param string    $estatus    'I' para breaks inactivos, 'A' para activos,
     *                              cualquier otra cosa para todos los breaks.
     *                              EN: 'I' for inactive breaks, 'A' for active,
     *                              EN: anything else for all breaks.
     *
     * @return array    Listado de breaks en el siguiente formato, o FALSE en
     *                  caso de error:
     *                  EN: List of breaks in the following format, or FALSE on error:
     *  array(
     *      array(id,name,description),....,
     *  )
     */
    function countBreaks($id_break = NULL, $estatus='all'){
        // Validación
        // EN: Validation
        $this->errMsg = "";
        if (!is_null($id_break) && !preg_match('/^\d+$/', $id_break)) {
            $this->errMsg = _tr("Break ID is not valid");
            return FALSE;
        }
        if (!in_array($estatus, array('I', 'A'))) $estatus = NULL;
    
        // Construcción de petición y sus parámetros
        // EN: Construction of request and its parameters
        $sPeticionSQL = 'SELECT count(id) FROM break WHERE tipo = ?';
        $paramSQL = array('B');
        if (!is_null($id_break)) { $sPeticionSQL .= ' AND id = ?'; $paramSQL[] = $id_break; }
        if (!is_null($estatus)) { $sPeticionSQL .= ' AND status = ?'; $paramSQL[] = $estatus; }
        $recordset = $this->_DB->getFirstRowQuery($sPeticionSQL, FALSE, $paramSQL);
        if (!is_array($recordset)) {
            $this->errMsg = $this->_DB->errMsg;
            return FALSE;
        }
        return $recordset[0];
    }
    
    /**
     * Procedimiento para obtener el listado de los breaks existentes. Si
     * se especifica id, el listado contendrá únicamente el break
     * indicada por el valor. De otro modo, se listarán todas los breaks.
     * EN: Procedure to get the list of existing breaks. If an id is specified,
     * EN: the list will contain only the break indicated by the value. Otherwise,
     * EN: all breaks will be listed.
     *
     * @param int       $id_break    Si != NULL, indica el id del break a recoger
     *                              EN: If != NULL, indicates the break id to retrieve
     * @param string    $estatus    'I' para breaks inactivos, 'A' para activos,
     *                              cualquier otra cosa para todos los breaks.
     *                              EN: 'I' for inactive breaks, 'A' for active,
     *                              EN: anything else for all breaks.
     *
     * @return array    Listado de breaks en el siguiente formato, o FALSE en
     *                  caso de error:
     *                  EN: List of breaks in the following format, or FALSE on error:
     *  array(
     *      array(id,name,description),....,
     *  )
     */
    function getBreaks($id_break = NULL, $estatus='all', $limit=NULL, $offset=NULL)
    {
        // Validación
        // EN: Validation
        $this->errMsg = "";
        if (!is_null($id_break) && !preg_match('/^\d+$/', $id_break)) {
            $this->errMsg = _tr("Break ID is not valid");
            return FALSE;
        }
        if (!in_array($estatus, array('I', 'A'))) $estatus = NULL;

        // Construcción de petición y sus parámetros
        // EN: Construction of request and its parameters
        $sPeticionSQL = 'SELECT id, name, description, status FROM break WHERE tipo = ?';
        $paramSQL = array('B');
        if (!is_null($id_break)) { $sPeticionSQL .= ' AND id = ?'; $paramSQL[] = $id_break; }
        if (!is_null($estatus)) { $sPeticionSQL .= ' AND status = ?'; $paramSQL[] = $estatus; }
            
        if(isset($limit)){
            $sPeticionSQL .=" LIMIT ?";
            $paramSQL[]=$limit;
        }
        
        if(isset($offset)){
            $sPeticionSQL .=" OFFSET ?";
            $paramSQL[]=$offset;
        }
        $recordset = $this->_DB->fetchTable($sPeticionSQL, TRUE, $paramSQL);
        if (!is_array($recordset)) {
            $this->errMsg = $this->_DB->errMsg;
            return FALSE;
        }
        return $recordset;
    }

    /**
     * Procedimiento para crear un nuevo Break.
     * EN: Procedure to create a new Break.
     *
     * @param   $sNombre            Nombre del Break
     *                              EN: Break name
     * @param   $sDescripcion       Un detalle del break
     *                              EN: A detail of the break
     *
     * @return  bool    true or false si inserto o no
     *                  EN: true or false whether inserted or not
     */
    function createBreak($sNombre, $sDescripcion)
    {
        $result = FALSE;
        $sNombre = trim("$sNombre");
        if ($sNombre == '') {
            $this->errMsg = _tr("Name Break can't be empty");
        } else {
            $recordset =& $this->_DB->fetchTable(
                'SELECT * FROM break WHERE name = ?', FALSE, 
                array($sNombre));
            if (is_array($recordset) && count($recordset) > 0) 
                $this->errMsg = _tr("Name Break already exists");
            else {
                // Construir y ejecutar la orden de inserción SQL
                // EN: Build and execute SQL insert command
                $result = $this->_DB->genQuery(
                    'INSERT INTO break (name, description) VALUES (?, ?)',
                    array($sNombre, $sDescripcion));
                if (!$result) {
                    $this->errMsg = _tr('(internal) Failed to insert break').': '.$this->_DB->errMsg;
                }
            }
        }
        return $result;
    }   

    /**
     * Procedimiento para actualizar un break dado
     * EN: Procedure to update a given break
     *
     * @param   $idBreak        id del Break
     *                          EN: Break ID
     * @param   $sNombre        Nombre del Break
     *                          EN: Break name
     * @param   $sDescripcion   Detalle del Break
     *                          EN: Break detail
     *
     * @return  bool    true or false si actualizo o no
     *                  EN: true or false whether updated or not
     */
    function updateBreak($idBreak, $sNombre, $sDescripcion)
    {
        $result = FALSE;
        $sNombre = trim("$sNombre");
        if ($sNombre == '') {
            $this->errMsg = _tr("Name Break can't be empty");
        } else if (!preg_match('/^\d+$/', $idBreak)) {
            $this->errMsg = _tr("Id Break is empty");
        } else {
            // Construir y ejecutar la orden de update SQL
            // EN: Build and execute SQL update command
            $result = $this->_DB->genQuery(
                'UPDATE break SET name = ?, description = ? WHERE id = ?',
                array($sNombre, $sDescripcion, $idBreak));            
            if (!$result) {
                $this->errMsg = _tr('(internal) Failed to update break').': '.$this->_DB->errMsg;
            }
        } 
        return $result;
    }

     /**
     * Procedimiento para poner en estado activo o inactivo un break
     * Activo = 'A'   ,  Inactivo = 'I'
     * EN: Procedure to set a break to active or inactive status
     * EN: Active = 'A', Inactive = 'I'
     *
     * @param   $idBreak        id del Break
     *                          EN: Break ID
     * @param   $activate        Activo o Inactivo ('A' o 'I')
     *                          EN: Active or Inactive ('A' or 'I')
     *
     * @return  bool    true or false si actualizo o no el estatus
     *                  EN: true or false whether status was updated or not
     */
    function activateBreak($idBreak,$activate)
    {
        $result = FALSE;
        if (!in_array($activate, array('A', 'I'))) {
            $this->errMsg = _tr('Invalid status');
        } else if (!preg_match('/^\d+$/', $idBreak)) {
            $this->errMsg = _tr("Id Break is empty");
        } else {
            // Construir y ejecutar la orden de update SQL
            // EN: Build and execute SQL update command
            $result = $this->_DB->genQuery(
                'UPDATE break SET status = ? WHERE id = ?',
                array($activate, $idBreak));
            if (!$result) {
                $this->errMsg = _tr('(internal) Failed to update break').': '.$this->_DB->errMsg;
            }
        }
        return $result;
    } 
}

?>
