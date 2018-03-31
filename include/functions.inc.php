<?php


// simple santization function to accept only alphanumeric characters
function san($a,$b=""){
    $a = preg_replace("/[^a-zA-Z0-9".$b."]/", "", $a);
    
    return $a;
}
// api  error and exit 
function api_err($data){
    global $_config;
	echo json_encode(array("status"=>"error","data"=>$data, "coin"=>$_config['coin']));
	exit;
}
// api print ok and exit
function api_echo($data){
    global $_config;
	echo json_encode(array("status"=>"ok","data"=>$data, "coin"=>$_config['coin']));
    exit;
}
// log function, shows only in cli atm
function _log($data){
	$date=date("[Y-m-d H:s:]");
	$trace=debug_backtrace(); 
	$loc=count($trace)-1;
	$file=substr($trace[$loc]['file'],strrpos($trace[$loc]['file'],"/")+1);
	
	$res="$date ".$file.":".$trace[$loc]['line'];
	
	if(!empty($trace[$loc]['class'])) $res.="---".$trace[$loc]['class'];
	if(!empty($trace[$loc]['function'])&&$trace[$loc]['function']!='_log') $res.='->'.$trace[$loc]['function'].'()';
	$res.=" $data \n";
	if(php_sapi_name() === 'cli') echo $res;
	global $_config;
	if($_config['enable_logging']==true){
		@file_put_contents($_config['log_file'],$res, FILE_APPEND);
	}
}

// converts PEM key to hex
function pem2hex ($data) {
    $data=str_replace("-----BEGIN PUBLIC KEY-----","",$data);
    $data=str_replace("-----END PUBLIC KEY-----","",$data);
    $data=str_replace("-----BEGIN EC PRIVATE KEY-----","",$data);
    $data=str_replace("-----END EC PRIVATE KEY-----","",$data);
    $data=str_replace("\n","",$data);
    $data=base64_decode($data);
    $data=bin2hex($data);
    return $data;
}

// converts hex key to PEM
function hex2pem ($data, $is_private_key=false) {
    $data=hex2bin($data);
    $data=base64_encode($data);
    if($is_private_key) return "-----BEGIN EC PRIVATE KEY-----\n".$data."\n-----END EC PRIVATE KEY-----";
    return "-----BEGIN PUBLIC KEY-----\n".$data."\n-----END PUBLIC KEY-----";
}




    // Base58 encoding/decoding functions - all credits go to https://github.com/stephen-hill/base58php
    function base58_encode($string)
    {
	$alphabet='123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';
	$base=strlen($alphabet);
        // Type validation
        if (is_string($string) === false) {
		return false;
        }
        // If the string is empty, then the encoded string is obviously empty
        if (strlen($string) === 0) {
            return '';
        }
        // Now we need to convert the byte array into an arbitrary-precision decimal
        // We basically do this by performing a base256 to base10 conversion
        $hex = unpack('H*', $string);
        $hex = reset($hex);
        $decimal = gmp_init($hex, 16);
        // This loop now performs base 10 to base 58 conversion
        // The remainder or modulo on each loop becomes a base 58 character
        $output = '';
        while (gmp_cmp($decimal, $base) >= 0) {
            list($decimal, $mod) = gmp_div_qr($decimal, $base);
            $output .= $alphabet[gmp_intval($mod)];
        }
        // If there's still a remainder, append it
        if (gmp_cmp($decimal, 0) > 0) {
            $output .= $alphabet[gmp_intval($decimal)];
        }
        // Now we need to reverse the encoded data
        $output = strrev($output);
        // Now we need to add leading zeros
        $bytes = str_split($string);
        foreach ($bytes as $byte) {
            if ($byte === "\x00") {
                $output = $alphabet[0] . $output;
                continue;
            }
            break;
        }
        return (string) $output;
    }
    function base58_decode($base58)
    {
        $alphabet='123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';
        $base=strlen($alphabet);

        // Type Validation
        if (is_string($base58) === false) {
		return false;
        }
        // If the string is empty, then the decoded string is obviously empty
        if (strlen($base58) === 0) {
            return '';
        }
        $indexes = array_flip(str_split($alphabet));
        $chars = str_split($base58);
        // Check for invalid characters in the supplied base58 string
        foreach ($chars as $char) {
            if (isset($indexes[$char]) === false) {
		return false;
            }
        }
        // Convert from base58 to base10
        $decimal = gmp_init($indexes[$chars[0]], 10);
        for ($i = 1, $l = count($chars); $i < $l; $i++) {
            $decimal = gmp_mul($decimal, $base);
            $decimal = gmp_add($decimal, $indexes[$chars[$i]]);
        }
        // Convert from base10 to base256 (8-bit byte array)
        $output = '';
        while (gmp_cmp($decimal, 0) > 0) {
            list($decimal, $byte) = gmp_div_qr($decimal, 256);
            $output = pack('C', gmp_intval($byte)) . $output;
        }
        // Now we need to add leading zeros
        foreach ($chars as $char) {
            if ($indexes[$char] === 0) {
                $output = "\x00" . $output;
                continue;
            }
            break;
        }
        return $output;
    }

