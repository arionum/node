<?php

class Block
{
    public function add($height, $public_key, $nonce, $data, $date, $signature, $difficulty, $reward_signature, $argon)
    {
        global $db;
        $acc = new Account;
        $trx = new Transaction;

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
        if (!$acc->check_signature($info, $signature, $public_key)) {
            _log("Block signature check failed");
            return false;
        }

        if (!$this->parse_block($hash, $height, $data, true)) {
            _log("Parse block failed");
            return false;
        }

        // lock table to avoid race conditions on blocks
        $db->exec("LOCK TABLES blocks WRITE, accounts WRITE, transactions WRITE, mempool WRITE");

        $reward = $this->reward($height, $data);

        $msg = '';

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
        // check the signature
        $info = $transaction['val']."-".$transaction['fee']."-".$transaction['dst']."-".$transaction['message']."-".$transaction['version']."-".$transaction['public_key']."-".$transaction['date'];
        if (!$acc->check_signature($info, $reward_signature, $public_key)) {
            _log("Reward signature failed");
            return false;
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
        $trx->add($hash, $height, $transaction);

        // parse the block's transactions and insert them to db
        $res = $this->parse_block($hash, $height, $data, false);
        // if any fails, rollback
        if ($res == false) {
            $db->rollback();
        } else {
            $db->commit();
        }
        // relese the locking as everything is finished
        $db->exec("UNLOCK TABLES");
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

        if ($height == 10801) {
            return 5555555555; //hard fork 10900 resistance, force new difficulty
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

        // elapsed time between the last 20 blocks
        $first = $db->row("SELECT `date` FROM blocks ORDER by height DESC LIMIT $limit,1");
        $time = $current['date'] - $first['date'];

        // avg block time
        $result = ceil($time / $limit);

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
        // starting reward
        $reward = 1000;

        // decrease by 1% each 10800 blocks (approx 1 month)

        $factor = floor($id / 10800) / 100;
        $reward -= $reward * $factor;
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
        $acc = new Account;
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
        if (!$this->mine($data['public_key'], $data['nonce'], $data['argon'])) {
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
        $txn = new Transaction;
        $data = $txn->mempool($this->max_transactions());


        $difficulty = $this->difficulty();
        $acc = new Account;
        $generator = $acc->get_address($public_key);

        // always sort  the transactions in the same way
        ksort($data);

        // sign the block
        $signature = $this->sign($generator, $height, $date, $nonce, $data, $private_key, $difficulty, $argon);

        // reward transaction and signature
        $reward = $this->reward($height, $data);
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

    // check if the arguments are good for mining a specific block
    public function mine($public_key, $nonce, $argon, $difficulty = 0, $current_id = 0, $current_height = 0)
    {
        global $_config;
        // if no id is specified, we use the current
        if ($current_id === 0) {
            $current = $this->current();
            $current_id = $current['id'];
            $current_height = $current['height'];
        }
        // get the current difficulty if empty
        if ($difficulty === 0) {
            $difficulty = $this->difficulty();
        }

        // the argon parameters are hardcoded to avoid any exploits
        if ($current_height > 10800) {
            $argon = '$argon2i$v=19$m=524288,t=1,p=1'.$argon; //10800 block hard fork - resistance against gpu
        } else {
            $argon = '$argon2i$v=19$m=16384,t=4,p=4'.$argon;
        }

        // the hash base for agon
        $base = "$public_key-$nonce-".$current_id."-$difficulty";


        // check argon's hash validity
        if (!password_verify($base, $argon)) {
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
    public function parse_block($block, $height, $data, $test = true)
    {
        global $db;
        // data must be array
        if ($data === false) {
            return false;
        }
        $acc = new Account;
        $trx = new Transaction;
        // no transactions means all are valid
        if (count($data) == 0) {
            return true;
        }

        // check if the number of transactions is not bigger than current block size
        $max = $this->max_transactions();
        if (count($data) > $max) {
            return false;
        }

        $balance = [];
        foreach ($data as &$x) {
            // get the sender's account if empty
            if (empty($x['src'])) {
                $x['src'] = $acc->get_address($x['public_key']);
            }

            //validate the transaction
            if (!$trx->check($x, $height)) {
                return false;
            }

            // prepare total balance
            $balance[$x['src']] += $x['val'] + $x['fee'];

            // check if the transaction is already on the blockchain
            if ($db->single("SELECT COUNT(1) FROM transactions WHERE id=:id", [":id" => $x['id']]) > 0) {
                return false;
            }
        }

        // check if the account has enough balance to perform the transaction
        foreach ($balance as $id => $bal) {
            $res = $db->single(
                "SELECT COUNT(1) FROM accounts WHERE id=:id AND balance>=:balance",
                [":id" => $id, ":balance" => $bal]
            );
            if ($res == 0) {
                return false; // not enough balance for the transactions
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
        $trx = new Transaction;

        $r = $db->run("SELECT * FROM blocks WHERE height>=:height ORDER by height DESC", [":height" => $height]);

        if (count($r) == 0) {
            return;
        }
        $db->beginTransaction();
        $db->exec("LOCK TABLES blocks WRITE, accounts WRITE, transactions WRITE, mempool WRITE");
        foreach ($r as $x) {
            $res = $trx->reverse($x['id']);
            if ($res === false) {
                $db->rollback();
                $db->exec("UNLOCK TABLES");
                return false;
            }
            $res = $db->run("DELETE FROM blocks WHERE id=:id", [":id" => $x['id']]);
            if ($res != 1) {
                $db->rollback();
                $db->exec("UNLOCK TABLES");
                return false;
            }
        }

        $db->commit();
        $db->exec("UNLOCK TABLES");
        return true;
    }


    // delete specific block
    public function delete_id($id)
    {

        global $db;
        $trx = new Transaction;

        $x = $db->row("SELECT * FROM blocks WHERE id=:id", [":id" => $id]);

        if ($x === false) {
            return false;
        }
        // avoid race conditions on blockchain manipulations
        $db->beginTransaction();
        $db->exec("LOCK TABLES blocks WRITE, accounts WRITE, transactions WRITE, mempool WRITE");
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
        $trx = new Transaction;
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
            "SELECT public_key, signature FROM transactions WHERE  version=0 AND block=:block",
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
