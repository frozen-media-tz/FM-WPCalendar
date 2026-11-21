<?php
/**
 * Deinstallation: entfernt Einstellungen und – falls aktiviert – alle Termine und Orte.
 *
 * @package FrozenMedia\WPCalendar
 */

declare(strict_types=1);

// Nur über die WordPress-Deinstallation ausführbar.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$fmvt_settings = get_option( 'fmvt_settings', array() );

if ( is_array( $fmvt_settings ) && ! empty( $fmvt_settings['delete_uninstall'] ) ) {
	// Alle Termine inkl. Meta-Daten endgültig löschen (in Blöcken).
	do {
		$fmvt_ids = get_posts(
			array(
				'post_type'      => 'fmvt_termin',
				'post_status'    => 'any',
				'posts_per_page' => 200,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);
		foreach ( $fmvt_ids as $fmvt_id ) {
			wp_delete_post( (int) $fmvt_id, true );
		}
	} while ( ! empty( $fmvt_ids ) );

	// Auch Termine im Papierkorb.
	$fmvt_trash = get_posts(
		array(
			'post_type'      => 'fmvt_termin',
			'post_status'    => 'trash',
			'posts_per_page' => -1,
			'fields'         => 'ids',
		)
	);
	foreach ( $fmvt_trash as $fmvt_id ) {
		wp_delete_post( (int) $fmvt_id, true );
	}

	// Orte löschen. Die Taxonomie ist hier nicht registriert, daher kurz registrieren.
	if ( ! taxonomy_exists( 'fmvt_ort' ) ) {
		register_taxonomy( 'fmvt_ort', 'fmvt_termin' );
	}
	$fmvt_terms = get_terms(
		array(
			'taxonomy'   => 'fmvt_ort',
			'hide_empty' => false,
			'fields'     => 'ids',
		)
	);
	if ( is_array( $fmvt_terms ) ) {
		foreach ( $fmvt_terms as $fmvt_term_id ) {
			wp_delete_term( (int) $fmvt_term_id, 'fmvt_ort' );
		}
	}
}

delete_option( 'fmvt_settings' );
