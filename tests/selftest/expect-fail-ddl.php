<?php
/**
 * Runner self-test: DDL inside the transaction is blocked and fails the file.
 * Expect: DDL blocked inside a transaction
 * Expect: [PASS] the blocked CREATE TABLE did not run
 *
 * @package Parich_Long_Slug
 */

require __DIR__ . '/../bootstrap.php';

global $wpdb;
$table = $wpdb->prefix . 'pls_selftest_ddl';
$wpdb->query("CREATE TABLE {$table} (id int)");
pls_test_assert('the blocked CREATE TABLE did not run', null === $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)));
pls_test_done();
