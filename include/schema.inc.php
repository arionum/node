<?php


$dbversion=intval($_config['dbversion']);
$db->beginTransaction();
if($dbversion==0){
    $db->run("  
    CREATE TABLE `accounts` (
      `id` varbinary(128) NOT NULL,
      `public_key` varbinary(1024) NOT NULL,
      `block` varbinary(128) NOT NULL,
      `balance` decimal(20,8) NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 ROW_FORMAT=COMPACT;");
    
    $db->run("CREATE TABLE `blocks` (
      `id` varbinary(128) NOT NULL,
      `generator` varbinary(128) NOT NULL,
      `height` int(11) NOT NULL,
      `date` int(11) NOT NULL,
      `nonce` varbinary(128) NOT NULL,
      `signature` varbinary(256) NOT NULL,
      `difficulty` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
      `argon` varbinary(128) NOT NULL,
      `transactions` INT NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    
    $db->run("CREATE TABLE `config` (
      `cfg` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
      `val` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    
    
    $db->run("INSERT INTO `config` (`cfg`, `val`) VALUES
    ('hostname', '');");
    
    $db->run("INSERT INTO `config` (`cfg`, `val`) VALUES
    ('dbversion', '1');");

    $db->run("CREATE TABLE `mempool` (
      `id` varbinary(128) NOT NULL,
      `height` int(11) NOT NULL,
      `src` varbinary(128) NOT NULL,
      `dst` varbinary(128) NOT NULL,
      `val` decimal(20,8) NOT NULL,
      `fee` decimal(20,8) NOT NULL,
      `signature` varbinary(256) NOT NULL,
      `version` tinyint(4) NOT NULL,
      `message` varchar(256) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL DEFAULT '',
      `public_key` varbinary(1024) NOT NULL,
      `date` bigint(20) NOT NULL,
      `peer` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    
    $db->run("CREATE TABLE `peers` (
      `id` int(11) NOT NULL,
      `hostname` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
      `blacklisted` int(11) NOT NULL DEFAULT 0,
      `ping` int(11) NOT NULL,
      `reserve` tinyint(4) NOT NULL DEFAULT 1,
      `ip` varchar(45) NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    
    
    $db->run("CREATE TABLE `transactions` (
      `id` varbinary(128) NOT NULL,
      `block` varbinary(128) NOT NULL,
      `height` int(11) NOT NULL,
      `dst` varbinary(128) NOT NULL,
      `val` decimal(20,8) NOT NULL,
      `fee` decimal(20,8) NOT NULL,
      `signature` varbinary(256) NOT NULL,
      `version` tinyint(4) NOT NULL,
      `message` varchar(256) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL DEFAULT '',
      `date` int(11) NOT NULL,
      `public_key` varbinary(1024) NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    
    $db->run("ALTER TABLE `peers`
      ADD PRIMARY KEY (`id`);");
      $db->run("ALTER TABLE `peers`
      MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;");
    
    $db->run("ALTER TABLE `accounts`
      ADD PRIMARY KEY (`id`),
      ADD KEY `accounts` (`block`);");
    
    $db->run("ALTER TABLE `blocks`
      ADD PRIMARY KEY (`id`),
      ADD UNIQUE KEY `height` (`height`);");
    
      $db->run("ALTER TABLE `config` ADD PRIMARY KEY (`cfg`);");
    
      $db->run("ALTER TABLE `mempool`
      ADD PRIMARY KEY (`id`),
      ADD KEY `height` (`height`);");
    
      $db->run("ALTER TABLE `peers`
      ADD UNIQUE KEY `hostname` (`hostname`),
      ADD UNIQUE KEY `ip` (`ip`),
      ADD KEY `blacklisted` (`blacklisted`),
      ADD KEY `ping` (`ping`),
      ADD KEY `reserve` (`reserve`);");
    
      $db->run("ALTER TABLE `transactions`
      ADD PRIMARY KEY (`id`),
      ADD KEY `block_id` (`block`);");
    
      $db->run("ALTER TABLE `accounts`
      ADD CONSTRAINT `accounts` FOREIGN KEY (`block`) REFERENCES `blocks` (`id`) ON DELETE CASCADE;");
    
      $db->run("ALTER TABLE `transactions`
      ADD CONSTRAINT `block_id` FOREIGN KEY (`block`) REFERENCES `blocks` (`id`) ON DELETE CASCADE;");
     
    $dbversion++;
}
if($dbversion==1){
  $db->run("INSERT INTO `config` (`cfg`, `val`) VALUES ('sanity_last', '0');");
  $dbversion++;
}
if($dbversion==2){
  $db->run("INSERT INTO `config` (`cfg`, `val`) VALUES ('sanity_sync', '0');");
  $dbversion++;
}
if($dbversion==3){
  $dbversion++;
}

if($dbversion==4){
  $db->run("ALTER TABLE `mempool` ADD INDEX(`src`);");
  $db->run("ALTER TABLE `mempool` ADD INDEX(`peer`); ");
  $db->run("ALTER TABLE `mempool` ADD INDEX(`val`); ");
  $dbversion++;
}
if($dbversion==5){
  $db->run("ALTER TABLE `peers` ADD `fails` TINYINT NOT NULL DEFAULT '0' AFTER `ip`; ");
  $dbversion++;
}
if($dbversion!=$_config['dbversion']) $db->run("UPDATE config SET val=:val WHERE cfg='dbversion'",array(":val"=>$dbversion));
$db->commit();


?>