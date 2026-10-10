<?php
/**
 * Phase 7: the port of Long URL Maker's slug functions gives exactly what the
 * original functions gave (tests/fixtures/legacy-slugs.json, made in Phase 1 by
 * running a verbatim copy of them), without PHP notices.
 *
 * @package Parich_Long_Slug
 */

require __DIR__ . '/../bootstrap.php';

/**
 * Entries of the fixture the port does not reproduce, or a single problem line
 * when there is nothing to compare.
 *
 * @return string[]
 */
function pls_legacy_mismatches($entries)
{
	if (!is_array($entries) || count($entries) < 40) {
		return array('fixture has ' . (is_array($entries) ? count($entries) : 0) . ' entries, at least 40 needed');
	}
	$mismatches = array();
	foreach ($entries as $i => $entry) {
		$checks = array(
			'legacy_new_save' => Parich_Slug_Legacy_Sanitize::legacy_new_save($entry['title']),
			'legacy_new_raw' => Parich_Slug_Legacy_Sanitize::legacy_new($entry['title']),
			'legacy_old' => Parich_Slug_Legacy_Sanitize::legacy_old($entry['title']),
		);
		foreach ($checks as $field => $ours) {
			if ($ours !== $entry[$field]) {
				$mismatches[] = "#{$i} {$field}: " . mb_substr($entry['title'], 0, 25) . " → {$ours} ≠ {$entry[$field]}";
			}
		}
	}
	return $mismatches;
}

$fixture = json_decode((string) file_get_contents(__DIR__ . '/../fixtures/legacy-slugs.json'), true);

pls_test_section('Port against the original functions');
$mismatches = pls_legacy_mismatches($fixture);
pls_test_assert(count($fixture) . ' titles × 3 functions identical (save path with remove_accents, raw, old)', array() === $mismatches, implode(' | ', array_slice($mismatches, 0, 3)));
$long = array_filter($fixture, function ($entry) {
	return strlen($entry['legacy_new_raw']) > 200;
});
pls_test_assert('the fixture covers slugs longer than 200 bytes (' . count($long) . ') and the 200-byte cut of the old function', count($long) >= 10 && 200 >= max(array_map('strlen', array_column($fixture, 'legacy_old'))));
pls_test_assert(
	'quirk kept: curly quotes stay percent-encoded (the original never ran its "save" branch)',
	false !== strpos(Parich_Slug_Legacy_Sanitize::legacy_new('ข่าว “ทุน” ใหม่'), '%e2%80%9c') && false === strpos(sanitize_title_with_dashes('ข่าว “ทุน” ใหม่', '', 'save'), '%e2%80%9c')
);
pls_test_assert('quirk kept: remove_accents() only on the save path', 'cafe' === Parich_Slug_Legacy_Sanitize::legacy_new_save('Café') && 'caf%c3%a9' === Parich_Slug_Legacy_Sanitize::legacy_new('Café'));

pls_test_section('Negative controls');
$broken = $fixture;
$broken[7]['legacy_old'] .= 'x';
pls_test_assert('one wrong value in the fixture is reported', 1 === count(pls_legacy_mismatches($broken)));
pls_test_assert('an empty fixture is reported, not passed', 1 === count(pls_legacy_mismatches(array())) && false !== strpos(pls_legacy_mismatches(array())[0], '0 entries'));

pls_test_done();
