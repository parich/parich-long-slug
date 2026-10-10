<?php
/**
 * For the directory screenshots (tools/wporg-assets.js): on a damage lab snapshot loaded
 * under its own prefix, scans, saves the HTML of the "Repair slugs" tab, repairs the
 * items marked ready and saves the tab again. Only the snapshot's tables change (the
 * caller drops them afterwards); users are the site's own, read only.
 *
 *   wp eval-file render-repair.php <plslab_x_> <output dir>
 *
 * Writes repair-scan.html and repair-done.html (the tab's content, without the page around it).
 *
 * @package Parich_Long_Slug
 */

if (!defined('WP_CLI')) {
	exit;
}

list($prefix, $out) = array_pad($args, 2, '');
if (!preg_match('/^plslab_[a-z0-9_]*_$/', $prefix) || !is_dir($out)) {
	WP_CLI::error('Usage: wp eval-file render-repair.php <plslab_x_> <output dir>');
}

global $wpdb, $wp_rewrite;
$users = array($wpdb->users, $wpdb->usermeta);
$wpdb->set_prefix($prefix);
list($wpdb->users, $wpdb->usermeta) = $users;
wp_cache_flush();
// No current user, as with WP-CLI without --user: the "By" column stays empty, so no
// user name appears in the pictures.
wp_set_current_user(0);
if ($wpdb->posts !== $prefix . 'posts' || !$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i', $wpdb->posts))) {
	WP_CLI::error("WordPress could not be pointed at {$prefix}*.");
}

if (true !== Parich_Slug_Repair_Scanner::maybe_install() || true !== Parich_Slug_Shadow::install()) {
	WP_CLI::error('Could not create the plugin tables under the snapshot prefix.');
}
// What activation does on a site Long URL Maker left at varchar(200).
Parich_Slug_Schema::ensure_columns();
$wp_rewrite->init();
$ready = Parich_Slug_Repair_Applier::check_ready();
if (true !== $ready) {
	WP_CLI::error('Not ready to repair: ' . $ready->get_error_message());
}

$scan = Parich_Slug_Repair_Scanner::run();
if (is_wp_error($scan) || 'done' !== $scan['step']) {
	WP_CLI::error('Scan failed.');
}
ob_start();
Parich_Slug_Repair_Admin::render();
file_put_contents($out . '/repair-scan.html', ob_get_clean());
WP_CLI::log(sprintf('Scan: %d ready, %d to check, %d unresolved', $scan['counts']['auto'], $scan['counts']['review'], $scan['counts']['unresolved']));

$state = Parich_Slug_Repair_Applier::run('auto');
if (is_wp_error($state)) {
	WP_CLI::error('Repair failed: ' . $state->get_error_message());
}
ob_start();
Parich_Slug_Repair_Admin::render();
file_put_contents($out . '/repair-done.html', ob_get_clean());
WP_CLI::log(sprintf('Repair %d: %d slugs, %d redirects', $state['batch'], $state['applied'], $state['redirects']));
