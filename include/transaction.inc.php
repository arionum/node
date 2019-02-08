<?php

use Arionum\Blacklist;

class Transaction
{
    // reverse and remove all transactions from a block
    public function reverse($block)
    {
        global $db;
        
        $acc = new Account();
        $r = $db->run("SELECT * FROM transactions WHERE block=:block ORDER by `version` ASC", [":block" => $block]);
        foreach ($r as $x) {
            _log("Reversing transaction $x[id]", 4);
            if (empty($x['src'])) {
                $x['src'] = $acc->get_address($x['public_key']);
            }
            if ($x['version'] == 2) {
                // payment sent to alias
                $rez=$db->run(
                    "UPDATE accounts SET balance=balance-:val WHERE alias=:alias",
                    [":alias" => $x['dst'], ":val" => $x['val']]
                    );
                if ($rez!=1) {
                    _log("Update alias balance minus failed", 3);
                    return false;
                }
            } else {
                // other type of transactions
       
                if ($x['version']!=100&&$x['version']<111) {
                    $rez=$db->run(
                    "UPDATE accounts SET balance=balance-:val WHERE id=:id",
                    [":id" => $x['dst'], ":val" => $x['val']]
                    );
                    if ($rez!=1) {
                        _log("Update accounts balance minus failed", 3);
                        return false;
                    }
                }
            }
            // on version 0 / reward transaction, don't credit anyone
            if ($x['version'] > 0 && $x['version']<111) {
                $rez=$db->run(
                    "UPDATE accounts SET balance=balance+:val WHERE id=:id",
                    [":id" => $x['src'], ":val" => $x['val'] + $x['fee']]
                );
                if ($rez!=1) {
                    _log("Update account balance plus failed", 3);
                    return false;
                }
            }
            // removing the alias if the alias transaction is reversed
            if ($x['version']==3) {
                $rez=$db->run(
                    "UPDATE accounts SET alias=NULL WHERE id=:id",
                    [":id" => $x['src']]
                );
                if ($rez!=1) {
                    _log("Clear alias failed", 3);
                    return false;
                }
            }


            if ($x['version']>=100&&$x['version']<110&&$x['height']>=80000) {
                if ($x['version']==100) {
                    $rez=$db->run("DELETE FROM masternode WHERE public_key=:public_key", [':public_key'=>$x['public_key']]);
                    if ($rez!=1) {
                        _log("Delete from masternode failed", 3);
                        return false;
                    }
                } elseif ($x['version']==101) {
                    $rez=$db->run(
                        "UPDATE masternode SET status=1 WHERE public_key=:public_key",
                    [':public_key'=>$x['public_key']]
                    );
                } elseif ($x['version']==102) {
                    $rez=$db->run("UPDATE masternode SET status=0 WHERE public_key=:public_key", [':public_key'=>$x['public_key']]);
                } elseif ($x['version']==103) {
                    $mnt=$db->row("SELECT height, `message` FROM transactions WHERE version=100 AND public_key=:public_key ORDER by height DESC LIMIT 1", [":public_key"=>$x['public_key']]);
                    $vers=$db->single(
                        "SELECT `version` FROM transactions WHERE (version=101 or version=102) AND public_key=:public_key AND height>:height ORDER by height DESC LIMIT 1",
                    [":public_key"=>$x['public_key'],":height"=>$mnt['height']]
                    );
                       
                    $status=1;
                        
                    if ($vers==101) {
                        $status=0;
                    }
                    
                    $rez=$db->run(
                        "INSERT into masternode SET `public_key`=:public_key, `height`=:height, `ip`=:ip, `status`=:status",
                    [":public_key"=>$x['public_key'], ":height"=>$mnt['height'], ":ip"=>$mnt['message'], ":status"=>$status]
                    );
                    if ($rez!=1) {
                        _log("Insert into masternode failed", 3);
                        return false;
                    }
                    $rez=$db->run("UPDATE accounts SET balance=balance-100000 WHERE public_key=:public_key", [':public_key'=>$x['public_key']]);
                    if ($rez!=1) {
                        _log("Update masternode balance failed", 3);
                        return false;
                    }
                }
            }
            // internal masternode history
            if ($x['version']==111) {
                _log("Masternode reverse: $x[message]", 4);
                $m=explode(",", $x['message']);

                $rez=$db->run(
                    "UPDATE masternode SET fails=:fails, blacklist=:blacklist, last_won=:last_won WHERE public_key=:public_key",
                [":public_key"=>$x['public_key'], ":blacklist"=> $m[0], ":fails"=>$m[2], ":last_won"=>$m[1]]
                );
                if ($rez!=1) {
                    _log("Update masternode log failed", 3);
                    return false;
                }
            }
    
            // add the transactions to mempool
            if ($x['version'] > 0 && $x['version']<=110) {
                $this->add_mempool($x);
            }
            $res = $db->run("DELETE FROM transactions WHERE id=:id", [":id" => $x['id']]);
            if ($res != 1) {
                _log("Delete transaction failed", 3);
                return false;
            }
        }
    }

