/**
 * FM-WPCalendar – Frontend
 * - Monatskalender blättern ohne Neuladen der Seite
 * - "Mehr anzeigen" in der Terminliste
 * - "Zurück" auf der Detailseite (Browser-Verlauf, wenn von dieser Website gekommen)
 */
( function () {
	'use strict';

	var config = window.fmvtConfig || {};
	var i18n = config.i18n || {};

	/**
	 * GET-Anfrage an admin-ajax.php, liefert das "data"-Objekt der JSON-Antwort.
	 */
	function request( params ) {
		var url = new URL( config.ajaxUrl, window.location.href );
		Object.keys( params ).forEach( function ( key ) {
			url.searchParams.set( key, params[ key ] );
		} );
		return fetch( url.toString(), { credentials: 'same-origin' } )
			.then( function ( response ) {
				if ( ! response.ok ) {
					throw new Error( 'HTTP ' + response.status );
				}
				return response.json();
			} )
			.then( function ( json ) {
				if ( ! json || ! json.success ) {
					throw new Error( 'Ungültige Antwort' );
				}
				return json.data;
			} );
	}

	/* Monatskalender blättern */
	function gotoMonth( calendar, month, focusClass ) {
		calendar.classList.add( 'is-loading' );
		calendar.setAttribute( 'aria-busy', 'true' );

		request( {
			action: 'fmvt_month',
			month: month,
			page_id: calendar.getAttribute( 'data-fmvt-page' ) || '0'
		} )
			.then( function ( data ) {
				var tpl = document.createElement( 'template' );
				tpl.innerHTML = data.html.trim();
				var fresh = tpl.content.firstElementChild;
				if ( ! fresh ) {
					throw new Error( 'Leere Antwort' );
				}
				calendar.replaceWith( fresh );
				// Fokus auf denselben Navigationsknopf setzen (Tastaturbedienung).
				var target = fresh.querySelector( '.' + focusClass ) || fresh.querySelector( '.fmvt-month-title' );
				if ( target && target.focus ) {
					target.focus( { preventScroll: true } );
				}
			} )
			.catch( function () {
				calendar.classList.remove( 'is-loading' );
				calendar.removeAttribute( 'aria-busy' );
				window.alert( i18n.error || 'Fehler' );
			} );
	}

	/* "Mehr anzeigen" */
	function loadMore( button ) {
		var wrap = button.closest( '.fmvt-list-wrap' );
		var list = wrap ? wrap.querySelector( '.fmvt-list' ) : null;
		if ( ! list ) {
			return;
		}
		var label = button.textContent;
		button.disabled = true;
		button.textContent = i18n.loading || '…';

		request( {
			action: 'fmvt_list',
			offset: button.getAttribute( 'data-fmvt-offset' ) || '0',
			count: button.getAttribute( 'data-fmvt-count' ) || '10'
		} )
			.then( function ( data ) {
				var before = list.children.length;
				list.insertAdjacentHTML( 'beforeend', data.html );
				button.setAttribute( 'data-fmvt-offset', String( data.offset ) );
				button.disabled = false;
				button.textContent = label;
				if ( ! data.has_more ) {
					button.parentNode.remove();
				}
				// Fokus auf den ersten neuen Eintrag setzen.
				var first = list.children[ before ];
				var link = first ? first.querySelector( 'a' ) : null;
				if ( link ) {
					link.focus( { preventScroll: true } );
				}
			} )
			.catch( function () {
				button.disabled = false;
				button.textContent = label;
				window.alert( i18n.error || 'Fehler' );
			} );
	}

	document.addEventListener( 'click', function ( event ) {
		var target = event.target;
		if ( ! ( target instanceof Element ) ) {
			return;
		}

		// Kalender-Navigation.
		var nav = target.closest( '[data-fmvt-goto]' );
		if ( nav ) {
			var calendar = nav.closest( '.fmvt-month' );
			if ( calendar && config.ajaxUrl ) {
				event.preventDefault();
				var focusClass = nav.classList.contains( 'fmvt-prev' ) ? 'fmvt-prev' : ( nav.classList.contains( 'fmvt-next' ) ? 'fmvt-next' : 'fmvt-month-title' );
				gotoMonth( calendar, nav.getAttribute( 'data-fmvt-goto' ), focusClass );
			}
			return;
		}

		// Mehr anzeigen.
		var more = target.closest( '.fmvt-more' );
		if ( more && config.ajaxUrl ) {
			event.preventDefault();
			loadMore( more );
			return;
		}

		// Zurück-Link: wenn der Besucher von dieser Website kommt, Browser-Verlauf nutzen
		// (dann bleiben Monat bzw. nachgeladene Einträge erhalten).
		var back = target.closest( '[data-fmvt-back]' );
		if ( back && document.referrer && window.history.length > 1 ) {
			try {
				if ( new URL( document.referrer ).origin === window.location.origin ) {
					event.preventDefault();
					window.history.back();
				}
			} catch ( e ) {
				// Ungültiger Referrer: normalen Link verwenden.
			}
		}
	} );
} )();
