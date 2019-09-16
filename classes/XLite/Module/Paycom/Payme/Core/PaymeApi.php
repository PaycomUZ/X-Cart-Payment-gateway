<?php

namespace XLite\Module\Paycom\Payme\Core;

use XLite\Core\Database;

class PaymeApi {

	private $errorInfo ="";
	private $errorCod =0;
	private $request_id=0;
	private $responceType=0;
	private $result =true;
	private $inputArray;
	private $lastTransaction;
	private $lastTransactionDate;
	private $statement;
	private $paymentMethod;

	public function construct() {}
 
	public function parseRequest() {

		//file_put_contents(dirname(__FILE__) . "../../../../../../../../payme.log", " PaymeApi -> parseRequest **************  begin  ********************** >>>>>>>>>>>>>>>".date("M,d,Y h:i:s A").PHP_EOL, FILE_APPEND);
		
		if ( (!isset($this->inputArray)) || empty($this->inputArray) ) {

			$this->setErrorCod(-32700,"empty inputArray");

		} else {

			$parsingJsonError=false;

			switch (json_last_error()){

				case JSON_ERROR_NONE: break;
				default: $parsingJson=true; break;
			}

			if ($parsingJsonError) {

				$this->setErrorCod(-32700,"parsingJsonError");

			} else {

				// Request ID
				if (!empty($this->inputArray['id']) ) {

					$this->request_id = filter_var($this->inputArray['id'], FILTER_SANITIZE_NUMBER_INT);
				}

				$methodPaymes = Database::getRepo('XLite\Model\Payment\Method')
				->createQueryBuilder('p')
				->andWhere('p.service_name = :i_name')
				->setParameter('i_name', 'Payme')
				->getResult();

				foreach ($methodPaymes as $methodPayme) {
					$this->paymentMethod=$methodPayme;	
				}

					 if ($_SERVER['REQUEST_METHOD']!='POST') $this->setErrorCod(-32300);
				else if(! isset($_SERVER['PHP_AUTH_USER']))  $this->setErrorCod(-32504,"логин пустой");
				else if(! isset($_SERVER['PHP_AUTH_PW']))	 $this->setErrorCod(-32504,"пароль пустой");
			}
		}

		if ($this->result) {

			if ($this->paymentMethod->getSetting('payme_test_mode')=='payme_yes'){

				$merchantKey=html_entity_decode($this->paymentMethod->getSetting('payme_secret_key_for_test'));

			} else if ($this->paymentMethod->getSetting('payme_test_mode')=='payme_no'){

				$merchantKey=html_entity_decode($this->paymentMethod->getSetting('payme_secret_key'));
			}

			if( $merchantKey != html_entity_decode($_SERVER['PHP_AUTH_PW']) ) {

				$this->setErrorCod(-32504,"неправильный  пароль");

			} else {

				if ( method_exists($this,"payme_".$this->inputArray['method'])) {

					//file_put_contents(dirname(__FILE__) . "../../../../../../../../payme.log", " PaymeApi -> parseRequest  RUN ".$this->inputArray['method'].' -> '.date("M,d,Y h:i:s A").PHP_EOL, FILE_APPEND);

					$methodName="payme_".$this->inputArray['method'];
					$this->$methodName();

				} else {

					$this->setErrorCod(-32601, $this->inputArray['method'] );
				}
			}
		}

		//file_put_contents(dirname(__FILE__) . "../../../../../../../../payme.log", " PaymeApi -> parseRequest  End ".date("M,d,Y h:i:s A").PHP_EOL, FILE_APPEND);

		return $this->GenerateResponse();
	}

	public function getTransactionDateByName($dateName){
	
		$b_v='';
		foreach ($this->lastTransactionDate as $TrDate){
			
			if ($TrDate->getName()==$dateName) {
				$b_v=$TrDate->getValue();
				break;
			}	
		}
		return $b_v;
	}

	public function getTransactionByOrderId($order_id) {

		$transactions = Database::getRepo('XLite\Model\Payment\Transaction')
					->createQueryBuilder('d')
					->andWhere('d.public_id = :i_value')
					->setParameter('i_value', $order_id)
					->getResult();

		foreach ($transactions as $transaction) {

			$this->lastTransaction=$transaction;
		}

		if ($this->lastTransaction) {

			$this->lastTransactionDate = Database::getRepo('XLite\Model\Payment\TransactionData')
					->createQueryBuilder('d')
					->andWhere('d.transaction = :i_value')
					->setParameter('i_value', $this->lastTransaction->getTransactionId())
					->getResult();
		}
	}

