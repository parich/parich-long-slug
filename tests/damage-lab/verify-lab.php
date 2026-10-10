<?php
/**
 * Checks a loaded damage lab snapshot against its ground truth: every kind of damage
 * the scenario should have happened to at least 3 lab objects, each lab object shows
 * the damage expected for its group, and nothing else.
 *
 *   wp eval-file verify-lab.php <prefix> <truth.json> <A|B|A-no-d3>
 *
 * Signatures (read from the slug alone, as a repair tool would):
 *   D1 the slug is exactly the first 200 characters of the clean slug
 *   D2 the slug is what the deactivation routine writes: legacy_old(title)
 *   D3 the slug is one the object had before (_wp_old_slug), not the clean one
 *   D4 another object of the same type/parent (or taxonomy) has the same slug
 *   D5 the clean slug itself ends in -N and was cut to fit 200 bytes when it was made
 *   D6 a post whose post_name is a key (ACF, template, global styles) changed
 *   D7 a term slug changed
 *   X  the slug changed in some other way
 *
 * @package Parich_Long_Slug
 */

if (!defined('WP_CLI')) {
	exit;
}

list($prefix, $truth_file, $scenario) = array_pad($args, 3, '');
if (!preg_match('/^plslab_[a-z0-9_]*_$/', $prefix) || !is_readable($truth_file) || !in_array($scenario, array('A', 'B', 'A-no-d3'), true)) {
	WP_CLI::error('Usage: wp eval-file verify-lab.php <plslab_x_> <truth.json> <A|B|A-no-d3>');
}

global $wpdb;
$truth = json_decode((string) file_get_contents($truth_file), true);
$system = array('acf-field', 'acf-field-group', 'wp_global_styles', 'wp_template', 'wp_template_part', 'wp_font_family');

// What each lab group should show, per scenario.
$expected = array(
	'A' => array(
		'rest' => array('D1'), 'cpt' => array('D1'),
		// Never backed up: wp_insert_post() skips save_post for attachments, and its
		// activation backup only takes publish/draft/pending/private/future/trash.
		'attach' => array('D1'), 'attach-backup' => array('D1'),
		'd3x' => array('D3'), 'd4y' => array('D4'), 'd5' => array(),
		// Its restore writes the backed-up name over a trashed post's "__trashed" name.
		'trash' => array('X'),
	),
	'B' => array(
		'form' => array('D2'), 'quotes' => array('D2'), 'retitled' => array('D2'), 'rest' => array('D2'), 'd3x' => array('D2'), 'd4y' => array('D2'),
		'cpt' => array('D2'), 'page' => array('D2'), 'custom' => array('D2'), 'empty' => array('D2'), 'trash' => array('D2'), 'links' => array('D2'), 'late' => array('D2'),
		'd5' => array('D2', 'D4'), 'system' => array('D6', 'D2'), 'attach' => array('D1'), 'attach-backup' => array('D1'),
		'term-cat' => array('D7', 'D2'), 'term-tag' => array('D7', 'D2'), 'term-custom' => array('D7', 'D1'),
	),
);
// Negative control: neither the version-change restore nor the reactivation restore ran.
$expected['A-no-d3'] = array();
$need = array('A' => array('D1', 'D3', 'D4', 'D5'), 'B' => array('D1', 'D2', 'D4', 'D6', 'D7'), 'A-no-d3' => array('D1', 'D5'));

$posts = $wpdb->get_results("SELECT ID, post_name, post_type, post_parent, post_status FROM `{$prefix}posts` WHERE post_type <> 'revision' AND post_status <> 'auto-draft'", OBJECT_K);
$terms = $wpdb->get_results("SELECT t.term_id, t.slug, tt.taxonomy, tt.parent FROM `{$prefix}terms` t JOIN `{$prefix}term_taxonomy` tt ON tt.term_id = t.term_id", OBJECT_K);
$old_slugs = array();
foreach ($wpdb->get_results("SELECT post_id, meta_value FROM `{$prefix}postmeta` WHERE meta_key = '_wp_old_slug'") as $row) {
	$old_slugs[(int) $row->post_id][] = $row->meta_value;
}
if (!$posts || !$terms) {
	WP_CLI::error("No posts or terms under prefix {$prefix}: load the snapshot first.");
}

// Same slug twice where WordPress needs it unique (empty slugs are D2's business).
$seen = array();
foreach ($posts as $post) {
	if ('' !== $post->post_name && 'attachment' !== $post->post_type) {
		$seen['post'][$post->post_type . '|' . $post->post_parent . '|' . $post->post_name][] = (int) $post->ID;
	}
}
foreach ($terms as $term) {
	$seen['term'][$term->taxonomy . '|' . $term->slug][] = (int) $term->term_id;
}
$duplicates = array('post' => array(), 'term' => array());
foreach ($seen as $type => $groups) {
	foreach ($groups as $ids) {
		if (count($ids) > 1) {
			foreach ($ids as $id) {
				$duplicates[$type][$id] = true;
			}
		}
	}
}

// Slugs the deactivation routine writes (posts in these statuses, terms in these taxonomies),
// grouped the way WordPress needs them unique.
$m1_key = array();
$m1_count = array();
foreach ($truth['objects'] as $object) {
	$rewritten = 'post' === $object['type']
		? in_array($object['status'], array('publish', 'draft', 'pending', 'private', 'future', 'trash'), true)
		: in_array($object['subtype'], array('category', 'post_tag', 'product_tag', 'product_cat'), true);
	$slug = Parich_Slug_Legacy_Sanitize::legacy_old($object['title']);
	if ($rewritten && '' !== $slug) {
		$key = 'post' === $object['type'] ? "post|{$object['subtype']}|{$object['parent']}|{$slug}" : "term|{$object['subtype']}|{$slug}";
		$m1_key[$object['type'] . ':' . $object['id']] = $key;
		$m1_count[$key] = ($m1_count[$key] ?? 0) + 1;
	}
}

