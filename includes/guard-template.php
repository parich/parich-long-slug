<?php
/**
 * Plugin Name: Parich Long Slug Guard
 * Description: Keeps the long slug columns of Parich Long Slug when WordPress updates its database, and stops Long URL Maker's code that writes old slugs back. Installed by Parich Long Slug.
 * Guard Version: 2
 *
 * Why a must-use plugin: WordPress upgrades its database in wp-admin/upgrade.php,
 * which does not load normal plugins (WP_INSTALLING). That upgrade runs dbDelta(),
 * which would change wp_posts.post_name and wp_terms.slug back to varchar(200) and
 * cut every longer slug. Must-use plugins are loaded there, and before any normal
 * plugin, so this file also runs before Long URL Maker's file-level restore code.
 *
 * This file is copied from parich-long-slug/includes/guard-template.php. It stays
 * when Parich Long Slug is deactivated, and is deleted when the plugin is
 * uninstalled and no slug is longer than 200 characters. Do not delete it by hand
 * while slugs longer than 200 characters exist.
 *
 * @package Parich_Long_Slug
 */

if (!defined('ABSPATH')) {
	exit;
}
if (defined('PARICH_SLUG_GUARD_VERSION')) {
	return;
}

define('PARICH_SLUG_GUARD_VERSION', '2');

if (!function_exists('parich_slug_guard_dbdelta_queries')) {
	/**
	 * Makes dbDelta() expect varchar(2000) for the slug columns, so a database
	 * upgrade finds nothing to change there.
	 *
	 * @param array $queries CREATE TABLE statements keyed by table name.
	 * @return array
	 */
	function parich_slug_guard_dbdelta_queries($queries)
	{
		global $wpdb;
		$columns = array(
			$wpdb->posts => 'post_name',
			$wpdb->terms => 'slug',
		);
		foreach ($columns as $table => $column) {
			if (isset($queries[$table]) && is_string($queries[$table])) {
				$queries[$table] = preg_replace('/\b' . $column . '\s+varchar\(200\)/i', $column . ' varchar(2000)', $queries[$table]);
			}
		}
		return $queries;
	}
}

if (!function_exists('parich_slug_guard_legacy_version')) {
	/**
	 * Long URL Maker restores its old backup rows over current slugs whenever its stored
	 * WordPress version differs from the running one. Reporting the running version
	 * keeps that code from running, whatever order the plugins load in.
	 *
	 * @return string
	 */
	function parich_slug_guard_legacy_version()
	{
		return (string) $GLOBALS['wp_version'];
	}
}

add_filter('dbdelta_create_queries', 'parich_slug_guard_dbdelta_queries');
add_filter('pre_option_kpcode_version_wp', 'parich_slug_guard_legacy_version');
// Same restore on the 1.0 → 2.0 upgrade path, which runs while this option is not empty.
add_filter('pre_option_c_version_wp', '__return_empty_string');
