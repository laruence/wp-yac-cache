( function() {
	var mask = document.getElementById( 'yac-ocache-modal' ),
		keyEl  = document.getElementById( 'yac-ocache-modal-key' ),
		metaEl = document.getElementById( 'yac-ocache-modal-meta' ),
		bodyEl = document.getElementById( 'yac-ocache-modal-body' ),
		delEl  = document.getElementById( 'yac-ocache-modal-delete' ),
		ajaxurl = ( window.YAC_OCACHE_CONFIG && YAC_OCACHE_CONFIG.ajaxUrl ) || '',
		nonce   = ( window.YAC_OCACHE_CONFIG && YAC_OCACHE_CONFIG.entryNonce ) || '',
		cur = null, curRow = null;

	function esc( s ) {
		var d = document.createElement( 'div' );
		d.textContent = s == null ? '' : String( s );
		return d.innerHTML;
	}
	function rel( s ) {
		var diff = Math.round( Date.now() / 1000 - s ), a = Math.abs( diff ), u;
		u = a >= 172800 ? Math.round( a / 86400 ) + 'd' : ( a >= 7200 ? Math.round( a / 3600 ) + 'h' : ( a >= 120 ? Math.round( a / 60 ) + 'm' : a + 's' ) );
		return diff >= 0 ? u + ' ago' : 'in ' + u;
	}
	function epoch( s ) {
		return new Date( s * 1000 ).toLocaleString() + ' (' + rel( s ) + ')';
	}
	function num( n ) {
		return String( n ).replace( /\B(?=(\d{3})+(?!\d))/g, ',' );
	}
	function send( action, cb ) {
		var xhr = new XMLHttpRequest();
		xhr.open( 'POST', ajaxurl );
		xhr.setRequestHeader( 'Content-Type', 'application/x-www-form-urlencoded' );
		xhr.onload = function() {
			var res;
			try { res = JSON.parse( xhr.responseText ); } catch ( e ) { res = { success: false }; }
			cb( res );
		};
		xhr.send( 'action=' + action + '&key=' + encodeURIComponent( cur ) + '&_wpnonce=' + encodeURIComponent( nonce ) );
	}
	function close() {
		mask.hidden = true;
		cur = null;
		curRow = null;
	}
	function open( key, row ) {
		cur = key;
		curRow = row;
		keyEl.textContent = key;
		metaEl.innerHTML = '<li><span>Loading…</span><strong></strong></li>';
		bodyEl.textContent = '';
		mask.hidden = false;
		send( 'yac_ocache_entry', function( res ) {
			if ( ! res.success || ! res.data ) {
				metaEl.innerHTML = '<li><span>Could not load the entry.</span><strong></strong></li>';
				return;
			}
			var d = res.data,
				rows = [
					'<li><span>Content (' + ( d.c_len != null ? 'v_len/c_len' : 'v_len' ) + ')</span><strong>' + esc( d.v_len ) + ( d.c_len != null ? ' / ' + esc( d.c_len ) : '' ) + '</strong></li>',
					'<li><span>Occupied (padded)</span><strong>' + esc( d.size ) + '</strong></li>',
					'<li><span>Expires</span><strong>' + ( d.ttl ? esc( epoch( d.ttl ) ) : 'never' ) + '</strong></li>'
				];
			if ( d.atime ) {
				rows.push( '<li><span>Last access</span><strong>' + esc( epoch( d.atime ) ) + '</strong></li>' );
			}
			if ( d.hits != null ) {
				rows.push( '<li><span>Hits</span><strong>' + esc( num( d.hits ) ) + '</strong></li>' );
			}
			if ( d.embedded != null ) {
				rows.push( '<li><span>Embedded in slot</span><strong>' + ( d.embedded ? 'yes' : 'no' ) + '</strong></li>' );
			}
			metaEl.innerHTML = rows.join( '' );
			if ( d.gone ) {
				bodyEl.textContent = '(the entry is gone — evicted or expired between the page render and now)';
			} else {
				bodyEl.textContent = d.content + ( d.truncated ? '\n… truncated, full content is ' + d.content_len + ' bytes' : '' );
			}
		} );
	}

	document.addEventListener( 'click', function( e ) {
		var tab = e.target.closest ? e.target.closest( '.yac-ocache-tab' ) : null;
		if ( tab ) {
			var list = tab.getAttribute( 'data-yac-ocache-list' );
			document.querySelectorAll( '.yac-ocache-tab' ).forEach( function( b ) {
				b.classList.toggle( 'is-active', b === tab );
				b.setAttribute( 'aria-selected', b === tab ? 'true' : 'false' );
			} );
			document.querySelectorAll( '.yac-ocache-entry-list' ).forEach( function( ul ) {
				ul.classList.toggle( 'is-hidden', ul.getAttribute( 'data-yac-ocache-list' ) !== list );
			} );
			return;
		}
		var t = e.target.closest ? e.target.closest( '.yac-ocache-entry-inspect' ) : null;
		if ( t ) {
			open( t.getAttribute( 'data-key' ), t.closest( 'li' ) );
			return;
		}
		if ( e.target === mask || ( e.target.closest && e.target.closest( '[data-yac-ocache-close]' ) ) ) {
			close();
		}
	} );
	delEl.addEventListener( 'click', function() {
		if ( ! cur ) {
			return;
		}
		send( 'yac_ocache_entry_delete', function( res ) {
			if ( res.success && res.data && res.data.deleted && curRow && curRow.parentNode ) {
				curRow.parentNode.removeChild( curRow );
			}
			close();
		} );
	} );
} )();
