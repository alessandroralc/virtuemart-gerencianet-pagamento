<?php
defined('_JEXEC') or die('Restricted access');

if (!class_exists('vmPSPlugin')) {
    require(JPATH_VM_PLUGINS . DS . 'vmpsplugin.php');
}

class plgVmPaymentGerencianet extends vmPSPlugin
{
    const API_URI               = 'https://integracao.gerencianet.com.br/xml/cobrancaonline/emite/xml';
    const DATA_FOR_CALLBACK_URI = 'https://integracao.gerencianet.com.br/callback/armazenar/virtuemart';

    public static $_this = false;
    private static $_pluginVersion = '1.1';

    public function __construct(&$subject, $config)
    {
        parent::__construct($subject, $config);

        $this->_loggable   = true;
        $this->tableFields = array_keys($this->getTableSQLFields());
        $this->_tablepkey  = 'id';
        $this->_tableId    = 'id';
        $this->setConfigParameterable($this->_configTableFieldName, $this->_getGerencianetVarsToPush());

        $this->getVmPluginCreateTableSQL();
        $this->_addGerencianetLibrary();
    }

    private function _addGerencianetLibrary()
    {
        require_once 'GerencianetLibrary/GerencianetXml.php';
    }

    public function formataValor($method, $valor)
    {
        $paymentCurrency        = CurrencyDisplay::getInstance($method->payment_currency);
        $totalInPaymentCurrency = round(
            $paymentCurrency->convertCurrencyTo($method->payment_currency, $valor, false),
            2
        );
        $totalInPaymentCurrency < 0 ? $totalInPaymentCurrency *= -1 : $totalInPaymentCurrency *= 1;
        return (int)$totalInPaymentCurrency * 100;
    }

    public function plgVmConfirmedOrder($cart, $order)
    {
        if (!($method = $this->getVmPluginMethod($order['details']['BT']->virtuemart_paymentmethod_id))) {
            return null; // Another method was selected, do nothing
        }
        if (!$this->selectedThisElement($method->payment_element)) {
            return false;
        }

        $session                = JFactory::getSession();
        $return_context         = $session->getId();
        $email_currency         = $this->getEmailCurrency($method);
        $paymentCurrency        = CurrencyDisplay::getInstance($method->payment_currency);
        $totalInPaymentCurrency = round(
            $paymentCurrency->convertCurrencyTo($method->payment_currency, $order['details']['BT']->order_total, false),
            2
        );

        // Prepare data that should be stored in the database
        $dbValues['order_number']                = $order['details']['BT']->order_number;
        $dbValues['payment_name']                = $this->renderPluginName($method, $order);
        $dbValues['virtuemart_paymentmethod_id'] = $cart->virtuemart_paymentmethod_id;
        $dbValues['paypal_custom']               = $return_context;
        $dbValues['cost_per_transaction']        = $method->cost_per_transaction;
        $dbValues['cost_percent_total']          = $method->cost_percent_total;
        $dbValues['payment_currency']            = $method->payment_currency;
        $dbValues['email_currency']              = $email_currency;
        $dbValues['payment_order_total']         = $totalInPaymentCurrency;
        $dbValues['tax_id']                      = $method->tax_id;
        $this->storePSPluginInternalData($dbValues);

        $billingDetails = $order['details']['BT'];
        $params         = $this->_getParamsData();
        $xml            = new GerencianetXml('<?xml version="1.0" encoding="utf-8"?><cobrancaonline></cobrancaonline>');
        $xml->addChild('token', $params['gerencianet_token']);
        $clientes = $xml->addChild('clientes');
        $cliente  = $clientes->addChild('cliente');
        $cliente->addChild('email', $billingDetails->email);

        $opcionaisCliente = $cliente->addChild('opcionais');

        $nome = $billingDetails->first_name . ' ' . $billingDetails->last_name;
        $opcionaisCliente->addChild('nomeRazaoSocial')->addCData($nome);
        $opcionaisCliente->addChild('cep', str_replace('-', '', $billingDetails->zip));
        $opcionaisCliente->addChild('rua')->addCData($billingDetails->address_1);
        $stateCode = $this->_getColumnValue(
            'virtuemart_states',
            'state_2_code',
            'virtuemart_state_id',
            $billingDetails->virtuemart_state_id
        );
        $opcionaisCliente->addChild('estado', $stateCode);
        $opcionaisCliente->addChild('cidade')->addCData($billingDetails->city);
        $opcionaisCliente->addChild('retorno', 'virtuemart_' . $billingDetails->virtuemart_order_id);

        $phone = $billingDetails->phone_1;
        $phone2 = $billingDetails->phone_2;
        if ($phone2 != '') {
            $phone = $phone2;
        }
		
        $phone = preg_replace("/[()-.\s]/", "", $phone);
        $opcionaisCliente->addChild('cel', $phone);

        $items = $xml->addChild('itens');

        foreach ($order['items'] as $i) {
            $item = $items->addChild('item');
            $item->addChild('descricao')->addCData($i->order_item_name);
            $itemValor = $this->formataValor($method, $i->product_final_price);            
            $item->addChild('valor', $itemValor);
            $item->addChild('qtde', $i->product_quantity);
        }

        $opcionais = $xml->addChild('opcionais');
        $opcionais->addChild('descontoSobreTotal', $this->formataValor($method, $billingDetails->order_discount));

        $frete = $opcionais->addChild('frete');
        $frete->addChild('tipo', 'fixo');
        $frete->addChild('pesoOuValor', $this->formataValor($method, $billingDetails->order_shipment));

        $strXml = $xml->saveXML();

        $response = $this->makeRequest(self::API_URI, array('entrada' => $strXml));

        $application = JFactory::getApplication();
        $xmlResposta = simplexml_load_string($response);
        if ($xmlResposta->statusCod == 2) {
            $this->setIPN($params, $xmlResposta);
            $url = $xmlResposta->resposta->cobrancasGeradas->cliente->cobranca->link;
            $cart->emptyCart();
            $this->abrirURL($url);
        } else {
            if ($xmlResposta->resposta->erro->status == 1012) {
                $url = $xmlResposta->resposta->erro->entrada->emitirCobranca->resposta->cobrancasGeradas->cliente->cobranca->link;
                $cart->emptyCart();
                $this->abrirURL($url);
            } else {
                $msg = $xmlResposta->resposta->erro->statusMsg;

                $application->redirect(
                    JRoute::_('index.php?option=com_virtuemart&view=cart'),
                    "{$msg}",
                    'error'
                );
            }
        }
    }

