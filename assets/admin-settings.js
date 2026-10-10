/**
 * Settings → Long Slug: shows the slug a typed title would get with the length
 * in the field next to it, without saving. Text is set with textContent only.
 */
( function () {
	const settings = window.parichSlugSettings;
	const title = document.getElementById( 'parich-slug-preview-title' );
	const max = document.getElementById( 'parich_slug_max_length' );
	const slug = document.getElementById( 'parich-slug-preview-slug' );
	const info = document.getElementById( 'parich-slug-preview-info' );
	if ( ! settings || ! title || ! max || ! slug || ! info ) {
		return;
	}

	let timer = null;
	let request = 0;

	function update() {
		const current = ++request;
		if ( '' === title.value.trim() ) {
			slug.textContent = '';
			info.textContent = '';
			return;
		}
		const body = new URLSearchParams( {
			action: settings.action,
			nonce: settings.nonce,
			title: title.value,
			max: max.value,
		} );
		window
			.fetch( settings.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				body,
			} )
			.then( ( response ) => response.json() )
			.then( ( result ) => {
				if ( current !== request ) {
					return; // A newer request is on its way.
				}
				if ( ! result || ! result.success ) {
					throw new Error();
				}
				slug.textContent = result.data.readable;
				info.textContent = result.data.text;
			} )
			.catch( () => {
				if ( current === request ) {
					slug.textContent = '';
					info.textContent = settings.error;
				}
			} );
	}

	function schedule() {
		window.clearTimeout( timer );
		timer = window.setTimeout( update, 300 );
	}

	title.addEventListener( 'input', schedule );
	max.addEventListener( 'input', schedule );
} )();
