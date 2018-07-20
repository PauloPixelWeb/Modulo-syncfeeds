<?php

include(dirname(__FILE__) . '/../../config/config.inc.php');
include(dirname(__FILE__) . '/syncfeeds.php');

$modulo = new SyncFeeds();
if (Tools::getValue('token') == Tools::encrypt($modulo->name))
    if ($modulo->active)
    {
        echo "<p>Starting process...</p>";
        $modulo->sincronizarProductos();
        $modulo->sincronizarSku();
        echo "<p>Synchronization process ended.</p>";
    }
?>
