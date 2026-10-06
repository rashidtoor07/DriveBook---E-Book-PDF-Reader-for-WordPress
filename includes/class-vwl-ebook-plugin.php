<?php
/**
 * Core plugin wiring.
 *
 * @package VWL_Ebook_Reader
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugin.
 */
class VWL_Ebook_Plugin {

	/**
	 * Singleton.
	 *
	 * @var VWL_Ebook_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Get instance.
	 *
	 * @return VWL_Ebook_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		add_action( 'init', array( $this, 'load_textdomain' ), 1 );
		add_action( 'init', array( 'VWL_Ebook_Renderer', 'register_assets' ), 5 );
		add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue' ) );
		add_action( 'init', array( $this, 'ensure_cron' ) );

		VWL_Ebook_Proxy::init();
		VWL_Ebook_Shortcode::init();
		VWL_Ebook_Post_Type::init();
		VWL_Ebook_Block::init();
		VWL_Ebook_Analytics::init();

		if ( is_admin() ) {
			VWL_Ebook_Admin::init();
		}
	}

	/**
	 * Load translations.
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'vwl-ebook-reader', false, dirname( plugin_basename( VWL_EBOOK_FILE ) ) . '/languages' );
	}

	/**
	 * Enqueue assets in <head> when we can tell the page needs them.
	 * Pages built with page builders are handled by the late enqueue inside the renderer.
	 */
	public function maybe_enqueue() {
		if ( is_singular( VWL_Ebook_Post_Type::POST_TYPE ) ) {
			VWL_Ebook_Renderer::enqueue();
			return;
		}
		if ( ! is_singular() ) {
			return;
		}
		$post = get_post();
		if ( ! $post || empty( $post->post_content ) ) {
			return;
		}
		$content = $post->post_content;
		if (
			has_shortcode( $content, 'pdf_ebook' ) ||
			has_shortcode( $content, 'e_book' ) ||
			( function_exists( 'has_block' ) && has_block( VWL_Ebook_Block::NAME, $post ) )
		) {
			VWL_Ebook_Renderer::enqueue();
		}
	}

	/**
	 * Make sure the cleanup cron exists.
	 */
	public function ensure_cron() {
		if ( ! wp_next_scheduled( VWL_Ebook_Proxy::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', VWL_Ebook_Proxy::CRON_HOOK );
		}
	}

	/**
	 * Activation.
	 */
	public static function activate() {
		if ( false === get_option( VWL_Ebook_Settings::OPTION, false ) ) {
			add_option( VWL_Ebook_Settings::OPTION, VWL_Ebook_Settings::defaults() );
		}
		VWL_Ebook_Post_Type::register();
		flush_rewrite_rules();
		VWL_Ebook_Proxy::cache_dir();
		if ( ! wp_next_scheduled( VWL_Ebook_Proxy::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', VWL_Ebook_Proxy::CRON_HOOK );
		}
		update_option( 'vwl_ebook_show_welcome', 1, false );
	}

	/**
	 * Deactivation.
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( VWL_Ebook_Proxy::CRON_HOOK );
		flush_rewrite_rules();
	}
}
