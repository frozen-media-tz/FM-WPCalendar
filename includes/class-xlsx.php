<?php
/**
 * Minimaler, abhängigkeitsfreier XLSX-Leser und -Schreiber (nur erstes Tabellenblatt).
 *
 * Benötigt die PHP-Erweiterung "zip" (ZipArchive), die bei nahezu allen Hostern aktiv ist.
 *
 * @package FrozenMedia\WPCalendar
 */

declare(strict_types=1);

namespace FrozenMedia\WPCalendar;

use RuntimeException;
use SimpleXMLElement;
use ZipArchive;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Xlsx {

	/** Maximale entpackte Größe je XML-Datei (Schutz vor "Zip-Bomben"). */
	private const MAX_XML_BYTES = 20 * 1024 * 1024;

	/** Maximale Anzahl gelesener Zeilen. */
	public const MAX_ROWS = 5000;

	public static function available(): bool {
		return class_exists( ZipArchive::class );
	}

	/* ---------------------------------------------------------------------
	 * Schreiben
	 * ------------------------------------------------------------------- */

	/**
	 * Erzeugt eine XLSX-Datei aus Zeilen (erste Zeile = Kopfzeile, fett).
	 *
	 * @param array<int, array<int, string>> $rows Zeilen.
	 * @param string                         $file Zielpfad.
	 */
	public static function write( array $rows, string $file, string $sheet_name = 'Termine' ): void {
		if ( ! self::available() ) {
			throw new RuntimeException( 'ZipArchive nicht verfügbar.' );
		}

		$sheet_rows = '';
		$col_widths = array();
		foreach ( array_values( $rows ) as $r => $row ) {
			$cells = '';
			foreach ( array_values( $row ) as $c => $value ) {
				$value              = self::clean_xml_string( (string) $value );
				$col_widths[ $c ]   = max( $col_widths[ $c ] ?? 8, min( 60, mb_strlen( $value ) + 2 ) );
				$ref                = self::column_letter( $c ) . ( $r + 1 );
				$style              = 0 === $r ? ' s="1"' : '';
				$cells             .= '<c r="' . $ref . '" t="inlineStr"' . $style . '><is><t xml:space="preserve">'
					. htmlspecialchars( $value, ENT_XML1 | ENT_QUOTES, 'UTF-8' ) . '</t></is></c>';
			}
			$sheet_rows .= '<row r="' . ( $r + 1 ) . '">' . $cells . '</row>';
		}

		$cols = '';
		foreach ( $col_widths as $c => $width ) {
			$cols .= '<col min="' . ( $c + 1 ) . '" max="' . ( $c + 1 ) . '" width="' . $width . '" customWidth="1"/>';
		}

		$sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
			. '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
			. ( '' !== $cols ? '<cols>' . $cols . '</cols>' : '' )
			. '<sheetData>' . $sheet_rows . '</sheetData></worksheet>';

		$sheet_name = htmlspecialchars( mb_substr( $sheet_name, 0, 31 ), ENT_XML1 | ENT_QUOTES, 'UTF-8' );

		$files = array(
			'[Content_Types].xml'        => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
				. '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
				. '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
				. '<Default Extension="xml" ContentType="application/xml"/>'
				. '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
				. '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
				. '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
				. '</Types>',
			'_rels/.rels'                => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
				. '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
				. '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
				. '</Relationships>',
			'xl/workbook.xml'            => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
				. '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
				. '<sheets><sheet name="' . $sheet_name . '" sheetId="1" r:id="rId1"/></sheets></workbook>',
			'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
				. '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
				. '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
				. '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
				. '</Relationships>',
			'xl/styles.xml'              => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
				. '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
				. '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
				. '<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
				. '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
				. '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
				. '<cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
				. '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/></cellXfs>'
				. '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
				. '</styleSheet>',
			'xl/worksheets/sheet1.xml'   => $sheet,
		);

		$zip = new ZipArchive();
		if ( true !== $zip->open( $file, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
			throw new RuntimeException( 'XLSX-Datei konnte nicht erstellt werden.' );
		}
		foreach ( $files as $name => $content ) {
			$zip->addFromString( $name, $content );
		}
		$zip->close();
	}

	/* ---------------------------------------------------------------------
	 * Lesen
	 * ------------------------------------------------------------------- */

	/**
	 * Liest das erste Tabellenblatt einer XLSX-Datei.
	 *
	 * @return array<int, array<int, string>> Zeilen mit Zellwerten als String.
	 */
	public static function read( string $file ): array {
		if ( ! self::available() ) {
			throw new RuntimeException( __( 'Die PHP-Erweiterung „zip“ fehlt – Excel-Import nicht möglich.', 'fm-wpcalendar' ) );
		}

		$zip = new ZipArchive();
		if ( true !== $zip->open( $file, ZipArchive::RDONLY ) ) {
			throw new RuntimeException( __( 'Die Excel-Datei konnte nicht geöffnet werden.', 'fm-wpcalendar' ) );
		}

		try {
			$sheet_path = self::first_sheet_path( $zip );
			$shared     = self::shared_strings( $zip );
			$xml        = self::load_xml( $zip, $sheet_path );
			if ( null === $xml || ! isset( $xml->sheetData ) ) {
				throw new RuntimeException( __( 'Kein Tabellenblatt in der Excel-Datei gefunden.', 'fm-wpcalendar' ) );
			}

			$rows = array();
			foreach ( $xml->sheetData->row as $row ) {
				if ( count( $rows ) >= self::MAX_ROWS + 1 ) {
					break;
				}
				$cells = array();
				$next  = 0;
				foreach ( $row->c as $cell ) {
					$ref   = (string) $cell['r'];
					$index = '' !== $ref ? self::column_index( $ref ) : $next;
					if ( $index < 0 || $index > 100 ) {
						continue; // Unsinnige Spalten ignorieren.
					}
					$next  = $index + 1;
					$type  = (string) $cell['t'];
					$value = '';

					if ( 's' === $type ) {
						$value = $shared[ (int) $cell->v ] ?? '';
					} elseif ( 'inlineStr' === $type ) {
						$value = self::rich_text( $cell->is );
					} elseif ( 'b' === $type ) {
						$value = '1' === (string) $cell->v ? '1' : '0';
					} else {
						$value = (string) $cell->v;
					}

					// Lücken mit leeren Zellen auffüllen.
					for ( $i = count( $cells ); $i < $index; $i++ ) {
						$cells[ $i ] = '';
					}
					$cells[ $index ] = $value;
				}
				$rows[] = $cells;
			}
			return $rows;
		} finally {
			$zip->close();
		}
	}

	/**
	 * Ermittelt den Pfad des ersten Tabellenblatts über die Beziehungsdateien.
	 */
	private static function first_sheet_path( ZipArchive $zip ): string {
		$default  = 'xl/worksheets/sheet1.xml';
		$workbook = self::load_xml( $zip, 'xl/workbook.xml' );
		$rels     = self::load_xml( $zip, 'xl/_rels/workbook.xml.rels' );
		if ( null === $workbook || null === $rels || ! isset( $workbook->sheets->sheet[0] ) ) {
			return $default;
		}

		$attrs = $workbook->sheets->sheet[0]->attributes( 'http://schemas.openxmlformats.org/officeDocument/2006/relationships' );
		$rid   = null !== $attrs ? (string) $attrs['id'] : '';
		foreach ( $rels->Relationship as $rel ) {
			if ( (string) $rel['Id'] === $rid ) {
				$target = ltrim( (string) $rel['Target'], '/' );
				$target = str_starts_with( $target, 'xl/' ) ? $target : 'xl/' . $target;
				// Nur einfache, relative Pfade innerhalb von xl/ zulassen.
				if ( 1 === preg_match( '#^xl/[A-Za-z0-9_/.-]+\.xml$#', $target ) && ! str_contains( $target, '..' ) ) {
					return $target;
				}
			}
		}
		return $default;
	}

	/**
	 * @return string[] Gemeinsame Zeichenketten der Arbeitsmappe.
	 */
	private static function shared_strings( ZipArchive $zip ): array {
		$xml = self::load_xml( $zip, 'xl/sharedStrings.xml' );
		if ( null === $xml ) {
			return array();
		}
		$strings = array();
		foreach ( $xml->si as $si ) {
			$strings[] = self::rich_text( $si );
		}
		return $strings;
	}

	/**
	 * Text eines <si>- bzw. <is>-Elements (einfacher Text oder Rich Text).
	 */
	private static function rich_text( ?SimpleXMLElement $node ): string {
		if ( null === $node ) {
			return '';
		}
		$text = isset( $node->t ) ? (string) $node->t : '';
		if ( isset( $node->r ) ) {
			foreach ( $node->r as $run ) {
				$text .= (string) $run->t;
			}
		}
		return $text;
	}

	/**
	 * Lädt eine XML-Datei aus dem Archiv – mit Größenlimit und ohne Netzwerkzugriff.
	 */
	private static function load_xml( ZipArchive $zip, string $name ): ?SimpleXMLElement {
		$stat = $zip->statName( $name );
		if ( false === $stat ) {
			return null;
		}
		if ( (int) $stat['size'] > self::MAX_XML_BYTES ) {
			throw new RuntimeException( __( 'Die Excel-Datei ist zu groß.', 'fm-wpcalendar' ) );
		}
		$content = $zip->getFromName( $name );
		if ( false === $content || '' === $content ) {
			return null;
		}
		// DOCTYPE-Deklarationen sind in XLSX nicht vorgesehen -> ablehnen (Schutz vor XXE / Entity-Expansion).
		if ( false !== stripos( $content, '<!DOCTYPE' ) || false !== stripos( $content, '<!ENTITY' ) ) {
			throw new RuntimeException( __( 'Die Excel-Datei enthält unzulässige Inhalte.', 'fm-wpcalendar' ) );
		}

		$previous = libxml_use_internal_errors( true );
		$xml      = simplexml_load_string( $content, SimpleXMLElement::class, LIBXML_NONET | LIBXML_COMPACT );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		return false === $xml ? null : $xml;
	}

	/* ---------------------------------------------------------------------
	 * Hilfsfunktionen
	 * ------------------------------------------------------------------- */

	/**
	 * Spaltenbuchstaben aus Index (0 = A).
	 */
	private static function column_letter( int $index ): string {
		$letter = '';
		for ( $n = $index + 1; $n > 0; $n = intdiv( $n - 1, 26 ) ) {
			$letter = chr( 65 + ( ( $n - 1 ) % 26 ) ) . $letter;
		}
		return $letter;
	}

	/**
	 * Spaltenindex aus Zellreferenz, z. B. "C12" -> 2.
	 */
	private static function column_index( string $ref ): int {
		if ( 1 !== preg_match( '/^([A-Z]{1,3})\d*$/', strtoupper( $ref ), $m ) ) {
			return -1;
		}
		$index = 0;
		foreach ( str_split( $m[1] ) as $char ) {
			$index = $index * 26 + ( ord( $char ) - 64 );
		}
		return $index - 1;
	}

	/**
	 * Entfernt in XML unzulässige Steuerzeichen.
	 */
	private static function clean_xml_string( string $value ): string {
		return (string) preg_replace( '/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}]/u', '', $value );
	}

	/**
	 * Wandelt eine Excel-Seriennummer (Datum) in Y-m-d um.
	 */
	public static function serial_to_date( string $value ): string {
		if ( ! is_numeric( $value ) ) {
			return '';
		}
		$serial = (float) $value;
		if ( $serial < 1 || $serial > 150000 ) {
			return '';
		}
		// Excel-Basis 30.12.1899 (berücksichtigt den 1900-Schaltjahrfehler).
		$days = (int) floor( $serial );
		return gmdate( 'Y-m-d', ( $days - 25569 ) * 86400 );
	}

	/**
	 * Wandelt einen Excel-Zeitwert (Tagesbruchteil) in H:i um.
	 */
	public static function fraction_to_time( string $value ): string {
		if ( ! is_numeric( $value ) ) {
			return '';
		}
		$fraction = fmod( (float) $value, 1.0 );
		if ( $fraction < 0 ) {
			return '';
		}
		$minutes = (int) round( $fraction * 1440 );
		if ( $minutes >= 1440 ) {
			$minutes = 0;
		}
		return sprintf( '%02d:%02d', intdiv( $minutes, 60 ), $minutes % 60 );
	}
}
