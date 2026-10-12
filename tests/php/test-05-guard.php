<?php
/**
 * Phase 3 (L1, L12): the guard keeps dbDelta() from shrinking the slug columns and
 * stops Long URL Maker's file-level restore code; installing/removing the guard;
 * the db_version check; the setup-error notice and its "Try again" button.
 * Needs the site's columns already widened (activate the plugin once).
 *
 * @package Parich_Long_Slug
 */

require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/../fixtures/helpers.php';
require_once ABSPATH . 'wp-admin/includes/upgrade.php';
pls_test_catch_exits();

global $wpdb, $wp_version;

$real = Parich_Slug_Schema::get_column_info($wpdb->posts, 'post_name');
if (is_wp_error($real) || $real['length'] < Parich_Slug_Schema::LENGTH) {
	pls_test_assert('precondition: the site\'s slug columns are widened (activate Parich Long Slug on this site once)', false);
	pls_test_done();
}

function pls_schema_notices($screen = 'plugins')
{
	return pls_test_notices(array('Parich_Slug_Schema', 'admin_notices'), $screen);
}

/**
 * dbDelta() messages about the slug columns, without changing anything.
 */
function pls_dbdelta_slug_changes()
{
	return array_values(array_filter(dbDelta(wp_get_db_schema('blog'), false), function ($line) {
		return (bool) preg_match('/\.(post_name|slug) from /', $line);
	}));
}

pls_test_section('Guard on this site');
$site_guard = WPMU_PLUGIN_DIR . '/' . Parich_Slug_Guard::FILE_NAME;
pls_test_assert('installed in wp-content/mu-plugins and current', Parich_Slug_Guard::is_current($site_guard));
pls_test_assert('loaded as a must-use plugin (PARICH_SLUG_GUARD_VERSION defined)', defined('PARICH_SLUG_GUARD_VERSION') && in_array(wp_normalize_path($site_guard), array_map('wp_normalize_path', wp_get_mu_plugins()), true));
if (!defined('PARICH_SLUG_GUARD_VERSION')) {
	require PLS_TEST_PLUGIN_DIR . '/includes/guard-template.php';
}

pls_test_section('dbDelta() during a WordPress database upgrade (L1)');
$changes = pls_dbdelta_slug_changes();
pls_test_assert('with the guard: no change proposed for post_name or slug', array() === $changes, implode(' | ', $changes));
remove_filter('dbdelta_create_queries', 'parich_slug_guard_dbdelta_queries');
$changes = pls_dbdelta_slug_changes();
$expected = array(
	"Changed type of {$wpdb->posts}.post_name from varchar(2000) to varchar(200)",
	"Changed type of {$wpdb->terms}.slug from varchar(2000) to varchar(200)",
);
pls_test_assert('negative control — without it dbDelta() would shrink both columns', array() === array_diff($expected, $changes), implode(' | ', $changes));
add_filter('dbdelta_create_queries', 'parich_slug_guard_dbdelta_queries');
pls_test_assert('the dry runs changed nothing', 2000 === Parich_Slug_Schema::get_column_info($wpdb->posts, 'post_name')['length'] && 2000 === Parich_Slug_Schema::get_column_info($wpdb->terms, 'slug')['length']);

