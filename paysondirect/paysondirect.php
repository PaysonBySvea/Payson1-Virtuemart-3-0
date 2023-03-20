<?php

defined('_JEXEC') or die('Restricted access');

if (!class_exists('vmPSPlugin')) {
    require(JPATH_VM_PLUGINS . DS . 'vmpsplugin.php');
}

class plgVmPaymentPaysondirect extends vmPSPlugin {

    public $module_vesion = '3.0.9';

    function __construct(& $subject, $config) {

        parent::__construct($subject, $config);
        $this->_loggable = true;
        $this->tableFields = array_keys($this->getTableSQLFields());

        $varsToPush = $this->getVarsToPush();
        $this->setConfigParameterable($this->_configTableFieldName, $varsToPush);
    }

    public function getVmPluginCreateTableSQL() {
        return $this->createTableSQL('Payment payson_order Table');
    }

    /**
     * Fields to create the payment table
     * @return string SQL Fileds
     */
    function getTableSQLFields() {
        $SQLfields = array(
            'id' => 'int(1) unsigned NOT NULL AUTO_INCREMENT',
            'virtuemart_order_id' => 'int(1) UNSIGNED',
            'order_number' => 'char(64)',
            'virtuemart_paymentmethod_id' => 'mediumint(1) UNSIGNED',
            'payment_name' => 'varchar(5000)',
            'payment_order_total' => 'decimal(15,5) NOT NULL DEFAULT \'0.00000\'',
            'payment_currency' => 'char(3)',
            'cost_per_transaction' => 'decimal(10,2)',
            'cost_percent_total' => 'decimal(10,2)',
            'tax_id' => 'smallint(1)',
            'added' => 'datetime',
            'updated' => 'datetime',
            'valid' => 'tinyint(1) NOT NULL',
            'ipn_status' => 'varchar(65)',
            'token' => 'varchar(255)',
            'sender_email' => 'varchar(50)',
            'tracking_id' => 'varchar(100)',
            'type' => 'varchar(50)',
            'purchase_id' => 'varchar(50)',
            'invoice_status' => 'varchar(50)',
            'customer' => 'varchar(50)',
            'shippingAddress_name' => 'varchar(50)',
            'shippingAddress_street_ddress' => 'varchar(60)',
            'shippingAddress_postal_code' => 'varchar(20)',
            'shippingAddress_city' => 'varchar(60)',
            'shippingAddress_country' => 'varchar(60)',
        );

        return $SQLfields;
    }

