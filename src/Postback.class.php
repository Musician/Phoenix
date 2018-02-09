<?php
namespace Phoenix\Payment;

/*!
	Class Postback
		- extends like a wrapper to the processor(s) clases. You`d invoke only this case, passing parameters like $callback
		  and it is supposed to the the whole rest process. 
		- Please, note, that this class is intended ONLY for Postback, and do not process the sales.
		- usage:
		  $pb = new Postback($callback);
	
	@param callable $callback  Executed on a successful transaction (payment or rebill).
		This anonymous function takes only one parameter, array $data filled with the transaction and product.
		$data = array('id_product'=>.., 'product_name'=>..., 'price'=>....., 'id_transaction'=>.., 'date_processed'=>.., 'sum_transaction'=>...);
		The developer can then update on his side what needs to be updated.
		Example:
		new Postback(function($data) {
		    echo $data['id_product'];
		   // Updating things to show the sale was done.
		});

*/
class Postback
{
	public function __construct($callback=null)
	{
		require_once(PATH_PHOENIX_MODULES.'Payment/src/Processor.class.php');
		
		// Recognize Echovox postback by transaction ID and process the transaction.
		if (!empty($_GET['REF']))
		{
			require_once(PATH_PHOENIX_MODULES.'Payment/src/processor.echovox.class.php');
				
			Echovox::process_postback($callback);

			$this->ok = 1;
		}
		
		// Recognize Paypal postback by transaction ID and process the transaction.
		else if (!empty($_GET['p']) AND (!empty($_GET['token']) OR !empty($_POST)))
		{
			require_once(PATH_PHOENIX_MODULES.'Payment/src/processor.paypal.class.php');
				
			// Paypal needs to go thru __construct in order to get it`s details (Not working outside OOP context)
			$paypal = new Paypal();
			$paypal->process_postback($callback);
		
			$this->ok = 1;
		}
		
		// Recognize Hipay postback by given ID and process the transaction.
		else if (!empty($_GET['proc']) AND $_GET['proc'] == "hipay" ) 
		{
			require_once(PATH_PHOENIX_MODULES.'Payment/src/processor.hipay.class.php');
				
			Hipay::process_postback($callback);
			$this->ok = 1;
		}
		
		else
			$this->ok = 0; // All from the above fails so we return 0. 
	}
	
}