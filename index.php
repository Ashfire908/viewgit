<?php
error_reporting(E_ALL);

require_once('inc/config.php');

function debug($msg)
{
	file_put_contents('/tmp/viewgit.log', strftime('%H:%M:%S') ." $_SERVER[REMOTE_ADDR]:$_SERVER[REMOTE_PORT] $msg\n", FILE_APPEND);
}

/**
 * Formats "git diff" output into xhtml.
 * @return array(array of filenames, xhtml)
 */
function format_diff($text)
{
	$files = array();

	// match every "^diff --git a/<path> b/<path>$" line
	foreach (explode("\n", $text) as $line) {
		if (preg_match('#^diff --git a/(.*) b/(.*)$#', $line, $matches) > 0) {
			$files[$matches[1]] = urlencode($matches[1]);
		}
	}

	$text = htmlentities($text);

	$text = preg_replace(
		array(
			'/^(\+.*)$/m',
			'/^(-.*)$/m',
			'/^(@.*)$/m',
			'/^([^d\+-@].*)$/m',
		),
		array(
			'<span class="add">$1</span>',
			'<span class="del">$1</span>',
			'<span class="pos">$1</span>',
			'<span class="etc">$1</span>',
		),
		$text);
	$text = preg_replace_callback('#^diff --git a/(.*) b/(.*)$#m',
		create_function(
			'$m',
			'return "<span class=\"diffline\"><a name=\"". urlencode($m[1]) ."\">diff --git a/$m[1] b/$m[2]</a></span>";'
		),
		$text);

	return array($files, $text);
}

function get_project_info($name)
{
	global $conf;

	$info = $conf['projects'][$name];
	$info['name'] = $name;
	$info['description'] = file_get_contents($info['repo'] .'/description');

	$headinfo = git_get_commit_info($name, 'HEAD');
	$info['head_stamp'] = $headinfo['author_utcstamp'];
	$info['head_datetime'] = strftime($conf['datetime'], $headinfo['author_utcstamp']);
	$info['head_hash'] = $headinfo['h'];
	$info['head_tree'] = $headinfo['tree'];

	return $info;
}

/**
 * Get details of a commit: tree, parent, author/committer (name, mail, date), message
 */
function git_get_commit_info($project, $hash = 'HEAD')
{
	global $conf;

	$info = array();
	$info['h_name'] = $hash;
	$info['message_full'] = '';

	$output = run_git($project, "git rev-list --header --max-count=1 $hash");
	// tree <h>
	// parent <h>
	// author <name> "<"<mail>">" <stamp> <timezone>
	// committer
	// <empty>
	//     <message>
	$pattern = '/^(author|committer) ([^<]+) <([^>]*)> ([0-9]+) (.*)$/';
	foreach ($output as $line) {
		if (substr($line, 0, 4) === 'tree') {
			$info['tree'] = substr($line, 5);
		}
		elseif (substr($line, 0, 6) === 'parent') {
			$info['parent']  = substr($line, 7);
		}
		elseif (preg_match($pattern, $line, $matches) > 0) {
			$info[$matches[1] .'_name'] = $matches[2];
			$info[$matches[1] .'_mail'] = $matches[3];
			$info[$matches[1] .'_stamp'] = $matches[4];
			$info[$matches[1] .'_timezone'] = $matches[5];
			$info[$matches[1] .'_utcstamp'] = $matches[4] - ((intval($matches[5]) / 100.0) * 3600);
		}
		elseif (substr($line, 0, 4) === '    ') {
			$info['message_full'] .= substr($line, 4) ."\n";
			if (!isset($info['message'])) {
				$info['message'] = substr($line, 4, $conf['commit_message_maxlen']);
				$info['message_firstline'] = substr($line, 4);
			}
		}
		elseif (preg_match('/^[0-9a-f]{40}$/', $line) > 0) {
			$info['h'] = $line;
		}
	}

	return $info;
}

function git_get_heads($project)
{
	$heads = array();

	$output = run_git($project, 'git show-ref --heads');
	foreach ($output as $line) {
		$fullname = substr($line, 41);
		$name = array_pop(explode('/', $fullname));
		$heads[] = array('h' => substr($line, 0, 40), 'fullname' => "$fullname", 'name' => "$name");
	}

	return $heads;
}

/**
 * Get array containing path information for parts, starting from root_hash.
 *
 * @param root_hash commit/tree hash for the root tree
 * @param parts array of path fragments
 */
