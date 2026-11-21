/**
 * FM-WPCalendar – Backend-Metabox
 * Blendet Felder je nach Auswahl ein/aus (mehrtägig, ganztägig, neuer Ort).
 */
( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function () {
		var box = document.querySelector( '.fmvt-meta' );
		if ( ! box ) {
			return;
		}

		var startDate = box.querySelector( '#fmvt-start-date' );
		var endDate = box.querySelector( '#fmvt-end-date' );
		var multiDay = box.querySelector( '#fmvt-multi-day' );
		var endWrap = box.querySelector( '.fmvt-end-date-wrap' );
		var allDay = box.querySelector( '#fmvt-all-day' );
		var timeWrap = box.querySelector( '.fmvt-time-wrap' );
		var startTime = box.querySelector( '#fmvt-start-time' );
		var location = box.querySelector( '#fmvt-location' );
		var newLocation = box.querySelector( '.fmvt-new-location' );
		var newLocationName = box.querySelector( '#fmvt-new-location-name' );

		// Mehrtägig: Enddatum einblenden und sinnvoll vorbelegen.
		function toggleMultiDay() {
			var on = multiDay.checked;
			endWrap.hidden = ! on;
			if ( on && ! endDate.value && startDate.value ) {
				endDate.value = startDate.value;
			}
		}

		// Ganztägig: Uhrzeitfelder ausblenden.
		function toggleAllDay() {
			timeWrap.hidden = allDay.checked;
			startTime.required = ! allDay.checked;
		}

		// Neuer Ort: Eingabefelder einblenden.
		function toggleLocation() {
			if ( ! newLocation ) {
				return;
			}
			var isNew = location.value === 'new';
			newLocation.hidden = ! isNew;
			newLocationName.required = isNew;
			if ( isNew ) {
				newLocationName.focus();
			}
		}

		multiDay.addEventListener( 'change', toggleMultiDay );
		allDay.addEventListener( 'change', toggleAllDay );
		location.addEventListener( 'change', toggleLocation );

		startDate.addEventListener( 'change', function () {
			endDate.min = startDate.value;
			if ( endDate.value && endDate.value < startDate.value ) {
				endDate.value = startDate.value;
			}
		} );

		toggleAllDay();
	} );
} )();
