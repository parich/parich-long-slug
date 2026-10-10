<?php
/**
 * Damage lab stages: run the REAL Long URL Maker 2.0.1 on this site's database so it
 * damages slugs the way it does on real sites, and record what every slug should be.
 *
 * Run only through run-lab.js, which backs the database up first and restores it at
 * the end:  wp eval-file create-damage.php <stage> [<arg>]
 *
 * Stages, in order: preflight, prepare <backup.sql>, seed, d3 [no-trigger], check-d3,
 * d1, check-d1, reactivate, d2, after-restore. "fingerprint" prints a hash of every
 * slug, title and post meta row, used to prove the restore.
 *
 * Lab objects carry the post meta / term meta "_pls_lab" = group name. The ground truth
 * (tests/damage-lab/ground-truth.json, not committed) is written at the "clean" moment:
 * after every intended edit, before Long URL Maker's restore and the database upgrade.
 *
 * @package Parich_Long_Slug
 */

if (!defined('WP_CLI')) {
	exit;
}

const PLS_LAB_LEGACY = 'long-url-maker/long_url_maker.php';
const PLS_LAB_OURS = 'parich-long-slug/parich-long-slug.php';
const PLS_LAB_TRUTH = __DIR__ . '/ground-truth.json';

require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/post.php';
require_once ABSPATH . 'wp-admin/includes/image.php';

function pls_lab_fail($message)
{
	WP_CLI::error($message);
}

