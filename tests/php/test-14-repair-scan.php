<?php
/**
 * Phase 8 integration on this site's tables: a healthy site gives no proposals; the
 * scan writes only to its own tables and options; other plugins' tables that are
 * missing or different are skipped with a warning; imported URLs and CSV rows change
 * the group; the repair tab and its handlers check capabilities, nonces and files, and
 * escape what they show.
 *
 * Table copies (wp_pls_t_*) are made before the transaction (PLS_TEST_MANUAL_TX);
 * every scan and fixture runs inside it and is rolled back.
 *
 * @package Parich_Long_Slug
 */

define('PLS_TEST_MANUAL_TX', true);
require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/../fixtures/helpers.php';
pls_test_catch_exits();

global $wpdb;

pls_test_section('Setup (outside the transaction)');
pls_test_assert('the scan tables exist (created on first use)', true === Parich_Slug_Repair_Scanner::maybe_install());
// A copy of the site under another prefix, with two tables of other plugins in an unknown shape.
foreach (array($wpdb->posts, $wpdb->postmeta, $wpdb->terms, $wpdb->term_taxonomy, $wpdb->term_relationships, $wpdb->options) as $table) {
	pls_test_clone_table($table);
}
$copy = $wpdb->prefix . 'pls_t_';
foreach (array('yoast_indexable' => '(id int unsigned NOT NULL AUTO_INCREMENT, url text, PRIMARY KEY (id))', 'redirection_404' => '(id int unsigned NOT NULL AUTO_INCREMENT, created datetime, PRIMARY KEY (id))') as $name => $columns) {
	$wpdb->query("DROP TABLE IF EXISTS `{$copy}{$name}`");
	$wpdb->query("CREATE TABLE `{$copy}{$name}` {$columns}");
	$GLOBALS['pls_test_clones'][] = $copy . $name;
}
pls_test_assert('copies made', (bool) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $copy . 'yoast_indexable')));

pls_test_begin();
// Negative control: PLS_NEGATIVE=write makes something write post meta during every
// scan; the read-only checks below must then fail.
if ('write' === getenv('PLS_NEGATIVE')) {
	add_filter('parich_slug_repair_post_types', function ($types) {
		update_post_meta(1, '_pls_negative', (string) microtime(true));
		return $types;
	});
}
$admin = pls_test_user('administrator');
$subscriber = pls_test_user('subscriber');
wp_set_current_user($admin);

/**
 * Hash of every row that matters, read in PHP (GROUP_CONCAT would cut it).
 */
function pls_site_hash($prefix)
{
	global $wpdb;
	$parts = array(
		$wpdb->get_results("SELECT ID, post_name, post_title, post_status, post_parent, post_modified, guid FROM `{$prefix}posts` ORDER BY ID", ARRAY_N),
		$wpdb->get_results("SELECT meta_id, post_id, meta_key, meta_value FROM `{$prefix}postmeta` ORDER BY meta_id", ARRAY_N),
		$wpdb->get_results("SELECT term_id, name, slug FROM `{$prefix}terms` ORDER BY term_id", ARRAY_N),
		$wpdb->get_results("SELECT option_name, option_value FROM `{$prefix}options` WHERE option_name NOT LIKE 'parich\\_slug\\_scan%' AND option_name NOT LIKE '\\_%transient\\_%' ORDER BY option_name", ARRAY_N),
	);
	return md5(serialize($parts));
}

/**
 * Runs a scan and returns [state, write queries made meanwhile].
 */
function pls_scan($prefix = null)
{
	$writes = array();
	$log = function ($query) use (&$writes) {
		if (preg_match('/^\s*(INSERT|UPDATE|DELETE|REPLACE|ALTER|CREATE|DROP|TRUNCATE|RENAME)\b/i', $query)) {
			$writes[] = $query;
		}
		return $query;
	};
	add_filter('query', $log);
	$state = Parich_Slug_Repair_Scanner::run($prefix);
	remove_filter('query', $log);
	return array($state, $writes);
}

