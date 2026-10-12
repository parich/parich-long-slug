<?php
/**
 * Keeps wp_posts.post_name and wp_terms.slug at varchar(2000).
 *
 * WordPress defines both columns as varchar(200). Its database upgrade (dbDelta)
 * changes them back whenever it runs, which cuts longer slugs without an error, since
 * WordPress turns off MySQL strict mode for its own connection. The mu-plugin guard
 * stops that; this class widens the columns on activation, notices when a WordPress
 * update still shrank them (the guard was missing) and widens them again.
 *
 * Columns are never made shorter here.
 *
 * @package Parich_Long_Slug
 */

if (!defined('ABSPATH')) {
	exit;
}

class Parich_Slug_Schema
{
	const LENGTH = 2000;

	/** True once both columns are at least LENGTH long. */
	const COLUMNS_OK_OPTION = 'parich_slug_columns_ok';

	/** WordPress db_version the columns were last checked against. */
	const CHECKED_DB_VERSION_OPTION = 'parich_slug_checked_db_version';

	/** Last setup error shown to administrators, removed after a successful setup. */
	const ERROR_OPTION = 'parich_slug_setup_error';

	/** When and from which lengths the columns were last widened after an update, and what was restored. */
	const LAST_HEAL_OPTION = 'parich_slug_last_heal';

	/** Set when a heal happened, until an administrator dismisses the notice. */
	const HEAL_NOTICE_OPTION = 'parich_slug_heal_notice';

	const LOCK_OPTION = 'parich_slug_heal_lock';
	const LOCK_TTL = 600;

	const FIX_ACTION = 'parich_slug_fix_setup';
	const DISMISS_HEAL_ACTION = 'parich_slug_dismiss_heal';

	public static function init()
	{
		add_action('plugins_loaded', array(__CLASS__, 'maybe_check_db_version'), 5);
		add_action('admin_notices', array(__CLASS__, 'admin_notices'));
		add_action('admin_post_' . self::FIX_ACTION, array(__CLASS__, 'handle_fix'));
		add_action('admin_post_' . self::DISMISS_HEAL_ACTION, array(__CLASS__, 'handle_dismiss_heal'));
	}

	/**
	 * Slug columns of the current site.
	 *
	 * @return array<string, string> table => column
	 */
	public static function columns()
	{
		global $wpdb;
		return array(
			$wpdb->posts => 'post_name',
			$wpdb->terms => 'slug',
		);
	}

