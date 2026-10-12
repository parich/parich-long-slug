<?php
/**
 * Settings → Long Slug: the length setting with a live preview, and the status of
 * every part that keeps long slugs safe.
 *
 * @package Parich_Long_Slug
 */

if (!defined('ABSPATH')) {
	exit;
}

class Parich_Slug_Admin
{
	const PAGE = 'parich-long-slug';
	const GROUP = 'parich_slug';
	const PREVIEW_ACTION = 'parich_slug_preview';

	/** Longest URL most tools accept (sitemaps.org, many crawlers and browsers). */
	const URL_LIMIT = 2048;

	public static function init()
	{
		add_action('init', array(__CLASS__, 'register_settings'));
		add_action('admin_menu', array(__CLASS__, 'add_page'));
		add_action('admin_enqueue_scripts', array(__CLASS__, 'enqueue'));
		add_action('wp_ajax_' . self::PREVIEW_ACTION, array(__CLASS__, 'ajax_preview'));
		add_filter('option_page_capability_' . self::GROUP, array(__CLASS__, 'capability'));
		add_filter('plugin_action_links_' . plugin_basename(PARICH_SLUG_FILE), array(__CLASS__, 'action_links'));
		add_filter('plugin_row_meta', array(__CLASS__, 'row_meta'), 10, 2);
	}

	/**
	 * Capability needed for this page, its setting and the repair tool.
	 */
	public static function capability()
	{
		/**
		 * Filters the capability needed for Settings → Long Slug and the repair tool.
		 *
		 * @param string $capability Default 'manage_options'.
		 */
		return (string) apply_filters('parich_slug_capability', 'manage_options');
	}

	public static function register_settings()
	{
		register_setting(self::GROUP, Parich_Slug_Sanitize::MAX_OPTION, array(
			'type' => 'integer',
			'description' => 'Longest new slug in bytes once percent-encoded (200–1900).',
			'sanitize_callback' => array(__CLASS__, 'sanitize_max_length'),
			'default' => Parich_Slug_Sanitize::DEFAULT_MAX,
			'show_in_rest' => false,
		));
	}

	/**
	 * Keeps the setting within 200–1900; anything that is not a number is refused and
	 * the saved value stays.
	 *
	 * @param mixed $value Submitted value.
	 * @return int
	 */
	public static function sanitize_max_length($value)
	{
		if (!is_numeric($value)) {
			add_settings_error(self::GROUP, 'parich_slug_max_length_invalid', __('The longest slug must be a number. The previous value was kept.', 'parich-long-slug'));
			return Parich_Slug_Sanitize::max_length();
		}
		$number = (int) $value;
		$clamped = max(Parich_Slug_Sanitize::MIN_MAX, min(Parich_Slug_Sanitize::HARD_MAX, $number));
		if ($clamped !== $number) {
			add_settings_error(
				self::GROUP,
				'parich_slug_max_length_clamped',
				/* translators: 1: saved value, 2: lowest allowed, 3: highest allowed */
				sprintf(__('The longest slug was set to %1$d: it must be between %2$d and %3$d.', 'parich-long-slug'), $clamped, Parich_Slug_Sanitize::MIN_MAX, Parich_Slug_Sanitize::HARD_MAX),
				'warning'
			);
		}
		return $clamped;
	}

	public static function add_page()
	{
		add_options_page(
			__('Parich Long Slug', 'parich-long-slug'),
			__('Long Slug', 'parich-long-slug'),
			self::capability(),
			self::PAGE,
			array(__CLASS__, 'render_page')
		);
	}

	public static function action_links($links)
	{
		if (current_user_can(self::capability())) {
			array_unshift($links, sprintf('<a href="%s">%s</a>', esc_url(admin_url('options-general.php?page=' . self::PAGE)), esc_html__('Settings', 'parich-long-slug')));
		}
		return $links;
	}

	/**
	 * A "Donate" link under the plugin's description on the Plugins screen.
	 */
	public static function row_meta($links, $file)
	{
		if (plugin_basename(PARICH_SLUG_FILE) === $file) {
			$links[] = '<a href="' . esc_url(PARICH_SLUG_DONATE_URL) . '">' . esc_html__('Donate', 'parich-long-slug') . '</a>';
		}
		return $links;
	}

	/**
	 * Whether the plugin's notices may appear on the current screen: only the Dashboard,
	 * the Plugins screen and its own settings page (plus $more), never across the whole
	 * admin.
	 *
	 * @param string[] $more Other screen IDs for a notice, such as 'update-core'.
	 */
	public static function is_notice_screen(array $more = array())
	{
		$screen = function_exists('get_current_screen') ? get_current_screen() : null;
		return $screen && in_array($screen->id, array_merge(array('dashboard', 'plugins', 'settings_page_' . self::PAGE), $more), true);
	}

