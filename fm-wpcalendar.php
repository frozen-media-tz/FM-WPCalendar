<?php
/**
 * Plugin Name:       FM-WPCalendar
 * Plugin URI:        https://www.frozen-media.de
 * Description:       Einfache Terminverwaltung für Vereine: Termine mit Datum, Uhrzeit, Ort und Beschreibung. Monatskalender und Terminliste per Shortcode, Sidebar-Widget sowie CSV-/Excel-Import und -Export.
 * Version:           1.0.2
 * Requires at least: 6.4
 * Requires PHP:      8.2
 * Author:            Frozen-Media
 * Author URI:        https://www.frozen-media.de
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       fm-wpcalendar
 * Domain Path:       /languages
 */

declare(strict_types=1);

// Direkten Aufruf der Datei verhindern.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Plugin-Konstanten.
define( 'FMVT_VERSION', '1.0.2' );
define( 'FMVT_FILE', __FILE__ );
define( 'FMVT_DIR', plugin_dir_path( __FILE__ ) );
define( 'FMVT_URL', plugin_dir_url( __FILE__ ) );

// Sicherheitsnetz: Bei zu alter PHP-Version nichts laden, nur Hinweis anzeigen.
if ( version_compare( PHP_VERSION, '8.2', '<' ) ) {
	add_action(
		'admin_notices',
		static function (): void {
			echo '<div class="notice notice-error"><p>'
				. esc_html__( 'FM-WPCalendar benötigt mindestens PHP 8.2.', 'fm-wpcalendar' )
				. '</p></div>';
		}
	);
	return;
}

require_once FMVT_DIR . 'includes/class-event.php';
require_once FMVT_DIR . 'includes/class-post-type.php';
require_once FMVT_DIR . 'includes/class-xlsx.php';
require_once FMVT_DIR . 'includes/class-import-export.php';
require_once FMVT_DIR . 'includes/class-admin.php';
require_once FMVT_DIR . 'includes/class-frontend.php';
require_once FMVT_DIR . 'includes/class-widget.php';
require_once FMVT_DIR . 'includes/class-plugin.php';

register_activation_hook( __FILE__, array( \FrozenMedia\WPCalendar\Plugin::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( \FrozenMedia\WPCalendar\Plugin::class, 'deactivate' ) );

\FrozenMedia\WPCalendar\Plugin::instance();
