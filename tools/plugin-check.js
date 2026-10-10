/**
 * Runs Plugin Check on the files of parich-long-slug.zip (unpacked to a temporary
 * folder, as WordPress.org gets them), not on this development folder, and lists
 * what it reports.
 *
 *   node tools/build-zip.js && node tools/plugin-check.js [--warnings]
 *
 * Exit 1 when Plugin Check reports an ERROR (or does not run).
 */
const fs = require( 'fs' );
const os = require( 'os' );
const path = require( 'path' );
const { spawnSync } = require( 'child_process' );
const { wp } = require( '../tests/damage-lab/lib' );

/* eslint-disable no-console */

const SLUG = 'parich-long-slug';
const zip = path.join( __dirname, '..', `${ SLUG }.zip` );
const TAR = path.join( process.env.SystemRoot || 'C:\\Windows', 'System32', 'tar.exe' );
const showWarnings = process.argv.includes( '--warnings' );

if ( ! fs.existsSync( zip ) ) {
	console.error( 'No zip: run node tools/build-zip.js first.' );
	process.exit( 1 );
}
const dir = fs.mkdtempSync( path.join( os.tmpdir(), 'pls-check-' ) );
let csv;
try {
	const unpacked = spawnSync( TAR, [ '-x', '-f', zip, '-C', dir ], { encoding: 'utf8' } );
	if ( unpacked.status !== 0 ) {
		throw new Error( `tar failed: ${ unpacked.stderr }` );
	}
	csv = wp( [ 'plugin', 'check', path.join( dir, SLUG ), `--slug=${ SLUG }`, '--include-experimental', '--format=csv', '--fields=line,type,code,message' ] );
} catch ( error ) {
	// Plugin Check exits non-zero when it finds errors; its report is in the output.
	csv = String( error.message );
} finally {
	fs.rmSync( dir, { recursive: true, force: true } );
}

const counts = { ERROR: 0, WARNING: 0 };
let file = '';
let ran = false;
for ( const line of csv.split( /\r?\n/ ) ) {
	if ( line.startsWith( 'FILE: ' ) ) {
		file = line.slice( 6 ).split( new RegExp( `[\\\\/]${ SLUG }[\\\\/]` ) ).pop().replace( /\\/g, '/' );
		ran = true;
		continue;
	}
	if ( /^line,type,code/.test( line ) ) {
		ran = true;
	}
	const m = line.match( /^(\d+),(ERROR|WARNING),([^,]+),(.*)$/ );
	if ( m ) {
		counts[ m[ 2 ] ]++;
		if ( 'ERROR' === m[ 2 ] || showWarnings ) {
			console.log( `${ m[ 2 ] } ${ file }:${ m[ 1 ] } ${ m[ 3 ] } — ${ m[ 4 ].replace( /^"|"$/g, '' ).slice( 0, 140 ) }` );
		}
	}
}
if ( ! ran && /Success: Checks complete\. No errors found\./.test( csv ) ) {
	ran = true;
}
console.log( `Plugin Check on the unpacked zip: ${ counts.ERROR } errors, ${ counts.WARNING } warnings` );
process.exit( ! ran || counts.ERROR ? 1 : 0 );
