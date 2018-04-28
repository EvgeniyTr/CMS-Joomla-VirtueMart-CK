<?php
defined ('_JEXEC') or die();
/**
 *
 * @package    VirtueMart
 * @subpackage Plugins  - Elements
 * @author Valérie Isaksen
 * @link https://virtuemart.net
 * @copyright Copyright (c) 2004 - 2011 VirtueMart Team. All rights reserved.
 * @license http://www.gnu.org/copyleft/gpl.html GNU/GPL, see LICENSE.php
 * VirtueMart is free software. This version may have been modified pursuant
 * to the GNU General Public License, and as distributed it includes or
 * is derivative of works licensed under the GNU General Public License or
 * other free or open source software licenses.
 * @version $Id:$
 */

class JFormFieldPaymentmethods extends JFormField {
	var $type = 'orderstatus';
	function getInput () {
		
		if (!class_exists( 'VmConfig' )) require(JPATH_ROOT .'/administrator/components/com_virtuemart/helpers/config.php');

		VmConfig::loadConfig ();
		vmLanguage::loadJLang('com_virtuemart');
		$key = ($this->element['key_field'] ? $this->element['key_field'] : 'value');
		$val = ($this->element['value_field'] ? $this->element['value_field'] : $this->name);
		$model = VmModel::getModel ('Paymentmethod');
		$aPayments = $model->getPayments (1, true);
		return JHtml::_ ('select.genericlist', $aPayments, $this->name, 'class="inputbox" multiple="true" size="1"', 'virtuemart_paymentmethod_id', 'payment_element', $this->value, $this->id);
	}

}