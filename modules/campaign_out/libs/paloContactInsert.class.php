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
 $Id: paloSantoCampaignCC.class.php,v 1.2 2008/06/06 07:15:07 cbarcos Exp $ */

class paloContactInsert
{
    private $_id_campaign;
    private $_db;
    private $_sth_dnc;
    private $_sth_contact_number;
    private $_sth_attribute;
    var $errMsg = NULL;

    function __construct($db, $idCampaign)
    {
        if (get_class($db) == 'paloDB') $db = $db->conn;
        if (get_class($db) != 'PDO') die ('Expected PDO, got '.get_class($db));
        $this->_db = $db;
        $this->_id_campaign = $idCampaign;

        $this->_sth_dnc = $this->_db->prepare(
            'SELECT COUNT(*) AS N FROM dont_call WHERE caller_id = ? AND status = ?');
        $this->_sth_contact_number = $this->_db->prepare(
            'INSERT INTO calls (id_campaign, phone, status, dnc) VALUES (?, ?, NULL, ?)');
        $this->_sth_attribute = $this->_db->prepare(
            'INSERT INTO call_attribute (id_call, columna, value, column_number) VALUES (?, ?, ?, ?)');
    }

    function beforeBatchInsert() { return TRUE; }

    /**
     * Procedimiento para insertar un contacto a la campaña. El formato de los
     * atributos en el parámetro $attributes es un arreglo cuya clave es el
     * número de columna correspondiente al atributo y cuyo valor es una tupla
     * cuyo primer elemento es la etiqueta del atributo y cuyo segundo elemento
     * es el valor cadena del atributo. Es responsabilidad del llamador el
     * asegurar que una etiqueta en particular aparezca siempre en la misma
     * posición en todas las llamadas al método. Es también responsabilidad del
     * llamador el asegurar que no hayan huecos en la secuencia de números de
     * columna en la inserción de atributos. Si un valor de atributo aparece
     * como NULL, se insertará como una cadena vacía.
     * EN: Procedure to insert a contact into the campaign. The format of attributes
     * EN: in the $attributes parameter is an array whose key is the column number
     * EN: corresponding to the attribute and whose value is a tuple whose first
     * EN: element is the attribute label and whose second element is the attribute
     * EN: string value. It is the caller's responsibility to ensure that a
     * EN: particular label always appears in the same position in all method calls.
     * EN: It is also the caller's responsibility to ensure there are no gaps in the
     * EN: column number sequence in attribute insertion. If an attribute value
     * EN: appears as NULL, it will be inserted as an empty string.
     *
     * @param string    $number     Número del contacto a llamar
     *                              EN: Contact number to call
     * @param array     $attributes Atributos del contacto.
     *                              EN: Contact attributes.
     *
     * @return  ID del contacto en la tabla calls, o NULL en error.
     *          EN: Contact ID in the calls table, or NULL on error.
     */
    function insertOneContact($number, $attributes)
    {
        $this->errMsg = NULL;

        // Se busca estatus DNC
        // EN: DNC status is searched
        $r = $this->_sth_dnc->execute(array($number, 'A'));
        if (!$r) {
            $this->errMsg = 'On DNC lookup: '.print_r($this->_sth_dnc->errorInfo(), TRUE);
            return NULL;
        }
        $tupla = $this->_sth_dnc->fetch(PDO::FETCH_ASSOC);
        $this->_sth_dnc->closeCursor();
        $iDNC = ($tupla['N'] != 0) ? 1 : 0;

        // Inserción del número en sí
        // EN: Insertion of the number itself
        $r = $this->_sth_contact_number->execute(array($this->_id_campaign, $number, $iDNC));
        if (!$r) {
            $this->errMsg = _tr('On number insert').': '.print_r($this->_sth_contact_number->errorInfo(), TRUE);
            return NULL;
        }

        // Recuperar el ID de inserción para insertar atributos. Esto asume MySQL.
        // EN: Retrieve insertion ID to insert attributes. This assumes MySQL.
        $idCall = $this->_db->lastInsertId();

        // Inserción de atributos
        // EN: Insertion of attributes
        foreach ($attributes as $iNumColumna => $attr) {
            if (is_null($attr[1])) $attr[1] = '';
            $r = $this->_sth_attribute->execute(array($idCall, $attr[0], $attr[1], $iNumColumna));
            if (!$r) {
                $this->errMsg = _tr('On attribute insert').': '.print_r($this->_sth_attribute->errorInfo(), TRUE);
                return NULL;
            }
        }
        return $idCall;
    }

    function afterBatchInsert()
    {
        $sth = $this->_db->prepare('UPDATE campaign SET estatus = ? WHERE id = ? AND estatus = ?');
        $r = $sth->execute(array('A', $this->_id_campaign, 'T'));
        if (!$r) {
            $this->errMsg = _tr('On campaign reactivation').': '.print_r($this->_sth_attribute->errorInfo(), TRUE);
            return FALSE;
        }
        return TRUE;
    }
}
