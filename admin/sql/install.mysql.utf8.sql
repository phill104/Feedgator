CREATE TABLE IF NOT EXISTS `#__feedgator` (
	`id` int(10) NOT NULL AUTO_INCREMENT,
	`title` varchar(100) NOT NULL DEFAULT 'Untitled',
	`feed` text NOT NULL DEFAULT '',
	`content_type` varchar(50) DEFAULT NULL,
	`sectionid` int(10) NOT NULL DEFAULT 0,
	`catid` int(10) NOT NULL DEFAULT 0,
	`default_author` varchar(100) DEFAULT NULL,
	`default_introtext` varchar(250) DEFAULT NULL,
	`created_by` int(11) NOT NULL DEFAULT 0,
	`created` datetime NOT NULL DEFAULT '1000-01-01 00:00:00',
	`checked_out` int(11) unsigned NOT NULL DEFAULT 0,
	`checked_out_time` datetime NOT NULL DEFAULT '1000-01-01 00:00:00',
	`last_run` datetime NOT NULL DEFAULT '1000-01-01 00:00:00',
	`last_email` int(11) NOT NULL DEFAULT 0,
	`published` tinyint(1) NOT NULL DEFAULT 0,
	`front_page` tinyint(1) NOT NULL DEFAULT 0,
	`filtering` tinyint(1) NOT NULL DEFAULT 0,
	`filter_whitelist` text NOT NULL DEFAULT '',
	`filter_blacklist` text NOT NULL DEFAULT '',
	`params` text NOT NULL DEFAULT '',
	`imports` text NOT NULL DEFAULT '',
	PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `#__feedgator_imports` (
	`id` int(11) unsigned NOT NULL AUTO_INCREMENT,
	`content_id` int(11) NOT NULL,
	`plugin` text NOT NULL DEFAULT '',
	`feed_id` int(11) NOT NULL,
	`hash` text NOT NULL DEFAULT '',
	PRIMARY KEY (`id`),
	KEY `idx_feed_id` (`feed_id`),
	KEY `idx_content_id` (`content_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `#__feedgator_plugins` (
	`id` int(11) unsigned NOT NULL AUTO_INCREMENT,
	`extension` varchar(100) NOT NULL,
	`published` int(1) NOT NULL DEFAULT 0,
	`params` text NOT NULL DEFAULT '',
	PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `#__feedgator_plugins` (`extension`, `published`, `params`)
SELECT * FROM (SELECT 'com_content' AS extension, 1 AS published, '-2{}' AS params) AS tmp
WHERE NOT EXISTS (SELECT 1 FROM `#__feedgator_plugins` WHERE `extension` = 'com_content') LIMIT 1;

-- K2 support was dropped (K2 doesn't support Joomla 4+ anyway - see
-- MIGRATION_REPORT.md). Remove any leftover row from an earlier install.
DELETE FROM `#__feedgator_plugins` WHERE `extension` = 'com_k2';
