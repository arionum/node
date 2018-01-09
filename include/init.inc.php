<?php

define("VERSION", "0.1a");

date_default_timezone_set("Europe/Amsterdam");


//error_reporting(E_ALL & ~E_NOTICE);
error_reporting(0);
ini_set('display_errors',"off");


if(php_sapi_name() !== 'cli'&&substr_count($_SERVER['PHP_SELF'],"/")>1){
	die("This application should only be run in the main directory /");
}


require_once("include/config.inc.php");
require_once("include/db.inc.php");
require_once("include/functions.inc.php");
require_once("include/block.inc.php");
require_once("include/account.inc.php");
require_once("include/transaction.inc.php");

if($_config['db_pass']=="ENTER-DB-PASS") die("Please update your config file and set your db password");
// initial DB connection
$db=new DB($_config['db_connect'],$_config['db_user'],$_config['db_pass'],0);
if(!$db) die("Could not connect to the DB backend.");
if (!extension_loaded("openssl") && !defined("OPENSSL_KEYTYPE_EC")) api_err("Openssl php extension missing");
if (!extension_loaded("gmp")) api_err("gmp php extension missing");
if (!extension_loaded('PDO')) api_err("pdo php extension missing");
if (!extension_loaded("bcmath")) api_err("bcmath php extension missing");


if(floatval(phpversion())<7.2) api_err("The minimum php version required is 7.2");





// Getting extra configs from the database
$query=$db->run("SELECT cfg, val FROM config");
foreach($query as $res){
	$_config[$res['cfg']]=trim($res['val']);
}





if($_config['maintenance']==1) api_err("under-maintenance");


if(file_exists("tmp/db-update")){
	
	$res=unlink("tmp/db-update");
	if($res){
		echo "Updating db schema! Please refresh!\n";
		require_once("include/schema.inc.php");
		exit;
	}
	echo "Could not access the tmp/db-update file. Please give full permissions to this file\n";
}

if($_config['dbversion']<2) exit;

if($_config['testnet']==true) $_config['coin'].="-testnet"; 

$hostname=(!empty($_SERVER['HTTPS'])?'https':'http')."://".$_SERVER['HTTP_HOST'];
if($_SERVER['SERVER_PORT']!=80&&$_SERVER['SERVER_PORT']!=443) $hostname.=":".$_SERVER['SERVER_PORT'];

if($hostname!=$_config['hostname']&&$_SERVER['HTTP_HOST']!="localhost"&&$_SERVER['HTTP_HOST']!="127.0.0.1"&&$_SERVER['hostname']!='::1'&&php_sapi_name() !== 'cli'){
	$db->run("UPDATE config SET val=:hostname WHERE cfg='hostname' LIMIT 1",array(":hostname"=>$hostname));
	$_config['hostname']=$hostname;
}
if(empty($_config['hostname'])||$_config['hostname']=="http://"||$_config['hostname']=="https://") api_err("Invalid hostname");


	$t=time();
	if($t-$_config['sanity_last']>$_config['sanity_interval']&& php_sapi_name() !== 'cli') system("php sanity.php &>>/dev/null &");


?>
