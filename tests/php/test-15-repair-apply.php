<?php
/**
 * Phase 9 on this site's tables (inside the rolled-back transaction): repairing a cut
 * slug and undoing it, the redirect handler (only 404s, no query otherwise, loops,
 * query strings, targets that are gone), redirects matched by hand, turning redirects
 * off and deleting them, the export, refusals while Long URL Maker is active or the
 * guard is missing, and capabilities and nonces of every handler.
 *
 * @package Parich_Long_Slug
 */

require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/../fixtures/helpers.php';
pls_test_catch_exits();

global $wpdb;
$admin = pls_test_user('administrator');
$subscriber = pls_test_user('subscriber');
wp_set_current_user($admin);
pls_test_assert('precondition: the repair tables exist (scan once on this site after updating the plugin)', Parich_Slug_Repair_Scanner::VERSION === get_option(Parich_Slug_Repair_Scanner::VERSION_OPTION));
// Tests keep the guard in a temporary folder (see bootstrap.php): put one there, as on the site.
pls_test_assert('precondition: guard installed in the test folder', true === Parich_Slug_Guard::install() && Parich_Slug_Guard::is_current());

/**
 * Front-end request for a URL, as WordPress runs it.
 */
function pls_go_to($url)
{
	$parts = wp_parse_url($url);
	$_SERVER['REQUEST_URI'] = (isset($parts['path']) ? $parts['path'] : '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
	$_SERVER['PHP_SELF'] = '/index.php';
	$vars = $GLOBALS['wp']->public_query_vars;
	foreach ($vars as $var) {
		unset($GLOBALS[$var]);
	}
	$GLOBALS['wp_the_query'] = new WP_Query();
	$GLOBALS['wp_query'] = $GLOBALS['wp_the_query'];
	$GLOBALS['wp'] = new WP();
	$GLOBALS['wp']->public_query_vars = $vars;
	$GLOBALS['wp']->main();
}

/**
 * [outcome, queries to the redirects table] of maybe_redirect() after a request.
 */
function pls_handle($url)
{
	pls_go_to($url);
	$queries = array();
	$log = function ($query) use (&$queries) {
		if (false !== strpos($query, 'parich_slug_redirects')) {
			$queries[] = $query;
		}
		return $query;
	};
	add_filter('query', $log);
	try {
		Parich_Slug_Redirects::maybe_redirect();
		$outcome = is_404() ? '404' : 'served';
	} catch (PLS_Test_Exit $e) {
		$outcome = 'redirect:' . $e->location;
	}
	remove_filter('query', $log);
	return array($outcome, $queries);
}

$hooks = array('save_post' => 0, 'post_updated' => 0, 'wp_insert_post' => 0);
foreach (array_keys($hooks) as $hook) {
	add_action($hook, function () use (&$hooks, $hook) {
		++$hooks[$hook];
	});
}

pls_test_section('Repair a cut slug on this site');
$thai = 'กองพัฒนานักศึกษาประกาศรายชื่อผู้ได้รับทุนการศึกษาเพื่อพัฒนาท้องถิ่นประจำปีการศึกษา';
$fixture_post = wp_insert_post(array('post_title' => 'Scholarship list', 'post_name' => $thai, 'post_status' => 'publish', 'post_type' => 'post'));
$full = pls_slug_of($fixture_post);
$cut = substr($full, 0, 200);
$wpdb->update($wpdb->posts, array('post_name' => $cut), array('ID' => $fixture_post));
clean_post_cache($fixture_post);
$cut_url = get_permalink($fixture_post);
Parich_Slug_Repair_Scanner::import_csv(array("post,{$fixture_post},{$full}"));
$scan = Parich_Slug_Repair_Scanner::run();
$item = null;
foreach (Parich_Slug_Repair_Scanner::results((int) $scan['id'])['items'] as $candidate) {
	if ($fixture_post === (int) $candidate['object']['id']) {
		$item = $candidate;
	}
}
pls_test_assert('fixture: the cut slug is "auto" with the full one (CSV)', $item && 'auto' === $item['group'] && $full === $item['proposal']);
$modified = get_post_field('post_modified', $fixture_post, 'raw');
$hooks = array_fill_keys(array_keys($hooks), 0);
$state = Parich_Slug_Repair_Applier::run('auto');
pls_test_assert('the repair finishes with one slug and one redirect', !is_wp_error($state) && 1 === $state['applied'] && 1 === $state['redirects'], is_wp_error($state) ? $state->get_error_message() : wp_json_encode(array($state['applied'], $state['redirects'], $state['skipped'])));
pls_test_assert('the slug is the full one again; post_modified unchanged; no save hooks', $full === pls_slug_of($fixture_post) && $modified === get_post_field('post_modified', $fixture_post, 'raw') && 0 === array_sum($hooks), wp_json_encode($hooks));
pls_test_assert('the shadow copy has the full slug', $full === $wpdb->get_var($wpdb->prepare('SELECT slug FROM ' . Parich_Slug_Shadow::table() . " WHERE object_type = 'post' AND object_id = %d", $fixture_post)));
pls_test_assert('no Long URL Maker table here, and none was created', !$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->prefix . 'kpcode_url_posts')));
pls_test_assert('the redirect count option is up to date', 1 <= (int) get_option(Parich_Slug_Redirects::COUNT_OPTION));
list($outcome) = pls_handle($cut_url);
pls_test_assert('the cut address 301s to the repaired one', 'redirect:' . get_permalink($fixture_post) === $outcome, $outcome);
list($outcome) = pls_handle($cut_url . '?utm_source=line');
pls_test_assert('…keeping the query string', 'redirect:' . get_permalink($fixture_post) . '?utm_source=line' === $outcome, $outcome);
$hits = (int) $wpdb->get_var($wpdb->prepare('SELECT hits FROM ' . Parich_Slug_Redirects::table() . ' WHERE object_id = %d', $fixture_post));
pls_test_assert('hits are counted', 2 === $hits);

