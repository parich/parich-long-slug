<?php
/**
 * Settings → Long Slug → Repair: scan for slugs Long URL Maker damaged, look at what
 * would be proposed and why, import URL lists and CSV, export the results.
 *
 * The scan only reads; nothing here changes a slug.
 *
 * @package Parich_Long_Slug
 */

if (!defined('ABSPATH')) {
	exit;
}

class Parich_Slug_Repair_Admin
{
	const TAB = 'repair';
	const SCAN_ACTION = 'parich_slug_scan';
	const IMPORT_ACTION = 'parich_slug_import';
	const EXPORT_ACTION = 'parich_slug_export';
	const CLEAR_ACTION = 'parich_slug_clear_imports';
	const APPLY_ACTION = 'parich_slug_apply';
	const UNDO_ACTION = 'parich_slug_undo';
	const REDIRECT_ACTION = 'parich_slug_redirect';
	const EXPORT_REDIRECTS_ACTION = 'parich_slug_export_redirects';
	const MAP_ACTION = 'parich_slug_map';
	const NOTICE_TRANSIENT = 'parich_slug_repair_notice_';
	const PER_PAGE = 50;

	/** Seconds one admin-ajax or admin-post request keeps working. */
	const STEP_SECONDS = 8;

	public static function init()
	{
		add_action('wp_ajax_' . self::SCAN_ACTION, array(__CLASS__, 'ajax_scan'));
		add_action('wp_ajax_' . self::APPLY_ACTION, array(__CLASS__, 'ajax_apply'));
		add_action('admin_post_' . self::IMPORT_ACTION, array(__CLASS__, 'handle_import'));
		add_action('admin_post_' . self::EXPORT_ACTION, array(__CLASS__, 'handle_export'));
		add_action('admin_post_' . self::CLEAR_ACTION, array(__CLASS__, 'handle_clear'));
		add_action('admin_post_' . self::UNDO_ACTION, array(__CLASS__, 'handle_undo'));
		add_action('admin_post_' . self::REDIRECT_ACTION, array(__CLASS__, 'handle_redirect'));
		add_action('admin_post_' . self::EXPORT_REDIRECTS_ACTION, array(__CLASS__, 'handle_export_redirects'));
		add_action('admin_post_' . self::MAP_ACTION, array(__CLASS__, 'handle_map'));
		add_action('admin_enqueue_scripts', array(__CLASS__, 'enqueue'));
		add_action('after_plugin_row_' . plugin_basename(PARICH_SLUG_FILE), array(__CLASS__, 'plugin_row_redirects'));
		add_filter('parich_slug_repair_url', array(__CLASS__, 'url'));
	}

	/**
	 * Under this plugin's own row on the Plugins screen (where core shows update messages),
	 * while repair redirects exist: they stop when the plugin is deactivated and are
	 * deleted with it, so offer to download them. Not an admin notice.
	 */
	public static function plugin_row_redirects()
	{
		$count = (int) get_option(Parich_Slug_Redirects::COUNT_OPTION);
		if (!$count || !current_user_can('activate_plugins')) {
			return;
		}
		$table = isset($GLOBALS['wp_list_table']) && $GLOBALS['wp_list_table'] instanceof WP_List_Table ? $GLOBALS['wp_list_table'] : null;
		printf(
			'<tr class="plugin-update-tr active"><td colspan="%d" class="plugin-update colspanchange"><div class="notice inline notice-info notice-alt"><p>%s <a href="%s">%s</a></p></div></td></tr>',
			$table ? (int) $table->get_column_count() : 3,
			esc_html(sprintf(
				/* translators: %d: number of redirects */
				_n('Parich Long Slug redirects %d old address to a repaired post or term. Redirects stop when the plugin is deactivated, and are deleted with it.', 'Parich Long Slug redirects %d old addresses to repaired posts and terms. Redirects stop when the plugin is deactivated, and are deleted with it.', $count, 'parich-long-slug'),
				$count
			)),
			esc_url(self::url()),
			esc_html__('Download them first to keep them in another redirect plugin.', 'parich-long-slug')
		);
	}

	public static function url($url = '', array $args = array())
	{
		return add_query_arg(array_merge(array('page' => Parich_Slug_Admin::PAGE, 'tab' => self::TAB), $args), admin_url('options-general.php'));
	}

	public static function enqueue($hook_suffix)
	{
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only picks the script to load.
		if ('settings_page_' . Parich_Slug_Admin::PAGE !== $hook_suffix || !isset($_GET['tab']) || self::TAB !== $_GET['tab']) {
			return;
		}
		wp_enqueue_script('parich-slug-admin-repair', plugins_url('assets/admin-repair.js', PARICH_SLUG_FILE), array(), PARICH_SLUG_VERSION, true);
		wp_localize_script('parich-slug-admin-repair', 'parichSlugRepair', array(
			'ajaxUrl' => admin_url('admin-ajax.php'),
			'action' => self::SCAN_ACTION,
			'nonce' => wp_create_nonce(self::SCAN_ACTION),
			'reloadUrl' => self::url(),
			'starting' => __('Starting the scan…', 'parich-long-slug'),
			/* translators: 1: step name, 2: objects checked so far */
			'progress' => __('Scanning: %1$s — %2$d posts and terms checked', 'parich-long-slug'),
			'done' => __('Scan finished. Loading the results…', 'parich-long-slug'),
			'error' => __('The scan stopped with an error. Try again; the scan restarts from the beginning.', 'parich-long-slug'),
			'steps' => self::step_labels(),
			'applyAction' => self::APPLY_ACTION,
			'applyNonce' => wp_create_nonce(self::APPLY_ACTION),
			/* translators: 1: items done, 2: items in all */
			'applying' => __('Repairing: %1$d of %2$d…', 'parich-long-slug'),
			'confirmSelected' => __('Change the slugs of the selected items? A redirect is made from every old address. You can undo the whole batch later.', 'parich-long-slug'),
			'confirmAuto' => __('Change the slugs of every item marked "Ready"? A redirect is made from every old address. You can undo the whole batch later.', 'parich-long-slug'),
			'nothingSelected' => __('Select at least one item.', 'parich-long-slug'),
			'applyError' => __('The repair stopped:', 'parich-long-slug'),
		));
	}