	public static function enqueue($hook_suffix)
	{
		if ('settings_page_' . self::PAGE !== $hook_suffix) {
			return;
		}
		wp_enqueue_style('parich-slug-admin-settings', plugins_url('assets/admin-settings.css', PARICH_SLUG_FILE), array('dashicons'), PARICH_SLUG_VERSION);
		wp_enqueue_script('parich-slug-admin-settings', plugins_url('assets/admin-settings.js', PARICH_SLUG_FILE), array(), PARICH_SLUG_VERSION, true);
		wp_localize_script('parich-slug-admin-settings', 'parichSlugSettings', array(
			'ajaxUrl' => admin_url('admin-ajax.php'),
			'action' => self::PREVIEW_ACTION,
			'nonce' => wp_create_nonce(self::PREVIEW_ACTION),
			'error' => __('The preview could not be loaded.', 'parich-long-slug'),
		));
	}

	/**
	 * Slug a title would get with the given length (admin-ajax, JSON).
	 */
	public static function ajax_preview()
	{
		check_ajax_referer(self::PREVIEW_ACTION, 'nonce');
		if (!current_user_can(self::capability())) {
			wp_die(-1, 403);
		}
		$title = isset($_POST['title']) ? sanitize_text_field(wp_unslash($_POST['title'])) : '';
		$max = isset($_POST['max']) ? absint(wp_unslash($_POST['max'])) : Parich_Slug_Sanitize::max_length();
		wp_send_json_success(self::preview($title, $max));
	}

	/**
	 * @return array {slug, readable, bytes, max, cut}
	 */
	public static function preview($title, $max)
	{
		$max = max(Parich_Slug_Sanitize::MIN_MAX, min(Parich_Slug_Sanitize::HARD_MAX, (int) $max));
		$slug = Parich_Slug_Sanitize::sanitize_with_max(remove_accents($title), $max);
		$uncut = Parich_Slug_Sanitize::sanitize_with_max(remove_accents($title), Parich_Slug_Sanitize::HARD_MAX);
		/* translators: 1: length in bytes, 2: longest allowed */
		$text = sprintf(__('%1$d of %2$d bytes', 'parich-long-slug'), strlen($slug), $max);
		if (strlen($slug) < strlen($uncut)) {
			$text .= ' — ' . __('cut to fit', 'parich-long-slug');
		}
		return array(
			'slug' => $slug,
			'readable' => urldecode($slug),
			'bytes' => strlen($slug),
			'max' => $max,
			'cut' => strlen($slug) < strlen($uncut),
			'text' => $text,
		);
	}

