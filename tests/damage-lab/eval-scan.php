<?php
/**
 * Runs the repair scan on a loaded damage lab snapshot and measures it against the
 * ground truth:
 *   - precision of "auto": every pre-selected proposal is the right slug (must be 100%)
 *   - recall: damaged objects whose proposal is right, per kind of damage, and how
 *     many of those are "auto"
 *   - undamaged objects are "ok" (except D5, cut when made: offered for review on purpose)
 *
 *   wp eval-file eval-scan.php <prefix> <truth.json> <scenario> [list]
 *
 * The scan writes to this plugin's scan tables only; they are emptied afterwards.
 *
 * @package Parich_Long_Slug
 */

if (!defined('WP_CLI')) {
	exit;
}

$positional = array_values(array_filter($args, function ($arg) {
	return 'list' !== $arg;
}));
list($prefix, $truth_file, $scenario) = array_pad($positional, 3, '');
$list = in_array("list", $args, true);
if (!preg_match('/^plslab_[a-z0-9_]*_$/', $prefix) || !is_readable($truth_file)) {
	WP_CLI::error('Usage: wp eval-file eval-scan.php <plslab_x_> <truth.json> <scenario> [list]');
}

// The lab's own post type and taxonomy, registered as they were when it ran.
register_post_type('pls_lab_cpt', array('public' => true, 'label' => 'Lab CPT'));
register_taxonomy('pls_lab_tax', array('post'), array('public' => true, 'label' => 'Lab tax'));

global $wpdb;
$imports = Parich_Slug_Repair_Scanner::import_counts();
if ($imports['urls'] || $imports['csv']) {
	WP_CLI::error('This site has imported URLs or CSV rows for the repair tool; they would mix with the lab. Clear them first.');
}
$state_before = get_option(Parich_Slug_Repair_Scanner::STATE_OPTION);

/**
 * Hash of every row of every table of the snapshot (read-only check), read in PHP.
 */
function pls_lab_tables_hash($prefix)
{
	global $wpdb;
	$hashes = array();
	foreach ($wpdb->get_col($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($prefix) . '%')) as $table) {
		$hashes[$table] = md5(serialize($wpdb->get_results("SELECT * FROM `{$table}` ORDER BY 1", ARRAY_N)));
	}
	ksort($hashes);
	return $hashes;
}
$hashes_before = pls_lab_tables_hash($prefix);
$writes = array();
add_filter('query', function ($query) use (&$writes) {
	if (preg_match('/^\s*(INSERT|UPDATE|DELETE|REPLACE|ALTER|CREATE|DROP|TRUNCATE|RENAME)\b/i', $query)) {
		$writes[] = $query;
	}
	return $query;
});

$started = microtime(true);
$steps = 0;
$state = Parich_Slug_Repair_Scanner::run($prefix, function () use (&$steps) {
	++$steps;
});
if (is_wp_error($state)) {
	WP_CLI::error($state->get_error_message());
}
$seconds = microtime(true) - $started;
$foreign = array_values(array_filter($writes, function ($query) {
	return false === strpos($query, 'parich_slug_scan') && false === strpos($query, 'parich_slug_repair_db_version');
}));
$hashes_after = pls_lab_tables_hash($prefix);
$results = Parich_Slug_Repair_Scanner::results($state['id']);
$items = array();
foreach ($results['items'] as $item) {
	$items[$item['object']['type'] . ':' . $item['object']['id']] = $item;
}

$truth = json_decode((string) file_get_contents($truth_file), true);
$current = array();
foreach ($wpdb->get_results("SELECT ID, post_name FROM `{$prefix}posts`") as $row) {
	$current['post:' . $row->ID] = $row->post_name;
}
foreach ($wpdb->get_results("SELECT term_id, slug FROM `{$prefix}terms`") as $row) {
	$current['term:' . $row->term_id] = $row->slug;
}
$scanned_types = array_merge(Parich_Slug_Repair_Scanner::post_types(), Parich_Slug_Repair_System_Keys::post_types());
$scanned_taxonomies = Parich_Slug_Repair_Scanner::taxonomies();

