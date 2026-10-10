/**
 * Shared helpers for the damage lab: WP-CLI, HTTP to the local site, mysql.
 * Paths come from tests/local-config.json (phpBin, phpArgs, wpCli, mysqlBin,
 * backupDir, env.WP_TEST_HOST).
 */
const { spawnSync } = require( 'child_process' );
const fs = require( 'fs' );
const http = require( 'http' );
const os = require( 'os' );
const path = require( 'path' );

const config = JSON.parse(
	fs.readFileSync( path.join( __dirname, '..', 'local-config.json' ), 'utf8' )
);
const WP_ROOT = path.resolve( __dirname, '../../../../..' );
const HOST = ( config.env && config.env.WP_TEST_HOST ) || 'localhost';
const STARTUP_NOISE =
	/PHP Startup: Unable to load dynamic library|Failed loading Zend extension|Failed to get current (SQL modes|character set)|is not recognized as an internal or external command|operable program or batch file/;

for ( const key of [ 'phpBin', 'wpCli', 'mysqlBin', 'backupDir' ] ) {
	if ( ! config[ key ] ) {
		throw new Error( `tests/local-config.json needs "${ key }"` );
	}
}

const env = {
	...process.env,
	...( config.env || {} ),
	PATH: config.mysqlBin + path.delimiter + process.env.PATH,
};

function clean( text ) {
	return String( text || '' )
		.split( /\r?\n/ )
		.filter( ( line ) => line && ! STARTUP_NOISE.test( line ) )
		.join( '\n' );
}

/**
 * Runs WP-CLI; throws with its output when it fails.
 */
function wp( args, input ) {
	const result = spawnSync(
		config.phpBin,
		[ ...( config.phpArgs || [] ), config.wpCli, ...args ],
		{ cwd: WP_ROOT, env, encoding: 'utf8', input, maxBuffer: 64 * 1024 * 1024 }
	);
	if ( result.error || result.status !== 0 ) {
		throw new Error(
			`wp ${ args.join( ' ' ) } failed (${ result.status }):\n${ clean(
				result.stdout
			) }\n${ clean( result.stderr ) }`
		);
	}
	return clean( result.stdout );
}

/**
 * Runs a stage of create-damage.php and returns its JSON line.
 */
function stage( name, ...rest ) {
	const out = wp( [
		'eval-file',
		path.join( __dirname, 'create-damage.php' ),
		name,
		...rest,
	] );
	const line = out
		.split( '\n' )
		.reverse()
		.find( ( l ) => l.startsWith( 'PLS_LAB_JSON ' ) );
	if ( ! line ) {
		throw new Error( `stage ${ name } printed no result:\n${ out }` );
	}
	return JSON.parse( line.slice( 'PLS_LAB_JSON '.length ) );
}

function get( urlPath ) {
	return new Promise( ( resolve ) => {
		const req = http.get(
			{ host: HOST, path: urlPath, timeout: 120000 },
			( res ) => {
				let body = '';
				res.setEncoding( 'utf8' );
				res.on( 'data', ( chunk ) => ( body += chunk ) );
				res.on( 'end', () => resolve( { status: res.statusCode, headers: res.headers, body } ) );
			}
		);
		req.on( 'error', ( error ) => resolve( { status: 0, body: error.message } ) );
		req.on( 'timeout', () => req.destroy( new Error( 'timeout' ) ) );
	} );
}

/**
 * GET that does not follow redirects, repeated while LocalWP answers 502 (or nothing).
 */
async function getStable( urlPath, attempts = 6 ) {
	let res;
	for ( let i = 0; i < attempts; i++ ) {
		res = await get( urlPath );
		if ( 502 !== res.status && 0 !== res.status ) {
			break;
		}
	}
	return res;
}

/**
 * Imports a full backup and drops the tables it does not have (lab snapshots excepted).
 *
 * @return {string[]} Tables dropped.
 */
function restoreBackup( backup ) {
	const keep = new Set( dumpTables( backup ) );
	return withMysql( ( mysql ) => {
		mysql( [], fs.readFileSync( backup ) );
		const extra = mysql( [ '-N', '-e', 'SHOW TABLES' ] )
			.split( /\r?\n/ )
			.filter( ( t ) => t && ! keep.has( t ) && ! t.startsWith( 'plslab_' ) );
		if ( extra.length ) {
			mysql( [ '-e', extra.map( ( t ) => 'DROP TABLE `' + t + '`;' ).join( ' ' ) ] );
		}
		return extra;
	} );
}

/**
 * GET until done() says the request had its effect. LocalWP's php-cgi on this
 * machine drops some requests (502), so a single failed GET proves nothing.
 */
async function getUntil( urlPath, done, attempts = 5 ) {
	const statuses = [];
	for ( let i = 0; i < attempts; i++ ) {
		const res = await get( urlPath );
		statuses.push( res.status );
		if ( done() ) {
			return statuses;
		}
	}
	throw new Error( `GET ${ urlPath } never took effect (statuses ${ statuses.join( ', ' ) })` );
}

/**
 * mysql client options file with this site's credentials, deleted by the caller.
 */
let mysqlCreds = null;
function withMysql( callback ) {
	// Read once: reading them loads WordPress (and this plugin, which may heal what a
	// check wants to see untouched), so later calls must not.
	if ( ! mysqlCreds ) {
		mysqlCreds = JSON.parse(
			wp( [ 'eval', 'echo wp_json_encode(array(DB_USER, DB_PASSWORD, DB_NAME));' ] )
				.split( '\n' )
				.pop()
		);
	}
	const creds = mysqlCreds;
	const cnf = path.join( os.tmpdir(), `pls-lab-${ process.pid }.cnf` );
	fs.writeFileSync(
		cnf,
		`[client]\nuser=${ creds[ 0 ] }\npassword=${ creds[ 1 ] }\nhost=127.0.0.1\nport=${
			config.mysqlPort || 3306
		}\ndefault-character-set=utf8mb4\n`,
		{ mode: 0o600 }
	);
	try {
		return callback( ( args, input ) => {
			const result = spawnSync(
				path.join( config.mysqlBin, 'mysql' ),
				[ `--defaults-extra-file=${ cnf }`, ...args, creds[ 2 ] ],
				{ env, input, encoding: 'utf8', maxBuffer: 256 * 1024 * 1024 }
			);
			if ( result.error || result.status !== 0 ) {
				throw new Error( `mysql ${ args.join( ' ' ) } failed: ${ clean( result.stderr ) }` );
			}
			return result.stdout;
		} );
	} finally {
		fs.rmSync( cnf, { force: true } );
	}
}

/**
 * Table names a dump creates.
 */
function dumpTables( file ) {
	const names = [];
	for ( const match of fs
		.readFileSync( file, 'utf8' )
		.matchAll( /^CREATE TABLE `([^`]+)`/gm ) ) {
		names.push( match[ 1 ] );
	}
	return names;
}

module.exports = { config, WP_ROOT, wp, stage, get, getStable, getUntil, withMysql, dumpTables, restoreBackup };
