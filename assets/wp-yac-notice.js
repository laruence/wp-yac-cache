( function() {
	var notice = document.getElementById( 'wp-yac-status-notice' );
	if ( ! notice || ! window.WP_YAC_CONFIG ) {
		return;
	}
	notice.querySelector( '.notice-dismiss' ).addEventListener( 'click', function() {
		var xhr = new XMLHttpRequest();
		xhr.open( 'POST', WP_YAC_CONFIG.ajaxUrl );
		xhr.setRequestHeader( 'Content-Type', 'application/x-www-form-urlencoded' );
		xhr.send( 'action=wp_yac_dismiss_status_notice&_wpnonce=' + encodeURIComponent( WP_YAC_CONFIG.noticeNonce ) );
	} );
} )();
