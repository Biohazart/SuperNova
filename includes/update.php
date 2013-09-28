<?php
/*
 update.php

 Automated DB upgrade system

 @package supernova
 @version 26

 25 - copyright (c) 2009-2011 Gorlum for http://supernova.ws
   [!] Now it's all about transactions...
   [~] Converted doquery to internal wrapper with logging ability
 24 - copyright (c) 2009-2011 Gorlum for http://supernova.ws
   [+] Converted pre v18 entries to use later implemented functions
 v18-v23 - copyright (c) 2009-2010 Gorlum for http://supernova.ws
   [!] DB code updates
 17 - copyright (c) 2009-2010 Gorlum for http://supernova.ws
   [~] PCG1 compliant

 v01-v16 copyright (c) 2009-2010 Gorlum for http://supernova.ws
   [!] DB code updates
*/

if(!defined('INIT'))
{
  include_once('init.php');
}

define('IN_UPDATE', true);

require('includes/upd_helpers.php');

$config->reset();
$config->db_loadAll();
$config->debug = 0;

//$config->db_loadItem('db_version');
if($config->db_version == DB_VERSION)
{
}
elseif($config->db_version > DB_VERSION)
{
  global $config, $time_now;

  $config->db_saveItem('var_db_update_end', $time_now);
  die('Internal error! Auotupdater detects DB version greater then can be handled!<br>Possible you have out-of-date SuperNova version<br>Pleas upgrade your server from <a href="http://github.com/supernova-ws/SuperNova">GIT repository</a>.');
}

if($config->db_version < 26)
{
  global $sys_log_disabled;
  $sys_log_disabled = true;
}

$upd_log = '';
$new_version = floatval($config->db_version);
upd_check_key('upd_lock_time', 60, !isset($config->upd_lock_time));

upd_log_message('Update started. Disabling server');

$old_server_status = $config->game_disable;
$old_server_reason = $config->game_disable_reason;
$config->db_saveItem('game_disable', 1);
$config->db_saveItem('game_disable_reason', 'Server is updating. Please wait');

upd_log_message('Server disabled. Loading table info...');
$update_tables  = array();
$update_indexes = array();
$query = upd_do_query('SHOW TABLES;');
while($row = mysql_fetch_row($query))
{
  upd_load_table_info($row[0]);
}
upd_log_message('Table info loaded. Now looking DB for upgrades...');

upd_do_query('SET FOREIGN_KEY_CHECKS=0;');

if($new_version < 32)
{
  require_once('update_old.php');
}

