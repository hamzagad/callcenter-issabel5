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
$Id: formulario $ */

include_once("libs/paloSantoDB.class.php");
/* Clase que implementa Formulario de Campanign de CallCenter (CC) */
/* EN: Class that implements CallCenter (CC) Campaign Form */
class paloSantoDataForm
{
    private $_db; // instancia de la clase paloDB // EN: paloDB class instance
    var $errMsg;

    function __construct($pDB)
    {
        // Se recibe como parámetro una referencia a una conexión paloDB
        // EN: A reference to a paloDB connection is received as a parameter
        if (is_object($pDB)) {
            $this->_db =& $pDB;
            $this->errMsg = $this->_db->errMsg;
        } else {
            $dsn = (string)$pDB;
            $this->_db = new paloDB($dsn);

            if (!$this->_db->connStatus) {
                $this->errMsg = $this->_db->errMsg;
                // debo llenar alguna variable de error
                // EN: I must fill some error variable
            } else {
                // debo llenar alguna variable de error
                // EN: I must fill some error variable
            }
        }

    }

    private function _condSQL($status)
    {
        $param = array();
        $where = array();
        switch ($status) {
        case 'all':
            break;
        case 'A':
        case 'I':
            $param[] = $status;
            $where[] = 'estatus = ?';
            break;
        }
        $cond = (count($where) > 0) ? ' WHERE '.implode(' AND ', $where) : '';
        
        return array($cond, $param);
    }
    
    function contarFormularios($status)
    {
        list($cond, $param) = $this->_condSQL($status);
        $sql = 'SELECT COUNT(*) AS N FROM form'.$cond;
        $tupla = $this->_db->getFirstRowQuery($sql, TRUE, $param);
        if (!is_array($tupla)) {
            $this->errMsg = $this->_db->errMsg;
        	return FALSE;
        }
        return $tupla['N'];
    }
    
    function listarFormularios($status, $limit = NULL, $offset = 0)
    {
    	list($cond, $param) = $this->_condSQL($status);
        $sql = 'SELECT id, nombre, descripcion, estatus FROM form'.$cond;
        if (!is_null($limit)) {
        	$sql .= ' LIMIT ? OFFSET ?';
            $param[] = $limit;
            $param[] = $offset;
        }
        $recordset = $this->_db->fetchTable($sql, TRUE, $param);
        if (!is_array($recordset)) {
            $this->errMsg = $this->_db->errMsg;
            return NULL;
        }
        return $recordset;
    }
    
    function leerFormulario($id_formulario)
    {
        $tupla = $this->_db->getFirstRowQuery(
            'SELECT id, nombre, descripcion, estatus FROM form WHERE id = ?',
            TRUE, array($id_formulario));
        if (!is_array($tupla)) {
            $this->errMsg = $this->_db->errMsg;
        	return FALSE;
        }
        return $tupla;
    }
    
    // Función exclusivamente para compatibilidad con campaign_out/campaign_in
    // EN: Function exclusively for compatibility with campaign_out/campaign_in
    function getFormularios($id_formulario = NULL, $estatus='all')
    {
        if (is_null($id_formulario))
            return $this->listarFormularios($estatus);
        return NULL;
    }
    
    function leerCamposFormulario($id_formulario)
    {
        $sql = 'SELECT id, etiqueta, value, tipo, orden FROM form_field WHERE id_form = ? ORDER by orden';
        $recordset = $this->_db->fetchTable($sql, TRUE, array($id_formulario));
        if (!is_array($recordset)) {
            $this->errMsg = $this->_db->errMsg;
            return NULL;
        }
        $campos = array();
        foreach ($recordset as $tuplacampo) {
            /* Convertir enumeración separada por comas en valores separados */
            /* EN: Convert comma-separated enumeration into separate values */
            if ($tuplacampo['tipo'] == 'LIST') {
                $enumval = explode(',', $tuplacampo['value']);
                if (count($enumval) > 0 && $enumval[count($enumval) - 1] == '')
                    array_pop($enumval);
                $tuplacampo['value'] = $enumval;
            } else {
                unset($tuplacampo['value']);
            }
            $campos[] = $tuplacampo;
        }
        return $campos;
    }
    
