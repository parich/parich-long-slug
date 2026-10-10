<?php
/**
 * Small helpers for the repair tool: encoded slugs and URL paths.
 *
 * @package Parich_Long_Slug
 */

if (!defined('ABSPATH')) {
	exit;
}

class Parich_Slug_Repair_Util
{
	/**
	 * Removes what a cut in the middle of a character left at the end of an encoded
	 * slug: a lone "%", "%e", or the first octets of a multi-byte character.
	 */
	public static function trim_partial($slug)
	{
		$slug = preg_replace('/%[0-9a-fA-F]?$/', '', (string) $slug);
		while ('' !== $slug && !self::decodes_cleanly($slug)) {
			$shorter = preg_replace('/%[0-9a-fA-F]{2}$/', '', $slug);
			if ($shorter === $slug) {
				break;
			}
			$slug = $shorter;
		}
		return $slug;
	}

	public static function decodes_cleanly($slug)
	{
		$decoded = rawurldecode((string) $slug);
		// phpcs:ignore WordPress.WP.DeprecatedFunctions.seems_utf8Found -- fallback for WordPress before 6.9, which has no wp_is_valid_utf8().
		return function_exists('wp_is_valid_utf8') ? wp_is_valid_utf8($decoded) : (bool) seems_utf8($decoded);
	}

	/**
	 * A slug the repair tool may write: lower-case letters, digits, "-", "_" and
	 * complete "%xx" octets of multi-byte characters (core encodes nothing below
	 * %80) that decode to valid UTF-8, at most $max bytes.
	 */
	public static function is_valid($slug, $max = Parich_Slug_Sanitize::HARD_MAX)
	{
		$slug = (string) $slug;
		return '' !== $slug
			&& strlen($slug) <= $max
			&& 1 === preg_match('/^(?:[a-z0-9_-]|%[89a-f][0-9a-f])+$/', $slug)
			&& self::decodes_cleanly($slug);
	}

	/**
	 * Path of a URL relative to the site's home, comparable with slugs: no query,
	 * fragment or trailing slash, every segment percent-encoded with lower-case hex
	 * (logs store URLs encoded, decoded, or with upper-case hex).
	 *
	 * @return string|null Path such as "/news/some-slug", or null for another host.
	 */
	public static function path_of($url, $home = null)
	{
		$home = wp_parse_url(null === $home ? home_url('/') : $home);
		$parts = wp_parse_url(trim((string) $url));
		if (false === $parts) {
			return null;
		}
		if (!empty($parts['host']) && !empty($home['host']) && strtolower($parts['host']) !== strtolower($home['host'])) {
			return null;
		}
		$path = isset($parts['path']) ? $parts['path'] : '';
		$base = rtrim(isset($home['path']) ? $home['path'] : '', '/');
		if ('' !== $base && 0 === strpos($path, $base . '/')) {
			$path = substr($path, strlen($base));
		}
		$segments = array();
		foreach (explode('/', $path) as $segment) {
			if ('' !== $segment) {
				$segments[] = strtolower(rawurlencode(rawurldecode($segment)));
			}
		}
		return '/' . implode('/', $segments);
	}

	public static function last_segment($path)
	{
		$path = rtrim((string) $path, '/');
		$at = strrpos($path, '/');
		return false === $at ? $path : substr($path, $at + 1);
	}
}
