<?php
/**
 * WP-CLI: wp parich-slug scan | import-urls | import-csv | clear-imports
 *
 * @package Parich_Long_Slug
 */

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Finds slugs damaged by Long URL Maker (dry run: nothing is changed).
 */
class Parich_Slug_CLI
{
	/**
	 * Run as a user (--user) who may not use the repair tool: refuse. Without --user,
	 * whoever runs WP-CLI already has the site's files and database.
	 */
	private static function check_user()
	{
		if (get_current_user_id() && !current_user_can(Parich_Slug_Admin::capability())) {
			WP_CLI::error('This user may not use the repair tool.');
		}
	}

	/**
	 * Scans posts and terms for slugs damaged by Long URL Maker and lists what would be proposed.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : table (readable slugs, shortened) or csv (every column, full values).
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 * ---
	 *
	 * [--group=<group>]
	 * : Only items of one group.
	 * ---
	 * options:
	 *   - auto
	 *   - review
	 *   - unresolved
	 * ---
	 *
	 * [--source-prefix=<prefix>]
	 * : Read tables with another prefix (a copy of the site, the damage lab).
	 *
	 * ## EXAMPLES
	 *
	 *     wp parich-slug scan
	 *     wp parich-slug scan --format=csv > scan.csv
	 *
	 * @when after_wp_load
	 */
	public function scan($args, $assoc_args)
	{
		self::check_user();
		$format = isset($assoc_args['format']) ? $assoc_args['format'] : 'table';
		$prefix = isset($assoc_args['source-prefix']) ? $assoc_args['source-prefix'] : null;
		$state = Parich_Slug_Repair_Scanner::run($prefix);
		if (is_wp_error($state)) {
			WP_CLI::error($state->get_error_message());
		}
		$rows = Parich_Slug_Repair_Scanner::export_rows((int) $state['id']);
		$header = array_shift($rows);
		if (!empty($assoc_args['group'])) {
			$column = array_search('group', $header, true);
			$rows = array_values(array_filter($rows, function ($row) use ($column, $assoc_args) {
				return $row[$column] === $assoc_args['group'];
			}));
		}
		if ('csv' === $format) {
			WP_CLI::line(Parich_Slug_Repair_Admin::csv(array_merge(array($header), $rows)));
			return;
		}
		foreach ($state['warnings'] as $warning) {
			WP_CLI::warning("Skipped {$warning['table']}: it does not have the expected columns.");
		}
		$items = array();
		foreach ($rows as $row) {
			$row = array_combine($header, $row);
			$items[] = array(
				'item' => $row['object_type'] . ' ' . $row['object_id'],
				'type' => $row['subtype'],
				'damage' => $row['damage'],
				'group' => $row['group'],
				'now' => mb_strimwidth($row['current'], 0, 40, '…', 'UTF-8'),
				'proposed' => mb_strimwidth($row['proposal'], 0, 40, '…', 'UTF-8'),
				'reasons' => $row['reasons'],
			);
		}
		if ($items) {
			WP_CLI\Utils\format_items('table', $items, array('item', 'type', 'damage', 'group', 'now', 'proposed', 'reasons'));
		}
		$counts = $state['counts'];
		WP_CLI::success(sprintf('%d posts and terms checked: %d ready (auto), %d to check (review), %d unknown (unresolved), %d fine.', $state['objects'], $counts['auto'], $counts['review'], $counts['unresolved'], $counts['ok']));
	}

	/**
	 * Imports a list of URLs the site used to have (one per line, or CSV with the URL first).
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : Plain UTF-8 text, up to 2 MB and 20,000 lines.
	 *
	 * @subcommand import-urls
	 * @when after_wp_load
	 */
	public function import_urls($args)
	{
		self::import($args[0], 'urls');
	}

	/**
	 * Imports slugs you know, as lines "type,id,slug" (type: post or term).
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : Plain UTF-8 text, up to 2 MB and 20,000 lines.
	 *
	 * @subcommand import-csv
	 * @when after_wp_load
	 */
	public function import_csv($args)
	{
		self::import($args[0], 'csv');
	}

