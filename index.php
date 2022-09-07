<?php
define("SAMBA_USERNAME", "Administrator");
define("SAMBA_PASSWORD", "1111");
define("SAMBA_GRANT_TYPE", "password");
define("SAMBA_CLIENT_ID", "pmpos");
define("SAMBA_AUTH_URL", "http://localhost:9000/Token");
define("SAMBA_API_URL", "http://localhost:9000/api/graphql");

//define("WC_KEY", "ck_52b096f8488fdcf334e114cc864b68047be1de55");
//define("WC_SECRET", "cs_afedfa4daf9a6b0525b626d1a2f77d999a4e0637");
//define("WC_URL", "https://www.donerhouse.kz");
//define("WC_STATUS", "processing,on-hold");

define("WC_KEY", "ck_ae464c94047e7f2fde73d99dcd18b6811beab0aa");
define("WC_SECRET", "cs_8fdf991635ae8c42f2e47cb222f01746b1d65165");
define("WC_URL", "http://localhost/storefront/");
define("WC_STATUS", "pending");

define("TICKET_TYPE", "Доставка");
define("TICKET_DEPARTMENT", "Доставка");
define("TICKET_USER", "Веб-сайт");
define("TICKET_TERMINAL", "Сервер");
define("TICKET_ENTITY_TYPE", "Клиенты");
define("TICKET_CALCULATION", "Скидка");
define("TICKET_DELIVERY_ITEM", "Доставка до 10км");
define("TICKET_DELIVERY_NAME", "Доставка");
define("TICKET_MISC_PRODUCTS", "Misc");
define("TICKET_STATUS", "Не подтверждено");


require __DIR__ . '/lib/HttpClient/BasicAuth.php';
require __DIR__ . '/lib/HttpClient/HttpClient.php';
require __DIR__ . '/lib/HttpClient/HttpClientException.php';
require __DIR__ . '/lib/HttpClient/OAuth.php';
require __DIR__ . '/lib/HttpClient/Options.php';
require __DIR__ . '/lib/HttpClient/Request.php';
require __DIR__ . '/lib/HttpClient/Response.php';
require __DIR__ . '/lib/Client.php';
require __DIR__ . '/functions.php';

use Automattic\WooCommerce\Client;


$config  = @parse_ini_file(__DIR__ . DIRECTORY_SEPARATOR . 'config.ini', true);
$token   = isset($config["samba_auth"]["access_token"]) ? $config["samba_auth"]["access_token"] : null;
$expires = isset($config["samba_auth"]["expires"]) ? $config["samba_auth"]["expires"] : null;
if(empty($token) || strtotime($expires) < time()) 
{
	try {
		$auth = auth();
		$auth = json_decode($auth, true);
		
		$config["samba_auth"]["access_token"] = $auth["access_token"];
		$config["samba_auth"]["token_type"] = $auth["token_type"];
		$config["samba_auth"]["expires_in"] = $auth["expires_in"];
		$config["samba_auth"]["refresh_token"] = $auth["refresh_token"];
		$config["samba_auth"]["issued"] = $auth[".issued"];
		$config["samba_auth"]["expires"] = $auth[".expires"];
		
		write_ini_file(__DIR__ . DIRECTORY_SEPARATOR . 'config.ini', $config);		
	} catch(Exception $e) {
		error_log($e);
		exit();
	}
}


try {
	$woocommerce = new Client(WC_URL, WC_KEY, WC_SECRET,
		[
			'wp_api' => true,
			'version' => 'wc/v3',
			'sslverify' => false,
		]
	);		
	$orders = $woocommerce->get('orders', array( 'status' => WC_STATUS, 'orderby' => 'date'));		
} catch(Exception $e) {
	error_log($e);
	exit();
}

//print_r($orders); exit();

foreach($orders as $order) 
{	
	$client = array(
		"name" 	  => trim($order->billing->first_name) ." ". trim($order->billing->last_name),
		"address" => trim($order->billing->address_1),
		"phone"   => clearPhone($order->billing->phone)
	);
	
	try {
		$has_client = hasClient($client); 
		if(empty($has_client)) {
			$add_client = addClient($client);
		}
	} catch(Exception $e) {
		error_log($e->getMessage());
		exit();		
	}
	
	$items = orderItems($order);
	

	$shipping_method = "Неизвестно";
	if(isset($order->shipping_lines[0]->method_id)) {
		$shipping_id = $order->shipping_lines[0]->method_id;
		switch($shipping_id) {
			case "local_pickup": 
				$shipping_method = "Самовывоз"; 
				break;
			case "flat_rate": 
				$shipping_method = "Доставка"; 
				break;
		}
	}	
	
	$query = 'mutation m {
	  addTicket(ticket: {
		type:"'.TICKET_TYPE.'",
		department:"'.TICKET_DEPARTMENT.'",
		user:"'.TICKET_USER.'",
		terminal:"'.TICKET_TERMINAL.'",
		note:"[#'.$order->id.'] '.$order->customer_note.'",
		entities: [{entityType: "'.TICKET_ENTITY_TYPE.'", name: "'.$client["phone"].'"}],
		states:[
			{stateName:"Статус", state:"'.TICKET_STATUS.'"},
		],	
		tags:[
			{tagName:"Тип заказа", tag:"'.$shipping_method.'"},
			{tagName:"Откуда", tag:"WEBSITE"},
			{tagName:"Оплата", tag:"'.$order->payment_method_title.'"}
		],
		orders:	'.$items.'
		}){id}
	}';

	try {
		//echo $query; exit();
		$result = gql($query);		
		$result = json_decode($result);
		if($result->errors == null) {
			$ticketId = $result->data->addTicket->id;
			updateEntityState($client, $ticketId);
			
			//$woocommerce->put('orders/'.$order->id, array('status' => 'completed'));
		} else {
			$exeption = array();
			foreach($result->errors as $arr) {
				$exeption[] = $arr->innerException->Message;
			}
			$exeption = json_encode($exeption);
			throw new Exception("addTicket error! Exception: " . $exeption, 400);
		}
	} catch(Exception $e) {
		error_log($e->getMessage());
		//exit();		
	}

	
	print_r($result);
}

?>