    function plgVmConfirmedOrder($cart, $order) {

        if (!($method = $this->getVmPluginMethod($order['details']['BT']->virtuemart_paymentmethod_id))) {
            return NULL; // Another method was selected, do nothing
        }
        if (!$this->selectedThisElement($method->payment_element)) {
            return false;
        }
        // 		$params = new JParameter($payment->payment_params);
        $lang = JFactory::getLanguage();
        $filename = 'com_virtuemart';
        $lang->load($filename, JPATH_ADMINISTRATOR);
        $vendorId = 0;
        $langCode = explode('-', $lang->get('tag'));

        $currencyModel = VmModel::getModel('Currency');
        $currencyToPayson = $currencyModel->getCurrency($cart->pricesCurrency);
        $user_billing_info = $order['details']['BT'];
        $user_shipping_info = ((isset($order['details']['ST'])) ? $order['details']['ST'] : $order['details']['BT']);
        $paymentCurrency = CurrencyDisplay::getInstance();
        $totalInPaymentCurrency = round($paymentCurrency->convertCurrencyTo($cart->pricesCurrency, $order['details']['BT']->order_total, FALSE), 2);

        $ipn_url = JROUTE::_(JURI::root() . 'index.php?option=com_virtuemart&view=pluginresponse&task=pluginnotification&on=' . $order['details']['BT']->virtuemart_order_id .
                        '&pm=' . $order['details']['BT']->virtuemart_paymentmethod_id);
        $return_url = JROUTE::_(JURI::root() . 'index.php?option=com_virtuemart&view=pluginresponse&task=pluginresponsereceived&on=' . $order['details']['BT']->virtuemart_order_id .
                        '&pm=' . $order['details']['BT']->virtuemart_paymentmethod_id);
        $cancel_url = JROUTE::_(JURI::root() . 'index.php?option=com_virtuemart&view=pluginresponse&task=pluginUserPaymentCancel&on=' . $order['details']['BT']->virtuemart_order_id .
                        '&pm=' . $order['details']['BT']->virtuemart_paymentmethod_id);
        $orderNumber = $order['details']['BT']->order_number;
        $this->paysonApi(
                $method, $totalInPaymentCurrency, $this->currencyPaysondirect($currencyToPayson->currency_code_3), $this->languagePaysondirect($langCode[0]), $user_billing_info, $user_shipping_info, $return_url, $ipn_url, $cancel_url, $order['details']['BT']->virtuemart_order_id, $orderNumber, $this->setOrderItems($cart, $order), $order['details']['BT']->order_payment
        );

        if (!class_exists('VirtueMartModelOrders')) {
            require(JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'orders.php');
        }
        $this->getPaymentCurrency($method, true);

        // END printing out HTML Form code (Payment Extra Info)
        $q = 'SELECT `currency_code_3` FROM `#__virtuemart_currencies` WHERE `virtuemart_currency_id`="' . $method->payment_currency . '" ';
        $db = JFactory::getDBO();
        $db->setQuery($q);
        $currency_code_3 = $db->loadResult();
        $paymentCurrency = CurrencyDisplay::getInstance($method->payment_currency);
        $totalInPaymentCurrency = round($paymentCurrency->convertCurrencyTo($method->payment_currency, $order['details']['BT']->order_total, false), 2);
        $cd = CurrencyDisplay::getInstance($cart->pricesCurrency);

        $dbValues['payment_name'] = $this->renderPluginName($method) . '<br />' . $method->payment_info;
        $dbValues['order_number'] = $order['details']['BT']->order_number;
        $dbValues['virtuemart_paymentmethod_id'] = $order['details']['BT']->virtuemart_paymentmethod_id;
        $dbValues['cost_per_transaction'] = $method->cost_per_transaction;
        $dbValues['cost_percent_total'] = $method->cost_percent_total;
        $dbValues['payment_currency'] = $currency_code_3;
        $dbValues['payment_order_total'] = $totalInPaymentCurrency;
        $dbValues['tax_id'] = $method->tax_id;
        $this->storePSPluginInternalData($dbValues);
        $modelOrder = VmModel::getModel('orders');
        $order['order_status'] = 'P';
        $order['customer_notified'] = 0;
        $order['comments'] = 'Payson Direkt';
        $modelOrder->updateStatusForOneOrder($order['details']['BT']->virtuemart_order_id, $order, true);
        $cart->_confirmDone = FALSE;
        $cart->_dataValidated = FALSE;
        $cart->setCartIntoSession();
    }

    /**
     * Display stored payment data for an order
     *
     */
    function plgVmOnShowOrderBEPayment($virtuemart_order_id, $virtuemart_payment_id) {
        if (!$this->selectedThisByMethodId($virtuemart_payment_id)) {
            return NULL; // Another method was selected, do nothing
        }

        if (!($paymentTable = $this->getDataByOrderId($virtuemart_order_id))) {
            return NULL;
        }
        $html = '<table class="adminlist">' . "\n";
        $html .= $this->getHtmlHeaderBE();
        $html .= $this->getHtmlRowBE('PAYSONDIRECT_PAYMENT_NAME', $paymentTable->payment_name);
        $html .= $this->getHtmlRowBE('PAYSONDIRECT_PAYMENT_TOTAL_CURRENCY', $paymentTable->payment_order_total . ' ' . $paymentTable->payment_currency);
        $html .= '</table>' . "\n";
        return $html;
    }

    function getCosts(VirtueMartCart $cart, $method, $cart_prices) {
        if (isset($method->cost_percent_total) && ($method->cost_per_transaction)) {
            //$test=TablePaymentmethods::getInstance($method->cost_percent_total);
            if (preg_match('/%$/', $method->cost_percent_total)) {
                $cost_percent_total = substr($method->cost_percent_total, 0, -1);
            } else {
                $cost_percent_total = $method->cost_percent_total;
            }

            return ($method->cost_percent_total + ($cart_prices['salesPrice'] * $cost_percent_total * 0.01));
        }
    }

