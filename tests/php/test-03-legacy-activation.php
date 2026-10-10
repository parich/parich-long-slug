<?php
/**
 * Phase 2 (D8): activating the real Long URL Maker while Parich Long Slug is active
 * must not run its activation routine (which restores old backup rows over current
 * slugs and alters the slug columns), and it is switched off again right away.
 *
 * Safety nets: a probe on its activation hook (priority 9, before the routine at 10)
 * throws if the routine is still attached, so it can never run; the bootstrap blocks
 * any DDL inside the transaction; everything is rolled back at the end.
 *
 * @package Parich_Long_Slug
 */

const PLS_REAL_LEGACY = 'long-url-maker/long_url_maker.php';

require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/../fixtures/helpers.php';

global $wpdb;

if (!file_exists(WP_PLUGIN_DIR . '/' . PLS_REAL_LEGACY)) {
	pls_test_assert('Long URL Maker 2.0.1 is installed at ' . PLS_REAL_LEGACY, false);
	pls_test_done();
}
if (is_plugin_active(PLS_REAL_LEGACY)) {
	pls_test_assert('Long URL Maker is inactive before the test', false);
	pls_test_done();
}

$kpcode_table = $wpdb->prefix . 'kpcode_url_posts';
$column_type = function () use ($wpdb) {
	return $wpdb->get_var($wpdb->prepare(
		'SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s',
		$wpdb->posts,
		'post_name'
	));
};
$column_before = $column_type();

$probe = array();
add_action('activate_' . PLS_REAL_LEGACY, function () use (&$probe) {
	$attached = false !== has_action('activate_' . PLS_REAL_LEGACY, 'kpcode_long_url_activation');
	$probe[] = $attached;
	if ($attached) {
		throw new PLS_Test_Exit('Long URL Maker activation routine is still attached');
	}
}, 9);

$activate = function () {
	$level = ob_get_level();
	try {
		$result = activate_plugin(PLS_REAL_LEGACY);
		$stopped = null;
	} catch (PLS_Test_Exit $e) {
		$result = null;
		$stopped = $e;
	}
	while (ob_get_level() > $level) {
		ob_end_clean();
	}
	return array($result, $stopped);
};

wp_set_current_user(pls_test_user('administrator'));

pls_test_section('activate_plugin() with the real Long URL Maker');
list($result, $stopped) = $activate();
pls_test_assert('activate_plugin() returns no error', !is_wp_error($result), is_wp_error($result) ? $result->get_error_message() : '');
pls_test_assert('its activation routine was detached before it could run', null === $stopped && array(false) === $probe, $stopped ? $stopped->getMessage() : wp_json_encode($probe));
pls_test_assert('Long URL Maker was switched off again right after activation', !is_plugin_active(PLS_REAL_LEGACY));
pls_test_assert('its backup table was not created', null === $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $kpcode_table)));
// Counted in the table: the guard answers get_option('kpcode_version_wp') with the WordPress version.
$kpcode_options = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE 'kpcode\\_%' OR option_name = 'c_version_wp'");
pls_test_assert('its options were not written', 0 === $kpcode_options, "{$kpcode_options} rows");
pls_test_assert('wp_posts.post_name type is unchanged', $column_before === $column_type(), $column_before . ' → ' . $column_type());
pls_test_assert(
	'its sanitize_title filter is gone; Parich Long Slug\'s (or core\'s) handles slugs for the rest of the request',
	false === has_filter('sanitize_title', 'kpcode_set_url_format')
		&& (Parich_Slug_Sanitize::is_active() xor 10 === has_filter('sanitize_title', 'sanitize_title_with_dashes'))
);
$html = pls_legacy_notices();
pls_test_assert('the next admin page explains why it was switched off', false !== strpos($html, 'switched off again right after activation'));

pls_test_section('Negative control: without the guard the probe catches the routine');
// What a fresh request does: including the file registers the activation hook again.
remove_action('activate_plugin', array('Parich_Slug_Legacy', 'before_activate'));
add_action('activate_' . PLS_REAL_LEGACY, 'kpcode_long_url_activation');
$probe = array();
list($result, $stopped) = $activate();
pls_test_assert('the probe saw the routine attached and stopped activation', null !== $stopped && array(true) === $probe, wp_json_encode($probe));
pls_test_assert('the routine never ran: still no backup table', null === $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $kpcode_table)));
pls_test_assert('Long URL Maker is not active', !is_plugin_active(PLS_REAL_LEGACY));

pls_test_done();
