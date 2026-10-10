<?php
/**
 * Runner self-test: table copies and manual transactions work, and nothing persists.
 *
 * @package Parich_Long_Slug
 */

define('PLS_TEST_MANUAL_TX', true);
require __DIR__ . '/../bootstrap.php';

global $wpdb;
$clone = pls_test_clone_table($wpdb->terms);
$rows = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->terms}");
pls_test_assert('copy of the terms table has the same rows', $clone && $rows === (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$clone}`"));

pls_test_begin();
$wpdb->insert($clone, array('name' => 'pls selftest', 'slug' => 'pls-selftest', 'term_group' => 0));
pls_test_assert('row inserted inside the transaction is visible', 1 === (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$clone}` WHERE slug = 'pls-selftest'"));
pls_test_rollback();
pls_test_assert('row is gone after pls_test_rollback()', 0 === (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$clone}` WHERE slug = 'pls-selftest'"));
pls_test_assert('no sentinel option left behind', 0 === (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE 'pls\_test\_sentinel\_%'"));

pls_test_done();
