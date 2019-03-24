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

const SANITY_LOCK_PATH = __DIR__.'/tmp/sanity-lock';

set_time_limit(0);
error_reporting(0);

// make sure it's not accessible in the browser
if (php_sapi_name() !== 'cli') {
    die("This should only be run as cli");
}

require_once __DIR__.'/include/init.inc.php';

// make sure there's only a single sanity process running at the same time
if (file_exists(SANITY_LOCK_PATH)) {
    $ignore_lock = false;
    if ($argv[1] == "force") {
        $res = intval(shell_exec("ps aux|grep sanity.php|grep -v grep|wc -l"));
        if ($res == 1) {
            $ignore_lock = true;
        }
    }
    $pid_time = filemtime(SANITY_LOCK_PATH);

    // If the process died, restart after 10 times the sanity interval
    if (time() - $pid_time > ($_config['sanity_interval'] ?? 900 * 10)) {
        @unlink(SANITY_LOCK_PATH);
    }

    if (!$ignore_lock) {
        die("Sanity lock in place".PHP_EOL);
    }
}

// set the new sanity lock
$lock = fopen(SANITY_LOCK_PATH, "w");
fclose($lock);
$arg = trim($argv[1]);
$arg2 = trim($argv[2]);
echo "Sleeping for 3 seconds\n";
// sleep for 3 seconds to make sure there's a delay between starting the sanity and other processes
if ($arg != "microsanity") {
    sleep(3);
}

if ($argv[1]=="dev") {
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT & ~E_NOTICE);
    ini_set("display_errors", "on");
}

// the sanity can't run without the schema being installed
if ($_config['dbversion'] < 2) {
    die("DB schema not created");
    @unlink(SANITY_LOCK_PATH);
    exit;
}

ini_set('memory_limit', '2G');

$block = new Block();
$acc = new Account();
$current = $block->current();






