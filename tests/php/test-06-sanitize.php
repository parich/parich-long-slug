<?php
/**
 * Phase 4 (L6): the long sanitize_title filter — identical to core for titles that
 * fit in 200 bytes, Thai-aware cuts at 200 / 1000 / 1900 bytes, max_length().
 *
 * @package Parich_Long_Slug
 */

require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/../fixtures/helpers.php';

$titles = json_decode((string) file_get_contents(__DIR__ . '/../fixtures/thai-titles.json'), true);

if (!Parich_Slug_Sanitize::is_active()) {
	pls_test_assert('precondition: the slug columns are widened, so the long filter is installed', false);
	pls_test_done();
}

const PLS_MARK = '/^[\x{0E31}\x{0E34}-\x{0E3A}\x{0E47}-\x{0E4E}]$/u';
const PLS_LEADING = '/^[\x{0E40}-\x{0E44}]$/u';

/**
 * Titles whose slug fits in 200 bytes, so core does not cut them.
 */
function pls_fits_in_200($title)
{
	return strlen(utf8_uri_encode(mb_strtolower(strip_tags($title), 'UTF-8'), 0)) <= 200;
}

/**
 * Differences between Parich_Slug_Sanitize::sanitize() and $expected($title, $context).
 *
 * @return string[]
 */
function pls_parity_mismatches(array $titles, callable $expected)
{
	$mismatches = array();
	foreach ($titles as $title) {
		foreach (array('save', 'query', 'display') as $context) {
			$ours = Parich_Slug_Sanitize::sanitize($title, $title, $context);
			$want = $expected($title, $context);
			if ($ours !== $want) {
				$mismatches[] = "[{$context}] " . mb_substr($title, 0, 30) . " → {$ours} ≠ {$want}";
			}
		}
	}
	return $mismatches;
}

/**
 * Problems with a slug cut to $max bytes: length, broken escapes, invalid UTF-8,
 * a trailing leading vowel, or (given the uncut slug) a mark left behind.
 *
 * @return string[]
 */
function pls_cut_problems($slug, $max, $uncut = null)
{
	$problems = array();
	if (strlen($slug) > $max) {
		$problems[] = 'longer than ' . $max;
	}
	if (!preg_match('/^(?:[a-z0-9_-]|%[0-9a-f]{2})*$/', $slug)) {
		$problems[] = 'broken percent-encoding';
	}
	$decoded = urldecode($slug);
	if (!mb_check_encoding($decoded, 'UTF-8')) {
		$problems[] = 'invalid UTF-8';
	}
	$chars = preg_split('//u', $decoded, -1, PREG_SPLIT_NO_EMPTY);
	if ($chars && preg_match(PLS_LEADING, end($chars))) {
		$problems[] = 'ends with a leading vowel';
	}
	if (null !== $uncut) {
		if (0 !== strpos($uncut, $slug)) {
			$problems[] = 'not a prefix of the uncut slug';
		} else {
			$rest = preg_split('//u', urldecode(substr($uncut, strlen($slug))), -1, PREG_SPLIT_NO_EMPTY);
			if ($rest && preg_match(PLS_MARK, $rest[0])) {
				$problems[] = 'a vowel sign or tone mark was cut off';
			}
		}
	}
	return $problems;
}

pls_test_section('Filter');
pls_test_assert('Parich Long Slug\'s filter replaces core\'s at priority 10', 10 === has_filter('sanitize_title', array('Parich_Slug_Sanitize', 'sanitize')) && false === has_filter('sanitize_title', 'sanitize_title_with_dashes'));
pls_test_assert('max_length() is 1000 without the option', 1000 === Parich_Slug_Sanitize::max_length());

