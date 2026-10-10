/**
 * Runs every tests/php/*.php file with PHP and reports a summary.
 *
 * A file passes only when it exits 0 and prints the bootstrap summary with at least
 * one passed and no failed assertion. A run that finds no test file fails.
 *
 * --selftest runs tests/selftest/*.php instead: expect-pass-* files must pass, and
 * expect-fail-* files must fail with every " * Expect: <text>" line of their header
 * found in the output — failing for another reason (no database, a typo) does not
 * count. This proves the runner and bootstrap catch the failures they claim to.
 *
 * The PHP binary, its arguments and extra env vars come from environment
 * variables (PHP_BIN, PHP_ARGS) or tests/local-config.json
 * (see tests/local-config.example.json). No npm packages are needed.
 *
 * Usage: node tests/run-php-tests.js [<filter>]   e.g. node tests/run-php-tests.js legacy
 *        node tests/run-php-tests.js path/to/file.php
 *        node tests/run-php-tests.js --selftest
 */
const { spawnSync } = require( 'child_process' );
const fs = require( 'fs' );
const path = require( 'path' );

const configFile = path.join( __dirname, 'local-config.json' );
const config = fs.existsSync( configFile )
	? JSON.parse( fs.readFileSync( configFile, 'utf8' ) )
	: {};

const phpBin = process.env.PHP_BIN || config.phpBin || 'php';
const phpArgs = process.env.PHP_ARGS
	? process.env.PHP_ARGS.split( ' ' ).filter( Boolean )
	: config.phpArgs || [];
const env = { ...process.env, ...( config.env || {} ) };

// LocalWP's php.ini lists extensions that are not shipped for CLI; hide that startup noise only.
const STARTUP_NOISE =
	/PHP Startup: Unable to load dynamic library|Failed loading Zend extension/;
const SUMMARY = /^(\d+) passed, (\d+) failed$/m;

/* eslint-disable no-console */

/**
 * Runs one PHP test file and returns whether it passed and why not.
 *
 * @param {string} file Absolute path.
 * @return {{passed: boolean, reason: string, output: string}} Outcome.
 */
function runFile( file ) {
	const result = spawnSync( phpBin, [ ...phpArgs, file ], {
		env,
		encoding: 'utf8',
	} );
	if ( result.error ) {
		console.error( `Could not run ${ phpBin }: ${ result.error.message }` );
		process.exit( 2 );
	}
	process.stdout.write( result.stdout );
	const stderr = result.stderr
		.split( /\r?\n/ )
		.filter( ( line ) => line && ! STARTUP_NOISE.test( line ) )
		.join( '\n' );
	if ( stderr ) {
		process.stderr.write( stderr + '\n' );
	}

	const output = result.stdout + '\n' + stderr;
	const summary = result.stdout.match( SUMMARY );
	if ( result.status !== 0 ) {
		return { passed: false, reason: `exit code ${ result.status }`, output };
	}
	if ( ! summary ) {
		return {
			passed: false,
			reason: 'no summary (pls_test_done() not reached)',
			output,
		};
	}
	if ( Number( summary[ 1 ] ) === 0 || Number( summary[ 2 ] ) > 0 ) {
		return { passed: false, reason: summary[ 0 ], output };
	}
	return { passed: true, reason: summary[ 0 ], output };
}

function listFiles( dir, filter ) {
	return fs
		.readdirSync( dir )
		.filter( ( file ) => file.endsWith( '.php' ) )
		.filter( ( file ) => ! filter || file.includes( filter ) )
		.sort();
}

const arg = process.argv[ 2 ];

if ( arg === '--selftest' ) {
	const dir = path.join( __dirname, 'selftest' );
	const files = listFiles( dir );
	const expectFail = files.filter( ( f ) => f.startsWith( 'expect-fail-' ) );
	const expectPass = files.filter( ( f ) => f.startsWith( 'expect-pass-' ) );
	if ( ! expectFail.length || ! expectPass.length ) {
		console.log( 'Self-test needs expect-fail-* and expect-pass-* files.' );
		process.exit( 1 );
	}
	let wrong = 0;
	for ( const file of [ ...expectFail, ...expectPass ] ) {
		console.log( `\n=== ${ file } ===` );
		const shouldPass = file.startsWith( 'expect-pass-' );
		const source = fs.readFileSync( path.join( dir, file ), 'utf8' );
		const expects = [ ...source.matchAll( /^\s*\*\s*Expect:\s*(.+?)\s*$/gm ) ].map(
			( m ) => m[ 1 ]
		);
		const { passed, reason, output } = runFile( path.join( dir, file ) );
		const missing = expects.filter(
			( text ) => ! ( output + '\n' + reason ).includes( text )
		);
		let ok;
		if ( shouldPass ) {
			ok = passed;
		} else {
			ok = ! passed && expects.length > 0 && missing.length === 0;
		}
		if ( ! ok ) {
			wrong++;
		}
		console.log(
			`${ ok ? 'AS EXPECTED' : 'UNEXPECTED' }: ${ file } ${
				passed ? 'passed' : 'failed'
			} (${ reason })${
				! shouldPass && ! expects.length ? ' — no Expect: line' : ''
			}${ missing.length ? ` — missing: ${ missing.join( ' | ' ) }` : '' }`
		);
	}
	console.log(
		`\nRunner self-test: ${ expectFail.length + expectPass.length - wrong }/${
			expectFail.length + expectPass.length
		} files behaved as expected`
	);
	process.exit( wrong ? 1 : 0 );
}

let testDir = path.join( __dirname, 'php' );
let files;
if ( arg && arg.endsWith( '.php' ) && fs.existsSync( arg ) ) {
	testDir = path.dirname( path.resolve( arg ) );
	files = [ path.basename( arg ) ];
} else {
	files = listFiles( testDir, arg );
}

if ( ! files.length ) {
	console.log( 'No PHP tests found — a run that checks nothing fails.' );
	process.exit( 1 );
}

let failed = 0;
for ( const file of files ) {
	console.log( `\n=== ${ file } ===` );
	const { passed, reason } = runFile( path.join( testDir, file ) );
	if ( ! passed ) {
		failed++;
		console.log( `FAILED: ${ file } (${ reason })` );
	}
}

console.log(
	`\n${ files.length - failed }/${ files.length } PHP test files passed`
);
process.exit( failed ? 1 : 0 );
