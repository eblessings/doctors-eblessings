-- phpMyAdmin SQL Dump
-- version 2.11.9.4
-- http://www.phpmyadmin.net
--

SET SQL_MODE="NO_AUTO_VALUE_ON_ZERO";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8 */;

--
--

-- --------------------------------------------------------

--
-- Table structure for table `challenge`
--

CREATE TABLE IF NOT EXISTS `challenge` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `challenge` char(255) NOT NULL,
  `dfrn-id` char(255) NOT NULL,
  `expire` int(11) NOT NULL,
  `type` char(255) NOT NULL,
  `last_update` char(255) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=MyISAM  DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

CREATE TABLE IF NOT EXISTS `config` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `cat` char(255) NOT NULL,
  `k` char(255) NOT NULL,
  `v` text NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8;




--
-- Table structure for table `contact`
--

CREATE TABLE IF NOT EXISTS `contact` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `uid` int(11) NOT NULL COMMENT 'owner uid',
  `created` datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  `self` tinyint(1) NOT NULL DEFAULT '0',
  `remote_self` tinyint(1) NOT NULL DEFAULT '0',
  `rel` tinyint(1) NOT NULL DEFAULT '0',
  `duplex` tinyint(1) NOT NULL DEFAULT '0',
  `network` char(255) NOT NULL,
  `name` char(255) NOT NULL,
  `nick` char(255) NOT NULL,
  `attag` char(255) NOT NULL,
  `photo` text NOT NULL, 
  `thumb` text NOT NULL,
  `micro` text NOT NULL,
  `site-pubkey` text NOT NULL,
  `issued-id` char(255) NOT NULL,
  `dfrn-id` char(255) NOT NULL,
  `url` char(255) NOT NULL,
  `nurl` char(255) NOT NULL,
  `addr` char(255) NOT NULL,
  `alias` char(255) NOT NULL,
  `pubkey` text NOT NULL,
  `prvkey` text NOT NULL,
  `batch` char(255) NOT NULL,
  `request` text NOT NULL,
  `notify` text NOT NULL,
  `poll` text NOT NULL,
  `confirm` text NOT NULL,
  `poco` text NOT NULL,
  `aes_allow` tinyint(1) NOT NULL DEFAULT '0',
  `ret-aes` tinyint(1) NOT NULL DEFAULT '0',
  `usehub` tinyint(1) NOT NULL DEFAULT '0',
  `subhub` tinyint(1) NOT NULL DEFAULT '0',
  `hub-verify` char(255) NOT NULL,
  `last-update` datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  `success_update` datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  `name-date` datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  `uri-date` datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  `avatar-date` datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  `term-date` datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  `priority` tinyint(3) NOT NULL,
  `blocked` tinyint(1) NOT NULL DEFAULT '1',
  `readonly` tinyint(1) NOT NULL DEFAULT '0',
  `writable` tinyint(1) NOT NULL DEFAULT '0',
  `pending` tinyint(1) NOT NULL DEFAULT '1',
  `rating` tinyint(1) NOT NULL DEFAULT '0',
  `reason` text NOT NULL,
  `info` mediumtext NOT NULL,
  `profile-id` int(11) NOT NULL DEFAULT '0',
  `bdyear` CHAR( 4 ) NOT NULL COMMENT 'birthday notify flag',
  `bd` date NOT NULL,
  PRIMARY KEY (`id`),
  KEY `uid` (`uid`),
  KEY `self` (`self`),
  KEY `network` (`network`),
  KEY `name` (`name`),
  KEY `nick` (`nick`),
  KEY `attag` (`attag`),
  KEY `url` (`url`),
  KEY `nurl` (`nurl`),
  KEY `addr` (`addr`),
  KEY `batch` (`batch`),
  KEY `issued-id` (`issued-id`),
  KEY `dfrn-id` (`dfrn-id`),
  KEY `blocked` (`blocked`),
  KEY `readonly` (`readonly`)  
) ENGINE=MyISAM  DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- Table structure for table `group`
--

CREATE TABLE IF NOT EXISTS `group` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `uid` int(10) unsigned NOT NULL,
  `visible` tinyint(1) NOT NULL DEFAULT '0',
  `deleted` tinyint(1) NOT NULL DEFAULT '0',
  `name` char(255) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=MyISAM  DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- Table structure for table `group_member`
