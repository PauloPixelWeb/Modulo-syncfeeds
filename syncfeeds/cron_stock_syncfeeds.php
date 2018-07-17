<?php

include(dirname(__FILE__) . '/../../config/config.inc.php');
include(dirname(__FILE__) . '/syncfeeds.php');

$modulo = new SyncFeeds();
if (Tools::getValue('token') == Tools::encrypt($modulo->name))
    if ($modulo->active)
    {
        echo "Starting process...";
        $modulo->sincronizarExistencias();
        echo "Synchronization process ended.";
    }
?>
