<?php
require_once JPATH_SITE.'/functions.php';
if (!defined('_VALID_MOS') && !defined('_JEXEC'))
	die('Direct Access to ' . basename(__FILE__) . ' is not allowed.');

if (!class_exists('vmPSPlugin'))
	require(JPATH_VM_PLUGINS . DS . 'vmpsplugin.php');

if (!class_exists('VmConfig'))
	require(JPATH_ADMINISTRATOR . DS . 'components' . DS . 'com_virtuemart' . DS . 'helpers' . DS . 'config.php');

VmConfig::loadConfig();

if (!class_exists('VmModel'))
	require(JPATH_ADMINISTRATOR . DS . 'components' . DS . 'com_virtuemart' . DS . 'helpers' . DS . 'vmmodel.php');

if (!class_exists('VirtueMartModelOrders')) {
	require(JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'orders.php');
}

class plgVmPaymentCloudKassir extends vmPSPlugin
{

	public $methodId;

	function __construct(&$subject, $config)
	{
		parent::__construct($subject, $config);
		$lang = JFactory::getLanguage();
		$this->_loggable = true;
		$this->tableFields = array_keys($this->getTableSQLFields());

		if (version_compare(JVM_VERSION, '3', 'ge')) {
			$varsToPush = $this->getVarsToPush();
		} else {
			$varsToPush = array(
				'public_id' => array('', 'string'),
				'api_password' => array('', 'string'),
				'tax_system' => array('', 'char'),
				'status_success' => array('', 'char'),
			);
		}
		$this->setConfigParameterable($this->_configTableFieldName, $varsToPush);
	}
	/**
	 * Срабатывает по урлу ?option=com_virtuemart&view=pluginresponse&task=pluginresponsereceived
	 * @param $html
	 * @param $d
	 * @return mixed
	 */
	function plgVmOnPaymentResponseReceived(&$html)
	{
		/**
		 * URL для получения ответа от сервера что чек отправлен
		 * /index.php?option=com_virtuemart&view=pluginresponse&task=pluginresponsereceived&cloudpayments_receipt
		 * /ru/?option=com_virtuemart&view=pluginresponse&task=pluginresponsereceived&cloudpayments_receipt
		 */
		if (isset($_GET['cloudpayments_receipt'])) {
			$this->CheckAllowedIps();
			$oOrder = $this->CheckDataFromSystemAndGetOrder();
			$method = $this->getVmPluginMethod($oOrder['details']['BT']->virtuemart_paymentmethod_id);
			$this->CheckHMAC($method->api_password);
			$sType = $_POST['Type'];
			if ($sType != 'Income' && $sType != 'IncomeReturn') exit('{"error":"unknown receipt type"}');
			$oDb = JFactory::getDBO();
			$sSql = "SELECT `id` FROM `#__virtuemart_payment_plg_cloudkassir` WHERE `type` = '".$sType."' AND `order_number` = '".$oOrder['details']['BT']->order_number."' LIMIT 1";
			$oDb->setQuery($sSql);
			$iId = $oDb->loadResult();
			if ($iId) {
				$sSql = "UPDATE `#__virtuemart_payment_plg_cloudkassir` SET  
									`time` = " . time() . ",
									`answer_received` = 1,
									`data` = '" . serialize($_POST) . "'  
								WHERE `id` = " . $iId;
				$oDb->setQuery($sSql);
				$oDb->execute();
			} else {
				$aData['order_number'] = $oOrder['details']['BT']->order_number;
				$aData['virtuemart_order_id'] = $oOrder['details']['BT']->virtuemart_order_id;
				$aData['type'] = $sType;
				$aData['time'] = time();
				$aData['answer_received'] = 1;
				$this->storePSPluginInternalData($aData);
			}
			exit('{"code":0}');
		}
		if (isset($_GET['cloudkassir_send'])) {
			$iVirtOrderId = (int) $_GET['cloudkassir_send'];
			$oOrderModel = VmModel::getModel('orders');
			$oOrder = $oOrderModel->getOrder($iVirtOrderId);
			$this->SendReceipt($oOrder, 'IncomeReturn');
			return JFactory::getApplication()->redirect($_SERVER['HTTP_REFERER']);
		}
	}

