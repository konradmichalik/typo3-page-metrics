CREATE TABLE tx_typo3pagemetrics_daily (
	uid int(11) unsigned NOT NULL auto_increment,
	date date NOT NULL,
	page_uid int(11) unsigned DEFAULT '0' NOT NULL,
	sys_language_uid int(11) DEFAULT '0' NOT NULL,
	provider varchar(32) DEFAULT '' NOT NULL,
	metric varchar(64) DEFAULT '' NOT NULL,
	dimension varchar(255) DEFAULT '' NOT NULL,
	value int(11) DEFAULT '0' NOT NULL,

	PRIMARY KEY (uid),
	KEY page_date (page_uid,sys_language_uid,date),
	UNIQUE KEY fact (date,page_uid,sys_language_uid,provider,metric,dimension)
);

# The record sibling of tx_typo3pagemetrics_daily, for the traffic a route
# enhancer attributed to a single record: /news/my-article is one news
# article, not just a hit on /news.
#
# A separate table rather than two more columns on the fact table. Widening
# that table's unique key would touch every row an installed site already has
# and add `record_table = ''` to every query on the page module's hot read
# path, all to carry a column that is empty for the overwhelming majority of
# rows. The read paths are disjoint anyway: page tiles never ask about a
# record, record tiles never ask about a page.
#
# There is deliberately no page_uid column. A record reachable below two
# detail pages is still one record, and "how is this article doing" has to
# answer the same number either way, so the page must not be part of the
# identity - and carrying it outside the key would leave it arbitrary
# whichever page was imported last. The page keeps its own count in
# tx_typo3pagemetrics_daily, where the same hit is already counted for the
# page that rendered it.
#
# record_table is varchar(64) because that is the longest table name TYPO3
# and its databases allow, and keeping it short keeps the unique key inside
# InnoDB's 3072-byte index limit next to dimension varchar(255).
#
# record_uid is always the default-language uid (a persisted route mapper
# returns the translation parent), so sys_language_uid is a dimension of the
# fact here exactly as it is for pages.
CREATE TABLE tx_typo3pagemetrics_record_daily (
	uid int(11) unsigned NOT NULL auto_increment,
	date date NOT NULL,
	record_table varchar(64) DEFAULT '' NOT NULL,
	record_uid int(11) unsigned DEFAULT '0' NOT NULL,
	sys_language_uid int(11) DEFAULT '0' NOT NULL,
	provider varchar(32) DEFAULT '' NOT NULL,
	metric varchar(64) DEFAULT '' NOT NULL,
	dimension varchar(255) DEFAULT '' NOT NULL,
	value int(11) DEFAULT '0' NOT NULL,

	PRIMARY KEY (uid),
	KEY record_date (record_table,record_uid,sys_language_uid,date),
	UNIQUE KEY fact (date,record_table,record_uid,sys_language_uid,provider,metric,dimension)
);

CREATE TABLE tx_typo3pagemetrics_unresolved (
	uid int(11) unsigned NOT NULL auto_increment,
	date date NOT NULL,
	provider varchar(32) DEFAULT '' NOT NULL,
	url_hash varchar(40) DEFAULT '' NOT NULL,
	url varchar(2048) DEFAULT '' NOT NULL,
	metric varchar(64) DEFAULT '' NOT NULL,
	reason varchar(24) DEFAULT '' NOT NULL,
	value int(11) DEFAULT '0' NOT NULL,

	PRIMARY KEY (uid),
	KEY date_provider (date,provider),
	UNIQUE KEY fact (date,provider,url_hash,metric)
);

# covered_from_date/covered_until_date are what make "we have no number for
# this day" answerable at all. The fact table cannot answer it: a provider
# only ever emits a metric for a URL that had activity, so a missing row
# means a quiet day far more often than it means a gap in the data. Only the
# import knows which window it actually fetched, so it records both ends here.
#
# They are deliberately NOT the same as last_imported_date, which is a resume
# cursor: that one is always set one day behind, because a run may only catch
# part of today and today must be re-fetched next time. The covered window,
# by contrast, does include today - the data for it was fetched, it is just
# not final yet. Reusing the cursor as the window's end masked every tile's
# most recent day as missing.
#
# Both are nullable, and either being NULL means "window unknown", which
# makes callers fall back to claiming nothing rather than claiming a gap.
CREATE TABLE tx_typo3pagemetrics_import (
	uid int(11) unsigned NOT NULL auto_increment,
	provider varchar(32) DEFAULT '' NOT NULL,
	site_identifier varchar(255) DEFAULT '' NOT NULL,
	covered_from_date date DEFAULT NULL,
	covered_until_date date DEFAULT NULL,
	last_imported_date date NOT NULL,
	tstamp int(11) unsigned DEFAULT '0' NOT NULL,

	PRIMARY KEY (uid),
	UNIQUE KEY provider_site (provider,site_identifier)
);

