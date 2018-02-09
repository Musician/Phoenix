<?php
namespace Phoenix\Payment;

/*!
	Class Sale
		- extends like a wrapper to the processor(s) clases. You`d invoke only this case, passing parameters like member`s ID, processor and the product
		  and it is supposed to the the whole rest process. 
		- Please, note, that this class is intended ONLY for Sales, and do not process the postback.
		- usage:
		  new Sale($_POST["processor"], $_POST["id_product"]);
		  when we gather all needed info, and trigger the line above, the visitor will be redirected to processor`s page so don`t write any HTML code 
*/
class Sale
{
	public function __construct($processor, $product_id)
	{
		$this->product_id = $product_id;
		
		require_once(PATH_PHOENIX_MODULES.'Payment/src/Processor.class.php');
		
		switch ($processor)
		{
			case "paypal":
				require_once(PATH_PHOENIX_MODULES.'Payment/src/processor.paypal.class.php');
			break;
			
			case "echovox":
				require_once(PATH_PHOENIX_MODULES.'Payment/src/processor.echovox.class.php');
			break;

			case "hipay":
				require_once(PATH_PHOENIX_MODULES.'Payment/src/processor.hipay.class.php');
			break;			
			
			case "hipaycc":
				require_once(PATH_PHOENIX_MODULES.'Payment/src/processor.hipaycc.class.php');
			break;
			
			default:
				throw new \Exception("Processor not recognized.");
			break;
		}
		
		$proc = "Phoenix\\Payment\\".ucfirst($processor);
		$this->processor = new $proc;
		$this->make_sale();
		
	}
	
	public function make_sale()
	{
		global $Member;
		if ($this->product_id)
			$this->processor->save_transaction(array("id_member"=>$Member->infos['id'], "id_product"=>$this->product_id ));
				
		if ($this->processor->trn_id)
			$this->processor->process_transaction($this->processor->trn_id);
		
	}
	
	
	
}
