<?php
/**
 * Zentrale Plugin-Klasse: verdrahtet alle Komponenten.
 *
 * @package FrozenMedia\WPCalendar
 */

declare(strict_types=1);

namespace FrozenMedia\WPCalendar;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Plugin {

	/** Name der Plugin-Option (Einstellungen). */
	public const OPTION = 'fmvt_settings';

	private static ?Plugin $instance = null;

	/**
	 * Singleton-Zugriff.
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'init', array( $this, 'load_textdomain' ), 0 );

		( new Post_Type() )->register_hooks();
		( new Frontend() )->register_hooks();

		if ( is_admin() ) {
			( new Admin() )->register_hooks();
			( new Import_Export() )->register_hooks();
		}

		add_action(
			'widgets_init',
			static function (): void {
				register_widget( Widget::class );
			}
		);
	}

	/**
	 * Übersetzungen laden (falls vorhanden).
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain( 'fm-wpcalendar', false, dirname( plugin_basename( FMVT_FILE ) ) . '/languages' );
	}

	/**
	 * Liefert eine einzelne Einstellung.
	 *
	 * @param string $key     Schlüssel.
	 * @param mixed  $default Standardwert.
	 * @return mixed
	 */
	public static function setting( string $key, mixed $default = null ): mixed {
		$settings = get_option( self::OPTION, array() );
		return ( is_array( $settings ) && array_key_exists( $key, $settings ) ) ? $settings[ $key ] : $default;
	}

	/**
	 * Aktivierung: Post-Type registrieren und Permalinks neu aufbauen.
	 */
	public static function activate(): void {
		Post_Type::register_post_type();
		Post_Type::register_taxonomy();
		flush_rewrite_rules();
	}

	/**
	 * Deaktivierung: Permalinks bereinigen.
	 */
	public static function deactivate(): void {
		flush_rewrite_rules();
	}
}