	/**
	 * Type, length, character set and collation of a column.
	 *
	 * @return array|WP_Error {type, length (null when not varchar), charset, collation}
	 */
	public static function get_column_info($table, $column)
	{
		global $wpdb;
		if (!self::is_identifier($table) || !self::is_identifier($column)) {
			return new WP_Error('parich_slug_invalid_identifier', sprintf('Invalid table or column name: %s.%s', $table, $column));
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- schema metadata, read only when needed.
		$row = $wpdb->get_row($wpdb->prepare(
			'SELECT COLUMN_TYPE, CHARACTER_SET_NAME, COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s',
			$table,
			$column
		));
		if (!$row) {
			return new WP_Error('parich_slug_no_column', sprintf('Column %s.%s does not exist.', $table, $column));
		}
		$type = strtolower($row->COLUMN_TYPE);
		return array(
			'type' => $type,
			'length' => preg_match('/^varchar\((\d+)\)$/', $type, $m) ? (int) $m[1] : null,
			'charset' => $row->CHARACTER_SET_NAME,
			'collation' => $row->COLLATION_NAME,
		);
	}

	/**
	 * Widens a varchar column to $length, keeping its character set and collation.
	 * Does nothing when it is already that long or longer.
	 *
	 * @return bool|WP_Error True when the column was changed, false when nothing was needed.
	 */
	public static function expand($table, $column, $length = self::LENGTH)
	{
		global $wpdb;
		$info = self::get_column_info($table, $column);
		if (is_wp_error($info)) {
			return $info;
		}
		if (null === $info['length']) {
			return new WP_Error('parich_slug_unexpected_type', sprintf('Column %s.%s is %s, not varchar; left unchanged.', $table, $column, $info['type']));
		}
		if ($info['length'] >= $length) {
			return false;
		}

		// Keep the column's character set and collation (MODIFY without them would take the table's).
		if (!$info['charset'] || !$info['collation'] || !self::is_identifier($info['charset']) || !self::is_identifier($info['collation'])) {
			return new WP_Error('parich_slug_unexpected_charset', sprintf('Column %s.%s has no usable character set or collation; left unchanged.', $table, $column));
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- widening the slug columns is the purpose of this plugin.
		if (false === $wpdb->query($wpdb->prepare("ALTER TABLE %i MODIFY %i varchar(%d) CHARACTER SET %i COLLATE %i NOT NULL DEFAULT ''", $table, $column, (int) $length, $info['charset'], $info['collation']))) {
			return new WP_Error('parich_slug_alter_failed', sprintf('Could not widen %s.%s: %s', $table, $column, $wpdb->last_error));
		}
		$after = self::get_column_info($table, $column);
		if (is_wp_error($after) || $after['length'] !== (int) $length) {
			return new WP_Error('parich_slug_alter_failed', sprintf('%s.%s is still not varchar(%d).', $table, $column, $length));
		}
		return true;
	}

	/**
	 * Widens the slug columns and records whether they are long enough.
	 *
	 * @param array|null $columns table => column; default: the site's slug columns.
	 * @return array|WP_Error Lengths before widening (table => length) or the first error.
	 */
	public static function ensure_columns($columns = null)
	{
		$before = array();
		foreach ($columns ? $columns : self::columns() as $table => $column) {
			$info = self::get_column_info($table, $column);
			if (is_wp_error($info)) {
				update_option(self::COLUMNS_OK_OPTION, false);
				return $info;
			}
			$before[$table] = $info['length'];
			$result = self::expand($table, $column);
			if (is_wp_error($result)) {
				update_option(self::COLUMNS_OK_OPTION, false);
				return $result;
			}
		}
		update_option(self::COLUMNS_OK_OPTION, true);
		return $before;
	}

	public static function columns_ok()
	{
		return (bool) get_option(self::COLUMNS_OK_OPTION, false);
	}

	/**
	 * Activation and "Try again": creates the shadow table, widens the columns, installs
	 * the guard and stores the checked db_version. When the plugin had been set up
	 * before and the columns are short again (WordPress was updated while it was off and
	 * the guard was gone), the cut slugs are restored first. Errors are kept for the
	 * admin notice; nothing is fatal.
	 *
	 * @return true|WP_Error
	 */
	public static function setup()
	{
		$errors = array();
		$shadow = Parich_Slug_Shadow::maybe_install();
		if (is_wp_error($shadow)) {
			$errors[] = $shadow->get_error_message();
		}
		if (false !== get_option(self::CHECKED_DB_VERSION_OPTION, false) && self::shrunk_columns()) {
			self::check_and_heal();
		}
		$columns = self::ensure_columns();
		if (is_wp_error($columns)) {
			$errors[] = $columns->get_error_message();
		}
		$guard = Parich_Slug_Guard::install();
		if (is_wp_error($guard)) {
			$errors[] = $guard->get_error_message();
		}
		update_option(self::CHECKED_DB_VERSION_OPTION, (string) get_option('db_version'));

		if ($errors) {
			update_option(self::ERROR_OPTION, implode(' ', $errors), false);
			return new WP_Error('parich_slug_setup', implode(' ', $errors));
		}
		delete_option(self::ERROR_OPTION);
		return true;
	}

	/**
	 * Runs on plugins_loaded. Only when WordPress's db_version changed since the last
	 * check (a database upgrade ran) are the columns read; shrunk columns are widened.
	 */
	public static function maybe_check_db_version()
	{
		$checked = get_option(self::CHECKED_DB_VERSION_OPTION, false);
		if (false === $checked) {
			return; // Not set up yet: activation does the first check.
		}
		$current = (string) get_option('db_version');
		if ($current === (string) $checked) {
			return;
		}
		if (self::check_and_heal()) {
			update_option(self::CHECKED_DB_VERSION_OPTION, $current);
		}
	}

	/**
	 * Columns shorter than LENGTH.
	 *
	 * @param array|null $columns table => column; default: the site's slug columns.
	 * @return array table => length
	 */
	public static function shrunk_columns($columns = null)
	{
		$shrunk = array();
		foreach ($columns ? $columns : self::columns() as $table => $column) {
			$info = self::get_column_info($table, $column);
			if (!is_wp_error($info) && null !== $info['length'] && $info['length'] < self::LENGTH) {
				$shrunk[$table] = $info['length'];
			}
		}
		return $shrunk;
	}

	/**
	 * Widens the columns again if a database upgrade shrank them, then restores the
	 * slugs it cut from the shadow copy.
	 *
	 * @param array|null $columns table => column; default: the site's slug columns.
	 * @param array|null $shadow  Tables for Parich_Slug_Shadow::restore(); default: the
	 *                            site's. With custom $columns, restoring needs $shadow.
	 * @return bool False when another request is already healing (try again later).
	 */
	public static function check_and_heal($columns = null, $shadow = null)
	{
		$shrunk = self::shrunk_columns($columns);
		if (!$shrunk) {
			update_option(self::COLUMNS_OK_OPTION, true);
			return true;
		}
		if (!self::acquire_lock()) {
			return false;
		}
		$result = self::ensure_columns($columns);
		$restore = array('checked' => 0, 'restored' => 0, 'skipped' => array());
		if (!is_wp_error($result) && (null === $columns || null !== $shadow)) {
			$restore = Parich_Slug_Shadow::restore($shadow);
		}
		update_option(self::LAST_HEAL_OPTION, array(
			'time' => time(),
			'db_version' => (string) get_option('db_version'),
			'lengths_before' => $shrunk,
			'error' => is_wp_error($result) ? $result->get_error_message() : '',
			'checked' => $restore['checked'],
			'restored' => $restore['restored'],
			'skipped' => $restore['skipped'],
		), false);
		update_option(self::HEAL_NOTICE_OPTION, 1, false);
		self::release_lock();
		return true;
	}

	/**
	 * Takes the heal lock with an atomic INSERT IGNORE (add_option() overwrites an
	 * existing row, so two requests could both "get" it). A lock older than LOCK_TTL
	 * is treated as left over from a request that died and is taken over.
	 */
	public static function acquire_lock()
	{
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- atomic lock; the options API has no insert-if-absent.
		if (1 === (int) $wpdb->query($wpdb->prepare("INSERT IGNORE INTO %i (option_name, option_value, autoload) VALUES (%s, %s, 'off')", $wpdb->options, self::LOCK_OPTION, (string) time()))) {
			return true;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- read the lock row itself, not a cached copy.
		$since = (int) $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::LOCK_OPTION));
		if ($since && time() - $since < self::LOCK_TTL) {
			return false;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- take over a stale lock only if nobody else did meanwhile.
		return 1 === (int) $wpdb->query($wpdb->prepare(
			"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
			(string) time(),
			self::LOCK_OPTION,
			(string) $since
		));
	}

	public static function release_lock()
	{
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- counterpart of acquire_lock().
		$wpdb->delete($wpdb->options, array('option_name' => self::LOCK_OPTION));
		wp_cache_delete(self::LOCK_OPTION, 'options');
	}

	/**
	 * For administrators, on the Dashboard, the Plugins screen and the plugin's page only
	 * (the heal notice on the Updates screen too).
	 */
	public static function admin_notices()
	{
		if (!current_user_can('activate_plugins')) {
			return;
		}
		if (Parich_Slug_Admin::is_notice_screen(array('update-core'))) {
			self::heal_notice();
		}
		$error = (string) get_option(self::ERROR_OPTION, '');
		if ('' === $error || !Parich_Slug_Admin::is_notice_screen()) {
			return;
		}
		?>
		<div class="notice notice-error">
			<p>
				<strong><?php esc_html_e('Parich Long Slug could not finish its setup.', 'parich-long-slug'); ?></strong>
				<?php echo esc_html($error); ?>
			</p>
			<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr(self::FIX_ACTION); ?>" />
				<?php wp_nonce_field(self::FIX_ACTION); ?>
				<p><?php submit_button(__('Try again', 'parich-long-slug'), 'secondary', 'submit', false); ?></p>
			</form>
		</div>
		<?php
	}

	/**
	 * Tells administrators that a WordPress update shrank the columns and what was
	 * restored, until one of them dismisses it.
	 */
	private static function heal_notice()
	{
		$heal = get_option(self::LAST_HEAL_OPTION);
		if (!get_option(self::HEAL_NOTICE_OPTION) || !is_array($heal)) {
			return;
		}
		$restored = isset($heal['restored']) ? (int) $heal['restored'] : 0;
		$skipped = isset($heal['skipped']) ? (array) $heal['skipped'] : array();
		?>
		<div class="notice notice-warning">
			<p>
				<strong><?php esc_html_e('A WordPress database update shortened the slug columns.', 'parich-long-slug'); ?></strong>
				<?php
				printf(
					/* translators: %d: number of slugs */
					esc_html(_n('Parich Long Slug widened them again and restored %d slug that had been cut to 200 characters.', 'Parich Long Slug widened them again and restored %d slugs that had been cut to 200 characters.', $restored, 'parich-long-slug')),
					(int) $restored
				);
				if ($skipped) {
					echo ' ';
					printf(
						/* translators: 1: number of slugs, 2: list such as "post:12, term:5" */
						esc_html(_n('%1$d slug was left as it is because it changed after being cut: %2$s.', '%1$d slugs were left as they are because they changed after being cut: %2$s.', count($skipped), 'parich-long-slug')),
						(int) count($skipped),
						esc_html(implode(', ', array_slice($skipped, 0, 20)) . (count($skipped) > 20 ? ', …' : ''))
					);
				}
				if (!empty($heal['error'])) {
					echo ' ' . esc_html($heal['error']);
				}
				?>
			</p>
			<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr(self::DISMISS_HEAL_ACTION); ?>" />
				<?php wp_nonce_field(self::DISMISS_HEAL_ACTION); ?>
				<p><?php submit_button(__('Dismiss', 'parich-long-slug'), 'secondary', 'submit', false); ?></p>
			</form>
		</div>
		<?php
	}

	/**
	 * admin-post handler of the heal notice's "Dismiss" button.
	 */
	public static function handle_dismiss_heal()
	{
		check_admin_referer(self::DISMISS_HEAL_ACTION);
		if (!current_user_can('activate_plugins')) {
			wp_die(
				esc_html__('Sorry, you are not allowed to dismiss this notice.', 'parich-long-slug'),
				'',
				array('response' => 403)
			);
		}
		delete_option(self::HEAL_NOTICE_OPTION);
		wp_safe_redirect(wp_get_referer() ? wp_get_referer() : admin_url());
		exit;
	}

	/**
	 * admin-post handler of the "Try again" button.
	 */
	public static function handle_fix()
	{
		check_admin_referer(self::FIX_ACTION);
		if (!current_user_can('activate_plugins')) {
			wp_die(
				esc_html__('Sorry, you are not allowed to change the database for this plugin.', 'parich-long-slug'),
				'',
				array('response' => 403)
			);
		}
		self::setup();
		$back = wp_get_referer();
		wp_safe_redirect($back ? $back : admin_url('plugins.php'));
		exit;
	}

	private static function is_identifier($name)
	{
		return is_string($name) && 1 === preg_match('/^[A-Za-z0-9_]+$/', $name);
	}
}