switch($new_version)
{
  case 32:
    upd_log_version_update();

    upd_check_key('avatar_max_width', 128, !isset($config->avatar_max_width));
    upd_check_key('avatar_max_height', 128, !isset($config->avatar_max_height));

    upd_alter_table('users', array(
      "MODIFY COLUMN `avatar` tinyint(1) unsigned NOT NULL DEFAULT '0'",
    ), strtoupper($update_tables['users']['avatar']['Type']) != 'TINYINT(1) UNSIGNED');

    upd_alter_table('alliance', array(
      "MODIFY COLUMN `ally_image` tinyint(1) unsigned NOT NULL DEFAULT '0'",
    ), strtoupper($update_tables['alliance']['ally_image']['Type']) != 'TINYINT(1) UNSIGNED');

    upd_alter_table('users', array(
      "DROP COLUMN `settings_allylogo`",
    ), isset($update_tables['users']['settings_allylogo']));

    if(!isset($update_tables['powerup']))
    {
      upd_do_query("DROP TABLE IF EXISTS {$config->db_prefix}mercenaries;");

      upd_create_table('powerup',
        "(
          `powerup_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
          `powerup_user_id` bigint(20) UNSIGNED NULL DEFAULT NULL,
          `powerup_planet_id` bigint(20) UNSIGNED NULL DEFAULT NULL,
          `powerup_category` SMALLINT NOT NULL DEFAULT 0,
          `powerup_unit_id` MEDIUMINT UNSIGNED NOT NULL DEFAULT '0',
          `powerup_unit_level` SMALLINT UNSIGNED NOT NULL DEFAULT '0',
          `powerup_time_start` int(11) NOT NULL DEFAULT '0',
          `powerup_time_finish` int(11) NOT NULL DEFAULT '0',

          PRIMARY KEY (`powerup_id`),
          KEY `I_powerup_user_id` (`powerup_user_id`),
          KEY `I_powerup_planet_id` (`powerup_planet_id`),
          KEY `I_user_powerup_time` (`powerup_user_id`, `powerup_unit_id`, `powerup_time_start`, `powerup_time_finish`),
          KEY `I_planet_powerup_time` (`powerup_planet_id`, `powerup_unit_id`, `powerup_time_start`, `powerup_time_finish`),

          CONSTRAINT `FK_powerup_user_id` FOREIGN KEY (`powerup_user_id`) REFERENCES `{$config->db_prefix}users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
          CONSTRAINT `FK_powerup_planet_id` FOREIGN KEY (`powerup_planet_id`) REFERENCES `{$config->db_prefix}planets` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8;"
      );

      upd_check_key('empire_mercenary_temporary', 0, !isset($config->empire_mercenary_temporary));
      upd_check_key('empire_mercenary_base_period', PERIOD_MONTH, !isset($config->empire_mercenary_base_period));

      $update_query_template = "UPDATE {{users}} SET id = id %s WHERE id = %d LIMIT 1;";
      $user_list = upd_do_query("SELECT * FROM {{users}};");
      while($user_row = mysql_fetch_assoc($user_list))
      {
        $update_query_str = '';
        foreach($sn_data['groups']['mercenaries'] as $mercenary_id)
        {
          $mercenary_data_name = $sn_data[$mercenary_id]['name'];
          if($mercenary_level = $user_row[$mercenary_data_name])
          {
            $update_query_str = ", `{$mercenary_data_name}` = 0";
            upd_do_query("DELETE FROM {{powerup}} WHERE powerup_user_id = {$user_row['id']} AND powerup_unit_id = {$mercenary_id} LIMIT 1;");
            upd_do_query("INSERT {{powerup}} SET powerup_user_id = {$user_row['id']}, powerup_unit_id = {$mercenary_id}, powerup_unit_level = {$mercenary_level};");
          }
        }

        if($update_query_str)
        {
          upd_do_query(sprintf($update_query_template, $update_query_str, $user_row['id']));
        }
      }
    }

    if(!isset($update_tables['universe']))
    {
      upd_create_table('universe',
        "(
          `universe_galaxy` SMALLINT UNSIGNED NOT NULL DEFAULT '0',
          `universe_system` SMALLINT UNSIGNED NOT NULL DEFAULT '0',
          `universe_name` varchar(32) NOT NULL DEFAULT '',
          `universe_price` bigint(20) NOT NULL DEFAULT 0,

          PRIMARY KEY (`universe_galaxy`, `universe_system`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8;"
      );

      upd_check_key('uni_price_galaxy', 10000, !isset($config->uni_price_galaxy));
      upd_check_key('uni_price_system', 1000, !isset($config->uni_price_system));
    }

    // ========================================================================
    // Ally player
    // Adding config variable
    upd_check_key('ali_bonus_members', 10, !isset($config->ali_bonus_members));

    // ------------------------------------------------------------------------
    // Modifying tables
    if(strtoupper($update_tables['users']['user_as_ally']['Type']) != 'BIGINT(20) UNSIGNED')
    {
      upd_alter_table('users', array(
        "ADD COLUMN user_as_ally BIGINT(20) UNSIGNED DEFAULT NULL",

        "ADD KEY `I_user_user_as_ally` (`user_as_ally`)",

        "ADD CONSTRAINT `FK_user_user_as_ally` FOREIGN KEY (`user_as_ally`) REFERENCES `{$config->db_prefix}alliance` (`id`) ON DELETE CASCADE ON UPDATE CASCADE",
      ), true);

      upd_alter_table('alliance', array(
        "ADD COLUMN ally_user_id BIGINT(20) UNSIGNED DEFAULT NULL",

        "ADD KEY `I_ally_user_id` (`ally_user_id`)",

        "ADD CONSTRAINT `FK_ally_ally_user_id` FOREIGN KEY (`ally_user_id`) REFERENCES `{$config->db_prefix}users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE",
      ), true);
    }

    // ------------------------------------------------------------------------
    // Creating players for allies
    $ally_row_list = doquery("SELECT `id`, `ally_tag` FROM {{alliance}} WHERE ally_user_id IS NULL;");
    while($ally_row = mysql_fetch_assoc($ally_row_list))
    {
      $ally_user_name = mysql_escape_string("[{$ally_row['ally_tag']}]");
      doquery("INSERT INTO {{users}} SET `username` = '{$ally_user_name}', `register_time` = {$time_now}, `user_as_ally` = {$ally_row['id']};");
      $ally_user_id = mysql_insert_id();
      doquery("UPDATE {{alliance}} SET ally_user_id = {$ally_user_id} WHERE id = {$ally_row['id']} LIMIT 1;");
    }
    // Renaming old ally players TODO: Remove on release
    upd_do_query("UPDATE {{users}} AS u LEFT JOIN {{alliance}} AS a ON u.user_as_ally = a.id SET u.username = CONCAT('[', a.ally_tag, ']') WHERE u.user_as_ally IS NOT NULL AND u.username = '';");
    // Setting last online time to old ally players TODO: Remove on release
    upd_do_query("UPDATE {{users}} SET `onlinetime` = {$time_now} WHERE onlinetime = 0;");

    // ------------------------------------------------------------------------
    // Creating planets for allies
    $ally_user_list = doquery("SELECT `id`, `username` FROM {{users}} WHERE `user_as_ally` IS NOT NULL AND `id_planet` = 0;");
    while($ally_user_row = mysql_fetch_assoc($ally_user_list))
    {
      $ally_planet_name = mysql_escape_string($ally_user_row['username']);
      doquery("INSERT INTO {{planets}} SET `name` = '{$ally_planet_name}', `last_update` = {$time_now}, `id_owner` = {$ally_user_row['id']};");
      $ally_planet_id = mysql_insert_id();
      doquery("UPDATE {{users}} SET `id_planet` = {$ally_planet_id} WHERE `id` = {$ally_user_row['id']} LIMIT 1;");
    }

    upd_do_query("UPDATE {{users}} AS u LEFT JOIN {{alliance}} AS a ON u.ally_id = a.id SET u.ally_name = a.ally_name, u.ally_tag = a.ally_tag WHERE u.ally_id IS NOT NULL;");

    upd_alter_table('users', array(
      "DROP COLUMN `rpg_amiral`",
      "DROP COLUMN `mrc_academic`",
      "DROP COLUMN `rpg_espion`",
      "DROP COLUMN `rpg_commandant`",
      "DROP COLUMN `rpg_stockeur`",
      "DROP COLUMN `rpg_destructeur`",
      "DROP COLUMN `rpg_general`",
      "DROP COLUMN `rpg_raideur`",
      "DROP COLUMN `rpg_empereur`",

      "ADD COLUMN `metal` decimal(65,5) NOT NULL DEFAULT '0.00000'",
      "ADD COLUMN `crystal` decimal(65,5) NOT NULL DEFAULT '0.00000'",
      "ADD COLUMN `deuterium` decimal(65,5) NOT NULL DEFAULT '0.00000'",
    ), $update_tables['users']['rpg_amiral']);


    // ========================================================================
    // User que
    // Adding db field
    upd_alter_table('users', "ADD `que` varchar(4096) NOT NULL DEFAULT '' COMMENT 'User que'", !$update_tables['users']['que']);
    // Converting old data to new one and dropping old fields
    if($update_tables['users']['b_tech_planet'])
    {
      $query = doquery("SELECT * FROM {{planets}} WHERE `b_tech_id` <> 0;");
      while($planet_row = mysql_fetch_assoc($query))
      {
        $que_item_string = "{$planet_row['b_tech_id']},1," . max(0, $planet_row['b_tech'] - $time_now) . "," . BUILD_CREATE . "," . QUE_RESEARCH;
        doquery("UPDATE {{users}} SET `que` = '{$que_item_string}' WHERE `id` = {$planet_row['id_owner']} LIMIT 1;");
      }

      upd_alter_table('planets', array(
        "DROP COLUMN `b_tech`",
        "DROP COLUMN `b_tech_id`",
      ), $update_tables['planets']['b_tech']);

      upd_alter_table('users', "DROP COLUMN `b_tech_planet`", $update_tables['users']['b_tech_planet']);
    }

    if(!$update_tables['powerup']['powerup_category'])
    {
      upd_alter_table('powerup', "ADD COLUMN `powerup_category` SMALLINT NOT NULL DEFAULT 0 AFTER `powerup_planet_id`", !$update_tables['powerup']['powerup_category']);

      doquery("UPDATE {{powerup}} SET powerup_category = " . BONUS_MERCENARY);
    }

    upd_check_key('rpg_cost_info', 10000, !isset($config->rpg_cost_info));
    upd_check_key('tpl_minifier', 0, !isset($config->tpl_minifier));

    upd_check_key('server_updater_check_auto', 0, !isset($config->server_updater_check_auto));
    upd_check_key('server_updater_check_period', PERIOD_DAY, !isset($config->server_updater_check_period));
    upd_check_key('server_updater_check_last', 0, !isset($config->server_updater_check_last));
    upd_check_key('server_updater_check_result', SNC_VER_NEVER, !isset($config->server_updater_check_result));
    upd_check_key('server_updater_key', '', !isset($config->server_updater_key));
    upd_check_key('server_updater_id', 0, !isset($config->server_updater_id));

    upd_check_key('ali_bonus_algorithm', 0, !isset($config->ali_bonus_algorithm));
    upd_check_key('ali_bonus_divisor', 10000000, !isset($config->ali_bonus_divisor));
    upd_check_key('ali_bonus_brackets', 10, !isset($config->ali_bonus_brackets));
    upd_check_key('ali_bonus_brackets_divisor', 50, !isset($config->ali_bonus_brackets_divisor));

    if(!$config->db_loadItem('rpg_flt_explore'))
    {
      $inflation_rate = 1000;

      $config->db_saveItem('rpg_cost_banker', $config->rpg_cost_banker * $inflation_rate);
      $config->db_saveItem('rpg_cost_exchange', $config->rpg_cost_exchange * $inflation_rate);
      $config->db_saveItem('rpg_cost_pawnshop', $config->rpg_cost_pawnshop * $inflation_rate);
      $config->db_saveItem('rpg_cost_scraper', $config->rpg_cost_scraper * $inflation_rate);
      $config->db_saveItem('rpg_cost_stockman', $config->rpg_cost_stockman * $inflation_rate);
      $config->db_saveItem('rpg_cost_trader', $config->rpg_cost_trader * $inflation_rate);

      $config->db_saveItem('rpg_exchange_darkMatter', $config->rpg_exchange_darkMatter / $inflation_rate * 4);

      $config->db_saveItem('rpg_flt_explore', $inflation_rate);

      doquery("UPDATE {{users}} SET `dark_matter` = `dark_matter` * {$inflation_rate};");

      $query = doquery("SELECT * FROM {{quest}}");
      while($row = mysql_fetch_assoc($query))
      {
        $query_add = '';
        $quest_reward_list = explode(';', $row['quest_rewards']);
        foreach($quest_reward_list as &$quest_reward)
        {
          list($reward_resource, $reward_amount) = explode(',', $quest_reward);
          if($reward_resource == RES_DARK_MATTER)
          {
            $quest_reward = "{$reward_resource}," . $reward_amount * 1000;
          }
        }
        $new_rewards = implode(';', $quest_reward_list);
        if($new_rewards != $row['quest_rewards'])
        {
          doquery("UPDATE {{quest}} SET `quest_rewards` = '{$new_rewards}' WHERE quest_id = {$row['quest_id']} LIMIT 1;");
        }
      }
    }

    upd_check_key('rpg_bonus_minimum', 10000, !isset($config->rpg_bonus_minimum));
    upd_check_key('rpg_bonus_divisor',
      !isset($config->rpg_bonus_divisor) ? 10 : ($config->rpg_bonus_divisor >= 1000 ? floor($config->rpg_bonus_divisor / 1000) : $config->rpg_bonus_divisor),
      !isset($config->rpg_bonus_divisor) || $config->rpg_bonus_divisor >= 1000);

    upd_check_key('var_news_last', 0, !isset($config->var_news_last));

    upd_do_query('COMMIT;', true);
    $new_version = 33;

  case 33:
    upd_log_version_update();

    upd_alter_table('users', array(
      "ADD `user_birthday` DATE DEFAULT NULL COMMENT 'User birthday'",
      "ADD `user_birthday_celebrated` DATE DEFAULT NULL COMMENT 'Last time where user got birthday gift'",

      "ADD KEY `I_user_birthday` (`user_birthday`, `user_birthday_celebrated`)",
    ), !$update_tables['users']['user_birthday']);

    upd_check_key('user_birthday_gift', 0, !isset($config->user_birthday_gift));
    upd_check_key('user_birthday_range', 30, !isset($config->user_birthday_range));
    upd_check_key('user_birthday_celebrate', 0, !isset($config->user_birthday_celebrate));

    if(!isset($update_tables['payment']))
    {
      upd_alter_table('users', array(
        "ADD KEY `I_user_id_name` (`id`, `username`)",
      ), !$update_indexes['users']['I_user_id_name']);

      upd_create_table('payment',
        "(
          `payment_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Internal payment ID',
          `payment_user_id` BIGINT(20) UNSIGNED DEFAULT NULL,
          `payment_user_name` VARCHAR(64) DEFAULT NULL,
          `payment_amount` DECIMAL(60,5) DEFAULT 0 COMMENT 'Amount paid',
          `payment_currency` VARCHAR(3) DEFAULT '' COMMENT 'Payment currency',
          `payment_dm` DECIMAL(65,0) DEFAULT 0 COMMENT 'DM gained',
          `payment_date` TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT 'Payment server timestamp',
          `payment_comment` TEXT COMMENT 'Payment comment',

          `payment_module_name` VARCHAR(255) DEFAULT '' COMMENT 'Payment module name',
          `payment_internal_id` VARCHAR(255) DEFAULT '' COMMENT 'Internal payment ID in payment system',
          `payment_internal_date` DATETIME COMMENT 'Internal payment timestamp in payment system',

          PRIMARY KEY (`payment_id`),
          KEY `I_payment_user` (`payment_user_id`, `payment_user_name`),
          KEY `I_payment_module_internal_id` (`payment_module_name`, `payment_internal_id`),

          CONSTRAINT `FK_payment_user` FOREIGN KEY (`payment_user_id`, `payment_user_name`) REFERENCES `{$config->db_prefix}users` (`id`, `username`) ON UPDATE CASCADE ON DELETE NO ACTION
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8;"
      );

      upd_check_key('payment_currency_default', 'UAH', !isset($config->payment_currency_default));
    }
    upd_check_key('payment_lot_size', 1000, !isset($config->payment_lot_size));
    upd_check_key('payment_lot_price', 1, !isset($config->payment_lot_price));

    // Updating category for Mercenaries
    upd_do_query("UPDATE {{powerup}} SET powerup_category = " . UNIT_MERCENARIES . " WHERE powerup_unit_id > 600 AND powerup_unit_id < 700;");

    // Convert Destructor to Death Star schematic
    upd_do_query("UPDATE {{powerup}}
      SET powerup_time_start = 0, powerup_time_finish = 0, powerup_category = " . UNIT_PLANS . ", powerup_unit_id = " . UNIT_PLAN_SHIP_DEATH_STAR . "
      WHERE (powerup_time_start = 0 OR powerup_time_finish >= UNIX_TIMESTAMP()) AND powerup_unit_id = 612;");
    // Convert Assasin to SuperNova schematic
    upd_do_query("UPDATE {{powerup}}
      SET powerup_time_start = 0, powerup_time_finish = 0, powerup_category = " . UNIT_PLANS . ", powerup_unit_id = " . UNIT_PLAN_SHIP_SUPERNOVA . "
      WHERE (powerup_time_start = 0 OR powerup_time_finish >= UNIX_TIMESTAMP()) AND powerup_unit_id = 614;");

    upd_alter_table('iraks', array(
      "ADD `fleet_start_type` SMALLINT NOT NULL DEFAULT 1",
      "ADD `fleet_end_type` SMALLINT NOT NULL DEFAULT 1",
    ), !$update_tables['iraks']['fleet_start_type']);


    if(!$update_tables['payment']['payment_status'])
    {
      upd_alter_table('payment', array(
        "ADD COLUMN `payment_status` INT DEFAULT 0 COMMENT 'Payment status' AFTER `payment_id`",

        "CHANGE COLUMN `payment_dm` `payment_dark_matter_paid` DECIMAL(65,0) DEFAULT 0 COMMENT 'Real DM paid for'",
        "ADD COLUMN `payment_dark_matter_gained` DECIMAL(65,0) DEFAULT 0 COMMENT 'DM gained by player (with bonuses)' AFTER `payment_dark_matter_paid`",

        "CHANGE COLUMN `payment_internal_id` `payment_external_id` VARCHAR(255) DEFAULT '' COMMENT 'External payment ID in payment system'",
        "CHANGE COLUMN `payment_internal_date` `payment_external_date` DATETIME COMMENT 'External payment timestamp in payment system'",
        "ADD COLUMN `payment_external_lots` decimal(65,5) NOT NULL DEFAULT '0.00000' COMMENT 'Payment system lot amount'",
        "ADD COLUMN `payment_external_amount` decimal(65,5) NOT NULL DEFAULT '0.00000' COMMENT 'Money incoming from payment system'",
        "ADD COLUMN `payment_external_currency` VARCHAR(3) NOT NULL DEFAULT '' COMMENT 'Payment system currency'",
      ), !$update_tables['payment']['payment_status']);
    }

    upd_do_query("UPDATE {{powerup}} SET powerup_time_start = 0, powerup_time_finish = 0 WHERE powerup_category = " . UNIT_PLANS . ";");

    upd_check_key('server_start_date', date('d.m.Y', $time_now), !isset($config->server_start_date));
    upd_check_key('server_que_length_structures', 5, !isset($config->server_que_length_structures));
    upd_check_key('server_que_length_hangar', 5, !isset($config->server_que_length_hangar));

    upd_check_key('chat_highlight_moderator', '<span class="nick_moderator">$1</span>', $config->chat_highlight_admin == '<font color=green>$1</font>');
    upd_check_key('chat_highlight_operator', '<span class="nick_operator">$1</span>', $config->chat_highlight_admin == '<font color=red>$1</font>');
    upd_check_key('chat_highlight_admin', '<span class="nick_admin">$1</span>', $config->chat_highlight_admin == '<font color=purple>$1</font>');

    upd_check_key('chat_highlight_premium', '<span class="nick_premium">$1</span>', !isset($config->chat_highlight_premium));

    upd_do_query("UPDATE {{planets}} SET `PLANET_GOVERNOR_LEVEL` = CEILING(`PLANET_GOVERNOR_LEVEL`/2) WHERE PLANET_GOVERNOR_ID = " . MRC_ENGINEER . " AND `PLANET_GOVERNOR_LEVEL` > 8;");


    upd_do_query('COMMIT;', true);
    $new_version = 34;

  case 34:
    upd_log_version_update();

    upd_alter_table('planets', array(
      "ADD COLUMN `planet_teleport_next` INT(11) NOT NULL DEFAULT 0 COMMENT 'Next teleport time'",
    ), !$update_tables['planets']['planet_teleport_next']);

    upd_check_key('planet_teleport_cost', 50000, !isset($config->planet_teleport_cost));
    upd_check_key('planet_teleport_timeout', PERIOD_DAY * 1, !isset($config->planet_teleport_timeout));

    upd_check_key('planet_capital_cost', 25000, !isset($config->planet_capital_cost));

    upd_alter_table('users', array(
      "ADD COLUMN `player_race` INT(11) NOT NULL DEFAULT 0 COMMENT 'Player\'s race'",
    ), !$update_tables['users']['player_race']);

    upd_alter_table('chat', array(
      "MODIFY COLUMN `user` TEXT COMMENT 'Chat message user name'",
    ), strtoupper($update_tables['chat']['user']['Type']) != 'TEXT');

    upd_alter_table('planets', array(
      "ADD `ship_sattelite_sloth` bigint(20) NOT NULL DEFAULT '0' COMMENT 'Terran Sloth'",
      "ADD `ship_bomber_envy` bigint(20) NOT NULL DEFAULT '0' COMMENT 'Lunar Envy'",
      "ADD `ship_recycler_gluttony` bigint(20) NOT NULL DEFAULT '0' COMMENT 'Mercurian Gluttony'",
      "ADD `ship_fighter_wrath` bigint(20) NOT NULL DEFAULT '0' COMMENT 'Venerian Wrath'",
      "ADD `ship_battleship_pride` bigint(20) NOT NULL DEFAULT '0' COMMENT 'Martian Pride'",
      "ADD `ship_cargo_greed` bigint(20) NOT NULL DEFAULT '0' COMMENT 'Republican Greed'",
    ), !$update_tables['planets']['ship_sattelite_sloth']);

    upd_alter_table('planets', array(
      "ADD `ship_sattelite_sloth_porcent` TINYINT(3) UNSIGNED NOT NULL DEFAULT '10' COMMENT 'Terran Sloth production'",
      "ADD KEY `I_ship_sattelite_sloth` (`ship_sattelite_sloth`, `id_level`)",
      "ADD KEY `I_ship_bomber_envy` (`ship_bomber_envy`, `id_level`)",
      "ADD KEY `I_ship_recycler_gluttony` (`ship_recycler_gluttony`, `id_level`)",
      "ADD KEY `I_ship_fighter_wrath` (`ship_fighter_wrath`, `id_level`)",
      "ADD KEY `I_ship_battleship_pride` (`ship_battleship_pride`, `id_level`)",
      "ADD KEY `I_ship_cargo_greed` (`ship_cargo_greed`, `id_level`)",
    ), !$update_tables['planets']['ship_sattelite_sloth_porcent']);

    upd_check_key('stats_hide_admins', 1, !isset($config->stats_hide_admins));
    upd_check_key('stats_hide_player_list', '', !isset($config->stats_hide_player_list));

    upd_check_key('adv_seo_meta_description', '', !isset($config->adv_seo_meta_description));
    upd_check_key('adv_seo_meta_keywords', '', !isset($config->adv_seo_meta_keywords));

    upd_check_key('stats_hide_pm_link', '0', !isset($config->stats_hide_pm_link));

    upd_alter_table('notes', array(
      "ADD INDEX `I_owner_priority_time` (`owner`, `priority`, `time`)",
    ), !$update_indexes['notes']['I_owner_priority_time']);

    if(!$update_tables['buddy']['BUDDY_ID'])
    {
      upd_alter_table('buddy', array(
        "CHANGE COLUMN `id` `BUDDY_ID` SERIAL COMMENT 'Buddy table row ID'",
        "CHANGE COLUMN `active` `BUDDY_STATUS` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Buddy request status'",
        "CHANGE COLUMN `text` `BUDDY_REQUEST` TINYTEXT DEFAULT '' COMMENT 'Buddy request text'", // 255 chars

        "DROP INDEX `id`",

        "DROP FOREIGN KEY `FK_buddy_sender_id`",
        "DROP FOREIGN KEY `FK_buddy_owner_id`",
        "DROP INDEX `I_buddy_sender`",
        "DROP INDEX `I_buddy_owner`",
      ), !$update_tables['buddy']['BUDDY_ID']);

      upd_alter_table('buddy', array(
        "CHANGE COLUMN `sender` `BUDDY_SENDER_ID` BIGINT(20) UNSIGNED NULL DEFAULT NULL COMMENT 'Buddy request sender ID'",
        "CHANGE COLUMN `owner` `BUDDY_OWNER_ID` BIGINT(20) UNSIGNED NULL DEFAULT NULL COMMENT 'Buddy request recipient ID'",
      ), !$update_tables['buddy']['BUDDY_SENDER']);

      $query = upd_do_query("SELECT `BUDDY_ID`, `BUDDY_SENDER_ID`, `BUDDY_OWNER_ID` FROM {{buddy}} ORDER BY `BUDDY_ID`;");
      $found = $lost = array();
      while($row = mysql_fetch_assoc($query))
      {
        $index = min($row['BUDDY_SENDER_ID'], $row['BUDDY_OWNER_ID']) . ';' . max($row['BUDDY_SENDER_ID'], $row['BUDDY_OWNER_ID']);
        if(!isset($found[$index]))
        {
          $found[$index] = $row['BUDDY_ID'];
        }
        else
        {
          $lost[] = $row['BUDDY_ID'];
        }
      }
      $lost = implode(',', $lost);
      if($lost)
      {
        upd_do_query("DELETE FROM {{buddy}} WHERE `BUDDY_ID` IN ({$lost})");
      }

      upd_alter_table('buddy', array(
          "ADD KEY `I_BUDDY_SENDER_ID` (`BUDDY_SENDER_ID`, `BUDDY_OWNER_ID`)",
          "ADD KEY `I_BUDDY_OWNER_ID` (`BUDDY_OWNER_ID`, `BUDDY_SENDER_ID`)",

          "ADD CONSTRAINT `FK_BUDDY_SENDER_ID` FOREIGN KEY (`BUDDY_SENDER_ID`) REFERENCES `{$config->db_prefix}users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE",
          "ADD CONSTRAINT `FK_BUDDY_OWNER_ID` FOREIGN KEY (`BUDDY_OWNER_ID`) REFERENCES `{$config->db_prefix}users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE",
      ), !$update_indexes['buddy']['I_BUDDY_SENDER_ID']);
    }

    upd_do_query('COMMIT;', true);
    $new_version = 35;

  case 35:
    upd_log_version_update();

    upd_do_query("UPDATE {{users}} SET `ally_name` = null, `ally_tag` = null, ally_register_time = 0, ally_rank_id = 0 WHERE `ally_id` IS NULL");

    if(!$update_tables['ube_report'])
    {
      upd_create_table('ube_report',
        "(
          `ube_report_id` SERIAL COMMENT 'Report ID',

          `ube_report_cypher` CHAR(32) NOT NULL DEFAULT '' COMMENT '16 char secret report ID',

          `ube_report_time_combat` DATETIME NOT NULL COMMENT 'Combat time',
          `ube_report_time_process` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Time when combat was processed',
          `ube_report_time_spent` DECIMAL(11,8) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Time in seconds spent for combat calculations',

          `ube_report_mission_type` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Mission type',
          `ube_report_combat_admin` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Does admin participates in combat?',

          `ube_report_combat_result` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Combat outcome',
          `ube_report_combat_sfr` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Small Fleet Reconnaissance',

          `ube_report_planet_id` BIGINT(20) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Player planet ID',
          `ube_report_planet_name` VARCHAR(64) NOT NULL DEFAULT 'Planet' COMMENT 'Player planet name',
          `ube_report_planet_size` SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Player diameter',
          `ube_report_planet_galaxy` SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Player planet coordinate galaxy',
          `ube_report_planet_system` SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Player planet coordinate system',
          `ube_report_planet_planet` SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Player planet coordinate planet',
          `ube_report_planet_planet_type` TINYINT NOT NULL DEFAULT 1 COMMENT 'Player planet type',

          `ube_report_moon` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Moon result: was, none, failed, created, destroyed',
          `ube_report_moon_chance` DECIMAL(9,6) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Moon creation chance',
          `ube_report_moon_size` SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Moon size',
          `ube_report_moon_reapers` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Moon reapers result: none, died, survived',
          `ube_report_moon_destroy_chance` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Moon destroy chance',
          `ube_report_moon_reapers_die_chance` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Moon reapers die chance',

          `ube_report_debris_metal` DECIMAL(65,0) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Metal debris',
          `ube_report_debris_crystal` DECIMAL(65,0) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Crystal debris',

          PRIMARY KEY (`ube_report_id`),
          KEY `I_ube_report_cypher` (`ube_report_cypher`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8;"
      );
    }

    if(!$update_tables['ube_report_player'])
    {
      upd_create_table('ube_report_player',
        "(
          `ube_report_player_id` SERIAL COMMENT 'Record ID',
          `ube_report_id` BIGINT(20) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Report ID',
          `ube_report_player_player_id` BIGINT(20) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Player ID',

          `ube_report_player_name` VARCHAR(64) NOT NULL DEFAULT '' COMMENT 'Player name',
          `ube_report_player_attacker` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Is player an attacker?',

          `ube_report_player_bonus_attack` DECIMAL(11,2) NOT NULL DEFAULT 0 COMMENT 'Player attack bonus', -- Only for statistics
          `ube_report_player_bonus_shield` DECIMAL(11,2) NOT NULL DEFAULT 0 COMMENT 'Player shield bonus', -- Only for statistics
          `ube_report_player_bonus_armor` DECIMAL(11,2) NOT NULL DEFAULT 0 COMMENT 'Player armor bonus', -- Only for statistics

          PRIMARY KEY (`ube_report_player_id`),
          KEY `I_ube_report_player_player_id` (`ube_report_player_player_id`),
          CONSTRAINT `FK_ube_report_player_ube_report` FOREIGN KEY (`ube_report_id`) REFERENCES `{$config->db_prefix}ube_report` (`ube_report_id`) ON UPDATE CASCADE ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8;"
      );
    }

    if(!$update_tables['ube_report_fleet'])
    {
      upd_create_table('ube_report_fleet',
        "(
          `ube_report_fleet_id` SERIAL COMMENT 'Record DB ID',

          `ube_report_id` BIGINT(20) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Report ID',
          `ube_report_fleet_player_id` BIGINT(20) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Owner ID',
          `ube_report_fleet_fleet_id` BIGINT(20) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Fleet ID',

          `ube_report_fleet_planet_id` BIGINT(20) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Player attack bonus',
          `ube_report_fleet_planet_name` VARCHAR(64) NOT NULL DEFAULT 'Planet' COMMENT 'Player planet name',
          `ube_report_fleet_planet_galaxy` SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Player planet coordinate galaxy',
          `ube_report_fleet_planet_system` SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Player planet coordinate system',
          `ube_report_fleet_planet_planet` SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Player planet coordinate planet',
          `ube_report_fleet_planet_planet_type` TINYINT NOT NULL DEFAULT 1 COMMENT 'Player planet type',

          `ube_report_fleet_bonus_attack` DECIMAL(11,2) NOT NULL DEFAULT 0 COMMENT 'Fleet attack bonus', -- Only for statistics
          `ube_report_fleet_bonus_shield` DECIMAL(11,2) NOT NULL DEFAULT 0 COMMENT 'Fleet shield bonus', -- Only for statistics
          `ube_report_fleet_bonus_armor` DECIMAL(11,2) NOT NULL DEFAULT 0 COMMENT 'Fleet armor bonus',   -- Only for statistics

          `ube_report_fleet_resource_metal` DECIMAL(65,0) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Fleet metal amount',
          `ube_report_fleet_resource_crystal` DECIMAL(65,0) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Fleet crystal amount',
          `ube_report_fleet_resource_deuterium` DECIMAL(65,0) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Fleet deuterium amount',

          PRIMARY KEY (`ube_report_fleet_id`),
          CONSTRAINT `FK_ube_report_fleet_ube_report` FOREIGN KEY (`ube_report_id`) REFERENCES `{$config->db_prefix}ube_report` (`ube_report_id`) ON UPDATE CASCADE ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8;"
      );
    }

    if(!$update_tables['ube_report_unit'])
    {
      // TODO: Сохранять так же имя корабля - на случай конструкторов - не, хуйня. Конструктор может давать имена разные на разных языках
      // Может сохранять имена удаленных кораблей долго?

      // round SIGNED!!! -1 например - для ауткома
      upd_create_table('ube_report_unit',
        "(
          `ube_report_unit_id` SERIAL COMMENT 'Record DB ID',

          `ube_report_id` BIGINT(20) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Report ID',
          `ube_report_unit_player_id` BIGINT(20) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Owner ID',
          `ube_report_unit_fleet_id` BIGINT(20) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Fleet ID',
          `ube_report_unit_round` TINYINT NOT NULL DEFAULT 0 COMMENT 'Round number',

          `ube_report_unit_unit_id` BIGINT(20) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Unit ID',
          `ube_report_unit_count` DECIMAL(65,0) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Unit count',
          `ube_report_unit_boom` SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Unit booms',

          `ube_report_unit_attack` DECIMAL(65,0) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Unit attack',
          `ube_report_unit_shield` DECIMAL(65,0) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Unit shield',
          `ube_report_unit_armor` DECIMAL(65,0) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Unit armor',

          `ube_report_unit_attack_base` DECIMAL(65,0) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Unit base attack',
          `ube_report_unit_shield_base` DECIMAL(65,0) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Unit base shield',
          `ube_report_unit_armor_base` DECIMAL(65,0) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Unit base armor',

          `ube_report_unit_sort_order` BIGINT(20) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Unit pass-through sort order to maintain same output',

          PRIMARY KEY (`ube_report_unit_id`),
          KEY `I_ube_report_unit_report_round_fleet_order` (`ube_report_id`, `ube_report_unit_round`, `ube_report_unit_fleet_id`, `ube_report_unit_sort_order`),
          KEY `I_ube_report_unit_report_unit_order` (`ube_report_id`, `ube_report_unit_sort_order`),
          KEY `I_ube_report_unit_order` (`ube_report_unit_sort_order`),
          CONSTRAINT `FK_ube_report_unit_ube_report` FOREIGN KEY (`ube_report_id`) REFERENCES `{$config->db_prefix}ube_report` (`ube_report_id`) ON UPDATE CASCADE ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8;"
      );
    }

    if(!$update_tables['ube_report_outcome_fleet'])
    {
      upd_create_table('ube_report_outcome_fleet',
        "(
          `ube_report_outcome_fleet_id` SERIAL COMMENT 'Record DB ID',

          `ube_report_id` BIGINT(20) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Report ID',
          `ube_report_outcome_fleet_fleet_id` BIGINT(20) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Fleet ID',

          `ube_report_outcome_fleet_resource_lost_metal` DECIMAL(65,0) NOT NULL DEFAULT 0 COMMENT 'Fleet metal loss from units',
          `ube_report_outcome_fleet_resource_lost_crystal` DECIMAL(65,0) NOT NULL DEFAULT 0 COMMENT 'Fleet crystal loss from units',
          `ube_report_outcome_fleet_resource_lost_deuterium` DECIMAL(65,0) NOT NULL DEFAULT 0 COMMENT 'Fleet deuterium loss from units',

          `ube_report_outcome_fleet_resource_dropped_metal` DECIMAL(65,0) NOT NULL DEFAULT 0 COMMENT 'Fleet metal dropped due reduced cargo',
          `ube_report_outcome_fleet_resource_dropped_crystal` DECIMAL(65,0) NOT NULL DEFAULT 0 COMMENT 'Fleet crystal dropped due reduced cargo',
          `ube_report_outcome_fleet_resource_dropped_deuterium` DECIMAL(65,0) NOT NULL DEFAULT 0 COMMENT 'Fleet deuterium dropped due reduced cargo',

          `ube_report_outcome_fleet_resource_loot_metal` DECIMAL(65,0) NOT NULL DEFAULT 0 COMMENT 'Looted/Lost from loot metal',
          `ube_report_outcome_fleet_resource_loot_crystal` DECIMAL(65,0) NOT NULL DEFAULT 0 COMMENT 'Looted/Lost from loot crystal',
          `ube_report_outcome_fleet_resource_loot_deuterium` DECIMAL(65,0) NOT NULL DEFAULT 0 COMMENT 'Looted/Lost from loot deuterium',

          `ube_report_outcome_fleet_resource_lost_in_metal` DECIMAL(65,0) NOT NULL DEFAULT 0 COMMENT 'Fleet total resource loss in metal',

          PRIMARY KEY (`ube_report_outcome_fleet_id`),
          KEY `I_ube_report_outcome_fleet_report_fleet` (`ube_report_id`, `ube_report_outcome_fleet_fleet_id`),
          CONSTRAINT `FK_ube_report_outcome_fleet_ube_report` FOREIGN KEY (`ube_report_id`) REFERENCES `{$config->db_prefix}ube_report` (`ube_report_id`) ON UPDATE CASCADE ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8;"
      );
    }

    if(!$update_tables['ube_report_outcome_unit'])
    {
      upd_create_table('ube_report_outcome_unit',
        "(
          `ube_report_outcome_unit_id` SERIAL COMMENT 'Record DB ID',

          `ube_report_id` BIGINT(20) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Report ID',
          `ube_report_outcome_unit_fleet_id` BIGINT(20) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Fleet ID',

          `ube_report_outcome_unit_unit_id` BIGINT(20) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Unit ID',
          `ube_report_outcome_unit_restored` DECIMAL(65,0) NOT NULL DEFAULT 0 COMMENT 'Unit restored',
          `ube_report_outcome_unit_lost` DECIMAL(65,0) NOT NULL DEFAULT 0 COMMENT 'Unit lost',

          `ube_report_outcome_unit_sort_order` BIGINT(20) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Unit pass-through sort order to maintain same output',

          PRIMARY KEY (`ube_report_outcome_unit_id`),
          KEY `I_ube_report_outcome_unit_report_order` (`ube_report_id`, `ube_report_outcome_unit_sort_order`),
          CONSTRAINT `FK_ube_report_outcome_unit_ube_report` FOREIGN KEY (`ube_report_id`) REFERENCES `{$config->db_prefix}ube_report` (`ube_report_id`) ON UPDATE CASCADE ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8;"
      );
    }

    if(!$update_tables['unit'])
    {
      upd_create_table('unit',
        "(
          `unit_id` SERIAL COMMENT 'Record ID',

          `unit_player_id` BIGINT(20) UNSIGNED DEFAULT NULL COMMENT 'Unit owner',
          `unit_location_type` TINYINT NOT NULL DEFAULT 0 COMMENT 'Location type: universe, user, planet (moon?), fleet',
          `unit_location_id` BIGINT(20) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Location ID',
          `unit_type` BIGINT(20) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Unit type',
          `unit_snid` BIGINT(20) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Unit SuperNova ID',
          `unit_level` DECIMAL(65,0) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Unit level or count - dependent of unit_type',

          PRIMARY KEY (`unit_id`),
          KEY `I_unit_player_location_snid` (`unit_player_id`, `unit_location_type`, `unit_location_id`, `unit_snid`),
          CONSTRAINT `FK_unit_player_id` FOREIGN KEY (`unit_player_id`) REFERENCES `{$config->db_prefix}users` (`id`) ON UPDATE CASCADE ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8;"
      );
    }

    if(!$update_tables['captain'])
    {
      upd_create_table('captain',
        "(
          `captain_id` SERIAL COMMENT 'Record ID',
          `captain_unit_id` BIGINT(20) UNSIGNED DEFAULT NULL COMMENT 'Link to `unit` record',

          `captain_xp` DECIMAL(65,0) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Captain expirience',
          `captain_level` BIGINT(20) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Captain level so far', -- Дублирует запись в unit

          `captain_shield` BIGINT(20) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Captain shield bonus level',
          `captain_armor` BIGINT(20) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Captain armor bonus level',
          `captain_attack` BIGINT(20) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Captain defense bonus level',

          PRIMARY KEY (`captain_id`),
          KEY `I_captain_unit_id` (`captain_unit_id`),
          CONSTRAINT `FK_captain_unit_id` FOREIGN KEY (`captain_unit_id`) REFERENCES `{$config->db_prefix}unit` (`unit_id`) ON UPDATE CASCADE ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8;"
      );
    }

    if(!$update_tables['fleets']['fleet_start_planet_id'])
    {
      upd_alter_table('fleets', array(
        "ADD `fleet_start_planet_id` BIGINT(20) UNSIGNED DEFAULT NULL COMMENT 'Fleet start planet ID' AFTER `fleet_start_time`",
        "ADD `fleet_end_planet_id` BIGINT(20) UNSIGNED DEFAULT NULL COMMENT 'Fleet end planet ID' AFTER `fleet_end_stay`",

        "ADD KEY `I_fleet_start_planet_id` (`fleet_start_planet_id`)",
        "ADD KEY `I_fleet_end_planet_id` (`fleet_end_planet_id`)",

        "ADD CONSTRAINT `FK_fleet_planet_start` FOREIGN KEY (`fleet_start_planet_id`) REFERENCES `{$config->db_prefix}planets` (`id`) ON DELETE SET NULL ON UPDATE CASCADE",
        "ADD CONSTRAINT `FK_fleet_planet_end` FOREIGN KEY (`fleet_end_planet_id`) REFERENCES `{$config->db_prefix}planets` (`id`) ON DELETE SET NULL ON UPDATE CASCADE",
      ), !$update_tables['fleets']['fleet_start_planet_id']);

      upd_do_query("
        UPDATE {{fleets}} AS f
         LEFT JOIN {{planets}} AS p_s ON p_s.galaxy = f.fleet_start_galaxy AND p_s.system = f.fleet_start_system AND p_s.planet = f.fleet_start_planet AND p_s.planet_type = f.fleet_start_type
         LEFT JOIN {{planets}} AS p_e ON p_e.galaxy = f.fleet_end_galaxy AND p_e.system = f.fleet_end_system AND p_e.planet = f.fleet_end_planet AND p_e.planet_type = f.fleet_end_type
        SET f.fleet_start_planet_id = p_s.id, f.fleet_end_planet_id = p_e.id
      ");
    }

    upd_alter_table('fleets', array("DROP COLUMN `processing_start`"), $update_tables['fleets']['processing_start']);

    if(!$update_tables['chat_player'])
    {
      upd_create_table('chat_player',
        "(
          `chat_player_id` SERIAL COMMENT 'Record ID',

          `chat_player_player_id` BIGINT(20) UNSIGNED DEFAULT NULL COMMENT 'Chat player record owner',
          `chat_player_activity` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Last player activity in chat',
          `chat_player_invisible` TINYINT NOT NULL DEFAULT 0 COMMENT 'Player invisibility',
          `chat_player_muted` INT(11) NOT NULL DEFAULT 0 COMMENT 'Player is muted',
          `chat_player_mute_reason` VARCHAR(256) NOT NULL DEFAULT '' COMMENT 'Player mute reason',

          PRIMARY KEY (`chat_player_id`),

          KEY `I_chat_player_id` (`chat_player_player_id`),

          CONSTRAINT `FK_chat_player_id` FOREIGN KEY (`chat_player_player_id`) REFERENCES `{$config->db_prefix}users` (`id`) ON UPDATE CASCADE ON DELETE CASCADE

        ) ENGINE=InnoDB DEFAULT CHARSET=utf8;"
      );
    }

    upd_alter_table('chat', array(
      "ADD `chat_message_sender_id` BIGINT(20) UNSIGNED DEFAULT NULL COMMENT 'Message sender ID' AFTER `messageid`",
      "ADD `chat_message_recipient_id` BIGINT(20) UNSIGNED DEFAULT NULL COMMENT 'Message recipient ID' AFTER `user`",

      "ADD KEY `I_chat_message_sender_id` (`chat_message_sender_id`)",
      "ADD KEY `I_chat_message_recipient_id` (`chat_message_recipient_id`)",

      "ADD CONSTRAINT `FK_chat_message_sender_user_id` FOREIGN KEY (`chat_message_sender_id`) REFERENCES `{$config->db_prefix}users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE",
      "ADD CONSTRAINT `FK_chat_message_sender_recipient_id` FOREIGN KEY (`chat_message_recipient_id`) REFERENCES `{$config->db_prefix}users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE",
    ), !$update_tables['chat']['chat_message_sender_id']);

    upd_alter_table('chat', array(
      "ADD `chat_message_sender_name` VARCHAR(64) DEFAULT '' COMMENT 'Message sender name' AFTER `chat_message_sender_id`",
      "ADD `chat_message_recipient_name` VARCHAR(64) DEFAULT '' COMMENT 'Message sender name' AFTER `chat_message_recipient_id`",
    ), !$update_tables['chat']['chat_message_sender_name']);

    upd_alter_table('users', array(
      "MODIFY COLUMN `banaday` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'User ban status'",
    ), strtoupper($update_tables['users']['banaday']['Null']) == 'YES');

    upd_alter_table('banned', array(
      "ADD `ban_user_id` BIGINT(20) UNSIGNED DEFAULT NULL COMMENT 'Banned user ID' AFTER `ban_id`",
      "ADD `ban_issuer_id` BIGINT(20) UNSIGNED DEFAULT NULL COMMENT 'Banner ID' AFTER `ban_until`",

      "ADD KEY `I_ban_user_id` (`ban_user_id`)",
      "ADD KEY `I_ban_issuer_id` (`ban_issuer_id`)",

      "ADD CONSTRAINT `FK_ban_user_id` FOREIGN KEY (`ban_user_id`) REFERENCES `{$config->db_prefix}users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE",
      "ADD CONSTRAINT `FK_ban_issuer_id` FOREIGN KEY (`ban_issuer_id`) REFERENCES `{$config->db_prefix}users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE",
    ), !$update_tables['banned']['ban_user_id']);

    upd_do_query('COMMIT;', true);
    $new_version = 36;

  case 36:
    upd_log_version_update();

    upd_alter_table('payment', array(
      "DROP FOREIGN KEY `FK_payment_user`",
    ), $update_foreigns['payment']['FK_payment_user']);

    if($update_foreigns['chat']['FK_chat_message_sender_user_id'] != 'chat_message_sender_id,users,id;')
    {
      upd_alter_table('chat', array(
        "DROP FOREIGN KEY `FK_chat_message_sender_user_id`",
        "DROP FOREIGN KEY `FK_chat_message_sender_recipient_id`",
      ), true);

      upd_alter_table('chat', array(
        "ADD CONSTRAINT `FK_chat_message_sender_user_id` FOREIGN KEY (`chat_message_sender_id`) REFERENCES `{$config->db_prefix}users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE",
        "ADD CONSTRAINT `FK_chat_message_sender_recipient_id` FOREIGN KEY (`chat_message_recipient_id`) REFERENCES `{$config->db_prefix}users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE",
      ), true);
    }

    upd_alter_table('users', array(
      "ADD `user_time_diff` INT(11) DEFAULT NULL COMMENT 'User time difference with server time' AFTER `onlinetime`",
      "ADD `user_time_diff_forced` TINYINT(1) DEFAULT 0 COMMENT 'User time difference forced with time zone selection flag' AFTER `user_time_diff`",
    ), !$update_tables['users']['user_time_diff']);

    upd_alter_table('planets', array(
      "ADD `ship_orbital_heavy` bigint(20) NOT NULL DEFAULT '0' COMMENT 'HOPe - Heavy Orbital Platform'",
    ), !$update_tables['planets']['ship_orbital_heavy']);

    upd_check_key('chat_refresh_rate', 5, !isset($config->chat_refresh_rate));

    upd_alter_table('chat_player', array(
      "ADD `chat_player_refresh_last`  INT(11) NOT NULL DEFAULT 0 COMMENT 'Player last refresh time'",

      "ADD KEY `I_chat_player_refresh_last` (`chat_player_refresh_last`)",
    ), !$update_tables['chat_player']['chat_player_refresh_last']);

    upd_alter_table('ube_report', array(
      "ADD KEY `I_ube_report_time_combat` (`ube_report_time_combat`)",
    ), !$update_indexes['ube_report']['I_ube_report_time_combat']);

    if(!$update_tables['unit']['unit_time_start'])
    {
      upd_alter_table('unit', array(
        "ADD COLUMN `unit_time_start` DATETIME NULL DEFAULT NULL COMMENT 'Unit activation start time'",
        "ADD COLUMN `unit_time_finish` DATETIME NULL DEFAULT NULL COMMENT 'Unit activation end time'",
      ), !$update_tables['unit']['unit_time_start']);

      upd_do_query(
        "INSERT INTO {{unit}}
          (unit_player_id, unit_location_type, unit_location_id, unit_type, unit_snid, unit_level, unit_time_start, unit_time_finish)
        SELECT
          `powerup_user_id`, " . LOC_USER . ", `powerup_user_id`, `powerup_category`, `powerup_unit_id`, `powerup_unit_level`
          , IF(`powerup_time_start`, FROM_UNIXTIME(`powerup_time_start`), NULL), IF(`powerup_time_finish`, FROM_UNIXTIME(`powerup_time_finish`), NULL)
        FROM {{powerup}}"
      );
    }




    if(!$update_tables['que'])
    {
      upd_create_table('que',
        "(
          `que_id` SERIAL COMMENT 'Internal que id',

          `que_player_id` BIGINT(20) UNSIGNED NULL DEFAULT NULL COMMENT 'Que owner ID',
          `que_planet_id` BIGINT(20) UNSIGNED NULL DEFAULT NULL COMMENT 'Which planet this que item belongs',
          `que_planet_id_origin` BIGINT(20) UNSIGNED NULL DEFAULT NULL COMMENT 'Planet spawner ID',
          `que_type` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Que type',
          `que_time_left` DECIMAL(20,5) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Build time left from last activity',

          `que_unit_id` BIGINT(20) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Unit ID',
          `que_unit_amount` BIGINT(20) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Amount left to build',
          `que_unit_mode` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Build/Destroy',

          `que_unit_level` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Unit level. Informational field',
          `que_unit_time` DECIMAL(20,5) NOT NULL DEFAULT 0 COMMENT 'Time to build one unit. Informational field',
          `que_unit_price` VARCHAR(128) NOT NULL DEFAULT '' COMMENT 'Price per unit - for correct trim/clear in case of global price events',

          PRIMARY KEY (`que_id`),
          KEY `I_que_player_type_planet` (`que_player_id`, `que_type`, `que_planet_id`, `que_id`), -- For main search
          KEY `I_que_player_type` (`que_player_id`, `que_type`, `que_id`), -- For main search
          KEY `I_que_planet_id` (`que_planet_id`), -- For constraint

          CONSTRAINT `FK_que_player_id` FOREIGN KEY (`que_player_id`) REFERENCES `{$config->db_prefix}users` (`id`) ON UPDATE CASCADE ON DELETE CASCADE,
          CONSTRAINT `FK_que_planet_id` FOREIGN KEY (`que_planet_id`) REFERENCES `{$config->db_prefix}planets` (`id`) ON UPDATE CASCADE ON DELETE CASCADE,
          CONSTRAINT `FK_que_planet_id_origin` FOREIGN KEY (`que_planet_id_origin`) REFERENCES `{$config->db_prefix}planets` (`id`) ON UPDATE CASCADE ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8;"
      );
    }

    // Конвертирум очередь исследований
    if($update_tables['users']['que'])
    {
      // upd_do_query("DELETE FROM {{que}}");

      $que_lines = array();
      $que_query = upd_do_query("SELECT * FROM {{users}} WHERE `que`");
      while($que_row = mysql_fetch_assoc($que_query))
      {
        $que_data = explode(',', $que_row['que']);

        if(!in_array($que_data[QI_UNIT_ID], $sn_data['groups']['tech']))
        {
          continue;
        }

        $que_data[QI_TIME] = $que_data[QI_TIME] >= 0 ? $que_data[QI_TIME] : 0;
        // Если планета пустая - ставим главку
        $que_data[QI_PLANET_ID] = $que_data[QI_PLANET_ID] ? $que_data[QI_PLANET_ID] : $que_row['id_planet'];
        if($que_data[QI_PLANET_ID])
        {
          $que_planet_check = mysql_fetch_assoc(upd_do_query("SELECT `id` FROM {{planets}} WHERE `id` = {$que_data[QI_PLANET_ID]}"));
          if(!$que_planet_check['id'])
          {
            $que_data[QI_PLANET_ID] = $que_row['id_planet'];
            $que_planet_check = mysql_fetch_assoc(upd_do_query("SELECT `id` FROM {{planets}} WHERE `id` = {$que_data[QI_PLANET_ID]}"));
            if(!$que_planet_check['id'])
            {
              $que_data[QI_PLANET_ID] = 'NULL';
            }
          }
        }
        else
        {
          $que_data[QI_PLANET_ID] = 'NULL';
        }

        $unit_level = $que_row[$sn_data[$que_data[QI_UNIT_ID]]['name']];
        $unit_factor = $sn_data[$que_data[QI_UNIT_ID]]['cost']['factor'] ? $sn_data[$que_data[QI_UNIT_ID]]['cost']['factor'] : 1;
        $price_increase = pow($unit_factor, $unit_level);
        $unit_level++;
        $unit_cost = array();
        foreach($sn_data[$que_data[QI_UNIT_ID]]['cost'] as $resource_id => $resource_amount)
        {
          if($resource_id === 'factor' || $resource_id == RES_ENERGY || !($resource_cost = $resource_amount * $price_increase))
          {
            continue;
          }
          $unit_cost[] = $resource_id . ',' . floor($resource_cost);
        }
        $unit_cost = implode(';', $unit_cost);

        $que_lines[] = "({$que_row['id']},{$que_data[QI_PLANET_ID]}," . QUE_RESEARCH . ",{$que_data[QI_TIME]},{$que_data[QI_UNIT_ID]},1," .
          BUILD_CREATE . ",{$unit_level},{$que_data[QI_TIME]},'{$unit_cost}')";
      }

      if(!empty($que_lines))
      {
        upd_do_query('INSERT INTO `{{que}}` (`que_player_id`,`que_planet_id_origin`,`que_type`,`que_time_left`,`que_unit_id`,`que_unit_amount`,`que_unit_mode`,`que_unit_level`,`que_unit_time`,`que_unit_price`) VALUES ' . implode(',', $que_lines));
        //upd_do_query("UPDATE `{{users}}` SET `que` = '' WHERE `que`");
      }

      upd_alter_table('users', array(
        "DROP COLUMN `que`",
      ), $update_tables['users']['que']);
    }


    upd_check_key('server_que_length_research', 1, !isset($config->server_que_length_research));


    // Ковертируем технологии в таблицы
    if($update_tables['users']['graviton_tech'])
    {
      upd_do_query("DELETE FROM {{unit}} WHERE unit_type = " . UNIT_TECHNOLOGIES);

      $que_lines = array();
      $user_query = upd_do_query("SELECT * FROM {{users}}");
      upd_add_more_time(300);
      while($user_row = mysql_fetch_assoc($user_query))
      {
        foreach($sn_data['groups']['tech'] as $tech_id)
        {
          if($tech_level = intval($user_row[$sn_data[$tech_id]['name']]))
          {
            $que_lines[] = "({$user_row['id']}," . LOC_USER . ",{$user_row['id']}," . UNIT_TECHNOLOGIES . ",{$tech_id},{$tech_level})";
          }
        }
      }

      if(!empty($que_lines))
      {
        upd_do_query("INSERT INTO {{unit}} (unit_player_id, unit_location_type, unit_location_id, unit_type, unit_snid, unit_level) VALUES " . implode(',', $que_lines));
      }

      upd_alter_table('users', array(
        "DROP COLUMN `graviton_tech`",
      ), $update_tables['users']['graviton_tech']);
    }

/*
    $planet_units = array('ship_sattelite_sloth' => 1, 'ship_bomber_envy' => 1, 'ship_recycler_gluttony' => 1, 'ship_fighter_wrath' => 1, 'ship_battleship_pride' => 1, 'ship_cargo_greed' => 1,
      'unit_ship_shadow' => 0, 'unit_ship_hornet' => 0, 'unit_ship_hive' => 0);
*/

    if(!$update_indexes['unit']['I_unit_record_search'])
    {
      upd_alter_table('unit', array(
        "ADD KEY `I_unit_record_search` (`unit_snid`,`unit_player_id`,`unit_level` DESC,`unit_id`)",
      ), !$update_indexes['unit']['I_unit_record_search']);

      foreach(array_merge($sn_data['groups']['structures'], $sn_data['groups']['fleet'], $sn_data['groups']['defense']) as $unit_id)
      {
        $planet_units[$sn_data[$unit_id]['name']] = 1; // $sn_data[$unit_id]['type'] != UNIT_SHIPS;
      }
      $drop_index = array();
      $create_index = &$drop_index; // array();
      foreach($planet_units as $unit_name => $unit_create)
      {
        if($update_indexes['planets']['I_' . $unit_name])
        {
          $drop_index[] = "DROP KEY I_{$unit_name}";
        }
        if($update_indexes['planets']['i_' . $unit_name])
        {
          $drop_index[] = "DROP KEY i_{$unit_name}";
        }

        if($unit_create)
        {
          $create_index[] = "ADD KEY `I_{$unit_name}` (`id_owner`, {$unit_name} DESC)";
        }
      }
      upd_alter_table('planets', $drop_index, true);
    }
//    upd_alter_table('planets', $create_index, true);

    upd_alter_table('users', array(
      "ADD `user_time_utc_offset` INT(11) DEFAULT NULL COMMENT 'User time difference with server time' AFTER `user_time_diff`",
    ), !$update_tables['users']['user_time_utc_offset']);

    if(!$update_foreigns['alliance']['FK_alliance_owner'])
    {
      upd_do_query("UPDATE {{alliance}} SET ally_owner = null WHERE ally_owner not in (select id from {{users}})");

      upd_alter_table('alliance', array(
        "ADD CONSTRAINT `FK_alliance_owner` FOREIGN KEY (`ally_owner`) REFERENCES `{$config->db_prefix}users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE",
      ), !$update_foreigns['alliance']['FK_alliance_owner']);

      upd_do_query("DELETE FROM {{alliance_negotiation}} WHERE alliance_negotiation_ally_id not in (select id from {{alliance}}) OR alliance_negotiation_contr_ally_id not in (select id from {{alliance}})");

      upd_do_query("DELETE FROM {{alliance_negotiation}} WHERE alliance_negotiation_ally_id = alliance_negotiation_contr_ally_id");
      upd_do_query("DELETE FROM {{alliance_diplomacy}} WHERE alliance_diplomacy_ally_id = alliance_diplomacy_contr_ally_id");
    }

//    fleets
//    fleet_owner

    upd_alter_table('fleets', array(
      'MODIFY COLUMN `fleet_owner` BIGINT(20) UNSIGNED DEFAULT NULL',
      "ADD CONSTRAINT `FK_fleet_owner` FOREIGN KEY (`fleet_owner`) REFERENCES `{$config->db_prefix}users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE",
    ), strtoupper($update_tables['fleets']['fleet_owner']['Type']) != 'BIGINT(20) UNSIGNED');

    upd_check_key('chat_highlight_developer', '<span class="nick_developer">$1</span>', !$config->chat_highlight_developer);

    upd_check_key('payment_currency_exchange_dm_', 2500,             !$config->payment_currency_exchange_dm_ || $config->payment_currency_exchange_dm_ == 1000);
    upd_check_key('payment_currency_exchange_usd', 0.122699,         !$config->payment_currency_exchange_usd);
    upd_check_key('payment_currency_exchange_wme', 0.09223050247178, !$config->payment_currency_exchange_usd);
    upd_check_key('payment_currency_exchange_wmr', 3.93,             !$config->payment_currency_exchange_wmr);
    upd_check_key('payment_currency_exchange_wmu', 1,                !$config->payment_currency_exchange_wmu);
    upd_check_key('payment_currency_exchange_wmz', 0.1204238921002,  !$config->payment_currency_exchange_wmz);

    if(!$update_tables['player_name_history'])
    {
      upd_check_key('game_user_changename_cost', 100000, !$config->game_user_changename_cost);
      upd_check_key('game_user_changename', SERVER_PLAYER_NAME_CHANGE_PAY, $config->game_user_changename != SERVER_PLAYER_NAME_CHANGE_PAY);

      upd_alter_table('users', array(
        "CHANGE COLUMN `username` `username` VARCHAR(32) NOT NULL DEFAULT '' COMMENT 'Player name'",
      ));

      upd_create_table('player_name_history',
        "(
          `player_id` BIGINT(20) UNSIGNED NULL DEFAULT NULL COMMENT 'Player ID',
          `player_name` VARCHAR(32) NOT NULL COMMENT 'Historical player name',
          `timestamp` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'When player changed name',

          PRIMARY KEY (`player_name`),
          KEY `I_player_name_history_id_name` (`player_id`, `player_name`),

          CONSTRAINT `FK_player_name_history_id` FOREIGN KEY (`player_id`) REFERENCES `{$config->db_prefix}users` (`id`) ON UPDATE CASCADE ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8;"
      );

      upd_do_query("REPLACE INTO {{player_name_history}} (`player_id`, `player_name`) SELECT `id`, `username` FROM {{users}} WHERE `user_as_ally` IS NULL;");
    }

    upd_alter_table('planets', array(
      "ADD `density` SMALLINT NOT NULL DEFAULT 5500 COMMENT 'Planet average density kg/m3'",
      "ADD `density_index` TINYINT NOT NULL DEFAULT " . PLANET_DENSITY_STANDARD . " COMMENT 'Planet cached density index'",
    ), !$update_tables['planets']['density_index']);

    if($update_tables['users']['player_artifact_list'])
    {
      upd_alter_table('unit', "DROP KEY `unit_id`", $update_indexes['unit']['unit_id']);

      upd_alter_table('unit', array(
        "ADD KEY `I_unit_player_id_temporary` (`unit_player_id`)",
        "DROP KEY `I_unit_player_location_snid`",
      ));
      upd_alter_table('unit', array(
        "ADD UNIQUE KEY `I_unit_player_location_snid` (`unit_player_id`, `unit_location_type`, `unit_location_id`, `unit_snid`)",
        "DROP KEY `I_unit_player_id_temporary`",
      ));

      $sn_data_artifacts = &$sn_data['groups']['artifacts'];
      $db_changeset = array();

      $query = upd_do_query("SELECT `id`, `player_artifact_list` FROM {{users}} WHERE `player_artifact_list` IS NOT NULL AND `player_artifact_list` != '' FOR UPDATE");
      while($row = mysql_fetch_assoc($query))
      {
        $artifact_list = explode(';', $row['player_artifact_list']);
        if(!$row['player_artifact_list'] || empty($artifact_list))
        {
          continue;
        }
        foreach($artifact_list as $key => &$value)
        {
          $value = explode(',', $value);
          if(!isset($value[1]) || $value[1] <= 0 || !isset($sn_data_artifacts[$value[0]]))
          {
            unset($artifact_list[$key]);
            continue;
          }
          $db_changeset['unit'][] = sn_db_unit_changeset_prepare($value[0], $value[1], $row);
        }
      }
      sn_db_changeset_apply($db_changeset);

      upd_alter_table('users', "DROP COLUMN `player_artifact_list`", $update_tables['users']['player_artifact_list']);
    }

    upd_alter_table('users', array(
      "DROP COLUMN `spy_tech`",
      "DROP COLUMN `computer_tech`",
      "DROP COLUMN `military_tech`",
      "DROP COLUMN `defence_tech`",
      "DROP COLUMN `shield_tech`",
      "DROP COLUMN `energy_tech`",
      "DROP COLUMN `hyperspace_tech`",
      "DROP COLUMN `combustion_tech`",
      "DROP COLUMN `impulse_motor_tech`",
      "DROP COLUMN `hyperspace_motor_tech`",
      "DROP COLUMN `laser_tech`",
      "DROP COLUMN `ionic_tech`",
      "DROP COLUMN `buster_tech`",
      "DROP COLUMN `intergalactic_tech`",
      "DROP COLUMN `expedition_tech`",
      "DROP COLUMN `colonisation_tech`",
    ), $update_tables['users']['spy_tech']);

/*
    upd_alter_table('planets', array(
      "ADD CONSTRAINT `FK_planet_owner` FOREIGN KEY (`id_owner`) REFERENCES `{$config->db_prefix}users` (`id`) ON DELETE CASCADE NULL ON UPDATE CASCADE",
    ), !$update_tables['planets']['FK_planet_owner']);
*/

/*
    upd_alter_table('banned', array(
      "DROP CONSTRAINT `FK_ban_user_id`",
    ), $update_foreigns['banned']['FK_ban_user_id']);

    upd_alter_table('banned', array(
      "ADD CONSTRAINT `FK_ban_user_id` FOREIGN KEY (`ban_user_id`) REFERENCES `{$config->db_prefix}users` (`id`) ON DELETE CASCADE NULL ON UPDATE CASCADE",
    ), !$update_tables['banned']['FK_ban_user_id']);
*/

    upd_do_query('COMMIT;', true);
//    $new_version = 37;
};
upd_log_message('Upgrade complete.');

upd_do_query('SET FOREIGN_KEY_CHECKS=1;');

if($new_version)
{
  $config->db_saveItem('db_version', $new_version);
  upd_log_message("<font color=green>DB version is now {$new_version}</font>");
}
else
{
  upd_log_message("DB version didn't changed from {$config->db_version}");
}

$config->db_loadAll();

if($user['authlevel'] >= 3)
{
  print(str_replace("\r\n", '<br>', $upd_log));
}

unset($sn_cache->tables);
sys_refresh_tablelist($config->db_prefix);

upd_log_message('Restoring server status');
$config->db_saveItem('game_disable', $old_server_status);
$config->db_saveItem('game_disable_reason', $old_server_reason);
