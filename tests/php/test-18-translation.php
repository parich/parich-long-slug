<?php
/**
 * Phase 11: the Thai translation (languages/parich-long-slug-th.po, kept in the
 * repository for translate.wordpress.org, not shipped): read with WordPress's own PO
 * reader, every string translated, every translation safe in sprintf(), and the
 * strings come out in Thai once loaded for the text domain.
 *
 * @package Parich_Long_Slug
 */

require __DIR__ . '/../bootstrap.php';
require_once ABSPATH . WPINC . '/pomo/po.php';

$file = PLS_TEST_PLUGIN_DIR . '/languages/parich-long-slug-th.po';
$po = new PO();
pls_test_assert('the Thai .po reads with WordPress\'s PO reader', is_file($file) && $po->import_from_file($file));
pls_test_assert('it is Thai, with one plural form', 'th' === $po->get_header('Language') && 'nplurals=1; plural=0;' === $po->get_header('Plural-Forms'));
$template = new PO();
$template->import_from_file(PLS_TEST_PLUGIN_DIR . '/languages/parich-long-slug.pot');
pls_test_assert('it has every string of the template (' . count($template->entries) . ')', count($template->entries) > 200 && !array_diff_key($template->entries, $po->entries) && !array_diff_key($po->entries, $template->entries));
$untranslated = array_filter($po->entries, function ($entry) {
	return !$entry->translations || '' === (string) $entry->translations[0];
});
pls_test_assert('every string is translated', !$untranslated, implode(' | ', array_slice(array_keys($untranslated), 0, 3)));

$broken = array();
foreach ($po->entries as $entry) {
	$translation = (string) $entry->translations[0];
	if (!preg_match_all('/%(\d+\$)?([sd])/', $entry->singular, $matches)) {
		continue;
	}
	$args = array();
	foreach ($matches[2] as $i => $type) {
		$args[] = 'd' === $type ? $i + 1 : 'x';
	}
	try {
		$result = vsprintf($translation, $args);
		if (false === $result) {
			$broken[] = $entry->singular;
		}
	} catch (Throwable $e) {
		$broken[] = $entry->singular . ' (' . $e->getMessage() . ')';
	}
}
pls_test_assert('every translation with placeholders works in sprintf()', !$broken, implode(' | ', $broken));

// Loaded as a language pack would load it.
$mo = new MO();
$mo->set_headers($po->headers);
$mo->entries = $po->entries;
$GLOBALS['l10n']['parich-long-slug'] = $mo;
pls_test_assert('strings come out in Thai once loaded', 'ตั้งค่า' === __('Settings', 'parich-long-slug') && 'บริจาค' === __('Donate', 'parich-long-slug'));
pls_test_assert('plural strings too (one Thai form for any number)', 'ลิงก์บนเว็บของคุณ 3 ลิงก์ยังชี้ไปที่อยู่เดิม การเปลี่ยนเส้นทางรองรับแล้ว แต่อาจแก้ลิงก์ให้ตรงด้วย' === sprintf(_n('%d link on your site still points at an old address; the redirects cover it, but you may want to update it.', '%d links on your site still point at old addresses; the redirects cover them, but you may want to update them.', 3, 'parich-long-slug'), 3));
$labels = Parich_Slug_Repair_Admin::code_labels();
pls_test_assert('the repair tab\'s labels are translated', 'ถูกตัดที่ 200 ตัวอักษร' === $labels['D1'] && false !== strpos(Parich_Slug_Repair_Admin::reason_label('d3'), 'Long URL Maker'));
unset($GLOBALS['l10n']['parich-long-slug']);

pls_test_section('Not shipped');
$main = (string) file_get_contents(PLS_TEST_PLUGIN_DIR . '/parich-long-slug.php');
pls_test_assert('no load_plugin_textdomain() and no Domain Path (WordPress.org language packs)', false === strpos($main, 'load_plugin_textdomain') && false === stripos($main, 'Domain Path'));

pls_test_done();
