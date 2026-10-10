<?php
/**
 * Runner self-test: a COMMIT inside the test transaction leaves the sentinel row
 * behind, which must fail the file (and the sentinel is removed again).
 * Expect: The transaction was committed before ROLLBACK
 *
 * @package Parich_Long_Slug
 */

require __DIR__ . '/../bootstrap.php';

global $wpdb;
$wpdb->query('COMMIT');
pls_test_assert('ran a COMMIT inside the test transaction', true);
pls_test_done();