	public function getTransactionDateByPaymeTrId($t_id) {

		$transactionDates = Database::getRepo('XLite\Model\Payment\TransactionData')
					->createQueryBuilder('d')
					->andWhere('d.name = :i_name')
					->setParameter('i_name', 'paycom_transaction_id')
					->andWhere('d.value = :i_value')
					->setParameter('i_value', $t_id)
					->getResult();

		foreach ($transactionDates as $transactionDate) {

			$this->lastTransaction = $transactionDate->getTransaction();
		}

		if ($this->lastTransaction) {

			$this->lastTransactionDate= Database::getRepo('XLite\Model\Payment\TransactionData')
					->createQueryBuilder('d')
					->andWhere('d.transaction = :i_value')
					->setParameter('i_value', $this->lastTransaction->getTransactionId())
					->getResult();
		}
	}

	public function payme_CheckPerformTransaction() {

		// Поиск транзакции по order_id
		$this->getTransactionByOrderId($this->inputArray['params']['account']['order_id']);
		// Поиск заказа по order_id
		$order=$this->lastTransaction->getOrder();

		// Заказ не найден
		if (! $order ) {

			$this->setErrorCod(-31050,'order_id');

		// Заказ найден
		} else {

			// Транзакция статусс
			if ($this->lastTransaction->getStatus()==$this->lastTransaction::STATUS_INPROGRESS ) {

				// Проверка состояния заказа
				if ($order->getPaymentStatus()->getCode()!=$order->getPaymentStatus()::STATUS_QUEUED ) { 

					$this->setErrorCod(-31052, 'order_id');

				// Сверка суммы заказа
				} else  if ( abs(($order->getTotal()*100) - (int)$this->inputArray['params']['amount'])>=0.01) {

					$this->setErrorCod(-31001, 'order_id ='.gettype($order->getTotal()).'-'.gettype(($order->getTotal()*100)) .'<>'.gettype($this->inputArray['params']['amount'])); 

				// Allow true
				} else {

					$this->responceType=1;
				} 

			// Существует транзакция
			} else {

				$this->setErrorCod(-31051, 'order_id');
			}
		}
	}

