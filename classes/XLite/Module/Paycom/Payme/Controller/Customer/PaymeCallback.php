<?php

namespace XLite\Module\Paycom\Payme\Controller\Customer;

use XLite\Module\Paycom\Payme\Core\PaymeApi;

class PaymeCallback extends \XLite\Controller\Customer\ACustomer {

	protected function doActionCallback(){

		//file_put_contents(dirname(__FILE__) . "../../../../../../../../payme.log", " PaymeCallback -> doActionCallback ".PHP_EOL, FILE_APPEND);

		$this->set('silent', true);
		header('Content-type: application/json charset=utf-8');

		$api = new PaymeApi();

		$api->setInputArray(file_get_contents("php://input"));
		$resp=json_encode($api->parseRequest(),JSON_UNESCAPED_UNICODE );

		echo $resp;

		//file_put_contents(dirname(__FILE__) . "../../../../../../../../payme.log", " PaymeCallback -> doActionCallback end".PHP_EOL, FILE_APPEND);
	}
}
