<?php
/**
 * Termin-Datenmodell: Lesen, Validieren, Abfragen und Formatieren von Terminen.
 *
 * @package FrozenMedia\WPCalendar
 */

declare(strict_types=1);

namespace FrozenMedia\WPCalendar;

use DateTimeImmutable;
use WP_Post;
use WP_Query;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Event {

	/** Meta-Schlüssel (mit Unterstrich = in "Eigene Felder" versteckt). */
	public const META_START_DATE = '_fmvt_start_date';
	public const META_END_DATE   = '_fmvt_end_date';
	public const META_ALL_DAY    = '_fmvt_all_day';
	public const META_START_TIME = '_fmvt_start_time';
	public const META_END_TIME   = '_fmvt_end_time';

	/** Obergrenze für Abfragen ohne Limit (Schutz vor Überlast). */
	public const MAX_QUERY = 500;

	public function __construct(
		public readonly int $id,
		public readonly string $title,
		public readonly string $start_date,
		public readonly string $end_date,
		public readonly bool $all_day,
		public readonly string $start_time,
		public readonly string $end_time,
		public readonly ?int $location_id,
		public readonly string $location_name,
		public readonly string $location_address,
	) {}

	/**
	 * Erzeugt ein Event-Objekt aus einem WP_Post.
	 */
	public static function from_post( WP_Post $post ): self {
		$start = (string) get_post_meta( $post->ID, self::META_START_DATE, true );
		$end   = (string) get_post_meta( $post->ID, self::META_END_DATE, true );
		$end   = ( '' === $end || $end < $start ) ? $start : $end;

		$all_day    = '1' === (string) get_post_meta( $post->ID, self::META_ALL_DAY, true );
		$start_time = $all_day ? '' : (string) get_post_meta( $post->ID, self::META_START_TIME, true );
		$end_time   = $all_day ? '' : (string) get_post_meta( $post->ID, self::META_END_TIME, true );

		$location_id      = null;
		$location_name    = '';
		$location_address = '';
		$terms            = get_the_terms( $post, Post_Type::TAXONOMY );
		if ( is_array( $terms ) && isset( $terms[0] ) ) {
			$location_id      = (int) $terms[0]->term_id;
			$location_name    = (string) $terms[0]->name;
			$location_address = (string) get_term_meta( $location_id, Post_Type::TERM_META_ADDRESS, true );
		}

		return new self(
			(int) $post->ID,
			get_the_title( $post ),
			$start,
			$end,
			$all_day || '' === $start_time,
			$start_time,
			$end_time,
			$location_id,
			$location_name,
			$location_address
		);
	}

	/* ---------------------------------------------------------------------
	 * Validierung
	 * ------------------------------------------------------------------- */

	/**
	 * Prüft und normalisiert ein Datum. Akzeptiert Y-m-d und d.m.Y (auch d.m.y).
	 *
	 * @return string Datum im Format Y-m-d oder '' bei ungültiger Eingabe.
	 */
	public static function sanitize_date( mixed $value ): string {
		if ( ! is_scalar( $value ) ) {
			return '';
		}
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return '';
		}
		foreach ( array( 'Y-m-d', 'd.m.Y', 'j.n.Y', 'd.m.y', 'j.n.y', 'Y/m/d' ) as $format ) {
			$dt = DateTimeImmutable::createFromFormat( '!' . $format, $value, wp_timezone() );
			// Strikte Prüfung: Rückformatierung muss exakt der Eingabe entsprechen (verhindert 31.02. usw.).
			if ( false !== $dt && $dt->format( $format ) === $value ) {
				$year = (int) $dt->format( 'Y' );
				if ( $year >= 1900 && $year <= 2200 ) {
					return $dt->format( 'Y-m-d' );
				}
			}
		}
		return '';
	}

	/**
	 * Prüft und normalisiert eine Uhrzeit (H:i, auch H:i:s oder H.i).
	 *
	 * @return string Uhrzeit im Format H:i oder '' bei ungültiger Eingabe.
	 */
	public static function sanitize_time( mixed $value ): string {
		if ( ! is_scalar( $value ) ) {
			return '';
		}
		$value = trim( (string) $value );
		if ( 1 === preg_match( '/^([01]?\d|2[0-3])[:.]([0-5]\d)(?::[0-5]\d)?$/', $value, $m ) ) {
			return sprintf( '%02d:%02d', (int) $m[1], (int) $m[2] );
		}
		return '';
	}

	/**
	 * Normalisiert einen vollständigen Datensatz und speichert die Meta-Daten.
	 * Wird von Metabox und Import gemeinsam genutzt.
	 *
	 * @param int                  $post_id Termin-ID.
	 * @param array<string, mixed> $data    start_date, end_date, all_day, start_time, end_time.
	 * @return string[] Liste von Korrekturhinweisen (leer = alles in Ordnung).
	 */
	public static function save_meta( int $post_id, array $data ): array {
		$notices = array();

		$start = self::sanitize_date( $data['start_date'] ?? '' );
		if ( '' === $start ) {
			$start     = current_datetime()->format( 'Y-m-d' );
			$notices[] = __( 'Kein gültiges Startdatum angegeben – das heutige Datum wurde gesetzt.', 'fm-wpcalendar' );
		}

		$end = self::sanitize_date( $data['end_date'] ?? '' );
		if ( '' === $end ) {
			$end = $start;
		} elseif ( $end < $start ) {
			$end       = $start;
			$notices[] = __( 'Das Enddatum lag vor dem Startdatum und wurde angepasst.', 'fm-wpcalendar' );
		}

		$all_day    = ! empty( $data['all_day'] );
		$start_time = $all_day ? '' : self::sanitize_time( $data['start_time'] ?? '' );
		$end_time   = $all_day ? '' : self::sanitize_time( $data['end_time'] ?? '' );

		// Ohne Startzeit gilt der Termin als ganztägig.
		if ( '' === $start_time ) {
			$all_day  = true;
			$end_time = '';
		}

		// Bei eintägigen Terminen muss die Endzeit nach der Startzeit liegen.
		if ( '' !== $end_time && $start === $end && $end_time <= $start_time ) {
			$end_time  = '';
			$notices[] = __( 'Die Endzeit lag nicht nach der Startzeit und wurde entfernt.', 'fm-wpcalendar' );
		}

		update_post_meta( $post_id, self::META_START_DATE, $start );
		update_post_meta( $post_id, self::META_END_DATE, $end );
		update_post_meta( $post_id, self::META_ALL_DAY, $all_day ? '1' : '0' );
		update_post_meta( $post_id, self::META_START_TIME, $start_time );
		update_post_meta( $post_id, self::META_END_TIME, $end_time );

		return $notices;
	}

	/* ---------------------------------------------------------------------
	 * Abfragen
	 * ------------------------------------------------------------------- */

	/**
	 * Basis-Abfrageargumente mit Sortierung nach Startdatum und Startzeit.
	 *
	 * @param array<int, array<string, mixed>> $date_clauses Zusätzliche Datumsbedingungen.
	 * @return array<string, mixed>
	 */
	private static function base_args( array $date_clauses, string $order = 'ASC' ): array {
		$order = 'DESC' === $order ? 'DESC' : 'ASC';

		return array(
			'post_type'              => Post_Type::POST_TYPE,
			'post_status'            => 'publish',
			'ignore_sticky_posts'    => true,
			'no_found_rows'          => true,
			'update_post_term_cache' => true,
			'update_post_meta_cache' => true,
			'meta_query'             => array_merge( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				array(
					'relation'     => 'AND',
					'start_clause' => array(
						'key'     => self::META_START_DATE,
						'compare' => 'EXISTS',
					),
					'time_clause'  => array(
						'key'     => self::META_START_TIME,
						'compare' => 'EXISTS',
					),
				),
				$date_clauses
			),
			'orderby'                => array(
				'start_clause' => $order,
				'time_clause'  => $order,
				'title'        => 'ASC',
			),
		);
	}

	/**
	 * Kommende Termine (inkl. heute laufender) ab Offset.
	 *
	 * @return array{events: Event[], has_more: bool}
	 */
	public static function upcoming( int $limit, int $offset = 0 ): array {
		$limit  = max( 1, min( 100, $limit ) );
		$offset = max( 0, min( 100000, $offset ) );
		$today  = current_datetime()->format( 'Y-m-d' );

		$args = self::base_args(
			array(
				array(
					'key'     => self::META_END_DATE,
					'value'   => $today,
					'compare' => '>=',
					'type'    => 'CHAR',
				),
			)
		);
		// Einen Datensatz mehr laden, um zu erkennen, ob es weitere gibt.
		$args['posts_per_page'] = $limit + 1;
		$args['offset']         = $offset;

		$posts    = ( new WP_Query( $args ) )->posts;
		$has_more = count( $posts ) > $limit;
		$posts    = array_slice( $posts, 0, $limit );

		return array(
			'events'   => array_map( array( self::class, 'from_post' ), $posts ),
			'has_more' => $has_more,
		);
	}

	/**
	 * Alle Termine, die einen Zeitraum berühren (für die Monatsansicht).
	 *
	 * @return Event[]
	 */
	public static function in_range( string $from, string $to ): array {
		$args = self::base_args(
			array(
				array(
					'key'     => self::META_START_DATE,
					'value'   => $to,
					'compare' => '<=',
					'type'    => 'CHAR',
				),
				array(
					'key'     => self::META_END_DATE,
					'value'   => $from,
					'compare' => '>=',
					'type'    => 'CHAR',
				),
			)
		);
		$args['posts_per_page'] = self::MAX_QUERY;

		return array_map( array( self::class, 'from_post' ), ( new WP_Query( $args ) )->posts );
	}

	/**
	 * Alle Termine für den Export (alle Status außer Papierkorb).
	 *
	 * @return Event[]
	 */
	public static function all_for_export(): array {
		$args                   = self::base_args( array() );
		$args['post_status']    = array( 'publish', 'draft', 'pending', 'private', 'future' );
		$args['posts_per_page'] = -1;
		return array_map( array( self::class, 'from_post' ), ( new WP_Query( $args ) )->posts );
	}

	/* ---------------------------------------------------------------------
	 * Formatierung
	 * ------------------------------------------------------------------- */

	public function is_multi_day(): bool {
		return $this->end_date !== $this->start_date;
	}

	/**
	 * Läuft der Termin heute?
	 */
	public function is_today(): bool {
		$today = current_datetime()->format( 'Y-m-d' );
		return $this->start_date <= $today && $this->end_date >= $today;
	}

	/**
	 * Formatiert ein Y-m-d-Datum lokalisiert über wp_date().
	 */
	public static function format_date( string $ymd, string $format ): string {
		$dt = DateTimeImmutable::createFromFormat( '!Y-m-d', $ymd, wp_timezone() );
		if ( false === $dt ) {
			return '';
		}
		return (string) wp_date( $format, $dt->getTimestamp(), wp_timezone() );
	}

	/**
	 * Uhrzeit-Text, z. B. "18:00 – 20:00 Uhr" oder "ab 18:00 Uhr".
	 */
	public function time_label(): string {
		if ( $this->all_day ) {
			return '';
		}
		if ( '' !== $this->end_time && ! $this->is_multi_day() ) {
			/* translators: 1: Startzeit, 2: Endzeit */
			return sprintf( __( '%1$s – %2$s Uhr', 'fm-wpcalendar' ), $this->start_time, $this->end_time );
		}
		/* translators: %s: Startzeit */
		return sprintf( __( 'ab %s Uhr', 'fm-wpcalendar' ), $this->start_time );
	}

	/**
	 * Vollständiger Datumsbereich als Text.
	 *
	 * Beispiele:
	 *  - Sa, 21.11.2026
	 *  - Sa, 21.11.2026, 18:00 – 20:00 Uhr
	 *  - Fr, 20.11. – So, 22.11.2026
	 *  - Fr, 20.11.2026, 18:00 Uhr – So, 22.11.2026, 14:00 Uhr
	 *
	 * @param bool $with_time Uhrzeit mit ausgeben.
	 */
	public function date_label( bool $with_time = true ): string {
		$long = 'D, d.m.Y';

		if ( ! $this->is_multi_day() ) {
			$label = self::format_date( $this->start_date, $long );
			if ( $with_time && ! $this->all_day ) {
				$label .= ', ' . $this->time_label();
			}
			return $label;
		}

		if ( $with_time && ! $this->all_day ) {
			$from = self::format_date( $this->start_date, $long ) . ', ' . $this->start_time . ' ' . __( 'Uhr', 'fm-wpcalendar' );
			$to   = self::format_date( $this->end_date, $long );
			if ( '' !== $this->end_time ) {
				$to .= ', ' . $this->end_time . ' ' . __( 'Uhr', 'fm-wpcalendar' );
			}
			return $from . ' – ' . $to;
		}

		// Gleiches Jahr: Jahr nur am Ende anzeigen.
		$same_year  = substr( $this->start_date, 0, 4 ) === substr( $this->end_date, 0, 4 );
		$start_text = self::format_date( $this->start_date, $same_year ? 'D, d.m.' : $long );
		return $start_text . ' – ' . self::format_date( $this->end_date, $long );
	}

	/**
	 * Permalink zur Detailseite.
	 */
	public function url(): string {
		return (string) get_permalink( $this->id );
	}
}
