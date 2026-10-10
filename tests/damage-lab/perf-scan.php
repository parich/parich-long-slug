<?php
/**
 * Performance of the repair scan on a large damaged site: copies the published posts
 * of a loaded snapshot until there are 5,000 posts (each copy with its own backup row,
 * so most are "auto"; slugs shared by many copies, so D4 clusters are large; the link
 * collection post copied too, so many items claim the same URLs), then times every
 * scan step and measures its memory.
 *
 *   wp eval-file perf-scan.php <prefix> [<posts>]     e.g. plslab_p_ 5000
 *
 * Limits checked: every step under 30 seconds and under 64 MB more memory.
 *
 * @package Parich_Long_Slug
 */

if (!defined('WP_CLI')) {
	exit;
}

list($prefix, $target) = array_pad($args, 2, '5000');
$target = (int) $target;
if (!preg_match('/^plslab_[a-z0-9_]*_$/', $prefix)) {
	WP_CLI::error('Usage: wp eval-file perf-scan.php <plslab_x_> [<posts>]');
}
register_post_type('pls_lab_cpt', array('public' => true, 'label' => 'Lab CPT'));
register_taxonomy('pls_lab_tax', array('post'), array('public' => true, 'label' => 'Lab tax'));

global $wpdb;
$posts = "{$prefix}posts";
$backup = "{$prefix}kpcode_url_posts";
$columns = $wpdb->get_col($wpdb->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s ORDER BY ORDINAL_POSITION', $posts));
$originals = $wpdb->get_col("SELECT ID FROM `{$posts}` WHERE post_type = 'post' AND post_status = 'publish' ORDER BY ID");
$step = (int) $wpdb->get_var("SELECT MAX(ID) FROM `{$posts}`") + 1;
$copy = 0;
while ((int) $wpdb->get_var("SELECT COUNT(*) FROM `{$posts}` WHERE post_type <> 'revision'") < $target) {
	++$copy;
	$offset = $step * $copy;
	$select = array();
	foreach ($columns as $column) {
		if ('ID' === $column) {
			$select[] = "ID + {$offset}";
		} elseif ('post_title' === $column) {
			$select[] = "CONCAT(post_title, ' {$copy}')";
		} else {
			$select[] = "`{$column}`";
		}
	}
	$in = implode(',', array_map('intval', $originals));
	$wpdb->query("INSERT INTO `{$posts}` (`" . implode('`,`', $columns) . '`) SELECT ' . implode(',', $select) . " FROM `{$posts}` WHERE ID IN ({$in})");
	// Each copy's backup row: the original's newest value with its own suffix (a unique slug).
	$wpdb->query("INSERT INTO `{$backup}` (kpcode_post_id, kpcode_post_name, kpcode_type) SELECT b.kpcode_post_id + {$offset}, CONCAT(LEFT(b.kpcode_post_name, 1800), '-c{$copy}'), 'posts' FROM `{$backup}` b JOIN (SELECT kpcode_post_id, MAX(id) AS id FROM `{$backup}` WHERE kpcode_type = 'posts' AND kpcode_post_id IN ({$in}) GROUP BY kpcode_post_id) m ON m.id = b.id");
}
$count = (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$posts}` WHERE post_type <> 'revision'");
WP_CLI::log("Posts in {$prefix}posts: {$count} ({$copy} copies of " . count($originals) . ' published posts)');

$state = Parich_Slug_Repair_Scanner::start($prefix);
if (is_wp_error($state)) {
	WP_CLI::error($state->get_error_message());
}
$worst = array();
$total = microtime(true);
$steps = 0;
while ('done' !== $state['step']) {
	$name = $state['step'];
	if (function_exists('memory_reset_peak_usage')) {
		memory_reset_peak_usage();
	}
	$base = memory_get_usage();
	$started = microtime(true);
	$state = Parich_Slug_Repair_Scanner::step();
	if (is_wp_error($state)) {
		WP_CLI::error($state->get_error_message());
	}
	$seconds = microtime(true) - $started;
	$memory = memory_get_peak_usage() - $base;
	++$steps;
	if (!isset($worst[$name])) {
		$worst[$name] = array('steps' => 0, 'seconds' => 0, 'memory' => 0);
	}
	++$worst[$name]['steps'];
	$worst[$name]['seconds'] = max($worst[$name]['seconds'], $seconds);
	$worst[$name]['memory'] = max($worst[$name]['memory'], $memory);
}
$total = microtime(true) - $total;
$problems = array();
foreach ($worst as $name => $row) {
	WP_CLI::log(sprintf('  %-10s %3d steps, slowest %5.2f s, most memory %5.1f MB', $name, $row['steps'], $row['seconds'], $row['memory'] / 1048576));
	if ($row['seconds'] >= 30) {
		$problems[] = "{$name}: a step took " . round($row['seconds'], 1) . ' s';
	}
	if ($row['memory'] >= 64 * 1048576) {
		$problems[] = "{$name}: a step used " . round($row['memory'] / 1048576) . ' MB';
	}
}
WP_CLI::log(sprintf('Whole scan: %d objects in %d steps, %.1f s; groups %s', $state['objects'], $steps, $total, wp_json_encode($state['counts'])));
if (!function_exists('memory_reset_peak_usage')) {
	WP_CLI::warning('PHP < 8.2: memory is the peak since the start, not per step.');
}

$wpdb->query('DELETE FROM `' . Parich_Slug_Repair_Scanner::items_table() . '`');
$wpdb->query('DELETE FROM `' . Parich_Slug_Repair_Scanner::urls_table() . '` WHERE scan_id <> 0');
delete_option(Parich_Slug_Repair_Scanner::STATE_OPTION);

if ($count < $target) {
	$problems[] = "only {$count} posts";
}
if ($problems) {
	WP_CLI::error("Performance check failed:\n  " . implode("\n  ", $problems));
}
WP_CLI::success("Every step of a {$count}-post scan stayed under 30 s and 64 MB.");