function git_get_path_info($project, $root_hash, $parts)
{
	$pathinfo = array();

	$tid = $root_hash;
	$pathinfo = array();
	foreach ($parts as $p) {
		$entry = git_ls_tree_part($project, $tid, $p);
		$pathinfo[] = $entry;
		$tid = $entry['hash'];
	}

	return $pathinfo;
}

function git_get_rev_list($project, $max_count = null, $start = 'HEAD')
{
	$cmd = "git rev-list $start";
	if (!is_null($max_count)) {
		$cmd = "git rev-list --max-count=$max_count $start";
	}

	return run_git($project, $cmd);
}

function git_get_tags($project)
{
	$tags = array();

	$output = run_git($project, 'git show-ref --tags');
	foreach ($output as $line) {
		$fullname = substr($line, 41);
		$name = array_pop(explode('/', $fullname));
		$tags[] = array('h' => substr($line, 0, 40), 'fullname' => $fullname, 'name' => $name);
	}
	return $tags;
}

function git_ls_tree($project, $tree)
{
	$entries = array();
	$output = run_git($project, "git ls-tree $tree");
	// 100644 blob 493b7fc4296d64af45dac64bceac2d9a96c958c1    .gitignore
	// 040000 tree 715c78b1011dc58106da2a1af2fe0aa4c829542f    doc
	foreach ($output as $line) {
		$parts = preg_split('/\s+/', $line, 4);
		$entries[] = array('name' => $parts[3], 'mode' => $parts[0], 'type' => $parts[1], 'hash' => $parts[2]);
	}

	return $entries;
}

function git_ls_tree_part($project, $tree, $name)
{
	$entries = git_ls_tree($project, $tree);
	foreach ($entries as $entry) {
		if ($entry['name'] === $name) {
			return $entry;
		}
	}
	return null;
}

function makelink($dict)
{
	$params = array();
	foreach ($dict as $k => $v) {
		$params[] = rawurlencode($k) .'='. str_replace('%2F', '/', rawurlencode($v));
	}
	if (count($params) > 0) {
		return '?'. htmlentities(join('&', $params));
	}
	return '';
}

/**
 * Executes a git command in the project repo.
 * @return array of output lines
 */
function run_git($project, $command)
{
	global $conf;

	$output = array();
	$cmd = "GIT_DIR=". $conf['projects'][$project]['repo'] ." $command";
	exec($cmd, &$output);
	return $output;
}

/**
 * Executes a git command in the project repo, sending output directly to the
 * client.
 */
function run_git_passthru($project, $command)
{
	global $conf;

	$cmd = "GIT_DIR=". $conf['projects'][$project]['repo'] ." $command";
	$result = 0;
	passthru($cmd, &$result);
	return $result;
}

/**
 * Makes sure the given project is valid. If it's not, this function will
 * die().
 * @return the project
 */
function validate_project($project)
{
	global $conf;

	if (!in_array($project, array_keys($conf['projects']))) {
		die('Invalid project');
	}
	return $project;
}

/**
 * Makes sure the given hash is valid. If it's not, this function will die().
 * @return the hash
 */
function validate_hash($hash)
{
	if (!preg_match('/^[0-9a-z]{40}$/', $hash) && !preg_match('!^refs/(heads|tags)/[-.0-9a-z]+$!', $hash)) {
		die('Invalid hash');

	}
	return $hash;
}

$action = 'index';
$template = 'index';
$page['title'] = 'ViewGit';

if (isset($_REQUEST['a'])) {
	$action = strtolower($_REQUEST['a']);
}
$page['action'] = $action;

if ($action === 'index') {
	$template = 'index';
	$page['title'] = 'List of projects - ViewGit';

	foreach (array_keys($conf['projects']) as $p) {
		$page['projects'][] = get_project_info($p);
	}
}
elseif ($action === 'archive') {
	$project = validate_project($_REQUEST['p']);
	$tree = validate_hash($_REQUEST['h']);
	$type = $_REQUEST['t'];

	$basename = "$project-tree-$tree";
	if (isset($_REQUEST['n'])) {
		$basename = "$project-$_REQUEST[n]-". substr($tree, 0, 6);
	}

	if ($type === 'targz') {
		header("Content-Type: application/x-tar-gz");
		header("Content-Transfer-Encoding: binary");
		header("Content-Disposition: attachment; filename=\"$basename.tar.gz\";");
		run_git_passthru($project, "git archive --format=tar $tree |gzip");
	}
	elseif ($type === 'zip') {
		header("Content-Type: application/x-zip");
		header("Content-Transfer-Encoding: binary");
		header("Content-Disposition: attachment; filename=\"$basename.zip\";");
		run_git_passthru($project, "git archive --format=zip $tree");
	}
	else {
		die('Invalid archive type requested');
	}

	die();
}
// blob: send a blob to browser with filename suggestion
elseif ($action === 'blob') {
	$project = validate_project($_REQUEST['p']);
	$hash = validate_hash($_REQUEST['h']);
	$name = $_REQUEST['n'];

	header('Content-type: application/octet-stream');
	header("Content-Disposition: attachment; filename=$name"); // FIXME needs quotation

	run_git_passthru($project, "git cat-file blob $hash");
	die();
}
/**
 * git checkout.
 */
