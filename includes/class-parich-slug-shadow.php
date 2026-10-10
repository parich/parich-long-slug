<?php
/**
 * Copy of every slug longer than 200 characters, so slugs cut by a WordPress
 * database upgrade can be put back (the second line of defence when the guard is
 * missing).
 *
 * One row per post or term, kept up to date on every save — including REST and
 * block editor saves, which Long URL Maker's backup never saw. A slug is restored
 * only when the column now holds exactly the first 200 characters of the copy, so
 * slugs changed after the cut are left alone and reported.
 *
 * @package Parich_Long_Slug
 */

if (!defined('ABSPATH')) {
	exit;
}

class Parich_Slug_Shadow
{
	const VERSION = '1';
	const VERSION_OPTION = 'parich_slug_shadow_version';

	/** Length of core's slug columns: only longer slugs need a copy. */
	const CORE_LENGTH = 200;

	const BATCH = 200;

	public static function init()
	{
		// Before Parich_Slug_Schema::maybe_check_db_version() (priority 5), which may need the table.
		add_action('plugins_loaded', array(__CLASS__, 'maybe_install'), 4);
		add_action('wp_insert_post', array(__CLASS__, 'sync_post'), 20, 3);
		// wp_insert_post() returns early for attachments, before save_post and wp_insert_post.
		add_action('add_attachment', array(__CLASS__, 'sync_attachment'), 20, 1);
		add_action('attachment_updated', array(__CLASS__, 'sync_attachment'), 20, 1);
		add_action('deleted_post', array(__CLASS__, 'forget_post'), 10, 1);
		add_action('created_term', array(__CLASS__, 'sync_term'), 20, 1);
		add_action('edited_term', array(__CLASS__, 'sync_term'), 20, 1);
		add_action('delete_term', array(__CLASS__, 'forget_term'), 10, 1);
	}

	public static function table()
	{
		global $wpdb;
		return $wpdb->prefix . 'parich_slug_shadow';
	}

	/**
	 * Creates the table on the first request after installing or updating the plugin
	 * (updates do not run the activation hook). One option read otherwise.
	 */
	public static function maybe_install()
	{
		if (self::VERSION === get_option(self::VERSION_OPTION)) {
			return true;
		}
		return self::install();
	}

