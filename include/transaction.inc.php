<?php

class Transaction {


    public function reverse($block){
        global $db;
        $acc=new Account;
        $r=$db->run("SELECT * FROM transactions WHERE block=:block",array(":block"=>$block));
        foreach($r as $x){
            if(empty($x['src'])) $x['src']=$acc->get_address($x['public_key']);
            $db->run("UPDATE accounts SET balance=balance-:val WHERE id=:id",array(":id"=>$x['dst'], ":val"=>$x['val']));
            if($x['version']>0) $db->run("UPDATE accounts SET balance=balance+:val WHERE id=:id",array(":id"=>$x['src'], ":val"=>$x['val']+$x['fee']));
            
            if($x['version']>0) $this->add_mempool($x);
           $res= $db->run("DELETE FROM transactions WHERE id=:id",array(":id"=>$x['id']));
           if($res!=1) return false;
         }
    }

    public function clean_mempool(){
        global $db;
        $block= new Block;
	    $current=$block->current();
        $height=$current['height'];
        $limit=$height-1000;
        $db->run("DELETE FROM mempool WHERE height<:limit",array(":limit"=>$limit));
    }

    public function mempool($max){
        global $db;
        $block=new Block;
        $current=$block->current();
        $height=$current['height']+1;
        $r=$db->run("SELECT * FROM mempool WHERE height<=:height ORDER by val/fee DESC LIMIT :max",array(":height"=>$height, ":max"=>$max+50));
        $transactions=array();
        if(count($r)>0){
            $i=0;
            $balance=array();
            foreach($r as $x){
                    $trans=array("id"=>$x['id'],"dst"=>$x['dst'],"val"=>$x['val'],"fee"=>$x['fee'],"signature"=>$x['signature'], "message"=>$x['message'],"version"=>$x['version'],"date"=>$x['date'], "public_key"=>$x['public_key']);
                
                    if($i>=$max) break;

                    if(empty($x['public_key'])){
                        _log("$x[id] - Transaction has empty public_key");
                        continue;
                    } 
                    if(empty($x['src'])){
                        _log("$x[id] - Transaction has empty src");
                        continue;
                    } 
                    if(!$this->check($trans)){
                        var_dump($trans);
                        _log("$x[id] - Transaction Check Failed");
                        continue;
                    } 
                    
                    $balance[$x['src']]+=$x['val']+$x['fee'];
                    if($db->single("SELECT COUNT(1) FROM transactions WHERE id=:id",array(":id"=>$x['id']))>0) {
                        _log("$x[id] - Duplicate transaction");
                        continue; //duplicate transaction	
                    }
                    
                    $res=$db->single("SELECT COUNT(1) FROM accounts WHERE id=:id AND balance>=:balance",array(":id"=>$x['src'], ":balance"=>$balance[$x['src']]));
                    
                    if($res==0) {
                        _log("$x[id] - Not enough funds in balance");
                        continue; // not enough balance for the transactions	
                    } 
                    $i++;
                    ksort($trans);
                    $transactions[$x['id']]=$trans;
                }
        }
        ksort($transactions);

        return $transactions;
    }

    public function add_mempool($x, $peer=""){
        global $db;
        $block= new Block;
	    $current=$block->current();
        $height=$current['height'];
        $x['id']=san($x['id']);
        $bind=array(":peer"=>$peer, ":id"=>$x['id'],"public_key"=>$x['public_key'], ":height"=>$height, ":src"=>$x['src'],":dst"=>$x['dst'],":val"=>$x['val'], ":fee"=>$x['fee'],":signature"=>$x['signature'], ":version"=>$x['version'],":date"=>$x['date'], ":message"=>$x['message']);
        $db->run("INSERT into mempool  SET peer=:peer, id=:id, public_key=:public_key, height=:height, src=:src, dst=:dst, val=:val, fee=:fee, signature=:signature, version=:version, message=:message, `date`=:date",$bind);
        return true;


    }

    public function add($block,$height, $x){
        global $db;
        $acc= new Account;
        $acc->add($x['public_key'], $block);
        $acc->add_id($x['dst'],$block);
        $x['id']=san($x['id']);
        $bind=array(":id"=>$x['id'], ":public_key"=>$x['public_key'],":height"=>$height, ":block"=>$block, ":dst"=>$x['dst'],":val"=>$x['val'], ":fee"=>$x['fee'],":signature"=>$x['signature'], ":version"=>$x['version'],":date"=>$x['date'], ":message"=>$x['message']);
        $res=$db->run("INSERT into transactions SET id=:id, public_key=:public_key, block=:block,  height=:height, dst=:dst, val=:val, fee=:fee, signature=:signature, version=:version, message=:message, `date`=:date",$bind);
        if($res!=1) return false;
        $db->run("UPDATE accounts SET balance=balance+:val WHERE id=:id",array(":id"=>$x['dst'], ":val"=>$x['val']));
	 if($x['version']>0) $db->run("UPDATE accounts SET balance=(balance-:val)-:fee WHERE id=:id",array(":id"=>$x['src'], ":val"=>$x['val'], ":fee"=>$x['fee']));
        $db->run("DELETE FROM mempool WHERE id=:id",array(":id"=>$x['id']));
        return true;


    }

