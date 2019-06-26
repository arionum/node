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

// make sure it's not accessible in the browser
if (php_sapi_name() !== 'cli') {
    die("This should only be run as cli");
}

require_once __DIR__.'/include/init.inc.php';
$cmd = trim($argv[1]);

/**
 * @api {php util.php} clean Clean
 * @apiName clean
 * @apiGroup UTIL
 * @apiDescription Cleans the entire database
 *
 * @apiExample {cli} Example usage:
 * php util.php clean
 */

if ($cmd == 'clean') {
    if (file_exists("tmp/sanity-lock")) {
        die("Sanity running. Wait for it to finish");
    }
    touch("tmp/sanity-lock");
    $db->run("SET foreign_key_checks=0;");
    $tables = ["accounts", "transactions", "mempool", "masternode","blocks"];
    foreach ($tables as $table) {
        $db->run("TRUNCATE TABLE {$table}");
    }
    $db->run("SET foreign_key_checks=1;");

    echo "\n The database has been cleared\n";
    unlink("tmp/sanity-lock");
} /**
 * @api {php util.php} pop Pop
 * @apiName pop
 * @apiGroup UTIL
 * @apiDescription Cleans the entire database
 *
 * @apiParam {Number} arg2 Number of blocks to delete
 *
 * @apiExample {cli} Example usage:
 * php util.php pop 1
 */

elseif ($cmd == 'pop') {
    if (file_exists("tmp/sanity-lock")) {
        die("Sanity running. Wait for it to finish");
    }
    touch("tmp/sanity-lock");
    $no = intval($argv[2]);
    $block = new Block();
    $block->pop($no);
    unlink("tmp/sanity-lock");
} /**
 * @api {php util.php} block-time Block-time
 * @apiName block-time
 * @apiGroup UTIL
 * @apiDescription Shows the block time of the last 100 blocks
 *
 * @apiExample {cli} Example usage:
 * php util.php block-time
 *
 * @apiSuccessExample {text} Success-Response:
 * 16830 -> 323
 * ...
 * 16731 -> 302
 * Average block time: 217 seconds
 */

elseif ($cmd == 'block-time') {
    $t = time();
    $r = $db->run("SELECT * FROM blocks ORDER by height DESC LIMIT 100");
    $start = 0;
    foreach ($r as $x) {
        if ($start == 0) {
            $start = $x['date'];
        }
        $time = $t - $x['date'];
        $t = $x['date'];
        echo "$x[height]\t\t$time\t\t$x[difficulty]\n";
        $end = $x['date'];
    }
    echo "Average block time: ".ceil(($start - $end) / 100)." seconds\n";
} /**
 * @api {php util.php} peer Peer
 * @apiName peer
 * @apiGroup UTIL
 * @apiDescription Creates a peering session with another node
 *
 * @apiParam {text} arg2 The Hostname of the other node
 *
 * @apiExample {cli} Example usage:
 * php util.php peer http://peer1.arionum.com
 *
 * @apiSuccessExample {text} Success-Response:
 * Peering OK
 */


elseif ($cmd == "peer") {
    $res = peer_post($argv[2]."/peer.php?q=peer", ["hostname" => $_config['hostname']]);
    if ($res !== false) {
        echo "Peering OK\n";
    } else {
        echo "Peering FAIL\n";
    }
} /**
 * @api {php util.php} current Current
 * @apiName current
 * @apiGroup UTIL
 * @apiDescription Prints the current block in var_dump
 *
 * @apiExample {cli} Example usage:
 * php util.php current
 *
 * @apiSuccessExample {text} Success-Response:
 * array(9) {
 *  ["id"]=>
 *  string(88) "4khstc1AknzDXg8h2v12rX42vDrzBaai6Rz53mbaBsghYN4DnfPhfG7oLZS24Q92MuusdYmwvDuiZiuHHWgdELLR"
 *  ["generator"]=>
 *  string(88) "5ADfrJUnLefPsaYjMTR4KmvQ79eHo2rYWnKBRCXConYKYJVAw2adtzb38oUG5EnsXEbTct3p7GagT2VVZ9hfVTVn"
 *  ["height"]=>
 *  int(16833)
 *  ["date"]=>
 *  int(1519312385)
 *  ["nonce"]=>
 *  string(41) "EwtJ1EigKrLurlXROuuiozrR6ICervJDF2KFl4qEY"
 *  ["signature"]=>
 *  string(97) "AN1rKpqit8UYv6uvf79GnbjyihCPE1UZu4CGRx7saZ68g396yjHFmzkzuBV69Hcr7TF2egTsEwVsRA3CETiqXVqet58MCM6tu"
 *  ["difficulty"]=>
 *  string(8) "61982809"
 *  ["argon"]=>
 *  string(68) "$SfghIBNSHoOJDlMthVcUtg$WTJMrQWHHqDA6FowzaZJ+O9JC8DPZTjTxNE4Pj/ggwg"
 *  ["transactions"]=>
 *  int(0)
 * }
 *
 */

