<?php
/**
 * Applies chosen slugs from the last scan, in batches, and undoes them.
 *
 * For each object, terms first and parents before children:
 *   1. note its address and every address that changes with it (descendants, posts
 *      whose links contain the category) as they are now;
 *   2. change only the slug, and only if it is still what the scan saw
 *      (no save_post, post_modified untouched);
 *   3. log the change, update the shadow copy, add a row to Long URL Maker's backup
 *      table if it is still there (so its restore can only write the repaired slug);
 *   4. redirect every address that changed to the object (301, see Parich_Slug_Redirects).
 *
 * Preconditions are checked again before every batch.
 *
 * @package Parich_Long_Slug
 */

if (!defined('ABSPATH')) {
	exit;
}

class Parich_Slug_Repair_Applier
{
	const STATE_OPTION = 'parich_slug_apply';
	const BATCH_SEQ_OPTION = 'parich_slug_batch_seq';
	const BATCH = 50;

	/** Seconds without a step after which an unfinished batch (page closed) no longer blocks a new one. */
	const STALE_AFTER = 300;

	/** Most addresses noted per object (descendants, posts in a category). */
	const MAX_INDIRECT = 500;

	public static function log_table()
	{
		global $wpdb;
		return $wpdb->prefix . 'parich_slug_repair_log';
	}

	/**
	 * Why repairs cannot be applied now, or true.
	 *
	 * @return true|WP_Error
	 */
	public static function check_ready()
	{
		if (Parich_Slug_Schema::shrunk_columns()) {
			return new WP_Error('parich_slug_columns', __('The slug columns are still shorter than 2,000 characters: repaired slugs would be cut again. Use "Set up again" on the Settings tab.', 'parich-long-slug'));
		}
		if (null !== Parich_Slug_Legacy::active_basename()) {
			return new WP_Error('parich_slug_legacy_active', __('Long URL Maker is active: its restore would write old slugs over the repaired ones. Deactivate it with the button in the notice first.', 'parich-long-slug'));
		}
		$legacy = Parich_Slug_Legacy::traces();
		if (!Parich_Slug_Guard::is_current() && $legacy['installed']) {
			return new WP_Error('parich_slug_guard', __('The guard is missing while Long URL Maker\'s files are still on the site: activating it again would undo repairs. Use "Set up again" on the Settings tab, or delete Long URL Maker.', 'parich-long-slug'));
		}
		$scanner = Parich_Slug_Repair_Scanner::maybe_install();
		return is_wp_error($scanner) ? $scanner : true;
	}

	public static function state()
	{
		$state = get_option(self::STATE_OPTION);
		return is_array($state) && isset($state['batch']) ? $state : null;
	}

	private static function save_state(array $state)
	{
		update_option(self::STATE_OPTION, $state, false);
		return $state;
	}

	/**
	 * The slug that would be written for an item: the admin's own value, else the proposal.
	 *
	 * @return string|WP_Error
	 */
	public static function chosen_value(array $item, $own = '')
	{
		$object = $item['object'];
		$value = '' !== trim((string) $own) ? Parich_Slug_Repair_Scanner::normalize_slug($own) : $item['proposal'];
		if (null === $value) {
			return new WP_Error('parich_slug_no_value', __('No slug was proposed: type one.', 'parich-long-slug'));
		}
		if ('post' === $object['type'] && 'trash' === $object['status'] && '' !== $value) {
			$value = Parich_Slug_Repair_Detectors::trashed_form($value);
		}
		$unslugged = 'post' === $object['type'] && in_array($object['status'], Parich_Slug_Repair_Detectors::UNSLUGGED_STATUSES, true);
		if (!Parich_Slug_Repair_Util::is_valid($value) && !('' === $value && $unslugged)) {
			return new WP_Error('parich_slug_invalid', __('Not a valid slug.', 'parich-long-slug'));
		}
		if ($value === $object['slug']) {
			return new WP_Error('parich_slug_same', __('The slug would not change.', 'parich-long-slug'));
		}
		return $value;
	}

