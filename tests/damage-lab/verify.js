/**
 * Loads damage lab snapshots under a lab prefix, checks them with verify-lab.php
 * against their ground truth, then drops the lab tables again.
 *
 *   node tests/damage-lab/verify.js            A and B (and the negative control if its snapshot exists)
 *   node tests/damage-lab/verify.js --keep     leave the plslab_* tables loaded
 */
const fs = require( 'fs' );
const path = require( 'path' );
const { spawnSync } = require( 'child_process' );
const { wp } = require( './lib' );

/* eslint-disable no-console */

const keep = process.argv.includes( '--keep' );
const runs = [
	[ 'damaged-active', 'plslab_a_', 'A' ],
	[ 'damaged-deactivated', 'plslab_b_', 'B' ],
	[ 'damaged-active-no-d3', 'plslab_n_', 'A-no-d3' ],
].filter( ( [ name ] ) => fs.existsSync( path.join( __dirname, `${ name }.sql` ) ) );

function load( ...args ) {
	const result = spawnSync( process.execPath, [ path.join( __dirname, 'load.js' ), ...args ], { encoding: 'utf8' } );
	if ( result.status !== 0 ) {
		throw new Error( result.stderr || result.stdout );
	}
}

let failed = 0;
for ( const [ name, prefix, scenario ] of runs ) {
	console.log( `\n=== ${ name } (scenario ${ scenario }) ===` );
	load( path.join( __dirname, `${ name }.sql` ), prefix );
	try {
		console.log(
			wp( [
				'eval-file',
				path.join( __dirname, 'verify-lab.php' ),
				prefix,
				path.join( __dirname, `${ name }.truth.json` ),
				scenario,
			] )
		);
	} catch ( error ) {
		failed++;
		console.log( error.message );
	} finally {
		if ( ! keep ) {
			load( '--unload', prefix );
		}
	}
}
if ( ! runs.length ) {
	console.log( 'No snapshots: run tests/damage-lab/run-lab.js first.' );
	process.exit( 1 );
}
console.log( `\n${ runs.length - failed }/${ runs.length } snapshots as expected` );
process.exit( failed ? 1 : 0 );