pls_test_section('Same as core for titles that fit in 200 bytes (all three contexts)');
$fitting = array_values(array_filter($titles, 'pls_fits_in_200'));
pls_test_assert('the corpus has at least 20 such titles (' . count($fitting) . ')', count($fitting) >= 20);
$core = function ($title, $context) {
	return sanitize_title_with_dashes($title, $title, $context);
};
$mismatches = pls_parity_mismatches($fitting, $core);
pls_test_assert(count($fitting) . ' titles × 3 contexts identical to sanitize_title_with_dashes()', array() === $mismatches, implode(' | ', array_slice($mismatches, 0, 3)));
$full_path = 0;
foreach ($fitting as $title) {
	$full_path += sanitize_title($title) === sanitize_title_with_dashes(remove_accents($title), $title, 'save') ? 0 : 1;
}
pls_test_assert('sanitize_title() (with remove_accents) gives core\'s result for each of them', 0 === $full_path, "{$full_path} differ");
$wrong = function ($title, $context) use ($core, $fitting) {
	return $title === $fitting[0] && 'save' === $context ? $core($title, $context) . 'x' : $core($title, $context);
};
pls_test_assert('negative control — a single wrong expectation is reported', 1 === count(pls_parity_mismatches($fitting, $wrong)));

pls_test_section('Characters core changes only when saving (L6)');
// Short titles, so core does not cut them either.
$cases = array(
	'ประกาศ “ทุนการศึกษา” 2569' => 'curly quotes',
	'สัมมนา — AI' => 'em dash',
	"ข่าว ด่วน" => 'no-break space',
	"ภาษาไทย​ศูนย์" => 'zero width space',
	'เวอร์ชัน 1.2.3' => 'dots (core turns them into hyphens)',
);
foreach ($cases as $title => $what) {
	pls_test_assert("{$what}: same as core" . (pls_fits_in_200($title) ? '' : ' (TITLE TOO LONG)'), Parich_Slug_Sanitize::sanitize($title, $title, 'save') === sanitize_title_with_dashes($title, $title, 'save'));
}
pls_test_assert('dots become hyphens, as in core', false !== strpos(Parich_Slug_Sanitize::sanitize('เวอร์ชัน 1.2.3', '', 'save'), '1-2-3'));

pls_test_section('Long Thai titles');
$thai100 = mb_substr('มหาวิทยาลัยราชภัฏมหาสารคามจัดโครงการพัฒนาศักยภาพบุคลากรสายวิชาการและสายสนับสนุนเพื่อยกระดับคุณภาพการจัดการเรียนการสอนในศตวรรษที่ยี่สิบเอ็ด', 0, 100);
$slug = sanitize_title($thai100);
pls_test_assert('a 100-character Thai title is kept whole (' . strlen($slug) . ' bytes)', $thai100 === urldecode($slug) && strlen($slug) > 200);

$max_filter = null;
$set_max = function ($max) use (&$max_filter) {
	if ($max_filter) {
		remove_filter('parich_slug_max_length', $max_filter);
	}
	$max_filter = function () use ($max) {
		return $max;
	};
	add_filter('parich_slug_max_length', $max_filter);
};
$uncut = array();
$set_max(1900);
foreach ($titles as $i => $title) {
	$uncut[$i] = Parich_Slug_Sanitize::sanitize($title, '', 'save');
}
foreach (array(200, 1000, 1900) as $max) {
	$set_max($max);
	$problems = array();
	$cut_count = 0;
	foreach ($titles as $i => $title) {
		$slug = Parich_Slug_Sanitize::sanitize($title, '', 'save');
		$cut_count += strlen($slug) < strlen($uncut[$i]) ? 1 : 0;
		foreach (pls_cut_problems($slug, $max, 1900 === $max ? null : $uncut[$i]) as $problem) {
			$problems[] = mb_substr($title, 0, 20) . ": {$problem}";
		}
	}
	pls_test_assert("max {$max}: " . count($titles) . " titles ({$cut_count} cut) — within the limit, valid encoding and UTF-8, no mark cut off, no trailing leading vowel", array() === $problems, implode(' | ', array_slice($problems, 0, 3)));
}