// bootstrapping the initial sync
if ($current['height']==1) {
    echo "Bootstrapping!\n";
    $last=file_get_contents("http://dumps.arionum.com/last");
    $last=intval($last);
    $failed_sync=false;
    for ($i=1000;$i<=$last;$i=$i+1000) {
        echo "Download file $i\n";
        $res=trim(file_get_contents("http://dumps.arionum.com/aro.db.$i"));
        if ($res===false) {
            echo "Could not download the bootstrap file $i. Syncing the old fashioned way.\n";
            break;
        }
        $data=json_decode($res, true);
        
        if ($data===false||is_null($data)) {
            echo "Could not parse the bootstrap file $i. Syncing the old fashioned way.\n";
            echo json_last_error_msg();
            break;
        }
        foreach ($data as $x) {
            if (count($x['data'])>0) {
                $transactions=[];
                
                foreach ($x['data'] as $d) {
                    $trans = [
                            "id"         => $d[0],
                            "dst"        => $d[1],
                            "val"        => $d[2],
                            "fee"        => $d[3],
                            "signature"  => $d[4],
                            "message"    => $d[5],
                            "version"    => $d[6],
                            "date"       => $d[7],
                            "public_key" => $d[8],
                        ];
                    ksort($trans);
                    $transactions[$d[0]] = $trans;
                }
                ksort($transactions);
                $x['data']=$transactions;
            }
            
        
            echo "-> Adding block $x[height]\n";

            $res=$block->add($x['height'], $x['public_key'], $x['nonce'], $x['data'], $x['date'], $x['signature'], $x['difficulty'], $x['reward_signature'], $x['argon'], true);
            if (!$res) {
                echo "Error: Adding the block failed. Syncing the old way.\n";
                $failed_sync=true;
                break;
            }
        }
        if ($failed_sync) {
            break;
        }
    }
    
    $current = $block->current();
}
// the microsanity process is an anti-fork measure that will determine the best blockchain to choose for the last block
$microsanity = false;
if ($arg == "microsanity" && !empty($arg2)) {
    do {
        // the microsanity runs only against 1 specific peer
        $x = $db->row(
            "SELECT id,hostname FROM peers WHERE reserve=0 AND blacklisted<UNIX_TIMESTAMP() AND ip=:ip",
            [":ip" => $arg2]
        );

        if (!$x) {
            echo "Invalid node - $arg2\n";
            break;
        }
        $url = $x['hostname']."/peer.php?q=";
        $data = peer_post($url."getBlock", ["height" => $current['height']]);

        if (!$data) {
            echo "Invalid getBlock result\n";
            break;
        }
        $data['id'] = san($data['id']);
        $data['height'] = san($data['height']);
        // nothing to be done, same blockchain
        if ($data['id'] == $current['id']) {
            echo "Same block\n";
            break;
        }

        // the blockchain with the most transactions wins the fork (to encourage the miners to include as many transactions as possible) / might backfire on garbage

        // transform the first 12 chars into an integer and choose the blockchain with the biggest value
        $no1 = hexdec(substr(coin2hex($current['id']), 0, 12));
        $no2 = hexdec(substr(coin2hex($data['id']), 0, 12));

        if (gmp_cmp($no1, $no2) != -1) {
            echo "Block hex larger than current\n";
            break;
        }
        
        // make sure the block is valid
        $prev = $block->get($current['height'] - 1);
        $public = $acc->public_key($data['generator']);
        if (!$block->mine(
            $public,
            $data['nonce'],
            $data['argon'],
            $block->difficulty($current['height'] - 1),
            $prev['id'],
            $prev['height'],
            $data['date']
        )) {
            echo "Invalid prev-block\n";
            break;
        }
        if (!$block->check($data)) {
            break;
        }

        // delete the last block
        $block->pop(1);


        // add the new block
        echo "Starting to sync last block from $x[hostname]\n";
        $b = $data;

        
        $res = $block->add(
            $b['height'],
            $b['public_key'],
            $b['nonce'],
            $b['data'],
            $b['date'],
            $b['signature'],
            $b['difficulty'],
            $b['reward_signature'],
            $b['argon']
        );
        if (!$res) {
            _log("Block add: could not add block - $b[id] - $b[height]");

            break;
        }

        _log("Synced block from $host - $b[height] $b[difficulty]");
    } while (0);

    @unlink(SANITY_LOCK_PATH);
    exit;
}


$t = time();
//if($t-$_config['sanity_last']<300) {@unlink("tmp/sanity-lock");  die("The sanity cron was already run recently"); }

_log("Starting sanity");

// update the last time sanity ran, to set the execution of the next run
$db->run("UPDATE config SET val=:time WHERE cfg='sanity_last'", [":time" => $t]);
$block_peers = [];
$longest_size = 0;
$longest = 0;
$blocks = [];
$blocks_count = [];
$most_common = "";
$most_common_size = 0;
$total_active_peers = 0;

// checking peers

// delete the dead peers
$db->run("DELETE from peers WHERE fails>100 OR stuckfail>100");
$r = $db->run("SELECT id,hostname,stuckfail,fails FROM peers WHERE reserve=0 AND blacklisted<UNIX_TIMESTAMP() LIMIT 50");

$total_peers = count($r);

