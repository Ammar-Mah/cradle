// Mobile navigation toggle (reference behavior).
document.querySelector( '.nav-toggle' ).addEventListener( 'click', function () {
	var nav = document.getElementById( 'primary-nav' );
	var open = nav.classList.toggle( 'is-open' );
	this.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
} );
