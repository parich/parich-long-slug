/**
 * Images for the WordPress.org directory, written to .wordpress-org/ (SVN /assets; not in
 * the zip), rendered with Edge or Chrome headless (tools/browser.js):
 *
 *   node tools/wporg-assets.js art           icon-128x128.png, icon-256x256.png, icon.svg,
 *                                           banner-772x250.png, banner-1544x500.png from tools/art/
 *   node tools/wporg-assets.js screenshots   screenshot-1…3.png of the admin pages, in English,
 *                                           on damage lab snapshot B
 *
 * "screenshots" never changes the site's own tables: snapshot B is loaded under the prefix
 * plslab_s_, scanned, rendered and repaired there (tests/damage-lab/render-repair.php), and
 * those tables are dropped at the end. The pictures are the site's real admin pages (a
 * temporary login session, destroyed at the end) with the repair tab's content from the
 * snapshot put in; the toolbar, menu and footer are hidden.
 */
const fs = require( 'fs' );
const path = require( 'path' );
const { pathToFileURL } = require( 'url' );
const { launch } = require( './browser' );

/* eslint-disable no-console */

const ROOT = path.join( __dirname, '..' );
const OUT = path.join( ROOT, '.wordpress-org' );
const ART = path.join( __dirname, 'art' );

async function art() {
	fs.mkdirSync( OUT, { recursive: true } );
	fs.copyFileSync( path.join( ART, 'icon.svg' ), path.join( OUT, 'icon.svg' ) );
	const page = await launch();
	try {
		await page.transparent();
		// The SVG has a viewBox and no size: opened on its own it fills the viewport.
		for ( const size of [ 128, 256 ] ) {
			await page.viewport( 128, 128, size / 128 );
			await page.open( pathToFileURL( path.join( ART, 'icon.svg' ) ).href );
			await page.screenshot( path.join( OUT, `icon-${ size }x${ size }.png` ) );
		}
		for ( const scale of [ 1, 2 ] ) {
			await page.viewport( 772, 250, scale );
			await page.open( pathToFileURL( path.join( ART, 'banner.html' ) ).href );
			await page.screenshot( path.join( OUT, `banner-${ 772 * scale }x${ 250 * scale }.png` ) );
		}
	} finally {
		await page.close();
	}
	for ( const file of fs.readdirSync( OUT ).sort() ) {
		console.log( `  .wordpress-org/${ file } (${ fs.statSync( path.join( OUT, file ) ).size } bytes)` );
	}
}

// Admin pages without the toolbar, the menu and the footer (no user name, no other plugins).
const BARE = `(() => {
	const style = document.createElement('style');
	style.textContent = '#wpadminbar, #adminmenumain, #wpfooter, #screen-meta-links { display: none !important; } html.wp-toolbar { padding-top: 0 !important; } #wpcontent { margin-left: 0 !important; }';
	document.head.appendChild(style);
	return true;
})()`;

/**
 * Rectangle (page coordinates) from the top of the first element to the bottom of the
 * last one, across the width of .wrap, with a margin.
 */
const region = ( from, to ) => `(() => {
	const wrap = document.querySelector('.wrap').getBoundingClientRect();
	const first = (${ from })();
	const last = (${ to })();
	if (!first || !last) { return null; }
	const top = first.getBoundingClientRect().top + scrollY - 12;
	const bottom = last.getBoundingClientRect().bottom + scrollY + 16;
	return { x: Math.max(0, wrap.left - 16), y: Math.max(0, top), width: wrap.width + 32, height: bottom - top };
})()`;

const heading = ( text ) => `() => [...document.querySelectorAll('.wrap h2')].find((h) => h.textContent.trim() === ${ JSON.stringify( text ) })`;

