<?php
/**
 * Phase 6: Settings → Long Slug — the length setting (registered with a literal
 * sanitize callback), the preview, the status table, capabilities and escaping.
 * Table copies for the status checks are made outside the transaction
 * (PLS_TEST_MANUAL_TX); everything else runs inside pls_test_begin()/rollback().
 *
 * @package Parich_Long_Slug
 */

define('PLS_TEST_MANUAL_TX', true);
require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/../fixtures/helpers.php';
pls_test_catch_exits();

global $wpdb, $wp_registered_settings;

function pls_status_row(array $rows, $key)
{
	foreach ($rows as $row) {
		if ($key === $row['key']) {
			return $row;
		}
	}
	return null;
}

// Copies for the status checks: slug columns at 200 and a Long URL Maker backup table.
$posts = pls_test_clone_table($wpdb->posts);
$terms = pls_test_clone_table($wpdb->terms);
$info = Parich_Slug_Schema::get_column_info($posts, 'post_name');
$wpdb->query("ALTER TABLE `{$posts}` MODIFY `post_name` varchar(200) CHARACTER SET {$info['charset']} COLLATE {$info['collation']} NOT NULL DEFAULT ''");
$legacy_table = $wpdb->prefix . 'pls_t_kpcode_url_posts';
$wpdb->query("DROP TABLE IF EXISTS `{$legacy_table}`");
$wpdb->query("CREATE TABLE `{$legacy_table}` (id bigint(20) NOT NULL AUTO_INCREMENT, kpcode_post_id bigint(20) NOT NULL DEFAULT 0, kpcode_post_name varchar(2000) NOT NULL DEFAULT '', kpcode_type varchar(200) NOT NULL DEFAULT '', UNIQUE KEY id (id))");
$GLOBALS['pls_test_clones'][] = $legacy_table;
$wpdb->query("INSERT INTO `{$legacy_table}` (kpcode_post_id, kpcode_post_name, kpcode_type) VALUES (1, 'a', 'posts'), (2, 'b', 'posts'), (3, 'c', 'terms')");

pls_test_begin();
$admin = pls_test_user('administrator');
$editor = pls_test_user('editor');
wp_set_current_user($admin);

pls_test_section('Setting');
$setting = $wp_registered_settings[Parich_Slug_Sanitize::MAX_OPTION] ?? array();
pls_test_assert(
	'parich_slug_max_length is registered: type integer, its own literal sanitize callback, default 1000',
	'integer' === ($setting['type'] ?? '') && array('Parich_Slug_Admin', 'sanitize_max_length') === ($setting['sanitize_callback'] ?? null) && 1000 === ($setting['default'] ?? null) && 'parich_slug' === ($setting['group'] ?? '')
);
pls_test_assert('the source registers it with a literal callback (what reviewers\' static tools look for)', false !== strpos((string) file_get_contents(PLS_TEST_PLUGIN_DIR . '/includes/class-parich-slug-admin.php'), "'sanitize_callback' => array(__CLASS__, 'sanitize_max_length')"));
foreach (array(array('1500', '1500'), array('50', '200'), array('5000', '1900')) as list($input, $stored)) {
	update_option(Parich_Slug_Sanitize::MAX_OPTION, $input);
	pls_test_assert("saving {$input} stores {$stored}", $stored === (string) get_option(Parich_Slug_Sanitize::MAX_OPTION));
}
global $wp_settings_errors;
$wp_settings_errors = array();
update_option(Parich_Slug_Sanitize::MAX_OPTION, 'abc');
$codes = wp_list_pluck(get_settings_errors(Parich_Slug_Admin::GROUP), 'code');
pls_test_assert('saving "abc" keeps 1900 and explains why', '1900' === (string) get_option(Parich_Slug_Sanitize::MAX_OPTION) && in_array('parich_slug_max_length_invalid', $codes, true));
remove_filter('sanitize_option_' . Parich_Slug_Sanitize::MAX_OPTION, array('Parich_Slug_Admin', 'sanitize_max_length'));
update_option(Parich_Slug_Sanitize::MAX_OPTION, '50');
pls_test_assert('negative control — without the sanitize callback "50" would be stored as is', '50' === (string) get_option(Parich_Slug_Sanitize::MAX_OPTION));
add_filter('sanitize_option_' . Parich_Slug_Sanitize::MAX_OPTION, array('Parich_Slug_Admin', 'sanitize_max_length'));
pls_test_assert('saving goes through options.php with this page\'s capability', 'manage_options' === apply_filters('option_page_capability_' . Parich_Slug_Admin::GROUP, 'manage_options'));

