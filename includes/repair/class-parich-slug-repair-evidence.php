<?php
/**
 * Evidence of what a slug was before Long URL Maker damaged it.
 *
 * Tied to one object (loaded per batch by load()):
 *   S1  Long URL Maker's backup rows (kpcode_url_posts), oldest first
 *   S2  _wp_old_slug          (also shows changes its backup never saw)
 *   S3  _wp_desired_post_slug (trashed posts)
 *   S4  SEO tables: Yoast yoast_indexable, Rank Math rank_math_internal_links
 *   S8  titles, current and in revisions (slugs made from them: see the detectors)
 *   S9  imported CSV rows "type,id,slug"
 *   S10 guid, when it is a pretty permalink of this site
 *
 * Not tied to an object, collected into the URL index by collect() and matched by
 * slug prefix (only cut slugs can be matched this way):
 *   S5  404 logs: Redirection, Rank Math, 301 Redirects (eps)
 *   S6  existing redirects: 301 Redirects (eps), Redirection, Rank Math
 *   S7  links: post content, custom menu links, Elementor data, widgets
 *   S9  imported URL lists
 *
 * Other plugins' tables are read only, and only when they have the expected columns;
 * a column shorter than the URLs it stores may hold cut values, which only count as
 * a prefix. Nothing here writes outside this plugin's tables.
 *
 * @package Parich_Long_Slug
 */

if (!defined('ABSPATH')) {
	exit;
}

class Parich_Slug_Repair_Evidence
{
	/** Path endings that are not part of an object's slug. */
	const ENDPOINTS = '#/(?:page/\d+|comment-page-\d+|feed(?:/[a-z0-9_-]+)?|rss2?|atom|rdf|embed|amp|trackback)$#';

	const URL_BATCH = 500;

	/** @var Parich_Slug_Repair_Source */
	private $source;

	/** @var array Problems met while reading other plugins' data, shown with the results. */
	public $warnings = array();

	public function __construct(Parich_Slug_Repair_Source $source)
	{
		$this->source = $source;
	}

	/**
	 * The newest Long URL Maker backup value, or null.
	 */
	public static function s1_latest(array $facts)
	{
		return $facts['s1'] ? $facts['s1'][count($facts['s1']) - 1]['value'] : null;
	}

	public static function empty_facts($title = '')
	{
		return array('s1' => array(), 'old_slugs' => array(), 'desired' => array(), 'seo' => array(), 'guid' => null, 'csv' => null, 'titles' => array($title));
	}

	private function warn($code, $table)
	{
		$this->warnings[$code . ':' . $table] = array('code' => $code, 'table' => $table);
	}

	/**
	 * Whether an optional table can be read: missing is fine, present with other
	 * columns is skipped with a warning.
	 */
	public function usable($name, array $columns)
	{
		if (!$this->source->has_table($name)) {
			return false;
		}
		if (!$this->source->has_columns($name, $columns)) {
			$this->warn('columns', $this->source->table($name));
			return false;
		}
		return true;
	}

	/**
	 * Facts about a batch of objects of one kind.
	 *
	 * @param string $type    'post' or 'term'.
	 * @param array  $objects id => object (see Parich_Slug_Repair_Detectors::detect()), with 'guid' for posts.
	 * @param int    $scan_id Current scan, for imported CSV rows.
	 * @return array id => facts
	 */
	public function load($type, array $objects, $scan_id = 0)
	{
		global $wpdb;
		$facts = array();
		foreach ($objects as $id => $object) {
			$facts[$id] = self::empty_facts($object['title']);
		}
		if (!$objects) {
			return $facts;
		}
		$ids = array_map('intval', array_keys($objects));
		$src = $this->source;

		if ($this->usable('kpcode_url_posts', array('id', 'kpcode_post_id', 'kpcode_post_name', 'kpcode_type'))) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- read-only scan of another plugin's table.
			$rows = $wpdb->get_results($wpdb->prepare("SELECT id, kpcode_post_id, kpcode_post_name FROM %i WHERE kpcode_type = %s AND kpcode_post_id IN (" . implode(',', array_fill(0, count($ids), '%d')) . ") ORDER BY id", array_merge(array($src->table('kpcode_url_posts'), 'post' === $type ? 'posts' : 'terms'), $ids)));
			foreach ($rows as $row) {
				$facts[(int) $row->kpcode_post_id]['s1'][] = array('row' => (int) $row->id, 'value' => strtolower((string) $row->kpcode_post_name));
			}
		}

