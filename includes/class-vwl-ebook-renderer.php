<?php
/**
 * Shared renderer used by the shortcodes, the block and the E-Book post type.
 *
 * @package VWL_Ebook_Reader
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renderer.
 */
class VWL_Ebook_Renderer {

	/**
	 * Instance counter for unique IDs.
	 *
	 * @var int
	 */
	private static $count = 0;

	/**
	 * Register (not enqueue) front-end assets.
	 */
	public static function register_assets() {
		$min = ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) ? '' : '.min';
		$pdf = file_exists( VWL_EBOOK_DIR . 'assets/vendor/pdfjs/pdf' . $min . '.js' ) ? 'pdf' . $min . '.js' : 'pdf.min.js';
		$wrk = file_exists( VWL_EBOOK_DIR . 'assets/vendor/pdfjs/pdf.worker' . $min . '.js' ) ? 'pdf.worker' . $min . '.js' : 'pdf.worker.min.js';

		wp_register_script( 'vwl-ebook-pdfjs', VWL_EBOOK_URL . 'assets/vendor/pdfjs/' . $pdf, array(), VWL_EBOOK_PDFJS_VERSION, true );
		wp_register_script( 'wp-flip-book', VWL_EBOOK_URL . 'assets/js/reader.js', array( 'vwl-ebook-pdfjs' ), VWL_EBOOK_VERSION, true );
		wp_register_style( 'wp-flip-book', VWL_EBOOK_URL . 'assets/css/reader.css', array(), VWL_EBOOK_VERSION );

		$messages = VWL_Ebook_Proxy::messages();

