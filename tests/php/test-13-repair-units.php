<?php
/**
 * Phase 8 units: cut %-escapes and URL paths, every detector with a positive and a
 * negative case, and the scorer's grouping rules. No tables are read.
 *
 * @package Parich_Long_Slug
 */

require __DIR__ . '/../bootstrap.php';

$util = 'Parich_Slug_Repair_Util';
$detectors = 'Parich_Slug_Repair_Detectors';
$scorer = 'Parich_Slug_Repair_Scorer';

// A long Thai title and the slugs Long URL Maker gave it.
$title = 'ประกาศมหาวิทยาลัยราชภัฏมหาสารคามเรื่องการรับสมัครนักศึกษาใหม่ ประจำปีการศึกษาสองพันห้าร้อยหกสิบเก้า';
$full = Parich_Slug_Legacy_Sanitize::legacy_new_save($title);
$old = Parich_Slug_Legacy_Sanitize::legacy_old($title);
$cut = substr($full, 0, 200);

function pls_object(array $fields = array())
{
	return array_merge(array('type' => 'post', 'id' => 7, 'subtype' => 'post', 'status' => 'publish', 'parent' => 0, 'title' => '', 'slug' => '', 'hierarchical' => false), $fields);
}

function pls_facts(array $fields = array(), $title = '')
{
	return array_merge(Parich_Slug_Repair_Evidence::empty_facts($title), $fields);
}

function pls_entry($source, $strength = null, array $extra = array())
{
	return array_merge(array('source' => $source, 'detail' => 'test', 'strength' => null === $strength ? Parich_Slug_Repair_Scorer::STRENGTH[$source] : $strength, 'stale' => false, 'url' => false, 'ambiguous' => false), $extra);
}

/**
 * An item with given candidates, decided.
 *
 * @param array $candidates value => entries
 */
function pls_item(array $codes, array $candidates, array $object = array(), array $extra = array())
{
	$list = array();
	foreach ($candidates as $value => $entries) {
		$list[] = array('value' => (string) $value, 'entries' => $entries);
	}
	return Parich_Slug_Repair_Scorer::decide(array_merge(array(
		'object' => pls_object($object + array('slug' => 'current-slug')),
		'codes' => $codes,
		'd2_by' => null,
		'notes' => array(),
		'candidates' => $list,
		'supports' => array(),
		'current_votes' => array(),
		'invalid' => array(),
		'flags' => array(),
		'pinned' => null,
	), $extra));
}

