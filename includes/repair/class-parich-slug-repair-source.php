<?php
/**
 * Where the repair tool reads from: this site's tables, or the same tables under
 * another prefix (the damage lab loads its snapshots as plslab_*_). Results always
 * go to this plugin's own tables.
 *
 * @package Parich_Long_Slug
 */

if (!defined('ABSPATH')) {
	exit;
}

class Parich_Slug_Repair_Source
{
	public $prefix;

	private $tables = array();
	private $columns = array();

	public function __construct($prefix = null)
	{
		global $wpdb;
		if (null !== $prefix && !preg_match('/^[A-Za-z0-9_]+$/', $prefix)) {
			throw new InvalidArgumentException('Invalid table prefix.');
		}
		$this->prefix = null === $prefix ? $wpdb->prefix : $prefix;
	}

	public function table($name)
	{
		return $this->prefix . $name;
	}

	public function has_table($name)
	{
		global $wpdb;
		$table = $this->table($name);
		if (!isset($this->tables[$table])) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- once per scan step.
			$this->tables[$table] = $table === $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table)));
		}
		return $this->tables[$table];
	}

	/**
	 * Whether the table exists with all these columns (other plugins change their schema).
	 */
	public function has_columns($name, array $columns)
	{
		global $wpdb;
		if (!$this->has_table($name)) {
			return false;
		}
		$table = $this->table($name);
		if (!isset($this->columns[$table])) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- once per scan step.
			$this->columns[$table] = $wpdb->get_col($wpdb->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $table));
		}
		return !array_diff($columns, $this->columns[$table]);
	}

	/**
	 * Longest value a text column holds, in characters; null for the *text types.
	 */
	public function column_length($name, $column)
	{
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- once per source per scan step.
		$length = $wpdb->get_var($wpdb->prepare('SELECT CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s', $this->table($name), $column));
		return null === $length || (int) $length >= 65535 ? null : (int) $length;
	}

	/**
	 * Home URL of the site the tables belong to.
	 */
	public function home()
	{
		global $wpdb;
		return $this->prefix === $wpdb->prefix ? home_url('/') : (string) $this->option('home');
	}

	/**
	 * An option read from this source's options table (not the site's cache).
	 */
	public function option($name)
	{
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- options of the scanned site, which may use another table prefix.
		$value = $wpdb->get_var($wpdb->prepare('SELECT option_value FROM %i WHERE option_name = %s', $this->table('options'), $name));
		return null === $value ? null : maybe_unserialize($value);
	}
}