pls_test_section('No query on ordinary requests');
list($outcome, $queries) = pls_handle(get_permalink($fixture_post));
pls_test_assert('a post that exists: served, no query to the redirects table', 'served' === $outcome && !$queries);
list($outcome, $queries) = pls_handle(home_url('/'));
pls_test_assert('the front page: no query to the redirects table', 'served' === $outcome && !$queries);
list($outcome, $queries) = pls_handle(home_url('/no-such-page-pls/'));
pls_test_assert('a 404 with no redirect: one lookup, stays 404', '404' === $outcome && 1 === count($queries), count($queries) . ' queries');
$count = get_option(Parich_Slug_Redirects::COUNT_OPTION);
update_option(Parich_Slug_Redirects::COUNT_OPTION, 0);
list($outcome, $queries) = pls_handle(home_url('/no-such-page-pls/'));
pls_test_assert('with no active redirects, a 404 makes no query either', '404' === $outcome && !$queries);
update_option(Parich_Slug_Redirects::COUNT_OPTION, $count);
pls_test_assert('the handler runs before core\'s old-slug and canonical redirects', 1 === has_action('template_redirect', array('Parich_Slug_Redirects', 'maybe_redirect')) && 10 === has_action('template_redirect', 'wp_old_slug_redirect'));

pls_test_section('Targets');
Parich_Slug_Redirects::add(wp_parse_url(get_permalink($fixture_post), PHP_URL_PATH) . 'loop/', 'post', $fixture_post, 'manual-map');
$self = Parich_Slug_Repair_Util::path_of(get_permalink($fixture_post));
pls_test_assert('a redirect to the address it comes from is never followed (no loop)', null === Parich_Slug_Redirects::find($self));
$draft = wp_insert_post(array('post_title' => 'Draft target', 'post_status' => 'draft'));
Parich_Slug_Redirects::add('/old-to-draft-pls/', 'post', $draft, 'manual-map');
Parich_Slug_Redirects::recount();
list($outcome) = pls_handle(home_url('/old-to-draft-pls/'));
pls_test_assert('a target that is not published: the old address stays a 404', '404' === $outcome, $outcome);
$category = wp_insert_term('Repair target category', 'category');
Parich_Slug_Redirects::add('/old-category-pls/', 'term', $category['term_id'], 'manual-map');
Parich_Slug_Redirects::recount();
list($outcome) = pls_handle(home_url('/old-category-pls/'));
pls_test_assert('a term target: 301 to the term\'s archive', 'redirect:' . get_term_link($category['term_id']) === $outcome, $outcome);

