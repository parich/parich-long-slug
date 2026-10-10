<?php
/**
 * Test bootstrap: loads WordPress with this plugin and gives each test file simple
 * assertions plus a DB transaction that is rolled back at the end.
 *
 * Usage (first line of a test file): require __DIR__ . '/../bootstrap.php';
 * Finish the file with pls_test_done(), which rolls back and sets the exit code.
 * A test file may define pls_test_early_hooks() before that line to register hooks
 * that must run while WordPress loads (pls_test_add_early_hook()).
 *
 * Transactions: DDL (CREATE, ALTER, DROP, TRUNCATE, RENAME) commits a MySQL transaction
 * implicitly, so while one is open such statements are blocked and reported as failures.
 * Each transaction writes a sentinel option; if it is still there after ROLLBACK,
 * something committed the fixtures and the run fails. Tests that need DDL define
 * PLS_TEST_MANUAL_TX before requiring this file, run DDL on copies made with
 * pls_test_clone_table() and wrap fixtures in pls_test_begin() / pls_test_rollback().
 *
 * A file counts as passed only with at least one assertion and no failures.
 *
 * Environment:
 *   WP_LOAD_PATH  path to wp-load.php (default: four directories up from this folder)
 *   WP_TEST_HOST  host name used for $_SERVER['HTTP_HOST'] (default: localhost)
 *
 * @package Parich_Long_Slug
 */

$_SERVER['HTTP_HOST'] = getenv('WP_TEST_HOST') ?: 'localhost';
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['SERVER_NAME'] = $_SERVER['HTTP_HOST'];

define('WP_USE_THEMES', false);
define('WP_DEBUG', true);
// Keep wp-load.php from spawning a cron request while tests run.
define('DISABLE_WP_CRON', true);

define('PLS_TEST_PLUGIN_DIR', dirname(__DIR__));
define('PLS_TEST_PLUGIN_FILE', PLS_TEST_PLUGIN_DIR . '/parich-long-slug.php');
define('PLS_TEST_PLUGIN_BASENAME', 'parich-long-slug/parich-long-slug.php');

$GLOBALS['pls_test_failures'] = array();
$GLOBALS['pls_test_passes'] = 0;
// Sentinel option name while a transaction is open, null otherwise.
$GLOBALS['pls_test_tx'] = null;
$GLOBALS['pls_test_clones'] = array();

/**
 * Records PHP notices/warnings/deprecations raised from this plugin's own files.
 * The handler is called regardless of error_reporting(), so nothing slips through.
 */
set_error_handler(function ($errno, $errstr, $errfile, $errline) {
	if (strpos(pls_test_normalize_path($errfile), pls_test_normalize_path(PLS_TEST_PLUGIN_DIR) . '/') === 0) {
		$GLOBALS['pls_test_failures'][] = sprintf('PHP error %d: %s (%s:%d)', $errno, $errstr, $errfile, $errline);
	}
	return false; // Let PHP handle (print) it as usual.
});

// wp_normalize_path() is not available yet when early errors happen.
function pls_test_normalize_path($path)
{
	return str_replace('\\', '/', (string) $path);
}

/**
 * Hooks registered before WordPress loads (same technique as tests_add_filter()
 * in the WordPress PHPUnit suite).
 */
function pls_test_add_early_hook($hook, $callback, $priority = 10, $accepted_args = 1)
{
	$GLOBALS['wp_filter'][$hook][$priority][] = array(
		'function' => $callback,
		'accepted_args' => $accepted_args,
	);
}

/**
 * Opens a transaction and writes its sentinel row.
 */
function pls_test_begin()
{
	global $wpdb;
	if (null !== $GLOBALS['pls_test_tx']) {
		return;
	}
	$wpdb->query('SET autocommit = 0');
	$wpdb->query('START TRANSACTION');
	$sentinel = 'pls_test_sentinel_' . bin2hex(random_bytes(6));
	$wpdb->insert($wpdb->options, array('option_name' => $sentinel, 'option_value' => '1', 'autoload' => 'off'));
	$GLOBALS['pls_test_tx'] = $sentinel;
}

/**
 * Rolls the open transaction back and fails the run if its sentinel survived.
 */
