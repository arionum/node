<?php

class Account
{
    // inserts the account in the DB and updates the public key if empty
    public function add($public_key, $block)
    {
        global $db;
        $id = $this->get_address($public_key);
        $bind = [":id" => $id, ":public_key" => $public_key, ":block" => $block, ":public_key2" => $public_key];

        $db->run(
            "INSERT INTO accounts SET id=:id, public_key=:public_key, block=:block, balance=0 ON DUPLICATE KEY UPDATE public_key=if(public_key='',:public_key2,public_key)",
            $bind
        );
    }

    // inserts just the account without public key
    public function add_id($id, $block)
    {
        global $db;
        $bind = [":id" => $id, ":block" => $block];
        $db->run("INSERT ignore INTO accounts SET id=:id, public_key='', block=:block, balance=0", $bind);
    }

    // generates Account's address from the public key
    public function get_address($hash)
    {
        //broken base58 addresses, which are block winners, missing the first 0 bytes from the address.
        if ($hash == 'PZ8Tyr4Nx8MHsRAGMpZmZ6TWY63dXWSCwCpspGFGQSaF9yVGLamBgymdf8M7FafghmP3oPzQb3W4PZsZApVa41uQrrHRVBH5p9bdoz7c6XeRQHK2TkzWR45e') {
            return '22SoB29oyq2JhMxtBbesL7JioEYytyC6VeFmzvBH6fRQrueSvyZfEXR5oR7ajSQ9mLERn6JKU85EAbVDNChke32';
        } elseif ($hash == 'PZ8Tyr4Nx8MHsRAGMpZmZ6TWY63dXWSCzbRyyz5oDNDKhk5jyjg4caRjkbqegMZMrUkuBjVMuYcVfPyc3aKuLmPHS4QEDjCrNGks7Z5oPxwv4yXSv7WJnkbL') {
            return 'AoFnv3SLujrJSa2J7FDTADGD7Eb9kv3KtNAp7YVYQEUPcLE6cC6nLvvhVqcVnRLYF5BFF38C1DyunUtmfJBhyU';
        } elseif ($hash == 'PZ8Tyr4Nx8MHsRAGMpZmZ6TWY63dXWSCyradtFFJoaYB4QdcXyBGSXjiASMMnofsT4f5ZNaxTnNDJt91ubemn3LzgKrfQh8CBpqaphkVNoRLub2ctdMnrzG1') {
            return 'RncXQuc7S7aWkvTUJSHEFvYoV3ntAf7bfxEHjSiZNBvQV37MzZtg44L7GAV7szZ3uV8qWqikBewa3piZMqzBqm';
        } elseif ($hash == 'PZ8Tyr4Nx8MHsRAGMpZmZ6TWY63dXWSCyjKMBY4ihhJ2G25EVezg7KnoCBVbhdvWfqzNA4LC5R7wgu3VNfJgvqkCq9sKKZcCoCpX6Qr9cN882MoXsfGTvZoj') {
            return 'Rq53oLzpCrb4BdJZ1jqQ2zsixV2ukxVdM4H9uvUhCGJCz1q2wagvuXV4hC6UVwK7HqAt1FenukzhVXgzyG1y32';
        }

        // hashes 9 times in sha512 (binary) and encodes in base58
        for ($i = 0; $i < 9;
             $i++) {
            $hash = hash('sha512', $hash, true);
        }
        return base58_encode($hash);
    }

    // checks the ecdsa secp256k1 signature for a specific public key
    public function check_signature($data, $signature, $public_key)
    {
        return ec_verify($data, $signature, $public_key);
    }

    // generates a new account and a public/private key pair
    public function generate_account()
    {
        // using secp256k1 curve for ECDSA
        $args = [
            "curve_name"       => "secp256k1",
            "private_key_type" => OPENSSL_KEYTYPE_EC,
        ];

        // generates a new key pair
        $key1 = openssl_pkey_new($args);

        // exports the private key encoded as PEM
        openssl_pkey_export($key1, $pvkey);

        // converts the PEM to a base58 format
        $private_key = pem2coin($pvkey);

        // exports the private key encoded as PEM
        $pub = openssl_pkey_get_details($key1);

        // converts the PEM to a base58 format
        $public_key = pem2coin($pub['key']);

        // generates the account's address based on the public key
        $address = $this->get_address($public_key);
        return ["address" => $address, "public_key" => $public_key, "private_key" => $private_key];
    }