pls_test_section('Redirects matched by hand');
$matched_page = wp_insert_post(array('post_title' => 'Matched page', 'post_status' => 'publish', 'post_type' => 'page'));
$mapped = Parich_Slug_Repair_Admin::map('/' . rawurlencode('ข่าวเก่าที่หาย') . '/', (string) $matched_page);
list($outcome) = pls_handle(home_url('/ข่าวเก่าที่หาย/'));
pls_test_assert('a 404 address (Thai) matched to a page ID redirects there', $matched_page === $mapped && 'redirect:' . get_permalink($matched_page) === $outcome, $outcome);
$mapped = Parich_Slug_Repair_Admin::map('/old-by-link-pls/', get_permalink($matched_page));
pls_test_assert('…also when the page is given by its link', $matched_page === $mapped);
pls_test_assert('a draft as target is refused', is_wp_error(Parich_Slug_Repair_Admin::map('/x-pls/', (string) $draft)));
pls_test_assert('a target that does not exist is refused', is_wp_error(Parich_Slug_Repair_Admin::map('/x-pls/', '999999999')) && is_wp_error(Parich_Slug_Repair_Admin::map('/x-pls/', 'not a link')));
pls_test_assert('the page\'s own address cannot redirect to it', is_wp_error(Parich_Slug_Repair_Admin::map(get_permalink($matched_page), (string) $matched_page)));
pls_test_assert('an address already redirecting elsewhere is refused', is_wp_error(Parich_Slug_Repair_Admin::map('/old-by-link-pls/', (string) $fixture_post)));
pls_test_assert('the front page cannot be redirected', is_wp_error(Parich_Slug_Repair_Admin::map('/', (string) $matched_page)));

pls_test_section('Turning off, deleting, export');
$row = $wpdb->get_row("SELECT id FROM " . Parich_Slug_Redirects::table() . " WHERE source_path = '/old-by-link-pls'");
Parich_Slug_Redirects::set_active($row->id, false);
list($outcome) = pls_handle(home_url('/old-by-link-pls/'));
pls_test_assert('a redirect turned off: 404', '404' === $outcome);
Parich_Slug_Redirects::set_active($row->id, true);
list($outcome) = pls_handle(home_url('/old-by-link-pls/'));
pls_test_assert('turned on again: 301', 'redirect:' . get_permalink($matched_page) === $outcome);
Parich_Slug_Redirects::delete($row->id);
pls_test_assert('deleted: gone from the table, count updated', null === $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . Parich_Slug_Redirects::table() . ' WHERE id = %d', $row->id)) && (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . Parich_Slug_Redirects::table() . ' WHERE is_active = 1') === (int) get_option(Parich_Slug_Redirects::COUNT_OPTION));
$rows = Parich_Slug_Redirects::export_rows();
$sources = wp_list_pluck(array_slice($rows, 1), 0);
pls_test_assert('the export has a header and every redirect, with readable old addresses and their targets', 'source_url' === $rows[0][0] && in_array(home_url('/ข่าวเก่าที่หาย/'), $sources, true) && in_array(get_permalink($matched_page), wp_list_pluck(array_slice($rows, 1), 1), true));

pls_test_section('Undo');
$batch = $state['batch'];
$_POST = $_REQUEST = array('_wpnonce' => wp_create_nonce(Parich_Slug_Repair_Admin::UNDO_ACTION), 'batch' => $batch);
try {
	Parich_Slug_Repair_Admin::handle_undo();
	$exit = null;
} catch (PLS_Test_Exit $e) {
	$exit = $e;
}
$_POST = $_REQUEST = array();
$notice = get_transient(Parich_Slug_Repair_Admin::NOTICE_TRANSIENT . $admin);
pls_test_assert('undo through the admin button puts the cut slug back and says so', $exit && 'redirect' === $exit->getMessage() && $cut === pls_slug_of($fixture_post) && is_array($notice) && 'success' === $notice['type']);
list($outcome) = pls_handle($cut_url);
pls_test_assert('…the cut address is no longer redirected', 0 !== strpos($outcome, 'redirect:'), $outcome);
pls_test_assert('…and the shadow copy no longer holds the full slug', $full !== $wpdb->get_var($wpdb->prepare('SELECT slug FROM ' . Parich_Slug_Shadow::table() . " WHERE object_type = 'post' AND object_id = %d", $fixture_post)));
$again = Parich_Slug_Repair_Applier::undo($batch);
pls_test_assert('undoing twice changes nothing', 0 === $again['restored'] && 0 === $again['remaining'] && $cut === pls_slug_of($fixture_post));

