<?php
/**
 * Turns the evidence about one damaged object into candidate slugs and a group:
 *
 *   auto        one strong source, or two independent medium ones, agree; nothing
 *               strong says otherwise; the value is valid and taken by no one
 *   review      a value, but not certain enough to pre-select (D3, D5, conflicts…)
 *   unresolved  no value at all: the admin has to type one
 *   ok          no damage after all
 *
 * The aim is that "auto" is never wrong: anything doubtful goes to "review".
 *
 * @package Parich_Long_Slug
 */

if (!defined('ABSPATH')) {
	exit;
}

class Parich_Slug_Repair_Scorer
{
	const STRONG = 3;
	const MEDIUM = 2;
	const LOW = 1;

	/** How much each source counts. Sources with the same number are one source. */
	const STRENGTH = array(
		'S1' => self::STRONG,  // Long URL Maker's backup rows
		'S3' => self::STRONG,  // _wp_desired_post_slug
		'S4' => self::STRONG,  // SEO plugin tables
		'S9C' => self::STRONG, // imported CSV
		'S5' => self::MEDIUM,  // 404 logs
		'S6' => self::MEDIUM,  // existing redirects
		'S7' => self::MEDIUM,  // links
		'S8' => self::MEDIUM,  // slugs made from titles
		'S9U' => self::MEDIUM, // imported URL lists
		'S10' => self::LOW,    // guid
	);

	/** Shortest slug whose URLs are looked up by prefix (about 11 Thai characters). */
	const MIN_URL_PREFIX = 100;