	public function payme_CreateTransaction() {

		$this->getTransactionDateByPaymeTrId($this->inputArray['params']['id']);
		
		if ($this->lastTransaction) {
		$order=$this->lastTransaction->getOrder();
		}

		// Существует транзакция
		if ($this->lastTransactionDate) {

			$paycom_time_integer=$this->datetime2timestamp($this->getTransactionDateByName('create_time'))*1000;
			$paycom_time_integer=$paycom_time_integer+43200000;

			// Проверка состояния заказа
			if ($order->getPaymentStatus()->getCode()!=$order->getPaymentStatus()::STATUS_AUTHORIZED ){ //order status 2 A

				$this->setErrorCod(-31052, 'order_id');
 
			// Проверка состояния транзакции
			} else if ($this->lastTransaction->getStatus() !=$this->lastTransaction::STATUS_PENDING){ //Transaction status W

				$this->setErrorCod(-31008, 'order_id');

			// Проверка времени создания транзакции
			} else if ($paycom_time_integer <= $this->timestamp2milliseconds(time())){

				// Отменит reason = 4
				$this->lastTransaction->setDataCell('cancel_time', date('Y-m-d H:i:s'));
				$this->lastTransaction->setDataCell('reason', "4");
				$this->lastTransaction->setDataCell('state', "-1");

				$this->lastTransaction->setStatus($this->lastTransaction::STATUS_CANCELED); //Transaction status C
				$order->setPaymentStatus(\XLite\Model\Order\Status\Payment::STATUS_CANCELED); //order status 6 C

				\XLite\Core\Database::getEM()->flush();
				\XLite\Core\Database::getEM()->clear();

				$this->responceType=2;
				$this->getTransactionByOrderId($this->inputArray['params']['account']['order_id']);
 
			// Всё OK
			} else {

				$this->responceType=2;
			}

		// Транзакция нет
		} else {
			
			$this->getTransactionByOrderId($this->inputArray['params']['account']['order_id']); 

			if ($this->lastTransaction) {
				$order=$this->lastTransaction->getOrder();
			}
 
			// Заказ не найден
			if (! $order ) {

				$this->setErrorCod(-31050,'order_id');

			// Заказ найден
			} else {

				// Транзакция статусс
				if ($this->lastTransaction->getStatus()==$this->lastTransaction::STATUS_INPROGRESS ) {
 
				// Проверка состояния заказа 
				if ($order->getPaymentStatus()->getCode()!=$order->getPaymentStatus()::STATUS_QUEUED )  { //order status 1 Q

					$this->setErrorCod(-31052, 'order_id');

				// Сверка суммы заказа 	
				} else  if ( abs(($order->getTotal()*100) - (int)$this->inputArray['params']['amount'])>=0.01) {

					$this->setErrorCod(-31001, 'order_id');

				// Запись транзакцию state=1
				} else {

					$this->lastTransaction->setDataCell('create_time', 			date('Y-m-d H:i:s'));
					$this->lastTransaction->setDataCell('amount', 				$order->getTotal()*100);
					$this->lastTransaction->setDataCell('state', 				'1');
					$this->lastTransaction->setDataCell('cms_order_id', 		$this->inputArray['params']['account']['order_id']);
					$this->lastTransaction->setDataCell('paycom_time', 			$this->inputArray['params']['time']);
					$this->lastTransaction->setDataCell('paycom_time_datetime', $this->timestamp2datetime($this->inputArray['params']['time']));
					$this->lastTransaction->setDataCell('paycom_transaction_id',$this->inputArray['params']['id']);

					$this->lastTransaction->setStatus($this->lastTransaction::STATUS_PENDING); //Transaction status W
					$order->markAsOrder();
					$order->setShippingStatus(\XLite\Model\Order\Status\Shipping::STATUS_NEW);
					$order->setPaymentStatus (\XLite\Model\Order\Status\Payment::STATUS_AUTHORIZED); //order status 2 A

					\XLite\Core\Database::getEM()->flush();
					\XLite\Core\Database::getEM()->clear();

					$this->responceType=2; 
					$this->getTransactionByOrderId($this->inputArray['params']['account']['order_id']);
				}
				// Существует транзакция
				} else {

				$this->setErrorCod(-31051, 'order_id');
				}
			} //
		}
	}
 
	public function payme_CheckTransaction() {
 
		// Поиск транзакции по id
		$this->getTransactionDateByPaymeTrId($this->inputArray['params']['id']);

		// Существует транзакция
		if ($this->lastTransactionDate) {

			$this->responceType=2;

		// Транзакция нет
		} else {

			$this->setErrorCod(-31003);
		}
	}

	public function payme_PerformTransaction() {

		// Поиск транзакции по id
		$this->getTransactionDateByPaymeTrId($this->inputArray['params']['id']);

		// Существует транзакция
		if ($this->lastTransactionDate) {

			// Поиск заказа по order_id
			$order=$this->lastTransaction->getOrder();
  
			// Проверка состояние транзакцие
			if ($this->lastTransaction->getStatus() ==$this->lastTransaction::STATUS_PENDING){ //Transaction status W

				$paycom_time_integer=$this->datetime2timestamp($this->getTransactionDateByName('create_time')) *1000;

				$paycom_time_integer=$paycom_time_integer+43200000;

				// Проверка времени создания транзакции
				if ($paycom_time_integer <= $this->timestamp2milliseconds(time())){

					// Отменит reason = 4
					$this->lastTransaction->setDataCell('cancel_time', date('Y-m-d H:i:s'));
					$this->lastTransaction->setDataCell('reason', "4");
					$this->lastTransaction->setDataCell('state', "-1");

					$this->lastTransaction->setStatus($this->lastTransaction::STATUS_CANCELED); //Transaction status C
					$order->setPaymentStatus(\XLite\Model\Order\Status\Payment::STATUS_CANCELED); //order status 6 C

					\XLite\Core\Database::getEM()->flush();
					\XLite\Core\Database::getEM()->clear(); 

				// Всё Ok
				} else {

					// Оплата
					$this->lastTransaction->setDataCell('perform_time', date('Y-m-d H:i:s'));
					$this->lastTransaction->setDataCell('state', "2");
					
					$this->lastTransaction->setStatus($this->lastTransaction::STATUS_SUCCESS); //Transaction status S
					$order->setPaymentStatus(\XLite\Model\Order\Status\Payment::STATUS_PAID); //order status 6 P

					\XLite\Core\Database::getEM()->flush();
					\XLite\Core\Database::getEM()->clear(); 
				}

				$this->responceType=2;
				$this->getTransactionDateByPaymeTrId($this->inputArray['params']['id']);

			// Cостояние не 1
			} else {

				// Проверка состояние транзакцие
				if ($this->lastTransaction->getStatus() ==$this->lastTransaction::STATUS_SUCCESS){ //Transaction status

					$this->responceType=2;

				// Cостояние не 2
				} else {

					$this->setErrorCod(-31008);
				}
			}
		// Транзакция нет
		} else {

			$this->setErrorCod(-31003);
		}
	}

