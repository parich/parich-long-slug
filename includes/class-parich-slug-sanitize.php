<?php
/**
 * Long slugs: sanitize_title() without core's 200-byte cut, Thai-aware truncation,
 * full-length "-2" suffixes, and Thai marks put back when the block editor's slug
 * field removed them.
 *
 * Core cuts every slug at 200 bytes once percent-encoded (about 22 Thai characters)
 * and cuts by code point, which can drop a tone mark or vowel sign, or leave a
 * leading vowel at the end. Everything else follows core exactly: titles that fit
 * in 200 bytes get the same slug as without this plugin.
 *
 * Active only while the slug columns are long enough (Parich_Slug_Schema::columns_ok()).
 *
 * @package Parich_Long_Slug
 */

if (!defined('ABSPATH')) {
	exit;
}

class Parich_Slug_Sanitize
{
	const MAX_OPTION = 'parich_slug_max_length';
	const DEFAULT_MAX = 1000;
	const MIN_MAX = 200;

	/** Longest slug written or looked up, in bytes once encoded; varchar(2000) leaves room. */
	const HARD_MAX = 1900;

	/** Thai vowel signs and tone marks written above or below a consonant (Mn). */
	const THAI_MARKS = '\x{0E31}\x{0E34}-\x{0E3A}\x{0E47}-\x{0E4E}';

	/** Thai vowels written before the consonant they follow in speech. */
	const THAI_LEADING_VOWELS = '\x{0E40}-\x{0E44}';

	/** Length used by sanitize_with_max() while it runs. */
	private static $max_override = null;

	public static function init()
	{
		// After Parich_Slug_Legacy::neutralize() (priority 10), which can put core's filter back.
		add_action('plugins_loaded', array(__CLASS__, 'install_filter'), 20);
		add_filter('wp_unique_post_slug', array(__CLASS__, 'unique_post_slug'), 10, 6);
		add_filter('wp_insert_post_data', array(__CLASS__, 'restore_thai_marks'), 10, 3);
	}

	/**
	 * Replaces core's sanitize_title filter with self::sanitize() once the columns are long enough.
	 */
	public static function install_filter()
	{
		if (!Parich_Slug_Schema::columns_ok()) {
			return;
		}
		remove_filter('sanitize_title', 'sanitize_title_with_dashes', 10);
		add_filter('sanitize_title', array(__CLASS__, 'sanitize'), 10, 3);
	}

	public static function is_active()
	{
		return false !== has_filter('sanitize_title', array(__CLASS__, 'sanitize'));
	}

	/**
	 * Longest new slug in bytes once encoded: the parich_slug_max_length option
	 * (default 1000) through the parich_slug_max_length filter, kept within 200–1900.
	 */
	public static function max_length()
	{
		if (null !== self::$max_override) {
			return self::$max_override;
		}
		$value = get_option(self::MAX_OPTION, self::DEFAULT_MAX);
		$value = is_numeric($value) ? (int) $value : self::DEFAULT_MAX;
		/**
		 * Filters the longest slug for new posts and terms, in bytes once percent-encoded
		 * (a Thai character takes 9). Kept within 200–1900.
		 *
		 * @param int $value Length from the parich_slug_max_length option.
		 */
		$value = (int) apply_filters('parich_slug_max_length', $value);
		return max(self::MIN_MAX, min(self::HARD_MAX, $value));
	}

	/**
	 * The slug a title gets when saved with a given longest length (for the settings
	 * preview), without touching the option or any hook.
	 *
	 * @param string $title Title, already passed through remove_accents() like sanitize_title() does.
	 * @param int    $max   Length in bytes; kept within 200–1900.
	 * @return string
	 */
	public static function sanitize_with_max($title, $max)
	{
		self::$max_override = max(self::MIN_MAX, min(self::HARD_MAX, (int) $max));
		try {
			return self::sanitize($title, $title, 'save');
		} finally {
			self::$max_override = null;
		}
	}

