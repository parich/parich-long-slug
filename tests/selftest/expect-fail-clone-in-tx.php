<?php
/**
 * Runner self-test: copying a table inside a transaction is refused.
 * Expect: called inside a transaction
 * Expect: [PASS] pls_test_clone_table() returns false inside a transaction
 *
 * @package Parich_Long_Slug
 */

require __DIR__ . '/../bootstrap.php';

global $wpdb;
pls_test_assert('pls_test_clone_table() returns false inside a transaction', false === pls_test_clone_table($wpdb->terms));
pls_test_done();