    public function hash($x){
        $info=$x['val']."-".$x['fee']."-".$x['dst']."-".$x['message']."-".$x['version']."-".$x['public_key']."-".$x['date']."-".$x['signature'];
        $hash= hash("sha512",$info);
        return hex2coin($hash);   
    }
    

    public function check($x){
                
                $acc= new Account;
                $info=$x['val']."-".$x['fee']."-".$x['dst']."-".$x['message']."-".$x['version']."-".$x['public_key']."-".$x['date'];

                if($x['val']<0){ _log("$x[id] - Value below 0"); return false; }
                if($x['fee']<0) { _log("$x[id] - Fee below 0"); return false; }
            
                $fee=$x['val']*0.0025;
		$fee=number_format($fee,8,".","");
                if($fee<0.00000001) $fee=0.00000001;
                if($fee!=$x['fee']) { _log("$x[id] - Fee not 0.25%"); return false; }

                if(!$acc->valid($x['dst'])) { _log("$x[id] - Invalid destination address"); return false; }
             
                if($x['version']<1) { _log("$x[id] - Invalid version <1"); return false; }
                //if($x['version']>1) { _log("$x[id] - Invalid version >1"); return false; }
                
		        if(strlen($x['public_key'])<15) { _log("$x[id] - Invalid public key size"); return false; }
                if($x['date']<1511725068) { _log("$x[id] - Date before genesis"); return false; }
                if($x['date']>time()+86400) { _log("$x[id] - Date in the future"); return false; }
               
                $id=$this->hash($x);	
                if($x['id']!=$id) { _log("$x[id] - Invalid hash"); return false; }
                
           
    		    if(!$acc->check_signature($info, $x['signature'], $x['public_key'])) { _log("$x[id] - Invalid signature"); return false; }
               
	    return true;
    }
    public function sign($x, $private_key){
        $info=$x['val']."-".$x['fee']."-".$x['dst']."-".$x['message']."-".$x['version']."-".$x['public_key']."-".$x['date'];
        $signature=ec_sign($info,$private_key);
        
        return $signature;

    }


    public function export($id){
        global $db;
        $r=$db->row("SELECT * FROM mempool WHERE id=:id",array(":id"=>$id));
        //unset($r['peer']);
        return $r;

    }
    public function get_transaction($id){
        global $db;
        $block=new Block;
        $current=$block->current();
        $acc=new Account;
        $x=$db->row("SELECT * FROM transactions WHERE id=:id",array(":id"=>$id));
       
		if(!$x) return false;
			$trans=array("block"=>$x['block'],"height"=>$x['height'], "id"=>$x['id'],"dst"=>$x['dst'],"val"=>$x['val'],"fee"=>$x['fee'],"signature"=>$x['signature'], "message"=>$x['message'],"version"=>$x['version'],"date"=>$x['date'], "public_key"=>$x['public_key']);
            $trans['src']=$acc->get_address($x['public_key']);
            $trans['confirmations']=$current['height']-$x['height'];

			if($x['version']==0) $trans['type']="mining";
			elseif($x['version']==1){
				if($x['dst']==$id) $trans['type']="credit";
				else $trans['type']="debit";
			} else {
				$trans['type']="other";
			}
			ksort($trans);
			return $trans;
            
    }

    public function get_mempool_transaction($id){
        global $db;
        $x=$db->row("SELECT * FROM mempool WHERE id=:id",array(":id"=>$id));
		if(!$x) return false;
			$trans=array("block"=>$x['block'],"height"=>$x['height'], "id"=>$x['id'],"dst"=>$x['dst'],"val"=>$x['val'],"fee"=>$x['fee'],"signature"=>$x['signature'], "message"=>$x['message'],"version"=>$x['version'],"date"=>$x['date'], "public_key"=>$x['public_key']);
			$trans['src']=$x['src'];

			$trans['type']="mempool";
            $trans['confirmations']=-1;
			ksort($trans);
			return $trans;
            
    }

}



?>
