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
set_time_limit(360);
require_once("include/init.inc.php");
$block = new Block();

$type = san($argv[1]);
$id = san($argv[2]);
$debug = false;
$linear = false;
// if debug mode, all data is printed to console, no background processes
if (trim($argv[5]) == 'debug') {
    $debug = true;
}
if (trim($argv[5]) == 'linear') {
    $linear = true;
}
$peer = san(trim($argv[3]));


// broadcasting a block to all peers
if ((empty($peer) || $peer == 'all') && $type == "block") {
    $whr = "";
    if ($id == "current") {
        $current = $block->current();
        $id = $current['id'];
    }
    $data = $block->export($id);
    $id = san($id);
    if ($data === false || empty($data)) {
        die("Could not export block");
    }
    $data = json_encode($data);
    // cache it to reduce the load
    $res = file_put_contents("tmp/$id", $data);
    if ($res === false) {
        die("Could not write the cache file");
    }
    // broadcasting to all peers
    $ewhr = "";
    // boradcasting to only certain peers
    if ($linear == true) {
        $ewhr = " ORDER by RAND() LIMIT 5";
    }
    $r = $db->run("SELECT * FROM peers WHERE blacklisted < UNIX_TIMESTAMP() AND reserve=0 $ewhr");
    foreach ($r as $x) {
        // encode the hostname in base58 and sanitize the IP to avoid any second order shell injections
        $host = base58_encode($x['hostname']);
        $ip = filter_var($x['ip'], FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
        // fork a new process to send the blocks async
        if ($debug) {
            system("php propagate.php '$type' '$id' '$host' '$ip' debug");
        } elseif ($linear) {
            system("php propagate.php '$type' '$id' '$host' '$ip' linear");
        } else {
            system("php propagate.php '$type' '$id' '$host' 'ip'  > /dev/null 2>&1  &");
        }
    }
    exit;
}


// broadcast a block to a single peer (usually a forked process from above)
if ($type == "block") {
    // current block or read cache
    if ($id == "current") {
        $current = $block->current();
        $data = $block->export($current['id']);
        if (!$data) {
            echo "Invalid Block data";
            exit;
        }
    } else {
        $data = file_get_contents("tmp/$id");
        if (empty($data)) {
            echo "Invalid Block data";
            exit;
        }
        $data = json_decode($data, true);
    }
    $hostname = base58_decode($peer);
    // send the block as POST to the peer
    echo "Block sent to $hostname:\n";
    $response = peer_post($hostname."/peer.php?q=submitBlock", $data, 60, $debug);
    if ($response == "block-ok") {
        echo "Block $i accepted. Exiting.\n";
        exit;
    } elseif ($response['request'] == "microsync") {
        // the peer requested us to send more blocks, as it's behind
        echo "Microsync request\n";
        $height = intval($response['height']);
        $bl = san($response['block']);
        $current = $block->current();
        // maximum microsync is 10 blocks, for more, the peer should sync by sanity
        if ($current['height'] - $height > 10) {
            echo "Height Differece too high\n";
            exit;
        }
        $last_block = $block->get($height);
        // if their last block does not match our blockchain/fork, ignore the request
        if ($last_block['id'] != $bl) {
            echo "Last block does not match\n";
            exit;
        }
        echo "Sending the requested blocks\n";
        //start sending the requested block
        for ($i = $height + 1; $i <= $current['height']; $i++) {
            $data = $block->export("", $i);
            $response = peer_post($hostname."/peer.php?q=submitBlock", $data, 60, $debug);
            if ($response != "block-ok") {
                echo "Block $i not accepted. Exiting.\n";
                exit;
            }
            echo "Block\t$i\t accepted\n";
        }
    } elseif ($response == "reverse-microsanity") {
        // the peer informe us that we should run a microsanity
        echo "Running microsanity\n";
        $ip = trim($argv[4]);
        $ip = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
        if (empty($ip)) {
            die("Invalid IP");
        }
        // fork a microsanity in a new process
        system("php sanity.php microsanity '$ip'  > /dev/null 2>&1  &");
    } else {
        echo "Block not accepted!\n";
    }
}
// broadcast a transaction to some peers
if ($type == "transaction") {
    $trx = new Transaction;
    // get the transaction data
    $data = $trx->export($id);

    if (!$data) {
        echo "Invalid transaction id\n";
        exit;
    }
    // if the transaction was first sent locally, we will send it to all our peers, otherwise to just a few
    if ($data['peer'] == "local") {
        $r = $db->run("SELECT hostname FROM peers WHERE blacklisted < UNIX_TIMESTAMP()");
    } else {
        $r = $db->run("SELECT hostname FROM peers WHERE blacklisted < UNIX_TIMESTAMP() AND reserve=0  ORDER by RAND() LIMIT ".intval($_config['transaction_propagation_peers']));
    }
    foreach ($r as $x) {
        $res = peer_post($x['hostname']."/peer.php?q=submitTransaction", $data);
        if (!$res) {
            echo "Transaction not accepted\n";
        } else {
            echo "Transaction accepted\n";
        }
    }
}