	/**
	 * sanitize_title_with_dashes() of WordPress 7.1.2 with one change: the encoding
	 * step cuts at max_length() (HARD_MAX for lookups) on a grapheme boundary instead
	 * of at 200 bytes on a code point.
	 *
	 * @param string $title     The title to be sanitized.
	 * @param string $raw_title Not used.
	 * @param string $context   'save', 'query' or 'display'.
	 * @return string
	 */
	public static function sanitize($title, $raw_title = '', $context = 'display')
	{
		$title = strip_tags($title); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- as in core's sanitize_title_with_dashes(): the slugs must be the same.
		// Preserve escaped octets.
		$title = preg_replace('|%([a-fA-F0-9][a-fA-F0-9])|', '---$1---', $title);
		// Remove percent signs that are not part of an octet.
		$title = str_replace('%', '', $title);
		// Restore octets.
		$title = preg_replace('|---([a-fA-F0-9][a-fA-F0-9])---|', '%$1', $title);

		if (self::is_valid_utf8($title)) {
			if (function_exists('mb_strtolower')) {
				$title = mb_strtolower($title, 'UTF-8');
			}
			// Lookups use the longest length, so slugs saved before a lower setting are still found.
			$title = self::encode_thai_safe($title, 'query' === $context ? self::HARD_MAX : self::max_length());
		}

		$title = strtolower($title);

		if ('save' === $context) {
			// Convert &nbsp, non-breaking hyphen, &ndash, and &mdash to hyphens.
			$title = str_replace(array('%c2%a0', '%e2%80%91', '%e2%80%93', '%e2%80%94'), '-', $title);
			// Convert &nbsp, non-breaking hyphen, &ndash, and &mdash HTML entities to hyphens.
			$title = str_replace(array('&nbsp;', '&#8209;', '&#160;', '&ndash;', '&#8211;', '&mdash;', '&#8212;'), '-', $title);
			// Convert forward slash to hyphen.
			$title = str_replace('/', '-', $title);

			// Strip these characters entirely.
			$title = str_replace(
				array(
					// Soft hyphens.
					'%c2%ad',
					// &iexcl and &iquest.
					'%c2%a1',
					'%c2%bf',
					// Angle quotes.
					'%c2%ab',
					'%c2%bb',
					'%e2%80%b9',
					'%e2%80%ba',
					// Curly quotes.
					'%e2%80%98',
					'%e2%80%99',
					'%e2%80%9c',
					'%e2%80%9d',
					'%e2%80%9a',
					'%e2%80%9b',
					'%e2%80%9e',
					'%e2%80%9f',
					// Bullet.
					'%e2%80%a2',
					// &copy, &reg, &deg, &hellip, and &trade.
					'%c2%a9',
					'%c2%ae',
					'%c2%b0',
					'%e2%80%a6',
					'%e2%84%a2',
					// Acute accents.
					'%c2%b4',
					'%cb%8a',
					'%cc%81',
					'%cd%81',
					// Grave accent, macron, caron.
					'%cc%80',
					'%cc%84',
					'%cc%8c',
					// Non-visible characters that display without a width.
					'%e2%80%8b', // Zero width space.
					'%e2%80%8c', // Zero width non-joiner.
					'%e2%80%8d', // Zero width joiner.
					'%e2%80%8e', // Left-to-right mark.
					'%e2%80%8f', // Right-to-left mark.
					'%e2%80%aa', // Left-to-right embedding.
					'%e2%80%ab', // Right-to-left embedding.
					'%e2%80%ac', // Pop directional formatting.
					'%e2%80%ad', // Left-to-right override.
					'%e2%80%ae', // Right-to-left override.
					'%ef%bb%bf', // Byte order mark.
					'%ef%bf%bc', // Object replacement character.
				),
				'',
				$title
			);

			// Convert non-visible characters that display with a width to hyphen.
			$title = str_replace(
				array(
					'%e2%80%80', // En quad.
					'%e2%80%81', // Em quad.
					'%e2%80%82', // En space.
					'%e2%80%83', // Em space.
					'%e2%80%84', // Three-per-em space.
					'%e2%80%85', // Four-per-em space.
					'%e2%80%86', // Six-per-em space.
					'%e2%80%87', // Figure space.
					'%e2%80%88', // Punctuation space.
					'%e2%80%89', // Thin space.
					'%e2%80%8a', // Hair space.
					'%e2%80%a8', // Line separator.
					'%e2%80%a9', // Paragraph separator.
					'%e2%80%af', // Narrow no-break space.
				),
				'-',
				$title
			);

			// Convert &times to 'x'.
			$title = str_replace('%c3%97', 'x', $title);
		}

		// Remove HTML entities.
		$title = preg_replace('/&.+?;/', '', $title);
		$title = str_replace('.', '-', $title);

		$title = preg_replace('/[^%a-z0-9 _-]/', '', $title);
		$title = preg_replace('/\s+/', '-', $title);
		$title = preg_replace('|-+|', '-', $title);
		$title = trim($title, '-');

		return $title;
	}

