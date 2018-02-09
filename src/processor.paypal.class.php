<?php
namespace Phoenix\Payment;

// For all the steps, for recurring payments, you may check this:
// https://www.paypal-knowledge.com/infocenter/index?page=content&id=FAQ1504&actp=LIST


class Paypal extends Processor
{
	public $processor 	= "paypal";
	
	public function __construct()
	{
		global $Phoenix;
		
		// Internally define the vars that we should process further and we would need in the class
		
		// Token URL - sandbox or live
		$this->paypal_token_url = "https://api-3t.";
		if ($Phoenix['Payment']['paypal']['testmode'])
			$this->paypal_token_url .= "sandbox.";
		$this->paypal_token_url .= "paypal.com/nvp";
		
		// URL for completition order - sandbox or live
		$this->paypal_completion_url = "https://www.";
		if ($Phoenix['Payment']['paypal']['testmode'])
			$this->paypal_completion_url .= "sandbox.";
		$this->paypal_completion_url .= "paypal.com/webscr?cmd=_express-checkout&useraction=commit&token=";		
		
		// Start building Paypal parameters for call
		$this->params = array(
				"USER" 								=> $Phoenix['Payment']['paypal']['paypal_api_username'],
				"PWD" 								=> $Phoenix['Payment']['paypal']['paypal_api_password'],
				"SIGNATURE" 						=> $Phoenix['Payment']['paypal']['paypal_api_signature'],
				"VERSION" 							=> 93,
				"METHOD"							=> "SetExpressCheckout",
		
				"RETURNURL"							=> $Phoenix['www']['url'].$Phoenix['Payment']['return_url']."?p=paypal-accepted",
				"CANCELURL"							=> $Phoenix['www']['url'].$Phoenix['Payment']['return_url']."?p=paypal-declined",
				
				// Dynamically set the IPN notification URL. It is supposed to handle the rebills - after rebill is made, Paypal would send info to that URL
				// https://developer.paypal.com/docs/classic/ipn/integration-guide/IPNSetup/
				"NOTIFYURL"							=> $Phoenix['www']['url'].$Phoenix['Payment']['return_url']."?p=paypal-ipn",
				
				"PAYMENTREQUEST_0_CURRENCYCODE"		=> "EUR",			
				"CURRENCYCODE"						=> "EUR",	
				
				
				"PAYMENTREQUEST_0_PAYMENTACTION"	=> "Sale",
				"PAYMENTACTION"						=> "Sale",
		);	
	}
	
	//! Here we add all the validation rules. 
	//public function validate($data)
	//{
	//	parent::validate($data);
		// Here you can add more validators, extending the original function
		
	//}	
	
	public function save_transaction($data)
	{
		if ($this->validate($data))
			$this->_save_transaction($data);
	}
	
	public function process_transaction($trn_id=null)
	{
		if ($trn_id)
			$this->_process_transaction($trn_id); // Private method per class
	}
	
