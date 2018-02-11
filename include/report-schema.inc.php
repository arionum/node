<?php


$dbversion=intval($_config['reportdbversion']);
$db->beginTransaction();
if($dbversion==0){
    #Workers table records basic first connect stats for a worker, based on name & hostname.
    $db->run("  
    CREATE TABLE `workers` (
      `id` int(11) NOT NULL,
      `name` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
      `ip` varchar(45) NOT NULL,
      `date` bigint(20) NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 ROW_FORMAT=COMPACT;");
    
    $db->run("ALTER TABLE `workers`
      ADD PRIMARY KEY (`id`);");

    $db->run("ALTER TABLE `workers`
      MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;");
    
    $db->run("CREATE TABLE `worker_report` (
      `worker` int(11) NOT NULL,
      `date` bigint(20) NOT NULL,
      `hashes` int(11) NOT NULL,
      `elapsed` int(11) NOT NULL,
      `rate` decimal(20,3) NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    
    $db->run("ALTER TABLE `worker_report`
      ADD CONSTRAINT `workers` FOREIGN KEY (`worker`) REFERENCES `workers` (`id`) ON DELETE NO ACTION;");
    
    $db->run("CREATE TABLE `worker_discovery` (
      `worker` int(11) NOT NULL,
      `date` bigint(20) NOT NULL,
      `difficulty` int(20) NOT NULL,
      `dl` int(11) NOT NULL,
      `nonce` varbinary(128) NOT NULL,
      `argon` varbinary(128) NOT NULL,
      `retries` tinyint(4) NOT NULL,
      `confirmed` tinyint(4) NOT NULL DEFAULT 1
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    
    $db->run("ALTER TABLE `worker_discovery`
      ADD CONSTRAINT `workers` FOREIGN KEY (`worker`) REFERENCES `workers` (`id`) ON DELETE NO ACTION;");
    
    $db->run("ALTER TABLE `workers` ADD INDEX(`name`, `ip`);");

    $db->run("INSERT INTO `config` (`cfg`, `val`) VALUES
      ('reportdbversion', '1');");

    $dbversion++;
}
if($dbversion==1){
  $db->run("ALTER TABLE `workers` ADD `type` varchar(12) NOT NULL DEFAULT 'cpu' AFTER `ip`; ");
  $dbversion++;
}
if($dbversion!=$_config['reportdbversion']) $db->run("UPDATE config SET val=:val WHERE cfg='reportdbversion'",array(":val"=>$dbversion));
$db->commit();


?>

