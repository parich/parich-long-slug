/**
 * End-to-end on this site's real database (backed up first, restored at the end, also
 * when a step fails; a fingerprint proves the restore):
 *
 *   L1   WordPress database upgrade through upgrade.php (plugins not loaded) and
 *        through `wp core update-db` (plugins loaded): long slugs and the wide columns
 *        survive thanks to the guard — read straight from MySQL, before anything that
 *        loads WordPress could heal them, and with no heal recorded.
 *   L2   the same without the guard: the columns are shortened and the slugs cut
 *        (negative), then the next ordinary request heals both.
 *   L3   deactivating Parich Long Slug changes no slug and no column; the guard stays;
 *        its redirects stop.
 *   L12  repairs on damage lab snapshot B survive activating the real Long URL Maker,
 *        which is switched off again at once.
 *   L10  uninstall (files kept): this plugin's tables and options go; slugs, columns,
 *        Long URL Maker's table and the guard (long slugs remain) stay.
 *
 *   node tests/e2e/run-e2e.js
 */
const fs = require( 'fs' );
const path = require( 'path' );
const { config, WP_ROOT, wp, stage, getStable, getUntil, withMysql, restoreBackup } = require( '../damage-lab/lib' );

/* eslint-disable no-console */

const SNAPSHOT = path.join( __dirname, '..', 'damage-lab', 'damaged-deactivated.sql' );
const GUARD = path.join( WP_ROOT, 'wp-content', 'mu-plugins', 'parich-long-slug-guard.php' );
const PLUGIN_FILE = path.join( WP_ROOT, 'wp-content', 'plugins', 'parich-long-slug', 'parich-long-slug.php' );

function e2e( name, ...rest ) {
	const out = wp( [ 'eval-file', path.join( __dirname, 'stages.php' ), name, ...rest ] );
	const line = out.split( '\n' ).reverse().find( ( l ) => l.startsWith( 'PLS_E2E_JSON ' ) );
	if ( ! line ) {
		throw new Error( `stage ${ name } printed no result:\n${ out }` );
	}
	return JSON.parse( line.slice( 'PLS_E2E_JSON '.length ) );
}

/**
 * The database as it is, without loading WordPress (which would heal it).
 */
function raw() {
	return withMysql( ( mysql ) => {
		const q = ( sql ) => mysql( [ '-N', '-e', sql ] ).trim();
		return {
			dbVersion: Number( q( "SELECT option_value FROM wp_options WHERE option_name = 'db_version'" ) ),
			lastHeal: q( "SELECT MD5(option_value) FROM wp_options WHERE option_name = 'parich_slug_last_heal'" ),
			postName: q( "SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'wp_posts' AND COLUMN_NAME = 'post_name'" ),
			slug: q( "SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'wp_terms' AND COLUMN_NAME = 'slug'" ),
			lengths: q( "SELECT CHAR_LENGTH(p.post_name) FROM wp_posts p JOIN wp_postmeta m ON m.post_id = p.ID AND m.meta_key = '_pls_e2e' ORDER BY p.ID" )
				.split( /\s+/ )
				.filter( Boolean )
				.map( Number ),
		};
	} );
}