	/**
	 * @param array         $object     See Parich_Slug_Repair_Detectors::detect().
	 * @param array         $facts      From Parich_Slug_Repair_Evidence::load().
	 * @param array         $signs      From Parich_Slug_Repair_Detectors::detect(); codes may include D4.
	 * @param callable|null $url_lookup function (string $prefix): object[] rows of the URL index.
	 * @return array Item: {object, codes, d2_by, notes, candidates, supports, current_votes, invalid, flags, pinned, proposal, group, reasons}
	 */
	public static function analyze(array $object, array $facts, array $signs, $url_lookup = null)
	{
		$slug = (string) $object['slug'];
		$codes = $signs['codes'];
		$item = array(
			'object' => $object,
			'codes' => $codes,
			'd2_by' => $signs['d2_by'],
			'notes' => $signs['notes'],
			'candidates' => array(),
			'supports' => array(),
			'current_votes' => array(),
			'invalid' => array(),
			'flags' => array(),
			'pinned' => null,
			'proposal' => null,
			'group' => 'ok',
			'reasons' => array(),
		);
		if (!$codes) {
			return $item;
		}

		$is_trash = 'post' === $object['type'] && 'trash' === $object['status'];
		$has = function ($code) use ($codes) {
			return in_array($code, $codes, true);
		};
		$latest = Parich_Slug_Repair_Evidence::s1_latest($facts);

		// Saved again (while Long URL Maker was active) after its deactivation routine rewrote it: accepted.
		if ($has('D2') && !$has('D1') && !$has('D4') && null !== $latest && $latest === $slug && !in_array($latest, $facts['old_slugs'], true)) {
			$item['notes'][] = 'confirmed_after_damage';
			return $item;
		}

		// A cut slug can only have been something longer that starts with it.
		$require_extend = $has('D1') && !$has('D2') && !$has('D3') && !$is_trash;
		$unslugged = 'post' === $object['type'] && in_array($object['status'], Parich_Slug_Repair_Detectors::UNSLUGGED_STATUSES, true);

		$add = function ($value, $source, $detail, $kind = 'full', array $extra = array()) use (&$item, $slug, $is_trash, $require_extend, $unslugged) {
			$value = (string) $value;
			$strength = isset($extra['strength']) ? $extra['strength'] : self::STRENGTH[$source];
			if ($is_trash && 'full' === $kind && '' !== $value) {
				$value = Parich_Slug_Repair_Detectors::trashed_form($value);
			}
			if ('prefix' === $kind) {
				if (strlen($value) > strlen($slug) || !$require_extend) {
					$item['supports'][] = array('value' => $value, 'source' => $source, 'detail' => $detail) + array_intersect_key($extra, array('path' => 1));
				}
				return;
			}
			if ($value === $slug) {
				if (self::STRONG === $strength) {
					$item['current_votes'][] = array('source' => $source, 'detail' => $detail);
				}
				return;
			}
			// Admins' CSV rows are taken as they are; everything else must fit the damage.
			if ($require_extend && 'S9C' !== $source && !Parich_Slug_Repair_Detectors::extends_slug($value, $slug)) {
				return;
			}
			if (!Parich_Slug_Repair_Util::is_valid($value) && !('' === $value && $unslugged)) {
				$item['invalid'][] = array('value' => $value, 'source' => $source, 'detail' => $detail);
				return;
			}
			if (!isset($item['candidates'][$value])) {
				$item['candidates'][$value] = array('value' => $value, 'entries' => array());
			}
			$item['candidates'][$value]['entries'][] = array(
				'source' => $source,
				'detail' => $detail,
				'strength' => $strength,
				'stale' => !empty($extra['stale']),
				'url' => !empty($extra['url']),
				'ambiguous' => false,
			) + array_intersect_key($extra, array('path' => 1, 'hits' => 1));
		};

		// S1: the newest backup row; for a cut slug, the newest row it was cut from.
		if ($facts['s1']) {
			$row = null;
			if ($require_extend) {
				foreach (array_reverse($facts['s1']) as $candidate) {
					if (Parich_Slug_Repair_Detectors::extends_slug($candidate['value'], $slug)) {
						$row = $candidate;
						break;
					}
				}
				if ($latest === $slug) {
					$item['current_votes'][] = array('source' => 'S1', 'detail' => 'kpcode_url_posts');
				}
			} else {
				$row = $facts['s1'][count($facts['s1']) - 1];
			}
			if ($row) {
				// A backed-up slug that core later recorded as an old slug was changed by a save
				// Long URL Maker never saw (block editor, REST): its backup is out of date.
				$stale = in_array($row['value'], $facts['old_slugs'], true);
				if ($stale) {
					$item['flags'][] = 'stale_backup';
				}
				$add($row['value'], 'S1', 'kpcode_url_posts #' . $row['row'], 'full', array('stale' => $stale, 'strength' => $stale ? self::LOW : self::STRONG));
			}
		}

		if ($is_trash) {
			foreach ($facts['desired'] as $desired) {
				$add($desired, 'S3', '_wp_desired_post_slug');
			}
		}

		foreach ($facts['seo'] as $seo) {
			$stale = in_array($seo['value'], $facts['old_slugs'], true);
			$add($seo['value'], 'S4', $seo['source'], $seo['truncated'] ? 'prefix' : 'full', array('stale' => $stale, 'strength' => $stale ? self::LOW : self::STRONG, 'path' => $seo['path']));
		}

		if (null !== $facts['csv']) {
			$add($facts['csv'], 'S9C', 'import');
		}

		if ($has('D5') && $signs['d5']) {
			// Made over REST while the slug was taken: core cut it to fit "-N" in 200 bytes.
			$suffix = '-' . $signs['d5']['suffix'];
			foreach ($signs['d5']['values'] as $value) {
				$add(Parich_Slug_Sanitize::truncate_encoded($value, Parich_Slug_Sanitize::HARD_MAX - strlen($suffix)) . $suffix, 'S8', 'title');
			}
		} else {
			$current_title = isset($facts['titles'][0]) ? $facts['titles'][0] : '';
			foreach (Parich_Slug_Repair_Detectors::title_slugs($facts['titles']) as $made) {
				$add($made['value'], 'S8', $made['title'] === $current_title ? 'title' : 'revision');
			}
		}

		if (null !== $facts['guid']) {
			$add($facts['guid']['value'], 'S10', 'guid', 'full', array('path' => $facts['guid']['path']));
		}

		// URLs: only a slug that is the start of the real one (cut, or rewritten from the same title) can be matched.
		if ($url_lookup && !$is_trash && ($has('D1') || $has('D2')) && strlen($slug) >= self::MIN_URL_PREFIX) {
			foreach (call_user_func($url_lookup, $slug) as $row) {
				$add($row->last_segment, $row->source, $row->detail, $row->truncated ? 'prefix' : 'full', array('url' => true, 'path' => $row->url_path, 'hits' => (int) $row->hits));
			}
		}

		$item['flags'] = array_values(array_unique($item['flags']));
		$item['candidates'] = array_values($item['candidates']);
		return self::decide($item);
	}

	/**
	 * Strength of the evidence for each candidate.
	 *
	 * @return array value => {strong, groups, medium, best, supports}
	 */
	public static function weigh(array $item)
	{
		$slug = (string) $item['object']['slug'];
		$weights = array();
		foreach ($item['candidates'] as $candidate) {
			$strong = false;
			$groups = array();
			$medium = array();
			$best = 0;
			foreach ($candidate['entries'] as $entry) {
				$strength = $entry['ambiguous'] || $entry['stale'] ? self::LOW : $entry['strength'];
				$best = max($best, $strength);
				if (self::STRONG === $strength) {
					$strong = true;
				}
				if ($strength >= self::MEDIUM) {
					$groups[$entry['source']] = true;
				}
				if (self::MEDIUM === $strength) {
					$medium[$entry['source']] = true;
				}
			}
			$supports = array();
			foreach ($item['supports'] as $support) {
				if (strlen($support['value']) > strlen($slug) && 0 === strpos($candidate['value'], $support['value'])) {
					$supports[$support['source']] = true;
				}
			}
			$weights[$candidate['value']] = array(
				'strong' => $strong,
				'groups' => count($groups),
				'medium' => count($medium),
				'best' => $best,
				'supports' => count($supports),
			);
		}
		return $weights;
	}