    function activacionFormulario($id_formulario, $bEstado)
    {
        $bExito = $this->_db->genQuery(
            'UPDATE form SET estatus = ? WHERE id = ?',
            array(($bEstado ? 'A' : 'I'), $id_formulario));
        if (!$bExito) {
            $this->errMsg = $this->_db->errMsg;
            return false;
        }
        return true;
    }
    
    function eliminarFormulario($id_formulario)
    {
        // Revisar si hay datos recolectados para este formulario
        foreach (array('form_data_recolected', 'form_data_recolected_entry') as $tabla) {
            $sQuery =
                "SELECT COUNT(*) AS N FROM form_field AS ff, $tabla AS fd ".
                'WHERE ff.id_form = ? AND ff.id = fd.id_form_field';
            $tupla = $this->_db->getFirstRowQuery($sQuery, TRUE, array($id_formulario));
            if (!is_array($tupla)) {
                $this->errMsg = $this->_db->errMsg;
                return FALSE;
            }
            if ($tupla['N'] > 0) {
                $this->errMsg = _tr("This form is been used by any campaign");
                return FALSE;
            }
        }
        
        // Revisar si hay campañas que referencian el formulario
        // EN: Check if there are campaigns referencing the form
        foreach (array('campaign_form', 'campaign_form_entry') as $tabla) {
            $sQuery = "SELECT COUNT(*) AS N FROM $tabla WHERE id_form = ?";
            $tupla = $this->_db->getFirstRowQuery($sQuery, TRUE, array($id_formulario));
            if (!is_array($tupla)) {
                $this->errMsg = $this->_db->errMsg;
                return FALSE;
            }
            if ($tupla['N'] > 0) {
                $this->errMsg = _tr("This form is been used by any campaign");
                return FALSE;
            }
        }
        
        // Ejecutar el borrado del formulario
        // EN: Execute form deletion
        $this->_db->beginTransaction();
        $sqllist = array(
            'DELETE FROM form_field WHERE id_form = ?',
            'DELETE FROM form WHERE id = ?',
        );
        foreach ($sqllist as $sql) {
            if (!$this->_db->genQuery($sql, array($id_formulario))) {
                $this->errMsg = $this->_db->errMsg;
                $this->_db->rollBack();
                return FALSE;
            }
        }
        $this->_db->commit();
        return TRUE;        
    }
    
