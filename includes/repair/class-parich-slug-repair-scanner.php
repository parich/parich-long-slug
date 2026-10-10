<?php
/**
 * Dry-run scan for slugs damaged by Long URL Maker. Reads posts, terms and the
 * evidence sources; writes only to this plugin's two scan tables:
 *
 *   {prefix}parich_slug_scan_items  one row per object that needs a look
 *   {prefix}parich_slug_scan_urls   URL index (S5–S7, imports) and the claims made on it
 *
 * Runs in small steps so it fits in admin-ajax requests: url sources, posts, terms,
 * then the checks across objects (same slug, URLs claimed twice, values wanted by
 * two objects, values taken by others) and the order parents → children.
 *
 * @package Parich_Long_Slug
 */

if (!defined('ABSPATH')) {
	exit;
}

class Parich_Slug_Repair_Scanner
{
	const VERSION = '4';
	const VERSION_OPTION = 'parich_slug_repair_db_version';
	const STATE_OPTION = 'parich_slug_scan';
	const SEQ_OPTION = 'parich_slug_scan_seq';

	const BATCH = 200;
	const STATUSES = array('publish', 'future', 'private', 'draft', 'pending', 'trash', 'inherit');
	/** Statuses whose slugs WordPress keeps unique. */
	const UNIQUE_STATUSES = array('publish', 'future', 'private');

	const STEPS = array('urls', 'posts', 'terms', 'system', 'd4', 'redecide', 'contest', 'collisions', 'structure', 'done');

	/** Largest import file, and most lines read from it. */
	const IMPORT_MAX_BYTES = 2097152;
	const IMPORT_MAX_LINES = 20000;

	public static function items_table()
	{
		global $wpdb;
		return $wpdb->prefix . 'parich_slug_scan_items';
	}

	public static function urls_table()
	{
		global $wpdb;
		return $wpdb->prefix . 'parich_slug_scan_urls';
	}

	public static function maybe_install()
	{
		return self::VERSION === get_option(self::VERSION_OPTION) ? true : self::install();
	}

