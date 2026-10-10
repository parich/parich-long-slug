<?php
/**
 * Phase 5 (L2): after a "WordPress update" shrank the slug columns, heal widens them
 * and restores cut slugs from the shadow copy — only slugs that still hold exactly
 * their first 200 characters. Runs on copies of wp_posts, wp_terms and the shadow
 * table (DDL outside transactions); the options it writes are restored at the end.
 *
 * @package Parich_Long_Slug
 */

define('PLS_TEST_MANUAL_TX', true);
require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/../fixtures/helpers.php';

global $wpdb;
$shadow_source = Parich_Slug_Shadow::table();
if ($shadow_source !== $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $shadow_source))) {
	pls_test_assert('precondition: shadow table created (open the site once after updating the plugin)', false);
	pls_test_done();
}

function pls_heal_set_column($table, $column, $length)
{
	global $wpdb;
	$info = Parich_Slug_Schema::get_column_info($table, $column);
	// WordPress's own connection is not in strict mode, so longer values are cut, as during an upgrade.
	$wpdb->query("ALTER TABLE `{$table}` MODIFY `{$column}` varchar({$length}) CHARACTER SET {$info['charset']} COLLATE {$info['collation']} NOT NULL DEFAULT ''");
}

function pls_heal_value($table, $id_column, $column, $id)
{
	global $wpdb;
	return (string) $wpdb->get_var($wpdb->prepare("SELECT `{$column}` FROM `{$table}` WHERE `{$id_column}` = %d", $id));
}

$posts = pls_test_clone_table($wpdb->posts);
$terms = pls_test_clone_table($wpdb->terms);
$shadow = pls_test_clone_table($shadow_source);
$empty = $wpdb->prefix . 'pls_t_shadow_empty';
$wpdb->query("DROP TABLE IF EXISTS `{$empty}`");
$wpdb->query("CREATE TABLE `{$empty}` LIKE `{$shadow_source}`");
$GLOBALS['pls_test_clones'][] = $empty;
$wpdb->query("DELETE FROM `{$shadow}`");

$columns = array($posts => 'post_name', $terms => 'slug');
$tables = array('shadow' => $shadow, 'post' => $posts, 'term' => $terms);
$slug = function ($text) {
	return Parich_Slug_Sanitize::sanitize($text, '', 'save');
};
$long = array(
	'A' => $slug('ก. มหาวิทยาลัยราชภัฏมหาสารคามจัดโครงการพัฒนาศักยภาพบุคลากรสายวิชาการและสายสนับสนุน'),
	'B' => $slug('ข. ประกาศผลการคัดเลือกนักศึกษาที่ได้รับทุนการศึกษาเพื่อพัฒนาท้องถิ่นประจำปีการศึกษา'),
	'C' => $slug('ค. ขอเชิญร่วมงานวันวิทยาศาสตร์แห่งชาติและกิจกรรมเปิดบ้านคณะวิทยาศาสตร์และเทคโนโลยี'),
	'D' => $slug('ง. หมวดหมู่ข่าวประชาสัมพันธ์ของสำนักวิทยบริการและเทคโนโลยีสารสนเทศมหาวิทยาลัย'),
	'E' => $slug('จ. ผลการแข่งขันกีฬามหาวิทยาลัยราชภัฏแห่งประเทศไทยครั้งที่สี่สิบแปดณจังหวัดมหาสารคาม'),
	'E2' => $slug('จ. ข้อความอื่นที่ไม่ใช่ตอนต้นของลิงก์เดิมแต่บันทึกไว้ในสำเนาก่อนหน้านี้แล้วเท่านั้น'),
);
$ids = array_map('intval', $wpdb->get_col("SELECT ID FROM `{$posts}` ORDER BY ID LIMIT 4"));
list($id_a, $id_b, $id_c, $id_e) = $ids;
$id_d = (int) $wpdb->get_var("SELECT term_id FROM `{$terms}` ORDER BY term_id LIMIT 1");