	/**
	 * Starts a batch from the last scan of this site.
	 *
	 * @param array|string $choices "auto" for every pre-selected item, or key ("post-12") => own slug ('' = proposal).
	 * @return array|WP_Error State.
	 */
	public static function start($choices)
	{
		global $wpdb;
		$ready = self::check_ready();
		if (is_wp_error($ready)) {
			return $ready;
		}
		$scan = Parich_Slug_Repair_Scanner::state();
		if (!$scan || 'done' !== $scan['step'] || null !== $scan['prefix']) {
			return new WP_Error('parich_slug_no_scan', __('Scan this site first.', 'parich-long-slug'));
		}
		$current = self::state();
		if ($current && empty($current['finished']) && time() - (int) ($current['touched'] ?? 0) < self::STALE_AFTER) {
			return new WP_Error('parich_slug_busy', __('Another repair is still running. Wait for it to finish.', 'parich-long-slug'));
		}
		$scan_id = (int) $scan['id'];
		$items = 'auto' === $choices
			? Parich_Slug_Repair_Scanner::load_items($scan_id, array('group' => 'auto'))
			: self::items_for(array_keys((array) $choices), $scan_id);
		$queue = array();
		$refused = array();
		$table = Parich_Slug_Repair_Scanner::items_table();
		foreach ($items as $row_id => $item) {
			$key = $item['object']['type'] . '-' . $item['object']['id'];
			$own = is_array($choices) && isset($choices[$key]) ? $choices[$key] : '';
			$value = self::chosen_value($item, $own);
			if (is_wp_error($value)) {
				$refused[] = array('key' => $key, 'reason' => $value->get_error_message());
				continue;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- plugin table.
			$wpdb->update($table, array('chosen' => $value), array('id' => $row_id));
			// Terms before posts (a category's posts change address with it), parents before children.
			$queue[] = array('post' === $item['object']['type'] ? 1 : 0, (int) $item['row']->depth, $row_id);
		}
		if (!$queue) {
			return new WP_Error('parich_slug_nothing', __('Nothing to repair was selected.', 'parich-long-slug'), $refused);
		}
		sort($queue);
		$batch = (int) get_option(self::BATCH_SEQ_OPTION, 0) + 1;
		update_option(self::BATCH_SEQ_OPTION, $batch, false);
		return self::save_state(array(
			'batch' => $batch,
			'scan' => $scan_id,
			'queue' => array_column($queue, 2),
			'cursor' => 0,
			'applied' => 0,
			'redirects' => 0,
			'skipped' => $refused,
			'user' => get_current_user_id(),
			'started' => time(),
			'touched' => time(),
			'finished' => null,
		));
	}

	private static function items_for(array $keys, $scan_id)
	{
		$items = array();
		foreach ($keys as $key) {
			if (!preg_match('/^(post|term)-(\d+)$/', (string) $key, $match)) {
				continue;
			}
			$items += Parich_Slug_Repair_Scanner::load_items($scan_id, array('object' => array($match[1], (int) $match[2])));
		}
		return $items;
	}

	/**
	 * Applies the next batch.
	 *
	 * @return array|WP_Error State.
	 */
	public static function step()
	{
		$state = self::state();
		if (!$state || !empty($state['finished'])) {
			return new WP_Error('parich_slug_no_batch', 'No repair is running.');
		}
		$ready = self::check_ready();
		if (is_wp_error($ready)) {
			return $ready; // The queue stays; the next step continues once it is fixed.
		}
		$rows = array_slice($state['queue'], $state['cursor'], self::BATCH);
		foreach ($rows as $row_id) {
			$items = Parich_Slug_Repair_Scanner::load_items($state['scan'], array('id' => $row_id));
			if (!$items) {
				continue;
			}
			$item = current($items);
			$result = self::apply_item($item, (string) $item['row']->chosen, $state['batch']);
			if (is_wp_error($result)) {
				$state['skipped'][] = array('key' => $item['object']['type'] . '-' . $item['object']['id'], 'reason' => $result->get_error_message());
			} else {
				++$state['applied'];
				$state['redirects'] += $result;
			}
		}
		$state['cursor'] += count($rows);
		$state['touched'] = time();
		if ($state['cursor'] >= count($state['queue'])) {
			$state['finished'] = time();
		}
		Parich_Slug_Redirects::recount();
		return self::save_state($state);
	}

	/**
	 * Runs a whole batch (WP-CLI, tests).
	 */
	public static function run($choices)
	{
		$state = self::start($choices);
		while (!is_wp_error($state) && empty($state['finished'])) {
			$state = self::step();
		}
		return $state;
	}

	/**
	 * Another object that keeps this slug where WordPress needs it unique.
	 */
	private static function taken(array $object, $value)
	{
		global $wpdb;
		if ('' === Parich_Slug_Repair_Scanner::unique_key($object, $value)) {
			return false;
		}
		if ('term' === $object['type']) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- uniqueness check before writing.
			return (bool) $wpdb->get_var($wpdb->prepare("SELECT t.term_id FROM {$wpdb->terms} t JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id WHERE t.slug = %s AND tt.taxonomy = %s AND t.term_id <> %d LIMIT 1", $value, $object['subtype'], $object['id']));
		}
		if ('attachment' === $object['subtype']) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- uniqueness check before writing.
			return (bool) $wpdb->get_var($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_name = %s AND ID <> %d AND post_type <> 'revision' AND post_status <> 'auto-draft' LIMIT 1", $value, $object['id']));
		}
		if (!empty($object['hierarchical'])) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- uniqueness check before writing.
			return (bool) $wpdb->get_var($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_name = %s AND post_type IN (%s, 'attachment') AND post_parent = %d AND ID <> %d AND post_status <> 'auto-draft' LIMIT 1", $value, $object['subtype'], $object['parent'], $object['id']));
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- uniqueness check before writing.
		return (bool) $wpdb->get_var($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_name = %s AND post_type = %s AND ID <> %d AND post_status <> 'auto-draft' LIMIT 1", $value, $object['subtype'], $object['id']));
	}

	/**
	 * The stored slug, '' included (get_var() would turn '' into null), or null when the
	 * object is gone.
	 */
	private static function current_slug($type, $id)
	{
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the stored value, not a cached object.
		$rows = 'term' === $type
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- table names and IN lists of placeholders are built in this class; every value goes through prepare().
			? $wpdb->get_col($wpdb->prepare("SELECT slug FROM {$wpdb->terms} WHERE term_id = %d", $id))
			: $wpdb->get_col($wpdb->prepare("SELECT post_name FROM {$wpdb->posts} WHERE ID = %d", $id));
		return $rows ? (string) $rows[0] : null;
	}

	/**
	 * Writes a slug if the stored one is still $expected. A plain UPDATE: wpdb caches
	 * column lengths, and save_post must not run.
	 *
	 * @return bool
	 */
	private static function write_slug($type, $id, $expected, $value)
	{
		global $wpdb;
		if ('term' === $type) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- see above.
			$changed = $wpdb->query($wpdb->prepare("UPDATE {$wpdb->terms} SET slug = %s WHERE term_id = %d AND slug = %s", $value, $id, $expected));
			$term = get_term($id);
			clean_term_cache($id, $term && !is_wp_error($term) ? $term->taxonomy : '');
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- see above.
			$changed = $wpdb->query($wpdb->prepare("UPDATE {$wpdb->posts} SET post_name = %s WHERE ID = %d AND post_name = %s", $value, $id, $expected));
			clean_post_cache($id);
		}
		return 1 === $changed;
	}

	/**
	 * Other addresses that change with an object (descendants, posts whose links contain
	 * the category), as they are now: [type, id, url].
	 */
	private static function indirect_addresses(array $object)
	{
		global $wpdb;
		$list = array();
		$id = (int) $object['id'];
		if ('post' === $object['type']) {
			if (!empty($object['hierarchical'])) {
				$level = array($id);
				$seen = array();
				while ($level && count($seen) < self::MAX_INDIRECT) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- children of the repaired page, level by level.
					$level = array_map('intval', $wpdb->get_col($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_parent IN (" . implode(',', array_fill(0, count($level), '%d')) . ") AND post_type IN (%s, 'attachment') AND post_status IN ('publish', 'inherit')", array_merge($level, array($object['subtype'])))));
					$level = array_values(array_diff($level, array_keys($seen), array($id)));
					foreach ($level as $child) {
						$seen[$child] = true;
						$list[] = array('post', $child, Parich_Slug_Redirects::target_url('post', $child));
					}
				}
			}
		} else {
			$children = get_term_children($id, $object['subtype']);
			$children = is_wp_error($children) ? array() : array_slice(array_map('intval', $children), 0, self::MAX_INDIRECT);
			foreach ($children as $child) {
				$list[] = array('term', $child, Parich_Slug_Redirects::target_url('term', $child));
			}
			if ('category' === $object['subtype'] && false !== strpos((string) get_option('permalink_structure'), '%category%')) {
				$posts = get_posts(array('category' => $id, 'numberposts' => self::MAX_INDIRECT, 'post_status' => 'publish', 'fields' => 'ids'));
				foreach ($posts as $post) {
					$list[] = array('post', (int) $post, Parich_Slug_Redirects::target_url('post', (int) $post));
				}
			}
		}
		return array_values(array_filter($list, function ($entry) {
			return null !== $entry[2];
		}));
	}

	/**
	 * Whether the object's address is its own, so its old address may redirect to it:
	 * - WordPress serves this object there: yes;
	 * - WordPress serves another object there (they share a damaged slug): no;
	 * - WordPress serves nothing there (a 404): yes only if no other object has the same
	 *   slug. A slug cut inside a character is such a 404 that menus and search engines
	 *   picked up; a slug shared with a newer trashed post is a 404 nobody can claim.
	 */
	private static function owns_address(array $object, $url)
	{
		if ('term' === $object['type']) {
			$term = get_term_by('slug', $object['slug'], $object['subtype']);
			return !$term || (int) $term->term_id === (int) $object['id'];
		}
		if ('attachment' === $object['subtype']) {
			return true;
		}
		$served = (int) url_to_postid($url);
		return $served ? (int) $object['id'] === $served : !self::taken($object, $object['slug']);
	}

	/**
	 * @return int|WP_Error Redirects made.
	 */
	public static function apply_item(array $item, $value, $batch)
	{
		global $wpdb;
		$object = $item['object'];
		$type = $object['type'];
		$id = (int) $object['id'];
		$now = self::current_slug($type, $id);
		if (null === $now) {
			return new WP_Error('parich_slug_missing', __('It no longer exists.', 'parich-long-slug'));
		}
		if ($now === $value) {
			return new WP_Error('parich_slug_done', __('Already repaired (by a repair that was interrupted).', 'parich-long-slug'));
		}
		if ($now !== $object['slug']) {
			return new WP_Error('parich_slug_changed', __('Its slug changed after the scan. Scan again.', 'parich-long-slug'));
		}
		if (self::taken($object, $value)) {
			return new WP_Error('parich_slug_taken', __('Another item uses this slug now.', 'parich-long-slug'));
		}
		// The object's own address counts only when WordPress serves this object there.
		$own = Parich_Slug_Redirects::target_url($type, $id);
		$before = self::indirect_addresses($object);
		if (null !== $own && self::owns_address($object, $own)) {
			array_unshift($before, array($type, $id, $own));
		}
		if (!self::write_slug($type, $id, $object['slug'], $value)) {
			return new WP_Error('parich_slug_changed', __('Its slug changed after the scan. Scan again.', 'parich-long-slug'));
		}

		$redirects = 0;
		$moved = array();
		foreach ($before as $entry) {
			$old = Parich_Slug_Repair_Util::path_of($entry[2]);
			$new = Parich_Slug_Repair_Util::path_of((string) Parich_Slug_Redirects::target_url($entry[0], $entry[1]));
			if (null !== $old && $old !== $new && Parich_Slug_Redirects::add($old, $entry[0], $entry[1], 'repair', $batch)) {
				$moved[] = $old;
				++$redirects;
			}
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- plugin table.
		$wpdb->insert(self::log_table(), array(
			'batch_id' => $batch,
			'object_type' => $type,
			'object_id' => $id,
			'old_slug' => $object['slug'],
			'new_slug' => $value,
			'old_paths' => wp_json_encode($moved),
			'status' => 'applied',
			'user_id' => get_current_user_id(),
			'applied_at' => current_time('mysql', true),
		));
		self::after_write($type, $id, $value);
		return $redirects;
	}

	/**
	 * Keeps the shadow copy and Long URL Maker's backup table in line with a written slug.
	 */
	private static function after_write($type, $id, $slug)
	{
		global $wpdb;
		if (strlen($slug) > Parich_Slug_Shadow::CORE_LENGTH) {
			Parich_Slug_Shadow::remember($type, $id, $slug);
		} else {
			Parich_Slug_Shadow::forget($type, $id);
		}
		$legacy = $wpdb->prefix . 'kpcode_url_posts';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- another plugin's table, written only so its restore cannot undo a repair.
		if ($legacy === $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($legacy)))) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- see above.
			$wpdb->insert($legacy, array('kpcode_post_id' => $id, 'kpcode_post_name' => $slug, 'kpcode_type' => 'term' === $type ? 'terms' : 'posts'));
		}
	}