function pls_item_of($state, $type, $id)
{
	foreach (Parich_Slug_Repair_Scanner::results((int) $state['id'])['items'] as $item) {
		if ($type === $item['object']['type'] && $id === (int) $item['object']['id']) {
			return $item;
		}
	}
	return null;
}

/**
 * Write queries that touch anything but the scan tables and the scan's own options.
 */
function pls_foreign_writes(array $writes)
{
	return array_values(array_filter($writes, function ($query) {
		return false === strpos($query, 'parich_slug_scan') && false === strpos($query, 'parich_slug_repair_db_version');
	}));
}

pls_test_section('A healthy site');
$before = pls_site_hash($wpdb->prefix);
list($state, $writes) = pls_scan();
pls_test_assert('the scan finishes', !is_wp_error($state) && 'done' === $state['step'], is_wp_error($state) ? $state->get_error_message() : '');
pls_test_assert('this site (never damaged) → 0 items, nothing proposed', 0 === $state['counts']['auto'] + $state['counts']['review'] + $state['counts']['unresolved'] && $state['objects'] > 0, wp_json_encode($state['counts']));
pls_test_assert('read-only: posts, post meta, terms and options are unchanged', pls_site_hash($wpdb->prefix) === $before);
$foreign = pls_foreign_writes($writes);
pls_test_assert('read-only: every write went to the scan tables or the scan\'s options', $writes && !$foreign, implode("\n", array_slice($foreign, 0, 3)));

pls_test_section('Other plugins\' tables missing or different');
$before = pls_site_hash($copy);
list($state, $writes) = pls_scan($copy);
pls_test_assert('a source without Long URL Maker\'s table and with odd SEO/404 tables still scans', !is_wp_error($state) && 'done' === $state['step'], is_wp_error($state) ? $state->get_error_message() : '');
$warned = is_array($state) ? wp_list_pluck($state['warnings'], 'table') : array();
pls_test_assert('…and warns about each table with unexpected columns', in_array($copy . 'yoast_indexable', $warned, true) && in_array($copy . 'redirection_404', $warned, true), wp_json_encode($warned));
pls_test_assert('…but not about tables that are simply missing', !in_array($copy . 'kpcode_url_posts', $warned, true) && 2 === count($warned));
pls_test_assert('read-only on the copy as well', pls_site_hash($copy) === $before && !pls_foreign_writes($writes));
pls_test_assert('a prefix with no WordPress tables is refused', is_wp_error(Parich_Slug_Repair_Scanner::start('nothing_here_')));
pls_test_assert('a prefix with odd characters is refused', is_wp_error(Parich_Slug_Repair_Scanner::start('wp_; DROP TABLE x')));

