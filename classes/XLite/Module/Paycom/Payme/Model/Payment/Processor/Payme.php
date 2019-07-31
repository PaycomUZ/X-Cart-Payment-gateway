<?php

namespace XLite\Module\Paycom\Payme\Model\Payment\Processor;

use Includes\Utils\URLManager;
use XLite\Core\Config;
use XLite\Core\Converter;
use XLite\Coreequest;
use XLite\Core\Translation;
use XLite\Core\XML;
use XLite\Model\Payment\Method;
use XLite\Model\Payment\Transaction; 

class Payme extends \XLite\Model\Payment\Base\WebBased {

	protected $allowedCurrencies = array('UZS', 'RUB', 'USD');	

	protected function getAllowedCurrencies(Method $method) {

		return $this->allowedCurrencies;
	}

	public function getPaymeCallbackURL2(){

		return URLManager::getShopURL(\Includes\Utils\Converter::buildURL('payme_callback', 'callback', [], \XLite::getCustomerScript()));
	}

	public function getPaymeSuccessURL(){

		return URLManager::getShopURL(\Includes\Utils\Converter::buildURL('callback', 'return', [], \XLite::getCustomerScript()));
	}

	public function getAdminIconURL(Method $method){

		return true;
	}

	protected function getFormURL(){

		if ($this->getSetting('payme_test_mode')=='payme_no')
			return $this->getSetting('payme_checkout_url');
		else
			return $this->getSetting('payme_checkout_url_for_test');
	}

	public function isConfigured(Method $method){

		$result = parent::isConfigured($method)
			&& $method->getSetting('payme_merchant_id')
			&& $method->getSetting('payme_secret_key')
			&& $method->getSetting('payme_secret_key_for_test')
			&& $method->getSetting('payme_test_mode')
			&& $method->getSetting('payme_checkout_url')
			&& $method->getSetting('payme_checkout_url_for_test');
		return $result;
	}

	public function getSettingsWidget(){

		return 'modules/Paycom/Payme/config.twig';
	}

	public function hasModuleSettings(){

		return false;
	}

	protected function getFormFields(){

		$transactionId = $this->transaction->getPublicTxnId();
		$orderNum = $this->getTransactionId();

		$t_currency="";
			 if( $this->transaction->getCurrency()->getCode() == 'UZS') $t_currency = 860;
		else if( $this->transaction->getCurrency()->getCode() == 'USD') $t_currency = 840;
		else if( $this->transaction->getCurrency()->getCode() == 'RUB') $t_currency = 643;
		else if( $this->transaction->getCurrency()->getCode() == 'EUR') $t_currency = 978;
		else							  								$t_currency = 860;

		$result = array(
			'merchant'			=> $this->getSetting('payme_merchant_id'),
			'callback'			=> $this->getSetting('payme_return_url'),
			'callback_timeout'	=> $this->getSetting('payme_return_after'),
			'account[order_id]'	=> $orderNum,
			'amount'			=> $this->transaction->getValue()*100,
			'currency'			=> $t_currency,
			'description'		=> ''
		);
		return $result;
	}
}