    protected function checkConditions($cart, $method, $cart_prices) {
        $this->convert($method);
        $address = (($cart->ST == 0) ? $cart->BT : $cart->ST);

        $amount = $cart_prices['salesPrice'];
        $amount_cond = ($amount >= $method->min_amount AND $amount <= $method->max_amount OR ( $method->min_amount <= $amount AND ( $method->max_amount == 0)));
        if (!$amount_cond) {
            return false;
        }
        $countries = array();
        if (!empty($method->countries)) {
            if (!is_array($method->countries)) {
                $countries[0] = $method->countries;
            } else {
                $countries = $method->countries;
            }
        }

        //Support only SEK and EUR
        $paymentCurrency = CurrencyDisplay::getInstance();
        if (strtoupper($paymentCurrency->ensureUsingCurrencyCode($cart->pricesCurrency)) == 'SEK' || strtoupper($paymentCurrency->ensureUsingCurrencyCode($cart->pricesCurrency)) == 'EUR') {
            return true;
        }
        if (!is_array($address)) {
            $address = array();
            $address['virtuemart_country_id'] = 0;
        }

        if (!isset($address['virtuemart_country_id'])) {
            $address['virtuemart_country_id'] = 0;
        }
        if (count($countries) == 0 || in_array($address['virtuemart_country_id'], $countries) || count($countries) == 0) {
            return true;
        }

        return false;
    }

    function convert($method) {
        $method->min_amount = (float) $method->min_amount;
        $method->max_amount = (float) $method->max_amount;
    }

    function plgVmOnStoreInstallPaymentPluginTable($jplugin_id) {
        return $this->onStoreInstallPluginTable($jplugin_id);
    }

    public function plgVmOnSelectCheckPayment(VirtueMartCart $cart, &$msg) {
        //efter att man v�ljer payson hamnar man h�r
        return $this->OnSelectCheck($cart);
    }

    public function plgVmDisplayListFEPayment(VirtueMartCart $cart, $selected = 0, &$htmlIn) {
        //DisplayList Payment
        return $this->displayListFE($cart, $selected, $htmlIn);
    }

    public function plgVmonSelectedCalculatePricePayment(VirtueMartCart $cart, array &$cart_prices, &$cart_prices_name) {
        //Calculate Price Payment efter DisplayList Payment  Obs!
        return $this->onSelectedCalculatePrice($cart, $cart_prices, $cart_prices_name);
    }

    function plgVmgetPaymentCurrency($virtuemart_paymentmethod_id, &$paymentCurrencyId) {
        //i start
        if (!($method = $this->getVmPluginMethod($virtuemart_paymentmethod_id))) {
            return NULL; // Another method was selected, do nothing
        }
        if (!$this->selectedThisElement($method->payment_element)) {
            return false;
        }
        $this->getPaymentCurrency($method);

        $paymentCurrencyId = $method->payment_currency;
        return;
    }

    function plgVmOnCheckAutomaticSelectedPayment(VirtueMartCart $cart, array $cart_prices = array(), &$paymentCounter) {
        return $this->onCheckAutomaticSelected($cart, $cart_prices, $paymentCounter);
    }

    public function plgVmOnShowOrderFEPayment($virtuemart_order_id, $virtuemart_paymentmethod_id, &$payment_name) {
        $this->onShowOrderFE($virtuemart_order_id, $virtuemart_paymentmethod_id, $payment_name);
    }

    function plgVmonShowOrderPrintPayment($order_number, $method_id) {
        return $this->onShowOrderPrint($order_number, $method_id);
    }

    function plgVmDeclarePluginParamsPaymentVM3(&$data) {
        return $this->declarePluginParams('payment', $data);
    }

    function plgVmDeclarePluginParamsPayment($name, $id, &$data) {
        return $this->declarePluginParams('payment', $name, $id, $data);
    }

    function plgVmSetOnTablePluginParamsPayment($name, $id, &$table) {
        return $this->setOnTablePluginParams($name, $id, $table);
    }

