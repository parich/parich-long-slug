<?php
/**
 * Phase 10 (D6): keys that WordPress or a plugin looks things up by. Every type with a
 * positive and a negative case, the grouping rules (ACF needs two sources, templates
 * are always checked by hand), the context read from the tables (ACF values in post
 * meta, a tie between two keys, acf-json files) inside the rolled-back transaction.
 *
 * @package Parich_Long_Slug
 */

require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/../fixtures/helpers.php';

$keys = 'Parich_Slug_Repair_System_Keys';

function pls_sys_object(array $fields)
{
	return array_merge(array('type' => 'post', 'id' => 900001, 'subtype' => 'acf-field', 'status' => 'publish', 'parent' => 0, 'title' => '', 'slug' => '', 'hierarchical' => false, 'excerpt' => ''), $fields);
}

function pls_sys_context(array $fields = array())
{
	return array_merge(array(
		'themes' => array(),
		'styles_order' => array(),
		'styles_count' => array(),
		'acf_meta' => array(),
		'acf_options' => array(),
		'acf_json' => array('fields' => array(), 'groups' => array()),
		'templates' => Parich_Slug_Repair_System_Keys::templates(),
	), $fields);
}

function pls_sys_facts(array $fields = array())
{
	return array_merge(Parich_Slug_Repair_Evidence::empty_facts(), $fields);
}

pls_test_section('Which post types');
$types = $keys::post_types();
pls_test_assert('ACF, templates, global styles, fonts, custom CSS and caches are checked, even with their plugin inactive', !array_diff(array('acf-field', 'acf-field-group', 'wp_template', 'wp_template_part', 'wp_global_styles', 'wp_font_family', 'custom_css', 'oembed_cache'), $types));
pls_test_assert('menus, reusable blocks, menu items, revisions and public types are not (negative)', !array_intersect(array('wp_navigation', 'wp_block', 'nav_menu_item', 'revision', 'post', 'page', 'attachment'), $types));

pls_test_section('ACF fields');
$field = pls_sys_object(array('title' => 'Lab Field', 'slug' => 'lab-field', 'excerpt' => 'lab_field'));
pls_test_assert('a key in the right form: nothing to do', null === $keys::analyze(pls_sys_object(array('slug' => 'field_5f1a2b3c')), pls_sys_facts(), pls_sys_context()));
$item = $keys::analyze($field, pls_sys_facts(), pls_sys_context());
pls_test_assert('rewritten, used nowhere, not in the backup → "unresolved"', 'unresolved' === $item['group'] && null === $item['proposal'] && array('D6') === $item['codes']);
$item = $keys::analyze($field, pls_sys_facts(), pls_sys_context(array('acf_meta' => array('lab_field' => array('field_aaa111' => 3)))));
pls_test_assert('saved values point at one key → that key, "review" (one source)', 'review' === $item['group'] && 'field_aaa111' === $item['proposal'] && in_array('weak', $item['reasons'], true));
$item = $keys::analyze($field, pls_sys_facts(), pls_sys_context(array('acf_meta' => array('lab_field' => array('field_aaa111' => 2, 'field_bbb222' => 2)))));
pls_test_assert('saved values point at two keys as often → "review" (conflict)', 'review' === $item['group'] && in_array('conflict', $item['reasons'], true));
$item = $keys::analyze($field, pls_sys_facts(), pls_sys_context(array('acf_meta' => array('lab_field' => array('field_aaa111' => 5, 'field_bbb222' => 1)))));
pls_test_assert('…one key clearly more often → that one first, still "review"', 'field_aaa111' === $item['proposal'] && 'review' === $item['group']);
$item = $keys::analyze($field, pls_sys_facts(), pls_sys_context(array('acf_meta' => array('lab_field' => array('field_aaa111' => 3)), 'acf_json' => array('fields' => array('lab_field' => array('field_aaa111')), 'groups' => array()))));
pls_test_assert('saved values and acf-json agree → "auto"', 'auto' === $item['group'] && 'field_aaa111' === $item['proposal']);
$item = $keys::analyze($field, pls_sys_facts(), pls_sys_context(array('acf_options' => array('lab_field' => 'field_ccc333'), 'acf_meta' => array('lab_field' => array('field_ccc333' => 1)))));
pls_test_assert('options page and saved values agree → "auto"', 'auto' === $item['group'] && 'field_ccc333' === $item['proposal']);
$item = $keys::analyze($field, pls_sys_facts(array('s1' => array(array('row' => 4, 'value' => 'field_ddd444')))), pls_sys_context());
pls_test_assert('the backup alone → that key, "review" (ACF needs two sources)', 'review' === $item['group'] && 'field_ddd444' === $item['proposal']);
$item = $keys::analyze($field, pls_sys_facts(array('s1' => array(array('row' => 4, 'value' => 'lab-field')))), pls_sys_context());
pls_test_assert('a backup row that is not a field key is not proposed', 'unresolved' === $item['group']);