pls_test_section('Cut %-escapes and validity');
pls_test_assert('the fixture title gives a slug over 200 bytes and a 200-byte cut', strlen($full) > 400 && 200 === strlen($cut) && 0 === strpos($full, $old));
$base = '%e0%b8%aa%e0%b8%a1';
foreach (array('%', '%e', '%e0', '%e0%b8', '%e0%b8%') as $tail) {
	pls_test_assert("trim_partial removes \"{$tail}\" left by a cut", $base === $util::trim_partial($base . $tail));
	pls_test_assert("a slug ending in \"{$tail}\" looks cut", $detectors::looks_cut($base . $tail));
}
pls_test_assert('trim_partial keeps a complete slug', $base === $util::trim_partial($base));
pls_test_assert('a complete 199-byte slug does not look cut (negative)', !$detectors::looks_cut(substr($full, 0, 198) . 'a'));
pls_test_assert('exactly 200 characters looks cut', $detectors::looks_cut($cut));
pls_test_assert('is_valid: lower-case hex, letters, digits, - and _', $util::is_valid($full) && $util::is_valid('abc_1-2'));
pls_test_assert('is_valid refuses upper-case hex, spaces, "+", "%20", a partial escape, invalid UTF-8 and over 1900 bytes', !$util::is_valid('%E0%B8%AA') && !$util::is_valid('a b') && !$util::is_valid('a+b') && !$util::is_valid('a%20b') && !$util::is_valid($base . '%e0') && !$util::is_valid('%e0%b8') && !$util::is_valid(str_repeat('a', 1901)) && !$util::is_valid(''));
$home = 'http://example.test/news';
pls_test_assert('path_of: upper- and lower-case hex give the same path', '/' . $base === $util::path_of('http://example.test/news/%E0%B8%AA%E0%B8%A1/', $home) && '/' . $base === $util::path_of('/news/' . $base, $home));
pls_test_assert('path_of: decoded Thai is encoded; query, fragment and trailing slash are dropped', '/' . $base === $util::path_of('http://example.test/news/สม/?utm=1#top', $home));
pls_test_assert('path_of: "+" stays a plus and "%20" a space (not the same)', '/a%2bb' === $util::path_of('/news/a+b', $home) && '/a%20b' === $util::path_of('/news/a%20b', $home));
pls_test_assert('path_of: another host gives null', null === $util::path_of('http://other.test/news/x', $home));
$segment = Parich_Slug_Repair_Evidence::segment_of('http://example.test/news/' . $base . '%e0%b8/page/2/', $home, true);
pls_test_assert('segment_of: a cut URL loses its partial character and endpoints like /page/2', is_array($segment) && $base === $segment['segment']);
pls_test_assert('segment_of: ?p= links are not slugs', null === Parich_Slug_Repair_Evidence::segment_of('http://example.test/news/?p=12', $home));
$urls = Parich_Slug_Repair_Evidence::urls_in_text('<a href="http://example.test/news/a-b/">x</a> <a href="/news/c/">y</a> {"url":"http:\/\/example.test\/news\/d\/"} http://other.test/e', 'http://example.test/news');
pls_test_assert('urls_in_text finds absolute, root-relative and JSON-escaped links of this site only', array('/news/a-b/', '/news/d/', '/news/c/') === $urls, wp_json_encode($urls));
pls_test_assert('a CSV cell starting with = + - @ is neutralised', "'=1+1" === Parich_Slug_Repair_Scanner::csv_cell('=1+1') && "'@x" === Parich_Slug_Repair_Scanner::csv_cell('@x') && 'ok' === Parich_Slug_Repair_Scanner::csv_cell('ok'));

