/* global jQuery, ACSP */
( function ( $ ) {
	'use strict';

	/* ------------------------------------------------------------------
	 * Confirmations
	 *
	 * A native <dialog> gives us the focus trap, the Escape key and the
	 * inert backdrop for free, so the dialog is keyboard-accessible without
	 * a hand-rolled focus manager. Where <dialog> is unsupported we fall
	 * back to window.confirm(), so a destructive action is never unguarded.
	 * ---------------------------------------------------------------- */
	var supportsDialog = typeof window.HTMLDialogElement === 'function' &&
		typeof document.createElement( 'dialog' ).showModal === 'function';
	var $dialog = null;
	var pending = null;

	function buildDialog() {
		if ( $dialog ) {
			return $dialog;
		}
		$dialog = $(
			'<dialog class="acsp-dialog" aria-labelledby="acsp-dialog-title" aria-describedby="acsp-dialog-text">' +
				'<form method="dialog">' +
					'<div class="acsp-dialog-body">' +
						'<h2 id="acsp-dialog-title"></h2>' +
						'<p id="acsp-dialog-text"></p>' +
					'</div>' +
					'<div class="acsp-dialog-foot">' +
						'<button type="submit" value="cancel" class="acsp-btn acsp-dialog-cancel"></button>' +
						'<button type="submit" value="confirm" class="acsp-btn acsp-btn-danger acsp-dialog-confirm"></button>' +
					'</div>' +
				'</form>' +
			'</dialog>'
		// Appended inside the plugin's own wrapper, not to <body>: the design
		// tokens are declared on .acsp-wrap, so a dialog outside it would
		// resolve every colour to nothing and render as transparent text.
		// A modal <dialog> is painted in the top layer either way, so its
		// position in the DOM costs nothing.
		).appendTo( $( '.acsp-wrap' )[ 0 ] || document.body );

		// Runs for every way the dialog can close, Escape included, so a
		// dismissed dialog can never leave the action half-armed.
		$dialog.on( 'close', function () {
			var action = pending;
			var proceed = this.returnValue === 'confirm';
			pending = null;
			if ( proceed && action ) {
				action();
			}
		} );

		return $dialog;
	}

	$( document ).on( 'click', '.acsp-confirm', function ( event ) {
		var $trigger = $( this );
		var message = $trigger.data( 'confirm' );

		if ( ! message || $trigger.data( 'acsp-confirmed' ) ) {
			$trigger.removeData( 'acsp-confirmed' );
			return;
		}

		event.preventDefault();

		if ( ! supportsDialog ) {
			if ( window.confirm( message ) ) {
				$trigger.data( 'acsp-confirmed', true );
				$trigger.trigger( 'click' );
			}
			return;
		}

		var $d = buildDialog();
		$d.find( '.acsp-dialog-cancel' ).text( ACSP.i18n.cancel );
		$d.find( '.acsp-dialog-confirm' ).text( $trigger.data( 'confirm-action' ) || ACSP.i18n.proceed );
		$d.find( '#acsp-dialog-title' ).text( $trigger.data( 'confirm-title' ) || ACSP.i18n.confirm );
		$d.find( '#acsp-dialog-text' ).text( message );

		// Re-dispatching the click rather than submitting the form keeps the
		// button's own name and value in the request, which the handlers for
		// the bulk actions rely on.
		pending = function () {
			$trigger.data( 'acsp-confirmed', true );
			$trigger.trigger( 'click' );
		};
		$d[ 0 ].returnValue = '';
		$d[ 0 ].showModal();
	} );

	/* ------------------------------------------------------------------
	 * Policy editor: turn each plain text field into removable chips.
	 * The original field stays in the form and is what gets submitted, so
	 * the editor still works if this script fails to load.
	 * ---------------------------------------------------------------- */
	var RISKY = [ "'unsafe-inline'", "'unsafe-eval'", '*', 'http:', 'https:' ];

	function tokens( value ) {
		return $.grep( String( value ).split( /[\s,]+/ ), function ( token ) {
			return token !== '';
		} );
	}

	$( '.acsp-sources' ).each( function () {
		var $field = $( this );
		var list = tokens( $field.val() );
		var $box = $( '<div class="acsp-chips" />' );
		var $entry = $( '<input type="text" autocomplete="off" spellcheck="false" />' )
			.attr( 'placeholder', ACSP.i18n.placeholder )
			.attr( 'aria-label', ACSP.i18n.placeholder );

		function sync() {
			$field.val( list.join( ' ' ) );
		}

		// Chips are added and removed one at a time. Re-rendering the whole
		// list would destroy a remove button while it is being clicked.
		function addChip( token ) {
			var $chip = $( '<span class="acsp-chip" />' ).text( token );
			if ( token.charAt( 0 ) === "'" || /^[a-z][a-z0-9+.-]*:$/.test( token ) ) {
				$chip.addClass( 'is-keyword' );
			}
			if ( $.inArray( token, RISKY ) !== -1 ) {
				$chip.addClass( 'is-risky' );
			}
			$( '<button type="button" />' )
				.attr( 'aria-label', ACSP.i18n.remove + ' ' + token )
				.html( '&times;' )
				.on( 'click', function () {
					var index = $.inArray( token, list );
					if ( index !== -1 ) {
						list.splice( index, 1 );
					}
					$chip.remove();
					sync();
					$entry.trigger( 'focus' );
				} )
				.appendTo( $chip );
			$chip.insertBefore( $entry );
		}

		function commit() {
			$.each( tokens( $entry.val() ), function ( i, token ) {
				if ( $.inArray( token, list ) === -1 ) {
					list.push( token );
					addChip( token );
				}
			} );
			$entry.val( '' );
			sync();
		}

		$entry
			.on( 'keydown', function ( event ) {
				if ( event.key === 'Enter' || event.key === ',' || event.key === ' ' ) {
					event.preventDefault();
					commit();
				} else if ( event.key === 'Backspace' && $entry.val() === '' && list.length ) {
					list.pop();
					$box.find( '.acsp-chip' ).last().remove();
					sync();
				}
			} )
			.on( 'blur', commit )
			.on( 'paste', function () {
				window.setTimeout( commit, 0 );
			} );

		$box.on( 'click', function ( event ) {
			if ( event.target === $box[ 0 ] ) {
				$entry.trigger( 'focus' );
			}
		} );

		$box.append( $entry );
		$field.attr( 'type', 'hidden' ).after( $box );
		// Keep the visible label pointing at something focusable.
		$entry.attr( 'id', $field.attr( 'id' ) + '-entry' );
		$( 'label[for="' + $field.attr( 'id' ) + '"]' ).attr( 'for', $field.attr( 'id' ) + '-entry' );
		$field.closest( 'form' ).on( 'submit', commit );
		$.each( list, function ( i, token ) {
			addChip( token );
		} );
	} );

	/* ------------------------------------------------------------------
	 * Scanner: run the scan one short request at a time.
	 * ---------------------------------------------------------------- */
	var $log = $( '#acsp-scan-log' );
	var $buttons = $( '.acsp-scan-btn' );
	var $panel = $( '#acsp-scan-progress' );
	var $track = $( '#acsp-scan-bar-track' );
	var $bar = $( '#acsp-scan-bar' );
	var $phase = $( '#acsp-scan-phase' );
	var $percent = $( '#acsp-scan-percent' );
	var running = false;
	var repeats = 0;

	// Where each step of the server's scan sequence sits on the bar. The
	// sequence is fixed (see ACSP_Admin::ajax_scan_step), so the percentage
	// is derived from real progress rather than invented.
	var PHASES = {
		start: 0,
		theme: 5,
		options: 15,
		posts: 35,
		meta: 55,
		crawl: 70,
		finish: 95,
		done: 100
	};
	var ORDER = [ 'start', 'theme', 'options', 'posts', 'meta', 'crawl', 'finish', 'done' ];

	/**
	 * Position on the bar for a step.
	 *
	 * A step that resumes (a long options, posts, meta or crawl pass) has no
	 * total to count against, so the bar eases toward the next phase without
	 * ever reaching it. It never goes backwards and never claims to be done.
	 */
	function percentFor( step, repeat ) {
		var base = PHASES[ step ];
		if ( typeof base !== 'number' ) {
			return null;
		}
		var next = PHASES[ ORDER[ $.inArray( step, ORDER ) + 1 ] ];
		if ( typeof next !== 'number' || ! repeat ) {
			return base;
		}
		return Math.round( base + ( next - base ) * ( 1 - 1 / ( 1 + repeat / 3 ) ) );
	}

	function setProgress( value, label, state ) {
		if ( typeof value === 'number' ) {
			$bar.css( 'width', value + '%' );
			$track.attr( 'aria-valuenow', value );
			$percent.text( value + '%' );
		}
		if ( label ) {
			$phase.text( label );
		}
		$track.removeClass( 'is-done is-error' );
		if ( state ) {
			$track.addClass( state );
		}
	}

	function line( text, state, replace ) {
		var $last = $log.children().last();
		$log.children().removeClass( 'is-active' );
		if ( replace && $last.length ) {
			$last.text( text );
		} else {
			$last = $( '<li />' ).text( text ).appendTo( $log );
		}
		if ( state ) {
			$last.addClass( state );
		}
	}

	function fail( message ) {
		running = false;
		$buttons.prop( 'disabled', false ).removeClass( 'is-loading' );
		setProgress( null, ACSP.i18n.stopped, 'is-error' );
		line( message || ACSP.i18n.failed, 'is-error', false );
	}

	function step( name, cursor, auto ) {
		$.post( ACSP.ajaxUrl, {
			action: 'acsp_scan_step',
			nonce: ACSP.nonce,
			step: name,
			cursor: cursor,
			auto: auto ? 1 : 0
		} )
			.done( function ( response ) {
				if ( ! response || ! response.success || ! response.data ) {
					fail( response && response.data && response.data.message );
					return;
				}
				var data = response.data;
				line( data.message, data.done ? '' : 'is-active', !! data.replace );

				if ( data.done ) {
					setProgress( 100, ACSP.i18n.complete, 'is-done' );
					window.location.href = data.redirect;
					return;
				}

				repeats = data.replace ? repeats + 1 : 0;
				setProgress( percentFor( data.step, repeats ), data.message );
				step( data.step, data.cursor, auto );
			} )
			.fail( function () {
				fail();
			} );
	}

	function start( auto ) {
		if ( running ) {
			return;
		}
		running = true;
		repeats = 0;
		$buttons.prop( 'disabled', true );
		$log.empty();
		$panel.prop( 'hidden', false );
		setProgress( 0, ACSP.i18n.starting );
		line( ACSP.i18n.starting, 'is-active', false );
		step( 'start', 0, auto );
	}

	$buttons.on( 'click', function () {
		var $button = $( this );
		// The clicked button carries the spinner; the other is only disabled,
		// so it is clear which scan is running.
		$button.addClass( 'is-loading' );
		start( String( $button.data( 'auto' ) ) === '1' );
	} );

	// Straight after activation: run the first full scan so the owner has
	// something to review. It deliberately does not apply anything on its own.
	if ( ACSP.autoStart && $buttons.length ) {
		start( false );
	}

	// "Select all" for the scan review tables.
	$( '.acsp-diff thead .check-column input[type="checkbox"]' ).on( 'change', function () {
		$( this ).closest( 'table' ).find( "tbody .check-column input[type=\"checkbox\"]" ).prop( 'checked', this.checked );
	} );
} )( jQuery );