		if ('post' === $type) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- batch read; see above.
			$meta = $wpdb->get_results($wpdb->prepare("SELECT post_id, meta_key, meta_value FROM %i WHERE meta_key IN ('_wp_old_slug', '_wp_desired_post_slug') AND post_id IN (" . implode(',', array_fill(0, count($ids), '%d')) . ") ORDER BY meta_id", array_merge(array($src->table('postmeta')), $ids)));
			foreach ($meta as $row) {
				$facts[(int) $row->post_id]['_wp_old_slug' === $row->meta_key ? 'old_slugs' : 'desired'][] = (string) $row->meta_value;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- batch read; see above.
			$revisions = $wpdb->get_results($wpdb->prepare("SELECT post_parent, post_title FROM %i WHERE post_type = 'revision' AND post_parent IN (" . implode(',', array_fill(0, count($ids), '%d')) . ") ORDER BY ID DESC", array_merge(array($src->table('posts')), $ids)));
			foreach ($revisions as $row) {
				if (!in_array($row->post_title, $facts[(int) $row->post_parent]['titles'], true)) {
					$facts[(int) $row->post_parent]['titles'][] = $row->post_title;
				}
			}
			$home = $this->source->home();
			foreach ($objects as $id => $object) {
				if ('attachment' !== $object['subtype'] && !empty($object['guid']) && false === strpos($object['guid'], '?')) {
					$path = Parich_Slug_Repair_Util::path_of($object['guid'], $home);
					$segment = null === $path ? '' : Parich_Slug_Repair_Util::last_segment($path);
					if ('' !== $segment) {
						$facts[$id]['guid'] = array('value' => $segment, 'path' => $path);
					}
				}
			}
		}

		$this->load_seo($type, $ids, $facts);