	private function SendReceipt($oOrder, $sType){
		$oDb = JFactory::getDBO();
		$sSql = "SELECT * FROM `#__virtuemart_payment_plg_cloudkassir` WHERE `type` = '".$sType."' AND `order_number` = '".$oOrder['details']['BT']->order_number."' LIMIT 1";
		$oDb->setQuery($sSql);
		$oRow = $oDb->loadObject();
		if ($oRow && $oRow->time && (time() - $oRow->time) < 120) return false; // если уже отправлен запрос системе на отправку чека
		if ($oRow && $oRow->answer_received) return false;
		$iCurrency = 0;
		$oCurrencyModel = VmModel::getModel('currency');
		$aItems = [];
		foreach ($oOrder['items'] as $oProduct) {
			$currency = $oCurrencyModel->getCurrency($oProduct->allPrices[0]['product_currency']);
//					$sDescription .= $oProduct->product_name . ': ' . $oProduct->product_quantity . 'x' .round($oProduct->product_final_price, 2) .$currency->currency_code_3.
//						' (в т.ч. налог ' . round($oProduct->product_tax*$oProduct->product_quantity,2) . $currency->currency_code_3.'), ';
			$sCurrencyName = $currency->currency_code_3;
			if ($iCurrency == 0) $iCurrency = $currency->virtuemart_currency_id;
			if ($iCurrency > 0 && $iCurrency != $currency->virtuemart_currency_id) exit('All products must have only one currence for paying');
			$aTax = current($oProduct->prices['Tax']);
			$aItems[] = [
				"label" => $oProduct->product_name, //наименование товара
				"price" => round($oProduct->product_final_price,2), //цена
				"quantity" => $oProduct->product_quantity, //количество
				"amount" => round($oProduct->product_subtotal_with_tax,2), //сумма
				"vat" => (isset($aTax[1]) ? round($aTax[1], 2) : 0) //ставка НДС
			];
		}
		/**
		 * Доставка
		 */
		if ($oOrder['details']['BT']->order_billTax && $oOrder['details']['BT']->order_shipment) {
			$aTax = current(json_decode($oOrder['details']['BT']->order_billTax));
			$aItems[] = [
				"label" => 'Доставка',
				"price" => round($oOrder['details']['BT']->order_shipment + $oOrder['details']['BT']->order_shipment_tax,2), //цена
				"quantity" => 1, //количество
				"amount" => round($oOrder['details']['BT']->order_shipment + $oOrder['details']['BT']->order_shipment_tax,2), //сумма
				"vat" => round($aTax->calc_value,2) //ставка НДС
			];
		}
		$oCloudKassirMethod = $this->getCloudKassirMethod();
		$sPhone = $oOrder['details']['BT']->phone_1 ? $oOrder['details']['BT']->phone_1 : ($oOrder['details']['BT']->phone_2 ? $oOrder['details']['BT']->phone_2 : '');
		$aData = [
			'Inn' => $oCloudKassirMethod->inn,
			'InvoiceId' => $oOrder['details']['BT']->order_number, //номер заказа, необязательный
			'AccountId' => $sPhone,
			'Type' => $sType,
			'CustomerReceipt' => [
				'Items' => $aItems,
				'taxationSystem' => $oCloudKassirMethod->tax_system,
				'email' => $oOrder['details']['BT']->email,
				'phone' => $sPhone
			]
		];
		vmInfo('Отправлен запрос на отправку чека '.($sType == 'Income' ? 'прихода' : 'возврата'));
		$this->makeRequest($oCloudKassirMethod, 'kkt/receipt', $aData);
		if (!$oRow) {
			// создаем запись что отправили запрос.
			$aData['order_number'] = $oOrder['details']['BT']->order_number;
			$aData['virtuemart_order_id'] = $oOrder['details']['BT']->virtuemart_order_id;
			$aData['type'] = $sType;
			$aData['time'] = time();
			$this->storePSPluginInternalData($aData);
		} else {
			//
			$sSql = "UPDATE `#__virtuemart_payment_plg_cloudkassir` SET  
									`time` = " . time() . "
								WHERE `order_number` = '" . $oOrder->order_number . "' AND `type` = '".$sType."'";
			$oDb->setQuery($sSql);
			$oDb->execute();
		}
	}

