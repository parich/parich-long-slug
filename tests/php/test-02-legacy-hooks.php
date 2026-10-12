<?php
/**
 * Phase 2 (L3, L4): with Long URL Maker active under a renamed folder (stand-in
 * functions, no real plugin file), Parich Long Slug removes the hooks that rewrite
 * or shorten slugs, keeps its backup hooks, and the safe-deactivate button skips
 * the deactivation routine. Everything runs inside the rolled-back transaction.
 *
 * @package Parich_Long_Slug
 */

const PLS_FAKE_LEGACY = 'renamed-folder/long_url_maker.php';

function pls_test_early_hooks()
{
	// Inside the test transaction (opened at priority 1), before plugins load.
	pls_test_add_early_hook('muplugins_loaded', function () {
		foreach ((array) get_option('active_plugins', array()) as $basename) {
			if ('long_url_maker.php' === basename($basename)) {
				fwrite(STDERR, "Long URL Maker is active on this site ({$basename}); this test needs it inactive.\n");
				exit(2);
			}
		}
		require __DIR__ . '/../fixtures/legacy-stubs.php';
		pls_legacy_make_active(PLS_FAKE_LEGACY);
		pls_legacy_register_hooks(PLS_FAKE_LEGACY);
	}, 2, 0);
}

require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/../fixtures/helpers.php';
pls_test_catch_exits();

$fake = PLS_FAKE_LEGACY;
$deactivate_hook = 'deactivate_' . $fake;

pls_test_section('plugins_loaded removed what rewrites or shortens slugs');
pls_test_assert('Long URL Maker is found under a renamed folder', $fake === Parich_Slug_Legacy::active_basename());
pls_test_assert('its deactivation routine is unhooked', false === has_action($deactivate_hook, 'kpcode_long_url_deactivation'));
pls_test_assert('its sanitize_title filter is removed', false === has_filter('sanitize_title', 'kpcode_set_url_format'));
pls_test_assert(
	'exactly one slug filter at priority 10: Parich Long Slug\'s (columns widened) or core\'s',
	Parich_Slug_Sanitize::is_active()
		? 10 === has_filter('sanitize_title', array('Parich_Slug_Sanitize', 'sanitize')) && false === has_filter('sanitize_title', 'sanitize_title_with_dashes')
		: 10 === has_filter('sanitize_title', 'sanitize_title_with_dashes')
);
pls_test_assert('its wp_unique_post_slug filter is removed', false === has_filter('wp_unique_post_slug', 'kpcode_unique_post_slug'));
pls_test_assert('sanitize_title() returns core output', 'hello-world' === sanitize_title('Hello World') && 0 === pls_legacy_calls('kpcode_set_url_format'));
pls_test_assert(
	'its backup hooks stay (save_post 12, create_term 12, edited_term 10)',
	12 === has_action('save_post', 'kpcode_duplicate_postname') && 12 === has_action('create_term', 'kpcode_duplicate_term') && 10 === has_action('edited_term', 'kpcode_update_term')
);

pls_test_section('Deactivating through the normal path (deactivate_plugins, not silent)');
deactivate_plugins($fake);
pls_test_assert('its deactivation routine did not run', 0 === pls_legacy_calls('kpcode_long_url_deactivation'));
pls_test_assert('Long URL Maker is no longer active', null === Parich_Slug_Legacy::active_basename());

pls_test_section('Negative control: a deactivation routine left hooked is caught');
pls_legacy_make_active($fake);
add_action($deactivate_hook, 'kpcode_long_url_deactivation');
deactivate_plugins($fake);
pls_test_assert('with the hook left in place the routine runs (the test would notice)', 1 === pls_legacy_calls('kpcode_long_url_deactivation'));
pls_legacy_make_active($fake);
add_action($deactivate_hook, 'kpcode_long_url_deactivation');
Parich_Slug_Legacy::neutralize();
pls_test_assert('neutralize() removes a deactivation hook registered again', false === has_action($deactivate_hook, 'kpcode_long_url_deactivation'));

