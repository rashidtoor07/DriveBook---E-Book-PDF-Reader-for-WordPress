<?php
/**
 * Inline SVG icons (no icon fonts, no external requests).
 *
 * @package VWL_Ebook_Reader
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Icon helper.
 */
class VWL_Ebook_Icons {

	/**
	 * Path data for each icon (24x24 viewBox, stroked).
	 *
	 * @return array
	 */
	private static function paths() {
		return array(
			'prev'           => '<path d="M15 18l-6-6 6-6"/>',
			'next'           => '<path d="M9 18l6-6-6-6"/>',
			'zoom-in'        => '<circle cx="11" cy="11" r="6.5"/><path d="M20 20l-4.2-4.2M11 8v6M8 11h6"/>',
			'zoom-out'       => '<circle cx="11" cy="11" r="6.5"/><path d="M20 20l-4.2-4.2M8 11h6"/>',
			'fit-width'      => '<path d="M4 5v14M20 5v14M7.5 12h9M10 9.5L7.5 12l2.5 2.5M14 9.5l2.5 2.5-2.5 2.5"/>',
			'fit-page'       => '<rect x="6" y="3.5" width="12" height="17" rx="1.5"/><path d="M9 8h6M9 11.5h6M9 15h4"/>',
			'search'         => '<circle cx="11" cy="11" r="6.5"/><path d="M20 20l-4.2-4.2"/>',
			'moon'           => '<path d="M20 14.5A8 8 0 019.5 4a8 8 0 1010.5 10.5z"/>',
			'sun'            => '<circle cx="12" cy="12" r="4"/><path d="M12 2.5v2M12 19.5v2M4.6 4.6l1.4 1.4M18 18l1.4 1.4M2.5 12h2M19.5 12h2M4.6 19.4L6 18M18 6l1.4-1.4"/>',
			'fullscreen'     => '<path d="M4 9V4h5M20 9V4h-5M4 15v5h5M20 15v5h-5"/>',
			'fullscreen-off' => '<path d="M9 4v5H4M15 4v5h5M9 20v-5H4M15 20v-5h5"/>',
			'download'       => '<path d="M12 4v11M7.5 10.5L12 15l4.5-4.5M5 19.5h14"/>',
			'print'          => '<path d="M7 9V4h10v5"/><rect x="4" y="9" width="16" height="7" rx="1.5"/><path d="M7 14h10v6H7z"/>',
			'toc'            => '<path d="M9 6h11M9 12h11M9 18h11"/><circle cx="4.5" cy="6" r=".9"/><circle cx="4.5" cy="12" r=".9"/><circle cx="4.5" cy="18" r=".9"/>',
			'more'           => '<circle cx="12" cy="5.5" r="1.2"/><circle cx="12" cy="12" r="1.2"/><circle cx="12" cy="18.5" r="1.2"/>',
			'close'          => '<path d="M6 6l12 12M18 6L6 18"/>',
			'up'             => '<path d="M6 15l6-6 6 6"/>',
			'down'           => '<path d="M6 9l6 6 6-6"/>',
			'book'           => '<path d="M12 6.5C10 5 7 4.5 3.5 5v13c3.5-.5 6.5 0 8.5 1.5 2-1.5 5-2 8.5-1.5V5C17 4.5 14 5 12 6.5z"/><path d="M12 6.5v13"/>',
			'reset'          => '<path d="M4 12a8 8 0 108-8H8.5"/><path d="M10.5 1.5L8 4l2.5 2.5"/>',
		);
	}

	/**
	 * Return the SVG markup for an icon.
	 *
	 * @param string $name Icon name.
	 * @return string Safe SVG markup.
	 */
	public static function get( $name ) {
		$paths = self::paths();
		if ( ! isset( $paths[ $name ] ) ) {
			return '';
		}
		return '<svg class="vwl-ebook__icon vwl-ebook__icon--' . esc_attr( $name ) . '" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $paths[ $name ] . '</svg>';
	}

	/**
	 * Echo an icon. Markup is built from a fixed internal whitelist above.
	 *
	 * @param string $name Icon name.
	 */
	public static function render( $name ) {
		echo self::get( $name ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static internal SVG.
	}
}
