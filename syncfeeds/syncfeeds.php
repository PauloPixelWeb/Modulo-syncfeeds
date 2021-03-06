<?php

if (!defined('_PS_VERSION_'))
    exit;

include(dirname(__FILE__) . '/classes/SyncFeedsModel.php');

class SyncFeeds extends Module
{

    private $_html;

    const TIPO_PRODUCTO = 1;
    const TIPO_STOCK = 2;
    const TIPO_PRECIO = 3;
    const TIPO_SKU = 4;
    const RUTA_FTP_ENTRADA_PRODUCTO = 'inbound/catalog/product/process/';
    const RUTA_FTP_ENTRADA_SKU = 'inbound/catalog/item/process/';
    const RUTA_FTP_ENTRADA_PRECIO = 'inbound/catalog/pricelist/process/';
    const RUTA_FTP_ENTRADA_STOCK = 'inbound/inventory/process/';
    const RUTA_FTP_ENTRADA_PRODUCTO_TEST = 'test/inbound/catalog/product/process/';
    const RUTA_FTP_ENTRADA_SKU_TEST = 'test/inbound/catalog/item/process/';
    const RUTA_FTP_ENTRADA_PRECIO_TEST = 'test/inbound/catalog/pricelist/process/';
    const RUTA_FTP_ENTRADA_STOCK_TEST = 'test/inbound/inventory/process/';
    const RUTA_FTP_SALIDA = 'outbound/sales/process/';
    const RUTA_FTP_SALIDA_TEST = 'test/outbound/sales/process/';
    //id_carrier => array(metodo envio lowes, dias demora envio)
    /* const METODOS_ENVIO = array(
      10 => array('Local Pickup', 8), //Envio a Domicilio (Camion Lowe's)
      5 => array('Store Pickup', 3), //Recoger en Tienda
      8 => array('Warehouse Shipment', 5)//FedEx
      ); */
    //const METODOS_ENVIO = '{"32":["Local Pickup", 8],"27":["Store Pickup", 3],"36":["Warehouse Shipment", 5]}';
    const METODOS_ENVIO = '{"32":["Local Pickup", 8],"27":["Store Pickup", 3],"36":["Warehouse Shipment", 5]}';
    //id_shop => numero tienda lowes
    /* const NUMEROS_TIENDA = array(
      1 => '3286', //Ecommerce
      5 => '2936', //Sendero
      6 => '3227', //Cumbres
      7 => '2935', //Linda Vista
      8 => '3267', //Escobedo
      13 => '3265', //Valle Alto
      9 => '3235', //Hermosillo
      10 => '3236', //Culiacan
      11 => '3255', //Chihuahua
      12 => '3233', //Saltillo
      0 => '14851' //Warehouse
      ); */
    const NUMEROS_TIENDA = '{"1":"3286","5":"2936","6":"3227","7":"2935","8":"3267","13":"3265","9":"3235","10":"3236","11":"3255","12":"3233","0":"14851","15":"3165","16":"3389"}';
    //module => codigo paso lowes
    /* const METODOS_PAGO = array(
      'payulatam' => 'XO',
      'paypal' => 'PP',
      'paypalplus' => 'PP'
      ); */
    const METODOS_PAGO = '{"payulatam":"XO","paypal":"PP","paypalplus":"PP"}';

    public function __construct()
    {
        $this->name = 'syncfeeds';
        $this->author = 'PixelWeb';
        $this->version = '1.0.0';
        $this->tab = 'others';
        parent::__construct();

        $this->displayName = $this->l('Sync Feeds');
        $this->description = $this->l('Synchronize product feeds.');
    }