--

CREATE TABLE IF NOT EXISTS `group_member` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `uid` int(10) unsigned NOT NULL,
  `gid` int(10) unsigned NOT NULL,
  `contact-id` int(10) unsigned NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=MyISAM  DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- Table structure for table `intro`
--

CREATE TABLE IF NOT EXISTS `intro` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `uid` int(10) unsigned NOT NULL,
  `fid` int(11) NOT NULL DEFAULT '0',
  `contact-id` int(11) NOT NULL,
  `knowyou` tinyint(1) NOT NULL,
  `duplex` tinyint(1) NOT NULL DEFAULT '0',
  `note` text NOT NULL,
  `hash` char(255) NOT NULL,
  `datetime` datetime NOT NULL,
  `blocked` tinyint(1) NOT NULL DEFAULT '1',
  `ignore` tinyint(1) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`)
) ENGINE=MyISAM  DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- Table structure for table `item`
--

CREATE TABLE IF NOT EXISTS `item` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `guid` char(64) NOT NULL,
  `uri` char(255) NOT NULL,
  `uid` int(10) unsigned NOT NULL DEFAULT '0',
  `contact-id` int(10) unsigned NOT NULL DEFAULT '0',
  `type` char(255) NOT NULL,
  `wall` tinyint(1) NOT NULL DEFAULT '0',
  `gravity` tinyint(1) NOT NULL DEFAULT '0',
  `parent` int(10) unsigned NOT NULL DEFAULT '0',
  `parent-uri` char(255) NOT NULL,
  `extid` char(255) NOT NULL,
  `thr-parent` char(255) NOT NULL,
  `created` datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  `edited` datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  `commented` datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  `received` datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  `changed` datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  `owner-name` char(255) NOT NULL,
  `owner-link` char(255) NOT NULL,
  `owner-avatar` char(255) NOT NULL,
  `author-name` char(255) NOT NULL,
  `author-link` char(255) NOT NULL,
  `author-avatar` char(255) NOT NULL,
  `title` char(255) NOT NULL,
  `body` mediumtext NOT NULL,
  `app` char(255) NOT NULL,
  `verb` char(255) NOT NULL,
  `object-type` char(255) NOT NULL,
  `object` text NOT NULL,
  `target-type` char(255) NOT NULL,
  `target` text NOT NULL,
  `postopts` text NOT NULL,
  `plink` char(255) NOT NULL, 
  `resource-id` char(255) NOT NULL,
  `event-id` int(10) unsigned NOT NULL,
  `tag` mediumtext NOT NULL,
  `attach` mediumtext NOT NULL,
  `inform` mediumtext NOT NULL,
  `location` char(255) NOT NULL,
  `coord` char(255) NOT NULL,
  `allow_cid` mediumtext NOT NULL,
  `allow_gid` mediumtext NOT NULL,
  `deny_cid` mediumtext NOT NULL,
  `deny_gid` mediumtext NOT NULL,
  `private` tinyint(1) NOT NULL DEFAULT '0',
  `pubmail` tinyint(1) NOT NULL DEFAULT '0',
  `visible` tinyint(1) NOT NULL DEFAULT '0',
  `starred` tinyint(1) NOT NULL DEFAULT '0',
  `bookmark` tinyint(1) NOT NULL DEFAULT '0',
  `unseen` tinyint(1) NOT NULL DEFAULT '1',
  `deleted` tinyint(1) NOT NULL DEFAULT '0',
  `origin` tinyint(1) NOT NULL DEFAULT '0',
  `last-child` tinyint(1) unsigned NOT NULL DEFAULT '1',
  PRIMARY KEY (`id`),
  KEY `guid` (`guid`),
  KEY `uri` (`uri`),
  KEY `uid` (`uid`),
  KEY `contact-id` (`contact-id`),
  KEY `type` (`type`),
  KEY `wall` (`wall`),
  KEY `parent` (`parent`),
  KEY `parent-uri` (`parent-uri`),
  KEY `extid` (`extid`),
  KEY `created` (`created`),
  KEY `edited` (`edited`),
  KEY `received` (`received`),
  KEY `visible` (`visible`),
  KEY `starred` (`starred`),
  KEY `deleted` (`deleted`),
  KEY `origin`  (`origin`),
  KEY `last-child` (`last-child`),
  KEY `unseen` (`unseen`),
  FULLTEXT KEY `title` (`title`),
  FULLTEXT KEY `body` (`body`),
  FULLTEXT KEY `allow_cid` (`allow_cid`),
  FULLTEXT KEY `allow_gid` (`allow_gid`),
  FULLTEXT KEY `deny_cid` (`deny_cid`),
  FULLTEXT KEY `deny_gid` (`deny_gid`)
) ENGINE=MyISAM  DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- Table structure for table `mail`
--

CREATE TABLE IF NOT EXISTS `mail` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `uid` int(10) unsigned NOT NULL,
  `from-name` char(255) NOT NULL,
  `from-photo` char(255) NOT NULL,
  `from-url` char(255) NOT NULL,
  `contact-id` char(255) NOT NULL,
  `title` char(255) NOT NULL,
  `body` mediumtext NOT NULL,
  `seen` tinyint(1) NOT NULL,
  `replied` tinyint(1) NOT NULL,
  `uri` char(255) NOT NULL,
  `parent-uri` char(255) NOT NULL,
  `created` datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY (`id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- Table structure for table `photo`