pls_test_section('Imported URLs and CSV');
$thai = 'สำนักงานอธิการบดีแจ้งกำหนดการปิดปรับปรุงระบบสารสนเทศเพื่อการบริหารจัดการประจำภาคเรียน';
$id = wp_insert_post(array('post_title' => 'Unrelated English title', 'post_name' => $thai, 'post_status' => 'publish', 'post_type' => 'post'));
$full = pls_slug_of($id);
$wpdb->update($wpdb->posts, array('post_name' => substr($full, 0, 200)), array('ID' => $id));
clean_post_cache($id);
pls_test_assert('fixture: a post with a long Thai slug, cut to 200, and a title that does not give it', strlen($full) > 300 && 200 === strlen(pls_slug_of($id)));
list($state) = pls_scan();
$item = pls_item_of($state, 'post', $id);
pls_test_assert('no evidence → "unresolved"', $item && 'unresolved' === $item['group'] && array('D1') === $item['codes'], $item ? $item['group'] : 'not listed');
$result = Parich_Slug_Repair_Scanner::import_urls(array('# a comment', 'http://example.org/other-site/', home_url('/something-else/'), 'not a url'));
list($state) = pls_scan();
$item = pls_item_of($state, 'post', $id);
pls_test_assert('URLs that do not continue the cut slug change nothing (negative)', $item && 'unresolved' === $item['group'] && 1 === $result['added'] && 2 === $result['skipped'], wp_json_encode($result));
$result = Parich_Slug_Repair_Scanner::import_urls(array(home_url('/' . rawurldecode($full) . '/') . ',37'));
list($state) = pls_scan();
$item = pls_item_of($state, 'post', $id);
pls_test_assert('an imported URL (readable Thai, with clicks) continuing the cut slug → "review" with that slug', $item && 'review' === $item['group'] && $full === $item['proposal'] && 1 === $result['added']);
$result = Parich_Slug_Repair_Scanner::import_csv(array('type,id,slug', "post,{$id}," . rawurldecode($full), 'post,abc,x', 'page,1,x'));
list($state) = pls_scan();
$item = pls_item_of($state, 'post', $id);
pls_test_assert('a CSV row for the post → "auto"', $item && 'auto' === $item['group'] && $full === $item['proposal'] && 1 === $result['added'] && 2 === $result['skipped'], wp_json_encode($result));
$counts = Parich_Slug_Repair_Scanner::import_counts();
pls_test_assert('imports are counted and kept across scans', 2 === $counts['urls'] && 1 === $counts['csv'], wp_json_encode($counts));
$unowned = wp_list_pluck(Parich_Slug_Repair_Scanner::unowned_urls((int) $state['id']), 'path');
pls_test_assert('an imported URL nothing accounts for is listed for the admin; the matched one is not', in_array('/something-else', $unowned, true) && !in_array('/' . $full, $unowned, true), wp_json_encode($unowned));
Parich_Slug_Repair_Scanner::clear_imports();
$counts = Parich_Slug_Repair_Scanner::import_counts();
pls_test_assert('clear_imports() removes them', 0 === $counts['urls'] && 0 === $counts['csv']);

