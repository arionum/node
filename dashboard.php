<?php
/* 
The MIT License (MIT)
Copyright (c) 2018 AroDev, portions ProgrammerDan (Daniel Boston) 

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


require_once("include/report-init.inc.php");

$block=new Block;
$current=$block->current();
echo '<head>
<meta name="viewport" content="width=device-width, initial-scale=1">
	
<link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootswatch/3.3.7/lumen/bootstrap.min.css">
<link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Source+Sans+Pro:300,400,700,400italic">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.7.0/css/font-awesome.min.css">
</head>';
echo '<body><div class="container"><div class="row">';
echo "<h3>Arionum Node Worker Dashboard</h3>";
echo "Current block: $current[height] <br><br> Current difficulty: ".$block->difficulty();

echo "<hr/>";

echo "<table class=\"table table-striped table-bordered\">";

echo "<thead><tr><td>Worker</td><td>Name</td><td>Last Report</td><td>Time Active</td><td>Hashes</td><td>H/s</td><td>Submits</td><td>Finds</td><td>Failures</td><td>Efficiency</td><td>Hash/Attempt</td></tr></thead><tbody>";

$workers = $db->run("select a.worker as worker, d.name, total_hashes, avg_rate, find, submit, failure, latest_date, life from (select worker, sum(hashes) as total_hashes, (max(date) - min(date)) as life from worker_report group by worker having max(date) > UNIX_TIMESTAMP() - 120) a "
        ."left join (select worker, avg(rate) as avg_rate, max(date) as latest_date from worker_report where date > UNIX_TIMESTAMP() - 300 group by worker having max(date) > UNIX_TIMESTAMP() - 120) b on a.worker = b.worker "
        ."left join (select worker, sum(case when confirmed and dl <= 240 then 1 else 0 end) as find, sum(case when confirmed and dl > 240 then 1 else 0 end) as submit, sum(case when not confirmed then 1 else 0 end) as failure from worker_discovery group by worker) c "
        ."on a.worker = c.worker left join (select id, name from workers) d on a.worker = d.id");

if (count($workers)>0) {
    $totals = array("hashes"=>0,"rate"=>0.0,"submit"=>0,"find"=>0,"failure"=>0,"eff"=>0.0,"drate"=>0.0,"life"=>0);
    $count = 0;
    foreach($workers as $t) {
	$totals['hashes'] += $t['total_hashes'];
	$totals['rate'] += $t['avg_rate'];
	$totals['submit'] += $t['submit'];
	$totals['find'] += $t['find'];
	$totals['failure'] += $t['failure'];
	$totals['eff'] += 100 - bcdiv($t['failure'], $t['submit'] + $t['find'] + $t['failure'], 4) * 100;
        $totals['drate'] += bcdiv($t['total_hashes'], $t['submit'] + $t['find'] + $t['failure'], 2);
        $totals['life'] += $t['life'];
        $count++;
    }

    $totals['eff'] = bcdiv($totals['eff'], $count);
    $totals['drate'] = bcdiv($totals['drate'], $count);

    $d1 = new DateTime();
    $d2 = clone $d1;
    $d2->add(new DateInterval('PT'.$totals['life'].'S'));
    $iv = $d2->diff($d1);

    echo "<tr><td><b>Total</b></td><td colspan='2'>".date("M d, Y H:i:s")."</td><td>".$iv->format("%adays %hhr %imin %ss")."</td><td>".number_format($totals['hashes'],0)."</td><td>".number_format($totals['rate'],2)."</td><td>".$totals['submit']."</td><td>".$totals['find']."</td><td>".$totals['failure']."</td><td>".number_format($totals['eff'],2)."</td><td>".number_format($totals['drate'],0)."</td></tr>";

    foreach($workers as $t) {
	$eff = 100.0 - bcdiv($t['failure'], $t['submit'] + $t['find'] + $t['failure'], 4) * 100;
        $drate = bcdiv($t['total_hashes'], $t['submit'] + $t['find'] + $t['failure'], 0);
        $d3 = new DateTime();
        $d4 = clone $d3;
        $d4->add(new DateInterval('PT'.$t['life'].'S'));
        $ix = $d4->diff($d3);

        echo "<tr><td>".$t['worker']."</td><td>".$t['name']."</td><td>".date("H:i:s",$t['latest_date'])."</td><td>".$ix->format("%adays %hhr %imin %ss")."</td><td>".number_format($t['total_hashes'],0)."</td><td>".number_format($t["avg_rate"], 2)."</td><td>".$t['submit']."</td><td>".$t['find']."</td><td>".$t['failure']."</td><td>".number_format($eff,2)."</td><td>".number_format($drate,0)."</td></tr>";
    }
} else {
    echo "<tr><td colspan=\"8\">No Workers currently reporting.</td></tr>";
}

echo "</tbody></table>";

$all_hashes = $db->single("select sum(hashes) as all_hashes from worker_report");
$all_submit = $db->row("select sum(case when confirmed and dl <= 240 then 1 else 0 end) as all_finds, sum(case when confirmed and dl > 240 then 1 else 0 end) as all_submits, sum(case when not confirmed then 1 else 0 end) as all_failures from worker_discovery");
$all_drate = bcdiv($all_hashes, $all_submit["all_submits"] + $all_submit["all_finds"] + $all_submit["all_failures"],0);

echo "<h4>Data across all miners since tracking began</h4><ul>";
echo "<li><b>Hashes: </b><span style=\"font-size: larger;\">".number_format($all_hashes,0)."</span></li>";
echo "<li><b>Submits: </b><span style=\"font-size: larger;\">".$all_submit["all_submits"]."</span></li>";
echo "<li><b>Block Finds: </b><span style=\"font-size: larger;\">".$all_submit["all_finds"]."</span></li>";
echo "<li><b>Failures: </b><span style=\"font-size: larger;\">".$all_submit["all_failures"]."</span></li>";
echo "<li><b>Hash/Attempt: </b><span style=\"font-size: larger;\">".number_format($all_drate,0)."</span></li></ul>";


echo "<script>setTimeout(function(){location.reload();}, 15000);</script>";
echo "</div></div></body>"

?>