	public function payme_CancelTransaction() {

		// Поиск транзакции по id
		$this->getTransactionDateByPaymeTrId($this->inputArray['params']['id']);

		// Существует транзакция
		if ($this->lastTransactionDate) {

			// Поиск заказа по order_id
			$order=$this->lastTransaction->getOrder();

			$reasonCencel=filter_var($this->inputArray['params']['reason'], FILTER_SANITIZE_NUMBER_INT);

			// Проверка состояние транзакцие
			if ($this->lastTransaction->getStatus() ==$this->lastTransaction::STATUS_PENDING){ //Transaction status W

				// Отменит state = -1
				$this->lastTransaction->setDataCell('cancel_time', date('Y-m-d H:i:s'));
				$this->lastTransaction->setDataCell('reason', $reasonCencel);
				$this->lastTransaction->setDataCell('state', "-1");

				$this->lastTransaction->setStatus($this->lastTransaction::STATUS_CANCELED); //Transaction status C
				$order->setPaymentStatus(\XLite\Model\Order\Status\Payment::STATUS_CANCELED); //order status 6 C

				\XLite\Core\Database::getEM()->flush();
				\XLite\Core\Database::getEM()->clear();

			// Cостояние 2
			} else if ($this->lastTransaction->getStatus() ==$this->lastTransaction::STATUS_SUCCESS){ //Transaction status

				// Отменит state = -2
				$this->lastTransaction->setDataCell('cancel_time', date('Y-m-d H:i:s'));
				$this->lastTransaction->setDataCell('reason', $reasonCencel);
				$this->lastTransaction->setDataCell('state', "-2");

				$this->lastTransaction->setStatus($this->lastTransaction::STATUS_CANCELED); //Transaction status C
				$order->setPaymentStatus(\XLite\Model\Order\Status\Payment::STATUS_CANCELED); //order status 6 C

				\XLite\Core\Database::getEM()->flush();
				\XLite\Core\Database::getEM()->clear();

			// Cостояние
			} else {

				// Ничего не надо делать
			}

			$this->responceType=2;
			$this->getTransactionDateByPaymeTrId($this->inputArray['params']['id']);

		// Транзакция нет
		} else {

			$this->setErrorCod(-31003);
		}
	}

	public function payme_ChangePassword() {
		
		$this->paymentMethod->setSetting('payme_secret_key',$this->inputArray['params']['password']);
		\XLite\Core\Database::getEM()->flush();
		\XLite\Core\Database::getEM()->clear(); 

		$this->responceType=3;
	}