$peered = [];
// if we have no peers, get the seed list from the official site
if ($total_peers == 0 && $_config['testnet'] == false) {
    $i = 0;
    echo 'No peers found. Attempting to get peers from the initial list.'.PHP_EOL;

    $initialPeers = new \Arionum\Node\InitialPeers($_config['initial_peer_list'] ?? []);

    try {
        $peers = $initialPeers->getAll();
    } catch (\Arionum\Node\Exception $e) {
        @unlink(SANITY_LOCK_PATH);
        die($e->getMessage().PHP_EOL);
    }

    foreach ($peers as $peer) {
        // Peer with all until max_peers
        // This will ask them to send a peering request to our peer.php where we add their peer to the db.
        $peer = trim(san_host($peer));
        $bad_peers = ["127.", "localhost", "10.", "192.168.","172.16.","172.17.","172.18.","172.19.","172.20.","172.21.","172.22.","172.23.","172.24.","172.25.","172.26.","172.27.","172.28.","172.29.","172.30.","172.31."];

        $tpeer=str_replace(["https://","http://","//"], "", $peer);
        foreach ($bad_peers as $bp) {
            if (strpos($tpeer, $bp)===0) {
                continue;
            }
        }

        $peer = filter_var($peer, FILTER_SANITIZE_URL);
        if (!filter_var($peer, FILTER_VALIDATE_URL)) {
            continue;
        }
        // store the hostname as md5 hash, for easier checking
        $pid = md5($peer);
        // do not peer if we are already peered
        if ($peered[$pid] == 1) {
            continue;
        }
        $peered[$pid] = 1;
        
        if($_config['passive_peering'] == true){
            // does not peer, just add it to DB in passive mode
            $db->run("INSERT into peers set hostname=:hostname, ping=0, reserve=0,ip=:ip",[":hostname"=>$peer, ":ip"=>md5($peer)]);
            $res=true;
        } else {
            // forces the other node to peer with us.
            $res = peer_post($peer."/peer.php?q=peer", ["hostname" => $_config['hostname'], "repeer" => 1]);
        }
        if ($res !== false) {
            $i++;
            echo "Peering OK - $peer\n";
        } else {
            echo "Peering FAIL - $peer\n";
        }
        if ($i > $_config['max_peers']) {
            break;
        }
    }
    // count the total peers we have
    $r = $db->run("SELECT id,hostname FROM peers WHERE reserve=0 AND blacklisted<UNIX_TIMESTAMP()");
    $total_peers = count($r);
    if ($total_peers == 0) {
        // something went wrong, could not add any peers -> exit
        @unlink(SANITY_LOCK_PATH);
        die("Could not peer to any peers! Please check internet connectivity!\n");
    }
}

// contact all the active peers
 $i = 0;