    function plgVmOnPaymentResponseReceived(&$html) {
        if (!class_exists('VirtueMartCart')) {
            require(JPATH_VM_SITE . DS . 'helpers' . DS . 'cart.php');
        }
        if (!class_exists('shopFunctionsF')) {
            require(JPATH_VM_SITE . DS . 'helpers' . DS . 'shopfunctionsf.php');
        }
        if (!class_exists('VirtueMartModelOrders')) {
            require(VMPATH_ADMIN . DS . 'models' . DS . 'orders.php');
        }
        
        $virtuemart_paymentmethod_id = JRequest::getInt('pm', 0);
        $order_number = JRequest::getString('on', 0);

        // the payment itself should send the parameter needed.
        if (!($method = $this->getVmPluginMethod($virtuemart_paymentmethod_id))) {
            return NULL;
        } // Another method was selected, do nothing

        if (!$this->selectedThisElement($method->payment_element)) {
            return NULL;
        }

        $q = 'SELECT * FROM `' . $this->_tablename . '` WHERE `token`="' . JRequest::getString('TOKEN') . '" ';
        $db = JFactory::getDBO();
        $db->setQuery($q);
        $sgroup = $db->loadAssoc();
        $api = $this->getAPIInstance($method);
        $paymentDetails = $api->paymentDetails(new PaymentDetailsData(JRequest::getString('TOKEN')))->getPaymentDetails();

        $modelOrder = VmModel::getModel('orders');
        $order = $modelOrder->getOrder($order_number);
        
        if ($paymentDetails->getStatus() == 'COMPLETED' && $paymentDetails->getType() == 'TRANSFER') {
            $html = $this->renderByLayout('post_payment', array(
                'order_number' => $order['details']['BT']->order_number,
                'order_pass' => $order['details']['BT']->order_pass,
                'payment_name' => $this->renderPluginName($method)	
            ));
            $order['customer_notified'] = 1;
            // Do not send email if order exists and status is approved
            if ($order['details']['BT'] && ($order['details']['BT']->order_status == $method->payment_approved_status)) {
                $order['customer_notified'] = 0;
            }
            $order['order_status'] = $method->payment_approved_status;
            $order['comments'] = 'Paysons-ref: ' . $paymentDetails->getPurchaseId();
            $modelOrder->updateStatusForOneOrder($order['details']['BT']->virtuemart_order_id, $order, TRUE);

            $cart = VirtueMartCart::getCart();
            $cart->emptyCart();
            vRequest::setVar ('html', $html);
            return TRUE;
        }
        
        if ($paymentDetails->getStatus() == 'ERROR' || $paymentDetails->getStatus() == 'REVERSALERROR' || $paymentDetails->getStatus() == 'ABORTED') {
            $order['order_status'] = $method->payment_declined_status;
            $order['customer_notified'] = 0;
            $order['comments'] = 'Paysons-ref: ' . $paymentDetails->getPurchaseId();
            $modelOrder->updateStatusForOneOrder($order['details']['BT']->virtuemart_order_id, $order, FALSE);
            $mainframe = JFactory::getApplication();
            $mainframe->redirect(JRoute::_('index.php?option=com_virtuemart&view=cart'), $html);
        } else {
            return FALSE;
        }
    }

