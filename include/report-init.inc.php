<?php

require_once("include/init.inc.php");
require_once("include/reportconfig.inc.php");

if(file_exists("tmp/report-db-update")){
	
	$res=unlink("tmp/report-db-update");
	if($res){
		echo "Updating reporting db schema! Please refresh!\n";
		require_once("include/report-schema.inc.php");
		exit;
	}
	echo "Could not access the tmp/report-db-update file. Please give full permissions to this file\n";
}

?>

