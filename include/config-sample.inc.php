<?php

/*
|--------------------------------------------------------------------------
| Database Configuration
|--------------------------------------------------------------------------
*/

// The database DSN
$_config['db_connect'] = 'mysql:host=localhost;dbname=ENTER-DB-NAME';

// The database username
$_config['db_user'] = 'ENTER-DB-USER';

// The database password
$_config['db_pass'] = 'ENTER-DB-PASS';

/*
|--------------------------------------------------------------------------
| General Configuration
|--------------------------------------------------------------------------
*/

// Maximum number of connected peers
$_config['max_peers'] = 30;

// Enable testnet mode for development
$_config['testnet'] = false;

// To avoid any problems if other clones are made
$_config['coin'] = 'arionum';

// Allow others to connect to the node api (if set to false, only the below 'allowed_hosts' are allowed)
$_config['public_api'] = true;

// Hosts that are allowed to mine on this node
$_config['allowed_hosts'] = [
    '127.0.0.1',
];

// Disable transactions and block repropagation
$_config['disable_repropagation'] = false;


/*
|--------------------------------------------------------------------------
| Peer Configuration
|--------------------------------------------------------------------------
*/

// The number of peers to send each new transaction to
$_config['transaction_propagation_peers'] = 5;

// How many new peers to check from each peer
$_config['max_test_peers'] = 5;

// The initial peers to sync from in sanity
$_config['initial_peer_list'] = [
    'http://peer1.arionum.com',
    'http://peer2.arionum.com',
    'http://peer3.arionum.com',
    'http://peer4.arionum.com',
    'http://peer5.arionum.com',
    'http://peer6.arionum.com',
    'http://peer7.arionum.com',
    'http://peer8.arionum.com',
    'http://peer9.arionum.com',
    'http://peer10.arionum.com',
    'http://peer11.arionum.com',
    'http://peer12.arionum.com',
    'http://peer13.arionum.com',
    'http://peer14.arionum.com',
    'http://peer15.arionum.com',
    'http://peer16.arionum.com',
    'http://peer17.arionum.com',
    'http://peer18.arionum.com',
    'http://peer19.arionum.com',
    'http://peer20.arionum.com',
    'http://peer21.arionum.com',
    'http://peer22.arionum.com',
    'http://peer23.arionum.com',
    'http://peer24.arionum.com',
    'http://peer25.arionum.com',
    'http://peer26.arionum.com',
    'http://peer27.arionum.com',
];

/*
|--------------------------------------------------------------------------
| Mempool Configuration
|--------------------------------------------------------------------------
*/

// The maximum transactions to accept from a single peer
$_config['peer_max_mempool'] = 100;

// The maximum number of mempool transactions to be rebroadcasted
$_config['max_mempool_rebroadcast'] = 5000;

// The number of blocks between rebroadcasting transactions
$_config['sanity_rebroadcast_height'] = 30;

// Block accepting transfers from addresses blacklisted by the Arionum devs
$_config['use_official_blacklist'] = true;

/*
|--------------------------------------------------------------------------
| Sanity Configuration
|--------------------------------------------------------------------------
*/

// Recheck the last blocks on sanity
$_config['sanity_recheck_blocks'] = 10;

// The interval to run the sanity in seconds
$_config['sanity_interval'] = 900;

// Enable setting a new hostname (should be used only if you want to change the hostname)
$_config['allow_hostname_change'] = false;

// Rebroadcast local transactions when running sanity
$_config['sanity_rebroadcast_locals'] = true;

// Get more peers?
$_config['get_more_peers'] = true;

/*
|--------------------------------------------------------------------------
| Logging Configuration
|--------------------------------------------------------------------------
*/

// Enable log output to the specified file
$_config['enable_logging'] = false;

// The specified file to write to (this should not be publicly visible)
$_config['log_file'] = '/var/log/aro.log';

// Log verbosity (default 0, maximum 3)
$_config['log_verbosity'] = 0;

/*
|--------------------------------------------------------------------------
| Masternode Configuration
|--------------------------------------------------------------------------
*/

// Enable this node as a masternode
$_config['masternode'] = false;

// The public key for the masternode
$_config['masternode_public_key'] = '';