pls_test_section('Listing the results (filters and pages)');
// Three more cut posts: a CSV row makes one ready, an imported URL puts one to check,
// the third has no evidence. Distinct beginnings, so they do not share a cut slug.
$listing = array();
foreach (array('auto', 'review', 'unresolved') as $want) {
	$pid = wp_insert_post(array('post_title' => 'Listing fixture ' . $want, 'post_name' => 'listing-' . $want . '-' . $thai, 'post_status' => 'publish', 'post_type' => 'post'));
	$listing[$want] = array('id' => $pid, 'full' => pls_slug_of($pid));
	$wpdb->update($wpdb->posts, array('post_name' => substr($listing[$want]['full'], 0, 200)), array('ID' => $pid));
	clean_post_cache($pid);
}
Parich_Slug_Repair_Scanner::import_csv(array('type,id,slug', "post,{$listing['auto']['id']}," . rawurldecode($listing['auto']['full'])));
Parich_Slug_Repair_Scanner::import_urls(array(home_url('/' . rawurldecode($listing['review']['full']) . '/') . ',5'));
list($state) = pls_scan();
$scan_id = (int) $state['id'];
$groups = array();
foreach ($listing as $want => $fixture) {
	$item = pls_item_of($state, 'post', $fixture['id']);
	$groups[$want] = $item ? $item['group'] : 'not listed';
}
pls_test_assert('fixture: one ready, one to check, one unresolved', array('auto' => 'auto', 'review' => 'review', 'unresolved' => 'unresolved') === $groups, wp_json_encode($groups));
$listed = $state['counts']['auto'] + $state['counts']['review'] + $state['counts']['unresolved'];
$all = Parich_Slug_Repair_Scanner::results($scan_id);
pls_test_assert('no filter: every item, and the total agrees with the scan counts', $listed >= 4 && count($all['items']) === $listed && $all['total'] === $listed, $listed . ' / ' . count($all['items']) . ' / ' . $all['total']);
$rank = array('auto' => 0, 'review' => 1, 'unresolved' => 2);
$ranks = array_map(function ($item) use ($rank) {
	return $rank[$item['group']];
}, array_values($all['items']));
$sorted = $ranks;
sort($sorted);
pls_test_assert('results order: ready first, then to check, then unresolved', $ranks === $sorted);
foreach (array('auto', 'review', 'unresolved') as $group) {
	$page = Parich_Slug_Repair_Scanner::results($scan_id, array('group' => $group));
	$only = array_unique(wp_list_pluck(array_values($page['items']), 'group'));
	pls_test_assert("group \"{$group}\": only those items, total = that count", array($group) === array_values($only) && count($page['items']) === $state['counts'][$group] && $page['total'] === $state['counts'][$group], wp_json_encode($only));
}
$page = Parich_Slug_Repair_Scanner::results($scan_id, array('code' => 'D1'));
$with_d1 = array_filter($page['items'], function ($item) {
	return in_array('D1', $item['codes'], true);
});
pls_test_assert('detector D1: only items with D1, total = the D1 count', $page['items'] && count($with_d1) === count($page['items']) && $page['total'] === $state['counts']['D1']);
$page = Parich_Slug_Repair_Scanner::results($scan_id, array('code' => 'D7'));
pls_test_assert('detector D7 (no term damaged here) → nothing (negative)', !$page['items'] && 0 === $page['total']);
$page = Parich_Slug_Repair_Scanner::results($scan_id, array('group' => 'review', 'code' => 'D1'));
pls_test_assert('group and detector together', 1 <= count($page['items']) && 'review' === current($page['items'])['group']);
$seen = array();
for ($offset = 0; $offset < $listed + 1; $offset++) {
	$page = Parich_Slug_Repair_Scanner::results($scan_id, array('limit' => 1, 'offset' => $offset));
	$seen = array_merge($seen, array_keys($page['items']));
}
pls_test_assert('pages of one go through every item once, in the same order (the page after the last is empty)', array_keys($all['items']) === $seen && $listed === $page['total']);
$row_auto = key(Parich_Slug_Repair_Scanner::results($scan_id, array('group' => 'auto'))['items']);
$by_id = Parich_Slug_Repair_Scanner::load_items($scan_id, array('id' => $row_auto));
pls_test_assert('load_items by row id', array($row_auto) === array_keys($by_id));
$by_object = Parich_Slug_Repair_Scanner::load_items($scan_id, array('object' => array('post', $listing['unresolved']['id'])));
pls_test_assert('load_items by object', 1 === count($by_object) && $listing['unresolved']['id'] === (int) current($by_object)['object']['id']);
$key = $by_id[$row_auto]['row']->proposal_key;
$by_key = Parich_Slug_Repair_Scanner::load_items($scan_id, array('proposal_key' => $key));
pls_test_assert('load_items by proposed slug', '' !== $key && isset($by_key[$row_auto]) && array($key) === array_values(array_unique(wp_list_pluck(wp_list_pluck($by_key, 'row'), 'proposal_key'))));
$ids = array_keys($all['items']);
sort($ids);
$first = Parich_Slug_Repair_Scanner::load_items($scan_id, array('after' => 0, 'limit' => 2));
$rest = Parich_Slug_Repair_Scanner::load_items($scan_id, array('after' => max(array_keys($first)), 'limit' => 1000));
pls_test_assert('batches by id: two, then the rest after the last one', array_slice($ids, 0, 2) === array_keys($first) && array_slice($ids, 2) === array_keys($rest));
$proposing = Parich_Slug_Repair_Scanner::load_items($scan_id, array('after' => 0, 'limit' => 1000, 'has_proposal' => true));
$without = array_filter($all['items'], function ($item) {
	return null === $item['proposal'] || '' === $item['proposal'];
});
pls_test_assert('batches of items that propose a slug leave out the others', $without && count($proposing) === count($all['items']) - count($without) && !array_intersect_key($proposing, $without));
$two = Parich_Slug_Repair_Scanner::load_items($scan_id, array('group' => array('auto', 'review')));
pls_test_assert('several groups at once', count($two) === $state['counts']['auto'] + $state['counts']['review'] && !in_array('unresolved', wp_list_pluck($two, 'group'), true));
Parich_Slug_Repair_Scanner::clear_imports();

