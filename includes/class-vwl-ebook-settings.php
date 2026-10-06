<?php
/**
 * Plugin settings: defaults, retrieval and sanitization.
 *
 * @package VWL_Ebook_Reader
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings helper.
 */
class VWL_Ebook_Settings {

	const OPTION = 'vwl_ebook_settings';

	/**
	 * Cached settings for the request.
	 *
	 * @var array|null
	 */
	private static $cache = null;

	/**
	 * Default settings.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			// General.
			'height'                  => 760,
			'theme'                   => 'light',
			'page_mode'               => 'auto',
			'animations'              => 1,
			'remember'                => 1,
			'cover'                   => 0,
			'completion'              => 1,
			'completion_message'      => '',
			'completion_button_label' => '',
			'completion_button_url'   => '',
			'fallback_iframe'         => 1,
			// Toolbar.
			'btn_nav'                 => 1,
			'btn_zoom'                => 1,
			'btn_search'              => 1,
			'btn_fullscreen'          => 1,
			'btn_download'            => 0,
			'btn_print'               => 0,
			'btn_theme'               => 1,
			'btn_toc'                 => 1,
			// Performance.
			'lazy_load'               => 1,
			'preload'                 => 1,
			'max_scale'               => 2,
			'cache'                   => 1,
			'cache_hours'             => 24,
			'max_file_mb'             => 150,
			// Appearance.
			'primary_color'           => '#2f5d8a',
			'background_color'        => '#e4e7eb',
			'page_shadow'             => 'soft',
			'toolbar_position'        => 'top',
			'animation_speed'         => 650,
			// Advanced.
			'analytics'               => 0,
			'delete_data'             => 0,
		);
	}

	/**
	 * Keys that are booleans (checkboxes).
	 *
	 * @return string[]
	 */
	public static function bool_keys() {
		return array(
			'animations',
			'remember',
			'cover',
			'completion',
			'fallback_iframe',
			'btn_nav',
			'btn_zoom',
			'btn_search',
			'btn_fullscreen',
			'btn_download',
			'btn_print',
			'btn_theme',
			'btn_toc',
			'lazy_load',
			'preload',
			'cache',
			'analytics',
			'delete_data',
		);
	}

	/**
	 * Get all settings merged with defaults.
	 *
	 * @return array
	 */
	public static function all() {
		if ( null === self::$cache ) {
			$saved       = get_option( self::OPTION, array() );
			self::$cache = wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
		}
		return self::$cache;
	}

	/**
	 * Get one setting.
	 *
	 * @param string $key Setting key.
	 * @return mixed
	 */
	public static function get( $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	/**
	 * Reset the in-memory cache (after saving).
	 */
	public static function flush() {
		self::$cache = null;
	}

	/**
	 * Sanitize the settings array submitted from the settings page.
	 *
	 * @param mixed $input Raw input.
	 * @return array
	 */
	public static function sanitize( $input ) {
		$defaults = self::defaults();
		$input    = is_array( $input ) ? $input : array();
		$out      = array();

		foreach ( self::bool_keys() as $key ) {
			$out[ $key ] = empty( $input[ $key ] ) ? 0 : 1;
		}

		$out['height']          = self::clamp_int( isset( $input['height'] ) ? $input['height'] : $defaults['height'], 320, 2000 );
		$out['max_scale']       = self::clamp_float( isset( $input['max_scale'] ) ? $input['max_scale'] : $defaults['max_scale'], 1, 4 );
		$out['cache_hours']     = self::clamp_int( isset( $input['cache_hours'] ) ? $input['cache_hours'] : $defaults['cache_hours'], 1, 720 );
		$out['max_file_mb']     = self::clamp_int( isset( $input['max_file_mb'] ) ? $input['max_file_mb'] : $defaults['max_file_mb'], 5, 1024 );
		$out['animation_speed'] = self::clamp_int( isset( $input['animation_speed'] ) ? $input['animation_speed'] : $defaults['animation_speed'], 200, 1500 );

		$out['theme']            = self::pick( isset( $input['theme'] ) ? $input['theme'] : '', array( 'light', 'dark', 'auto' ), $defaults['theme'] );
		$out['page_mode']        = self::pick( isset( $input['page_mode'] ) ? $input['page_mode'] : '', array( 'auto', 'single' ), $defaults['page_mode'] );
		$out['page_shadow']      = self::pick( isset( $input['page_shadow'] ) ? $input['page_shadow'] : '', array( 'none', 'soft', 'strong' ), $defaults['page_shadow'] );
		$out['toolbar_position'] = self::pick( isset( $input['toolbar_position'] ) ? $input['toolbar_position'] : '', array( 'top', 'bottom' ), $defaults['toolbar_position'] );

		$primary              = isset( $input['primary_color'] ) ? sanitize_hex_color( $input['primary_color'] ) : '';
		$background           = isset( $input['background_color'] ) ? sanitize_hex_color( $input['background_color'] ) : '';
		$out['primary_color']    = $primary ? $primary : $defaults['primary_color'];
		$out['background_color'] = $background ? $background : $defaults['background_color'];

		$out['completion_message']      = isset( $input['completion_message'] ) ? sanitize_textarea_field( $input['completion_message'] ) : '';
		$out['completion_button_label'] = isset( $input['completion_button_label'] ) ? sanitize_text_field( $input['completion_button_label'] ) : '';
		$out['completion_button_url']   = isset( $input['completion_button_url'] ) ? esc_url_raw( $input['completion_button_url'], array( 'http', 'https' ) ) : '';

		self::flush();
		return $out;
	}

	/**
	 * Clamp an integer.
	 *
	 * @param mixed $value Value.
	 * @param int   $min   Min.
	 * @param int   $max   Max.
	 * @return int
	 */
	public static function clamp_int( $value, $min, $max ) {
		$value = absint( $value );
		return max( $min, min( $max, $value ) );
	}

	/**
	 * Clamp a float.
	 *
	 * @param mixed $value Value.
	 * @param float $min   Min.
	 * @param float $max   Max.
	 * @return float
	 */
	public static function clamp_float( $value, $min, $max ) {
		$value = is_numeric( $value ) ? (float) $value : $min;
		return round( max( $min, min( $max, $value ) ), 2 );
	}

	/**
	 * Whitelist a value.
	 *
	 * @param mixed    $value    Value.
	 * @param string[] $allowed  Allowed values.
	 * @param string   $fallback Fallback.
	 * @return string
	 */
	public static function pick( $value, $allowed, $fallback ) {
		$value = sanitize_key( (string) $value );
		return in_array( $value, $allowed, true ) ? $value : $fallback;
	}

	/**
	 * Interpret shortcode/block boolean-ish values.
	 *
	 * @param mixed $value    Value.
	 * @param bool  $fallback Fallback when empty/unknown.
	 * @return bool
	 */
	public static function to_bool( $value, $fallback ) {
		if ( is_bool( $value ) ) {
			return $value;
		}
		if ( null === $value || '' === $value ) {
			return (bool) $fallback;
		}
		$value = strtolower( trim( (string) $value ) );
		if ( in_array( $value, array( '1', 'true', 'yes', 'on' ), true ) ) {
			return true;
		}
		if ( in_array( $value, array( '0', 'false', 'no', 'off' ), true ) ) {
			return false;
		}
		return (bool) $fallback;
	}
}
