<?php
/**
 * Signs of the damage Long URL Maker does, read from one post or term and the facts
 * stored about it (see Parich_Slug_Repair_Evidence::load()).
 *
 *   D1 cut at 200 characters by a database upgrade (or its own deactivation)
 *   D2 rewritten from the title by its deactivation routine
 *   D3 put back to an earlier value by its restore
 *   D4 the same slug as another object (found by the scanner, across objects)
 *   D5 cut to fit "-2", "-3"… into 200 bytes when the post was made over REST
 *   D7 any of these on a term
 *
 * @package Parich_Long_Slug
 */

if (!defined('ABSPATH')) {
	exit;
}

class Parich_Slug_Repair_Detectors
{
	/** Length of core's slug columns, and of slugs cut to fit them. */
	const CUT = 200;

	/** Post statuses and taxonomies whose slugs its deactivation routine rewrote. */
	const M1_STATUSES = array('publish', 'draft', 'pending', 'private', 'future', 'trash');
	const M1_TAXONOMIES = array('category', 'post_tag', 'product_tag', 'product_cat');

	/** Statuses whose posts have no slug until they are published. */
	const UNSLUGGED_STATUSES = array('draft', 'pending', 'auto-draft');

	public static function has_partial_escape($slug)
	{
		return 1 === preg_match('/%[0-9a-fA-F]?$/', (string) $slug) || !Parich_Slug_Repair_Util::decodes_cleanly($slug);
	}

	/**
	 * Exactly 200 characters, or ends in the middle of a character.
	 */
	public static function looks_cut($slug)
	{
		$slug = (string) $slug;
		return '' !== $slug && (self::CUT === strlen($slug) || self::has_partial_escape($slug));
	}

	/**
	 * Whether $value is $slug made longer (what a cut slug was before the cut).
	 */
	public static function extends_slug($value, $slug)
	{
		return strlen($value) > strlen($slug) && 0 === strpos($value, $slug);
	}

	/**
	 * Name core gives a trashed post: the slug cut to 191 bytes plus "__trashed".
	 */
	public static function trashed_form($slug)
	{
		if ('__trashed' === substr($slug, -9)) {
			return $slug;
		}
		$cut = function_exists('_truncate_post_slug') ? _truncate_post_slug($slug, 191) : Parich_Slug_Sanitize::truncate_encoded($slug, 191);
		return $cut . '__trashed';
	}

	/**
	 * Slugs a title gave over time: Long URL Maker's while it was active, and core's.
	 *
	 * @param string[] $titles Current title first, then older ones (revisions).
	 * @return array[] {value, title} without duplicates or empty values.
	 */
	public static function title_slugs(array $titles)
	{
		$slugs = array();
		foreach ($titles as $title) {
			$made = array(
				Parich_Slug_Legacy_Sanitize::legacy_new_save($title),
				sanitize_title_with_dashes(remove_accents($title), '', 'save'),
			);
			foreach ($made as $value) {
				if ('' !== $value && !isset($slugs[$value])) {
					$slugs[$value] = array('value' => $value, 'title' => $title);
				}
			}
		}
		return array_values($slugs);
	}

	/**
	 * Strong evidence tied to this object that names another slug (or the same one).
	 *
	 * @return array {other: bool, same: bool}
	 */
	private static function strong_votes(array $object, array $facts)
	{
		$votes = array('other' => false, 'same' => false);
		$values = array();
		$latest = Parich_Slug_Repair_Evidence::s1_latest($facts);
		if (null !== $latest && !in_array($latest, $facts['old_slugs'], true)) {
			$values[] = $latest;
		}
		foreach ($facts['seo'] as $seo) {
			if (!$seo['truncated'] && !in_array($seo['value'], $facts['old_slugs'], true)) {
				$values[] = $seo['value'];
			}
		}
		if (null !== $facts['csv']) {
			$values[] = $facts['csv'];
		}
		foreach ($facts['desired'] as $desired) {
			$values[] = 'trash' === $object['status'] ? self::trashed_form($desired) : $desired;
		}
		foreach ($values as $value) {
			$votes[$value === $object['slug'] ? 'same' : 'other'] = true;
		}
		return $votes;
	}