	/**
	 * Содержимое заказа для пользователя
	 * @param $virtuemart_order_id
	 * @param $virtuemart_paymentmethod_id
	 * @param $payment_name
	 * @return null
	 */
	public function plgVmOnShowOrderFEPayment($virtuemart_order_id, $virtuemart_paymentmethod_id, &$payment_name)
	{
		return null;
	}

	/**
	 * Проверяем айпи адреса с которых пришли запросы
	 */
	private function CheckAllowedIps()
	{
		return true;
		if (!in_array($_SERVER['REMOTE_ADDR'], ['130.193.70.192', '185.98.85.109'])) throw new Exception('CloudPayments: Hacking atempt!');
	}

	/**
	 * Проверяем коректность запроса
	 */
	private function CheckHMAC($sSercet)
	{
		if (!$sSercet) throw new Exception('CloudPayments: Sercet key is not defined');
		$sPostData    = file_get_contents('php://input');
		$sCheckSign   = base64_encode(hash_hmac('SHA256', $sPostData, $sSercet, true));
		$sRequestSign = isset($_SERVER['HTTP_CONTENT_HMAC']) ? $_SERVER['HTTP_CONTENT_HMAC'] : '';
		if ($sCheckSign !== $sRequestSign) {
			throw new Exception('CloudPayments: Hacking atempt!');
		};
		return true;
	}

	/**
	 * Проверяем данные которые пришли от платежной системе и возвращаем заказ
	 * @return mixed
	 */
	private function CheckDataFromSystemAndGetOrder()
	{
		/**
		 * Делаем проверку с системой платежей
		 */
		if (!isset($_POST['InvoiceId'])) {
			exit('{"code":13}'); // Платеж не может быть принят
		} else {
			/**
			 * Получаем заказ
			 */
			$oOrderModel = VmModel::getModel('orders');
			$iOrderId = $oOrderModel->getOrderIdByOrderNumber($_POST['InvoiceId']);
			if (!$iOrderId) exit('CloudKassir error: order not found');
			$oOrder = $oOrderModel->getOrder($iOrderId);
			return $oOrder;
		}
	}
	/**
	 * Срабатывает при сохранении в админке. С его помощью появляется таблица в БД
	 * Create the table for this plugin if it does not yet exist.
	 * This functions checks if the called plugin is active one.
	 * When yes it is calling the standard method to create the tables
	 * @author Valérie Isaksen
	 *
	 */
	public function plgVmOnStoreInstallPaymentPluginTable($jplugin_id)
	{
		return $this->onStoreInstallPluginTable($jplugin_id);
	}

	/**
	 * Срабатывает после того как пользователь нажал кнопку подтвердить в корзине
	 * @param $cart
	 * @param $order
	 * @return bool|null
	 */
	public function plgVmConfirmedOrder($cart, $order)
	{
		return null;
	}

	/**
	 * Срабатывает до показа корзины
	 * plgVmDisplayListFEPayment
	 * This event is fired to display the pluginmethods in the cart (edit shipment/payment) for exampel
	 *
	 * @param object $cart Cart object
	 * @param integer $selected ID of the method selected
	 * @return boolean True on succes, false on failures, null when this plugin was not selected.
	 * On errors, JError::raiseWarning (or JError::raiseError) must be used to set a message.
	 *
	 */
	public function plgVmDisplayListFEPayment(VirtueMartCart $cart, $selected = 0, &$htmlIn)
	{
		return true;
	}

	private function getCloudKassirMethod()
	{
		// ищем метод
		$oDb  = JFactory::getDBO();
		$sSql = "SELECT * FROM `#__virtuemart_paymentmethods` WHERE `payment_element` = 'cloudkassir' LIMIT 1";
		$oDb->setQuery($sSql);
		$oRow = $oDb->loadObject();
		return $this->getVmPluginMethod($oRow->virtuemart_paymentmethod_id);
	}

