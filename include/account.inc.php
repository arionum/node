<?php

class Account {
	
	

	public function add($public_key, $block){
		global $db;
		$id=$this->get_address($public_key);
		$bind=array(":id"=>$id, ":public_key"=>$public_key, ":block"=>$block,":public_key2"=>$public_key );
		
		$db->run("INSERT INTO accounts SET id=:id, public_key=:public_key, block=:block, balance=0 ON DUPLICATE KEY UPDATE public_key=if(public_key='',:public_key2,public_key)",$bind);
	}
	public function add_id($id, $block){
		global $db;
		$bind=array(":id"=>$id, ":block"=>$block);
		$db->run("INSERT ignore INTO accounts SET id=:id, public_key='', block=:block, balance=0",$bind);
	}

	public function get_address($hash){
	      for($i=0;$i<9;$i++) $hash=hash('sha512',$hash, true);
			
			
			
			
			return base58_encode($hash);
        	

	}

	public function check_signature($data, $signature, $public_key){
	
		return ec_verify($data ,$signature, $public_key);
	}


	public function generate_account(){

		$args = array(
			"curve_name" => "secp256k1",
			"private_key_type" => OPENSSL_KEYTYPE_EC,
		);
		
		
		$key1 = openssl_pkey_new($args);
		
		openssl_pkey_export($key1, $pvkey);
		
		$private_key= pem2coin($pvkey);
	
		$pub = openssl_pkey_get_details($key1);
		
		$public_key= pem2coin($pub['key']);
		
		$address=$this->get_address($public_key);
		return array("address"=>$address, "public_key"=>$public_key,"private_key"=>$private_key);


	}
	public function valid_key($id){
		$chars = str_split("123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz");
		for($i=0;$i<strlen($id);$i++) if(!in_array($id[$i],$chars)) return false;

		return true;

	}
	public function valid($id){
		if(strlen($id)<70||strlen($id)>128) return false;
		$chars = str_split("123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz");
		for($i=0;$i<strlen($id);$i++) if(!in_array($id[$i],$chars)) return false;

		return true;

	}
	public function balance($id){
		global $db;
		$res=$db->single("SELECT balance FROM accounts WHERE id=:id",array(":id"=>$id));
		if($res===false) $res="0.00000000";
		return number_fomrat($res,8,".","");
	}
	public function pending_balance($id){
		global $db;
		$res=$db->single("SELECT balance FROM accounts WHERE id=:id",array(":id"=>$id));
		if($res===false) $res="0.00000000";
		if($res=="0.00000000") return $res;
		$mem=$db->single("SELECT SUM(val+fee) FROM mempool WHERE src=:id",array(":id"=>$id));
		$rez=$res-$mem;
		return number_fomrat($rez,8,".","");
		
	}
	public function get_transactions($id){
		global $db;
		$block=new Block;
        $current=$block->current();
		$public_key=$this->public_key($id);
		$res=$db->run("SELECT * FROM transactions WHERE dst=:dst or public_key=:src ORDER by height DESC LIMIT 100",array(":src"=>$public_key, ":dst"=>$id));
		
		$transactions=array();
		foreach($res as $x){
			$trans=array("block"=>$x['block'],"height"=>$x['height'], "id"=>$x['id'],"dst"=>$x['dst'],"val"=>$x['val'],"fee"=>$x['fee'],"signature"=>$x['signature'], "message"=>$x['message'],"version"=>$x['version'],"date"=>$x['date'], "public_key"=>$x['public_key']);
			$trans['src']=$this->get_address($x['public_key']);
			$trans['confirmations']=$current['height']-$x['height'];

			if($x['version']==0) $trans['type']="mining";
			elseif($x['version']==1){
				if($x['dst']==$id) $trans['type']="credit";
				else $trans['type']="debit";
			} else {
				$trans['type']="other";
			}
			ksort($trans);
			$transactions[]=$trans;
		}
		return $transactions;
	}
	public function get_mempool_transactions($id){
		global $db;
		$transactions=array();
		$res=$db->run("SELECT * FROM mempool WHERE src=:src ORDER by height DESC LIMIT 100",array(":src"=>$id, ":dst"=>$id));
		foreach($res as $x){
			$trans=array("block"=>$x['block'],"height"=>$x['height'], "id"=>$x['id'],"src"=>$x['src'],"dst"=>$x['dst'],"val"=>$x['val'],"fee"=>$x['fee'],"signature"=>$x['signature'], "message"=>$x['message'],"version"=>$x['version'],"date"=>$x['date'], "public_key"=>$x['public_key']);
			$trans['type']="mempool";
			$trans['confirmations']=-1;
			ksort($trans);
			$transactions[]=$trans;
		}
		return $transactions;
	}
	public function public_key($id){
		global $db;
		$res=$db->single("SELECT public_key FROM accounts WHERE id=:id",array(":id"=>$id));
		return $res;
	}
}



?>
