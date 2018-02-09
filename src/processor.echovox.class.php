<?php
namespace Phoenix\Payment;

class Echovox extends Processor
{
	public static $processor 	= "echovox";
	
	
	//protected $url = "https://openapi.echovox.net/routing/subscribe";
	public $url 		= "http://front.mobplus.biz/purchase"; 		// The url to make call to it.

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
	
	// When processor report to us the details of transaction (ref=id) - we update the status of it in our DB
	public static function process_postback($callback=null)
	{
		global $SQL;
		if (!empty($_GET['STATUS']))
		{
			$string = "\nSecond postback when processor send details for transaction - if it is paid or not.\n";
			$status = ( $_GET['STATUS'] == "SUB_SUCCESS" ) ? "accepted" : "declined";
		}
		else 
		{
			$string = "The initial postback when processor accepts the transaction and move it to the mobile side.\n";
			$status = "pending";
		}
			
		$trn_id = $_GET['REF'];
		
		$query = "UPDATE `payments_transactions` SET `status_transaction`=?, `date_processed` = NOW(), `raw_postback` = concat(`raw_postback`, ?, '\n\n') WHERE `id_transaction` = ? ";
		return $SQL->exec($query, $status, $string . print_r($_GET, 1), $trn_id);
		
		if ($callback AND $status == "accepted") $callback($SQL->exec2row("SELECT * FROM `payments_transactions` t LEFT JOIN `payments_products` p  on t.id_product=p.id WHERE t.id_transaction = ?", $trn_id));
	}
	
	/** Internal private methods */
	
	private function _process_transaction($trn_id)
	{
		global $Phoenix, $SQL;
		
		$t = $SQL->exec2row("SELECT * FROM `payments_transactions` WHERE `id_transaction` = " . $trn_id);
		
		
		$this->params  = "?AID=".$Phoenix['Payment']['echovox']['aid'];
		$this->params .= "&SID=".$this->product['echovox_sid'];
		$this->params .= "&REF=".$trn_id;
		
		
		// If we have already collected the visitor`s mobile number - we pass it to Echovox. Suitable for case, when we have our custom form.
		// In order to use this scenario we need to gave form field "echovox_msisdn" where we ask visitor for his number (in format +112233445566) and save it along
		// with other details in transaction. Should be url_encoded($_POST['echovox_msisdn'])
		if($t['echovox_msisdn'])
			$this->params .= "&MSISDN=".$t['echovox_msisdn'];
				
		
		// It is somewhat good idea, to log some information before we lose the focus. I.e. - redirect visitor somewhere.
		if (defined(PROCESSOR_LOG))
			file_put_contents(PROCESSOR_LOG, "[".date("Y-m-d H:i:s")."] Visitor redirected to:"  . $this->url . $this->params . "\n");
		// Post information for this transaction to the processor and update status in DB
		// echo(" Location: " . $this->url . $this->params);	
		header("Location: " . $this->url . $this->params);	
	}
	

	
	private function _save_transaction()
	{
		// Include DB methods
		global $SQL, $Member;
		
		// Product info
		$this->product = $SQL->query2row("SELECT * FROM `payments_products` WHERE `id` = " . $_GET['product_id']);
		
		
		if (!$this->product['echovox_sid'])
			throw new \Exception("This product can not be purchased with Echovox SMS. Please, select another option.");

		$transaction = array();
		$transaction['raw_postback'] 	= print_r($_GET, 1);
		$transaction['id_member'] 		= $$Member->infos['id'];
		$transaction['id_product'] 		= $this->product['id'];
		$transaction['processor'] 		= self::$processor;
		$transaction['sum_transaction'] = $this->product['price'];
		
				
		/** Insert transaction into database. If all OK - process_transactions() is suspposed to be called. */
		$this->trn_id = $SQL->insert('`payments_transactions`', $transaction);
		$this->id_product = $this->product['id'];
		
		return $this->trn_id;
	}
	
}

		
		
		
		
		