	/**
	 * admin-ajax: op=start begins a batch ("auto" or the selected items with own slugs),
	 * op=step continues it for a few seconds.
	 */
	public static function ajax_apply()
	{
		check_ajax_referer(self::APPLY_ACTION, 'nonce');
		if (!current_user_can(Parich_Slug_Admin::capability())) {
			wp_die(-1, 403);
		}
		$op = isset($_POST['op']) ? sanitize_key(wp_unslash($_POST['op'])) : '';
		if ('start' === $op) {
			if (isset($_POST['mode']) && 'auto' === $_POST['mode']) {
				$choices = 'auto';
			} else {
				$choices = array();
				$keys = isset($_POST['repair']) ? array_map('sanitize_key', (array) wp_unslash($_POST['repair'])) : array();
				// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- slugs typed by the admin; each is normalised and validated by Parich_Slug_Repair_Applier::chosen_value().
				$own = isset($_POST['own']) && is_array($_POST['own']) ? wp_unslash($_POST['own']) : array();
				foreach ($keys as $key) {
					$choices[$key] = isset($own[$key]) && is_string($own[$key]) ? sanitize_text_field($own[$key]) : '';
				}
			}
			$state = Parich_Slug_Repair_Applier::start($choices);
		} elseif ('step' === $op) {
			$batch = isset($_POST['batch']) ? absint(wp_unslash($_POST['batch'])) : 0;
			$state = Parich_Slug_Repair_Applier::state();
			if (!$state || (int) $state['batch'] !== $batch) {
				wp_send_json_error(array('message' => __('Another repair was started meanwhile.', 'parich-long-slug')), 409);
			}
			$until = microtime(true) + self::STEP_SECONDS;
			do {
				$state = Parich_Slug_Repair_Applier::step();
			} while (!is_wp_error($state) && empty($state['finished']) && microtime(true) < $until);
			if (!is_wp_error($state) && !empty($state['finished'])) {
				self::notice_applied($state);
			}
		} else {
			wp_send_json_error(array('message' => 'Unknown operation.'), 400);
		}
		if (is_wp_error($state)) {
			wp_send_json_error(array('message' => $state->get_error_message()), 409);
		}
		wp_send_json_success(array('batch' => (int) $state['batch'], 'done' => min($state['cursor'], count($state['queue'])), 'total' => count($state['queue']), 'finished' => !empty($state['finished'])));
	}

	/**
	 * What to do after a batch: caches, sitemaps, SEO data, links still pointing at old addresses.
	 */
	private static function notice_applied(array $state)
	{
		$parts = array(
			/* translators: 1: batch number, 2: items repaired, 3: items skipped, 4: redirects made */
			sprintf(__('Repair %1$d: %2$d slugs repaired, %3$d skipped, %4$d redirects made.', 'parich-long-slug'), $state['batch'], $state['applied'], count($state['skipped']), $state['redirects']),
			__('Clear any page cache and let your SEO or sitemap plugin rebuild its sitemap.', 'parich-long-slug'),
		);
		if (defined('WPSEO_VERSION')) {
			$parts[] = __('Yoast SEO: run "Optimize SEO data" (Yoast SEO → Tools) so it reads the repaired addresses.', 'parich-long-slug');
		}
		$links = Parich_Slug_Repair_Applier::links_to_redirects((int) $state['scan']);
		if ($links) {
			/* translators: %d: number of links */
			$parts[] = sprintf(_n('%d link on your site still points at an old address; the redirects cover it, but you may want to update it.', '%d links on your site still point at old addresses; the redirects cover them, but you may want to update them.', $links, 'parich-long-slug'), $links);
		}
		foreach (array_slice($state['skipped'], 0, 10) as $skip) {
			$parts[] = $skip['key'] . ': ' . $skip['reason'];
		}
		self::notice('success', implode(' ', $parts));
	}

	/**
	 * admin-post: undoes a batch (as much as fits in a few seconds; the rest on the next click).
	 */
	public static function handle_undo()
	{
		self::check_capability();
		check_admin_referer(self::UNDO_ACTION);
		$batch = isset($_POST['batch']) ? absint(wp_unslash($_POST['batch'])) : 0;
		if (!Parich_Slug_Repair_Applier::batch_exists($batch)) {
			self::notice('error', __('That repair does not exist.', 'parich-long-slug'));
			wp_safe_redirect(self::url());
			exit;
		}
		$restored = 0;
		$kept = array();
		$until = microtime(true) + self::STEP_SECONDS;
		do {
			$result = Parich_Slug_Repair_Applier::undo($batch);
			$restored += $result['restored'];
			$kept = array_merge($kept, $result['kept']);
		} while ($result['remaining'] > 0 && microtime(true) < $until);
		/* translators: 1: batch number, 2: slugs put back, 3: slugs left (changed since), 4: items still to undo */
		$message = sprintf(__('Undo of repair %1$d: %2$d slugs put back, %3$d left as they were changed since, %4$d still to undo.', 'parich-long-slug'), $batch, $restored, count($kept), $result['remaining']);
		foreach (array_slice($kept, 0, 10) as $item) {
			$message .= ' ' . $item['key'] . ': ' . $item['reason'];
		}
		self::notice($result['remaining'] ? 'error' : 'success', $result['remaining'] ? $message . ' ' . __('Click Undo again to go on.', 'parich-long-slug') : $message);
		wp_safe_redirect(self::url());
		exit;
	}

	/**
	 * admin-post: turns a redirect off or on, or deletes it.
	 */
	public static function handle_redirect()
	{
		self::check_capability();
		check_admin_referer(self::REDIRECT_ACTION);
		$id = isset($_POST['redirect']) ? absint(wp_unslash($_POST['redirect'])) : 0;
		$do = isset($_POST['do']) ? sanitize_key(wp_unslash($_POST['do'])) : '';
		if ('delete' === $do) {
			Parich_Slug_Redirects::delete($id);
		} elseif (in_array($do, array('on', 'off'), true)) {
			Parich_Slug_Redirects::set_active($id, 'on' === $do);
		}
		wp_safe_redirect(self::url());
		exit;
	}

	public static function handle_export_redirects()
	{
		self::check_capability();
		check_admin_referer(self::EXPORT_REDIRECTS_ACTION);
		nocache_headers();
		header('Content-Type: text/csv; charset=utf-8');
		header('Content-Disposition: attachment; filename="parich-long-slug-redirects-' . gmdate('Y-m-d') . '.csv"');
		echo self::csv(Parich_Slug_Redirects::export_rows()); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV download, cells neutralised by Parich_Slug_Repair_Scanner::csv_cell().
		exit;
	}