foreach ($r as $x) {
    _log("Contacting peer $x[hostname]");
    $url = $x['hostname']."/peer.php?q=";
    // get their peers list
    if ($_config['get_more_peers']==true && $_config['passive_peering']!=true) {
        $data = peer_post($url."getPeers", [], 5);
        if ($data === false) {
            _log("Peer $x[hostname] unresponsive");
            // if the peer is unresponsive, mark it as failed and blacklist it for a while
            $db->run(
            "UPDATE peers SET fails=fails+1, blacklisted=UNIX_TIMESTAMP()+((fails+1)*3600) WHERE id=:id",
            [":id" => $x['id']]
        );
            continue;
        }
        foreach ($data as $peer) {
            // store the hostname as md5 hash, for easier checking
            $peer['hostname'] = san_host($peer['hostname']);
            $peer['ip'] = san_ip($peer['ip']);
            $pid = md5($peer['hostname']);
            // do not peer if we are already peered
            if ($peered[$pid] == 1) {
                continue;
            }
            $peered[$pid] = 1;
            $bad_peers = ["127.", "localhost", "10.", "192.168.","172.16.","172.17.","172.18.","172.19.","172.20.","172.21.","172.22.","172.23.","172.24.","172.25.","172.26.","172.27.","172.28.","172.29.","172.30.","172.31."];
            $tpeer=str_replace(["https://","http://","//"], "", $peer['hostname']);
            foreach ($bad_peers as $bp) {
                if (strpos($tpeer, $bp)===0) {
                    continue;
                }
            }
            // if it's our hostname, ignore
            if ($peer['hostname'] == $_config['hostname']) {
                continue;
            }
            // if invalid hostname, ignore
            if (!filter_var($peer['hostname'], FILTER_VALIDATE_URL)) {
                continue;
            }
            // make sure there's no peer in db with this ip or hostname
            if (!$db->single(
            "SELECT COUNT(1) FROM peers WHERE ip=:ip or hostname=:hostname",
            [":ip" => $peer['ip'], ":hostname" => $peer['hostname']]
        )) {
                $i++;
                // check a max_test_peers number of peers from each peer
                if ($i > $_config['max_test_peers']) {
                    break;
                }
                $peer['hostname'] = filter_var($peer['hostname'], FILTER_SANITIZE_URL);
                // peer with each one
                _log("Trying to peer with recommended peer: $peer[hostname]");
                $test = peer_post($peer['hostname']."/peer.php?q=peer", ["hostname" => $_config['hostname']], 5);
                if ($test !== false) {
                    $total_peers++;
                    echo "Peered with: $peer[hostname]\n";
                }
            }
        }
    }

    // get the current block and check it's blockchain
    $data = peer_post($url."currentBlock", [], 5);
    if ($data === false) {
        continue;
    }
    // peer was responsive, mark it as good
    if ($x['fails'] > 0) {
        $db->run("UPDATE peers SET fails=0 WHERE id=:id", [":id" => $x['id']]);
    }
    $data['id'] = san($data['id']);
    $data['height'] = san($data['height']);

    if ($data['height'] < $current['height'] - 500) {
        $db->run(
            "UPDATE peers SET stuckfail=stuckfail+1, blacklisted=UNIX_TIMESTAMP()+7200 WHERE id=:id",
            [":id" => $x['id']]
        );
        continue;
    } else {
        if ($x['stuckfail'] > 0) {
            $db->run("UPDATE peers SET stuckfail=0 WHERE id=:id", [":id" => $x['id']]);
        }
    }
    $total_active_peers++;
    // add the hostname and block relationship to an array
    $block_peers[$data['id']][] = $x['hostname'];
    // count the number of peers with this block id
    $blocks_count[$data['id']]++;
    // keep block data for this block id
    $blocks[$data['id']] = $data;
    // set the most common block on all peers
    if ($blocks_count[$data['id']] > $most_common_size) {
        $most_common = $data['id'];
        $most_common_size = $blocks_count[$data['id']];
    }
    // set the largest height block
    if ($data['height'] > $largest_height) {
        $largest_height = $data['height'];
        $largest_height_block = $data['id'];
    } elseif ($data['height'] == $largest_height && $data['id'] != $largest_height_block) {
        // if there are multiple blocks on the largest height, choose one with the smallest (hardest) difficulty
        if ($data['difficulty'] == $blocks[$largest_height_block]['difficulty']) {
            // if they have the same difficulty, choose if it's most common
            if ($most_common == $data['id']) {
                $largest_height = $data['height'];
                $largest_height_block = $data['id'];
            } else {
               

                    // if the blocks have the same number of transactions, choose the one with the highest derived integer from the first 12 hex characters
                $no1 = hexdec(substr(coin2hex($largest_height_block), 0, 12));
                $no2 = hexdec(substr(coin2hex($data['id']), 0, 12));
                if (gmp_cmp($no1, $no2) == 1) {
                    $largest_height = $data['height'];
                    $largest_height_block = $data['id'];
                }
            }
        } elseif ($data['difficulty'] < $blocks[$largest_height_block]['difficulty']) {
            // choose smallest (hardest) difficulty
            $largest_height = $data['height'];
            $largest_height_block = $data['id'];
        }
    }
}
echo "Most common: $most_common\n";
echo "Most common block: $most_common_size\n";
echo "Max height: $largest_height\n";
echo "Current block: $current[height]\n";
$block_parse_failed=false;
// if we're not on the largest height
if ($current['height'] < $largest_height && $largest_height > 1) {
    // start  sanity sync / block all other transactions/blocks
    $db->run("UPDATE config SET val=1 WHERE cfg='sanity_sync'");
    sleep(10);
    _log("Longest chain rule triggered - $largest_height - $largest_height_block");
    // choose the peers which have the larget height block
    $peers = $block_peers[$largest_height_block];
    shuffle($peers);
    // sync from them
    foreach ($peers as $host) {
        _log("Starting to sync from $host");
        $url = $host."/peer.php?q=";
        $data = peer_post($url."getBlock", ["height" => $current['height']], 60);
        // invalid data
        if ($data === false) {
            _log("Could not get block from $host - $current[height]");
            continue;
        }
        $data['id'] = san($data['id']);
        $data['height'] = san($data['height']);

        // if we're not on the same blockchain but the blockchain is most common with over 90% of the peers, delete the last 3 blocks and retry
        if ($data['id'] != $current['id'] && $data['id'] == $most_common && ($most_common_size / $total_active_peers) > 0.90) {
            $block->delete($current['height'] - 3);
            $current = $block->current();
            $data = peer_post($url."getBlock", ["height" => $current['height']]);

            if ($data === false) {
                _log("Could not get block from $host - $current[height]");
                break;
            }
        } elseif ($data['id'] != $current['id'] && $data['id'] != $most_common) {
            //if we're not on the same blockchain and also it's not the most common, verify all the blocks on on this blockchain starting at current-30 until current
            $invalid = false;
            $last_good = $current['height'];
            for ($i = $current['height'] - 30; $i < $current['height']; $i++) {
                $data = peer_post($url."getBlock", ["height" => $i]);
                if ($data === false) {
                    $invalid = true;
                    break;
                }
                $ext = $block->get($i);
                if ($i == $current['height'] - 30 && $ext['id'] != $data['id']) {
                    $invalid = true;
                    break;
                }

                if ($ext['id'] == $data['id']) {
                    $last_good = $i;
                }
            }
            if ($last_good==$current['height']-1) {
                $block->pop(1);
            }
            // if last 10 blocks are good, verify all the blocks
            if ($invalid == false) {
                $cblock = [];
                for ($i = $last_good; $i <= $largest_height; $i++) {
                    $data = peer_post($url."getBlock", ["height" => $i]);
                    if ($data === false) {
                        $invalid = true;
                        break;
                    }
                    $cblock[$i] = $data;
                }
                // check if the block mining data is correct
                for ($i = $last_good + 1; $i <= $largest_height; $i++) {
                    if (($i-1)%3==2&&$cblock[$i - 1]['height']<80458) {
                        continue;
                    }
                    if (!$block->mine(
                        $cblock[$i]['public_key'],
                        $cblock[$i]['nonce'],
                        $cblock[$i]['argon'],
                        $cblock[$i]['difficulty'],
                        $cblock[$i - 1]['id'],
                        $cblock[$i - 1]['height'],
                        $cblock[$i]['date']
                    )) {
                        $invalid = true;
                        break;
                    }
                }
            }
            // if the blockchain proves ok, delete until the last block
            if ($invalid == false) {
                _log("Changing fork, deleting $last_good", 1);
                $block->delete($last_good);
                $current = $block->current();
                $data = $current;
            }
        }
        // if current still doesn't match the data, something went wrong
        if ($data['id'] != $current['id']) {
            continue;
        }
        // start syncing all blocks
        while ($current['height'] < $largest_height) {
            $data = peer_post($url."getBlocks", ["height" => $current['height'] + 1]);

            if ($data === false) {
                _log("Could not get blocks from $host - height: $current[height]");
                break;
            }
            $good_peer = true;
            foreach ($data as $b) {
                $b['id'] = san($b['id']);
                $b['height'] = san($b['height']);
                
                if (!$block->check($b)) {
                    $block_parse_failed=true;
                    _log("Block check: could not add block - $b[id] - $b[height]");
                    $good_peer = false;
                    break;
                }
                $res = $block->add(
                    $b['height'],
                    $b['public_key'],
                    $b['nonce'],
                    $b['data'],
                    $b['date'],
                    $b['signature'],
                    $b['difficulty'],
                    $b['reward_signature'],
                    $b['argon']
                );
                if (!$res) {
                    $block_parse_failed=true;
                    _log("Block add: could not add block - $b[id] - $b[height]");
                    $good_peer = false;
                    break;
                }

                _log("Synced block from $host - $b[height] $b[difficulty]");
            }
            if (!$good_peer) {
                break;
            }
            $current = $block->current();
        }
        if ($good_peer) {
            break;
        }
    }

    $resyncing=false;
    if ($block_parse_failed==true&&$current['date']<time()-(3600*24)) {
        _log("Rechecking reward transactions");
        $current = $block->current();
        $rwpb=$db->single("SELECT COUNT(1) FROM transactions WHERE version=0 AND message=''");
        if ($rwpb!=$current['height']) {
            $failed=$db->single("SELECT blocks.height FROM blocks LEFT JOIN transactions ON transactions.block=blocks.id and transactions.version=0 and transactions.message='' WHERE transactions.height is NULL ORDER by blocks.height ASC LIMIT 1");
            if ($failed>1) {
                _log("Found failed block - $faield");
                $block->delete($failed);
                $block_parse_failed==false;
            }
        }
    }
    if ($block_parse_failed==true||$argv[1]=="resync") {
        $last_resync=$db->single("SELECT val FROM config WHERE cfg='last_resync'");
        if ($last_resync<time()-(3600*24)||$argv[1]=="resync") {
            if ($current['date']<time()-(3600*72)||$argv[1]=="resync") {
                $to_remove=3000;
                if (intval($argv[2])>0) {
                    $to_remove=intval($argv[2]);
                }
                _log("Removing $to_remove blocks, the blockchain is stale.");
                $block->pop(to_remove);
                $resyncing=true;
            } elseif ($current['date']<time()-(3600*24)) {
                _log("Removing 200 blocks, the blockchain is stale.");
                $block->pop(200);

                $resyncing=true;
            }
            if ($resyncing==true) {
                _log("Resyncing accounts");
                $db->run("INSERT into config SET val=UNIX_TIMESTAMP(), cfg='last_resync' ON DUPLICATE KEY UPDATE val=UNIX_TIMESTAMP()");
                $db->exec("LOCK TABLES blocks WRITE, accounts WRITE, transactions WRITE, mempool WRITE");

                $r=$db->run("SELECT * FROM accounts");
                foreach ($r as $x) {
                    $alias=$x['alias'];
                    if (empty($alias)) {
                        $alias="A";
                    }
                    $rec=$db->single("SELECT SUM(val) FROM transactions WHERE (dst=:id or dst=:alias) AND (height<80000 OR (version!=100 AND version!=103)) and version<111", [":id"=>$x['id'], ":alias"=>$alias]);
                    $spent=$db->single("SELECT SUM(val+fee) FROM transactions WHERE public_key=:pub AND version>0", [":pub"=>$x['public_key']]);
                    if ($spent==false) {
                        $spent=0;
                    }
                    $balance=round(($rec-$spent), 8);
                    if ($x['balance']!=$balance) {
                        // echo "rec: $rec, spent: $spent, bal: $x[balance], should be: $balance - $x[id] $x[public_key]\n";
                        if (trim($argv[2])!="check") {
                            $db->run("UPDATE accounts SET balance=:bal WHERE id=:id", [":id"=>$x['id'], ":bal"=>$balance]);
                        }
                    }
                }
                $current = $block->current();
                $db->run("DELETE FROM masternode WHERE height>:h", [":h"=>$current['height']]);
                $db->exec("UNLOCK TABLES");
            }
        }
    }


    $db->run("UPDATE config SET val=0 WHERE cfg='sanity_sync'", [":time" => $t]);
}


