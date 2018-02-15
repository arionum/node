<?php
/* 
The MIT License (MIT)
Copyright (c) 2018 AroDev, portions by ProgrammerDan

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

/*
	SCHEMA:
&q=report&id=workerid&type=workertype&hashes=delta&elapsed=delta

&q=discovery&id=workerid&type=workertype&difficulty=diff&dl=dl&nonce=nonce&argon=argon&retries=ret&confirmed
*/
require_once("include/report-init.inc.php");
set_time_limit(360);
$q=$_GET['q'];

$ip=$_SERVER['REMOTE_ADDR'];
$token=$_GET['token'];
if($_config['report_token'] != $token && !in_array($ip,$_config['allowed_hosts'])) api_err("unauthorized");

$worker=san($_GET['id']);
$type=san($_GET['type']);

$workerid=$db->single("SELECT id FROM workers WHERE name=:name and type=:type and ip=:ip", array(":name"=>$worker, ":type"=>$type, ":ip"=>$ip) );

if($workerid == false) {
	$db->run("INSERT ignore INTO workers SET name=:name, date=UNIX_TIMESTAMP(), type=:type, ip=:ip",
		array(":ip"=>$ip, ":type"=>$type, ":name"=>$worker));
	// TODO: RETURNING
	$workerid=$db->single("SELECT id FROM workers WHERE name=:name and ip=:ip", array(":name"=>$worker, ":ip"=>$ip) );
}

if ($workerid == false) {
	api_err("unregistered");
} elseif($q=="report"){
	$hashes=$_GET['hashes'];
	$elapsed=$_GET['elapsed'];
	$rate=bcdiv($hashes, $elapsed, 6) * 1000;

	$db->run("INSERT ignore INTO worker_report SET worker=:id, date=UNIX_TIMESTAMP(), hashes=:hashes, elapsed=:elapsed, rate=:rate",array(":id"=>$workerid, ":hashes"=>$hashes, ":elapsed"=>$elapsed, ":rate"=>$rate));

	api_echo("ok");
	exit;
} elseif($q=="discovery"){
    	$nonce = san($_GET['nonce']);
	$argon = san($_GET['argon']);
	$difficulty = intval($_GET['difficulty']);
	$dl = intval($_GET['dl']);
	$retries = intval($_GET['retries']);
	$confirmed = isset($_GET['confirmed']);

	$db->run("INSERT ignore INTO worker_discovery SET worker=:id, date=UNIX_TIMESTAMP(), nonce=:nonce, argon=:argon, difficulty=:diff, dl=:dl, retries=:retries, confirmed=:confirmed",
		array(":id"=>$workerid, ":nonce"=>$nonce, ":argon"=>$argon, ":diff"=>$difficulty, ":dl"=>$dl, ":retries"=>$retries, ":confirmed"=>$confirmed));

	api_echo("ok");
} else {
	api_err("invalid post");
}

?>
