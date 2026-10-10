/**
 * Runs the repair scan on every damage lab snapshot and checks it against the ground
 * truth with eval-scan.php: "auto" never wrong, nothing damaged missed, nothing
 * undamaged flagged, and the snapshot tables unchanged.
 *
 *   node tests/damage-lab/scan-lab.js           A, B and the negative control
 *   node tests/damage-lab/scan-lab.js --list    also one line per object
 *   node tests/damage-lab/scan-lab.js --keep    leave the plslab_* tables loaded
 */
const fs = require( 'fs' );
const path = require( 'path' );
const { spawnSync } = require( 'child_process' );
const { wp } = require( './lib' );

/* eslint-disable no-console */

const keep = process.argv.includes( '--keep' );
const list = process.argv.includes( '--list' );
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

if ( ! runs.length ) {
	console.log( 'No snapshots: run tests/damage-lab/run-lab.js first.' );
	process.exit( 1 );
}
let failed = 0;
const summary = [];
for ( const [ name, prefix, scenario ] of runs ) {
	console.log( `\n=== ${ name } (scenario ${ scenario }) ===` );
	load( path.join( __dirname, `${ name }.sql` ), prefix );
	try {
		const output = wp( [
			'eval-file',
			path.join( __dirname, 'eval-scan.php' ),
			prefix,
			path.join( __dirname, `${ name }.truth.json` ),
			scenario,
			...( list ? [ 'list' ] : [] ),
		] );
		console.log( output.split( '\n' ).filter( ( line ) => ! line.startsWith( 'PLS_EVAL_JSON' ) ).join( '\n' ) );
		const json = output.split( '\n' ).find( ( line ) => line.startsWith( 'PLS_EVAL_JSON ' ) );
		if ( json ) {
			summary.push( JSON.parse( json.slice( 'PLS_EVAL_JSON '.length ) ) );
		}
	} catch ( error ) {
		failed++;
		console.log( error.message.split( '\n' ).filter( ( line ) => ! line.startsWith( 'PLS_EVAL_JSON' ) ).join( '\n' ) );
	} finally {
		if ( ! keep ) {
			load( '--unload', prefix );
		}
	}
}
const total = summary.reduce(
	( sum, run ) => {
		sum.auto += run.stats.auto;
		sum.autoRight += run.stats.auto_right;
		sum.damaged += run.stats.damaged;
		sum.right += run.stats.right;
		sum.rightAuto += run.stats.right_auto;
		return sum;
	},
	{ auto: 0, autoRight: 0, damaged: 0, right: 0, rightAuto: 0 }
);
console.log(
	`\nAll snapshots: auto right ${ total.autoRight }/${ total.auto }, right proposal ${ total.right }/${ total.damaged }, right and auto ${ total.rightAuto }/${ total.damaged }`
);
console.log( `${ runs.length - failed }/${ runs.length } snapshots as expected` );
process.exit( failed || ! summary.length ? 1 : 0 );