--

CREATE TABLE IF NOT EXISTS `photo` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `uid` int(10) unsigned NOT NULL,
  `contact-id` int(10) unsigned NOT NULL,
  `guid` char(64) NOT NULL, 
  `resource-id` char(255) NOT NULL,
  `created` datetime NOT NULL,
  `edited` datetime NOT NULL,
  `title` char(255) NOT NULL,
  `desc` text NOT NULL,
  `album` char(255) NOT NULL,
  `filename` char(255) NOT NULL,
  `height` smallint(6) NOT NULL,
  `width` smallint(6) NOT NULL,
  `data` mediumblob NOT NULL,
  `scale` tinyint(3) NOT NULL,
  `profile` tinyint(1) NOT NULL DEFAULT '0',
  `allow_cid` mediumtext NOT NULL,
  `allow_gid` mediumtext NOT NULL,
  `deny_cid` mediumtext NOT NULL,
  `deny_gid` mediumtext NOT NULL,
  PRIMARY KEY (`id`),
  KEY `uid` (`uid`),
  KEY `resource-id` (`resource-id`),
  KEY `album` (`album`),
  KEY `scale` (`scale`),
  KEY `profile` (`profile`),
  KEY `guid` (`guid`)
) ENGINE=MyISAM  DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- Table structure for table `profile`
--

CREATE TABLE IF NOT EXISTS `profile` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `uid` int(11) NOT NULL,
  `profile-name` char(255) NOT NULL,
  `is-default` tinyint(1) NOT NULL DEFAULT '0',
  `hide-friends` tinyint(1) NOT NULL DEFAULT '0',
  `name` char(255) NOT NULL,
  `pdesc` char(255) NOT NULL,
  `dob` char(32) NOT NULL DEFAULT '0000-00-00',
  `address` char(255) NOT NULL,
  `locality` char(255) NOT NULL,
  `region` char(255) NOT NULL,
  `postal-code` char(32) NOT NULL,
  `country-name` char(255) NOT NULL,
  `gender` char(32) NOT NULL,
  `marital` char(255) NOT NULL,
  `showwith` tinyint(1) NOT NULL DEFAULT '0',
  `with` text NOT NULL,
  `sexual` char(255) NOT NULL,
  `politic` char(255) NOT NULL,
  `religion` char(255) NOT NULL,
  `pub_keywords` text NOT NULL,
  `prv_keywords` text NOT NULL,
  `about` text NOT NULL,
  `summary` char(255) NOT NULL,
  `music` text NOT NULL,
  `book` text NOT NULL,
  `tv` text NOT NULL,
  `film` text NOT NULL,
  `interest` text NOT NULL,
  `romance` text NOT NULL,
  `work` text NOT NULL,
  `education` text NOT NULL,
  `contact` text NOT NULL,
  `homepage` char(255) NOT NULL,
  `photo` char(255) NOT NULL,
  `thumb` char(255) NOT NULL,
  `publish` tinyint(1) NOT NULL DEFAULT '0',
  `net-publish` tinyint(1) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  FULLTEXT KEY `pub_keywords` (`pub_keywords`),
  FULLTEXT KEY `prv_keywords` (`prv_keywords`)
) ENGINE=MyISAM  DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- Table structure for table `profile_check`
--