	/**
	 * Repairs slugs from the last scan of this site and redirects their old addresses.
	 *
	 * ## OPTIONS
	 *
	 * [--items=<items>]
	 * : Comma-separated items such as post-12,term-3 (with their proposed slug). Default:
	 * every item marked ready (group auto).
	 *
	 * [--yes]
	 * : Do not ask for confirmation.
	 *
	 * ## EXAMPLES
	 *
	 *     wp parich-slug scan && wp parich-slug repair
	 *     wp parich-slug repair --items=post-12,post-15 --yes
	 *
	 * @when after_wp_load
	 */
	public function repair($args, $assoc_args)
	{
		self::check_user();
		$choices = 'auto';
		if (!empty($assoc_args['items'])) {
			$choices = array_fill_keys(array_map('trim', explode(',', $assoc_args['items'])), '');
		}
		$ready = Parich_Slug_Repair_Applier::check_ready();
		if (is_wp_error($ready)) {
			WP_CLI::error($ready->get_error_message());
		}
		WP_CLI::confirm('auto' === $choices ? 'Repair every item marked ready in the last scan?' : 'Repair ' . count($choices) . ' items?', $assoc_args);
		$state = Parich_Slug_Repair_Applier::run($choices);
		if (is_wp_error($state)) {
			WP_CLI::error($state->get_error_message());
		}
		foreach ($state['skipped'] as $skip) {
			WP_CLI::warning("{$skip['key']}: {$skip['reason']}");
		}
		WP_CLI::success(sprintf('Repair %d: %d slugs repaired, %d skipped, %d redirects made. Undo with: wp parich-slug undo %d', $state['batch'], $state['applied'], count($state['skipped']), $state['redirects'], $state['batch']));
	}

	/**
	 * Puts back the slugs of a repair and turns its redirects off.
	 *
	 * ## OPTIONS
	 *
	 * <batch>
	 * : Repair number (shown after repairing and on the Repair slugs tab).
	 *
	 * [--yes]
	 * : Do not ask for confirmation.
	 *
	 * @when after_wp_load
	 */
	public function undo($args, $assoc_args)
	{
		self::check_user();
		$batch = (int) $args[0];
		if (!Parich_Slug_Repair_Applier::batch_exists($batch)) {
			WP_CLI::error("There is no repair {$batch}.");
		}
		WP_CLI::confirm("Undo repair {$batch}?", $assoc_args);
		$restored = 0;
		do {
			$result = Parich_Slug_Repair_Applier::undo($batch);
			$restored += $result['restored'];
			foreach ($result['kept'] as $kept) {
				WP_CLI::warning("{$kept['key']}: {$kept['reason']}");
			}
		} while ($result['remaining'] > 0);
		WP_CLI::success("Repair {$batch}: {$restored} slugs put back.");
	}

	/**
	 * Lists the redirects made by repairs and by matching 404s.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 * ---
	 *
	 * @when after_wp_load
	 */
	public function redirects($args, $assoc_args)
	{
		self::check_user();
		$rows = Parich_Slug_Redirects::export_rows();
		$header = array_shift($rows);
		if ('csv' === ($assoc_args['format'] ?? 'table')) {
			WP_CLI::line(Parich_Slug_Repair_Admin::csv(array_merge(array($header), $rows)));
			return;
		}
		$items = array_map(function ($row) use ($header) {
			return array_combine($header, $row);
		}, $rows);
		if ($items) {
			WP_CLI\Utils\format_items('table', $items, array('source_url', 'target_url', 'status', 'origin', 'hits'));
		}
		WP_CLI::log(count($items) . ' redirects.');
	}

	/**
	 * Removes imported URLs and CSV rows.
	 *
	 * @subcommand clear-imports
	 * @when after_wp_load
	 */
	public function clear_imports()
	{
		self::check_user();
		Parich_Slug_Repair_Scanner::clear_imports();
		WP_CLI::success('Imported evidence removed.');
	}

	private static function import($file, $kind)
	{
		self::check_user();
		$lines = Parich_Slug_Repair_Scanner::read_import_file($file);
		if (is_wp_error($lines)) {
			WP_CLI::error($lines->get_error_message());
		}
		$result = 'csv' === $kind ? Parich_Slug_Repair_Scanner::import_csv($lines) : Parich_Slug_Repair_Scanner::import_urls($lines);
		if (!empty($result['error'])) {
			WP_CLI::error($result['error']);
		}
		WP_CLI::success(sprintf('Imported %d lines, skipped %d. Run "wp parich-slug scan" to use them.', $result['added'], $result['skipped']));
	}
}
