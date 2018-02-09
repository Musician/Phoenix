<?php
/**
// Documentation:
// https://docs.google.com/document/d/19Ix6X5472jVSEinFKzJl1qvp2IkmOLM899Pe9GBudv8/edit#

	The payment process consists of two major parts - the Sale and the Postback. 
	 - The sale is the moment when visitor goes to our page, check our products and decides to buy some of them. He clicks on the "Buy" 
	   button and that is the Sale. In this first step we save the transaction info (who make this purhcase, what he bought, what processor he want to use)
	   and once the transaction is saved we call process_transaction (after validation, if needed).
	   
	   This whole process is wrapped into Sale.class.php, so the developer only should need to collect the info about the product and the processor and call
	  
	  	require_once(PATH_PHOENIX_MODULES.'Payment/src/Sale.class.php');
	  	$sale = new Sale($processor, $product);
	   
	   how he will show the products and the processor, one-click or two-click form, is a question of design and implementation. At the point when dev has 
	   this two parameters - processor and product (the product ID from the database) he is ready to trigger the Sale().
	   
	 - The postback is when visitor is returned from the payment processor page. He brings with him (in GET or in POST) all the information for
	   the sale - if he paid or cancled, what is the processor`s transaction ID, his details (ie. paypal mail, shipping address, names) etc. 
	   We are supposed to process_postback() with that info. This includes reading details about our transaction ( saved in Sale() ), adjusting the transaction
	   details and update the status of the transaction - paid, cancled, etc. 
	   
	   This whole process is wrapped in Postback.class.php, so the developer would only need to call
	   
	 	require_once(PATH_PHOENIX_MODULES.'Payment/src/Postback.class.php');
	  	$pb = new Postback($callback);
	   
	   Postback will take care of reading, saving/updating all the information regarding the transaction. If $callback function is used - Postback will 
	   execute that function (ie. will update some table into database) with information regarding the transaction and the product. 
	   
*/
namespace Phoenix\Payment;

class Payment
{

	//! Needed even if not used, to show this is a Phenix module.
	public function phoenix_ajax() {
	}
	//! This is run at reconfig only.
	public function phoenix_conf(&$Phoenix) {

	}
	
	public function phoenix_conf_after()
	{
		global $SQL;
		
		$SQL->query("
			CREATE TABLE IF NOT EXISTS `payments_products` (
			  `id` int(11) NOT NULL AUTO_INCREMENT,
			  `product_name` char(50) NOT NULL,
			  `price` float(4,2) NOT NULL,
			  `entities` int(5) NOT NULL,
			  `allowed_country` char(2) NOT NULL,
			  `processor` char(50) NOT NULL,
			  `recurring` int(1) NOT NULL DEFAULT '0',
			  `echovox_sid` char(100) NOT NULL,
			  `billing_period` char(10) NOT NULL,
			  `hipay_product_id` char(100) NOT NULL,
			  `proc_description` char(200) NOT NULL,
			  `active` int(1) NOT NULL DEFAULT '1',
			  PRIMARY KEY (`id`)
			) ENGINE=MyISAM DEFAULT CHARSET=utf8;	
		");
		
		$SQL->query("
			CREATE TABLE IF NOT EXISTS `payments_transactions` (
			  `id_transaction` int(11) NOT NULL AUTO_INCREMENT,
			  `date_initiated` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
			  `date_processed` datetime NOT NULL,
			  `sum_transaction` float(4,2) NOT NULL,
			  `status_transaction` char(20) NOT NULL DEFAULT 'pending',
			  `processor` char(50) NOT NULL,
			  `paypal_token` char(30) NOT NULL,
			  `paypal_payer_id` char(50) NOT NULL,
			  `paypal_recurring_payment_id` char(20) NOT NULL,
			  `echovox_msisdn` char(30) NOT NULL,
			  `hipay_transaction_id` char(50) NOT NULL,
			  `id_member` int(11) NOT NULL,
			  `id_product` int(11) NOT NULL,
			  `raw_postback` text NOT NULL,
			  PRIMARY KEY (`id_transaction`)
			) ENGINE=MyISAM DEFAULT CHARSET=utf8;
		");
		
		$SQL->query("
			CREATE TABLE IF NOT EXISTS `payments_paypal_ipn_log` (
			  `id` int(11) NOT NULL AUTO_INCREMENT,
			  `post` text NOT NULL,
			  `get` text NOT NULL,
			  `received` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
			  PRIMARY KEY (`id`)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8;
		");
	}
}

