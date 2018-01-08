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
set_time_limit(0);
if(php_sapi_name() !== 'cli') die("This should only be run as cli");

if(file_exists("tmp/sanity-lock")){

	$pid_time=filemtime("tmp/sanity-lock");
	if(time()-$pid_time>3600){
		@unlink("tmp/sanity-lock");
	}
	die("Sanity lock in place");
} 
$lock = fopen("tmp/sanity-lock", "w");
fclose($lock);
$arg=trim($argv[1]);
$arg2=trim($argv[2]);

if($arg!="microsanity") sleep(10);


require_once("include/init.inc.php");


if($_config['dbversion']<2){
	die("DB schema not created");
	@unlink("tmp/sanity-lock");
	exit;
}

$block=new Block();
$acc=new Account();
$current=$block->current();


$microsanity=false;
if($arg=="microsanity"&&!empty($arg2)){

do {
	$x=$db->row("SELECT id,hostname FROM peers WHERE reserve=0 AND blacklisted<UNIX_TIMESTAMP() AND ip=:ip",array(":ip"=>$arg2));
	
	if(!$x){ echo "Invalid node - $arg2\n"; break; }
	$url=$x['hostname']."/peer.php?q=";
	$data=peer_post($url."getBlock",array("height"=>$current['height']));
	
	if(!$data) {echo "Invalid getBlock result\n"; break; }
	if($data['id']==$current['id']) {echo "Same block\n"; break;}

	if($current['transactions']>$data['transactions']){
		echo "Block has less transactions\n";
		break;
	} elseif($current['transactions']==$data['transactions']) {
		$no1=hexdec(substr(coin2hex($current['id']),0,12));
		$no2=hexdec(substr(coin2hex($data['id']),0,12));
		
		if(gmp_cmp($no1,$no2)!=-1){
			echo "Block hex larger than current\n";
			break;
		}
	}

	$prev = $block->get($current['height']-1);
	
	$public=$acc->public_key($data['generator']);
	if(!$block->mine($public, $data['nonce'],$data['argon'],$block->difficulty($current['height']-1),$prev['id'])) { echo "Invalid prev-block\n"; break;}
	$block->pop(1);
	if(!$block->check($data)) break;

	
	echo "Starting to sync last block from $x[hostname]\n";
	$b=$data;
	$res=$block->add($b['height'], $b['public_key'], $b['nonce'], $b['data'], $b['date'], $b['signature'], $b['difficulty'], $b['reward_signature'], $b['argon']);	
	if(!$res) {
		
		_log("Block add: could not add block - $b[id] - $b[height]"); 					
		
		break; 
	}
	
	_log("Synced block from $host - $b[height] $b[difficulty]"); 
	



} while(0);

	@unlink("tmp/sanity-lock");
exit;
} 


$t=time();
//if($t-$_config['sanity_last']<300) {@unlink("tmp/sanity-lock");  die("The sanity cron was already run recently"); }
$db->run("UPDATE config SET val=:time WHERE cfg='sanity_last'",array(":time"=>$t));
$block_peers=array();
$longest_size=0;
$longest=0;
$blocks=array();
$blocks_count=array();
$most_common="";
$most_common_size=0;


// checking peers

$db->run("DELETE from peers WHERE fails>50");

$r=$db->run("SELECT id,hostname FROM peers WHERE reserve=0 AND blacklisted<UNIX_TIMESTAMP()");

$total_peers=count($r);

if($total_peers==0){
	$i=0;
	echo "No peers found. Attempting to get peers from arionum.com\n";
	$f=file("https://www.arionum.com/peers.txt");
	shuffle($f);
	if(count($f)<2) die("Could nto connect to arionum.com! Will try later!\n");
	foreach($f as $peer){
		$peer=trim($peer);
		$peer = filter_var($peer, FILTER_SANITIZE_URL);
        if (!filter_var($peer, FILTER_VALIDATE_URL)) continue;

		$res=peer_post($peer."/peer.php?q=peer",array("hostname"=>$_config['hostname']));
		if($res!==false) {$i++; echo "Peering OK - $peer\n"; }
		else echo "Peering FAIL - $peer\n";
		if($i>$_config['max_peers']) break;
	}
	$r=$db->run("SELECT id,hostname FROM peers WHERE reserve=0 AND blacklisted<UNIX_TIMESTAMP()");
	$total_peers=count($r);
	if($total_peers==0){
		@unlink("tmp/sanity-lock");
		die("Could not peer to any peers! Please check internet connectivity!\n");
	}
}