$set_max(200);
$variants = array_values(array_filter($titles, function ($title) {
	return 0 === strpos($title, 'aaaaaaaaa') || 0 === strpos($title, 'ที่นี่มีเรื่อง');
}));
$ours_bad = 0;
$core_bad = 0;
foreach ($variants as $title) {
	$full = Parich_Slug_Sanitize::encode_thai_safe(mb_strtolower($title, 'UTF-8'), 1900);
	$ours_bad += pls_cut_problems(Parich_Slug_Sanitize::sanitize($title, '', 'save'), 200, $full) ? 1 : 0;
	$core_bad += pls_cut_problems(sanitize_title_with_dashes($title, '', 'save'), 200, $full) ? 1 : 0;
}
pls_test_assert(count($variants) . " cuts walked across marks and leading vowels: core breaks {$core_bad}, Parich Long Slug 0", 13 === count($variants) && $core_bad >= 3 && 0 === $ours_bad, "ours {$ours_bad}");

pls_test_assert('lookups (query context) use 1900 bytes even when new slugs are limited to 200', strlen(Parich_Slug_Sanitize::sanitize($titles[35], '', 'query')) > 1000 && strlen(Parich_Slug_Sanitize::sanitize($titles[35], '', 'save')) <= 200);
remove_filter('parich_slug_max_length', $max_filter);

pls_test_section('max_length() limits');
// Values already stored, bypassing the setting's own sanitize callback (tested in test-11),
// so max_length() has to cope with whatever is in the database.
remove_filter('sanitize_option_' . Parich_Slug_Sanitize::MAX_OPTION, array('Parich_Slug_Admin', 'sanitize_max_length'));
foreach (array(array(null, 1000), array('50', 200), array('5000', 1900), array('abc', 1000), array('1500', 1500)) as list($option, $want)) {
	null === $option ? delete_option(Parich_Slug_Sanitize::MAX_OPTION) : update_option(Parich_Slug_Sanitize::MAX_OPTION, $option);
	pls_test_assert('option ' . var_export($option, true) . " → {$want}", $want === Parich_Slug_Sanitize::max_length(), (string) Parich_Slug_Sanitize::max_length());
}
delete_option(Parich_Slug_Sanitize::MAX_OPTION);
add_filter('sanitize_option_' . Parich_Slug_Sanitize::MAX_OPTION, array('Parich_Slug_Admin', 'sanitize_max_length'));
$set_max(99999);
pls_test_assert('a filter above 1900 is held at 1900', 1900 === Parich_Slug_Sanitize::max_length());
remove_filter('parich_slug_max_length', $max_filter);

pls_test_section('Edge cases');
pls_test_assert('emoji: same as core', Parich_Slug_Sanitize::sanitize('ยินดีต้อนรับ 🎉', '', 'save') === sanitize_title_with_dashes('ยินดีต้อนรับ 🎉', '', 'save'));
pls_test_assert('symbols only: empty, as in core', '' === Parich_Slug_Sanitize::sanitize('!!! ??? ***', '', 'save'));
pls_test_assert('graphemes() keeps vowel signs and tone marks with their consonant', array('ที่', 'นี่') === Parich_Slug_Sanitize::graphemes('ที่นี่'));

update_option(Parich_Slug_Schema::COLUMNS_OK_OPTION, false);
remove_filter('sanitize_title', array('Parich_Slug_Sanitize', 'sanitize'), 10);
add_filter('sanitize_title', 'sanitize_title_with_dashes', 10, 3);
Parich_Slug_Sanitize::install_filter();
pls_test_assert('columns not widened: core\'s filter stays and slugs are cut at 200 bytes', !Parich_Slug_Sanitize::is_active() && strlen(sanitize_title($thai100)) <= 200);
update_option(Parich_Slug_Schema::COLUMNS_OK_OPTION, true);
Parich_Slug_Sanitize::install_filter();
pls_test_assert('…and the long filter replaces it once they are', Parich_Slug_Sanitize::is_active() && false === has_filter('sanitize_title', 'sanitize_title_with_dashes'));

pls_test_done();
