<?php
/**
 * Phase 5 (L11): the shadow copy follows every save — REST included — and is not
 * read on ordinary requests; seeding; the heal notice. Inside the rolled-back
 * transaction (the shadow table exists already, created on a normal request).
 *
 * @package Parich_Long_Slug
 */

function pls_test_early_hooks()
{
	// Every query that touches the shadow table, from the moment WordPress starts loading.
	$GLOBALS['pls_shadow_queries'] = array();
	pls_test_add_early_hook('query', function ($query) {
		if (false !== strpos($query, 'parich_slug_shadow')) {
			$GLOBALS['pls_shadow_queries'][] = $query;
		}
		return $query;
	}, 1, 1);
}

require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/../fixtures/helpers.php';
pls_test_catch_exits();

// Queries made while WordPress and the plugins loaded, before this file's own checks.
$load_queries = $GLOBALS['pls_shadow_queries'];

global $wpdb;
$shadow_table = Parich_Slug_Shadow::table();
if ($shadow_table !== $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $shadow_table)) || !Parich_Slug_Sanitize::is_active()) {
	pls_test_assert('precondition: shadow table created and long slugs active (open the site once after updating the plugin)', false);
	pls_test_done();
}

function pls_shadow_of($type, $id)
{
	global $wpdb;
	return $wpdb->get_var($wpdb->prepare('SELECT slug FROM ' . Parich_Slug_Shadow::table() . ' WHERE object_type = %s AND object_id = %d', $type, $id));
}

pls_test_section('Ordinary requests do not read the shadow table');
pls_test_assert('loading WordPress and the plugins: 0 queries on the shadow table', array() === $load_queries, implode(' | ', $load_queries));
$GLOBALS['pls_shadow_queries'] = array();
$posts = get_posts(array('numberposts' => 5, 'post_status' => 'publish'));
foreach ($posts as $one) {
	get_permalink($one);
	url_to_postid(get_permalink($one));
}
sanitize_title('ข่าวประชาสัมพันธ์');
Parich_Slug_Schema::maybe_check_db_version();
Parich_Slug_Shadow::maybe_install();
pls_test_assert('reading posts, permalinks, url_to_postid(), sanitize_title() and the version checks: 0 queries on it', array() === $GLOBALS['pls_shadow_queries'], implode(' | ', $GLOBALS['pls_shadow_queries']));
pls_test_assert('install() again changes nothing (dbDelta finds the table as defined; DDL would have been blocked)', true === Parich_Slug_Shadow::install());

wp_set_current_user(pls_test_user('administrator'));
$long_title = 'มหาวิทยาลัยราชภัฏมหาสารคามจัดโครงการพัฒนาศักยภาพบุคลากรสายวิชาการและสายสนับสนุน';
$long = sanitize_title($long_title);

pls_test_section('Posts saved through the REST API (L11: Long URL Maker never saw these)');
list(, $created) = pls_rest('POST', '/wp/v2/posts', array('title' => $long_title, 'status' => 'publish'));
$pid = $created['id'];
pls_test_assert('new post with a long slug: copy saved', $long === pls_shadow_of('post', $pid) && strlen($long) > 200);
list(, $short) = pls_rest('POST', '/wp/v2/posts', array('title' => 'ข่าวสั้น', 'status' => 'publish'));
pls_test_assert('new post with a short slug: no copy', null === pls_shadow_of('post', $short['id']));
pls_rest('POST', '/wp/v2/posts/' . $pid, array('slug' => 'short-now'));
pls_test_assert('slug shortened: copy removed', null === pls_shadow_of('post', $pid));
pls_rest('POST', '/wp/v2/posts/' . $pid, array('slug' => $long));
pls_test_assert('slug long again: copy back', $long === pls_shadow_of('post', $pid));
pls_rest('POST', '/wp/v2/posts/' . $pid, array('content' => 'changed once'));
pls_rest('POST', '/wp/v2/posts/' . $pid, array('content' => 'changed twice'));
$revisions = wp_get_post_revisions($pid);
$revision_rows = $revisions ? (int) $wpdb->get_var("SELECT COUNT(*) FROM {$shadow_table} WHERE object_type = 'post' AND object_id IN (" . implode(',', array_map('intval', array_keys($revisions))) . ')') : 0;
pls_test_assert('revisions get no copy (' . count($revisions) . ' revisions)', count($revisions) > 0 && 0 === $revision_rows);

pls_rest('DELETE', '/wp/v2/posts/' . $pid);
pls_test_assert('moved to the trash: the copy keeps the full slug', 'trash' === get_post_status($pid) && $long === pls_shadow_of('post', $pid));
wp_untrash_post($pid);
pls_test_assert('restored from the trash: copy still the full slug', $long === pls_slug_of($pid) && $long === pls_shadow_of('post', $pid));
pls_rest('DELETE', '/wp/v2/posts/' . $pid, array('force' => true));
pls_test_assert('deleted permanently: copy removed', null === get_post($pid) && null === pls_shadow_of('post', $pid));