pls_test_section('Notice and "Deactivate Long URL Maker safely" button');
$admin = pls_test_user('administrator');
$subscriber = pls_test_user('subscriber');
wp_set_current_user($admin);
$html = pls_legacy_notices();
pls_test_assert('administrator sees the warning with the button', false !== strpos($html, 'Long URL Maker is active.') && false !== strpos($html, 'Deactivate Long URL Maker safely'));
pls_test_assert(
	'the button posts to admin-post.php with its action and a nonce',
	false !== strpos($html, 'admin-post.php') && false !== strpos($html, 'value="' . Parich_Slug_Legacy::DEACTIVATE_ACTION . '"') && false !== strpos($html, 'name="_wpnonce"')
);
foreach (array('dashboard', 'settings_page_parich-long-slug') as $screen_id) {
	pls_test_assert("…also on {$screen_id}", false !== strpos(pls_legacy_notices($screen_id), 'Long URL Maker is active.'));
}
foreach (array('edit-post', 'post', 'upload', 'options-general', 'users') as $screen_id) {
	pls_test_assert("…but not across the admin: nothing on {$screen_id} (guideline 11)", '' === pls_legacy_notices($screen_id));
}
pls_test_assert('…nor without a screen', '' === pls_legacy_notices(null));
wp_set_current_user($subscriber);
pls_test_assert('subscriber sees nothing', '' === pls_legacy_notices());

// The routine stays hooked here, so a non-silent deactivation by the button would run it.
add_action($deactivate_hook, 'kpcode_long_url_deactivation');
$calls = pls_legacy_calls('kpcode_long_url_deactivation');

$_REQUEST['_wpnonce'] = wp_create_nonce(Parich_Slug_Legacy::DEACTIVATE_ACTION);
try {
	Parich_Slug_Legacy::handle_safe_deactivate();
	$exit = null;
} catch (PLS_Test_Exit $e) {
	$exit = $e;
}
pls_test_assert('subscriber with a valid nonce gets 403', $exit && 403 === $exit->status, $exit ? $exit->status . ' ' . $exit->getMessage() : 'no exit');
pls_test_assert('…and Long URL Maker is still active', $fake === Parich_Slug_Legacy::active_basename());

wp_set_current_user($admin);
unset($_REQUEST['_wpnonce']);
try {
	Parich_Slug_Legacy::handle_safe_deactivate();
	$exit = null;
} catch (PLS_Test_Exit $e) {
	$exit = $e;
}
pls_test_assert('administrator without a nonce gets 403', $exit && 403 === $exit->status, $exit ? (string) $exit->status : 'no exit');
pls_test_assert('…and Long URL Maker is still active', $fake === Parich_Slug_Legacy::active_basename());

$_REQUEST['_wpnonce'] = wp_create_nonce(Parich_Slug_Legacy::DEACTIVATE_ACTION);
try {
	Parich_Slug_Legacy::handle_safe_deactivate();
	$exit = null;
} catch (PLS_Test_Exit $e) {
	$exit = $e;
}
pls_test_assert('administrator with a valid nonce is redirected to the Plugins screen', $exit && 'redirect' === $exit->getMessage() && admin_url('plugins.php') === $exit->location, $exit ? $exit->getMessage() . ' ' . $exit->location : 'no exit');
pls_test_assert('Long URL Maker is deactivated', null === Parich_Slug_Legacy::active_basename());
pls_test_assert('its deactivation routine did not run although it was hooked (silent deactivation)', $calls === pls_legacy_calls('kpcode_long_url_deactivation'));
$html = pls_legacy_notices();
pls_test_assert('the next admin page confirms that slugs were not changed', false !== strpos($html, 'deactivated without running its deactivation routine'));
pls_test_assert('the confirmation is shown once', '' === pls_legacy_notices());

pls_test_done();