	/**
	 * Puts back the slugs of a batch, where nobody changed them since; turns its
	 * redirects off. Works through up to $limit objects per call.
	 *
	 * @return array {restored, kept: [{key, reason}], remaining}
	 */
	public static function undo($batch, $limit = 200)
	{
		global $wpdb;
		$table = self::log_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- plugin table.
		$rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM %i WHERE batch_id = %d AND status = 'applied' ORDER BY id DESC LIMIT %d", $table, $batch, $limit));
		$result = array('restored' => 0, 'kept' => array(), 'remaining' => 0);
		foreach ($rows as $row) {
			$key = $row->object_type . '-' . $row->object_id;
			if (self::write_slug($row->object_type, (int) $row->object_id, $row->new_slug, $row->old_slug)) {
				self::after_write($row->object_type, (int) $row->object_id, $row->old_slug);
				$status = 'undone';
				++$result['restored'];
			} else {
				$status = 'kept';
				$result['kept'][] = array('key' => $key, 'reason' => __('Its slug was changed after the repair; it was left as it is.', 'parich-long-slug'));
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- plugin table.
			$wpdb->update($table, array('status' => $status, 'undone_at' => current_time('mysql', true)), array('id' => (int) $row->id));
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- plugin table.
		$result['remaining'] = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM %i WHERE batch_id = %d AND status = 'applied'", $table, $batch));
		if (0 === $result['remaining']) {
			$redirects = Parich_Slug_Redirects::table();
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- plugin table.
			$wpdb->update($redirects, array('is_active' => 0), array('batch_id' => (int) $batch, 'origin' => 'repair'));
			Parich_Slug_Redirects::recount();
		}
		return $result;
	}

	public static function batch_exists($batch)
	{
		global $wpdb;
		if (Parich_Slug_Repair_Scanner::VERSION !== get_option(Parich_Slug_Repair_Scanner::VERSION_OPTION)) {
			return false;
		}
		$table = self::log_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- plugin table.
		return (bool) $wpdb->get_var($wpdb->prepare('SELECT id FROM %i WHERE batch_id = %d LIMIT 1', $table, $batch));
	}

	/**
	 * Batches applied so far, newest first.
	 *
	 * @return object[] {batch_id, applied_at, user_id, total, applied, undone, kept}
	 */
	public static function batches($limit = 20)
	{
		global $wpdb;
		if (Parich_Slug_Repair_Scanner::VERSION !== get_option(Parich_Slug_Repair_Scanner::VERSION_OPTION)) {
			return array();
		}
		$table = self::log_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- plugin table.
		return $wpdb->get_results($wpdb->prepare("SELECT batch_id, MIN(applied_at) AS applied_at, MAX(user_id) AS user_id, COUNT(*) AS total, SUM(status = 'applied') AS applied, SUM(status = 'undone') AS undone, SUM(status = 'kept') AS kept FROM %i GROUP BY batch_id ORDER BY batch_id DESC LIMIT %d", $table, $limit));
	}

	/**
	 * Links on the site (scan source S7) that point at addresses now redirected.
	 */
	public static function links_to_redirects($scan_id)
	{
		global $wpdb;
		$urls = Parich_Slug_Repair_Scanner::urls_table();
		$redirects = Parich_Slug_Redirects::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- plugin tables.
		return (int) $wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(u.hits), 0) FROM %i u JOIN %i r ON r.source_hash = MD5(u.url_path) WHERE u.scan_id = %d AND u.source = 'S7' AND r.is_active = 1", $urls, $redirects, $scan_id));
	}
}
