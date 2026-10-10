<?php
/**
 * Phase 11 (L10): uninstall.php on a copy of this site (tables copied under the prefix
 * plsun_, WordPress pointed at them): this plugin's tables, options and notices go;
 * slug columns, slugs and Long URL Maker's table and options stay; the guard stays
 * while a slug longer than 200 characters remains and goes after. DDL on copies only
 * (PLS_TEST_MANUAL_TX); the copies are dropped at the end.
 *
 * @package Parich_Long_Slug
 */

define('PLS_TEST_MANUAL_TX', true);
require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/../fixtures/helpers.php';

global $wpdb;
$site_prefix = $wpdb->prefix;
$copy = 'plsun_';
$plugin_tables = array('parich_slug_shadow', 'parich_slug_scan_items', 'parich_slug_scan_urls', 'parich_slug_repair_log', 'parich_slug_redirects');
foreach (array_merge(array('posts', 'postmeta', 'terms', 'term_taxonomy', 'termmeta', 'term_relationships', 'options'), $plugin_tables) as $name) {
	$wpdb->query("DROP TABLE IF EXISTS `{$copy}{$name}`");
	$wpdb->query("CREATE TABLE `{$copy}{$name}` LIKE `{$site_prefix}{$name}`");
	$wpdb->query("INSERT INTO `{$copy}{$name}` SELECT * FROM `{$site_prefix}{$name}`");
	$GLOBALS['pls_test_clones'][] = $copy . $name;
}
$wpdb->query("CREATE TABLE `{$copy}kpcode_url_posts` (id bigint(20) NOT NULL AUTO_INCREMENT, kpcode_post_id bigint(20) NOT NULL DEFAULT 0, kpcode_post_name varchar(2000) NOT NULL DEFAULT '', kpcode_type varchar(200) NOT NULL DEFAULT '', UNIQUE KEY id (id))");
$GLOBALS['pls_test_clones'][] = $copy . 'kpcode_url_posts';

// WordPress on the copy (users stay the site's).
$users = array($wpdb->users, $wpdb->usermeta);
$wpdb->set_prefix($copy);
list($wpdb->users, $wpdb->usermeta) = $users;
wp_cache_flush();
pls_test_assert('precondition: WordPress points at the copy, which has every table of this plugin', $copy . 'posts' === $wpdb->posts && count($plugin_tables) === count(array_filter($plugin_tables, function ($name) use ($wpdb, $copy) {
	return $copy . $name === $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $copy . $name));
})));

// Fixtures on the copy: a long slug, Long URL Maker's traces, notices waiting for a user, a redirect count.
$long = str_repeat('%e0%b8%81', 30);
$wpdb->insert($wpdb->posts, array('post_title' => 'Long', 'post_name' => $long, 'post_status' => 'publish', 'post_type' => 'post', 'post_content' => '', 'post_excerpt' => '', 'to_ping' => '', 'pinged' => '', 'post_content_filtered' => '', 'post_date' => current_time('mysql'), 'post_date_gmt' => current_time('mysql', true), 'post_modified' => current_time('mysql'), 'post_modified_gmt' => current_time('mysql', true)));
$long_id = (int) $wpdb->insert_id;
$wpdb->insert($copy . 'kpcode_url_posts', array('kpcode_post_id' => $long_id, 'kpcode_post_name' => $long, 'kpcode_type' => 'posts'));
update_option('kpcode_longurlmaker_version', '2.0');
foreach (array('parich_slug_scan' => array('id' => 1), 'parich_slug_redirect_count' => 3, 'parich_slug_apply' => array('batch' => 1), 'parich_slug_max_length' => 900) as $name => $value) {
	update_option($name, $value);
}
set_transient('parich_slug_repair_notice_1', array('type' => 'success', 'message' => 'x'), 600);
set_transient('parich_slug_legacy_notice_1', 'x', 600);
wp_cache_flush();

