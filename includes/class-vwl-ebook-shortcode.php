<?php
/**
 * Shortcodes: [vwl_ebook] and [vwl_ebook_library].
 *
 * @package VWL_Ebook_Reader
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shortcode handlers.
 */
class VWL_Ebook_Shortcode {

	/**
	 * Register shortcodes.
	 */
	public static function init() {
		add_shortcode( 'vwl_ebook', array( __CLASS__, 'vwl_ebook' ) );
		add_shortcode( 'vwl_ebook_library', array( __CLASS__, 'vwl_ebook_library' ) );
	}

	/**
	 * Attributes accepted by both shortcodes (null = use site default).
	 *
	 * @return array
	 */
	public static function attribute_defaults() {
		return array(
			'url'           => '',
			'file_id'       => '',
			'title'         => '',
			'author'        => '',
			'height'        => '',
			'theme'         => '',
			'toolbar'       => '',
			'nav'           => '',
			'zoom'          => '',
			'search'        => '',
			'fullscreen'    => '',
			'download'      => '',
			'print'         => '',
			'darkmode'      => '',
			'toc'           => '',
			'remember'      => '',
			'cover'         => '',
			'cover_image'   => '',
			'two_page'      => '',
			'mobile_single' => '',
			'animations'    => '',
			'completion'    => '',
			'class'         => '',
		);
	}

	/**
	 * [vwl_ebook url="..."].
	 *
	 * @param array|string $atts Attributes.
	 * @return string
	 */
	public static function vwl_ebook( $atts ) {
		$atts = shortcode_atts( self::attribute_defaults(), (array) $atts, 'vwl_ebook' );
		return VWL_Ebook_Renderer::render( $atts );
	}

	/**
	 * [vwl_ebook_library id="123"] – renders a book from the E-Books library.
	 *
	 * @param array|string $atts Attributes.
	 * @return string
	 */
	public static function vwl_ebook_library( $atts ) {
		$defaults       = self::attribute_defaults();
		$defaults['id'] = 0;
		$atts           = shortcode_atts( $defaults, (array) $atts, 'vwl_ebook_library' );

		$post_id = absint( $atts['id'] );
		$book    = VWL_Ebook_Post_Type::get_book_atts( $post_id );

		if ( empty( $book ) ) {
			if ( current_user_can( 'edit_posts' ) ) {
				return '<p class="vwl-ebook-notice">' . esc_html__( 'VWL Flip Book: no published e-book was found with this ID.', 'vwl-flip-book' ) . '</p>';
			}
			return '';
		}

		unset( $atts['id'] );
		// Explicit shortcode attributes override the stored book values.
		foreach ( $atts as $key => $value ) {
			if ( '' !== $value ) {
				$book[ $key ] = $value;
			}
		}

		return VWL_Ebook_Renderer::render( $book );
	}
}
