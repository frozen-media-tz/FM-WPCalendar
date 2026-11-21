<?php
/**
 * Backend: Metabox, Speichern, Listenspalten, Filter, Hinweise und Einstellungen.
 *
 * @package FrozenMedia\WPCalendar
 */

declare(strict_types=1);

namespace FrozenMedia\WPCalendar;

use WP_Post;
use WP_Query;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Admin {

	private const NONCE_ACTION = 'fmvt_save_event';
	private const NONCE_FIELD  = 'fmvt_event_nonce';
	private const NOTICE_KEY   = 'fmvt_notice_';

	public function register_hooks(): void {
		add_action( 'add_meta_boxes_' . Post_Type::POST_TYPE, array( $this, 'add_meta_box' ) );
		add_action( 'edit_form_after_title', array( $this, 'render_after_title_boxes' ) );
		add_action( 'save_post_' . Post_Type::POST_TYPE, array( $this, 'save' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'admin_notices', array( $this, 'notices' ) );

		// Listenansicht.
		add_filter( 'manage_' . Post_Type::POST_TYPE . '_posts_columns', array( $this, 'columns' ) );
		add_action( 'manage_' . Post_Type::POST_TYPE . '_posts_custom_column', array( $this, 'column_content' ), 10, 2 );
		add_filter( 'manage_edit-' . Post_Type::POST_TYPE . '_sortable_columns', array( $this, 'sortable_columns' ) );
		add_action( 'pre_get_posts', array( $this, 'admin_query' ) );
		add_action( 'restrict_manage_posts', array( $this, 'filter_dropdowns' ) );

		// Titel-Platzhalter.
		add_filter( 'enter_title_here', array( $this, 'title_placeholder' ), 10, 2 );

		// Einstellungen.
		add_action( 'admin_menu', array( $this, 'settings_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( FMVT_FILE ), array( $this, 'plugin_links' ) );
	}

	/* ---------------------------------------------------------------------
	 * Metabox
	 * ------------------------------------------------------------------- */

	public function add_meta_box(): void {
		add_meta_box(
			'fmvt_event_details',
			__( 'Termindetails', 'fm-wpcalendar' ),
			array( $this, 'render_meta_box' ),
			Post_Type::POST_TYPE,
			'fmvt_after_title', // Eigener Bereich direkt unter dem Titel.
			'high'
		);
	}

	/**
	 * Gibt den Metabox-Bereich unter dem Titel aus (Datum/Ort vor der Beschreibung).
	 */
	public function render_after_title_boxes( WP_Post $post ): void {
		if ( Post_Type::POST_TYPE !== $post->post_type ) {
			return;
		}
		echo '<div class="fmvt-after-title">';
		do_meta_boxes( get_current_screen(), 'fmvt_after_title', $post );
		echo '</div>';
	}

	public function render_meta_box( WP_Post $post ): void {
		$is_new     = 'auto-draft' === $post->post_status;
		$start      = (string) get_post_meta( $post->ID, Event::META_START_DATE, true );
		$end        = (string) get_post_meta( $post->ID, Event::META_END_DATE, true );
		$start      = '' !== $start ? $start : current_datetime()->format( 'Y-m-d' );
		$multi_day  = '' !== $end && $end !== $start;
		$all_day    = $is_new ? false : '1' === (string) get_post_meta( $post->ID, Event::META_ALL_DAY, true );
		$start_time = (string) get_post_meta( $post->ID, Event::META_START_TIME, true );
		$end_time   = (string) get_post_meta( $post->ID, Event::META_END_TIME, true );

		$terms       = get_the_terms( $post, Post_Type::TAXONOMY );
		$location_id = ( is_array( $terms ) && isset( $terms[0] ) ) ? (int) $terms[0]->term_id : 0;
		$locations   = get_terms(
			array(
				'taxonomy'   => Post_Type::TAXONOMY,
				'hide_empty' => false,
				'orderby'    => 'name',
				'number'     => 1000,
			)
		);
		$locations   = is_array( $locations ) ? $locations : array();
		$tax         = get_taxonomy( Post_Type::TAXONOMY );
		$can_create  = $tax && current_user_can( $tax->cap->edit_terms );

		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );
		?>
		<div class="fmvt-meta">
			<div class="fmvt-row">
				<label class="fmvt-label" for="fmvt-start-date"><?php esc_html_e( 'Datum', 'fm-wpcalendar' ); ?></label>
				<div class="fmvt-field">
					<input type="date" id="fmvt-start-date" name="fmvt[start_date]" value="<?php echo esc_attr( $start ); ?>" required>
					<label class="fmvt-check">
						<input type="checkbox" id="fmvt-multi-day" name="fmvt[multi_day]" value="1" <?php checked( $multi_day ); ?>>
						<?php esc_html_e( 'mehrtägig', 'fm-wpcalendar' ); ?>
					</label>
					<span class="fmvt-end-date-wrap"<?php echo $multi_day ? '' : ' hidden'; ?>>
						<label for="fmvt-end-date"><?php esc_html_e( 'bis', 'fm-wpcalendar' ); ?></label>
						<input type="date" id="fmvt-end-date" name="fmvt[end_date]" value="<?php echo esc_attr( $multi_day ? $end : '' ); ?>" min="<?php echo esc_attr( $start ); ?>">
					</span>
				</div>
			</div>

			<div class="fmvt-row">
				<span class="fmvt-label"><?php esc_html_e( 'Uhrzeit', 'fm-wpcalendar' ); ?></span>
				<div class="fmvt-field">
					<label class="fmvt-check">
						<input type="checkbox" id="fmvt-all-day" name="fmvt[all_day]" value="1" <?php checked( $all_day ); ?>>
						<?php esc_html_e( 'ohne Uhrzeit (ganztägig)', 'fm-wpcalendar' ); ?>
					</label>
					<span class="fmvt-time-wrap"<?php echo $all_day ? ' hidden' : ''; ?>>
						<label for="fmvt-start-time"><?php esc_html_e( 'von', 'fm-wpcalendar' ); ?></label>
						<input type="time" id="fmvt-start-time" name="fmvt[start_time]" value="<?php echo esc_attr( $start_time ); ?>" step="300">
						<label for="fmvt-end-time"><?php esc_html_e( 'bis', 'fm-wpcalendar' ); ?></label>
						<input type="time" id="fmvt-end-time" name="fmvt[end_time]" value="<?php echo esc_attr( $end_time ); ?>" step="300">
						<span class="description"><?php esc_html_e( 'Endzeit optional', 'fm-wpcalendar' ); ?></span>
					</span>
				</div>
			</div>

			<div class="fmvt-row">
				<label class="fmvt-label" for="fmvt-location"><?php esc_html_e( 'Ort', 'fm-wpcalendar' ); ?></label>
				<div class="fmvt-field">
					<select id="fmvt-location" name="fmvt[location]">
						<option value="0"><?php esc_html_e( '— kein Ort —', 'fm-wpcalendar' ); ?></option>
						<?php foreach ( $locations as $loc ) : ?>
							<option value="<?php echo esc_attr( (string) $loc->term_id ); ?>" <?php selected( $location_id, (int) $loc->term_id ); ?>>
								<?php echo esc_html( $loc->name ); ?>
							</option>
						<?php endforeach; ?>
						<?php if ( $can_create ) : ?>
							<option value="new"><?php esc_html_e( '+ Neuen Ort anlegen …', 'fm-wpcalendar' ); ?></option>
						<?php endif; ?>
					</select>

					<?php if ( $can_create ) : ?>
						<div class="fmvt-new-location" hidden>
							<p>
								<label for="fmvt-new-location-name"><?php esc_html_e( 'Name des Ortes', 'fm-wpcalendar' ); ?></label><br>
								<input type="text" id="fmvt-new-location-name" name="fmvt[new_location_name]" class="regular-text" maxlength="190">
							</p>
							<p>
								<label for="fmvt-new-location-address"><?php esc_html_e( 'Adresse (optional)', 'fm-wpcalendar' ); ?></label><br>
								<textarea id="fmvt-new-location-address" name="fmvt[new_location_address]" rows="2" class="large-text" maxlength="500"></textarea>
							</p>
						</div>
					<?php endif; ?>
				</div>
			</div>
		</div>
		<p class="description"><?php esc_html_e( 'Die Beschreibung des Termins wird im Editor darunter eingegeben (optional).', 'fm-wpcalendar' ); ?></p>
		<?php
	}

	/**
	 * Speichert die Termindaten aus der Metabox.
	 */
	public function save( int $post_id, WP_Post $post ): void {
		// Nonce prüfen.
		if ( ! isset( $_POST[ self::NONCE_FIELD ] )
			|| ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ), self::NONCE_ACTION ) ) {
			return;
		}
		// Kein Speichern bei Autosave oder Revisionen.
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		// Rechte prüfen.
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// Eingaben einzeln aus dem Array lesen und bereinigen.
		$raw = isset( $_POST['fmvt'] ) && is_array( $_POST['fmvt'] ) ? wp_unslash( $_POST['fmvt'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Felder werden unten einzeln geprüft.
		$get = static fn( string $key ): string => ( isset( $raw[ $key ] ) && is_scalar( $raw[ $key ] ) ) ? (string) $raw[ $key ] : '';

		$notices = Event::save_meta(
			$post_id,
			array(
				'start_date' => sanitize_text_field( $get( 'start_date' ) ),
				'end_date'   => '1' === $get( 'multi_day' ) ? sanitize_text_field( $get( 'end_date' ) ) : '',
				'all_day'    => '1' === $get( 'all_day' ),
				'start_time' => sanitize_text_field( $get( 'start_time' ) ),
				'end_time'   => sanitize_text_field( $get( 'end_time' ) ),
			)
		);

		// Ort zuordnen bzw. neu anlegen.
		$location = $get( 'location' );
		$tax      = get_taxonomy( Post_Type::TAXONOMY );
		if ( 'new' === $location ) {
			$name = sanitize_text_field( $get( 'new_location_name' ) );
			if ( '' !== $name && $tax && current_user_can( $tax->cap->edit_terms ) ) {
				$term_id = Post_Type::get_or_create_location( $name, sanitize_textarea_field( $get( 'new_location_address' ) ) );
				wp_set_object_terms( $post_id, null !== $term_id ? array( $term_id ) : array(), Post_Type::TAXONOMY );
			} elseif ( '' === $name ) {
				$notices[] = __( 'Für den neuen Ort wurde kein Name angegeben.', 'fm-wpcalendar' );
			}
		} else {
			$term_id = absint( $location );
			if ( $term_id > 0 && term_exists( $term_id, Post_Type::TAXONOMY ) ) {
				wp_set_object_terms( $post_id, array( $term_id ), Post_Type::TAXONOMY );
			} else {
				wp_set_object_terms( $post_id, array(), Post_Type::TAXONOMY );
			}
		}

		if ( array() !== $notices ) {
			set_transient( self::NOTICE_KEY . get_current_user_id(), array_map( 'strval', $notices ), 60 );
		}
	}

	/**
	 * Zeigt Korrekturhinweise nach dem Speichern.
	 */
	public function notices(): void {
		$key     = self::NOTICE_KEY . get_current_user_id();
		$notices = get_transient( $key );
		if ( ! is_array( $notices ) || array() === $notices ) {
			return;
		}
		delete_transient( $key );
		echo '<div class="notice notice-warning is-dismissible">';
		foreach ( $notices as $notice ) {
			echo '<p>' . esc_html( (string) $notice ) . '</p>';
		}
		echo '</div>';
	}

	/**
	 * Admin-Skripte nur auf den Plugin-Seiten laden.
	 */
	public function enqueue( string $hook ): void {
		// Farbwähler (in WordPress enthalten) auf der Einstellungsseite.
		if ( str_ends_with( $hook, '_page_fmvt-settings' ) ) {
			wp_enqueue_style( 'wp-color-picker' );
			wp_enqueue_script( 'wp-color-picker' );
			wp_add_inline_script( 'wp-color-picker', 'jQuery(function($){$(".fmvt-color").wpColorPicker();});' );
		}

		$screen = get_current_screen();
		if ( ! $screen || Post_Type::POST_TYPE !== $screen->post_type ) {
			return;
		}
		wp_enqueue_style( 'fmvt-admin', FMVT_URL . 'assets/css/admin.css', array(), FMVT_VERSION );
		if ( in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			wp_enqueue_script( 'fmvt-admin', FMVT_URL . 'assets/js/admin.js', array(), FMVT_VERSION, true );
		}
	}

	public function title_placeholder( string $text, WP_Post $post ): string {
		return Post_Type::POST_TYPE === $post->post_type ? __( 'Titel des Termins', 'fm-wpcalendar' ) : $text;
	}

	/* ---------------------------------------------------------------------
	 * Listenansicht
	 * ------------------------------------------------------------------- */

	/**
	 * @param array<string, string> $columns Spalten.
	 * @return array<string, string>
	 */
	public function columns( array $columns ): array {
		$new = array();
		foreach ( $columns as $key => $label ) {
			if ( 'date' === $key ) {
				continue; // Veröffentlichungsdatum ist hier verwirrend.
			}
			$new[ $key ] = $label;
			if ( 'title' === $key ) {
				$new['fmvt_date'] = __( 'Termin', 'fm-wpcalendar' );
				$new['fmvt_time'] = __( 'Uhrzeit', 'fm-wpcalendar' );
			}
		}
		return $new;
	}

	public function column_content( string $column, int $post_id ): void {
		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post ) {
			return;
		}
		$event = Event::from_post( $post );
		if ( 'fmvt_date' === $column ) {
			echo esc_html( '' !== $event->start_date ? $event->date_label( false ) : '—' );
		} elseif ( 'fmvt_time' === $column ) {
			echo esc_html( $event->all_day ? __( 'ganztägig', 'fm-wpcalendar' ) : $event->time_label() );
		}
	}

	/**
	 * @param array<string, string> $columns Sortierbare Spalten.
	 * @return array<string, string>
	 */
	public function sortable_columns( array $columns ): array {
		$columns['fmvt_date'] = 'fmvt_start';
		return $columns;
	}

	/**
	 * Sortierung und Zeitraum-Filter in der Backend-Liste.
	 */
	public function admin_query( WP_Query $query ): void {
		if ( ! is_admin() || ! $query->is_main_query() || Post_Type::POST_TYPE !== $query->get( 'post_type' ) ) {
			return;
		}
		global $pagenow;
		if ( 'edit.php' !== $pagenow ) {
			return;
		}

		$orderby = (string) $query->get( 'orderby' );
		if ( '' === $orderby || 'fmvt_start' === $orderby ) {
			$order = strtoupper( (string) $query->get( 'order' ) );
			$order = 'ASC' === $order ? 'ASC' : 'DESC';
			$query->set( 'meta_key', Event::META_START_DATE ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			$query->set( 'orderby', array( 'meta_value' => $order, 'title' => 'ASC' ) );
		}

		// Filter nach Ort (Taxonomie ist nicht öffentlich, daher eigene tax_query).
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reiner Lesefilter.
		$location = isset( $_GET[ Post_Type::TAXONOMY ] ) ? sanitize_title( wp_unslash( $_GET[ Post_Type::TAXONOMY ] ) ) : '';
		if ( '' !== $location && '0' !== $location ) {
			$query->set(
				'tax_query', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
				array(
					array(
						'taxonomy' => Post_Type::TAXONOMY,
						'field'    => 'slug',
						'terms'    => $location,
					),
				)
			);
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reiner Lesefilter.
		$range = isset( $_GET['fmvt_range'] ) ? sanitize_key( wp_unslash( $_GET['fmvt_range'] ) ) : '';
		$today = current_datetime()->format( 'Y-m-d' );
		if ( 'upcoming' === $range ) {
			$query->set(
				'meta_query', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				array(
					array(
						'key'     => Event::META_END_DATE,
						'value'   => $today,
						'compare' => '>=',
					),
				)
			);
		} elseif ( 'past' === $range ) {
			$query->set(
				'meta_query', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				array(
					array(
						'key'     => Event::META_END_DATE,
						'value'   => $today,
						'compare' => '<',
					),
				)
			);
		}
	}

	/**
	 * Filter-Dropdowns (Zeitraum, Ort) über der Liste.
	 */
	public function filter_dropdowns( string $post_type ): void {
		if ( Post_Type::POST_TYPE !== $post_type ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reiner Lesefilter.
		$range   = isset( $_GET['fmvt_range'] ) ? sanitize_key( wp_unslash( $_GET['fmvt_range'] ) ) : '';
		$options = array(
			''         => __( 'Alle Zeiträume', 'fm-wpcalendar' ),
			'upcoming' => __( 'Kommende Termine', 'fm-wpcalendar' ),
			'past'     => __( 'Vergangene Termine', 'fm-wpcalendar' ),
		);
		echo '<label class="screen-reader-text" for="fmvt-range">' . esc_html__( 'Zeitraum', 'fm-wpcalendar' ) . '</label>';
		echo '<select name="fmvt_range" id="fmvt-range">';
		foreach ( $options as $value => $label ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $value ), selected( $range, $value, false ), esc_html( $label ) );
		}
		echo '</select>';

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reiner Lesefilter.
		$current = isset( $_GET[ Post_Type::TAXONOMY ] ) ? sanitize_title( wp_unslash( $_GET[ Post_Type::TAXONOMY ] ) ) : '';
		wp_dropdown_categories(
			array(
				'taxonomy'        => Post_Type::TAXONOMY,
				'name'            => Post_Type::TAXONOMY,
				'value_field'     => 'slug',
				'selected'        => $current,
				'show_option_all' => __( 'Alle Orte', 'fm-wpcalendar' ),
				'hide_empty'      => false,
				'hide_if_empty'   => true,
				'orderby'         => 'name',
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Einstellungen
	 * ------------------------------------------------------------------- */

	public function settings_menu(): void {
		add_submenu_page(
			'edit.php?post_type=' . Post_Type::POST_TYPE,
			__( 'Termine – Einstellungen', 'fm-wpcalendar' ),
			__( 'Einstellungen', 'fm-wpcalendar' ),
			'manage_options',
			'fmvt-settings',
			array( $this, 'render_settings' )
		);
	}

	public function register_settings(): void {
		register_setting(
			'fmvt_settings_group',
			Plugin::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_settings' ),
				'default'           => array(),
				'show_in_rest'      => false,
			)
		);
	}

	/**
	 * @param mixed $input Rohdaten aus dem Formular.
	 * @return array<string, mixed>
	 */
	public function sanitize_settings( mixed $input ): array {
		$input = is_array( $input ) ? $input : array();
		$page  = isset( $input['calendar_page'] ) ? absint( $input['calendar_page'] ) : 0;
		if ( $page > 0 && 'page' !== get_post_type( $page ) ) {
			$page = 0;
		}
		$accent = isset( $input['accent_color'] ) ? sanitize_hex_color( trim( (string) $input['accent_color'] ) ) : '';
		if ( isset( $input['accent_color'] ) && '' !== trim( (string) $input['accent_color'] ) && ! $accent ) {
			add_settings_error( Plugin::OPTION, 'fmvt_accent', __( 'Die Akzentfarbe muss als Hex-Wert angegeben werden (z. B. #960909) und wurde nicht übernommen.', 'fm-wpcalendar' ) );
		}
		$font = isset( $input['font_family'] ) ? Frontend::sanitize_font( (string) $input['font_family'] ) : '';
		if ( isset( $input['font_family'] ) && '' !== trim( (string) $input['font_family'] ) && '' === $font ) {
			add_settings_error( Plugin::OPTION, 'fmvt_font', __( 'Die Schriftart enthält unzulässige Zeichen und wurde nicht übernommen.', 'fm-wpcalendar' ) );
		}
		return array(
			'calendar_page'    => $page,
			'accent_color'     => is_string( $accent ) ? $accent : '',
			'font_family'      => $font,
			'delete_uninstall' => ! empty( $input['delete_uninstall'] ),
		);
	}

	public function render_settings(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$page   = (int) Plugin::setting( 'calendar_page', 0 );
		$delete = (bool) Plugin::setting( 'delete_uninstall', false );
		$font   = (string) Plugin::setting( 'font_family', '' );
		$accent = (string) Plugin::setting( 'accent_color', '' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Termine – Einstellungen', 'fm-wpcalendar' ); ?></h1>
			<?php settings_errors( Plugin::OPTION ); ?>
			<form method="post" action="options.php">
				<?php settings_fields( 'fmvt_settings_group' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="fmvt-calendar-page"><?php esc_html_e( 'Terminseite', 'fm-wpcalendar' ); ?></label></th>
						<td>
							<?php
							wp_dropdown_pages(
								array(
									'name'              => Plugin::OPTION . '[calendar_page]',
									'id'                => 'fmvt-calendar-page',
									'selected'          => $page, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Integer.
									'show_option_none'  => esc_html__( '— keine —', 'fm-wpcalendar' ),
									'option_none_value' => '0',
								)
							);
							?>
							<p class="description"><?php esc_html_e( 'Seite mit dem Termin-Shortcode. Der „Zurück“-Link auf der Detailseite führt hierhin, wenn der Besucher nicht von einer Seite dieser Website kommt. Außerdem verlinkt das Widget auf „Alle Termine“.', 'fm-wpcalendar' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="fmvt-accent"><?php esc_html_e( 'Akzentfarbe', 'fm-wpcalendar' ); ?></label></th>
						<td>
							<input type="text" id="fmvt-accent" class="fmvt-color" name="<?php echo esc_attr( Plugin::OPTION ); ?>[accent_color]" value="<?php echo esc_attr( $accent ); ?>" maxlength="7" placeholder="#960909">
							<p class="description"><?php esc_html_e( 'Optional. Hat Vorrang vor einer per CSS gesetzten Farbe. Leer lassen, um die Standardfarbe bzw. die CSS-Angabe (--fmvt-accent) zu verwenden.', 'fm-wpcalendar' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="fmvt-font"><?php esc_html_e( 'Schriftart', 'fm-wpcalendar' ); ?></label></th>
						<td>
							<input type="text" id="fmvt-font" class="regular-text" name="<?php echo esc_attr( Plugin::OPTION ); ?>[font_family]" value="<?php echo esc_attr( $font ); ?>" placeholder="<?php esc_attr_e( 'leer = Schrift des Themes', 'fm-wpcalendar' ); ?>" maxlength="200">
							<p class="description">
								<?php esc_html_e( 'Optional. Leer lassen, um die Schrift des Themes zu übernehmen. Sonst wie in CSS angeben, z. B. "Open Sans", sans-serif – die Schrift muss vom Theme bereits geladen sein.', 'fm-wpcalendar' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Deinstallation', 'fm-wpcalendar' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( Plugin::OPTION ); ?>[delete_uninstall]" value="1" <?php checked( $delete ); ?>>
								<?php esc_html_e( 'Beim Löschen des Plugins alle Termine und Orte endgültig entfernen', 'fm-wpcalendar' ); ?>
							</label>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>

			<h2><?php esc_html_e( 'Shortcodes', 'fm-wpcalendar' ); ?></h2>
			<p><?php esc_html_e( 'Zum Kopieren in das Feld klicken. Im Editor am besten als normalen Text bzw. in einen Shortcode-Block einfügen, nicht als Code formatieren.', 'fm-wpcalendar' ); ?></p>
			<table class="widefat striped fmvt-help">
				<tbody>
					<tr><td><input type="text" class="fmvt-sc" readonly value="<?php echo esc_attr( '[vereinstermine]' ); ?>" onfocus="this.select()"></td><td><?php esc_html_e( 'Terminliste, 10 Einträge, „Mehr anzeigen“ lädt weitere nach', 'fm-wpcalendar' ); ?></td></tr>
					<tr><td><input type="text" class="fmvt-sc" readonly value="<?php echo esc_attr( '[vereinstermine ansicht="liste" anzahl="5"]' ); ?>" onfocus="this.select()"></td><td><?php esc_html_e( 'Terminliste mit 5 Einträgen je Ladevorgang', 'fm-wpcalendar' ); ?></td></tr>
					<tr><td><input type="text" class="fmvt-sc" readonly value="<?php echo esc_attr( '[vereinstermine ansicht="monat"]' ); ?>" onfocus="this.select()"></td><td><?php esc_html_e( 'Monatskalender, startet im aktuellen Monat, vor- und zurückblätterbar', 'fm-wpcalendar' ); ?></td></tr>
					<tr><td><input type="text" class="fmvt-sc" readonly value="<?php echo esc_attr( '[vereinstermine ansicht="kompakt" anzahl="5"]' ); ?>" onfocus="this.select()"></td><td><?php esc_html_e( 'Kompakte Liste (wie das Widget), z. B. für Seitenleisten im Block-Editor', 'fm-wpcalendar' ); ?></td></tr>
				</tbody>
			</table>
			<p class="description"><?php esc_html_e( 'Statt [vereinstermine] kann auch [fm_wpcalendar] mit denselben Attributen verwendet werden.', 'fm-wpcalendar' ); ?></p>
		</div>
		<?php
	}

	/**
	 * @param string[] $links Links in der Pluginliste.
	 * @return string[]
	 */
	public function plugin_links( array $links ): array {
		$url = admin_url( 'edit.php?post_type=' . Post_Type::POST_TYPE . '&page=fmvt-settings' );
		array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Einstellungen', 'fm-wpcalendar' ) . '</a>' );
		return $links;
	}
}