	public function process_postback($callback)
	{
		global $SQL;
		list($proc, $status) = explode("-", $_GET['p']);

		if ($proc != "paypal")
			throw new \Exception("Processor not match");
		
			
			
		/////////////////////// This happens when Paypal post to us info, regarding to rebills
		// Recognize and process a rebill event.
		if ($status == "ipn")
		{
			if (!$_POST)
				return false;
			
			header("HTTP/1.1 200 OK");
				
			$SQL->exec("INSERT INTO  `payments_paypal_ipn_log` SET `post` = ?, `get`=?",
						print_r($_POST, 1),  print_r($_GET, 1));
			
			// Get member`s details
			// This is a bit tricky part: when we save the transaction we have all the info for transaction, product and member. However, when we process
			// the transaction, paypal does not return this information to us. So without extracting needed information bellow, we would add in database
			// almsot empty transaction, without product ID and member ID. The query bellow is executed in order to get missing info and attach it to
			// the paypal postback in order to properly update all needed info and save the rebill transaction with all the info. 
			$member = $SQL->exec2row("SELECT * FROM `payments_transactions` WHERE `paypal_recurring_payment_id` = ? AND `id_member` != '' AND `id_product` != '' LIMIT 1 ", 
					$_POST['recurring_payment_id']);

			// Security check - email should correspond to ID of the member. In case we don`t have member`s ID - email is invalid. 
			if (!$member['id_member'])
				return false;
			
			// Build the transaction
			$transaction = array();
			$transaction['raw_postback'] 	= print_r($_POST, 1);
			$transaction['id_member'] 		= $member['id_member'];
			$transaction['id_product'] 		= $member['id_product'];
			$transaction['paypal_payer_id'] = $_POST['payer_id'];
			$transaction['processor'] 		= $proc . "-rebill";
			$transaction['sum_transaction'] = $_POST['mc_gross'];
			$transaction['date_processed'] 	= date("Y-m-d H:i:s");
			$transaction['paypal_token'] 	= $member['paypal_token'];
				
			$transaction['paypal_recurring_payment_id'] = $_POST['recurring_payment_id'];
				
			if ($_POST['payment_status'] == "Completed")
				$transaction['status_transaction'] 		= "accepted";

			
			// Mark, that this is an empty transaction, that only marks/report that the payemnt profile was created.
			if ($_POST['txn_type'] == "recurring_payment_profile_created")
				$transaction['status_transaction'] 		= "paypal-rpc"; // Recurring payment created.

			// Mark, that this is an empty transaction, that only marks/report that the payemnt profile was cancelled.
			if ($_POST['txn_type'] == "recurring_payment_profile_cancel")
				$transaction['status_transaction'] 		= "paypal-cancel"; // Recurring payment cancelled.
				
			// When a recurring payment transaction is made we receive three posts from Paypal - one for aknowledging that user accepted the payment
			// one for creation of the recurring payment profile and another one for the initial payment. There for the third transaction duplicate the first one
			// Paypal advices not to use the third one, as it could be delayed. There for make the query bellow, to recognize if we already added TODAY 
			// the initial payment and just mark duplicating transaction as -init(ial). On the next day the TODAY match won`t be triggered, there for
			// we accept it as a normal rebill transaction and add it to the sum of the client.
			else if ( $SQL->exec2row("SELECT * FROM `payments_transactions` WHERE `processor` = 'paypal' AND `status_transaction` = 'accepted' AND `paypal_recurring_payment_id` = '".$_POST['recurring_payment_id']."' AND DATE(`date_processed`) = CURDATE()") )
				$transaction['status_transaction'] 		= "paypal-init"; // Initial payment note.
				
			// Pass transaction for save.
			$trn_id = $this->_save_transaction($transaction);
			
			if ($trn_id)
				if ($callback) $callback($SQL->exec2row("SELECT * FROM `payments_transactions` t LEFT JOIN `payments_products` p  on t.id_product=p.id WHERE t.id_transaction = ?", $trn_id));
	
		}
		
		
		
		
		/////////////////////// When user go to paypal payment page, but click on "Cancel"
		if ($status == "declined")
		{
			$query = "UPDATE `payments_transactions` SET `status_transaction`=?, `processor`='paypal', `date_processed` = NOW(), `raw_postback` =concat(`raw_postback`, ? ), `paypal_payer_id` =?  WHERE `paypal_token` = ? ";
			$SQL->exec($query, "canceled", "Initial values (_GET) after we sent SetExpressCheckout:\n" . print_r($_GET,1), $_GET['PayerID'], $_GET['token']);
		}
		
		
		
		
		/////////////////////// When user go to paypal payment page and pay
		if ($status == "accepted")
		{
			$r = $SQL->exec2row("SELECT p.recurring, p.id, t.id_member FROM `payments_products` p LEFT JOIN `payments_transactions` t ON p.id = t.id_product WHERE t.paypal_token = ?", $_GET['token']);
			$_GET['id_product'] = $r['id'];
			$_GET['id_member'] 	= $r['id_member'];
				
			if ($r['recurring'])
				self::Paypal_CreateRecurringPaymentsProfile($_GET);
	
			// Added $reccuring (1/0) due to:
			// https://developer.paypal.com/docs/classic/express-checkout/integration-guide/ECReferenceTxns/
			else if (!self::Paypal_DoExpressCheckoutPayment($_GET, $r['recurring']))
				$status 		= "failed"; // Failed transaction - neither paid, nor canceled. More like internal error
				
			
			if (empty($_GET['PayerID']) AND !empty($this->params['PAYERID']))
				$_GET['PayerID'] = $this->params['PAYERID'];
			
			$query = "UPDATE `payments_transactions` SET `status_transaction`=?, `processor`='paypal', `date_processed` = NOW(), `raw_postback` =concat(`raw_postback`, ? ), `paypal_payer_id` =?  WHERE `paypal_token` = ? ";
			$SQL->exec($query, $status, "Initial values (_GET) after we sent SetExpressCheckout:\n" . print_r($_GET,1), $_GET['PayerID'], $_GET['token']);
			
			// requested function by Laurent - works like callback in case of successful transaction. It calls another internal function, that does something
			if ($callback) $callback($SQL->exec2row("SELECT * FROM `payments_transactions` t LEFT JOIN `payments_products` p  on t.id_product=p.id WHERE t.paypal_token = ?", $_GET['token']));
			
		}

		return;
	}
	