$found = array();
$wrong = array();
$damaged_existing = 0;
foreach ($truth['objects'] as $object) {
	$row = 'post' === $object['type'] ? ($posts[$object['id']] ?? null) : ($terms[$object['id']] ?? null);
	if (!$row) {
		continue;
	}
	$current = 'post' === $object['type'] ? $row->post_name : $row->slug;
	$clean = $object['clean_slug'];
	$signs = array();
	if ($current !== $clean) {
		if (200 === strlen($current) && 0 === strpos($clean, $current)) {
			$signs[] = 'D1';
		}
		if ($current === Parich_Slug_Legacy_Sanitize::legacy_old($object['title'])) {
			$signs[] = 'D2';
		}
		if (in_array($current, array_merge($object['old_slugs'], $old_slugs[$object['id']] ?? array()), true) && 'post' === $object['type']) {
			$signs[] = 'D3';
		}
		if ('post' === $object['type'] && in_array($object['subtype'], $system, true)) {
			$signs[] = 'D6';
		}
		if ('term' === $object['type']) {
			$signs[] = 'D7';
		}
		if (!array_intersect($signs, array('D1', 'D2', 'D3', 'D6', 'D7'))) {
			$signs[] = 'X';
		}
	}
	if (!empty($duplicates[$object['type']][$object['id']])) {
		$signs[] = 'D4';
	}
	if (preg_match('/-\d+$/', $clean) && strlen($clean) >= 190 && strlen($clean) <= 200 && strlen(Parich_Slug_Legacy_Sanitize::legacy_new_save($object['title'])) > 200) {
		$signs[] = 'D5';
	}

	if ('existing' === $object['group']) {
		$damaged_existing += $signs ? 1 : 0;
		continue;
	}
	foreach ($signs as $sign) {
		$found[$sign][$object['group']][] = $object['id'];
	}
	$want = $expected[$scenario][$object['group']] ?? array();
	// The d3x posts whose old slug was taken by a d4y post are duplicates as well.
	if ('A' === $scenario && 'd3x' === $object['group'] && in_array('D4', $signs, true)) {
		$want[] = 'D4';
	}
	if ('d5' === $object['group']) {
		// Lab titles end in a number too; only a slug cut to fit 200 bytes has a "-N" suffix.
		if (preg_match('/-\d+$/', $clean) && strlen($clean) <= 200) {
			$want[] = 'D5'; // "-2"… cut to 200 bytes when made: true in every snapshot.
		} elseif ('B' !== $scenario) {
			$want = array('D1'); // The first one has no suffix and is cut like any REST post.
		}
	}
	// In B, slugs rewritten from titles collide whenever two titles share their first
	// ~22 Thai characters (200 bytes): D4 is expected exactly where that happens.
	if ('B' === $scenario && isset($m1_key[$object['type'] . ':' . $object['id']]) && $m1_count[$m1_key[$object['type'] . ':' . $object['id']]] > 1) {
		$want[] = 'D4';
	}
	// The deactivation routine changes nothing when the title already gives the clean slug.
	if (Parich_Slug_Legacy_Sanitize::legacy_old($object['title']) === $clean) {
		$want = array_values(array_diff($want, array('D2', 'D6', 'D7')));
	}
	// A cut at exactly 200 bytes can match both D1 and D2.
	$tolerated = array();
	if (in_array('D1', $want, true)) {
		$tolerated[] = 'D2';
	}
	if (in_array('D2', $want, true)) {
		$tolerated[] = 'D1';
	}
	$missing = array_diff($want, $signs);
	$extra = array_diff($signs, $want, $tolerated);
	// The negative control only has to show that D3 does not appear.
	if ('A-no-d3' !== $scenario && ($missing || $extra)) {
		$wrong[] = "{$object['group']} #{$object['id']}: expected " . (implode('+', $want) ?: 'none') . ', saw ' . (implode('+', $signs) ?: 'none') . ' (' . substr(urldecode($current), 0, 40) . ')';
	}
}

$counts = array();
foreach (array('D1', 'D2', 'D3', 'D4', 'D5', 'D6', 'D7') as $sign) {
	$counts[$sign] = isset($found[$sign]) ? array_sum(array_map('count', $found[$sign])) : 0;
}
WP_CLI::log("Scenario {$scenario}: lab objects per damage — " . implode(', ', array_map(function ($sign) use ($counts) {
	return "{$sign} {$counts[$sign]}";
}, array_keys($counts))));
WP_CLI::log("Existing (non-lab) objects with damage: {$damaged_existing}");
foreach ($found as $sign => $groups) {
	WP_CLI::log("  {$sign}: " . implode(', ', array_map(function ($group, $ids) {
		return "{$group} (" . count($ids) . ')';
	}, array_keys($groups), $groups)));
}

$problems = array();
foreach ($need[$scenario] as $sign) {
	if ($counts[$sign] < 3) {
		$problems[] = "{$sign} on {$counts[$sign]} lab objects (need 3)";
	}
}
if ('A-no-d3' === $scenario && $counts['D3'] > 0) {
	$problems[] = "D3 on {$counts['D3']} objects although the restore was never armed";
}
foreach ($wrong as $line) {
	$problems[] = $line;
}
if ($problems) {
	WP_CLI::error("Lab check failed:\n  " . implode("\n  ", $problems));
}
WP_CLI::success("Scenario {$scenario}: every expected kind of damage happened, and only where expected.");
