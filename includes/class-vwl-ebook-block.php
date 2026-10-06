<?php
/**
 * Gutenberg block "VWL Flip Book" (no build step required).
 *
 * @package VWL_Ebook_Reader
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Block.
 */
class VWL_Ebook_Block {

	const NAME = 'vwl/ebook-reader';

	/**
	 * Hook.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ), 20 );
	}

	/**
	 * Block attributes. Defaults mirror the current site settings.
	 *
	 * @return array
	 */
	public static function attributes() {
		$s = VWL_Ebook_Settings::all();
		return array(
			'url'          => array(
				'type'    => 'string',
				'default' => '',
			),
			'ebookId'      => array(
				'type'    => 'number',
				'default' => 0,
			),
			'title'        => array(
				'type'    => 'string',
				'default' => '',
			),
			'height'       => array(
				'type'    => 'number',
				'default' => (int) $s['height'],
			),
			'theme'        => array(
				'type'    => 'string',
				'default' => (string) $s['theme'],
			),
			'toolbar'      => array(
				'type'    => 'boolean',
				'default' => true,
			),
			'search'       => array(
				'type'    => 'boolean',
				'default' => (bool) $s['btn_search'],
			),
			'download'     => array(
				'type'    => 'boolean',
				'default' => (bool) $s['btn_download'],
			),
			'print'        => array(
				'type'    => 'boolean',
				'default' => (bool) $s['btn_print'],
			),
			'fullscreen'   => array(
				'type'    => 'boolean',
				'default' => (bool) $s['btn_fullscreen'],
			),
			'toc'          => array(
				'type'    => 'boolean',
				'default' => (bool) $s['btn_toc'],
			),
			'remember'     => array(
				'type'    => 'boolean',
				'default' => (bool) $s['remember'],
			),
			'twoPage'      => array(
				'type'    => 'boolean',
				'default' => ( 'auto' === $s['page_mode'] ),
			),
			'mobileSingle' => array(
				'type'    => 'boolean',
				'default' => true,
			),
			'cover'        => array(
				'type'    => 'boolean',
				'default' => (bool) $s['cover'],
			),
		);
	}

	/**
	 * Register script and block.
	 */
	public static function register() {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}

		wp_register_script(
			'vwl-ebook-block',
			VWL_EBOOK_URL . 'assets/js/block.js',
			array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n', 'wp-data', 'wp-core-data' ),
			VWL_EBOOK_VERSION,
			true
		);
		wp_register_style( 'vwl-ebook-block-editor', VWL_EBOOK_URL . 'assets/css/block-editor.css', array(), VWL_EBOOK_VERSION );

		if ( function_exists( 'wp_set_script_translations' ) ) {
			wp_set_script_translations( 'vwl-ebook-block', 'vwl-flip-book', VWL_EBOOK_DIR . 'languages' );
		}

		register_block_type(
			self::NAME,
			array(
				'api_version'     => 3,
				'title'           => __( 'VWL Flip Book', 'vwl-flip-book' ),
				'description'     => __( 'Display a Google Drive PDF as an interactive e-book.', 'vwl-flip-book' ),
				'category'        => 'media',
				'icon'            => 'book-alt',
				'keywords'        => array( 'pdf', 'ebook', 'google drive', 'flipbook' ),
				'textdomain'      => 'vwl-flip-book',
				'supports'        => array(
					'align'    => array( 'wide', 'full' ),
					'html'     => false,
					'multiple' => true,
				),
				'attributes'      => self::attributes(),
				'editor_script'   => 'vwl-ebook-block',
				'editor_style'    => 'vwl-ebook-block-editor',
				'render_callback' => array( __CLASS__, 'render' ),
			)
		);
	}

	/**
	 * Server render.
	 *
	 * @param array $attributes Block attributes.
	 * @return string
	 */
	public static function render( $attributes ) {
		$a    = is_array( $attributes ) ? $attributes : array();
		$bool = function ( $key ) use ( $a ) {
			return ! empty( $a[ $key ] ) ? 'true' : 'false';
		};

		$atts = array();
		if ( ! empty( $a['ebookId'] ) ) {
			$atts = VWL_Ebook_Post_Type::get_book_atts( absint( $a['ebookId'] ) );
		}
		if ( ! empty( $a['url'] ) ) {
			$atts['url'] = (string) $a['url'];
		}
		if ( ! empty( $a['title'] ) ) {
			$atts['title'] = (string) $a['title'];
		}

		if ( empty( $atts['url'] ) ) {
			if ( current_user_can( 'edit_posts' ) ) {
				return '<p class="vwl-ebook-notice">' . esc_html__( 'VWL Flip Book: add a Google Drive PDF link in the block settings.', 'vwl-flip-book' ) . '</p>';
			}
			return '';
		}

		$atts['height']        = isset( $a['height'] ) ? absint( $a['height'] ) : '';
		$atts['theme']         = isset( $a['theme'] ) ? (string) $a['theme'] : '';
		$atts['toolbar']       = $bool( 'toolbar' );
		$atts['search']        = $bool( 'search' );
		$atts['fullscreen']    = $bool( 'fullscreen' );
		$atts['toc']           = $bool( 'toc' );
		$atts['remember']      = $bool( 'remember' );
		$atts['two_page']      = $bool( 'twoPage' );
		$atts['mobile_single'] = $bool( 'mobileSingle' );
		// A library book's own download/print/cover choice wins unless the block changes it.
		foreach ( array( 'download', 'print', 'cover' ) as $key ) {
			if ( empty( $a['ebookId'] ) || ! isset( $atts[ $key ] ) ) {
				$atts[ $key ] = $bool( $key );
			}
		}

		$wrapper = function_exists( 'get_block_wrapper_attributes' ) ? get_block_wrapper_attributes( array( 'class' => 'vwl-ebook-block' ) ) : 'class="vwl-ebook-block"';

		return '<div ' . $wrapper . '>' . VWL_Ebook_Renderer::render( $atts ) . '</div>';
	}
}
