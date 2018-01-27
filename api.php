<?php
/* 
The MIT License (MIT)
Copyright (c) 2018 AroDev 

www.arionum.com

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND,
EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF
MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT.
IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM,
DAMAGES OR OTHER LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR
OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE
OR OTHER DEALINGS IN THE SOFTWARE.
*/
require_once("include/init.inc.php");
error_reporting(0);
$ip=$_SERVER['REMOTE_ADDR'];
if($_config['public_api']==false&&!in_array($ip,$_config['allowed_hosts'])){
    api_err("private-api");
}

$acc = new Account;
$block = new Block;

$trx = new Transaction;
$q=$_GET['q'];
if(!empty($_POST['data'])){
    $data=json_decode($_POST['data'],true);
}  else {
    $data=$_GET;
}


if($q=="getAddress"){
    $public_key=$data['public_key'];
    if(strlen($public_key)<32) api_err("Invalid public key");
    api_echo($acc->get_address($public_key));
}
elseif($q=="base58"){		
    api_echo(base58_encode($data['data']));
}
elseif($q=="getBalance"){
    $public_key=$data['public_key'];
    $account=$data['account'];
    if(!empty($public_key)&&strlen($public_key)<32) api_err("Invalid public key");
    if(!empty($public_key)) $account=$acc->get_address($public_key);
    if(empty($account)) api_err("Invalid account id");
    $account=san($account);
    api_echo($acc->balance($account));
}
elseif($q=="getPendingBalance"){
   
    $account=$data['account'];
    if(empty($account)) api_err("Invalid account id");
    $account=san($account);
    api_echo($acc->pending_balance($account));
}
elseif($q=="getTransactions"){
    $account=san($data['account']);
    $transactions=$acc->get_mempool_transactions($account);
    $transactions=array_merge($transactions, $acc->get_transactions($account));
    api_echo($transactions);

}
elseif($q=="getPublicKey"){
    $account=san($data['account']);
    if(empty($account)) api_err("Invalid account id");
    $public_key=$acc->public_key($account);
    if($public_key===false) api_err("No public key found for this account");
    else api_echo($public_key);

} elseif($q=="getTransaction"){
    
    $id=san($data['transaction']);
    $res=$trx->get_transaction($id);
    if($res===false) {
        $res=$trx->get_mempool_transaction($id);
        if($res===false) api_err("invalid transaction");
    }
    api_Echo($res);
    
} elseif($q=="generateAccount"){
	$acc=new Account;
	$res=$acc->generate_account();
	api_echo($res);
} elseif($q=="currentBlock"){
    $current=$block->current();
     api_echo($current);
} elseif($q=="version"){ 
     api_echo(VERSION);

} elseif($q=="send"){

    $acc = new Account;
    $block = new Block;
    
    $trx = new Transaction;
    
    $dst=san($data['dst']);

    if(!$acc->valid($dst)) api_err("Invalid destination address");
    $dst_b=base58_decode($dst);
    if(strlen($dst_b)!=64)  api_err("Invalid destination address");


    $public_key=san($data['public_key']);
    if(!$acc->valid_key($public_key)) api_err("Invalid public key");
    $private_key=san($data['private_key']);
    if(!$acc->valid_key($private_key)) api_err("Invalid private key");
    $signature=san($data['signature']);
    if(!$acc->valid_key($signature)) api_err("Invalid signature");
    $date=$data['date']+0;
    
    if($date==0) $date=time();
    if($date<time()-(3600*24*48)) api_err("The date is too old");
    if($date>time()+86400) api_err("Invalid Date");
    $version=intval($data['version']);
    $message=$data['message'];
    if(strlen($message)>128) api_err("The message must be less than 128 chars");
    $val=$data['val']+0;
    $fee=$val*0.0025;
    if($fee<0.00000001) $fee=0.00000001;

    if($val<0.00000001) api_err("Invalid value");
 
   
    if($version<1) api_err("Invalid version");

    $val=number_format($val,8,'.','');
    $fee=number_format($fee,8,'.','');
    
    
    if(empty($public_key)&&empty($private_key)) api_err("Either the private key or the public key must be sent");
    
    
    
    if(empty($private_key)&&empty($signature)) api_err("Either the private_key or the signature must be sent");
    if(empty($public_key))
    {
    
        $pk=coin2pem($private_key,true);
        $pkey=openssl_pkey_get_private($pk);
        $pub = openssl_pkey_get_details($pkey);
        $public_key= pem2coin($pub['key']);
    
    }
    $transaction=array("val"=>$val, "fee"=>$fee, "dst"=>$dst, "public_key"=>$public_key,"date"=>$date, "version"=>$version,"message"=>$message, "signature"=>$signature);
    
    if(!empty($private_key)){
        
            $signature=$trx->sign($transaction, $private_key);
            $transaction['signature']=$signature;
        
    }
    
    
    $hash=$trx->hash($transaction);
    $transaction['id']=$hash;
    
    
   
    if(!$trx->check($transaction)) api_err("Transaction signature failed");
    
       
    
    
    $res=$db->single("SELECT COUNT(1) FROM mempool WHERE id=:id",array(":id"=>$hash));
    if($res!=0) api_err("The transaction is already in mempool");
    
    $res=$db->single("SELECT COUNT(1) FROM transactions WHERE id=:id",array(":id"=>$hash));
    if($res!=0) api_err("The transaction is already in a block");
    
    
    
    $src=$acc->get_address($public_key);
    $transaction['src']=$src;
    $balance=$db->single("SELECT balance FROM accounts WHERE id=:id",array(":id"=>$src));
    if($balance<$val+$fee) api_err("Not enough funds");
    
    
    $memspent=$db->single("SELECT SUM(val+fee) FROM mempool WHERE src=:src",array(":src"=>$src));
    if($balance-$memspent<$val+$fee) api_err("Not enough funds (mempool)");
    
    
    
    $trx->add_mempool($transaction, "local");
    system("php propagate.php transaction $hash &>/dev/null &");
    api_echo($hash);
} elseif($q=="mempoolSize"){
    $res=$db->single("SELECT COUNT(1) FROM mempool");
    api_echo($res);

} else {
     api_err("Invalid request");
 }
?>
