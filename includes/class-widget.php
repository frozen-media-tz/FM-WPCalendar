<?php
/**
 * Sidebar-Widget "Nächste Termine".
 *
 * @package FrozenMedia\WPCalendar
 */

declare(strict_types=1);

namespace FrozenMedia\WPCalendar;

use WP_Widget;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Widget extends WP_Widget {

	private const DEFAULT_COUNT = 5;

	public function __construct() {
		parent::__construct(
			'fmvt_upcoming',
			__( 'Nächste Termine', 'fm-wpcalendar' ),
			array(
				'classname'                   => 'fmvt-widget',
				'description'                 => __( 'Zeigt die nächsten Vereinstermine als kompakte Liste.', 'fm-wpcalendar' ),
				'customize_selective_refresh' => true,
				'show_instance_in_rest'       => false,
			)
		);
	}

	/**
	 * Ausgabe im Frontend.
	 *
	 * @param array<string, mixed> $args     Widget-Rahmen (vom Theme).
	 * @param array<string, mixed> $instance Einstellungen.
	 */
	public function widget( $args, $instance ): void {
		$title    = isset( $instance['title'] ) ? (string) $instance['title'] : __( 'Nächste Termine', 'fm-wpcalendar' );
		$title    = (string) apply_filters( 'widget_title', $title, $instance, $this->id_base );
		$count    = isset( $instance['count'] ) ? max( 1, min( 50, absint( $instance['count'] ) ) ) : self::DEFAULT_COUNT;
		$all_link = ! empty( $instance['all_link'] );

		// $args stammen vom Theme (register_sidebar) und sind vertrauenswürdiges Markup.
		echo $args['before_widget'] ?? ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		if ( '' !== $title ) {
			echo ( $args['before_title'] ?? '' ) . esc_html( $title ) . ( $args['after_title'] ?? '' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		echo Frontend::render_compact( $count, $all_link ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- In render_compact() escaped.
		echo $args['after_widget'] ?? ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Formular im Backend.
	 *
	 * @param array<string, mixed> $instance Einstellungen.
	 */
	public function form( $instance ): string {
		$title    = isset( $instance['title'] ) ? (string) $instance['title'] : __( 'Nächste Termine', 'fm-wpcalendar' );
		$count    = isset( $instance['count'] ) ? absint( $instance['count'] ) : self::DEFAULT_COUNT;
		$all_link = ! empty( $instance['all_link'] );
		?>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>"><?php esc_html_e( 'Titel:', 'fm-wpcalendar' ); ?></label>
			<input class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'title' ) ); ?>" type="text" value="<?php echo esc_attr( $title ); ?>">
		</p>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'count' ) ); ?>"><?php esc_html_e( 'Anzahl der Termine:', 'fm-wpcalendar' ); ?></label>
			<input class="tiny-text" id="<?php echo esc_attr( $this->get_field_id( 'count' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'count' ) ); ?>" type="number" min="1" max="50" step="1" value="<?php echo esc_attr( (string) $count ); ?>">
		</p>
		<p>
			<input class="checkbox" id="<?php echo esc_attr( $this->get_field_id( 'all_link' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'all_link' ) ); ?>" type="checkbox" value="1" <?php checked( $all_link ); ?>>
			<label for="<?php echo esc_attr( $this->get_field_id( 'all_link' ) ); ?>"><?php esc_html_e( 'Link „Alle Termine“ anzeigen (Terminseite in den Einstellungen festlegen)', 'fm-wpcalendar' ); ?></label>
		</p>
		<?php
		return '';
	}

	/**
	 * Speichern der Einstellungen.
	 *
	 * @param array<string, mixed> $new_instance Neue Werte.
	 * @param array<string, mixed> $old_instance Alte Werte.
	 * @return array<string, mixed>
	 */
	public function update( $new_instance, $old_instance ): array {
		$count = isset( $new_instance['count'] ) ? absint( $new_instance['count'] ) : self::DEFAULT_COUNT;
		return array(
			'title'    => isset( $new_instance['title'] ) ? sanitize_text_field( (string) $new_instance['title'] ) : '',
			'count'    => $count > 0 ? min( 50, $count ) : self::DEFAULT_COUNT,
			'all_link' => ! empty( $new_instance['all_link'] ),
		);
	}
}
