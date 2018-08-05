<?php
// ARO version
define("VERSION", "0.4.0");
// Amsterdam timezone by default, should probably be moved to config
date_default_timezone_set("UTC");

//error_reporting(E_ALL & ~E_NOTICE);
error_reporting(0);
ini_set('display_errors', "off");

// not accessible directly
if (php_sapi_name() !== 'cli' && substr_count($_SERVER['PHP_SELF'], "/") > 1) {
    die("This application should only be run in the main directory /");
}

require_once("include/config.inc.php");
require_once("include/db.inc.php");
require_once("include/functions.inc.php");
require_once("include/block.inc.php");
require_once("include/account.inc.php");
require_once("include/transaction.inc.php");

if ($_config['db_pass'] == "ENTER-DB-PASS") {
    die("Please update your config file and set your db password");
}
// initial DB connection
$db = new DB($_config['db_connect'], $_config['db_user'], $_config['db_pass'], $_config['enable_logging']);
if (!$db) {
    die("Could not connect to the DB backend.");
}

// checks for php version and extensions
if (!extension_loaded("openssl") && !defined("OPENSSL_KEYTYPE_EC")) {
    api_err("Openssl php extension missing");
}
if (!extension_loaded("gmp")) {
    api_err("gmp php extension missing");
}
if (!extension_loaded('PDO')) {
    api_err("pdo php extension missing");
}
if (!extension_loaded("bcmath")) {
    api_err("bcmath php extension missing");
}
if (!defined("PASSWORD_ARGON2I")) {
    api_err("The php version is not compiled with argon2i support");
}

if (floatval(phpversion()) < 7.2) {
    api_err("The minimum php version required is 7.2");
}

// Getting extra configs from the database
$query = $db->run("SELECT cfg, val FROM config");
foreach ($query as $res) {
    $_config[$res['cfg']] = trim($res['val']);
}

// nothing is allowed while in maintenance
if ($_config['maintenance'] == 1) {
    api_err("under-maintenance");
}

// update the db schema, on every git pull or initial install
if (file_exists("tmp/db-update")) {
    //checking if the server has at least 2GB of ram
    $ram=file_get_contents("/proc/meminfo");
    $ramz=explode("MemTotal:",$ram);
    $ramb=explode("kB",$ramz[1]);
    $ram=intval(trim($ramb[0]));
    if($ram<1700000) {
        die("The node requires at least 2 GB of RAM");
    }
    if($_config['masternode']==true && $ram<7000000){
        die("The masternode require at least 8GB of RAM");
    }
    $res = unlink("tmp/db-update");
    if ($res) {
        echo "Updating db schema! Please refresh!\n";
        require_once("include/schema.inc.php");
        exit;
    }
    echo "Could not access the tmp/db-update file. Please give full permissions to this file\n";
}

// something went wront with the db schema
if ($_config['dbversion'] < 2) {
    exit;
}

// separate blockchain for testnet
if ($_config['testnet'] == true) {
    $_config['coin'] .= "-testnet";
}

// current hostname
$hostname = (!empty($_SERVER['HTTPS']) ? 'https' : 'http')."://".san_host($_SERVER['HTTP_HOST']);
// set the hostname to the current one
if ($hostname != $_config['hostname'] && $_SERVER['HTTP_HOST'] != "localhost" && $_SERVER['HTTP_HOST'] != "127.0.0.1" && $_SERVER['hostname'] != '::1' && php_sapi_name() !== 'cli' && ($_config['allow_hostname_change'] != false || empty($_config['hostname']))) {
    $db->run("UPDATE config SET val=:hostname WHERE cfg='hostname' LIMIT 1", [":hostname" => $hostname]);
    $_config['hostname'] = $hostname;
}
if (empty($_config['hostname']) || $_config['hostname'] == "http://" || $_config['hostname'] == "https://") {
    api_err("Invalid hostname");
}

// run sanity
$t = time();
if ($t - $_config['sanity_last'] > $_config['sanity_interval'] && php_sapi_name() !== 'cli') {
    system("php sanity.php  > /dev/null 2>&1  &");
}