	/**
	 * Срабатывает после оформления заказа /  При обнослении статуса заказа в админке
	 * Срабатывает при сохранении статуса заказ в админке
	 * Save updated order data to the method specific table
	 *
	 * @param array $_formData Form data
	 * @return mixed, True on success, false on failures (the rest of the save-process will be
	 * skipped!), or null when this method is not actived.
	 * @author Oscar van Eijk
	 */
	public function plgVmOnUpdateOrderPayment(&$_formData)
	{
		if (isset($_GET['task']) && $_GET['task'] == 'pluginresponsereceived'
            && isset($_GET['cloudpayments_receipt']))  return true; // если вызывается вебхук, то ничего не делаем, иначе не изменится статус заказа
		/**
		 * Отправляем CloudPayments чек:
		 */
		$oCloudKassirMethod = $this->getCloudKassirMethod();
		$oOrderModel = VmModel::getModel('orders');
		$iOrderId = $oOrderModel->getOrderIdByOrderNumber($_formData->order_number);
		$oOrder = $oOrderModel->getOrder($iOrderId);
		// если статус заказа совпадает с настройками в кассире
		if ($_formData->order_status == $oCloudKassirMethod->status_success){
			// если метод оплаты  подлежит отправки чеку
			if (in_array($_formData->virtuemart_paymentmethod_id, $oCloudKassirMethod->payment_methods)){
				$this->SendReceipt($oOrder, 'Income');
			}
		}
		// 	return
	}

