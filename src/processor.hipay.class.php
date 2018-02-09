<?php
namespace Phoenix\Payment;
// Hipay = allopass!

class Hipay extends Processor
{
	public static $processor = "hipay"; // Internal recognition to the processor.
	
	public function __construct()
	{
		global $Phoenix;
		
		// Obtain data given in config file.
		$this->site_id = $Phoenix['Payment']['hipay']['site_id'];
	}

	public function save_transaction($data)
	{
		if ($this->validate($data))
			$this->_save_transaction($data);
	}
	
	public function process_transaction($trn_id)
	{
		if ($trn_id)
			$this->_process_transaction($trn_id); // Private method per class
	}
	
	public static function process_postback($callback=null)
	{
		global $SQL;
		
		$transaction_id = $_GET['data'];
		
		if (!empty($_GET['status']))
			$status = $_GET['status'];
		else 
			$status = "pending";
		
		if ($callback AND $status == "accepted") $callback($SQL->exec2row("SELECT * FROM `payments_transactions` t LEFT JOIN `payments_products` p  on t.id_product=p.id WHERE t.id_transaction = ?", $_GET['data']));
			
		$query = "UPDATE `payments_transactions` SET `status_transaction`=?, `date_processed` = NOW(), `hipay_transaction_id`=?, `raw_postback` = concat(`raw_postback`, ?) WHERE `id_transaction`=?";
		
		return $SQL->exec($query, $status, $_GET['trxid'], print_r($_GET, 1), $transaction_id );
	}
	
	/** Internal private methods */
	
	private function _save_transaction($data)
	{
		// Include DB methods
		global $SQL;
		
		// Product info
		$product = $SQL->exec2row("SELECT * FROM `payments_products` WHERE `id` = " . $data['id_product']);
		
		if (!$product['hipay_product_id'])
			throw new \Exception("This product can not be purchased with " . $this->processor);
		
		$data['sum_transaction'] = $product['price'];
		$data['processor'] 		 = self::$processor;
		
		/** Insert transaction into database. If all OK - process_transactions() is suspposed to be called. */
		$this->trn_id = $SQL->insert('`payments_transactions`', $data);
		$this->id_product = $data['id_product'];
		
		return $this->trn_id;
	}
	
	private function _process_transaction($trn_id)
	{
		global $SQL;
		
		$transaction 	= $SQL->query2row("SELECT * FROM `payments_transactions` WHERE `id_transaction` = " . $trn_id);
		$product 		= $SQL->query2row("SELECT * FROM `payments_products` WHERE `id` = " .  $transaction['id_product']);
		
		
		$url = "https://payment.allopass.com/buy/buy.apu?ids=" . $this->site_id . "&idd=". $product['hipay_product_id'] . "&data=". $trn_id;
	
		// It is somewhat good idea, to log some information before we lose the focus. I.e. - redirect visitor somewhere.
		if (defined(PROCESSOR_LOG))
			file_put_contents(PROCESSOR_LOG, "[".date("Y-m-d H:i:s")."] Visitor redirected to: $url\n");
			// Post information for this transaction to the processor and update status in DB
			// echo(" Location: " . $url);
		header("Location: " . $url);
		exit;
	}
	
	// It`s somewhat good idea, to keep this function per class. As every class may have different way for calling the URLs. 
	private function curl_call($params=null)
	{
		$p = $params ? : $this->params;
		//create name value pairs seperated by &
		$post_data = http_build_query($p);
	
		if (defined(PROCESSOR_LOG))
			file_put_contents(PROCESSOR_LOG, "[".date("Y-m-d H:i:s")."] Hipay Data:"  . $post_data . "\n");
	
		///// Ask Paypal a token to send the user to the form with it
		$ch = curl_init($this->url);
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

		
		
		
		
		