elseif ($action === 'co') {
	if (!$conf['allow_checkout']) { die('Checkout not allowed'); }

	// For debugging
	debug("Project: $_REQUEST[p] Request: $_REQUEST[r]");

	// eg. info/refs, HEAD
	$p = validate_project($_REQUEST['p']); // project
	$r = $_REQUEST['r']; // path

	$gitdir = $conf['projects'][$p]['repo'];
	$filename = $gitdir .'/'. $r;

	// make sure the request is legit (no reading of other files besides those under git projects)
	if ($r === 'HEAD' || $r === 'info/refs' || preg_match('!^objects/info/(packs|http-alternates|alternates)$!', $r) > 0 || preg_match('!^objects/[0-9a-f]{2}/[0-9a-f]{38}$!', $r) > 0) {
		if (file_exists($filename)) {
			debug('OK, sending');
			readfile($filename);
		} else {
			debug('Not found');
			header('404');
		}
	} else {
		debug("Denied");
	}

	die();
}
elseif ($action === 'commit') {
	$template = 'commit';
	$page['project'] = validate_project($_REQUEST['p']);
	$page['title'] = "$page[project] - Commit - ViewGit";
	$page['commit_id'] = validate_hash($_REQUEST['h']);

	$info = git_get_commit_info($page['project'], $page['commit_id']);

	$page['author_name'] = $info['author_name'];
	$page['author_mail'] = $info['author_mail'];
	$page['author_datetime'] = strftime($conf['datetime'], $info['author_utcstamp']);
	$page['author_datetime_local'] = strftime($conf['datetime'], $info['author_stamp']) .' '. $info['author_timezone'];
	$page['committer_name'] = $info['committer_name'];
	$page['committer_mail'] = $info['committer_mail'];
	$page['committer_datetime'] = strftime($conf['datetime'], $info['committer_utcstamp']);
	$page['committer_datetime_local'] = strftime($conf['datetime'], $info['committer_stamp']) .' '. $info['committer_timezone'];
	$page['tree_id'] = $info['tree'];
	$page['parent'] = $info['parent'];
	$page['message'] = $info['message'];
	$page['message_firstline'] = $info['message_firstline'];
	$page['message_full'] = $info['message_full'];

}
elseif ($action === 'commitdiff') {
	$template = 'commitdiff';
	$page['project'] = validate_project($_REQUEST['p']);
	$page['title'] = "$page[project] - Commitdiff - ViewGit";
	$hash = validate_hash($_REQUEST['h']);
	$page['commit_id'] = $hash;

	$info = git_get_commit_info($page['project'], $hash);

	$page['tree_id'] = $info['tree'];

	$page['message'] = $info['message'];
	$page['message_firstline'] = $info['message_firstline'];
	$page['message_full'] = $info['message_full'];
	$page['author_name'] = $info['author_name'];
	$page['author_mail'] = $info['author_mail'];
	$page['author_datetime'] = strftime($conf['datetime'], $info['author_utcstamp']);

	$text = join("\n", run_git($page['project'], "git diff $hash^..$hash"));
	list($page['files'], $page['diffdata']) = format_diff($text);
	//$page['diffdata'] = format_diff($text);
}
elseif ($action === 'shortlog') {
	$template = 'shortlog';
	$page['project'] = validate_project($_REQUEST['p']);
	$page['title'] = "$page[project] - Shortlog - ViewGit";
	if (isset($_REQUEST['h'])) {
		$page['ref'] = validate_hash($_REQUEST['h']);
	} else {
		$page['ref'] = 'HEAD';
	}

	$info = git_get_commit_info($page['project'], $page['ref']);
	$page['commit_id'] = $info['h'];
	$page['tree_id'] = $info['tree'];

	// TODO merge the logic with 'summary' below
	$revs = git_get_rev_list($page['project'], $conf['summary_shortlog'], $page['ref']); // TODO pass first rev as parameter
	foreach ($revs as $rev) {
		$info = git_get_commit_info($page['project'], $rev);
		$page['shortlog'][] = array(
			'author' => $info['author_name'],
			'date' => strftime($conf['datetime'], $info['author_utcstamp']),
			'message' => $info['message'],
			'commit_id' => $rev,
			'tree' => $info['tree'],
		);
	}
}
elseif ($action === 'summary') {
	$template = 'summary';
	$page['project'] = validate_project($_REQUEST['p']);
	$page['title'] = "$page[project] - Summary - ViewGit";

	$info = git_get_commit_info($page['project']);
	$page['commit_id'] = $info['h'];
	$page['tree_id'] = $info['tree'];
	
	$revs = git_get_rev_list($page['project'], $conf['summary_shortlog']);
	foreach ($revs as $rev) {
		$info = git_get_commit_info($page['project'], $rev);
		$page['shortlog'][] = array(
			'author' => $info['author_name'],
			'date' => strftime($conf['datetime'], $info['author_utcstamp']),
			'message' => $info['message'],
			'commit_id' => $rev,
			'tree' => $info['tree'],
		);
	}

	$tags = git_get_tags($page['project']);
	$page['tags'] = array();
	foreach ($tags as $tag) {
		$info = git_get_commit_info($page['project'], $tag['h']);
		$page['tags'][] = array(
			'date' => strftime($conf['datetime'], $info['author_utcstamp']),
			'h' => $tag['h'],
			'fullname' => $tag['fullname'],
			'name' => $tag['name'],
		);
	}

	$heads = git_get_heads($page['project']);
	$page['heads'] = array();
	foreach ($heads as $h) {
		$info = git_get_commit_info($page['project'], $h['h']);
		$page['heads'][] = array(
			'date' => strftime($conf['datetime'], $info['author_utcstamp']),
			'h' => $h['h'],
			'fullname' => $h['fullname'],
			'name' => $h['name'],
		);
	}
}
/*
 * Shows a tree, with list of directories/files, links to them and download
 * links to archives.
 *
 * @param p project
 * @param h tree hash
 * @param hb OPTIONAL base commit (trees can be part of multiple commits, this
 * one denotes which commit the user navigated from)
 * @param f OPTIONAL path the user has followed to view this tree
 */