    function guardarFormulario($id, $nombre, $descripcion, $formfields)
    {
        if (!is_null($id) && !is_numeric($id)) {
            $this->errMsg = _tr('Error Id Form');
            return FALSE;
        }
        if (trim($nombre) == '') {
            $this->errMsg = _tr('Error Form Name is empty');
            return FALSE;
        }
        if (count($formfields) <= 0) {
            $this->errMsg = _tr('Error List is empty');
            return FALSE;
        }
        
        // Asignar ordenamiento según posición de arreglo
        // EN: Assign ordering according to array position
        for ($i = 0; $i < count($formfields); $i++) {
            $formfields[$i]['orden'] = $i + 1;
        }
        
        /* Leer los datos de los campos anteriores, si existen. Se agregan o quitan
         * campos según existan. No se deben eliminar campos que tienen valores
         * ya recogidos. */
        /* EN: Read existing field data, if any. Fields are added or removed */
        /* EN: according to existence. Fields with collected values should not be deleted. */
        $camposExistentes = array();
        $camposBorrar = array();
        $camposActualizar = array();
        $camposInsertar = array();
        $iNumCampaniasUsanForm = 0;
        if (!is_null($id)) {
            // Revisar si hay datos recolectados para este formulario
            // EN: Check if there is collected data for this form
            // EN: Check if there is collected data for this form
            foreach (array('form_data_recolected', 'form_data_recolected_entry') as $tabla) {
                $sQuery =
                    "SELECT COUNT(*) AS N FROM form_field AS ff, $tabla AS fd ".
                    'WHERE ff.id_form = ? AND ff.id = fd.id_form_field';
                $tupla = $this->_db->getFirstRowQuery($sQuery, TRUE, array($id));
                if (!is_array($tupla)) {
                    $this->errMsg = $this->_db->errMsg;
                    return FALSE;
                }
                $iNumCampaniasUsanForm += $tupla['N'];
            }
            
            // Leer los IDs de los campos del formulario
            // EN: Read form field IDs
            $sQuery = 'SELECT id FROM form_field WHERE id_form = ?';
            $recordset = $this->_db->fetchTable($sQuery, TRUE, array($id));
            if (!is_array($recordset)) {
                $this->errMsg = $this->_db->errMsg;
                return FALSE;
            }
            foreach ($recordset as $tupla) {
                $camposExistentes[] = $tupla['id'];
            }
        }
        
        // Clasificar los campos que se envían para actualizar
        // EN: Classify fields sent for update
        $camposRef = array();
        foreach ($formfields as $field) {
            if (!in_array($field['tipo'], array('TEXT', 'LIST', 'DATE', 'TEXTAREA', 'LABEL'))) {
                $this->errMsg = _tr('Invalid field type');
                return FALSE;
            }
            if (trim($field['etiqueta']) == '') {
                $this->errMsg = _tr('Error Field Name is empty');
                return FALSE;                
            }
            if ($field['tipo'] == 'LIST') {
                if (!isset($field['value']) || count($field['value']) <= 0) {
                    $this->errMsg = _tr('Error List is empty');
                    return FALSE;
                }
                $field['value'] = implode(',', $field['value']).',';
            } else {
                $field['value'] = '';
            }
            
            if (isset($field['id']) && trim($field['id']) == '')
                unset($field['id']);
            
            if (isset($field['id'])) {
                if (!is_numeric($field['id']) || !in_array($field['id'], $camposExistentes)) {
                    $this->errMsg = _tr('Invalid field ID');
                    return FALSE;
                }
                $camposActualizar[] = $field;
                $camposRef[] = $field['id'];
            } else {
                $camposInsertar[] = $field;
            }
        }
        $camposBorrar = array_diff($camposExistentes, $camposRef);
        
        // No debe de borrarse campos de un formulario si lo usan las campañas
        // EN: Fields of a form should not be deleted if campaigns use it
        if (count($camposBorrar) > 0 && $iNumCampaniasUsanForm > 0) {
            $this->errMsg = _tr("This form is been used by any campaign");
            return FALSE;
        }        
        
        // Ejecutar la actualización
        // EN: Execute the update
        $this->_db->beginTransaction();
        if (is_null($id)) {
            $sql = 'INSERT INTO form (nombre, descripcion, estatus) VALUES (?, ?, "A")';
            $param = array($nombre, $descripcion);
        } else {
            $sql = 'UPDATE form SET nombre = ?, descripcion = ? WHERE id = ?';
            $param = array($nombre, $descripcion, $id);
        }
        if (!$this->_db->genQuery($sql, $param)) {
            $this->errMsg = $this->_db->errMsg;
            $this->_db->rollBack();
            return FALSE;
        }
        if (is_null($id)) $id = $this->_db->getLastInsertId();
        
        // Campos a borrar
        // EN: Fields to delete
        foreach ($camposBorrar as $id_field) {
            if (!$this->_db->genQuery(
                'DELETE FROM form_field WHERE id_form = ? AND id = ?',
                array($id, $id_field))) {
                $this->errMsg = $this->_db->errMsg;
                $this->_db->rollBack();
                return FALSE;
            }
        }
        
        // Campos a actualizar
        // EN: Fields to update
        foreach ($camposActualizar as $field) {
            if (!$this->_db->genQuery(
                'UPDATE form_field SET etiqueta = ?, value = ?, tipo = ?, orden = ? WHERE id_form = ? AND id = ?',
                array($field['etiqueta'], $field['value'], $field['tipo'], $field['orden'],
                        $id, $field['id']))) {
                $this->errMsg = $this->_db->errMsg;
                $this->_db->rollBack();
                return FALSE;                
            }
        }
        
        // Campos a insertar
        // EN: Fields to insert
        foreach ($camposInsertar as $field) {
            if (!$this->_db->genQuery(
                'INSERT INTO form_field (id_form, etiqueta, value, tipo, orden) VALUES (?, ?, ?, ?, ?)',
                array($id, $field['etiqueta'], $field['value'], $field['tipo'], $field['orden']))) {
                $this->errMsg = $this->_db->errMsg;
                $this->_db->rollBack();
                return FALSE;
            }
        }
        
        $this->_db->commit();
        return TRUE;
    }
}

?>
