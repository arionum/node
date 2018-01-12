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
$cmd=trim($argv[1]);



if($cmd=='clean'){
$tables=array("blocks","accounts","transactions","mempool");
foreach($tables as $table) $db->run("DELETE FROM {$table}");

echo "\n The database has been cleared\n";

}


elseif($cmd=='pop'){
	$no=intval($argv[2]);
	$block=new Block;
	$block->pop($no);
}


elseif($cmd=='block-time'){
	$t=time();
	$r=$db->run("SELECT * FROM blocks ORDER by height DESC LIMIT 100");
	$start=0;
	foreach($r as $x){
		if($start==0) $start=$x['date'];
		$time=$t-$x['date'];
		$t=$x['date'];
		echo "$x[height] -> $time\n";
		$end=$x['date'];
	}
echo "Average block time: ".ceil(($start-$end)/100)." seconds\n";


}

elseif($cmd=="peer"){
	$res=peer_post($argv[2]."/peer.php?q=peer",array("hostname"=>$_config['hostname']));
	if($res!==false) echo "Peering OK\n";
	else echo "Peering FAIL\n";
} 
elseif ($cmd=="current") {
	$block=new Block;
	var_dump($block->current());
} elseif($cmd=="blocks"){
	$height=intval($argv[2]);
	$limit=intval($argv[3]);
	if($limit<1) $limit=100;
	$r=$db->run("SELECT * FROM blocks WHERE height>:height ORDER by height ASC LIMIT $limit",array(":height"=>$height));
	foreach($r as $x){
		echo "$x[height]\t$x[id]\n";
	}
}

elseif($cmd=="recheck-blocks"){
	$blocks=array();
	$block=new Block();
	$r=$db->run("SELECT * FROM blocks ORDER by height ASC");
	foreach($r as $x){
		$blocks[$x['height']]=$x;
		$max_height=$x['height'];
	}
	for($i=2;$i<=$max_height;$i++){
		$data=$blocks[$i];
		
		$key=$db->single("SELECT public_key FROM accounts WHERE id=:id",array(":id"=>$data['generator']));

		if(!$block->mine($key,$data['nonce'], $data['argon'], $data['difficulty'], $blocks[$i-1]['id'])) {
			_log("Invalid block detected. We should delete everything after $data[height] - $data[id]");
			break;
		}
	}
} elseif($cmd=="peers"){
	$r=$db->run("SELECT * FROM peers ORDER by reserve ASC LIMIT 100");
	foreach($r as $x){
		echo "$x[hostname]\t$x[reserve]\n";
	}
	
} elseif($cmd=="mempool"){
$res=$db->single("SELECT COUNT(1) from mempool");
echo "Mempool size: $res\n";

} elseif($cmd=="delete-peer"){
        $peer=trim($argv[2]);
        if(empty($peer)) die("Invalid peer");
        $db->run("DELETE FROM peers WHERE ip=:ip",array(":ip"=>$peer));
        echo "Peer removed\n";
}elseif($cmd=="recheck-peers"){
	$r=$db->run("SELECT * FROM peers");
	foreach($r as $x){
		$a=peer_post($x['hostname']."/peer.php?q=ping");
		if($a!="pong"){
			echo "$x[hostname] -> failed\n";
			$db->run("DELETE FROM peers WHERE id=:id",array(":id"=>$x['id']));
		} else echo "$x[hostname] ->ok \n";
	}

}elseif($cmd=="peers-block"){
	$r=$db->run("SELECT * FROM peers");
        foreach($r as $x){
                $a=peer_post($x['hostname']."/peer.php?q=currentBlock",array(),5);
		$enc=base58_encode($x['hostname']);
		if($argv[2]=="debug") echo "$enc\t";
		echo "$x[hostname]\t$a[height]\n";

        }


} else {
	echo "Invalid command\n";
}


?>
