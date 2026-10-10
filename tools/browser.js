/**
 * A small Chrome DevTools Protocol client: starts Edge (or Chrome) headless, opens one
 * page and takes PNG screenshots of a page, an element or the whole viewport.
 * Node 22+ (global WebSocket). Used by tools/wporg-assets.js.
 */
const { spawn } = require( 'child_process' );
const fs = require( 'fs' );
const os = require( 'os' );
const path = require( 'path' );

const CANDIDATES = [
	process.env.PLS_BROWSER,
	'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe',
	'C:\\Program Files\\Microsoft\\Edge\\Application\\msedge.exe',
	'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',
	'/usr/bin/google-chrome',
	'/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
].filter( Boolean );

const sleep = ( ms ) => new Promise( ( resolve ) => setTimeout( resolve, ms ) );

async function launch() {
	const binary = CANDIDATES.find( ( file ) => fs.existsSync( file ) );
	if ( ! binary ) {
		throw new Error( 'No Edge or Chrome found (set PLS_BROWSER).' );
	}
	const profile = fs.mkdtempSync( path.join( os.tmpdir(), 'pls-browser-' ) );
	const port = 9300 + Math.floor( Math.random() * 500 );
	const child = spawn( binary, [ '--headless=new', `--remote-debugging-port=${ port }`, `--user-data-dir=${ profile }`, '--no-first-run', '--no-default-browser-check', '--hide-scrollbars', '--disable-extensions', '--force-color-profile=srgb', 'about:blank' ], { stdio: 'ignore' } );
	let target;
	for ( let i = 0; i < 50 && ! target; i++ ) {
		await sleep( 200 );
		try {
			const list = await ( await fetch( `http://127.0.0.1:${ port }/json/list` ) ).json();
			target = list.find( ( t ) => 'page' === t.type );
		} catch ( e ) {
			// not up yet
		}
	}
	if ( ! target ) {
		child.kill();
		throw new Error( 'The browser did not start.' );
	}
	const ws = new WebSocket( target.webSocketDebuggerUrl );
	await new Promise( ( resolve, reject ) => {
		ws.onopen = resolve;
		ws.onerror = reject;
	} );
	let id = 0;
	const pending = new Map();
	const listeners = [];
	ws.onmessage = ( message ) => {
		const data = JSON.parse( message.data );
		if ( data.id && pending.has( data.id ) ) {
			const { resolve, reject } = pending.get( data.id );
			pending.delete( data.id );
			if ( data.error ) {
				reject( new Error( `${ data.error.message } (${ data.error.code })` ) );
			} else {
				resolve( data.result );
			}
		} else if ( data.method ) {
			listeners.slice().forEach( ( listener ) => listener( data ) );
		}
	};
	const send = ( method, params = {} ) =>
		new Promise( ( resolve, reject ) => {
			const n = ++id;
			pending.set( n, { resolve, reject } );
			ws.send( JSON.stringify( { id: n, method, params } ) );
		} );
	const once = ( method, timeout = 30000 ) =>
		new Promise( ( resolve, reject ) => {
			const timer = setTimeout( () => reject( new Error( `timeout waiting for ${ method }` ) ), timeout );
			const listener = ( data ) => {
				if ( data.method === method ) {
					clearTimeout( timer );
					listeners.splice( listeners.indexOf( listener ), 1 );
					resolve( data.params );
				}
			};
			listeners.push( listener );
		} );
	await send( 'Page.enable' );
	await send( 'Network.enable' );

	const page = {
		/** Viewport in CSS pixels; scale 2 gives a twice as large PNG. */
		viewport: ( width, height, scale = 1 ) => send( 'Emulation.setDeviceMetricsOverride', { width, height, deviceScaleFactor: scale, mobile: false } ),
		transparent: () => send( 'Emulation.setDefaultBackgroundColorOverride', { color: { r: 0, g: 0, b: 0, a: 0 } } ),
		/** Cookies from a Cookie header value ("a=1; b=2") for a site; they stay when the site sets its own. */
		cookies: ( url, header ) =>
			send( 'Network.setCookies', {
				cookies: header.split( /;\s*/ ).filter( Boolean ).map( ( pair ) => {
					const at = pair.indexOf( '=' );
					return { url, name: pair.slice( 0, at ), value: pair.slice( at + 1 ) };
				} ),
			} ),
		async open( url ) {
			const loaded = once( 'Page.loadEventFired', 60000 );
			const result = await send( 'Page.navigate', { url } );
			if ( result.errorText ) {
				throw new Error( `${ url }: ${ result.errorText }` );
			}
			await loaded;
			await page.eval( 'document.fonts ? document.fonts.ready.then(() => true) : true' );
			await sleep( 300 );
		},
		async eval( expression ) {
			const result = await send( 'Runtime.evaluate', { expression, awaitPromise: true, returnByValue: true } );
			if ( result.exceptionDetails ) {
				throw new Error( `${ expression.slice( 0, 80 ) }: ${ result.exceptionDetails.exception?.description || result.exceptionDetails.text }` );
			}
			return result.result.value;
		},
		/** PNG of the viewport, or of a rectangle {x, y, width, height} in CSS pixels. */
		async screenshot( file, clip ) {
			const params = { format: 'png', captureBeyondViewport: true };
			if ( clip ) {
				params.clip = { ...clip, scale: 1 };
			}
			const { data } = await send( 'Page.captureScreenshot', params );
			fs.writeFileSync( file, Buffer.from( data, 'base64' ) );
		},
		async close() {
			try {
				await send( 'Browser.close' );
			} catch ( e ) {
				// already gone
			}
			ws.close();
			child.kill();
			await sleep( 300 );
			fs.rmSync( profile, { recursive: true, force: true } );
		},
	};
	return page;
}

module.exports = { launch };
