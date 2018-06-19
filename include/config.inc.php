<?php
// Database connection
$_config['db_connect']="mysql:host=localhost;dbname=ENTER-DB-NAME";
$_config['db_user']="ENTER-DB-USER";
$_config['db_pass']="ENTER-DB-PASS";

// Maximum number of connected peers 
$_config['max_peers']=30;
// Testnet, used for development
$_config['testnet']=false;
// To avoid any problems if other clones are made
$_config['coin']="arionum";
// maximum transactions accepted from a single peer
$_config['peer_max_mempool']=100;
// maximum mempool transactions to be rebroadcasted
$_config['max_mempool_rebroadcast']=5000;
// after how many blocks should the transactions be rebroadcasted
$_config['sanity_rebroadcast_height']=30;
// each new received transaction is sent to X peers
$_config['transaction_propagation_peers']=5;
// how many new peers to check from each peer.
$_config['max_test_peers']=5;
// recheck the last blocks on sanity
$_config['sanity_recheck_blocks']=10;
// allow others to connect to node api. If set to false, only allowed_hosts are allowed
$_config['public_api']=true;
// hosts allowed to mine on this node
$_config['allowed_hosts']=array("127.0.0.1");
// sanity is run every X seconds
$_config['sanity_interval']=900;
// accept the setting of new hostnames / should be used only if you want to change the hostname
$_config['allow_hostname_change']=false;
// rebroadcast local transactions on each sanity
$_config['sanity_rebroadcast_locals']=true;
// write logs to file
$_config['enable_logging']=false;
// log file, should not be publicly viewable
$_config['log_file']="/var/log/aro.log";

// Default timezone to be set
$_config['timezone'] = 'UTC';