    // clears the mempool
    public function clean_mempool()
    {
        global $db;
        $block = new Block();
        $current = $block->current();
        $height = $current['height'];
        $limit = $height - 1000;
        $db->run("DELETE FROM mempool WHERE height<:limit", [":limit" => $limit]);
    }

    // returns X  transactions from mempool
    public function mempool($max)
    {
        global $db;
        $block = new Block();
        $current = $block->current();
        $height = $current['height'] + 1;
        // only get the transactions that are not locked with a future height
        $r = $db->run(
            "SELECT * FROM mempool WHERE height<=:height ORDER by val/fee DESC LIMIT :max",
            [":height" => $height, ":max" => $max + 50]
        );
        $transactions = [];
        if (count($r) > 0) {
            $i = 0;
            $balance = [];
            foreach ($r as $x) {
                $trans = [
                    "id"         => $x['id'],
                    "dst"        => $x['dst'],
                    "val"        => $x['val'],
                    "fee"        => $x['fee'],
                    "signature"  => $x['signature'],
                    "message"    => $x['message'],
                    "version"    => $x['version'],
                    "date"       => $x['date'],
                    "public_key" => $x['public_key'],
                ];

                if ($i >= $max) {
                    break;
                }

                if (empty($x['public_key'])) {
                    _log("$x[id] - Transaction has empty public_key");
                    continue;
                }
                if (empty($x['src'])) {
                    _log("$x[id] - Transaction has empty src");
                    continue;
                }
                if (!$this->check($trans, $current['height'])) {
                    _log("$x[id] - Transaction Check Failed");
                    continue;
                }
  
                $balance[$x['src']] += $x['val'] + $x['fee'];
                if ($db->single("SELECT COUNT(1) FROM transactions WHERE id=:id", [":id" => $x['id']]) > 0) {
                    _log("$x[id] - Duplicate transaction");
                    continue; //duplicate transaction
                }

                $res = $db->single(
                    "SELECT COUNT(1) FROM accounts WHERE id=:id AND balance>=:balance",
                    [":id" => $x['src'], ":balance" => $balance[$x['src']]]
                );

                if ($res == 0) {
                    _log("$x[id] - Not enough funds in balance");
                    continue; // not enough balance for the transactions
                }
                $i++;
                ksort($trans);
                $transactions[$x['id']] = $trans;
            }
        }
        // always sort the array
        ksort($transactions);

        return $transactions;
    }

    // add a new transaction to mempool and lock it with the current height
    public function add_mempool($x, $peer = "")
    {
        global $db;
        global $_config;
        $block = new Block();
        if ($x['version']>110) {
            return true;
        }
        
        if ($_config['use_official_blacklist']!==false) {
            if (Blacklist::checkPublicKey($x['public_key']) || Blacklist::checkAddress($x['src'])) {
                return true;
            }
        }
        $current = $block->current();
        $height = $current['height'];
        $x['id'] = san($x['id']);
        $bind = [
            ":peer"      => $peer,
            ":id"        => $x['id'],
            "public_key" => $x['public_key'],
            ":height"    => $height,
            ":src"       => $x['src'],
            ":dst"       => $x['dst'],
            ":val"       => $x['val'],
            ":fee"       => $x['fee'],
            ":signature" => $x['signature'],
            ":version"   => $x['version'],
            ":date"      => $x['date'],
            ":message"   => $x['message'],
        ];

        //only a single masternode command of same type, per block
        if ($x['version']>=100&&$x['version']<110) {
            $check=$db->single("SELECT COUNT(1) FROM mempool WHERE public_key=:public_key", [":public_key"=>$x['public_key']]);
            if ($check!=0) {
                _log("Masternode transaction already in mempool", 3);
                return false;
            }
        }

        $db->run(
            "INSERT into mempool  SET peer=:peer, id=:id, public_key=:public_key, height=:height, src=:src, dst=:dst, val=:val, fee=:fee, signature=:signature, version=:version, message=:message, `date`=:date",
            $bind
        );
        return true;
    }

