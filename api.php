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


/**
 * @api {get} /api.php 01. Basic Information
 * @apiName Info
 * @apiGroup API
 * @apiDescription Each API call will return the result in JSON format.
 * There are 2 objects, "status" and "data".
 *
 * The "status" object returns "ok" when the transaction is successful and "error" on failure.
 *
 * The "data" object returns the requested data, as sub-objects.
 *
 * The parameters must be sent either as POST['data'], json encoded array or independently as GET.
 *
 * @apiSuccess {String} status "ok"
 * @apiSuccess {String} data The data provided by the api will be under this object.
 *
 * @apiSuccessExample {json} Success-Response:
 *{
 *   "status":"ok",
 *   "data":{
 *      "obj1":"val1",
 *      "obj2":"val2",
 *      "obj3":{
 *         "obj4":"val4",
 *         "obj5":"val5"
 *      }
 *   }
 *}
 *
 * @apiError {String} status "error"
 * @apiError {String} result Information regarding the error
 *
 * @apiErrorExample {json} Error-Response:
 *     {
 *       "status": "error",
 *       "data": "The requested action could not be completed."
 *     }
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


/**
 * @api {get} /api.php?q=getAddress  02. getAddress
 * @apiName getAddress
 * @apiGroup API
 * @apiDescription Converts the public key to an ARO address.
 *
 * @apiParam {string} public_key The public key
 *
 * @apiSuccess {string} data Contains the address
 */