	/**
	 * utf8_uri_encode() that stops at $max bytes between graphemes, so a Thai vowel
	 * sign or tone mark is never separated from its consonant, and that never ends on
	 * a Thai leading vowel. Strings that fit are encoded exactly like core.
	 *
	 * @param string $string Valid UTF-8.
	 * @param int    $max    Longest result in bytes.
	 * @return string
	 */
	public static function encode_thai_safe($string, $max)
	{
		$full = utf8_uri_encode($string, 0);
		if (strlen($full) <= $max) {
			return $full;
		}

		$kept = array();
		$length = 0;
		foreach (self::graphemes($string) as $grapheme) {
			$encoded = utf8_uri_encode($grapheme, 0);
			if ($length + strlen($encoded) > $max) {
				break;
			}
			$kept[] = array($grapheme, $encoded);
			$length += strlen($encoded);
		}
		while ($kept && preg_match('/^[' . self::THAI_LEADING_VOWELS . ']$/u', $kept[count($kept) - 1][0])) {
			array_pop($kept);
		}
		return implode('', array_column($kept, 1));
	}

	/**
	 * Grapheme clusters of a UTF-8 string. Without PCRE's \X, combining marks are
	 * attached to the preceding code point.
	 *
	 * @return string[]
	 */
	public static function graphemes($string)
	{
		if (preg_match_all('/\X/u', $string, $matches) && implode('', $matches[0]) === $string) {
			return $matches[0];
		}
		$clusters = array();
		foreach (preg_split('//u', $string, -1, PREG_SPLIT_NO_EMPTY) as $char) {
			if ($clusters && preg_match('/^\p{M}$/u', $char)) {
				$clusters[count($clusters) - 1] .= $char;
			} else {
				$clusters[] = $char;
			}
		}
		return $clusters;
	}

	/**
	 * Shortens an encoded slug to $length bytes like _truncate_post_slug(), but on
	 * a grapheme boundary.
	 */
	public static function truncate_encoded($slug, $length)
	{
		if (strlen($slug) > $length) {
			$decoded = urldecode($slug);
			if ($decoded === $slug) {
				$slug = substr($slug, 0, $length);
			} else {
				$slug = self::encode_thai_safe($decoded, $length);
			}
		}
		return rtrim($slug, '-');
	}