	/**
	 * admin-post: sends a logged 404 address to a post the admin names (ID or link).
	 */
	public static function handle_map()
	{
		self::check_capability();
		check_admin_referer(self::MAP_ACTION);
		$path = isset($_POST['path']) ? sanitize_text_field(wp_unslash($_POST['path'])) : '';
		$target = isset($_POST['target']) ? trim(sanitize_text_field(wp_unslash($_POST['target']))) : '';
		$result = self::map($path, $target);
		if (is_wp_error($result)) {
			self::notice('error', $result->get_error_message());
		} else {
			/* translators: 1: old address, 2: post title */
			self::notice('success', sprintf(__('%1$s now redirects to "%2$s".', 'parich-long-slug'), self::readable($path), get_the_title($result)));
		}
		wp_safe_redirect(self::url());
		exit;
	}

	/**
	 * @return int|WP_Error Post ID the path now redirects to.
	 */
	public static function map($path, $target)
	{
		$ready = Parich_Slug_Repair_Scanner::maybe_install();
		if (is_wp_error($ready)) {
			return $ready;
		}
		$target = trim((string) $target);
		$id = ctype_digit($target) ? (int) $target : ('' === $target ? 0 : (int) url_to_postid($target));
		$post = $id ? get_post($id) : null;
		if (!$post || !is_post_publicly_viewable($post)) {
			return new WP_Error('parich_slug_map_target', __('Give the ID or the link of a published post or page.', 'parich-long-slug'));
		}
		$source = Parich_Slug_Repair_Util::path_of($path, home_url('/'));
		if (null === $source || '/' === $source || Parich_Slug_Repair_Util::path_of(get_permalink($post)) === $source) {
			return new WP_Error('parich_slug_map_source', __('That address cannot be redirected.', 'parich-long-slug'));
		}
		if (!Parich_Slug_Redirects::add($source, 'post', $post->ID, 'manual-map')) {
			return new WP_Error('parich_slug_map_taken', __('That address already redirects somewhere else. Turn that redirect off first.', 'parich-long-slug'));
		}
		Parich_Slug_Redirects::recount();
		return (int) $post->ID;
	}

	private static function step_labels()
	{
		return array(
			'urls' => __('collecting URLs from logs, redirects and links', 'parich-long-slug'),
			'posts' => __('checking posts', 'parich-long-slug'),
			'terms' => __('checking categories and tags', 'parich-long-slug'),
			'd4' => __('looking for shared slugs', 'parich-long-slug'),
			'redecide' => __('weighing the evidence', 'parich-long-slug'),
			'contest' => __('settling slugs wanted twice', 'parich-long-slug'),
			'collisions' => __('checking proposals against existing slugs', 'parich-long-slug'),
			'structure' => __('ordering parents before children', 'parich-long-slug'),
			'done' => __('done', 'parich-long-slug'),
		);
	}

	/**
	 * admin-ajax: op=start begins a scan, op=step continues it for a few seconds.
	 */
	public static function ajax_scan()
	{
		check_ajax_referer(self::SCAN_ACTION, 'nonce');
		if (!current_user_can(Parich_Slug_Admin::capability())) {
			wp_die(-1, 403);
		}
		$op = isset($_POST['op']) ? sanitize_key(wp_unslash($_POST['op'])) : '';
		if ('start' === $op) {
			$state = Parich_Slug_Repair_Scanner::start();
		} elseif ('step' === $op) {
			$scan = isset($_POST['scan']) ? absint(wp_unslash($_POST['scan'])) : 0;
			$state = Parich_Slug_Repair_Scanner::state();
			if (!$state || (int) $state['id'] !== $scan) {
				wp_send_json_error(array('message' => __('Another scan was started meanwhile.', 'parich-long-slug')), 409);
			}
			$until = microtime(true) + self::STEP_SECONDS;
			do {
				$state = Parich_Slug_Repair_Scanner::step();
			} while (!is_wp_error($state) && 'done' !== $state['step'] && microtime(true) < $until);
		} else {
			wp_send_json_error(array('message' => 'Unknown operation.'), 400);
		}
		if (is_wp_error($state)) {
			wp_send_json_error(array('message' => $state->get_error_message()), 500);
		}
		wp_send_json_success(array('scan' => (int) $state['id'], 'step' => $state['step'], 'objects' => (int) $state['objects'], 'done' => 'done' === $state['step']));
	}

	/**
	 * Refuses users who may not use the repair tool (403). Each handler then checks its nonce.
	 */
	private static function check_capability()
	{
		if (!current_user_can(Parich_Slug_Admin::capability())) {
			wp_die(esc_html__('Sorry, you are not allowed to use the repair tool.', 'parich-long-slug'), '', array('response' => 403));
		}
	}

	private static function notice($type, $message)
	{
		set_transient(self::NOTICE_TRANSIENT . get_current_user_id(), array('type' => $type, 'message' => $message), 10 * MINUTE_IN_SECONDS);
	}

	/**
	 * admin-post: a URL list or a CSV file "type,id,slug".
	 */
	public static function handle_import()
	{
		self::check_capability();
		check_admin_referer(self::IMPORT_ACTION);
		$kind = isset($_POST['kind']) && 'csv' === $_POST['kind'] ? 'csv' : 'urls';
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- the upload array is checked field by field below.
		$file = isset($_FILES['import_file']) && is_array($_FILES['import_file']) ? $_FILES['import_file'] : null;
		$name = $file && isset($file['name']) ? sanitize_file_name((string) $file['name']) : '';
		if ('' !== $name && !preg_match('/\.(txt|csv)$/i', $name)) {
			self::notice('error', __('Only .txt and .csv files can be imported.', 'parich-long-slug'));
		} elseif (!$file || !empty($file['error']) || empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
			self::notice('error', __('No file was uploaded.', 'parich-long-slug'));
		} else {
			$lines = Parich_Slug_Repair_Scanner::read_import_file($file['tmp_name']);
			if (is_wp_error($lines)) {
				self::notice('error', $lines->get_error_message());
			} else {
				$result = 'csv' === $kind ? Parich_Slug_Repair_Scanner::import_csv($lines) : Parich_Slug_Repair_Scanner::import_urls($lines);
				if (!empty($result['error'])) {
					self::notice('error', $result['error']);
				} else {
					/* translators: 1: lines imported, 2: lines skipped */
					self::notice('success', sprintf(__('Imported %1$d lines, skipped %2$d. Scan again to use them.', 'parich-long-slug'), $result['added'], $result['skipped']));
				}
			}
		}
		wp_safe_redirect(self::url());
		exit;
	}