// deleting mempool transactions older than 14 days
$db->run("DELETE FROM `mempool` WHERE `date` < UNIX_TIMESTAMP()-(3600*24*14)");


//rebroadcasting local transactions
if ($_config['sanity_rebroadcast_locals'] == true && $_config['disable_repropagation'] == false) {
    $r = $db->run(
        "SELECT id FROM mempool WHERE height<:current and peer='local' order by `height` asc LIMIT 20",
        [":current" => $current['height']]
    );
    _log("Rebroadcasting local transactions - ".count($r));
    foreach ($r as $x) {
        $x['id'] = escapeshellarg(san($x['id'])); // i know it's redundant due to san(), but some people are too scared of any exec
        system("php propagate.php transaction $x[id]  > /dev/null 2>&1  &");
        $db->run(
            "UPDATE mempool SET height=:current WHERE id=:id",
            [":id" => $x['id'], ":current" => $current['height']]
        );
    }
}

//rebroadcasting transactions
if ($_config['disable_repropagation'] == false) {
    $forgotten = $current['height'] - $_config['sanity_rebroadcast_height'];
    $r1 = $db->run(
    "SELECT id FROM mempool WHERE height<:forgotten ORDER by val DESC LIMIT 10",
    [":forgotten" => $forgotten]);
    // getting some random transactions as well
    $r2 = $db->run(
    "SELECT id FROM mempool WHERE height<:forgotten ORDER by RAND() LIMIT 10",
    [":forgotten" => $forgotten]);
    $r=array_merge($r1,$r2);


    _log("Rebroadcasting external transactions - ".count($r));

    foreach ($r as $x) {
        $x['id'] = escapeshellarg(san($x['id'])); // i know it's redundant due to san(), but some people are too scared of any exec
        system("php propagate.php transaction $x[id]  > /dev/null 2>&1  &");
        $db->run("UPDATE mempool SET height=:current WHERE id=:id", [":id" => $x['id'], ":current" => $current['height']]);
    }
}