pls_test_section('New slugs follow the setting, existing slugs stay');
update_option(Parich_Slug_Sanitize::MAX_OPTION, '1000');
$title = 'มหาวิทยาลัยราชภัฏมหาสารคามจัดโครงการพัฒนาศักยภาพบุคลากรสายวิชาการและสายสนับสนุน';
list(, $before) = pls_rest('POST', '/wp/v2/posts', array('title' => $title, 'status' => 'publish'));
$before_slug = pls_slug_of($before['id']);
update_option(Parich_Slug_Sanitize::MAX_OPTION, '300');
list(, $after) = pls_rest('POST', '/wp/v2/posts', array('title' => $title . ' ใหม่', 'status' => 'publish'));
clean_post_cache($before['id']);
pls_test_assert('the post saved at 1000 keeps its ' . strlen($before_slug) . '-byte slug', $before_slug === pls_slug_of($before['id']) && strlen($before_slug) > 300);
pls_test_assert('a new post gets at most 300 bytes', strlen(pls_slug_of($after['id'])) <= 300);
delete_option(Parich_Slug_Sanitize::MAX_OPTION);

pls_test_section('Preview (admin-ajax)');
$nonce = wp_create_nonce(Parich_Slug_Admin::PREVIEW_ACTION);
list($output, $exit) = pls_test_ajax(array('Parich_Slug_Admin', 'ajax_preview'), array('nonce' => $nonce, 'title' => $title, 'max' => '300'));
$json = json_decode($output, true);
pls_test_assert('administrator gets the slug, its length and whether it was cut', !empty($json['success']) && $json['data']['bytes'] <= 300 && true === $json['data']['cut'] && 0 === strpos($title, $json['data']['readable']), substr($output, 0, 200));
list($output) = pls_test_ajax(array('Parich_Slug_Admin', 'ajax_preview'), array('nonce' => $nonce, 'title' => '<script>alert(1)</script> ข่าว', 'max' => '1000'));
pls_test_assert('markup in the title never comes back as markup', false === strpos($output, '<script') && false === stripos(json_decode($output, true)['data']['readable'] ?? '<', '<'));
list($output, $exit) = pls_test_ajax(array('Parich_Slug_Admin', 'ajax_preview'), array('title' => $title));
pls_test_assert('without a nonce: 403', $exit && 403 === $exit->status && false === strpos($output, '"success":true'));
wp_set_current_user($editor);
list($output, $exit) = pls_test_ajax(array('Parich_Slug_Admin', 'ajax_preview'), array('nonce' => wp_create_nonce(Parich_Slug_Admin::PREVIEW_ACTION), 'title' => $title));
pls_test_assert('editor with a nonce: 403', $exit && 403 === $exit->status && false === strpos($output, '"success":true'));

