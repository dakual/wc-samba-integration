<?php
function auth() {
	$data = array(
		"username" => SAMBA_USERNAME, 
		"password" => SAMBA_PASSWORD, 
		"grant_type" => SAMBA_GRANT_TYPE, 
		"client_id"  => SAMBA_CLIENT_ID
	);
	$data = http_build_query($data);
	
	$curl = curl_init(SAMBA_AUTH_URL);
	curl_setopt($curl, CURLOPT_CUSTOMREQUEST, "POST");
	curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
	curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($curl, CURLOPT_POST, true);
	curl_setopt($curl, CURLOPT_HTTPHEADER, array(
		"Content-Type: application/x-www-form-urlencoded",
		"Accept: application/json",
		"Content-Length: " . strlen($data))
	);

	$curl_response = curl_exec($curl);
	if ($curl_response === false) {
		curl_close($curl);
		throw new Exception("Samba auth connection error!", 400);
	}
	curl_close($curl);
	return $curl_response;
}

function gql($query) {
	global $config;
	
	$token = $config["samba_auth"]["access_token"];
	$data  = json_encode(array("query" => $query));
	
	$curl = curl_init(SAMBA_API_URL);
	curl_setopt($curl, CURLOPT_CUSTOMREQUEST, "POST");
	curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
	curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($curl, CURLOPT_POST, true);
	curl_setopt($curl, CURLOPT_HTTPHEADER, array(
		"Authorization: Bearer $token",
		'Content-Type: application/json',
		"Accept: application/json",
		'Content-Length: ' . strlen($data))
	);

	$curl_response = curl_exec($curl);
	if ($curl_response === false) {
		curl_close($curl);
		throw new Exception("Samba api connection error!", 400);
	}
	curl_close($curl);
	return $curl_response;
}

function arr2ql($arr) {
	$arr = json_encode($arr, JSON_UNESCAPED_UNICODE);
	return preg_replace('/"([a-zA-Z_]+[a-zA-Z0-9_]*)":/','$1:',$arr);	
}

function orderItems($order)
{
	$orderItems = array();
	foreach($order->line_items as $item) {
		$name = empty($item->sku) ? TICKET_MISC_PRODUCTS : $item->sku;
		$menuItemName = ($name === TICKET_MISC_PRODUCTS) ? $item->name : '';
		
		$tags = array();
		$metas = $item->meta_data;
		foreach($metas as $meta) {
			$konum = strpos($meta->value, "&#8376;");
			if ($konum === false) {
				$tags[] = array("tagName" => "Default", "tag" => $meta->value);
			}
		}
		
		$calPrice = true;
		$states   = array();
		$states[] = array("stateName" => "Status", "state" => "Отправлено");
		if((int)$item->price <= 0) {
			$states[] = array("stateName" => "GStatus", "state" => "Подарок");
			$calPrice = false;
		}
		
		$orderItems[] = array(
			"name" => $name, 
			"menuItemName" => $menuItemName, 
			"quantity" => $item->quantity, 
			"price" => (int)$item->price,
			"tags" => $tags,
			"calculatePrice" => $calPrice,
			"states" => $states
		);
	}
	
	$shipping_total = $order->shipping_total;
	if($shipping_total > 0) {
		$orderItems[] = array(
			"name" => TICKET_DELIVERY_ITEM, 
			"menuItemName" => TICKET_DELIVERY_NAME, 
			"quantity" => 1, 
			"price" => (int)$shipping_total,
			"states" => array(
				array("stateName" => "Status", "state" => "Отправлено")
			)
		);		
	}
	
	return arr2ql($orderItems);	
}

function hasClient($client) 
{
	if(empty($client["phone"]))
		throw new Exception("function(hasClient) - phone error!", 400);
	
	$query  = '{isEntityExists(type:"'.TICKET_ENTITY_TYPE.'",name:"'.$client["phone"].'")}';
	$result = gql($query);
	$result = json_decode($result);
	if($result->errors == null) {
		return $result->data->isEntityExists;
	} else {
		throw new Exception("function(hasClient) - error!", 400);
	}
}

function addClient($client)
{
    $query = 'mutation m{addEntity(entity:{
        entityType:"'.TICKET_ENTITY_TYPE.'", name:"'.$client["phone"].'", customData:[
            {name:"Название", value:"'.$client["name"].'"},
            {name:"Адрес", value:"'.$client["address"].'"}
        ]})
        {name}
    }';
	
	$result = gql($query);
	$result = json_decode($result);
	if($result->errors == null) {
		return $result->data->addEntity->name;
	} else {
		throw new Exception("function(addClient) - error!", 400);
	}	
}

function updateEntityState($client, $ticketId) 
{
	$query = 'mutation m {
		a2:updateEntityState(
			entityTypeName:"'.TICKET_ENTITY_TYPE.'",
			entityName:"'.$client["phone"].'",
			stateName:"Status",
			state:"Новые заказы"
	    ){name},
		a3:postTicketRefreshMessage(id:'.$ticketId.'){id}
	}';
	
	gql($query);
}

function clearPhone($phone) 
{
	if (substr($phone, 0, 1) === '8') { 
		$phone = substr($phone, 1); 
	} else if (substr($phone, 0, 2) === '+7') { 
		$phone = substr($phone, 2); 
	}
	
	return substr($phone, -10);
}


function write_ini_file($file, $array = []) {
	if (!is_string($file)) {
		throw new \InvalidArgumentException('Function argument 1 must be a string.');
	}

	if (!is_array($array)) {
		throw new \InvalidArgumentException('Function argument 2 must be an array.');
	}

	$data = array();
	foreach ($array as $key => $val) {
		if (is_array($val)) {
			$data[] = "[$key]";
			foreach ($val as $skey => $sval) {
				if (is_array($sval)) {
					foreach ($sval as $_skey => $_sval) {
						if (is_numeric($_skey)) {
							$data[] = $skey.'[] = '.(is_numeric($_sval) ? $_sval : (ctype_upper($_sval) ? $_sval : '"'.$_sval.'"'));
						} else {
							$data[] = $skey.'['.$_skey.'] = '.(is_numeric($_sval) ? $_sval : (ctype_upper($_sval) ? $_sval : '"'.$_sval.'"'));
						}
					}
				} else {
					$data[] = $skey.' = '.(is_numeric($sval) ? $sval : (ctype_upper($sval) ? $sval : '"'.$sval.'"'));
				}
			}
		} else {
			$data[] = $key.' = '.(is_numeric($val) ? $val : (ctype_upper($val) ? $val : '"'.$val.'"'));
		}
		$data[] = null;
	}

	$fp = fopen($file, 'w');
	$retries = 0;
	$max_retries = 100;

	if (!$fp) {
		return false;
	}

	do {
		if ($retries > 0) {
			usleep(rand(1, 5000));
		}
		$retries += 1;
	} while (!flock($fp, LOCK_EX) && $retries <= $max_retries);

	if ($retries == $max_retries) {
		return false;
	}

	fwrite($fp, implode(PHP_EOL, $data).PHP_EOL);

	flock($fp, LOCK_UN);
	fclose($fp);

	return true;
}
?>