	/**
	 * Creates or updates the table; the first time, copies the long slugs already there.
	 *
	 * @return true|WP_Error
	 */
	public static function install()
	{
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table = self::table();
		dbDelta("CREATE TABLE {$table} (
  object_type varchar(20) NOT NULL,
  object_id bigint(20) unsigned NOT NULL,
  slug text NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (object_type,object_id)
) " . $wpdb->get_charset_collate() . ';');

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- checking the table just created.
		if ($table !== $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table)))) {
			return new WP_Error('parich_slug_shadow', sprintf('The table %s could not be created: %s', $table, $wpdb->last_error));
		}
		$first = false === get_option(self::VERSION_OPTION);
		update_option(self::VERSION_OPTION, self::VERSION);
		if ($first) {
			self::seed();
		}
		return true;
	}

	/**
	 * Copies every slug longer than 200 characters, in batches.
	 *
	 * @return int Rows written.
	 */
	public static function seed()
	{
		global $wpdb;
		$queries = array(
			'post' => "SELECT ID AS id, post_name AS slug FROM {$wpdb->posts} WHERE ID > %d AND CHAR_LENGTH(post_name) > %d AND post_type <> 'revision' AND post_status NOT IN ('trash', 'auto-draft') ORDER BY ID LIMIT %d",
			'term' => "SELECT term_id AS id, slug FROM {$wpdb->terms} WHERE term_id > %d AND CHAR_LENGTH(slug) > %d ORDER BY term_id LIMIT %d",
		);
		$written = 0;
		foreach ($queries as $type => $sql) {
			$last = 0;
			do {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- one-time batch read; $sql holds placeholders only.
				$rows = $wpdb->get_results($wpdb->prepare($sql, $last, self::CORE_LENGTH, self::BATCH));
				foreach ($rows as $row) {
					self::remember($type, (int) $row->id, $row->slug);
					$last = (int) $row->id;
					++$written;
				}
			} while (count($rows) === self::BATCH);
		}
		return $written;
	}

	public static function remember($type, $id, $slug)
	{
		global $wpdb;
		$now = current_time('mysql', true);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- plugin table; upsert has no API.
		$wpdb->query($wpdb->prepare(
			'INSERT INTO %i (object_type, object_id, slug, updated_at) VALUES (%s, %d, %s, %s) ON DUPLICATE KEY UPDATE slug = %s, updated_at = %s',
			self::table(),
			$type,
			$id,
			$slug,
			$now,
			$slug,
			$now
		));
	}

	public static function forget($type, $id)
	{
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- plugin table.
		$wpdb->delete(self::table(), array('object_type' => $type, 'object_id' => (int) $id));
	}

	/**
	 * Runs after every post save. Trashed posts keep their copy: core stores their
	 * full slug in _wp_desired_post_slug and puts it back when they are restored.
	 */
	public static function sync_post($post_id, $post, $update)
	{
		if (!$post instanceof WP_Post || 'revision' === $post->post_type || in_array($post->post_status, array('trash', 'auto-draft'), true)) {
			return;
		}
		if (strlen($post->post_name) > self::CORE_LENGTH) {
			self::remember('post', $post_id, $post->post_name);
		} elseif ($update) {
			self::forget('post', $post_id);
		}
	}

	public static function sync_attachment($post_id)
	{
		$post = get_post($post_id);
		if ($post instanceof WP_Post) {
			self::sync_post($post_id, $post, true);
		}
	}

	public static function forget_post($post_id)
	{
		self::forget('post', $post_id);
	}

	public static function sync_term($term_id)
	{
		$term = get_term((int) $term_id);
		if (!$term instanceof WP_Term) {
			return;
		}
		if (strlen($term->slug) > self::CORE_LENGTH) {
			self::remember('term', $term->term_id, $term->slug);
		} else {
			self::forget('term', $term->term_id);
		}
	}

	public static function forget_term($term_id)
	{
		self::forget('term', $term_id);
	}

	/**
	 * Puts back slugs that a database upgrade cut to 200 characters: only when the
	 * column holds exactly the first 200 characters of the copy, and only if it still
	 * holds them when updated. Run after the columns were widened again.
	 *
	 * @param array|null $tables shadow, post and term table names; default: the site's.
	 * @return array {checked, restored, skipped: "type:id" of slugs changed after the cut}
	 */
	public static function restore($tables = null)
	{
		global $wpdb;
		$tables = $tables ? $tables : array('shadow' => self::table(), 'post' => $wpdb->posts, 'term' => $wpdb->terms);
		$targets = array(
			'post' => array($tables['post'], 'ID', 'post_name'),
			'term' => array($tables['term'], 'term_id', 'slug'),
		);
		$result = array('checked' => 0, 'restored' => 0, 'skipped' => array());

		foreach ($targets as $type => list($table, $id_column, $slug_column)) {
			$last = 0;
			do {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- heal after a database update, in batches.
				$rows = $wpdb->get_results($wpdb->prepare(
					'SELECT s.object_id, s.slug AS saved, t.%i AS current FROM %i s JOIN %i t ON t.%i = s.object_id WHERE s.object_type = %s AND s.object_id > %d ORDER BY s.object_id LIMIT %d',
					$slug_column,
					$tables['shadow'],
					$table,
					$id_column,
					$type,
					$last,
					self::BATCH
				));
				foreach ($rows as $row) {
					$last = (int) $row->object_id;
					++$result['checked'];
					if ($row->current === $row->saved) {
						continue;
					}
					$cut = self::CORE_LENGTH === strlen($row->current) && 0 === strpos($row->saved, $row->current);
					// A plain UPDATE, not $wpdb->update(): wpdb caches column lengths per request and
					// could still hold varchar(200) from before the columns were widened, then refuse.
					// Only while the cut slug is still there.
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- heal: put back the slug the database update cut.
					if ($cut && 1 === (int) $wpdb->query($wpdb->prepare('UPDATE %i SET %i = %s WHERE %i = %d AND %i = %s', $table, $slug_column, $row->saved, $id_column, $last, $slug_column, $row->current))) {
						++$result['restored'];
						if ($table === $wpdb->posts) {
							clean_post_cache($last);
						} elseif ($table === $wpdb->terms) {
							clean_term_cache($last);
						}
					} else {
						$result['skipped'][] = "{$type}:{$last}";
					}
				}
			} while (count($rows) === self::BATCH);
		}
		return $result;
	}
}
