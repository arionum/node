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
$trx = new Transaction;
$block=new Block;
$q=$_GET['q'];
// the data is sent as json, in $_POST['data']
if(!empty($_POST['data'])){
    $data=json_decode(trim($_POST['data']),true);  
}

// make sure it's the same coin and not testnet
if($_POST['coin']!=$_config['coin']) api_err("Invalid coin");
$ip=$_SERVER['REMOTE_ADDR'];
$ip=filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);

// peer with the current node
if($q=="peer"){
    // sanitize the hostname
    $hostname = filter_var($data['hostname'], FILTER_SANITIZE_URL);
    
    if (!filter_var($hostname, FILTER_VALIDATE_URL)) api_err("invalid-hostname");
   
    // if it's already peered, only repeer on request
    $res=$db->single("SELECT COUNT(1) FROM peers WHERE hostname=:hostname AND ip=:ip",array(":hostname"=>$hostname,":ip"=>$ip));
    if($res==1){
         if($data['repeer']==1){
            $res=peer_post($hostname."/peer.php?q=peer",array("hostname"=>$_config['hostname']));
            if($res!==false) api_echo("re-peer-ok");
            else api_err("re-peer failed - $result");
         }
         api_echo("peer-ok-already");
    }
    // if we have enough peers, add it to DB as reserve
    $res=$db->single("SELECT COUNT(1) FROM peers WHERE blacklisted<UNIX_TIMESTAMP() AND ping >UNIX_TIMESTAMP()-86400 AND reserve=0");
    $reserve=1;
    if($res<$_config['max_peers']) $reserve=0;
    $db->run("INSERT ignore INTO peers SET hostname=:hostname, reserve=:reserve, ping=UNIX_TIMESTAMP(), ip=:ip ON DUPLICATE KEY UPDATE hostname=:hostname2",array(":ip"=>$ip, ":hostname2"=>$hostname,":hostname"=>$hostname, ":reserve"=>$reserve));
    // re-peer to make sure the peer is valid
    $res=peer_post($hostname."/peer.php?q=peer",array("hostname"=>$_config['hostname']));
    if($res!==false) api_echo("re-peer-ok");
    else{ 
        $db->run("DELETE FROM peers WHERE ip=:ip",array(":ip"=>$ip));
        api_err("re-peer failed - $result");
    }
}
elseif($q=="ping"){
    // confirm peer is active
    api_echo("pong");
} elseif($q=="submitTransaction"){
    // receive a new transaction from a peer
    $current=$block->current();


    // no transactions accepted if the sanity is syncing
    if($_config['sanity_sync']==1) api_err("sanity-sync");
    
    $data['id']=san($data['id']);
    // validate transaction data
    if(!$trx->check($data)) api_err("Invalid transaction");
    $hash=$data['id']; 
    // make sure it's not already in mempool 
    $res=$db->single("SELECT COUNT(1) FROM mempool WHERE id=:id",array(":id"=>$hash));
    if($res!=0) api_err("The transaction is already in mempool");
    // make sure the peer is not flooding us with transactions
    $res=$db->single("SELECT COUNT(1) FROM mempool WHERE src=:src",array(":src"=>$data['src']));
    if($res>25) api_err("Too many transactions from this address in mempool. Please rebroadcast later.");
    $res=$db->single("SELECT COUNT(1) FROM mempool WHERE peer=:peer",array(":peer"=>$ip));
    if($res>$_config['peer_max_mempool']) api_error("Too many transactions broadcasted from this peer");
    
    
    // make sure the transaction is not already on the blockchain
    $res=$db->single("SELECT COUNT(1) FROM transactions WHERE id=:id",array(":id"=>$hash));
    if($res!=0) api_err("The transaction is already in a block");
    $acc=new Account;
    $src=$acc->get_address($data['public_key']);
    // make sure the sender has enough balance
    $balance=$db->single("SELECT balance FROM accounts WHERE id=:id",array(":id"=>$src));
    if($balance<$val+$fee) api_err("Not enough funds");
    
    // make sure the sender has enough pending balance
    $memspent=$db->single("SELECT SUM(val+fee) FROM mempool WHERE src=:src",array(":src"=>$src));
    if($balance-$memspent<$val+$fee) api_err("Not enough funds (mempool)");

    // add to mempool
    $trx->add_mempool($data, $ip);
   
    // rebroadcast the transaction to some peers unless the transaction is smaller than the average size of transactions in mempool - protect against garbage data flooding
    $res=$db->row("SELECT COUNT(1) as c, sum(val) as v FROM  mempool ",array(":src"=>$data['src']));
    if($res['c']<$_config['max_mempool_rebroadcast']&&$res['v']/$res['c']<$data['val']) system("php propagate.php transaction '$data[id]'  > /dev/null 2>&1  &");
    api_echo("transaction-ok");
}
elseif($q=="submitBlock"){
    // receive a  new block from a peer
    
    // if sanity sync, refuse all
    if($_config['sanity_sync']==1){ _log('['.$ip."] Block rejected due to sanity sync"); api_err("sanity-sync"); }
    $data['id']=san($data['id']);
    $current=$block->current();
    // block already in the blockchain
    if($current['id']==$data['id']) api_echo("block-ok");
    if($data['date']>time()+30) api_err("block in the future");

    if($current['height']==$data['height']&&$current['id']!=$data['id']){
        // different forks, same height
        $accept_new=false;
        if($current['transactions']<$data['transactions']){
    	    // accept the one with most transactions
            $accept_new=true;
        } elseif($current['transactions']==$data['transactions']) {
            // convert the first 12 characters from hex to decimal and the block with the largest number wins
            $no1=hexdec(substr(coin2hex($current['id']),0,12));
            $no2=hexdec(substr(coin2hex($data['id']),0,12));
            if(gmp_cmp($no1,$no2)==1){
                $accept_new=true;
            }
        }
        if($accept_new){
	    // if the new block is accepted, run a microsanity to sync it
	    _log('['.$ip."] Starting microsanity - $data[height]"); 
            system("php sanity.php microsanity '$ip'  > /dev/null 2>&1  &");
            api_echo("microsanity");
	
        } else {
		 _log('['.$ip."] suggesting reverse-microsanity - $data[height]"); 
		 api_echo("reverse-microsanity"); // if it's not, suggest to the peer to get the block from us
	}
    }
    // if it's not the next block
    if($current['height']!=$data['height']-1) {
	// if the height of the block submitted is lower than our current height, send them our current block
        if($data['height']<$current['height']){ 
		$pr=$db->row("SELECT * FROM peers WHERE ip=:ip",array(":ip"=>$ip));
		if(!$pr) api_err("block-too-old");
		$peer_host=base58_encode($pr['hostname']);
		$pr['ip']=escapeshellcmd($pr['ip']);
		system("php propagate.php block current '$peer_host' '$pr[ip]'   > /dev/null 2>&1  &");
		_log('['.$ip."] block too old, sending our current block - $data[height]");

		api_err("block-too-old");
	}
	// if the block difference is bigger than 150, nothing should be done. They should sync via sanity
        if($data['height']-$current['height']>150) { 
		_log('['.$ip."] block-out-of-sync - $data[height]");  
		api_err("block-out-of-sync"); 
	}
	// request them to send us a microsync with the latest blocks
	_log('['.$ip."] requesting microsync - $current[height] - $data[height]");
        api_echo(array("request"=>"microsync","height"=>$current['height'], "block"=>$current['id']));
        
    }
    // check block data
    if(!$block->check($data)){
	_log('['.$ip."] invalid block - $data[height]");
	 api_err("invalid-block"); 
    }
    $b=$data;
    // add the block to the blockchain
    $res=$block->add($b['height'], $b['public_key'], $b['nonce'], $b['data'], $b['date'], $b['signature'], $b['difficulty'], $b['reward_signature'], $b['argon']);	
   
    if(!$res) {
	_log('['.$ip."] invalid block data - $data[height]");
	api_err("invalid-block-data"); 
    }

    _log('['.$ip."] block ok, repropagating - $data[height]");

    // send it to all our peers
    system("php propagate.php block '$data[id]' all all linear > /dev/null 2>&1  &");
    api_echo("block-ok");
}
// return the current block, used in syncing
elseif($q=="currentBlock"){
   $current=$block->current();
    api_echo($current);
}
// return a specific block, used in syncing
elseif($q=="getBlock"){
    $height=intval($data['height']);
    
    $export=$block->export("",$height);
    if(!$export) api_err("invalid-block");
     api_echo($export);
 }
 elseif($q=="getBlocks"){
// returns X block starting at height,  used in syncing

    $height=intval($data['height']);
    
    $r=$db->run("SELECT id,height FROM blocks WHERE height>=:height ORDER by height ASC LIMIT 100",array(":height"=>$height));
    foreach($r as $x){
        $blocks[$x['height']]=$block->export($x['id']);
    }
    api_echo($blocks);

 }
 // returns a full list of unblacklisted peers in a random order
 elseif($q=="getPeers"){
    $peers=$db->run("SELECT ip,hostname FROM peers WHERE blacklisted<UNIX_TIMESTAMP() ORDER by RAND()");
    api_echo($peers);
 } else {
     api_err("Invalid request");
 }
 
?>