pls_test_section('Detectors');
$d = $detectors::detect(pls_object(array('title' => $title, 'slug' => $cut)), pls_facts(array(), $title));
pls_test_assert('D1: cut at 200', array('D1') === $d['codes'], implode(',', $d['codes']));
$d = $detectors::detect(pls_object(array('title' => $title, 'slug' => $full)), pls_facts(array(), $title));
pls_test_assert('the long slug of the title: nothing (negative)', array() === $d['codes'], implode(',', $d['codes']));
$d = $detectors::detect(pls_object(array('title' => $title, 'slug' => $old)), pls_facts(array(), $title));
pls_test_assert('D2: what the deactivation routine writes for a long title', array('D2') === $d['codes'] && 'title' === $d['d2_by'], implode(',', $d['codes']));
$d = $detectors::detect(pls_object(array('title' => 'Hello world!', 'slug' => 'hello-world')), pls_facts(array(), 'Hello world!'));
pls_test_assert('a short title: legacy_old == legacy_new, no other evidence → nothing (negative)', array() === $d['codes']);
$d = $detectors::detect(pls_object(array('title' => 'Hello world!', 'slug' => 'hello-world')), pls_facts(array('s1' => array(array('row' => 1, 'value' => 'my-first-post'))), 'Hello world!'));
pls_test_assert('D2 by evidence: short title, but the backup says another slug', array('D2') === $d['codes'] && 'evidence' === $d['d2_by']);
$d = $detectors::detect(pls_object(array('title' => 'Hello world!', 'slug' => 'hello-world')), pls_facts(array('s1' => array(array('row' => 1, 'value' => 'my-first-post')), 'old_slugs' => array('my-first-post')), 'Hello world!'));
pls_test_assert('the backup value is an old slug of the post (changed later through core): no D2 (negative)', array() === $d['codes']);
$d = $detectors::detect(pls_object(array('title' => '', 'slug' => '')), pls_facts(array('s1' => array(array('row' => 1, 'value' => 'post-without-title'))), ''));
pls_test_assert('D2: empty slug of a published post with no title', array('D2') === $d['codes']);
$d = $detectors::detect(pls_object(array('status' => 'draft', 'title' => '', 'slug' => '')), pls_facts(array(), ''));
pls_test_assert('an empty slug of a draft is normal (negative)', array() === $d['codes']);
$d = $detectors::detect(pls_object(array('status' => 'inherit', 'subtype' => 'attachment', 'title' => $title, 'slug' => $old)), pls_facts(array(), $title));
pls_test_assert('attachments were never rewritten by the deactivation routine: no D2', !in_array('D2', $d['codes'], true));
$d = $detectors::detect(pls_object(array('title' => $title, 'slug' => 'older-slug')), pls_facts(array('old_slugs' => array('older-slug')), $title));
pls_test_assert('D3: the slug is one of the post\'s own old slugs', array('D3') === $d['codes']);
$seo_y = array('seo' => array(array('source' => 'yoast_indexable', 'value' => 'y', 'path' => '/y', 'truncated' => false)));
$d = $detectors::detect(pls_object(array('title' => 'x', 'slug' => 'z')), pls_facts($seo_y, 'x'));
pls_test_assert('D3: the SEO plugin remembers another slug (slug set by hand)', array('D3') === $d['codes'], implode(',', $d['codes']));
$d = $detectors::detect(pls_object(array('title' => 'x', 'slug' => 'x')), pls_facts($seo_y, 'x'));
pls_test_assert('the slug is the title\'s and the SEO plugin remembers another: D2 found by evidence', array('D2') === $d['codes'] && 'evidence' === $d['d2_by']);
$d = $detectors::detect(pls_object(array('title' => 'x', 'slug' => 'z')), pls_facts($seo_y + array('old_slugs' => array('y')), 'x'));
pls_test_assert('the SEO value is an old slug (changed through core afterwards): no D3 (negative)', array() === $d['codes']);
$stem = rtrim(substr($full, 0, 198), '-');
$d = $detectors::detect(pls_object(array('title' => $title, 'slug' => $stem . '-2')), pls_facts(array(), $title));
pls_test_assert('D5: the start of the title\'s slug plus "-2", 200 bytes or less, and not D1', array('D5') === $d['codes'] && '2' === $d['d5']['suffix']);
$numbered = 'ข่าว ' . str_repeat('ก', 20) . ' 12 ' . str_repeat('ข', 30);
$numbered_full = Parich_Slug_Legacy_Sanitize::legacy_new_save($numbered);
$numbered_cut = substr($numbered_full, 0, strpos($numbered_full, '-12-') + 3);
$d = $detectors::detect(pls_object(array('title' => $numbered, 'slug' => $numbered_cut)), pls_facts(array(), $numbered));
pls_test_assert('a slug ending in "-12" that is itself the start of the title\'s slug is not D5 (negative)', !in_array('D5', $d['codes'], true), implode(',', $d['codes']));
$trashed = $detectors::trashed_form($full);
pls_test_assert('trashed_form() is core\'s name: cut to 191 bytes + "__trashed"', $trashed === _truncate_post_slug($full, 191) . '__trashed' && strlen($trashed) <= 200);
$d = $detectors::detect(pls_object(array('status' => 'trash', 'title' => $title, 'slug' => $trashed)), pls_facts(array('desired' => array($full)), $title));
pls_test_assert('a trashed post named like core names it: nothing (negative)', array() === $d['codes']);
$d = $detectors::detect(pls_object(array('status' => 'trash', 'title' => $title, 'slug' => $full)), pls_facts(array('desired' => array($full)), $title));
pls_test_assert('a trashed post whose name was put back (no "__trashed"): D3', array('D3') === $d['codes']);
$d = $detectors::detect(pls_object(array('status' => 'trash', 'title' => $title, 'slug' => $old)), pls_facts(array('desired' => array($full)), $title));
pls_test_assert('a trashed post rewritten from its title: D2', array('D2') === $d['codes']);
$d = $detectors::detect(pls_object(array('type' => 'term', 'subtype' => 'category', 'status' => '', 'title' => $title, 'slug' => $old)), pls_facts(array(), $title));
pls_test_assert('D7 marks a damaged term (here also D2)', array('D2', 'D7') === $d['codes']);
$d = $detectors::detect(pls_object(array('type' => 'term', 'subtype' => 'genre', 'status' => '', 'title' => $title, 'slug' => $old)), pls_facts(array(), $title));
pls_test_assert('a term of another taxonomy was not rewritten: no D2 (negative)', !in_array('D2', $d['codes'], true));

