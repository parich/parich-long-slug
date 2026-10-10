/**
 * Repairs every damage lab snapshot in a rolled-back transaction and checks the result
 * with eval-apply.php (slugs, log, shadow, Long URL Maker's backup rows, redirects,
 * edge cases, undo). Each snapshot is loaded fresh, so earlier runs cannot help.
 *
 *   node tests/damage-lab/apply-lab.js
 *   PLS_NEGATIVE=no-redirects node tests/damage-lab/apply-lab.js    must fail
 */
const fs = require( 'fs' );
const path = require( 'path' );
const { spawnSync } = require( 'child_process' );
const { wp } = require( './lib' );

/* eslint-disable no-console */

const runs = [
	[ 'damaged-active', 'plslab_a_', 'A' ],
	[ 'damaged-deactivated', 'plslab_b_', 'B' ],
].filter( ( [ name ] ) => fs.existsSync( path.join( __dirname, `${ name }.sql` ) ) );

function load( ...args ) {
	const result = spawnSync( process.execPath, [ path.join( __dirname, 'load.js' ), ...args ], { encoding: 'utf8' } );
	if ( result.status !== 0 ) {
		throw new Error( result.stderr || result.stdout );
	}
}

if ( ! runs.length ) {
	console.log( 'No snapshots: run tests/damage-lab/run-lab.js first.' );
	process.exit( 1 );
}
let failed = 0;
let checks = 0;
for ( const [ name, prefix, scenario ] of runs ) {
	console.log( `\n=== ${ name } (scenario ${ scenario }) ===` );
	load( path.join( __dirname, `${ name }.sql` ), prefix );
	try {
		const output = wp( [ 'eval-file', path.join( __dirname, 'eval-apply.php' ), prefix, path.join( __dirname, `${ name }.truth.json` ), scenario ] );
		const lines = output.split( '\n' );
		console.log( lines.filter( ( line ) => ! line.startsWith( 'PLS_APPLY_JSON' ) ).join( '\n' ) );
		const json = lines.find( ( line ) => line.startsWith( 'PLS_APPLY_JSON ' ) );
		const result = json ? JSON.parse( json.slice( 'PLS_APPLY_JSON '.length ) ) : null;
		if ( ! result || ! result.passes || result.failures.length ) {
			failed++;
		} else {
			checks += result.passes;
		}
	} catch ( error ) {
		failed++;
		console.log( error.message.split( '\n' ).filter( ( line ) => ! line.startsWith( 'PLS_APPLY_JSON' ) ).join( '\n' ) );
	} finally {
		load( '--unload', prefix );
	}
}
console.log( `\n${ runs.length - failed }/${ runs.length } snapshots repaired as expected (${ checks } checks)` );
process.exit( failed ? 1 : 0 );
