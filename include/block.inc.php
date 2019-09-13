<?php

class Block
{
    public function add_log($hash, $log)
    {
        global $db;
        $hash=san($hash);
        //$json=["table"=>"masternode", "key"=>"public_key","id"=>$x['public_key'], "vals"=>['ip'=>$current_ip] ];
        $db->run("INSERT into logs SET block=:id, json=:json", [':id'=>$hash, ":json"=>json_encode($log)]);
    }
    public function reverse_log($hash)
    {
        global $db;
        $r=$db->run("SELECT json, id FROM logs WHERE block=:id ORDER by id DESC", [":id"=>$hash]);
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
            $db->run("DELETE FROM logs WHERE id=:id", [":id"=>$json['id']]);
        }
    }

    public function add($height, $public_key, $nonce, $data, $date, $signature, $difficulty, $reward_signature, $argon, $bootstrapping=false)
    {
        global $db;
        $acc = new Account();
        $trx = new Transaction();

        $generator = $acc->get_address($public_key);

        // the transactions are always sorted in the same way, on all nodes, as they are hashed as json
        ksort($data);

        // create the hash / block id
        $hash = $this->hash($generator, $height, $date, $nonce, $data, $signature, $difficulty, $argon);
        //fix for the broken base58 library used until block 16900, trimming the first 0 bytes.
        if ($height < 16900) {
            $hash = ltrim($hash, '1');
        }

        $json = json_encode($data);

        // create the block data and check it against the signature
        $info = "{$generator}-{$height}-{$date}-{$nonce}-{$json}-{$difficulty}-{$argon}";
        // _log($info,3);
        if (!$bootstrapping) {
            if (!$acc->check_signature($info, $signature, $public_key)) {
                _log("Block signature check failed");
                return false;
            }

            if (!$this->parse_block($hash, $height, $data, true)) {
                _log("Parse block failed");
                return false;
            }
        }
        // lock table to avoid race conditions on blocks
        $db->exec("LOCK TABLES blocks WRITE, accounts WRITE, transactions WRITE, mempool WRITE, masternode WRITE, peers write, config WRITE, assets WRITE, assets_balance WRITE, assets_market WRITE, votes WRITE, logs WRITE");

        $reward = $this->reward($height, $data);

        $msg = '';

        $mn_reward_rate=0.33;
  
        // hf
        if ($height>216000) {
            $votes=[];
            $r=$db->run("SELECT id,val FROM votes");
            foreach ($r as $vote) {
                $votes[$vote['id']]=$vote['val'];
            }
            // emission cut by 30%
            if ($votes['emission30']==1) {
                $reward=round($reward*0.7);
            }
            // 50% to masternodes
            if ($votes['masternodereward50']==1) {
                $mn_reward_rate=0.5;
            }
            // minimum reward to always be 10 aro
            if ($votes['endless10reward']==1&&$reward<10) {
                $reward=10;
            }
        }


        if ($height>=80458) {
            //reward the masternode

            $mn_winner=$db->single(
                "SELECT public_key FROM masternode WHERE status=1 AND blacklist<:current AND height<:start ORDER by last_won ASC, public_key ASC LIMIT 1",
                [":current"=>$height, ":start"=>$height-360]
            );
            _log("MN Winner: $mn_winner", 2);
            if ($mn_winner!==false) {
                $mn_reward=round($mn_reward_rate*$reward, 8);
                $reward=round($reward-$mn_reward, 8);
                $reward=number_format($reward, 8, ".", "");
                $mn_reward=number_format($mn_reward, 8, ".", "");
                _log("MN Reward: $mn_reward", 2);
            }
        }
        $cold_winner=false;
        $cold_reward=0;
        if ($height>216000) {
            if ($votes['coldstacking']==1) {
                $cold_reward=round($mn_reward*0.2, 8);
                $mn_reward=$mn_reward-$cold_reward;
                $mn_reward=number_format($mn_reward, 8, ".", "");
                $cold_reward=number_format($cold_reward, 8, ".", "");
                $cold_winner=$db->single(
                        "SELECT public_key FROM masternode WHERE height<:start ORDER by cold_last_won ASC, public_key ASC LIMIT 1",
                        [":current"=>$height, ":start"=>$height-360]
                    );
                _log("Cold MN Winner: $mn_winner", 2);
            }
        }

        

        // the reward transaction
        $transaction = [
            "src"        => $generator,
            "dst"        => $generator,
            "val"        => $reward,
            "version"    => 0,
            "date"       => $date,
            "message"    => $msg,
            "fee"        => "0.00000000",
            "public_key" => $public_key,
        ];
        $transaction['signature'] = $reward_signature;
        // hash the transaction
        $transaction['id'] = $trx->hash($transaction);
        if (!$bootstrapping) {
            // check the signature
            $info = $transaction['val']."-".$transaction['fee']."-".$transaction['dst']."-".$transaction['message']."-".$transaction['version']."-".$transaction['public_key']."-".$transaction['date'];
            if (!$acc->check_signature($info, $reward_signature, $public_key)) {
                _log("Reward signature failed");
                return false;
            }
        }
        // insert the block into the db
        $db->beginTransaction();
        $total = count($data);


        $bind = [
            ":id"           => $hash,
            ":generator"    => $generator,
            ":signature"    => $signature,
            ":height"       => $height,
            ":date"         => $date,
            ":nonce"        => $nonce,
            ":difficulty"   => $difficulty,
            ":argon"        => $argon,
            ":transactions" => $total,
        ];
        $res = $db->run(
            "INSERT into blocks SET id=:id, generator=:generator, height=:height,`date`=:date,nonce=:nonce, signature=:signature, difficulty=:difficulty, argon=:argon, transactions=:transactions",
            $bind
        );
        if ($res != 1) {
            // rollback and exit if it fails
            _log("Block DB insert failed");
            $db->rollback();
            $db->exec("UNLOCK TABLES");
            return false;
        }

        // insert the reward transaction in the db
        $res=$trx->add($hash, $height, $transaction);
        if ($res == false) {
            // rollback and exit if it fails
            _log("Reward DB insert failed");
            $db->rollback();
            $db->exec("UNLOCK TABLES");
            return false;
        }
        //masternode rewards
        if ($mn_winner!==false&&$height>=80458&&$mn_reward>0) {
            //cold stacking rewards
            if ($cold_winner!==false&&$height>216000&&$cold_reward>0) {
                $db->run("UPDATE accounts SET balance=balance+:bal WHERE public_key=:pub", [":pub"=>$cold_winner, ":bal"=>$cold_reward]);
            
                $bind = [
            ":id"         => hex2coin(hash("sha512", "cold".$hash.$height.$cold_winner)),
            ":public_key" => $public_key,
            ":height"     => $height,
            ":block"      => $hash,
            ":dst"        => $acc->get_address($cold_winner),
            ":val"        => $cold_reward,
            ":fee"        => 0,
            ":signature"  => $reward_signature,
            ":version"    => 0,
            ":date"       => $date,
            ":message"    => 'masternode-cold',
        ];
                $res = $db->run(
                "INSERT into transactions SET id=:id, public_key=:public_key, block=:block,  height=:height, dst=:dst, val=:val, fee=:fee, signature=:signature, version=:version, message=:message, `date`=:date",
                $bind
        );
                if ($res != 1) {
                    // rollback and exit if it fails
                    _log("Masternode Cold reward DB insert failed");
                    $db->rollback();
                    $db->exec("UNLOCK TABLES");
                    return false;
                }
            }


            $db->run("UPDATE accounts SET balance=balance+:bal WHERE public_key=:pub", [":pub"=>$mn_winner, ":bal"=>$mn_reward]);
            $bind = [
            ":id"         => hex2coin(hash("sha512", "mn".$hash.$height.$mn_winner)),
            ":public_key" => $public_key,
            ":height"     => $height,
            ":block"      => $hash,
            ":dst"        => $acc->get_address($mn_winner),
            ":val"        => $mn_reward,
            ":fee"        => 0,
            ":signature"  => $reward_signature,
            ":version"    => 0,
            ":date"       => $date,
            ":message"    => 'masternode',
        ];
            $res = $db->run(
                "INSERT into transactions SET id=:id, public_key=:public_key, block=:block,  height=:height, dst=:dst, val=:val, fee=:fee, signature=:signature, version=:version, message=:message, `date`=:date",
                $bind
        );
            if ($res != 1) {
                // rollback and exit if it fails
                _log("Masternode reward DB insert failed");
                $db->rollback();
                $db->exec("UNLOCK TABLES");
                return false;
            }
            $res=$this->reset_fails_masternodes($mn_winner, $height, $hash);
            if (!$res) {
                
                    // rollback and exit if it fails
                _log("Masternode log DB insert failed");
                $db->rollback();
                $db->exec("UNLOCK TABLES");
                return false;
            }
            

            $this->do_hard_forks($height, $hash);
        }

        // parse the block's transactions and insert them to db
        $res = $this->parse_block($hash, $height, $data, false, $bootstrapping);

        if (($height-1)%3==2 && $height>=80000&&$height<80458) {
            $this->blacklist_masternodes();
            $this->reset_fails_masternodes($public_key, $height, $hash);
        }

        // automated asset distribution, checked only every 1000 blocks to reduce load. Payouts every 10000 blocks.

        if ($height>216000  && $height%50==1 && $res==true) { //  every 50 for testing. No initial height set yet.
            $res=$this->asset_distribute_dividends($height, $hash, $public_key, $date, $signature);
        }

        if ($height>216000 && $res==true) {
            $res=$this->asset_market_orders($height, $hash, $public_key, $date, $signature);
        }

        if ($height>216000 && $height%43200==0) {
            $res=$this->masternode_votes($public_key, $height, $hash);
        }
        
        // if any fails, rollback
        if ($res == false) {
            _log("Rollback block",3);
            $db->rollback();
        } else {
            _log("Commiting block",3);
            $db->commit();
        }
        // relese the locking as everything is finished
        $db->exec("UNLOCK TABLES");
        return true;
    }

    public function masternode_votes($public_key, $height, $hash)
    {
        global $db;

        $arodev='PZ8Tyr4Nx8MHsRAGMpZmZ6TWY63dXWSCvcUb8x4p38GFbZWaJKcncEWqUbe7YJtrDXomwn7DtDYuyYnN2j6s4nQxP1u9BiwCA8U4TjtC9Z21j3R3STLJSFyL';

        // masternode votes
        if ($height%43200==0) {
            _log("Checking masternode votes", 3);
            $blacklist=[];
            $total_mns=$db->single("SELECT COUNT(1) FROM masternode");
            $total_mns_with_key=$db->single("SELECT COUNT(1) FROM masternode WHERE vote_key IS NOT NULL");
            
            // only if at least 50% of the masternodes have voting keys
            if ($total_mns_with_key/$total_mns>0.50) {
                _log("Counting the votes from other masternodes", 3);
                $r=$db->run("SELECT message, count(message) as c FROM transactions WHERE version=106 AND height>:height group by message", [':height'=>$height-43200]);
                foreach ($r as $x) {
                    if ($x['c']>$total_mns_with_key/1.5) {
                        $blacklist[]=san($x['message']);
                    }
                }
            } else {
                // If less than 50% of the mns have voting key, AroDev's votes are used
                _log("Counting AroDev votes", 3);
                $r=$db->run("SELECT message FROM transactions WHERE version=106 AND height>:height AND public_key=:pub", [':height'=>$height-43200, ":pub"=>$arodev]);
                foreach ($r as $x) {
                    $blacklist[]=san($x['message']);
                }
            }
            $r=$db->run("SELECT public_key FROM masternode WHERE voted=1");
            foreach ($r as $masternode) {
                if (!in_array($masternode, $blacklist)) {
                    _log("Masternode removed from voting blacklist - $masternode", 3);
                    $this->add_log($hash, ["table"=>"masternode", "key"=>"public_key","id"=>$masternode, "vals"=>['voted'=>1]]);
                    $db->run("UPDATE masternode SET voted=0 WHERE public_key=:pub", [":pub"=>$masternode]);
                }
            }

            foreach ($blacklist as $masternode) {
                $res=$db->single("SELECT voted FROM masternode WHERE public_key=:pub", [":pub"=>$masternode]);
                if ($res==0) {
                    _log("Masternode blacklist voted - $masternode", 3);
                    $db->run("UPDATE masternode SET voted=1 WHERE public_key=:pub", [":pub"=>$masternode]);
                    $this->add_log($hash, ["table"=>"masternode", "key"=>"public_key","id"=>$masternode, "vals"=>['voted'=>0]]);
                }
            }
        }

        // blockchain votes
        $voted=[];
        if ($height%129600==0) {

             // only if at least 50% of the masternodes have voting keys
            if ($total_mns_with_key/$total_mns>0.50) {
                _log("Counting masternode blockchain votes", 3);
                $r=$db->run("SELECT message, count(message) as c FROM transactions WHERE version=107 AND height>:height group by message", [':height'=>$height-129600]);
                foreach ($r as $x) {
                    if ($x['c']>$total_mns_with_key/1.5) {
                        $voted[]=san($x['message']);
                    }
                }
            } else {
                _log("Counting AroDev blockchain votes", 3);
                // If less than 50% of the mns have voting key, AroDev's votes are used
                $r=$db->run("SELECT message FROM transactions WHERE version=107 AND height>:height AND public_key=:pub", [':height'=>$height-129600*, ":pub"=>$arodev]);
                foreach ($r as $x) {
                    $voted[]=san($x['message']);
                }
            }


            foreach ($voted as $vote) {
                $v=$db->row("SELECT id, val FROM votes WHERE id=:id", [":id"=>$vote]);
                if ($v) {
                    if ($v['val']==0) {
                        _log("Blockchain vote - $v[id] = 1", 3);
                        $db->run("UPDATE votes SET val=1 WHERE id=:id", [":id"=>$v['id']]);
                        $this->add_log($hash, ["table"=>"votes", "key"=>"id","id"=>$v['id'], "vals"=>['val'=>0]]);
                    } else {
                        _log("Blockchain vote - $v[id] = 0", 3);
                        $db->run("UPDATE votes SET val=0 WHERE id=:id", [":id"=>$v['id']]);
                        $this->add_log($hash, ["table"=>"votes", "key"=>"id","id"=>$v['id'], "vals"=>['val'=>1]]);
                    }
                }
            }
        }

        return true;
    }

    public function asset_market_orders($height, $hash, $public_key, $date, $signature)
    {
        global $db;
        $trx=new Transaction;
        // checks all bid market orders ordered in the same way on all nodes
        $r=$db->run("SELECT * FROM assets_market WHERE status=0 and val_done<val AND type='bid' ORDER by asset ASC, id ASC");
        foreach ($r as $x) {
            $finished=0;
            //remaining part of the order
            $val=$x['val']-$x['val_done'];
            // starts checking all ask orders that are still valid and are on the same price. should probably adapt this to allow lower price as well in the future.
            $asks=$db->run("SELECT * FROM assets_market WHERE status=0 and val_done<val AND asset=:asset AND price=:price AND type='ask' ORDER by price ASC, id ASC", [":asset"=>$x['asset'], ":price"=>$x['price']]);
            foreach ($asks as $ask) {
                //remaining part of the order
                $remaining=$ask['val']-$ask['val_done'];
                // how much of the ask should we use to fill the bid order
                $use=0;
                if ($remaining>$val) {
                    $use=$val;
                } else {
                    $use=$remaining;
                }
                $val-=$use;
                $db->run("UPDATE assets_market SET val_done=val_done+:done WHERE id=:id", [":id"=>$ask['id'], ":done"=>$use]);
                $db->run("UPDATE assets_market SET val_done=val_done+:done WHERE id=:id", [":id"=>$x['id'], ":done"=>$use]);
                // if we filled the order, we should exit the loop
                $db->run("INSERT into assets_balance SET account=:account, asset=:asset, balance=:balance ON DUPLICATE KEY UPDATE balance=balance+:balance2", [":account"=>$x['account'], ":asset"=>$x['asset'], ":balance"=>$use, ":balance2"=>$use]);
                $aro=$use*$x['price'];
                $db->run("UPDATE accounts SET balance=balance+:balance WHERE id=:id", [":balance"=>$aro, ":id"=>$ask['account']]);

                $random = hex2coin(hash("sha512", $x['id'].$ask['id'].$val.$hash));
                $new = [
                        "id"         => $random,
                        "public_key" => $x['id'],
                        "dst"        => $ask['id'],
                        "val"        => $aro,
                        "fee"        => 0,
                        "signature"  => $signature,
                        "version"    => 58,
                        "date"       => $date,
                        "message"    => $use
                    ];
                    
                $res=$trx->add($hash, $height, $new);
                if (!$res) {
                    return false;
                }
                if ($val<=0) {
                    break;
                }
            }
        }



        return true;
    }


    public function asset_distribute_dividends($height, $hash, $public_key, $date, $signature)
    {
        global $db;
        $trx=new Transaction;
        _log("Starting automated dividend distribution", 3);
        // just the assets with autodividend
        $r=$db->run("SELECT * FROM assets WHERE auto_dividend=1");
        
        if ($r===false) {
            return true;
        }
        foreach ($r as $x) {
            $asset=$db->row("SELECT id, public_key, balance FROM accounts WHERE id=:id", [":id"=>$x['id']]);
            // minimum balance 1 aro
            if ($asset['balance']<1) {
                _log("Asset $asset[id] not enough balance", 3);
                continue;
            }
            _log("Autodividend $asset[id] - $asset[balance] ARO", 3);
            // every 10000 blocks and at minimum 10000 of asset creation or last distribution, manual or automated
            $last=$db->single("SELECT height FROM transactions WHERE (version=54 OR version=50 or version=57) AND public_key=:pub ORDER by height DESC LIMIT 1", [":pub"=>$asset['public_key']]);
            if ($height<$last+100) { // 100 for testnet
                continue;
            }
            // generate a pseudorandom id  and version 54 transaction for automated dividend distribution. No fees for such automated distributions to encourage the system
            $random = hex2coin(hash("sha512", $x['id'].$hash.$height));
            $new = [
                "id"         => $random,
                "public_key" => $asset['public_key'],
                "dst"        => $asset['id'],
                "val"        => $asset['balance'],
                "fee"        => 0,
                "signature"  => $signature,
                "version"    => 57,
                "date"       => $date,
                "src"        => $asset['id'],
                "message"    => '',
            ];
            $res=$trx->add($hash, $height, $new);
            if (!$res) {
                return false;
            }
        }
        return true;
    }
    public function do_hard_forks($height, $block)
    {
        global $db;
        if ($height==126215) {
            // compromised masternodes are being removed
            $mns=['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',
        ];

            foreach ($mns as $mn) {
                $db->run("DELETE FROM masternode WHERE public_key=:p", [":p"=>$mn]);
            }
            // their locked coins are added to dev's safewallet
            $id=hex2coin(hash("sha512", "hf".$block.$height.'compromised-masternodes'));
            $res=$db->run(
                "INSERT into transactions SET id=:id, block=:block, height=:height, dst=:dst, val=4700000, fee=0, signature=:sig, version=0, message=:msg, date=:date, public_key=:public_key",
                [":id"=>$id, ":block"=>$block, ":height"=>$height, ":dst"=>'4kWXV4HMuogUcjZBEzmmQdtc1dHzta6VykhCV1HWyEXK7kRWEMJLNoMWbuDwFMTfBrq5a9VthkZfmkMkamTfwRBP', ":sig"=>$id, ":msg"=>'compromised-masternodes-hf', ":date"=>time(), ":public_key"=>'4kWXV4HMuogUcjZBEzmmQdtc1dHzta6VykhCV1HWyEXK7kRWEMJLNoMWbuDwFMTfBrq5a9VthkZfmkMkamTfwRBP']
            );
            $db->run("UPDATE accounts SET balance=balance+4700000 where id='4kWXV4HMuogUcjZBEzmmQdtc1dHzta6VykhCV1HWyEXK7kRWEMJLNoMWbuDwFMTfBrq5a9VthkZfmkMkamTfwRBP' LIMIT 1");
        }
    }


    // resets the number of fails when winning a block and marks it with a transaction

    public function reset_fails_masternodes($public_key, $height, $hash)
    {
        global $db;
        $res=$this->masternode_log($public_key, $height, $hash);
        if ($res===5) {
            return false;
        }

        if ($res) {
            $rez=$db->run("UPDATE masternode SET last_won=:last_won,fails=0 WHERE public_key=:public_key", [":public_key"=>$public_key, ":last_won"=>$height]);
            if ($rez!=1) {
                return false;
            }
        }
        return true;
    }

    //logs the current masternode status
    public function masternode_log($public_key, $height, $hash)
    {
        global $db;
       
        $mn=$db->row("SELECT blacklist,last_won,fails FROM masternode WHERE public_key=:public_key", [":public_key"=>$public_key]);
        
        if (!$mn) {
            return false;
        }

        $id = hex2coin(hash("sha512", "resetfails-$hash-$height-$public_key"));
        $msg="$mn[blacklist],$mn[last_won],$mn[fails]";
        
        $res=$db->run(
            "INSERT into transactions SET id=:id, block=:block, height=:height, dst=:dst, val=0, fee=0, signature=:sig, version=111, message=:msg, date=:date, public_key=:public_key",
            [":id"=>$id, ":block"=>$hash, ":height"=>$height, ":dst"=>$hash, ":sig"=>$hash, ":msg"=>$msg, ":date"=>time(), ":public_key"=>$public_key]
        
        );
        if ($res!=1) {
            return 5;
        }
        return true;
    }

    // returns the current block, without the transactions
    public function current()
    {
        global $db;
        $current = $db->row("SELECT * FROM blocks ORDER by height DESC LIMIT 1");
        if (!$current) {
            $this->genesis();
            return $this->current(true);
        }
        return $current;
    }

    // returns the previous block
    public function prev()
    {
        global $db;
        $current = $db->row("SELECT * FROM blocks ORDER by height DESC LIMIT 1,1");

        return $current;
    }

    // calculates the difficulty / base target for a specific block. The higher the difficulty number, the easier it is to win a block.
    public function difficulty($height = 0)
    {
        global $db;

        // if no block height is specified, use the current block.
        if ($height == 0) {
            $current = $this->current();
        } else {
            $current = $this->get($height);
        }


        $height = $current['height'];

        if ($height == 10801||($height>=80456&&$height<80460)) {
            return "5555555555"; //hard fork 10900 resistance, force new difficulty
        }

        // last 20 blocks used to check the block times
        $limit = 20;
        if ($height < 20) {
            $limit = $height - 1;
        }

        // for the first 10 blocks, use the genesis difficulty
        if ($height < 10) {
            return $current['difficulty'];
        }

        // before mnn hf
        if ($height<80000) {
            // elapsed time between the last 20 blocks
            $first = $db->row("SELECT `date` FROM blocks  ORDER by height DESC LIMIT :limit,1", [":limit"=>$limit]);
            $time = $current['date'] - $first['date'];

            // avg block time
            $result = ceil($time / $limit);
            _log("Block time: $result", 3);

        
            // if larger than 200 sec, increase by 5%
            if ($result > 220) {
                $dif = bcmul($current['difficulty'], 1.05);
            } elseif ($result < 260) {
                // if lower, decrease by 5%
                $dif = bcmul($current['difficulty'], 0.95);
            } else {
                // keep current difficulty
                $dif = $current['difficulty'];
            }
        } elseif ($height>=80458) {
            $type=$height%2;
            $current=$db->row("SELECT difficulty from blocks WHERE height<=:h ORDER by height DESC LIMIT 1,1", [":h"=>$height]);
            $blks=0;
            $total_time=0;
            $blk = $db->run("SELECT `date`, height FROM blocks WHERE height<=:h  ORDER by height DESC LIMIT 20", [":h"=>$height]);
            for ($i=0;$i<19;$i++) {
                $ctype=$blk[$i+1]['height']%2;
                $time=$blk[$i]['date']-$blk[$i+1]['date'];
                if ($type!=$ctype) {
                    continue;
                }
                $blks++;
                $total_time+=$time;
            }
            $result=ceil($total_time/$blks);
            _log("Block time: $result", 3);
            // 1 minute blocktime
            if ($height>216000) {
                if ($result > 65) {
                    $dif = bcmul($current['difficulty'], 1.05);
                } elseif ($result < 55) {
                    // if lower, decrease by 5%
                    $dif = bcmul($current['difficulty'], 0.95);
                } else {
                    // keep current difficulty
                    $dif = $current['difficulty'];
                }
            } else {
                // 4 minutes blocktime
                if ($result > 260) {
                    $dif = bcmul($current['difficulty'], 1.05);
                } elseif ($result < 220) {
                    // if lower, decrease by 5%
                    $dif = bcmul($current['difficulty'], 0.95);
                } else {
                    // keep current difficulty
                    $dif = $current['difficulty'];
                }
            }
        } else {
            // hardfork 80000, fix difficulty targetting



            $type=$height%3;
            // for mn, we use gpu diff
            if ($type == 2) {
                return $current['difficulty'];
            }

            $blks=0;
            $total_time=0;
            $blk = $db->run("SELECT `date`, height FROM blocks  ORDER by height DESC LIMIT 60");
            for ($i=0;$i<59;$i++) {
                $ctype=$blk[$i+1]['height']%3;
                $time=$blk[$i]['date']-$blk[$i+1]['date'];
                if ($type!=$ctype) {
                    continue;
                }
                $blks++;
                $total_time+=$time;
            }
            $result=ceil($total_time/$blks);
            _log("Block time: $result", 3);
           
            // if larger than 260 sec, increase by 5%
            if ($result > 260) {
                $dif = bcmul($current['difficulty'], 1.05);
            } elseif ($result < 220) {
                // if lower, decrease by 5%
                $dif = bcmul($current['difficulty'], 0.95);
            } else {
                // keep current difficulty
                $dif = $current['difficulty'];
            }
        }






        if (strpos($dif, '.') !== false) {
            $dif = substr($dif, 0, strpos($dif, '.'));
        }

        //minimum and maximum diff
        if ($dif < 1000) {
            $dif = 1000;
        }
        if ($dif > 9223372036854775800) {
            $dif = 9223372036854775800;
        }
        _log("Difficulty: $dif", 3);
        return $dif;
    }

    // calculates the maximum block size and increase by 10% the number of transactions if > 100 on the last 100 blocks
    public function max_transactions()
    {
        global $db;
        $current = $this->current();
        $limit = $current['height'] - 100;
        $avg = $db->single("SELECT AVG(transactions) FROM blocks WHERE height>:limit", [":limit" => $limit]);
        if ($avg < 100) {
            return 100;
        }
        return ceil($avg * 1.1);
    }

    // calculate the reward for each block
    public function reward($id, $data = [])
    {
        if ($id>216000) {
            // 1min block time
            $reward=200;
            $factor = floor(($id-216000) / 43200) / 100;
            $reward -= $reward * $factor;
        } else {
            // starting reward
            $reward = 1000;
            // decrease by 1% each 10800 blocks (approx 1 month)
            $factor = floor($id / 10800) / 100;
            $reward -= $reward * $factor;
        }

        if ($reward < 0) {
            $reward = 0;
        }
        // calculate the transaction fees
        $fees = 0;
        if (count($data) > 0) {
            foreach ($data as $x) {
                $fees += $x['fee'];
            }
        }
        return number_format($reward + $fees, 8, '.', '');
    }

    // checks the validity of a block
    public function check($data)
    {
        // argon must have at least 20 chars
        if (strlen($data['argon']) < 20) {
            _log("Invalid block argon - $data[argon]");
            return false;
        }
        $acc = new Account();

        if ($data['date']>time()+30) {
            _log("Future block - $data[date] $data[public_key]", 2);
            return false;
        }

        // generator's public key must be valid
        if (!$acc->valid_key($data['public_key'])) {
            _log("Invalid public key - $data[public_key]");
            return false;
        }

        //difficulty should be the same as our calculation
        if ($data['difficulty'] != $this->difficulty()) {
            _log("Invalid difficulty - $data[difficulty] - ".$this->difficulty());
            return false;
        }

        //check the argon hash and the nonce to produce a valid block
        if (!$this->mine($data['public_key'], $data['nonce'], $data['argon'], $data['difficulty'], 0, 0, $data['date'])) {
            _log("Mine check failed");
            return false;
        }

        return true;
    }

    // creates a new block on this node
    public function forge($nonce, $argon, $public_key, $private_key)
    {
        //check the argon hash and the nonce to produce a valid block
        if (!$this->mine($public_key, $nonce, $argon)) {
            _log("Forge failed - Invalid argon");
            return false;
        }

        // the block's date timestamp must be bigger than the last block
        $current = $this->current();
        $height = $current['height'] += 1;
        $date = time();
        if ($date <= $current['date']) {
            _log("Forge failed - Date older than last block");
            return false;
        }

        // get the mempool transactions
        $txn = new Transaction();
        $data = $txn->mempool($this->max_transactions());


        $difficulty = $this->difficulty();
        $acc = new Account();
        $generator = $acc->get_address($public_key);

        // always sort  the transactions in the same way
        ksort($data);

        // sign the block
        $signature = $this->sign($generator, $height, $date, $nonce, $data, $private_key, $difficulty, $argon);

        // reward transaction and signature
        $reward = $this->reward($height, $data);
        $mn_reward_rate=0.33;
        global $db;
        // hf
        if ($height>216000) {
            $votes=[];
            $r=$db->run("SELECT id,val FROM votes");
            foreach ($r as $vote) {
                $votes[$vote['id']]=$vote['val'];
            }
            // emission cut by 30%
            if ($votes['emission30']==1) {
                $reward=round($reward*0.7);
            }
            // 50% to masternodes
            if ($votes['masternodereward50']==1) {
                $mn_reward_rate=0.5;
            }

            // minimum reward to always be 10 aro
            if ($votes['endless10reward']==1&&$reward<10) {
                $reward=10;
            }
        }

        if ($height>=80458) {
            //reward the masternode
            
            $mn_winner=$db->single(
                "SELECT public_key FROM masternode WHERE status=1 AND blacklist<:current AND height<:start ORDER by last_won ASC, public_key ASC LIMIT 1",
                [":current"=>$height, ":start"=>$height-360]
            );
            _log("MN Winner: $mn_winner", 2);
            if ($mn_winner!==false) {
                $mn_reward=round($mn_reward_rate*$reward, 8);
                $reward=round($reward-$mn_reward, 8);
                $reward=number_format($reward, 8, ".", "");
                $mn_reward=number_format($mn_reward, 8, ".", "");
                _log("MN Reward: $mn_reward", 2);
            }
        }

        $msg = '';
        $transaction = [
            "src"        => $generator,
            "dst"        => $generator,
            "val"        => $reward,
            "version"    => 0,
            "date"       => $date,
            "message"    => $msg,
            "fee"        => "0.00000000",
            "public_key" => $public_key,
        ];
        ksort($transaction);
        $reward_signature = $txn->sign($transaction, $private_key);

        // add the block to the blockchain
        $res = $this->add(
            $height,
            $public_key,
            $nonce,
            $data,
            $date,
            $signature,
            $difficulty,
            $reward_signature,
            $argon
        );
        if (!$res) {
            _log("Forge failed - Block->Add() failed");
            return false;
        }
        return true;
    }
    
    public function blacklist_masternodes()
    {
        global $db;
        _log("Checking if there are masternodes to be blacklisted", 2);
        $current = $this->current();
        if (($current['height']-1)%3!=2) {
            _log("bad height");
            return;
        }
        $last=$this->get($current['height']-1);
        $total_time=$current['date']-$last['date'];
        _log("blacklist total time $total_time");
        if ($total_time<=600&&$current['height']<80500) {
            return;
        }
        if ($current['height']>=80500&&$total_time<360) {
            return false;
        }
        if ($current['height']>=80500) {
            $total_time-=360;
            $tem=floor($total_time/120)+1;
            if ($tem>5) {
                $tem=5;
            }
        } else {
            $tem=floor($total_time/600);
        }
        _log("We have masternodes to blacklist - $tem", 2);
        $ban=$db->run(
            "SELECT public_key, blacklist, fails, last_won FROM masternode WHERE status=1 AND blacklist<:current AND height<:start ORDER by last_won ASC, public_key ASC LIMIT 0,:limit",
            [":current"=>$last['height'], ":start"=>$last['height']-360, ":limit"=>$tem]
        );
        _log(json_encode($ban));
        $i=0;
        foreach ($ban as $b) {
            $this->masternode_log($b['public_key'], $current['height'], $current['id']);
            _log("Blacklisting masternode - $i $b[public_key]", 2);
            $btime=10;
            if ($current['height']>83000) {
                $btime=360;
            }
            $db->run("UPDATE masternode SET fails=fails+1, blacklist=:blacklist WHERE public_key=:public_key", [":public_key"=>$b['public_key'], ":blacklist"=> $current['height']+(($b['fails']+1)*$btime)]);
            $i++;
        }
    }

    // check if the arguments are good for mining a specific block
    public function mine($public_key, $nonce, $argon, $difficulty = 0, $current_id = 0, $current_height = 0, $time=0)
    {
        global $_config;
   
        // invalid future blocks
        if ($time>time()+30) {
            return false;
        }


        // if no id is specified, we use the current
        if ($current_id === 0 || $current_height === 0) {
            $current = $this->current();
            $current_id = $current['id'];
            $current_height = $current['height'];
        }
        _log("Block Timestamp $time", 3);
        if ($time == 0) {
            $time=time();
        }
        // get the current difficulty if empty
        if ($difficulty === 0) {
            $difficulty = $this->difficulty();
        }
        
        if (empty($public_key)) {
            _log("Empty public key", 1);
            return false;
        }
        
        if ($current_height<80000) {
            
            // the argon parameters are hardcoded to avoid any exploits
            if ($current_height > 10800) {
                _log("Block below 80000 but after 10800, using 512MB argon", 2);
                $argon = '$argon2i$v=19$m=524288,t=1,p=1'.$argon; //10800 block hard fork - resistance against gpu
            } else {
                _log("Block below 10800, using 16MB argon", 2);
                $argon = '$argon2i$v=19$m=16384,t=4,p=4'.$argon;
            }
        } elseif ($current_height>=80458) {
            if ($current_height%2==0) {
                // cpu mining
                _log("CPU Mining - $current_height", 2);
                $argon = '$argon2i$v=19$m=524288,t=1,p=1'.$argon;
            } else {
                // gpu mining
                _log("GPU Mining - $current_height", 2);
                $argon = '$argon2i$v=19$m=16384,t=4,p=4'.$argon;
            }
        } else {
            _log("Block > 80000 - $current_height", 2);
            if ($current_height%3==0) {
                // cpu mining
                _log("CPU Mining - $current_height", 2);
                $argon = '$argon2i$v=19$m=524288,t=1,p=1'.$argon;
            } elseif ($current_height%3==1) {
                // gpu mining
                _log("GPU Mining - $current_height", 2);
                $argon = '$argon2i$v=19$m=16384,t=4,p=4'.$argon;
            } else {
                _log("Masternode Mining - $current_height", 2);
                // masternode
                global $db;
                
                // fake time
                if ($time>time()) {
                    _log("Masternode block in the future - $time", 1);
                    return false;
                }

                // selecting the masternode winner in order
                $winner=$db->single(
                    "SELECT public_key FROM masternode WHERE status=1 AND blacklist<:current AND height<:start ORDER by last_won ASC, public_key ASC LIMIT 1",
                    [":current"=>$current_height, ":start"=>$current_height-360]
                );
               
                // if there are no active masternodes, give the block to gpu
                if ($winner===false) {
                    _log("No active masternodes, reverting to gpu", 1);
                    $argon = '$argon2i$v=19$m=16384,t=4,p=4'.$argon;
                } else {
                    _log("The first masternode winner should be $winner", 1);
                    // 4 mins need to pass since last block
                    $last_time=$db->single("SELECT `date` FROM blocks WHERE height=:height", [":height"=>$current_height]);
                    if ($time-$last_time<240&&$_config['testnet']==false) {
                        _log("4 minutes have not passed since the last block - $time", 1);
                        return false;
                    }
                    
                    if ($public_key==$winner) {
                        return true;
                    }
                    // if 10 mins have passed, try to give the block to the next masternode and do this every 10mins
                    _log("Last block time: $last_time, difference: ".($time-$last_time), 3);
                    if (($time-$last_time>600&&$current_height<80500)||($time-$last_time>360&&$current_height>=80500)) {
                        _log("Current public_key $public_key", 3);
                        if ($current_height>=80500) {
                            $total_time=$time-$last_time;
                            $total_time-=360;
                            $tem=floor($total_time/120)+1;
                        } else {
                            $tem=floor(($time-$last_time)/600);
                        }
                        $winner=$db->single(
                            "SELECT public_key FROM masternode WHERE status=1 AND blacklist<:current AND height<:start ORDER by last_won ASC, public_key ASC LIMIT :tem,1",
                            [":current"=>$current_height, ":start"=>$current_height-360, ":tem"=>$tem]
                        );
                        _log("Moving to the next masternode - $tem - $winner", 1);
                        // if all masternodes are dead, give the block to gpu
                        if ($winner===false||($tem>=5&&$current_height>=80500)) {
                            _log("All masternodes failed, giving the block to gpu", 1);
                            $argon = '$argon2i$v=19$m=16384,t=4,p=4'.$argon;
                        } elseif ($winner==$public_key) {
                            return true;
                        } else {
                            return false;
                        }
                    } else {
                        _log("A different masternode should win this block $public_key - $winner", 2);
                        return false;
                    }
                }
            }
        }

        // the hash base for agon
        $base = "$public_key-$nonce-".$current_id."-$difficulty";


        // check argon's hash validity
        if (!password_verify($base, $argon)) {
            _log("Argon verify failed - $base - $argon", 2);
            return false;
        }

        // all nonces are valid in testnet
        if ($_config['testnet'] == true) {
            return true;
        }

        // prepare the base for the hashing
        $hash = $base.$argon;

        // hash the base 6 times
        for ($i = 0; $i < 5;
             $i++) {
            $hash = hash("sha512", $hash, true);
        }
        $hash = hash("sha512", $hash);

        // split it in 2 char substrings, to be used as hex
        $m = str_split($hash, 2);

        // calculate a number based on 8 hex numbers - no specific reason, we just needed an algoritm to generate the number from the hash
        $duration = hexdec($m[10]).hexdec($m[15]).hexdec($m[20]).hexdec($m[23]).hexdec($m[31]).hexdec($m[40]).hexdec($m[45]).hexdec($m[55]);

        // the number must not start with 0
        $duration = ltrim($duration, '0');

        // divide the number by the difficulty and create the deadline
        $result = gmp_div($duration, $difficulty);

        // if the deadline >0 and <=240, the arguments are valid fora  block win
        if ($result > 0 && $result <= 240) {
            return true;
        }
        return false;
    }


    // parse the block transactions
    public function parse_block($block, $height, $data, $test = true, $bootstrapping=false)
    {
        global $db;
        // data must be array
        if ($data === false) {
            _log("Block data is false", 3);
            return false;
        }
        $acc = new Account();
        $trx = new Transaction();
        // no transactions means all are valid
        if (count($data) == 0) {
            return true;
        }

        // check if the number of transactions is not bigger than current block size
        $max = $this->max_transactions();
        if (count($data) > $max) {
            _log("Too many transactions in block", 3);
            return false;
        }

        $balance = [];
        $mns = [];

        foreach ($data as &$x) {
            // get the sender's account if empty
            if (empty($x['src'])) {
                $x['src'] = $acc->get_address($x['public_key']);
            }
            if (!$bootstrapping) {
                //validate the transaction
                if (!$trx->check($x, $height)) {
                    _log("Transaction check failed - $x[id]", 3);
                    return false;
                }
                if ($x['version']>=100&&$x['version']<110&&$x['version']!=106&&$x['version']!=107) {
                    $mns[] = $x['public_key'];
                }
                if($x['version']==106||$x['version']==107){
                    $mns[]=$x['public_key'].$x['message'];
                }

                // prepare total balance
                $balance[$x['src']] += $x['val'] + $x['fee'];

                // check if the transaction is already on the blockchain
                if ($db->single("SELECT COUNT(1) FROM transactions WHERE id=:id", [":id" => $x['id']]) > 0) {
                    _log("Transaction already on the blockchain - $x[id]", 3);
                    return false;
                }
            }
        }
        //only a single masternode transaction per block for any masternode
        if (count($mns) != count(array_unique($mns))) {
            _log("Too many masternode transactions", 3);
            return false;
        }

        if (!$bootstrapping) {
            // check if the account has enough balance to perform the transaction
            foreach ($balance as $id => $bal) {
                $res = $db->single(
                    "SELECT COUNT(1) FROM accounts WHERE id=:id AND balance>=:balance",
                    [":id" => $id, ":balance" => $bal]
            );
                if ($res == 0) {
                    _log("Not enough balance for transaction - $id", 3);
                    return false; // not enough balance for the transactions
                }
            }
        }
        // if the test argument is false, add the transactions to the blockchain
        if ($test == false) {
            foreach ($data as $d) {
                $res = $trx->add($block, $height, $d);
                if ($res == false) {
                    return false;
                }
            }
        }

        return true;
    }


    // initialize the blockchain, add the genesis block
    private function genesis()
    {
        global $db;
        $signature = 'AN1rKvtLTWvZorbiiNk5TBYXLgxiLakra2byFef9qoz1bmRzhQheRtiWivfGSwP6r8qHJGrf8uBeKjNZP1GZvsdKUVVN2XQoL';
        $generator = '2P67zUANj7NRKTruQ8nJRHNdKMroY6gLw4NjptTVmYk6Hh1QPYzzfEa9z4gv8qJhuhCNM8p9GDAEDqGUU1awaLW6';
        $public_key = 'PZ8Tyr4Nx8MHsRAGMpZmZ6TWY63dXWSCyjGMdVDanywM3CbqvswVqysqU8XS87FcjpqNijtpRSSQ36WexRDv3rJL5X8qpGvzvznuErSRMfb2G6aNoiaT3aEJ';
        $reward_signature = '381yXZ3yq2AXHHdXfEm8TDHS4xJ6nkV4suXtUUvLjtvuyi17jCujtwcwXuYALM1F3Wiae2A4yJ6pXL1kTHJxZbrJNgtsKEsb';
        $argon = '$M1ZpVzYzSUxYVFp6cXEwWA$CA6p39MVX7bvdXdIIRMnJuelqequanFfvcxzQjlmiik';

        $difficulty = "5555555555";
        $height = 1;
        $data = [];
        $date = '1515324995';
        $nonce = '4QRKTSJ+i9Gf9ubPo487eSi+eWOnIBt9w4Y+5J+qbh8=';


        $res = $this->add(
            $height,
            $public_key,
            $nonce,
            $data,
            $date,
            $signature,
            $difficulty,
            $reward_signature,
            $argon
        );
        if (!$res) {
            api_err("Could not add the genesis block.");
        }
    }

    // delete last X blocks
    public function pop($no = 1)
    {
        $current = $this->current();
        $this->delete($current['height'] - $no + 1);
    }

    // delete all blocks >= height
    public function delete($height)
    {
        if ($height < 2) {
            $height = 2;
        }
        global $db;
        $trx = new Transaction();

        $r = $db->run("SELECT * FROM blocks WHERE height>=:height ORDER by height DESC", [":height" => $height]);

        if (count($r) == 0) {
            return;
        }
        $db->beginTransaction();
        $db->exec("LOCK TABLES blocks WRITE, accounts WRITE, transactions WRITE, mempool WRITE, masternode WRITE, peers write, config WRITE, assets WRITE, assets_balance WRITE, assets_market WRITE, votes WRITE,logs WRITE");

        foreach ($r as $x) {
            $res = $trx->reverse($x['id']);
            if ($res === false) {
                _log("A transaction could not be reversed. Delete block failed.");
                $db->rollback();
                $db->exec("UNLOCK TABLES");
                return false;
            }
            $res = $db->run("DELETE FROM blocks WHERE id=:id", [":id" => $x['id']]);
            if ($res != 1) {
                _log("Delete block failed.");
                $db->rollback();
                $db->exec("UNLOCK TABLES");
                return false;
            }
            $this->reverse_log($x['id']);
        }

      

        $db->commit();
        $db->exec("UNLOCK TABLES");
        return true;
    }


    // delete specific block
    public function delete_id($id)
    {
        global $db;
        $trx = new Transaction();

        $x = $db->row("SELECT * FROM blocks WHERE id=:id", [":id" => $id]);

        if ($x === false) {
            return false;
        }
        // avoid race conditions on blockchain manipulations
        $db->beginTransaction();
        $db->exec("LOCK TABLES blocks WRITE, accounts WRITE, transactions WRITE, mempool WRITE, masternode WRITE, peers write, config WRITE, assets WRITE, assets_balance WRITE, assets_market WRITE, votes WRITE, logs WRITE");

        // reverse all transactions of the block
        $res = $trx->reverse($x['id']);
        if ($res === false) {
            // rollback if you can't reverse the transactions
            $db->rollback();
            $db->exec("UNLOCK TABLES");
            return false;
        }
        // remove the actual block
        $res = $db->run("DELETE FROM blocks WHERE id=:id", [":id" => $x['id']]);
        if ($res != 1) {
            //rollback if you can't delete the block
            $db->rollback();
            $db->exec("UNLOCK TABLES");
            return false;
        }
        // commit and release if all good
        $db->commit();
        $db->exec("UNLOCK TABLES");
        return true;
    }


    // sign a new block, used when mining
    public function sign($generator, $height, $date, $nonce, $data, $key, $difficulty, $argon)
    {
        $json = json_encode($data);
        $info = "{$generator}-{$height}-{$date}-{$nonce}-{$json}-{$difficulty}-{$argon}";

        $signature = ec_sign($info, $key);
        return $signature;
    }

    // generate the sha512 hash of the block data and converts it to base58
    public function hash($public_key, $height, $date, $nonce, $data, $signature, $difficulty, $argon)
    {
        $json = json_encode($data);
        $hash = hash("sha512", "{$public_key}-{$height}-{$date}-{$nonce}-{$json}-{$signature}-{$difficulty}-{$argon}");
        return hex2coin($hash);
    }


    // exports the block data, to be used when submitting to other peers
    public function export($id = "", $height = "")
    {
        if (empty($id) && empty($height)) {
            return false;
        }

        global $db;
        $trx = new Transaction();
        if (!empty($height)) {
            $block = $db->row("SELECT * FROM blocks WHERE height=:height", [":height" => $height]);
        } else {
            $block = $db->row("SELECT * FROM blocks WHERE id=:id", [":id" => $id]);
        }

        if (!$block) {
            return false;
        }
        $r = $db->run("SELECT * FROM transactions WHERE version>0 AND block=:block", [":block" => $block['id']]);
        $transactions = [];
        foreach ($r as $x) {
            if ($x['version']>110||$x['version']==57||$x['version']==58||$x['version']==59) {
                //internal transactions
                continue;
            }
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
            ksort($trans);
            $transactions[$x['id']] = $trans;
        }
        ksort($transactions);
        $block['data'] = $transactions;

        // the reward transaction always has version 0
        $gen = $db->row(
            "SELECT public_key, signature FROM transactions WHERE  version=0 AND block=:block AND message=''",
            [":block" => $block['id']]
        );
        $block['public_key'] = $gen['public_key'];
        $block['reward_signature'] = $gen['signature'];
        return $block;
    }

    //return a specific block as array
    public function get($height)
    {
        global $db;
        if (empty($height)) {
            return false;
        }
        $block = $db->row("SELECT * FROM blocks WHERE height=:height", [":height" => $height]);
        return $block;
    }
}
