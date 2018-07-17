<?php

class SyncFeedsModel extends ObjectModel
{

    public $id_minorista;
    public $distribuidor;
    public $tipo;
    public static $definition = array(
        'table' => 'syncfeeds',
        'primary' => 'id_syncfeeds',
        'fields' => array(
            'id_minorista' => array('type' => self::TYPE_INT, 'validate' => 'isUnsignedId'),
            'distribuidor' => array('type' => self::TYPE_STRING, 'validate' => 'isString'),
            'tipo' => array('type' => self::TYPE_STRING, 'validate' => 'isGenericName', 'values' => array('fabricante', 'caracteristica', 'caracteristica_valor'), 'default' => 'fabricante')
    ));

    public static function getIdsMinoristaDadoTipo($tipo, $id_inclusion = array())
    {
        $sql = 'SELECT id_minorista FROM ' . _DB_PREFIX_ . 'syncfeeds 
                WHERE tipo = "' . $tipo . '"
                    AND distribuidor IN ("' . implode('","', $id_inclusion) . '")';

        $result = Db::getInstance()->executeS($sql);

        $ids = array();
        foreach ($result as $fila)
            $ids[] = $fila['id_minorista'];

        return $ids;
    }

    public static function deleteIdsDadoTipo($tipo, $id_minoristas)
    {
        Db::getInstance()->execute('DELETE FROM `' . _DB_PREFIX_ . 'syncfeeds`
        WHERE tipo = "' . $tipo . '" AND id_minorista IN (' . implode(',', $id_minoristas) . ')');
    }

    public static function getIdMinoristaDadoIdDistribuidor($id, $tipo)
    {
        $sql = "SELECT id_minorista FROM " . _DB_PREFIX_ . "syncfeeds 
                        WHERE tipo = '" . $tipo . "' AND distribuidor = '" . Db::getInstance()->escape($id) . "'";

        return Db::getInstance()->getValue($sql, false);
    }

}