async function screenshots() {
	const { wp } = require( '../tests/damage-lab/lib' );
	const { spawnSync } = require( 'child_process' );
	const lab = path.join( ROOT, 'tests', 'damage-lab' );
	const snapshot = path.join( lab, 'damaged-deactivated.sql' );
	if ( ! fs.existsSync( snapshot ) ) {
		throw new Error( 'No snapshot: run tests/damage-lab/run-lab.js first.' );
	}
	const PREFIX = 'plslab_s_';
	const load = ( ...args ) => {
		const result = spawnSync( process.execPath, [ path.join( lab, 'load.js' ), ...args ], { encoding: 'utf8' } );
		if ( 0 !== result.status ) {
			throw new Error( `load.js ${ args.join( ' ' ) }: ${ result.stderr || result.stdout }` );
		}
	};
	const html = fs.mkdtempSync( path.join( require( 'os' ).tmpdir(), 'pls-shots-' ) );
	try {
		load( snapshot, PREFIX );
		console.log( wp( [ 'eval-file', path.join( lab, 'render-repair.php' ), PREFIX, html ] ).replace( /^/gm, '  ' ) );
	} finally {
		load( '--unload', PREFIX );
	}

	const admin = `${ wp( [ 'option', 'get', 'siteurl' ] ).trim() }/wp-admin/`;
	const session = JSON.parse( wp( [ 'eval', "$u = get_users(array('role' => 'administrator', 'number' => 1))[0]; $e = time() + 1800; $t = WP_Session_Tokens::get_instance($u->ID)->create($e); echo wp_json_encode(array('user' => $u->ID, 'token' => $t, 'cookies' => array(LOGGED_IN_COOKIE => rawurlencode(wp_generate_auth_cookie($u->ID, $e, 'logged_in', $t)), AUTH_COOKIE => rawurlencode(wp_generate_auth_cookie($u->ID, $e, 'auth', $t)))));" ] ) );
	const page = await launch();
	try {
		await page.viewport( 1140, 900, 1 );
		await page.cookies( admin, Object.entries( session.cookies ).map( ( [ name, value ] ) => `${ name }=${ value }` ).join( '; ' ) );
		// LocalWP's php-cgi drops a request now and then: open until the page is there.
		const open = async ( url ) => {
			for ( let i = 0; i < 6; i++ ) {
				await page.open( admin + url );
				if ( await page.eval( '!!document.querySelector(".wrap h1")' ) ) {
					await page.eval( BARE );
					return;
				}
			}
			throw new Error( `${ url } did not load` );
		};
		const shoot = async ( name, from, to ) => {
			const clip = await page.eval( region( from, to ) );
			if ( ! clip ) {
				throw new Error( `${ name }: section not found` );
			}
			await page.screenshot( path.join( OUT, name ), clip );
			console.log( `  .wordpress-org/${ name } (${ Math.round( clip.width ) }×${ Math.round( clip.height ) })` );
		};
		// The repair tab's content from the snapshot in place of the site's.
		const tab = ( file ) => `(() => {
			const wrap = document.querySelector('.wrap');
			const nav = wrap.querySelector('.nav-tab-wrapper');
			while (nav.nextSibling) { nav.nextSibling.remove(); }
			nav.insertAdjacentHTML('afterend', ${ JSON.stringify( fs.readFileSync( path.join( html, file ), 'utf8' ) ) });
			return true;
		})()`;

		// 1. Settings, with a Thai title in the preview.
		await open( 'options-general.php?page=parich-long-slug' );
		await page.eval( `(() => {
			const input = document.getElementById('parich-slug-preview-title');
			input.value = 'วิธีปลูกผักสวนครัวในกระถางสำหรับคอนโดและบ้านที่มีพื้นที่จำกัด ฉบับเริ่มต้นสำหรับมือใหม่ ทำได้จริงภายในหนึ่งเดือน';
			input.dispatchEvent(new Event('input', { bubbles: true }));
			return true;
		})()` );
		for ( let i = 0; i < 20 && ! ( await page.eval( 'document.getElementById("parich-slug-preview-slug").textContent.length > 0' ) ); i++ ) {
			await new Promise( ( resolve ) => setTimeout( resolve, 300 ) );
		}
		await page.eval( '(document.activeElement && document.activeElement.blur(), true)' );
		await shoot( 'screenshot-1.png', '() => document.querySelector(".wrap h1")', '() => document.querySelector(".parich-slug-support").previousElementSibling' );

		// 2. Scan results; 3. after repairing the items marked ready: the repair (with undo) and its redirects.
		await open( 'options-general.php?page=parich-long-slug&tab=repair' );
		await page.eval( tab( 'repair-scan.html' ) );
		// Four rows with Thai titles that name no real university (the lab's titles are made up
		// after a university's news); the others are taken out of the table.
		const kept = await page.eval( `(() => {
			const rows = [...document.querySelectorAll('.parich-slug-results tbody tr')];
			const keep = rows.filter((tr) => {
				const title = tr.querySelector('td:nth-child(2)');
				return title && /[ก-๙]/.test(title.textContent) && !/มหาวิทยาลัย|ราชภัฏ/.test(tr.textContent);
			}).slice(0, 4);
			rows.forEach((tr) => { if (!keep.includes(tr)) { tr.remove(); } });
			return keep.length;
		})()` );
		if ( kept < 4 ) {
			throw new Error( `screenshot-2.png: only ${ kept } rows to show` );
		}
		await shoot( 'screenshot-2.png', '() => document.querySelector(".wrap h1")', '() => { const rows = document.querySelectorAll(".parich-slug-results tbody tr"); return rows[rows.length - 1]; }' );
		await page.eval( tab( 'repair-done.html' ) );
		// The first six redirects; the table then ends on its own border.
		const redirects = `(() => { let el = (${ heading( 'Redirects' ) })(); while (el && el.tagName !== 'TABLE') { el = el.nextElementSibling; } return el; })()`;
		await page.eval( `(() => { const rows = [...${ redirects }.querySelectorAll('tbody tr')]; rows.slice(6).forEach((tr) => tr.remove()); return true; })()` );
		await shoot( 'screenshot-3.png', heading( 'Repairs made' ), `() => ${ redirects }` );
	} finally {
		await page.close();
		wp( [ 'eval', `WP_Session_Tokens::get_instance(${ Number( session.user ) })->destroy('${ String( session.token ).replace( /[^A-Za-z0-9]/g, '' ) }'); echo 'session destroyed';` ] );
		fs.rmSync( html, { recursive: true, force: true } );
	}
}

const mode = process.argv[ 2 ];
const run = { art, screenshots }[ mode ];
if ( ! run ) {
	console.error( 'Usage: node tools/wporg-assets.js art|screenshots' );
	process.exit( 1 );
}
run().catch( ( error ) => {
	console.error( error.message );
	process.exit( 1 );
} );