foreach($r as $x){
	$url=$x['hostname']."/peer.php?q=";
	$data=peer_post($url."getPeers");
	if($data===false) { 

		$db->run("UPDATE peers SET fails=fails+1, blacklisted=UNIX_TIMESTAMP()+((fails+1)*3600) WHERE id=:id",array(":id"=>$x['id']));		
		continue;
	}
	
		$i=0;
		foreach($data as $peer){
			if($peer['hostname']==$_config['hostname']) continue;
			if (!filter_var($peer['hostname'], FILTER_VALIDATE_URL)) continue;
						
				if(!$db->single("SELECT COUNT(1) FROM peers WHERE ip=:ip or hostname=:hostname",array(":ip"=>$peer['ip'],":hostname"=>$peer['hostname']))){
					$i++;
					if($i>$_config['max_test_peers']) break;
					$peer['hostname'] = filter_var($peer['hostname'], FILTER_SANITIZE_URL);
					
					$test=peer_post($peer['hostname']."/peer.php?q=peer",array("hostname"=>$_config['hostname']));
					if($test!==false){
						 $total_peers++;
						echo "Peered with: $peer[hostname]\n";
					}
				}
			}
	


	


	$data=peer_post($url."currentBlock");
	if($data===false) continue;
	$db->run("UPDATE peers SET fails=0 WHERE id=:id",array(":id"=>$x['id']));		
		

		$block_peers[$data['id']][]=$x['hostname'];
		$blocks_count[$data['id']]++;
		$blocks[$data['id']]=$data;
		if($blocks_count[$data['id']]>$most_common_size){
			$most_common=$data['id'];
			$most_common_size=$blocks_count[$data['id']];
		}
		if($data['height']>$largest_height){
			 $largest_height=$data['height'];
			 $largest_height_block=$data['id'];
		} elseif($data['height']==$largestblock&&$data['id']!=$largest_height_block){
			if($data['difficulty']==$blocks[$largest_height_block]['difficulty']){
					if($most_common==$data['id']){
						$largest_height=$data['height'];
						$largest_height_block=$data['id'];
					} else {
						if($blocks[$largest_height_block]['transactions']<$data['transactions']){
							$largest_height=$data['height'];
							$largest_height_block=$data['id'];
						} elseif($blocks[$largest_height_block]['transactions']==$data['transactions']) {
							$no1=hexdec(substr(coin2hex($largest_height_block),0,12));
							$no2=hexdec(substr(coin2hex($data['id']),0,12));
							if(gmp_cmp($no1,$no2)==1){
								$largest_height=$data['height'];
								$largest_height_block=$data['id'];
							}
						}
					}
			} elseif($data['difficulty']<$blocks[$largest_height_block]['difficulty']){
				$largest_height=$data['height'];
				$largest_height_block=$data['id'];
			}
			
		}



}
echo "Most common: $most_common\n";
echo "Most common block: $most_common_size\n";
echo "Max height: $largest_height\n";
echo "Current block: $current[height]\n";
if($current['height']<$largest_height&&$largest_height>1){
	$db->run("UPDATE config SET val=1 WHERE cfg='sanity_sync'");
	sleep(10);
	_log("Longest chain rule triggered - $largest_height - $largest_height_block"); 
	$peers=$block_peers[$largest_height_block];
	shuffle($peers);
	foreach($peers as $host){
		_log("Starting to sync from $host"); 
		$url=$host."/peer.php?q=";
		$data=peer_post($url."getBlock",array("height"=>$current['height']));

		if($data===false){ _log("Could not get block from $host - $current[height]");  continue; }

		while($data['id']!=$current['id']){
			$block->delete($current['height']-10);
			$current=$block->current();
			$data=peer_post($url."getBlock",array("height"=>$current['height']));
			
			if($data===false){_log("Could not get block from $host - $current[height]"); 	 break; }
		}
		if($data['id']!=$current['id']) continue;
		while($current['height']<$largest_height){
			$data=peer_post($url."getBlocks",array("height"=>$current['height']+1));
			
			if($data===false){_log("Could not get blocks from $host - height: $current[height]");  break; }
			$good_peer=true;
			foreach($data as $b){
				if(!$block->check($b)){
					_log("Block check: could not add block - $b[id] - $b[height]");
					$good_peer=false; 
					break;
				}
				$res=$block->add($b['height'], $b['public_key'], $b['nonce'], $b['data'], $b['date'], $b['signature'], $b['difficulty'], $b['reward_signature'], $b['argon']);	
				if(!$res) {
					
					_log("Block add: could not add block - $b[id] - $b[height]"); 					
					$good_peer=false;
					break; 
				}
				
				_log("Synced block from $host - $b[height] $b[difficulty]"); 
				
			}	
			if(!$good_peer) break;
			$current=$block->current();		

		}
		if($good_peer) break;
		
	}
	$db->run("UPDATE config SET val=0 WHERE cfg='sanity_sync'",array(":time"=>$t));
}