    function paysonApi($method, $amount, $currency, $langCode, $user_billing_info, $user_shipping_info, $return_url, $ipn_url, $cancel_url, $virtuemart_order_id, $orderNumber, $orderItems) {
        require_once (JPATH_ROOT . DS . 'plugins' . DS . 'vmpayment' . DS . 'paysondirect' . DS . 'payson' . DS . 'paysonapi.php');

        $api = $this->getAPIInstance($method);

        if ($method->sandbox) {
            $receiver = new Receiver('testagent-checkout2@payson.se', $amount);
            $sender = new Sender('test@payson.se', $user_billing_info->first_name, $user_billing_info->last_name);
        } else {
            $receiver = new Receiver(trim($method->seller_email), $amount);
            $sender = new Sender($user_billing_info->email, $user_billing_info->first_name, $user_billing_info->last_name);
        }

        $receivers = array($receiver);
        
        $payData = new PayData($return_url, $cancel_url, $ipn_url, (isset(VmModel::getModel('vendor')->getVendor()->vendor_store_name) != null && strlen(VmModel::getModel('vendor')->getVendor()->vendor_store_name) <= 110 ? VmModel::getModel('vendor')->getVendor()->vendor_store_name : JURI::root()) . ' Order: ' . $orderNumber, $sender, $receivers);
        $payData->setCurrencyCode($currency);
        $payData->setLocaleCode($langCode);

        $payData->setOrderItems($orderItems);
        $constraints = array($method->paymentmethod);
        $payData->setFundingConstraints($constraints);
        $payData->setGuaranteeOffered('NO');


        $payData->setTrackingId(md5($method->secure_word) . '1-' . $virtuemart_order_id);
        $payResponse = $api->pay($payData);

        if ($method->logg) {
            $this->debugLog($this->module_vesion, "Payson Direct", "Module Version");
            $this->debugLog($amount, "Amount", "PaysonAPI");
            $this->debugLog($orderItems, "Payment details", "PaysonAPI");
        }

        if ($payResponse->getResponseEnvelope()->wasSuccessful()) {  //ack = SUCCESS och token  = token = N�got
            //return the url: https://www.payson.se/paysecure/?token=#
            header("Location: " . $api->getForwardPayUrl($payResponse));
        } else {
            if ($method->logg) {
                $error = $payResponse->getResponseEnvelope()->getErrors();
                $this->debugLog($error, "paysonApi", "error payson direct");
            }
            $mainframe = JFactory::getApplication();
            $mainframe->redirect(JRoute::_('index.php?option=com_virtuemart&view=cart'));
        }
    }
    
