/**
 * Builds languages/parich-long-slug-th.po from languages/parich-long-slug.pot and
 * tools/th-strings.js, and checks the translations:
 *   - every string of the template is translated, and nothing in th-strings.js is unused;
 *   - every placeholder (%s, %d, %1$s…) of the original is in the translation, and the
 *     translation has no other "%" (sprintf() would fail on it).
 *
 *   node tools/make-po.js            write the .po (exit 1 on any problem)
 *   node tools/make-po.js --check    only check
 *
 * Regenerate the template first after changing strings:
 *   wp i18n make-pot . languages/parich-long-slug.pot --slug=parich-long-slug --domain=parich-long-slug --exclude=tests,docs,tools,languages
 */
const fs = require( 'fs' );
const path = require( 'path' );
const thai = require( './th-strings' );

/* eslint-disable no-console */

const root = path.join( __dirname, '..' );
const pot = fs.readFileSync( path.join( root, 'languages', 'parich-long-slug.pot' ), 'utf8' );
const blocks = pot.split( /\r?\n\r?\n/ );
const header = blocks.shift();
const quote = ( text ) => JSON.stringify( text ).replace( /\\u([0-9a-f]{4})/g, ( m, hex ) => String.fromCharCode( parseInt( hex, 16 ) ) );
const placeholders = ( text ) => ( text.match( /%(\d+\$)?[sd]/g ) || [] ).sort().join( ' ' );

function read( lines, prefix ) {
	const i = lines.findIndex( ( l ) => l.startsWith( prefix + ' "' ) );
	if ( i < 0 ) {
		return null;
	}
	let text = JSON.parse( lines[ i ].slice( prefix.length + 1 ) );
	for ( let j = i + 1; j < lines.length && lines[ j ].startsWith( '"' ); j++ ) {
		text += JSON.parse( lines[ j ] );
	}
	return text;
}

const problems = [];
const used = new Set();
const out = [];
for ( const block of blocks ) {
	const lines = block.split( /\r?\n/ );
	const id = read( lines, 'msgid' );
	if ( null === id ) {
		continue;
	}
	const plural = read( lines, 'msgid_plural' );
	const comments = lines.filter( ( l ) => l.startsWith( '#' ) );
	const translation = thai[ id ];
	if ( undefined === translation ) {
		problems.push( `untranslated: ${ id }` );
		continue;
	}
	used.add( id );
	if ( placeholders( translation ) !== placeholders( id ) ) {
		problems.push( `placeholders differ: ${ id } → ${ translation }` );
	}
	if ( /%(?!(\d+\$)?[sd]|%)/.test( translation ) ) {
		problems.push( `stray "%": ${ translation }` );
	}
	const entry = [ ...comments, `msgid ${ quote( id ) }` ];
	if ( null !== plural ) {
		// Thai has one form: it must fit the singular and the plural.
		if ( placeholders( plural ) !== placeholders( translation ) ) {
			problems.push( `placeholders differ from the plural: ${ plural } → ${ translation }` );
		}
		entry.push( `msgid_plural ${ quote( plural ) }`, `msgstr[0] ${ quote( translation ) }` );
	} else {
		entry.push( `msgstr ${ quote( translation ) }` );
	}
	out.push( entry.join( '\n' ) );
}
for ( const id of Object.keys( thai ) ) {
	if ( ! used.has( id ) ) {
		problems.push( `not in the template (removed from the code?): ${ id }` );
	}
}

const meta = header
	.replace( /^# Copyright.*\r?\n# This file.*\r?\n/, '' )
	.replace( /"Last-Translator: .*\\n"/, '"Last-Translator: Parich Suriya\\n"' )
	.replace( /"Language-Team: .*\\n"/, '"Language-Team: Thai\\n"' )
	.replace( /"PO-Revision-Date: .*\\n"/, `"PO-Revision-Date: ${ new Date().toISOString().slice( 0, 16 ).replace( 'T', ' ' ) }+0000\\n"\n"Language: th\\n"` )
	.replace( /"X-Domain: parich-long-slug\\n"/, '"X-Domain: parich-long-slug\\n"\n"Plural-Forms: nplurals=1; plural=0;\\n"' );

console.log( `${ used.size } strings translated, ${ problems.length } problems` );
problems.forEach( ( p ) => console.log( '  ' + p ) );
if ( ! process.argv.includes( '--check' ) && ! problems.length ) {
	fs.writeFileSync( path.join( root, 'languages', 'parich-long-slug-th.po' ), [ meta, ...out ].join( '\n\n' ) + '\n' );
	console.log( 'Wrote languages/parich-long-slug-th.po' );
}
process.exit( problems.length || ! used.size ? 1 : 0 );
