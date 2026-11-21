<?php
/**
 * Frontend: Shortcode (Monat, Liste, kompakt), AJAX-Nachladen und Detailseite.
 *
 * @package FrozenMedia\WPCalendar
 */

declare(strict_types=1);

namespace FrozenMedia\WPCalendar;

use DateTimeImmutable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Frontend {

	public const SHORTCODE = 'vereinstermine';

	/** Alternativer Shortcode passend zum Plugin-Namen. */
	public const SHORTCODE_ALIAS = 'fm_wpcalendar';

	/** Höchstzahl an Terminen je Ladevorgang. */
	private const MAX_COUNT = 50;

	/** Zähler für eindeutige IDs mehrerer Shortcodes auf einer Seite. */
	private static int $instance = 0;

	public function register_hooks(): void {
		add_shortcode( self::SHORTCODE, array( $this, 'shortcode' ) );
		add_shortcode( self::SHORTCODE_ALIAS, array( $this, 'shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );

		// AJAX für angemeldete und nicht angemeldete Besucher (nur lesend).
		foreach ( array( 'fmvt_month', 'fmvt_list' ) as $action ) {
			add_action( 'wp_ajax_' . $action, array( $this, 'ajax_' . substr( $action, 5 ) ) );
			add_action( 'wp_ajax_nopriv_' . $action, array( $this, 'ajax_' . substr( $action, 5 ) ) );
		}

		// Versehentliche Code-Formatierung um den Shortcode entfernen (vor wpautop und do_shortcode).
		add_filter( 'the_content', array( $this, 'unwrap_code_formatting' ), 8 );
		add_filter( 'widget_text_content', array( $this, 'unwrap_code_formatting' ), 8 );

		// Detailseite.
		add_filter( 'the_content', array( $this, 'detail_content' ), 20 );
		add_filter( 'body_class', array( $this, 'body_class' ) );
	}

	/* ---------------------------------------------------------------------
	 * Assets
	 * ------------------------------------------------------------------- */

	public function register_assets(): void {
		// Nur einmal registrieren: Block-Themes rendern Inhalte (und damit Shortcodes) schon vor wp_head.
		if ( wp_style_is( 'fmvt-frontend', 'registered' ) ) {
			if ( did_action( 'wp_enqueue_scripts' ) && self::page_needs_assets() ) {
				self::enqueue();
			}
			return;
		}

		wp_register_style( 'fmvt-frontend', FMVT_URL . 'assets/css/frontend.css', array(), FMVT_VERSION );
		wp_register_script( 'fmvt-frontend', FMVT_URL . 'assets/js/frontend.js', array(), FMVT_VERSION, array( 'in_footer' => true, 'strategy' => 'defer' ) );
		wp_add_inline_script(
			'fmvt-frontend',
			'window.fmvtConfig = ' . wp_json_encode(
				array(
					'ajaxUrl' => admin_url( 'admin-ajax.php' ),
					'i18n'    => array(
						'loading' => __( 'Wird geladen …', 'fm-wpcalendar' ),
						'error'   => __( 'Die Termine konnten nicht geladen werden.', 'fm-wpcalendar' ),
					),
				)
			) . ';',
			'before'
		);

		// Akzentfarbe und Schriftart aus den Einstellungen (leer = Standard bzw. Theme).
		// "body .fmvt" ist spezifischer als ".fmvt": die Einstellung hat damit Vorrang vor Theme-CSS.
		$inline = '';
		$accent = sanitize_hex_color( (string) Plugin::setting( 'accent_color', '' ) );
		if ( is_string( $accent ) && '' !== $accent ) {
			$inline .= 'body .fmvt{--fmvt-accent:' . $accent . ';}';
		}
		$font = self::sanitize_font( (string) Plugin::setting( 'font_family', '' ) );
		if ( '' !== $font ) {
			// Auch die Monatsüberschrift umstellen (Themes setzen für h3 oft eine eigene Schrift).
			$inline .= 'body .fmvt{--fmvt-font:' . $font . ';}body .fmvt h3{font-family:' . $font . ';}';
		}
		if ( '' !== $inline ) {
			wp_add_inline_style( 'fmvt-frontend', $inline );
		}

		// Styles möglichst schon im <head> laden (kein kurzes Aufblitzen ohne Gestaltung).
		if ( self::page_needs_assets() ) {
			self::enqueue();
		}
	}

	/**
	 * Prüft vorab, ob die aktuelle Seite Termine anzeigt (Detailseite, Shortcode im Inhalt oder aktives Widget).
	 * Fälle, die hier nicht erkannt werden (z. B. Shortcode in einem Page-Builder-Template), laden die
	 * Styles weiterhin beim Rendern nach.
	 */
	private static function page_needs_assets(): bool {
		if ( is_singular( Post_Type::POST_TYPE ) ) {
			return true;
		}
		if ( is_active_widget( false, false, 'fmvt_upcoming', true ) ) {
			return true;
		}
		if ( is_singular() ) {
			$post = get_queried_object();
			if ( $post instanceof \WP_Post
				&& ( has_shortcode( $post->post_content, self::SHORTCODE ) || has_shortcode( $post->post_content, self::SHORTCODE_ALIAS ) ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Lädt CSS und JS (auch noch während der Seitenausgabe möglich).
	 */
	public static function enqueue(): void {
		if ( ! wp_style_is( 'fmvt-frontend', 'registered' ) ) {
			( new self() )->register_assets();
		}
		wp_enqueue_style( 'fmvt-frontend' );
		wp_enqueue_script( 'fmvt-frontend' );
	}

	/**
	 * Bereinigt eine CSS-font-family-Angabe (nur Buchstaben, Ziffern, Leerzeichen, Komma, Bindestrich, Anführungszeichen).
	 */
	public static function sanitize_font( string $font ): string {
		$font = trim( wp_strip_all_tags( $font ) );
		if ( '' === $font || mb_strlen( $font ) > 200 || 1 !== preg_match( '/^[\p{L}0-9 ,\-_"\']+$/u', $font ) ) {
			return '';
		}
		// Anführungszeichen müssen paarweise vorkommen, sonst wäre das CSS kaputt.
		if ( 0 !== substr_count( $font, '"' ) % 2 || 0 !== substr_count( $font, "'" ) % 2 ) {
			return '';
		}
		return $font;
	}

	/**
	 * Entfernt <code>/<pre>/<kbd>, die versehentlich um den Shortcode gesetzt wurden
	 * (z. B. beim Kopieren aus der Hilfe). Sonst übernimmt die ganze Ausgabe die Code-Schrift.
	 */
	public function unwrap_code_formatting( mixed $content ): mixed {
		if ( ! is_string( $content ) || ! str_contains( $content, '[' ) ) {
			return $content;
		}
		$tags = preg_quote( self::SHORTCODE, '#' ) . '|' . preg_quote( self::SHORTCODE_ALIAS, '#' );
		return (string) preg_replace(
			'#<(code|pre|kbd)(?:\s[^>]*)?>\s*(\[(?:' . $tags . ')\b[^\]]*\])\s*</\1>#i',
			'$2',
			$content
		);
	}

	/* ---------------------------------------------------------------------
	 * Shortcode
	 * ------------------------------------------------------------------- */

	/**
	 * [vereinstermine ansicht="liste|monat|kompakt" anzahl="10" mehr="ja|nein"]
	 *
	 * @param array<string, string>|string $atts Attribute.
	 */
	public function shortcode( array|string $atts ): string {
		$atts = shortcode_atts(
			array(
				'ansicht' => 'liste',
				'anzahl'  => '',
				'mehr'    => 'ja',
			),
			is_array( $atts ) ? $atts : array(),
			self::SHORTCODE
		);

		$view = sanitize_key( (string) $atts['ansicht'] );
		self::enqueue();

		if ( in_array( $view, array( 'monat', 'month', 'kalender', 'calendar' ), true ) ) {
			[ $year, $month ] = $this->requested_month();
			return self::render_month( $year, $month, (int) get_the_ID() );
		}

		if ( in_array( $view, array( 'kompakt', 'compact', 'widget' ), true ) ) {
			return self::render_compact( self::sanitize_count( $atts['anzahl'], 5 ) );
		}

		$more = ! in_array( strtolower( (string) $atts['mehr'] ), array( 'nein', 'no', '0', 'false' ), true );
		return self::render_list( self::sanitize_count( $atts['anzahl'], 10 ), $more );
	}

	private static function sanitize_count( mixed $value, int $default ): int {
		$count = absint( is_scalar( $value ) ? $value : 0 );
		return $count > 0 ? min( self::MAX_COUNT, $count ) : $default;
	}

	/**
	 * Gewünschter Monat aus ?fmvt_monat=YYYY-MM (Fallback ohne JavaScript), sonst aktueller Monat.
	 *
	 * @return array{0: int, 1: int}
	 */
	private function requested_month(): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reine Anzeige, keine Datenänderung.
		$raw = isset( $_GET['fmvt_monat'] ) ? sanitize_text_field( wp_unslash( $_GET['fmvt_monat'] ) ) : '';
		return self::parse_month( $raw );
	}

	/**
	 * @return array{0: int, 1: int}
	 */
	private static function parse_month( string $raw ): array {
		if ( 1 === preg_match( '/^(\d{4})-(\d{2})$/', $raw, $m ) ) {
			$year  = (int) $m[1];
			$month = (int) $m[2];
			if ( $year >= 1970 && $year <= 2100 && $month >= 1 && $month <= 12 ) {
				return array( $year, $month );
			}
		}
		$now = current_datetime();
		return array( (int) $now->format( 'Y' ), (int) $now->format( 'n' ) );
	}

	/* ---------------------------------------------------------------------
	 * Monatsansicht
	 * ------------------------------------------------------------------- */

	public static function render_month( int $year, int $month, int $page_id = 0 ): string {
		global $wp_locale;

		$tz          = wp_timezone();
		$first       = new DateTimeImmutable( sprintf( '%04d-%02d-01', $year, $month ), $tz );
		$last        = $first->modify( 'last day of this month' );
		$week_start  = (int) get_option( 'start_of_week', 1 );
		$lead        = ( (int) $first->format( 'w' ) - $week_start + 7 ) % 7;
		$grid_start  = $first->modify( '-' . $lead . ' days' );
		$trail       = ( 7 - ( ( $lead + (int) $last->format( 'j' ) ) % 7 ) ) % 7;
		$grid_end    = $last->modify( '+' . $trail . ' days' );
		$today       = current_datetime()->format( 'Y-m-d' );
		$prev        = $first->modify( '-1 month' );
		$next        = $first->modify( '+1 month' );
		$is_current  = $first->format( 'Y-m' ) === current_datetime()->format( 'Y-m' );
		$base_url    = $page_id > 0 ? (string) get_permalink( $page_id ) : home_url( '/' );
		$month_label = (string) wp_date( 'F Y', $first->getTimestamp(), $tz );

		// Termine nach Tagen gruppieren (mehrtägige an jedem Tag).
		$by_day = array();
		foreach ( Event::in_range( $grid_start->format( 'Y-m-d' ), $grid_end->format( 'Y-m-d' ) ) as $event ) {
			$day  = max( $event->start_date, $grid_start->format( 'Y-m-d' ) );
			$stop = min( $event->end_date, $grid_end->format( 'Y-m-d' ) );
			$cur  = new DateTimeImmutable( $day, $tz );
			for ( $i = 0; $i < 366 && $cur->format( 'Y-m-d' ) <= $stop; $i++ ) {
				$by_day[ $cur->format( 'Y-m-d' ) ][] = $event;
				$cur                                 = $cur->modify( '+1 day' );
			}
		}

		$uid = 'fmvt-' . ( ++self::$instance );

		ob_start();
		?>
		<div class="fmvt fmvt-month" id="<?php echo esc_attr( $uid ); ?>" data-fmvt-month="<?php echo esc_attr( $first->format( 'Y-m' ) ); ?>" data-fmvt-page="<?php echo esc_attr( (string) $page_id ); ?>">
			<div class="fmvt-month-nav">
				<a class="fmvt-nav-btn fmvt-prev" href="<?php echo esc_url( add_query_arg( 'fmvt_monat', $prev->format( 'Y-m' ), $base_url ) ); ?>" data-fmvt-goto="<?php echo esc_attr( $prev->format( 'Y-m' ) ); ?>" rel="nofollow">
					<span aria-hidden="true">‹</span><span class="fmvt-sr"><?php esc_html_e( 'Vorheriger Monat', 'fm-wpcalendar' ); ?></span>
				</a>
				<h3 class="fmvt-month-title" tabindex="-1"><?php echo esc_html( $month_label ); ?></h3>
				<a class="fmvt-nav-btn fmvt-next" href="<?php echo esc_url( add_query_arg( 'fmvt_monat', $next->format( 'Y-m' ), $base_url ) ); ?>" data-fmvt-goto="<?php echo esc_attr( $next->format( 'Y-m' ) ); ?>" rel="nofollow">
					<span class="fmvt-sr"><?php esc_html_e( 'Nächster Monat', 'fm-wpcalendar' ); ?></span><span aria-hidden="true">›</span>
				</a>
				<?php if ( ! $is_current ) : ?>
					<a class="fmvt-today-btn" href="<?php echo esc_url( remove_query_arg( 'fmvt_monat', $base_url ) ); ?>" data-fmvt-goto="<?php echo esc_attr( current_datetime()->format( 'Y-m' ) ); ?>" rel="nofollow"><?php esc_html_e( 'Heute', 'fm-wpcalendar' ); ?></a>
				<?php endif; ?>
			</div>

			<div class="fmvt-weekdays" aria-hidden="true">
				<?php for ( $i = 0; $i < 7; $i++ ) : ?>
					<span><?php echo esc_html( $wp_locale->get_weekday_abbrev( $wp_locale->get_weekday( ( $week_start + $i ) % 7 ) ) ); ?></span>
				<?php endfor; ?>
			</div>

			<ol class="fmvt-days">
				<?php
				for ( $cur = $grid_start; $cur <= $grid_end; $cur = $cur->modify( '+1 day' ) ) :
					$ymd     = $cur->format( 'Y-m-d' );
					$events  = $by_day[ $ymd ] ?? array();
					$classes = array( 'fmvt-day' );
					if ( (int) $cur->format( 'n' ) !== $month ) {
						$classes[] = 'is-other-month';
					}
					if ( $ymd === $today ) {
						$classes[] = 'is-today';
					}
					if ( array() !== $events ) {
						$classes[] = 'has-events';
					}
					?>
					<li class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>">
						<time class="fmvt-day-num" datetime="<?php echo esc_attr( $ymd ); ?>">
							<span aria-hidden="true"><?php echo esc_html( $cur->format( 'j' ) ); ?></span>
							<span class="fmvt-day-full"><?php echo esc_html( (string) wp_date( 'l, j. F', $cur->getTimestamp(), $tz ) ); ?></span>
						</time>
						<?php if ( array() !== $events ) : ?>
							<ul class="fmvt-day-events">
								<?php foreach ( $events as $event ) : ?>
									<?php
									$ev_class = 'fmvt-day-event';
									if ( $event->is_multi_day() ) {
										$ev_class .= ' is-multi';
										$ev_class .= $ymd === $event->start_date ? ' is-start' : ( $ymd === $event->end_date ? ' is-end' : ' is-middle' );
									}
									?>
									<li class="<?php echo esc_attr( $ev_class ); ?>">
										<?php if ( ! $event->all_day && $ymd === $event->start_date ) : ?>
											<span class="fmvt-ev-time"><?php echo esc_html( $event->start_time ); ?></span>
										<?php endif; ?>
										<a href="<?php echo esc_url( $event->url() ); ?>"><?php echo esc_html( $event->title ); ?></a>
										<?php if ( '' !== $event->location_name ) : ?>
											<span class="fmvt-ev-location"><?php echo esc_html( $event->location_name ); ?></span>
										<?php endif; ?>
									</li>
								<?php endforeach; ?>
							</ul>
						<?php endif; ?>
					</li>
				<?php endfor; ?>
			</ol>
			<?php if ( array() === array_filter( $by_day, static fn( string $d ): bool => str_starts_with( $d, $first->format( 'Y-m' ) ), ARRAY_FILTER_USE_KEY ) ) : ?>
				<p class="fmvt-empty"><?php esc_html_e( 'In diesem Monat sind keine Termine eingetragen.', 'fm-wpcalendar' ); ?></p>
			<?php endif; ?>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/* ---------------------------------------------------------------------
	 * Listenansicht
	 * ------------------------------------------------------------------- */

	public static function render_list( int $count, bool $more = true ): string {
		$data = Event::upcoming( $count, 0 );
		$uid  = 'fmvt-' . ( ++self::$instance );

		ob_start();
		?>
		<div class="fmvt fmvt-list-wrap" id="<?php echo esc_attr( $uid ); ?>">
			<?php if ( array() === $data['events'] ) : ?>
				<p class="fmvt-empty"><?php esc_html_e( 'Derzeit sind keine Termine geplant.', 'fm-wpcalendar' ); ?></p>
			<?php else : ?>
				<ul class="fmvt-list">
					<?php echo self::list_items( $data['events'], 0 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- In list_items() escaped. ?>
				</ul>
				<?php if ( $more && $data['has_more'] ) : ?>
					<div class="fmvt-more-wrap">
						<button type="button" class="fmvt-more" data-fmvt-offset="<?php echo esc_attr( (string) $count ); ?>" data-fmvt-count="<?php echo esc_attr( (string) $count ); ?>">
							<?php esc_html_e( 'Mehr anzeigen', 'fm-wpcalendar' ); ?>
						</button>
					</div>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Listeneinträge (auch für das AJAX-Nachladen).
	 *
	 * @param Event[] $events Termine.
	 * @param int     $offset Bisherige Anzahl (0 = erster Block, dort wird der nächste Termin hervorgehoben).
	 */
	private static function list_items( array $events, int $offset ): string {
		$html = '';
		foreach ( $events as $i => $event ) {
			$featured = 0 === $offset && 0 === $i;
			$classes  = 'fmvt-item' . ( $featured ? ' is-next' : '' ) . ( $event->is_today() ? ' is-today' : '' );

			$badge_day   = Event::format_date( $event->start_date, 'j' );
			$badge_month = Event::format_date( $event->start_date, 'M' );
			$badge_class = 'fmvt-badge';
			if ( $event->is_multi_day() ) {
				$badge_class .= ' is-range';
				$badge_day    = $badge_day . '.–' . Event::format_date( $event->end_date, 'j' ) . '.';
				// Über den Monatswechsel: beide Monate anzeigen, z. B. "30.–2." / "Jan.–Feb.".
				if ( substr( $event->start_date, 0, 7 ) !== substr( $event->end_date, 0, 7 ) ) {
					$badge_month .= '–' . Event::format_date( $event->end_date, 'M' );
				}
			}

			$label = '';
			if ( $featured ) {
				$label = $event->is_today() ? __( 'Heute', 'fm-wpcalendar' ) : __( 'Nächster Termin', 'fm-wpcalendar' );
			} elseif ( $event->is_today() ) {
				$label = __( 'Heute', 'fm-wpcalendar' );
			}

			$html .= '<li class="' . esc_attr( $classes ) . '">';
			$html .= '<div class="' . esc_attr( $badge_class ) . '" aria-hidden="true"><span class="fmvt-badge-day">' . esc_html( $badge_day ) . '</span><span class="fmvt-badge-month">' . esc_html( $badge_month ) . '</span></div>';
			$html .= '<div class="fmvt-item-main">';
			if ( '' !== $label ) {
				$html .= '<span class="fmvt-label">' . esc_html( $label ) . '</span>';
			}
			$html .= '<a class="fmvt-item-title" href="' . esc_url( $event->url() ) . '">' . esc_html( $event->title ) . '</a>';
			$html .= '<div class="fmvt-item-meta">';
			$html .= '<span class="fmvt-item-date"><time datetime="' . esc_attr( $event->start_date ) . '">' . esc_html( $event->date_label() ) . '</time></span>';
			if ( '' !== $event->location_name ) {
				$html .= '<span class="fmvt-item-location">' . esc_html( $event->location_name ) . '</span>';
			}
			$html .= '</div></div></li>';
		}
		return $html;
	}

	/**
	 * Kompakte Liste (Widget bzw. ansicht="kompakt").
	 */
	public static function render_compact( int $count, bool $show_all_link = false ): string {
		self::enqueue();
		$data = Event::upcoming( max( 1, min( self::MAX_COUNT, $count ) ), 0 );

		if ( array() === $data['events'] ) {
			return '<p class="fmvt fmvt-empty">' . esc_html__( 'Derzeit sind keine Termine geplant.', 'fm-wpcalendar' ) . '</p>';
		}

		$html = '<ul class="fmvt fmvt-compact">';
		foreach ( $data['events'] as $event ) {
			$html .= '<li class="fmvt-compact-item' . ( $event->is_today() ? ' is-today' : '' ) . '">';
			$html .= '<time class="fmvt-compact-date" datetime="' . esc_attr( $event->start_date ) . '">' . esc_html( $event->date_label() ) . '</time>';
			$html .= '<a class="fmvt-compact-title" href="' . esc_url( $event->url() ) . '">' . esc_html( $event->title ) . '</a>';
			if ( '' !== $event->location_name ) {
				$html .= '<span class="fmvt-compact-location">' . esc_html( $event->location_name ) . '</span>';
			}
			$html .= '</li>';
		}
		$html .= '</ul>';

		$page = (int) Plugin::setting( 'calendar_page', 0 );
		if ( $show_all_link && $page > 0 && 'publish' === get_post_status( $page ) ) {
			$html .= '<p class="fmvt-compact-all"><a href="' . esc_url( (string) get_permalink( $page ) ) . '">' . esc_html__( 'Alle Termine', 'fm-wpcalendar' ) . ' →</a></p>';
		}
		return $html;
	}

	/* ---------------------------------------------------------------------
	 * AJAX (öffentlich, nur lesend – daher ohne Nonce, damit Seiten-Caching funktioniert)
	 * ------------------------------------------------------------------- */

	public function ajax_month(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Öffentlicher Lesezugriff.
		$raw     = isset( $_GET['month'] ) ? sanitize_text_field( wp_unslash( $_GET['month'] ) ) : '';
		$page_id = isset( $_GET['page_id'] ) ? absint( $_GET['page_id'] ) : 0;
		// phpcs:enable

		// Seiten-ID nur verwenden, wenn sie zu einem öffentlich sichtbaren Inhalt gehört.
		if ( $page_id > 0 && ( 'publish' !== get_post_status( $page_id ) || ! is_post_type_viewable( (string) get_post_type( $page_id ) ) ) ) {
			$page_id = 0;
		}

		[ $year, $month ] = self::parse_month( $raw );
		wp_send_json_success( array( 'html' => self::render_month( $year, $month, $page_id ) ) );
	}

	public function ajax_list(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Öffentlicher Lesezugriff.
		$offset = isset( $_GET['offset'] ) ? absint( $_GET['offset'] ) : 0;
		$count  = isset( $_GET['count'] ) ? self::sanitize_count( absint( $_GET['count'] ), 10 ) : 10;
		// phpcs:enable

		$offset = min( $offset, 10000 );
		$data   = Event::upcoming( $count, $offset );
		wp_send_json_success(
			array(
				'html'     => self::list_items( $data['events'], max( 1, $offset ) ),
				'has_more' => $data['has_more'],
				'offset'   => $offset + count( $data['events'] ),
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Detailseite
	 * ------------------------------------------------------------------- */

	/**
	 * Ergänzt die Beschreibung auf der Einzelseite um Datum, Uhrzeit, Ort und einen Zurück-Link.
	 */
	public function detail_content( mixed $content ): string {
		$content = is_string( $content ) ? $content : '';
		if ( ! is_singular( Post_Type::POST_TYPE ) || (int) get_the_ID() !== (int) get_queried_object_id() ) {
			return $content;
		}
		$post = get_post();
		if ( ! $post || Post_Type::POST_TYPE !== $post->post_type ) {
			return $content;
		}
		// Passwortgeschützt: erst nach Eingabe des Passworts Details zeigen.
		if ( post_password_required( $post ) ) {
			return $content;
		}

		$event    = Event::from_post( $post );
		$page     = (int) Plugin::setting( 'calendar_page', 0 );
		$back_url = ( $page > 0 && 'publish' === get_post_status( $page ) ) ? (string) get_permalink( $page ) : home_url( '/' );

		$html  = '<div class="fmvt fmvt-detail">';
		$html .= '<dl class="fmvt-detail-facts">';

		$html .= '<div class="fmvt-fact fmvt-fact-date"><dt>' . esc_html__( 'Datum', 'fm-wpcalendar' ) . '</dt><dd>'
			. '<time datetime="' . esc_attr( $event->start_date ) . '">' . esc_html( $event->date_label( false ) ) . '</time>'
			. ( $event->is_today() ? ' <span class="fmvt-label">' . esc_html__( 'Heute', 'fm-wpcalendar' ) . '</span>' : '' )
			. '</dd></div>';

		if ( ! $event->all_day ) {
			$time = $event->is_multi_day()
				? $event->date_label( true )
				: $event->time_label();
			$html .= '<div class="fmvt-fact fmvt-fact-time"><dt>' . esc_html__( 'Uhrzeit', 'fm-wpcalendar' ) . '</dt><dd>' . esc_html( $time ) . '</dd></div>';
		}

		if ( '' !== $event->location_name ) {
			$html .= '<div class="fmvt-fact fmvt-fact-location"><dt>' . esc_html__( 'Ort', 'fm-wpcalendar' ) . '</dt><dd>'
				. '<strong>' . esc_html( $event->location_name ) . '</strong>';
			if ( '' !== $event->location_address ) {
				$map_url = 'https://www.openstreetmap.org/search?query=' . rawurlencode( str_replace( array( "\r\n", "\n" ), ', ', $event->location_address ) );
				$html   .= '<address>' . nl2br( esc_html( $event->location_address ) ) . '</address>'
					. '<a class="fmvt-map-link" href="' . esc_url( $map_url ) . '" target="_blank" rel="noopener noreferrer">'
					. esc_html__( 'Auf Karte anzeigen', 'fm-wpcalendar' ) . '</a>';
			}
			$html .= '</dd></div>';
		}
		$html .= '</dl>';

		if ( '' !== trim( wp_strip_all_tags( $content ) ) || str_contains( $content, '<img' ) ) {
			$html .= '<div class="fmvt-detail-description">' . $content . '</div>';
		}

		$html .= '<p class="fmvt-back-wrap"><a class="fmvt-back" href="' . esc_url( $back_url ) . '" data-fmvt-back>← '
			. esc_html__( 'Zurück zur Übersicht', 'fm-wpcalendar' ) . '</a></p>';
		$html .= '</div>';

		return $html;
	}

	/**
	 * @param string[] $classes Body-Klassen.
	 * @return string[]
	 */
	public function body_class( array $classes ): array {
		if ( is_singular( Post_Type::POST_TYPE ) ) {
			$classes[] = 'fmvt-single';
		}
		return $classes;
	}
}
