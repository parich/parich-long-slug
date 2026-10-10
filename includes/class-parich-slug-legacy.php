<?php
/**
 * Keeps Long URL Maker 2.0.1 from damaging slugs while Parich Long Slug is active.
 *
 * Long URL Maker rewrites every post_name from the post title and shrinks the slug
 * columns back to 200 when it is deactivated, and restores stale backup rows over
 * current slugs when it is activated. This class:
 * - removes its deactivation hook and its slug filters once all plugins are loaded
 *   (its backup hooks stay, so its backup table keeps recording current slugs);
 * - stops its activation routine and switches it off again if someone activates it;
 * - offers a button that deactivates it without running its deactivation routine.
 *
 * Its file-level restore code runs when the file is included, before any hook can
 * stop it; the mu-plugin guard handles that part.
 *
 * @package Parich_Long_Slug
 */

if (!defined('ABSPATH')) {
	exit;
}

class Parich_Slug_Legacy
{
	/** Main file name of Long URL Maker; the folder name may differ between sites. */
	const MAIN_FILE = 'long_url_maker.php';

	const DEACTIVATE_ACTION = 'parich_slug_deactivate_legacy';

	/** Per-user transient holding the notice to show on the next admin page. */
	const NOTICE_TRANSIENT = 'parich_slug_legacy_notice_';

	/** Basename whose activation routine was stopped in this request. */
	private static $blocked_activation = null;

	public static function init()
	{
		add_action('plugins_loaded', array(__CLASS__, 'neutralize'));
		add_action('activate_plugin', array(__CLASS__, 'before_activate'), 10, 1);
		add_action('activated_plugin', array(__CLASS__, 'after_activate'), 10, 1);
		add_action('admin_notices', array(__CLASS__, 'admin_notices'));
		add_action('admin_post_' . self::DEACTIVATE_ACTION, array(__CLASS__, 'handle_safe_deactivate'));
	}

	public static function is_legacy_basename($basename)
	{
		return self::MAIN_FILE === basename((string) $basename);
	}

	/**
	 * Basename of the active Long URL Maker, or null when it is not active.
	 *
	 * @return string|null
	 */
	public static function active_basename()
	{
		foreach ((array) get_option('active_plugins', array()) as $basename) {
			if (self::is_legacy_basename($basename)) {
				return $basename;
			}
		}
		return null;
	}