    // add a new transaction to the blockchain
    public function add($block, $height, $x)
    {
        global $db;
        $acc = new Account();
        $acc->add($x['public_key'], $block);
        if ($x['version']==1) {
            $acc->add_id($x['dst'], $block);
        }
        $x['id'] = san($x['id']);
        $bind = [
            ":id"         => $x['id'],
            ":public_key" => $x['public_key'],
            ":height"     => $height,
            ":block"      => $block,
            ":dst"        => $x['dst'],
            ":val"        => $x['val'],
            ":fee"        => $x['fee'],
            ":signature"  => $x['signature'],
            ":version"    => $x['version'],
            ":date"       => $x['date'],
            ":message"    => $x['message'],
        ];
        $res = $db->run(
            "INSERT into transactions SET id=:id, public_key=:public_key, block=:block,  height=:height, dst=:dst, val=:val, fee=:fee, signature=:signature, version=:version, message=:message, `date`=:date",
            $bind
        );
        if ($res != 1) {
            return false;
        }
        if ($x['version'] == 2&&$height>=80000) {
            $db->run("UPDATE accounts SET balance=balance+:val WHERE alias=:alias", [":alias" => $x['dst'], ":val" => $x['val']]);
        } elseif ($x['version']==100&&$height>=80000) {
            //master node deposit
        } elseif ($x['version']==103&&$height>=80000) {
            $blk=new Block();
            $blk->masternode_log($x['public_key'], $height, $block);

        //master node withdrawal
        } else {
            $db->run("UPDATE accounts SET balance=balance+:val WHERE id=:id", [":id" => $x['dst'], ":val" => $x['val']]);
        }



        // no debit when the transaction is reward
        if ($x['version'] > 0) {
            $db->run(
                "UPDATE accounts SET balance=(balance-:val)-:fee WHERE id=:id",
                [":id" => $x['src'], ":val" => $x['val'], ":fee" => $x['fee']]
            );
        }


        // set the alias
        if ($x['version']==3&&$height>=80000) {
            $db->run(
                    "UPDATE accounts SET alias=:alias WHERE id=:id",
                    [":id" => $x['src'], ":alias"=>$x['message']]
                );
        }


        if ($x['version']>=100&&$x['version']<110&&$height>=80000) {
            $message=$x['message'];
            $message=preg_replace("/[^0-9\.]/", "", $message);
            if ($x['version']==100) {
                $db->run("INSERT into masternode SET `public_key`=:public_key, `height`=:height, `ip`=:ip, `status`=1", [":public_key"=>$x['public_key'], ":height"=>$height, ":ip"=>$message]);
            } else {
                if ($x['version']==101) {
                    $db->run("UPDATE masternode SET status=0 WHERE public_key=:public_key", [':public_key'=>$x['public_key']]);
                } elseif ($x['version']==102) {
                    $db->run("UPDATE masternode SET status=1 WHERE public_key=:public_key", [':public_key'=>$x['public_key']]);
                } elseif ($x['version']==103) {
                    $db->run("DELETE FROM masternode WHERE public_key=:public_key", [':public_key'=>$x['public_key']]);
                    $db->run("UPDATE accounts SET balance=balance+100000 WHERE public_key=:public_key", [':public_key'=>$x['public_key']]);
                }
            }
        }
        


        $db->run("DELETE FROM mempool WHERE id=:id", [":id" => $x['id']]);
        return true;
    }

    // hash the transaction's most important fields and create the transaction ID
    public function hash($x)
    {
        $info = $x['val']."-".$x['fee']."-".$x['dst']."-".$x['message']."-".$x['version']."-".$x['public_key']."-".$x['date']."-".$x['signature'];
        $hash = hash("sha512", $info);
        return hex2coin($hash);
    }