	/** Internal private methods */
	
	private function _save_transaction($data)
	{
		// Include DB methods
		global $SQL;
		
		// Product info
		$product = $SQL->exec2row("SELECT * FROM `payments_products` WHERE `id` = ?",  $data['id_product']);
		
		if (empty($data['sum_transaction'])) $data['sum_transaction'] = $product['price'];
		
		/** Insert transaction into database. If all OK - process_transactions() is suspposed to be called. */
		$this->trn_id = $SQL->insert('payments_transactions', $data);
		$this->id_product = $data['id_product'];
		
		return $this->trn_id;
	}	
	
	private function _process_transaction($trn_id=null)
	{
		global $SQL, $Phoenix;

		// Getting previously stored transactions details.
		$this->transaction 	= $SQL->query2row("SELECT * FROM `payments_transactions` WHERE `id_transaction` = " . $trn_id);
		$this->product 		= $SQL->query2row("SELECT * FROM `payments_products` WHERE `id` = " .  $this->transaction['id_product']);
		
		if (!$this->transaction['id_transaction'])
			throw new \Exception("No such transaction");

		
		$this->params["DESC"]									= ($this->product['proc_description']) ? $this->product['proc_description'] : "Purchase of '" . $this->product['product_name'] . "' from 2Fight";
		$this->params["L_BILLINGAGREEMENTDESCRIPTION0"]			= $this->params["DESC"];

		// Case, where we are processing subscrtiptions/reccurs
		if ($this->product['recurring'])
		{
			$this->params["L_BILLINGTYPE0"]						= "RecurringPayments";
			$this->params["AMT"]								= 0;
		}
		// Standart ONE-OFF payments parameters
		else 
			$this->params["AMT"]								= $this->product['price'];
			
		$this->params["PAYMENTREQUEST_0_AMT"]					= $this->params["AMT"];
		
		// Create transaction, save it in Paypal and get the token in return
		$SQL->exec("UPDATE `payments_transactions` SET `raw_postback` = concat(`raw_postback`, ?) WHERE `id_transaction`=?",
				"(1) Values we send for Inintial Call (for token):\n" . print_r($this->params, 1),  $trn_id);
				
		$this->res = $this->curl_call();
		$SQL->exec("UPDATE `payments_transactions` SET `raw_postback` = concat(`raw_postback`, ?) WHERE `id_transaction`=?",
				"(2) Values sent from Inintial Call (for token):\n" . print_r($this->res, 1),  $trn_id);
		
		
		// It is somewhat good idea, to log some information before we lose the focus. I.e. - redirect visitor somewhere.
		if (defined(PROCESSOR_LOG))
			file_put_contents(PROCESSOR_LOG, "[".date("Y-m-d H:i:s")."] Visitor redirected to:"  . $this->paypal_completion_url . $this->res['TOKEN'] . "\n");

		if($this->res['ACK'] == "Success") {
			// Save paypal token for further reference.
			$SQL->exec("UPDATE `payments_transactions` SET `paypal_token`='". $this->res['TOKEN']."' WHERE `id_transaction`='".$trn_id."'");
			header("Location: " . $this->paypal_completion_url . $this->res['TOKEN']);
			exit();
		}
		else 
			throw new \Exception("Error during Paypal first contact occured.");
	}
	