function pls_test_rollback()
{
	global $wpdb;
	$sentinel = $GLOBALS['pls_test_tx'];
	if (null === $sentinel) {
		return;
	}
	$wpdb->query('ROLLBACK');
	$wpdb->query('SET autocommit = 1');
	$GLOBALS['pls_test_tx'] = null;
	wp_cache_flush();

	$left = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name = %s", $sentinel));
	if ($left) {
		$GLOBALS['pls_test_failures'][] = 'The transaction was committed before ROLLBACK; fixtures may have persisted';
		$wpdb->delete($wpdb->options, array('option_name' => $sentinel));
	}
}

/**
 * Copies a table (structure and rows) for DDL tests; dropped by pls_test_done().
 * Refused inside a transaction, where the CREATE TABLE would commit it.
 *
 * @return string|false Name of the copy.
 */
function pls_test_clone_table($table)
{
	global $wpdb;
	if (null !== $GLOBALS['pls_test_tx']) {
		$GLOBALS['pls_test_failures'][] = "pls_test_clone_table({$table}) called inside a transaction";
		return false;
	}
	$clone = $wpdb->prefix . 'pls_t_' . substr($table, strlen($wpdb->prefix));
	$wpdb->query("DROP TABLE IF EXISTS `{$clone}`");
	$wpdb->query("CREATE TABLE `{$clone}` LIKE `{$table}`");
	$wpdb->query("INSERT INTO `{$clone}` SELECT * FROM `{$table}`");
	$GLOBALS['pls_test_clones'][] = $clone;
	return $clone;
}

// Blocks DDL while a transaction is open (TEMPORARY tables do not commit, so they pass).
pls_test_add_early_hook('query', function ($query) {
	if (null !== $GLOBALS['pls_test_tx'] && preg_match('/^\s*(CREATE|ALTER|DROP|TRUNCATE|RENAME)\b(?!\s+TEMPORARY\b)/i', $query)) {
		$GLOBALS['pls_test_failures'][] = 'DDL blocked inside a transaction (it would commit the fixtures): ' . substr(trim($query), 0, 120);
		return '';
	}
	return $query;
}, 10, 1);

/**
 * Everything from here on runs inside one transaction that pls_test_done() rolls back,
 * including what plugins write during init. Refuse to run if a table cannot roll back
 * (MyISAM would keep the fixtures).
 */
pls_test_add_early_hook('muplugins_loaded', function () {
	global $wpdb;
	foreach (array($wpdb->posts, $wpdb->postmeta, $wpdb->options, $wpdb->terms, $wpdb->term_taxonomy, $wpdb->term_relationships, $wpdb->users, $wpdb->usermeta) as $table) {
		$engine = $wpdb->get_var($wpdb->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $table));
		if (strtolower((string) $engine) !== 'innodb') {
			fwrite(STDERR, "Table {$table} uses engine '{$engine}', fixtures could not be rolled back. Aborting.\n");
			exit(2);
		}
	}
	if (!defined('PLS_TEST_MANUAL_TX')) {
		pls_test_begin();
	}
}, 1, 0);

// The guard goes to a temporary folder in tests, never to the site's wp-content/mu-plugins.
// The user's TEMP first: LocalWP's PHP reports C:\Windows\TEMP, where files can be
// created but the folder cannot be listed, so it could not be cleaned up.
define('PLS_TEST_GUARD_DIR', pls_test_normalize_path(getenv('TEMP') ?: (getenv('TMPDIR') ?: sys_get_temp_dir())) . '/pls-guard-' . getmypid());
pls_test_add_early_hook('parich_slug_guard_file', function () {
	return PLS_TEST_GUARD_DIR . '/parich-long-slug-guard.php';
}, 10, 0);

// Tests assert English text whatever the site language is.
pls_test_add_early_hook('locale', function () {
	return 'en_US';
}, 10, 0);

// Load the plugin even when it is not activated on the site, early enough for its hooks.
pls_test_add_early_hook('muplugins_loaded', function () {
	$active = (array) get_option('active_plugins', array());
	if (!in_array(PLS_TEST_PLUGIN_BASENAME, $active, true)) {
		require PLS_TEST_PLUGIN_FILE;
	}
}, 10, 0);

