<?php
/**
 * Stand-ins for Long URL Maker's functions, for tests that must not load the real
 * plugin. Each one only counts its calls; pls_legacy_register_hooks() registers them
 * the way long_url_maker.php does when it is included.
 *
 * @package Parich_Long_Slug
 */

$GLOBALS['pls_legacy_calls'] = array();

function pls_legacy_record($name)
{
	$GLOBALS['pls_legacy_calls'][$name] = pls_legacy_calls($name) + 1;
}

function pls_legacy_calls($name)
{
	return $GLOBALS['pls_legacy_calls'][$name] ?? 0;
}

function kpcode_long_url_deactivation()
{
	pls_legacy_record(__FUNCTION__);
}

function kpcode_long_url_activation()
{
	pls_legacy_record(__FUNCTION__);
}

function kpcode_set_url_format($title)
{
	pls_legacy_record(__FUNCTION__);
	return 'legacy-' . $title;
}

function kpcode_unique_post_slug($slug)
{
	pls_legacy_record(__FUNCTION__);
	return $slug;
}

function kpcode_duplicate_postname()
{
	pls_legacy_record(__FUNCTION__);
}

function kpcode_duplicate_term()
{
	pls_legacy_record(__FUNCTION__);
}

function kpcode_update_term()
{
	pls_legacy_record(__FUNCTION__);
}

/**
 * Same hooks as long_url_maker.php registers at file level (L11–L13, L229–L231,
 * L292–L294, L391, L413, L429, L554), under the given basename.
 */
function pls_legacy_register_hooks($basename)
{
	add_action('activate_' . $basename, 'kpcode_long_url_activation');
	add_action('deactivate_' . $basename, 'kpcode_long_url_deactivation');
	add_filter('wp_unique_post_slug', 'kpcode_unique_post_slug', 100, 6);
	remove_filter('sanitize_title', 'sanitize_title_with_dashes');
	add_filter('sanitize_title', 'kpcode_set_url_format');
	add_action('save_post', 'kpcode_duplicate_postname', 12);
	add_action('create_term', 'kpcode_duplicate_term', 12);
	add_action('edited_term', 'kpcode_update_term');
}

/**
 * Adds the basename to active_plugins (inside the test transaction).
 */
function pls_legacy_make_active($basename)
{
	$active = (array) get_option('active_plugins', array());
	if (!in_array($basename, $active, true)) {
		$active[] = $basename;
		update_option('active_plugins', $active);
	}
}
