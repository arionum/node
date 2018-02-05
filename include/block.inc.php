<?php

class Block {



public function add($height, $public_key, $nonce, $data, $date, $signature, $difficulty, $reward_signature, $argon){
	global $db;
	$acc=new Account;
	$trx=new Transaction;
	//try {
		
	// }	catch (Exception $e){
		
	// }
	
	$generator=$acc->get_address($public_key);
	
	ksort($data);
	
	$hash=$this->hash($generator, $height, $date, $nonce, $data, $signature, $difficulty, $argon);

	
	$json=json_encode($data);

	$info="{$generator}-{$height}-{$date}-{$nonce}-{$json}-{$difficulty}-{$argon}";
	
	if(!$acc->check_signature($info,$signature,$public_key)) return false;


	if(!$this->parse_block($hash,$height,$data, true)) return false;

	$db->exec("LOCK TABLES blocks WRITE, accounts WRITE, transactions WRITE, mempool WRITE");

		$reward=$this->reward($height,$data);
	
		$msg='';
	
	
		$transaction=array("src"=>$generator, "dst"=>$generator, "val"=>$reward, "version"=>0, "date"=>$date, "message"=>$msg, "fee"=>"0.00000000","public_key"=>$public_key);
		$transaction['signature']=$reward_signature;
		$transaction['id']=$trx->hash($transaction);
		$info=$transaction['val']."-".$transaction['fee']."-".$transaction['dst']."-".$transaction['message']."-".$transaction['version']."-".$transaction['public_key']."-".$transaction['date'];
		
		if(!$acc->check_signature($info,$reward_signature,$public_key)) return false;

		$db->beginTransaction();
		$total=count($data);
	$bind=array(":id"=>$hash,":generator"=>$generator, ":signature"=>$signature, ":height"=>$height, ":date"=>$date, ":nonce"=>$nonce, ":difficulty"=>$difficulty,":argon"=>$argon, ":transactions"=>$total);
	$res=$db->run("INSERT into blocks SET id=:id, generator=:generator, height=:height,`date`=:date,nonce=:nonce, signature=:signature, difficulty=:difficulty, argon=:argon, transactions=:transactions",$bind);
	if($res!=1) {
		$db->rollback();
		$db->exec("UNLOCK TABLES");
		return false;
	}
    

	$trx->add($hash, $height,$transaction);


	$res=$this->parse_block($hash,$height,$data, false);
	if($res==false) $db->rollback();
	else $db->commit();
	$db->exec("UNLOCK TABLES");
	return true;
}

public function current(){
	global $db;
	$current=$db->row("SELECT * FROM blocks ORDER by height DESC LIMIT 1");
	if(!$current){ 
		$this->genesis();
		return $this->current(true);	
	}
	return $current;

}

public function prev(){
	global $db;
	$current=$db->row("SELECT * FROM blocks ORDER by height DESC LIMIT 1,1");

	return $current;

}

public function difficulty($height=0){
	global $db;
	if($height==0){
		$current=$this->current();
	} else{
		$current=$this->get($height);
	}
	
	
	$height=$current['height'];

	$limit=20;
	if($height<20) 
		$limit=$height-1;
	
	if($height<10) return $current['difficulty'];


	$first=$db->row("SELECT `date` FROM blocks ORDER by height DESC LIMIT $limit,1");
	$time=$current['date']-$first['date'];
	$result=ceil($time/$limit);
	if($result>220){
		$dif= bcmul($current['difficulty'], 1.05);
	} elseif($result<260){
		$dif= bcmul($current['difficulty'], 0.95);
	} else {
		$dif=$current['difficulty'];
	}
	if(strpos($dif,'.')!==false){
		$dif=substr($dif,0,strpos($dif,'.'));
	}
	if($dif<1000) $dif=1000;
	if($dif>9223372036854775800) $dif=9223372036854775800;

	return $dif;
}

public function max_transactions(){
	global $db;
	$current=$this->current();
	$limit=$current['height']-100;
	$avg=$db->single("SELECT AVG(transactions) FROM blocks WHERE height>:limit",array(":limit"=>$limit));
	if($avg<100) return 100;
	return ceil($avg*1.1);
}

public function reward($id,$data=array()){

	
	$reward=1000;
	
	$factor=floor($id/10800)/100;
	$reward-=$reward*$factor;
	if($reward<0) $reward=0;

	$fees=0;
	if(count($data)>0){
		
		foreach($data as $x){
			$fees+=$x['fee'];
		}
	}
	return number_format($reward+$fees,8,'.','');
}


public function check($data){
	if(strlen($data['argon'])<20) return false;
	$acc=new Account;
	if(!$acc->valid_key($data['public_key'])) return false;
    if($data['difficulty']!=$this->difficulty()) return false;
	if(!$this->mine($data['public_key'],$data['nonce'], $data['argon'])) return false;
	
	return true;

}


public function forge($nonce, $argon, $public_key, $private_key){

	if(!$this->mine($public_key,$nonce, $argon)) return false;

	$current=$this->current();
	$height=$current['height']+=1;
	$date=time();
	if($date<=$current['date']) return 0;
	
	$txn=new Transaction;
	$data=$txn->mempool($this->max_transactions());

	
	$difficulty=$this->difficulty();
	$acc=new Account;
	$generator=$acc->get_address($public_key);
	ksort($data);
	$signature=$this->sign($generator, $height, $date, $nonce, $data, $private_key, $difficulty, $argon);
	
		// reward signature
		$reward=$this->reward($height,$data);
		$msg='';
		$transaction=array("src"=>$generator, "dst"=>$generator, "val"=>$reward, "version"=>0, "date"=>$date, "message"=>$msg, "fee"=>"0.00000000","public_key"=>$public_key);
		ksort($transaction);
		$reward_signature=$txn->sign($transaction, $private_key);
		

	$res=$this->add($height, $public_key, $nonce, $data, $date, $signature, $difficulty, $reward_signature, $argon);	
	if(!$res) return false;
	return true;
}


public function mine($public_key, $nonce, $argon, $difficulty=0, $current_id=0, $current_height=0){
	global $_config;
	if($current_id===0){
		$current=$this->current();
		$current_id=$current['id'];
		$current_height=$current['height'];
	} 
	if($difficulty===0) $difficulty=$this->difficulty();
	
	
	if($current_height>10800) 	$argon='$argon2i$v=19$m=524288,t=1,p=1'.$argon; //10800
	else $argon='$argon2i$v=19$m=16384,t=4,p=4'.$argon;
	$base="$public_key-$nonce-".$current_id."-$difficulty";
	
	
	
	
	if(!password_verify($base,$argon)) { return false; }

	if($_config['testnet']==true) return true;

	$hash=$base.$argon;

	for($i=0;$i<5;$i++) $hash=hash("sha512",$hash,true);
	$hash=hash("sha512",$hash);
	
	$m=str_split($hash,2);

	$duration=hexdec($m[10]).hexdec($m[15]).hexdec($m[20]).hexdec($m[23]).hexdec($m[31]).hexdec($m[40]).hexdec($m[45]).hexdec($m[55]);
	$duration=ltrim($duration, '0');
	$result=gmp_div($duration, $difficulty);

	if($result>0&&$result<=240) return true;
	return false;

}




public function parse_block($block, $height, $data, $test=true){
	global $db;

	if($data===false) return false;
	$acc=new Account;
	$trx=new Transaction;
	if(count($data)==0) return true;

	$max=$this->max_transactions();

	if(count($data)>$max) return false;
	$balance=array();
	foreach($data as &$x){
		if(empty($x['src'])) $x['src']=$acc->get_address($x['public_key']);
		
		if(!$trx->check($x,$height)) return false;
		
		$balance[$x['src']]+=$x['val']+$x['fee'];
		
		if($db->single("SELECT COUNT(1) FROM transactions WHERE id=:id",array(":id"=>$x['id']))>0) return false; //duplicate transaction	

	}
	
	foreach($balance as $id=>$bal){
		$res=$db->single("SELECT COUNT(1) FROM accounts WHERE id=:id AND balance>=:balance",array(":id"=>$id, ":balance"=>$bal));
		if($res==0) return false; // not enough balance for the transactions	
	}
	
	if($test==false){
		
		foreach($data as $d){
			$res=$trx->add($block, $height, $d);
			if($res==false) return false;
		}
	}
	
	return true;
}


private function genesis(){
	global $db;
	$signature='AN1rKvtLTWvZorbiiNk5TBYXLgxiLakra2byFef9qoz1bmRzhQheRtiWivfGSwP6r8qHJGrf8uBeKjNZP1GZvsdKUVVN2XQoL';
	$generator='2P67zUANj7NRKTruQ8nJRHNdKMroY6gLw4NjptTVmYk6Hh1QPYzzfEa9z4gv8qJhuhCNM8p9GDAEDqGUU1awaLW6';
	$public_key='PZ8Tyr4Nx8MHsRAGMpZmZ6TWY63dXWSCyjGMdVDanywM3CbqvswVqysqU8XS87FcjpqNijtpRSSQ36WexRDv3rJL5X8qpGvzvznuErSRMfb2G6aNoiaT3aEJ';
	$reward_signature='381yXZ3yq2AXHHdXfEm8TDHS4xJ6nkV4suXtUUvLjtvuyi17jCujtwcwXuYALM1F3Wiae2A4yJ6pXL1kTHJxZbrJNgtsKEsb';
	$argon='$M1ZpVzYzSUxYVFp6cXEwWA$CA6p39MVX7bvdXdIIRMnJuelqequanFfvcxzQjlmiik';
	
	$difficulty="5555555555";
	$height=1;
	$data=array();
	$date='1515324995';
	$nonce='4QRKTSJ+i9Gf9ubPo487eSi+eWOnIBt9w4Y+5J+qbh8=';


	$res=$this->add($height, $public_key, $nonce, $data, $date, $signature, $difficulty, $reward_signature,$argon);	
	if(!$res) api_err("Could not add the genesis block.");
}
// delete last X blocks
public function pop($no=1){
	$current=$this->current();
	$this->delete($current['height']-$no+1);
}

public function delete($height){
	if($height<2) $height=2;
	global $db;
	$trx=new Transaction;
	
	$r=$db->run("SELECT * FROM blocks WHERE height>=:height ORDER by height DESC",array(":height"=>$height));

	if(count($r)==0) return;
	$db->beginTransaction();
	$db->exec("LOCK TABLES blocks WRITE, accounts WRITE, transactions WRITE, mempool WRITE");
	foreach($r as $x){
		$res=$trx->reverse($x['id']);
		if($res===false) {
			$db->rollback();
			$db->exec("UNLOCK TABLES");
			return false;
		}
		$res=$db->run("DELETE FROM blocks WHERE id=:id",array(":id"=>$x['id']));
		if($res!=1){
			$db->rollback();
			$db->exec("UNLOCK TABLES");
			return false;
		}
	}

	$db->commit();
	$db->exec("UNLOCK TABLES");
	return true;
}

public function delete_id($id){
	
	global $db;
	$trx=new Transaction;
	
	$x=$db->row("SELECT * FROM blocks WHERE id=:id",array(":id"=>$id));

	if($x===false) return false;
	$db->beginTransaction();
	$db->exec("LOCK TABLES blocks WRITE, accounts WRITE, transactions WRITE, mempool WRITE");
	
		$res=$trx->reverse($x['id']);
		if($res===false) {
			$db->rollback();
			$db->exec("UNLOCK TABLES");
			return false;
		}
		$res=$db->run("DELETE FROM blocks WHERE id=:id",array(":id"=>$x['id']));
		if($res!=1){
			$db->rollback();
			$db->exec("UNLOCK TABLES");
			return false;
		}

	$db->commit();
	$db->exec("UNLOCK TABLES");
	return true;
}

public function sign($generator, $height, $date, $nonce, $data, $key, $difficulty, $argon){

	$json=json_encode($data);
	$info="{$generator}-{$height}-{$date}-{$nonce}-{$json}-{$difficulty}-{$argon}";
	
	$signature=ec_sign($info,$key);
	return $signature;

}

public function hash($public_key, $height, $date, $nonce, $data, $signature, $difficulty, $argon){
	$json=json_encode($data);
	$hash= hash("sha512", "{$public_key}-{$height}-{$date}-{$nonce}-{$json}-{$signature}-{$difficulty}-{$argon}");
	return hex2coin($hash);
}


public function export($id="",$height=""){
	if(empty($id)&&empty($height)) return false;

	global $db;
	$trx=new Transaction;
	if(!empty($height)) $block=$db->row("SELECT * FROM blocks WHERE height=:height",array(":height"=>$height));
	else $block=$db->row("SELECT * FROM blocks WHERE id=:id",array(":id"=>$id));
	
	if(!$block) return false;
	$r=$db->run("SELECT * FROM transactions WHERE version>0 AND block=:block",array(":block"=>$block['id']));
	$transactions=array();
	foreach($r as $x){
		$trans=array("id"=>$x['id'],"dst"=>$x['dst'],"val"=>$x['val'],"fee"=>$x['fee'],"signature"=>$x['signature'], "message"=>$x['message'],"version"=>$x['version'],"date"=>$x['date'], "public_key"=>$x['public_key']);
        ksort($trans);        
		$transactions[$x['id']]=$trans;
	}
	ksort($transactions);
	$block['data']=$transactions;

	$gen=$db->row("SELECT public_key, signature FROM transactions WHERE  version=0 AND block=:block",array(":block"=>$block['id']));
	$block['public_key']=$gen['public_key'];
	$block['reward_signature']=$gen['signature'];
	return $block;

}

public function get($height){
	global $db;
	if(empty($height)) return false;
	$block=$db->row("SELECT * FROM blocks WHERE height=:height",array(":height"=>$height));
	return $block;
}

}
?>