    // check the validity of a base58 encoded key. At the moment, it checks only the characters to be base58.
    public function valid_key($id)
    {
        $chars = str_split("123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz");
        for ($i = 0; $i < strlen($id);
             $i++) {
            if (!in_array($id[$i], $chars)) {
                return false;
            }
        }

        return true;
    }

    // check the validity of an address. At the moment, it checks only the characters to be base58 and the length to be >=70 and <=128.
    public function valid($id)
    {
        if (strlen($id) < 70 || strlen($id) > 128) {
            return false;
        }
        $chars = str_split("123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz");
        for ($i = 0; $i < strlen($id);
             $i++) {
            if (!in_array($id[$i], $chars)) {
                return false;
            }
        }

        return true;
    }

    // returns the current account balance
    public function balance($id)
    {
        global $db;
        $res = $db->single("SELECT balance FROM accounts WHERE id=:id", [":id" => $id]);
        if ($res === false) {
            $res = "0.00000000";
        }

        return number_format($res, 8, ".", "");
    }

    // returns the account balance - any pending debits from the mempool
    public function pending_balance($id)
    {
        global $db;
        $res = $db->single("SELECT balance FROM accounts WHERE id=:id", [":id" => $id]);
        if ($res === false) {
            $res = "0.00000000";
        }

        // if the original balance is 0, no mempool transactions are possible
        if ($res == "0.00000000") {
            return $res;
        }
        $mem = $db->single("SELECT SUM(val+fee) FROM mempool WHERE src=:id", [":id" => $id]);
        $rez = $res - $mem;
        return number_format($rez, 8, ".", "");
    }

    // returns all the transactions of a specific address
    public function get_transactions($id, $limit = 100)
    {
        global $db;
        $block = new Block();
        $current = $block->current();
        $public_key = $this->public_key($id);
        $limit = intval($limit);
        if ($limit > 100 || $limit < 1) {
            $limit = 100;
        }
        $res = $db->run(
            "SELECT * FROM transactions WHERE dst=:dst or public_key=:src ORDER by height DESC LIMIT :limit",
            [":src" => $public_key, ":dst" => $id, ":limit" => $limit]
        );

        $transactions = [];
        foreach ($res as $x) {
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
            $trans['src'] = $this->get_address($x['public_key']);
            $trans['confirmations'] = $current['height'] - $x['height'];

            // version 0 -> reward transaction, version 1 -> normal transaction
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
            $transactions[] = $trans;
        }

        return $transactions;
    }

    // returns the transactions from the mempool
    public function get_mempool_transactions($id)
    {
        global $db;
        $transactions = [];
        $res = $db->run(
            "SELECT * FROM mempool WHERE src=:src ORDER by height DESC LIMIT 100",
            [":src" => $id, ":dst" => $id]
        );
        foreach ($res as $x) {
            $trans = [
                "block"      => $x['block'],
                "height"     => $x['height'],
                "id"         => $x['id'],
                "src"        => $x['src'],
                "dst"        => $x['dst'],
                "val"        => $x['val'],
                "fee"        => $x['fee'],
                "signature"  => $x['signature'],
                "message"    => $x['message'],
                "version"    => $x['version'],
                "date"       => $x['date'],
                "public_key" => $x['public_key'],
            ];
            $trans['type'] = "mempool";
            // they are unconfirmed, so they will have -1 confirmations.
            $trans['confirmations'] = -1;
            ksort($trans);
            $transactions[] = $trans;
        }
        return $transactions;
    }

    // returns the public key for a specific account
    public function public_key($id)
    {
        global $db;
        $res = $db->single("SELECT public_key FROM accounts WHERE id=:id", [":id" => $id]);
        return $res;
    }
}
