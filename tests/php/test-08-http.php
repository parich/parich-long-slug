<?php
/**
 * Phase 4: a real published post with a long Thai slug opens over HTTP. The post is
 * committed (the web server cannot see an open transaction) and deleted again at the end.
 *
 * @package Parich_Long_Slug
 */

define('PLS_TEST_MANUAL_TX', true);
require __DIR__ . '/../bootstrap.php';

if (!Parich_Slug_Sanitize::is_active()) {
	pls_test_assert('precondition: the slug columns are widened, so the long filter is installed', false);
	pls_test_done();
}

// About 70 Thai characters: over core's 200 bytes, under the default 1000.
$title = 'ทดสอบปลั๊กอินพาริชลองสลักด้วยหัวข้อข่าวภาษาไทยที่ยาวกว่ายี่สิบสองตัวอักษร';
$test_post_id = wp_insert_post(array(
	'post_title' => $title,
	'post_content' => 'pls-http-check',
	'post_status' => 'publish',
	'post_type' => 'post',
), true);

try {
	pls_test_assert('test post created', !is_wp_error($test_post_id) && $test_post_id > 0, is_wp_error($test_post_id) ? $test_post_id->get_error_message() : '');
	$slug = (string) get_post_field('post_name', $test_post_id, 'raw');
	$url = get_permalink($test_post_id);
	pls_test_assert('its slug is the whole title (' . strlen($slug) . ' bytes, longer than core\'s 200)', $title === urldecode($slug) && strlen($slug) > 200);

	$response = wp_remote_get($url, array('timeout' => 20, 'redirection' => 0, 'sslverify' => false));
	$code = is_wp_error($response) ? $response->get_error_message() : wp_remote_retrieve_response_code($response);
	$body = is_wp_error($response) ? '' : wp_remote_retrieve_body($response);
	pls_test_assert('GET of its permalink (' . strlen($url) . ' characters) returns 200', 200 === $code, (string) $code);
	pls_test_assert('…and serves this post, not another page', false !== strpos($body, 'pls-http-check') && false !== strpos($body, esc_html($title)));

	$cut = substr($url, 0, strpos($url, $slug) + 200);
	$response = wp_remote_get($cut, array('timeout' => 20, 'redirection' => 0, 'sslverify' => false));
	$code = is_wp_error($response) ? $response->get_error_message() : wp_remote_retrieve_response_code($response);
	pls_test_assert('the URL cut at 200 bytes does not serve the post with 200 (it is not its address)', 200 !== $code, (string) $code);
} finally {
	if (!is_wp_error($test_post_id) && $test_post_id) {
		wp_delete_post($test_post_id, true);
	}
}
pls_test_assert('test post deleted again', null === get_post($test_post_id));

pls_test_done();
