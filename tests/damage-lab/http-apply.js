/**
 * End-to-end over HTTP: puts damage lab snapshot B (Long URL Maker deactivated) into
 * this site's real tables, activates Parich Long Slug, scans and repairs with WP-CLI,
 * then requests every old address through the web server (nginx + php-cgi): each must
 * answer 301 "Parich Long Slug" to its repaired address, which must answer 200. After
 * undo, none may be redirected by this plugin. The whole database is backed up first
 * and restored at the end (also when a step fails); a fingerprint proves the restore.
 *
 *   node tests/damage-lab/http-apply.js
 */
const fs = require( 'fs' );
const path = require( 'path' );
const { config, wp, stage, getStable, withMysql, restoreBackup } = require( './lib' );

/* eslint-disable no-console */

const SNAPSHOT = path.join( __dirname, 'damaged-deactivated.sql' );

function parseCsv( text ) {
	const rows = [];
	for ( const line of text.replace( /^﻿/, '' ).split( /\r?\n/ ).filter( Boolean ) ) {
		const cells = [];
		let cell = '';
		let quoted = false;
		for ( let i = 0; i < line.length; i++ ) {
			const c = line[ i ];
			if ( quoted ) {
				if ( '"' === c && '"' === line[ i + 1 ] ) {
					cell += '"';
					i++;
				} else if ( '"' === c ) {
					quoted = false;
				} else {
					cell += c;
				}
			} else if ( '"' === c ) {
				quoted = true;
			} else if ( ',' === c ) {
				cells.push( cell );
				cell = '';
			} else {
				cell += c;
			}
		}
		cells.push( cell );
		rows.push( cells );
	}
	const header = rows.shift();
	return rows.map( ( cells ) => Object.fromEntries( header.map( ( key, i ) => [ key, cells[ i ] ] ) ) );
}

// Request path of a URL as a browser sends it; comparable form of a URL.
const requestPath = ( url ) => new URL( url ).pathname;
const comparable = ( url ) => decodeURIComponent( new URL( url ).pathname.replace( /%(?![0-9a-f]{2})/gi, '%25' ) ).toLowerCase();

async function checkRedirects( redirects, expectRedirect ) {
	const problems = [];
	let checked = 0;
	let served = 0;
	for ( const row of redirects ) {
		const res = await getStable( requestPath( row.source_url ) );
		const ours = 'Parich Long Slug' === res.headers?.[ 'x-redirect-by' ];
		checked++;
		if ( ! expectRedirect ) {
			if ( ours ) {
				problems.push( `still redirected: ${ row.source_url }` );
			}
			continue;
		}
		// Redirects apply to 404s only: an old slug another item still has (left for
		// review) is that item's address until it is repaired too.
		if ( 200 === res.status ) {
			served++;
			continue;
		}
		if ( 301 !== res.status || ! ours || comparable( new URL( res.headers.location, row.source_url ).href ) !== comparable( row.target_url ) ) {
			problems.push( `${ row.source_url } → ${ res.status } ${ res.headers?.location || '' } (want 301 to ${ row.target_url })` );
			continue;
		}
		const target = await getStable( requestPath( row.target_url ) );
		if ( 200 !== target.status ) {
			problems.push( `${ row.target_url } → ${ target.status } (want 200)` );
		}
	}
	return { checked, served, problems };
}

async function main() {
	if ( ! fs.existsSync( SNAPSHOT ) ) {
		throw new Error( 'No snapshot: run tests/damage-lab/run-lab.js first.' );
	}
	const before = stage( 'fingerprint' );
	const stamp = new Date().toISOString().replace( /[:.]/g, '-' );
	const backup = path.join( config.backupDir, `before-http-apply-${ stamp }.sql` );
	wp( [ 'db', 'export', backup, '--host=127.0.0.1', `--port=${ config.mysqlPort || 3306 }` ] );
	if ( ! fs.readFileSync( backup, 'utf8' ).includes( 'Dump completed' ) ) {
		throw new Error( `Backup ${ backup } is incomplete; nothing was changed.` );
	}
	console.log( `Backup: ${ backup }` );

	const failures = [];
	let passes = 0;
	const check = ( label, ok, detail = '' ) => {
		console.log( `  [${ ok ? 'PASS' : 'FAIL' }] ${ label }${ ok || ! detail ? '' : ' — ' + detail }` );
		if ( ok ) {
			passes++;
		} else {
			failures.push( label );
		}
	};
	try {
		console.log( '\nSnapshot B into the real tables' );
		withMysql( ( mysql ) => mysql( [], fs.readFileSync( SNAPSHOT ) ) );
		console.log( ' ', wp( [ 'plugin', 'activate', 'parich-long-slug' ] ).split( '\n' ).pop() );
		console.log( ' ', wp( [ 'parich-slug', 'scan' ] ).split( '\n' ).pop() );
		const repaired = wp( [ 'parich-slug', 'repair', '--yes' ] ).split( '\n' ).pop();
		console.log( ' ', repaired );
		const batch = ( repaired.match( /Repair (\d+):/ ) || [] )[ 1 ];
		check( 'WP-CLI repaired the items marked ready', !! batch && / [1-9]\d* slugs repaired/.test( repaired ), repaired );

		const redirects = parseCsv( wp( [ 'parich-slug', 'redirects', '--format=csv' ] ) ).filter( ( row ) => '301' === row.status );
		console.log( '\nAfter the repair' );
		let result = await checkRedirects( redirects, true );
		check( `every old address answers 301 "Parich Long Slug" to its repaired address, which answers 200 (${ result.checked - result.served } redirected; ${ result.served } still another item's address)`, result.checked - result.served > 0 && ! result.problems.length, result.problems.slice( 0, 3 ).join( '; ' ) );
		const home = await getStable( '/' );
		check( 'the front page answers 200', 200 === home.status, String( home.status ) );
		const missing = await getStable( '/no-such-page-pls-http/' );
		check( 'an unknown address still answers 404', 404 === missing.status, String( missing.status ) );

		console.log( '\nAfter undo' );
		console.log( ' ', wp( [ 'parich-slug', 'undo', batch, '--yes' ] ).split( '\n' ).pop() );
		result = await checkRedirects( redirects, false );
		check( `no old address is redirected by Parich Long Slug any more (${ result.checked } addresses)`, ! result.problems.length, result.problems.slice( 0, 3 ).join( '; ' ) );
	} catch ( error ) {
		failures.push( error.message );
		console.error( '\nStep failed — restoring:', error.message );
	} finally {
		const dropped = restoreBackup( backup );
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