	public static function handle_clear()
	{
		self::check_capability();
		check_admin_referer(self::CLEAR_ACTION);
		Parich_Slug_Repair_Scanner::clear_imports();
		self::notice('success', __('Imported URLs and CSV rows were removed.', 'parich-long-slug'));
		wp_safe_redirect(self::url());
		exit;
	}

	/**
	 * admin-post: the results of the last scan as CSV (UTF-8 with BOM, for spreadsheets).
	 */
	public static function handle_export()
	{
		self::check_capability();
		check_admin_referer(self::EXPORT_ACTION);
		$state = Parich_Slug_Repair_Scanner::state();
		if (!$state || 'done' !== $state['step']) {
			wp_die(esc_html__('There are no scan results to export yet.', 'parich-long-slug'), '', array('response' => 404));
		}
		nocache_headers();
		header('Content-Type: text/csv; charset=utf-8');
		header('Content-Disposition: attachment; filename="parich-long-slug-scan-' . gmdate('Y-m-d') . '.csv"');
		echo self::csv(Parich_Slug_Repair_Scanner::export_rows((int) $state['id'])); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV download, cells neutralised by Parich_Slug_Repair_Scanner::csv_cell().
		exit;
	}

	/**
	 * CSV text (RFC 4180: a field with a comma, quote or line break is quoted, quotes
	 * doubled), UTF-8 with a BOM so spreadsheet programs read Thai correctly.
	 */
	public static function csv(array $rows)
	{
		$lines = array();
		foreach ($rows as $row) {
			$lines[] = implode(',', array_map(function ($cell) {
				$cell = (string) $cell;
				return preg_match('/[",\r\n]/', $cell) ? '"' . str_replace('"', '""', $cell) . '"' : $cell;
			}, $row));
		}
		return "\xEF\xBB\xBF" . implode("\n", $lines) . "\n";
	}

	/**
	 * Readable form of an encoded slug (Thai shown as Thai); bytes a cut left behind are dropped.
	 */
	public static function readable($slug)
	{
		$text = rawurldecode((string) $slug);
		return function_exists('wp_check_invalid_utf8') ? wp_check_invalid_utf8($text, true) : $text;
	}

	public static function source_label($source)
	{
		$labels = array(
			'S1' => __('Long URL Maker backup', 'parich-long-slug'),
			'S3' => __('Slug before trashing', 'parich-long-slug'),
			'S4' => __('SEO plugin data', 'parich-long-slug'),
			'S5' => __('404 log', 'parich-long-slug'),
			'S6' => __('Existing redirect', 'parich-long-slug'),
			'S7' => __('Link on the site', 'parich-long-slug'),
			'S8' => __('Made from the title', 'parich-long-slug'),
			'S9C' => __('Imported CSV', 'parich-long-slug'),
			'S9U' => __('Imported URL', 'parich-long-slug'),
			'S10' => __('GUID', 'parich-long-slug'),
			'ACFM' => __('ACF: saved values', 'parich-long-slug'),
			'ACFO' => __('ACF: options page', 'parich-long-slug'),
			'ACFJ' => __('ACF: acf-json file', 'parich-long-slug'),
			'THEME' => __('Theme it belongs to', 'parich-long-slug'),
			'TPL' => __('Template with this title', 'parich-long-slug'),
		);
		return isset($labels[$source]) ? $labels[$source] : $source;
	}

	public static function code_labels()
	{
		return array(
			'D1' => __('Cut at 200 characters', 'parich-long-slug'),
			'D2' => __('Rewritten from the title', 'parich-long-slug'),
			'D3' => __('Put back to an older slug', 'parich-long-slug'),
			'D4' => __('Same slug as another item', 'parich-long-slug'),
			'D5' => __('Cut to fit "-2" when made', 'parich-long-slug'),
			'D6' => __('System key (ACF field, template, styles…)', 'parich-long-slug'),
			'D7' => __('Category or tag', 'parich-long-slug'),
		);
	}

	public static function reason_label($reason)
	{
		$labels = array(
			'd3' => __('Long URL Maker put back an older slug: both were in use, so choose one.', 'parich-long-slug'),
			'd4' => __('Another item keeps the same slug.', 'parich-long-slug'),
			'd5' => __('Cut when it was made: this URL never changed. Lengthening it is optional.', 'parich-long-slug'),
			'conflict' => __('Strong sources disagree.', 'parich-long-slug'),
			'current_confirmed' => __('A strong source says the current slug is right.', 'parich-long-slug'),
			'weak' => __('Only one source that can be wrong.', 'parich-long-slug'),
			'unslugged' => __('Drafts get their slug when published.', 'parich-long-slug'),
			'stale_backup' => __('The slug was changed after Long URL Maker\'s backup was made.', 'parich-long-slug'),
			'unchecked_history' => __('Only the backup says so, and WordPress keeps no history of page slugs.', 'parich-long-slug'),
			'contested' => __('Another item would get the same slug.', 'parich-long-slug'),
			'collision' => __('Another item already uses this slug.', 'parich-long-slug'),
			'system_check' => __('WordPress or a plugin finds this item by this key: check that it is the right one.', 'parich-long-slug'),
			'shared_theme' => __('Several global styles belong to this theme: they are numbered in the order they were made.', 'parich-long-slug'),
			'report_only' => __('A cache WordPress rebuilds by itself: nothing to repair.', 'parich-long-slug'),
		);
		return isset($labels[$reason]) ? $labels[$reason] : $reason;
	}

	private static function group_labels()
	{
		return array(
			'auto' => __('Ready', 'parich-long-slug'),
			'review' => __('Check', 'parich-long-slug'),
			'unresolved' => __('Unknown', 'parich-long-slug'),
		);
	}

	/**
	 * What must be true before repairs can be applied (shown now, enforced when applying).
	 *
	 * @return array[] {label, ok, note}
	 */
	public static function preconditions()
	{
		return array(
			array(
				'label' => __('Slug columns hold 2,000 characters', 'parich-long-slug'),
				'ok' => Parich_Slug_Schema::columns_ok(),
				'note' => __('Use "Set up again" on the Settings tab.', 'parich-long-slug'),
			),
			array(
				'label' => __('Long URL Maker is not active', 'parich-long-slug'),
				'ok' => null === Parich_Slug_Legacy::active_basename(),
				'note' => __('Deactivate it with the button in the notice at the top of the page.', 'parich-long-slug'),
			),
			array(
				'label' => __('The guard is installed', 'parich-long-slug'),
				'ok' => Parich_Slug_Guard::is_current(),
				'note' => __('Use "Set up again" on the Settings tab.', 'parich-long-slug'),
			),
		);
	}

