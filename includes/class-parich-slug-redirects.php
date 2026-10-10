<?php
/**
 * 301 redirects from damaged addresses to the repaired object, made by the repair
 * tool ("repair") or by an admin matching a logged 404 to a post ("manual-map").
 *
 * A redirect points at an object, not at a URL: the target is worked out when the
 * request comes, so it follows later slug changes. Only requests that end in a 404
 * are looked up, and only while active redirects exist (counted in an autoloaded
 * option), so ordinary requests never query the table.
 *
 * @package Parich_Long_Slug
 */

if (!defined('ABSPATH')) {
	exit;
}

class Parich_Slug_Redirects
{
	const COUNT_OPTION = 'parich_slug_redirect_count';

	public static function init()
	{
		// Before core's wp_old_slug_redirect() and redirect_canonical() (priority 10), which
		// may guess a wrong post from the start of a cut slug.
		add_action('template_redirect', array(__CLASS__, 'maybe_redirect'), 1);
	}

	public static function table()
	{
		global $wpdb;
		return $wpdb->prefix . 'parich_slug_redirects';
	}

	public static function maybe_redirect()
	{
		if (!is_404() || !(int) get_option(self::COUNT_OPTION) || !isset($_SERVER['REQUEST_URI'])) {
			return;
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- only parsed into a normalised path, never output.
		$redirect = self::find(wp_unslash($_SERVER['REQUEST_URI']));
		if (!$redirect) {
			return;
		}
		self::record_hit($redirect['id']);
		wp_redirect($redirect['location'], 301, 'Parich Long Slug'); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- the target is a permalink of this site.
		exit;
	}

	/**
	 * The active redirect for a request URI and where it goes now.
	 *
	 * @return array|null {id, location}
	 */
	public static function find($request_uri)
	{
		global $wpdb;
		$path = Parich_Slug_Repair_Util::path_of($request_uri, home_url('/'));
		if (null === $path || '/' === $path) {
			return null;
		}
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- plugin table, 404s only.
		$row = $wpdb->get_row($wpdb->prepare('SELECT id, object_type, object_id FROM %i WHERE source_hash = %s AND is_active = 1', $table, md5($path)));
		if (!$row) {
			return null;
		}
		$location = self::target_url($row->object_type, (int) $row->object_id);
		if (!$location || Parich_Slug_Repair_Util::path_of($location) === $path) {
			return null; // Gone, not public, or the same address (no loop).
		}
		$query = wp_parse_url($request_uri, PHP_URL_QUERY);
		if ($query) {
			$location .= (false === strpos($location, '?') ? '?' : '&') . $query;
		}
		return array('id' => (int) $row->id, 'location' => $location);
	}

	/**
	 * Current public address of a post or term, or null.
	 */
	public static function target_url($type, $id)
	{
		if ('term' === $type) {
			$term = get_term($id);
			if (!$term || is_wp_error($term)) {
				return null;
			}
			$taxonomy = get_taxonomy($term->taxonomy);
			if (!$taxonomy || !$taxonomy->public) {
				return null;
			}
			$link = get_term_link($term);
			return is_wp_error($link) ? null : $link;
		}
		$post = get_post($id);
		if (!$post || !is_post_publicly_viewable($post)) {
			return null;
		}
		$link = get_permalink($post);
		return $link ? $link : null;
	}

	private static function record_hit($id)
	{
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- plugin table.
		$wpdb->query($wpdb->prepare('UPDATE %i SET hits = hits + 1, last_hit = %s WHERE id = %d', $table, current_time('mysql', true), $id));
	}

	/**
	 * Adds a redirect from a path (normalised) to an object. A path that already has an
	 * active redirect keeps it; an inactive one (undone) is taken over.
	 *
	 * @return bool Whether the path now goes to this object.
	 */
	public static function add($path, $type, $id, $origin, $batch_id = 0)
	{
		global $wpdb;
		$path = Parich_Slug_Repair_Util::path_of($path, home_url('/'));
		if (null === $path || '/' === $path) {
			return false;
		}
		$table = self::table();
		$hash = md5($path);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- plugin table.
		$existing = $wpdb->get_row($wpdb->prepare('SELECT id, object_type, object_id, is_active FROM %i WHERE source_hash = %s', $table, $hash));
		if ($existing && (int) $existing->is_active) {
			return $type === $existing->object_type && (int) $id === (int) $existing->object_id;
		}
		$fields = array(
			'source_path' => $path,
			'object_type' => $type,
			'object_id' => (int) $id,
			'origin' => $origin,
			'batch_id' => (int) $batch_id,
			'is_active' => 1,
			'hits' => 0,
			'last_hit' => null,
			'created_at' => current_time('mysql', true),
		);
		if ($existing) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- plugin table.
			$wpdb->update($table, $fields, array('id' => (int) $existing->id));
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- plugin table.
			$wpdb->insert($table, array('source_hash' => $hash) + $fields);
		}
		return true;
	}

	public static function set_active($id, $active)
	{
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- plugin table.
		$wpdb->update(self::table(), array('is_active' => $active ? 1 : 0), array('id' => (int) $id));
		self::recount();
	}

	public static function delete($id)
	{
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- plugin table.
		$wpdb->delete(self::table(), array('id' => (int) $id));
		self::recount();
	}

	/**
	 * Stores the number of active redirects (autoloaded, read on every 404).
	 */
	public static function recount()
	{
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- plugin table.
		$exists = $table === $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table)));
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- plugin table.
		$count = $exists ? (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE is_active = 1', $table)) : 0;
		update_option(self::COUNT_OPTION, $count, true);
		return $count;
	}

	/**
	 * @return array {rows, total}
	 */
	public static function page($offset = 0, $limit = 50)
	{
		global $wpdb;
		$table = self::table();
		if (Parich_Slug_Repair_Scanner::VERSION !== get_option(Parich_Slug_Repair_Scanner::VERSION_OPTION)) {
			return array('rows' => array(), 'total' => 0);
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- plugin table.
		$total = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i', $table));
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- plugin table.
		$rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM %i ORDER BY is_active DESC, id DESC LIMIT %d OFFSET %d', $table, $limit, $offset));
		return array('rows' => $rows, 'total' => $total);
	}

	/**
	 * Every redirect as CSV rows (header first), to move them to another redirect plugin.
	 */
	public static function export_rows()
	{
		global $wpdb;
		$rows = array(array('source_url', 'target_url', 'status', 'origin', 'hits', 'last_hit', 'object_type', 'object_id'));
		if (Parich_Slug_Repair_Scanner::VERSION !== get_option(Parich_Slug_Repair_Scanner::VERSION_OPTION)) {
			return $rows;
		}
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- plugin table, export on demand.
		foreach ($wpdb->get_results($wpdb->prepare('SELECT * FROM %i ORDER BY id', $table)) as $row) {
			$rows[] = array_map(array('Parich_Slug_Repair_Scanner', 'csv_cell'), array(
				home_url(rawurldecode($row->source_path) . '/'),
				(string) self::target_url($row->object_type, (int) $row->object_id),
				(int) $row->is_active ? '301' : 'off',
				$row->origin,
				(int) $row->hits,
				(string) $row->last_hit,
				$row->object_type,
				(int) $row->object_id,
			));
		}
		return $rows;
	}
}