function pls_slugs_hash()
{
	global $wpdb;
	return md5(serialize(array($wpdb->get_results("SELECT ID, post_name FROM {$wpdb->posts} ORDER BY ID", ARRAY_N), $wpdb->get_results("SELECT term_id, slug FROM {$wpdb->terms} ORDER BY term_id", ARRAY_N))));
}

$guard = Parich_Slug_Guard::path();
Parich_Slug_Guard::install();
pls_test_assert('precondition: guard installed (test folder) and a slug over 200 characters exists', Parich_Slug_Guard::is_current() && strlen($long) > 200);
$slugs_before = pls_slugs_hash();
$columns_before = array(Parich_Slug_Schema::get_column_info($wpdb->posts, 'post_name'), Parich_Slug_Schema::get_column_info($wpdb->terms, 'slug'));

pls_test_section('Uninstall while a long slug remains');
define('WP_UNINSTALL_PLUGIN', 'parich-long-slug/parich-long-slug.php');
include PLS_TEST_PLUGIN_DIR . '/uninstall.php';
wp_cache_flush();
$left = array_filter($plugin_tables, function ($name) use ($wpdb, $copy) {
	return (bool) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $copy . $name));
});
pls_test_assert('every table of this plugin is gone (redirects included)', !$left, implode(', ', $left));
$options = $wpdb->get_col("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'parich\\_slug\\_%' OR option_name LIKE '\\_transient%parich\\_slug\\_%'");
pls_test_assert('no option or notice of this plugin is left', !$options, implode(', ', $options));
pls_test_assert('slugs are unchanged', pls_slugs_hash() === $slugs_before);
pls_test_assert('the slug columns are not shortened', array(Parich_Slug_Schema::get_column_info($wpdb->posts, 'post_name'), Parich_Slug_Schema::get_column_info($wpdb->terms, 'slug')) == $columns_before && 2000 === $columns_before[0]['length']);
pls_test_assert('Long URL Maker\'s table and settings are left alone', (bool) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $copy . 'kpcode_url_posts')) && '2.0' === $wpdb->get_var("SELECT option_value FROM {$wpdb->options} WHERE option_name = 'kpcode_longurlmaker_version'"));
pls_test_assert('the guard stays: a slug over 200 characters still needs it', is_file($guard));

pls_test_section('Uninstall when no long slug remains');
// On the copy only: the site itself may have long slugs of its own.
$wpdb->query("DELETE FROM {$wpdb->posts} WHERE CHAR_LENGTH(post_name) > 200");
$wpdb->query("DELETE FROM {$wpdb->terms} WHERE CHAR_LENGTH(slug) > 200");
pls_test_assert('precondition: the copy has no slug over 200 characters now', $copy . 'posts' === $wpdb->posts && 0 === (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE CHAR_LENGTH(post_name) > 200"));
include PLS_TEST_PLUGIN_DIR . '/uninstall.php';
pls_test_assert('the guard is removed', !is_file($guard));
file_put_contents($guard, "<?php\n// Some other must-use plugin with the same name.\n");
include PLS_TEST_PLUGIN_DIR . '/uninstall.php';
pls_test_assert('a file at that path that is not the guard is left alone', is_file($guard));
wp_delete_file($guard);
pls_test_assert('running it again on a clean site does no harm', (function () {
	include PLS_TEST_PLUGIN_DIR . '/uninstall.php';
	return true;
})());

pls_test_section('Without WordPress\'s uninstall constant');
$code = (string) file_get_contents(PLS_TEST_PLUGIN_DIR . '/uninstall.php');
pls_test_assert('the file stops at once when not run by WordPress\'s uninstaller', false !== strpos($code, "if (!defined('WP_UNINSTALL_PLUGIN')) {\n\texit;"));

$wpdb->set_prefix($site_prefix);
list($wpdb->users, $wpdb->usermeta) = $users;
wp_cache_flush();
pls_test_assert('back on the site: its own tables are untouched', $site_prefix . 'parich_slug_shadow' === $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $site_prefix . 'parich_slug_shadow')));

pls_test_done();