pls_test_section('Page and plugin row');
try {
	ob_start();
	Parich_Slug_Admin::render_page();
	ob_end_clean();
	$exit = null;
} catch (PLS_Test_Exit $e) {
	ob_end_clean();
	$exit = $e;
}
pls_test_assert('editor cannot open the page (403)', $exit && 403 === $exit->status);
wp_set_current_user($admin);
ob_start();
Parich_Slug_Admin::render_page();
$html = (string) ob_get_clean();
pls_test_assert('the form posts to options.php with the setting\'s group and nonce', false !== strpos($html, 'options.php') && false !== strpos($html, "name='option_page' value='parich_slug'") && false !== strpos($html, 'name="parich_slug_max_length"'));
pls_test_assert('the status table and the "Set up again" button are there', false !== strpos($html, 'Slug columns') && false !== strpos($html, 'Set up again') && false !== strpos($html, 'value="' . Parich_Slug_Schema::FIX_ACTION . '"'));
$links = apply_filters('plugin_action_links_' . PLS_TEST_PLUGIN_BASENAME, array());
pls_test_assert('the Plugins screen row links to the page', isset($links[0]) && false !== strpos($links[0], 'options-general.php?page=parich-long-slug'));

pls_test_section('Status matches the site');
$rows = Parich_Slug_Admin::status();
$row = pls_status_row($rows, 'columns');
pls_test_assert('this site: both columns varchar(2000) → ok', 'ok' === $row['state'] && 2 === substr_count($row['value'], 'varchar(2000)'));
Parich_Slug_Guard::install();
$row = pls_status_row(Parich_Slug_Admin::status(), 'guard');
pls_test_assert('guard present → ok', 'ok' === $row['state']);
pls_test_assert('a guard outside the site root is shown with its full path', false !== strpos(wp_normalize_path($row['value']), PLS_TEST_GUARD_DIR . '/parich-long-slug-guard.php'), $row['value']);
$in_root = function () {
	return WPMU_PLUGIN_DIR . '/parich-long-slug-guard.php';
};
add_filter('parich_slug_guard_file', $in_root, 20);
$row = pls_status_row(Parich_Slug_Admin::status(), 'guard');
remove_filter('parich_slug_guard_file', $in_root, 20);
pls_test_assert('…one inside it relative to the root (no server path or user name on screen)', false !== strpos($row['value'], ' — wp-content/mu-plugins/parich-long-slug-guard.php') && false === strpos(wp_normalize_path($row['value']), wp_normalize_path(ABSPATH)), $row['value']);
Parich_Slug_Guard::remove();
pls_test_assert('guard missing → error', 'error' === pls_status_row(Parich_Slug_Admin::status(), 'guard')['state']);
pls_test_assert('shadow table → ok', 'ok' === pls_status_row($rows, 'shadow')['state']);
pls_test_assert('no repair after an update yet → "Never needed"', 'Never needed' === pls_status_row($rows, 'heal')['value']);
pls_test_assert('Long URL Maker installed but never used here → ok', 'ok' === pls_status_row($rows, 'legacy')['state'] && 'Installed, never used' === pls_status_row($rows, 'legacy')['value']);

$rows = Parich_Slug_Admin::status(array($posts => 'post_name', $terms => 'slug'), $legacy_table);
pls_test_assert('a copy at varchar(200) → error with an explanation', 'error' === pls_status_row($rows, 'columns')['state'] && false !== strpos(pls_status_row($rows, 'columns')['value'], 'varchar(200)'));
$legacy = pls_status_row($rows, 'legacy');
pls_test_assert('a Long URL Maker backup table (copy, 3 rows) → warning that it was used here', 'warning' === $legacy['state'] && false !== strpos($legacy['value'], '3 rows'));
add_option('kpcode_longurlmaker_version', '2.0');
pls_test_assert('its settings left in the options table are noticed (the guard hides them from get_option)', Parich_Slug_Legacy::traces()['options'] && 'warning' === pls_status_row(Parich_Slug_Admin::status(), 'legacy')['state']);
$repair = function () {
	return admin_url('tools.php?page=parich-long-slug-repair');
};
add_filter('parich_slug_repair_url', $repair);
pls_test_assert('…with a link to the repair tool once one is available', false !== strpos(pls_status_row(Parich_Slug_Admin::status(), 'legacy')['note'], 'parich-long-slug-repair'));
remove_filter('parich_slug_repair_url', $repair);

