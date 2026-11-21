<?php
/**
 * Registrierung des Post-Types "Termin" und der Taxonomie "Ort" inkl. Adressfeld.
 *
 * @package FrozenMedia\WPCalendar
 */

declare(strict_types=1);

namespace FrozenMedia\WPCalendar;

use WP_Term;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Post_Type {

	public const POST_TYPE         = 'fmvt_termin';
	public const TAXONOMY          = 'fmvt_ort';
	public const TERM_META_ADDRESS = 'fmvt_address';

	private const TERM_NONCE = 'fmvt_term_address_nonce';

	public function register_hooks(): void {
		add_action( 'init', array( self::class, 'register_post_type' ) );
		add_action( 'init', array( self::class, 'register_taxonomy' ) );

		// Adressfeld für Orte (Anlegen, Bearbeiten, Speichern).
		add_action( self::TAXONOMY . '_add_form_fields', array( $this, 'term_add_field' ) );
		add_action( self::TAXONOMY . '_edit_form_fields', array( $this, 'term_edit_field' ) );
		add_action( 'created_' . self::TAXONOMY, array( $this, 'term_save' ) );
		add_action( 'edited_' . self::TAXONOMY, array( $this, 'term_save' ) );

		// Adress-Spalte in der Ortsliste.
		add_filter( 'manage_edit-' . self::TAXONOMY . '_columns', array( $this, 'term_columns' ) );
		add_filter( 'manage_' . self::TAXONOMY . '_custom_column', array( $this, 'term_column_content' ), 10, 3 );
	}

	/**
	 * Post-Type "Termin".
	 */
	public static function register_post_type(): void {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'              => array(
					'name'               => __( 'Termine', 'fm-wpcalendar' ),
					'singular_name'      => __( 'Termin', 'fm-wpcalendar' ),
					'menu_name'          => __( 'Termine', 'fm-wpcalendar' ),
					'add_new'            => __( 'Neuer Termin', 'fm-wpcalendar' ),
					'add_new_item'       => __( 'Neuen Termin anlegen', 'fm-wpcalendar' ),
					'edit_item'          => __( 'Termin bearbeiten', 'fm-wpcalendar' ),
					'new_item'           => __( 'Neuer Termin', 'fm-wpcalendar' ),
					'view_item'          => __( 'Termin ansehen', 'fm-wpcalendar' ),
					'search_items'       => __( 'Termine durchsuchen', 'fm-wpcalendar' ),
					'not_found'          => __( 'Keine Termine gefunden.', 'fm-wpcalendar' ),
					'not_found_in_trash' => __( 'Keine Termine im Papierkorb.', 'fm-wpcalendar' ),
					'all_items'          => __( 'Alle Termine', 'fm-wpcalendar' ),
				),
				'public'              => true,
				'publicly_queryable'  => true,
				'exclude_from_search' => false,
				'show_ui'             => true,
				'show_in_menu'        => true,
				'show_in_nav_menus'   => false,
				'show_in_rest'        => false, // Klassischer Editor, keine REST-Schnittstelle nötig.
				'menu_position'       => 21,
				'menu_icon'           => 'dashicons-calendar-alt',
				'capability_type'     => 'post',
				'map_meta_cap'        => true,
				'hierarchical'        => false,
				'supports'            => array( 'title', 'editor', 'revisions' ),
				'has_archive'         => false,
				'rewrite'             => array(
					'slug'       => _x( 'termin', 'URL-Slug', 'fm-wpcalendar' ),
					'with_front' => false,
				),
				'query_var'           => true,
			)
		);
	}

	/**
	 * Taxonomie "Ort" (ein Ort je Termin, Auswahl erfolgt in der Termin-Metabox).
	 */
	public static function register_taxonomy(): void {
		register_taxonomy(
			self::TAXONOMY,
			self::POST_TYPE,
			array(
				'labels'             => array(
					'name'          => __( 'Orte', 'fm-wpcalendar' ),
					'singular_name' => __( 'Ort', 'fm-wpcalendar' ),
					'menu_name'     => __( 'Orte', 'fm-wpcalendar' ),
					'all_items'     => __( 'Alle Orte', 'fm-wpcalendar' ),
					'edit_item'     => __( 'Ort bearbeiten', 'fm-wpcalendar' ),
					'update_item'   => __( 'Ort aktualisieren', 'fm-wpcalendar' ),
					'add_new_item'  => __( 'Neuen Ort anlegen', 'fm-wpcalendar' ),
					'new_item_name' => __( 'Name des Ortes', 'fm-wpcalendar' ),
					'search_items'  => __( 'Orte durchsuchen', 'fm-wpcalendar' ),
					'not_found'     => __( 'Keine Orte gefunden.', 'fm-wpcalendar' ),
					'back_to_items' => __( '← Zurück zu den Orten', 'fm-wpcalendar' ),
				),
				'public'             => false,
				'publicly_queryable' => false,
				'show_ui'            => true,
				'show_in_menu'       => true,
				'show_in_nav_menus'  => false,
				'show_tagcloud'      => false,
				'show_in_rest'       => false,
				'show_admin_column'  => true,
				'hierarchical'       => false,
				'meta_box_cb'        => false, // Eigene Auswahl in der Termin-Metabox.
				'rewrite'            => false,
				'capabilities'       => array(
					'manage_terms' => 'publish_posts',
					'edit_terms'   => 'publish_posts',
					'delete_terms' => 'edit_others_posts',
					'assign_terms' => 'edit_posts',
				),
			)
		);
	}

	/**
	 * Legt einen Ort an bzw. liefert einen bestehenden mit gleichem Namen.
	 *
	 * @return int|null Term-ID oder null bei Fehler.
	 */
	public static function get_or_create_location( string $name, string $address = '' ): ?int {
		$name = trim( sanitize_text_field( $name ) );
		if ( '' === $name ) {
			return null;
		}
		$name = mb_substr( $name, 0, 190 );

		$existing = get_term_by( 'name', $name, self::TAXONOMY );
		if ( $existing instanceof WP_Term ) {
			// Adresse nur ergänzen, wenn bisher keine hinterlegt ist.
			if ( '' !== $address && '' === (string) get_term_meta( $existing->term_id, self::TERM_META_ADDRESS, true ) ) {
				update_term_meta( $existing->term_id, self::TERM_META_ADDRESS, sanitize_textarea_field( $address ) );
			}
			return (int) $existing->term_id;
		}

		$result = wp_insert_term( $name, self::TAXONOMY );
		if ( is_wp_error( $result ) ) {
			// Parallel angelegt? Dann vorhandene ID verwenden.
			$term_id = $result->get_error_data( 'term_exists' );
			return is_numeric( $term_id ) ? (int) $term_id : null;
		}

		$term_id = (int) $result['term_id'];
		if ( '' !== $address ) {
			update_term_meta( $term_id, self::TERM_META_ADDRESS, sanitize_textarea_field( $address ) );
		}
		return $term_id;
	}

	/* ---------------------------------------------------------------------
	 * Adressfeld in der Orts-Verwaltung
	 * ------------------------------------------------------------------- */

	public function term_add_field(): void {
		wp_nonce_field( 'fmvt_term_address', self::TERM_NONCE );
		?>
		<div class="form-field">
			<label for="fmvt-address"><?php esc_html_e( 'Adresse (optional)', 'fm-wpcalendar' ); ?></label>
			<textarea name="fmvt_address" id="fmvt-address" rows="3" maxlength="500"></textarea>
			<p><?php esc_html_e( 'z. B. Straße, PLZ und Ort. Wird auf der Detailseite angezeigt und mit einer Karte verlinkt.', 'fm-wpcalendar' ); ?></p>
		</div>
		<?php
	}

	public function term_edit_field( WP_Term $term ): void {
		$address = (string) get_term_meta( $term->term_id, self::TERM_META_ADDRESS, true );
		?>
		<tr class="form-field">
			<th scope="row"><label for="fmvt-address"><?php esc_html_e( 'Adresse (optional)', 'fm-wpcalendar' ); ?></label></th>
			<td>
				<?php wp_nonce_field( 'fmvt_term_address', self::TERM_NONCE ); ?>
				<textarea name="fmvt_address" id="fmvt-address" rows="3" maxlength="500"><?php echo esc_textarea( $address ); ?></textarea>
				<p class="description"><?php esc_html_e( 'z. B. Straße, PLZ und Ort.', 'fm-wpcalendar' ); ?></p>
			</td>
		</tr>
		<?php
	}

	/**
	 * Speichert die Adresse eines Ortes (Nonce- und Rechteprüfung).
	 */
	public function term_save( int $term_id ): void {
		if ( ! isset( $_POST[ self::TERM_NONCE ] )
			|| ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST[ self::TERM_NONCE ] ) ), 'fmvt_term_address' ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_term', $term_id ) ) {
			return;
		}
		$address = isset( $_POST['fmvt_address'] ) ? sanitize_textarea_field( wp_unslash( $_POST['fmvt_address'] ) ) : '';
		$address = mb_substr( $address, 0, 500 );

		if ( '' === $address ) {
			delete_term_meta( $term_id, self::TERM_META_ADDRESS );
		} else {
			update_term_meta( $term_id, self::TERM_META_ADDRESS, $address );
		}
	}

	/**
	 * @param array<string, string> $columns Spalten.
	 * @return array<string, string>
	 */
	public function term_columns( array $columns ): array {
		$new = array();
		foreach ( $columns as $key => $label ) {
			if ( 'description' === $key || 'slug' === $key ) {
				continue; // Für Orte nicht relevant.
			}
			$new[ $key ] = $label;
			if ( 'name' === $key ) {
				$new['fmvt_address'] = __( 'Adresse', 'fm-wpcalendar' );
			}
		}
		if ( isset( $new['posts'] ) ) {
			$new['posts'] = __( 'Termine', 'fm-wpcalendar' );
		}
		return $new;
	}

	public function term_column_content( string $content, string $column, int $term_id ): string {
		if ( 'fmvt_address' === $column ) {
			return nl2br( esc_html( (string) get_term_meta( $term_id, self::TERM_META_ADDRESS, true ) ) );
		}
		return $content;
	}
}
