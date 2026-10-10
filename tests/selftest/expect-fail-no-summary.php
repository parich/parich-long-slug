<?php
/**
 * Runner self-test: a file that exits 0 without pls_test_done() must fail.
 * Expect: [PASS] passes, but the file never reaches pls_test_done()
 * Expect: no summary (pls_test_done() not reached)
 *
 * @package Parich_Long_Slug
 */

require __DIR__ . '/../bootstrap.php';

pls_test_assert('passes, but the file never reaches pls_test_done()', true);
exit(0);