	/**
	 * @return true|WP_Error
	 */
	public static function install()
	{
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$items = self::items_table();
		$urls = self::urls_table();
		dbDelta("CREATE TABLE {$items} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  scan_id bigint(20) unsigned NOT NULL,
  object_type varchar(10) NOT NULL,
  object_id bigint(20) unsigned NOT NULL,
  subtype varchar(32) NOT NULL DEFAULT '',
  status varchar(20) NOT NULL DEFAULT '',
  parent_id bigint(20) unsigned NOT NULL DEFAULT 0,
  depth int(10) unsigned NOT NULL DEFAULT 0,
  indirect int(10) unsigned NOT NULL DEFAULT 0,
  title text NOT NULL,
  current_slug text NOT NULL,
  detectors varchar(40) NOT NULL DEFAULT '',
  repair_group varchar(12) NOT NULL DEFAULT '',
  proposal text NULL,
  proposal_key char(32) NOT NULL DEFAULT '',
  flags varchar(255) NOT NULL DEFAULT '',
  chosen text NULL,
  data longtext NOT NULL,
  PRIMARY KEY  (id),
  KEY scan_group (scan_id,repair_group),
  KEY scan_object (scan_id,object_type,object_id),
  KEY scan_proposal (scan_id,proposal_key)
) {$charset};");
		dbDelta("CREATE TABLE {$urls} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  scan_id bigint(20) unsigned NOT NULL,
  source varchar(10) NOT NULL,
  detail varchar(64) NOT NULL DEFAULT '',
  url_path text NOT NULL,
  path_hash char(32) NOT NULL,
  last_segment varchar(2000) NOT NULL DEFAULT '',
  segment_hash char(32) NOT NULL DEFAULT '',
  object_key varchar(40) NOT NULL DEFAULT '',
  hits int(10) unsigned NOT NULL DEFAULT 1,
  truncated tinyint(1) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  UNIQUE KEY scan_hash (scan_id,path_hash),
  KEY scan_segment (scan_id,last_segment(191)),
  KEY scan_segment_hash (scan_id,segment_hash),
  KEY object_key (object_key)
) {$charset};");
		$log = Parich_Slug_Repair_Applier::log_table();
		$redirects = Parich_Slug_Redirects::table();
		dbDelta("CREATE TABLE {$log} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  batch_id bigint(20) unsigned NOT NULL,
  object_type varchar(10) NOT NULL,
  object_id bigint(20) unsigned NOT NULL,
  old_slug text NOT NULL,
  new_slug text NOT NULL,
  old_paths longtext NOT NULL,
  status varchar(10) NOT NULL DEFAULT 'applied',
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  applied_at datetime NOT NULL,
  undone_at datetime NULL,
  PRIMARY KEY  (id),
  KEY batch (batch_id,status),
  KEY object (object_type,object_id)
) {$charset};");
		dbDelta("CREATE TABLE {$redirects} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  source_hash char(32) NOT NULL,
  source_path text NOT NULL,
  object_type varchar(10) NOT NULL,
  object_id bigint(20) unsigned NOT NULL,
  origin varchar(20) NOT NULL,
  batch_id bigint(20) unsigned NOT NULL DEFAULT 0,
  is_active tinyint(1) unsigned NOT NULL DEFAULT 1,
  hits bigint(20) unsigned NOT NULL DEFAULT 0,
  last_hit datetime NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY source_hash (source_hash),
  KEY object (object_type,object_id),
  KEY batch (batch_id)
) {$charset};");
		foreach (array($items, $urls, $log, $redirects) as $table) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- checking the table just created.
			if ($table !== $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table)))) {
				return new WP_Error('parich_slug_repair_tables', sprintf('The table %s could not be created: %s', $table, $wpdb->last_error));
			}
		}
		update_option(self::VERSION_OPTION, self::VERSION, false);
		return true;
	}

	public static function state()
	{
		$state = get_option(self::STATE_OPTION);
		return is_array($state) && isset($state['id']) ? $state : null;
	}

	private static function save_state(array $state)
	{
		update_option(self::STATE_OPTION, $state, false);
		return $state;
	}

	/**
	 * Post types and taxonomies that get a URL: public ones, attachments included.
	 */
	public static function post_types()
	{
		/**
		 * Filters the post types the repair scan checks.
		 *
		 * @param string[] $types Public post types.
		 */
		return array_values((array) apply_filters('parich_slug_repair_post_types', array_values(get_post_types(array('public' => true)))));
	}

	public static function taxonomies()
	{
		/**
		 * Filters the taxonomies the repair scan checks.
		 *
		 * @param string[] $taxonomies Public taxonomies.
		 */
		return array_values((array) apply_filters('parich_slug_repair_taxonomies', array_values(get_taxonomies(array('public' => true)))));
	}

	/**
	 * Starts a new scan; the previous one's results are removed (imports are kept).
	 *
	 * @param string|null $prefix Table prefix to read from; default: this site's.
	 * @return array|WP_Error State.
	 */
	public static function start($prefix = null)
	{
		global $wpdb;
		$installed = self::maybe_install();
		if (is_wp_error($installed)) {
			return $installed;
		}
		try {
			$source = new Parich_Slug_Repair_Source($prefix);
		} catch (InvalidArgumentException $e) {
			return new WP_Error('parich_slug_prefix', $e->getMessage());
		}
		if (!$source->has_table('posts') || !$source->has_table('terms') || !$source->has_table('term_taxonomy') || !$source->has_table('postmeta')) {
			return new WP_Error('parich_slug_prefix', sprintf('No WordPress tables with the prefix %s.', $source->prefix));
		}
		$id = (int) get_option(self::SEQ_OPTION, 0) + 1;
		update_option(self::SEQ_OPTION, $id, false);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- plugin tables.
		$wpdb->query($wpdb->prepare('DELETE FROM %i', self::items_table()));
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- plugin tables; imports (scan 0) stay.
		$wpdb->query($wpdb->prepare('DELETE FROM %i WHERE scan_id <> 0', self::urls_table()));
		return self::save_state(array(
			'id' => $id,
			'prefix' => null === $prefix ? null : $source->prefix,
			'step' => 'urls',
			'task' => 0,
			'cursor' => 0,
			'started' => time(),
			'finished' => null,
			'objects' => 0,
			'counts' => array(),
			'warnings' => array(),
		));
	}

	/**
	 * Runs one unit of work (one batch) of the current scan.
	 *
	 * @return array|WP_Error State after the step.
	 */
	public static function step()
	{
		$state = self::state();
		if (!$state) {
			return new WP_Error('parich_slug_no_scan', 'No scan has been started.');
		}
		if ('done' === $state['step']) {
			return $state;
		}
		$source = new Parich_Slug_Repair_Source($state['prefix']);
		$evidence = new Parich_Slug_Repair_Evidence($source);
		$method = 'step_' . $state['step'];
		$state = self::$method($state, $source, $evidence);
		foreach ($evidence->warnings as $key => $warning) {
			$state['warnings'][$key] = $warning;
		}
		return self::save_state($state);
	}

	/**
	 * Runs a whole scan (WP-CLI, tests).
	 *
	 * @param callable|null $progress Called with the state after every step.
	 * @return array|WP_Error Final state.
	 */
	public static function run($prefix = null, $progress = null)
	{
		$state = self::start($prefix);
		while (!is_wp_error($state) && 'done' !== $state['step']) {
			$state = self::step();
			if ($progress && !is_wp_error($state)) {
				call_user_func($progress, $state);
			}
		}
		return $state;
	}

	private static function advance(array $state)
	{
		$next = array_search($state['step'], self::STEPS, true) + 1;
		$state['step'] = self::STEPS[$next];
		$state['task'] = 0;
		$state['cursor'] = 0;
		if ('done' === $state['step']) {
			$state = self::finish($state);
		}
		return $state;
	}

	private static function step_urls(array $state, $source, Parich_Slug_Repair_Evidence $evidence)
	{
		$tasks = $evidence->url_tasks();
		if (!isset($tasks[$state['task']])) {
			return self::advance($state);
		}
		$next = $evidence->collect($tasks[$state['task']], $state['id'], $state['cursor']);
		if (null === $next) {
			++$state['task'];
			$state['cursor'] = 0;
		} else {
			$state['cursor'] = $next;
		}
		return $state;
	}

	/**
	 * Looks up URLs whose slug starts with a prefix, leaving out URLs that are some
	 * object's current slug (those belong to it).
	 */
	private static function url_lookup($scan_id, Parich_Slug_Repair_Evidence $evidence)
	{
		return function ($prefix) use ($scan_id, $evidence) {
			$rows = Parich_Slug_Repair_Evidence::urls_with_prefix($scan_id, $prefix);
			if (!$rows) {
				return array();
			}
			$owners = $evidence->owners(wp_list_pluck($rows, 'last_segment'));
			return array_values(array_filter($rows, function ($row) use ($owners) {
				return !isset($owners[$row->last_segment]);
			}));
		};
	}

	private static function step_posts(array $state, Parich_Slug_Repair_Source $source, Parich_Slug_Repair_Evidence $evidence)
	{
		global $wpdb;
		$types = self::post_types();
		$args = array_merge(array($source->table('posts'), (int) $state['cursor']), $types, self::STATUSES, array(self::BATCH));
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- read-only batch.
		$rows = $wpdb->get_results($wpdb->prepare('SELECT ID, post_type, post_status, post_parent, post_title, post_name, guid FROM %i WHERE ID > %d AND post_type IN (' . implode(',', array_fill(0, count($types), '%s')) . ') AND post_status IN (' . implode(',', array_fill(0, count(self::STATUSES), '%s')) . ') ORDER BY ID LIMIT %d', $args));
		$objects = array();
		foreach ($rows as $row) {
			$objects[(int) $row->ID] = self::post_object($row);
		}
		self::analyze_batch('post', $objects, $state, $evidence);
		if (count($rows) < self::BATCH) {
			return self::advance($state);
		}
		$state['cursor'] = (int) $rows[count($rows) - 1]->ID;
		return $state;
	}

	private static function post_object($row)
	{
		return array(
			'type' => 'post',
			'id' => (int) $row->ID,
			'subtype' => $row->post_type,
			'status' => $row->post_status,
			'parent' => (int) $row->post_parent,
			'title' => (string) $row->post_title,
			'slug' => (string) $row->post_name,
			'hierarchical' => is_post_type_hierarchical($row->post_type),
			'guid' => (string) $row->guid,
		);
	}

	private static function step_terms(array $state, Parich_Slug_Repair_Source $source, Parich_Slug_Repair_Evidence $evidence)
	{
		global $wpdb;
		$taxonomies = self::taxonomies();
		$args = array_merge(array($source->table('terms'), $source->table('term_taxonomy'), (int) $state['cursor']), $taxonomies, array(self::BATCH));
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- read-only batch.
		$rows = $wpdb->get_results($wpdb->prepare('SELECT t.term_id, t.name, t.slug, tt.taxonomy, tt.parent FROM %i t JOIN %i tt ON tt.term_id = t.term_id WHERE t.term_id > %d AND tt.taxonomy IN (' . implode(',', array_fill(0, count($taxonomies), '%s')) . ') ORDER BY t.term_id LIMIT %d', $args));
		$objects = array();
		foreach ($rows as $row) {
			if (!isset($objects[(int) $row->term_id])) {
				$objects[(int) $row->term_id] = self::term_object($row);
			}
		}
		self::analyze_batch('term', $objects, $state, $evidence);
		if (count($rows) < self::BATCH) {
			return self::advance($state);
		}
		$state['cursor'] = (int) $rows[count($rows) - 1]->term_id;
		return $state;
	}

	/**
	 * D6: posts whose post_name is a key (ACF, templates, global styles…), in batches.
	 */
	private static function step_system(array $state, Parich_Slug_Repair_Source $source, Parich_Slug_Repair_Evidence $evidence)
	{
		global $wpdb;
		$types = Parich_Slug_Repair_System_Keys::post_types();
		if (!$types) {
			return self::advance($state);
		}
		$args = array_merge(array($source->table('posts'), (int) $state['cursor']), $types, Parich_Slug_Repair_Detectors::M1_STATUSES, array(self::BATCH));
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- read-only batch.
		$rows = $wpdb->get_results($wpdb->prepare('SELECT ID, post_type, post_status, post_parent, post_title, post_name, post_excerpt, guid FROM %i WHERE ID > %d AND post_type IN (' . implode(',', array_fill(0, count($types), '%s')) . ') AND post_status IN (' . implode(',', array_fill(0, count(Parich_Slug_Repair_Detectors::M1_STATUSES), '%s')) . ') ORDER BY ID LIMIT %d', $args));
		$objects = array();
		foreach ($rows as $row) {
			$object = self::post_object($row);
			// Keys are unique per type, not per parent.
			$object['hierarchical'] = false;
			$object['excerpt'] = (string) $row->post_excerpt;
			$objects[(int) $row->ID] = $object;
		}
		$state['objects'] += count($objects);
		if ($objects) {
			$facts = $evidence->load('post', $objects, $state['id']);
			$context = Parich_Slug_Repair_System_Keys::context($source, $objects);
			foreach ($objects as $id => $object) {
				$item = Parich_Slug_Repair_System_Keys::analyze($object, $facts[$id], $context);
				if ($item && 'ok' !== $item['group']) {
					self::save_item($state['id'], $item);
				}
			}
		}
		if (count($rows) < self::BATCH) {
			return self::advance($state);
		}
		$state['cursor'] = (int) $rows[count($rows) - 1]->ID;
		return $state;
	}

	private static function term_object($row)
	{
		return array(
			'type' => 'term',
			'id' => (int) $row->term_id,
			'subtype' => $row->taxonomy,
			'status' => '',
			'parent' => (int) $row->parent,
			'title' => (string) $row->name,
			'slug' => (string) $row->slug,
			'hierarchical' => is_taxonomy_hierarchical($row->taxonomy),
		);
	}

	private static function analyze_batch($type, array $objects, array &$state, Parich_Slug_Repair_Evidence $evidence)
	{
		$state['objects'] += count($objects);
		if (!$objects) {
			return;
		}
		$facts = $evidence->load($type, $objects, $state['id']);
		$lookup = self::url_lookup($state['id'], $evidence);
		foreach ($objects as $id => $object) {
			$signs = Parich_Slug_Repair_Detectors::detect($object, $facts[$id]);
			if (!$signs['codes']) {
				continue;
			}
			$item = Parich_Slug_Repair_Scorer::analyze($object, $facts[$id], $signs, $lookup);
			if ('ok' !== $item['group']) {
				self::save_item($state['id'], $item);
			}
		}
	}

	/**
	 * Key under which WordPress keeps a slug unique ('' when it does not).
	 */
	public static function unique_key(array $object, $value)
	{
		if (null === $value || '' === $value) {
			return '';
		}
		if ('term' === $object['type']) {
			return md5('term|' . $object['subtype'] . '|' . $value);
		}
		if (!in_array($object['status'], self::UNIQUE_STATUSES, true) && 'inherit' !== $object['status']) {
			return '';
		}
		if ('attachment' === $object['subtype']) {
			return md5('attachment|' . $value);
		}
		return md5('post|' . $object['subtype'] . '|' . (empty($object['hierarchical']) ? 0 : (int) $object['parent']) . '|' . $value);
	}

	private static function columns(array $item)
	{
		$object = $item['object'];
		return array(
			'object_type' => $object['type'],
			'object_id' => $object['id'],
			'subtype' => $object['subtype'],
			'status' => $object['status'],
			'parent_id' => $object['parent'],
			'title' => $object['title'],
			'current_slug' => $object['slug'],
			'detectors' => implode(',', $item['codes']),
			'repair_group' => $item['group'],
			'proposal' => $item['proposal'],
			'proposal_key' => self::unique_key($object, $item['proposal']),
			'flags' => substr(implode(',', $item['flags']), 0, 255),
			'data' => wp_json_encode($item),
		);
	}

	public static function save_item($scan_id, array $item)
	{
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- plugin table.
		$wpdb->insert(self::items_table(), array('scan_id' => $scan_id) + self::columns($item));
		$key = $item['object']['type'] . ':' . $item['object']['id'];
		foreach (Parich_Slug_Repair_Scorer::url_values($item) as $value) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- plugin table.
			$wpdb->query($wpdb->prepare(
				"INSERT IGNORE INTO %i (scan_id, source, detail, url_path, path_hash, last_segment, segment_hash, object_key, hits, truncated) VALUES (%d, 'CLAIM', '', '', %s, %s, %s, %s, 1, 0)",
				self::urls_table(),
				$scan_id,
				md5('CLAIM|' . $key . '|' . $value),
				$value,
				md5($value),
				$key
			));
		}
	}

	private static function update_item($row_id, array $item)
	{
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- plugin table.
		$wpdb->update(self::items_table(), self::columns($item), array('id' => $row_id));
	}

	/**
	 * Stored items, decoded. One fixed query per kind of filter:
	 * - {id}, {object: [type, id]} or {proposal_key}: those items, in id order;
	 * - {after, limit, has_proposal}: the next batch in id order (with has_proposal, only
	 *   items that propose a slug);
	 * - otherwise a listing {group: string|string[], code, limit, offset} in results order
	 *   (ready first, then parents before children); no group or code lists every item.
	 *
	 * @return array row id => item (with 'row' => the table row)
	 */
	public static function load_items($scan_id, array $filter = array())
	{
		global $wpdb;
		$table = self::items_table();
		$scan_id = (int) $scan_id;
		if (isset($filter['id'])) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- plugin table.
			$rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM %i WHERE scan_id = %d AND id = %d', $table, $scan_id, (int) $filter['id']));
		} elseif (isset($filter['object'])) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- plugin table.
			$rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM %i WHERE scan_id = %d AND object_type = %s AND object_id = %d ORDER BY id', $table, $scan_id, (string) $filter['object'][0], (int) $filter['object'][1]));
		} elseif (isset($filter['proposal_key'])) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- plugin table.
			$rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM %i WHERE scan_id = %d AND proposal_key = %s ORDER BY id', $table, $scan_id, (string) $filter['proposal_key']));
		} elseif (isset($filter['after'])) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- plugin table.
			$rows = $wpdb->get_results($wpdb->prepare(
				"SELECT * FROM %i WHERE scan_id = %d AND id > %d AND (%d = 0 OR (proposal IS NOT NULL AND proposal <> '')) ORDER BY id LIMIT %d",
				$table,
				$scan_id,
				(int) $filter['after'],
				empty($filter['has_proposal']) ? 0 : 1,
				isset($filter['limit']) ? (int) $filter['limit'] : self::BATCH
			));
		} else {
			// '' matches every group or detector.
			$groups = isset($filter['group']) ? implode(',', (array) $filter['group']) : '';
			$code = isset($filter['code']) ? (string) $filter['code'] : '';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- plugin table.
			$rows = $wpdb->get_results($wpdb->prepare(
				"SELECT * FROM %i WHERE scan_id = %d AND (%s = '' OR FIND_IN_SET(repair_group, %s) > 0) AND (%s = '' OR FIND_IN_SET(%s, detectors) > 0) ORDER BY FIELD(repair_group, 'auto', 'review', 'unresolved'), depth, object_type, object_id LIMIT %d OFFSET %d",
				$table,
				$scan_id,
				$groups,
				$groups,
				$code,
				$code,
				!empty($filter['limit']) ? (int) $filter['limit'] : PHP_INT_MAX,
				isset($filter['offset']) ? max(0, (int) $filter['offset']) : 0
			));
		}
		$items = array();
		foreach ($rows as $row) {
			$item = json_decode($row->data, true);
			if (is_array($item)) {
				$item['row'] = $row;
				$items[(int) $row->id] = $item;
			}
		}
		return $items;
	}

	private static function find_item($scan_id, $type, $id)
	{
		$items = self::load_items($scan_id, array('object' => array($type, $id)));
		return $items ? array(key($items), current($items)) : array(null, null);
	}

	/**
	 * D4: objects sharing a slug where WordPress needs it unique. Those moving to a
	 * proposed slug are fine; when two or more stay, each of them needs a look.
	 */
	private static function step_d4(array $state, Parich_Slug_Repair_Source $source, Parich_Slug_Repair_Evidence $evidence)
	{
		global $wpdb;
		$types = array_values(array_diff(self::post_types(), array('attachment')));
		$hierarchical = array_values(array_filter($types, 'is_post_type_hierarchical'));
		$hierarchical = $hierarchical ? $hierarchical : array('-');
		$offset = (int) $state['cursor'];
		$clusters = array();
		// Task 0: post groups, task 1: term groups; the cursor counts groups done. Grouped
		// by hash: MySQL can fail to group long values of a prefix-indexed column.
		if (0 === (int) $state['task'] && $types) {
			$args = array_merge($hierarchical, array($source->table('posts')), $types, self::UNIQUE_STATUSES, array(self::BATCH, $offset));
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- read-only.
			$groups = $wpdb->get_results($wpdb->prepare('SELECT post_type, IF(post_type IN (' . implode(',', array_fill(0, count($hierarchical), '%s')) . '), post_parent, 0) AS parent_key, MIN(post_name) AS post_name, MD5(post_name) AS name_hash, COUNT(*) AS c FROM %i WHERE post_type IN (' . implode(',', array_fill(0, count($types), '%s')) . ') AND post_status IN (' . implode(',', array_fill(0, count(self::UNIQUE_STATUSES), '%s')) . ") AND post_name <> '' GROUP BY post_type, parent_key, name_hash HAVING c > 1 ORDER BY post_type, parent_key, name_hash LIMIT %d OFFSET %d", $args));
			foreach ($groups as $group) {
				$hier = in_array($group->post_type, $hierarchical, true);
				$member_args = array_merge(array($source->table('posts'), $group->post_type, $group->post_name), self::UNIQUE_STATUSES);
				if ($hier) {
					// Unique per parent: only the members under the same parent.
					$member_args[] = (int) $group->parent_key;
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- read-only.
					$members = $wpdb->get_results($wpdb->prepare('SELECT ID, post_type, post_status, post_parent, post_title, post_name, guid FROM %i WHERE post_type = %s AND post_name = %s AND post_status IN (' . implode(',', array_fill(0, count(self::UNIQUE_STATUSES), '%s')) . ') AND post_parent = %d ORDER BY ID', $member_args));
				} else {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- read-only.
					$members = $wpdb->get_results($wpdb->prepare('SELECT ID, post_type, post_status, post_parent, post_title, post_name, guid FROM %i WHERE post_type = %s AND post_name = %s AND post_status IN (' . implode(',', array_fill(0, count(self::UNIQUE_STATUSES), '%s')) . ') ORDER BY ID', $member_args));
				}
				$clusters[] = array('post', $members);
			}
		} elseif (1 === (int) $state['task'] && self::taxonomies()) {
			$taxonomies = self::taxonomies();
			$args = array_merge(array($source->table('terms'), $source->table('term_taxonomy')), $taxonomies, array(self::BATCH, $offset));
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- read-only.
			$groups = $wpdb->get_results($wpdb->prepare('SELECT tt.taxonomy, MIN(t.slug) AS slug, MD5(t.slug) AS slug_hash, COUNT(*) AS c FROM %i t JOIN %i tt ON tt.term_id = t.term_id WHERE tt.taxonomy IN (' . implode(',', array_fill(0, count($taxonomies), '%s')) . ') GROUP BY tt.taxonomy, slug_hash HAVING c > 1 ORDER BY tt.taxonomy, slug_hash LIMIT %d OFFSET %d', $args));
			foreach ($groups as $group) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- read-only.
				$clusters[] = array('term', $wpdb->get_results($wpdb->prepare('SELECT t.term_id, t.name, t.slug, tt.taxonomy, tt.parent FROM %i t JOIN %i tt ON tt.term_id = t.term_id WHERE tt.taxonomy = %s AND t.slug = %s ORDER BY t.term_id', $source->table('terms'), $source->table('term_taxonomy'), $group->taxonomy, $group->slug)));
			}
		}

		// Stop after about 1,000 objects so one request stays short when many share a slug.
		$done = 0;
		$members = 0;
		foreach ($clusters as $cluster) {
			self::resolve_cluster($state, $cluster[0], $cluster[1], $evidence);
			++$done;
			$members += count($cluster[1]);
			if ($members >= 5 * self::BATCH) {
				break;
			}
		}

		if ($done < count($clusters) || count($clusters) === self::BATCH) {
			$state['cursor'] = $offset + $done;
		} elseif (0 === (int) $state['task']) {
			$state['task'] = 1;
			$state['cursor'] = 0;
		} else {
			return self::advance($state);
		}
		return $state;
	}

	private static function resolve_cluster(array &$state, $type, array $rows, Parich_Slug_Repair_Evidence $evidence)
	{
		$members = array();
		foreach ($rows as $row) {
			$object = 'post' === $type ? self::post_object($row) : self::term_object($row);
			list($row_id, $item) = self::find_item($state['id'], $type, $object['id']);
			$moving = $item && null !== $item['proposal'] && $item['proposal'] !== $object['slug'];
			$members[] = array('object' => $object, 'row_id' => $row_id, 'item' => $item, 'moving' => $moving);
		}
		$staying = array_filter($members, function ($member) {
			return !$member['moving'];
		});
		foreach ($members as $member) {
			$item = $member['item'];
			if (!$member['moving'] && count($staying) < 2) {
				continue; // The only one left with this slug: nothing to do.
			}
			if (!$item) {
				$facts = $evidence->load($type, array($member['object']['id'] => $member['object']), $state['id']);
				$facts = $facts[$member['object']['id']];
				$signs = array('codes' => array('D4'), 'd2_by' => null, 'd5' => null, 'notes' => array());
				$item = Parich_Slug_Repair_Scorer::analyze($member['object'], $facts, $signs);
				$item['flags'][] = 'd4_staying';
				self::save_item($state['id'], Parich_Slug_Repair_Scorer::decide($item));
				continue;
			}
			if (!in_array('D4', $item['codes'], true)) {
				$item['codes'][] = 'D4';
			}
			if (!$member['moving']) {
				$item['flags'][] = 'd4_staying';
			}
			unset($item['row']);
			self::update_item($member['row_id'], Parich_Slug_Repair_Scorer::decide($item));
		}
	}

	/**
	 * Decides every item again, with URL evidence that several objects claim set aside.
	 */
	private static function step_redecide(array $state)
	{
		global $wpdb;
		$urls = self::urls_table();
		// Grouped by hash: MySQL can fail to group long values of a prefix-indexed column.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- plugin table.
		$ambiguous = array_flip($wpdb->get_col($wpdb->prepare("SELECT segment_hash FROM %i WHERE scan_id = %d AND source = 'CLAIM' GROUP BY segment_hash HAVING COUNT(DISTINCT object_key) > 1", $urls, $state['id'])));
		$items = self::load_items($state['id'], array('after' => $state['cursor'], 'limit' => self::BATCH));
		foreach ($items as $row_id => $item) {
			unset($item['row']);
			$claimed = array();
			foreach (Parich_Slug_Repair_Scorer::url_values($item) as $value) {
				if (isset($ambiguous[md5($value)])) {
					$claimed[$value] = true;
				}
			}
			if ($claimed) {
				$item = Parich_Slug_Repair_Scorer::mark_ambiguous($item, $claimed);
				$item['notes'][] = 'url_claimed_twice';
				$item['notes'] = array_values(array_unique($item['notes']));
			}
			self::update_item($row_id, Parich_Slug_Repair_Scorer::decide($item));
		}
		if (count($items) < self::BATCH) {
			return self::advance($state);
		}
		$state['cursor'] = max(array_keys($items));
		return $state;
	}

	/**
	 * Two objects proposing the same slug: the one with no other choice keeps it, the
	 * others take their next candidate. All of them need a look.
	 */
	private static function step_contest(array $state)
	{
		global $wpdb;
		$table = self::items_table();
		// Values wanted twice where someone has not been settled yet; settling flags every
		// one of them, so each step makes progress and ties are not taken up again.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- plugin table.
		$keys = $wpdb->get_col($wpdb->prepare("SELECT proposal_key FROM %i WHERE scan_id = %d AND proposal_key <> '' GROUP BY proposal_key HAVING COUNT(*) > 1 AND SUM(FIND_IN_SET('contested', flags) = 0) > 0 LIMIT %d", $table, $state['id'], self::BATCH / 2));
		if (!$keys) {
			return self::advance($state);
		}
		foreach ($keys as $key) {
			$items = self::load_items($state['id'], array('proposal_key' => $key));
			$value = null;
			$alternatives = array();
			$keepers = array();
			foreach ($items as $row_id => $item) {
				$value = $item['proposal'];
				$alternatives[$row_id] = null;
				// Only a value with evidence of this object's own counts as another choice;
				// a URL that merely starts like its slug may well be the other object's.
				$own = array();
				foreach ($item['candidates'] as $candidate) {
					foreach ($candidate['entries'] as $entry) {
						if (!$entry['url']) {
							$own[$candidate['value']] = true;
						}
					}
				}
				foreach (Parich_Slug_Repair_Scorer::ranked($item) as $candidate) {
					if ($candidate !== $value && isset($own[$candidate])) {
						$alternatives[$row_id] = $candidate;
						break;
					}
				}
				if (null === $alternatives[$row_id]) {
					$keepers[] = $row_id;
				}
			}
			if (!$keepers) {
				// Everyone has another choice: the one with clearly the best evidence for it keeps it.
				$scores = array();
				foreach ($items as $row_id => $item) {
					$weight = Parich_Slug_Repair_Scorer::weigh($item)[$value];
					$scores[$row_id] = array((int) $weight['strong'], $weight['groups'], $weight['best'], $weight['supports']);
				}
				arsort($scores);
				$best = reset($scores);
				if (1 === count(array_keys($scores, $best, true))) {
					$keepers[] = key($scores);
				}
			}
			foreach ($items as $row_id => $item) {
				unset($item['row']);
				$item['flags'][] = 'contested';
				$item['flags'] = array_values(array_unique($item['flags']));
				// No clear keeper: all keep the value and the admin chooses.
				$keeps = $keepers ? in_array($row_id, $keepers, true) : true;
				$item['pinned'] = $keeps || null === $alternatives[$row_id] ? $value : $alternatives[$row_id];
				self::update_item($row_id, Parich_Slug_Repair_Scorer::decide($item));
			}
		}
		return $state;
	}

	/**
	 * A proposal another object already uses (and keeps) cannot be applied as it is.
	 */
	private static function step_collisions(array $state, Parich_Slug_Repair_Source $source)
	{
		global $wpdb;
		$items = self::load_items($state['id'], array('after' => $state['cursor'], 'has_proposal' => true, 'limit' => self::BATCH));
		foreach ($items as $row_id => $item) {
			$object = $item['object'];
			$value = $item['proposal'];
			if ('' === self::unique_key($object, $value)) {
				continue;
			}
			$posts = $source->table('posts');
			if ('term' === $object['type']) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- read-only.
				$others = $wpdb->get_col($wpdb->prepare('SELECT t.term_id FROM %i t JOIN %i tt ON tt.term_id = t.term_id WHERE t.slug = %s AND tt.taxonomy = %s AND t.term_id <> %d LIMIT 5', $source->table('terms'), $source->table('term_taxonomy'), $value, $object['subtype'], $object['id']));
			} elseif ('attachment' === $object['subtype']) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- read-only.
				$others = $wpdb->get_col($wpdb->prepare("SELECT ID FROM %i WHERE post_name = %s AND ID <> %d AND post_type <> 'revision' AND post_status <> 'auto-draft' LIMIT 5", $posts, $value, $object['id']));
			} elseif (!empty($object['hierarchical'])) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- read-only.
				$others = $wpdb->get_col($wpdb->prepare("SELECT ID FROM %i WHERE post_name = %s AND post_type IN (%s, 'attachment') AND post_parent = %d AND ID <> %d AND post_status <> 'auto-draft' LIMIT 5", $posts, $value, $object['subtype'], $object['parent'], $object['id']));
			} else {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- read-only.
				$others = $wpdb->get_col($wpdb->prepare("SELECT ID FROM %i WHERE post_name = %s AND post_type = %s AND ID <> %d AND post_status <> 'auto-draft' LIMIT 5", $posts, $value, $object['subtype'], $object['id']));
			}
			$blocking = array();
			foreach ($others as $other) {
				$type = 'term' === $object['type'] ? 'term' : 'post';
				list(, $other_item) = self::find_item($state['id'], $type, (int) $other);
				// Fine when the other one moves to a slug of its own in the same repair.
				if (!$other_item || 'auto' !== $other_item['group'] || $other_item['proposal'] === $value) {
					$blocking[] = $type . ':' . $other;
				}
			}
			if ($blocking) {
				unset($item['row']);
				$item['flags'][] = 'collision';
				$item['flags'] = array_values(array_unique($item['flags']));
				$item['collides_with'] = $blocking;
				self::update_item($row_id, Parich_Slug_Repair_Scorer::decide($item));
			}
		}
		if (count($items) < self::BATCH) {
			return self::advance($state);
		}
		$state['cursor'] = max(array_keys($items));
		return $state;
	}

	/**
	 * Depth (parents first when applying) and how many other URLs change with the
	 * object: descendants, and posts whose links contain a category.
	 */
	private static function step_structure(array $state, Parich_Slug_Repair_Source $source)
	{
		global $wpdb;
		$items = self::load_items($state['id'], array('after' => $state['cursor'], 'limit' => self::BATCH));
		$category_links = false !== strpos((string) $source->option('permalink_structure'), '%category%');
		foreach ($items as $row_id => $item) {
			$object = $item['object'];
			if (empty($object['hierarchical'])) {
				if ('term' !== $object['type'] || 'category' !== $object['subtype'] || !$category_links) {
					continue;
				}
			}
			$depth = 0;
			$parent = (int) $object['parent'];
			$seen = array();
			while ($parent && $depth < 50 && !isset($seen[$parent])) {
				$seen[$parent] = true;
				++$depth;
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- read-only.
				$parent = (int) ('term' === $object['type']
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- read-only.
					? $wpdb->get_var($wpdb->prepare('SELECT parent FROM %i WHERE term_id = %d AND taxonomy = %s', $source->table('term_taxonomy'), $parent, $object['subtype']))
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- read-only.
					: $wpdb->get_var($wpdb->prepare('SELECT post_parent FROM %i WHERE ID = %d', $source->table('posts'), $parent)));
			}
			$descendants = array();
			$level = array((int) $object['id']);
			for ($i = 0; $i < 20 && $level && count($descendants) < 10000; $i++) {
				if ('term' === $object['type']) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- read-only.
					$level = array_map('intval', $wpdb->get_col($wpdb->prepare("SELECT term_id FROM %i WHERE taxonomy = %s AND parent IN (" . implode(',', array_fill(0, count($level), '%d')) . ")", array_merge(array($source->table('term_taxonomy'), $object['subtype']), $level))));
				} else {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- read-only.
					$level = array_map('intval', $wpdb->get_col($wpdb->prepare("SELECT ID FROM %i WHERE post_type IN (%s, 'attachment') AND post_status <> 'auto-draft' AND post_parent IN (" . implode(',', array_fill(0, count($level), '%d')) . ")", array_merge(array($source->table('posts'), $object['subtype']), $level))));
				}
				$level = array_values(array_diff($level, $descendants, array((int) $object['id'])));
				$descendants = array_merge($descendants, $level);
			}
			$indirect = count($descendants);
			if ('term' === $object['type'] && 'category' === $object['subtype'] && $category_links) {
				$terms = array_merge(array((int) $object['id']), $descendants);
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- read-only.
				$indirect += (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT tr.object_id) FROM %i tr JOIN %i tt ON tt.term_taxonomy_id = tr.term_taxonomy_id WHERE tt.taxonomy = 'category' AND tt.term_id IN (" . implode(',', array_fill(0, count($terms), '%d')) . ")", array_merge(array($source->table('term_relationships'), $source->table('term_taxonomy')), $terms)));
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- plugin table.
			$wpdb->update(self::items_table(), array('depth' => $depth, 'indirect' => $indirect), array('id' => $row_id));
		}
		if (count($items) < self::BATCH) {
			return self::advance($state);
		}
		$state['cursor'] = max(array_keys($items));
		return $state;
	}

	private static function finish(array $state)
	{
		global $wpdb;
		$table = self::items_table();
		$counts = array('auto' => 0, 'review' => 0, 'unresolved' => 0);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- plugin table.
		foreach ($wpdb->get_results($wpdb->prepare('SELECT repair_group, COUNT(*) AS c FROM %i WHERE scan_id = %d GROUP BY repair_group', $table, $state['id'])) as $row) {
			$counts[$row->repair_group] = (int) $row->c;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- plugin table.
		$listed = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE scan_id = %d', $table, $state['id']));
		$counts['ok'] = max(0, $state['objects'] - $listed);
		foreach (array('D1', 'D2', 'D3', 'D4', 'D5', 'D6', 'D7') as $code) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- plugin table.
			$counts[$code] = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE scan_id = %d AND FIND_IN_SET(%s, detectors)', $table, $state['id'], $code));
		}
		$state['counts'] = $counts;
		$state['finished'] = time();
		return $state;
	}

	/**
	 * Results for the admin page and WP-CLI, parents first.
	 *
	 * @param array $args {group, code, offset, limit}
	 * @return array {items, total}
	 */
	public static function results($scan_id, array $args = array())
	{
		global $wpdb;
		// '' matches every group or detector, as in load_items().
		$group = empty($args['group']) ? '' : (string) $args['group'];
		$code = empty($args['code']) ? '' : (string) $args['code'];
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- plugin table.
		$total = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM %i WHERE scan_id = %d AND (%s = '' OR FIND_IN_SET(repair_group, %s) > 0) AND (%s = '' OR FIND_IN_SET(%s, detectors) > 0)", self::items_table(), $scan_id, $group, $group, $code, $code));
		$items = self::load_items($scan_id, array(
			'group' => $group,
			'code' => $code,
			'limit' => isset($args['limit']) ? (int) $args['limit'] : 0,
			'offset' => isset($args['offset']) ? (int) $args['offset'] : 0,
		));
		return array('items' => $items, 'total' => $total);
	}

	/**
	 * 404s (logs and imported URLs) that no object accounts for, most requested first,
	 * so an admin can say which post each one was.
	 *
	 * @return array[] {path, hits, sources}
	 */
	public static function unowned_urls($scan_id, $prefix = null, $limit = 100)
	{
		global $wpdb;
		$urls = self::urls_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- plugin table.
		$rows = $wpdb->get_results($wpdb->prepare("SELECT url_path, last_segment, detail, hits FROM %i WHERE scan_id IN (0, %d) AND source IN ('S5', 'S9U') ORDER BY hits DESC, id LIMIT 2000", $urls, $scan_id));
		if (!$rows) {
			return array();
		}
		$segments = array_values(array_unique(wp_list_pluck($rows, 'last_segment')));
		$evidence = new Parich_Slug_Repair_Evidence(new Parich_Slug_Repair_Source($prefix));
		$owned = $evidence->owners($segments);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- plugin table.
		$claimed = array_flip($wpdb->get_col($wpdb->prepare("SELECT DISTINCT last_segment FROM %i WHERE scan_id = %d AND source = 'CLAIM' AND last_segment IN (" . implode(',', array_fill(0, count($segments), '%s')) . ")", array_merge(array($urls, $scan_id), $segments))));
		$paths = array();
		foreach ($rows as $row) {
			if (isset($owned[$row->last_segment]) || isset($claimed[$row->last_segment])) {
				continue;
			}
			if (!isset($paths[$row->url_path])) {
				$paths[$row->url_path] = array('path' => $row->url_path, 'hits' => 0, 'sources' => array());
			}
			$paths[$row->url_path]['hits'] += (int) $row->hits;
			$paths[$row->url_path]['sources'][$row->detail] = true;
		}
		uasort($paths, function ($a, $b) {
			return $b['hits'] - $a['hits'];
		});
		$paths = array_slice(array_values($paths), 0, $limit);
		foreach ($paths as &$path) {
			$path['sources'] = array_keys($path['sources']);
		}
		return $paths;
	}

	/**
	 * Rows of a results CSV: header first.
	 *
	 * @return array[]
	 */
	public static function export_rows($scan_id)
	{
		$rows = array(array('object_type', 'object_id', 'subtype', 'status', 'title', 'damage', 'group', 'reasons', 'current', 'proposal', 'current_encoded', 'proposal_encoded', 'sources'));
		$results = self::results($scan_id);
		foreach ($results['items'] as $item) {
			$object = $item['object'];
			$sources = array();
			foreach ($item['candidates'] as $candidate) {
				if ($candidate['value'] === $item['proposal']) {
					foreach ($candidate['entries'] as $entry) {
						$sources[] = $entry['source'] . ' ' . $entry['detail'];
					}
				}
			}
			$rows[] = array_map(array(__CLASS__, 'csv_cell'), array(
				$object['type'],
				$object['id'],
				$object['subtype'],
				$object['status'],
				$object['title'],
				implode(' ', $item['codes']),
				$item['group'],
				implode(' ', $item['reasons']),
				rawurldecode($object['slug']),
				null === $item['proposal'] ? '' : rawurldecode($item['proposal']),
				$object['slug'],
				(string) $item['proposal'],
				implode('; ', array_unique($sources)),
			));
		}
		return $rows;
	}

	/**
	 * Keeps spreadsheet programs from running a cell as a formula.
	 */
	public static function csv_cell($value)
	{
		$value = (string) $value;
		return '' !== $value && false !== strpos("=+-@\t\r", $value[0]) ? "'" . $value : $value;
	}

	/**
	 * Reads an uploaded or given import file: plain UTF-8 text, limited in size and lines.
	 *
	 * @return string[]|WP_Error Lines.
	 */
	public static function read_import_file($path)
	{
		if (!is_readable($path) || !is_file($path)) {
			return new WP_Error('parich_slug_import', __('The file could not be read.', 'parich-long-slug'));
		}
		if (filesize($path) > self::IMPORT_MAX_BYTES) {
			/* translators: %s: size such as 2 MB */
			return new WP_Error('parich_slug_import', sprintf(__('The file is larger than %s.', 'parich-long-slug'), size_format(self::IMPORT_MAX_BYTES)));
		}
		$text = (string) file_get_contents($path);
		$text = preg_replace('/^\xEF\xBB\xBF/', '', $text);
		// phpcs:ignore WordPress.WP.DeprecatedFunctions.seems_utf8Found -- fallback for WordPress before 6.9, which has no wp_is_valid_utf8().
		$valid = function_exists('wp_is_valid_utf8') ? wp_is_valid_utf8($text) : seems_utf8($text);
		if (false !== strpos($text, "\0") || !$valid) {
			return new WP_Error('parich_slug_import', __('The file is not plain UTF-8 text.', 'parich-long-slug'));
		}
		$lines = preg_split('/\r\n|\r|\n/', $text);
		if (count($lines) > self::IMPORT_MAX_LINES) {
			/* translators: %s: number of lines */
			return new WP_Error('parich_slug_import', sprintf(__('The file has more than %s lines.', 'parich-long-slug'), number_format_i18n(self::IMPORT_MAX_LINES)));
		}
		return $lines;
	}

	/**
	 * Adds URLs (one per line; or CSV with the URL first and a number of clicks or
	 * hits second, as Search Console exports it). They are kept across scans.
	 *
	 * @return array {added, skipped}
	 */
	public static function import_urls(array $lines)
	{
		$installed = self::maybe_install();
		if (is_wp_error($installed)) {
			return array('added' => 0, 'skipped' => count($lines), 'error' => $installed->get_error_message());
		}
		$added = 0;
		$skipped = 0;
		$home = home_url('/');
		foreach ($lines as $line) {
			$line = trim($line);
			if ('' === $line || '#' === $line[0]) {
				continue;
			}
			$cells = str_getcsv($line);
			$url = trim((string) $cells[0]);
			$hits = isset($cells[1]) && is_numeric(trim($cells[1])) ? (int) $cells[1] : 1;
			if (!preg_match('#^(https?://|/)#i', $url)) {
				++$skipped;
				continue;
			}
			if (Parich_Slug_Repair_Evidence::add_url(0, 'S9U', 'import', $url, $home, max(1, $hits))) {
				++$added;
			} else {
				++$skipped;
			}
		}
		return array('added' => $added, 'skipped' => $skipped);
	}

	/**
	 * Adds rows "type,id,slug" (type: post or term; slug readable or encoded, or a URL).
	 *
	 * @return array {added, skipped}
	 */
	public static function import_csv(array $lines)
	{
		global $wpdb;
		$installed = self::maybe_install();
		if (is_wp_error($installed)) {
			return array('added' => 0, 'skipped' => count($lines), 'error' => $installed->get_error_message());
		}
		$added = 0;
		$skipped = 0;
		foreach ($lines as $line) {
			if ('' === trim($line)) {
				continue;
			}
			$cells = array_map('trim', str_getcsv($line));
			if (3 > count($cells) || !in_array(strtolower($cells[0]), array('post', 'term'), true) || !ctype_digit($cells[1])) {
				if ('type' !== strtolower($cells[0])) {
					++$skipped; // Anything but the header line.
				}
				continue;
			}
			$slug = self::normalize_slug($cells[2]);
			if (!Parich_Slug_Repair_Util::is_valid($slug)) {
				++$skipped;
				continue;
			}
			$key = strtolower($cells[0]) . ':' . (int) $cells[1];
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- plugin table; upsert has no API.
			$wpdb->query($wpdb->prepare(
				"INSERT INTO %i (scan_id, source, detail, url_path, path_hash, last_segment, segment_hash, object_key, hits, truncated) VALUES (0, 'S9C', 'import', '', %s, %s, %s, %s, 1, 0) ON DUPLICATE KEY UPDATE last_segment = VALUES(last_segment), segment_hash = VALUES(segment_hash)",
				self::urls_table(),
				md5('S9C|' . $key),
				$slug,
				md5($slug),
				$key
			));
			++$added;
		}
		return array('added' => $added, 'skipped' => $skipped);
	}

	/**
	 * A slug typed or pasted by a person: readable Thai, encoded, or a whole URL. A
	 * valid slug is kept exactly (an old slug may hold characters WordPress would clean
	 * now); anything else is cleaned as WordPress cleans a typed slug (spaces → "-"),
	 * up to the 1,900 bytes repairs may use.
	 */
	public static function normalize_slug($text)
	{
		$text = trim((string) $text);
		if (false !== strpos($text, '/')) {
			$segment = Parich_Slug_Repair_Evidence::segment_of($text, home_url('/'));
			return null === $segment ? '' : $segment['segment'];
		}
		$encoded = strtolower(rawurlencode(rawurldecode($text)));
		if ('' === $encoded || Parich_Slug_Repair_Util::is_valid($encoded)) {
			return $encoded;
		}
		return Parich_Slug_Sanitize::sanitize_with_max(remove_accents(rawurldecode($text)), Parich_Slug_Sanitize::HARD_MAX);
	}

	/**
	 * @return array {urls, csv}
	 */
	public static function import_counts()
	{
		global $wpdb;
		if (self::VERSION !== get_option(self::VERSION_OPTION)) {
			return array('urls' => 0, 'csv' => 0);
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- plugin table.
		$rows = $wpdb->get_results($wpdb->prepare('SELECT source, COUNT(*) AS c FROM %i WHERE scan_id = 0 GROUP BY source', self::urls_table()), OBJECT_K);
		return array('urls' => isset($rows['S9U']) ? (int) $rows['S9U']->c : 0, 'csv' => isset($rows['S9C']) ? (int) $rows['S9C']->c : 0);
	}

	public static function clear_imports()
	{
		global $wpdb;
		if (self::VERSION === get_option(self::VERSION_OPTION)) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- plugin table.
			$wpdb->query($wpdb->prepare('DELETE FROM %i WHERE scan_id = 0', self::urls_table()));
		}
	}
}
