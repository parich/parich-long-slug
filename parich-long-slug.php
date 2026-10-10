<?php
/**
 * Plugin Name:       Parich Long Slug
 * Plugin URI:        https://github.com/parich/parich-long-slug
 * Description:       Long, Thai-friendly post and term slugs that survive WordPress updates, with a repair tool for slugs damaged by Long URL Maker.
 * Version:           0.1.0
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            Mr.Parich Suriya
 * Author URI:        https://github.com/parich
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       parich-long-slug
 *
 * @package Parich_Long_Slug
 */

if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly.
}

define('PARICH_SLUG_VERSION', '0.1.0');
define('PARICH_SLUG_FILE', __FILE__);
define('PARICH_SLUG_DIR', __DIR__);
define('PARICH_SLUG_DONATE_URL', 'https://buy.stripe.com/3cIaEW9699pf3dyeAfaMU00');

require_once __DIR__ . '/includes/class-parich-slug-legacy.php';
require_once __DIR__ . '/includes/class-parich-slug-schema.php';
require_once __DIR__ . '/includes/class-parich-slug-guard.php';
require_once __DIR__ . '/includes/class-parich-slug-sanitize.php';
require_once __DIR__ . '/includes/class-parich-slug-shadow.php';
require_once __DIR__ . '/includes/class-parich-slug-admin.php';
require_once __DIR__ . '/includes/class-parich-slug-legacy-sanitize.php';
require_once __DIR__ . '/includes/repair/class-parich-slug-repair-util.php';
require_once __DIR__ . '/includes/repair/class-parich-slug-repair-source.php';
require_once __DIR__ . '/includes/repair/class-parich-slug-repair-detectors.php';
require_once __DIR__ . '/includes/repair/class-parich-slug-repair-evidence.php';
require_once __DIR__ . '/includes/repair/class-parich-slug-repair-scorer.php';
require_once __DIR__ . '/includes/repair/class-parich-slug-repair-system-keys.php';
require_once __DIR__ . '/includes/repair/class-parich-slug-repair-scanner.php';
require_once __DIR__ . '/includes/repair/class-parich-slug-repair-applier.php';
require_once __DIR__ . '/includes/class-parich-slug-redirects.php';
require_once __DIR__ . '/includes/repair/class-parich-slug-repair-admin.php';
if (defined('WP_CLI') && WP_CLI) {
	require_once __DIR__ . '/includes/class-parich-slug-cli.php';
	WP_CLI::add_command('parich-slug', 'Parich_Slug_CLI');
}

// Translations come as language packs from translate.wordpress.org; WordPress loads them when needed.

Parich_Slug_Legacy::init();
Parich_Slug_Schema::init();
Parich_Slug_Guard::init();
Parich_Slug_Sanitize::init();
Parich_Slug_Shadow::init();
Parich_Slug_Admin::init();
Parich_Slug_Repair_Admin::init();
Parich_Slug_Redirects::init();

register_activation_hook(__FILE__, 'parich_slug_activate');

/**
 * Widens the slug columns and installs the guard. Refuses network activation: the
 * slug columns and the guard are per site, and multisite is not supported.
 *
 * There is deliberately no deactivation hook: the columns stay wide and the guard
 * stays installed, so a WordPress update cannot shorten long slugs meanwhile.
 *
 * @param bool $network_wide Whether the plugin is being activated for the whole network.
 */
function parich_slug_activate($network_wide = false)
{
	if ($network_wide) {
		wp_die(
			esc_html__('Parich Long Slug does not support network activation.', 'parich-long-slug'),
			esc_html__('Plugin not activated', 'parich-long-slug'),
			array('back_link' => true)
		);
	}
	// Errors are shown as an admin notice with a "Try again" button; activation goes on.
	Parich_Slug_Schema::setup();
}
