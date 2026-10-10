<?php
/**
 * Runs when Parich Long Slug is deleted from the Plugins screen.
 *
 * Removes this plugin's tables and settings. On purpose it does NOT:
 * - shorten the slug columns or change any slug: long slugs keep working until
 *   WordPress shortens the columns at its next database upgrade;
 * - remove the guard from mu-plugins while slugs longer than 200 characters remain,
 *   because without it that upgrade would cut them;
 * - touch Long URL Maker's backup table or settings (they belong to Long URL Maker).
 *
 * The redirects made by the repair tool are deleted with their table: the plugin
 * page and the readme ask to download them first.
 *
 * WordPress loads this file without the plugin, so it uses nothing from it.
 *
 * @package Parich_Long_Slug
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
	exit;
}

global $wpdb;

foreach (array('parich_slug_shadow', 'parich_slug_scan_items', 'parich_slug_scan_urls', 'parich_slug_repair_log', 'parich_slug_redirects') as $parich_slug_table) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- removing this plugin's own tables.
	$wpdb->query($wpdb->prepare('DROP TABLE IF EXISTS %i', $wpdb->prefix . $parich_slug_table));
}

foreach (array(
	'parich_slug_max_length',
	'parich_slug_columns_ok',
	'parich_slug_checked_db_version',
	'parich_slug_setup_error',
	'parich_slug_last_heal',
	'parich_slug_heal_notice',
	'parich_slug_heal_lock',
	'parich_slug_shadow_version',
	'parich_slug_repair_db_version',
	'parich_slug_scan',
	'parich_slug_scan_seq',
	'parich_slug_apply',
	'parich_slug_batch_seq',
	'parich_slug_redirect_count',
) as $parich_slug_option) {
	delete_option($parich_slug_option);
}

// Notices waiting for a user (transients named after the user).
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- transients with per-user names cannot be listed through the API.
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_parich\\_slug\\_%' OR option_name LIKE '\\_transient\\_timeout\\_parich\\_slug\\_%'");

// The guard goes only when no slug needs it any more.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one check at uninstall.
$parich_slug_long = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE CHAR_LENGTH(post_name) > 200") + (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->terms} WHERE CHAR_LENGTH(slug) > 200"); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- see above.
$parich_slug_guard = (string) apply_filters('parich_slug_guard_file', WPMU_PLUGIN_DIR . '/parich-long-slug-guard.php');
if (0 === $parich_slug_long && is_file($parich_slug_guard)) {
	$parich_slug_header = get_file_data($parich_slug_guard, array('version' => 'Guard Version'));
	if ('' !== $parich_slug_header['version']) {
		wp_delete_file($parich_slug_guard);
	}
}
