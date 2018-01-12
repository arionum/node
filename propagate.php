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
set_time_limit(360);
require_once("include/init.inc.php");
$block= new Block();
$type=san($argv[1]);

$id=san($argv[2]);
$debug=false;
if(trim($argv[5])=='debug') $debug=true;

$peer=san(trim($argv[3]));
if((empty($peer)||$peer=='all')&&$type=="block"){
	$whr="";
	if($id=="current") {
		$current=$block->current();
		$id=$current['id'];
	}
	$data=$block->export($id);
	
	if($data===false||empty($data)) die("Could not export block");  
	$data=json_encode($data);
	// cache it to reduce the load
	$res=file_put_contents("tmp/$id",$data);
	if($res===false) die("Could not write the cachce file");
	$r=$db->run("SELECT * FROM peers WHERE blacklisted < UNIX_TIMESTAMP() AND reserve=0");
	foreach($r as $x) {
		$host=base58_encode($x['hostname']);
		if($debug) system("php propagate.php '$type' '$id' '$host' '$x[ip]' debug");
		else system("php propagate.php '$type' '$id' '$host' '$x[ip]' &>/dev/null &");
	}
	exit;
}




if($type=="block"){

	if($id=="current"){
		$current=$block->current();
		$data=$block->export($current['id']);
		if(!$data)  { echo "Invalid Block data"; exit; }
	} else {
		$data=file_get_contents("tmp/$id");
		if(empty($data)) { echo "Invalid Block data"; exit; }
		$data=json_decode($data,true);
	}
	$hostname=base58_decode($peer);

	echo "Block sent to $hostname:\n";
	$response= peer_post($hostname."/peer.php?q=submitBlock",$data,60,$debug);
	if($response=="block-ok") { echo "Block $i accepted. Exiting.\n"; exit;}
	elseif($response['request']=="microsync"){
		echo "Microsync request\n";
		$height=intval($response['height']);
		$bl=san($response['block']);
		$current=$block->current();
		if($current['height']-$height>10) { echo "Height Differece too high\n"; exit; }
		$last_block=$block->get($height);

		if ($last_block['id'] != $bl ) { echo "Last block does not match\n"; exit; }
		echo "Sending the requested blocks\n";

		for($i=$height+1;$i<=$current['height'];$i++){
			$data=$block->export("",$i);
			$response = peer_post($hostname."/peer.php?q=submitBlock",$data,60,$debug);
			if($response!="block-ok") { echo "Block $i not accepted. Exiting.\n"; exit;}
			echo "Block\t$i\t accepted\n";
		}

	} elseif($response=="reverse-microsanity"){
		echo "Running microsanity\n";
		$ip=trim($argv[4]);
		if(empty($ip)) die("Invalid IP");
		system("php sanity.php microsanity '$ip' &>/dev/null &");
	}
	else echo "Block not accepted!\n";

}
if($type=="transaction"){

	$trx=new Transaction;
	
	$data=$trx->export($id);

	if(!$data){ echo "Invalid transaction id\n"; exit; }
	
	if($data['peer']=="local") $r=$db->run("SELECT hostname FROM peers WHERE blacklisted < UNIX_TIMESTAMP()");
	else $r=$db->run("SELECT hostname FROM peers WHERE blacklisted < UNIX_TIMESTAMP() AND reserve=0  ORDER by RAND() LIMIT ".$_config['transaction_propagation_peers']);
	foreach($r as $x){
		$res= peer_post($x['hostname']."/peer.php?q=submitTransaction",$data);
		if(!$res) echo "Transaction not accepted\n";
		else echo "Transaction accepted\n";
	}
}

?>