pls_test_section('Import files');
$dir = PLS_TEST_GUARD_DIR . '-imports';
wp_mkdir_p($dir);
$files = array(
	'binary' => "http://x/\0\1\2",
	'latin1' => "http://x/\xE9t\xE9",
	'big' => str_repeat('a', Parich_Slug_Repair_Scanner::IMPORT_MAX_BYTES + 1),
	'long' => str_repeat("/a\n", Parich_Slug_Repair_Scanner::IMPORT_MAX_LINES + 1),
	'good' => "\xEF\xBB\xBF/ข่าว\r\n/news/",
);
foreach ($files as $name => $content) {
	file_put_contents("{$dir}/{$name}.txt", $content);
}
foreach (array('binary' => 'binary content', 'latin1' => 'not UTF-8', 'big' => 'over 2 MB', 'long' => 'over 20,000 lines') as $name => $why) {
	pls_test_assert("a file with {$why} is refused", is_wp_error(Parich_Slug_Repair_Scanner::read_import_file("{$dir}/{$name}.txt")));
}
$lines = Parich_Slug_Repair_Scanner::read_import_file("{$dir}/good.txt");
pls_test_assert('a UTF-8 text file is read, without its BOM', array('/ข่าว', '/news/') === $lines);
pls_test_assert('a missing file is refused', is_wp_error(Parich_Slug_Repair_Scanner::read_import_file("{$dir}/none.txt")));
foreach (array_keys($files) as $name) {
	wp_delete_file("{$dir}/{$name}.txt");
}
rmdir($dir);

pls_test_section('Capabilities and nonces');
$nonce = wp_create_nonce(Parich_Slug_Repair_Admin::SCAN_ACTION);
list($output, $exit) = pls_test_ajax(array('Parich_Slug_Repair_Admin', 'ajax_scan'), array('op' => 'start'));
pls_test_assert('scan without a nonce → refused', $exit && 403 === $exit->status);
wp_set_current_user($subscriber);
list($output, $exit) = pls_test_ajax(array('Parich_Slug_Repair_Admin', 'ajax_scan'), array('op' => 'start', 'nonce' => wp_create_nonce(Parich_Slug_Repair_Admin::SCAN_ACTION)));
pls_test_assert('scan as a subscriber (with a nonce) → 403', $exit && 403 === $exit->status);
wp_set_current_user($admin);
list($output) = pls_test_ajax(array('Parich_Slug_Repair_Admin', 'ajax_scan'), array('op' => 'start', 'nonce' => $nonce));
$started = json_decode($output, true);
pls_test_assert('scan as an administrator starts', !empty($started['success']) && 'urls' === $started['data']['step']);
list($output) = pls_test_ajax(array('Parich_Slug_Repair_Admin', 'ajax_scan'), array('op' => 'step', 'scan' => $started['data']['scan'] + 1, 'nonce' => $nonce));
$json = json_decode($output, true);
pls_test_assert('a step for a scan that is not the current one → refused', isset($json['success']) && false === $json['success']);
list($output) = pls_test_ajax(array('Parich_Slug_Repair_Admin', 'ajax_scan'), array('op' => 'step', 'scan' => $started['data']['scan'], 'nonce' => $nonce));
$json = json_decode($output, true);
pls_test_assert('steps continue the scan to the end', !empty($json['success']) && true === $json['data']['done'], $output);