pls_test_section('Scorer');
$item = $scorer::analyze(pls_object(array('title' => 'Unrelated', 'slug' => $cut)), pls_facts(array(), 'Unrelated'), $detectors::detect(pls_object(array('title' => 'Unrelated', 'slug' => $cut)), pls_facts(array(), 'Unrelated')));
pls_test_assert('a 200-byte slug with no evidence is "unresolved", not "auto"', 'unresolved' === $item['group'] && null === $item['proposal']);
$facts = pls_facts(array('s1' => array(array('row' => 3, 'value' => $full))), $title);
$item = $scorer::analyze(pls_object(array('title' => $title, 'slug' => $cut)), $facts, $detectors::detect(pls_object(array('title' => $title, 'slug' => $cut)), $facts));
pls_test_assert('D1 + the backup row it was cut from → "auto" with that value', 'auto' === $item['group'] && $full === $item['proposal']);
$facts = pls_facts(array('s1' => array(array('row' => 3, 'value' => $full), array('row' => 9, 'value' => $cut))), $title);
$item = $scorer::analyze(pls_object(array('title' => $title, 'slug' => $cut)), $facts, $detectors::detect(pls_object(array('title' => $title, 'slug' => $cut)), $facts));
pls_test_assert('…but a newer backup row says the cut slug was saved again → "review"', 'review' === $item['group'] && in_array('current_confirmed', $item['reasons'], true));
$facts = pls_facts(array('s1' => array(array('row' => 3, 'value' => $old))), $title);
$item = $scorer::analyze(pls_object(array('title' => $title, 'slug' => $old)), $facts, $detectors::detect(pls_object(array('title' => $title, 'slug' => $old)), $facts));
pls_test_assert('D2 whose newest backup row equals the slug (saved after the damage) → "ok"', 'ok' === $item['group'] && in_array('confirmed_after_damage', $item['notes'], true));
$facts = pls_facts(array('s1' => array(array('row' => 3, 'value' => 'custom-slug'))), $title);
$item = $scorer::analyze(pls_object(array('title' => $title, 'slug' => $old)), $facts, $detectors::detect(pls_object(array('title' => $title, 'slug' => $old)), $facts));
pls_test_assert('D2 + backup row → "auto" with the backup value, even against the title\'s slug (medium)', 'auto' === $item['group'] && 'custom-slug' === $item['proposal']);
$item = $scorer::analyze(pls_object(array('title' => $title, 'slug' => $old, 'hierarchical' => true, 'subtype' => 'page')), pls_facts(array('s1' => array(array('row' => 3, 'value' => 'about'))), 'Contact'), array('codes' => array('D2'), 'd2_by' => 'evidence', 'd5' => null, 'notes' => array()));
pls_test_assert('a page whose only sign is a differing backup row → "review" (pages keep no slug history)', 'review' === $item['group'] && in_array('unchecked_history', $item['reasons'], true));
$facts = pls_facts(array('s1' => array(array('row' => 3, 'value' => 'old-one'))), $title);
$facts['old_slugs'] = array('old-one');
$facts['seo'] = array(array('source' => 'yoast_indexable', 'value' => 'new-one', 'path' => '/new-one', 'truncated' => false));
$item = $scorer::analyze(pls_object(array('title' => $title, 'slug' => $old)), $facts, $detectors::detect(pls_object(array('title' => $title, 'slug' => $old)), $facts));
pls_test_assert('an out-of-date backup (its value is an old slug) → "review", the SEO value first', 'review' === $item['group'] && 'new-one' === $item['proposal'] && in_array('stale_backup', $item['reasons'], true));

