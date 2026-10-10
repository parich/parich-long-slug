<?php
/**
 * Phase 4 (L5, L9): long slugs through the REST API (block editor path), "-2"
 * suffixes at full length, pages, a custom post type, terms, trash/untrash, and Thai
 * marks put back after the block editor's slug field removed them. All inside the
 * rolled-back transaction.
 *
 * @package Parich_Long_Slug
 */

require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/../fixtures/helpers.php';

if (!Parich_Slug_Sanitize::is_active()) {
	pls_test_assert('precondition: the slug columns are widened, so the long filter is installed', false);
	pls_test_done();
}

wp_set_current_user(pls_test_user('administrator'));
register_post_type('pls_test_cpt', array('public' => true, 'show_in_rest' => true, 'rest_base' => 'pls-test-cpt'));
register_taxonomy('pls_test_tax', array('post'), array('public' => true, 'show_in_rest' => true));

// About 75 Thai characters: well over core's 200 bytes, well under the default 1000.
$long = 'มหาวิทยาลัยราชภัฏมหาสารคามจัดโครงการพัฒนาศักยภาพบุคลากรสายวิชาการและสายสนับสนุน';
$expected = sanitize_title($long);

pls_test_section('Posts created through the REST API (block editor)');
pls_test_assert('the expected slug is the whole title (' . strlen($expected) . ' bytes)', urldecode($expected) === $long && strlen($expected) > 200 && strlen($expected) + 2 <= Parich_Slug_Sanitize::max_length());
list($status, $first) = pls_rest('POST', '/wp/v2/posts', array('title' => $long, 'status' => 'publish'));
pls_test_assert('201 Created with the full slug', 201 === $status && $expected === pls_slug_of($first['id']), $status . ' ' . pls_slug_of($first['id'] ?? 0));
list(, $second) = pls_rest('POST', '/wp/v2/posts', array('title' => $long, 'status' => 'publish'));
pls_test_assert('same title again: full slug + "-2"', $expected . '-2' === pls_slug_of($second['id']), strlen(pls_slug_of($second['id'])) . ' bytes');
list(, $third) = pls_rest('POST', '/wp/v2/posts', array('title' => $long, 'status' => 'publish'));
pls_test_assert('and a third time: "-3"', $expected . '-3' === pls_slug_of($third['id']));
pls_test_assert('url_to_postid() finds each of them from its permalink', $first['id'] === url_to_postid(get_permalink($first['id'])) && $second['id'] === url_to_postid(get_permalink($second['id'])));

remove_filter('wp_unique_post_slug', array('Parich_Slug_Sanitize', 'unique_post_slug'), 10);
list(, $without) = pls_rest('POST', '/wp/v2/posts', array('title' => $long, 'status' => 'publish'));
pls_test_assert('negative control (L5) — without the unique-slug filter the "-N" slug is cut to 200 bytes', strlen(pls_slug_of($without['id'])) <= 200, strlen(pls_slug_of($without['id'])) . ' bytes');
add_filter('wp_unique_post_slug', array('Parich_Slug_Sanitize', 'unique_post_slug'), 10, 6);

$filler = function () {
	return 300;
};
add_filter('parich_slug_max_length', $filler);
$at_limit = sanitize_title($long);
list(, $a) = pls_rest('POST', '/wp/v2/posts', array('title' => $long . ' ก', 'status' => 'publish'));
list(, $b) = pls_rest('POST', '/wp/v2/posts', array('title' => $long . ' ก', 'status' => 'publish'));
$slug_b = pls_slug_of($b['id']);
pls_test_assert(
	'at the length limit (300) "-2" replaces the end of the slug on a grapheme boundary',
	strlen($slug_b) <= 300 && '-2' === substr($slug_b, -2) && 0 === strpos(pls_slug_of($a['id']), substr($slug_b, 0, -2)) && strlen($at_limit) <= 300,
	strlen($slug_b) . ' bytes'
);
remove_filter('parich_slug_max_length', $filler);

