<?php
/**
 * Stages of the end-to-end checks (run by run-e2e.js only, after a full backup):
 *   wp eval-file stages.php <stage> [<arg>]
 *
 *   seed     posts and a term with long Thai slugs, saved for real (meta _pls_e2e)
 *   state    columns, db_version, guard, and a hash of the seeded slugs
 *   arm      db_version and the version this plugin last checked set one lower, as if
 *            WordPress had just been updated and its database not yet
 *   map      a redirect from /e2e-old-address/ to the first seeded post
 *   slugs    hash of every slug on the site (posts and terms)
 *   repaired post IDs and slugs written by the last repair
 *   traces   what of this plugin is left (tables, options), and Long URL Maker's table
 *
 * @package Parich_Long_Slug
 */

if (!defined('WP_CLI')) {
	exit;
}
require_once ABSPATH . 'wp-admin/includes/plugin.php';

function pls_e2e_out($data)
{
	WP_CLI::line('PLS_E2E_JSON ' . wp_json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function pls_e2e_column($table, $column)
{
	global $wpdb;
	return $wpdb->get_var($wpdb->prepare('SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s', $table, $column));
}

function pls_e2e_seeded()
{
	global $wpdb;
	$posts = $wpdb->get_results("SELECT p.ID, p.post_name FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_pls_e2e' ORDER BY p.ID");
	$terms = $wpdb->get_results("SELECT t.term_id, t.slug FROM {$wpdb->terms} t JOIN {$wpdb->termmeta} m ON m.term_id = t.term_id AND m.meta_key = '_pls_e2e' ORDER BY t.term_id");
	return array($posts, $terms);
}

global $wpdb, $wp_db_version;
$stage = isset($args[0]) ? $args[0] : '';

switch ($stage) {
	case 'seed':
		$admins = get_users(array('role' => 'administrator', 'number' => 1));
		wp_set_current_user($admins[0]->ID);
		$titles = array(
			'มหาวิทยาลัยราชภัฏมหาสารคามประกาศผลการคัดเลือกบุคคลเพื่อบรรจุและแต่งตั้งเป็นพนักงานมหาวิทยาลัยสายวิชาการ',
			'คณะเทคโนโลยีสารสนเทศจัดอบรมเชิงปฏิบัติการการพัฒนาเว็บไซต์ด้วยเวิร์ดเพรสสำหรับบุคลากรสายสนับสนุนวิชาการ',
			'สำนักส่งเสริมวิชาการและงานทะเบียนแจ้งกำหนดการลงทะเบียนเรียนภาคเรียนที่สองปีการศึกษาสองพันห้าร้อยหกสิบเก้า',
		);
		$ids = array();
		foreach ($titles as $title) {
			$id = wp_insert_post(array('post_title' => $title, 'post_status' => 'publish', 'post_type' => 'post', 'post_content' => 'E2E'), true);
			if (is_wp_error($id)) {
				WP_CLI::error($id->get_error_message());
			}
			update_post_meta($id, '_pls_e2e', 1);
			$ids[] = $id;
		}
		$term = wp_insert_term('หมวดข่าวประชาสัมพันธ์และกิจกรรมของมหาวิทยาลัยราชภัฏมหาสารคามประจำปีการศึกษา', 'category');
		if (is_wp_error($term)) {
			WP_CLI::error($term->get_error_message());
		}
		update_term_meta($term['term_id'], '_pls_e2e', 1);
		list($posts, $terms) = pls_e2e_seeded();
		pls_e2e_out(array('posts' => wp_list_pluck($posts, 'ID'), 'term' => (int) $term['term_id'], 'lengths' => array_merge(array_map('strlen', wp_list_pluck($posts, 'post_name')), array_map('strlen', wp_list_pluck($terms, 'slug')))));
		break;

	case 'state':
		list($posts, $terms) = pls_e2e_seeded();
		pls_e2e_out(array(
			'db_version' => (int) get_option('db_version'),
			'wp_db_version' => (int) $wp_db_version,
			'checked' => (int) get_option('parich_slug_checked_db_version'),
			'post_name' => pls_e2e_column($wpdb->posts, 'post_name'),
			'slug' => pls_e2e_column($wpdb->terms, 'slug'),
			'guard' => file_exists(WPMU_PLUGIN_DIR . '/parich-long-slug-guard.php'),
			'active' => is_plugin_active('parich-long-slug/parich-long-slug.php'),
			'legacy_active' => is_plugin_active('long-url-maker/long_url_maker.php'),
			'seeded' => md5(serialize(array($posts, $terms))),
			'lengths' => array_merge(array_map('strlen', wp_list_pluck($posts, 'post_name')), array_map('strlen', wp_list_pluck($terms, 'slug'))),
			'last_heal' => get_option('parich_slug_last_heal'),
		));
		break;

	case 'arm':
		update_option('db_version', $wp_db_version - 1);
		update_option('parich_slug_checked_db_version', $wp_db_version - 1);
		pls_e2e_out(array('db_version' => (int) get_option('db_version')));
		break;

	case 'map':
		list($posts) = pls_e2e_seeded();
		$result = Parich_Slug_Repair_Admin::map('/e2e-old-address/', (string) $posts[0]->ID);
		pls_e2e_out(array('mapped' => !is_wp_error($result), 'target' => get_permalink($posts[0]->ID)));
		break;

	case 'slugs':
		pls_e2e_out(array(
			'hash' => md5(serialize(array($wpdb->get_results("SELECT ID, post_name FROM {$wpdb->posts} ORDER BY ID", ARRAY_N), $wpdb->get_results("SELECT term_id, slug FROM {$wpdb->terms} ORDER BY term_id", ARRAY_N)))),
			'post_name' => pls_e2e_column($wpdb->posts, 'post_name'),
			'slug' => pls_e2e_column($wpdb->terms, 'slug'),
			'long' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE CHAR_LENGTH(post_name) > 200"),
		));
		break;

	case 'repaired':
		$rows = $wpdb->get_results("SELECT object_type, object_id, new_slug FROM {$wpdb->prefix}parich_slug_repair_log WHERE status = 'applied' ORDER BY id");
		$now = array();
		foreach ($rows as $row) {
			$now[] = 'post' === $row->object_type ? get_post_field('post_name', (int) $row->object_id, 'raw') : get_term((int) $row->object_id)->slug;
		}
		pls_e2e_out(array('count' => count($rows), 'unchanged' => wp_list_pluck($rows, 'new_slug') === $now));
		break;

	case 'traces':
		$tables = $wpdb->get_col("SHOW TABLES LIKE '{$wpdb->prefix}parich\\_slug\\_%'");
		$options = $wpdb->get_col("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'parich\\_slug\\_%'");
		pls_e2e_out(array(
			'tables' => $tables,
			'options' => $options,
			'kpcode_table' => (bool) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->prefix . 'kpcode_url_posts')),
			'plugin_file' => file_exists(WP_PLUGIN_DIR . '/parich-long-slug/parich-long-slug.php'),
			'guard' => file_exists(WPMU_PLUGIN_DIR . '/parich-long-slug-guard.php'),
		));
		break;

	default:
		WP_CLI::error("Unknown stage '{$stage}'.");
}