		wp_localize_script(
			'wp-flip-book',
			'vwlEbookGlobals',
			array(
				'workerSrc'           => VWL_EBOOK_URL . 'assets/vendor/pdfjs/' . $wrk . '?ver=' . VWL_EBOOK_PDFJS_VERSION,
				'cMapUrl'             => VWL_EBOOK_URL . 'assets/vendor/pdfjs/cmaps/',
				'standardFontDataUrl' => VWL_EBOOK_URL . 'assets/vendor/pdfjs/standard_fonts/',
				'ajaxUrl'             => VWL_Ebook_Drive::ajax_url(),
				'errors'              => $messages,
				'i18n'                => array(
					'opening'          => __( 'Opening your book…', 'wp-flip-book' ),
					'preparing'        => __( 'Preparing your book…', 'wp-flip-book' ),
					'loadingPct'       => __( 'Loading %s%%', 'wp-flip-book' ),
					'stillLoading'     => __( 'Still loading the book. Please wait…', 'wp-flip-book' ),
					'errorTitle'       => __( 'Unable to open this book', 'wp-flip-book' ),
					'retry'            => __( 'Try again', 'wp-flip-book' ),
					'openFallback'     => __( 'Open with Google Drive viewer', 'wp-flip-book' ),
					'adminHint'        => __( 'Shown to editors only. Error code: %s. Check Settings → WP Flip Book → Test a link.', 'wp-flip-book' ),
					'pageOf'           => __( 'Page %1$s of %2$s', 'wp-flip-book' ),
					'pagesOf'          => __( 'Pages %1$s–%2$s of %3$s', 'wp-flip-book' ),
					'pageShort'        => __( '%1$s / %2$s', 'wp-flip-book' ),
					'readingPct'       => __( 'Reading: %s%%', 'wp-flip-book' ),
					'pageLabel'        => __( 'Page %s', 'wp-flip-book' ),
					'resumeTitle'      => __( 'Continue reading from page %s?', 'wp-flip-book' ),
					'resumeYes'        => __( 'Continue reading', 'wp-flip-book' ),
					'resumeNo'         => __( 'Start from beginning', 'wp-flip-book' ),
					'searchNoText'     => __( 'This PDF does not contain searchable text.', 'wp-flip-book' ),
					'searching'        => __( 'Searching… %s%%', 'wp-flip-book' ),
					'noMatches'        => __( 'No matches', 'wp-flip-book' ),
					'matchOf'          => __( '%1$s of %2$s', 'wp-flip-book' ),
					'matchOfMany'      => __( '%1$s of %2$s+', 'wp-flip-book' ),
					'tocEmpty'         => __( 'This book has no table of contents.', 'wp-flip-book' ),
					'printPreparing'   => __( 'Preparing pages for printing… %1$s / %2$s', 'wp-flip-book' ),
					'completeTitle'    => __( "You've reached the end of this book.", 'wp-flip-book' ),
					'zoomLabel'        => __( 'Zoom %s%%', 'wp-flip-book' ),
					'cancel'           => __( 'Cancel', 'wp-flip-book' ),
				),
			)
		);
	}

	/**
	 * Enqueue assets (safe to call multiple times, including late in the page).
	 */
	public static function enqueue() {
		if ( ! wp_script_is( 'wp-flip-book', 'registered' ) ) {
			self::register_assets();
		}
		wp_enqueue_style( 'wp-flip-book' );
		wp_enqueue_script( 'wp-flip-book' );
	}

	/**
	 * Resolve attributes against site defaults.
	 *
	 * @param array $atts Raw attributes (shortcode/block/post type).
	 * @return array
	 */
	public static function resolve( $atts ) {
		$s    = VWL_Ebook_Settings::all();
		$atts = is_array( $atts ) ? $atts : array();
		$get  = function ( $key ) use ( $atts ) {
			return isset( $atts[ $key ] ) ? $atts[ $key ] : null;
		};

		$source  = $get( 'url' );
		$source  = ( null === $source || '' === $source ) ? (string) $get( 'file_id' ) : (string) $source;
		$file_id = VWL_Ebook_Drive::extract_id( $source );

		$height_raw = trim( (string) $get( 'height' ) );
		if ( preg_match( '/^(\d{2,4})(px)?$/', $height_raw, $m ) ) {
			$height = max( 320, min( 2000, (int) $m[1] ) ) . 'px';
		} elseif ( preg_match( '/^(\d{2,3})(vh|svh|dvh)$/', $height_raw, $m ) ) {
			$height = max( 30, min( 100, (int) $m[1] ) ) . $m[2];
		} else {
			$height = absint( $s['height'] ) . 'px';
		}

		$theme = VWL_Ebook_Settings::pick( (string) $get( 'theme' ), array( 'light', 'dark', 'auto' ), $s['theme'] );

		$toolbar = VWL_Ebook_Settings::to_bool( $get( 'toolbar' ), true );

		$two_page_default = ( 'auto' === $s['page_mode'] );

		$cover_image = (string) $get( 'cover_image' );
		$cover_image = '' !== $cover_image ? esc_url_raw( $cover_image, array( 'http', 'https' ) ) : '';

		return array(
			'file_id'       => $file_id,
			'source'        => $source,
			'title'         => sanitize_text_field( (string) $get( 'title' ) ),
			'author'        => sanitize_text_field( (string) $get( 'author' ) ),
			'ebook_id'      => absint( $get( 'ebook_id' ) ),
			'height'        => $height,
			'theme'         => $theme,
			'toolbar'       => $toolbar,
			'nav'           => VWL_Ebook_Settings::to_bool( $get( 'nav' ), $s['btn_nav'] ),
			'zoom'          => VWL_Ebook_Settings::to_bool( $get( 'zoom' ), $s['btn_zoom'] ),
			'search'        => VWL_Ebook_Settings::to_bool( $get( 'search' ), $s['btn_search'] ),
			'fullscreen'    => VWL_Ebook_Settings::to_bool( $get( 'fullscreen' ), $s['btn_fullscreen'] ),
			'download'      => VWL_Ebook_Settings::to_bool( $get( 'download' ), $s['btn_download'] ),
			'print'         => VWL_Ebook_Settings::to_bool( $get( 'print' ), $s['btn_print'] ),
			'darkmode'      => VWL_Ebook_Settings::to_bool( $get( 'darkmode' ), $s['btn_theme'] ),
			'toc'           => VWL_Ebook_Settings::to_bool( $get( 'toc' ), $s['btn_toc'] ),
			'remember'      => VWL_Ebook_Settings::to_bool( $get( 'remember' ), $s['remember'] ),
			'cover'         => VWL_Ebook_Settings::to_bool( $get( 'cover' ), $s['cover'] ),
			'cover_image'   => $cover_image,
			'two_page'      => VWL_Ebook_Settings::to_bool( $get( 'two_page' ), $two_page_default ),
			'mobile_single' => VWL_Ebook_Settings::to_bool( $get( 'mobile_single' ), true ),
			'animations'    => VWL_Ebook_Settings::to_bool( $get( 'animations' ), $s['animations'] ),
			'completion'    => VWL_Ebook_Settings::to_bool( $get( 'completion' ), $s['completion'] ),
			'class'         => sanitize_html_class( (string) $get( 'class' ) ),
		);
	}

	/**
	 * Render the reader.
	 *
	 * @param array $atts Raw attributes.
	 * @return string HTML.
	 */
	public static function render( $atts ) {
		$o = self::resolve( $atts );
		$s = VWL_Ebook_Settings::all();
		self::enqueue();
		++self::$count;

		$reader_id = 'vwl-ebook-' . self::$count;
		$title     = '' !== $o['title'] ? $o['title'] : __( 'E-book', 'wp-flip-book' );
		$file_id   = $o['file_id'];
		$has_file  = '' !== $file_id;

		$config = array(
			'id'            => $reader_id,
			'fileId'        => $file_id,
			'src'           => $has_file ? VWL_Ebook_Drive::file_url( $file_id ) : '',
			'prepareUrl'    => $has_file ? VWL_Ebook_Drive::prepare_url( $file_id ) : '',
			'downloadUrl'   => ( $has_file && $o['download'] ) ? VWL_Ebook_Drive::download_url( $file_id, $title ) : '',
			'fallbackUrl'   => ( $has_file && ! empty( $s['fallback_iframe'] ) ) ? VWL_Ebook_Drive::preview_url( $file_id ) : '',
			'token'         => $has_file ? VWL_Ebook_Drive::token( $file_id ) : '',
			'title'         => $title,
			'ebookId'       => $o['ebook_id'],
			'theme'         => $o['theme'],
			'remember'      => $o['remember'],
			'cover'         => $o['cover'],
			'coverImage'    => $o['cover_image'],
			'twoPage'       => $o['two_page'],
			'mobileSingle'  => $o['mobile_single'],
			'animations'    => $o['animations'],
			'animationMs'   => absint( $s['animation_speed'] ),
			'lazy'          => (bool) $s['lazy_load'],
			'preload'       => (bool) $s['preload'],
			'maxScale'      => (float) $s['max_scale'],
			'analytics'     => (bool) $s['analytics'],
			'canEdit'       => current_user_can( 'edit_posts' ),
			'features'      => array(
				'toolbar'    => $o['toolbar'],
				'nav'        => $o['nav'],
				'zoom'       => $o['zoom'],
				'search'     => $o['search'],
				'fullscreen' => $o['fullscreen'],
				'download'   => $o['download'] && $has_file,
				'print'      => $o['print'],
				'darkmode'   => $o['darkmode'],
				'toc'        => $o['toc'],
			),
			'completion'    => array(
				'enabled'     => $o['completion'],
				'message'     => (string) $s['completion_message'],
				'buttonLabel' => (string) $s['completion_button_label'],
				'buttonUrl'   => (string) $s['completion_button_url'],
			),
		);

		/**
		 * Filter the reader configuration passed to JavaScript.
		 *
		 * @param array $config Config.
		 * @param array $o      Resolved options.
		 */
		$config = apply_filters( 'vwl_ebook_reader_config', $config, $o );

		$style = sprintf(
			'--vwl-height:%1$s;--vwl-primary:%2$s;--vwl-stage-light:%3$s;--vwl-flip-ms:%4$dms;',
			$o['height'],
			$s['primary_color'],
			$s['background_color'],
			absint( $s['animation_speed'] )
		);

		$classes = array(
			'vwl-ebook',
			'vwl-ebook--shadow-' . $s['page_shadow'],
			'vwl-ebook--toolbar-' . $s['toolbar_position'],
		);
		if ( ! $o['toolbar'] ) {
			$classes[] = 'vwl-ebook--no-toolbar';
		}
		if ( ! $o['nav'] ) {
			$classes[] = 'vwl-ebook--no-nav';
		}
		if ( '' !== $o['class'] ) {
			$classes[] = $o['class'];
		}

		$view = array(
			'reader_id' => $reader_id,
			'title'     => $title,
			'author'    => $o['author'],
			'config'    => $config,
			'style'     => $style,
			'classes'   => $classes,
			'theme'     => $o['theme'],
			'f'         => $config['features'],
			'cover'     => $o['cover'],
		);

		$html = '';

		// When rendered after wp_head (page builders, widgets), print the stylesheet inline
		// so visitors never see an unstyled reader.
		if ( did_action( 'wp_head' ) && ! wp_style_is( 'wp-flip-book', 'done' ) ) {
			ob_start();
			wp_print_styles( 'wp-flip-book' );
			$html .= ob_get_clean();
		}

		ob_start();
		include VWL_EBOOK_DIR . 'templates/reader.php';
		$html .= ob_get_clean();

		return $html;
	}
}
