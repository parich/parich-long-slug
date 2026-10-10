<?php
/**
 * Installs and keeps the guard must-use plugin (see guard-template.php) up to date.
 *
 * @package Parich_Long_Slug
 */

if (!defined('ABSPATH')) {
	exit;
}

class Parich_Slug_Guard
{
	const FILE_NAME = 'parich-long-slug-guard.php';

	public static function init()
	{
		add_action('admin_init', array(__CLASS__, 'maybe_repair'));
	}

	public static function template()
	{
		return PARICH_SLUG_DIR . '/includes/guard-template.php';
	}

	/**
	 * Where the guard is installed.
	 */
	public static function path()
	{
		/**
		 * Filters the path of the guard must-use plugin.
		 *
		 * @param string $path Default: WPMU_PLUGIN_DIR/parich-long-slug-guard.php.
		 */
		return (string) apply_filters('parich_slug_guard_file', WPMU_PLUGIN_DIR . '/' . self::FILE_NAME);
	}

	/**
	 * "Guard Version" header of a guard file, or null when missing or unreadable.
	 */
	public static function version_of($file)
	{
		if (!is_readable($file)) {
			return null;
		}
		$data = get_file_data($file, array('version' => 'Guard Version'));
		return '' === $data['version'] ? null : $data['version'];
	}

	/**
	 * Whether the installed guard has the same version as the bundled template.
	 */
	public static function is_current($path = null)
	{
		$installed = self::version_of($path ? $path : self::path());
		return null !== $installed && $installed === self::version_of(self::template());
	}

	/**
	 * Copies the template to the guard path: written to a temporary name first, so
	 * a half-written file is never loaded as a must-use plugin (only *.php is).
	 *
	 * @return true|WP_Error
	 */
	public static function install($path = null)
	{
		global $wp_filesystem;
		$path = $path ? $path : self::path();

		require_once ABSPATH . 'wp-admin/includes/file.php';
		if (!WP_Filesystem() || !$wp_filesystem) {
			return self::error(__('WordPress cannot write files directly on this server.', 'parich-long-slug'), $path);
		}
		$contents = $wp_filesystem->get_contents(self::template());
		if (false === $contents || '' === $contents) {
			return self::error(__('The guard template is missing.', 'parich-long-slug'), $path);
		}
		if (!wp_mkdir_p(dirname($path))) {
			return self::error(__('The folder could not be created.', 'parich-long-slug'), $path);
		}
		$tmp = $path . '.tmp';
		if (!$wp_filesystem->put_contents($tmp, $contents, FS_CHMOD_FILE)) {
			return self::error(__('The file could not be written.', 'parich-long-slug'), $path);
		}
		if (!$wp_filesystem->move($tmp, $path, true)) {
			$wp_filesystem->delete($tmp);
			return self::error(__('The file could not be written.', 'parich-long-slug'), $path);
		}
		return true;
	}

	/**
	 * @return bool|WP_Error True when removed, false when there was nothing to remove.
	 */
	public static function remove($path = null)
	{
		global $wp_filesystem;
		$path = $path ? $path : self::path();
		if (!file_exists($path)) {
			return false;
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		if (!WP_Filesystem() || !$wp_filesystem || !$wp_filesystem->delete($path)) {
			return self::error(__('The file could not be deleted.', 'parich-long-slug'), $path);
		}
		return true;
	}

	/**
	 * Reinstalls a missing or outdated guard when an administrator opens wp-admin.
	 */
	public static function maybe_repair()
	{
		if (!current_user_can('activate_plugins') || self::is_current()) {
			return;
		}
		$result = self::install();
		if (is_wp_error($result)) {
			update_option(Parich_Slug_Schema::ERROR_OPTION, $result->get_error_message(), false);
		} elseif (Parich_Slug_Schema::columns_ok()) {
			delete_option(Parich_Slug_Schema::ERROR_OPTION);
		}
	}

	private static function error($reason, $path)
	{
		return new WP_Error(
			'parich_slug_guard',
			/* translators: 1: reason, 2: file path */
			sprintf(__('The guard file could not be installed at %2$s: %1$s Without it, a WordPress update can shorten slugs longer than 200 characters.', 'parich-long-slug'), $reason, $path)
		);
	}
}
