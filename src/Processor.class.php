<?php

namespace Phoenix\Payment;

/*!
	Brief explanation of plugin new processor:
	THIS is the abstract class, that every new processor class, should follow. The class is abstract (not interface) so programmer is allowed to
	add new functions, when needed. 
	Basic functions for each processor is:
	save_transaction - where we send the transactions details in our database, either for further reference/statistics or for further process of the transaction
	validate - we describes the rule that should match in order to process the transaction. This might be different for different processors
	process_transaction - usually, the method where we send the transaction details to the processor. It might return the response immediatelly, or later (ping)
	If processor returns data immediatelly, we are processing the post back direclty into the function. Otherwise, we might use the static method 
	process_postback() directly from the caller, passing $_GET or $_POST to the function (depends on the returned result)
	All other methods are optional and depends on the processor`s specification. 
	Due to the autocaller way of invoking the Processor class, the name of the processor is expected to Start with upper case letter. Ie:
	class Paypall extends Processor {}
	Every particular class should extends THIS abstract class - Processor
	
	send_request() and get_postback() are supposed to be used for the non-immediatelly response of the processor (ping) (Not implemented yet)
	
	In case you want to log the transactions in Processors you should 
	define("PROCESSOR_LOG", [path_to_log_file]);
	in your config file
*/
abstract class Processor
{
	/** Function holder for all request operations that are sent to the server of the payment processor
		@param $transation at least id of the transaction (??)
	*/
	public function send_request($transaction=null) {}

	/** Function holder for everything we might receive from the payment processor. */
	public function get_postback($data) {}
	
	/** Holder for possible statistics page, if needed.
		@param $transation if trn_id is given - return details for that transaction. Otherwise - stats for all */
	public function stats($transaction=null) {}
	
	/** The very first step is to validate $data, and on success - we should save transaction in our database. Then we`ll process it. */
	public function save_transaction($data) {}
	
	/** Once we have saved transaction we need to process it to the payment processor */
	public function process_transaction($data, $callback) {}
	
	
	
	
	/** We may need this function in order to internally validate the transaction, before we send it to the processor.
		@param $data data that is supposed to be validated, according to the rules of particular processor.
		Could be overrided in child class, by defining new rules in child::validate() or extended in same manner, 
		but calling parent::validate() in child function first. */
	public function validate($data)
	{
		if ( !is_array($data) )
			throw new \Exception("No valid data for transaction.");
		
		if (empty($data['id_member']))
			throw new \Exception("Missing data for member.");
		
		if (empty($data['id_product']))
			throw new \Exception("No product selected.");
			
		return true;
	}	
	
	/** Extract products related to the particular processo and (optional, if geoip module is included) related to the country of origin. 
	    Extract and return all products related to this processor
	    In case you need to show distinct products (ie. you duplicate products per processor), set distinct=true in the caller:
	    Processor::list_products(1); */
	public static function list_products($distinct = false)
	{
		global $SQL;

		if ($distinct) 
			$subq = "DISTINCT(`product_name`) ";
		else 
			$subq = " id, product_name, processor, price ";
		$query = "SELECT $subq FROM `payments_products` WHERE 1";

		if (function_exists('geoip_country_code_by_name'))
		{
			$country = strtolower(geoip_country_code_by_name($_SERVER['REMOTE_ADDR']));
			if (!empty($country))
				$query .= " AND `allowed_country` IN ('', '$country')";
		}
		
		$query .= " AND `active` = 1";
		
		//echo $query;
		return $SQL->exec2assoc($query);
	}	
	
	/** This function is related to two_steps form, where first we get the product, then we pass the name of the product to this function
	    and it returns list of the available processors for this product. 
	    usage:
	    (step 1) Processor::list_products(1); - get the products, distinctive, by their names
	    (step 2) Processor::list_processor_per_products('30 Tokens'); - get the processor avialable for purchase of 30 tokens (has 30 tokens in product_name) */
	public static function list_processor_per_products($name)
	{
		global $SQL;
		
		$query = "SELECT `proc_description`,`processor`, `id` FROM `payments_products` WHERE `product_name` = ?";

		if (function_exists('geoip_country_code_by_name'))
		{
			$country = strtolower(geoip_country_code_by_name($_SERVER['REMOTE_ADDR']));
			if (!empty($country))
				$query .= " AND `allowed_country` IN ('', '$country')";
		}
		
		$query .= " AND `active` = 1";
		
		//echo $query;
		return $SQL->exec2assoc($query, $name);
	}	
	
	/** We could use this function to check out what user had paid for (the entities) and return it as digit. Member should be logged first. 
	    Useful if you want to show "You have currently X tokens" on the site. For "X" you call this function. */
	public static function get_my_entities()
	{
		global $SQL, $Member;
		
		$id = (int) $Member->infos['id'];
		if (!$id) return 0;
		
		$query = "
				SELECT SUM(p.entities) as total
				FROM `payments_transactions` t 
					LEFT JOIN `payments_products` p
						ON t.id_product = p.id
				WHERE t.id_member = '$id' 
					AND t.status_transaction = 'accepted'
				";
	
		//echo $query;
		$res = $SQL->exec2cell($query);
		return (int)$res;
	}
	
}