pls_test_section('Refusals');
$active = get_option('active_plugins');
update_option('active_plugins', array_merge((array) $active, array('long-url-maker/long_url_maker.php')));
$ready = Parich_Slug_Repair_Applier::check_ready();
pls_test_assert('Long URL Maker active → repairing is refused', is_wp_error($ready) && 'parich_slug_legacy_active' === $ready->get_error_code());
pls_test_assert('…and start() refuses too', is_wp_error(Parich_Slug_Repair_Applier::start('auto')));
update_option('active_plugins', $active);
$missing = function () {
	return PLS_TEST_GUARD_DIR . '/missing-guard.php';
};
add_filter('parich_slug_guard_file', $missing);
$ready = Parich_Slug_Repair_Applier::check_ready();
pls_test_assert('guard missing while Long URL Maker\'s files are on the site → refused', is_wp_error($ready) && 'parich_slug_guard' === $ready->get_error_code());
remove_filter('parich_slug_guard_file', $missing);
pls_test_assert('everything in place → allowed', true === Parich_Slug_Repair_Applier::check_ready());
$wpdb->update($wpdb->posts, array('post_name' => 'changed-pls-' . $fixture_post), array('ID' => $fixture_post));
clean_post_cache($fixture_post);
$state = Parich_Slug_Repair_Applier::run(array('post-' . $fixture_post => ''));
pls_test_assert('a slug changed since the scan is skipped, not overwritten', !is_wp_error($state) && 0 === $state['applied'] && 'changed-pls-' . $fixture_post === pls_slug_of($fixture_post));
$state = Parich_Slug_Repair_Applier::start(array('post-' . $fixture_post => '!!!'));
pls_test_assert('a slug typed by hand that cleans to nothing is refused', is_wp_error($state));

pls_test_section('A repair left unfinished');
$wpdb->update($wpdb->posts, array('post_name' => $cut), array('ID' => $fixture_post));
clean_post_cache($fixture_post);
Parich_Slug_Repair_Scanner::run();
$open = Parich_Slug_Repair_Applier::start('auto');
pls_test_assert('fixture: a batch started and never stepped (page closed)', !is_wp_error($open) && empty($open['finished']));
pls_test_assert('a new repair is refused while it may still be running', is_wp_error(Parich_Slug_Repair_Applier::start('auto')));
$open['touched'] = time() - Parich_Slug_Repair_Applier::STALE_AFTER - 1;
update_option(Parich_Slug_Repair_Applier::STATE_OPTION, $open, false);
$wpdb->update($wpdb->posts, array('post_name' => $full), array('ID' => $fixture_post));
clean_post_cache($fixture_post);
$again = Parich_Slug_Repair_Applier::run(array('post-' . $fixture_post => ''));
pls_test_assert('after five quiet minutes a new repair may start; an item already at its slug is reported, not changed', !is_wp_error($again) && 0 === $again['applied'] && false !== strpos(wp_json_encode($again['skipped'], JSON_UNESCAPED_UNICODE), 'Already repaired') && $full === pls_slug_of($fixture_post), is_wp_error($again) ? $again->get_error_message() : wp_json_encode($again['skipped']));