	public function payme_GetStatement() {
		
		$rows = \Includes\Utils\Database::fetchAll(
				"SELECT 
						(select w.value from ".get_db_tables_prefix()."payment_transaction_data w where w.transaction_id=t.transaction_id and w.name='paycom_time') as paycom_time,
						(select w.value from ".get_db_tables_prefix()."payment_transaction_data w where w.transaction_id=t.transaction_id and w.name='paycom_transaction_id') as paycom_transaction_id, 
						(select w.value from ".get_db_tables_prefix()."payment_transaction_data w where w.transaction_id=t.transaction_id and w.name='amount') as amount, 
						(select w.value from ".get_db_tables_prefix()."payment_transaction_data w where w.transaction_id=t.transaction_id and w.name='cms_order_id') as cms_order_id, 
						(select w.value from ".get_db_tables_prefix()."payment_transaction_data w where w.transaction_id=t.transaction_id and w.name='create_time') as create_time, 
						(select w.value from ".get_db_tables_prefix()."payment_transaction_data w where w.transaction_id=t.transaction_id and w.name='perform_time') as perform_time, 
						(select w.value from ".get_db_tables_prefix()."payment_transaction_data w where w.transaction_id=t.transaction_id and w.name='cancel_time') as cancel_time, 
						(select w.value from ".get_db_tables_prefix()."payment_transaction_data w where w.transaction_id=t.transaction_id and w.name='state') as state, 
						(select w.value from ".get_db_tables_prefix()."payment_transaction_data w where w.transaction_id=t.transaction_id and w.name='reason') as reason 
				FROM xc_payment_transactions t WHERE t.transaction_id in 
				(SELECT d.transaction_id FROM ".get_db_tables_prefix()."payment_transaction_data d 
				 WHERE 
				 d.name='paycom_time' and 
				 CAST(d.value AS UNSIGNED)>=".$this->inputArray['params']['from']." and 
				 CAST(d.value AS UNSIGNED)<=".$this->inputArray['params']['to']." )");
				 
		$responseArray = array();
		$transactions  = array();
		
		foreach ($rows as $row) {

			array_push($transactions,array(

				"id"		   => $row["paycom_transaction_id"],
				"time"		   => $row['paycom_time']  ,
				"amount"	   => $row["amount"],
				"account"	   => array("cms_order_id" => $row["cms_order_id"]),
				"create_time"  => (is_null($row['create_time']) ? null: $this->datetime2timestamp( $row['create_time']) ) ,
				"perform_time" => (is_null($row['perform_time'])? null: $this->datetime2timestamp( $row['perform_time'])) ,
				"cancel_time"  => (is_null($row['cancel_time']) ? null: $this->datetime2timestamp( $row['cancel_time']) ) ,
				"transaction"  => $row["order_id"],
				"state"		   => (int) $row['state'],
				"reason"	   => (is_null($row['reason'])?null:(int) $row['reason']) ,
				"receivers"	=> null
			)) ;
		}

		$responseArray['result'] = array( "transactions"=> $transactions );

		$this->responceType=4;
		$this->statement=$responseArray;
	}
 
	public function GenerateResponse() {

		if ($this->errorCod==0) {

			if ($this->responceType==1) {

				$responseArray = array('result'=>array( 'allow' => true )); 

			} else if ($this->responceType==2) {

				$responseArray = array(); 
				$responseArray['id']	 = $this->request_id;
				$responseArray['result'] = array(

					"create_time"	=> $this->datetime2timestamp($this->getTransactionDateByName('create_time')) *1000,
					"perform_time"  => $this->datetime2timestamp($this->getTransactionDateByName('perform_time'))*1000,
					"cancel_time"   => $this->datetime2timestamp($this->getTransactionDateByName('cancel_time')) *1000,
					"transaction"	=> $this->getTransactionDateByName('cms_order_id'), //FIX $this->order_id,
					"state"			=>     (int)$this->getTransactionDateByName('state'),
					"reason"		=> ( $this->getTransactionDateByName('reason') ? (int)$this->getTransactionDateByName('reason') : null)
				);

			} else if ($this->responceType==3) {

				$responseArray = array('result'=>array( 'success' => true ));

			} else if ($this->responceType==4) {

				$responseArray=$this->statement;
			}

		} else {

			$responseArray['id']	= $this->request_id;
			$responseArray['error'] = array (

				'code'  =>(int)$this->errorCod,
				"data" 	=>$this->errorInfo,
				'message'=> array(

					"ru"=>$this->getGenerateErrorText($this->errorCod,"ru"),
					"uz"=>$this->getGenerateErrorText($this->errorCod,"uz"),
					"en"=>$this->getGenerateErrorText($this->errorCod,"en"),

				)
			);
		}

		return $responseArray;
	}

	public function getGenerateErrorText($codeOfError,$codOfLang){

		$listOfError=array ('-31001' => array(
										  "ru"=>'Неверная сумма.',
										  "uz"=>'Неверная сумма.',
										  "en"=>'Неверная сумма.'
										),
							'-31003' => array(
										  "ru"=>'Транзакция не найдена.',
										  "uz"=>'Транзакция не найдена.',
										  "en"=>'Транзакция не найдена.'
										),
							'-31008' => array(
										  "ru"=>'Невозможно выполнить операцию.',
										  "uz"=>'Невозможно выполнить операцию.',
										  "en"=>'Невозможно выполнить операцию.'
										),
							'-31050' => array(
										  "ru"=>'Заказ не найден.',
										  "uz"=>'Заказ не найден.',
										  "en"=>'Заказ не найден.'
										),
							'-31051' => array(
										  "ru"=>'Существует транзакция.',
										  "uz"=>'Существует транзакция.',
										  "en"=>'Существует транзакция.'
										),
							'-31052' => array(
											"ru"=>'Заказ уже оплачен.',
											"uz"=>'Заказ уже оплачен.',
											"en"=>'Заказ уже оплачен.'
										),
										
							'-32300' => array(
										  "ru"=>'Ошибка возникает если метод запроса не POST.',
										  "uz"=>'Ошибка возникает если метод запроса не POST.',
										  "en"=>'Ошибка возникает если метод запроса не POST.'
										),
							'-32600' => array(
										  "ru"=>'Отсутствуют обязательные поля в RPC-запросе или тип полей не соответствует спецификации',
										  "uz"=>'Отсутствуют обязательные поля в RPC-запросе или тип полей не соответствует спецификации',
										  "en"=>'Отсутствуют обязательные поля в RPC-запросе или тип полей не соответствует спецификации'
										),
							'-32700' => array(
										  "ru"=>'Ошибка парсинга JSON.',
										  "uz"=>'Ошибка парсинга JSON.',
										  "en"=>'Ошибка парсинга JSON.'
										),
							'-32600' => array(
										  "ru"=>'Отсутствуют обязательные поля в RPC-запросе или тип полей не соответствует спецификации.',
										  "uz"=>'Отсутствуют обязательные поля в RPC-запросе или тип полей не соответствует спецификации.',
										  "en"=>'Отсутствуют обязательные поля в RPC-запросе или тип полей не соответствует спецификации.'
										),
							'-32601' => array(
										  "ru"=>'Запрашиваемый метод не найден. В RPC-запросе имя запрашиваемого метода содержится в поле data.',
										  "uz"=>'Запрашиваемый метод не найден. В RPC-запросе имя запрашиваемого метода содержится в поле data.',
										  "en"=>'Запрашиваемый метод не найден. В RPC-запросе имя запрашиваемого метода содержится в поле data.'
										),
							'-32504' => array(
										  "ru"=>'Недостаточно привилегий для выполнения метода.',
										  "uz"=>'Недостаточно привилегий для выполнения метода.',
										  "en"=>'Недостаточно привилегий для выполнения метода.'
										),
							'-32400' => array(
										  "ru"=>'Системная (внутренняя ошибка). Ошибку следует использовать в случае системных сбоев: отказа базы данных, отказа файловой системы, неопределенного поведения и т.д.',
										  "uz"=>'Системная (внутренняя ошибка). Ошибку следует использовать в случае системных сбоев: отказа базы данных, отказа файловой системы, неопределенного поведения и т.д.',
										  "en"=>'Системная (внутренняя ошибка). Ошибку следует использовать в случае системных сбоев: отказа базы данных, отказа файловой системы, неопределенного поведения и т.д.'
										)
							);

		return $listOfError[$codeOfError][$codOfLang];
	}

	public function timestamp2datetime($timestamp){

		if (strlen((string)$timestamp) == 13) {
			$timestamp = $this->timestamp2seconds($timestamp);
		}

		return date('Y-m-d H:i:s', $timestamp);
	}

	public function timestamp2seconds($timestamp) {

		if (strlen((string)$timestamp) == 10) {
			return $timestamp;
		}

		return floor(1 * $timestamp / 1000);
	}

	public function timestamp2milliseconds($timestamp) {

		if (strlen((string)$timestamp) == 13) {
			return $timestamp;
		}

		return $timestamp * 1000;
	}

	public function datetime2timestamp($datetime) {

		if ($datetime) {

			return strtotime($datetime);
		}

		return $datetime;
	}

	public function setErrorCod($cod_,$info=null) {

		$this->errorCod=$cod_;

		if ($info!=null) $this->errorInfo=$info;

		if ($cod_!=0) {

			$this->result=false;
		}
	}

	public function getInputArray() {

		return $this->inputArray;
	}

	public function setInputArray($i_Array) {

		$this->inputArray = json_decode($i_Array, true); 
	}

}