    public function abrirURL($url)
    {
        $link = "Clique <a href='{$url}' target='_blank'>aqui</a> se não conseguir visualizar a página da cobrança.";
        $html = "
        <script type='text/javascript'>
            setTimeout(
                function() {
                    window.open('{$url}','_blank');
                },
                0
            );
        </script>";
        echo $html . $link;
    }

    protected function makeRequest($url, $data)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 2);
        curl_setopt($ch, CURLOPT_AUTOREFERER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_USERAGENT, $_SERVER['HTTP_USER_AGENT']);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);

        $result = curl_exec($ch);

        if (curl_errno($ch)) {
            throw new Exception('cURL error: ' . curl_errno($ch) . ' - ' . curl_error($ch));
        }

        return $result;
    }

    protected function setIPN($params, $xml)
    {
        $data['chave']    = $xml->resposta->cobrancasGeradas->cliente->cobranca->chave;
        $data['retorno']  = $xml->resposta->cobrancasGeradas->cliente->cobranca->retorno;
        $data['valor']    = $xml->resposta->cobrancasGeradas->cliente->cobranca->valor;
        $data['link']     = $xml->resposta->cobrancasGeradas->cliente->cobranca->link;
        $data['metodo']   = $xml->metodo;
        $data['versao']   = self::$_pluginVersion;
        $data['token']    = $params['gerencianet_token'];
        $data['callback'] = $this->_getNotificationUrl();
        $this->makeRequest(self::DATA_FOR_CALLBACK_URI, $data);

    }

    function plgVmOnPaymentNotification()
    {
        $ipn = $_POST;

        $token   = $ipn['token'];
        $orderid = $ipn['orderId'];
        $params  = $this->_getParamsData();

        if ($token == $params['gerencianet_token']) {
            $payments   = $this->getDatasByOrderId($orderid);
            $model      = VmModel::getModel('orders');
            $method     = $this->getVmPluginMethod($payments[0]->virtuemart_paymentmethod_id);
            $inputOrder = array(
                'order_status'      => $method->status_paid,
                'customer_notified' => true,
                'comments'          => '[Pagamento confirmado pela Gerêncianet]'
            );

            $model->updateStatusForOneOrder($orderid, $inputOrder);

            echo "200";
        }

        exit;
    }

    function setConfigParameterable($paramsFieldName, $varsToPushParam)
    {
        $this->_varsToPushParam = $varsToPushParam;
        $this->_xParams         = $paramsFieldName;
    }

    public function getVmPluginCreateTableSQL()
    {
        return $this->createTableSQL('Payment Gerencianet Table');
    }

    public function getTableSQLFields()
    {
        return array(
            'id'                          => 'int(11) UNSIGNED NOT NULL AUTO_INCREMENT',
            'virtuemart_order_id'         => 'int(11) UNSIGNED DEFAULT NULL',
            'order_number'                => 'char(32) DEFAULT NULL',
            'virtuemart_paymentmethod_id' => 'mediumint(1) UNSIGNED DEFAULT NULL',
            'payment_name'                => 'char(255) NOT NULL DEFAULT \'\' ',
            'payment_order_total'         => 'decimal(15,5) NOT NULL DEFAULT \'0.00000\' ',
            'payment_currency'            => 'char(3) ',
            'cost_per_transaction'        => 'decimal(10,2) DEFAULT NULL',
            'cost_percent_total'          => 'decimal(10,2) DEFAULT NULL',
            'tax_id'                      => 'smallint(11) DEFAULT NULL',
            'reference'                   => 'char(32) DEFAULT NULL'
        );
    }

    private function _getGerencianetVarsToPush()
    {
        return array(
            'gerencianet_token'            => array('', 'string'),
            'gerencianet_charset'          => array('', 'string'),
            'gerencianet_url_notification' => array('', 'string'),
            'payment_logos'                => array('', 'char'),
            'status_waiting_payment'       => array('', 'char'),
            'status_in_analysis'           => array('', 'char'),
            'status_paid'                  => array('', 'char'),
            'status_cancelled'             => array('', 'char')
        );
    }

    public function plgVmDisplayListFEPayment(VirtueMartCart $cart, $selected = 0, &$htmlIn)
    {
        return $this->displayListFE($cart, $selected, $htmlIn);
    }

    protected function displayLogos($logo_list)
    {

        $img = "";

        if (!(empty($logo_list))) {

            $url = JURI::root() . '/media/images/stories/virtuemart/' . $this->_psType . '/';
            if (!is_array($logo_list)) {
                $logo_list = (array)$logo_list;
            }
            foreach ($logo_list as $logo) {
                $alt_text = substr($logo, 0, strpos($logo, '.'));
                $img .= '<span class="vmCartPaymentLogo" ><img align="middle" src="' . $url . $logo . '"  alt="' . $alt_text . '" /></span> ';
            }
        }
        return $img;
    }

    private function _addRequiredClasses()
    {
        if (!class_exists('VirtueMartModelOrders')) {
            require(JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'orders.php');
        }

        if (!class_exists('VirtueMartModelCurrency')) {
            require(JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'currency.php');
        }

        if (!class_exists('TableVendors')) {
            require(JPATH_VM_ADMINISTRATOR . DS . 'tables' . DS . 'vendors.php');
        }

        if (!class_exists('VirtueMartCart')) {
            require(JPATH_VM_SITE . DS . 'helpers' . DS . 'cart.php');
        }

    }

    function plgVmgetPaymentCurrency($virtuemart_paymentmethod_id, &$paymentCurrencyId)
    {

        if (!($method = $this->getVmPluginMethod($virtuemart_paymentmethod_id))) {
            return null; // Another method was selected, do nothing
        }
        if (!$this->selectedThisElement($method->payment_element)) {
            return false;
        }

        $this->getPaymentCurrency($method);
        $paymentCurrencyId = $method->payment_currency;
    }

    function plgVmOnShowOrderBEPayment($virtuemart_order_id, $virtuemart_payment_id)
    {

        if (!$this->selectedThisByMethodId($virtuemart_payment_id)) {
            return null; // Another method was selected, do nothing
        }

        // getting order data from Gerencianet payment table
        $paymentTable = $this->_getGerencianetPaymentData($virtuemart_order_id);
        $this->getPaymentCurrency($paymentTable);

        $html = '<table class="adminlist">' . "\n";
        $html .= $this->getHtmlHeaderBE();
        $html .= $this->getHtmlRowBE('STANDARD_PAYMENT_NAME', $paymentTable->payment_name);
        $html .= $this->getHtmlRowBE(
            'STANDARD_PAYMENT_TOTAL_CURRENCY',
            $paymentTable->payment_order_total . ' ' . $paymentTable->payment_currency
        );
        $html .= '</table>' . "\n";

        return $html;
    }

    protected function checkConditions($cart, $method, $cart_prices)
    {

        $this->convert($method);

        $address = (($cart->ST == 0) ? $cart->BT : $cart->ST);

        $amount      = $cart_prices['salesPrice'];
        $amount_cond = ($amount >= $method->min_amount AND $amount <= $method->max_amount
            OR ($method->min_amount <= $amount AND ($method->max_amount == 0)));

        $countries = array();
        if (!empty($method->countries)) {
            if (!is_array($method->countries)) {
                $countries[0] = $method->countries;
            } else {
                $countries = $method->countries;
            }
        }
        // probably did not gave his BT:ST address
        if (!is_array($address)) {
            $address                          = array();
            $address['virtuemart_country_id'] = 0;
        }

        if (!isset($address['virtuemart_country_id'])) {
            $address['virtuemart_country_id'] = 0;
        }

        if (in_array($address['virtuemart_country_id'], $countries) || count($countries) == 0) {
            if ($amount_cond) {
                return true;
            }
        }

        return false;
    }

    function plgVmOnStoreInstallPaymentPluginTable($jplugin_id)
    {
        return $this->onStoreInstallPluginTable($jplugin_id);
    }

    public function plgVmOnSelectCheckPayment(VirtueMartCart $cart, &$msg)
    {
        return $this->OnSelectCheck($cart);
    }

    public function plgVmonSelectedCalculatePricePayment(VirtueMartCart $cart, array & $cart_prices, &$cart_prices_name)
    {
        return $this->onSelectedCalculatePrice($cart, $cart_prices, $cart_prices_name);
    }

    function plgVmOnCheckAutomaticSelectedPayment(VirtueMartCart $cart, array $cart_prices = array(), &$paymentCounter)
    {
        return $this->onCheckAutomaticSelected($cart, $cart_prices, $paymentCounter);
    }

    public function plgVmOnShowOrderFEPayment($virtuemart_order_id, $virtuemart_paymentmethod_id, &$payment_name)
    {
        $this->onShowOrderFE($virtuemart_order_id, $virtuemart_paymentmethod_id, $payment_name);
    }

    function plgVmonShowOrderPrintPayment($order_number, $method_id)
    {
        return $this->onShowOrderPrint($order_number, $method_id);
    }

    function plgVmDeclarePluginParamsPayment($name, $id, &$data)
    {
        return $this->declarePluginParams('payment', $name, $id, $data);
    }

    function plgVmSetOnTablePluginParamsPayment($name, $id, &$table)
    {
        return $this->setOnTablePluginParams($name, $id, $table);
    }

    function plgVmOnPaymentResponseReceived(&$html)
    {
        // adding required classes
        $this->_addRequiredClasses();

        //We delete the old stuff
        // get the correct cart / session
        $cart = VirtueMartCart::getCart();
        $cart->emptyCart();

        return true;
    }

    private function _getNotificationUrl()
    {
        $notificationUrl = JROUTE::_(
            JURI::root() . 'index.php?option=com_virtuemart&view=pluginresponse&task=pluginnotification&tmpl=component'
        );
        return $notificationUrl;
    }

    private function _getParamsData()
    {
        $paramsData = array();

        $db = JFactory::getDBO();
        $db->setQuery(
            'SELECT `payment_params` FROM `#__virtuemart_paymentmethods` WHERE `payment_element`="gerencianet" '
        );
        $data = explode('|', $db->loadResult());

        foreach ($data as $param) {
            if (!empty($param)) {
                $array_temp                 = explode('=', $param);
                $paramsData[$array_temp[0]] = str_replace('"', '', $array_temp[1]);
            }
        }

        return $paramsData;
    }

    function convert($method)
    {
        $method->min_amount = (float)$method->min_amount;
        $method->max_amount = (float)$method->max_amount;
    }

    private function _getGerencianetPaymentData($virtuemart_order_id)
    {
        $db = JFactory::getDBO();
        $q  = 'SELECT * FROM `' . $this->_tablename . '` ' . 'WHERE `virtuemart_order_id` = ' . $virtuemart_order_id;
        $db->setQuery($q);
        if (!($paymentTable = $db->loadObject())) {
            vmWarn(500, $q . " " . $db->getErrorMsg());
            return '';
        }

        return $paymentTable;
    }

    private function _getColumnValue($table, $select, $where, $value)
    {
        $db  = JFactory::getDbo();
        $sql = "select $select from #__$table where $where=" . $value;
        $db->setQuery($sql);
        return $db->loadResult();
    }
}