# category, date_until, page_uid and include_subpages are what turn a marker
# from "something happened" into an explanation. category carries the icon and
# the colour a chart draws it with, date_until makes a campaign a band rather
# than a tick on the day it started, and page_uid scopes a marker to the page
# it actually explains - 0 keeps the site-wide meaning every annotation had
# before these columns existed, which is why it is the default rather than
# NULL.
CREATE TABLE tx_typo3pagemetrics_annotation (
	uid int(11) unsigned NOT NULL auto_increment,
	date date NOT NULL,
	date_until date DEFAULT NULL,
	site_identifier varchar(255) DEFAULT '' NOT NULL,
	provider varchar(32) DEFAULT '' NOT NULL,
	annotation_id varchar(32) DEFAULT '' NOT NULL,
	category varchar(16) DEFAULT 'release' NOT NULL,
	page_uid int(11) unsigned DEFAULT '0' NOT NULL,
	include_subpages tinyint(1) unsigned DEFAULT '0' NOT NULL,
	text varchar(1024) DEFAULT '' NOT NULL,

	PRIMARY KEY (uid),
	KEY site_date (site_identifier,date),
	UNIQUE KEY fact (provider,site_identifier,annotation_id)
);

CREATE TABLE tx_typo3pagemetrics_bot_agent_daily (
	uid int(11) unsigned NOT NULL auto_increment,
	date date NOT NULL,
	site_identifier varchar(255) DEFAULT '' NOT NULL,
	page_uid int(11) unsigned DEFAULT '0' NOT NULL,
	sys_language_uid int(11) DEFAULT '0' NOT NULL,
	bucket varchar(20) DEFAULT '' NOT NULL,
	agent varchar(255) DEFAULT '' NOT NULL,
	agent_hash char(40) DEFAULT '' NOT NULL,
	hits int(11) unsigned DEFAULT '0' NOT NULL,
	hits_suspicious int(11) unsigned DEFAULT '0' NOT NULL,

	PRIMARY KEY (uid),
	KEY site_date (site_identifier,date),
	KEY page_date (page_uid,sys_language_uid,date),
	UNIQUE KEY fact (date,site_identifier,page_uid,sys_language_uid,bucket,agent_hash)
);

# uid/pid and the standard ctrl fields (tstamp, crdate, cruser_id, deleted,
# hidden) are added automatically from Configuration/TCA/tx_typo3pagemetrics_
# manual_annotation.php by TYPO3's DefaultTcaSchema - only this table's own
# custom columns need declaring here, same as core's sys_note.
#
# date is int, not the SQL `date` type tx_typo3pagemetrics_annotation uses:
# this column is edited through TCA (type=datetime), which stores as a Unix
# timestamp by convention - unlike tx_typo3pagemetrics_annotation.date,
# which is only ever written by this extension's own code as a plain
# 'Y-m-d' string, never through a form.
# date_until is int here for the same reason date is: this table is edited
# through TCA, which stores a datetime as a Unix timestamp. Not nullable,
# because TCA writes 0 for an empty datetime rather than NULL, which is why
# the repository reads 0 as "no range" instead of as a date in 1970.
CREATE TABLE tx_typo3pagemetrics_manual_annotation (
	site_identifier varchar(255) DEFAULT '' NOT NULL,
	date int(11) unsigned DEFAULT '0' NOT NULL,
	date_until int(11) unsigned DEFAULT '0' NOT NULL,
	category varchar(16) DEFAULT 'release' NOT NULL,
	page_uid int(11) unsigned DEFAULT '0' NOT NULL,
	include_subpages tinyint(1) unsigned DEFAULT '0' NOT NULL,
	text varchar(1024) DEFAULT '' NOT NULL,

	KEY site_date (site_identifier,date)
);