pls_test_section('Pages, a custom post type and a term');
list(, $parent) = pls_rest('POST', '/wp/v2/pages', array('title' => $long, 'status' => 'publish'));
list(, $child1) = pls_rest('POST', '/wp/v2/pages', array('title' => $long, 'status' => 'publish', 'parent' => $parent['id']));
list(, $child2) = pls_rest('POST', '/wp/v2/pages', array('title' => $long, 'status' => 'publish', 'parent' => $parent['id']));
pls_test_assert('parent page: full slug', $expected === pls_slug_of($parent['id']));
pls_test_assert('first child page under it: same full slug (pages are unique per parent)', $expected === pls_slug_of($child1['id']));
pls_test_assert('second child page: full slug + "-2"', $expected . '-2' === pls_slug_of($child2['id']));
pls_test_assert('url_to_postid() finds the child from its permalink', $child2['id'] === url_to_postid(get_permalink($child2['id'])));
list($status, $cpt) = pls_rest('POST', '/wp/v2/pls-test-cpt', array('title' => $long, 'status' => 'publish'));
pls_test_assert('custom post type: full slug', 201 === $status && $expected === pls_slug_of($cpt['id']));
$term = wp_insert_term($long, 'pls_test_tax');
$term_slug = is_wp_error($term) ? '' : get_term($term['term_id'], 'pls_test_tax')->slug;
pls_test_assert('term: full slug, found again by slug', $expected === $term_slug && get_term_by('slug', $term_slug, 'pls_test_tax')->term_id === $term['term_id']);
list(, $numeric) = pls_rest('POST', '/wp/v2/pages', array('title' => '2569', 'status' => 'publish'));
pls_test_assert('numeric page title: "2569-2" as in core', '2569-2' === pls_slug_of($numeric['id']));

pls_test_section('Trash and restore');
wp_trash_post($first['id']);
pls_test_assert('in the trash: core\'s short name plus the full slug kept in _wp_desired_post_slug', '__trashed' === substr(pls_slug_of($first['id']), -9) && $expected === get_post_meta($first['id'], '_wp_desired_post_slug', true));
wp_untrash_post($first['id']);
pls_test_assert('restored: the full slug is back', $expected === pls_slug_of($first['id']));

pls_test_section('Thai marks removed by the block editor\'s slug field');
$marked_title = 'ประกาศผลการคัดเลือกนักศึกษาที่ได้รับทุนการศึกษาเพื่อพัฒนาท้องถิ่นประจำปีการศึกษาสองพันห้าร้อยหกสิบเก้า';
list(, $marked) = pls_rest('POST', '/wp/v2/posts', array('title' => $marked_title, 'status' => 'publish'));
$with_marks = pls_slug_of($marked['id']);
$cleaned = pls_clean_for_slug(urldecode($with_marks));
pls_test_assert('cleanForSlug() really removes the marks (test setup)', $cleaned !== urldecode($with_marks) && Parich_Slug_Sanitize::strip_thai_marks(urldecode($with_marks)) === $cleaned);
pls_rest('POST', '/wp/v2/posts/' . $marked['id'], array('slug' => $cleaned));
pls_test_assert('slug field focused and left: the slug keeps its marks', $with_marks === pls_slug_of($marked['id']), urldecode(pls_slug_of($marked['id'])));
list(, $new) = pls_rest('POST', '/wp/v2/posts', array('title' => $marked_title . ' ใหม่', 'slug' => pls_clean_for_slug($marked_title . ' ใหม่'), 'status' => 'publish'));
pls_test_assert('new post with a cleaned slug: the slug of its title is used', sanitize_title($marked_title . ' ใหม่') === pls_slug_of($new['id']));
pls_rest('POST', '/wp/v2/posts/' . $marked['id'], array('slug' => 'ข้อความที่ตั้งเองมีวรรณยุกต์'));
$custom = pls_slug_of($marked['id']);
pls_rest('POST', '/wp/v2/posts/' . $marked['id'], array('slug' => pls_clean_for_slug(urldecode($custom))));
pls_test_assert('a custom Thai slug keeps its marks the same way', $custom === pls_slug_of($marked['id']) && 'ข้อความที่ตั้งเองมีวรรณยุกต์' === urldecode($custom));
pls_rest('POST', '/wp/v2/posts/' . $marked['id'], array('slug' => 'english-slug'));
pls_test_assert('a different slug is saved as typed', 'english-slug' === pls_slug_of($marked['id']));
pls_rest('POST', '/wp/v2/posts/' . $marked['id'], array('slug' => $with_marks));
remove_filter('wp_insert_post_data', array('Parich_Slug_Sanitize', 'restore_thai_marks'), 10);
pls_rest('POST', '/wp/v2/posts/' . $marked['id'], array('slug' => $cleaned));
pls_test_assert('negative control — without the filter the marks are lost', rawurlencode($cleaned) !== $with_marks && urldecode(pls_slug_of($marked['id'])) === $cleaned);
add_filter('wp_insert_post_data', array('Parich_Slug_Sanitize', 'restore_thai_marks'), 10, 3);

pls_test_done();