elseif ($cmd == "current") {
    $block = new Block();
    var_dump($block->current());
} /**
 * @api {php util.php} blocks Blocks
 * @apiName blocks
 * @apiGroup UTIL
 * @apiDescription Prints the id and the height of the blocks >=arg2, max 100 or arg3
 *
 * @apiParam {number} arg2 Starting height
 *
 * @apiParam {number} [arg3] Block Limit
 *
 * @apiExample {cli} Example usage:
 * php util.php blocks 10800 5
 *
 * @apiSuccessExample {text} Success-Response:
 * 10801   2yAHaZ3ghNnThaNK6BJcup2zq7EXuFsruMb5qqXaHP9M6JfBfstAag1n1PX7SMKGcuYGZddMzU7hW87S5ZSayeKX
 * 10802   wNa4mRvRPCMHzsgLdseMdJCvmeBaCNibRJCDhsuTeznJh8C1aSpGuXRDPYMbqKiVtmGAaYYb9Ze2NJdmK1HY9zM
 * 10803   3eW3B8jCFBauw8EoKN4SXgrn33UBPw7n8kvDDpyQBw1uQcmJQEzecAvwBk5sVfQxUqgzv31JdNHK45JxUFcupVot
 * 10804   4mWK1f8ch2Ji3D6aw1BsCJavLNBhQgpUHBCHihnrLDuh8Bjwsou5bQDj7D7nV4RsEPmP2ZbjUUMZwqywpRc8r6dR
 * 10805   5RBeWXo2c9NZ7UF2ubztk53PZpiA4tsk3bhXNXbcBk89cNqorNj771Qu4kthQN5hXLtu1hzUnv7nkH33hDxBM34m
 *
 */
elseif ($cmd == "blocks") {
    $height = intval($argv[2]);
    $limit = intval($argv[3]);
    if ($limit < 1) {
        $limit = 100;
    }
    $r = $db->run("SELECT * FROM blocks WHERE height>:height ORDER by height ASC LIMIT :limit", [":height" => $height, ":limit"=>$limit]);
    foreach ($r as $x) {
        echo "$x[height]\t$x[id]\n";
    }
} /**
 * @api {php util.php} recheck-blocks Recheck-Blocks
 * @apiName recheck-blocks
 * @apiGroup UTIL
 * @apiDescription Recheck all the blocks to make sure the blockchain is correct
 *
 * @apiExample {cli} Example usage:
 * php util.php recheck-blocks
 *
 */
elseif ($cmd == "recheck-blocks") {
    $blocks = [];
    $block = new Block();
    $r = $db->run("SELECT * FROM blocks ORDER by height ASC");
    foreach ($r as $x) {
        $blocks[$x['height']] = $x;
        $max_height = $x['height'];
    }
    for ($i = 2; $i <= $max_height; $i++) {
        $data = $blocks[$i];

        $key = $db->single("SELECT public_key FROM accounts WHERE id=:id", [":id" => $data['generator']]);

        if (!$block->mine(
            $key,
            $data['nonce'],
            $data['argon'],
            $data['difficulty'],
            $blocks[$i - 1]['id'],
            $blocks[$i - 1]['height'],
            $data['date']
        )) {
            _log("Invalid block detected. We should delete everything after $data[height] - $data[id]");
            break;
        }
    }
} /**
 * @api {php util.php} peers Peers
 * @apiName peers
 * @apiGroup UTIL
 * @apiDescription Prints all the peers and their status
 *
 * @apiExample {cli} Example usage:
 * php util.php peers
 *
 * @apiSuccessExample {text} Success-Response:
 * http://35.190.160.142   active
 * ...
 * http://aro.master.hashpi.com    active
 */
elseif ($cmd == "peers") {
    $r = $db->run("SELECT * FROM peers ORDER by reserve ASC");
    $status = "active";
    if ($x['reserve'] == 1) {
        $status = "reserve";
    }
    foreach ($r as $x) {
        echo "$x[hostname]\t$status\n";
    }
} /**
 * @api {php util.php} mempool Mempool
 * @apiName mempool
 * @apiGroup UTIL
 * @apiDescription Prints the number of transactions in mempool
 *
 * @apiExample {cli} Example usage:
 * php util.php mempool
 *
 * @apiSuccessExample {text} Success-Response:
 * Mempool size: 12
 */
