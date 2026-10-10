<?php
/**
 * The slugs Long URL Maker 2.0.1 made, reproduced exactly, so the repair tool can
 * tell which slugs it wrote and what the original long slug of a title was.
 *
 * Ported from kpcode_set_url_format() and kpcode_set_url_format_old() of
 * Long URL Maker 2.0.1 by kpcode (https://wordpress.org/plugins/long-url-maker/),
 * itself based on PP-auto-thai-url by Preukson; both GPLv2 or later. The results
 * are checked byte for byte against the original functions (tests/fixtures/legacy-slugs.json).
 *
 * Two quirks of the original are kept on purpose:
 * - its "save" branch (curly quotes, dashes, no-break spaces…) never ran, because
 *   $context was never defined there, so those characters stay percent-encoded;
 * - slugs made while it was active went through sanitize_title() first, which calls
 *   remove_accents(); its deactivation routine called the "old" function directly.
 *
 * @package Parich_Long_Slug
 */

if (!defined('ABSPATH')) {
	exit;
}

class Parich_Slug_Legacy_Sanitize
{
	/**
	 * kpcode_set_url_format(): cut at 1900 bytes.
	 */
	public static function legacy_new($title)
	{
		return self::port($title, 1900);
	}

	/**
	 * Slug Long URL Maker gave a title while it was active (sanitize_title() → remove_accents()).
	 */
	public static function legacy_new_save($title)
	{
		return self::legacy_new(remove_accents($title));
	}

	/**
	 * kpcode_set_url_format_old(): cut at 200 bytes; what its deactivation routine
	 * wrote over every post_name and term slug.
	 */
	public static function legacy_old($title)
	{
		return self::port($title, 200);
	}

	private static function port($title, $length)
	{
		$title = strip_tags((string) $title); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- as in Long URL Maker: its slugs must be reproduced exactly.
		$title = preg_replace('|%([a-fA-F0-9][a-fA-F0-9])|', '---$1---', $title);
		$title = str_replace('%', '', $title);
		$title = preg_replace('|---([a-fA-F0-9][a-fA-F0-9])---|', '%$1', $title);

		// phpcs:ignore WordPress.WP.DeprecatedFunctions.seems_utf8Found -- fallback for WordPress before 6.9, which has no wp_is_valid_utf8().
		$valid = function_exists('wp_is_valid_utf8') ? wp_is_valid_utf8($title) : seems_utf8($title);
		if ($valid) {
			if (function_exists('mb_strtolower')) {
				$title = mb_strtolower($title, 'UTF-8');
			}
			$title = utf8_uri_encode($title, $length);
		}

		$title = strtolower($title);
		$title = preg_replace('/&.+?;/', '', $title);
		$title = str_replace('.', '-', $title);
		// The original's "save" block is skipped here, as it was there.
		$title = preg_replace('/[^%a-z0-9 _-]/', '', $title);
		$title = preg_replace('/\s+/', '-', $title);
		$title = preg_replace('|-+|', '-', $title);
		return trim($title, '-');
	}
}
