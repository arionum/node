<?php

class Transaction
{
    // reverse and remove all transactions from a block
    public function reverse($block)
    {
        global $db;
        $acc = new Account();
        $r = $db->run("SELECT * FROM transactions WHERE block=:block", [":block" => $block]);
        foreach ($r as $x) {
            if (empty($x['src'])) {
                $x['src'] = $acc->get_address($x['public_key']);
            }
            $db->run(
                "UPDATE accounts SET balance=balance-:val WHERE id=:id",
                [":id" => $x['dst'], ":val" => $x['val']]
            );

            // on version 0 / reward transaction, don't credit anyone
            if ($x['version'] > 0) {
                $db->run(
                    "UPDATE accounts SET balance=balance+:val WHERE id=:id",
                    [":id" => $x['src'], ":val" => $x['val'] + $x['fee']]
                );
            }

            // add the transactions to mempool
            if ($x['version'] > 0) {
                $this->add_mempool($x);
            }
            $res = $db->run("DELETE FROM transactions WHERE id=:id", [":id" => $x['id']]);
            if ($res != 1) {
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
        $block = new Block();
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
        $acc->add_id($x['dst'], $block);
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
        $db->run("UPDATE accounts SET balance=balance+:val WHERE id=:id", [":id" => $x['dst'], ":val" => $x['val']]);
        // no debit when the transaction is reward
        if ($x['version'] > 0) {
            $db->run(
                "UPDATE accounts SET balance=(balance-:val)-:fee WHERE id=:id",
                [":id" => $x['src'], ":val" => $x['val'], ":fee" => $x['fee']]
            );
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

        // the value must be >=0
        if ($x['val'] < 0) {
            _log("$x[id] - Value below 0");
            return false;
        }

        // the fee must be >=0
        if ($x['fee'] < 0) {
            _log("$x[id] - Fee below 0");
            return false;
        }

        // the fee is 0.25%, hardcoded
        $fee = $x['val'] * 0.0025;
        $fee = number_format($fee, 8, ".", "");
        if ($fee < 0.00000001) {
            $fee = 0.00000001;
        }
        // max fee after block 10800 is 10
        if ($height > 10800 && $fee > 10) {
            $fee = 10; //10800
        }
        // added fee does not match
        if ($fee != $x['fee']) {
            _log("$x[id] - Fee not 0.25%");
            return false;
        }

        // invalid destination address
        if (!$acc->valid($x['dst'])) {
            _log("$x[id] - Invalid destination address");
            return false;
        }

        // reward transactions are not added via this function
        if ($x['version'] < 1) {
            _log("$x[id] - Invalid version <1");
            return false;
        }
        //if($x['version']>1) { _log("$x[id] - Invalid version >1"); return false; }

        // public key must be at least 15 chars / probably should be replaced with the validator function
        if (strlen($x['public_key']) < 15) {
            _log("$x[id] - Invalid public key size");
            return false;
        }
        // no transactions before the genesis
        if ($x['date'] < 1511725068) {
            _log("$x[id] - Date before genesis");
            return false;
        }
        // no future transactions
        if ($x['date'] > time() + 86400) {
            _log("$x[id] - Date in the future");
            return false;
        }
        // prevent the resending of broken base58 transactions
        if ($height > 16900 && $x['date'] < 1519327780) {
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
            _log("$x[id] - Invalid signature");
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
        } elseif ($x['version'] == 1) {
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
    public function get_transactions($height = "", $id = "")
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
        if (!empty($id)) {
            $r = $db->run("SELECT * FROM transactions WHERE block=:id AND version>0", [":id" => $id]);
        } else {
            $r = $db->run("SELECT * FROM transactions WHERE height=:height AND version>0", [":height" => $height]);
        }
        $res = [];
        foreach ($r as $x) {
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
            } elseif ($x['version'] == 1) {
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
