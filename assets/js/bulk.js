/* Image Alt Text Autofill: bulk page. Lists the images, then sends them in small batches. */
( function () {
	'use strict';
	var cfg = window.tetAltBulk;
	var form = document.getElementById( 'tet-alt-bulk-form' );

	// Notes that belong to one choice of a radio group (the AI cost warning): data-tet-alt-show="name=value".
	Array.prototype.forEach.call( document.querySelectorAll( '[data-tet-alt-show]' ), function ( el ) {
		var parts = el.getAttribute( 'data-tet-alt-show' ).split( '=' );
		var value = parts.pop();
		var radios = document.getElementsByName( parts.join( '=' ) );
		function sync() {
			var on = false;
			Array.prototype.forEach.call( radios, function ( r ) {
				on = on || ( r.checked && r.value === value );
			} );
			el.hidden = ! on;
		}
		Array.prototype.forEach.call( radios, function ( r ) {
			r.addEventListener( 'change', sync );
		} );
		sync();
	} );
	if ( ! cfg || ! form ) {
		return;
	}
	var startBtn = document.getElementById( 'tet-alt-start' );
	var stopBtn = document.getElementById( 'tet-alt-stop' );
	var bar = document.getElementById( 'tet-alt-progress' );
	var text = document.getElementById( 'tet-alt-progress-text' );
	var table = document.getElementById( 'tet-alt-results' );
	var tbody = table.querySelector( 'tbody' );
	var stopped = false;

	function post( data ) {
		var body = new URLSearchParams();
		Object.keys( data ).forEach( function ( k ) {
			var v = data[ k ];
			if ( Array.isArray( v ) ) {
				v.forEach( function ( item ) {
					body.append( k + '[]', item );
				} );
			} else {
				body.append( k, v );
			}
		} );
		return fetch( cfg.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body.toString(),
		} ).then( function ( r ) {
			if ( ! r.ok && 403 !== r.status ) {
				throw new Error( cfg.i18n.failed );
			}
			return r.json();
		} );
	}

	function format( str ) {
		var args = Array.prototype.slice.call( arguments, 1 );
		return str.replace( /%(\d+)\$d/g, function ( m, n ) {
			return args[ n - 1 ];
		} );
	}

	function cell( tr, content ) {
		var td = document.createElement( 'td' );
		if ( content instanceof Node ) {
			td.appendChild( content );
		} else {
			td.textContent = content;
		}
		tr.appendChild( td );
		return td;
	}

	function addRow( row ) {
		var tr = document.createElement( 'tr' );
		tr.className = 'tet-alt-row--' + row.status;
		var img = document.createElement( 'img' );
		img.src = row.thumb;
		img.alt = '';
		img.width = 48;
		img.height = 48;
		cell( tr, row.thumb ? img : '' ).className = 'tet-alt-col-thumb';
		var a = document.createElement( 'a' );
		a.href = row.edit;
		a.textContent = row.title || '#' + row.id;
		cell( tr, a );
		var alt = cell( tr, 'error' === row.status ? '' : row.alt );
		if ( row.previous && row.previous !== row.alt && 'skipped' !== row.status ) {
			var prev = document.createElement( 'div' );
			prev.className = 'tet-alt-previous';
			prev.textContent = row.previous;
			alt.appendChild( prev );
		}
		var result = cfg.i18n[ row.status ] || row.status;
		if ( row.source && 'error' !== row.status ) {
			result += ' · ' + ( 'ai' === row.source ? cfg.i18n.sourceAi : cfg.i18n.sourceTemplate );
		}
		var td = cell( tr, result );
		if ( row.note ) {
			var note = document.createElement( 'div' );
			note.className = 'description';
			note.textContent = row.note;
			td.appendChild( note );
		}
		tbody.insertBefore( tr, tbody.firstChild );
	}

	function done( message ) {
		text.textContent += ' ' + message;
		startBtn.disabled = false;
		stopBtn.hidden = true;
	}

	stopBtn.addEventListener( 'click', function () {
		stopped = true;
		stopBtn.disabled = true;
	} );

	form.addEventListener( 'submit', function ( e ) {
		e.preventDefault();
		var data = new FormData( form );
		var scope = data.get( 'scope' );
		if ( 'all' === scope && ! window.confirm( cfg.i18n.confirmAll ) ) {
			return;
		}
		stopped = false;
		startBtn.disabled = true;
		stopBtn.hidden = false;
		stopBtn.disabled = false;
		tbody.innerHTML = '';
		text.textContent = '';

		var base = {
			nonce: cfg.nonce,
			scope: scope,
			source: data.get( 'source' ) || 'template',
			dry_run: data.get( 'dry_run' ) ? '1' : '',
		};

		post( Object.assign( { action: 'tet_alt_bulk_ids', ids: data.get( 'ids' ) || '' }, base ) )
			.then( function ( res ) {
				if ( ! res.success ) {
					throw new Error( res.data && res.data.message ? res.data.message : cfg.i18n.failed );
				}
				var ids = res.data.ids;
				var batch = res.data.batch;
				base.source = res.data.source;
				var total = ids.length;
				if ( ! total ) {
					text.textContent = cfg.i18n.none;
					done( '' );
					return;
				}
				if ( 'ai' === base.source && ! window.confirm( cfg.i18n.confirmAi.replace( '%d', total ) ) ) {
					done( cfg.i18n.stopped );
					return;
				}
				table.hidden = false;
				bar.hidden = false;
				bar.max = total;
				bar.value = 0;
				var pos = 0;

				function next() {
					text.textContent = format( cfg.i18n.progress, pos, total );
					bar.value = pos;
					if ( stopped ) {
						return done( cfg.i18n.stopped );
					}
					if ( pos >= total ) {
						return done( cfg.i18n.finished );
					}
					var chunk = ids.slice( pos, pos + batch );
					return post( Object.assign( { action: 'tet_alt_bulk_run', ids: chunk }, base ) ).then( function ( r ) {
						if ( ! r.success ) {
							throw new Error( r.data && r.data.message ? r.data.message : cfg.i18n.failed );
						}
						r.data.rows.forEach( addRow );
						pos += chunk.length;
						return next();
					} );
				}
				return next();
			} )
			.catch( function ( err ) {
				done( err.message );
			} );
	} );
} )();