$stats = array('auto' => 0, 'auto_right' => 0, 'damaged' => 0, 'right' => 0, 'right_auto' => 0, 'clean' => 0, 'clean_ok' => 0, 'by_design' => 0);
$per_code = array();
$problems = array();
$lines = array();
foreach ($truth['objects'] as $object) {
	$key = $object['type'] . ':' . $object['id'];
	if (!isset($current[$key])) {
		continue;
	}
	$scanned = 'post' === $object['type'] ? in_array($object['subtype'], $scanned_types, true) : in_array($object['subtype'], $scanned_taxonomies, true);
	if (!$scanned) {
		continue; // Found by ID (menus, reusable blocks…): not checked.
	}
	$now = $current[$key];
	$clean = $object['clean_slug'];
	$item = isset($items[$key]) ? $items[$key] : null;
	$group = $item ? $item['group'] : 'ok';
	$proposal = $item ? $item['proposal'] : null;
	$codes = $item ? $item['codes'] : array();
	$damaged = $now !== $clean;

	if ('auto' === $group) {
		++$stats['auto'];
		if ($proposal === $clean) {
			++$stats['auto_right'];
		} else {
			$problems[] = "WRONG AUTO {$key} ({$object['group']}): proposed " . substr(rawurldecode((string) $proposal), 0, 40) . ', truth ' . substr(rawurldecode($clean), 0, 40);
		}
	}
	if ($damaged) {
		++$stats['damaged'];
		$right = $proposal === $clean;
		$stats['right'] += $right ? 1 : 0;
		$stats['right_auto'] += $right && 'auto' === $group ? 1 : 0;
		foreach ($codes ? $codes : array('none') as $code) {
			if (!isset($per_code[$code])) {
				$per_code[$code] = array('damaged' => 0, 'right' => 0, 'auto' => 0);
			}
			++$per_code[$code]['damaged'];
			$per_code[$code]['right'] += $right ? 1 : 0;
			$per_code[$code]['auto'] += $right && 'auto' === $group ? 1 : 0;
		}
		if (!$item) {
			$problems[] = "MISSED {$key} ({$object['group']}): damaged but reported ok";
		}
	} else {
		++$stats['clean'];
		if ('ok' === $group) {
			++$stats['clean_ok'];
		} elseif (in_array('D5', $codes, true) && 'review' === $group) {
			++$stats['by_design']; // Cut to fit "-N" when it was made: offered, not pre-selected.
		} else {
			$problems[] = "FALSE ALARM {$key} ({$object['group']}): undamaged but {$group} " . implode('+', $codes);
		}
	}
	if ($list) {
		$lines[] = sprintf('%-13s %-10s %-10s %-11s %-30s %s', $object['group'], $key, implode('+', $codes) ?: '-', $group, implode(',', $item ? $item['reasons'] : array()), $damaged ? (null === $proposal ? 'no proposal' : ($proposal === $clean ? 'RIGHT' : 'other')) : 'clean');
	}
}

if ($list) {
	WP_CLI::log(implode("\n", $lines));
}
$changed = array_keys(array_diff_assoc($hashes_after, $hashes_before));
if ($changed || count($hashes_after) !== count($hashes_before)) {
	$problems[] = 'NOT READ-ONLY: tables changed: ' . implode(', ', $changed);
}
if ($foreign) {
	$problems[] = 'NOT READ-ONLY: writes outside the scan tables: ' . substr($foreign[0], 0, 120);
}
if (!$writes) {
	$problems[] = 'the write log saw no query at all (the check would not notice anything)';
}
$percent = function ($part, $whole) {
	return $whole ? sprintf('%d/%d (%.0f%%)', $part, $whole, 100 * $part / $whole) : '0/0';
};
WP_CLI::log("Scenario {$scenario}: {$state['objects']} objects scanned in {$steps} steps, " . sprintf('%.1f', $seconds) . ' s');
WP_CLI::log('  Precision of auto: ' . $percent($stats['auto_right'], $stats['auto']));
WP_CLI::log('  Recall (right proposal, any group): ' . $percent($stats['right'], $stats['damaged']) . '; right and auto: ' . $percent($stats['right_auto'], $stats['damaged']));
foreach ($per_code as $code => $counts) {
	WP_CLI::log("    {$code}: right " . $percent($counts['right'], $counts['damaged']) . ", auto {$counts['auto']}");
}
WP_CLI::log('  Undamaged reported ok: ' . $percent($stats['clean_ok'], $stats['clean']) . " (+{$stats['by_design']} D5 offered for review)");
WP_CLI::log('  Groups: ' . wp_json_encode($state['counts']));
WP_CLI::log('  Read-only: ' . count($hashes_after) . ' snapshot tables hashed before and after, ' . count($writes) . ' writes, ' . count($foreign) . ' outside the scan tables');
if ($state['warnings']) {
	WP_CLI::log('  Warnings: ' . wp_json_encode(array_values($state['warnings'])));
}
WP_CLI::log('PLS_EVAL_JSON ' . wp_json_encode(array('scenario' => $scenario, 'stats' => $stats, 'per_code' => $per_code, 'problems' => $problems, 'seconds' => $seconds, 'counts' => $state['counts'])));

// Leave no lab results in the site's repair tool.
$wpdb->query('DELETE FROM `' . Parich_Slug_Repair_Scanner::items_table() . '`');
$wpdb->query('DELETE FROM `' . Parich_Slug_Repair_Scanner::urls_table() . '` WHERE scan_id <> 0');
if (false === $state_before) {
	delete_option(Parich_Slug_Repair_Scanner::STATE_OPTION);
} else {
	update_option(Parich_Slug_Repair_Scanner::STATE_OPTION, $state_before, false);
}

if ($problems) {
	WP_CLI::error("Scan check failed:\n  " . implode("\n  ", $problems));
}
WP_CLI::success("Scenario {$scenario}: auto is never wrong, nothing damaged was missed, nothing undamaged was flagged.");
