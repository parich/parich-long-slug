<?php
/**
 * Phase 3 (L1, L8): reading and widening the slug columns, the heal lock and
 * check_and_heal(), all on copies of wp_posts and wp_terms — the site's own tables
 * are never altered here. DDL runs outside transactions (PLS_TEST_MANUAL_TX); the
 * options that check_and_heal() writes are restored at the end.
 *
 * @package Parich_Long_Slug
 */

define('PLS_TEST_MANUAL_TX', true);
require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/../fixtures/helpers.php';

global $wpdb;

/**
 * Sets a copied column to varchar($length) with the source's character set and collation.
 */
function pls_set_column($table, $column, $definition)
{
	global $wpdb;
	$info = Parich_Slug_Schema::get_column_info($table, $column);
	$wpdb->query("ALTER TABLE `{$table}` MODIFY `{$column}` {$definition} CHARACTER SET {$info['charset']} COLLATE {$info['collation']} NOT NULL" . (0 === stripos($definition, 'varchar') ? " DEFAULT ''" : ''));
}

/**
 * Hash of every row's id and column, computed in PHP (GROUP_CONCAT would stop at
 * group_concat_max_len, 1024 bytes by default, and hide changes past it).
 */
function pls_rows_checksum($table, $id, $column)
{
	global $wpdb;
	$rows = $wpdb->get_results("SELECT `{$id}`, `{$column}` FROM `{$table}` ORDER BY `{$id}`", ARRAY_N);
	return count($rows) . ':' . md5(serialize($rows));
}

function pls_index_sub_part($table, $index)
{
	global $wpdb;
	$row = $wpdb->get_row($wpdb->prepare("SHOW INDEX FROM `{$table}` WHERE Key_name = %s", $index));
	return $row ? (int) $row->Sub_part : null;
}

/**
 * Number of ALTER statements sent while running $callback.
 */
function pls_count_alters($callback)
{
	$count = 0;
	$filter = function ($query) use (&$count) {
		if (preg_match('/^\s*ALTER\b/i', $query)) {
			$count++;
		}
		return $query;
	};
	add_filter('query', $filter, 1);
	$callback();
	remove_filter('query', $filter, 1);
	return $count;
}

pls_test_section('get_column_info()');
$site = Parich_Slug_Schema::get_column_info($wpdb->posts, 'post_name');
pls_test_assert('reads wp_posts.post_name (type, length, charset, collation)', !is_wp_error($site) && is_int($site['length']) && $site['charset'] && $site['collation'], wp_json_encode($site));
$bad = Parich_Slug_Schema::get_column_info('wp_posts`; DROP TABLE x', 'post_name');
pls_test_assert('rejects a table name that is not a plain identifier', is_wp_error($bad) && 'parich_slug_invalid_identifier' === $bad->get_error_code());
$missing = Parich_Slug_Schema::get_column_info($wpdb->prefix . 'no_such_table', 'post_name');
pls_test_assert('reports a missing table as an error', is_wp_error($missing) && 'parich_slug_no_column' === $missing->get_error_code());
$missing = Parich_Slug_Schema::expand($wpdb->prefix . 'no_such_table', 'post_name');
pls_test_assert('expand() on a missing table returns WP_Error, no fatal', is_wp_error($missing));

$posts = pls_test_clone_table($wpdb->posts);
$terms = pls_test_clone_table($wpdb->terms);
$map = array($posts => 'post_name', $terms => 'slug');

pls_test_section('expand() on copies set to a fresh WordPress definition, varchar(200)');
foreach (array(array($posts, 'ID', 'post_name'), array($terms, 'term_id', 'slug')) as list($table, $id, $column)) {
	pls_set_column($table, $column, 'varchar(200)');
	$before = Parich_Slug_Schema::get_column_info($table, $column);
	$rows = pls_rows_checksum($table, $id, $column);
	$index = pls_index_sub_part($table, $column);
	$result = Parich_Slug_Schema::expand($table, $column);
	$after = Parich_Slug_Schema::get_column_info($table, $column);
	pls_test_assert("{$column}: varchar(200) → varchar(2000)", true === $result && 2000 === $after['length'], is_wp_error($result) ? $result->get_error_message() : $after['type']);
	pls_test_assert("{$column}: character set and collation kept", $before['charset'] === $after['charset'] && $before['collation'] === $after['collation'], $after['collation']);
	pls_test_assert("{$column}: index prefix still {$index}", 191 === $index && $index === pls_index_sub_part($table, $column));
	pls_test_assert("{$column}: every row unchanged", $rows === pls_rows_checksum($table, $id, $column));
	$alters = pls_count_alters(function () use ($table, $column, &$result) {
		$result = Parich_Slug_Schema::expand($table, $column);
	});
	pls_test_assert("{$column}: running it again changes nothing (no ALTER)", false === $result && 0 === $alters);
}