	public static function render()
	{
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- filters and paging of a read-only list.
		$group = isset($_GET['group']) ? sanitize_key(wp_unslash($_GET['group'])) : '';
		$group = array_key_exists($group, self::group_labels()) ? $group : '';
		$code = isset($_GET['code']) ? strtoupper(sanitize_key(wp_unslash($_GET['code']))) : '';
		$code = array_key_exists($code, self::code_labels()) ? $code : '';
		$paged = isset($_GET['paged']) ? max(1, absint(wp_unslash($_GET['paged']))) : 1;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$notice = get_transient(self::NOTICE_TRANSIENT . get_current_user_id());
		if (is_array($notice)) {
			delete_transient(self::NOTICE_TRANSIENT . get_current_user_id());
			printf('<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr('error' === $notice['type'] ? 'error' : 'success'), esc_html($notice['message']));
		}
		$state = Parich_Slug_Repair_Scanner::state();
		$done = $state && 'done' === $state['step'] && null === $state['prefix'];
		?>
		<p><?php esc_html_e('Finds slugs that Long URL Maker cut, rewrote from the title or put back to an older value, and proposes the original slug with the evidence for it. Scanning only reads your site; nothing is changed.', 'parich-long-slug'); ?></p>

		<h2><?php esc_html_e('Before repairing', 'parich-long-slug'); ?></h2>
		<ul>
			<?php foreach (self::preconditions() as $check) : ?>
				<li>
					<span class="dashicons dashicons-<?php echo $check['ok'] ? 'yes-alt' : 'dismiss'; ?>" aria-hidden="true"></span>
					<span class="screen-reader-text"><?php echo $check['ok'] ? esc_html__('Done:', 'parich-long-slug') : esc_html__('Not yet:', 'parich-long-slug'); ?></span>
					<?php echo esc_html($check['label']); ?>
					<?php if (!$check['ok']) : ?>
						— <?php echo esc_html($check['note']); ?>
					<?php endif; ?>
				</li>
			<?php endforeach; ?>
			<li>
				<span class="dashicons dashicons-warning" aria-hidden="true"></span>
				<?php esc_html_e('Back up the database before applying any repair.', 'parich-long-slug'); ?>
			</li>
		</ul>

		<h2><?php esc_html_e('Scan', 'parich-long-slug'); ?></h2>
		<p>
			<button type="button" class="button button-primary" id="parich-slug-scan"><?php echo $done ? esc_html__('Scan again', 'parich-long-slug') : esc_html__('Scan for damaged slugs', 'parich-long-slug'); ?></button>
			<span id="parich-slug-scan-status" role="status" aria-live="polite"></span>
		</p>
		<?php
		if ($state && !$done && 'done' !== $state['step']) {
			echo '<p class="description">' . esc_html__('A scan did not finish. Start it again to see results.', 'parich-long-slug') . '</p>';
		}
		if ($done) {
			self::render_results($state, $group, $code, $paged);
		}
		self::render_batches();
		self::render_redirects();
		self::render_imports();
	}

	private static function render_batches()
	{
		$batches = Parich_Slug_Repair_Applier::batches();
		if (!$batches) {
			return;
		}
		?>
		<h2><?php esc_html_e('Repairs made', 'parich-long-slug'); ?></h2>
		<table class="widefat striped">
			<thead><tr><th scope="col"><?php esc_html_e('Repair', 'parich-long-slug'); ?></th><th scope="col"><?php esc_html_e('When', 'parich-long-slug'); ?></th><th scope="col"><?php esc_html_e('By', 'parich-long-slug'); ?></th><th scope="col"><?php esc_html_e('Slugs', 'parich-long-slug'); ?></th><th scope="col"><span class="screen-reader-text"><?php esc_html_e('Actions', 'parich-long-slug'); ?></span></th></tr></thead>
			<tbody>
				<?php foreach ($batches as $batch) : ?>
					<?php $user = get_userdata((int) $batch->user_id); ?>
					<tr>
						<td>#<?php echo (int) $batch->batch_id; ?></td>
						<td><?php echo esc_html(wp_date(get_option('date_format') . ' ' . get_option('time_format'), strtotime($batch->applied_at . ' UTC'))); ?></td>
						<td><?php echo esc_html($user ? $user->display_name : '—'); ?></td>
						<td>
							<?php
							/* translators: 1: repaired, 2: undone, 3: left as they were changed since */
							echo esc_html(sprintf(__('%1$d repaired, %2$d undone, %3$d kept', 'parich-long-slug'), (int) $batch->applied, (int) $batch->undone, (int) $batch->kept));
							?>
						</td>
						<td>
							<?php if ((int) $batch->applied > 0) : ?>
								<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
									<input type="hidden" name="action" value="<?php echo esc_attr(self::UNDO_ACTION); ?>" />
									<input type="hidden" name="batch" value="<?php echo (int) $batch->batch_id; ?>" />
									<?php wp_nonce_field(self::UNDO_ACTION); ?>
									<?php submit_button(__('Undo', 'parich-long-slug'), 'secondary small', 'submit', false, array('aria-label' => sprintf(/* translators: %d: batch number */ __('Undo repair %d', 'parich-long-slug'), (int) $batch->batch_id))); ?>
								</form>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	private static function render_redirects()
	{
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- paging of a read-only list.
		$page = isset($_GET['rpaged']) ? max(1, absint(wp_unslash($_GET['rpaged']))) : 1;
		$list = Parich_Slug_Redirects::page(($page - 1) * self::PER_PAGE, self::PER_PAGE);
		if (!$list['total']) {
			return;
		}
		$origins = array('repair' => __('Repair', 'parich-long-slug'), 'manual-map' => __('Matched by hand', 'parich-long-slug'));
		?>
		<h2><?php esc_html_e('Redirects', 'parich-long-slug'); ?></h2>
		<p><?php esc_html_e('Old addresses that now lead to the repaired post or term (301). They work only while Parich Long Slug is active: before removing it, download them and add them to a redirect plugin.', 'parich-long-slug'); ?></p>
		<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr(self::EXPORT_REDIRECTS_ACTION); ?>" />
			<?php wp_nonce_field(self::EXPORT_REDIRECTS_ACTION); ?>
			<p><?php submit_button(__('Download the redirects (CSV)', 'parich-long-slug'), 'secondary', 'submit', false); ?></p>
		</form>
		<table class="widefat striped">
			<thead><tr><th scope="col"><?php esc_html_e('Old address', 'parich-long-slug'); ?></th><th scope="col"><?php esc_html_e('Goes to', 'parich-long-slug'); ?></th><th scope="col"><?php esc_html_e('Made by', 'parich-long-slug'); ?></th><th scope="col"><?php esc_html_e('Used', 'parich-long-slug'); ?></th><th scope="col"><span class="screen-reader-text"><?php esc_html_e('Actions', 'parich-long-slug'); ?></span></th></tr></thead>
			<tbody>
				<?php foreach ($list['rows'] as $row) : ?>
					<?php $target = Parich_Slug_Redirects::target_url($row->object_type, (int) $row->object_id); ?>
					<tr>
						<td><code><?php echo esc_html(self::readable($row->source_path)); ?></code><?php echo (int) $row->is_active ? '' : ' <em>' . esc_html__('(off)', 'parich-long-slug') . '</em>'; ?></td>
						<td><?php echo $target ? '<a href="' . esc_url($target) . '">' . esc_html(self::readable(Parich_Slug_Repair_Util::path_of($target))) . '</a>' : esc_html__('Not published any more: the old address shows "not found".', 'parich-long-slug'); ?></td>
						<td><?php echo esc_html((isset($origins[$row->origin]) ? $origins[$row->origin] : $row->origin) . ((int) $row->batch_id ? ' #' . (int) $row->batch_id : '')); ?></td>
						<td><?php echo esc_html(number_format_i18n((int) $row->hits) . ($row->last_hit ? ' · ' . wp_date(get_option('date_format'), strtotime($row->last_hit . ' UTC')) : '')); ?></td>
						<td>
							<?php foreach (array((int) $row->is_active ? 'off' : 'on', 'delete') as $do) : ?>
								<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline">
									<input type="hidden" name="action" value="<?php echo esc_attr(self::REDIRECT_ACTION); ?>" />
									<input type="hidden" name="redirect" value="<?php echo (int) $row->id; ?>" />
									<input type="hidden" name="do" value="<?php echo esc_attr($do); ?>" />
									<?php wp_nonce_field(self::REDIRECT_ACTION); ?>
									<?php submit_button(array('off' => __('Turn off', 'parich-long-slug'), 'on' => __('Turn on', 'parich-long-slug'), 'delete' => __('Delete', 'parich-long-slug'))[$do], 'delete' === $do ? 'delete small' : 'small', 'submit', false); ?>
								</form>
							<?php endforeach; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
		$pages = (int) ceil($list['total'] / self::PER_PAGE);
		if ($pages > 1) {
			echo '<p class="tablenav-pages">' . wp_kses_post(paginate_links(array(
				'base' => esc_url_raw(add_query_arg('rpaged', '%#%', self::url())),
				'format' => '',
				'current' => $page,
				'total' => $pages,
			))) . '</p>';
		}
	}

	private static function render_results(array $state, $group, $code, $paged)
	{
		// A scan made by an older version may lack counts added since.
		$counts = array_merge(array_fill_keys(array_merge(array('auto', 'review', 'unresolved', 'ok'), array_keys(self::code_labels())), 0), (array) $state['counts']);
		printf(
			'<p>%s</p>',
			esc_html(sprintf(
				/* translators: 1: date and time, 2: posts and terms checked, 3: items listed */
				__('Last scan %1$s: %2$d posts and terms checked, %3$d need a look.', 'parich-long-slug'),
				wp_date(get_option('date_format') . ' ' . get_option('time_format'), (int) $state['finished']),
				(int) $state['objects'],
				(int) ($counts['auto'] + $counts['review'] + $counts['unresolved'])
			))
		);
		foreach ($state['warnings'] as $warning) {
			/* translators: %s: table name */
			printf('<div class="notice notice-warning inline"><p>%s</p></div>', esc_html(sprintf(__('The table %s does not have the columns this version knows; it was skipped.', 'parich-long-slug'), $warning['table'])));
		}

		$links = array('' => __('All', 'parich-long-slug'));
		foreach (self::group_labels() as $key => $label) {
			$links[$key] = $label;
		}
		echo '<ul class="subsubsub">';
		$last = array_key_last($links);
		foreach ($links as $key => $label) {
			$count = '' === $key ? $counts['auto'] + $counts['review'] + $counts['unresolved'] : $counts[$key];
			printf(
				'<li><a href="%s"%s>%s <span class="count">(%d)</span></a>%s</li>',
				esc_url(self::url(array_filter(array('group' => $key, 'code' => $code)))),
				$key === $group ? ' class="current" aria-current="page"' : '',
				esc_html($label),
				(int) $count,
				$key === $last ? '' : ' |'
			);
		}
		echo '</ul>';
		?>
		<form method="get" action="<?php echo esc_url(admin_url('options-general.php')); ?>" class="alignright">
			<input type="hidden" name="page" value="<?php echo esc_attr(Parich_Slug_Admin::PAGE); ?>" />
			<input type="hidden" name="tab" value="<?php echo esc_attr(self::TAB); ?>" />
			<input type="hidden" name="group" value="<?php echo esc_attr($group); ?>" />
			<label for="parich-slug-code" class="screen-reader-text"><?php esc_html_e('Kind of damage', 'parich-long-slug'); ?></label>
			<select name="code" id="parich-slug-code">
				<option value=""><?php esc_html_e('All kinds of damage', 'parich-long-slug'); ?></option>
				<?php foreach (self::code_labels() as $key => $label) : ?>
					<option value="<?php echo esc_attr($key); ?>" <?php selected($code, $key); ?>><?php echo esc_html($key . ' — ' . $label . ' (' . (int) $counts[$key] . ')'); ?></option>
				<?php endforeach; ?>
			</select>
			<?php submit_button(__('Filter', 'parich-long-slug'), 'secondary', '', false); ?>
		</form>
		<?php
		$results = Parich_Slug_Repair_Scanner::results((int) $state['id'], array('group' => $group, 'code' => $code, 'limit' => self::PER_PAGE, 'offset' => ($paged - 1) * self::PER_PAGE));
		$ready = Parich_Slug_Repair_Applier::check_ready();
		$running = Parich_Slug_Repair_Applier::state();
		?>
		<form id="parich-slug-apply-form">
			<p>
				<button type="button" class="button button-primary" id="parich-slug-apply-selected" <?php disabled(is_wp_error($ready)); ?>><?php esc_html_e('Repair the selected items', 'parich-long-slug'); ?></button>
				<button type="button" class="button" id="parich-slug-apply-auto" <?php disabled(is_wp_error($ready) || !$counts['auto']); ?>>
					<?php
					/* translators: %d: number of items marked Ready */
					echo esc_html(sprintf(_n('Repair the %d item marked "Ready"', 'Repair all %d items marked "Ready"', (int) $counts['auto'], 'parich-long-slug'), (int) $counts['auto']));
					?>
				</button>
				<span id="parich-slug-apply-status" role="status" aria-live="polite"></span>
			</p>
			<?php if (is_wp_error($ready)) : ?>
				<div class="notice notice-error inline"><p><?php echo esc_html($ready->get_error_message()); ?></p></div>
			<?php elseif ($running && empty($running['finished'])) : ?>
				<div class="notice notice-warning inline"><p><?php esc_html_e('A repair did not finish (the page was closed or the server stopped it). You can start it again after five minutes; items it already repaired are skipped, and it can be undone below.', 'parich-long-slug'); ?></p></div>
			<?php endif; ?>
			<?php self::render_table($results['items']); ?>
		</form>
		<?php
		$pages = (int) ceil($results['total'] / self::PER_PAGE);
		if ($pages > 1) {
			echo '<p class="tablenav-pages">' . wp_kses_post(paginate_links(array(
				'base' => esc_url_raw(add_query_arg('paged', '%#%', self::url(array_filter(array('group' => $group, 'code' => $code))))),
				'format' => '',
				'current' => $paged,
				'total' => $pages,
			))) . '</p>';
		}
		?>
		<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr(self::EXPORT_ACTION); ?>" />
			<?php wp_nonce_field(self::EXPORT_ACTION); ?>
			<p><?php submit_button(__('Download the results (CSV)', 'parich-long-slug'), 'secondary', 'submit', false); ?></p>
		</form>
		<?php
		self::render_unowned((int) $state['id']);
	}

	private static function render_table(array $items)
	{
		$groups = self::group_labels();
		$codes = self::code_labels();
		?>
		<table class="widefat striped parich-slug-results">
			<thead>
				<tr>
					<td class="check-column"><span class="screen-reader-text"><?php esc_html_e('Select', 'parich-long-slug'); ?></span></td>
					<th scope="col"><?php esc_html_e('Item', 'parich-long-slug'); ?></th>
					<th scope="col"><?php esc_html_e('Now', 'parich-long-slug'); ?></th>
					<th scope="col"><?php esc_html_e('Proposed', 'parich-long-slug'); ?></th>
					<th scope="col"><?php esc_html_e('Evidence', 'parich-long-slug'); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if (!$items) : ?>
					<tr><td colspan="5"><?php esc_html_e('Nothing in this list.', 'parich-long-slug'); ?></td></tr>
				<?php endif; ?>
				<?php
				foreach ($items as $item) :
					$object = $item['object'];
					$key = $object['type'] . '-' . $object['id'];
					$edit = 'post' === $object['type'] ? get_edit_post_link($object['id'], 'raw') : get_edit_term_link($object['id'], $object['subtype']);
					?>
					<tr>
						<th scope="row" class="check-column">
							<input type="checkbox" name="repair[]" id="parich-slug-pick-<?php echo esc_attr($key); ?>" value="<?php echo esc_attr($key); ?>" <?php checked('auto', $item['group']); ?> <?php disabled(null === $item['proposal']); ?> />
							<label for="parich-slug-pick-<?php echo esc_attr($key); ?>" class="screen-reader-text"><?php esc_html_e('Repair this item', 'parich-long-slug'); ?></label>
						</th>
						<td>
							<strong><?php echo $edit ? '<a href="' . esc_url($edit) . '">' . esc_html('' !== $object['title'] ? $object['title'] : __('(no title)', 'parich-long-slug')) . '</a>' : esc_html('' !== $object['title'] ? $object['title'] : __('(no title)', 'parich-long-slug')); ?></strong>
							<br /><span class="description"><?php echo esc_html($object['subtype'] . ' #' . $object['id'] . ('' !== $object['status'] ? ' · ' . $object['status'] : '')); ?></span>
							<br /><strong><?php echo esc_html($groups[$item['group']]); ?></strong>
							<?php foreach ($item['codes'] as $code) : ?>
								<span class="parich-slug-code" title="<?php echo esc_attr(isset($codes[$code]) ? $codes[$code] : $code); ?>"><?php echo esc_html($code); ?></span>
							<?php endforeach; ?>
							<?php if ($item['reasons']) : ?>
								<ul class="description">
									<?php foreach ($item['reasons'] as $reason) : ?>
										<li><?php echo esc_html(self::reason_label($reason)); ?></li>
									<?php endforeach; ?>
								</ul>
							<?php endif; ?>
							<?php if ((int) $item['row']->indirect > 0) : ?>
								<p class="description">
									<?php
									/* translators: %d: number of other links */
									echo esc_html(sprintf(_n('%d more link changes with it (children, posts in it).', '%d more links change with it (children, posts in it).', (int) $item['row']->indirect, 'parich-long-slug'), (int) $item['row']->indirect));
									?>
								</p>
							<?php endif; ?>
						</td>
						<td><code><?php echo esc_html('' === $object['slug'] ? __('(empty)', 'parich-long-slug') : self::readable($object['slug'])); ?></code><br /><span class="description"><?php echo esc_html(sprintf(/* translators: %d: bytes */ __('%d bytes', 'parich-long-slug'), strlen($object['slug']))); ?></span></td>
						<td>
							<?php if (null !== $item['proposal']) : ?>
								<code><?php echo esc_html(self::readable($item['proposal'])); ?></code><br /><span class="description"><?php echo esc_html(sprintf(/* translators: %d: bytes */ __('%d bytes', 'parich-long-slug'), strlen($item['proposal']))); ?></span>
							<?php endif; ?>
							<p>
								<label for="parich-slug-own-<?php echo esc_attr($key); ?>"><?php esc_html_e('Or type a slug:', 'parich-long-slug'); ?></label><br />
								<input type="text" class="regular-text" name="own[<?php echo esc_attr($key); ?>]" id="parich-slug-own-<?php echo esc_attr($key); ?>" autocomplete="off" />
							</p>
						</td>
						<td>
							<?php if (!$item['candidates']) : ?>
								<?php esc_html_e('No evidence of what the slug was.', 'parich-long-slug'); ?>
							<?php else : ?>
								<ul>
									<?php foreach (array_slice(Parich_Slug_Repair_Scorer::ranked($item), 0, 4) as $value) : ?>
										<?php
										$entries = array();
										foreach ($item['candidates'] as $candidate) {
											if ($candidate['value'] === $value) {
												$entries = $candidate['entries'];
											}
										}
										?>
										<li>
											<?php echo $value === $item['proposal'] ? '<strong>' : ''; ?>
											<code><?php echo esc_html(mb_strimwidth(self::readable($value), 0, 60, '…', 'UTF-8')); ?></code>
											<?php echo $value === $item['proposal'] ? '</strong>' : ''; ?>
											<br /><span class="description">
												<?php
												echo esc_html(implode(', ', array_unique(array_map(function ($entry) {
													return self::source_label($entry['source']) . (!empty($entry['stale']) ? ' ' . __('(out of date)', 'parich-long-slug') : '') . (!empty($entry['ambiguous']) ? ' ' . __('(matches other items too)', 'parich-long-slug') : '');
												}, $entries))));
												?>
											</span>
										</li>
									<?php endforeach; ?>
								</ul>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	private static function render_unowned($scan_id)
	{
		$urls = Parich_Slug_Repair_Scanner::unowned_urls($scan_id, null, 50);
		?>
		<h2><?php esc_html_e('Requested URLs no item accounts for', 'parich-long-slug'); ?></h2>
		<p><?php esc_html_e('Addresses from 404 logs and imported lists that match no post or term, most requested first. They may belong to items whose slug was rewritten from the title.', 'parich-long-slug'); ?></p>
		<?php if (!$urls) : ?>
			<p><?php esc_html_e('None.', 'parich-long-slug'); ?></p>
		<?php else : ?>
			<table class="widefat striped">
				<thead><tr><th scope="col"><?php esc_html_e('Address', 'parich-long-slug'); ?></th><th scope="col"><?php esc_html_e('Requests', 'parich-long-slug'); ?></th><th scope="col"><?php esc_html_e('From', 'parich-long-slug'); ?></th><th scope="col"><?php esc_html_e('Redirect to', 'parich-long-slug'); ?></th></tr></thead>
				<tbody>
					<?php foreach ($urls as $index => $url) : ?>
						<tr>
							<td><code><?php echo esc_html(self::readable($url['path'])); ?></code></td>
							<td><?php echo esc_html(number_format_i18n($url['hits'])); ?></td>
							<td><?php echo esc_html(implode(', ', $url['sources'])); ?></td>
							<td>
								<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
									<input type="hidden" name="action" value="<?php echo esc_attr(self::MAP_ACTION); ?>" />
									<input type="hidden" name="path" value="<?php echo esc_attr($url['path']); ?>" />
									<?php wp_nonce_field(self::MAP_ACTION); ?>
									<label for="parich-slug-map-<?php echo esc_attr($index); ?>" class="screen-reader-text"><?php esc_html_e('ID or link of the post or page', 'parich-long-slug'); ?></label>
									<input type="text" name="target" id="parich-slug-map-<?php echo esc_attr($index); ?>" placeholder="<?php esc_attr_e('Post ID or link', 'parich-long-slug'); ?>" required />
									<?php submit_button(__('Redirect', 'parich-long-slug'), 'small', 'submit', false); ?>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
		<?php
	}

	private static function render_imports()
	{
		$counts = Parich_Slug_Repair_Scanner::import_counts();
		?>
		<h2><?php esc_html_e('Add evidence', 'parich-long-slug'); ?></h2>
		<p>
			<?php
			printf(
				/* translators: 1: imported URLs, 2: imported CSV rows */
				esc_html__('Imported so far: %1$d URLs, %2$d CSV rows. They are used by every scan until removed.', 'parich-long-slug'),
				(int) $counts['urls'],
				(int) $counts['csv']
			);
			?>
		</p>
		<?php
		$forms = array(
			'urls' => array(__('URL list', 'parich-long-slug'), __('One address per line, for example exported from Search Console, server logs or the Wayback Machine. A CSV with the address first and a number of clicks second works too.', 'parich-long-slug')),
			'csv' => array(__('Slugs you know (CSV)', 'parich-long-slug'), __('Lines "type,id,slug", for example "post,123,ข่าว-ประจำวัน": the type is post or term, the slug readable, encoded or a whole address.', 'parich-long-slug')),
		);
		foreach ($forms as $kind => $text) :
			?>
			<form method="post" enctype="multipart/form-data" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr(self::IMPORT_ACTION); ?>" />
				<input type="hidden" name="kind" value="<?php echo esc_attr($kind); ?>" />
				<?php wp_nonce_field(self::IMPORT_ACTION); ?>
				<p>
					<label for="parich-slug-import-<?php echo esc_attr($kind); ?>"><strong><?php echo esc_html($text[0]); ?></strong></label><br />
					<span class="description"><?php echo esc_html($text[1]); ?></span><br />
					<input type="file" name="import_file" id="parich-slug-import-<?php echo esc_attr($kind); ?>" accept=".txt,.csv,text/plain,text/csv" required />
					<?php submit_button(__('Import', 'parich-long-slug'), 'secondary', 'submit', false); ?>
					<span class="description">
						<?php
						/* translators: 1: largest file size, 2: most lines */
						echo esc_html(sprintf(__('Up to %1$s and %2$s lines.', 'parich-long-slug'), size_format(Parich_Slug_Repair_Scanner::IMPORT_MAX_BYTES), number_format_i18n(Parich_Slug_Repair_Scanner::IMPORT_MAX_LINES)));
						?>
					</span>
				</p>
			</form>
		<?php endforeach; ?>
		<?php if ($counts['urls'] || $counts['csv']) : ?>
			<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr(self::CLEAR_ACTION); ?>" />
				<?php wp_nonce_field(self::CLEAR_ACTION); ?>
				<p><?php submit_button(__('Remove imported evidence', 'parich-long-slug'), 'delete', 'submit', false); ?></p>
			</form>
		<?php endif; ?>
		<?php
	}
}
