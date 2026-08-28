( function() {
	var notice = document.getElementById( 'yac-ocache-status-notice' );
	if ( ! notice || ! window.YAC_OCACHE_CONFIG ) {
		return;
	}
	notice.querySelector( '.notice-dismiss' ).addEventListener( 'click', function() {
		var xhr = new XMLHttpRequest();
		xhr.open( 'POST', YAC_OCACHE_CONFIG.ajaxUrl );
		xhr.setRequestHeader( 'Content-Type', 'application/x-www-form-urlencoded' );
		xhr.send( 'action=yac_ocache_dismiss_status_notice&_wpnonce=' + encodeURIComponent( YAC_OCACHE_CONFIG.noticeNonce ) );
	} );
} )();
