<?php
/**
 * Import und Export von Terminen als CSV oder Excel (XLSX).
 *
 * @package FrozenMedia\WPCalendar
 */

declare(strict_types=1);

namespace FrozenMedia\WPCalendar;

use RuntimeException;
use Throwable;
use WP_Query;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Import_Export {

	private const PAGE_SLUG  = 'fmvt-import-export';
	private const CAPABILITY = 'edit_others_posts'; // Redakteure und Administratoren.
	private const MAX_BYTES  = 5 * 1024 * 1024;     // 5 MB.
	private const RESULT_KEY = 'fmvt_import_result_';

	/** Spalten in Export-Reihenfolge. */
	private const COLUMNS = array(
		'titel',
		'beschreibung',
		'start_datum',
		'end_datum',
		'ganztaegig',
		'start_zeit',
		'end_zeit',
		'ort',
		'ort_adresse',
	);

	/** Erlaubte alternative Spaltennamen beim Import (normalisiert). */
	private const ALIASES = array(
		'titel'        => array( 'titel', 'title', 'name', 'termin', 'bezeichnung' ),
		'beschreibung' => array( 'beschreibung', 'description', 'text', 'details', 'inhalt' ),
		'start_datum'  => array( 'startdatum', 'datum', 'date', 'von', 'vondatum', 'beginn', 'start', 'startdate' ),
		'end_datum'    => array( 'enddatum', 'bis', 'bisdatum', 'ende', 'end', 'enddate' ),
		'ganztaegig'   => array( 'ganztaegig', 'ganztagig', 'ganztags', 'allday' ),
		'start_zeit'   => array( 'startzeit', 'zeit', 'uhrzeit', 'vonzeit', 'beginnzeit', 'starttime' ),
		'end_zeit'     => array( 'endzeit', 'biszeit', 'endezeit', 'endtime' ),
		'ort'          => array( 'ort', 'location', 'veranstaltungsort', 'ortname' ),
		'ort_adresse'  => array( 'ortadresse', 'adresse', 'address', 'anschrift' ),
	);

	public function register_hooks(): void {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_post_fmvt_export', array( $this, 'handle_export' ) );
		add_action( 'admin_post_fmvt_import', array( $this, 'handle_import' ) );
	}

	public function menu(): void {
		add_submenu_page(
			'edit.php?post_type=' . Post_Type::POST_TYPE,
			__( 'Termine importieren / exportieren', 'fm-wpcalendar' ),
			__( 'Import / Export', 'fm-wpcalendar' ),
			self::CAPABILITY,
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/* ---------------------------------------------------------------------
	 * Seite
	 * ------------------------------------------------------------------- */

	public function render_page(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'fm-wpcalendar' ), 403 );
		}
		$xlsx   = Xlsx::available();
		$result = get_transient( self::RESULT_KEY . get_current_user_id() );
		if ( is_array( $result ) ) {
			delete_transient( self::RESULT_KEY . get_current_user_id() );
		}
		$action = admin_url( 'admin-post.php' );
		?>
		<div class="wrap fmvt-ie">
			<h1><?php esc_html_e( 'Termine importieren / exportieren', 'fm-wpcalendar' ); ?></h1>

			<?php if ( is_array( $result ) ) : ?>
				<?php $this->render_result( $result ); ?>
			<?php endif; ?>

			<div class="fmvt-ie-grid">
				<div class="card">
					<h2><?php esc_html_e( 'Export', 'fm-wpcalendar' ); ?></h2>
					<form method="post" action="<?php echo esc_url( $action ); ?>">
						<input type="hidden" name="action" value="fmvt_export">
						<?php wp_nonce_field( 'fmvt_export', 'fmvt_export_nonce' ); ?>
						<p>
							<label><input type="radio" name="range" value="all" checked> <?php esc_html_e( 'Alle Termine', 'fm-wpcalendar' ); ?></label><br>
							<label><input type="radio" name="range" value="upcoming"> <?php esc_html_e( 'Nur kommende Termine', 'fm-wpcalendar' ); ?></label>
						</p>
						<p>
							<button type="submit" name="format" value="csv" class="button button-primary"><?php esc_html_e( 'Als CSV exportieren', 'fm-wpcalendar' ); ?></button>
							<?php if ( $xlsx ) : ?>
								<button type="submit" name="format" value="xlsx" class="button"><?php esc_html_e( 'Als Excel (XLSX) exportieren', 'fm-wpcalendar' ); ?></button>
							<?php endif; ?>
						</p>
						<p>
							<button type="submit" name="format" value="template" class="button-link"><?php esc_html_e( 'Leere Vorlage mit Beispielzeilen (CSV) herunterladen', 'fm-wpcalendar' ); ?></button>
						</p>
					</form>
				</div>

				<div class="card">
					<h2><?php esc_html_e( 'Import', 'fm-wpcalendar' ); ?></h2>
					<form method="post" action="<?php echo esc_url( $action ); ?>" enctype="multipart/form-data">
						<input type="hidden" name="action" value="fmvt_import">
						<?php wp_nonce_field( 'fmvt_import', 'fmvt_import_nonce' ); ?>
						<p>
							<label for="fmvt-import-file"><?php echo esc_html( $xlsx ? __( 'CSV- oder Excel-Datei (.csv, .xlsx), max. 5 MB', 'fm-wpcalendar' ) : __( 'CSV-Datei (.csv), max. 5 MB', 'fm-wpcalendar' ) ); ?></label><br>
							<input type="file" id="fmvt-import-file" name="fmvt_file" accept="<?php echo esc_attr( $xlsx ? '.csv,.xlsx' : '.csv' ); ?>" required>
						</p>
						<fieldset>
							<legend><strong><?php esc_html_e( 'Bereits vorhandene Termine (gleicher Titel und gleiches Startdatum)', 'fm-wpcalendar' ); ?></strong></legend>
							<label><input type="radio" name="duplicates" value="skip" checked> <?php esc_html_e( 'überspringen', 'fm-wpcalendar' ); ?></label><br>
							<label><input type="radio" name="duplicates" value="update"> <?php esc_html_e( 'aktualisieren', 'fm-wpcalendar' ); ?></label><br>
							<label><input type="radio" name="duplicates" value="create"> <?php esc_html_e( 'trotzdem neu anlegen', 'fm-wpcalendar' ); ?></label>
						</fieldset>
						<p>
							<label><input type="checkbox" name="as_draft" value="1"> <?php esc_html_e( 'Neue Termine als Entwurf anlegen (zur Prüfung vor Veröffentlichung)', 'fm-wpcalendar' ); ?></label>
						</p>
						<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Importieren', 'fm-wpcalendar' ); ?></button></p>
					</form>
				</div>
			</div>

			<h2><?php esc_html_e( 'Dateiformat', 'fm-wpcalendar' ); ?></h2>
			<p><?php esc_html_e( 'Die erste Zeile muss die Spaltennamen enthalten. Trennzeichen (Semikolon, Komma, Tab) und Zeichensatz (UTF-8 oder Windows/Excel) werden automatisch erkannt.', 'fm-wpcalendar' ); ?></p>
			<table class="widefat striped fmvt-help">
				<thead><tr><th><?php esc_html_e( 'Spalte', 'fm-wpcalendar' ); ?></th><th><?php esc_html_e( 'Inhalt', 'fm-wpcalendar' ); ?></th></tr></thead>
				<tbody>
					<tr><td><code>titel</code></td><td><?php esc_html_e( 'Pflichtfeld', 'fm-wpcalendar' ); ?></td></tr>
					<tr><td><code>beschreibung</code></td><td><?php esc_html_e( 'optional, einfaches HTML erlaubt', 'fm-wpcalendar' ); ?></td></tr>
					<tr><td><code>start_datum</code></td><td><?php esc_html_e( 'Pflichtfeld, z. B. 24.12.2026 oder 2026-12-24', 'fm-wpcalendar' ); ?></td></tr>
					<tr><td><code>end_datum</code></td><td><?php esc_html_e( 'optional, nur bei mehrtägigen Terminen', 'fm-wpcalendar' ); ?></td></tr>
					<tr><td><code>ganztaegig</code></td><td><?php esc_html_e( 'optional: ja/nein bzw. 1/0 – leer = ganztägig, wenn keine Startzeit angegeben ist', 'fm-wpcalendar' ); ?></td></tr>
					<tr><td><code>start_zeit</code>, <code>end_zeit</code></td><td><?php esc_html_e( 'optional, z. B. 18:00', 'fm-wpcalendar' ); ?></td></tr>
					<tr><td><code>ort</code>, <code>ort_adresse</code></td><td><?php esc_html_e( 'optional – unbekannte Orte werden automatisch angelegt', 'fm-wpcalendar' ); ?></td></tr>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * @param array<string, mixed> $result Importergebnis.
	 */
	private function render_result( array $result ): void {
		$errors = isset( $result['errors'] ) && is_array( $result['errors'] ) ? $result['errors'] : array();
		$class  = ! empty( $result['fatal'] ) ? 'notice-error' : ( array() === $errors ? 'notice-success' : 'notice-warning' );
		echo '<div class="notice ' . esc_attr( $class ) . '">';
		if ( ! empty( $result['fatal'] ) ) {
			echo '<p><strong>' . esc_html( (string) $result['fatal'] ) . '</strong></p>';
		} else {
			echo '<p><strong>' . esc_html(
				sprintf(
					/* translators: 1: neu, 2: aktualisiert, 3: übersprungen, 4: fehlerhaft */
					__( 'Import abgeschlossen: %1$d neu, %2$d aktualisiert, %3$d übersprungen, %4$d fehlerhaft.', 'fm-wpcalendar' ),
					(int) ( $result['created'] ?? 0 ),
					(int) ( $result['updated'] ?? 0 ),
					(int) ( $result['skipped'] ?? 0 ),
					(int) ( $result['failed'] ?? 0 )
				)
			) . '</strong></p>';
		}
		if ( array() !== $errors ) {
			echo '<ul class="fmvt-errors">';
			foreach ( array_slice( $errors, 0, 30 ) as $error ) {
				echo '<li>' . esc_html( (string) $error ) . '</li>';
			}
			if ( count( $errors ) > 30 ) {
				echo '<li>' . esc_html__( '… weitere Meldungen ausgeblendet.', 'fm-wpcalendar' ) . '</li>';
			}
			echo '</ul>';
		}
		echo '</div>';
	}

	/* ---------------------------------------------------------------------
	 * Export
	 * ------------------------------------------------------------------- */

	public function handle_export(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'fm-wpcalendar' ), 403 );
		}
		check_admin_referer( 'fmvt_export', 'fmvt_export_nonce' );

		$format = isset( $_POST['format'] ) ? sanitize_key( wp_unslash( $_POST['format'] ) ) : 'csv';
		$range  = isset( $_POST['range'] ) ? sanitize_key( wp_unslash( $_POST['range'] ) ) : 'all';

		if ( 'template' === $format ) {
			$rows   = array( self::COLUMNS );
			$rows[] = array( 'Jahreshauptversammlung', 'Alle Mitglieder sind herzlich eingeladen.', '20.03.2027', '', 'nein', '19:00', '21:30', 'Vereinsheim', 'Sportplatzweg 1, 12345 Musterstadt' );
			$rows[] = array( 'Sommerfest', '', '10.07.2027', '11.07.2027', 'ja', '', '', 'Festwiese', '' );
			$rows[] = array( 'Arbeitseinsatz', '', '05.04.2027', '', 'nein', '09:00', '', 'Vereinsheim', '' );
			$this->send_csv( $rows, 'termine-vorlage.csv' );
		}

		$events = Event::all_for_export();
		if ( 'upcoming' === $range ) {
			$today  = current_datetime()->format( 'Y-m-d' );
			$events = array_values( array_filter( $events, static fn( Event $e ): bool => $e->end_date >= $today ) );
		}

		$rows = array( self::COLUMNS );
		foreach ( $events as $event ) {
			$post   = get_post( $event->id );
			$rows[] = array(
				$post ? (string) $post->post_title : $event->title, // Rohtitel ohne Typografie-Umwandlung.
				$post ? (string) $post->post_content : '',
				Event::format_date( $event->start_date, 'd.m.Y' ),
				$event->is_multi_day() ? Event::format_date( $event->end_date, 'd.m.Y' ) : '',
				$event->all_day ? 'ja' : 'nein',
				$event->start_time,
				$event->end_time,
				$event->location_name,
				$event->location_address,
			);
		}

		// Schutz vor CSV-/Formel-Injection beim Öffnen in Excel.
		$rows = array_map( static fn( array $row ): array => array_map( array( self::class, 'escape_formula' ), $row ), $rows );

		$filename = 'termine-' . current_datetime()->format( 'Y-m-d' );
		if ( 'xlsx' === $format && Xlsx::available() ) {
			$this->send_xlsx( $rows, $filename . '.xlsx' );
		}
		$this->send_csv( $rows, $filename . '.csv' );
	}

	/**
	 * Setzt vor gefährliche Startzeichen ein Hochkomma, damit Excel keine Formel ausführt.
	 */
	public static function escape_formula( string $value ): string {
		if ( '' !== $value && in_array( $value[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ) {
			return "'" . $value;
		}
		return $value;
	}

	/**
	 * Gemeinsame Download-Header.
	 */
	private function download_headers( string $filename, string $mime ): void {
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}
		nocache_headers();
		header( 'Content-Type: ' . $mime );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $filename ) . '"' );
		header( 'X-Content-Type-Options: nosniff' );
	}

	/**
	 * @param array<int, array<int, string>> $rows Zeilen.
	 */
	private function send_csv( array $rows, string $filename ): never {
		$this->download_headers( $filename, 'text/csv; charset=UTF-8' );
		$out = fopen( 'php://output', 'wb' );
		if ( false === $out ) {
			exit;
		}
		fwrite( $out, "\xEF\xBB\xBF" ); // UTF-8-BOM, damit Excel Umlaute korrekt anzeigt.
		foreach ( $rows as $row ) {
			fputcsv( $out, $row, ';', '"', '', "\r\n" );
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}

	/**
	 * @param array<int, array<int, string>> $rows Zeilen.
	 */
	private function send_xlsx( array $rows, string $filename ): never {
		$tmp = wp_tempnam( 'fmvt-export' );
		try {
			Xlsx::write( $rows, $tmp, __( 'Termine', 'fm-wpcalendar' ) );
			$this->download_headers( $filename, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' );
			header( 'Content-Length: ' . (string) filesize( $tmp ) );
			readfile( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		} catch ( Throwable $e ) {
			wp_die( esc_html__( 'Die Excel-Datei konnte nicht erstellt werden.', 'fm-wpcalendar' ) );
		} finally {
			wp_delete_file( $tmp );
		}
		exit;
	}

	/* ---------------------------------------------------------------------
	 * Import
	 * ------------------------------------------------------------------- */

	public function handle_import(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'fm-wpcalendar' ), 403 );
		}
		check_admin_referer( 'fmvt_import', 'fmvt_import_nonce' );

		$duplicates = isset( $_POST['duplicates'] ) ? sanitize_key( wp_unslash( $_POST['duplicates'] ) ) : 'skip';
		$duplicates = in_array( $duplicates, array( 'skip', 'update', 'create' ), true ) ? $duplicates : 'skip';
		$as_draft   = ! empty( $_POST['as_draft'] );

		try {
			$rows   = $this->read_upload();
			$result = $this->import_rows( $rows, $duplicates, $as_draft );
		} catch ( RuntimeException $e ) {
			$result = array( 'fatal' => $e->getMessage() );
		}

		set_transient( self::RESULT_KEY . get_current_user_id(), $result, 5 * MINUTE_IN_SECONDS );
		wp_safe_redirect( admin_url( 'edit.php?post_type=' . Post_Type::POST_TYPE . '&page=' . self::PAGE_SLUG ) );
		exit;
	}

	/**
	 * Prüft den Upload und liest die Zeilen (Datei wird nie dauerhaft gespeichert).
	 *
	 * @return array<int, array<int, string>>
	 */
	private function read_upload(): array {
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput -- Upload-Array wird unten feldweise geprüft.
		$file = $_FILES['fmvt_file'] ?? null;
		// phpcs:enable

		if ( ! is_array( $file ) || ! isset( $file['error'], $file['tmp_name'], $file['name'], $file['size'] ) || is_array( $file['error'] ) ) {
			throw new RuntimeException( __( 'Es wurde keine Datei hochgeladen.', 'fm-wpcalendar' ) );
		}
		if ( UPLOAD_ERR_OK !== (int) $file['error'] ) {
			throw new RuntimeException( __( 'Beim Hochladen ist ein Fehler aufgetreten (evtl. Datei zu groß).', 'fm-wpcalendar' ) );
		}
		$tmp = (string) $file['tmp_name'];
		if ( ! is_uploaded_file( $tmp ) ) {
			throw new RuntimeException( __( 'Ungültiger Upload.', 'fm-wpcalendar' ) );
		}
		$size = (int) filesize( $tmp );
		if ( $size <= 0 || $size > self::MAX_BYTES ) {
			throw new RuntimeException( __( 'Die Datei ist leer oder größer als 5 MB.', 'fm-wpcalendar' ) );
		}

		$ext = strtolower( pathinfo( sanitize_file_name( (string) $file['name'] ), PATHINFO_EXTENSION ) );

		if ( 'xlsx' === $ext ) {
			// Inhalt prüfen: XLSX ist ein ZIP-Archiv ("PK"-Signatur).
			$handle = fopen( $tmp, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
			$magic  = false !== $handle ? (string) fread( $handle, 4 ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
			if ( false !== $handle ) {
				fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			}
			if ( "PK\x03\x04" !== $magic ) {
				throw new RuntimeException( __( 'Die Datei ist keine gültige Excel-Datei (.xlsx).', 'fm-wpcalendar' ) );
			}
			return Xlsx::read( $tmp );
		}

		if ( in_array( $ext, array( 'csv', 'txt' ), true ) ) {
			return $this->read_csv( $tmp );
		}

		throw new RuntimeException( __( 'Nicht unterstütztes Dateiformat. Erlaubt sind .csv und .xlsx.', 'fm-wpcalendar' ) );
	}

	/**
	 * Liest eine CSV-Datei mit automatischer Erkennung von Zeichensatz und Trennzeichen.
	 *
	 * @return array<int, array<int, string>>
	 */
	private function read_csv( string $path ): array {
		$content = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( str_contains( $content, "\0" ) ) {
			throw new RuntimeException( __( 'Die Datei ist keine Textdatei.', 'fm-wpcalendar' ) );
		}

		// BOM entfernen und Zeichensatz vereinheitlichen.
		if ( str_starts_with( $content, "\xEF\xBB\xBF" ) ) {
			$content = substr( $content, 3 );
		}
		if ( ! mb_check_encoding( $content, 'UTF-8' ) ) {
			$content = (string) mb_convert_encoding( $content, 'UTF-8', 'Windows-1252' );
		}

		// Trennzeichen anhand der Kopfzeile ermitteln.
		$first_line = strtok( $content, "\r\n" );
		$first_line = false === $first_line ? '' : $first_line;
		$counts     = array(
			';'  => substr_count( $first_line, ';' ),
			','  => substr_count( $first_line, ',' ),
			"\t" => substr_count( $first_line, "\t" ),
		);
		arsort( $counts );
		$delimiter = (string) array_key_first( $counts );

		$stream = fopen( 'php://temp', 'r+b' );
		if ( false === $stream ) {
			throw new RuntimeException( __( 'Die Datei konnte nicht gelesen werden.', 'fm-wpcalendar' ) );
		}
		fwrite( $stream, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		rewind( $stream );

		$rows = array();
		while ( false !== ( $row = fgetcsv( $stream, null, $delimiter, '"', '' ) ) ) {
			if ( count( $rows ) > Xlsx::MAX_ROWS ) {
				break;
			}
			$rows[] = array_map( static fn( $v ): string => (string) $v, $row );
		}
		fclose( $stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		return $rows;
	}

	/**
	 * Verarbeitet die eingelesenen Zeilen.
	 *
	 * @param array<int, array<int, string>> $rows Zeilen inkl. Kopfzeile.
	 * @return array<string, mixed> Ergebnis.
	 */
	private function import_rows( array $rows, string $duplicates, bool $as_draft ): array {
		if ( count( $rows ) < 2 ) {
			throw new RuntimeException( __( 'Die Datei enthält keine Termine (mindestens Kopfzeile und eine Datenzeile erforderlich).', 'fm-wpcalendar' ) );
		}

		$map = $this->map_header( array_shift( $rows ) );
		if ( ! isset( $map['titel'], $map['start_datum'] ) ) {
			throw new RuntimeException( __( 'Die Spalten „titel“ und „start_datum“ wurden in der Kopfzeile nicht gefunden.', 'fm-wpcalendar' ) );
		}

		$result = array(
			'created' => 0,
			'updated' => 0,
			'skipped' => 0,
			'failed'  => 0,
			'errors'  => array(),
		);

		// Rechte für das Anlegen von Orten und das Veröffentlichen.
		$tax             = get_taxonomy( Post_Type::TAXONOMY );
		$can_create_term = $tax && current_user_can( $tax->cap->edit_terms );
		$status          = ( $as_draft || ! current_user_can( 'publish_posts' ) ) ? 'draft' : 'publish';

		// Beim Massenimport Zähler-Updates aussetzen (Performance).
		wp_defer_term_counting( true );

		foreach ( $rows as $index => $row ) {
			$line = $index + 2; // Zeilennummer in der Datei (1 = Kopfzeile).
			$get  = static function ( string $key ) use ( $map, $row ): string {
				if ( ! isset( $map[ $key ] ) ) {
					return '';
				}
				$value = trim( (string) ( $row[ $map[ $key ] ] ?? '' ) );
				// Beim Export gesetztes Schutz-Hochkomma wieder entfernen.
				if ( 1 === preg_match( "/^'[=+\-@\t\r]/", $value ) ) {
					$value = substr( $value, 1 );
				}
				return $value;
			};

			// Leere Zeilen überspringen.
			if ( '' === implode( '', array_map( 'trim', $row ) ) ) {
				continue;
			}

			$title = mb_substr( sanitize_text_field( $get( 'titel' ) ), 0, 250 );
			$start = $this->parse_date( $get( 'start_datum' ) );
			if ( '' === $title || '' === $start ) {
				++$result['failed'];
				/* translators: %d: Zeilennummer */
				$result['errors'][] = sprintf( __( 'Zeile %d: Titel oder gültiges Startdatum fehlt.', 'fm-wpcalendar' ), $line );
				continue;
			}

			$end_raw = $get( 'end_datum' );
			$end     = $this->parse_date( $end_raw );
			if ( '' !== $end_raw && '' === $end ) {
				/* translators: %d: Zeilennummer */
				$result['errors'][] = sprintf( __( 'Zeile %d: Enddatum ungültig – Termin als eintägig importiert.', 'fm-wpcalendar' ), $line );
			}

			$start_time = $this->parse_time( $get( 'start_zeit' ) );
			$end_time   = $this->parse_time( $get( 'end_zeit' ) );
			$all_day    = $this->parse_bool( $get( 'ganztaegig' ) ) ?? ( '' === $start_time );

			// Duplikate erkennen.
			$existing = 'create' === $duplicates ? 0 : $this->find_duplicate( $title, $start );
			if ( $existing > 0 && 'skip' === $duplicates ) {
				++$result['skipped'];
				continue;
			}
			if ( $existing > 0 && ! current_user_can( 'edit_post', $existing ) ) {
				++$result['skipped'];
				continue;
			}

			$postarr = array(
				'post_type'    => Post_Type::POST_TYPE,
				'post_title'   => $title,
				'post_content' => wp_kses_post( $get( 'beschreibung' ) ),
			);
			if ( $existing > 0 ) {
				$postarr['ID'] = $existing;
				$post_id       = wp_update_post( wp_slash( $postarr ), true );
			} else {
				$postarr['post_status'] = $status;
				$postarr['post_author'] = get_current_user_id();
				$post_id                = wp_insert_post( wp_slash( $postarr ), true );
			}

			if ( is_wp_error( $post_id ) || 0 === (int) $post_id ) {
				++$result['failed'];
				/* translators: %d: Zeilennummer */
				$result['errors'][] = sprintf( __( 'Zeile %d: Termin konnte nicht gespeichert werden.', 'fm-wpcalendar' ), $line );
				continue;
			}
			$post_id = (int) $post_id;

			Event::save_meta(
				$post_id,
				array(
					'start_date' => $start,
					'end_date'   => $end,
					'all_day'    => $all_day,
					'start_time' => $start_time,
					'end_time'   => $end_time,
				)
			);

			// Ort zuordnen bzw. anlegen.
			$location = $get( 'ort' );
			if ( '' !== $location ) {
				$term = get_term_by( 'name', sanitize_text_field( $location ), Post_Type::TAXONOMY );
				if ( $term ) {
					$term_id = (int) $term->term_id;
				} elseif ( $can_create_term ) {
					$term_id = Post_Type::get_or_create_location( $location, sanitize_textarea_field( $get( 'ort_adresse' ) ) );
				} else {
					$term_id = null;
					/* translators: %d: Zeilennummer */
					$result['errors'][] = sprintf( __( 'Zeile %d: Ort unbekannt und keine Berechtigung zum Anlegen.', 'fm-wpcalendar' ), $line );
				}
				wp_set_object_terms( $post_id, null !== $term_id ? array( $term_id ) : array(), Post_Type::TAXONOMY );
			} elseif ( $existing > 0 ) {
				wp_set_object_terms( $post_id, array(), Post_Type::TAXONOMY );
			}

			$existing > 0 ? ++$result['updated'] : ++$result['created'];
		}

		wp_defer_term_counting( false );

		return $result;
	}

	/**
	 * Ordnet Spaltennamen den internen Feldern zu.
	 *
	 * @param array<int, string> $header Kopfzeile.
	 * @return array<string, int> Feld => Spaltenindex.
	 */
	private function map_header( array $header ): array {
		$map = array();
		foreach ( $header as $index => $name ) {
			$normalized = strtolower( remove_accents( trim( (string) $name ) ) );
			$normalized = (string) preg_replace( '/[^a-z0-9]/', '', $normalized );
			foreach ( self::ALIASES as $field => $aliases ) {
				if ( ! isset( $map[ $field ] ) && in_array( $normalized, $aliases, true ) ) {
					$map[ $field ] = (int) $index;
					break;
				}
			}
		}
		return $map;
	}

	/**
	 * Datum aus Text oder Excel-Seriennummer.
	 */
	private function parse_date( string $value ): string {
		$date = Event::sanitize_date( $value );
		if ( '' === $date && 1 === preg_match( '/^\d{4,6}(\.\d+)?$/', $value ) ) {
			$date = Event::sanitize_date( Xlsx::serial_to_date( $value ) );
		}
		// Excel liefert manchmal "2026-12-24 00:00:00".
		if ( '' === $date && 1 === preg_match( '/^(\d{4}-\d{2}-\d{2})[ T]/', $value, $m ) ) {
			$date = Event::sanitize_date( $m[1] );
		}
		return $date;
	}

	/**
	 * Uhrzeit aus Text oder Excel-Tagesbruchteil.
	 */
	private function parse_time( string $value ): string {
		$time = Event::sanitize_time( $value );
		if ( '' === $time && 1 === preg_match( '/^0?\.\d+$/', $value ) ) {
			$time = Xlsx::fraction_to_time( $value );
		}
		return $time;
	}

	/**
	 * Ja/Nein-Werte. Null = nicht angegeben.
	 */
	private function parse_bool( string $value ): ?bool {
		$value = strtolower( trim( $value ) );
		if ( '' === $value ) {
			return null;
		}
		if ( in_array( $value, array( '1', 'ja', 'j', 'yes', 'y', 'true', 'wahr', 'x' ), true ) ) {
			return true;
		}
		if ( in_array( $value, array( '0', 'nein', 'n', 'no', 'false', 'falsch' ), true ) ) {
			return false;
		}
		return null;
	}

	/**
	 * Sucht einen vorhandenen Termin mit gleichem Titel und Startdatum.
	 */
	private function find_duplicate( string $title, string $start ): int {
		$query = new WP_Query(
			array(
				'post_type'      => Post_Type::POST_TYPE,
				'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future' ),
				'title'          => $title,
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'   => Event::META_START_DATE,
						'value' => $start,
					),
				),
			)
		);
		return isset( $query->posts[0] ) ? (int) $query->posts[0] : 0;
	}
}