	// Paypal call for getting the Checkout details
	private function Paypal_GetExpressCheckoutDetails()
	{
		$this->params['METHOD'] = "GetExpressCheckoutDetails";
		return $this->curl_call();
	}
	
	// Paypal call for creating billing agreement - needed for recurring payments. 
	private function Paypal_CreateBillingAgreement()
	{
		$this->params['METHOD'] = "CreateBillingAgreement";
		$r = $this->curl_call($this->params);
	
		$SQL->exec("UPDATE `payments_transactions` SET `raw_postback` = concat(`raw_postback`, ?) WHERE `id_transaction`=?",
				"(5) Values sent from CreateBillingAgreement:\n" . print_r($r, 1),  $transaction['id_transaction']);
	
		// After you establish a billing agreement, you can initiate a payment, which withdraws funds from the buyer's PayPal account without manual intervention.
		// Call DoReferenceTransaction to use a reference transaction.
			
		$this->params["REFERENCEID"]	= $r['BILLINGAGREEMENTID'];
		$this->params["RECURRING"]		= "Y";
	
		return $r;
	}
	
	// Commit the transaction
	private function Paypal_DoExpressCheckoutPayment($data, $recurring=null)
	{
		global $SQL;
		
		$this->params["METHOD"]							= "DoExpressCheckoutPayment";
		
		$transaction = $SQL->query2row("SELECT * FROM `payments_transactions` WHERE `paypal_token` = '" . $data['token'] . "'");
		
		if (!$data['PayerID'])
		{
				$payer_details = self::Paypal_GetExpressCheckoutDetails();
	
			if ($payer_details['PAYERID'])
			{
				$data['PayerID'] = $payer_details['PAYERID'];
				$SQL->exec("UPDATE `payments_transactions` SET `paypal_payer_id` = ?, `raw_postback` = concat(`raw_postback`, ?) WHERE `id_transaction`=?",
						$payer_details['PAYERID'] , "User details returned by GetExpressCheckoutDetails:\n". print_r($payer_details, 1),  $transaction['id_transaction']);
			}
			else
				throw new \Exception("No payer ID returned from paypal. Cann`t continue with transaction.");
		}
		
		// Change settings for the recurring payments:
		// https://developer.paypal.com/docs/classic/express-checkout/integration-guide/ECReferenceTxns/
		$this->params["TOKEN"]							= $data['token'];
		$this->params["PAYERID"]						= $data['PayerID'];
		$this->params["AMT"]							= $transaction['sum_transaction'];
		
		$SQL->exec("UPDATE `payments_transactions` SET `raw_postback` = concat(`raw_postback`, ?) WHERE `id_transaction`=?",
				"(OUT Final) Values sent from us to DoExpressCheckoutPayment:\n" . print_r($this->params, 1),  $transaction['id_transaction']);
		
		$result = $this->curl_call($this->params);
		$SQL->exec("UPDATE `payments_transactions` SET `raw_postback` = concat(`raw_postback`, ?) WHERE `id_transaction`=?",
			"(IN Final) Values sent from DoExpressCheckoutPayment:\n" . print_r($result, 1),  $transaction['id_transaction']);

		if ( $result['ACK'] == "Success" ) 
			return 1;
		else 
			return 0;
	}

