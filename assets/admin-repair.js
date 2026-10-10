/**
 * Settings → Long Slug → Repair: runs the scan and the repairs in steps over
 * admin-ajax and shows progress, then reloads the page to show the results.
 */
( function () {
	const settings = window.parichSlugRepair;
	if ( ! settings ) {
		return;
	}

	function request( fields ) {
		const body = new URLSearchParams();
		Object.keys( fields ).forEach( ( key ) => {
			const value = fields[ key ];
			if ( Array.isArray( value ) ) {
				value.forEach( ( item ) => body.append( key + '[]', item ) );
			} else if ( value && 'object' === typeof value ) {
				Object.keys( value ).forEach( ( sub ) =>
					body.append( key + '[' + sub + ']', value[ sub ] )
				);
			} else if ( undefined !== value ) {
				body.append( key, String( value ) );
			}
		} );
		return fetch( settings.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body,
		} ).then( ( response ) =>
			response.json().then( ( json ) => {
				if ( ! response.ok || ! json || ! json.success ) {
					throw new Error(
						( json && json.data && json.data.message ) || response.statusText
					);
				}
				return json.data;
			} )
		);
	}

	// Scan.
	const scanButton = document.getElementById( 'parich-slug-scan' );
	const scanStatus = document.getElementById( 'parich-slug-scan-status' );
	if ( scanButton && scanStatus ) {
		const scan = ( fields ) =>
			request( Object.assign( { action: settings.action, nonce: settings.nonce }, fields ) );
		const next = ( data ) => {
			scanStatus.textContent = settings.progress
				.replace( '%1$s', settings.steps[ data.step ] || data.step )
				.replace( '%2$d', String( data.objects ) );
			if ( data.done ) {
				scanStatus.textContent = settings.done;
				window.location.href = settings.reloadUrl;
				return null;
			}
			return scan( { op: 'step', scan: data.scan } ).then( next );
		};
		scanButton.addEventListener( 'click', () => {
			scanButton.disabled = true;
			scanStatus.textContent = settings.starting;
			scan( { op: 'start' } )
				.then( next )
				.catch( ( error ) => {
					scanStatus.textContent = settings.error + ' (' + error.message + ')';
					scanButton.disabled = false;
				} );
		} );
	}

	// Repair.
	const form = document.getElementById( 'parich-slug-apply-form' );
	const status = document.getElementById( 'parich-slug-apply-status' );
	const buttons = [
		document.getElementById( 'parich-slug-apply-selected' ),
		document.getElementById( 'parich-slug-apply-auto' ),
	].filter( Boolean );
	if ( ! form || ! status || ! buttons.length ) {
		return;
	}
	form.addEventListener( 'submit', ( event ) => event.preventDefault() );
	const apply = ( fields ) =>
		request( Object.assign( { action: settings.applyAction, nonce: settings.applyNonce }, fields ) );
	const next = ( data ) => {
		status.textContent = settings.applying
			.replace( '%1$d', String( data.done ) )
			.replace( '%2$d', String( data.total ) );
		if ( data.finished ) {
			window.location.href = settings.reloadUrl;
			return null;
		}
		return apply( { op: 'step', batch: data.batch } ).then( next );
	};
	const start = ( fields, question ) => {
		// eslint-disable-next-line no-alert
		if ( ! window.confirm( question ) ) {
			return;
		}
		buttons.forEach( ( button ) => ( button.disabled = true ) );
		apply( Object.assign( { op: 'start' }, fields ) )
			.then( next )
			.catch( ( error ) => {
				status.textContent = settings.applyError + ' ' + error.message;
				buttons.forEach( ( button ) => ( button.disabled = false ) );
			} );
	};

	buttons[ 0 ].addEventListener( 'click', () => {
		const keys = Array.from( form.querySelectorAll( 'input[name="repair[]"]:checked' ) ).map(
			( box ) => box.value
		);
		if ( ! keys.length ) {
			status.textContent = settings.nothingSelected;
			return;
		}
		const own = {};
		keys.forEach( ( key ) => {
			const field = form.querySelector( 'input[name="own[' + key + ']"]' );
			if ( field && field.value.trim() ) {
				own[ key ] = field.value.trim();
			}
		} );
		start( { mode: 'selected', repair: keys, own }, settings.confirmSelected );
	} );
	if ( buttons[ 1 ] ) {
		buttons[ 1 ].addEventListener( 'click', () =>
			start( { mode: 'auto' }, settings.confirmAuto )
		);
	}

	// Typing a slug of one's own selects the row.
	form.querySelectorAll( 'input[name^="own["]' ).forEach( ( field ) => {
		field.addEventListener( 'input', () => {
			const key = field.name.slice( 4, -1 );
			const box = form.querySelector( 'input[name="repair[]"][value="' + key + '"]' );
			if ( box && field.value.trim() ) {
				box.disabled = false;
				box.checked = true;
			}
		} );
	} );
} )();