pls_test_section('Attachments (wp_insert_post() skips save_post for them)');
$attachment = wp_insert_attachment(array('post_title' => $long_title . ' รูปภาพ', 'post_mime_type' => 'image/png', 'post_status' => 'inherit', 'guid' => home_url('/pls-test.png')));
$attachment_slug = pls_slug_of($attachment);
pls_test_assert('new attachment with a long slug: copy saved', strlen($attachment_slug) > 200 && $attachment_slug === pls_shadow_of('post', $attachment));
wp_update_post(array('ID' => $attachment, 'post_name' => 'short-attachment'));
pls_test_assert('slug shortened: copy removed', null === pls_shadow_of('post', $attachment));
remove_action('add_attachment', array('Parich_Slug_Shadow', 'sync_attachment'), 20);
$untracked = wp_insert_attachment(array('post_title' => $long_title . ' รูปที่สอง', 'post_mime_type' => 'image/png', 'post_status' => 'inherit', 'guid' => home_url('/pls-test-2.png')));
pls_test_assert('negative control — without the attachment hook no copy is made (as with Long URL Maker\'s backup)', strlen(pls_slug_of($untracked)) > 200 && null === pls_shadow_of('post', $untracked));
add_action('add_attachment', array('Parich_Slug_Shadow', 'sync_attachment'), 20, 1);

pls_test_section('Terms');
list($status, $cat) = pls_rest('POST', '/wp/v2/categories', array('name' => $long_title));
pls_test_assert('category with a long slug: copy saved', 201 === $status && $long === pls_shadow_of('term', $cat['id']));
pls_rest('POST', '/wp/v2/categories/' . $cat['id'], array('slug' => 'short-category'));
pls_test_assert('slug shortened: copy removed', null === pls_shadow_of('term', $cat['id']));
pls_rest('POST', '/wp/v2/categories/' . $cat['id'], array('slug' => $long));
pls_rest('DELETE', '/wp/v2/categories/' . $cat['id'], array('force' => true));
pls_test_assert('deleted: copy removed', null === get_term($cat['id']) && null === pls_shadow_of('term', $cat['id']));

pls_test_section('Seeding long slugs that are already there');
$wpdb->insert($wpdb->posts, array('post_title' => 'seed', 'post_name' => $long . '-seed', 'post_status' => 'publish', 'post_type' => 'post', 'post_date' => current_time('mysql'), 'post_date_gmt' => current_time('mysql', true), 'post_content' => '', 'post_excerpt' => '', 'to_ping' => '', 'pinged' => '', 'post_content_filtered' => ''));
$seed_post = (int) $wpdb->insert_id;
$wpdb->insert($wpdb->terms, array('name' => 'seed', 'slug' => $long . '-seed', 'term_group' => 0));
$seed_term = (int) $wpdb->insert_id;
$written = Parich_Slug_Shadow::seed();
$count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$shadow_table}");
pls_test_assert('seed() copies long slugs written without WordPress hooks (post and term)', $long . '-seed' === pls_shadow_of('post', $seed_post) && $long . '-seed' === pls_shadow_of('term', $seed_term), "{$written} written");
pls_test_assert('…including the attachment saved without the hook above', strlen(pls_slug_of($untracked)) > 200 && pls_slug_of($untracked) === pls_shadow_of('post', $untracked));
Parich_Slug_Shadow::seed();
pls_test_assert('running it again adds no rows', $count === (int) $wpdb->get_var("SELECT COUNT(*) FROM {$shadow_table}"));

pls_test_section('Heal notice');
update_option(Parich_Slug_Schema::LAST_HEAL_OPTION, array('time' => time(), 'restored' => 3, 'skipped' => array('post:12', 'term:5'), 'error' => ''), false);
update_option(Parich_Slug_Schema::HEAL_NOTICE_OPTION, 1, false);
$notices = function () {
	ob_start();
	Parich_Slug_Schema::admin_notices();
	return (string) ob_get_clean();
};
$html = $notices();
pls_test_assert('administrator sees what was restored and what was left', false !== strpos($html, 'restored 3 slugs') && false !== strpos($html, '2 slugs were left as they are') && false !== strpos($html, 'post:12, term:5'));
pls_test_assert('…with a Dismiss button that posts a nonce', false !== strpos($html, 'value="' . Parich_Slug_Schema::DISMISS_HEAL_ACTION . '"') && false !== strpos($html, 'name="_wpnonce"'));
$admin = get_current_user_id();
wp_set_current_user(pls_test_user('subscriber'));
pls_test_assert('subscriber sees nothing', '' === $notices());
$dismiss = function () {
	try {
		Parich_Slug_Schema::handle_dismiss_heal();
	} catch (PLS_Test_Exit $e) {
		return $e;
	}
	return null;
};
$_REQUEST['_wpnonce'] = wp_create_nonce(Parich_Slug_Schema::DISMISS_HEAL_ACTION);
$exit = $dismiss();
pls_test_assert('subscriber with a valid nonce gets 403', $exit && 403 === $exit->status);
wp_set_current_user($admin);
unset($_REQUEST['_wpnonce']);
$exit = $dismiss();
pls_test_assert('administrator without a nonce gets 403, notice stays', $exit && 403 === $exit->status && get_option(Parich_Slug_Schema::HEAL_NOTICE_OPTION));
$_REQUEST['_wpnonce'] = wp_create_nonce(Parich_Slug_Schema::DISMISS_HEAL_ACTION);
$exit = $dismiss();
pls_test_assert('administrator with a nonce dismisses it', $exit && 'redirect' === $exit->getMessage() && !get_option(Parich_Slug_Schema::HEAL_NOTICE_OPTION) && false === strpos($notices(), 'database update shortened'));

pls_test_done();