	/**
	 * Метод для отправки запросов системе
	 * @param string $location
	 * @param array  $request
	 * @return bool|array
	 */
	private function makeRequest($method, $location, $request = array()) {
		if (!$this->curl) {
			$auth       = $method->public_id . ':' . $method->api_password;
			$this->curl = curl_init();
			curl_setopt($this->curl, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($this->curl, CURLOPT_CONNECTTIMEOUT, 30);
			curl_setopt($this->curl, CURLOPT_TIMEOUT, 30);
			curl_setopt($this->curl, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
			curl_setopt($this->curl, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
			curl_setopt($this->curl, CURLOPT_USERPWD, $auth);
		}

		curl_setopt($this->curl, CURLOPT_URL, 'https://api.cloudpayments.ru/' . $location);
		curl_setopt($this->curl, CURLOPT_HTTPHEADER, array(
			"content-type: application/json"
		));
		curl_setopt($this->curl, CURLOPT_POST, true);
		curl_setopt($this->curl, CURLOPT_POSTFIELDS, json_encode($request));

		$response = curl_exec($this->curl);
		if ($response === false || curl_getinfo($this->curl, CURLINFO_HTTP_CODE) != 200) {
			vmDebug('CloudPayments Failed API request' .
				' Location: ' . $location .
				' Request: ' . print_r($request, true) .
				' HTTP Code: ' . curl_getinfo($this->curl, CURLINFO_HTTP_CODE) .
				' Error: ' . curl_error($this->curl)
			);

			return false;
		}
		$response = json_decode($response, true);
		if (!isset($response['Success']) || !$response['Success']) {
			vmError('CloudPayments error: '.$response['Message']);
			vmDebug('CloudPayments Failed API request' .
				' Location: ' . $location .
				' Request: ' . print_r($request, true) .
				' HTTP Code: ' . curl_getinfo($this->curl, CURLINFO_HTTP_CODE) .
				' Error: ' . curl_error($this->curl)
			);

			return false;
		}

		return $response;
	}

	/**
	 * Срабатывает при вызове в админке
	 * @param $data
	 * @return bool
	 */
	public function plgVmDeclarePluginParamsPaymentVM3(&$data)
	{
		return $this->declarePluginParams('payment', $data);
	}

	/**
	 * Fields to create the payment table
	 * @return string SQL Fileds
	 */
	function getTableSQLFields()
	{
		$SQLfields = array(
			'id' => 'int(11) unsigned NOT NULL AUTO_INCREMENT',
			'virtuemart_order_id' => 'int(11) UNSIGNED',
			'order_number' => 'char(32)',
			'type' => 'char(32)',
			'time' => 'int(11) unsigned',
			'answer_received' => 'int(11) unsigned',
			'data' => 'text'
		);

		return $SQLfields;
	}

	/**
	 * Срабатывает после оформления заказа
	 * Display stored payment data for an order
	 * @param $virtuemart_order_id
	 * @param $virtuemart_payment_id
	 * @return mixed
	 */
	function plgVmOnShowOrderBEPayment($virtuemart_order_id, $virtuemart_payment_id)
	{
		$sHtml = '<div style="text-align: center; padding-bottom: 5px; padding-top: 11px;"><b>ЧЕКИ</b></div><table class="adminlist table"><tr><th>Дата</th><th>Тип</th><th>Ответ</th>';
		$oDb = JFactory::getDBO();
		$sSql = "SELECT * FROM `#__virtuemart_payment_plg_cloudkassir` WHERE `virtuemart_order_id` = ".$virtuemart_order_id;
		$oDb->setQuery($sSql);
		$aRow = $oDb->loadObjectList();
		$bSendIncomeReturn = true;
		if (!empty($aRow)) {
			foreach($aRow as $oRow) {
				$oDate = new DateTime();
				$oDate->setTimestamp($oRow->time);
				$sHtml .= "
				<tr>
					<td>".$oDate->format('d.m.Y H:i:s')."</td>
					<td>".$oRow->type."</td>
					<td>".$oRow->answer_received."</td>
				</tr>
				";
				if ($oRow->type == 'IncomeReturn') {
					if ($oRow->answer_received || time() - $oRow->time < 120) {
						$bSendIncomeReturn = false;
					}
				}
			}
		}
		$sHtml .= "</table>";
		if ($bSendIncomeReturn) {
			$sHtml .= '<span class="btn btn-small "><a href="/index.php?option=com_virtuemart&view=pluginresponse&task=pluginresponsereceived&cloudkassir_send='.$virtuemart_order_id.'"><span class=" icon-forward"></span>Отправить чек возврата</a></span>';
		}
		return $sHtml;
	}

	/**
	 * Срабатывает до показа корзины
	 * @param VirtueMartCart $cart
	 * @param int $method
	 * @param array $cart_prices
	 *
	 * @return bool
	 *
	 * @since version
	 */
	protected function checkConditions($cart, $method, $cart_prices)
	{
		return true;
	}

	/**
	 * Срабатывает при клике по платежной системе в коризине
	 * This event is fired after the payment method has been selected. It can be used to store
	 * additional payment info in the cart.
	 *
	 * @author Max Milbers
	 * @author Valérie isaksen
	 *
	 * @param VirtueMartCart $cart : the actual cart
	 * @return null if the payment was not selected, true if the data is valid, error message if the data is not vlaid
	 *
	 */
	public function plgVmOnSelectCheckPayment(VirtueMartCart $cart)
	{
		return $this->OnSelectCheck($cart);
	}

	/**
	 * Срабатывает до показа корзины
	 * plgVmonSelectedCalculatePricePayment
	 * Calculate the price (value, tax_id) of the selected method
	 * It is called by the calculator
	 * This function does NOT to be reimplemented. If not reimplemented, then the default values from this function are taken.
	 * @author Valerie Isaksen
	 * @cart: VirtueMartCart the current cart
	 * @cart_prices: array the new cart prices
	 * @return null if the method was not selected, false if the shiiping rate is not valid any more, true otherwise
	 *
	 *
	 */
	public function plgVmonSelectedCalculatePricePayment(VirtueMartCart $cart, array &$cart_prices, &$cart_prices_name)
	{
		return $this->onSelectedCalculatePrice($cart, $cart_prices, $cart_prices_name);
	}

	/**
	 * Срабатывает когда заказ оформлен
	 * @param $virtuemart_paymentmethod_id
	 * @param $paymentCurrencyId
	 * @return bool|null
	 */
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

	/**
	 * Срабатывает во время оформления заказа. Заказа в базе еще нет
	 * plgVmOnCheckAutomaticSelectedPayment
	 * Checks how many plugins are available. If only one, the user will not have the choice. Enter edit_xxx page
	 * The plugin must check first if it is the correct type
	 * @author Valerie Isaksen
	 * @param VirtueMartCart cart: the cart object
	 * @return null if no plugin was found, 0 if more then one plugin was found,  virtuemart_xxx_id if only one plugin is found
	 *
	 */
	function plgVmOnCheckAutomaticSelectedPayment(VirtueMartCart $cart, array $cart_prices = array())
	{
		return $this->onCheckAutomaticSelected($cart, $cart_prices);
	}

	/**
	 * Срабатывает до показа корзины
	 * Не подходит для проверки, т.к. пользователь должен быть авторизован
	 * This event is fired during the checkout process. It can be used to validate the
	 * method data as entered by the user.
	 *
	 * @return boolean True when the data was valid, false otherwise. If the plugin is not activated, it should return null.
	 * @author Max Milbers
	 */
	public function plgVmOnCheckoutCheckDataPayment(VirtueMartCart $cart)
	{
		return null;
	}


	/**
	 * This method is fired when showing when priting an Order
	 * It displays the the payment method-specific data.
	 *
	 * @param integer $_virtuemart_order_id The order ID
	 * @param integer $method_id method used for this order
	 * @return mixed Null when for payment methods that were not selected, text (HTML) otherwise
	 * @author Valerie Isaksen
	 */
	function plgVmonShowOrderPrintPayment($order_number, $method_id)
	{
		return $this->onShowOrderPrint($order_number, $method_id);
	}

	function plgVmDeclarePluginParamsPayment($name, $id, &$data)
	{
		return $this->declarePluginParams('payment', $name, $id, $data);
	}

	/**
	 * Срабатывает при сохранении настроек в админке
	 * @param $name
	 * @param $id
	 * @param $table
	 *
	 * @return bool
	 *
	 * @since version
	 */
	function plgVmSetOnTablePluginParamsPayment($name, $id, &$table)
	{
		return $this->setOnTablePluginParams($name, $id, $table);
	}

	//Notice: We only need to add the events, which should work for the specific plugin, when an event is doing nothing, it should not be added


	/**
	 * Save updated orderline data to the method specific table
	 *
	 * @param array $_formData Form data
	 * @return mixed, True on success, false on failures (the rest of the save-process will be
	 * skipped!), or null when this method is not actived.
	 * @author Oscar van Eijk
	 */
	public function plgVmOnUpdateOrderLine($_formData)
	{
		return null;
	}

	/**
	 * Данного метода нет PayPal
	 * plgVmOnEditOrderLineBE
	 * This method is fired when editing the order line details in the backend.
	 * It can be used to add line specific package codes
	 *
	 * @param integer $_orderId The order ID
	 * @param integer $_lineId
	 * @return mixed Null for method that aren't active, text (HTML) otherwise
	 * @author Oscar van Eijk
	 */
	public function plgVmOnEditOrderLineBEPayment($_orderId, $_lineId)
	{
		return null;
	}

	/**
	 * Данного метода нет PayPal
	 * This method is fired when showing the order details in the frontend, for every orderline.
	 * It can be used to display line specific package codes, e.g. with a link to external tracking and
	 * tracing systems
	 *
	 * @param integer $_orderId The order ID
	 * @param integer $_lineId
	 * @return mixed Null for method that aren't active, text (HTML) otherwise
	 * @author Oscar van Eijk
	 */
	public function plgVmOnShowOrderLineFE($_orderId, $_lineId)
	{
		return null;
	}

	/**
	 * This event is fired when the  method notifies you when an event occurs that affects the order.
	 * Typically,  the events  represents for payment authorizations, Fraud Management Filter actions and other actions,
	 * such as refunds, disputes, and chargebacks.
	 *
	 * NOTE for Plugin developers:
	 *  If the plugin is NOT actually executed (not the selected payment method), this method must return NULL
	 *
	 * @param $return_context : it was given and sent in the payment form. The notification should return it back.
	 * Used to know which cart should be emptied, in case it is still in the session.
	 * @param int $virtuemart_order_id : payment  order id
	 * @param char $new_status : new_status for this order id.
	 * @return mixed Null when this method was not selected, otherwise the true or false
	 *
	 * @author Valerie Isaksen
	 *
	 **/

	/**
	 * @return bool|null
	 */
	public function plgVmOnPaymentNotification()
	{
	    prex('public function plgVmOnPaymentNotification()');
		return null;
	}

	function plgVmOnUserPaymentCancel()
	{
		return $this->plgVmOnUserPaymentCancel();

	}
}