<?php

use Arionum\Blacklist;

class Transaction
{
    public function add_log($x, $log)
    {
        global $db;
        //$json=["table"=>"masternode", "key"=>"public_key","id"=>$x['public_key'], "vals"=>['ip'=>$current_ip] ];
        $db->run("INSERT into logs SET transaction=:id, json=:json", [':id'=>$x['id'], ":json"=>json_encode($log)]);
    }
    public function reverse_log($x)
    {
        global $db;
        $r=$db->run("SELECT json, id FROM logs WHERE transaction=:id ORDER by id DESC", [":id"=>$x['id']]);
        foreach ($r as $json) {
            $old=json_decode($json['json'], true);
            if ($old!==false&&is_array($old)) {
                //making sure there's no sql injection here, as the table name and keys are sanitized to A-Za-z0-9_
                $table=san($old['table']);
                $key=san($old['key'], '_');
                $id=san($old['id'], '_');
                foreach ($old['vals'] as $v=>$l) {
                    $v=san($v, '_');
                    $db->run("UPDATE `$table` SET `$v`=:val WHERE `$key`=:keyid", [":keyid"=>$id, ":val"=>$l]);
                }
            }
            $db->run("DELETE FROM logs WHERE id=:id",[":id"=>$json['id']]);
        }
    }
    // reverse and remove all transactions from a block
    public function reverse($block)
    {
        global $db;
        
        $acc = new Account();
        $r = $db->run("SELECT * FROM transactions WHERE block=:block ORDER by `version` DESC", [":block" => $block]);
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
       
                if ($x['version']!=100 && $x['version']<111 && $x['version'] != 54 && $x['version'] != 57 && $x['version'] != 58 && $x['val']>0) {
                    $rez=$db->run(
                        "UPDATE accounts SET balance=balance-:val WHERE id=:id",
                        [":id" => $x['dst'], ":val" => $x['val']]
                    );
                    if ($rez!=1) {
                        _log("Update accounts balance minus failed - $x[id]", 3);
                        return false;
                    }
                }
            }
            // on version 0 / reward transaction, don't credit anyone
            if ($x['version'] > 0 && $x['version']<111 && $x['version'] != 54 && $x['version'] != 57 && $x['version'] != 58 && ($x['val']+$x['fee'])>0) {
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
                } elseif ($x['version']==104) {
                    $this->reverse_log($x);
                } elseif ($x['version']==105) {
                    $db->run("UPDATE masternode SET vote_key=NULL WHERE public_key=:public_key", [":public_key"=>$x['public_key']]);
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
    

            // asset transactions
            if ($x['version']==50) {
                $db->run("DELETE FROM assets WHERE id=:id", [":id"=>$x['src']]);
                $db->run("DELETE FROM assets_balance WHERE asset=:id", [":id"=>$x['src']]);
            } elseif ($x['version']==51) {
                $t=json_decode($x['message'], true);
                $db->run(
                    "UPDATE assets_balance SET balance=balance-:balance WHERE account=:account and asset=:asset",
                    [":account"=>$x['dst'], ":asset"=>san($t[0]), ":balance"=>intval($t[1])]
                );
                $db->run("UPDATE assets_balance SET balance=balance+:balance WHERE account=:account and asset=:asset", [":account"=>$x['src'], ":asset"=>san($t[0]), ":balance"=>intval($t[1])]);
            } elseif ($x['version']==52) {
                $t=json_decode($x['message'], true);
                if ($t[4]=="ask") {
                    $type="ask";
                } else {
                    $type="bid";
                }
                if ($type=="ask") {
                    $db->run("UPDATE assets_balance SET balance=balance+:val WHERE account=:account AND asset=:asset", [
                        ":account"=>$x['src'],
                        ":asset"=>san($t[0]),
                        ":val"=>intval($t[2])
                        ]);
                } else {
                    $val=number_format($t[2]*$t[1], 8, '.', '');
                    $db->run("UPDATE accounts SET balance=balance+:val WHERE id=:id", [":id"=>$x['src'], ":val"=>$val]);
                }
                $db->run("DELETE FROM assets_market WHERE id=:id", [':id'=>$x['id']]);
            } elseif ($x['version']==53) {
                $order=$db->row("SELECT * FROM assets_market WHERE id=:id AND account=:account AND status=2", [":id"=>san($x['message']), ":account"=>$x['src']]);
                if ($order) {
                    $remaining=$order['val']-$order['val_done'];
                    if ($remaining>0) {
                        if ($order['type']=="ask") {
                            $db->run("UPDATE assets_balance SET balance=balance-:val WHERE account=:account AND asset=:asset", [
                                ":account"=>$x['src'],
                                ":asset"=>san($order['asset']),
                                ":val"=>intval($remaining)
                                ]);
                        } else {
                            $val=number_format($order['price']*$remaining, 8, '.', '');
                            $db->run("UPDATE accounts SET balance=balance-:val WHERE id=:id", [":id"=>$x['src'], ":val"=>$val]);
                        }
                        $db->run("UPDATE assets_market SET status=0 WHERE id=:id", [":id"=>san($x['message'])]);
                    }
                }
            } elseif ($x['version']==54||$x['version']==57) {
                //nothing to be done
            } elseif ($x['version']==55) {
                // the message stores the increment value
                $plus=intval($x['message']);
                $db->run("UPDATE assets_balance SET balance=balance-:plus WHERE account=:account AND asset=:asset", [":account"=>$x['src'], ":asset"=>$x['src'], ":plus"=>$plus]);
            } elseif ($x['version']==58) {
                // the message stores the number of units
                $use=intval($x['message']);
                // we stored the bid order id in the public key field and the ask in the dst field
                $db->run("UPDATE assets_market SET val_done=val_done-:done WHERE id=:id", [":id"=>$x['public_key'], ":done"=>$use]);
                $db->run("UPDATE assets_market SET val_done=val_done-:done WHERE id=:id", [":id"=>$x['dst'], ":done"=>$use]);
               
                $bid=$db->row("SELECT * FROM assets_market WHERE id=:id", [':id'=>$x['public_key']]);
                $ask=$db->row("SELECT * FROM assets_market WHERE id=:id", [':id'=>$x['dst']]);

                $db->run("UPDATE assets_balance SET balance=balance-:balance WHERE account=:account AND asset=:asset", [":account"=>$bid['account'], ":asset"=>$bid['asset'], ":balance"=>$use]);
                $aro=$x['val'];
                $db->run("UPDATE accounts SET balance=balance-:balance WHERE id=:id", [":balance"=>$aro, ":id"=>$ask['account']]);
            }


            // add the transactions to mempool
            if ($x['version'] > 0 && $x['version']<=110 && $x['version'] != 59 && $x['version'] != 58 && $x['version'] != 57) {
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
            $assets=0;
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

                //only a single asset creation per block
                if ($x['version']==50) {
                    $assets++;
                    if ($assets>1) {
                        continue;
                    }
                }
                // single blockchain vote per block
               
                if ($x['version']==106||$x['version']==107) {
                    $tid=$x['public_key'].$x['message'];
                    if ($exists[$tid]==1) {
                        continue;
                    }
                    $exists[$tid]=1;
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
        if ($x['version']>=100&&$x['version']<110&&$x['version']!=106&&$x['version']!=107) {
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
        // not a valid or useful public key for internal transactions
        if ($x['version']!=58 && $x['version']!=59) {
            // add the public key to the accounts table
            $acc->add($x['public_key'], $block);
            if ($x['version']==1 || $x['version'] == 51) {
                // make sure the destination address in on the accounts table as well
                $acc->add_id($x['dst'], $block);
            }
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

        // market order side chain
        if ($x['version']==58) {
            return true;
        }

        if ($x['version'] == 2&&$height>=80000) {
            $db->run("UPDATE accounts SET balance=balance+:val WHERE alias=:alias", [":alias" => $x['dst'], ":val" => $x['val']]);
        } elseif ($x['version']==50||$x['version']==54||$x['version']==57) {
            // asset creation and dividend distribution
        } elseif ($x['version']==100&&$height>=80000) {
            //master node deposit
        } elseif ($x['version']==103&&$height>=80000) {
            $blk=new Block();
            $blk->masternode_log($x['public_key'], $height, $block);

        //master node withdrawal
        } else {
            $db->run("UPDATE accounts SET balance=balance+:val WHERE id=:id", [":id" => $x['dst'], ":val" => $x['val']]);
        }



        // no debit when the transaction is reward or dividend distribution
        if ($x['version'] > 0 && $x['version'] != 54 && $x['version'] != 57) {
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
                    // pause masternode
                    $db->run("UPDATE masternode SET status=0 WHERE public_key=:public_key", [':public_key'=>$x['public_key']]);
                } elseif ($x['version']==102) {
                    // reactivate pasternode
                    $db->run("UPDATE masternode SET status=1 WHERE public_key=:public_key", [':public_key'=>$x['public_key']]);
                } elseif ($x['version']==103) {
                    // release and cancel the masternode
                    $db->run("DELETE FROM masternode WHERE public_key=:public_key", [':public_key'=>$x['public_key']]);
                    $db->run("UPDATE accounts SET balance=balance+100000 WHERE public_key=:public_key", [':public_key'=>$x['public_key']]);
                } elseif ($x['version']==104) {
                    // update ip
                    $current_ip=$db->single("SELECT ip FROM masternode WHERE public_key=:public_key", [":public_key"=>$x['public_key']]);
                    $json=["table"=>"masternode", "key"=>"public_key","id"=>$x['public_key'], "vals"=>['ip'=>$current_ip] ];
                    $this->add_log($x, $json);
                    $db->run("UPDATE masternode SET ip=:ip WHERE public_key=:public_key", [':ip'=>$message, ":public_key"=>$x['public_key']]);
                } elseif ($x['version']==105) {
                    // add vote key
                    $db->run("UPDATE masternode SET vote_key=:vote_key WHERE public_key=:public_key AND vote_key is NULL", [':vote_key'=>san($x['message']), ":public_key"=>$x['public_key']]);
                }
            }
        }
        // asset system
        if ($x['version']==50) {
            // asset creation
            $bind=[];
            $asset=json_decode($x['message'], true);
            $bind[':max_supply']=intval($asset[0]);
            $bind[':tradable']=intval($asset[1]);
            $bind[':price']=number_format($asset[2], 8, '.', '');
            $bind[':dividend_only']=intval($asset[3]);
            $bind[':auto_divident']=intval($asset[4]);
            $bind[':allow_bid']=intval($asset[5]);
            $bind[':height']=$height;
            $bind[':id']=$x['src'];
            $db->run("INSERT into assets SET id=:id, max_supply=:max_supply, tradable=:tradable, price=:price, dividend_only=:dividend_only, auto_dividend=:auto_divident, height=:height, allow_bid=:allow_bid", $bind);
            if ($bind[':max_supply']>0) {
                $db->run("INSERT into assets_balance SET account=:account, asset=:asset, balance=:balance", [":account"=>$x['src'], ":asset"=>$x['src'], ":balance"=>$bind[':max_supply']]);
            }
        } elseif ($x['version']==51) {
            // send asset
            $t=json_decode($x['message'], true);
            $db->run(
                "INSERT into assets_balance SET account=:account, asset=:asset, balance=:balance ON DUPLICATE KEY UPDATE balance=balance+:balance2",
                [":account"=>$x['dst'], ":asset"=>san($t[0]), ":balance"=>intval($t[1]), ":balance2"=>intval($t[1])]
            );
            $db->run("UPDATE assets_balance SET balance=balance-:balance WHERE account=:account and asset=:asset", [":account"=>$x['src'], ":asset"=>san($t[0]), ":balance"=>intval($t[1])]);
        } elseif ($x['version']==52) {
            // market order
            $t=json_decode($x['message'], true);

            if ($t[4]=="ask") {
                $type="ask";
            } else {
                $type="bid";
            }


            

            $bind=[":id" => san($x['id']),
                ":account" => $x['src'],
                ":asset" => san($t[0]),
                ":price" => number_format($t[1], 8, '.', ''),
                ":date" => $x['date'],
                ":val"=>intval($t[2]),
                ":type" => $type,
                ":cancel" => intval($t[3])
        ];
            $db->run("INSERT into assets_market SET id=:id, account=:account, asset=:asset, price=:price, `date`=:date, status=0, `type`=:type, val=:val, val_done=0, cancelable=:cancel", $bind);

            if ($type=="ask") {
                $db->run("UPDATE assets_balance SET balance=balance-:val WHERE account=:account AND asset=:asset", [
                    ":account"=>$x['src'],
                    ":asset"=>san($t[0]),
                    ":val"=>intval($t[2])
                    ]);
            } else {
                $val=number_format($t[2]*$t[1], 8, '.', '');
                $db->run("UPDATE accounts SET balance=balance-:val WHERE id=:id", [":id"=>$x['src'], ":val"=>$val]);
            }
        } elseif ($x['version']==53) {
            // cancel order
            $order=$db->row("SELECT * FROM assets_market WHERE id=:id AND account=:account AND status=0", [":id"=>san($x['message']), ":account"=>$x['src']]);
            if ($order) {
                $remaining=$order['val']-$order['val_done'];
                if ($remaining>0) {
                    if ($order['type']=="ask") {
                        $db->run("UPDATE assets_balance SET balance=balance+:val WHERE account=:account AND asset=:asset", [
                            ":account"=>$x['src'],
                            ":asset"=>san($order['asset']),
                            ":val"=>intval($remaining)
                            ]);
                    } else {
                        $val=number_format($order['price']*$remaining, 8, '.', '');
                        $db->run("UPDATE accounts SET balance=balance+:val WHERE id=:id", [":id"=>$x['src'], ":val"=>$val]);
                    }
                    $db->run("UPDATE assets_market SET status=2 WHERE id=:id", [":id"=>san($x['message'])]);
                }
            }
        } elseif ($x['version']==54||$x['version']==57) {
            //distribute dividends - only from asset wallet and only to other holders
            
            $r=$db->run("SELECT * FROM assets_balance WHERE asset=:asset AND balance>0 AND account!=:acc", [":asset"=>$x['src'], ":acc"=>$x['src']]);
            $total=0;
            foreach ($r as $g) {
                $total+=$g['balance'];
            }
            _log("Asset dividend distribution: $total units", 3);
            foreach ($r as $g) {
                $coins=number_format(($g['balance']/$total)*$x['val'], 8, '.', '');
                $fee=number_format(($g['balance']/$total)*$x['fee'], 8, '.', '');
                $hash = hex2coin(hash("sha512", $x['id'].$g['account']));
                _log("Distributing to $g[account] for $g[balance] units - $coins ARO", 3);
                
                $new = [
                    "id"         => $hash,
                    "public_key" => $x['public_key'],
                    "dst"        => $g['account'],
                    "val"        => $coins,
                    "fee"        => $fee,
                    "signature"  => $x['signature'],
                    "version"    => 59,
                    "date"       => $x['date'],
                    "src"        => $x['src'],
                    "message"    => '',
                ];
                $res=$this->add($block, $height, $new);
                if (!$res) {
                    return false;
                }
            }
        } elseif ($x['version']==55) {
            // increase max supply
            $plus=intval($x['message']);
            $db->run("INSERT into assets_balance SET balance=:plus, account=:account, asset=:asset ON DUPLICATE KEY UPDATE balance=balance+:plus2", [":account"=>$x['src'], ":asset"=>$x['src'], ":plus"=>$plus, ":plus2"=>$plus]);
        }


        $db->run("DELETE FROM mempool WHERE id=:id", [":id" => $x['id']]);
        return true;
    }

    // hash the transaction's most important fields and create the transaction ID
    public function hash($x)
    {
        $info = $x['val']."-".$x['fee']."-".$x['dst']."-".$x['message']."-".$x['version']."-".$x['public_key']."-".$x['date']."-".$x['signature'];
        $hash = hash("sha512", $info);
        //_log("Hashing: ".$info,3);
        //_log("Hash: $hash",3);
        return hex2coin($hash);
    }

    // check the transaction for validity
    public function check($x, $height = 0)
    {
        global $db;
        // blocktime lowered by 1 minute after 216000
        $blocktime_factor=1;
        if($height>216000){
            $blocktime_factor=4;
        }
        // if no specific block, use current
        if ($height === 0) {
            $block = new Block();
            $current = $block->current();
            $height = $current['height'];
        }
        $acc = new Account();
        $info = $x['val']."-".$x['fee']."-".$x['dst']."-".$x['message']."-".$x['version']."-".$x['public_key']."-".$x['date'];

        $src = $acc->get_address($x['public_key']);

        // hard fork at 80000 to implement alias, new mining system, assets
        // if($x['version']>1 && $height<80000){
        //     return false;
        // }
            
        // internal transactions
        if ($x['version']>110 || $x['version'] == 57 || $x['version'] == 58 || $x['version'] == 59) {
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
            if ($x['dst']!=$src) {
                // just to prevent some bypasses in the future
                _log("DST must be SRC for this transaction", 3);
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

                if ($x['dst']!=$src&&$x['version']!=106) {
                    // just to prevent some bypasses in the future
                    _log("DST must be SRC for this transaction", 3);
                    return false;
                }
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
                    } elseif ($height-$mn['last_won']<10800*$blocktime_factor) { //10800
                        _log("The masternode last won block is less than 10800 blocks", 3);
                        return false;
                    } elseif ($height-$mn['height']<32400*$blocktime_factor) { //32400
                        _log("The masternode start height is less than 32400 blocks! $height - $mn[height]", 3);
                        return false;
                    }
                } elseif ($x['version']==104) {
                    //only once per month (every 43200 blocks)
                    $res=$db->single("SELECT COUNT(1) FROM transactions WHERE public_key=:public_key AND version=104 AND height>:height", [':public_key'=>$x['public_key'], ":height"=>$height-43200]);
                    if ($res!=0) {
                        return false;
                    }
                
                    // already using this ip
                    if ($message==$mn['ip']) {
                        return false;
                    }
                    // valid ips
                    $message=$x['message'];
                    $message=preg_replace("/[^0-9\.]/", "", $message);
                    if (!filter_var($message, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                        _log("The Masternode IP is invalid", 3);
                        return false;
                    }
                    // making sure the ip is not already in use
                    global $db;
                    $existing=$db->single("SELECT COUNT(1) FROM masternode WHERE ip=:ip", [":ip"=>$message]);
                    if ($existing!=0) {
                        return false;
                    }
                
                } elseif ($x['version']==105) {
                    // masternode voting key can only be set once
                    if(!empty($mn['vote_key'])){
                        return false;
                    }
                }        
                
                // masternode votes
                elseif ($x['version']==106) {
                    // value always 0
                    if ($x['val']!=0) {
                        return false;
                    }
                    // one vote to each mn per 43200 blocks
                    $res=$db->single("SELECT COUNT(1) FROM transactions WHERE dst=:dst AND version=106 AND public_key=:id AND height>:height", [':dst'=>$x['dst'], ":id"=>$x['public_key'], ":height"=>$height-43200]);
                    if ($res>0) {
                        return false;
                    }
                }        
                // masternode blockchain votes
                elseif ($x['version']==107) {
                    // value always 0
                    if ($x['val']!=0) {
                        _log("The value should be 0 for this transaction type - $x[val]",3);
                        return false;
                    }
        
                    // one vote to each mn per 129600 blocks
                    $res=$db->single("SELECT COUNT(1) FROM transactions WHERE message=:message AND version=107 AND public_key=:id AND height>:height", [':message'=>$x['message'], ":id"=>$x['public_key'], ":height"=>$height-129600]);
                    if ($res>0) {
                        _log("There is already a vote in the last 129600 blocks",3);
                        return false;
                    }
                }
            }
        }

        // no asset transactions prior to 216000
        if($x['version']>=50&&$x['version']<=55&&$height<=216000){
            return false;
        }
        // no masternode voting prior to 216000
        if(($x['version']==106||$x['version']==107)&&$height<=216000){
            return false;
        }
        // assets
        if ($x['version']==50) {
            // asset creation
            // fixed asset price 100 +. The 100 are burned and not distributed to miners.
            if ($x['val']!=100) {
                _log("The asset creation transaction is not 100", 3);
                return false;
            }
            // stored in message in json format - [max supply, tradable, fixed price, dividend only, autodividend]
            $asset=json_decode($x['message'], true);
            if ($asset==false) {
                _log("Invalid asset creation json", 3);
                return false;
            }

            // minimum 0 (for inflatable assets) and  maximum 1.000.000.000
            if ($asset[0]>1000000000||$asset[0]<0||intval($asset[0])!=$asset[0]) {
                _log("Invalid asset max supply", 3);
                return false;
            }
            // 0 for non-tradable, 1 for tradable on the blockchain market
            if ($asset[1]!==1&&$asset[1]!==0) {
                _log("Invalid asset tradable", 3);
                return false;
            }
            // If the price is set, it cannot be sold by the asset wallet at a dfferent price. Max price 1.000.000 aro
            if (number_format($asset[2], 8, '.', '')!=$asset[2]||$asset[2]<0||$asset[2]>1000000) {
                _log("Invalid asset price", 3);
                return false;
            }
            // 1 to allow only dividend distribution, 0 to allow all transfers
            if ($asset[3]!==1&&$asset[3]!==0) {
                _log("Invalid asset dividend setting", 3);
                return false;
            }
            // automatic dividend distribution every 10000 blocks
            if ($asset[4]!==1&&$asset[4]!==0) {
                _log("Invalid asset autodividend setting", 3);
                return false;
            }
            // do not allow this asset to buy other assets via the market
            if ($asset[5]!==1&&$asset[5]!==0) {
                _log("Invalid asset bid_only setting", 3);
                return false;
            }
            // make sure there is no similar asset with the same alias
            $chk=$db->single("SELECT COUNT(1) FROM assets WHERE id=:id", [":id"=>$src]);
            if ($chk!==0) {
                _log("The asset already exists", 3);
                return false;
            }
        }

        // asset transfer
        if ($x['version']==51) {
            // Transfer details in json format, stored in the message. format: [asset id, units]
            // The transfer is done to the dst address of the transactions
            $asset=json_decode($x['message'], true);
            if ($asset==false) {
                _log("Invalid asset creation json", 3);
                return false;
            }
            // check if the asset exists
            $blockasset=$db->row("SELECT id, price FROM assets WHERE id=:id", [":id"=>san($asset[0])]);
            if (!$blockasset) {
                _log("Invalid asset", 3);
                return false;
            }
            // minimum 1 unit is transfered
            if (intval($asset[1])!=$asset[1]||$asset[1]<1) {
                _log("Invalid amount", 3);
                return false;
            }
            //make sure the wallet has enough asset units
            $balance=$db->single("SELECT balance FROM assets_balance WHERE account=:account AND asset=:asset", [":account"=>$src, ":asset"=>san($asset[0])]);
            if ($balance<=$asset[1]) {
                _log("Not enough balance", 3);
                return false;
            }
            if ($blockasset['price']>0 && $src == $blockasset['id'] && $blockasset['price']!=$asset[1] && $blockasset['tradable'] ==1) {
                // if the asset has a price defined, check if the asset wallet owns all the asset units and only in this case allow transfers. In such cases, the asset should be sold on market
                // on a fixed price always
                $chk=$db->single("SELECT COUNT(1) FROM assets_balance WHERE asset=:asset AND account!=:account", [":account"=>$src, ":asset"=>$src]);
                if ($chk!=0) {
                    _log("Initial asset distribution already done. Market orders only on fixed price.", 3);
                    return false;
                }
            }
        }
        // make sure the dividend only function is not bypassed after height X
        if (($x['version']==1||$x['version']==2)&&$height>216000) {
            $check=$db->single("SELECT COUNT(1) FROM assets WHERE id=:id AND dividend_only=1", [":id"=>$src]);
            if ($check==1) {
                _log("This asset wallet cannot send funds directly", 3);
                return false;
            }
        }


        // asset market orders

        if ($x['version']==52) {
            
            // we store the order details in a json array on the format [asset_id, price, amount of asset units, cancelable, order type ]
            $asset=json_decode($x['message'], true);
            if ($asset==false) {
                _log("Invalid asset creation json", 3);
                return false;
            }
            // only ask and bid allowed
            if ($asset[4]!="ask"&&$asset[4]!="bid") {
                _log("Invalid asset order type", 3);
                return false;
            }
            $type=san($asset[4]);

            $blockasset=$db->row("SELECT * FROM assets WHERE id=:id", [":id"=>san($asset[0])]);
            if (!$blockasset||$blockasset['tradable']!=1) {
                _log("Invalid asset", 3);
                return false;
            }
            // the sale price per unit has to be at least 0.00000001 or max 1000000 aro
            if (number_format($asset[1], 8, '.', '')!=$asset[1]||$asset[1]<=0||$asset[1]>1000000) {
                _log("Invalid asset price", 3);
                return false;
            }
            // integer min 1 and max 1000000
            if (intval($asset[2])!=$asset[2]||$asset[2]<1||$asset[2]>1000000) {
                _log("Invalid asset value", 3);
                return false;
            }
            // if the order should be cancelable or not
            if ($asset[3]!=1&&$asset[3]!=0) {
                _log("Invalid asset cancel setting", 3);
                return false;
            }
            // the type of order, ask or bid
            if ($type=="ask") {
                $balance=$db->single("SELECT balance FROM assets_balance WHERE asset=:asset AND account=:account", [":account"=>$src, ":asset"=>$asset[0]]);
                if ($balance<$asset[2]) {
                    _log("Not enough asset balance", 3);
                    return false;
                }
            } else {
                $balance=$acc->balance($src);
                if ($balance<$asset[2]*$asset[1]) {
                    _log("Not enough aro balance", 3);
                    return false;
                }
                if ($blockasset['id']!=$src) {
                    $asset_bids_allowed=$db->single("SELECT COUNT(1) FROM assets WHERE id=:id AND allow_bid=0", [":id"=>$src]);
                    if ($asset_bids_allowed==1) {
                        _log("This wallet asset is not allowed to buy other assets", 3);
                        return false;
                    }
                }
            }
            if ($blockasset['id']==$src && $blockasset['price']>0 && $blockasset['price']!=$asset[1]) {
                // In case the asset has fixed price, the asset wallet cannot sell on a different price (to prevent abuse by the owner)
                _log("This asset has fixed market price when sold by it's wallet", 3);
                return false;
            }
        }
        if ($x['version']==53) {
            if (san($x['message'])!=$x['message']) {
                _log("Invalid order id - $x[message]", 3);
                return false;
            }
            $chk=$db->single("SELECT COUNT(1) FROM assets_market WHERE id=:id AND account=:src AND val_done<val AND status=0 AND cancelable=1", [":id"=>san($x['message']), ":src"=>$src]);
            if ($chk!=1) {
                _log("Invalid order - $x[message]", 3);
                return false;
            }
        }
        if ($x['version']==54) {
            $balance=$acc->balance($src);
            if ($balance<$x['val']||$x['val']<0.00000001) {
                _log("Not enough aro balance", 3);
                return false;
            }
        }

        if ($x['version']==55) {
            $plus=intval($x['message']);
            if ($x['message']!=$plus) {
                _log("Invalid asset value", 3);
                return false;
            }
            $test=$db->single("SELECT COUNT(1) FROM assets WHERE id=:id AND max_supply=0", [":id"=>$src]);
            if ($test!=1) {
                _log("Asset not inflatable", 3);
                return false;
            }
            $total=$db->single("SELECT SUM(balance) FROM assets_balance WHERE asset=:id", [":id"=>$src]);
            $total+=$db->single("SELECT SUM(val-val_done) FROM assets_market WHERE status=0 AND type='ask' AND asset=:id", [":id"=>$src]);
            if ($total+$plus>1000000000) {
                _log("Maximum asset unit limits reached", 3);
                return false;
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




        
        if ($x['version']==106) {
            // the masternode votes are using a different signature
            $vote_key=$db->single("SELECT vote_key FROM masternode WHERE public_key=:public_key", [':public_key'=>$x['public_key']]);
            if (empty($vote_key)) {
                return false;
            }
            if (!$acc->check_signature($info, $x['signature'], $vote_key)) {
                _log("$x[id] - Invalid vote key signature - $info");
                return false;
            }
        } else {
            //verify the ecdsa signature
            if (!$acc->check_signature($info, $x['signature'], $x['public_key'])) {
                _log("$x[id] - Invalid signature - $info");
                return false;
            }
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