    function plgVmOnPaymentNotification() {
        require_once (JPATH_ROOT . DS . 'plugins' . DS . 'vmpayment' . DS . 'paysondirect' . DS . 'payson' . DS . 'paysonapi.php');
        $order_number = JRequest::getString('on', 0);
        $virtuemart_paymentmethod_id = JRequest::getInt('pm', 0);

        $vendorId = 0;
        if (!($method = $this->getVmPluginMethod($virtuemart_paymentmethod_id))) {
            return NULL; // Another method was selected, do nothing
        }
        if (!$this->selectedThisElement($method->payment_element)) {
            return FALSE;
        }

        $postData = file_get_contents("php://input");

        // Set up API
        $credentials = new PaysonCredentials(trim($method->agent_id), trim($method->md5_key));
        $api = new PaysonApi($credentials);
        $response = $api->validate($postData);

        if ($method->logg) {
            $this->debugLog($response->getPaymentDetails()->getPurchaseId(), "Paysons-ref", "PaysonIPN Notification");
            $this->debugLog($response->getPaymentDetails()->getStatus(), "status", "PaysonIPN Notification");
        }

        if ($response->isVerified()) {
            $salt = explode("-", $response->getPaymentDetails()->getTrackingId());
            if ($salt[0] == (md5($method->secure_word) . '1')) {
                $response->getPaymentDetails();
                $database = JFactory::getDBO();
                $database->setQuery("UPDATE`" . $this->_tablename . "`SET 
                        `token`                        ='" . addslashes($response->getPaymentDetails()->getToken()) . "', 
                        `ipn_status`		       ='" . addslashes($response->getPaymentDetails()->getStatus()) . "', 
                        `tracking_id`		       ='" . $response->getPaymentDetails()->getTrackingId() . "', 
                        `sender_email`		       ='" . addslashes($response->getPaymentDetails()->getSenderEmail()) . "', 
                        `type`                         ='" . addslashes($response->getPaymentDetails()->getType()) . "', 
                        `customer`                     ='" . addslashes($response->getPaymentDetails()->getCustom()) . "', 
                        `purchase_id`                  ='" . addslashes($response->getPaymentDetails()->getPurchaseId()) . "', 
                        `sender_email`                 ='" . addslashes($response->getPaymentDetails()->getSenderEmail()) . "',  
                        `shippingAddress_name`         ='" . addslashes($response->getPaymentDetails()->getShippingAddressName()) . "', 
                        `shippingAddress_street_ddress`='" . addslashes($response->getPaymentDetails()->getShippingAddressStreetAddress()) . "', 
                        `shippingAddress_postal_code`  ='" . addslashes($response->getPaymentDetails()->getShippingAddressPostalCode()) . "', 
                        `shippingAddress_city`	       ='" . addslashes($response->getPaymentDetails()->getShippingAddressCity()) . "', 
                        `shippingAddress_country`      ='" . addslashes($response->getPaymentDetails()->getShippingAddressCountry()) . "' 
                        WHERE  `virtuemart_order_id`   =" . $order_number);
                $database->query();

                if ($response->getPaymentDetails()->getStatus() == 'COMPLETED' && $response->getPaymentDetails()->getType() == 'TRANSFER') {
                    $payment_name = $this->renderPluginName($method);
                    $modelOrder = VmModel::getModel('orders');
                    $orderExists = $modelOrder->getOrder($order_number);
                    
                    $order['customer_notified'] = 1;
                    // Do not send email if order exists and status is approved
                    if ($orderExists['details']['BT'] && ($orderExists['details']['BT']->order_status == $method->payment_approved_status)) {
                        $order['customer_notified'] = 0;
                    }
                    $order['order_status'] = $method->payment_approved_status;
                    $order['comments'] = 'Paysons-ref: ' . $response->getPaymentDetails()->getPurchaseId();
                    $modelOrder->updateStatusForOneOrder($salt[count($salt) - 1], $order, TRUE);
                    $cart = VirtueMartCart::getCart();
                    $cart->emptyCart();
                    return TRUE;
                }
                
                if ($response->getPaymentDetails()->getStatus() == 'ERROR' || $response->getPaymentDetails()->getStatus() == 'REVERSALERROR' || $response->getPaymentDetails()->getStatus() == 'ABORTED') {
                    $payment_name = $this->renderPluginName($method);
                    $modelOrder = VmModel::getModel('orders');
                    $order['order_status'] = $method->payment_declined_status;
                    $order['customer_notified'] = 0;
                    $order['comments'] = 'Paysons-ref: ' . $response->paymentDetails->getPurchaseId();
                    $modelOrder->updateStatusForOneOrder($salt[count($salt) - 1], $order, FALSE);
                }
            }
        }
    }

    public function getAPIInstance($method) {
        require_once (JPATH_ROOT . DS . 'plugins' . DS . 'vmpayment' . DS . 'paysondirect' . DS . 'payson' . DS . 'paysonapi.php');

        if ($method->sandbox) {
            $credentials = new PaysonCredentials(4, '2acab30d-fe50-426f-90d7-8c60a7eb31d4', null, 'payson_virtuemart|' . $this->module_vesion . '|' . VmConfig::getInstalledVersion());
        } else {
            $credentials = new PaysonCredentials(trim($method->agent_id), trim($method->md5_key), null, 'payson_virtuemart|' . $this->module_vesion . '|' . VmConfig::getInstalledVersion());
        }

        $api = new PaysonApi($credentials, $method->sandbox);

        return $api;
    }

    function getTaxRate($product_tax_id, $product_tax) {
        //echo $product_tax; exit;
        if (isset($product_tax_id) && $product_tax > 0) {
            $TaxId = (ShopFunctions::getTaxByID($product_tax_id));
            //echo '<pre>';print_r($TaxId);echo '</pre>nada';exit;
            if (isset($TaxId['calc_value']) && $TaxId['calc_value'] > 0) {
                return ($TaxId['calc_value'] / 100);
            } else {
                return 0;
            }
        }
        return 0;
    }

    function plgVmOnUserPaymentCancel() {
        if (!class_exists('VirtueMartCart')) {
            require(JPATH_VM_SITE . DS . 'helpers' . DS . 'cart.php');
        }

        if (!class_exists('shopFunctionsF')) {
            require(JPATH_VM_SITE . DS . 'helpers' . DS . 'shopfunctionsf.php');
        }
        if (!class_exists('VirtueMartModelOrders')) {
            require(JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'orders.php');
        }

        $virtuemart_paymentmethod_id = JRequest::getInt('pm', 0);
        $order_number = JRequest::getString('on', 0);

        // the payment itself should send the parameter needed.
        if (!($method = $this->getVmPluginMethod($virtuemart_paymentmethod_id))) {
            return NULL;
        } // Another method was selected, do nothing

        if (!$this->selectedThisElement($method->payment_element)) {
            return NULL;
        }


        $payment_name = $this->renderPluginName($method);
        $modelOrder = VmModel::getModel('orders');
        $order['order_status'] = 'X';
        $order['customer_notified'] = 0;
        $order['comments'] = 'Cancel payment';
        $modelOrder->updateStatusForOneOrder($order_number, $order, TRUE);
    }

    function setOrderItems($cart, $order) {

        require_once (JPATH_ROOT . DS . 'plugins' . DS . 'vmpayment' . DS . 'paysondirect' . DS . 'payson' . DS . 'paysonapi.php');

        $paymentCurrency = CurrencyDisplay::getInstance();
        $orderItems = array();
        foreach ($cart->products as $key => $product) {
            $i = 0;
            //Check if there is a generic tax, which is related to a productCategory
            if (($cart->pricesUnformatted[$key]['product_tax_id'] == 0) && ($cart->pricesUnformatted[$key]['taxAmount'] > 0)) {
                $taxId = 0;
                //Checks what type of tax has been registered to the selected orderitem, if the array is populated get that id and use $this->getTaxRate to fetch the tax rate
                if ($cart->cartPrices[$key]['DBTax']) {
                    $taxId = key($cart->cartPrices[$key]['DBTax']);
                } elseif ($cart->cartPrices[$key]['Tax']) {
                    $taxId = key($cart->cartPrices[$key]['Tax']);
                } elseif ($cart->cartPrices[$key]['VatTax']) {
                    $taxId = key($cart->cartPrices[$key]['VatTax']);
                } elseif ($cart->cartPrices[$key]['DATax']) {
                    $taxId = key($cart->cartPrices[$key]['DATax']);
                }
                $orderItems[] = new OrderItem(
                        substr(strip_tags($product->product_name), 0, 127), strtoupper($paymentCurrency->ensureUsingCurrencyCode($product->product_currency)) != 'SEK' ? $paymentCurrency->convertCurrencyTo($paymentCurrency->getCurrencyIdByField('SEK'), $cart->pricesUnformatted[$key]['discountedPriceWithoutTax'], FALSE) :
                                $cart->pricesUnformatted[$key]['discountedPriceWithoutTax'], $product->quantity, $this->getTaxRate($taxId, $cart->pricesUnformatted[$key]['taxAmount']), $product->product_sku != Null ? $product->product_sku : 'Product sku'
                );
            } else {
                $orderItems[] = new OrderItem(
                        substr(strip_tags($product->product_name), 0, 127), strtoupper($paymentCurrency->ensureUsingCurrencyCode($product->product_currency)) != 'SEK' ? $paymentCurrency->convertCurrencyTo($paymentCurrency->getCurrencyIdByField('SEK'), $cart->pricesUnformatted[$key]['discountedPriceWithoutTax'], FALSE) :
                                $cart->pricesUnformatted[$key]['discountedPriceWithoutTax'], $product->quantity, $this->getTaxRate($cart->pricesUnformatted[$key]['product_tax_id'], $cart->pricesUnformatted[$key]['taxAmount']), $product->product_sku != Null ? $product->product_sku : 'Product sku'
                );
                $i++;
            }
        }

        if ($order['details']['BT']->order_shipment >> 0) {
            $shipment_tax = (100 * $order['details']['BT']->order_shipment_tax) / $order['details']['BT']->order_shipment;
            $orderItems[] = new OrderItem('Frakt', $paymentCurrency->convertCurrencyTo($cart->pricesCurrency, $order['details']['BT']->order_shipment, FALSE), 1, $shipment_tax / 100, 9998);
        }

        if ($order['details']['BT']->coupon_discount != 0) {
            $orderItems[] = new OrderItem('Rabatt', $paymentCurrency->convertCurrencyTo($cart->pricesCurrency, $order['details']['BT']->coupon_discount, FALSE), 1, 0, 'Rabatt');
        }
        return $orderItems;
    }

    public function languagePaysondirect($langCode) {
        switch (strtoupper($langCode)) {
            case "SV":
                return "SV";
            case "FI":
                return "FI";
            default:
                return "EN";
        }
    }

    public function currencyPaysondirect($currency) {
        switch (strtoupper($currency)) {
            case "SEK":
                return "SEK";
            default:
                return "EUR";
        }
    }

}

// No closing tag