pls_test_section('Repairing through admin-ajax');
$second = wp_insert_post(array('post_title' => 'Second fixture', 'post_name' => $thai . 'ครั้งที่สอง', 'post_status' => 'publish', 'post_type' => 'post'));
$second_full = pls_slug_of($second);
$wpdb->update($wpdb->posts, array('post_name' => substr($second_full, 0, 200)), array('ID' => $second));
clean_post_cache($second);
Parich_Slug_Repair_Scanner::import_csv(array("post,{$second},{$second_full}"));
Parich_Slug_Repair_Scanner::run();
$nonce = wp_create_nonce(Parich_Slug_Repair_Admin::APPLY_ACTION);
// The admin types a slug of their own (readable Thai with a space) instead of the proposal.
list($output) = pls_test_ajax(array('Parich_Slug_Repair_Admin', 'ajax_apply'), array('op' => 'start', 'mode' => 'selected', 'repair' => array('post-' . $second), 'own' => array('post-' . $second => 'สลักเอง ชื่อใหม่'), 'nonce' => $nonce));
$started = json_decode($output, true);
pls_test_assert('start: one item queued', !empty($started['success']) && 1 === $started['data']['total'] && false === $started['data']['finished'], $output);
list($output) = pls_test_ajax(array('Parich_Slug_Repair_Admin', 'ajax_apply'), array('op' => 'step', 'batch' => $started['data']['batch'], 'nonce' => $nonce));
$stepped = json_decode($output, true);
$notice = get_transient(Parich_Slug_Repair_Admin::NOTICE_TRANSIENT . $admin);
pls_test_assert('step: repaired with the typed slug, cleaned like a slug typed in WordPress (space → "-")', !empty($stepped['data']['finished']) && strtolower(rawurlencode('สลักเอง') . '-' . rawurlencode('ชื่อใหม่')) === pls_slug_of($second), rawurldecode(pls_slug_of($second)));
pls_test_assert('…and the admin is told what to do next (caches, sitemaps)', is_array($notice) && false !== strpos($notice['message'], '1 slugs repaired') && false !== strpos($notice['message'], 'page cache'), is_array($notice) ? $notice['message'] : 'no notice');
delete_transient(Parich_Slug_Repair_Admin::NOTICE_TRANSIENT . $admin);

pls_test_section('Capabilities and nonces');
$nonce = wp_create_nonce(Parich_Slug_Repair_Admin::APPLY_ACTION);
list($output, $exit) = pls_test_ajax(array('Parich_Slug_Repair_Admin', 'ajax_apply'), array('op' => 'start', 'mode' => 'auto'));
pls_test_assert('repair without a nonce → refused', $exit && 403 === $exit->status);
wp_set_current_user($subscriber);
list($output, $exit) = pls_test_ajax(array('Parich_Slug_Repair_Admin', 'ajax_apply'), array('op' => 'start', 'mode' => 'auto', 'nonce' => wp_create_nonce(Parich_Slug_Repair_Admin::APPLY_ACTION)));
pls_test_assert('repair as a subscriber (with a nonce) → 403', $exit && 403 === $exit->status);
wp_set_current_user($admin);
list($output) = pls_test_ajax(array('Parich_Slug_Repair_Admin', 'ajax_apply'), array('op' => 'step', 'batch' => 999999, 'nonce' => $nonce));
$json = json_decode($output, true);
pls_test_assert('a step for another batch → refused', isset($json['success']) && false === $json['success']);
foreach (array('handle_undo' => Parich_Slug_Repair_Admin::UNDO_ACTION, 'handle_redirect' => Parich_Slug_Repair_Admin::REDIRECT_ACTION, 'handle_export_redirects' => Parich_Slug_Repair_Admin::EXPORT_REDIRECTS_ACTION, 'handle_map' => Parich_Slug_Repair_Admin::MAP_ACTION) as $handler => $action) {
	foreach (array(array($subscriber, true), array($admin, false)) as list($user, $with_nonce)) {
		wp_set_current_user($user);
		$_POST = $_REQUEST = $with_nonce ? array('_wpnonce' => wp_create_nonce($action), 'redirect' => 1, 'do' => 'delete', 'batch' => 1, 'path' => '/x/', 'target' => (string) $matched_page) : array('redirect' => 1, 'do' => 'delete', 'batch' => 1);
		try {
			call_user_func(array('Parich_Slug_Repair_Admin', $handler));
			$exit = null;
		} catch (PLS_Test_Exit $e) {
			$exit = $e;
		}
		pls_test_assert("{$handler} " . ($with_nonce ? 'as a subscriber' : 'without a nonce') . ' → 403', $exit && 'redirect' !== $exit->getMessage() && 403 === $exit->status);
	}
}
$_POST = $_REQUEST = array();
wp_set_current_user($admin);
pls_test_assert('nothing was deleted by the refused requests', 0 < (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . Parich_Slug_Redirects::table()));

pls_test_done();
