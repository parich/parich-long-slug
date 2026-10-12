<?php
/**
 * Helpers shared by the test files (load after bootstrap.php).
 *
 * @package Parich_Long_Slug
 */

// submit_button() for the admin notices.
require_once ABSPATH . 'wp-admin/includes/template.php';

/**
 * Creates a user with the role inside the test transaction and returns its ID.
 */
function pls_test_user($role)
{
	return wp_insert_user(array(
		'user_login' => 'pls_' . $role . '_' . wp_rand(),
		'user_pass' => wp_generate_password(),
		'user_email' => 'pls_' . $role . '_' . wp_rand() . '@example.com',
		'role' => $role,
	));
}

/**
 * PHP copy of the block editor's cleanForSlug() (wp-includes/js/dist/url.js),
 * which runs when the slug field loses focus. Its removeAccents() step only
 * changes Latin letters and is left out.
 */
function pls_clean_for_slug($text)
{
	$text = preg_replace('/(&nbsp;|&ndash;|&mdash;)/', '-', (string) $text);
	$text = preg_replace('/[\s\.\/]+/u', '-', $text);
	$text = preg_replace('/&\S+?;/u', '', $text);
	$text = preg_replace('/[^\p{L}\p{N}_-]+/u', '', $text);
	$text = mb_strtolower($text, 'UTF-8');
	$text = preg_replace('/-+/', '-', $text);
	return preg_replace('/(^-+)|(-+$)/', '', $text);
}

/**
 * REST request as the block editor sends it; returns [status, data].
 */
function pls_rest($method, $route, array $params = array())
{
	$request = new WP_REST_Request($method, $route);
	foreach ($params as $key => $value) {
		$request->set_param($key, $value);
	}
	$response = rest_do_request($request);
	return array($response->get_status(), $response->get_data());
}

function pls_slug_of($id)
{
	return (string) get_post_field('post_name', $id, 'raw');
}

/**
 * Calls an admin-ajax handler as WordPress would (wp_doing_ajax() true) and returns
 * [output, PLS_Test_Exit|null]; wp_die() and wp_send_json() end in the exception.
 */
function pls_test_ajax(callable $handler, array $post)
{
	$doing_ajax = '__return_true';
	$die_handler = function () {
		return function ($message, $title = '', $args = array()) {
			$parsed = _wp_die_process_input($message, $title, $args);
			throw new PLS_Test_Exit($message, $parsed[2]['response']);
		};
	};
	add_filter('wp_doing_ajax', $doing_ajax);
	add_filter('wp_die_ajax_handler', $die_handler);
	$_POST = $post;
	$_REQUEST = $post;
	ob_start();
	try {
		$handler();
		$exit = null;
	} catch (PLS_Test_Exit $e) {
		$exit = $e;
	}
	$output = (string) ob_get_clean();
	$_POST = array();
	$_REQUEST = array();
	remove_filter('wp_doing_ajax', $doing_ajax);
	remove_filter('wp_die_ajax_handler', $die_handler);
	return array($output, $exit);
}

/**
 * Makes $id the current admin screen (get_current_screen()), or none with null.
 */
function pls_test_screen($id)
{
	if (!class_exists('WP_Screen')) {
		require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
		require_once ABSPATH . 'wp-admin/includes/screen.php';
	}
	$GLOBALS['current_screen'] = null === $id ? null : WP_Screen::get($id);
}

/**
 * Output of a notice callback for the current user on admin screen $screen.
 */
function pls_test_notices($callback, $screen = 'plugins')
{
	pls_test_screen($screen);
	ob_start();
	call_user_func($callback);
	$html = (string) ob_get_clean();
	pls_test_screen(null);
	return $html;
}

/**
 * Output of Parich_Slug_Legacy::admin_notices() for the current user (Plugins screen by default).
 */
function pls_legacy_notices($screen = 'plugins')
{
	return pls_test_notices(array('Parich_Slug_Legacy', 'admin_notices'), $screen);
}