	/**
	 * @param array $object {type, id, subtype, status, parent, title, slug, hierarchical}
	 * @param array $facts  From Parich_Slug_Repair_Evidence::load().
	 * @return array {codes: string[], d2_by: title|evidence|empty|null, d5: {stem, suffix, values}|null, notes: string[]}
	 */
	public static function detect(array $object, array $facts)
	{
		$slug = (string) $object['slug'];
		$is_post = 'post' === $object['type'];
		$codes = array();
		$notes = array();
		$d2_by = null;
		$d5 = null;

		if ($is_post && 'trash' === $object['status']) {
			return self::detect_trashed($object, $facts);
		}

		// D5 first: "-2" after a stem cut to fit makes exactly 200 bytes too, but the slug
		// itself is no start of a longer one.
		if ($is_post && preg_match('/^(.+)-(\d+)$/', $slug, $match) && strlen($slug) >= self::CUT - 5 && strlen($slug) <= self::CUT && !self::has_partial_escape($slug)) {
			$values = array();
			foreach (self::title_slugs($facts['titles']) as $made) {
				if (self::extends_slug($made['value'], $match[1]) && 0 !== strpos($made['value'], $slug)) {
					$values[] = $made['value'];
				}
			}
			if ($values) {
				$codes[] = 'D5';
				$d5 = array('stem' => $match[1], 'suffix' => $match[2], 'values' => $values);
			}
		}

		if (!$d5 && self::looks_cut($slug)) {
			$codes[] = 'D1';
		}

		$rewritten = $is_post ? in_array($object['status'], self::M1_STATUSES, true) : in_array($object['subtype'], self::M1_TAXONOMIES, true);
		if ($rewritten) {
			$votes = self::strong_votes($object, $facts);
			foreach ($facts['titles'] as $title) {
				$old = Parich_Slug_Legacy_Sanitize::legacy_old($title);
				if ($slug !== $old) {
					continue;
				}
				if ('' !== $old && $old !== Parich_Slug_Legacy_Sanitize::legacy_new_save($title)) {
					$d2_by = 'title';
				} elseif ($votes['other']) {
					$d2_by = 'evidence';
				}
				break;
			}
			if (null === $d2_by && '' === $slug && $is_post && !in_array($object['status'], self::UNSLUGGED_STATUSES, true)) {
				$d2_by = 'empty';
			}
			if (null !== $d2_by) {
				$codes[] = 'D2';
			}
		}

		if ($is_post && '' !== $slug && in_array($slug, $facts['old_slugs'], true)) {
			$codes[] = 'D3';
			$notes[] = 'own_old_slug';
		}
		if (!array_intersect($codes, array('D1', 'D2', 'D3'))) {
			foreach ($facts['seo'] as $seo) {
				if (!$seo['truncated'] && $seo['value'] !== $slug && !in_array($seo['value'], $facts['old_slugs'], true)) {
					$codes[] = 'D3';
					$notes[] = 'seo_differs';
					break;
				}
			}
		}

		if (!$is_post && $codes) {
			$codes[] = 'D7';
		}
		return array('codes' => array_values(array_unique($codes)), 'd2_by' => $d2_by, 'd5' => $d5, 'notes' => $notes);
	}

	/**
	 * A trashed post should be named like core names it. Long URL Maker's restore put
	 * the backed-up name back (D3), its deactivation routine a slug from the title (D2).
	 */
	private static function detect_trashed(array $object, array $facts)
	{
		$slug = (string) $object['slug'];
		$none = array('codes' => array(), 'd2_by' => null, 'd5' => null, 'notes' => array());
		if ('__trashed' === substr($slug, -9)) {
			return $none;
		}
		$codes = array();
		foreach ($facts['titles'] as $title) {
			if ($slug === Parich_Slug_Legacy_Sanitize::legacy_old($title)) {
				$codes[] = 'D2';
				break;
			}
		}
		if (!$codes) {
			$codes[] = 'D3';
		}
		if (self::looks_cut($slug)) {
			$codes[] = 'D1';
		}
		return array('codes' => $codes, 'd2_by' => in_array('D2', $codes, true) ? 'title' : null, 'd5' => null, 'notes' => array('trashed_name_lost'));
	}
}
