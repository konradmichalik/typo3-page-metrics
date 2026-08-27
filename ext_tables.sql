CREATE TABLE tx_typo3pagemetrics_daily (
	uid int(11) unsigned NOT NULL auto_increment,
	date date NOT NULL,
	page_uid int(11) unsigned DEFAULT '0' NOT NULL,
	sys_language_uid int(11) DEFAULT '0' NOT NULL,
	source varchar(32) DEFAULT '' NOT NULL,
	metric varchar(64) DEFAULT '' NOT NULL,
	dimension varchar(255) DEFAULT '' NOT NULL,
	value int(11) DEFAULT '0' NOT NULL,

	PRIMARY KEY (uid),
	KEY page_date (page_uid,sys_language_uid,date),
	UNIQUE KEY fact (date,page_uid,sys_language_uid,source,metric,dimension)
);

CREATE TABLE tx_typo3pagemetrics_unresolved (
	uid int(11) unsigned NOT NULL auto_increment,
	date date NOT NULL,
	source varchar(32) DEFAULT '' NOT NULL,
	url_hash varchar(40) DEFAULT '' NOT NULL,
	url varchar(2048) DEFAULT '' NOT NULL,
	metric varchar(64) DEFAULT '' NOT NULL,
	reason varchar(24) DEFAULT '' NOT NULL,
	value int(11) DEFAULT '0' NOT NULL,

	PRIMARY KEY (uid),
	KEY date_source (date,source),
	UNIQUE KEY fact (date,source,url_hash,metric)
);

CREATE TABLE tx_typo3pagemetrics_import (
	uid int(11) unsigned NOT NULL auto_increment,
	source varchar(32) DEFAULT '' NOT NULL,
	site_identifier varchar(255) DEFAULT '' NOT NULL,
	last_imported_date date NOT NULL,
	tstamp int(11) unsigned DEFAULT '0' NOT NULL,

	PRIMARY KEY (uid),
	UNIQUE KEY source_site (source,site_identifier)
);

CREATE TABLE tx_typo3pagemetrics_annotation (
	uid int(11) unsigned NOT NULL auto_increment,
	date date NOT NULL,
	site_identifier varchar(255) DEFAULT '' NOT NULL,
	source varchar(32) DEFAULT '' NOT NULL,
	annotation_id varchar(32) DEFAULT '' NOT NULL,
	text varchar(1024) DEFAULT '' NOT NULL,

	PRIMARY KEY (uid),
	KEY site_date (site_identifier,date),
	UNIQUE KEY fact (source,site_identifier,annotation_id)
);