// _doing_it_wrong() / deprecation notices triggered from this plugin's code count as failures.
$pls_test_wp_notice = function ($function_name, $message = '') {
	foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
		if (isset($frame['file']) && strpos(pls_test_normalize_path($frame['file']), pls_test_normalize_path(PLS_TEST_PLUGIN_DIR) . '/') === 0) {
			$GLOBALS['pls_test_failures'][] = sprintf('WordPress notice from %s: %s', $function_name, wp_strip_all_tags((string) $message));
			return;
		}
	}
};
foreach (array('doing_it_wrong_run', 'deprecated_function_run', 'deprecated_argument_run', 'deprecated_hook_run') as $pls_test_hook) {
	pls_test_add_early_hook($pls_test_hook, $pls_test_wp_notice, 10, 2);
}

if (function_exists('pls_test_early_hooks')) {
	pls_test_early_hooks();
}

$pls_test_wp_load = getenv('WP_LOAD_PATH') ?: dirname(__DIR__, 4) . '/wp-load.php';
if (!file_exists($pls_test_wp_load)) {
	fwrite(STDERR, "wp-load.php not found at {$pls_test_wp_load} (set WP_LOAD_PATH)\n");
	exit(2);
}
require $pls_test_wp_load;
require_once ABSPATH . 'wp-admin/includes/plugin.php';

function pls_test_section($title)
{
	echo "\n{$title}\n";
}

function pls_test_assert($label, $condition, $detail = '')
{
	if ($condition) {
		$GLOBALS['pls_test_passes']++;
		echo "  [PASS] {$label}\n";
		return true;
	}
	$GLOBALS['pls_test_failures'][] = $label . ($detail !== '' ? " — {$detail}" : '');
	echo "  [FAIL] {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
	return false;
}

/**
 * Rolls back every fixture, drops table copies, prints the summary and exits
 * non-zero on any failure or when no assertion ran.
 */
function pls_test_done()
{
	global $wpdb;
	pls_test_rollback();
	foreach ($GLOBALS['pls_test_clones'] as $clone) {
		$wpdb->query("DROP TABLE IF EXISTS `{$clone}`");
	}
	pls_test_remove_dir(PLS_TEST_GUARD_DIR);
	if (0 === $GLOBALS['pls_test_passes'] && !$GLOBALS['pls_test_failures']) {
		$GLOBALS['pls_test_failures'][] = 'No assertions ran';
	}

	$failures = $GLOBALS['pls_test_failures'];
	echo "\n" . $GLOBALS['pls_test_passes'] . ' passed, ' . count($failures) . " failed\n";
	foreach ($failures as $failure) {
		echo "  - {$failure}\n";
	}
	exit($failures ? 1 : 0);
}

function pls_test_remove_dir($dir)
{
	if (!is_dir($dir)) {
		return;
	}
	$entries = scandir($dir);
	if (false === $entries) {
		$GLOBALS['pls_test_failures'][] = "Could not clean up {$dir}";
		return;
	}
	foreach ($entries as $entry) {
		if ('.' === $entry || '..' === $entry) {
			continue;
		}
		$path = $dir . '/' . $entry;
		is_dir($path) ? pls_test_remove_dir($path) : unlink($path);
	}
	rmdir($dir);
}

/**
 * Thrown by the wp_die and wp_redirect test handlers so handlers that end with
 * exit can be called from a test.
 */
class PLS_Test_Exit extends Exception
{
	public $status;
	public $location;

	public function __construct($message, $status = 0, $location = '')
	{
		parent::__construct((string) $message);
		$this->status = (int) $status;
		$this->location = (string) $location;
	}
}

/**
 * Makes wp_die() and wp_redirect() throw PLS_Test_Exit instead of ending the script.
 */
function pls_test_catch_exits()
{
	add_filter('wp_die_handler', function () {
		return function ($message, $title = '', $args = array()) {
			$parsed = _wp_die_process_input($message, $title, $args);
			throw new PLS_Test_Exit($parsed[0], $parsed[2]['response']);
		};
	});
	add_filter('wp_redirect', function ($location, $status) {
		throw new PLS_Test_Exit('redirect', $status, $location);
	}, PHP_INT_MAX, 2);
}
