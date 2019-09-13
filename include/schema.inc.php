<?php

// when db schema modifications are done, this function is run.
$dbversion = intval($_config['dbversion']);

$db->beginTransaction();
if ($dbversion == 0) {
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
if ($dbversion == 1) {
    $db->run("INSERT INTO `config` (`cfg`, `val`) VALUES ('sanity_last', '0');");
    $dbversion++;
}
if ($dbversion == 2) {
    $db->run("INSERT INTO `config` (`cfg`, `val`) VALUES ('sanity_sync', '0');");
    $dbversion++;
}
if ($dbversion == 3) {
    $dbversion++;
}

if ($dbversion == 4) {
    $db->run("ALTER TABLE `mempool` ADD INDEX(`src`);");
    $db->run("ALTER TABLE `mempool` ADD INDEX(`peer`); ");
    $db->run("ALTER TABLE `mempool` ADD INDEX(`val`); ");
    $dbversion++;
}
if ($dbversion == 5) {
    $db->run("ALTER TABLE `peers` ADD `fails` TINYINT NOT NULL DEFAULT '0' AFTER `ip`; ");
    $dbversion++;
}
if ($dbversion == 6) {
    $db->run("ALTER TABLE `peers` ADD `stuckfail` TINYINT(4) NOT NULL DEFAULT '0' AFTER `fails`, ADD INDEX (`stuckfail`); ");
    $db->run("ALTER TABLE `accounts` ADD `alias` VARCHAR(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL DEFAULT NULL AFTER `balance`; ");
    $dbversion++;
}
if ($dbversion == 7) {
    $db->run("ALTER TABLE `accounts` ADD INDEX(`alias`); ");
    $db->run("ALTER TABLE `transactions` ADD KEY `dst` (`dst`), ADD KEY `height` (`height`),  ADD KEY `public_key` (`public_key`);");
    $dbversion++;
}
if ($dbversion == 8) {
    $db->run("CREATE TABLE `masternode` (
    `public_key` varchar(128) COLLATE utf8mb4_bin NOT NULL,
    `height` int(11) NOT NULL,
    `ip` varchar(16) COLLATE utf8mb4_bin NOT NULL,
    `last_won` int(11) NOT NULL DEFAULT '0',
    `blacklist` int(11) NOT NULL DEFAULT '0',
    `fails` int(11) NOT NULL DEFAULT '0',
    `status` tinyint(4) NOT NULL DEFAULT '1'
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;");

    $db->run("ALTER TABLE `masternode`
  ADD PRIMARY KEY (`public_key`),
  ADD KEY `last_won` (`last_won`),
  ADD KEY `status` (`status`),
  ADD KEY `blacklist` (`blacklist`),
  ADD KEY `height` (`height`);");
    $dbversion++;
}
if ($dbversion == 9) {
    //dev only
    $dbversion++;
}
if ($dbversion == 10) {
    //assets system
    $db->run("
  CREATE TABLE `assets` (
    `id` varbinary(128) NOT NULL,
    `max_supply` bigint(18) NOT NULL DEFAULT '0',
    `tradable` tinyint(1) NOT NULL DEFAULT '1',
    `price` decimal(20,8) NOT NULL DEFAULT '0.00000000',
    `dividend_only` tinyint(1) NOT NULL DEFAULT '0',
    `auto_dividend` tinyint(1) NOT NULL DEFAULT '0',
    `allow_bid` tinyint(1) NOT NULL DEFAULT '1',
    `height` int(11) NOT NULL DEFAULT '0'
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
  ");
    $db->run("
  ALTER TABLE `assets`
  ADD PRIMARY KEY (`id`)
  ");
    $db->run("
  CREATE TABLE `assets_market` (
    `id` varchar(128) COLLATE utf8mb4_bin NOT NULL,
    `account` varbinary(128) NOT NULL,
    `asset` varbinary(128) NOT NULL,
    `price` decimal(20,8) NOT NULL,
    `date` int(11) NOT NULL,
    `status` tinyint(1) NOT NULL DEFAULT '0',
    `type` enum('bid','ask') COLLATE utf8mb4_bin NOT NULL DEFAULT 'bid',
    `val` bigint(18) NOT NULL,
    `val_done` bigint(18) NOT NULL DEFAULT '0',
    `cancelable`  tinyint(1) NOT NULL DEFAULT '1'
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;  
  ");
    $db->run("
  ALTER TABLE `assets_market`
  ADD PRIMARY KEY (`id`);
  ");
    $db->run("CREATE TABLE `assets_balance` (
    `account` varbinary(128) NOT NULL,
    `asset` varbinary(128) NOT NULL,
    `balance` bigint(128) NOT NULL DEFAULT '0'
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
  ");

    $db->run("
  ALTER TABLE `assets_balance`
  ADD PRIMARY KEY (`account`,`asset`);
  ");

    $dbversion++;
}

if ($dbversion == 11) { 
    $db->run("ALTER TABLE `transactions` ADD INDEX(`version`); ");
    $db->run("ALTER TABLE `transactions` ADD INDEX(`message`); ");
    $db->run("
    CREATE TABLE `logs` (
      `id` int(11) NOT NULL,
      `transaction` varbinary(128) NULL DEFAULT NULL,
      `block` VARBINARY(128) NULL DEFAULT NULL,
      `json` text DEFAULT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=latin1;");
    $db->run("ALTER TABLE `logs`
    ADD PRIMARY KEY (`id`),
    ADD INDEX(`transaction`),
    ADD INDEX(`block`);");
    $db->run("ALTER TABLE `logs` MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;");

    $db->run("ALTER TABLE `masternode` ADD `vote_key` VARCHAR(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL DEFAULT NULL AFTER `status`, ADD INDEX(`vote_key`);");
    $db->run("ALTER TABLE `masternode` ADD `cold_last_won` INT NOT NULL DEFAULT '0' AFTER `vote_key`, ADD INDEX(`cold_last_won`);  ");
    $db->run("ALTER TABLE `masternode` ADD `voted` TINYINT NOT NULL DEFAULT '0' AFTER `cold_last_won`, ADD INDEX (`voted`); ");



    $db->run("CREATE TABLE `votes` (
      `id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
      `nfo` varchar(64) NOT NULL,
      `val` int(11) NOT NULL DEFAULT 0
    ) ENGINE=InnoDB DEFAULT CHARSET=latin1;");
    
  
    
    $db->run("INSERT INTO `votes` (`id`, `nfo`, `val`) VALUES
    ('coldstacking', 'Enable cold stacking for inactive masternodes', 1),
    ('emission30', 'Emission reduction by 30 percent', 1),
    ('endless10reward', 'Minimum reward to be 10 aro forever', 0),
    ('masternodereward50', 'Masternode reward to be 50 percent of the block reward', 1);");
    
    $db->run("ALTER TABLE `votes`  ADD PRIMARY KEY (`id`);");

    $dbversion++;
}



// update the db version to the latest one
if ($dbversion != $_config['dbversion']) {
    $db->run("UPDATE config SET val=:val WHERE cfg='dbversion'", [":val" => $dbversion]);
}
$db->commit();