pls_test_section('ACF field groups');
$group = pls_sys_object(array('subtype' => 'acf-field-group', 'title' => 'Lab Group', 'slug' => 'lab-group'));
$item = $keys::analyze($group, pls_sys_facts(), pls_sys_context(array('acf_json' => array('fields' => array(), 'groups' => array('Lab Group' => array('group_abc123'))))));
pls_test_assert('acf-json with the same title → its key, always "review"', 'review' === $item['group'] && 'group_abc123' === $item['proposal'] && in_array('system_check', $item['reasons'], true));
pls_test_assert('a disabled group (acf-disabled: never rewritten) is not checked', null === $keys::analyze(array_merge($group, array('status' => 'acf-disabled')), pls_sys_facts(), pls_sys_context()));

pls_test_section('Global styles and custom CSS');
$styles = pls_sys_object(array('id' => 900010, 'subtype' => 'wp_global_styles', 'title' => 'Custom Styles', 'slug' => 'custom-styles'));
$single = pls_sys_context(array('themes' => array(900010 => 'twentytwentyfive'), 'styles_order' => array(900010 => 0), 'styles_count' => array('twentytwentyfive' => 1)));
$item = $keys::analyze($styles, pls_sys_facts(), $single);
pls_test_assert('the only global styles of a theme → wp-global-styles-{theme}, "auto"', 'auto' === $item['group'] && 'wp-global-styles-twentytwentyfive' === $item['proposal']);
$shared = pls_sys_context(array('themes' => array(900010 => 'twentytwentyfive'), 'styles_order' => array(900010 => 1), 'styles_count' => array('twentytwentyfive' => 2)));
$item = $keys::analyze($styles, pls_sys_facts(), $shared);
pls_test_assert('the second of a theme → "-2", "review"', 'review' === $item['group'] && 'wp-global-styles-twentytwentyfive-2' === $item['proposal'] && in_array('shared_theme', $item['reasons'], true));
pls_test_assert('a right name (with or without "-N") → nothing to do', null === $keys::analyze(array_merge($styles, array('slug' => 'wp-global-styles-twentytwentyfive')), pls_sys_facts(), $single) && null === $keys::analyze(array_merge($styles, array('slug' => 'wp-global-styles-twentytwentyfive-3')), pls_sys_facts(), $shared));
$css = pls_sys_object(array('subtype' => 'custom_css', 'title' => 'twentytwentyfive', 'slug' => 'something-else'));
$item = $keys::analyze($css, pls_sys_facts(), pls_sys_context());
pls_test_assert('custom CSS named otherwise than its theme → the theme\'s name, "auto"', 'auto' === $item['group'] && 'twentytwentyfive' === $item['proposal']);
pls_test_assert('custom CSS named like its theme → nothing to do', null === $keys::analyze(array_merge($css, array('slug' => 'twentytwentyfive')), pls_sys_facts(), pls_sys_context()));

pls_test_section('Templates');
$template = pls_sys_object(array('subtype' => 'wp_template', 'title' => 'Single Posts', 'slug' => 'single-posts'));
$item = $keys::analyze($template, pls_sys_facts(), pls_sys_context());
pls_test_assert('a template rewritten from its title → the template with that title first, always "review"', 'review' === $item['group'] && 'single' === Parich_Slug_Repair_Scorer::ranked($item)[0] && in_array('system_check', $item['reasons'], true));
pls_test_assert('a known template slug → nothing to do', null === $keys::analyze(array_merge($template, array('slug' => 'single')), pls_sys_facts(), pls_sys_context()));
pls_test_assert('a slug that is not what the title gives (set on purpose) → nothing to do', null === $keys::analyze(array_merge($template, array('slug' => 'my-own-slug')), pls_sys_facts(), pls_sys_context()));
$part = pls_sys_object(array('subtype' => 'wp_template_part', 'title' => 'Header with large title', 'slug' => 'header-with-large-title'));
$item = $keys::analyze($part, pls_sys_facts(), pls_sys_context());
pls_test_assert('a template part → the theme\'s part with that title', 'header-large-title' === $item['proposal']);
$item = $keys::analyze(pls_sys_object(array('subtype' => 'wp_template', 'title' => 'Landing For Events', 'slug' => 'landing-for-events')), pls_sys_facts(), pls_sys_context());
pls_test_assert('a custom template with no match → the Site Editor\'s name for it, as a weak guess', 'review' === $item['group'] && 'wp-custom-template-landing-for-events' === $item['proposal']);

