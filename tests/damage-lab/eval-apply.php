<?php
/**
 * Phase 9 on a loaded damage lab snapshot: WordPress is pointed at the snapshot's
 * tables ($wpdb->set_prefix), so permalinks, url_to_postid() and the redirect handler
 * run against the damaged site. Then: scan → repair everything marked ready → check the
 * slugs against the ground truth, that nothing else changed, the log, the shadow copy,
 * Long URL Maker's backup rows, every redirect (own address, child pages, posts under
 * a category), the edge cases, undo, and that Long URL Maker's restore can no longer
 * undo a repair.
 *
 *   wp eval-file eval-apply.php <plslab_x_> <truth.json> <A|B>
 *
 * Tables of this plugin are created under the snapshot's prefix first (DDL); the rest
 * runs in a transaction that is rolled back. PLS_NEGATIVE=no-redirects deletes the
 * redirects right after the repair: the redirect checks must then fail.
 *
 * @package Parich_Long_Slug
 */

if (!defined('WP_CLI')) {
	exit;
}

list($prefix, $truth_file, $scenario) = array_pad($args, 3, '');
if (!preg_match('/^plslab_[a-z0-9_]*_$/', $prefix) || !is_readable($truth_file) || !in_array($scenario, array('A', 'B'), true)) {
	WP_CLI::error('Usage: wp eval-file eval-apply.php <plslab_x_> <truth.json> <A|B>');
}
$negative = getenv('PLS_NEGATIVE');

// Globals on purpose: eval-file runs this file inside a function.
$GLOBALS['pls_passes'] = 0;
$GLOBALS['pls_failures'] = array();
function pls_check($label, $condition, $detail = '')
{
	if ($condition) {
		++$GLOBALS['pls_passes'];
		WP_CLI::log("  [PASS] {$label}");
	} else {
		$GLOBALS['pls_failures'][] = $label . ('' !== $detail ? " — {$detail}" : '');
		WP_CLI::log("  [FAIL] {$label}" . ('' !== $detail ? " — {$detail}" : ''));
	}
}

class PLS_Redirected extends Exception
{
}

/**
 * Runs WordPress's main query for a URL, as a front-end request would.
 */
