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
require_once("include/init.inc.php");
$block=new Block();
$acc=new Account();
set_time_limit(360);
$q=$_GET['q'];

$ip=$_SERVER['REMOTE_ADDR'];
if($_config['testnet']==false&&!in_array($ip,$_config['allowed_hosts'])) api_err("unauthorized");

if($q=="info"){
	$diff=$block->difficulty();
	$current=$block->current();
	api_echo(array("difficulty"=>$diff, "block"=>$current['id'], "height"=>$current['height']));
	exit;
} elseif($q=="submitNonce"){
	if($_config['sanity_sync']==1) api_err("sanity-sync");
	$nonce = san($_POST['nonce']);
	$argon=$_POST['argon'];
	$public_key=san($_POST['public_key']);
	$private_key=san($_POST['private_key']);

	$result=$block->mine($public_key, $nonce, $argon);

	if($result) {

			$res=$block->forge($nonce,$argon, $public_key, $private_key);





		if($res){
			$current=$block->current();
			system("php propagate.php block $current[id] > /dev/null 2>&1 &");
			api_echo("accepted");
		}
	}
	api_err("rejected");
} else {
	api_err("invalid command");
}

?>