    // check the transaction for validity
    public function check($x, $height = 0)
    {
        // if no specific block, use current
        if ($height === 0) {
            $block = new Block();
            $current = $block->current();
            $height = $current['height'];
        }
        $acc = new Account();
        $info = $x['val']."-".$x['fee']."-".$x['dst']."-".$x['message']."-".$x['version']."-".$x['public_key']."-".$x['date'];

        // hard fork at 80000 to implement alias, new mining system, assets
        // if($x['version']>1 && $height<80000){
        //     return false;
        // }
            
        // internal transactions
        if ($x['version']>110) {
            return false;
        }

        // the value must be >=0
        if ($x['val'] < 0) {
            _log("$x[id] - Value below 0", 3);
            return false;
        }

        // the fee must be >=0
        if ($x['fee'] < 0) {
            _log("$x[id] - Fee below 0", 3);
            return false;
        }

        // the fee is 0.25%, hardcoded
        $fee = $x['val'] * 0.0025;
        $fee = number_format($fee, 8, ".", "");
        if ($fee < 0.00000001) {
            $fee = 0.00000001;
        }
        //alias fee
        if ($x['version']==3&&$height>=80000) {
            $fee=10;
            if (!$acc->free_alias($x['message'])) {
                _log("Alias not free", 3);
                return false;
            }
            // alias can only be set once per account
            if ($acc->has_alias($x['public_key'])) {
                _log("The account already has an alias", 3);
                return false;
            }
        }

        //masternode transactions
        
        if ($x['version']>=100&&$x['version']<110&&$height>=80000) {
            if ($x['version']==100) {
                $message=$x['message'];
                $message=preg_replace("/[^0-9\.]/", "", $message);
                if (!filter_var($message, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    _log("The Masternode IP is invalid", 3);
                    return false;
                }
                global $db;
                $existing=$db->single("SELECT COUNT(1) FROM masternode WHERE public_key=:id or ip=:ip", ["id"=>$x['public_key'], ":ip"=>$message]);
                if ($existing!=0) {
                    return false;
                }
            }
           
        
            if ($x['version']==100&&$x['val']!=100000) {
                _log("The masternode transaction is not 100k", 3);
                return false;
            } elseif ($x['version']!=100) {
                $mn=$acc->get_masternode($x['public_key']);
                
                if (!$mn) {
                    _log("The masternode does not exist", 3);
                    return false;
                }
                if ($x['version']==101&&$mn['status']!=1) {
                    _log("The masternode does is not running", 3);
                    return false;
                } elseif ($x['version']==102 && $mn['status']!=0) {
                    _log("The masternode is not paused", 3);
                    return false;
                } elseif ($x['version']==103) {
                    if ($mn['status']!=0) {
                        _log("The masternode is not paused", 3);
                        return false;
                    } elseif ($height-$mn['last_won']<10800) { //10800
                        _log("The masternode last won block is less than 10800 blocks", 3);
                        return false;
                    } elseif ($height-$mn['height']<32400) { //32400
                        _log("The masternode start height is less than 32400 blocks! $height - $mn[height]", 3);
                        return false;
                    }
                }
            }
        }
        

        // max fee after block 10800 is 10
        if ($height > 10800 && $fee > 10) {
            $fee = 10; //10800
        }
        // added fee does not match
        if ($fee != $x['fee']) {
            _log("$x[id] - Fee not 0.25%", 3);
            _log(json_encode($x), 3);
            return false;
        }

        if ($x['version']==1) {
            // invalid destination address
            if (!$acc->valid($x['dst'])) {
                _log("$x[id] - Invalid destination address", 3);
                return false;
            }
        } elseif ($x['version']==2&&$height>=80000) {
            if (!$acc->valid_alias($x['dst'])) {
                _log("$x[id] - Invalid destination alias", 3);
                return false;
            }
        }


        // reward transactions are not added via this function
        if ($x['version'] < 1) {
            _log("$x[id] - Invalid version <1", 3);
            return false;
        }
        //if($x['version']>1) { _log("$x[id] - Invalid version >1"); return false; }

        // public key must be at least 15 chars / probably should be replaced with the validator function
        if (strlen($x['public_key']) < 15) {
            _log("$x[id] - Invalid public key size", 3);
            return false;
        }
        // no transactions before the genesis
        if ($x['date'] < 1511725068) {
            _log("$x[id] - Date before genesis", 3);
            return false;
        }
        // no future transactions
        if ($x['date'] > time() + 86400) {
            _log("$x[id] - Date in the future", 3);
            return false;
        }
        // prevent the resending of broken base58 transactions
        if ($height > 16900 && $x['date'] < 1519327780) {
            _log("$x[id] - Broken base58 transaction", 3);
            return false;
        }
        $id = $this->hash($x);
        // the hash does not match our regenerated hash
        if ($x['id'] != $id) {
            // fix for broken base58 library which was used until block 16900, accepts hashes without the first 1 or 2 bytes
            $xs = base58_decode($x['id']);
            if (((strlen($xs) != 63 || substr($id, 1) != $x['id']) && (strlen($xs) != 62 || substr(
                $id,
                2
            ) != $x['id'])) || $height > 16900) {
                _log("$x[id] - $id - Invalid hash");
                return false;
            }
        }

        //verify the ecdsa signature
        if (!$acc->check_signature($info, $x['signature'], $x['public_key'])) {
            _log("$x[id] - Invalid signature - $info");
            return false;
        }

        return true;
    }

    // sign a transaction
    public function sign($x, $private_key)
    {
        $info = $x['val']."-".$x['fee']."-".$x['dst']."-".$x['message']."-".$x['version']."-".$x['public_key']."-".$x['date'];
        
        $signature = ec_sign($info, $private_key);

        return $signature;
    }

    //export a mempool transaction
    public function export($id)
    {
        global $db;
        $r = $db->row("SELECT * FROM mempool WHERE id=:id", [":id" => $id]);
        return $r;
    }

    // get the transaction data as array
    public function get_transaction($id)
    {
        global $db;
        $acc = new Account();
        $block = new Block();
        $current = $block->current();

        $x = $db->row("SELECT * FROM transactions WHERE id=:id", [":id" => $id]);

        if (!$x) {
            return false;
        }
        $trans = [
            "block"      => $x['block'],
            "height"     => $x['height'],
            "id"         => $x['id'],
            "dst"        => $x['dst'],
            "val"        => $x['val'],
            "fee"        => $x['fee'],
            "signature"  => $x['signature'],
            "message"    => $x['message'],
            "version"    => $x['version'],
            "date"       => $x['date'],
            "public_key" => $x['public_key'],
        ];
        $trans['src'] = $acc->get_address($x['public_key']);
        $trans['confirmations'] = $current['height'] - $x['height'];

        if ($x['version'] == 0) {
            $trans['type'] = "mining";
        } elseif ($x['version'] == 1 || $x['version'] == 2) {
            if ($x['dst'] == $id) {
                $trans['type'] = "credit";
            } else {
                $trans['type'] = "debit";
            }
        } else {
            $trans['type'] = "other";
        }
        ksort($trans);
        return $trans;
    }

    // return the transactions for a specific block id or height
    public function get_transactions($height = "", $id = "", $includeMiningRewards = false)
    {
        global $db;
        $block = new Block();
        $current = $block->current();
        $acc = new Account();
        $height = san($height);
        $id = san($id);
        if (empty($id) && empty($height)) {
            return false;
        }
        $versionLimit = $includeMiningRewards ? 0 : 1;
        if (!empty($id)) {
            $r = $db->run("SELECT * FROM transactions WHERE block=:id AND version >= :version", [":id" => $id, ":version" => $versionLimit]);
        } else {
            $r = $db->run("SELECT * FROM transactions WHERE height=:height AND version >= :version", [":height" => $height, ":version" => $versionLimit]);
        }
        $res = [];
        foreach ($r as $x) {
            if ($x['version']>110) {
                continue; //internal transactions
            }
            $trans = [
                "block"      => $x['block'],
                "height"     => $x['height'],
                "id"         => $x['id'],
                "dst"        => $x['dst'],
                "val"        => $x['val'],
                "fee"        => $x['fee'],
                "signature"  => $x['signature'],
                "message"    => $x['message'],
                "version"    => $x['version'],
                "date"       => $x['date'],
                "public_key" => $x['public_key'],
            ];
            $trans['src'] = $acc->get_address($x['public_key']);
            $trans['confirmations'] = $current['height'] - $x['height'];

            if ($x['version'] == 0) {
                $trans['type'] = "mining";
            } elseif ($x['version'] == 1||$x['version'] == 2) {
                if ($x['dst'] == $id) {
                    $trans['type'] = "credit";
                } else {
                    $trans['type'] = "debit";
                }
            } else {
                $trans['type'] = "other";
            }
            ksort($trans);
            $res[] = $trans;
        }
        return $res;
    }

    // get a specific mempool transaction as array
    public function get_mempool_transaction($id)
    {
        global $db;
        $x = $db->row("SELECT * FROM mempool WHERE id=:id", [":id" => $id]);
        if (!$x) {
            return false;
        }
        $trans = [
            "block"      => $x['block'],
            "height"     => $x['height'],
            "id"         => $x['id'],
            "dst"        => $x['dst'],
            "val"        => $x['val'],
            "fee"        => $x['fee'],
            "signature"  => $x['signature'],
            "message"    => $x['message'],
            "version"    => $x['version'],
            "date"       => $x['date'],
            "public_key" => $x['public_key'],
        ];
        $trans['src'] = $x['src'];

        $trans['type'] = "mempool";
        $trans['confirmations'] = -1;
        ksort($trans);
        return $trans;
    }
}