elseif ($action === 'tree') {
	$template = 'tree';
	$page['project'] = validate_project($_REQUEST['p']);
	$page['tree_id'] = validate_hash($_REQUEST['h']);
	$page['title'] = "$page[project] - Tree - ViewGit";

	// 'hb' optionally contains the commit_id this tree is related to
	if (isset($_REQUEST['hb'])) {
		$page['commit_id'] = validate_hash($_REQUEST['hb']);
	}
	else {
		// for the header
		$info = git_get_commit_info($page['project']);
		$page['commit_id'] = $info['h'];
	}

	$page['path'] = '';
	if (isset($_REQUEST['f'])) {
		$page['path'] = $_REQUEST['f']; // TODO validate?
	}

	// get path info for the header
	$page['pathinfo'] = git_get_path_info($page['project'], $page['commit_id'], explode('/', $page['path']));

	$page['entries'] = git_ls_tree($page['project'], $page['tree_id']);
}
/*
 * View a blob as inline, embedded on the page.
 * @param p project
 * @param h blob hash
 * @param hb OPTIONAL base commit
 */
elseif ($action === 'viewblob') {
	$template = 'blob';
	$page['project'] = validate_project($_REQUEST['p']);
	$page['hash'] = validate_hash($_REQUEST['h']);
	$page['title'] = "$page[project] - Blob - ViewGit";
	if (isset($_REQUEST['hb'])) {
		$page['commit_id'] = validate_hash($_REQUEST['hb']);
	}
	else {
		$page['commit_id'] = 'HEAD';
	}

	$page['path'] = '';
	if (isset($_REQUEST['f'])) {
		$page['path'] = $_REQUEST['f']; // TODO validate?
	}

	// For the header's pagenav
	$info = git_get_commit_info($page['project'], $page['commit_id']);
	$page['commit_id'] = $info['h'];
	$page['tree_id'] = $info['tree'];

	$page['pathinfo'] = git_get_path_info($page['project'], $page['commit_id'], explode('/', $page['path']));

	$page['data'] = join("\n", run_git($page['project'], "git cat-file blob $page[hash]"));
}
else {
	die('Invalid action');
}

require 'templates/header.php';
require "templates/$template.php";
require 'templates/footer.php';