elseif ($cmd == "mempool") {
    $res = $db->single("SELECT COUNT(1) from mempool");
    echo "Mempool size: $res\n";
} /**
 * @api {php util.php} delete-peer Delete-peer
 * @apiName delete-peer
 * @apiGroup UTIL
 * @apiDescription Removes a peer from the peerlist
 *
 * @apiParam {text} arg2 Peer's hostname
 *
 * @apiExample {cli} Example usage:
 * php util.php delete-peer http://peer1.arionum.com
 *
 * @apiSuccessExample {text} Success-Response:
 * Peer removed
 */
elseif ($cmd == "delete-peer") {
    $peer = trim($argv[2]);
    if (empty($peer)) {
        die("Invalid peer");
    }
    $db->run("DELETE FROM peers WHERE ip=:ip", [":ip" => $peer]);
    echo "Peer removed\n";
} elseif ($cmd == "recheck-peers") {
    $r = $db->run("SELECT * FROM peers");
    foreach ($r as $x) {
        $a = peer_post($x['hostname']."/peer.php?q=ping");
        if ($a != "pong") {
            echo "$x[hostname] -> failed\n";
            $db->run("DELETE FROM peers WHERE id=:id", [":id" => $x['id']]);
        } else {
            echo "$x[hostname] ->ok \n";
        }
    }
} /**
 * @api {php util.php} peers-block Peers-Block
 * @apiName peers-block
 * @apiGroup UTIL
 * @apiDescription Prints the current height of all the peers
 *
 * @apiExample {cli} Example usage:
 * php util.php peers-block
 *
 * @apiSuccessExample {text} Success-Response:
 * http://peer5.arionum.com        16849
 * ...
 * http://peer10.arionum.com        16849
 */
elseif ($cmd == "peers-block") {
    $only_diff = false;
    if ($argv[2] == "diff") {
        $current = $db->single("SELECT height FROM blocks ORDER by height DESC LIMIT 1");
        $only_diff = true;
    }
    $r = $db->run("SELECT * FROM peers WHERE blacklisted<UNIX_TIMESTAMP()");
    foreach ($r as $x) {
        $a = peer_post($x['hostname']."/peer.php?q=currentBlock", [], 5);
        $enc = base58_encode($x['hostname']);
        if ($argv[2] == "debug") {
            echo "$enc\t";
        }
        if ($only_diff == false || $current != $a['height']) {
            echo "$x[hostname]\t$a[height]\n";
        }
    }
} /**
 * @api {php util.php} balance Balance
 * @apiName balance
 * @apiGroup UTIL
 * @apiDescription Prints the balance of an address or a public key
 *
 * @apiParam {text} arg2 address or public_key
 *
 * @apiExample {cli} Example usage:
 * php util.php balance 5WuRMXGM7Pf8NqEArVz1NxgSBptkimSpvuSaYC79g1yo3RDQc8TjVtGH5chQWQV7CHbJEuq9DmW5fbmCEW4AghQr
 *
 * @apiSuccessExample {text} Success-Response:
 * Balance: 2,487
 */

elseif ($cmd == "balance") {
    $id = san($argv[2]);
    $res = $db->single(
        "SELECT balance FROM accounts WHERE id=:id OR public_key=:id2 LIMIT 1",
        [":id" => $id, ":id2" => $id]
    );

    echo "Balance: ".number_format($res)."\n";
} /**
 * @api {php util.php} block Block
 * @apiName block
 * @apiGroup UTIL
 * @apiDescription Returns a specific block
 *
 * @apiParam {text} arg2 block id
 *
 * @apiExample {cli} Example usage:
 * php util.php block 4khstc1AknzDXg8h2v12rX42vDrzBaai6Rz53mbaBsghYN4DnfPhfG7oLZS24Q92MuusdYmwvDuiZiuHHWgdELLR
 *
 * @apiSuccessExample {text} Success-Response:
 * array(9) {
 *  ["id"]=>
 *  string(88) "4khstc1AknzDXg8h2v12rX42vDrzBaai6Rz53mbaBsghYN4DnfPhfG7oLZS24Q92MuusdYmwvDuiZiuHHWgdELLR"
 *  ["generator"]=>
 *  string(88) "5ADfrJUnLefPsaYjMTR4KmvQ79eHo2rYWnKBRCXConYKYJVAw2adtzb38oUG5EnsXEbTct3p7GagT2VVZ9hfVTVn"
 *  ["height"]=>
 *  int(16833)
 *  ["date"]=>
 *  int(1519312385)
 *  ["nonce"]=>
 *  string(41) "EwtJ1EigKrLurlXROuuiozrR6ICervJDF2KFl4qEY"
 *  ["signature"]=>
 *  string(97) "AN1rKpqit8UYv6uvf79GnbjyihCPE1UZu4CGRx7saZ68g396yjHFmzkzuBV69Hcr7TF2egTsEwVsRA3CETiqXVqet58MCM6tu"
 *  ["difficulty"]=>
 *  string(8) "61982809"
 *  ["argon"]=>
 *  string(68) "$SfghIBNSHoOJDlMthVcUtg$WTJMrQWHHqDA6FowzaZJ+O9JC8DPZTjTxNE4Pj/ggwg"
 *  ["transactions"]=>
 *  int(0)
 * }
 */
