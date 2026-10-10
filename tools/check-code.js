/**
 * Runs tools/check-code.php (L7 SQL and PHP 7.4 syntax) with the PHP binary from
 * tests/local-config.json, first its self-test, then on the shipped files.
 *
 *   node tools/check-code.js [files…]
 */
const fs = require( 'fs' );
const path = require( 'path' );
const { spawnSync } = require( 'child_process' );

/* eslint-disable no-console */

const config = JSON.parse( fs.readFileSync( path.join( __dirname, '..', 'tests', 'local-config.json' ), 'utf8' ) );
const php = ( args ) =>
	spawnSync( config.phpBin, [ ...( config.phpArgs || [] ), '-d', 'display_startup_errors=0', path.join( __dirname, 'check-code.php' ), ...args ], { encoding: 'utf8', stdio: 'inherit' } ).status;

const selftest = php( [ '--selftest' ] );
const status = 0 === selftest ? php( process.argv.slice( 2 ) ) : 1;
process.exit( selftest || status ? 1 : 0 );