async function main() {
	if ( ! fs.existsSync( SNAPSHOT ) ) {
		throw new Error( 'No damage lab snapshot: run tests/damage-lab/run-lab.js first.' );
	}
	const before = stage( 'fingerprint' );
	const guardCopy = fs.readFileSync( GUARD );
	const stamp = new Date().toISOString().replace( /[:.]/g, '-' );
	const backup = path.join( config.backupDir, `before-e2e-${ stamp }.sql` );
	wp( [ 'db', 'export', backup, '--host=127.0.0.1', `--port=${ config.mysqlPort || 3306 }` ] );
	if ( ! fs.readFileSync( backup, 'utf8' ).includes( 'Dump completed' ) ) {
		throw new Error( `Backup ${ backup } is incomplete; nothing was changed.` );
	}
	console.log( `Backup: ${ backup }` );

	let passes = 0;
	const failures = [];
	const check = ( label, ok, detail = '' ) => {
		console.log( `  [${ ok ? 'PASS' : 'FAIL' }] ${ label }${ ok || '' === detail ? '' : ' — ' + detail }` );
		if ( ok ) {
			passes++;
		} else {
			failures.push( label );
		}
	};
	const upgradeThroughBrowser = async ( target ) => {
		const statuses = await getUntil( '/wp-admin/upgrade.php?step=upgrade_db', () => raw().dbVersion === target );
		return statuses.join( ', ' );
	};

	try {
		console.log( '\nSeed: long Thai slugs, saved for real' );
		const seed = e2e( 'seed' );
		check( `3 posts and a category with slugs over 200 bytes (${ seed.lengths.join( ', ' ) })`, 4 === seed.lengths.length && seed.lengths.every( ( n ) => n > 200 ) );
		const s0 = e2e( 'state' );
		// Reads MySQL credentials now: raw() must not load WordPress after an upgrade.
		const heal0 = raw().lastHeal;

		console.log( '\nL1: database upgrade through upgrade.php (plugins are not loaded there)' );
		e2e( 'arm' );
		console.log( `  upgrade.php answered ${ await upgradeThroughBrowser( s0.wp_db_version ) }` );
		let db = raw();
		check( 'straight from MySQL: columns still varchar(2000), slugs not cut, no heal needed', 'varchar(2000)' === db.postName && 'varchar(2000)' === db.slug && db.lengths.every( ( n ) => n > 200 ) && db.lastHeal === heal0, JSON.stringify( db ) );
		check( 'the seeded slugs are exactly as before', e2e( 'state' ).seeded === s0.seeded );

		console.log( '\nL1: database upgrade through `wp core update-db` (plugins are loaded)' );
		e2e( 'arm' );
		wp( [ 'core', 'update-db' ] );
		db = raw();
		check( 'straight from MySQL: columns still varchar(2000), slugs not cut, no heal needed', db.dbVersion === s0.wp_db_version && 'varchar(2000)' === db.postName && 'varchar(2000)' === db.slug && db.lengths.every( ( n ) => n > 200 ) && db.lastHeal === heal0, JSON.stringify( db ) );
		check( 'the seeded slugs are exactly as before', e2e( 'state' ).seeded === s0.seeded );

		console.log( '\nL2: the same upgrade without the guard' );
		fs.renameSync( GUARD, GUARD + '.e2e-off' );
		e2e( 'arm' );
		console.log( `  upgrade.php answered ${ await upgradeThroughBrowser( s0.wp_db_version ) }` );
		db = raw();
		if ( 'varchar(200)' === db.postName ) {
			check( 'negative: without the guard WordPress shortens the columns to 200 and cuts the slugs', 'varchar(200)' === db.slug && db.lengths.every( ( n ) => 200 === n ), JSON.stringify( db ) );
		} else {
			// Another request (an open admin tab's heartbeat, a visitor) loaded WordPress first and
			// healed at once: the heal record still shows what the upgrade did.
			const healed = e2e( 'state' ).last_heal || {};
			const before = Object.values( healed.lengths_before || {} );
			check( 'negative: without the guard WordPress shortened the columns to 200 and cut the slugs (healed at once by another request; from its record)', db.lastHeal !== heal0 && 2 === before.length && before.every( ( n ) => 200 === Number( n ) ) && healed.restored >= 4, JSON.stringify( healed ) );
		}
		const statuses = await getUntil( '/', () => 'varchar(2000)' === raw().postName );
		db = raw();
		const s3 = e2e( 'state' );
		check( `the next ordinary request (HTTP ${ statuses.join( ', ' ) }) widens the columns and puts every cut slug back`, 'varchar(2000)' === db.postName && 'varchar(2000)' === db.slug && s3.seeded === s0.seeded && s3.last_heal && s3.last_heal.restored >= 4, JSON.stringify( { db, heal: s3.last_heal } ) );
		fs.renameSync( GUARD + '.e2e-off', GUARD );
		check( 'guard back in place', e2e( 'state' ).guard );

		console.log( '\nL3: deactivating Parich Long Slug' );
		const mapped = e2e( 'map' );
		let res = await getStable( '/e2e-old-address/' );
		check( 'a redirect works while it is active', mapped.mapped && 301 === res.status && 'Parich Long Slug' === res.headers[ 'x-redirect-by' ], `${ res.status } ${ res.headers?.[ 'x-redirect-by' ] || '' }` );
		const slugsActive = e2e( 'slugs' );
		wp( [ 'plugin', 'deactivate', 'parich-long-slug' ] );
		const slugsOff = e2e( 'slugs' );
		check( 'deactivated: no slug and no column changed, the guard stays', slugsOff.hash === slugsActive.hash && 'varchar(2000)' === slugsOff.post_name && 'varchar(2000)' === slugsOff.slug && fs.existsSync( GUARD ) );
		res = await getStable( '/e2e-old-address/' );
		check( 'deactivated: its redirects stop (the readme and the Plugins screen say so)', 'Parich Long Slug' !== res.headers?.[ 'x-redirect-by' ], String( res.status ) );
		wp( [ 'plugin', 'activate', 'parich-long-slug' ] );

		console.log( '\nL12: repairs survive activating the real Long URL Maker' );
		withMysql( ( mysql ) => mysql( [], fs.readFileSync( SNAPSHOT ) ) );
		wp( [ 'plugin', 'activate', 'parich-long-slug' ] );
		wp( [ 'parich-slug', 'scan' ] );
		console.log( ' ', wp( [ 'parich-slug', 'repair', '--yes' ] ).split( '\n' ).pop() );
		const repaired = e2e( 'repaired' );
		const out = wp( [ 'plugin', 'activate', 'long-url-maker' ] );
		const after = e2e( 'state' );
		const still = e2e( 'repaired' );
		check( `activating Long URL Maker: switched off again at once, its restore did not run (${ repaired.count } repaired slugs unchanged)`, repaired.count > 0 && ! after.legacy_active && still.unchanged && 'varchar(2000)' === after.post_name, out.split( '\n' ).pop() );

		console.log( '\nL10: uninstall (plugin files kept)' );
		const slugsBefore = e2e( 'slugs' );
		wp( [ 'plugin', 'deactivate', 'parich-long-slug' ] );
		wp( [ 'plugin', 'uninstall', 'parich-long-slug', '--skip-delete' ] );
		const traces = e2e( 'traces' );
		const slugsAfter = e2e( 'slugs' );
		check( 'its tables and options are gone', ! traces.tables.length && ! traces.options.length, JSON.stringify( traces ) );
		check( 'slugs and columns unchanged; Long URL Maker\'s table kept', slugsAfter.hash === slugsBefore.hash && 'varchar(2000)' === slugsAfter.post_name && traces.kpcode_table );
		check( `the guard stays while slugs over 200 characters remain (${ slugsAfter.long })`, slugsAfter.long > 0 && traces.guard );
		check( 'the plugin files are still there (--skip-delete)', traces.plugin_file );
	} catch ( error ) {
		failures.push( error.message );
		console.error( '\nStep failed — restoring:', error.message );
	} finally {
		if ( fs.existsSync( GUARD + '.e2e-off' ) ) {
			fs.renameSync( GUARD + '.e2e-off', GUARD );
		}
		const dropped = restoreBackup( backup );
		if ( ! fs.existsSync( GUARD ) || ! fs.readFileSync( GUARD ).equals( guardCopy ) ) {
			fs.writeFileSync( GUARD, guardCopy );
			console.log( '  guard file put back' );
		}
		if ( ! fs.existsSync( PLUGIN_FILE ) ) {
			failures.push( 'plugin files are missing: copy them back from backups/plugin-copy-before-phase11' );
		}
		console.log( `\nRestored ${ path.basename( backup ) }; dropped: ${ dropped.join( ', ' ) || 'none' }` );
	}
	const after = stage( 'fingerprint' );
	const differences = Object.keys( before ).filter( ( key ) => before[ key ] !== after[ key ] );
	check( `database restored (${ Object.keys( before ).length } fingerprints)`, ! differences.length, differences.join( ', ' ) );
	console.log( `\n${ passes } passed, ${ failures.length } failed` );
	process.exit( failures.length || ! passes ? 1 : 0 );
}

main().catch( ( error ) => {
	console.error( error.message );
	process.exit( 1 );
} );