function pls_lab_out($data)
{
	WP_CLI::line('PLS_LAB_JSON ' . wp_json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function pls_lab_guard_path()
{
	return WPMU_PLUGIN_DIR . '/parich-long-slug-guard.php';
}

function pls_lab_column($table, $column)
{
	global $wpdb;
	return $wpdb->get_var($wpdb->prepare('SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s', $table, $column));
}

function pls_lab_register_types()
{
	register_post_type('pls_lab_cpt', array('public' => true, 'show_in_rest' => true, 'rest_base' => 'pls-lab-cpt', 'label' => 'Lab CPT'));
	register_taxonomy('pls_lab_tax', array('post'), array('public' => true, 'show_in_rest' => true, 'label' => 'Lab tax'));
}

function pls_lab_admin()
{
	$admins = get_users(array('role' => 'administrator', 'number' => 1));
	if (!$admins) {
		pls_lab_fail('No administrator on this site.');
	}
	wp_set_current_user($admins[0]->ID);
}

/**
 * A save from the classic editor form: Long URL Maker only backs a slug up when
 * $_POST['post_type'] is post, page, product or attachment.
 */
function pls_lab_form_post(array $post, $group)
{
	$_POST = array('post_type' => isset($post['post_type']) ? $post['post_type'] : 'post', 'post_title' => isset($post['post_title']) ? $post['post_title'] : '');
	$id = wp_insert_post($post + array('post_status' => 'publish', 'post_type' => 'post', 'post_content' => 'Lab: ' . $group), true);
	$_POST = array();
	if (is_wp_error($id)) {
		pls_lab_fail("form post ({$group}): " . $id->get_error_message());
	}
	update_post_meta($id, '_pls_lab', $group);
	return $id;
}

/**
 * A save from the block editor (REST): $_POST is empty, so no backup row.
 */
function pls_lab_rest($method, $route, array $params, $group = null)
{
	$request = new WP_REST_Request($method, $route);
	foreach ($params as $key => $value) {
		$request->set_param($key, $value);
	}
	$response = rest_do_request($request);
	if ($response->is_error() || $response->get_status() >= 300) {
		pls_lab_fail("REST {$method} {$route}: " . wp_json_encode($response->get_data()));
	}
	$data = $response->get_data();
	if ($group && isset($data['id'])) {
		update_post_meta($data['id'], '_pls_lab', $group);
	}
	return $data;
}

function pls_lab_term($name, $taxonomy, $group, $parent = 0)
{
	$term = wp_insert_term($name, $taxonomy, array('parent' => $parent));
	if (is_wp_error($term)) {
		pls_lab_fail("term {$name}: " . $term->get_error_message());
	}
	update_term_meta($term['term_id'], '_pls_lab', $group);
	return $term['term_id'];
}

/**
 * Distinct Thai titles of 30–80 characters (270–720 bytes once encoded).
 */
function pls_lab_title($n)
{
	$parts = array(
		'ประกาศมหาวิทยาลัยราชภัฏมหาสารคามเรื่องการรับสมัครนักศึกษาใหม่',
		'คณะครุศาสตร์จัดโครงการพัฒนาศักยภาพครูในศตวรรษที่ยี่สิบเอ็ด',
		'สำนักวิทยบริการแนะนำฐานข้อมูลออนไลน์สำหรับการค้นคว้าวิจัย',
		'ผลการแข่งขันกีฬามหาวิทยาลัยราชภัฏแห่งประเทศไทยครั้งที่สี่สิบแปด',
		'ขอเชิญร่วมงานวันวิทยาศาสตร์แห่งชาติและกิจกรรมเปิดบ้านวิชาการ',
		'กองพัฒนานักศึกษาประกาศรายชื่อผู้ได้รับทุนการศึกษาเพื่อพัฒนาท้องถิ่น',
		'บัณฑิตวิทยาลัยเปิดรับสมัครนักศึกษาปริญญาโทและปริญญาเอกภาคพิเศษ',
		'ศูนย์ภาษาจัดอบรมภาษาอังกฤษเพื่อการสื่อสารสำหรับบุคลากรสายสนับสนุน',
	);
	$tails = array('ประจำปีการศึกษาสองพันห้าร้อยหกสิบเก้า', 'ภาคเรียนที่หนึ่ง', 'ภาคเรียนที่สอง', 'รอบที่สาม', 'ฉบับปรับปรุงใหม่', 'สำหรับผู้สนใจทั่วไป');
	return $parts[$n % count($parts)] . ' ' . $tails[intdiv($n, count($parts)) % count($tails)] . ' ' . ($n + 1);
}

/**
 * Every post (except revisions and auto-drafts) and term with its slug now.
 */
function pls_lab_snapshot_objects()
{
	global $wpdb;
	$objects = array();
	$posts = $wpdb->get_results("SELECT ID, post_type, post_status, post_parent, post_title, post_name FROM {$wpdb->posts} WHERE post_type <> 'revision' AND post_status <> 'auto-draft' ORDER BY ID");
	foreach ($posts as $post) {
		$objects[] = array(
			'type' => 'post',
			'id' => (int) $post->ID,
			'subtype' => $post->post_type,
			'status' => $post->post_status,
			'parent' => (int) $post->post_parent,
			'title' => $post->post_title,
			'clean_slug' => $post->post_name,
			'old_slugs' => array_values(get_post_meta($post->ID, '_wp_old_slug')),
			'group' => (string) get_post_meta($post->ID, '_pls_lab', true) ?: 'existing',
		);
	}
	$terms = $wpdb->get_results("SELECT t.term_id, t.name, t.slug, tt.taxonomy, tt.parent FROM {$wpdb->terms} t JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id ORDER BY t.term_id");
	foreach ($terms as $term) {
		$objects[] = array(
			'type' => 'term',
			'id' => (int) $term->term_id,
			'subtype' => $term->taxonomy,
			'status' => '',
			'parent' => (int) $term->parent,
			'title' => $term->name,
			'clean_slug' => $term->slug,
			'old_slugs' => array(),
			'group' => (string) get_term_meta($term->term_id, '_pls_lab', true) ?: 'existing',
		);
	}
	return $objects;
}

global $wpdb, $wp_version, $wp_db_version;
$stage = isset($args[0]) ? $args[0] : '';

switch ($stage) {
	case 'preflight':
		$problems = array();
		if (!is_plugin_active(PLS_LAB_OURS)) {
			$problems[] = 'Parich Long Slug is not active';
		}
		if (!file_exists(WP_PLUGIN_DIR . '/' . PLS_LAB_LEGACY)) {
			$problems[] = 'Long URL Maker is not installed';
		} elseif (is_plugin_active(PLS_LAB_LEGACY)) {
			$problems[] = 'Long URL Maker is already active';
		}
		if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->prefix . 'kpcode_url_posts'))) {
			$problems[] = 'wp_kpcode_url_posts already exists';
		}
		if ((int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name IN ('kpcode_version_wp', 'kpcode_longurlmaker_version', 'c_version_wp')")) {
			$problems[] = 'Long URL Maker options already exist';
		}
		if ($wpdb->get_col("SHOW TABLES LIKE 'plslab\\_%'")) {
			$problems[] = 'lab snapshot tables (plslab_*) are still loaded';
		}
		if (!file_exists(pls_lab_guard_path())) {
			$problems[] = 'the guard is not installed';
		}
		pls_lab_out(array(
			'ok' => !$problems,
			'problems' => $problems,
			'wp_version' => $wp_version,
			'db_version' => (int) get_option('db_version'),
			'post_name' => pls_lab_column($wpdb->posts, 'post_name'),
		));
		break;

	case 'fingerprint':
		$parts = array(
			'posts' => $wpdb->get_results("SELECT ID, post_name, post_title, post_status, post_type, post_modified FROM {$wpdb->posts} ORDER BY ID", ARRAY_N),
			'postmeta' => $wpdb->get_results("SELECT meta_id, post_id, meta_key, meta_value FROM {$wpdb->postmeta} ORDER BY meta_id", ARRAY_N),
			'terms' => $wpdb->get_results("SELECT term_id, name, slug FROM {$wpdb->terms} ORDER BY term_id", ARRAY_N),
			'term_taxonomy' => $wpdb->get_results("SELECT term_taxonomy_id, term_id, taxonomy, parent FROM {$wpdb->term_taxonomy} ORDER BY term_taxonomy_id", ARRAY_N),
			'tables' => $wpdb->get_col('SHOW TABLES'),
			'active_plugins' => array_values((array) get_option('active_plugins')),
			'kpcode_options' => $wpdb->get_col("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'kpcode\\_%' OR option_name = 'c_version_wp'"),
			'columns' => array(pls_lab_column($wpdb->posts, 'post_name'), pls_lab_column($wpdb->terms, 'slug')),
			'db_version' => get_option('db_version'),
		);
		$hashes = array();
		foreach ($parts as $key => $value) {
			$hashes[$key] = (is_array($value) ? count($value) : 1) . ':' . md5(serialize($value));
		}
		pls_lab_out($hashes);
		break;

	case 'prepare':
		$backup = isset($args[1]) ? $args[1] : '';
		$head = is_readable($backup) ? (string) file_get_contents($backup, false, null, max(0, filesize($backup) - 200)) : '';
		if (!$backup || !is_readable($backup) || time() - filemtime($backup) > 1800 || false === strpos($head, 'Dump completed')) {
			pls_lab_fail('A complete database backup made in the last 30 minutes is required.');
		}
		deactivate_plugins(PLS_LAB_OURS);
		if (!rename(pls_lab_guard_path(), pls_lab_guard_path() . '.lab-off')) {
			pls_lab_fail('Could not move the guard aside.');
		}
		pls_lab_out(array('ours_active' => is_plugin_active(PLS_LAB_OURS), 'guard' => file_exists(pls_lab_guard_path())));
		break;

	case 'seed':
		if (is_plugin_active(PLS_LAB_OURS) || file_exists(pls_lab_guard_path())) {
			pls_lab_fail('Parich Long Slug and its guard must be off.');
		}
		pls_lab_register_types();
		pls_lab_admin();
		$result = activate_plugin(PLS_LAB_LEGACY);
		if (is_wp_error($result) || !function_exists('kpcode_set_url_format')) {
			pls_lab_fail('Long URL Maker did not activate.');
		}

		$n = 0;
		$ids = array();
		foreach (array('form', 'quotes', 'retitled') as $group) {
			for ($i = 0; $i < 3; $i++) {
				$title = pls_lab_title($n++);
				if ('quotes' === $group) {
					$title = '“' . $title . '” – ' . 'ฉบับพิเศษ';
				}
				$ids[$group][] = pls_lab_form_post(array('post_title' => $title), $group);
			}
		}
		foreach ($ids['retitled'] as $id) {
			// Title changed later in the block editor; the slug stays from the first title.
			pls_lab_rest('POST', '/wp/v2/posts/' . $id, array('title' => 'หัวข้อใหม่หลังแก้ไข ' . get_the_title($id)));
		}
		for ($i = 0; $i < 3; $i++) {
			pls_lab_rest('POST', '/wp/v2/posts', array('title' => pls_lab_title($n++), 'status' => 'publish'), 'rest');
		}
		for ($i = 0; $i < 4; $i++) {
			$ids['d3x'][] = pls_lab_form_post(array('post_title' => pls_lab_title($n++)), 'd3x');
		}
		$d5 = pls_lab_title($n++);
		for ($i = 0; $i < 4; $i++) {
			pls_lab_rest('POST', '/wp/v2/posts', array('title' => $d5, 'status' => 'publish'), 'd5');
		}
		for ($i = 0; $i < 3; $i++) {
			pls_lab_rest('POST', '/wp/v2/pls-lab-cpt', array('title' => pls_lab_title($n++), 'status' => 'publish'), 'cpt');
		}
		$parent = 0;
		for ($level = 0; $level < 3; $level++) {
			$parent = pls_lab_form_post(array('post_title' => pls_lab_title($n++), 'post_type' => 'page', 'post_parent' => $parent), 'page');
		}
		for ($i = 0; $i < 3; $i++) {
			pls_lab_form_post(array('post_title' => pls_lab_title($n++), 'post_name' => 'custom-slug-set-by-hand-' . ($i + 1)), 'custom');
		}
		for ($i = 0; $i < 3; $i++) {
			pls_lab_form_post(array('post_title' => '', 'post_name' => 'post-without-title-' . ($i + 1)), 'empty');
		}
		for ($i = 0; $i < 3; $i++) {
			$id = pls_lab_form_post(array('post_title' => pls_lab_title($n++)), 'trash');
			wp_trash_post($id);
		}
		for ($i = 0; $i < 3; $i++) {
			$_POST = 2 > $i ? array('post_type' => 'attachment') : array();
			$id = wp_insert_attachment(array('post_title' => pls_lab_title($n++), 'post_mime_type' => 'image/png', 'post_status' => 'inherit', 'guid' => home_url('/lab-' . $i . '.png')));
			$_POST = array();
			update_post_meta($id, '_pls_lab', 2 > $i ? 'attach-backup' : 'attach');
		}

		$cat = pls_lab_term(pls_lab_title($n++), 'category', 'term-cat');
		pls_lab_term(pls_lab_title($n++), 'category', 'term-cat', $cat);
		for ($i = 0; $i < 3; $i++) {
			pls_lab_term(pls_lab_title($n++), 'post_tag', 'term-tag');
			pls_lab_term(pls_lab_title($n++), 'pls_lab_tax', 'term-custom');
		}

		// Posts whose post_name is a key, not a URL (D6).
		$stylesheet = get_stylesheet();
		$system = array(
			array('post_type' => 'acf-field', 'post_title' => 'Lab Field One', 'post_name' => 'field_64f1a2b3c4d5e', 'post_excerpt' => 'lab_field_one'),
			array('post_type' => 'acf-field', 'post_title' => 'Lab Field Two', 'post_name' => 'field_64f1a2b3c4d5f', 'post_excerpt' => 'lab_field_two'),
			array('post_type' => 'acf-field-group', 'post_title' => 'Lab Field Group', 'post_name' => 'group_64f1a2b3c4d60'),
			array('post_type' => 'wp_global_styles', 'post_title' => 'Custom Styles', 'post_name' => 'wp-global-styles-' . urlencode($stylesheet), 'post_content' => '{"version":3,"isGlobalStylesUserThemeJSON":true}'),
			array('post_type' => 'wp_template', 'post_title' => 'Single Posts', 'post_name' => 'single', 'post_content' => '<!-- wp:post-content /-->'),
			array('post_type' => 'wp_font_family', 'post_title' => 'Noto Sans Thai', 'post_name' => 'noto-sans-thai', 'post_content' => '{"fontFamily":"\"Noto Sans Thai\", sans-serif"}'),
		);
		foreach ($system as $post) {
			$id = wp_insert_post($post + array('post_status' => 'publish'), true);
			if (is_wp_error($id)) {
				pls_lab_fail('system post: ' . $id->get_error_message());
			}
			if (in_array($post['post_type'], array('wp_global_styles', 'wp_template'), true)) {
				wp_set_object_terms($id, $stylesheet, 'wp_theme');
			}
			update_post_meta($id, '_pls_lab', 'system');
		}
		// ACF stores, next to each value, the field key under "_<field name>".
		update_post_meta($ids['form'][0], 'lab_field_one', 'value');
		update_post_meta($ids['form'][0], '_lab_field_one', 'field_64f1a2b3c4d5e');
		update_post_meta($ids['form'][1], 'lab_field_one', 'value');
		update_post_meta($ids['form'][1], '_lab_field_one', 'field_64f1a2b3c4d5e');

		update_option('pls_lab_state', array('d3x' => $ids['d3x'], 'next_title' => $n), false);
		pls_lab_out(array(
			'backup_rows' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}kpcode_url_posts"),
			'lab_posts' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_pls_lab'"),
			'lab_terms' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->termmeta} WHERE meta_key = '_pls_lab'"),
			'post_name' => pls_lab_column($wpdb->posts, 'post_name'),
		));
		break;

	case 'd3':
		$trigger = !(isset($args[1]) && 'no-trigger' === $args[1]);
		pls_lab_register_types();
		pls_lab_admin();
		$state = get_option('pls_lab_state');
		$n = (int) $state['next_title'];
		$old = array();
		foreach ($state['d3x'] as $i => $id) {
			$old[$i] = get_post_field('post_name', $id, 'raw');
			// Slug changed in the block editor: no backup row, core keeps the previous one in _wp_old_slug.
			pls_lab_rest('POST', '/wp/v2/posts/' . $id, array('slug' => pls_lab_title($n++) . ' แก้ลิงก์'));
		}
		for ($i = 0; $i < 3; $i++) {
			// A new post (classic editor, so it is backed up) takes the slug the first three
			// gave up: both end up with it once those are reverted (D4). A REST post would be
			// cut by the upgrade and never restored, so it would not collide.
			pls_lab_form_post(array('post_title' => 'โพสต์ใหม่ที่ใช้ลิงก์เดิม ' . ($i + 1), 'post_name' => urldecode($old[$i])), 'd4y');
		}

		// Evidence that outlives the damage: links in content, an SEO index, 404 logs. A site
		// may still have these tables from a plugin it removed (this one has Yoast's): then they
		// are used as they are, and the backup restore puts their old content back.
		$targets = get_posts(array('post_type' => 'post', 'numberposts' => -1, 'post_status' => 'publish', 'meta_key' => '_pls_lab', 'meta_value' => 'rest', 'orderby' => 'ID', 'order' => 'ASC'));
		$content = '';
		foreach (array_merge($targets, array_map('get_post', $state['d3x'])) as $target) {
			$content .= '<p><a href="' . esc_url(get_permalink($target)) . '">' . esc_html($target->post_title) . '</a></p>';
		}
		pls_lab_form_post(array('post_title' => 'รวมลิงก์ภายในก่อนเสียหาย', 'post_content' => $content), 'links');

		$charset = $wpdb->get_charset_collate();
		$wpdb->query("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}yoast_indexable (id int(11) unsigned NOT NULL AUTO_INCREMENT, permalink mediumtext, permalink_hash varchar(40), object_id bigint(20), object_type varchar(32) NOT NULL, object_sub_type varchar(32), PRIMARY KEY (id)) {$charset}");
		$wpdb->query("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}redirection_404 (id int(11) unsigned NOT NULL AUTO_INCREMENT, created datetime NOT NULL, url mediumtext NOT NULL, domain varchar(255), agent varchar(255), referrer varchar(255), http_code int(11) unsigned NOT NULL DEFAULT 0, request_method varchar(10), request_data mediumtext, ip varchar(45), PRIMARY KEY (id)) {$charset}");
		$wpdb->query("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}rank_math_404_logs (id bigint(20) unsigned NOT NULL AUTO_INCREMENT, uri varchar(255) NOT NULL, accessed datetime NOT NULL, times_accessed bigint(20) unsigned NOT NULL DEFAULT 1, referer varchar(255) NOT NULL DEFAULT '', user_agent varchar(255) NOT NULL DEFAULT '', PRIMARY KEY (id), KEY uri (uri(191))) {$charset}");
		foreach (array_merge($targets, array_map('get_post', $state['d3x'])) as $target) {
			$permalink = get_permalink($target);
			$wpdb->insert($wpdb->prefix . 'yoast_indexable', array('permalink' => $permalink, 'permalink_hash' => strlen($permalink) . ':' . md5($permalink), 'object_id' => $target->ID, 'object_type' => 'post', 'object_sub_type' => $target->post_type));
		}
		foreach (array_slice($targets, 1) as $target) {
			$path = wp_parse_url(get_permalink($target), PHP_URL_PATH);
			$wpdb->insert($wpdb->prefix . 'redirection_404', array('created' => current_time('mysql'), 'url' => $path, 'http_code' => 404));
			// varchar(255): longer URLs are cut, as they are on real sites.
			$wpdb->insert($wpdb->prefix . 'rank_math_404_logs', array('uri' => $path, 'accessed' => current_time('mysql'), 'times_accessed' => 3));
		}

		file_put_contents(PLS_LAB_TRUTH, wp_json_encode(array(
			'made' => gmdate('c'),
			'wp_version' => $wp_version,
			'stylesheet' => get_stylesheet(),
			'd3_trigger' => $trigger,
			'objects' => pls_lab_snapshot_objects(),
		), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

		if ($trigger) {
			// Long URL Maker restores its backup rows on the next request after it sees a new WordPress version.
			update_option('kpcode_version_wp', '4.2.3');
		}
		pls_lab_out(array('truth_objects' => count(pls_lab_snapshot_objects()), 'trigger_armed' => $trigger));
		break;

	case 'check-d3':
		$state = get_option('pls_lab_state');
		$truth = json_decode((string) file_get_contents(PLS_LAB_TRUTH), true);
		$reverted = 0;
		foreach ($truth['objects'] as $object) {
			if ('post' === $object['type'] && in_array($object['id'], $state['d3x'], true)) {
				$now = get_post_field('post_name', $object['id'], 'raw');
				$reverted += ($now !== $object['clean_slug'] && in_array($now, $object['old_slugs'], true)) ? 1 : 0;
			}
		}
		pls_lab_out(array('kpcode_version_wp' => get_option('kpcode_version_wp'), 'restore_ran' => $wp_version === get_option('kpcode_version_wp'), 'reverted' => $reverted));
		break;

	case 'd1':
		deactivate_plugins(PLS_LAB_LEGACY, true);
		update_option('db_version', $wp_db_version - 1);
		pls_lab_out(array('legacy_active' => is_plugin_active(PLS_LAB_LEGACY), 'db_version' => (int) get_option('db_version')));
		break;

	case 'check-d1':
		pls_lab_out(array('db_version' => (int) get_option('db_version'), 'upgraded' => $wp_db_version === (int) get_option('db_version'), 'post_name' => pls_lab_column($wpdb->posts, 'post_name'), 'slug' => pls_lab_column($wpdb->terms, 'slug')));
		break;

	case 'reactivate':
		pls_lab_register_types();
		pls_lab_admin();
		$result = activate_plugin(PLS_LAB_LEGACY);
		pls_lab_out(array('ok' => !is_wp_error($result), 'post_name' => pls_lab_column($wpdb->posts, 'post_name')));
		break;

	case 'd2':
		pls_lab_register_types();
		pls_lab_admin();
		$state = get_option('pls_lab_state');
		$n = (int) $state['next_title'] + 20;
		$late = array();
		for ($i = 0; $i < 2; $i++) {
			$late[] = pls_lab_form_post(array('post_title' => pls_lab_title($n++)), 'late');
		}
		$truth = json_decode((string) file_get_contents(PLS_LAB_TRUTH), true);
		foreach ($late as $id) {
			$post = get_post($id);
			$truth['objects'][] = array('type' => 'post', 'id' => $id, 'subtype' => 'post', 'status' => 'publish', 'parent' => 0, 'title' => $post->post_title, 'clean_slug' => $post->post_name, 'old_slugs' => array(), 'group' => 'late');
		}
		file_put_contents(PLS_LAB_TRUTH, wp_json_encode($truth, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
		// The usual Deactivate link: Long URL Maker's deactivation routine runs.
		deactivate_plugins(PLS_LAB_LEGACY);
		pls_lab_out(array('legacy_active' => is_plugin_active(PLS_LAB_LEGACY), 'post_name' => pls_lab_column($wpdb->posts, 'post_name'), 'slug' => pls_lab_column($wpdb->terms, 'slug')));
		break;

	case 'after-restore':
		$moved = file_exists(pls_lab_guard_path() . '.lab-off');
		if ($moved && !rename(pls_lab_guard_path() . '.lab-off', pls_lab_guard_path())) {
			pls_lab_fail('Could not move the guard back.');
		}
		pls_lab_out(array('guard' => file_exists(pls_lab_guard_path()), 'ours_active' => is_plugin_active(PLS_LAB_OURS), 'legacy_active' => is_plugin_active(PLS_LAB_LEGACY)));
		break;

	default:
		pls_lab_fail("Unknown stage '{$stage}'.");
}