pls_test_section('Test setup on copies');
foreach (array(array($id_a, 'A'), array($id_b, 'B'), array($id_c, 'C'), array($id_e, 'E')) as list($id, $key)) {
	$wpdb->query($wpdb->prepare("UPDATE `{$posts}` SET post_name = %s WHERE ID = %d", $long[$key], $id));
}
$wpdb->query($wpdb->prepare("UPDATE `{$terms}` SET slug = %s WHERE term_id = %d", $long['D'], $id_d));
$copies = array(array('post', $id_a, 'A'), array('post', $id_b, 'B'), array('term', $id_d, 'D'), array('post', $id_e, 'E2'));
foreach ($copies as list($type, $id, $key)) {
	$wpdb->insert($shadow, array('object_type' => $type, 'object_id' => $id, 'slug' => $long[$key], 'updated_at' => current_time('mysql', true)));
}
pls_test_assert('five long slugs (' . implode(', ', array_map('strlen', $long)) . ' bytes); copies for A, B, D and a stale one for E; none for C', 4 === (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$shadow}`") && min(array_map('strlen', $long)) > 200);

$saved = array();
foreach (array(Parich_Slug_Schema::COLUMNS_OK_OPTION, Parich_Slug_Schema::LAST_HEAL_OPTION, Parich_Slug_Schema::HEAL_NOTICE_OPTION) as $name) {
	$saved[$name] = $wpdb->get_row($wpdb->prepare("SELECT option_value, autoload FROM {$wpdb->options} WHERE option_name = %s", $name));
}
try {
	pls_heal_set_column($posts, 'post_name', 200);
	pls_heal_set_column($terms, 'slug', 200);
	$cut_a = pls_heal_value($posts, 'ID', 'post_name', $id_a);
	pls_test_assert('the "update" cut A to its first 200 characters', 200 === strlen($cut_a) && 0 === strpos($long['A'], $cut_a));
	$wpdb->query($wpdb->prepare("UPDATE `{$posts}` SET post_name = 'edited-after-the-cut' WHERE ID = %d", $id_b));
	$cut_c = pls_heal_value($posts, 'ID', 'post_name', $id_c);

	pls_test_section('check_and_heal() with the shadow copy');
	pls_test_assert('shrunk_columns() reports both copies at 200', array($posts => 200, $terms => 200) === Parich_Slug_Schema::shrunk_columns($columns));
	$result = Parich_Slug_Schema::check_and_heal($columns, $tables);
	pls_test_assert('it returns true and both columns are varchar(2000) again', true === $result && array() === Parich_Slug_Schema::shrunk_columns($columns));
	pls_test_assert('A (post) is restored in full', $long['A'] === pls_heal_value($posts, 'ID', 'post_name', $id_a));
	pls_test_assert('D (term) is restored in full', $long['D'] === pls_heal_value($terms, 'term_id', 'slug', $id_d));
	pls_test_assert('B, changed after the cut, is left alone', 'edited-after-the-cut' === pls_heal_value($posts, 'ID', 'post_name', $id_b));
	pls_test_assert('C, cut but without a copy, stays cut', $cut_c === pls_heal_value($posts, 'ID', 'post_name', $id_c) && 200 === strlen($cut_c));
	pls_test_assert('E, whose copy does not start with what is left, stays cut', 200 === strlen(pls_heal_value($posts, 'ID', 'post_name', $id_e)));
	$heal = get_option(Parich_Slug_Schema::LAST_HEAL_OPTION);
	pls_test_assert(
		'the record: 4 checked, 2 restored, B and E reported as left alone',
		is_array($heal) && 4 === $heal['checked'] && 2 === $heal['restored'] && array("post:{$id_b}", "post:{$id_e}") === $heal['skipped'],
		wp_json_encode($heal)
	);
	pls_test_assert('the notice for administrators is switched on', (bool) get_option(Parich_Slug_Schema::HEAL_NOTICE_OPTION));

	pls_test_section('Negative control: without copies nothing comes back');
	pls_heal_set_column($posts, 'post_name', 200);
	pls_heal_set_column($terms, 'slug', 200);
	Parich_Slug_Schema::check_and_heal($columns, array('shadow' => $empty, 'post' => $posts, 'term' => $terms));
	pls_test_assert('with an empty copy table the columns are widened but A stays cut (the checks above would fail)', array() === Parich_Slug_Schema::shrunk_columns($columns) && 200 === strlen(pls_heal_value($posts, 'ID', 'post_name', $id_a)));
	$heal = get_option(Parich_Slug_Schema::LAST_HEAL_OPTION);
	pls_test_assert('…and records 0 checked, 0 restored', 0 === $heal['checked'] && 0 === $heal['restored']);
} finally {
	foreach ($saved as $name => $row) {
		if ($row) {
			$wpdb->update($wpdb->options, array('option_value' => $row->option_value, 'autoload' => $row->autoload), array('option_name' => $name));
		} else {
			$wpdb->delete($wpdb->options, array('option_name' => $name));
		}
		wp_cache_delete($name, 'options');
	}
	wp_cache_delete('alloptions', 'options');
	Parich_Slug_Schema::release_lock();
}

pls_test_done();