CREATE TABLE IF NOT EXISTS `profile_check` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `uid` int(10) unsigned NOT NULL,
  `cid` int(10) unsigned NOT NULL,
  `dfrn_id` char(255) NOT NULL,
  `sec` char(255) NOT NULL,
  `expire` int(11) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=MyISAM  DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- Table structure for table `session`
--

CREATE TABLE IF NOT EXISTS `session` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `sid` char(255) NOT NULL,
  `data` text NOT NULL,
  `expire` int(10) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  KEY `sid` (`sid`),
  KEY `expire` (`expire`)
) ENGINE=MyISAM  DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- Table structure for table `user`
--

CREATE TABLE IF NOT EXISTS `user` (
  `uid` int(11) NOT NULL AUTO_INCREMENT,
  `guid` char(16) NOT NULL,
  `username` char(255) NOT NULL,
  `password` char(255) NOT NULL,
  `nickname` char(255) NOT NULL,
  `email` char(255) NOT NULL,
  `openid` char(255) NOT NULL,
  `timezone` char(128) NOT NULL,
  `language` char(32) NOT NULL DEFAULT 'en',
  `register_date` datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  `login_date` datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  `default-location` char(255) NOT NULL,
  `allow_location` tinyint(1) NOT NULL DEFAULT '0',
  `theme` char(255) NOT NULL,
  `pubkey` text NOT NULL,
  `prvkey` text NOT NULL,
  `spubkey` text NOT NULL,
  `sprvkey` text NOT NULL,
  `verified` tinyint(1) unsigned NOT NULL DEFAULT '0', 
  `blocked` tinyint(1) unsigned NOT NULL DEFAULT '0', 
  `blockwall` tinyint(1) unsigned NOT NULL DEFAULT '0',
  `hidewall` tinyint(1) unsigned NOT NULL DEFAULT '0',
  `blocktags` tinyint(1) unsigned NOT NULL DEFAULT '0',
  `notify-flags` int(11) unsigned NOT NULL DEFAULT '65535', 
  `page-flags` int(11) unsigned NOT NULL DEFAULT '0',
  `prvnets` tinyint(1) NOT NULL DEFAULT '0',
  `pwdreset` char(255) NOT NULL,
  `maxreq` int(11) NOT NULL DEFAULT '10',
  `expire` int(11) unsigned NOT NULL DEFAULT '0',
  `account_expired` tinyint( 1 ) NOT NULL DEFAULT '0',
  `account_expires_on` datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  `expire_notification_sent` datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  `allow_cid` mediumtext NOT NULL, 
  `allow_gid` mediumtext NOT NULL,
  `deny_cid` mediumtext NOT NULL, 
  `deny_gid` mediumtext NOT NULL,
  `openidserver` text NOT NULL,
  PRIMARY KEY (`uid`), 
  KEY `nickname` (`nickname`),
  KEY `account_expired` (`account_expired`),
  KEY `login_date` (`login_date`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8;


CREATE TABLE IF NOT EXISTS `register` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `hash` CHAR( 255 ) NOT NULL ,
  `created` DATETIME NOT NULL ,
  `uid` INT(11) UNSIGNED NOT NULL,
  `password` CHAR(255) NOT NULL,
  `language` CHAR(16) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE = MyISAM DEFAULT CHARSET=utf8;


CREATE TABLE IF NOT EXISTS `clients` (
`client_id` VARCHAR( 20 ) NOT NULL ,
`pw` VARCHAR( 20 ) NOT NULL ,
`redirect_uri` VARCHAR( 200 ) NOT NULL ,
PRIMARY KEY ( `client_id` )
) ENGINE = MyISAM DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `tokens` (
`id` VARCHAR( 40 ) NOT NULL ,
`client_id` VARCHAR( 20 ) NOT NULL ,
`expires` INT NOT NULL ,
`scope` VARCHAR( 200 ) NOT NULL ,
PRIMARY KEY ( `id` )
) ENGINE = MyISAM DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `auth_codes` (
`id` VARCHAR( 40 ) NOT NULL ,
`client_id` VARCHAR( 20 ) NOT NULL ,
`redirect_uri` VARCHAR( 200 ) NOT NULL ,
`expires` INT NOT NULL ,
`scope` VARCHAR( 250 ) NOT NULL ,
PRIMARY KEY ( `id` )
) ENGINE = MyISAM DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `queue` (
`id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY ,
`cid` INT NOT NULL ,
`network` CHAR( 32 ) NOT NULL,
`created` DATETIME NOT NULL ,
`last` DATETIME NOT NULL ,
`content` MEDIUMTEXT NOT NULL,
`batch` TINYINT( 1 ) NOT NULL DEFAULT '0',
INDEX ( `cid` ),
INDEX ( `created` ),
INDEX ( `last` ),
INDEX ( `network` ),
INDEX ( `batch` )
) ENGINE = MyISAM DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `pconfig` (
`id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY ,
`uid` INT NOT NULL DEFAULT '0',
`cat` CHAR( 255 ) NOT NULL ,
`k` CHAR( 255 ) NOT NULL ,
`v` MEDIUMTEXT NOT NULL
) ENGINE = MyISAM DEFAULT CHARSET=utf8;


CREATE TABLE IF NOT EXISTS `hook` (
`id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY ,
`hook` CHAR( 255 ) NOT NULL ,
`file` CHAR( 255 ) NOT NULL ,
`function` CHAR( 255 ) NOT NULL
) ENGINE = MyISAM DEFAULT CHARSET=utf8;


CREATE TABLE IF NOT EXISTS `addon` (
`id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY ,
`name` CHAR( 255 ) NOT NULL ,
`version` CHAR( 255 ) NOT NULL ,
`installed` TINYINT( 1 ) NOT NULL DEFAULT '0' ,
`timestamp` BIGINT NOT NULL DEFAULT '0' ,
`plugin_admin` TINYINT( 1 ) NOT NULL DEFAULT '0'
) ENGINE = MyISAM DEFAULT CHARSET=utf8;


CREATE TABLE IF NOT EXISTS `event` (
`id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY ,
`uid` INT NOT NULL ,
`cid` INT NOT NULL ,
`uri` CHAR( 255 ) NOT NULL,
`created` DATETIME NOT NULL ,
`edited` DATETIME NOT NULL ,
`start` DATETIME NOT NULL ,
`finish` DATETIME NOT NULL ,
`desc` TEXT NOT NULL ,
`location` TEXT NOT NULL ,
`type` CHAR( 255 ) NOT NULL ,
`nofinish` TINYINT( 1 ) NOT NULL DEFAULT '0',
`adjust` TINYINT( 1 ) NOT NULL DEFAULT '1',
`allow_cid` MEDIUMTEXT NOT NULL ,
`allow_gid` MEDIUMTEXT NOT NULL ,
`deny_cid` MEDIUMTEXT NOT NULL ,
`deny_gid` MEDIUMTEXT NOT NULL
) ENGINE = MyISAM DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `cache` (
 `k` CHAR( 255 ) NOT NULL PRIMARY KEY ,
 `v` TEXT NOT NULL,
 `updated` DATETIME NOT NULL
) ENGINE = MyISAM DEFAULT CHARSET=utf8;


CREATE TABLE IF NOT EXISTS `fcontact` (
`id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY ,
`url` CHAR( 255 ) NOT NULL ,
`name` CHAR( 255 ) NOT NULL ,
`photo` CHAR( 255 ) NOT NULL ,
`request` CHAR( 255 ) NOT NULL,
`nick` CHAR( 255 ) NOT NULL ,
`addr` CHAR( 255 ) NOT NULL ,
`batch` CHAR( 255) NOT NULL,
`notify` CHAR( 255 ) NOT NULL ,
`poll` CHAR( 255 ) NOT NULL ,
`confirm` CHAR( 255 ) NOT NULL ,
`priority` TINYINT( 1 ) NOT NULL ,
`network` CHAR( 32 ) NOT NULL ,
`alias` CHAR( 255 ) NOT NULL ,
`pubkey` TEXT NOT NULL ,
`updated` DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
INDEX ( `addr` ),
INDEX ( `network` )
) ENGINE = MyISAM DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `ffinder` (
`id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY ,
`uid` INT UNSIGNED NOT NULL ,
`cid` INT UNSIGNED NOT NULL ,
`fid` INT UNSIGNED NOT NULL
) ENGINE = MyISAM DEFAULT CHARSET=utf8;


CREATE TABLE IF NOT EXISTS `fsuggest` (
`id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY ,
`uid` INT NOT NULL ,
`cid` INT NOT NULL ,
`name` CHAR( 255 ) NOT NULL ,
`url` CHAR( 255 ) NOT NULL ,
`request` CHAR( 255 ) NOT NULL,
`photo` CHAR( 255 ) NOT NULL ,
`note` TEXT NOT NULL ,
`created` DATETIME NOT NULL
) ENGINE = MyISAM DEFAULT CHARSET=utf8;
 

CREATE TABLE IF NOT EXISTS `mailacct` (
`id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY ,
`uid` INT NOT NULL,
`server` CHAR( 255 ) NOT NULL ,
`port` INT NOT NULL,
`ssltype` CHAR( 16 ) NOT NULL,
`mailbox` CHAR( 255 ) NOT NULL,
`user` CHAR( 255 ) NOT NULL ,
`pass` TEXT NOT NULL ,
`reply_to` CHAR( 255 ) NOT NULL ,
`pubmail` TINYINT(1) NOT NULL DEFAULT '0',
`last_check` DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00'
) ENGINE = MyISAM DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `attach` (
`id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY ,
`uid` INT NOT NULL ,
`hash` CHAR(64) NOT NULL,
`filename` CHAR(255) NOT NULL,
`filetype` CHAR( 64 ) NOT NULL ,
`filesize` INT NOT NULL ,
`data` LONGBLOB NOT NULL ,
`created` DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
`edited` DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
`allow_cid` MEDIUMTEXT NOT NULL ,
`allow_gid` MEDIUMTEXT NOT NULL ,
`deny_cid` MEDIUMTEXT NOT NULL ,
`deny_gid` MEDIUMTEXT NOT NULL
) ENGINE = MyISAM DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `guid` (
`id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY ,
`guid` CHAR( 64 ) NOT NULL ,
INDEX ( `guid` )
) ENGINE = MyISAM  DEFAULT CHARSET=utf8;


CREATE TABLE IF NOT EXISTS `sign` (
`id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY ,
`iid` INT UNSIGNED NOT NULL ,
`signed_text` MEDIUMTEXT NOT NULL ,
`signature` TEXT NOT NULL ,
`signer` CHAR( 255 ) NOT NULL ,
INDEX ( `iid` )
) ENGINE = MyISAM DEFAULT CHARSET=utf8;


CREATE TABLE IF NOT EXISTS `deliverq` (
`id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY ,
`cmd` CHAR( 32 ) NOT NULL ,
`item` INT NOT NULL ,
`contact` INT NOT NULL
) ENGINE = MyISAM DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `search` (
`id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY ,
`uid` INT NOT NULL ,
`term` CHAR( 255 ) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL,
INDEX ( `uid` ),
INDEX ( `term` )
) ENGINE = MyISAM DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `fserver` (
`id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY ,
`server` CHAR( 255 ) NOT NULL ,
`posturl` CHAR( 255 ) NOT NULL ,
`key` TEXT NOT NULL,
INDEX ( `server` )
) ENGINE = MyISAM DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `gcontact` (
`id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY ,
`name` CHAR( 255 ) NOT NULL ,
`url` CHAR( 255 ) NOT NULL ,
`nurl` CHAR( 255 ) NOT NULL ,
`photo` CHAR( 255 ) NOT NULL,
INDEX ( `nurl` )
) ENGINE = MyISAM DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `glink` (
`id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY ,
`cid` INT NOT NULL ,
`uid` INT NOT NULL ,
`gcid` INT NOT NULL,
`updated` DATETIME NOT NULL,
INDEX ( `cid` ),
INDEX ( `uid` ),
INDEX ( `gcid` ),
INDEX ( `updated` )
) ENGINE = MyISAM DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `gcign` (
`id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY ,
`uid` INT NOT NULL ,
`gcid` INT NOT NULL,
INDEX ( `uid` ),
INDEX ( `gcid` )
) ENGINE = MyISAM DEFAULT CHARSET=utf8;

