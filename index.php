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
$block=new Block;
$current=$block->current();

echo "<h3>Arionum Node</h3>";
echo "System check complete.<br><br> Current block: $current[height]";

echo "<hr />";
echo "<h4>Worker Status</h4>";

echo "<table>";

echo "<th><td>Worker</td><td>Last Report</td><td>Hashes</td><td>H/s</td><td>Submits</td><td>Finds</td><td>Failures</td><td>Efficiency</td></th>";
select worker, avg(rate), max(date) from worker_report where date > UNIX_TIMESTAMP() - 300 group by worker having max(│date) > UNIX_TIMESTAMP() - 120;
select worker, count(*), min(dl), sum(case when confirmed = 1 then 1 else 0 end) as confirmed from worker_discovery group by worker;

$workers = $db->row("select a.worker as worker, total_hashes, avg_rate, find, submit, failure, latest_date from (select worker, sum(hashes) as total_hashes from worker_report group by worker having max(date) > UNIX_TIMESTAMP() - 120) a "
        ."left join (select worker, avg(rate) as avg_rate, max(date) as latest_date from worker_report where date > UNIX_TIMESTAMP() - 300 group by worker having max(date) > UNIX_TIMESTAMP() - 120) b on a.worker = b.worker "
        ."left join (select worker, sum(case when confirmed and dl <= 240 then 1 else 0 end) as find, sum(case when confirmed and dl > 240 then 1 else 0 end) as submit, sum(case when not confirmed then 1 else 0 end) as failure from worker_discovery group by worker) c "
        ."on b.worker = c.worker");

if ($workers!==false) {
    $totals = array("hashes"=>0,"rate"=>0.0,"submit"=>0,"find"=>0,"failure"=>0,"eff"=>0.0);
    $count = 0;
    foreach($workers as $t) {
	$totals['hashes'] += $t['total_hashes'];
	$totals['rate'] += $t['avg_rate'];
	$totals['submit'] += $t['submit'];
	$totals['find'] += $t['find'];
	$totals['failure'] += $t['failure'];
	$totals['eff'] += bcdiv($t['submit'] + $t['find'] + $t['failure'], $t['failure'], 4) * 100;
        $count++;
    } 

    echo "<tr><td>Total</td><td>Now</td><td>".$totals['hashes']."</td><td>".$totals['rate']."</td><td>".$totals['submit']."</td><td>".$totals['find']."</td><td>".$totals['failure']."</td><td>".$totals['eff']."</td></tr>";

    foreach($workers as $t) {
	$eff = bcdiv($t['submit'] + $t['find'] + $t['failure'], $t['failure'], 4) * 100;

        echo "<tr><td>".$t['worker']."</td><td>".$t['latest_date']."</td><td>".$t['total_hashes']."</td><td>".$t['avg_rate']."</td><td>".$t['submit']."</td><td>".$t['find']."</td><td>".$t['failure']."</td><td>".$eff."</td></tr>";
    }
} else {
    echo "<tr><td colspan=\"8\">No Workers currently reporting.</td></tr>"

echo "</table>";

$all_hashes = $db->single("select sum(hashes) as all_hashes from worker_report");
$all_submit = $db->row("select sum(case when confirmed and dl <= 240 then 1 else 0 end) as all_finds, sum(case when confirmed and dl > 240 then 1 else 0 end) as all_submits, sum(case when not confirmed then 1 else 0 end) as all_failures from worker_discovery");

echo "<ul><li><h4>Data across all miners since tracking began</h4></li>";
echo "<li><b>Total Hashes: </b><span style=\"font-size: larger;\">$all_hashes</span></li>";
echo "<li><b>Total Hashes: </b><span style=\"font-size: larger;\">".$all_submit["all_submits"]."</span></li>";
echo "<li><b>Total Hashes: </b><span style=\"font-size: larger;\">".$all_submit["all_finds"]."</span></li>";
echo "<li><b>Total Hashes: </b><span style=\"font-size: larger;\">".$all_submit["all_failure"]"</span></li></ul>";

?>