update_option(Parich_Slug_Schema::LAST_HEAL_OPTION, array('time' => time(), 'restored' => 4, 'skipped' => array('post:9')), false);
pls_test_assert('a recorded repair after an update → shown with its counts', false !== strpos(pls_status_row(Parich_Slug_Admin::status(), 'heal')['value'], 'restored 4, left alone 1'));

$long_max = function () {
	return 1500;
};
add_filter('parich_slug_max_length', $long_max);
$long_title = str_repeat('มหาวิทยาลัยราชภัฏมหาสารคาม', 8);
list(, $parent) = pls_rest('POST', '/wp/v2/pages', array('title' => $long_title, 'status' => 'publish'));
list(, $child) = pls_rest('POST', '/wp/v2/pages', array('title' => $long_title . ' ลูก', 'status' => 'publish', 'parent' => $parent['id']));
remove_filter('parich_slug_max_length', $long_max);
$found = Parich_Slug_Admin::long_permalinks()['ids'];
pls_test_assert(
	'a child page under a long parent (' . strlen(get_permalink($child['id'])) . ' characters) is listed; the parent (' . strlen(get_permalink($parent['id'])) . ') is not',
	in_array($child['id'], $found, true) && !in_array($parent['id'], $found, true) && 'warning' === pls_status_row(Parich_Slug_Admin::status(), 'long_urls')['state']
);

pls_test_section('Escaping');
update_option(Parich_Slug_Schema::LAST_HEAL_OPTION, array('time' => time(), 'restored' => 0, 'skipped' => array('<script>alert(1)</script>')), false);
update_option(Parich_Slug_Schema::HEAL_NOTICE_OPTION, 1, false);
ob_start();
Parich_Slug_Admin::render_page();
Parich_Slug_Schema::admin_notices();
$html = (string) ob_get_clean();
pls_test_assert('values from the database are escaped on the page and in notices', false === strpos($html, '<script>alert(1)</script>') && false !== strpos($html, '&lt;script&gt;'));

pls_test_section('Donate');
$meta = apply_filters('plugin_row_meta', array(), PLS_TEST_PLUGIN_BASENAME, array(), 'all');
$other = apply_filters('plugin_row_meta', array(), 'hello.php', array(), 'all');
pls_test_assert('a "Donate" link under the plugin\'s description on the Plugins screen, to the Stripe page', 1 === count(array_filter($meta, function ($link) {
	return false !== strpos($link, 'href="' . PARICH_SLUG_DONATE_URL . '"') && false !== strpos($link, '>Donate<');
})));
pls_test_assert('…on this plugin\'s row only', !array_filter($other, function ($link) {
	return false !== strpos($link, PARICH_SLUG_DONATE_URL);
}));
pls_test_assert('the Settings tab ends with "Support this plugin" and a Donate button (decorative heart hidden from screen readers)', false !== strpos($html, 'class="parich-slug-support"') && false !== strpos($html, 'class="button button-primary button-hero" href="' . PARICH_SLUG_DONATE_URL . '"') && false !== strpos($html, 'dashicons-heart" aria-hidden="true"'));
wp_dequeue_style('parich-slug-admin-settings');
Parich_Slug_Admin::enqueue('index.php');
$elsewhere = wp_style_is('parich-slug-admin-settings', 'enqueued');
Parich_Slug_Admin::enqueue('settings_page_' . Parich_Slug_Admin::PAGE);
pls_test_assert('its style loads on this plugin\'s page only, with Dashicons', !$elsewhere && wp_style_is('parich-slug-admin-settings', 'enqueued') && in_array('dashicons', wp_styles()->registered['parich-slug-admin-settings']->deps, true) && is_file(PLS_TEST_PLUGIN_DIR . '/assets/admin-settings.css'));

pls_test_rollback();
pls_test_done();