	/**
	 * Candidate values, best first.
	 *
	 * @return string[]
	 */
	public static function ranked(array $item)
	{
		$weights = self::weigh($item);
		$values = array_keys($weights);
		usort($values, function ($a, $b) use ($weights) {
			$wa = $weights[$a];
			$wb = $weights[$b];
			foreach (array('strong', 'groups', 'best', 'supports') as $key) {
				if ($wa[$key] != $wb[$key]) {
					return $wa[$key] < $wb[$key] ? 1 : -1;
				}
			}
			return strcmp((string) $a, (string) $b);
		});
		return array_map('strval', $values);
	}

	/**
	 * Picks the proposal and the group. Called again by the scanner after it has
	 * looked across objects (ambiguous URLs, contested values, collisions).
	 */
	public static function decide(array $item)
	{
		$item['reasons'] = array();
		$codes = $item['codes'];
		if (!$codes) {
			$item['group'] = 'ok';
			$item['proposal'] = null;
			return $item;
		}
		$ranked = self::ranked($item);
		if (!$ranked) {
			$item['group'] = 'unresolved';
			$item['proposal'] = null;
			if (in_array('report_only', $item['flags'], true)) {
				$item['reasons'][] = 'report_only';
			}
			return $item;
		}
		$weights = self::weigh($item);
		$proposal = null !== $item['pinned'] && isset($weights[$item['pinned']]) ? $item['pinned'] : $ranked[0];
		$top = $weights[$proposal];
		$object = $item['object'];
		$reasons = array();

		foreach (array('D3' => 'd3', 'D5' => 'd5') as $code => $reason) {
			if (in_array($code, $codes, true)) {
				$reasons[] = $reason;
			}
		}
		// D4 counts only for objects that keep the shared slug; the others move to their own.
		if (in_array('d4_staying', $item['flags'], true)) {
			$reasons[] = 'd4';
		}
		if (count(array_filter($weights, function ($weight) {
			return $weight['strong'];
		})) > 1) {
			$reasons[] = 'conflict';
		}
		if ($item['current_votes']) {
			$reasons[] = 'current_confirmed';
		}
		if (!$top['strong'] && $top['medium'] < 2) {
			$reasons[] = 'weak';
		}
		if ('post' === $object['type'] && in_array($object['status'], Parich_Slug_Repair_Detectors::UNSLUGGED_STATUSES, true)) {
			$reasons[] = 'unslugged';
		}
		if (in_array('stale_backup', $item['flags'], true)) {
			$reasons[] = 'stale_backup';
		}
		// Core keeps no record of slug changes on pages and other hierarchical types, so
		// a lone backup row that differs may be out of date without any sign of it.
		if (!empty($object['hierarchical']) && 'evidence' === $item['d2_by'] && 1 === $top['groups'] && 0 === $top['medium']) {
			$reasons[] = 'unchecked_history';
		}
		foreach (array('contested', 'collision', 'shared_theme') as $flag) {
			if (in_array($flag, $item['flags'], true)) {
				$reasons[] = $flag;
			}
		}
		// System keys (D6): some types are always checked by hand, ACF fields need two sources.
		if (in_array('always_review', $item['flags'], true)) {
			$reasons[] = 'system_check';
		}
		if (in_array('needs_two', $item['flags'], true) && $top['groups'] < 2) {
			$reasons[] = 'weak';
		}
		if (in_array('tie', $item['flags'], true)) {
			$reasons[] = 'conflict';
		}

		$item['proposal'] = $proposal;
		$item['reasons'] = array_values(array_unique($reasons));
		$item['group'] = $item['reasons'] ? 'review' : 'auto';
		return $item;
	}

	/**
	 * Marks URL evidence for $values as ambiguous (claimed by several objects).
	 */
	public static function mark_ambiguous(array $item, array $values)
	{
		foreach ($item['candidates'] as &$candidate) {
			if (isset($values[$candidate['value']])) {
				foreach ($candidate['entries'] as &$entry) {
					if ($entry['url']) {
						$entry['ambiguous'] = true;
					}
				}
				unset($entry);
			}
		}
		unset($candidate);
		return $item;
	}

	/**
	 * Values from URLs not tied to an object (to find URLs several objects claim).
	 *
	 * @return string[]
	 */
	public static function url_values(array $item)
	{
		$values = array();
		foreach ($item['candidates'] as $candidate) {
			foreach ($candidate['entries'] as $entry) {
				if ($entry['url']) {
					$values[] = $candidate['value'];
					break;
				}
			}
		}
		return $values;
	}
}
