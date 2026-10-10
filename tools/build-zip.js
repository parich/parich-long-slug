/**
 * Builds parich-long-slug.zip for WordPress.org: the main file, uninstall.php,
 * readme.txt, includes/ and assets/ under a parich-long-slug/ folder — no tests, docs,
 * tools, translation files or git files — then lists it and prints its SHA-256.
 *
 *   node tools/build-zip.js
 *
 * Uses Windows' own tar.exe (bsdtar) for the zip, so entry names use "/".
 */
const crypto = require( 'crypto' );
const fs = require( 'fs' );
const os = require( 'os' );
const path = require( 'path' );
const { spawnSync } = require( 'child_process' );

/* eslint-disable no-console */

const SLUG = 'parich-long-slug';
const root = path.join( __dirname, '..' );
// What ships: the "files" list of package.json (one list for this script and the precheck).
const SHIP = JSON.parse( fs.readFileSync( path.join( root, 'package.json' ), 'utf8' ) ).files;
const FORBIDDEN = /(^|\/)(tests|docs|tools|languages|node_modules|\.git[^/]*|CLAUDE\.md)(\/|$)|\.(po|mo|pot|l10n\.php|zip|sql|log)$/;
const TAR = path.join( process.env.SystemRoot || 'C:\\Windows', 'System32', 'tar.exe' );
const zip = path.join( root, `${ SLUG }.zip` );

const stage = fs.mkdtempSync( path.join( os.tmpdir(), 'pls-zip-' ) );
try {
	for ( const item of SHIP ) {
		fs.cpSync( path.join( root, item ), path.join( stage, SLUG, item ), { recursive: true } );
	}
	fs.rmSync( zip, { force: true } );
	const made = spawnSync( TAR, [ '-a', '-c', '-f', zip, '-C', stage, SLUG ], { encoding: 'utf8' } );
	if ( made.status !== 0 ) {
		throw new Error( `tar failed: ${ made.stderr }` );
	}
} finally {
	fs.rmSync( stage, { recursive: true, force: true } );
}

const listed = spawnSync( TAR, [ '-t', '-f', zip ], { encoding: 'utf8' } );
const entries = listed.stdout.split( /\r?\n/ ).filter( Boolean );
const files = entries.filter( ( e ) => ! e.endsWith( '/' ) );
const problems = [];
if ( ! files.includes( `${ SLUG }/${ SLUG }.php` ) ) {
	problems.push( `no ${ SLUG }/${ SLUG }.php` );
}
for ( const entry of entries ) {
	if ( ! entry.startsWith( `${ SLUG }/` ) || entry.includes( '\\' ) ) {
		problems.push( `bad path: ${ entry }` );
	}
	if ( FORBIDDEN.test( entry.slice( SLUG.length + 1 ) ) ) {
		problems.push( `should not ship: ${ entry }` );
	}
}
const size = fs.statSync( zip ).size;
if ( size > 10 * 1024 * 1024 ) {
	problems.push( `larger than 10 MB (${ size } bytes)` );
}
const sha = crypto.createHash( 'sha256' ).update( fs.readFileSync( zip ) ).digest( 'hex' );
console.log( files.map( ( f ) => '  ' + f ).join( '\n' ) );
console.log( `${ path.basename( zip ) }: ${ files.length } files, ${ size } bytes, SHA-256 ${ sha }` );
problems.forEach( ( p ) => console.log( '  PROBLEM ' + p ) );
process.exit( problems.length ? 1 : 0 );