$item = pls_item(array('D1'), array('aaa' => array(pls_entry('S1')), 'bbb' => array(pls_entry('S4'))));
pls_test_assert('two strong sources with different values → "review" (conflict)', 'review' === $item['group'] && in_array('conflict', $item['reasons'], true));
$item = pls_item(array('D1'), array('aaa' => array(pls_entry('S5', null, array('url' => true)), pls_entry('S7', null, array('url' => true)))));
pls_test_assert('the same value from two independent medium sources → "auto"', 'auto' === $item['group'] && 'aaa' === $item['proposal']);
$item = pls_item(array('D1'), array('aaa' => array(pls_entry('S5', null, array('url' => true, 'detail' => 'redirection_404')), pls_entry('S5', null, array('url' => true, 'detail' => 'rank_math_404_logs')))));
pls_test_assert('two 404 logs are one source → "review"', 'review' === $item['group'] && in_array('weak', $item['reasons'], true));
$item = pls_item(array('D1'), array('aaa' => array(pls_entry('S8'))));
pls_test_assert('one medium source → "review"', 'review' === $item['group'] && in_array('weak', $item['reasons'], true));
$item = Parich_Slug_Repair_Scorer::decide(Parich_Slug_Repair_Scorer::mark_ambiguous(pls_item(array('D1'), array('aaa' => array(pls_entry('S5', null, array('url' => true)), pls_entry('S7', null, array('url' => true))))), array('aaa' => true)));
pls_test_assert('URL evidence other items claim too does not count → "review"', 'review' === $item['group']);
$item = pls_item(array('D3'), array('aaa' => array(pls_entry('S4'))));
pls_test_assert('D3 is always "review"', 'review' === $item['group'] && in_array('d3', $item['reasons'], true));
$item = pls_item(array('D5'), array('aaa' => array(pls_entry('S8'), pls_entry('S1'))));
pls_test_assert('D5 is always "review"', 'review' === $item['group'] && in_array('d5', $item['reasons'], true));
$item = pls_item(array('D1'), array('aaa' => array(pls_entry('S1'))), array(), array('current_votes' => array(array('source' => 'S4', 'detail' => 'yoast'))));
pls_test_assert('a strong source for the current slug → "review"', 'review' === $item['group'] && in_array('current_confirmed', $item['reasons'], true));
$item = pls_item(array('D1'), array('aaa' => array(pls_entry('S1'))), array(), array('flags' => array('collision')));
pls_test_assert('a value another item keeps → "review"', 'review' === $item['group'] && in_array('collision', $item['reasons'], true));
$item = pls_item(array('D1'), array('aaa' => array(pls_entry('S10'))));
pls_test_assert('only the guid → "review"', 'review' === $item['group']);
$object = pls_object(array('title' => $title, 'slug' => $cut));
$facts = pls_facts(array('csv' => 'not valid!'), $title);
$item = $scorer::analyze($object, $facts, $detectors::detect($object, $facts));
pls_test_assert('an invalid value is never proposed', !in_array('not valid!', wp_list_pluck($item['candidates'], 'value'), true) && 1 === count($item['invalid']));
$item = $scorer::analyze($object, pls_facts(array(), $title), $detectors::detect($object, pls_facts(array(), $title)), function ($prefix) use ($full) {
	return array((object) array('source' => 'S5', 'detail' => 'redirection_404', 'url_path' => '/' . $full, 'last_segment' => $full, 'hits' => 4, 'truncated' => 0));
});
pls_test_assert('a cut slug + its title\'s slug + a 404 log with that URL → "auto"', 'auto' === $item['group'] && $full === $item['proposal']);
$item = $scorer::analyze($object, pls_facts(array(), $title), $detectors::detect($object, pls_facts(array(), $title)), function ($prefix) use ($full) {
	return array((object) array('source' => 'S5', 'detail' => 'rank_math_404_logs', 'url_path' => '/' . substr($full, 0, 255), 'last_segment' => substr($full, 0, 250), 'hits' => 4, 'truncated' => 1));
});
pls_test_assert('a cut URL (varchar(255)) only supports, it is never a value → "review"', 'review' === $item['group'] && $full === $item['proposal'] && 1 === count($item['supports']));

pls_test_done();