	private function Paypal_CreateRecurringPaymentsProfile($data)
	{
		global $SQL;

		$transaction 	= $SQL->query2row("SELECT * FROM `payments_transactions` WHERE `paypal_token` = '" . $data['token']."'");
		$product 		= $SQL->query2row("SELECT * FROM `payments_products` WHERE `id` = " .  $transaction['id_product']);
		
		if (!$product['billing_period'])
			return 0; // Avoid using "throw" as after this function, there is another one, that should be executed in each case. So - no messages, if not recurring - simply return.
		
		// Adjust billing period and frequence according to the paypal manual:
		// https://developer.paypal.com/docs/classic/api/merchant/CreateRecurringPaymentsProfile_API_Operation_NVP/
		
		switch ($product['billing_period'])
		{
			case "day":
				$BILLINGPERIOD 		= "Day";
				$BILLINGFREQUENCY	= "1";
			break;
			case "halfmonth":
				$BILLINGPERIOD 		= "SemiMonth";
				$BILLINGFREQUENCY	= "1";
			break;
			case "month":
				$BILLINGPERIOD 		= "Month";
				$BILLINGFREQUENCY	= "12";
			break;
			case "year":
				$BILLINGPERIOD 		= "Year";
				$BILLINGFREQUENCY	= "1";
			break;
			
			// Left the default billing period to be one week/every week. Sounded more logical
			default:
				$BILLINGPERIOD 		= "Week";
				$BILLINGFREQUENCY	= "52";				
			break;
			
		}
		
		$this->params["METHOD"]							= "CreateRecurringPaymentsProfile";
		$this->params["TOKEN"]							= $data['token'];
		$this->params["PAYERID"]						= $data['PayerID'];
		
		$this->params["DESC"]							= ($product['proc_description']) ? $product['proc_description'] : "Purchase of '" . $product['product_name'] . "'";
		$this->params["L_BILLINGAGREEMENTDESCRIPTION0"]	= $this->params["DESC"];
		$this->params["PROFILESTARTDATE"]				= date("Y-m-d") . "T00:00:00Z";
		$this->params["CURRENCYCODE"]					= "EUR";
		$this->params["MAXFAILEDPAYMENTS"]				= "3"; // According to the manual, if user fails 3 times to pay, we stop his payment cycle
		
		$this->params["BILLINGPERIOD"]					= $BILLINGPERIOD;
		$this->params["BILLINGFREQUENCY"]				= $BILLINGFREQUENCY;
		$this->params["TOTALBILLINGCYCLES"]				= "0"; // According to the manual we put this to 0, in order to bill user forever, until he stops it.
		
		$this->params["AMT"]							= $transaction['sum_transaction'];
		//$this->params["INITAMT"]						= $transaction['sum_transaction'];
		
	
		$SQL->exec("UPDATE `payments_transactions` SET `raw_postback` = concat(`raw_postback`, ?) WHERE `id_transaction`=?",
				"(3) This is what we send as CreateRecurringPaymentsProfile:\n" . print_r($this->params, 1),  $transaction['id_transaction']);
		
		$result = $this->curl_call($this->params);

		$SQL->exec("UPDATE `payments_transactions` SET `paypal_recurring_payment_id`=?, `raw_postback` = concat(`raw_postback`, ?) WHERE `id_transaction`=?",
				$result['PROFILEID'], "(4) Values sent from CreateRecurringPaymentsProfile:\n" . print_r($result, 1),  $transaction['id_transaction']);
		
		if ( strpos($result['ACK'], "Success") ) 
			return 1;
		else 
			return 0;		
		
	}
	
	// It`s somewhat good idea, to keep this function per class. As every class may have different way for calling the URLs.
	private function curl_call($params=null)
	{
		$p = ($params) ? $params : $this->params;
		//create name value pairs seperated by &
		$post_data = http_build_query($p);
		
		if (defined(PROCESSOR_LOG))
			file_put_contents(PROCESSOR_LOG, "[".date("Y-m-d H:i:s")."] Paypal Data:"  . $post_data . "\n");
		
			///// Ask Paypal a token to send the user to the form with it
			$ch = curl_init($this->paypal_token_url);
			curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false); // --insecure
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($ch, CURLOPT_POSTFIELDS, $post_data);
		
			$result = curl_exec($ch);
			curl_close($ch);
		
			// Format = "var=value&var=etc"
			$vals = explode("&", $result);
			foreach ($vals as $val)
			{
				list($key, $value) = explode("=", $val);
				$r[$key] = urldecode($value);
			}
			
		return $r;
	}
}

		
		
		
		
		