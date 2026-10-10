<?php
/**
 * Runner self-test: a failed assertion must fail the file.
 * Expect: [FAIL] this assertion is meant to fail
 *
 * @package Parich_Long_Slug
 */

require __DIR__ . '/../bootstrap.php';

pls_test_assert('this assertion is meant to fail', false);
pls_test_done();