// converts PEM key to the base58 version used by ARO
function pem2coin ($data) {
    $data=str_replace("-----BEGIN PUBLIC KEY-----","",$data);
    $data=str_replace("-----END PUBLIC KEY-----","",$data);
    $data=str_replace("-----BEGIN EC PRIVATE KEY-----","",$data);
    $data=str_replace("-----END EC PRIVATE KEY-----","",$data);
    $data=str_replace("\n","",$data);
    $data=base64_decode($data);
    
 
    return base58_encode($data);
    
}
// converts the key in base58 to PEM
function coin2pem ($data, $is_private_key=false) {

    
    
       $data=base58_decode($data);
       $data=base64_encode($data);

        $dat=str_split($data,64);
        $data=implode("\n",$dat);

    if($is_private_key) return "-----BEGIN EC PRIVATE KEY-----\n".$data."\n-----END EC PRIVATE KEY-----\n";
    return "-----BEGIN PUBLIC KEY-----\n".$data."\n-----END PUBLIC KEY-----\n";
}

// sign data with private key
function ec_sign($data, $key){
    // transform the base58 key format to PEM 
    $private_key=coin2pem($key,true);
   
   
    $pkey=openssl_pkey_get_private($private_key);
  
    $k=openssl_pkey_get_details($pkey);


    openssl_sign($data,$signature,$pkey,OPENSSL_ALGO_SHA256);
      
    // the signature will be base58 encoded  
    return base58_encode($signature);
    
}


function ec_verify($data, $signature, $key){

    
    // transform the base58 key to PEM
    $public_key=coin2pem($key);
   
    $signature=base58_decode($signature);
   
    $pkey=openssl_pkey_get_public($public_key);
    
    $res=openssl_verify($data,$signature,$pkey,OPENSSL_ALGO_SHA256);
  
 
    if($res===1) return true;
    return false;
}

// POST data to an URL (usualy peer). The data is an array, json encoded with is sent as $_POST['data']
function peer_post($url, $data=array(),$timeout=60,$debug=false){
    global $_config;
    if($debug) echo "\nPeer post: $url\n";
    $postdata = http_build_query(
        array(
            'data' => json_encode($data),
            "coin"=>$_config['coin']
            )
    );
    
    $opts = array('http' =>
        array(
            'timeout' => $timeout,
            'method'  => 'POST',
            'header'  => 'Content-type: application/x-www-form-urlencoded',
            'content' => $postdata
        )
    );
    
    $context  = stream_context_create($opts);
    
    $result = file_get_contents($url, false, $context);
    if($debug) echo "\nPeer response: $result\n";
    $res=json_decode($result,true);

    // the function will return false if something goes wrong
    if($res['status']!="ok"||$res['coin']!=$_config['coin']) return false;
    return $res['data'];
}

// convers hex to base58
function hex2coin($hex){
   
    $data=hex2bin($hex);
    return  base58_encode($data);
} 
// converts base58 to hex
function coin2hex($data){
    
    $bin= base58_decode($data);
    return bin2hex($bin);      
} 
?>