pls_test_section('Caches and other types');
pls_test_assert('an oEmbed cache with an md5 key → nothing to do', null === $keys::analyze(pls_sys_object(array('subtype' => 'oembed_cache', 'slug' => md5('x'))), pls_sys_facts(), pls_sys_context()));
$item = $keys::analyze(pls_sys_object(array('subtype' => 'oembed_cache', 'slug' => '')), pls_sys_facts(array('s1' => array(array('row' => 1, 'value' => md5('y'))))), pls_sys_context());
pls_test_assert('an oEmbed cache that lost its key → reported only (no proposal)', 'unresolved' === $item['group'] && null === $item['proposal'] && in_array('report_only', $item['reasons'], true));
$redecided = Parich_Slug_Repair_Scorer::decide($item);
pls_test_assert('…and stays so when decided again (as the scanner does)', in_array('report_only', $redecided['reasons'], true));
$font = pls_sys_object(array('subtype' => 'wp_font_family', 'title' => 'My Font', 'slug' => 'my-font'));
pls_test_assert('a font family whose slug its title gives, no backup → nothing to do', null === $keys::analyze($font, pls_sys_facts(), pls_sys_context()));
$item = $keys::analyze($font, pls_sys_facts(array('s1' => array(array('row' => 2, 'value' => 'my-font-v2')))), pls_sys_context());
pls_test_assert('…but the backup knew another slug → that one, "review"', 'review' === $item['group'] && 'my-font-v2' === $item['proposal']);
$item = $keys::analyze(pls_sys_object(array('subtype' => 'some_plugin_type', 'title' => 'Thing', 'slug' => 'thing')), pls_sys_facts(array('csv' => 'thing-key')), pls_sys_context());
pls_test_assert('any other type: only with evidence (here none from the backup) → nothing', null === $item);

pls_test_section('Context from the tables');
global $wpdb;
// acf-json is read once per request: the test folder is added before the first read.
$dir = PLS_TEST_GUARD_DIR . '-acf-json';
wp_mkdir_p($dir);
file_put_contents($dir . '/group_json1.json', wp_json_encode(array('key' => 'group_json1', 'title' => 'Json Group', 'fields' => array(array('key' => 'field_json1', 'name' => 'json_field', 'sub_fields' => array(array('key' => 'field_json2', 'name' => 'json_sub')))))));
add_filter('acf/settings/load_json', function ($paths) use ($dir) {
	$paths[] = $dir;
	return $paths;
});
$holders = array();
foreach (array('field_tie111', 'field_tie111', 'field_tie222', 'field_tie222') as $value) {
	$holder = wp_insert_post(array('post_title' => 'Holder', 'post_status' => 'publish'));
	add_post_meta($holder, '_pls_tie_field', $value);
	$holders[] = $holder;
}
$context = $keys::context(new Parich_Slug_Repair_Source(), array(900020 => pls_sys_object(array('id' => 900020, 'excerpt' => 'pls_tie_field'))));
pls_test_assert('saved values are counted per key from post meta', array('field_tie111' => 2, 'field_tie222' => 2) == $context['acf_meta']['pls_tie_field'], wp_json_encode($context['acf_meta']));
$item = $keys::analyze(pls_sys_object(array('id' => 900020, 'title' => 'Tie', 'slug' => 'tie', 'excerpt' => 'pls_tie_field')), pls_sys_facts(), $context);
pls_test_assert('two keys as often in post meta → "review"', 'review' === $item['group'] && in_array('conflict', $item['reasons'], true));
$json = $keys::acf_json();
pls_test_assert('acf-json files are read (fields, sub fields, groups by title)', array('field_json1') === $json['fields']['json_field'] && array('field_json2') === $json['fields']['json_sub'] && array('group_json1') === $json['groups']['Json Group']);
wp_delete_file($dir . '/group_json1.json');
rmdir($dir);

pls_test_section('Results of a scan made before this version');
wp_set_current_user(pls_test_user('administrator'));
$state = Parich_Slug_Repair_Scanner::run();
unset($state['counts']['D6']);
update_option(Parich_Slug_Repair_Scanner::STATE_OPTION, $state, false);
$failures_before = count($GLOBALS['pls_test_failures']);
ob_start();
Parich_Slug_Repair_Admin::render();
$html = (string) ob_get_clean();
pls_test_assert('the repair tab shows them, D6 counted as 0, without a PHP warning', count($GLOBALS['pls_test_failures']) === $failures_before && false !== strpos($html, 'D6 — '));

pls_test_done();
