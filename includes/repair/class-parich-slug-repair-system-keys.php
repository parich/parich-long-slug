<?php
/**
 * D6: posts whose post_name is a key that WordPress or a plugin looks things up by,
 * not an address. Long URL Maker's deactivation routine rewrote them from the title
 * like any post, which breaks ACF fields, templates, global styles and the like.
 *
 * What a key should be, per type:
 *   acf-field         field_xxx: from post meta "_{name}" next to saved values, ACF
 *                     options "_options_{name}", acf-json files, the backup (S1).
 *                     "auto" only when two sources agree.
 *   acf-field-group   group_xxx: acf-json (by title), the backup; always "review".
 *   wp_global_styles  "wp-global-styles-" + the theme it belongs to (term wp_theme);
 *                     "-2", "-3"… in order of ID when a theme has several.
 *   custom_css        the theme's name (sanitize_title() of its title).
 *   wp_template(_part) the template whose title it has (WordPress's default types,
 *                     the theme's templates and parts); always "review".
 *   oembed_cache, customize_changeset  only reported: WordPress makes new ones.
 *   anything else     the backup only; always "review".
 * Navigation menus, reusable blocks, menu items and revisions are found by ID: skipped.
 *
 * @package Parich_Long_Slug
 */

if (!defined('ABSPATH')) {
	exit;
}

class Parich_Slug_Repair_System_Keys
{
	/** Found by ID, or not damaged by Long URL Maker in a way that matters. */
	const SKIP = array('revision', 'nav_menu_item', 'wp_block', 'wp_navigation', 'wp_font_face', 'attachment');

	/** Checked even when the plugin that registers them is inactive. */
	const KNOWN = array('acf-field', 'acf-field-group', 'wp_global_styles', 'wp_template', 'wp_template_part', 'wp_font_family', 'custom_css', 'oembed_cache', 'customize_changeset', 'user_request');

	/** Only reported, with the form their key must have. */
	const REPORT_ONLY = array(
		'oembed_cache' => '/^[a-f0-9]{32}$/',
		'customize_changeset' => '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
	);

	public static function post_types()
	{
		$types = array_merge(self::KNOWN, array_values(get_post_types(array('public' => false))));
		$types = array_values(array_diff(array_unique($types), self::SKIP, Parich_Slug_Repair_Scanner::post_types()));
		/**
		 * Filters the post types whose post_name is a key the repair scan checks (D6).
		 *
		 * @param string[] $types Non-public post types and known plugin types.
		 */
		return array_values((array) apply_filters('parich_slug_repair_system_post_types', $types));
	}

	/**
	 * What a batch of system posts is checked against.
	 *
	 * @param array $objects id => object (with 'excerpt' for ACF fields)
	 * @return array {themes, styles_order, styles_count, acf_meta, acf_options, acf_json, templates}
	 */
	public static function context(Parich_Slug_Repair_Source $source, array $objects)
	{
		global $wpdb;
		$context = array(
			'themes' => array(),
			'styles_order' => array(),
			'styles_count' => array(),
			'acf_meta' => array(),
			'acf_options' => array(),
			'acf_json' => self::acf_json(),
			'templates' => self::templates(),
		);
		$themed = array();
		$names = array();
		foreach ($objects as $id => $object) {
			if (in_array($object['subtype'], array('wp_global_styles', 'wp_template', 'wp_template_part'), true)) {
				$themed[] = (int) $id;
			}
			if ('acf-field' === $object['subtype'] && '' !== (string) $object['excerpt']) {
				$names[] = (string) $object['excerpt'];
			}
		}

		$theme_tables = array($source->table('term_relationships'), $source->table('term_taxonomy'), $source->table('terms'));
		if ($themed) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- read-only scan.
			foreach ($wpdb->get_results($wpdb->prepare("SELECT tr.object_id, t.slug FROM %i tr JOIN %i tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'wp_theme' JOIN %i t ON t.term_id = tt.term_id WHERE tr.object_id IN (" . implode(',', array_fill(0, count($themed), '%d')) . ')', array_merge($theme_tables, $themed))) as $row) {
				$context['themes'][(int) $row->object_id] = (string) $row->slug;
			}
		}
		foreach (array_unique($context['themes']) as $theme) {
			// The global styles posts of a theme, in the order they were made.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- read-only scan.
			$ids = $wpdb->get_col($wpdb->prepare("SELECT tr.object_id FROM %i tr JOIN %i tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'wp_theme' JOIN %i t ON t.term_id = tt.term_id JOIN %i p ON p.ID = tr.object_id WHERE t.slug = %s AND p.post_type = 'wp_global_styles' AND p.post_status <> 'auto-draft' ORDER BY p.ID", array_merge($theme_tables, array($source->table('posts'), $theme))));
			$context['styles_count'][$theme] = count($ids);
			foreach ($ids as $index => $id) {
				$context['styles_order'][(int) $id] = $index;
			}
		}