	/**
	 * What Long URL Maker left on this site, for the status page.
	 *
	 * @param string|null $table Its backup table; default: {prefix}kpcode_url_posts.
	 * @return array {active, installed, backup_rows (null without the table), options, used}
	 */
	public static function traces($table = null)
	{
		global $wpdb;
		$table = $table ? $table : $wpdb->prefix . 'kpcode_url_posts';
		if (!function_exists('get_plugins')) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$installed = (bool) array_filter(array_keys(get_plugins()), array(__CLASS__, 'is_legacy_basename'));
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- status page, read on demand.
		$exists = $table === $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table)));
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- status page, read on demand.
		$rows = $exists ? (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i', $table)) : null;
		// Read from the table: the guard answers get_option('kpcode_version_wp') with the WordPress version.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- see above.
		$options = 0 < (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name IN ('kpcode_version_wp', 'kpcode_longurlmaker_version', 'c_version_wp')");
		return array(
			'active' => null !== self::active_basename(),
			'installed' => $installed,
			'backup_rows' => $rows,
			'options' => $options,
			'used' => $exists || $options,
		);
	}

	/**
	 * Runs on plugins_loaded, when Long URL Maker's file-level hooks are registered.
	 */
	public static function neutralize()
	{
		$basename = self::active_basename();
		if ($basename && function_exists('kpcode_long_url_deactivation')) {
			self::remove_runtime_hooks($basename);
		}
	}

	/**
	 * Removes the hooks that rewrite or shorten slugs and gives sanitize_title back to core.
	 *
	 * @param string $basename Long URL Maker's plugin basename.
	 */
	private static function remove_runtime_hooks($basename)
	{
		remove_action('deactivate_' . $basename, 'kpcode_long_url_deactivation');
		remove_filter('sanitize_title', 'kpcode_set_url_format');
		remove_filter('wp_unique_post_slug', 'kpcode_unique_post_slug', 100);
		// Long URL Maker removed core's filter when its file was included; put it back
		// unless Parich Long Slug's own one already replaces it.
		if (false === has_filter('sanitize_title', 'sanitize_title_with_dashes') && !Parich_Slug_Sanitize::is_active()) {
			add_filter('sanitize_title', 'sanitize_title_with_dashes', 10, 3);
		}
	}

	/**
	 * Fires after Long URL Maker's file is included and before its activation hook,
	 * so the routine that restores old backup rows over current slugs never runs.
	 *
	 * @param string $plugin Basename of the plugin being activated.
	 */
	public static function before_activate($plugin)
	{
		if (!self::is_legacy_basename($plugin) || !function_exists('kpcode_long_url_activation')) {
			return;
		}
		remove_action('activate_' . $plugin, 'kpcode_long_url_activation');
		self::$blocked_activation = $plugin;
	}

	/**
	 * Switches Long URL Maker off again right after it was activated.
	 *
	 * @param string $plugin Basename of the plugin that was activated.
	 */
	public static function after_activate($plugin)
	{
		if (null === self::$blocked_activation || $plugin !== self::$blocked_activation) {
			return;
		}
		self::$blocked_activation = null;
		deactivate_plugins($plugin, true);
		self::remove_runtime_hooks($plugin);
		self::queue_notice('reactivation_blocked');
	}

	/**
	 * admin-post handler of the "Deactivate Long URL Maker safely" button.
	 */
	public static function handle_safe_deactivate()
	{
		check_admin_referer(self::DEACTIVATE_ACTION);
		$basename = self::active_basename();
		if ($basename) {
			if (!current_user_can('deactivate_plugin', $basename)) {
				wp_die(
					esc_html__('Sorry, you are not allowed to deactivate this plugin.', 'parich-long-slug'),
					'',
					array('response' => 403)
				);
			}
			// Silent: skips Long URL Maker's deactivation routine, which would rewrite every slug.
			deactivate_plugins($basename, true);
			self::queue_notice('deactivated');
		}
		wp_safe_redirect(admin_url('plugins.php'));
		exit;
	}

	private static function queue_notice($type)
	{
		set_transient(self::NOTICE_TRANSIENT . get_current_user_id(), $type, 10 * MINUTE_IN_SECONDS);
	}

	public static function admin_notices()
	{
		if (!current_user_can('activate_plugins')) {
			return;
		}

		$key = self::NOTICE_TRANSIENT . get_current_user_id();
		$queued = get_transient($key);
		if ($queued) {
			delete_transient($key);
		}

		if ('deactivated' === $queued) {
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html__('Long URL Maker was deactivated without running its deactivation routine. Your slugs were not changed.', 'parich-long-slug')
			);
		} elseif ('reactivation_blocked' === $queued) {
			printf(
				'<div class="notice notice-warning is-dismissible"><p>%s</p></div>',
				esc_html__('Long URL Maker was switched off again right after activation, before its activation routine could write old slugs over the current ones. Keep it inactive while Parich Long Slug is active.', 'parich-long-slug')
			);
		}

		$basename = self::active_basename();
		if (!$basename || !current_user_can('deactivate_plugin', $basename)) {
			return;
		}
		?>
		<div class="notice notice-warning">
			<p>
				<strong><?php esc_html_e('Long URL Maker is active.', 'parich-long-slug'); ?></strong>
				<?php esc_html_e('When it is deactivated it rebuilds every slug from the post title and shortens the slug columns, which changes your URLs. Parich Long Slug has switched those actions off while both plugins are active. Turn Long URL Maker off with this button:', 'parich-long-slug'); ?>
			</p>
			<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr(self::DEACTIVATE_ACTION); ?>" />
				<?php wp_nonce_field(self::DEACTIVATE_ACTION); ?>
				<p><?php submit_button(__('Deactivate Long URL Maker safely', 'parich-long-slug'), 'secondary', 'submit', false); ?></p>
			</form>
		</div>
		<?php
	}
}