	/**
	 * Facts shown in the status table, one row each.
	 *
	 * @param array|null $columns      table => column; default: the site's slug columns.
	 * @param string|null $legacy_table Long URL Maker's backup table; default: the site's.
	 * @return array[] {key, label, value, state: ok|warning|error|info, note}
	 */
	public static function status($columns = null, $legacy_table = null)
	{
		global $wpdb;
		$rows = array();

		$types = array();
		$short = false;
		$charsets = array();
		foreach ($columns ? $columns : Parich_Slug_Schema::columns() as $table => $column) {
			$info = Parich_Slug_Schema::get_column_info($table, $column);
			if (is_wp_error($info)) {
				$types[] = "{$table}.{$column}: " . $info->get_error_message();
				$short = true;
				continue;
			}
			$types[] = "{$table}.{$column}: {$info['type']}";
			$short = $short || null === $info['length'] || $info['length'] < Parich_Slug_Schema::LENGTH;
			$charsets[] = "{$info['charset']} / {$info['collation']}";
		}
		$rows[] = array(
			'key' => 'columns',
			'label' => __('Slug columns', 'parich-long-slug'),
			'value' => implode(', ', $types),
			'state' => $short ? 'error' : 'ok',
			'note' => $short ? __('Long slugs are off until both columns are varchar(2000). Use "Set up again" below.', 'parich-long-slug') : '',
		);
		$charsets = array_unique($charsets);
		$unusual = (bool) array_filter($charsets, function ($charset) {
			return 0 !== strpos($charset, 'utf8');
		});
		$rows[] = array(
			'key' => 'charset',
			'label' => __('Character set', 'parich-long-slug'),
			'value' => implode(', ', $charsets),
			'state' => $unusual ? 'warning' : 'ok',
			'note' => $unusual ? __('Not UTF-8. Slugs still work because they are stored percent-encoded (plain ASCII), but titles in Thai may not be stored correctly elsewhere.', 'parich-long-slug') : '',
		);

		$guard_path = Parich_Slug_Guard::path();
		$installed = Parich_Slug_Guard::version_of($guard_path);
		$bundled = Parich_Slug_Guard::version_of(Parich_Slug_Guard::template());
		if (null === $installed) {
			$guard = array('error', __('Missing', 'parich-long-slug'), __('Without it, a WordPress update can cut slugs longer than 200 characters (they are restored from the shadow copy afterwards). Use "Set up again" below.', 'parich-long-slug'));
		} elseif ($installed !== $bundled) {
			/* translators: 1: installed version, 2: bundled version */
			$guard = array('warning', sprintf(__('Version %1$s (current: %2$s)', 'parich-long-slug'), $installed, $bundled), __('It is updated the next time an administrator opens wp-admin.', 'parich-long-slug'));
		} else {
			/* translators: %s: version */
			$guard = array('ok', sprintf(__('Installed, version %s', 'parich-long-slug'), $installed), '');
		}
		// Relative to the site root when it is inside it: the full server path is not needed to find it.
		$shown_path = wp_normalize_path($guard_path);
		$root = wp_normalize_path(ABSPATH);
		if (0 === strpos($shown_path, $root)) {
			$shown_path = substr($shown_path, strlen($root));
		}
		$rows[] = array('key' => 'guard', 'label' => __('Guard (must-use plugin)', 'parich-long-slug'), 'value' => $guard[1] . ' — ' . $shown_path, 'state' => $guard[0], 'note' => $guard[2]);

		$shadow_table = Parich_Slug_Shadow::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- status page, read on demand.
		$shadow_exists = $shadow_table === $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($shadow_table)));
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- plugin table.
		$shadow_rows = $shadow_exists ? (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i', $shadow_table)) : 0;
		$rows[] = array(
			'key' => 'shadow',
			'label' => __('Shadow copy of long slugs', 'parich-long-slug'),
			/* translators: %d: number of slugs */
			'value' => $shadow_exists ? sprintf(_n('%d slug longer than 200 characters', '%d slugs longer than 200 characters', $shadow_rows, 'parich-long-slug'), $shadow_rows) : __('Table missing', 'parich-long-slug'),
			'state' => $shadow_exists ? 'ok' : 'error',
			'note' => $shadow_exists ? '' : __('Use "Set up again" below.', 'parich-long-slug'),
		);

		$heal = get_option(Parich_Slug_Schema::LAST_HEAL_OPTION);
		if (is_array($heal) && !empty($heal['time'])) {
			$value = sprintf(
				/* translators: 1: date, 2: restored slugs, 3: slugs left alone */
				__('%1$s — restored %2$d, left alone %3$d', 'parich-long-slug'),
				wp_date(get_option('date_format') . ' ' . get_option('time_format'), (int) $heal['time']),
				isset($heal['restored']) ? (int) $heal['restored'] : 0,
				isset($heal['skipped']) ? count((array) $heal['skipped']) : 0
			);
			$rows[] = array('key' => 'heal', 'label' => __('Last repair after a WordPress update', 'parich-long-slug'), 'value' => $value, 'state' => 'warning', 'note' => __('The guard was missing during that update.', 'parich-long-slug'));
		} else {
			$rows[] = array('key' => 'heal', 'label' => __('Last repair after a WordPress update', 'parich-long-slug'), 'value' => __('Never needed', 'parich-long-slug'), 'state' => 'ok', 'note' => '');
		}

		$long_urls = self::long_permalinks();
		$rows[] = array(
			'key' => 'long_urls',
			/* translators: %s: 2,048 */
			'label' => sprintf(__('Links longer than %s characters', 'parich-long-slug'), number_format_i18n(self::URL_LIMIT)),
			'value' => $long_urls['ids'] ? implode(', ', array_map(function ($id) {
				return '#' . $id;
			}, array_slice($long_urls['ids'], 0, 10))) : __('None', 'parich-long-slug'),
			'state' => $long_urls['ids'] ? 'warning' : 'ok',
			'note' => $long_urls['ids'] ? __('Some browsers, crawlers and sitemap readers refuse links this long. Shorten these slugs or their parent pages.', 'parich-long-slug') : '',
		);

		$legacy = Parich_Slug_Legacy::traces($legacy_table);
		if ($legacy['active']) {
			$rows[] = array('key' => 'legacy', 'label' => __('Long URL Maker', 'parich-long-slug'), 'value' => __('Active', 'parich-long-slug'), 'state' => 'warning', 'note' => __('Deactivate it with the button in the notice above, not the usual Deactivate link.', 'parich-long-slug'));
		} elseif ($legacy['used']) {
			$details = array();
			if (null !== $legacy['backup_rows']) {
				/* translators: %d: rows */
				$details[] = sprintf(_n('its backup table has %d row', 'its backup table has %d rows', $legacy['backup_rows'], 'parich-long-slug'), $legacy['backup_rows']);
			}
			if ($legacy['options']) {
				$details[] = __('its settings are still stored', 'parich-long-slug');
			}
			$note = __('It was used on this site, so some slugs may have been cut or rewritten by it.', 'parich-long-slug');
			/**
			 * Filters the URL of the repair tool linked from the status table (empty: no link).
			 *
			 * @param string $url
			 */
			$repair_url = (string) apply_filters('parich_slug_repair_url', '');
			if ('' !== $repair_url) {
				$note .= ' ' . sprintf('<a href="%s">%s</a>', esc_url($repair_url), esc_html__('Scan for damaged slugs', 'parich-long-slug'));
			}
			$rows[] = array('key' => 'legacy', 'label' => __('Long URL Maker', 'parich-long-slug'), 'value' => ($legacy['installed'] ? __('Installed, inactive', 'parich-long-slug') : __('Removed', 'parich-long-slug')) . ($details ? '; ' . implode('; ', $details) : ''), 'state' => 'warning', 'note' => $note, 'note_html' => true);
		} else {
			$rows[] = array('key' => 'legacy', 'label' => __('Long URL Maker', 'parich-long-slug'), 'value' => $legacy['installed'] ? __('Installed, never used', 'parich-long-slug') : __('Not installed', 'parich-long-slug'), 'state' => 'ok', 'note' => '');
		}

		return $rows;
	}

	/**
	 * Published posts whose permalink is longer than URL_LIMIT. Only posts with a slug
	 * over 300 bytes, or a parent with one, can get there; at most 2,000 are checked.
	 *
	 * @return array {ids, checked}
	 */
	public static function long_permalinks()
	{
		global $wpdb;
		$types = array_values(get_post_types(array('public' => true)));
		$placeholders = implode(',', array_fill(0, count($types), '%s'));
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders built above; status page only.
		$candidates = $wpdb->get_col($wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- table names and IN lists of placeholders are built in this class; every value goes through prepare().
			"SELECT p.ID FROM {$wpdb->posts} p LEFT JOIN {$wpdb->posts} parent ON parent.ID = p.post_parent WHERE p.post_status = 'publish' AND p.post_type IN ({$placeholders}) AND (CHAR_LENGTH(p.post_name) > 300 OR CHAR_LENGTH(parent.post_name) > 300) ORDER BY p.ID LIMIT 2000",
			$types
		));
		$ids = array();
		foreach ($candidates as $id) {
			if (strlen((string) get_permalink((int) $id)) > self::URL_LIMIT) {
				$ids[] = (int) $id;
			}
		}
		return array('ids' => $ids, 'checked' => count($candidates));
	}

	public static function render_page()
	{
		if (!current_user_can(self::capability())) {
			wp_die(esc_html__('Sorry, you are not allowed to manage Parich Long Slug.', 'parich-long-slug'), '', array('response' => 403));
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- picks the tab to show.
		$tab = isset($_GET['tab']) && Parich_Slug_Repair_Admin::TAB === $_GET['tab'] ? Parich_Slug_Repair_Admin::TAB : 'settings';
		$tabs = array(
			'settings' => array(__('Settings', 'parich-long-slug'), admin_url('options-general.php?page=' . self::PAGE)),
			Parich_Slug_Repair_Admin::TAB => array(__('Repair slugs', 'parich-long-slug'), Parich_Slug_Repair_Admin::url()),
		);
		?>
		<div class="wrap">
			<h1><?php esc_html_e('Parich Long Slug', 'parich-long-slug'); ?></h1>
			<nav class="nav-tab-wrapper" aria-label="<?php esc_attr_e('Secondary menu', 'parich-long-slug'); ?>">
				<?php foreach ($tabs as $key => $link) : ?>
					<a href="<?php echo esc_url($link[1]); ?>" class="nav-tab<?php echo $key === $tab ? ' nav-tab-active' : ''; ?>"<?php echo $key === $tab ? ' aria-current="page"' : ''; ?>><?php echo esc_html($link[0]); ?></a>
				<?php endforeach; ?>
			</nav>
		<?php
		if (Parich_Slug_Repair_Admin::TAB === $tab) {
			Parich_Slug_Repair_Admin::render();
			echo '</div>';
			return;
		}
		$max = Parich_Slug_Sanitize::max_length();
		$icons = array('ok' => 'yes-alt', 'warning' => 'warning', 'error' => 'dismiss', 'info' => 'info');
		?>

			<form method="post" action="<?php echo esc_url(admin_url('options.php')); ?>">
				<?php settings_fields(self::GROUP); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="parich_slug_max_length"><?php esc_html_e('Longest new slug', 'parich-long-slug'); ?></label></th>
						<td>
							<input name="<?php echo esc_attr(Parich_Slug_Sanitize::MAX_OPTION); ?>" type="number" id="parich_slug_max_length" min="<?php echo esc_attr(Parich_Slug_Sanitize::MIN_MAX); ?>" max="<?php echo esc_attr(Parich_Slug_Sanitize::HARD_MAX); ?>" step="1" value="<?php echo esc_attr($max); ?>" class="small-text" aria-describedby="parich-slug-max-description" />
							<?php esc_html_e('bytes', 'parich-long-slug'); ?>
							<p class="description" id="parich-slug-max-description">
								<?php
								printf(
									/* translators: 1: lowest, 2: highest, 3: Thai characters at the current value */
									esc_html__('Between %1$d and %2$d, counted after percent-encoding: a Thai character takes 9 bytes and an English letter or digit 1, so the current value fits about %3$d Thai characters. WordPress alone stops at 200. Only new and edited slugs follow this setting; existing slugs are not changed.', 'parich-long-slug'),
									(int) Parich_Slug_Sanitize::MIN_MAX,
									(int) Parich_Slug_Sanitize::HARD_MAX,
									(int) floor($max / 9)
								);
								?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="parich-slug-preview-title"><?php esc_html_e('Try a title', 'parich-long-slug'); ?></label></th>
						<td>
							<input type="text" id="parich-slug-preview-title" class="large-text" autocomplete="off" />
							<p id="parich-slug-preview" aria-live="polite">
								<code id="parich-slug-preview-slug"></code>
								<span id="parich-slug-preview-info" class="description"></span>
							</p>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>

			<h2><?php esc_html_e('Status', 'parich-long-slug'); ?></h2>
			<table class="widefat striped" role="presentation">
				<tbody>
					<?php foreach (self::status() as $row) : ?>
						<tr>
							<th scope="row"><?php echo esc_html($row['label']); ?></th>
							<td>
								<span class="dashicons dashicons-<?php echo esc_attr($icons[$row['state']]); ?>" aria-hidden="true"></span>
								<span class="screen-reader-text"><?php echo esc_html($row['state']); ?>:</span>
								<?php echo esc_html($row['value']); ?>
								<?php if ('' !== $row['note']) : ?>
									<p class="description"><?php echo empty($row['note_html']) ? esc_html($row['note']) : wp_kses($row['note'], array('a' => array('href' => array()))); ?></p>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<?php if (current_user_can('activate_plugins')) : ?>
				<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
					<input type="hidden" name="action" value="<?php echo esc_attr(Parich_Slug_Schema::FIX_ACTION); ?>" />
					<?php wp_nonce_field(Parich_Slug_Schema::FIX_ACTION); ?>
					<p>
						<?php submit_button(__('Set up again', 'parich-long-slug'), 'secondary', 'submit', false); ?>
						<span class="description"><?php esc_html_e('Widens the slug columns, installs the guard and creates the shadow table if any of them is missing. Safe to repeat.', 'parich-long-slug'); ?></span>
					</p>
				</form>
			<?php endif; ?>

			<div class="parich-slug-support">
				<span class="parich-slug-support__icon dashicons dashicons-heart" aria-hidden="true"></span>
				<div>
					<h2><?php esc_html_e('Support this plugin', 'parich-long-slug'); ?></h2>
					<p><?php esc_html_e('Parich Long Slug is free, with every feature for everyone. If it is useful to you, you can support its development:', 'parich-long-slug'); ?></p>
					<p><a class="button button-primary button-hero" href="<?php echo esc_url(PARICH_SLUG_DONATE_URL); ?>"><?php esc_html_e('Donate', 'parich-long-slug'); ?></a></p>
					<p class="description"><?php esc_html_e('Secure payment by Stripe.', 'parich-long-slug'); ?></p>
				</div>
			</div>
		</div>
		<?php
	}
}