if($q=="getAddress"){
    $public_key=$data['public_key'];
    if(strlen($public_key)<32) api_err("Invalid public key");
    api_echo($acc->get_address($public_key));
}
elseif($q=="base58"){
/**
 * @api {get} /api.php?q=base58  03. base58
 * @apiName base58
 * @apiGroup API
 * @apiDescription Converts a string to base58.
 *
 * @apiParam {string} data Input string
 *
 * @apiSuccess {string} data Output string
 */

    api_echo(base58_encode($data['data']));
}
elseif($q=="getBalance"){
/**
 * @api {get} /api.php?q=getBalance  04. getBalance
 * @apiName getBalance
 * @apiGroup API
 * @apiDescription Returns the balance of a specific account or public key.
 *
 * @apiParam {string} [public_key] Public key
 * @apiParam {string} [account] Account id / address
 *
 * @apiSuccess {string} data The ARO balance
 */

    $public_key=$data['public_key'];
    $account=$data['account'];
    if(!empty($public_key)&&strlen($public_key)<32) api_err("Invalid public key");
    if(!empty($public_key)) $account=$acc->get_address($public_key);
    if(empty($account)) api_err("Invalid account id");
    $account=san($account);
    api_echo($acc->balance($account));
}
elseif($q=="getPendingBalance"){
/**
 * @api {get} /api.php?q=getPendingBalance  05. getPendingBalance
 * @apiName getPendingBalance
 * @apiGroup API
 * @apiDescription Returns the pending balance, which includes pending transactions, of a specific account or public key.
 *
 * @apiParam {string} [public_key] Public key
 * @apiParam {string} [account] Account id / address
 *
 * @apiSuccess {string} data The ARO balance
 */

    $account=$data['account'];
    if(!empty($public_key)&&strlen($public_key)<32) api_err("Invalid public key");
    if(!empty($public_key)) $account=$acc->get_address($public_key);
    if(empty($account)) api_err("Invalid account id");
    $account=san($account);
    api_echo($acc->pending_balance($account));
}
elseif($q=="getTransactions"){
/**
 * @api {get} /api.php?q=getTransactions  06. getTransactions
 * @apiName getTransactions
 * @apiGroup API
 * @apiDescription Returns the latest transactions of an account.
 *
 * @apiParam {string} [public_key] Public key
 * @apiParam {string} [account] Account id / address
 * @apiParam {numeric} [limit] Number of confirmed transactions, max 1000, min 1
 *
 * @apiSuccess {string} block  Block ID
 * @apiSuccess {numeric} confirmation Number of confirmations
 * @apiSuccess {numeric} date  Transaction's date in UNIX TIMESTAMP format
 * @apiSuccess {string} dst  Transaction destination
 * @apiSuccess {numeric} fee  The transaction's fee
 * @apiSuccess {numeric} height  Block height
 * @apiSuccess {string} id  Transaction ID/HASH
 * @apiSuccess {string} message  Transaction's message
 * @apiSuccess {string} signature  Transaction's signature
 * @apiSuccess {string} public_key  Account's public_key
 * @apiSuccess {string} src  Sender's address
 * @apiSuccess {string} type  "debit", "credit" or "mempool"
 * @apiSuccess {numeric} val Transaction value
 * @apiSuccess {numeric} version Transaction version
 */

    $account=san($data['account']);
    if(!empty($public_key)&&strlen($public_key)<32) api_err("Invalid public key");
    if(!empty($public_key)) $account=$acc->get_address($public_key);
    if(empty($account)) api_err("Invalid account id");

    $limit=intval($data['limit']);
    $transactions=$acc->get_mempool_transactions($account);
    $transactions=array_merge($transactions, $acc->get_transactions($account,$limit));
    api_echo($transactions);

} elseif($q=="getTransaction"){
/**
 * @api {get} /api.php?q=getTransaction  07. getTransaction
 * @apiName getTransaction
 * @apiGroup API
 * @apiDescription Returns one transaction.
 *
 * @apiParam {string} transaction Transaction ID
 *
 * @apiSuccess {string} block  Block ID
 * @apiSuccess {numeric} confirmation Number of confirmations
 * @apiSuccess {numeric} date  Transaction's date in UNIX TIMESTAMP format
 * @apiSuccess {string} dst  Transaction destination
 * @apiSuccess {numeric} fee  The transaction's fee
 * @apiSuccess {numeric} height  Block height
 * @apiSuccess {string} id  Transaction ID/HASH
 * @apiSuccess {string} message  Transaction's message
 * @apiSuccess {string} signature  Transaction's signature
 * @apiSuccess {string} public_key  Account's public_key
 * @apiSuccess {string} src  Sender's address
 * @apiSuccess {string} type  "debit", "credit" or "mempool"
 * @apiSuccess {numeric} val Transaction value
 * @apiSuccess {numeric} version Transaction version
 */

    $id=san($data['transaction']);
    $res=$trx->get_transaction($id);
    if($res===false) {
        $res=$trx->get_mempool_transaction($id);
        if($res===false) api_err("invalid transaction");
    }
    api_Echo($res);
} elseif($q=="getPublicKey"){
/**
 * @api {get} /api.php?q=getPublicKey  08. getPublicKey
 * @apiName getPublicKey
 * @apiGroup API
 * @apiDescription Returns the public key of a specific account.
 *
 * @apiParam {string} account Account id / address
 *
 * @apiSuccess {string} data The public key
 */

    $account=san($data['account']);
    if(empty($account)) api_err("Invalid account id");
    $public_key=$acc->public_key($account);
    if($public_key===false) api_err("No public key found for this account");
    else api_echo($public_key);


} elseif($q=="generateAccount"){
/**
 * @api {get} /api.php?q=generateAccount  09. generateAccount
 * @apiName generateAccount
 * @apiGroup API
 * @apiDescription Generates a new account. This function should only be used when the node is on the same host or over a really secure network.
 *
 * @apiSuccess {string} address Account address
 * @apiSuccess {string} public_key Public key
 * @apiSuccess {string} private_key Private key
 */

	$acc=new Account;
	$res=$acc->generate_account();
	api_echo($res);
} elseif($q=="currentBlock"){
/**
 * @api {get} /api.php?q=currentBlock  10. currentBlock
 * @apiName currentBlock
 * @apiGroup API
 * @apiDescription Returns the current block.
 *
 * @apiSuccess {string} id Blocks id
 * @apiSuccess {string} generator Block Generator
 * @apiSuccess {numeric} height Height
 * @apiSuccess {numeric} date Block's date in UNIX TIMESTAMP format
 * @apiSuccess {string} nonce Mining nonce
 * @apiSuccess {string} signature Signature signed by the generator
 * @apiSuccess {numeric} difficulty The base target / difficulty
 * @apiSuccess {string} argon Mining argon hash


 */

    $current=$block->current();
     api_echo($current);

} elseif($q=="getBlock"){
/**
 * @api {get} /api.php?q=getBlock  11. getBlock
 * @apiName getBlock
 * @apiGroup API
 * @apiDescription Returns the block.
 *
 * @apiParam {numeric} height Block Height
 *
 * @apiSuccess {string} id Block id
 * @apiSuccess {string} generator Block Generator
 * @apiSuccess {numeric} height Height
 * @apiSuccess {numeric} date Block's date in UNIX TIMESTAMP format
 * @apiSuccess {string} nonce Mining nonce
 * @apiSuccess {string} signature Signature signed by the generator
 * @apiSuccess {numeric} difficulty The base target / difficulty
 * @apiSuccess {string} argon Mining argon hash
 */
	$height=san($data['height']);
	$ret=$block->get($height);
	if($ret==false) api_err("Invalid block");
	else api_echo($ret);
} elseif($q=="getBlockTransactions"){
/**
 * @api {get} /api.php?q=getBlockTransactions  12. getBlockTransactions
 * @apiName getBlockTransactions
 * @apiGroup API
 * @apiDescription Returns the transactions of a specific block.
 *
 * @apiParam {numeric} [height] Block Height
 * @apiParam {string} [block] Block id
 *
 * @apiSuccess {string} block  Block ID
 * @apiSuccess {numeric} confirmation Number of confirmations
 * @apiSuccess {numeric} date  Transaction's date in UNIX TIMESTAMP format
 * @apiSuccess {string} dst  Transaction destination
 * @apiSuccess {numeric} fee  The transaction's fee
 * @apiSuccess {numeric} height  Block height
 * @apiSuccess {string} id  Transaction ID/HASH
 * @apiSuccess {string} message  Transaction's message
 * @apiSuccess {string} signature  Transaction's signature
 * @apiSuccess {string} public_key  Account's public_key
 * @apiSuccess {string} src  Sender's address
 * @apiSuccess {string} type  "debit", "credit" or "mempool"
 * @apiSuccess {numeric} val Transaction value
 * @apiSuccess {numeric} version Transaction version
 */
        $height=san($data['height']);
	$block=san($data['block']);
        $ret=$trx->get_transactions($height, $block);
        if($ret===false) api_err("Invalid block");
        else api_echo($ret);

} elseif($q=="version"){
/**
 * @api {get} /api.php?q=version  13. version
 * @apiName version
 * @apiGroup API
 * @apiDescription Returns the node's version.
 *
 *
 * @apiSuccess {string} data  Version
*/
     api_echo(VERSION);

} elseif($q=="send"){
/**
 * @api {get} /api.php?q=send  14. send
 * @apiName send
 * @apiGroup API
 * @apiDescription Sends a transaction.
 *
 * @apiParam {numeric} val Transaction value (without fees)
 * @apiParam {string} dst Destination address
 * @apiParam {string} public_key Sender's public key
 * @apiParam {string} [signature] Transaction signature. It's recommended that the transaction is signed before being sent to the node to avoid sending your private key to the node.
 * @apiParam {string} [private_key] Sender's private key. Only to be used when the transaction is not signed locally.
 * @apiParam {numeric} [date] Transaction's date in UNIX TIMESTAMP format. Requried when the transaction is pre-signed.
 * @apiParam {string} [message] A message to be included with the transaction. Maximum 128 chars.
 * @apiParam {numeric} [version] The version of the transaction. 1 to send coins.
 *
 * @apiSuccess {string} data  Transaction id
 */
    $current=$block->current();

    if($current['height']>10790&&$current['height']<10810) api_err("Hard fork in progress. Please retry the transaction later!"); //10800

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


    if($fee>10&&$current['height']>10800) $fee=10; //10800
    if($val<0.00000001) api_err("Invalid value");

    if($version<1) $version=1;

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
    system("php propagate.php transaction $hash > /dev/null 2>&1 &");
    api_echo($hash);
} elseif($q=="mempoolSize"){
/**
 * @api {get} /api.php?q=mempoolSize  15. mempoolSize
 * @apiName mempoolSize
 * @apiGroup API
 * @apiDescription Returns the number of transactions in mempool.
 *
 * @apiSuccess {numeric} data  Number of mempool transactions
 */

    $res=$db->single("SELECT COUNT(1) FROM mempool");
    api_echo($res);

} else {
     api_err("Invalid request");
 }
?>