		$keys = array();
		foreach ($ids as $id) {
			$keys[] = $type . ':' . $id;
		}
		$table = Parich_Slug_Repair_Scanner::urls_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- plugin table.
		$csv = $wpdb->get_results($wpdb->prepare("SELECT object_key, last_segment FROM %i WHERE scan_id = 0 AND source = 'S9C' AND object_key IN (" . implode(',', array_fill(0, count($keys), '%s')) . ") ORDER BY id", array_merge(array($table), $keys)));
		foreach ($csv as $row) {
			$facts[(int) substr($row->object_key, strlen($type) + 1)]['csv'] = $row->last_segment;
		}
		return $facts;
	}

	private function load_seo($type, array $ids, array &$facts)
	{
		global $wpdb;
		$home = $this->source->home();
		$src = $this->source;
		if ($this->usable('yoast_indexable', array('object_id', 'object_type', 'permalink'))) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- read-only scan of another plugin's table.
			$rows = $wpdb->get_results($wpdb->prepare("SELECT object_id, permalink FROM %i WHERE object_type = %s AND permalink IS NOT NULL AND object_id IN (" . implode(',', array_fill(0, count($ids), '%d')) . ")", array_merge(array($src->table('yoast_indexable'), $type), $ids)));
			$this->add_seo($rows, 'object_id', 'permalink', 'yoast_indexable', $src->column_length('yoast_indexable', 'permalink'), $home, $facts);
		}
		if ('post' === $type && $this->usable('rank_math_internal_links', array('target_post_id', 'url'))) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- read-only scan of another plugin's table.
			$rows = $wpdb->get_results($wpdb->prepare("SELECT DISTINCT target_post_id, url FROM %i WHERE target_post_id IN (" . implode(',', array_fill(0, count($ids), '%d')) . ")", array_merge(array($src->table('rank_math_internal_links')), $ids)));
			$this->add_seo($rows, 'target_post_id', 'url', 'rank_math_internal_links', $src->column_length('rank_math_internal_links', 'url'), $home, $facts);
		}
	}

	private function add_seo(array $rows, $id_column, $url_column, $detail, $max, $home, array &$facts)
	{
		foreach ($rows as $row) {
			$id = (int) $row->$id_column;
			$url = (string) $row->$url_column;
			$truncated = null !== $max && mb_strlen($url) >= $max;
			$segment = self::segment_of($url, $home, $truncated);
			if (null === $segment || !isset($facts[$id])) {
				continue;
			}
			$facts[$id]['seo'][] = array('source' => $detail, 'value' => $segment['segment'], 'path' => $segment['path'], 'truncated' => $truncated);
		}
	}

	/**
	 * Normalised path and last segment of a URL on this site; a cut URL loses a
	 * trailing partial escape or character.
	 *
	 * @return array|null {path, segment}
	 */
	public static function segment_of($url, $home, $truncated = false)
	{
		$url = trim((string) $url);
		if ($truncated) {
			$url = preg_replace('/%[0-9a-fA-F]?$/', '', $url);
		}
		if ('' === $url || false !== strpos($url, '?p=') || false !== strpos($url, '?page_id=')) {
			return null;
		}
		$path = Parich_Slug_Repair_Util::path_of($url, $home);
		if (null === $path) {
			return null;
		}
		$path = preg_replace(self::ENDPOINTS, '', $path);
		$segment = Parich_Slug_Repair_Util::last_segment($path);
		if ($truncated) {
			$segment = Parich_Slug_Repair_Util::trim_partial($segment);
		}
		return '' === $segment ? null : array('path' => $path, 'segment' => $segment);
	}

	/**
	 * Adds one URL to the index (hits add up per source and path).
	 */
	public static function add_url($scan_id, $source, $detail, $url, $home, $hits = 1, $truncated = false)
	{
		global $wpdb;
		$segment = self::segment_of($url, $home, $truncated);
		if (null === $segment) {
			return false;
		}
		$table = Parich_Slug_Repair_Scanner::urls_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- plugin table; upsert has no API.
		$wpdb->query($wpdb->prepare(
			'INSERT INTO %i (scan_id, source, detail, url_path, path_hash, last_segment, segment_hash, hits, truncated) VALUES (%d, %s, %s, %s, %s, %s, %s, %d, %d) ON DUPLICATE KEY UPDATE hits = hits + VALUES(hits)',
			$table,
			$scan_id,
			$source,
			$detail,
			$segment['path'],
			md5($source . '|' . $detail . '|' . $segment['path']),
			$segment['segment'],
			md5($segment['segment']),
			max(1, (int) $hits),
			$truncated ? 1 : 0
		));
		return true;
	}

	/**
	 * URLs of this site found in a block of text (absolute, protocol-relative or root-relative).
	 *
	 * @return string[]
	 */
	public static function urls_in_text($text, $home)
	{
		$text = str_replace('\\/', '/', (string) $text);
		$host = wp_parse_url($home, PHP_URL_HOST);
		$urls = array();
		if ($host) {
			$pattern = '#(?:https?:)?//(?:www\.)?' . preg_quote($host, '#') . '(?::\d+)?(/[^\s"\'<>\\\\)\]]*)#i';
			if (preg_match_all($pattern, $text, $matches)) {
				$urls = $matches[1];
			}
		}
		if (preg_match_all('#\bhref\s*=\s*["\'](/(?!/)[^"\'\s<>]*)["\']#i', $text, $matches)) {
			$urls = array_merge($urls, $matches[1]);
		}
		return array_map(function ($url) {
			return html_entity_decode($url, ENT_QUOTES, 'UTF-8');
		}, $urls);
	}

	/**
	 * URL sources read into the index, in order. Each reads its table in batches by id.
	 */
	public function url_tasks()
	{
		return array('redirection_404', 'rank_math_404_logs', 'eps_404_log', 'eps_redirects', 'redirection_items', 'rank_math_redirections', 'content', 'menu_links', 'elementor', 'widgets');
	}

	/**
	 * Reads one batch of a URL source into the index.
	 *
	 * @return int|null Cursor for the next batch, or null when the source is done.
	 */
	public function collect($task, $scan_id, $cursor)
	{
		global $wpdb;
		$src = $this->source;
		$home = $src->home();
		$cursor = (int) $cursor;
		$batch = self::URL_BATCH;

		switch ($task) {
			case 'redirection_404':
				if (!$this->usable('redirection_404', array('id', 'url'))) {
					return null;
				}
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- read-only scan of another plugin's table.
				$rows = $wpdb->get_results($wpdb->prepare('SELECT id, url FROM %i WHERE id > %d ORDER BY id LIMIT %d', $src->table('redirection_404'), $cursor, $batch));
				$max = $src->column_length('redirection_404', 'url');
				foreach ($rows as $row) {
					self::add_url($scan_id, 'S5', 'redirection_404', $row->url, $home, 1, null !== $max && mb_strlen($row->url) >= $max);
				}
				return self::next($rows, 'id', $batch);

			case 'rank_math_404_logs':
				if (!$this->usable('rank_math_404_logs', array('id', 'uri', 'times_accessed'))) {
					return null;
				}
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- read-only scan of another plugin's table.
				$rows = $wpdb->get_results($wpdb->prepare('SELECT id, uri, times_accessed FROM %i WHERE id > %d ORDER BY id LIMIT %d', $src->table('rank_math_404_logs'), $cursor, $batch));
				$max = $src->column_length('rank_math_404_logs', 'uri');
				foreach ($rows as $row) {
					self::add_url($scan_id, 'S5', 'rank_math_404_logs', $row->uri, $home, (int) $row->times_accessed, null !== $max && mb_strlen($row->uri) >= $max);
				}
				return self::next($rows, 'id', $batch);

			case 'eps_404_log':
				// 301 Redirects keeps its last 404s in an option: array of {timestamp, url, user_agent}.
				$log = $src->option('eps_redirects_404_log');
				foreach (is_array($log) ? $log : array() as $entry) {
					if (is_array($entry) && !empty($entry['url']) && is_string($entry['url'])) {
						self::add_url($scan_id, 'S5', 'eps_redirects_404_log', $entry['url'], $home);
					}
				}
				return null;

			case 'eps_redirects':
				// 301 Redirects: {prefix}redirects, url_from / url_to varchar(1024), stored decoded.
				if (!$src->has_table('redirects') || !$this->usable('redirects', array('id', 'url_from', 'url_to'))) {
					return null;
				}
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- read-only scan of another plugin's table.
				$rows = $wpdb->get_results($wpdb->prepare('SELECT id, url_from, url_to FROM %i WHERE id > %d ORDER BY id LIMIT %d', $src->table('redirects'), $cursor, $batch));
				$from_max = $src->column_length('redirects', 'url_from');
				$to_max = $src->column_length('redirects', 'url_to');
				foreach ($rows as $row) {
					$from = '/' . ltrim((string) $row->url_from, '/');
					self::add_url($scan_id, 'S6', 'eps_redirects', $from, $home, 1, null !== $from_max && mb_strlen($row->url_from) >= $from_max);
					if (!is_numeric($row->url_to)) {
						self::add_url($scan_id, 'S6', 'eps_redirects', $row->url_to, $home, 1, null !== $to_max && mb_strlen($row->url_to) >= $to_max);
					}
				}
				return self::next($rows, 'id', $batch);

			case 'redirection_items':
				if (!$this->usable('redirection_items', array('id', 'url', 'action_type', 'action_data'))) {
					return null;
				}
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- read-only scan of another plugin's table.
				$rows = $wpdb->get_results($wpdb->prepare('SELECT id, url, action_type, action_data FROM %i WHERE id > %d ORDER BY id LIMIT %d', $src->table('redirection_items'), $cursor, $batch));
				foreach ($rows as $row) {
					self::add_url($scan_id, 'S6', 'redirection_items', $row->url, $home);
					if ('url' === $row->action_type && is_string($row->action_data) && '' !== $row->action_data && '{' !== substr($row->action_data, 0, 1)) {
						self::add_url($scan_id, 'S6', 'redirection_items', $row->action_data, $home);
					}
				}
				return self::next($rows, 'id', $batch);

			case 'rank_math_redirections':
				if (!$this->usable('rank_math_redirections', array('id', 'sources', 'url_to'))) {
					return null;
				}
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- read-only scan of another plugin's table.
				$rows = $wpdb->get_results($wpdb->prepare('SELECT id, sources, url_to FROM %i WHERE id > %d ORDER BY id LIMIT %d', $src->table('rank_math_redirections'), $cursor, $batch));
				foreach ($rows as $row) {
					$sources = maybe_unserialize($row->sources);
					foreach (is_array($sources) ? $sources : array() as $entry) {
						if (is_array($entry) && isset($entry['pattern'], $entry['comparison']) && 'exact' === $entry['comparison'] && is_string($entry['pattern'])) {
							self::add_url($scan_id, 'S6', 'rank_math_redirections', '/' . ltrim($entry['pattern'], '/'), $home);
						}
					}
					self::add_url($scan_id, 'S6', 'rank_math_redirections', $row->url_to, $home);
				}
				return self::next($rows, 'id', $batch);

			case 'content':
				// Links in content, revisions included: they keep links from before the damage.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- read-only batch scan.
				$rows = $wpdb->get_results($wpdb->prepare("SELECT ID, post_content FROM %i WHERE ID > %d AND post_status <> 'auto-draft' ORDER BY ID LIMIT %d", $src->table('posts'), $cursor, $batch));
				foreach ($rows as $row) {
					foreach (self::urls_in_text($row->post_content, $home) as $url) {
						self::add_url($scan_id, 'S7', 'post_content', $url, $home);
					}
				}
				return self::next($rows, 'ID', $batch);

			case 'menu_links':
			case 'elementor':
				$key = 'menu_links' === $task ? '_menu_item_url' : '_elementor_data';
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- read-only batch scan.
				$rows = $wpdb->get_results($wpdb->prepare('SELECT meta_id, meta_value FROM %i WHERE meta_key = %s AND meta_id > %d ORDER BY meta_id LIMIT %d', $src->table('postmeta'), $key, $cursor, $batch));
				foreach ($rows as $row) {
					$urls = 'menu_links' === $task ? array((string) $row->meta_value) : self::urls_in_text($row->meta_value, $home);
					foreach ($urls as $url) {
						self::add_url($scan_id, 'S7', $key, $url, $home);
					}
				}
				return self::next($rows, 'meta_id', $batch);

			case 'widgets':
				foreach (array('widget_text', 'widget_custom_html', 'widget_block') as $option) {
					$widgets = $src->option($option);
					foreach (is_array($widgets) ? $widgets : array() as $widget) {
						if (is_array($widget)) {
							foreach (self::urls_in_text(implode("\n", array_filter($widget, 'is_string')), $home) as $url) {
								self::add_url($scan_id, 'S7', $option, $url, $home);
							}
						}
					}
				}
				return null;
		}
		return null;
	}

	private static function next(array $rows, $column, $batch)
	{
		return count($rows) === $batch ? (int) $rows[count($rows) - 1]->$column : null;
	}

	/**
	 * Indexed URLs (this scan's and imported ones) whose slug starts with $prefix and is longer.
	 *
	 * @return object[] {source, detail, url_path, last_segment, hits, truncated}
	 */
	public static function urls_with_prefix($scan_id, $prefix, $limit = 50)
	{
		global $wpdb;
		$table = Parich_Slug_Repair_Scanner::urls_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- plugin table.
		return $wpdb->get_results($wpdb->prepare(
			"SELECT source, detail, url_path, last_segment, hits, truncated FROM %i WHERE scan_id IN (0, %d) AND source IN ('S5', 'S6', 'S7', 'S9U') AND last_segment LIKE %s AND CHAR_LENGTH(last_segment) > %d ORDER BY hits DESC, id LIMIT %d",
			$table,
			$scan_id,
			$wpdb->esc_like($prefix) . '%',
			strlen($prefix),
			$limit
		));
	}

	/**
	 * Objects whose current slug is one of $values.
	 *
	 * @return array value => string[] "post:ID" / "term:ID"
	 */
	public function owners(array $values)
	{
		global $wpdb;
		$values = array_values(array_unique(array_filter($values, 'strlen')));
		$owners = array();
		if (!$values) {
			return $owners;
		}
		$src = $this->source;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- read-only.
		foreach ($wpdb->get_results($wpdb->prepare("SELECT ID, post_name FROM %i WHERE post_name IN (" . implode(',', array_fill(0, count($values), '%s')) . ") AND post_type <> 'revision' AND post_status <> 'auto-draft'", array_merge(array($src->table('posts')), $values))) as $row) {
			$owners[$row->post_name][] = 'post:' . $row->ID;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- read-only.
		foreach ($wpdb->get_results($wpdb->prepare("SELECT term_id, slug FROM %i WHERE slug IN (" . implode(',', array_fill(0, count($values), '%s')) . ")", array_merge(array($src->table('terms')), $values))) as $row) {
			$owners[$row->slug][] = 'term:' . $row->term_id;
		}
		return $owners;
	}
}