elseif ($cmd == "block") {
    $id = san($argv[2]);
    $res = $db->row("SELECT * FROM blocks WHERE id=:id OR height=:id2 LIMIT 1", [":id" => $id, ":id2" => $id]);

    var_dump($res);
} /**
 * @api {php util.php} check-address Check-Address
 * @apiName check-address
 * @apiGroup UTIL
 * @apiDescription Checks a specific address for validity
 *
 * @apiParam {text} arg2 block id
 *
 * @apiExample {cli} Example usage:
 * php util.php check-address 4khstc1AknzDXg8h2v12rX42vDrzBaai6Rz53mbaBsghYN4DnfPhfG7oLZS24Q92MuusdYmwvDuiZiuHHWgdELLR
 *
 * @apiSuccessExample {text} Success-Response:
 * The address is valid
 */
elseif ($cmd == "check-address") {
    $dst = trim($argv[2]);
    $acc = new Account();
    if (!$acc->valid($dst)) {
        die("Invalid address");
    }
    $dst_b = base58_decode($dst);
    if (strlen($dst_b) != 64) {
        die("Invalid address - ".strlen($dst_b)." bytes");
    }

    echo "The address is valid\n";
}
/**
 * @api {php util.php} get-address Get-Address
 * @apiName get-address
 * @apiGroup UTIL
 * @apiDescription Converts a public key into an address
 *
 * @apiParam {text} arg2 public key
 *
 * @apiExample {cli} Example usage:
 * php util.php get-address PZ8Tyr4Nx8MHsRAGMpZmZ6TWY63dXWSCwQr8cE5s6APWAE1SWAmH6NM1nJTryBURULEsifA2hLVuW5GXFD1XU6s6REG1iPK7qGaRDkGpQwJjDhQKVoSVkSNp
 *
 * @apiSuccessExample {text} Success-Response:
 * 5WuRMXGM7Pf8NqEArVz1NxgSBptkimSpvuSaYC79g1yo3RDQc8TjVtGH5chQWQV7CHbJEuq9DmW5fbmCEW4AghQr
 */