pls_test_section('Long URL Maker\'s file-level restore code (L12)');
$legacy_file = WP_PLUGIN_DIR . '/long-url-maker/long_url_maker.php';
$source = file_exists($legacy_file) ? (string) file_get_contents($legacy_file) : '';
$start = strpos($source, 'global $kpcode_longurlmaker_version;');
$end = strpos($source, '/// activate plugin');
if (false === $start || false === $end || function_exists('kpcode_restore_db_posts')) {
	pls_test_assert('precondition: Long URL Maker 2.0.1 is installed and not loaded', false);
} else {
	// Its real file-level code (L13–L45), run with the database functions replaced by counters.
	$GLOBALS['pls_restore_calls'] = array();
	function kpcode_cre_db_posts()
	{
		$GLOBALS['pls_restore_calls'][] = __FUNCTION__;
	}
	function kpcode_setup_new_database()
	{
		$GLOBALS['pls_restore_calls'][] = __FUNCTION__;
	}
	function kpcode_restore_db_posts()
	{
		$GLOBALS['pls_restore_calls'][] = __FUNCTION__;
	}
	wp_mkdir_p(PLS_TEST_GUARD_DIR);
	$file_level = PLS_TEST_GUARD_DIR . '/legacy-file-level.php';
	file_put_contents($file_level, "<?php\n" . substr($source, $start, $end - $start));
	$run = function () use ($file_level) {
		$GLOBALS['pls_restore_calls'] = array();
		include $file_level;
		return $GLOBALS['pls_restore_calls'];
	};

	// The state Long URL Maker leaves behind: its version option and an older WordPress version.
	update_option('kpcode_longurlmaker_version', '2.0');
	update_option('kpcode_version_wp', '4.0');
	update_option('c_version_wp', '4.0');
	pls_test_assert('get_option(\'kpcode_version_wp\') reports the running WordPress version', $wp_version === get_option('kpcode_version_wp'));
	pls_test_assert('get_option(\'c_version_wp\') reports nothing', '' === get_option('c_version_wp'));
	$calls = $run();
	pls_test_assert('with the guard its restore code does not run', array() === $calls, implode(', ', $calls));

	remove_filter('pre_option_kpcode_version_wp', 'parich_slug_guard_legacy_version');
	remove_filter('pre_option_c_version_wp', '__return_empty_string');
	$calls = $run();
	pls_test_assert('negative control — without the guard it widens and restores', in_array('kpcode_setup_new_database', $calls, true) && in_array('kpcode_restore_db_posts', $calls, true), implode(', ', $calls));
	add_filter('pre_option_kpcode_version_wp', 'parich_slug_guard_legacy_version');
	add_filter('pre_option_c_version_wp', '__return_empty_string');
}

pls_test_section('install(), is_current(), remove()');
$path = PLS_TEST_GUARD_DIR . '/install/' . Parich_Slug_Guard::FILE_NAME;
$result = Parich_Slug_Guard::install($path);
pls_test_assert('install() writes the guard', true === $result && file_exists($path), is_wp_error($result) ? $result->get_error_message() : '');
pls_test_assert('the installed file is the template, byte for byte', file_get_contents(Parich_Slug_Guard::template()) === file_get_contents($path));
pls_test_assert('no temporary file is left behind', !file_exists($path . '.tmp'));
exec(escapeshellarg(PHP_BINARY) . ' -n -l ' . escapeshellarg($path) . ' 2>&1', $lint, $lint_status);
pls_test_assert('php -l passes on the installed file', 0 === $lint_status, implode(' ', $lint));
pls_test_assert('is_current() is true', Parich_Slug_Guard::is_current($path));
file_put_contents($path, str_replace('Guard Version: ', 'Guard Version: 0', (string) file_get_contents($path)));
pls_test_assert('an older guard is not current', !Parich_Slug_Guard::is_current($path));
pls_test_assert('install() replaces it', true === Parich_Slug_Guard::install($path) && Parich_Slug_Guard::is_current($path));
$blocker = PLS_TEST_GUARD_DIR . '/not-a-folder';
file_put_contents($blocker, 'x');
$result = Parich_Slug_Guard::install($blocker . '/sub/' . Parich_Slug_Guard::FILE_NAME);
pls_test_assert('a path that cannot be written gives WP_Error naming the path, no fatal', is_wp_error($result) && false !== strpos($result->get_error_message(), 'not-a-folder'), is_wp_error($result) ? $result->get_error_message() : 'no error');
pls_test_assert('remove() deletes the guard', true === Parich_Slug_Guard::remove($path) && !file_exists($path));
pls_test_assert('remove() on a missing guard returns false', false === Parich_Slug_Guard::remove($path));
pls_test_assert('is_current() on a missing guard is false', !Parich_Slug_Guard::is_current($path));

pls_test_section('db_version check on plugins_loaded');
$schema_queries = 0;
add_filter('query', function ($query) use (&$schema_queries) {
	if (false !== stripos($query, 'information_schema.COLUMNS')) {
		$schema_queries++;
	}
	return $query;
});
$db_version = (string) get_option('db_version');

