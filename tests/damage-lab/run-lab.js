/**
 * Damage lab: lets the real Long URL Maker 2.0.1 damage slugs on this site's
 * database, saves the damaged state, then restores the database.
 *
 *   node tests/damage-lab/run-lab.js               snapshots A and B
 *   node tests/damage-lab/run-lab.js --no-d3        negative control: snapshot after the upgrade, with
 *                                                   neither of Long URL Maker's restores (so no D3)
 *
 * Snapshot A "still active": after a WordPress database upgrade cut long slugs and
 * Long URL Maker, activated again, restored what its backup table had (D1, D3, D4, D5).
 * Snapshot B "deactivated": after its deactivation routine rewrote every slug from
 * the title and shrank the columns (D1, D2, D4, D6, D7).
 *
 * The whole database is backed up to backupDir first and restored at the end, also
 * when a step fails; a fingerprint of every slug, title and post meta row proves it.
 * Nothing done on the local site while this runs survives the restore.
 */
const fs = require( 'fs' );
const path = require( 'path' );
const { config, wp, stage, getUntil, withMysql, dumpTables } = require( './lib' );

/* eslint-disable no-console */

const noD3 = process.argv.includes( '--no-d3' );
const out = ( name ) => path.join( __dirname, name );
const SNAPSHOT_TABLES = [
	'posts',
	'postmeta',
	'terms',
	'termmeta',
	'term_taxonomy',
	'term_relationships',
	'options',
	'kpcode_url_posts',
	'yoast_indexable',
	'redirection_404',
	'rank_math_404_logs',
].map( ( t ) => 'wp_' + t );

function exportTables( file ) {
	wp( [
		'db',
		'export',
		file,
		`--tables=${ SNAPSHOT_TABLES.join( ',' ) }`,
		'--host=127.0.0.1',
		`--port=${ config.mysqlPort || 3306 }`,
	] );
	console.log( `  saved ${ path.basename( file ) } (${ fs.statSync( file ).size } bytes)` );
}

function restore( backup ) {
	console.log( '\nRestore' );
	const keep = new Set( dumpTables( backup ) );
	withMysql( ( mysql ) => {
		mysql( [], fs.readFileSync( backup ) );
		const extra = mysql( [ '-N', '-e', 'SHOW TABLES' ] )
			.split( /\r?\n/ )
			.filter( ( t ) => t && ! keep.has( t ) && ! t.startsWith( 'plslab_' ) );
		if ( extra.length ) {
			mysql( [ '-e', extra.map( ( t ) => `DROP TABLE \`${ t }\`;` ).join( ' ' ) ] );
		}
		console.log( `  imported ${ path.basename( backup ) }; dropped lab tables: ${ extra.join( ', ' ) || 'none' }` );
	} );
	console.log( '  after restore:', JSON.stringify( stage( 'after-restore' ) ) );
}

async function main() {
	console.log( 'Preflight' );
	const pre = stage( 'preflight' );
	console.log( ' ', JSON.stringify( pre ) );
	if ( ! pre.ok ) {
		throw new Error( 'Preflight failed: ' + pre.problems.join( '; ' ) );
	}
	const before = stage( 'fingerprint' );

	const stamp = new Date().toISOString().replace( /[:.]/g, '-' );
	const backup = path.join( config.backupDir, `before-lab-${ stamp }.sql` );
	wp( [ 'db', 'export', backup, '--host=127.0.0.1', `--port=${ config.mysqlPort || 3306 }` ] );
	if ( ! fs.readFileSync( backup, 'utf8' ).includes( 'Dump completed' ) ) {
		throw new Error( `Backup ${ backup } is incomplete; nothing was changed.` );
	}
	console.log( `Backup: ${ backup }` );

	let failure = null;
	try {
		console.log( '\nLab' );
		console.log( '  prepare:', JSON.stringify( stage( 'prepare', backup ) ) );
		console.log( '  seed (Long URL Maker active):', JSON.stringify( stage( 'seed' ) ) );
		console.log( '  d3 (block editor slug edits, clean ground truth):', JSON.stringify( stage( 'd3', noD3 ? 'no-trigger' : 'trigger' ) ) );
		let d3;
		if ( noD3 ) {
			d3 = stage( 'check-d3' );
		} else {
			const statuses = await getUntil( '/', () => ( d3 = stage( 'check-d3' ) ).restore_ran );
			console.log( `  page load (statuses ${ statuses.join( ', ' ) }) ran its restore` );
		}
		console.log( '  check-d3:', JSON.stringify( d3 ) );
		console.log( '  d1 (silently off, db_version - 1):', JSON.stringify( stage( 'd1' ) ) );
		let d1;
		const statuses = await getUntil( '/wp-admin/upgrade.php?step=upgrade_db', () => ( d1 = stage( 'check-d1' ) ).upgraded );
		console.log( `  database upgrade (statuses ${ statuses.join( ', ' ) }):`, JSON.stringify( d1 ) );
		if ( 'varchar(200)' !== d1.post_name ) {
			throw new Error( 'The upgrade did not shrink wp_posts.post_name; is the guard really off?' );
		}
		// Its activation restores the backup rows too, which would also revert the
		// block-editor slug edits, so the negative control stops before it.
		if ( ! noD3 ) {
			console.log( '  reactivate (its activation restores its backup rows):', JSON.stringify( stage( 'reactivate' ) ) );
		}
		const a = noD3 ? 'damaged-active-no-d3' : 'damaged-active';
		exportTables( out( `${ a }.sql` ) );
		fs.copyFileSync( out( 'ground-truth.json' ), out( `${ a }.truth.json` ) );
		if ( ! noD3 ) {
			console.log( '  d2 (usual deactivation):', JSON.stringify( stage( 'd2' ) ) );
			exportTables( out( 'damaged-deactivated.sql' ) );
			fs.copyFileSync( out( 'ground-truth.json' ), out( 'damaged-deactivated.truth.json' ) );
		}
	} catch ( error ) {
		failure = error;
		console.error( '\nLab step failed — restoring:', error.message );
	} finally {
		restore( backup );
	}

	const after = stage( 'fingerprint' );
	const differences = Object.keys( before ).filter( ( key ) => before[ key ] !== after[ key ] );
	console.log(
		differences.length
			? `\nRESTORE CHECK FAILED: ${ differences.join( ', ' ) } differ from before the lab`
			: `\nRestore check: ${ Object.keys( before ).length } fingerprints identical (${ Object.entries( before )
					.map( ( [ k, v ] ) => `${ k } ${ v.split( ':' )[ 0 ] }` )
					.join( ', ' ) })`
	);
	if ( failure || differences.length ) {
		process.exit( 1 );
	}
}

main().catch( ( error ) => {
	console.error( error.message );
	process.exit( 1 );
} );