	/**
	 * When a slug was taken, core adds "-2", "-3"… after cutting the slug to fit
	 * 200 bytes. This redoes that step with the long limit, using the same
	 * uniqueness queries as wp_unique_post_slug().
	 *
	 * @return string
	 */
	public static function unique_post_slug($slug, $post_id, $post_status, $post_type, $post_parent, $original_slug = '')
	{
		global $wpdb;
		if (!self::is_active() || $slug === $original_slug || !preg_match('/-(\d+)$/', $slug, $suffix_match)) {
			return $slug;
		}
		// Core only cut the slug when it plus the suffix did not fit in 200 bytes.
		if (strlen($original_slug) + strlen($suffix_match[0]) <= 200) {
			return $slug;
		}

		$limit = max(self::max_length(), min(self::HARD_MAX, strlen($original_slug)));
		if ('attachment' === $post_type) {
			$sql = "SELECT post_name FROM $wpdb->posts WHERE post_name = %s AND ID != %d LIMIT 1";
			$args = array((int) $post_id);
		} elseif (is_post_type_hierarchical($post_type)) {
			$sql = "SELECT post_name FROM $wpdb->posts WHERE post_name = %s AND post_type IN ( %s, 'attachment' ) AND ID != %d AND post_parent = %d LIMIT 1";
			$args = array($post_type, (int) $post_id, (int) $post_parent);
		} else {
			$sql = "SELECT post_name FROM $wpdb->posts WHERE post_name = %s AND post_type = %s AND ID != %d LIMIT 1";
			$args = array($post_type, (int) $post_id);
		}

		$suffix = 2;
		do {
			$alt = self::truncate_encoded($original_slug, $limit - (strlen((string) $suffix) + 1)) . "-$suffix";
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- same queries as wp_unique_post_slug(); $sql holds placeholders only.
			$taken = $wpdb->get_var($wpdb->prepare($sql, array_merge(array($alt), $args)));
			++$suffix;
		} while ($taken);

		return $alt;
	}

	/**
	 * The block editor's slug field runs cleanForSlug() when it loses focus, which
	 * removes Thai vowel signs and tone marks (they are not letters to its regex).
	 * When the slug sent differs from the post's current slug, or from the slug of
	 * its title, only by those marks, the version with marks is kept.
	 *
	 * @param array $data                Slashed, sanitized post data.
	 * @param array $postarr             Sanitized post data.
	 * @param array $unsanitized_postarr Slashed post data as passed to wp_insert_post().
	 * @return array
	 */
	public static function restore_thai_marks($data, $postarr, $unsanitized_postarr)
	{
		if (empty($unsanitized_postarr['post_name']) || empty($data['post_name'])) {
			return $data;
		}
		$sent = urldecode(sanitize_title(wp_unslash($unsanitized_postarr['post_name'])));
		if ($sent !== self::strip_thai_marks($sent)) {
			return $data; // Marks were typed, so nothing was removed.
		}

		$candidates = array();
		if (!empty($postarr['ID'])) {
			$candidates[] = (string) get_post_field('post_name', (int) $postarr['ID'], 'raw');
		}
		$candidates[] = sanitize_title(wp_unslash($data['post_title']));

		foreach ($candidates as $candidate) {
			$plain = urldecode($candidate);
			if ('' !== $plain && $plain !== $sent && self::strip_thai_marks($plain) === $sent) {
				$data['post_name'] = wp_unique_post_slug($candidate, isset($postarr['ID']) ? (int) $postarr['ID'] : 0, $data['post_status'], $data['post_type'], (int) $data['post_parent']);
				break;
			}
		}
		return $data;
	}

	public static function strip_thai_marks($text)
	{
		return preg_replace('/[' . self::THAI_MARKS . ']/u', '', $text);
	}

	private static function is_valid_utf8($text)
	{
		// wp_is_valid_utf8() exists since WordPress 6.9; seems_utf8() is deprecated there.
		// phpcs:ignore WordPress.WP.DeprecatedFunctions.seems_utf8Found -- fallback for WordPress before 6.9, which has no wp_is_valid_utf8().
		return function_exists('wp_is_valid_utf8') ? wp_is_valid_utf8($text) : seems_utf8($text);
	}
}