//add new peers if there aren't enough active
if ($total_peers < $_config['max_peers'] * 0.7) {
    $res = $_config['max_peers'] - $total_peers;
    $db->run("UPDATE peers SET reserve=0 WHERE reserve=1 AND blacklisted<UNIX_TIMESTAMP() LIMIT $res");
}

//random peer check
$r = $db->run("SELECT * FROM peers WHERE blacklisted<UNIX_TIMESTAMP() and reserve=1 LIMIT :limit", [":limit"=>intval($_config['max_test_peers'])]);
foreach ($r as $x) {
    $url = $x['hostname']."/peer.php?q=";
    $data = peer_post($url."ping", [], 5);
    if ($data === false) {
        $db->run(
            "UPDATE peers SET fails=fails+1, blacklisted=UNIX_TIMESTAMP()+((fails+1)*60) WHERE id=:id",
            [":id" => $x['id']]
        );
        _log("Random reserve peer test $x[hostname] -> FAILED");
    } else {
        _log("Random reserve peer test $x[hostname] -> OK");
        $db->run("UPDATE peers SET fails=0 WHERE id=:id", [":id" => $x['id']]);
    }
}

//clean tmp files
_log("Cleaning tmp files");
$f = scandir("tmp/");
$time = time();
foreach ($f as $x) {
    if (strlen($x) < 5 && substr($x, 0, 1) == ".") {
        continue;
    }
    $pid_time = filemtime("tmp/$x");
    if ($time - $pid_time > 7200) {
        @unlink("tmp/$x");
    }
}


//recheck the last blocks
if ($_config['sanity_recheck_blocks'] > 0 && $_config['testnet'] == false) {
    _log("Rechecking blocks");
    $blocks = [];
    $all_blocks_ok = true;
    $start = $current['height'] - $_config['sanity_recheck_blocks'];
    if ($start < 2) {
        $start = 2;
    }
    $r = $db->run("SELECT * FROM blocks WHERE height>=:height ORDER by height ASC", [":height" => $start]);
    foreach ($r as $x) {
        $blocks[$x['height']] = $x;
        $max_height = $x['height'];
    }

    for ($i = $start + 1; $i <= $max_height; $i++) {
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
            $db->run("UPDATE config SET val=1 WHERE cfg='sanity_sync'");
            _log("Invalid block detected. Deleting everything after $data[height] - $data[id]");
            sleep(10);
            $all_blocks_ok = false;
            $block->delete($i);

            $db->run("UPDATE config SET val=0 WHERE cfg='sanity_sync'");
            break;
        }
    }
    if ($all_blocks_ok) {
        echo "All checked blocks are ok\n";
    }
}

_log("Finishing sanity");

@unlink(SANITY_LOCK_PATH);
