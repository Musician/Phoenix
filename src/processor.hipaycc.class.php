<?php


///////// At the moment we use only the Hipay class, which made this one useless and obsolete. DO NOT USE THIS ONE. USE processor.hipay.class.php INSTEAD.



namespace Phoenix\Payment;
// Hipay = allopass!

class Hipaycc extends Processor
{
	public $processor = "hipaycc"; // Internal recognition to the processor.
	
	public function __construct()
	{
		global $Phoenix;

		$this->url										= "https://";
		if ($Phoenix['Payment']['hipay']['testmode'])
			$this->url									.= "test-";
		$this->url							 			.= "ws.hipay.com/soap/payment-v2/generate";
		
		// Obtain data given in config file.
		$this->api_username = $Phoenix['Payment']['hipay']['api_username'];
		$this->api_password = $Phoenix['Payment']['hipay']['api_password'];

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
	
	public static function process_postback($data, $callback=null)
	{
		global $SQL;
		
		$transaction_id = $data['data'];
		
		if (!empty($data['status']))
			$status = $data['status'];
		else 
			$status = "pending";
		
		if ($callback AND $status == "accepted") $callback($data);
			
		$query = "UPDATE `payments_transactions` SET `status_transaction`=?, `date_processed` = NOW(), `hipay_transaction_id`=?, `raw_postback` = concat(`raw_postback`, ?) WHERE `id_transaction`=?";
		
		return $SQL->exec($query, $status, $data['trxid'], print_r($data, 1), $transaction_id );
	}
	
	/** Internal private methods */
	
	private function _save_transaction($data)
	{
		// Include DB methods
		global $SQL;
		
		// Product info
		$product = $SQL->exec2row("SELECT * FROM `payments_products` WHERE `id` = " . $data['id_product']);
		
		$data['sum_transaction'] = $product['price'];
		
		/** Insert transaction into database. If all OK - process_transactions() is suspposed to be called. */
		$this->trn_id = $SQL->insert('`payments_transactions`', $data);
		$this->id_product = $data['id_product'];
		
		return $this->trn_id;
	}
	
	private function _process_transaction($trn_id)
	{
		global $SQL, $Phoenix;
		
		// Get details for the trasnaction and the product. 
		$transaction 	= $SQL->query2row("SELECT * FROM `payments_transactions` WHERE `id_transaction` = " . $trn_id);
		$product 		= $SQL->query2row("SELECT * FROM `payments_products` WHERE `id` = " .  $transaction['id_product']);
	
	///// code taken from Hipay Help page
		// Define credentials
		$this->credentials = $this->api_username . ':' . $this->api_password;
		
		// Create query parameters
		$this->params = array(
				"wsLogin" => "fe9522ab5e747656afb7c8f7a8fdf600",
				"wsPassword" => "9af2c7ccfe35ca7f4bd02a2377407f45",
				"websiteId" => "365629",
				"categoryId" => "1",
				"currency" => "EUR",
				"amount" => "10.00",
				"rating" => "ALL",
				"locale" => "en_GB",
				"customerIpAddress" => "192.168.1.1",
				"executionDate" => "2016-06-06T19:57:55",
				"manualCapture" => 0,
				"description" => "TEST",
				"urlCallback" => "http://test.aur.bg/postback.php",
		);
		
		$query = "UPDATE `payments_transactions` SET `raw_postback` = concat(`raw_postback`, ?) WHERE `id_transaction`=?";
		$SQL->exec($query, "This parameters was sent to\n" . $this->url . "\n" .  print_r($this->params, 1), $transaction['id_transaction'] );
		
		$xml_data = new \SimpleXMLElement('<?xml version="1.0"?><soap></soap>');
		$this->array_to_xml($this->params,$xml_data);
		$xml = $xml_data->asXML();
		print_r($xml); exit;
		
		// Execute the call 
		$result = $this->curl_call($xml); 
		
		$query = "UPDATE `payments_transactions` SET `raw_postback` = concat(`raw_postback`, ?) WHERE `id_transaction`=?";
		$SQL->exec($query, "Returned result:\n" . "\n" .  print_r($result, 1), $transaction['id_transaction'] );
		
		print_r($result); exit;
		// Clean the answer
		if ($result['status'])
		{
			$status = $result['status'];
			$response = json_decode($result['plain_result']);
	
			// Get payment page URL
			header("Location: " . $response->forwardUrl);
			exit;
		}
		else 
		{
			echo ($result['error_message']);
			exit;
		}
				
		
		// Code taken from Hipay Help page.
	
	}
	
	// It`s somewhat good idea, to keep this function per class. As every class may have different way for calling the URLs. 
	private function curl_call($xml=null)
	{
		
		if (defined(PROCESSOR_LOG))
			file_put_contents(PROCESSOR_LOG, "[".date("Y-m-d H:i:s")."] Hipay Data:"  . $post_data . "\n");

		$ch = curl_init($this->url);
		
		curl_setopt($ch, CURLOPT_TIMEOUT, 30);
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($ch, CURLOPT_USERAGENT, "HIPAY");
		curl_setopt($ch, CURLOPT_POSTFIELDS, 'xml=' . urlencode($xml));
			
		curl_setopt($ch, CURLOPT_HEADER, false);
		
		curl_setopt($ch, CURLOPT_USERPWD, $this->credentials);

		$status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
		
		$result = curl_exec($ch);
		curl_close($ch);
		/*print_r($header); echo "<br />";
		print_r($this->credentials);echo "<br />";
		print_r($post_data);echo "<br />";
		
		exit;
*/
		
		
		$r['status'] = $status;
		$r['full_result'] = json_decode($result);
		$r['plain_result'] = $result;
		$r['error_message'] = $r['full_result']->message;
		return $result;
	}
	
	// function defination to convert array to xml
	function array_to_xml( $data, &$xml_data ) {
		foreach( $data as $key => $value ) {
			if( is_array($value) ) {
				if( is_numeric($key) ){
					$key = 'item'.$key; //dealing with <0/>..<n/> issues
				}
				$subnode = $xml_data->addChild($key);
				array_to_xml($value, $subnode);
			} else {
				$xml_data->addChild("$key",htmlspecialchars("$value"));
			}
		}
	}
}

		
		
		
		
		
