<?php



function san($a,$b=""){
    $a = preg_replace("/[^a-zA-Z0-9".$b."]/", "", $a);
    
    return $a;
}

function api_err($data){
    global $_config;
	echo json_encode(array("status"=>"error","data"=>$data, "coin"=>$_config['coin']));
	exit;
}
function api_echo($data){
    global $_config;
	echo json_encode(array("status"=>"ok","data"=>$data, "coin"=>$_config['coin']));
    exit;
}

function _log($data){
    $date=date("[Y-m-d H:s:]");
    $trace=debug_backtrace(); 
    $location=$trace[1]['class'].'->'.$trace[1]['function'].'()';
	if(php_sapi_name() === 'cli') echo "$date [$location] $data\n";
}

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

function hex2pem ($data, $is_private_key=false) {
    $data=hex2bin($data);
    $data=base64_encode($data);
    if($is_private_key) return "-----BEGIN EC PRIVATE KEY-----\n".$data."\n-----END EC PRIVATE KEY-----";
    return "-----BEGIN PUBLIC KEY-----\n".$data."\n-----END PUBLIC KEY-----";
}



   //all credits for this base58 functions should go to tuupola / https://github.com/tuupola/base58/
    function baseConvert(array $source, $source_base, $target_base)
    {
        $result = [];
        while ($count = count($source)) {
            $quotient = [];
            $remainder = 0;
            for ($i = 0; $i !== $count; $i++) {
                $accumulator = $source[$i] + $remainder * $source_base;
                $digit = (integer) ($accumulator / $target_base);
                $remainder = $accumulator % $target_base;
                if (count($quotient) || $digit) {
                    array_push($quotient, $digit);
                };
            }
            array_unshift($result, $remainder);
            $source = $quotient;
        }
        return $result;
    }
    function base58_encode($data)
    {
        if (is_integer($data)) {
            $data = [$data];
        } else {
            $data = str_split($data);
            $data = array_map(function ($character) {
                return ord($character);
            }, $data);
        }


        $converted = baseConvert($data, 256, 58);

        return implode("", array_map(function ($index) {
                $chars="123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz";
            return $chars[$index];
        }, $converted));
    }
     function base58_decode($data, $integer = false)
    {
        $data = str_split($data);
        $data = array_map(function ($character) {
                $chars="123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz";
            return strpos($chars, $character);
        }, $data);
        /* Return as integer when requested. */
        if ($integer) {
            $converted = baseConvert($data, 58, 10);
            return (integer) implode("", $converted);
        }
        $converted = baseConvert($data, 58, 256);
        return implode("", array_map(function ($ascii) {
            return chr($ascii);
        }, $converted));
    }




function pem2coin ($data) {
    $data=str_replace("-----BEGIN PUBLIC KEY-----","",$data);
    $data=str_replace("-----END PUBLIC KEY-----","",$data);
    $data=str_replace("-----BEGIN EC PRIVATE KEY-----","",$data);
    $data=str_replace("-----END EC PRIVATE KEY-----","",$data);
    $data=str_replace("\n","",$data);
    $data=base64_decode($data);
    
 
    return base58_encode($data);
    
}

function coin2pem ($data, $is_private_key=false) {

    
    
       $data=base58_decode($data);
       $data=base64_encode($data);

        $dat=str_split($data,64);
        $data=implode("\n",$dat);

    if($is_private_key) return "-----BEGIN EC PRIVATE KEY-----\n".$data."\n-----END EC PRIVATE KEY-----\n";
    return "-----BEGIN PUBLIC KEY-----\n".$data."\n-----END PUBLIC KEY-----\n";
}


function ec_sign($data, $key){

    $private_key=coin2pem($key,true);
   
   
    $pkey=openssl_pkey_get_private($private_key);
  
    $k=openssl_pkey_get_details($pkey);


    openssl_sign($data,$signature,$pkey,OPENSSL_ALGO_SHA256);
  
    
    
    return base58_encode($signature);
    
}


function ec_verify($data, $signature, $key){

    

    $public_key=coin2pem($key);
   
    $signature=base58_decode($signature);
   
    $pkey=openssl_pkey_get_public($public_key);
    
    $res=openssl_verify($data,$signature,$pkey,OPENSSL_ALGO_SHA256);
  
 
    if($res===1) return true;
    return false;
}


function peer_post($url, $data=array(),$timeout=60){
    global $_config;
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
    
    $res=json_decode($result,true);
    if($res['status']!="ok"||$res['coin']!=$_config['coin']) return false;
    return $res['data'];
}


function hex2coin($hex){
   
    $data=hex2bin($hex);
    return  base58_encode($data);
} 
function coin2hex($data){
    
    $bin= base58_decode($data);
    return bin2hex($bin);      
} 
?>