pls_test_section('Columns are never made shorter or changed from another type');
pls_set_column($posts, 'post_name', 'varchar(3000)');
$result = Parich_Slug_Schema::expand($posts, 'post_name');
pls_test_assert('varchar(3000) stays varchar(3000)', false === $result && 3000 === Parich_Slug_Schema::get_column_info($posts, 'post_name')['length']);
pls_set_column($posts, 'post_name', 'text');
$result = Parich_Slug_Schema::expand($posts, 'post_name');
pls_test_assert('a text column is left alone with an explanation', is_wp_error($result) && 'parich_slug_unexpected_type' === $result->get_error_code() && 'text' === Parich_Slug_Schema::get_column_info($posts, 'post_name')['type']);

pls_test_section('Heal lock');
pls_test_begin();
pls_test_assert('the first request takes the lock', Parich_Slug_Schema::acquire_lock());
pls_test_assert('a second request does not get it', !Parich_Slug_Schema::acquire_lock());
Parich_Slug_Schema::release_lock();
pls_test_assert('after release it can be taken again', Parich_Slug_Schema::acquire_lock());
$wpdb->update($wpdb->options, array('option_value' => (string) (time() - 10)), array('option_name' => Parich_Slug_Schema::LOCK_OPTION));
pls_test_assert('a lock taken 10 s ago is respected', !Parich_Slug_Schema::acquire_lock());
$wpdb->update($wpdb->options, array('option_value' => (string) (time() - Parich_Slug_Schema::LOCK_TTL - 1)), array('option_name' => Parich_Slug_Schema::LOCK_OPTION));
pls_test_assert('a lock older than 10 minutes is taken over', Parich_Slug_Schema::acquire_lock());
$since = (int) $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", Parich_Slug_Schema::LOCK_OPTION));
pls_test_assert('…and carries the new time', abs(time() - $since) <= 5);
pls_test_rollback();

pls_test_section('check_and_heal() on copies shrunk by a "WordPress update"');
$saved = array();
foreach (array(Parich_Slug_Schema::COLUMNS_OK_OPTION, Parich_Slug_Schema::LAST_HEAL_OPTION, Parich_Slug_Schema::HEAL_NOTICE_OPTION) as $name) {
	$saved[$name] = $wpdb->get_row($wpdb->prepare("SELECT option_value, autoload FROM {$wpdb->options} WHERE option_name = %s", $name));
}
try {
	pls_set_column($posts, 'post_name', 'varchar(200)');
	pls_set_column($terms, 'slug', 'varchar(200)');

	// Another request is healing: this one must skip and leave the columns alone.
	Parich_Slug_Schema::acquire_lock();
	$alters = pls_count_alters(function () use ($map, &$result) {
		$result = Parich_Slug_Schema::check_and_heal($map);
	});
	pls_test_assert('with the lock held by another request it skips (returns false, no ALTER)', false === $result && 0 === $alters);
	pls_test_assert('…and the copies are still varchar(200)', 200 === Parich_Slug_Schema::get_column_info($posts, 'post_name')['length']);
	Parich_Slug_Schema::release_lock();

	$result = Parich_Slug_Schema::check_and_heal($map);
	pls_test_assert('without the lock it widens both copies to varchar(2000)', true === $result && 2000 === Parich_Slug_Schema::get_column_info($posts, 'post_name')['length'] && 2000 === Parich_Slug_Schema::get_column_info($terms, 'slug')['length']);
	$heal = get_option(Parich_Slug_Schema::LAST_HEAL_OPTION);
	pls_test_assert('it records the lengths found before widening', is_array($heal) && array($posts => 200, $terms => 200) === $heal['lengths_before'] && '' === $heal['error'], wp_json_encode($heal));
	pls_test_assert('it releases the lock', null === $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", Parich_Slug_Schema::LOCK_OPTION)));
	$alters = pls_count_alters(function () use ($map, &$result) {
		$result = Parich_Slug_Schema::check_and_heal($map);
	});
	pls_test_assert('nothing to do on long columns (no ALTER)', true === $result && 0 === $alters);
} finally {
	Parich_Slug_Schema::release_lock();
	foreach ($saved as $name => $row) {
		if ($row) {
			$wpdb->update($wpdb->options, array('option_value' => $row->option_value, 'autoload' => $row->autoload), array('option_name' => $name));
		} else {
			$wpdb->delete($wpdb->options, array('option_name' => $name));
		}
		wp_cache_delete($name, 'options');
	}
	wp_cache_delete('alloptions', 'options');
}

pls_test_done();
