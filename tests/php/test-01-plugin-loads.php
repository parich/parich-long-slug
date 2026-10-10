<?php
/**
 * Phase 2: the plugin loads, has the right header, refuses network activation and
 * activates on this site.
 *
 * @package Parich_Long_Slug
 */

require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/../fixtures/helpers.php';
pls_test_catch_exits();

pls_test_section('Plugin loads');
pls_test_assert('Parich_Slug_Legacy is loaded', class_exists('Parich_Slug_Legacy'));
pls_test_assert('PARICH_SLUG_VERSION is 0.1.0', defined('PARICH_SLUG_VERSION') && '0.1.0' === PARICH_SLUG_VERSION);
$hooks = array(
	'plugins_loaded' => 'neutralize',
	'activate_plugin' => 'before_activate',
	'activated_plugin' => 'after_activate',
	'admin_notices' => 'admin_notices',
	'admin_post_' . Parich_Slug_Legacy::DEACTIVATE_ACTION => 'handle_safe_deactivate',
);
foreach ($hooks as $hook => $method) {
	pls_test_assert("{$hook} → Parich_Slug_Legacy::{$method}", false !== has_action($hook, array('Parich_Slug_Legacy', $method)));
}

pls_test_section('Plugin header');
$header = get_plugin_data(PLS_TEST_PLUGIN_FILE, false, false);
pls_test_assert('Name is "Parich Long Slug"', 'Parich Long Slug' === $header['Name'], $header['Name']);
pls_test_assert('Version is 0.1.0', '0.1.0' === $header['Version'], $header['Version']);
pls_test_assert('Text Domain is parich-long-slug', 'parich-long-slug' === $header['TextDomain']);
pls_test_assert('Requires at least 6.2, Requires PHP 7.4', '6.2' === $header['RequiresWP'] && '7.4' === $header['RequiresPHP']);
$sources = array_merge(array(PLS_TEST_PLUGIN_FILE), glob(PLS_TEST_PLUGIN_DIR . '/includes/*.php'));
$loads_textdomain = false;
foreach ($sources as $source) {
	$loads_textdomain = $loads_textdomain || false !== strpos((string) file_get_contents($source), 'load_plugin_textdomain');
}
pls_test_assert(
	'translations from translate.wordpress.org: no Domain Path and no load_plugin_textdomain()',
	'' === $header['DomainPath'] && !$loads_textdomain
);

pls_test_section('Network activation is refused');
try {
	parich_slug_activate(true);
	$died = null;
} catch (PLS_Test_Exit $e) {
	$died = $e;
}
pls_test_assert('parich_slug_activate(true) stops with an explanation', $died && false !== strpos($died->getMessage(), 'does not support network activation'));
try {
	parich_slug_activate(false);
	$died = null;
} catch (PLS_Test_Exit $e) {
	$died = $e;
}
pls_test_assert('parich_slug_activate(false) lets activation continue', null === $died);

pls_test_section('Activation on this site (rolled back afterwards)');
global $wpdb;
$real = Parich_Slug_Schema::get_column_info($wpdb->posts, 'post_name');
if (is_wp_error($real) || $real['length'] < Parich_Slug_Schema::LENGTH) {
	// Activation widens the columns, which is DDL and cannot run inside the test transaction.
	pls_test_assert('precondition: the site\'s slug columns are widened (activate Parich Long Slug on this site once)', false, is_wp_error($real) ? $real->get_error_message() : $real['type']);
} else {
	delete_option(Parich_Slug_Schema::CHECKED_DB_VERSION_OPTION);
	if (is_plugin_active(PLS_TEST_PLUGIN_BASENAME)) {
		echo "  (plugin is active on the site: running its activation callback directly)\n";
		ob_start();
		parich_slug_activate(false);
		$output = ob_get_clean();
	} else {
		ob_start();
		$result = activate_plugin(PLS_TEST_PLUGIN_BASENAME);
		$output = ob_get_clean();
		pls_test_assert('activate_plugin() returns no error', !is_wp_error($result), is_wp_error($result) ? $result->get_error_message() : '');
		pls_test_assert('plugin is active after activation', is_plugin_active(PLS_TEST_PLUGIN_BASENAME));
	}
	pls_test_assert('activation prints no unexpected output', '' === $output, $output);
	pls_test_assert('no setup error is stored', '' === (string) get_option(Parich_Slug_Schema::ERROR_OPTION, ''), (string) get_option(Parich_Slug_Schema::ERROR_OPTION, ''));
	pls_test_assert('columns are recorded as long enough', Parich_Slug_Schema::columns_ok());
	pls_test_assert('the checked db_version is the current one', (string) get_option('db_version') === get_option(Parich_Slug_Schema::CHECKED_DB_VERSION_OPTION));
	pls_test_assert('the guard is installed (test folder) and current', Parich_Slug_Guard::is_current() && 0 === strpos(Parich_Slug_Guard::path(), PLS_TEST_GUARD_DIR));
}

pls_test_section('Without Long URL Maker there is nothing to show');
if (Parich_Slug_Legacy::active_basename()) {
	echo "  (Long URL Maker is active on this site: skipped)\n";
} else {
	wp_set_current_user(pls_test_user('administrator'));
	pls_test_assert('administrator sees no Long URL Maker notice', '' === pls_legacy_notices());
}

pls_test_done();
