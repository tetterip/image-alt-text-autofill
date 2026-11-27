/* Image Alt Text Autofill: "Generate" buttons in the Media Library list, the media modal and the attachment edit screen. */
( function () {
	'use strict';
	var cfg = window.tetAltMedia;
	if ( ! cfg ) {
		return;
	}

	// Alt text fields WordPress renders for an attachment (modal, two-column grid view, edit screen).
	function altFields( id ) {
		var selectors = [
			'#attachment-details-alt-text',
			'#attachment-details-two-column-alt-text',
			'#attachment_alt',
			'[data-setting="alt"] textarea',
			'[data-setting="alt"] input',
			'textarea[name="attachments[' + id + '][image_alt]"]',
		];
		return document.querySelectorAll( selectors.join( ',' ) );
	}

	function post( data ) {
		var body = new URLSearchParams();
		Object.keys( data ).forEach( function ( k ) {
			body.append( k, data[ k ] );
		} );
		return fetch( cfg.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body.toString(),
		} ).then( function ( r ) {
			return r.json();
		} );
	}

	document.addEventListener( 'click', function ( e ) {
		var btn = e.target.closest ? e.target.closest( '.tet-alt-generate' ) : null;
		if ( ! btn || btn.disabled ) {
			return;
		}
		e.preventDefault();
		var id = btn.getAttribute( 'data-id' );
		var cell = btn.closest( '.tet-alt-cell' );
		var current = cell ? cell.querySelector( '.tet-alt-text' ).textContent.trim() : '';
		var fields = btn.getAttribute( 'data-fill' ) ? altFields( id ) : [];
		if ( fields.length && fields[ 0 ].value.trim() ) {
			current = fields[ 0 ].value.trim();
		}
		var hasAlt = cell ? ! cell.querySelector( '.tet-alt-missing' ) : '' !== current;
		if ( hasAlt && ! window.confirm( cfg.i18n.confirm ) ) {
			return;
		}

		var status = btn.parentNode.querySelector( '.tet-alt-modal-status' );
		var label = btn.textContent;
		btn.disabled = true;
		btn.textContent = cfg.i18n.working;
		if ( status ) {
			status.textContent = '';
		}

		post( { action: 'tet_alt_generate', nonce: cfg.nonce, id: id } )
			.then( function ( res ) {
				if ( ! res || ! res.success ) {
					throw new Error( ( res && res.data && res.data.message ) || cfg.i18n.error );
				}
				var alt = res.data.alt;
				if ( cell ) {
					var text = cell.querySelector( '.tet-alt-text' );
					text.textContent = alt;
					var old = cell.querySelector( '.tet-alt-badge' );
					if ( old ) {
						old.remove();
					}
					text.insertAdjacentHTML( 'afterend', ' ' + res.data.badge );
					label = cfg.i18n.regen;
				}
				// Saved on the server already; update the visible field so a later "save" keeps it.
				Array.prototype.forEach.call( fields, function ( f ) {
					f.value = alt;
					f.dispatchEvent( new Event( 'input', { bubbles: true } ) );
					f.dispatchEvent( new Event( 'change', { bubbles: true } ) );
				} );
				if ( status ) {
					status.textContent = res.data.note ? res.data.note : cfg.i18n.done;
				}
			} )
			.catch( function ( err ) {
				if ( status ) {
					status.textContent = err.message;
				} else {
					window.alert( err.message ); // eslint-disable-line no-alert
				}
			} )
			.then( function () {
				btn.disabled = false;
				btn.textContent = label;
			} );
	} );
} )();