    public function install()
    {
       
							//Estandarisa estados de ordenes
					   $idLang = Db::getInstance()->getValue("select id_lang from "._DB_PREFIX_."lang where language_code = 'es'");
															//Pago Cancelado
															Db::getIntance()->execute("update "._DB_PREFIX_."order_state_lang set name='Pedido Cancelado' where id_order_state = 6 and id_lang=".$idLang);
															Db::getIntance()->execute("update "._DB_PREFIX_."order_state_lang set name='Pedido Entregado',template='order_delivered' where id_order_state = 5 and id_lang=".$idLang);
															Db::getIntance()->execute("update "._DB_PREFIX_."order_state set send_email=1 where id_order_state = 5");
									
							//Creacion de tablas especiales del modulo
					  Db::getInstance()->execute('CREATE TABLE IF NOT EXISTS ' . _DB_PREFIX_ . 'syncfeeds (
                        id_syncfeeds int(10) unsigned NOT NULL Key AUTO_INCREMENT,
                        id_minorista int(10) unsigned NOT NULL,
                        distribuidor VARCHAR(100) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL,
                        tipo enum(\'fabricante\', \'caracteristica\', \'caracteristica_valor\') CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL DEFAULT \'fabricante\'
                        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8 ;');
        Db::getInstance()->execute('CREATE TABLE IF NOT EXISTS ' . _DB_PREFIX_ . 'syncfeeds_category (
                        id_lowes varchar(32) NOT NULL KEY,
                        id_prestashop int(10) NOT NULL
                        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8 ;');
        Db::getInstance()->execute('CREATE TABLE IF NOT EXISTS ' . _DB_PREFIX_ . 'syncfeeds_order (
                        id_order int(10) unsigned NOT NULL,
                        id_state int(10) unsigned NOT NULL,
                        PRIMARY KEY (id_order,id_state)
                        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8 ;');

        return (parent::install() &&
                Configuration::updateGlobalValue('SF_FTP', '') &&
                Configuration::updateGlobalValue('SF_USUARIO', '') &&
                Configuration::updateGlobalValue('SF_PRIVATE_KEY', '') &&
                Configuration::updateGlobalValue('SF_LAST_PEDIDOS_DEMAND', '1900-01-01 00:00:00') &&
                Configuration::updateGlobalValue('SF_LAST_PEDIDOS_SALES', '1900-01-01 00:00:00') &&
                Configuration::updateGlobalValue('SF_SALES_COMPLETE', '') &&
                Configuration::updateGlobalValue('SF_SALES_RETURN', '') &&
                Configuration::updateGlobalValue('SF_DEMAND_NEW', '') &&
                Configuration::updateGlobalValue('SF_DEMAND_CANCEL', '')
                );
    }

    public function uninstall()
    {
        //Db::getInstance()->execute('DROP table IF  EXISTS ' . _DB_PREFIX_ . 'syncfeeds');
        //Db::getInstance()->execute('DROP table IF  EXISTS ' . _DB_PREFIX_ . 'syncfeeds_category');
/*
        return (parent::uninstall() &&
                Configuration::deleteByName('SF_FTP') &&
                Configuration::deleteByName('SF_USUARIO') &&
                Configuration::deleteByName('SF_PRIVATE_KEY') &&
                Configuration::deleteByName('SF_LAST_PEDIDOS_DEMAND') &&
                Configuration::deleteByName('SF_LAST_PEDIDOS_SALES') &&
                Configuration::deleteByName('SF_SALES_COMPLETE', '') &&
                Configuration::deleteByName('SF_SALES_RETURN', '') &&
                Configuration::deleteByName('SF_DEMAND_NEW', '') &&
                Configuration::deleteByName('SF_DEMAND_CANCEL', '')
                );*/
																
																return parent::uninstall;
    }

    public function getContent()
    {
        if (Tools::isSubmit('submitSave'))
        {
            if (!Validate::isString(Tools::getValue('SF_FTP')))
                $this->_html .= $this->displayError($this->l('Invalid FTP IP address configuration.'));
            elseif (!Validate::isString(Tools::getValue('SF_USUARIO')))
                $this->_html .= $this->displayError($this->l('Invalid username configuration.'));
            elseif (!Validate::isString(Tools::getValue('SF_PRIVATE_KEY')))
                $this->_html .= $this->displayError($this->l('Invalid password configuration.'));
            else
            {
                Configuration::updateGlobalValue('SF_FTP', Tools::getValue('SF_FTP'));
                Configuration::updateGlobalValue('SF_USUARIO', Tools::getValue('SF_USUARIO'));
                Configuration::updateGlobalValue('SF_PRIVATE_KEY', Tools::getValue('SF_PRIVATE_KEY'));
                Configuration::updateGlobalValue('SF_SALES_COMPLETE', Tools::getValue('SF_SALES_COMPLETE'));
                Configuration::updateGlobalValue('SF_SALES_RETURN', Tools::getValue('SF_SALES_RETURN'));
                Configuration::updateGlobalValue('SF_DEMAND_NEW', Tools::getValue('SF_DEMAND_NEW'));
                Configuration::updateGlobalValue('SF_DEMAND_CANCEL', Tools::getValue('SF_DEMAND_CANCEL'));
                foreach (Shop::getShops() as $shop)
                    Configuration::updateGlobalValue('SF_SHOP_' . $shop['id_shop'], Tools::getValue('SF_SHOP_' . $shop['id_shop']));

                $this->_html .= $this->displayConfirmation($this->l('Configuration saved successfully.'));
            }
            //fichero CSV para categorias
            if (isset($_FILES['dm_csv']) && isset($_FILES['dm_csv']['tmp_name']) && !empty($_FILES['dm_csv']['tmp_name']))
            {
                $ext = substr($_FILES['dm_csv']['name'], strrpos($_FILES['dm_csv']['name'], '.') + 1);
                if ($ext == 'csv')
                    if ($fp = fopen($_FILES['dm_csv']['tmp_name'], "r"))
                    {
                        $categorias = array();
                        $ids = array();
                        while (($data = fgetcsv($fp, 500, ",")) !== FALSE)
                            if ((int) $data[1] && (string) $data[0] && !in_array((string) $data[0], $ids))
                            {
                                $categorias[] = array(
                                    'id_prestashop' => (int) $data[1],
                                    'id_lowes' => trim((string) $data[0])
                                );
                                $ids[] = (string) $data[0];
                            }
                        fclose($fp);

                        Db::getInstance()->execute('TRUNCATE ' . _DB_PREFIX_ . 'syncfeeds_category');
                        $result = Db::getInstance()->insert('syncfeeds_category', $categorias);

                        if ($result)
                            $this->_html .= $this->displayConfirmation($this->l('Category Link saved successfully.'));
                        else
                            $this->_html .= $this->displayError($this->l('An error occurred while attempting to update configuration.'));
                    }
                    else
                        $this->_html .= $this->displayError($this->l('An error occurred while attempting to upload the file.'));
                else
                    $this->_html .= $this->displayError($this->l('File must be in a valid CSV format.'));
            }
        }

        $this->displayForm();
        return $this->_html;
    }

    private function displayForm()
    {
        $this->_html .= '<div id="modulo-syncfeeds">';
								$this->_html .= '<form enctype="multipart/form-data" action="' . Tools::safeOutput($_SERVER['REQUEST_URI']) . '" method="post">';
        $this->_html .= '<fieldset>
            <legend>' . $this->l('Configuration') . '</legend>';
        
								
								
								
								//Informacion generación de crones.  Sale solo si se hay datos en la tabla syncfeeds_category
								if (Db::getInstance()->getValue('SELECT COUNT(*) FROM ' . _DB_PREFIX_ . 'syncfeeds_category'))
        {
            $token = Tools::encrypt($this->name);
            $this->_html .=
												'<div class="seccion">' .
												'<p class="relevante">' . $this->l('For set an automatic processing you must set a cron with these URL') . ':</p>
            <p>' . Context::getContext()->shop->getBaseURL() . 'modules/' . $this->name . '/cron_productos_syncfeeds.php?token=' . $token . ' - <a href="' . Context::getContext()->shop->getBaseURL() . 'modules/' . $this->name . '/cron_productos_syncfeeds.php?token=' . $token . '" target="_blank">Ejecutar</a></p>
            <p>' . Context::getContext()->shop->getBaseURL() . 'modules/' . $this->name . '/cron_stock_syncfeeds.php?token=' . $token . ' - <a href="' . Context::getContext()->shop->getBaseURL() . 'modules/' . $this->name . '/cron_stock_syncfeeds.php?token=' . $token . '" target="_blank">Ejecutar</a></p>
            <p>' . Context::getContext()->shop->getBaseURL() . 'modules/' . $this->name . '/cron_precios_syncfeeds.php?token=' . $token . ' - <a href="' . Context::getContext()->shop->getBaseURL() . 'modules/' . $this->name . '/cron_precios_syncfeeds.php?token=' . $token . '" target="_blank">Ejecutar</a></p>
            <p>' . Context::getContext()->shop->getBaseURL() . 'modules/' . $this->name . '/cron_demand_syncfeeds.php?token=' . $token . ' - <a href="' . Context::getContext()->shop->getBaseURL() . 'modules/' . $this->name . '/cron_demand_syncfeeds.php?token=' . $token . '" target="_blank">Ejecutar</a></p>
            <p>' . Context::getContext()->shop->getBaseURL() . 'modules/' . $this->name . '/cron_sales_syncfeeds.php?token=' . $token . ' - <a href="' . Context::getContext()->shop->getBaseURL() . 'modules/' . $this->name . '/cron_sales_syncfeeds.php?token=' . $token . '" target="_blank">Ejecutar</a></p>' .
												'</div>'
												;
        }
								
								//Configuracion FTP Lowe's
								$this->_html .= '<div class="seccion">';
								$this->_html .= '<p class="relevante">' . $this->l('FTP Conection settings') . ':</p>';
        $this->_html .= '<label for="SF_FTP">' . $this->l('FTP IP Address') . '</label>
        <div class="margin-form">
            <input type="text" name="SF_FTP" id="SF_FTP" value="' . Configuration::getGlobalValue('SF_FTP') . '" />
        </div>';
        $this->_html .= '<label for="SF_USUARIO">' . $this->l('FTP Username') . '</label>
        <div class="margin-form">
            <input type="text" name="SF_USUARIO" id="SF_USUARIO" value="' . Configuration::getGlobalValue('SF_USUARIO') . '" />
        </div>';
        $this->_html .= '<label for="SF_PRIVATE_KEY">' . $this->l('FTP Private Key') . '</label>
        <div class="margin-form">
            <textarea type="text" name="SF_PRIVATE_KEY" id="SF_PRIVATE_KEY" cols="80" rows="10">' . Configuration::getGlobalValue('SF_PRIVATE_KEY') . '</textarea>
        </div>';
										$this->_html .= '</div>';
										
								//Estados de ordenes
								$this->_html .= '<div class="seccion">';
								$this->_html .= '<p class="relevante">' . $this->l('Order States Settings') . ':</p>';
        $estados_pedido = OrderState::getOrderStates($this->context->language->id);
        $this->_html .= '<label for="SF_DEMAND_NEW">' . $this->l('New State for Demand File Generation') . '</label>
          <div class="margin-form">
          <select name="SF_DEMAND_NEW">';
        foreach ($estados_pedido as $estado)
            $this->_html .= '<option value="' . $estado['id_order_state'] . '" ' . ($estado['id_order_state'] == Configuration::getGlobalValue('SF_DEMAND_NEW') ? 'selected' : '') . '>' . $estado['name'] . '</option>';
        $this->_html .= '</select>
          </div></br>';
        $this->_html .= '<label for="SF_DEMAND_CANCEL">' . $this->l('Cancel State for Demand File Generation') . '</label>
          <div class="margin-form">
          <select name="SF_DEMAND_CANCEL">';
        foreach ($estados_pedido as $estado)
            $this->_html .= '<option value="' . $estado['id_order_state'] . '" ' . ($estado['id_order_state'] == Configuration::getGlobalValue('SF_DEMAND_CANCEL') ? 'selected' : '') . '>' . $estado['name'] . '</option>';
        $this->_html .= '</select>
          </div></br>';
        $this->_html .= '<label for="SF_SALES_COMPLETE">' . $this->l('Complete State for Sales File Generation') . '</label>
          <div class="margin-form">
          <select name="SF_SALES_COMPLETE">';
        foreach ($estados_pedido as $estado)
            $this->_html .= '<option value="' . $estado['id_order_state'] . '" ' . ($estado['id_order_state'] == Configuration::getGlobalValue('SF_SALES_COMPLETE') ? 'selected' : '') . '>' . $estado['name'] . '</option>';
        $this->_html .= '</select>
          </div></br>';
        $this->_html .= '<label for="SF_SALES_RETURN">' . $this->l('Return State for Sales File Generation') . '</label>
          <div class="margin-form">
          <select name="SF_SALES_RETURN">';
        foreach ($estados_pedido as $estado)
            $this->_html .= '<option value="' . $estado['id_order_state'] . '" ' . ($estado['id_order_state'] == Configuration::getGlobalValue('SF_SALES_RETURN') ? 'selected' : '') . '>' . $estado['name'] . '</option>';
        $this->_html .= '</select>
          </div></br>';
										
										$this->_html .= '</div>';
										
										//Configuracion numeros de tienda lowes para las Multitiendas de Prestashop
										$this->_html .= '<div class="seccion">';
										$this->_html .= '<p class="relevante">' . $this->l('Lowes number on Prestashop multistore') . ':</p>';
        foreach (Shop::getShops() as $shop)
            $this->_html .= '<label for="SF_SHOP_' . $shop['id_shop'] . '">' . $this->l('Lowes Shop Number for Shop') . ' "' . $shop['name'] . '"</label>
            <div class="margin-form">
                <input type="text" name="SF_SHOP_' . $shop['id_shop'] . '" id="SF_SHOP_' . $shop['id_shop'] . '" value="' . Configuration::getGlobalValue('SF_SHOP_' . $shop['id_shop']) . '" />
            </div></br>';
										$this->_html .= '</div>';
										
										
        $this->_html .= '<p class="center">
                            <input type="submit" class="button" name="submitSave" value="' . $this->l('Save') . '" />
                        </p>';


        $this->_html .= '</fieldset>';

        $this->_html .= '<form enctype="multipart/form-data" action="' . Tools::safeOutput($_SERVER['REQUEST_URI']) . '" method="post">';
        $this->_html .= '<fieldset>
            <legend>' . $this->l('Category Link') . '</legend>';
        
								//Subir archivo de link de categorias.
								$this->_html .= '<div class="seccion">';
								$this->_html .= '<label for="dm_csv">' . $this->l('Import Category Link by CSV File') . '</label>
            <div class="margin-form">
                <input type="file" name="dm_csv" id="dm_csv" />
            </div>';
        $this->_html .= '<p class="center">
                        <input type="submit" class="button" name="submitSave" value="' . $this->l('Import') . '" /> ';
        $this->_html .= '</p>';
								$this->_html .= '</div>';
								
								
								
								$this->_html .= '</fieldset>';
        

        $this->_html .= '</fieldset></form></div>';
								
								$style = '
										<style>
										#modulo-syncfeeds{    width: 85%;MARGIN: 20PX 0;}
										.seccion{    border: solid 2px darkblue; padding: 5px;  margin: 20px 0;}
										.relevante{font-weight: bold;
														text-transform: uppercase;
														text-decoration: underline;}
										.nobootstrap .button{    background: darkblue;
														color: white;
														padding: 10px;
														width: 100px;
														border-radius: 7px;}
										.nobootstrap .button:hover{border:solid 1px white; font-size:13px;}
										.nobootstrap label{width:380px; margin-right: 20px;}
										</style>
								';
								
								$this->_html .= $style;
								
								
    }

    /*
				Esta funcion permite que desde un archivo expuesto en ftp de Lowes se tome la informacion de nuevos productos
				*/
				public function sincronizarProductos()
    {
										echo "<p>- FUNCION SINCRONIZAR PRODUCTOS -</p>";
        if (function_exists('set_time_limit'))
            @set_time_limit(0);
        if ((int) Tools::substr(ini_get("memory_limit"), 0, -1) < 512)
            ini_set("memory_limit", "512M");

        if ($this->descargarFicheroXML(self::TIPO_PRODUCTO))
        {
            echo "<p>... Se ha descargardo el fichero</p>";
												$idiomas = Language::getLanguages();
            $limite_descripcion_corta = (int) Configuration::get('PS_PRODUCT_SHORT_DESC_LIMIT');
            if ($limite_descripcion_corta <= 0)
                $limite_descripcion_corta = 800;
            $caracteres_invalidos = array('^', '<', '>', ';', '=', '#', '{', '}');
            $ultima_clave_actualizada = Configuration::getGlobalValue('SF_ACTUALIZACION_PARCIAL');
            $continuar = (bool) $ultima_clave_actualizada;
            Shop::setContext(Shop::CONTEXT_ALL);

            $archivos = Tools::scandir(dirname(__FILE__) . '/ficheros/', 'txt');
            sort($archivos);
            $files = implode(",", $archivos);
            echo "<br/>Archivos ".$files."descargados<br/>";
            foreach ($archivos as $archivo)
                if (strpos($archivo, 'prd_') === 0)
                {echo "Archivo $archivo <br/>";
                    try
                    {
                        $archivo = dirname(__FILE__) . '/ficheros/' . $archivo;
                        if ($fp = fopen($archivo, "r"))
                        {
                            while (( $data = fgetcsv($fp, 5000, "^")) !== FALSE)
                            {
                                if ($ultima_clave_actualizada == $data[0])
                                    $continuar = false;
                                if ($continuar)
                                    continue;
                                Configuration::updateGlobalValue('SF_ACTUALIZACION_PARCIAL', $data[0]);

                                //Carga el ID del producto ya que el control de estos se hace a travez de la referencia
																																$id_producto = Db::getInstance()->getValue('SELECT `id_product` FROM ' . _DB_PREFIX_ . 'product WHERE `reference`="' . $data[0] . '"');
                                
																																//Se procesa el producto cargado si existe, sino se procesa un nuevo producto.
																																if ($id_producto)
                                {
																																		
																																			echo "Actualización de Producto: $id_producto<br/>";
																																		
																																								//Instancia del producto
																																				$producto = new Product($id_producto);
																																		
																																		
																																								//Varibles tipo lang, que varían con la traduccion.
																																				foreach ($idiomas as $idioma)
                                        $producto->name[$idioma['id_lang']] = Tools::truncate(str_replace($caracteres_invalidos, '', $data[1]), 128, '');
                                    foreach ($idiomas as $idioma)
                                        $producto->description_short[$idioma['id_lang']] = Tools::truncate($data[4], $limite_descripcion_corta);
                                    foreach ($idiomas as $idioma)
                                        $producto->description[$idioma['id_lang']] = $data[5];
                                    foreach ($idiomas as $idioma)
                                        $producto->link_rewrite[$idioma['id_lang']] = Tools::truncate(Tools::str2url(str_replace($caracteres_invalidos, '', $data[1])), 128, '');

																																		  //Proceso de categorías, el archivo trae el codigo de la ERP y se usa la tabla de correspondencias previamente cargada en el prestashop.
																																				$id_categorias_producto = array();
                                    if (isset($data[13]) && $data[13])
                                        foreach (explode('|', $data[13]) as $id_cat_lowes)
                                            if ($id_cat_presta = Db::getInstance()->getValue('SELECT `id_prestashop` FROM `' . _DB_PREFIX_ . 'syncfeeds_category` WHERE `id_lowes`="' . trim($id_cat_lowes) . '"'))
                                                $id_categorias_producto[] = $id_cat_presta;
                                    if (count($id_categorias_producto))
                                    {
                                        $id_categorias_producto = array_unique($id_categorias_producto);
                                        $producto->id_category_default = $id_categorias_producto[0];
                                        $producto->id_category[] = $id_categorias_producto;
                                    }

                                    $producto->save();
                                    $producto->addToCategories($id_categorias_producto);
                                }
                                else
                                {
																																			  //Proceso para un nuevo producto
																																			  echo "Nuevo Producto!<br/>";
                                    //es un producto que no ha sido importado aun
																																		
																																								//Instancia del producto
																																				$producto = new Product();
																																		
																																								//Varibles tipo lang, que varían con la traduccion.
																																				foreach ($idiomas as $idioma)
                                        $producto->name[$idioma['id_lang']] = Tools::truncate(str_replace($caracteres_invalidos, '', $data[1]), 128, '');
                                    foreach ($idiomas as $idioma)
                                        $producto->description_short[$idioma['id_lang']] = Tools::truncate($data[4], $limite_descripcion_corta);
                                    foreach ($idiomas as $idioma)
                                        $producto->description[$idioma['id_lang']] = $data[5];
                                    foreach ($idiomas as $idioma)
                                        $producto->link_rewrite[$idioma['id_lang']] = Tools::truncate(Tools::str2url(str_replace($caracteres_invalidos, '', $data[1])), 128, '');

																																		  //Proceso de categorías, el archivo trae el codigo de la ERP y se usa la tabla de correspondencias previamente cargada en el prestashop.
																																				$id_categorias_producto = array();
                                    if (isset($data[13]) && $data[1])
                                        foreach (explode('|', $data[13]) as $id_cat_lowes)
                                            if ($id_cat_presta = Db::getInstance()->getValue('SELECT `id_prestashop` FROM `' . _DB_PREFIX_ . 'syncfeeds_category` WHERE `id_lowes`="' . trim($id_cat_lowes) . '"'))
                                                $id_categorias_producto[] = $id_cat_presta;
                                    if (count($id_categorias_producto))
                                    {
                                        $id_categorias_producto = array_unique($id_categorias_producto);
                                        $producto->id_category_default = $id_categorias_producto[0];
                                        $producto->id_category[] = $id_categorias_producto;
                                    }
                                    $producto->reference = $data[0];

																																								//Proceso Fabricante
																																				$id_fabricante = SyncFeedsModel::getIdMinoristaDadoIdDistribuidor($data[2], 'fabricante');
                                    if (!$id_fabricante && $data[2])
                                    {
                                        $manufacturer = new Manufacturer();
                                        $manufacturer->name = $data[2];
                                        $manufacturer->active = true;
                                        $manufacturer->save();

                                        $dist_min = new SyncFeedsModel();
                                        $dist_min->distribuidor = $data[2];
                                        $dist_min->id_minorista = $manufacturer->id;
                                        $dist_min->tipo = 'fabricante';
                                        $dist_min->save();

                                        $id_fabricante = $manufacturer->id;
                                    }
                                    $producto->id_manufacturer = $id_fabricante;
				    $producto->active = false;
                                    try
                                    {
                                        if ($producto->save())
                                        {
                                            $producto->addToCategories($id_categorias_producto);
                                            $producto->checkDefaultAttributes();

                                            //Proceso para Marca
                                            if ($data[2])
                                            {
                                                $id_caracteristica = SyncFeedsModel::getIdMinoristaDadoIdDistribuidor('Marca', 'caracteristica');
                                                if (!$id_caracteristica)
                                                {
                                                    $id_caracteristica = Feature::addFeatureImport('Marca');

                                                    $dist_min = new SyncFeedsModel();
                                                    $dist_min->distribuidor = 'Marca';
                                                    $dist_min->id_minorista = $id_caracteristica;
                                                    $dist_min->tipo = 'caracteristica';
                                                    $dist_min->save();
                                                }

                                                $id_caracteristica_valor = SyncFeedsModel::getIdMinoristaDadoIdDistribuidor($data[2], 'caracteristica_valor');
                                                if ($id_caracteristica && !$id_caracteristica_valor)
                                                {
                                                    $id_caracteristica_valor = FeatureValue::addFeatureValueImport($id_caracteristica, $data[2]);

                                                    $dist_min = new SyncFeedsModel();
                                                    $dist_min->distribuidor = $data[2];
                                                    $dist_min->id_minorista = $id_caracteristica_valor;
                                                    $dist_min->tipo = 'caracteristica_valor';
                                                    $dist_min->save();
                                                }

                                                if ($id_caracteristica && $id_caracteristica_valor)
                                                    Product::addFeatureProductImport((int) $producto->id, $id_caracteristica, $id_caracteristica_valor);
                                            }
                                            //Proceso para Modelo
                                            if ($data[3])
                                            {
                                                $id_caracteristica = SyncFeedsModel::getIdMinoristaDadoIdDistribuidor('Modelo', 'caracteristica');
                                                if (!$id_caracteristica)
                                                {
                                                    $id_caracteristica = Feature::addFeatureImport('Modelo');

                                                    $dist_min = new SyncFeedsModel();
                                                    $dist_min->distribuidor = 'Modelo';
                                                    $dist_min->id_minorista = $id_caracteristica;
                                                    $dist_min->tipo = 'caracteristica';
                                                    $dist_min->save();
                                                }

                                                $id_caracteristica_valor = SyncFeedsModel::getIdMinoristaDadoIdDistribuidor($data[3], 'caracteristica_valor');
                                                if ($id_caracteristica && !$id_caracteristica_valor)
                                                {
                                                    $id_caracteristica_valor = FeatureValue::addFeatureValueImport($id_caracteristica, $data[3]);

                                                    $dist_min = new SyncFeedsModel();
                                                    $dist_min->distribuidor = $data[3];
                                                    $dist_min->id_minorista = $id_caracteristica_valor;
                                                    $dist_min->tipo = 'caracteristica_valor';
                                                    $dist_min->save();
                                                }

                                                if ($id_caracteristica && $id_caracteristica_valor)
                                                    Product::addFeatureProductImport((int) $producto->id, $id_caracteristica, $id_caracteristica_valor);
                                            }
                                            //metros cuadrados
                                            if (isset($data[14]) && $data[14])
                                            {
                                                $id_caracteristica = SyncFeedsModel::getIdMinoristaDadoIdDistribuidor('Metros Cuadrados', 'caracteristica');
                                                if (!$id_caracteristica)
                                                {
                                                    $id_caracteristica = Feature::addFeatureImport('Metros Cuadrados');

                                                    $dist_min = new SyncFeedsModel();
                                                    $dist_min->distribuidor = 'Metros Cuadrados';
                                                    $dist_min->id_minorista = $id_caracteristica;
                                                    $dist_min->tipo = 'caracteristica';
                                                    $dist_min->save();
                                                }

                                                $id_caracteristica_valor = SyncFeedsModel::getIdMinoristaDadoIdDistribuidor($data[14], 'caracteristica_valor');
                                                if ($id_caracteristica && !$id_caracteristica_valor)
                                                {
                                                    $id_caracteristica_valor = FeatureValue::addFeatureValueImport($id_caracteristica, $data[14]);

                                                    $dist_min = new SyncFeedsModel();
                                                    $dist_min->distribuidor = $data[14];
                                                    $dist_min->id_minorista = $id_caracteristica_valor;
                                                    $dist_min->tipo = 'caracteristica_valor';
                                                    $dist_min->save();
                                                }

                                                if ($id_caracteristica && $id_caracteristica_valor)
                                                    Product::addFeatureProductImport((int) $producto->id, $id_caracteristica, $id_caracteristica_valor);

                                                $producto->unity = 'm2';
                                                $producto->save();
                                            }
                                            if (isset($data[11]) && $data[11])
                                                foreach (explode('|', $data[11]) as $caract_clave_valor)
                                                {
                                                    $carac_datos = explode('=', $caract_clave_valor);
                                                    if (count($carac_datos) == 2)
                                                    {
                                                        $id_caracteristica = SyncFeedsModel::getIdMinoristaDadoIdDistribuidor($carac_datos[0], 'caracteristica');
                                                        if (!$id_caracteristica && $carac_datos[0])
                                                        {
                                                            $id_caracteristica = Feature::addFeatureImport($carac_datos[0]);

                                                            $dist_min = new SyncFeedsModel();
                                                            $dist_min->distribuidor = $carac_datos[0];
                                                            $dist_min->id_minorista = $id_caracteristica;
                                                            $dist_min->tipo = 'caracteristica';
                                                            $dist_min->save();
                                                        }

                                                        $id_caracteristica_valor = SyncFeedsModel::getIdMinoristaDadoIdDistribuidor($carac_datos[1], 'caracteristica_valor');
                                                        if ($id_caracteristica && !$id_caracteristica_valor && $carac_datos[1])
                                                        {
                                                            $id_caracteristica_valor = FeatureValue::addFeatureValueImport($id_caracteristica, $carac_datos[1]);

                                                            $dist_min = new SyncFeedsModel();
                                                            $dist_min->distribuidor = $carac_datos[1];
                                                            $dist_min->id_minorista = $id_caracteristica_valor;
                                                            $dist_min->tipo = 'caracteristica_valor';
                                                            $dist_min->save();
                                                        }

                                                        if ($id_caracteristica && $id_caracteristica_valor)
                                                            Product::addFeatureProductImport((int) $producto->id, $id_caracteristica, $id_caracteristica_valor);
                                                    }
                                                }
                                        }
                                    }
                                    catch (Exception $e)
                                    {
                                        $this->saveLog('Metodo: sincronizarProductos, guardando: producto ' . $data[0] . ' => ' . $e->getMessage());
                                        $producto->delete();
                                    }
                                }
                            }
                            fclose($fp);
                            Tools::deleteFile($archivo);
                        }
                    }
                    catch (Exception $e)
                    {
                        $this->saveLog('Metodo: sincronizarProductos, cargando: TXT local ' . $archivo . ' => ' . $e->getMessage());
                    }
                }

            Configuration::updateGlobalValue('SF_ACTUALIZACION_PARCIAL', '0');
            return true;
        }
        else
        {
            echo "<p>... Hubo un error al Descargar el fichero de Productos.</p>";
												$this->saveLog('Error descargando fichero');
            return false;
        }

        return false;
    }

    public function sincronizarSku()
    {
        echo "<p>- FUNCION SINCRONIZAR SKU -</p>";
								if (function_exists('set_time_limit'))
            @set_time_limit(0);
        if ((int) Tools::substr(ini_get("memory_limit"), 0, -1) < 512)
            ini_set("memory_limit", "512M");

        if ($this->descargarFicheroXML(self::TIPO_SKU))
        {
            echo "<p>... Se descargaron ficheros SKU</p>";
												$archivos = Tools::scandir(dirname(__FILE__) . '/ficheros/', 'txt');
            sort($archivos);
            foreach ($archivos as $archivo)
                if (strpos($archivo, 'sku_') === 0)
                {
                    try
                    {
                        $archivo = dirname(__FILE__) . '/ficheros/' . $archivo;
                        if ($fp = fopen($archivo, "r"))
                        {
                            while (( $data = fgetcsv($fp, 500, "^")) !== FALSE)
                            {
                                Db::getInstance()->update('product', array(
                                    'upc' => $data[3],
                                    'width' => $data[6],
                                    'height' => $data[5],
                                    'depth' => $data[7],
                                    'weight' => $data[4]
                                        ), 'reference = "' . $data[1] . '"');
                            }
                            fclose($fp);
                            Tools::deleteFile($archivo);
                        }
                    }
                    catch (Exception $e)
                    {
                        $this->saveLog('Metodo: sincronizarSku, cargando: TXT local => ' . $e->getMessage());
                    }
                }

            return true;
        }
        else
        {
            echo "<p>... Hubo un error descargando el fichero de SKU</p>";
												$this->saveLog('Error descargando fichero');
            return false;
        }

        return false;
    }

    
				/*
										Actualizacion de stock en las diferentes tiendas a partir del archivo expuesto por LOWES
				*/
				public function sincronizarExistencias()
    {
        
								echo "<p>- FUNCION SINCRONIZAR EXISTENCIAS -</p>";
								if (function_exists('set_time_limit'))
            @set_time_limit(0);
        if ((int) Tools::substr(ini_get("memory_limit"), 0, -1) < 512)
            ini_set("memory_limit", "512M");

        if ($tiendas = $this->obtenerConfiguracionTiendas())
        {
            if ($this->descargarFicheroXML(self::TIPO_STOCK))
            {
                $archivos = Tools::scandir(dirname(__FILE__) . '/ficheros/', 'txt');
                foreach ($archivos as $archivo)
                    if (strpos($archivo, 'Inventory_'))
                        try
                        {
                            $archivo = dirname(__FILE__) . '/ficheros/' . $archivo;
                            if ($fp = fopen($archivo, "r"))
                            {
                                $sem = 0;
																																while (( $data = fgetcsv($fp, 50, "|")) !== FALSE)
                                    if (isset($tiendas[$data[1]])){
																																		
																																								//Query para traer id del producto y el stock actual en la base de datos
																																								$sql_data = '
																																													SELECT a.id_product,b.quantity
																																													FROM ' . _DB_PREFIX_ . 'product a
																																													LEFT JOIN ' . _DB_PREFIX_ . 'stock_available b on a.id_product = b.id_product
																																													WHERE a.reference = "'.$data[0].'" and b.id_shop = "'.$tiendas[$data[1]].'"
																																								';

																																								if ($datox = Db::getInstance()->getRow($sql_data)){

																																													//Condicional si el stock es diferente, evita que se realice proceso de actualizacion con la misma información
																																													if($datox['quantity'] != $data[2]){

																																														//Esta funcion actualiza el stock disponible en la base de datos.
																																																		$sem++;
																																																	//StockAvailable::setQuantity($id_producto, 0, (int) $data[2], $tiendas[$data[1]]);
																																																	StockAvailable::setQuantity($datox['id_product'], 0, (int) $data[2], $tiendas[$data[1]]);
																																							
																																																	Hook::exec('actionUpdateQuantitySyncfeeds', 
																																																									array('product' => datox['id_product'],
																																																													'stock' => $data[2],
																																																													'shop' => $tiendas[$data[1]],));
																																													}
																														
                                        }
																																			}
                                fclose($fp);
                                Tools::deleteFile($archivo);
																																echo '<p>Se han procesado "'.$sem.'" registros</p>';
                                break;
                            }
																												
                        }
                        catch (Exception $e)
                        {
                            $this->saveLog('Metodo: sincronizarExistencias, cargando: TXT local => ' . $e->getMessage());
                        }

                return true;
            }
        }
        else
            $this->saveLog('Metodo: sincronizarExistencias, configuracion de tiendas vacia');

        return false;
    }

    public function sincronizarPrecios()
    {
        if (function_exists('set_time_limit'))
            @set_time_limit(0);
        if ((int) Tools::substr(ini_get("memory_limit"), 0, -1) < 512)
            ini_set("memory_limit", "512M");

        if ($tiendas = $this->obtenerConfiguracionTiendas())
        {
            if ($this->descargarFicheroXML(self::TIPO_PRECIO))
            {
                $archivos = Tools::scandir(dirname(__FILE__) . '/ficheros/', 'txt');
                sort($archivos);
                foreach ($archivos as $archivo)
                    if (strpos($archivo, 'price_') === 0)
                    {
                        try
                        {
                            $archivo = dirname(__FILE__) . '/ficheros/' . $archivo;
                            if ($fp = fopen($archivo, "r"))
                            {
                                while (( $data = fgetcsv($fp, 50, "^")) !== FALSE)
                                    if ($id_producto = (int) Db::getInstance()->getValue('SELECT `id_product` FROM ' . _DB_PREFIX_ . 'product WHERE `reference`="' . $data[0] . '"'))
                                    {
                                        $valor = 0;
                                        if ($id_caracteristica = SyncFeedsModel::getIdMinoristaDadoIdDistribuidor('Metros Cuadrados', 'caracteristica'))
                                            if ($id_caracteristica_valor = (int) Db::getInstance()->getValue('SELECT `id_feature_value` FROM `' . _DB_PREFIX_ . 'feature_product` '
                                                            . 'WHERE `id_feature`=' . $id_caracteristica . ' AND `id_product`=' . $id_producto))
                                                $valor = (float) Db::getInstance()->getValue('SELECT `value` FROM `' . _DB_PREFIX_ . 'feature_value_lang` WHERE `id_feature_value`=' . $id_caracteristica_valor);
echo "VALOR: $valor<br>$id_producto<br>";
                                        echo "result of update ".Db::getInstance()->update('product', array(
                                            'price' => (float) $data[2],
                                            'wholesale_price' => (float) $data[1],
                                            'unit_price_ratio' => ($valor ? (float) $valor : 0)
                                                ), 'id_product = ' . $id_producto);
echo "<br>";
                                        if (isset($tiendas[$data[3]]))
                                            Db::getInstance()->update('product_shop', array(
                                                'price' => (float) $data[2],
                                                'wholesale_price' => (float) $data[1],
                                                'unit_price_ratio' => ($valor ? (float) $valor : 0)
                                                    ), 'id_product = ' . $id_producto . ' AND id_shop = ' . $tiendas[$data[3]]);
                                    }
                                fclose($fp);
                                Tools::deleteFile($archivo);
                            }
                        }
                        catch (Exception $e)
                        {
                            $this->saveLog('Metodo: sincronizarPrecios, cargando: TXT local => ' . $e->getMessage());
                        }
                    }
                return true;
            }
            else
                $this->saveLog('Error descargando fichero');
        }
        else
            $this->saveLog('Metodo: sincronizarPrecios, configuracion de tiendas vacia');

        return false;
    }

    private function obtenerConfiguracionTiendas()
    {
        $tiendas = array();
        foreach (Shop::getShops() as $shop)
            if ($lowes = Configuration::getGlobalValue('SF_SHOP_' . $shop['id_shop']))
                $tiendas[$lowes] = (int) $shop['id_shop'];
        return $tiendas;
    }

    
				/* SINCRONIZACION DE PEDIDOS EN DEMANDA */
				public function sincronizarPedidosDemand()
    {
        if (function_exists('set_time_limit'))
            @set_time_limit(0);
        if ((int) Tools::substr(ini_get("memory_limit"), 0, -1) < 512)
            ini_set("memory_limit", "512M");

        try
        {
            $ultima_generacion = Configuration::getGlobalValue('SF_LAST_PEDIDOS_DEMAND');
            $max_date = '1900-01-01 00:00:00';
            $METODOS_ENVIO = json_decode(self::METODOS_ENVIO, true);
            $METODOS_PAGO = json_decode(self::METODOS_PAGO, true);
            $NUMEROS_TIENDA = json_decode(self::NUMEROS_TIENDA, true);
            //Demand
            $estados_demand = Configuration::getMultiple(array('SF_DEMAND_NEW', 'SF_DEMAND_CANCEL'));
												
												//Generacion XML
            $xml = '<?xml version="1.0" encoding="UTF-8"?>
                <eCommerceOrders>';
            foreach ($estados_demand as $estado_nombre => $estado_valor)
            {
                $sql = 'SELECT `o`.`id_order`,`o`.`date_add`,`o`.`date_upd`,`o`.`reference`,`o`.`id_customer`,`o`.`id_address_delivery`,`o`.`id_address_invoice`,`o`.`id_shop`,`o`.`id_carrier`,`o`.`module`,`o`.`'
                .'total_shipping_tax_incl`,`o`.`total_paid_tax_incl`,`o`.`total_discounts_tax_incl`,`o`.`current_state`'
                . ' FROM `' . _DB_PREFIX_ . 'orders` `o` LEFT JOIN `'._DB_PREFIX_.'syncfeeds_order` `so` '
                . ' ON `o`.`id_order`=`so`.`id_order` ';

                switch ($estado_nombre)
                {
                    case 'SF_DEMAND_CANCEL':
                        $transaccion = 'Cancel';
                        $states = "$estado_valor,25,26";
                        $sql .= 'WHERE (`so`.`id_order` IS NULL OR `o`.`id_order` NOT IN (select id_order from ps_syncfeeds_order where id_state in ('.$states.'))) ';
                        break;
                    default:
                        $transaccion = 'New';
                        $states = "$estado_valor,3,4,21,22,27";
                        $sql .= ' WHERE `so`.`id_order` IS NULL ';
                        break;
                }
                $sql .= ' AND `o`.`date_upd`>"'.$ultima_generacion.'" AND `o`.`current_state` IN (' .$states.')';
                $pedidos = Db::getInstance()->executeS($sql);
                echo "<br/>";
                foreach ($pedidos as $pedido){                 
                    echo "ORDER:".$pedido['id_order']."<br/>";
                    if (!Db::getInstance()->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'syncfeeds_order` WHERE `id_order`=' . $pedido['id_order'] . ' AND `id_state`=' . $estado_valor))
                    {
                        $xml .= '<Order>
                        <SiteID>B2C</SiteID>
                        <OrderDatetime>' . date('YmdHis') . '</OrderDatetime>
                        <OrderNumber>' . $pedido['reference'] . '</OrderNumber>
                        <OrderItems>';
                        //<OrderDatetime>' . date('YmdHis', strtotime($pedido['date_add'])) . '</OrderDatetime>

                        $metodo_envio = 'Local Pickup';
                        $dias_demora = 5;
                        if (isset($METODOS_ENVIO[(int) $pedido['id_carrier']]))
                        {
                            $metodo_envio = $METODOS_ENVIO[(int) $pedido['id_carrier']][0];
                            $dias_demora = (int) $METODOS_ENVIO[(int) $pedido['id_carrier']][1];
                        }

                        ### RM MOdified in order to avoid ExpectedDeliveryDate Exception in POS ###
                        $delivery_date = date('Ymd', strtotime('+' . $dias_demora . ' day'));

                        $metodo_pago = 'XO';

                        if (isset($METODOS_PAGO[$pedido['module']]))
                            $metodo_pago = $METODOS_PAGO[$pedido['module']];
                        $numero_tienda = '3286';
                        if (isset($NUMEROS_TIENDA[(int) $pedido['id_shop']]))
                            $numero_tienda = $NUMEROS_TIENDA[(int) $pedido['id_shop']];
																									
                        if ($pedido['id_carrier'] == 54){
                            $metodo_envio = 'Local Pickup';
                        }                        
                        if ($metodo_envio == 'Warehouse Shipment'){
                            $metodo_envio = 'Local Pickup';
                            $numero_referencia = '03286';

                        }
                        elseif ($metodo_envio == 'Local Pickup' )
                            $numero_referencia = '03286';

                        else{
                            $numero_referencia = str_pad($numero_tienda, 5, '0', STR_PAD_LEFT);
                        }
                        
                        switch ($metodo_envio)
                        {
                            case 'Warehouse Shipment':
                                $numero_referencia .= 'E01';
                                break;
                            case 'Local Pickup':
                                $numero_referencia .= 'E01';
                                break;
                            default:
                                $numero_referencia .= 'E56';
                                break;
                        }

                        $numero_referencia .= str_pad(($pedido['id_order']), 4, '0', STR_PAD_LEFT) . date('Ymd', strtotime($pedido['date_add']));

                        $consecutivo = 1;
                        $detalles_pedido = OrderDetail::getList($pedido['id_order']);
                        foreach ($detalles_pedido as $detalle)
                        {
                            if ($pedido['id_carrier'] == 54){
                                $numero_tienda = '2936';
                            }

                            $total_amount = round((float) $detalle['product_quantity']) * round($detalle['product_price'],2);
                            $tax_excl = round((float) $detalle['total_price_tax_excl'],2);
                            $totaldiscountamount = abs($total_amount - $tax_excl);
                            $xml .= '<OrderItem>
                                    <LineId>' . $consecutivo++ . '</LineId>
                                    <SkuId>' . $detalle['product_reference'] . '</SkuId>
                                    <ReferenceNo>' . $numero_referencia . '</ReferenceNo>
                                    <TranType>' . $transaccion . '</TranType>
                                    <DeliveryMethod>' . $metodo_envio . '</DeliveryMethod>
                                    <PickupLocation>' . $numero_tienda . '</PickupLocation>
                                    <InventoryLocation>' . $numero_tienda . '</InventoryLocation>
                                    <ExpectedDeliveryDate>' . $delivery_date . '</ExpectedDeliveryDate>
                                    <TotalQuantity>' . (int) $detalle['product_quantity'] . '</TotalQuantity>
                                    <TotalAmount>' . round((float) ($detalle['product_quantity'] * $detalle['product_price']), 2) . '</TotalAmount>
                                    <TotalDiscountAmount>' . round($totaldiscountamount,2) . '</TotalDiscountAmount>
                                    <TotalNetAmount>' . round((float) $detalle['total_price_tax_excl'], 2) . '</TotalNetAmount>
                                </OrderItem>';
                        }
                        $direccion_facturacion = new Address($pedido['id_address_invoice']);
                        $direccion_envio = new Address($pedido['id_address_delivery']);
                        $email = Db::getInstance()->getValue('SELECT `email` FROM `' . _DB_PREFIX_ . 'customer` WHERE `id_customer`=' . $pedido['id_customer']);
                        $xml .= '</OrderItems>
                        <OrderCustomer>
                            <CustId>' . $pedido['id_customer'] . '</CustId>
                            <BillTo>
                                <Contact>
                                    <FirstName>' . $direccion_facturacion->firstname . '</FirstName>
                                    <LastName>' . $direccion_facturacion->lastname . '</LastName>
                                    <CompanyName>' . $direccion_facturacion->company . '</CompanyName>
                                    <WorkPhone>' . $direccion_facturacion->phone . '</WorkPhone>
                                    <MobilePhone>' . $direccion_facturacion->phone . '</MobilePhone>
                                    <Email>' . $email . '</Email>
                                </Contact>
                                <Address>
                                    <Add1>' . $direccion_facturacion->address1 . '</Add1>
                                    <Add2>' . $direccion_facturacion->address2 . '</Add2>
                                    <City>' . $direccion_facturacion->city . '</City>
                                    <State>' . State::getNameById($direccion_facturacion->id_state) . '</State>
                                    <PostalCode>' . $direccion_facturacion->postcode . '</PostalCode>
                                    <CountryCode>' . Country::getIsoById($direccion_facturacion->id_country) . '</CountryCode>
                                </Address>
                            </BillTo>
                            <ShipTo>
                                <Contact>
                                    <FirstName>' . $direccion_envio->firstname . '</FirstName>
                                    <LastName>' . $direccion_envio->lastname . '</LastName>
                                    <CompanyName>' . $direccion_envio->company . '</CompanyName>
                                    <WorkPhone>' . $direccion_envio->phone . '</WorkPhone>
                                    <MobilePhone>' . $direccion_envio->phone . '</MobilePhone>
                                    <Email>' . $email . '</Email>
                                </Contact>
                                <Address>
                                    <Add1>' . $direccion_envio->address1 . '</Add1>
                                    <Add2>' . $direccion_envio->address2 . '</Add2>
                                    <City>' . $direccion_envio->city . '</City>
                                    <State>' . State::getNameById($direccion_envio->id_state) . '</State>
                                    <PostalCode>' . $direccion_envio->postcode . '</PostalCode>
                                    <CountryCode>' . Country::getIsoById($direccion_envio->id_country) . '</CountryCode>
                                </Address>
                            </ShipTo>
                        </OrderCustomer>
                        <OrderTenders>
                            <OrderTender>
                                <Type>' . $metodo_pago . '</Type>
                                <CardIssuer>' . $metodo_pago . '</CardIssuer>
                                <TotalAmount>' . round($pedido['total_paid_tax_incl']) . '</TotalAmount>
                                <CardNumber>XXXXXXXXXXXX0000</CardNumber>
                                <CardReference>0000</CardReference>
                            </OrderTender>
                        </OrderTenders>
                        <ShippingTotalAmount>' . round($pedido['total_shipping_tax_incl']) . '</ShippingTotalAmount>
                        <TotalAmount>' . round($pedido['total_paid_tax_incl']) . '</TotalAmount>
                        <TotalNetAmount>' . round($pedido['total_paid_tax_incl']) . '</TotalNetAmount>
                        <TotalDiscountAmount>' . round($pedido['total_discounts_tax_incl']) . '</TotalDiscountAmount>
                    </Order>';

                        if ($pedido['date_upd'] > $max_date)
                            $max_date = $pedido['date_upd'];

                        Db::getInstance()->insert('syncfeeds_order', array('id_order' => $pedido['id_order'], 'id_state' => $estado_valor, 'order_reference' => $numero_referencia,));
                    }
                }
            }
            $xml .= '</eCommerceOrders>';

            $xml = trim(preg_replace('/\t/', '', $xml));
            $generationDate = date('YmdHis');

            if(strlen($xml) > 91){
                $archivo = dirname(__FILE__) . '/ficheros/pedidos/demand_lowesmx_' .$generationDate. '.xml';
                file_put_contents($archivo, $xml);
                if(!file_exists(dirname(__FILE__) . '/ficheros/pedidos/generados/'))
                mkdir(dirname(__FILE__) . '/ficheros/pedidos/generados/');
                copy($archivo, dirname(__FILE__) . '/ficheros/pedidos/generados/demand_lowesmx_'.$generationDate.'.xml');
            }

            Configuration::updateGlobalValue('SF_LAST_PEDIDOS_DEMAND', $max_date);
        }
        catch (Exception $e)
        {
            $this->saveLog('Metodo: sincronizarPedidosDemand, guardando: XML local => ' . $e->getMessage());
        }


								$this->subirFicheroXML();
        return true;
    }

   //funcion nueva para generar el Sales file
    public function sincronizarPedidosSales()
    {
        if (function_exists('set_time_limit'))
            @set_time_limit(0);
        if ((int) Tools::substr(ini_get("memory_limit"), 0, -1) < 512)
            ini_set("memory_limit", "512M");

        try
        {
            $ultima_generacion = Configuration::getGlobalValue('SF_LAST_PEDIDOS');
            $max_date = '1900-01-01 00:00:00';
            $METODOS_ENVIO = json_decode(self::METODOS_ENVIO, true);
            $METODOS_PAGO = json_decode(self::METODOS_PAGO, true);
            $NUMEROS_TIENDA = json_decode(self::NUMEROS_TIENDA, true);

            //Sales
            $estados_sales = Configuration::getMultiple(array('SF_SALES_COMPLETE', 'SF_SALES_RETURN'));
            $xml = '<?xml version="1.0" encoding="UTF-8"?>
                <eCommerceOrders>';
            foreach ($estados_sales as $estado_nombre => $estado_valor)
            {
                $sql = 'SELECT po.`id_order`, po.`date_add`, po.`date_upd`,po.`reference`,po.`id_customer`,po.`id_address_delivery`,po.`id_address_invoice`,'
                        . ' po.`id_shop`,po.`id_carrier`,po.`module`,po.`total_shipping_tax_incl`,po.`total_paid_tax_incl`,po.`total_discounts_tax_incl` '
                        . ' FROM `' . _DB_PREFIX_ . 'orders` po ';

                if($estado_nombre == 'SF_SALES_COMPLETE'){
                    $transaccion = 'Complete';
                    $states = $estado_valor;
                    $sql .= ' LEFT JOIN ps_syncfeeds_order ps ON (po.id_order = ps.id_order and ps.id_state = '. $states .') ';
                    $sql .= ' WHERE po.`current_state`=' . $states . ' and ps.id_order IS NULL ';
                }else{
		continue;
		}

                $pedidos = Db::getInstance()->executeS($sql);
		        echo "<br/>";
                foreach ($pedidos as $pedido){
                echo "ORDER:".$pedido['id_order']."<br/>";

                    if (!Db::getInstance()->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'syncfeeds_order` WHERE `id_order`=' . $pedido['id_order'] . ' AND `id_state`=' . $estado_valor))
                    {
                        #echo "IF COUNT<br/>";
                        $xml .= '<Order>
                        <SiteID>B2C</SiteID>
                        <OrderDatetime>' . date('YmdHis') . '</OrderDatetime>
                        <OrderNumber>' . $pedido['reference'] . '</OrderNumber>
                        <OrderItems>';

                        $metodo_envio = 'Local Pickup';
                        $dias_demora = 5;
                        if (isset($METODOS_ENVIO[(int) $pedido['id_carrier']]))
                        {
                            $metodo_envio = $METODOS_ENVIO[(int) $pedido['id_carrier']][0];
                            $dias_demora = (int) $METODOS_ENVIO[(int) $pedido['id_carrier']][1];
                        }

			            $delivery_date = date('Ymd', strtotime('+' . $dias_demora . ' day'));
                        $metodo_pago = 'XO';

                        if (isset($METODOS_PAGO[$pedido['module']]))
                            $metodo_pago = $METODOS_PAGO[$pedido['module']];
                        $numero_tienda = '3286';
                        if (isset($NUMEROS_TIENDA[(int) $pedido['id_shop']]))
                            $numero_tienda = $NUMEROS_TIENDA[(int) $pedido['id_shop']];

                        $consecutivo = 1;
                        $detalles_pedido = OrderDetail::getList($pedido['id_order']);

                        foreach ($detalles_pedido as $detalle)
                            if ($pedido['id_carrier'] == 54){
                                $metodo_envio = 'Local Pickup';
                            } 
                            if ($metodo_envio == 'Warehouse Shipment'){
                                $metodo_envio = 'Local Pickup';
                                $numero_referencia = '03286';

                            }
                             elseif ($metodo_envio == 'Local Pickup' )
                                $numero_referencia = '03286';

                            else{
                                $numero_referencia = str_pad($numero_tienda, 5, '0', STR_PAD_LEFT);
                            }

                            switch ($metodo_envio)
                            {
                                case 'Warehouse Shipment':
                                    $numero_referencia .= 'E01';
                                    break;
                                case 'Local Pickup':
                                    $numero_referencia .= 'E01';
                                    break;
                                default:
                                    $numero_referencia .= 'E56';
                                    break;
                            }

                        $numero_referencia .= str_pad(($pedido['id_order']), 4, '0', STR_PAD_LEFT) . date('Ymd', strtotime($pedido['date_add']));                            
                        if ($consecutivo == 9999)
                        $consecutivo = 0;

                        foreach ($detalles_pedido as $detalle)
                        {
                            if ($pedido['id_carrier'] == 54){
                                $numero_tienda = '2936';
                            }

                            $total_amount = round((float) $detalle['product_quantity']) * round($detalle['product_price'],2);
                            $tax_excl = round((float) $detalle['total_price_tax_excl'],2);
                            $totaldiscountamount = abs($total_amount - $tax_excl);                            
                        $xml .= '<OrderItem>
                                   <LineId>' . $consecutivo++ . '</LineId>
                                    <SkuId>' . $detalle['product_reference'] . '</SkuId>
                                    <ReferenceNo>' . $numero_referencia . '</ReferenceNo>
                                    <TranType>' . $transaccion . '</TranType>
                                    <DeliveryMethod>' . $metodo_envio . '</DeliveryMethod>
                                    <PickupLocation>' . $numero_tienda . '</PickupLocation>
                                    <InventoryLocation>' . $numero_tienda . '</InventoryLocation>
                                    <ExpectedDeliveryDate>' . $delivery_date . '</ExpectedDeliveryDate>
                                    <TotalQuantity>' . (int) $detalle['product_quantity'] . '</TotalQuantity>
                                    <TotalAmount>' . round((float) ($detalle['product_quantity'] * $detalle['product_price']), 2) . '</TotalAmount>
                                    <TotalDiscountAmount>' . round($totaldiscountamount,2) . '</TotalDiscountAmount>
                                    <TotalNetAmount>' . round((float) $detalle['total_price_tax_excl'], 2) . '</TotalNetAmount>
                                </OrderItem>';
                        }
                        $direccion_facturacion = new Address($pedido['id_address_invoice']);
                        $direccion_envio = new Address($pedido['id_address_delivery']);
                        $email = Db::getInstance()->getValue('SELECT `email` FROM `' . _DB_PREFIX_ . 'customer` WHERE `id_customer`=' . $pedido['id_customer']);
                        $xml .= '</OrderItems>
                        <OrderCustomer>
                            <CustId>' . $pedido['id_customer'] . '</CustId>
                            <BillTo>
                                <Contact>
                                    <FirstName>' . $direccion_facturacion->firstname . '</FirstName>
                                    <LastName>' . $direccion_facturacion->lastname . '</LastName>
                                    <CompanyName>' . $direccion_facturacion->company . '</CompanyName>
                                    <WorkPhone>' . $direccion_facturacion->phone . '</WorkPhone>
                                    <MobilePhone>' . $direccion_facturacion->phone . '</MobilePhone>
                                    <Email>' . $email . '</Email>
                                </Contact>
                                <Address>
                                    <Add1>' . $direccion_facturacion->address1 . '</Add1>
                                    <Add2>' . $direccion_facturacion->address2 . '</Add2>
                                    <City>' . $direccion_facturacion->city . '</City>
                                    <State>' . State::getNameById($direccion_facturacion->id_state) . '</State>
                                    <PostalCode>' . $direccion_facturacion->postcode . '</PostalCode>
                                    <CountryCode>' . Country::getIsoById($direccion_facturacion->id_country) . '</CountryCode>
                                </Address>
                            </BillTo>
                            <ShipTo>
                                <Contact>
                                    <FirstName>' . $direccion_envio->firstname . '</FirstName>
                                    <LastName>' . $direccion_envio->lastname . '</LastName>
                                    <CompanyName>' . $direccion_envio->company . '</CompanyName>
                                    <WorkPhone>' . $direccion_envio->phone . '</WorkPhone>
                                    <MobilePhone>' . $direccion_envio->phone . '</MobilePhone>
                                    <Email>' . $email . '</Email>
                                </Contact>
                                <Address>
                                    <Add1>' . $direccion_envio->address1 . '</Add1>
                                    <Add2>' . $direccion_envio->address2 . '</Add2>
                                    <City>' . $direccion_envio->city . '</City>
                                    <State>' . State::getNameById($direccion_envio->id_state) . '</State>
                                    <PostalCode>' . $direccion_envio->postcode . '</PostalCode>
                                    <CountryCode>' . Country::getIsoById($direccion_envio->id_country) . '</CountryCode>
                                </Address>
                            </ShipTo>
                        </OrderCustomer>
                        <OrderTenders>
                            <OrderTender>
                                <Type>' . $metodo_pago . '</Type>
                                <CardIssuer>' . $metodo_pago . '</CardIssuer>
                                <TotalAmount>' . round($pedido['total_paid_tax_incl']) . '</TotalAmount>
                                <TransactionReference>0000000000</TransactionReference>
                                <CardNumber>XXXXXXXXXXXX0000</CardNumber>
                                <CardReference>0000</CardReference>
                            </OrderTender>
                        </OrderTenders>
                        <ShippingTotalAmount>' . round($pedido['total_shipping_tax_incl']) . '</ShippingTotalAmount>
                        <TotalAmount>' . round($pedido['total_paid_tax_incl']) . '</TotalAmount>
                        <TotalNetAmount>' . round($pedido['total_paid_tax_incl']) . '</TotalNetAmount>
                        <TotalDiscountAmount>' . round($pedido['total_discounts_tax_incl']) . '</TotalDiscountAmount>
                    </Order>';

                        if ($pedido['date_upd'] > $max_date)
                            $max_date = $pedido['date_upd'];
                        Db::getInstance()->insert('syncfeeds_order', array('id_order' => $pedido['id_order'], 'id_state' => $estado_valor, 'order_reference' => $numero_referencia,));
                        
                    }
                }
            }
            $xml .= '</eCommerceOrders>';

            $xml = trim(preg_replace('/\t/', '', $xml));

	    if(strlen($xml) > 91){
		$archivo = dirname(__FILE__) . '/ficheros/pedidos/sales_' . date('Ymd') . '.xml';
            	file_put_contents($archivo, $xml);
            	if(!file_exists(dirname(__FILE__) . '/ficheros/pedidos/generados/'))
                mkdir(dirname(__FILE__) . '/ficheros/pedidos/generados/');

	   	copy($archivo, dirname(__FILE__) . '/ficheros/pedidos/generados/sales_'.  date('YmdHis') .'.xml');
	    }

            Configuration::updateGlobalValue('SF_LAST_PEDIDOS', $max_date);
        }
        catch (Exception $e)
        {
            $this->saveLog('Metodo: sincronizarPedidos, guardando: XML local => ' . $e->getMessage());
        }

        $this->subirFicheroXML();
        return true;
    }


					/*
										Esta funcion permite acceder a los archivos expuestos por FTP en el servidor de LOWES
					*/
				private function descargarFicheroXML($tipo = 0)
    {
        if ($tipo == self::TIPO_PRODUCTO && Configuration::getGlobalValue('SF_ACTUALIZACION_PARCIAL'))
            return true;

        if (function_exists('set_time_limit'))
            @set_time_limit(0);
        if ((int) Tools::substr(ini_get("memory_limit"), 0, -1) < 512)
            ini_set("memory_limit", "512M");

        try
        {
            $fichero = false;
            include_once(dirname(__FILE__) . '/phpseclib/Net/SFTP.php');
            include_once(dirname(__FILE__) . '/phpseclib/Crypt/RSA.php');

            $key = new Crypt_RSA();
            $key->loadKey(Configuration::getGlobalValue('SF_PRIVATE_KEY'));
            $sftp = new Net_SFTP(Configuration::getGlobalValue('SF_FTP'));
            if ($sftp->login(Configuration::getGlobalValue('SF_USUARIO'), $key))
            {
                
																//WAC cambiar la ruta cuando pase a Produccion o a Desarrollo
                switch ($tipo)
                {
                    case self::TIPO_PRODUCTO:
                        {
                            $ruta = self::RUTA_FTP_ENTRADA_PRODUCTO_TEST;
                        } break;
                    case self::TIPO_SKU:
                        {
                            $ruta = self::RUTA_FTP_ENTRADA_SKU_TEST;
                        }break;
                    case self::TIPO_STOCK:
                       { 
                            $ruta = self::RUTA_FTP_ENTRADA_STOCK_TEST;
                        }break;
                    case self::TIPO_PRECIO:
                        {
                            $ruta = self::RUTA_FTP_ENTRADA_PRECIO_TEST;
                        }break;
                }

                if (!$sftp->is_dir($ruta . 'procesados/'))
                    $sftp->mkdir($ruta . 'procesados/');

                $fichero = true;
                $archivos = $sftp->nlist($ruta);
                rsort($archivos);
                foreach ($archivos as $archivo)
                    if (strpos($archivo, '.txt'))
                        if ($fichero = $sftp->get($ruta . $archivo, dirname(__FILE__) . '/ficheros/' . $archivo))
                        {
                            $sftp->rename($ruta . $archivo, $ruta . 'procesados/' . $archivo);
                            if ($tipo == self::TIPO_STOCK)
                                break;
                        }

                $sftp->disconnect();
            }
            else
                $this->saveLog('Metodo: descargarFicheroXML => No se pudo conectar al FTP');

            if ($fichero)
                return true;
            else
            {
                $errors = $sftp->getErrors();
                $this->saveLog('Metodo: descargarFicheroXML => ' . implode("\n", $errors));
            }
        }
        catch (Exception $e)
        {
            $this->saveLog('Metodo: descargarFicheroXML => ' . $e->getMessage());
        }

        //return false;
								return true;  //modo de pruebas no procesa descarga de ficheros
    }

    private function subirFicheroXML()
    {
        if (function_exists('set_time_limit'))
            @set_time_limit(0);
        if ((int) Tools::substr(ini_get("memory_limit"), 0, -1) < 512)
            ini_set("memory_limit", "512M");

        try
        {
            include_once(dirname(__FILE__) . '/phpseclib/Net/SFTP.php');
            include_once(dirname(__FILE__) . '/phpseclib/Crypt/RSA.php');

            $key = new Crypt_RSA();
            $key->loadKey(Configuration::getGlobalValue('SF_PRIVATE_KEY'));
            $sftp = new Net_SFTP(Configuration::getGlobalValue('SF_FTP'));
            if ($sftp->login(Configuration::getGlobalValue('SF_USUARIO'), $key))
            {
                $archivos = Tools::scandir(dirname(__FILE__) . '/ficheros/pedidos/', 'xml');
                foreach ($archivos as $archivo)
                {
                    //WAC cambiar la ruta de salida a la de produccion cuando sea el momento
                    if ($sftp->put(self::RUTA_FTP_SALIDA_TEST . $archivo, dirname(__FILE__) . '/ficheros/pedidos/' . $archivo, NET_SFTP_LOCAL_FILE))
                        unlink(dirname(__FILE__) . '/ficheros/pedidos/' . $archivo);
                    else
                        $this->saveLog('Metodo: subirFicheroXML => ' . $sftp->getLastSFTPError());
                }
                $sftp->disconnect();

                return true;
            }
            else
            {
                $errors = $sftp->getErrors();
                $this->saveLog('Metodo: subirFicheroXML => No se pudo conectar al FTP: ' . implode("\n", $errors));
            }
        }
        catch (Exception $e)
        {
            $this->saveLog('Metodo: subirFicheroXML => ' . $e->getMessage());
        }

        return false;
    }

    private function saveLog($mensaje)
    {
        $fw = fopen(_PS_MODULE_DIR_ . 'syncfeeds/log.txt', 'a');
        fwrite($fw, date(DATE_RSS) . "\n" . $mensaje . "\n\n");
        fclose($fw);
    }

}