function pls_go_to($url)
{
	$parts = wp_parse_url($url);
	$_SERVER['REQUEST_URI'] = (isset($parts['path']) ? $parts['path'] : '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
	$_SERVER['PHP_SELF'] = '/index.php';
	$_GET = array();
	if (isset($parts['query'])) {
		parse_str($parts['query'], $_GET);
	}
	foreach ($GLOBALS['pls_query_vars'] as $var) {
		unset($GLOBALS[$var]);
	}
	$GLOBALS['wp_the_query'] = new WP_Query();
	$GLOBALS['wp_query'] = $GLOBALS['wp_the_query'];
	$GLOBALS['wp'] = new WP();
	// Query vars added when post types were registered (a new WP object has core's only).
	$GLOBALS['wp']->public_query_vars = $GLOBALS['pls_query_vars'];
	$GLOBALS['wp']->main();
}

/**
 * Where a request for $url ends: 'ok:<id>' when served, 'redirect:<url>' from our
 * handler, '404' otherwise.
 */
function pls_outcome($url)
{
	pls_go_to($url);
	if (!is_404()) {
		return 'ok:' . get_queried_object_id();
	}
	$catch = function ($location) {
		throw new PLS_Redirected($location);
	};
	add_filter('wp_redirect', $catch, PHP_INT_MAX);
	try {
		Parich_Slug_Redirects::maybe_redirect();
		$result = '404';
	} catch (PLS_Redirected $e) {
		$result = 'redirect:' . $e->getMessage();
	}
	remove_filter('wp_redirect', $catch, PHP_INT_MAX);
	return $result;
}

// WP-CLI warns with a backtrace on every redirect; the checks catch redirects themselves.
remove_all_filters('wp_redirect');
register_post_type('pls_lab_cpt', array('public' => true, 'label' => 'Lab CPT'));
register_taxonomy('pls_lab_tax', array('post'), array('public' => true, 'label' => 'Lab tax'));
$GLOBALS['pls_query_vars'] = $GLOBALS['wp']->public_query_vars;

global $wpdb, $wp_rewrite;
// The site's administrator, found before the switch: under the snapshot's prefix the
// role is read from another meta key and nobody would be found.
$admins = get_users(array('role' => 'administrator', 'number' => 1));
$users = array($wpdb->users, $wpdb->usermeta);
$wpdb->set_prefix($prefix);
list($wpdb->users, $wpdb->usermeta) = $users;
wp_cache_flush();
wp_set_current_user($admins[0]->ID);
if ($wpdb->posts !== $prefix . 'posts' || !$wpdb->get_var("SELECT COUNT(*) FROM `{$wpdb->posts}`")) {
	WP_CLI::error("WordPress could not be pointed at {$prefix}*.");
}
$truth = json_decode((string) file_get_contents($truth_file), true);
$clean = array();
foreach ($truth['objects'] as $object) {
	$clean[$object['type'] . '-' . $object['id']] = $object['clean_slug'];
}

WP_CLI::log("Scenario {$scenario} on {$prefix}*");
WP_CLI::log('Before repairing (outside the transaction)');
pls_check('this plugin\'s tables are created under the snapshot\'s prefix', true === Parich_Slug_Repair_Scanner::maybe_install() && true === Parich_Slug_Shadow::install());
$ready = Parich_Slug_Repair_Applier::check_ready();
if ('A' === $scenario) {
	pls_check('A: Long URL Maker still active → repairing is refused', is_wp_error($ready) && 'parich_slug_legacy_active' === $ready->get_error_code(), is_wp_error($ready) ? $ready->get_error_code() : 'allowed');
	// What the safe-deactivate button does.
	update_option('active_plugins', array_values(array_filter((array) get_option('active_plugins'), function ($plugin) {
		return !Parich_Slug_Legacy::is_legacy_basename($plugin);
	})));
} else {
	pls_check('B: slug columns cut back to 200 by its deactivation → repairing is refused', is_wp_error($ready) && 'parich_slug_columns' === $ready->get_error_code(), is_wp_error($ready) ? $ready->get_error_code() : 'allowed');
	Parich_Slug_Schema::ensure_columns();
	// Posts whose links contain their category, to check addresses that change with a category.
	update_option('permalink_structure', '/%category%/%postname%/');
}
$wp_rewrite->init();
$wp_rewrite->flush_rules(false);
pls_check('after setup and deactivation, repairing is allowed', true === Parich_Slug_Repair_Applier::check_ready());

$wpdb->query('SET autocommit = 0');
$wpdb->query('START TRANSACTION');
try {
	$hooks = array('save_post' => 0, 'post_updated' => 0, 'edited_term' => 0, 'wp_insert_post' => 0);
	foreach (array_keys($hooks) as $hook) {
		add_action($hook, function () use (&$hooks, $hook) {
			++$hooks[$hook];
		});
	}
	$category_posts = array();
	if ('B' === $scenario) {
		$category = (int) $wpdb->get_var("SELECT term_id FROM `{$wpdb->termmeta}` WHERE meta_key = '_pls_lab' AND meta_value = 'term-cat' ORDER BY term_id LIMIT 1");
		foreach ($wpdb->get_col("SELECT post_id FROM `{$wpdb->postmeta}` WHERE meta_key = '_pls_lab' AND meta_value IN ('form', 'links') ORDER BY post_id LIMIT 2") as $id) {
			wp_set_post_categories((int) $id, array($category));
			$category_posts[] = (int) $id;
		}
		clean_term_cache($category, 'category');
	}

	if ('no-acf' === $negative) {
		// Negative control: the values ACF saved next to the field are what gives its key away.
		$wpdb->query("DELETE FROM `{$wpdb->postmeta}` WHERE meta_key = '_lab_field_one'");
		WP_CLI::warning('Negative control: the post meta pointing to the ACF field key was deleted.');
	}
	$scan = Parich_Slug_Repair_Scanner::run();
	pls_check('the scan of the snapshot finishes', !is_wp_error($scan) && 'done' === $scan['step']);

	$system_ids = array();
	if ('B' === $scenario) {
		WP_CLI::log('System keys (D6)');
		$system = array();
		foreach (Parich_Slug_Repair_Scanner::load_items((int) $scan['id'], array('code' => 'D6')) as $item) {
			$system[(int) $item['object']['id']] = $item;
		}
		$lab = $wpdb->get_results("SELECT p.ID, p.post_type, p.post_excerpt FROM `{$wpdb->posts}` p JOIN `{$wpdb->postmeta}` m ON m.post_id = p.ID AND m.meta_key = '_pls_lab' AND m.meta_value = 'system' ORDER BY p.ID");
		$find = function ($type, $excerpt = null) use ($lab) {
			foreach ($lab as $row) {
				if ($type === $row->post_type && (null === $excerpt || $excerpt === $row->post_excerpt)) {
					return (int) $row->ID;
				}
			}
			return 0;
		};
		$system_ids = array_keys($system);
		$field_one = $find('acf-field', 'lab_field_one');
		$field_two = $find('acf-field', 'lab_field_two');
		$styles = $find('wp_global_styles');
		$template = $find('wp_template');
		$item = isset($system[$field_one]) ? $system[$field_one] : null;
		pls_check('ACF field with saved values: its key is proposed, for review (one source)', $item && $clean['post-' . $field_one] === $item['proposal'] && 'review' === $item['group'], $item ? $item['group'] . ' ' . $item['proposal'] : 'not listed');
		$item = isset($system[$field_two]) ? $system[$field_two] : null;
		pls_check('ACF field used nowhere and not in the backup: unresolved', $item && 'unresolved' === $item['group'] && null === $item['proposal']);
		$item = isset($system[$styles]) ? $system[$styles] : null;
		pls_check('global styles: the name WordPress gave it (theme, "-2" as the second of the theme)', $item && $clean['post-' . $styles] === $item['proposal'], $item ? (string) $item['proposal'] : 'not listed');
		$item = isset($system[$template]) ? $system[$template] : null;
		$ranked = $item ? Parich_Slug_Repair_Scorer::ranked($item) : array();
		pls_check('template: for review, the right slug first', $item && 'review' === $item['group'] && isset($ranked[0]) && $clean['post-' . $template] === $ranked[0], $item ? $item['group'] . ' ' . implode(',', $ranked) : 'not listed');
		$font = $find('wp_font_family');
		pls_check('font family whose slug its title still gives: not listed', $font && !isset($system[$font]));
	}
	// Everything marked ready, plus the items to check whose proposal is right (an admin
	// who looked at them would tick them); D5 (lengthening a URL that never changed) is left out.
	$auto = Parich_Slug_Repair_Scanner::load_items((int) $scan['id'], array('group' => array('auto', 'review')));
	ksort($auto); // id order, as before: the first items are used below
	$auto = array_filter($auto, function ($item) use ($clean) {
		$key = $item['object']['type'] . '-' . $item['object']['id'];
		return 'auto' === $item['group'] || (isset($clean[$key]) && $item['proposal'] === $clean[$key] && !in_array('D5', $item['codes'], true));
	});
	$keys = array();
	$reviewed = 0;
	foreach ($auto as $item) {
		$keys[] = $item['object']['type'] . '-' . $item['object']['id'];
		$reviewed += 'review' === $item['group'] ? 1 : 0;
	}
	$choices = array_fill_keys($keys, '');
	WP_CLI::log('  ' . count($keys) . ' items to repair: ' . (count($keys) - $reviewed) . ' ready, ' . $reviewed . ' checked by hand');

	// One item changed by hand between the scan and the repair.
	$changed = null;
	foreach ($auto as $item) {
		if ('post' === $item['object']['type'] && 'publish' === $item['object']['status']) {
			$changed = $item['object']['id'];
			$wpdb->update($wpdb->posts, array('post_name' => 'changed-by-hand-' . $changed), array('ID' => $changed));
			clean_post_cache($changed);
			break;
		}
	}

	// Addresses before: every object's own, children of the lab pages, posts under the category.
	$modified = $wpdb->get_results("SELECT ID, post_modified FROM `{$wpdb->posts}` ORDER BY ID", OBJECT_K);
	$before = array();
	foreach ($auto as $item) {
		$object = $item['object'];
		$url = Parich_Slug_Redirects::target_url($object['type'], $object['id']);
		$owner = $url ? pls_outcome($url) : null;
		// The address is the object's when WordPress serves it there, or serves nothing there
		// (a slug cut inside a character) and no other object has the same slug (D4).
		$shared = 'post' === $object['type']
			? (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `{$wpdb->posts}` WHERE post_name = %s AND post_type = %s AND ID <> %d", $object['slug'], $object['subtype'], $object['id']))
			: (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `{$wpdb->terms}` t JOIN `{$wpdb->term_taxonomy}` tt ON tt.term_id = t.term_id WHERE t.slug = %s AND tt.taxonomy = %s AND t.term_id <> %d", $object['slug'], $object['subtype'], $object['id']));
		$before[$object['type'] . '-' . $object['id']] = array('url' => $url, 'served' => 'ok:' . $object['id'] === $owner || ('404' === $owner && 0 === $shared));
	}
	$page_children = array();
	foreach ($wpdb->get_col("SELECT p.ID FROM `{$wpdb->posts}` p JOIN `{$wpdb->postmeta}` m ON m.post_id = p.ID AND m.meta_key = '_pls_lab' AND m.meta_value = 'page' WHERE p.post_parent <> 0 ORDER BY p.ID") as $child) {
		$page_children[(int) $child] = get_permalink((int) $child);
	}
	$category_urls = array();
	foreach ($category_posts as $id) {
		$category_urls[$id] = get_permalink($id);
	}

	WP_CLI::log('Repair');
	$hooks = array_fill_keys(array_keys($hooks), 0);
	$state = Parich_Slug_Repair_Applier::run($choices);
	pls_check('the repair finishes', !is_wp_error($state) && !empty($state['finished']), is_wp_error($state) ? $state->get_error_message() : '');
	$skipped = wp_list_pluck($state['skipped'], 'reason', 'key');
	pls_check('the item changed after the scan is skipped, with the reason', null === $changed || (isset($skipped['post-' . $changed]) && false !== strpos($skipped['post-' . $changed], 'changed')), wp_json_encode($state['skipped']));
	pls_check('every other item is repaired', count($keys) - (null === $changed ? 0 : 1) === $state['applied'] && count($skipped) === (null === $changed ? 0 : 1), "applied {$state['applied']} of " . count($keys) . '; skipped ' . wp_json_encode($skipped, JSON_UNESCAPED_UNICODE));

	if ('no-redirects' === $negative) {
		$wpdb->query('DELETE FROM `' . Parich_Slug_Redirects::table() . '`');
		Parich_Slug_Redirects::recount();
		WP_CLI::warning('Negative control: redirects deleted after the repair.');
	}

	$wrong = array();
	foreach ($keys as $key) {
		if ('post-' . $changed === $key) {
			continue;
		}
		list($type, $id) = explode('-', $key);
		$now = 'post' === $type ? $wpdb->get_var($wpdb->prepare("SELECT post_name FROM `{$wpdb->posts}` WHERE ID = %d", $id)) : $wpdb->get_var($wpdb->prepare("SELECT slug FROM `{$wpdb->terms}` WHERE term_id = %d", $id));
		if (!isset($clean[$key]) || $now !== $clean[$key]) {
			$wrong[] = $key;
		}
	}
	pls_check('every repaired slug is the ground truth', !$wrong && $keys, implode(', ', $wrong));
	$after = $wpdb->get_results("SELECT ID, post_modified FROM `{$wpdb->posts}` ORDER BY ID", OBJECT_K);
	pls_check('post_modified unchanged on every post', $after == $modified);
	pls_check('no save_post / post_updated / wp_insert_post / edited_term during the repair', 0 === array_sum($hooks), wp_json_encode($hooks));
	$log = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM `' . Parich_Slug_Repair_Applier::log_table() . "` WHERE batch_id = %d AND status = 'applied'", $state['batch']));
	pls_check('one log row per repaired item', $log === $state['applied']);
	$missing_backup = array();
	$missing_shadow = array();
	foreach ($keys as $key) {
		if ('post-' . $changed === $key) {
			continue;
		}
		list($type, $id) = explode('-', $key);
		$latest = $wpdb->get_var($wpdb->prepare("SELECT kpcode_post_name FROM `{$wpdb->prefix}kpcode_url_posts` WHERE kpcode_post_id = %d AND kpcode_type = %s ORDER BY id DESC LIMIT 1", $id, 'post' === $type ? 'posts' : 'terms'));
		if ($latest !== $clean[$key]) {
			$missing_backup[] = $key;
		}
		$shadow = $wpdb->get_var($wpdb->prepare('SELECT slug FROM `' . Parich_Slug_Shadow::table() . '` WHERE object_type = %s AND object_id = %d', $type, $id));
		if (strlen($clean[$key]) > 200 ? $shadow !== $clean[$key] : null !== $shadow) {
			$missing_shadow[] = $key;
		}
	}
	pls_check('Long URL Maker\'s backup table ends with the repaired slug of each item', !$missing_backup, implode(', ', $missing_backup));
	pls_check('the shadow copy holds every repaired slug over 200 characters', !$missing_shadow, implode(', ', $missing_shadow));
	if ($system_ids) {
		$in = implode(',', array_map('intval', $system_ids));
		$repaired_system = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM `' . Parich_Slug_Repair_Applier::log_table() . "` WHERE batch_id = %d AND object_id IN ({$in})", $state['batch']));
		$system_redirects = (int) $wpdb->get_var('SELECT COUNT(*) FROM `' . Parich_Slug_Redirects::table() . "` WHERE object_type = 'post' AND object_id IN ({$in})");
		pls_check("system keys repaired ({$repaired_system}) make no redirect", $repaired_system > 0 && 0 === $system_redirects, "{$system_redirects} redirects");
	}

	WP_CLI::log('Addresses');
	$bad = array();
	$checked = 0;
	foreach ($before as $key => $was) {
		list($type, $id) = explode('-', $key);
		if ('post-' . $changed === $key || !$was['url'] || !$was['served']) {
			continue;
		}
		$new = Parich_Slug_Redirects::target_url($type, (int) $id);
		if (Parich_Slug_Repair_Util::path_of($was['url']) === Parich_Slug_Repair_Util::path_of($new)) {
			continue;
		}
		$old = pls_outcome($was['url']);
		$now = pls_outcome($new);
		if (0 === strpos($old, 'ok:') && 'ok:' . $id !== $old) {
			continue; // Another object keeps that slug (they shared it): the address stays its own.
		}
		++$checked;
		if ('redirect:' . $new !== $old || 'ok:' . $id !== $now) {
			$bad[] = "{$key}: old → {$old}, new → {$now}";
		}
	}
	pls_check("every old address (served or cut into a 404) 301s to the repaired one, which is served ({$checked} checked)", $checked > 0 && !$bad, implode('; ', array_slice($bad, 0, 3)));
	if ('B' === $scenario) {
		$bad = array();
		$moved = 0;
		foreach ($page_children as $id => $url) {
			$new = get_permalink($id);
			if ($url !== $new) {
				++$moved;
				if ('redirect:' . $new !== pls_outcome($url)) {
					$bad[] = $id;
				}
			}
		}
		pls_check("old addresses of child pages (parents repaired) 301 to their new address ({$moved} checked)", $moved > 0 && !$bad, implode(', ', $bad));
		$bad = array();
		foreach ($category_urls as $id => $url) {
			$new = get_permalink($id);
			if ($url === $new || 'redirect:' . $new !== pls_outcome($url)) {
				$bad[] = "{$id}: {$url} → " . pls_outcome($url);
			}
		}
		pls_check('with /%category%/%postname%/, old addresses of posts under a repaired category 301', $category_urls && !$bad, implode('; ', $bad));
	}
	$ids = array();
	foreach ($keys as $key) {
		if (0 === strpos($key, 'post-') && 'post-' . $changed !== $key && 'publish' === get_post_status((int) substr($key, 5)) && is_post_publicly_viewable((int) substr($key, 5))) {
			$ids[] = (int) substr($key, 5);
		}
	}
	$lost = array_filter($ids, function ($id) {
		return url_to_postid(get_permalink($id)) !== $id;
	});
	pls_check('url_to_postid() finds every repaired post from its new address', $ids && !$lost, implode(', ', $lost));

	WP_CLI::log('Edge cases');
	$redirected = null;
	foreach ($before as $key => $was) {
		if (0 === strpos($key, 'post-') && $was['served'] && $was['url'] && 'post-' . $changed !== $key && 'publish' === get_post_status((int) substr($key, 5)) && 'redirect:' . get_permalink((int) substr($key, 5)) === pls_outcome($was['url'])) {
			$redirected[] = array((int) substr($key, 5), $was['url']);
		}
	}
	// An address cut inside a character can never be served, whoever has that slug:
	// the "taken" case needs a complete one, put first.
	usort($redirected, function ($a, $b) {
		$cut = function ($entry) {
			// The raw address: path_of() turns a trailing "%e" into "%25e".
			return (int) Parich_Slug_Repair_Detectors::has_partial_escape(Parich_Slug_Repair_Util::last_segment((string) wp_parse_url($entry[1], PHP_URL_PATH)));
		};
		return $cut($a) - $cut($b);
	});
	if (count((array) $redirected) >= 2) {
		list($taken_id, $taken_url) = $redirected[0];
		$segment = Parich_Slug_Repair_Util::last_segment((string) wp_parse_url($taken_url, PHP_URL_PATH));
		if (Parich_Slug_Repair_Detectors::has_partial_escape($segment)) {
			WP_CLI::log('  (every old address in this snapshot was cut inside a character: the "taken" case is checked on the other snapshot)');
		} else {
			$new_post = wp_insert_post(array('post_title' => 'Takes an old address', 'post_status' => 'publish', 'post_type' => get_post_type($taken_id)));
			// The exact old slug (wp_insert_post() would clean characters Long URL Maker kept).
			$wpdb->update($wpdb->posts, array('post_name' => $segment), array('ID' => $new_post));
			clean_post_cache($new_post);
			pls_check('an old address that a real post now has is served, not redirected', 'ok:' . $new_post === pls_outcome($taken_url), pls_outcome($taken_url));
		}
		list($gone_id, $gone_url) = $redirected[1];
		wp_delete_post($gone_id, true);
		pls_check('when the repaired post is deleted, its old address is a 404 again', '404' === pls_outcome($gone_url));
	} else {
		pls_check('edge cases need two redirected posts', false, 'found ' . count((array) $redirected));
	}
	$home = pls_outcome(home_url('/'));
	pls_check('the front page is not touched', 0 === strpos($home, 'ok:'));

	WP_CLI::log('Undo');
	$kept_id = null;
	foreach ($ids as $id) {
		if ($id !== ($redirected[0][0] ?? 0) && $id !== ($redirected[1][0] ?? 0)) {
			$kept_id = $id;
			$wpdb->update($wpdb->posts, array('post_name' => 'edited-after-repair'), array('ID' => $id));
			clean_post_cache($id);
			break;
		}
	}
	$restored = 0;
	$kept = array();
	do {
		$result = Parich_Slug_Repair_Applier::undo($state['batch'], 25);
		$restored += $result['restored'];
		$kept = array_merge($kept, $result['kept']);
	} while ($result['remaining'] > 0);
	$deleted = isset($redirected[1]) ? 1 : 0;
	pls_check('undo puts back every slug not changed since (deleted post and the edited one excepted)', $restored === $state['applied'] - 1 - $deleted, "restored {$restored} of {$state['applied']}");
	pls_check('…and keeps the slug edited after the repair, saying why', 1 + $deleted === count($kept) && in_array('post-' . $kept_id, wp_list_pluck($kept, 'key'), true) && 'edited-after-repair' === get_post_field('post_name', $kept_id, 'raw'));
	$wrong = array();
	foreach ($auto as $item) {
		$object = $item['object'];
		$id = (int) $object['id'];
		if ($id === $kept_id || $id === $changed || $id === ($redirected[1][0] ?? 0)) {
			continue;
		}
		$now = 'post' === $object['type'] ? get_post_field('post_name', $id, 'raw') : get_term($id)->slug;
		if ($now !== $object['slug']) {
			$wrong[] = $object['type'] . '-' . $id;
		}
	}
	pls_check('every undone slug is exactly what the scan saw', !$wrong, implode(', ', $wrong));
	$active = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM `' . Parich_Slug_Redirects::table() . "` WHERE batch_id = %d AND is_active = 1", $state['batch']));
	pls_check('the batch\'s redirects are off', 0 === $active && 0 === (int) get_option(Parich_Slug_Redirects::COUNT_OPTION));

	WP_CLI::log('Long URL Maker\'s restore after a repair (L12)');
	$source = (string) file_get_contents(WP_PLUGIN_DIR . '/long-url-maker/long_url_maker.php');
	$start = strpos($source, 'function kpcode_restore_db_posts(');
	$end = strpos($source, '/// check unique post slug', $start);
	if (false === $start || false === $end) {
		pls_check('Long URL Maker\'s restore function was found in its file', false);
	} else {
		eval(str_replace('function kpcode_restore_db_posts(', 'function pls_lab_kpcode_restore(', substr($source, $start, $end - $start))); // phpcs:ignore -- runs the real restore code of Long URL Maker against the snapshot.
		$state2 = Parich_Slug_Repair_Applier::run($choices);
		// The posts this second repair changed (it skips the one edited after the first).
		$repaired = array();
		foreach ($wpdb->get_results($wpdb->prepare('SELECT object_id, new_slug FROM ' . Parich_Slug_Repair_Applier::log_table() . " WHERE batch_id = %d AND object_type = 'post' AND status = 'applied'", $state2['batch'])) as $row) {
			$repaired[(int) $row->object_id] = $row->new_slug;
		}
		pls_lab_kpcode_restore();
		wp_cache_flush();
		$reverted = array_filter($repaired, function ($slug, $id) use ($wpdb) {
			return $wpdb->get_var($wpdb->prepare("SELECT post_name FROM `{$wpdb->posts}` WHERE ID = %d", $id)) !== $slug;
		}, ARRAY_FILTER_USE_BOTH);
		pls_check('repaired again; running its restore changes none of the repaired slugs', !is_wp_error($state2) && $repaired && !$reverted, implode(', ', array_keys($reverted)));
		// Negative control: without the row this plugin adds, its restore puts the damaged slug back.
		$victim = (int) key($repaired);
		$wpdb->query($wpdb->prepare("DELETE FROM `{$wpdb->prefix}kpcode_url_posts` WHERE kpcode_post_id = %d AND kpcode_type = 'posts' AND kpcode_post_name = %s", $victim, $repaired[$victim]));
		pls_lab_kpcode_restore();
		pls_check('negative: without that row, its restore writes another slug over the repair', $wpdb->get_var($wpdb->prepare("SELECT post_name FROM `{$wpdb->posts}` WHERE ID = %d", $victim)) !== $repaired[$victim]);
	}
} finally {
	$wpdb->query('ROLLBACK');
	$wpdb->query('SET autocommit = 1');
}

$passes = $GLOBALS['pls_passes'];
$failures = $GLOBALS['pls_failures'];
WP_CLI::log("PLS_APPLY_JSON " . wp_json_encode(array('scenario' => $scenario, 'passes' => $passes, 'failures' => $failures, 'applied' => isset($state['applied']) ? $state['applied'] : 0, 'redirects' => isset($state['redirects']) ? $state['redirects'] : 0)));
if ($failures) {
	WP_CLI::error(count($failures) . " of " . ($passes + count($failures)) . " checks failed:\n  " . implode("\n  ", $failures));
}
WP_CLI::success("Scenario {$scenario}: {$passes} checks passed.");
