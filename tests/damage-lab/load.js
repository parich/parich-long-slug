/**
 * Loads a damage lab snapshot into tables with another prefix, so tests can read the
 * damaged data without touching the site's own tables.
 *
 *   node tests/damage-lab/load.js <snapshot.sql> <prefix>     e.g. damaged-active.sql plslab_a_
 *   node tests/damage-lab/load.js --unload <prefix>
 *
 * The prefix must start with "plslab_" and end with "_".
 */
const fs = require( 'fs' );
const path = require( 'path' );
const { withMysql } = require( './lib' );

/* eslint-disable no-console */

function checkPrefix( prefix ) {
	if ( ! /^plslab_[a-z0-9_]*_$/.test( prefix || '' ) ) {
		throw new Error( `Prefix must look like plslab_a_ (got "${ prefix }")` );
	}
}

function tablesWith( mysql, prefix ) {
	return mysql( [ '-N', '-e', `SHOW TABLES LIKE '${ prefix.replace( /_/g, '\\_' ) }%'` ] )
		.split( /\r?\n/ )
		.filter( Boolean );
}

function unload( mysql, prefix ) {
	const tables = tablesWith( mysql, prefix );
	if ( tables.length ) {
		mysql( [ '-e', tables.map( ( t ) => `DROP TABLE \`${ t }\`;` ).join( ' ' ) ] );
	}
	return tables;
}

function main() {
	const args = process.argv.slice( 2 );
	if ( '--unload' === args[ 0 ] ) {
		checkPrefix( args[ 1 ] );
		withMysql( ( mysql ) => console.log( `Dropped ${ unload( mysql, args[ 1 ] ).length } tables with prefix ${ args[ 1 ] }` ) );
		return;
	}
	const [ file, prefix ] = args;
	checkPrefix( prefix );
	const dump = fs.readFileSync( path.resolve( file ), 'utf8' );
	if ( ! dump.includes( 'Dump completed' ) ) {
		throw new Error( `${ file } is not a complete dump` );
	}
	// Table names only appear quoted with backticks in mysqldump output.
	const renamed = dump.replace( /`wp_/g, '`' + prefix );
	withMysql( ( mysql ) => {
		unload( mysql, prefix );
		mysql( [], renamed );
		console.log( `Loaded ${ path.basename( file ) } as ${ tablesWith( mysql, prefix ).join( ', ' ) }` );
	} );
}

try {
	main();
} catch ( error ) {
	console.error( error.message );
	process.exit( 1 );
}
