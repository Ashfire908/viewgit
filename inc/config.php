<?php
/** @file
 * Configuration file for viewgit.
 *
 * @note DO NOT EDIT THIS FILE. Create localconfig.php instead and override the settings in it.
 */
$conf['projects'] = array(
	// 'name' => array('repo' => '/path/to/repo'),
);

$conf['datetime'] = '%Y-%m-%d %H:%M:%S';

// Maximum length of commit message's first line to show 
$conf['commit_message_maxlen'] = 50;

// Maximum number of shortlog entries to show on the summary page
$conf['summary_shortlog'] = 30;

// Allow checking out projects via "git clone"
$conf['allow_checkout'] = true;

// RSS time to live (how often clients should update the feed), in minutes.
$conf['rss_ttl'] = 10;

// RSS: Maximum number of items in feed
$conf['rss_max_items'] = 30;

include_once('localconfig.php');