//rebroadcasting transactions
$forgotten=$current['height']-$_config['sanity_rebroadcast_height'];
$r=$db->run("SELECT id FROM mempool WHERE height<:forgotten ORDER by val DESC LIMIT 10",array(":forgotten"=>$forgotten));
foreach($r as $x){
	system("php propagate.php transaction $x[id] &>/dev/null &");
	$db->run("UPDATE mempool SET height=:current WHERE id=:id",array(":id"=>$x['id'], ":current"=>$current['height']));
}


//add new peers if there aren't enough active
if($total_peers<$_config['max_peers']*0.7){
	$res=$_config['max_peers']-$total_peers;
	$db->run("UPDATE peers SET reserve=0 WHERE reserve=1 AND blacklisted<UNIX_TIMESTAMP() LIMIT $res");
}

//random peer check
$r=$db->run("SELECT * FROM peers WHERE blacklisted<UNIX_TIMESTAMP() and reserve=1 LIMIT ".$_config['max_test_peers']);
foreach($r as $x){
	$url=$x['hostname']."/peer.php?q=";
	$data=peer_post($url."ping");
	if($data===false) $db->run("UPDATE peers SET fails=fails+1, blacklisted=UNIX_TIMESTAMP()+((fails+1)*3600) WHERE id=:id",array(":id"=>$x['id']));		
	else $db->run("UPDATE peers SET fails=0 WHERE id=:id",array(":id"=>$x['id']));
}

//clean tmp files
$f=scandir("tmp/");
$time=time();
foreach($f as $x){
	if(strlen($x)<5&&substr($x,0,1)==".") continue;
	$pid_time=filemtime("tmp/$x");
	if($time-$pid_time>7200) @unlink("tmp/$x");
}


//recheck blocks
if($_config['sanity_recheck_blocks']>0){
	$blocks=array();
	$all_blocks_ok=true;
	$start=$current['height']-$_config['sanity_recheck_blocks'];
	if($start<2) $start=2;
	$r=$db->run("SELECT * FROM blocks WHERE height>=:height ORDER by height ASC",array(":height"=>$start));
	foreach($r as $x){
			$blocks[$x['height']]=$x;
			$max_height=$x['height'];
	}
	
	for($i=$start+1;$i<=$max_height;$i++){
			$data=$blocks[$i];

			$key=$db->single("SELECT public_key FROM accounts WHERE id=:id",array(":id"=>$data['generator']));

			if(!$block->mine($key,$data['nonce'], $data['argon'], $data['difficulty'], $blocks[$i-1]['id'])) {
					$db->run("UPDATE config SET val=1 WHERE cfg='sanity_sync'");
					_log("Invalid block detected. Deleting everything after $data[height] - $data[id]");
					sleep(10);
					$all_blocks_ok=false;
					$block->delete($i);

					$db->run("UPDATE config SET val=0 WHERE cfg='sanity_sync'");
					break;
			}
	}
	if($all_blocks_ok) echo "All checked blocks are ok\n";
}


@unlink("tmp/sanity-lock");
?>