		foreach (array_unique($names) as $name) {
			$counts = array();
			foreach (array('postmeta', 'termmeta') as $meta) {
				if (!$source->has_table($meta)) {
					continue;
				}
				// Grouped by hash: MySQL can fail to group long values of an indexed column.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- read-only scan.
				foreach ($wpdb->get_results($wpdb->prepare('SELECT MIN(meta_value) AS value, COUNT(*) AS c FROM %i WHERE meta_key = %s AND meta_value LIKE %s GROUP BY MD5(meta_value)', $source->table($meta), '_' . $name, 'field\_%')) as $row) {
					$counts[$row->value] = (isset($counts[$row->value]) ? $counts[$row->value] : 0) + (int) $row->c;
				}
			}
			arsort($counts);
			$context['acf_meta'][$name] = $counts;
			$option = $source->option('_options_' . $name);
			if (is_string($option) && preg_match('/^field_[a-z0-9]+$/', $option)) {
				$context['acf_options'][$name] = $option;
			}
		}
		return $context;
	}

	/**
	 * Field and group keys in ACF's local JSON files (theme acf-json folders).
	 *
	 * @return array {fields: name => keys[], groups: title => keys[]}
	 */
	public static function acf_json()
	{
		static $map = null;
		if (null !== $map) {
			return $map;
		}
		$map = array('fields' => array(), 'groups' => array());
		$dirs = array(get_stylesheet_directory() . '/acf-json', get_template_directory() . '/acf-json');
		$dirs = array_unique(array_merge($dirs, (array) apply_filters('acf/settings/load_json', array()))); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- ACF's own filter, read only.
		$collect = function (array $fields) use (&$collect, &$map) {
			foreach ($fields as $field) {
				if (is_array($field) && !empty($field['key']) && !empty($field['name'])) {
					$map['fields'][(string) $field['name']][] = strtolower((string) $field['key']);
				}
				foreach (array('sub_fields', 'layouts') as $children) {
					if (is_array($field) && !empty($field[$children]) && is_array($field[$children])) {
						$collect($field[$children]);
					}
				}
			}
		};
		foreach ($dirs as $dir) {
			foreach (is_dir($dir) ? (array) glob(trailingslashit($dir) . '*.json') : array() as $file) {
				$group = json_decode((string) file_get_contents($file), true);
				if (!is_array($group) || empty($group['key'])) {
					continue;
				}
				if (!empty($group['title'])) {
					$map['groups'][(string) $group['title']][] = strtolower((string) $group['key']);
				}
				$collect(isset($group['fields']) && is_array($group['fields']) ? $group['fields'] : array());
			}
		}
		return $map;
	}

	/**
	 * Template slugs and titles: WordPress's default types and the active theme's files.
	 *
	 * @return array {wp_template: slug => title, wp_template_part: slug => title}
	 */
	public static function templates()
	{
		static $templates = null;
		if (null !== $templates) {
			return $templates;
		}
		$templates = array('wp_template' => array(), 'wp_template_part' => array());
		foreach (get_default_block_template_types() as $slug => $type) {
			$templates['wp_template'][$slug] = (string) $type['title'];
		}
		if (function_exists('wp_get_theme_data_custom_templates')) {
			foreach (wp_get_theme_data_custom_templates() as $slug => $template) {
				$templates['wp_template'][$slug] = isset($template['title']) ? (string) $template['title'] : $slug;
			}
		}
		foreach (array('wp_template', 'wp_template_part') as $type) {
			foreach (get_block_templates(array(), $type) as $template) {
				if ('theme' === $template->source) {
					$templates[$type][$template->slug] = (string) $template->title;
				}
			}
		}
		return $templates;
	}

	/**
	 * @param array $object  {type, id, subtype, status, parent, title, slug, excerpt}
	 * @param array $facts   From Parich_Slug_Repair_Evidence::load().
	 * @param array $context From context().
	 * @return array|null Item (see Parich_Slug_Repair_Scorer::analyze()), or null when the key looks right.
	 */
	public static function analyze(array $object, array $facts, array $context)
	{
		$slug = (string) $object['slug'];
		$title = (string) $object['title'];
		$type = $object['subtype'];
		$rewritten = $slug === Parich_Slug_Legacy_Sanitize::legacy_old($title);
		$item = array(
			'object' => $object,
			'codes' => array('D6'),
			'd2_by' => null,
			'notes' => array(),
			'candidates' => array(),
			'supports' => array(),
			'current_votes' => array(),
			'invalid' => array(),
			'flags' => array(),
			'pinned' => null,
			'proposal' => null,
			'group' => 'unresolved',
			'reasons' => array(),
		);
		$add = function ($value, $source, $detail, $strength) use (&$item, $slug) {
			$value = (string) $value;
			if ($value === $slug || !Parich_Slug_Repair_Util::is_valid($value)) {
				return;
			}
			if (!isset($item['candidates'][$value])) {
				$item['candidates'][$value] = array('value' => $value, 'entries' => array());
			}
			$item['candidates'][$value]['entries'][] = array('source' => $source, 'detail' => $detail, 'strength' => $strength, 'stale' => false, 'url' => false, 'ambiguous' => false);
		};
		// The backup and admins' CSV rows, when they have the right form for the type.
		$evidence = function ($pattern = null) use ($facts, $add) {
			foreach (array_reverse($facts['s1']) as $row) {
				if (null === $pattern || preg_match($pattern, $row['value'])) {
					$add($row['value'], 'S1', 'kpcode_url_posts #' . $row['row'], Parich_Slug_Repair_Scorer::STRONG);
					break;
				}
			}
			if (null !== $facts['csv']) {
				$add($facts['csv'], 'S9C', 'import', Parich_Slug_Repair_Scorer::STRONG);
			}
		};

		if (isset(self::REPORT_ONLY[$type])) {
			if (preg_match(self::REPORT_ONLY[$type], $slug)) {
				return null;
			}
			$item['flags'][] = 'report_only';
			return Parich_Slug_Repair_Scorer::decide($item);
		}

		switch ($type) {
			case 'acf-field':
				if (preg_match('/^field_[a-z0-9]+$/', $slug)) {
					return null;
				}
				$name = (string) $object['excerpt'];
				$counts = isset($context['acf_meta'][$name]) ? $context['acf_meta'][$name] : array();
				foreach ($counts as $value => $count) {
					/* translators: %d: number of values */
					$add($value, 'ACFM', sprintf(_n('next to %d saved value', 'next to %d saved values', $count, 'parich-long-slug'), $count), Parich_Slug_Repair_Scorer::MEDIUM);
				}
				$values = array_values($counts);
				if (count($values) > 1 && $values[0] === $values[1]) {
					$item['flags'][] = 'tie';
				}
				if (isset($context['acf_options'][$name])) {
					$add($context['acf_options'][$name], 'ACFO', 'options page', Parich_Slug_Repair_Scorer::MEDIUM);
				}
				foreach (isset($context['acf_json']['fields'][$name]) ? $context['acf_json']['fields'][$name] : array() as $key) {
					$add($key, 'ACFJ', 'acf-json', Parich_Slug_Repair_Scorer::MEDIUM);
				}
				$evidence('/^field_[a-z0-9]+$/');
				$item['flags'][] = 'needs_two';
				break;

			case 'acf-field-group':
				if (preg_match('/^group_[a-z0-9]+$/', $slug) || 'publish' !== $object['status']) {
					return null;
				}
				foreach (isset($context['acf_json']['groups'][$title]) ? $context['acf_json']['groups'][$title] : array() as $key) {
					$add($key, 'ACFJ', 'acf-json', Parich_Slug_Repair_Scorer::MEDIUM);
				}
				$evidence('/^group_[a-z0-9]+$/');
				$item['flags'][] = 'always_review';
				break;

			case 'wp_global_styles':
				$id = (int) $object['id'];
				if (!isset($context['themes'][$id])) {
					if (!$rewritten) {
						return null;
					}
					$evidence();
					$item['flags'][] = 'always_review';
					break;
				}
				$base = 'wp-global-styles-' . urlencode($context['themes'][$id]);
				if (preg_match('/^' . preg_quote($base, '/') . '(-\d+)?$/', $slug)) {
					return null;
				}
				$order = isset($context['styles_order'][$id]) ? $context['styles_order'][$id] : 0;
				$add(0 === $order ? $base : $base . '-' . ($order + 1), 'THEME', 'wp_theme: ' . $context['themes'][$id], Parich_Slug_Repair_Scorer::STRONG);
				if ($context['styles_count'][$context['themes'][$id]] > 1) {
					$item['flags'][] = 'shared_theme';
				}
				$evidence();
				break;

			case 'custom_css':
				$expected = sanitize_title($title);
				if ($slug === $expected) {
					return null;
				}
				$add($expected, 'THEME', 'theme ' . $title, Parich_Slug_Repair_Scorer::STRONG);
				$evidence();
				break;

			case 'wp_template':
			case 'wp_template_part':
				$known = $context['templates'][$type];
				if (!$rewritten || isset($known[$slug]) || 0 === strpos($slug, 'wp-custom-template-')) {
					return null;
				}
				foreach ($known as $key => $known_title) {
					if ('' !== $title && $known_title === $title) {
						$add($key, 'TPL', 'template "' . $known_title . '"', Parich_Slug_Repair_Scorer::MEDIUM);
					}
				}
				if ('wp_template' === $type) {
					$add('wp-custom-template-' . sanitize_title($title), 'TPL', 'custom template', Parich_Slug_Repair_Scorer::LOW);
				}
				$evidence();
				$item['flags'][] = 'always_review';
				break;

			default:
				// Anything else: only the backup knows; and only when the slug is what the
				// deactivation routine wrote and the backup says otherwise.
				$latest = Parich_Slug_Repair_Evidence::s1_latest($facts);
				if (!$rewritten || null === $latest || $latest === $slug) {
					return null;
				}
				$evidence();
				$item['flags'][] = 'always_review';
		}
		$item['candidates'] = array_values($item['candidates']);
		return Parich_Slug_Repair_Scorer::decide($item);
	}
}