elseif ($cmd == 'get-address') {
    $public_key = trim($argv2);
    if (strlen($public_key) < 32) {
        die("Invalid public key");
    }
    print($acc->get_address($public_key));
/**
 * @api {php util.php} clean-blacklist Clean-Blacklist
 * @apiName clean-blacklist
 * @apiGroup UTIL
 * @apiDescription Removes all the peers from blacklist
 *
 * @apiExample {cli} Example usage:
 * php util.php clean-blacklist
 *
 */
} elseif ($cmd == 'clean-blacklist') {
    $db->run("UPDATE peers SET blacklisted=0, fails=0, stuckfail=0");
    echo "All the peers have been removed from the blacklist\n";
} elseif ($cmd == 'resync-accounts') {
    // resyncs the balance on all accounts
    if (file_exists("tmp/sanity-lock")) {
        die("Sanity running. Wait for it to finish");
    }
    touch("tmp/sanity-lock");
    // lock table to avoid race conditions on blocks
    $db->exec("LOCK TABLES blocks WRITE, accounts WRITE, transactions WRITE, mempool WRITE");

    $r=$db->run("SELECT * FROM accounts");
    foreach ($r as $x) {
        $alias=$x['alias'];
        if (empty($alias)) {
            $alias="A";
        }
        $rec=$db->single("SELECT SUM(val) FROM transactions WHERE (dst=:id or dst=:alias) AND (height<80000 OR version!=100) and version<111", [":id"=>$x['id'], ":alias"=>$alias]);
        $releases=$db->single("SELECT COUNT(1) FROM transactions WHERE dst=:id AND version=103", [":id"=>$x['id']]);
        if($releases>0){ //masternode releases
                $rec+=$releases*100000;
        }
        $spent=$db->single("SELECT SUM(val+fee) FROM transactions WHERE public_key=:pub AND version>0", [":pub"=>$x['public_key']]);
        if ($spent==false) {
            $spent=0;
        }
        $balance=round(($rec-$spent), 8);
        if ($x['balance']!=$balance) {
            echo "rec: $rec, spent: $spent, bal: $x[balance], should be: $balance - $x[id] $x[public_key]\n";
            if (trim($argv[2])!="check") {
                $db->run("UPDATE accounts SET balance=:bal WHERE id=:id", [":id"=>$x['id'], ":bal"=>$balance]);
            }
        }
    }
    $db->exec("UNLOCK TABLES");
    echo "All done";
    unlink("tmp/sanity-lock");
} elseif ($cmd=="compare-blocks") {
    $block=new Block();

    $current=$block->current();
    $peer=trim($argv[2]);
    $limit=intval($argv[3]);
    if ($limit==0) {
        $limit=5000;
    }
    for ($i=$current['height']-$limit;$i<=$current['height'];$i++) {
        $data=peer_post($peer."/peer.php?q=getBlock", ["height" => $i]);
        if ($data==false) {
            continue;
        }
        $our=$block->export(false, $i);
        if ($data!=$our) {
            echo "Failed block -> $i\n";
            if ($argv[4]=="dump") {
                echo "\n\n  ---- Internal ----\n\n";
                var_dump($our);
                echo "\n\n  ---- External ----\n\n";
                var_dump($data);
            }
        }
    }
} elseif ($cmd=='compare-accounts') {
    $peer=trim($argv[2]);
    $r=$db->run("SELECT id,balance FROM accounts");
    foreach ($r as $x) {
        $data=peer_post($peer."/api.php?q=getBalance", ["account" => $x['id']]);
        if ($data==false) {
            continue;
        }
        if ($data!=$x['balance']) {
            echo "$x[id]\t\t$x[balance]\t$data\n";
        }
    }
} elseif ($cmd=='masternode-hash') {
    $res=$db->run("SELECT * FROM masternode ORDER by public_key ASC");
    $block=new Block();
    $current=$block->current();
    echo "Height:\t\t$current[height]\n";
    echo "Hash:\t\t".md5(json_encode($res))."\n\n";
} elseif ($cmd=='accounts-hash') {
    $res=$db->run("SELECT * FROM accounts ORDER by id ASC");
    $block=new Block();
    $current=$block->current();
    echo "Height:\t\t$current[height]\n";
    echo "Hash:\t\t".md5(json_encode($res))."\n\n";
} elseif ($cmd == "version") {
    echo "\n\n".VERSION."\n\n";
} elseif ($cmd == "sendblock"){
    $peer=trim($argv[3]);
    if (!filter_var($peer, FILTER_VALIDATE_URL)) {
        die("Invalid peer hostname");
    }
    $peer = filter_var($peer, FILTER_SANITIZE_URL);
    $height=intval($argv[2]);
    $block=new Block();
    $data = $block->export("", $height);

    
    if($data===false){
        die("Could not find this block");
    }
    $response = peer_post($peer."/peer.php?q=submitBlock", $data, 60, true);
    var_dump($response);

}elseif ($cmd == "recheck-external-blocks") {
    $peer=trim($argv[2]);
    if (!filter_var($peer, FILTER_VALIDATE_URL)) {
        die("Invalid peer hostname");
    }
    $peer = filter_var($peer, FILTER_SANITIZE_URL);


    $blocks = [];
    $block = new Block();
    $height=intval($argv[3]);

    $last=peer_post($peer."/peer.php?q=currentBlock");

    $b=peer_post($peer."/peer.php?q=getBlock",["height"=>$height]);

    for ($i = $height+1; $i <= $last['height']; $i++) {
        $c=peer_post($peer."/peer.php?q=getBlock",["height"=>$i]);

        if (!$block->mine(
            $c['public_key'],
            $c['nonce'],
            $c['argon'],
            $c['difficulty'],
            $b['id'],
            $b['height'],
            $c['date']
        )) {
            print("Invalid block detected. $c[height] - $c[id]\n");
            break;
        } 
        echo "Block $i -> ok\n";
        $b=$c;
    }


} else {
    echo "Invalid command\n";
}