$handlers = array('handle_import' => Parich_Slug_Repair_Admin::IMPORT_ACTION, 'handle_export' => Parich_Slug_Repair_Admin::EXPORT_ACTION, 'handle_clear' => Parich_Slug_Repair_Admin::CLEAR_ACTION);
foreach ($handlers as $handler => $action) {
	wp_set_current_user($subscriber);
	$_POST = $_REQUEST = array('_wpnonce' => wp_create_nonce($action));
	try {
		call_user_func(array('Parich_Slug_Repair_Admin', $handler));
		$exit = null;
	} catch (PLS_Test_Exit $e) {
		$exit = $e;
	}
	pls_test_assert("{$handler} as a subscriber → 403", $exit && 403 === $exit->status);
	wp_set_current_user($admin);
	$_POST = $_REQUEST = array();
	try {
		call_user_func(array('Parich_Slug_Repair_Admin', $handler));
		$exit = null;
	} catch (PLS_Test_Exit $e) {
		$exit = $e;
	}
	pls_test_assert("{$handler} without a nonce → refused", $exit && 'redirect' !== $exit->getMessage() && 403 === $exit->status);
}
$_POST = $_REQUEST = array('_wpnonce' => wp_create_nonce(Parich_Slug_Repair_Admin::IMPORT_ACTION), 'kind' => 'urls');
$_FILES = array('import_file' => array('name' => 'evil.php', 'tmp_name' => __FILE__, 'error' => 0, 'size' => 10));
try {
	Parich_Slug_Repair_Admin::handle_import();
	$exit = null;
} catch (PLS_Test_Exit $e) {
	$exit = $e;
}
$notice = get_transient(Parich_Slug_Repair_Admin::NOTICE_TRANSIENT . $admin);
pls_test_assert('importing a .php file is refused', $exit && 'redirect' === $exit->getMessage() && is_array($notice) && 'error' === $notice['type'] && false !== strpos($notice['message'], '.txt'));
$_FILES['import_file']['name'] = 'urls.txt';
try {
	Parich_Slug_Repair_Admin::handle_import();
} catch (PLS_Test_Exit $e) {
	$exit = $e;
}
$notice = get_transient(Parich_Slug_Repair_Admin::NOTICE_TRANSIENT . $admin);
pls_test_assert('a file that was not uploaded through the form is refused', is_array($notice) && 'error' === $notice['type'] && 0 === Parich_Slug_Repair_Scanner::import_counts()['urls']);
$_POST = $_REQUEST = $_FILES = array();
delete_transient(Parich_Slug_Repair_Admin::NOTICE_TRANSIENT . $admin);

pls_test_section('Escaping in the repair tab and the CSV');
$evil = wp_insert_post(array('post_title' => '<script>alert("pls")</script>=SUM(1) ' . $thai, 'post_status' => 'publish', 'post_type' => 'post'));
$wpdb->update($wpdb->posts, array('post_title' => '=SUM(1)<script>alert("pls")</script> ' . $thai, 'post_name' => substr(pls_slug_of($evil), 0, 200)), array('ID' => $evil));
clean_post_cache($evil);
list($state) = pls_scan();
pls_test_assert('fixture: the post is listed', null !== pls_item_of($state, 'post', $evil));
ob_start();
Parich_Slug_Repair_Admin::render();
$html = (string) ob_get_clean();
pls_test_assert('the title is escaped in the results table', false === strpos($html, '<script>alert') && false !== strpos($html, '&lt;script&gt;alert'));
pls_test_assert('the results table, filters and import forms are shown', false !== strpos($html, 'parich-slug-results') && false !== strpos($html, 'name="code"') && 2 === substr_count($html, 'name="import_file"'));
$csv = Parich_Slug_Repair_Admin::csv(Parich_Slug_Repair_Scanner::export_rows((int) $state['id']));
pls_test_assert('the CSV starts with a BOM and a header', 0 === strpos($csv, "\xEF\xBB\xBFobject_type,object_id"));
pls_test_assert('a title starting with "=" cannot run as a formula in the CSV', false !== strpos($csv, "'=SUM(1)") && false === strpos($csv, ',=SUM(1)'));
wp_set_current_user($subscriber);
pls_test_assert('the settings page with the repair tab refuses a subscriber', (function () {
	try {
		$_GET['tab'] = 'repair';
		Parich_Slug_Admin::render_page();
		return false;
	} catch (PLS_Test_Exit $e) {
		return 403 === $e->status;
	} finally {
		unset($_GET['tab']);
	}
})());
wp_set_current_user($admin);

pls_test_done();