delete_option(Parich_Slug_Schema::CHECKED_DB_VERSION_OPTION);
$schema_queries = 0;
Parich_Slug_Schema::maybe_check_db_version();
pls_test_assert('not set up yet: nothing is read', 0 === $schema_queries && false === get_option(Parich_Slug_Schema::CHECKED_DB_VERSION_OPTION));

update_option(Parich_Slug_Schema::CHECKED_DB_VERSION_OPTION, $db_version);
$schema_queries = 0;
Parich_Slug_Schema::maybe_check_db_version();
pls_test_assert('db_version unchanged: the columns are not read', 0 === $schema_queries);

update_option(Parich_Slug_Schema::CHECKED_DB_VERSION_OPTION, '1');
update_option(Parich_Slug_Schema::COLUMNS_OK_OPTION, false);
delete_option(Parich_Slug_Schema::LAST_HEAL_OPTION);
$schema_queries = 0;
Parich_Slug_Schema::maybe_check_db_version();
pls_test_assert('db_version changed: the columns are read', $schema_queries > 0);
pls_test_assert('…found long enough (no heal recorded) and the new db_version stored', $db_version === get_option(Parich_Slug_Schema::CHECKED_DB_VERSION_OPTION) && Parich_Slug_Schema::columns_ok() && false === get_option(Parich_Slug_Schema::LAST_HEAL_OPTION));

pls_test_section('Setup error notice and "Try again" button');
$admin = pls_test_user('administrator');
$subscriber = pls_test_user('subscriber');
update_option(Parich_Slug_Schema::ERROR_OPTION, 'Simulated setup error.', false);
wp_set_current_user($admin);
$html = pls_schema_notices();
pls_test_assert('administrator sees the error with a "Try again" button', false !== strpos($html, 'could not finish its setup') && false !== strpos($html, 'Simulated setup error.') && false !== strpos($html, 'Try again'));
pls_test_assert('the button posts its action with a nonce', false !== strpos($html, 'value="' . Parich_Slug_Schema::FIX_ACTION . '"') && false !== strpos($html, 'name="_wpnonce"'));
pls_test_assert('…also on the Dashboard and the plugin\'s page', false !== strpos(pls_schema_notices('dashboard'), 'Simulated setup error.') && false !== strpos(pls_schema_notices('settings_page_parich-long-slug'), 'Simulated setup error.'));
pls_test_assert('…but not across the admin: nothing in the post list, the editor or Settings → General (guideline 11)', '' === pls_schema_notices('edit-post') . pls_schema_notices('post') . pls_schema_notices('options-general'));
wp_set_current_user($subscriber);
pls_test_assert('subscriber sees nothing', '' === pls_schema_notices());

$call_fix = function () {
	try {
		Parich_Slug_Schema::handle_fix();
	} catch (PLS_Test_Exit $e) {
		return $e;
	}
	return null;
};
$_REQUEST['_wpnonce'] = wp_create_nonce(Parich_Slug_Schema::FIX_ACTION);
$exit = $call_fix();
pls_test_assert('subscriber with a valid nonce gets 403', $exit && 403 === $exit->status);
wp_set_current_user($admin);
unset($_REQUEST['_wpnonce']);
$exit = $call_fix();
pls_test_assert('administrator without a nonce gets 403', $exit && 403 === $exit->status);
pls_test_assert('…and the error is still shown', 'Simulated setup error.' === get_option(Parich_Slug_Schema::ERROR_OPTION));
$_REQUEST['_wpnonce'] = wp_create_nonce(Parich_Slug_Schema::FIX_ACTION);
$exit = $call_fix();
pls_test_assert('administrator with a nonce: setup runs and redirects to the Plugins screen', $exit && 'redirect' === $exit->getMessage() && admin_url('plugins.php') === $exit->location);
pls_test_assert('…the error is gone and the guard (test folder) is current', '' === (string) get_option(Parich_Slug_Schema::ERROR_OPTION, '') && Parich_Slug_Guard::is_current());

pls_test_done();
