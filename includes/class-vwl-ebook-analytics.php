<?php
/**
 * Analytics hooks.
 *
 * When "Send reader events to WordPress hooks" is enabled, the reader sends small
 * beacons that fire these actions (no personal data is collected by this plugin):
 *
 *   do_action( 'vwl_ebook_opened',      array $data );
 *   do_action( 'vwl_ebook_page_viewed', array $data );
 *   do_action( 'vwl_ebook_completed',   array $data );
 *   do_action( 'vwl_ebook_search',      array $data );
 *
 * $data = array(
 *   'ebook_id'    => int    E-Book post ID (0 for plain [pdf_ebook] embeds),
 *   'file_id'     => string Google Drive file ID,
 *   'pdf_url'     => string Public Google Drive view URL,
 *   'page'        => int    Current page,
 *   'total_pages' => int    Total pages,
 *   'query'       => string Search phrase (search event only),
 *   'source_url'  => string Page the reader is embedded on,
 * );
 *
 * Regardless of this setting, the browser also dispatches DOM events on the reader
 * element (vwl-ebook:opened, vwl-ebook:page, vwl-ebook:completed, vwl-ebook:search)
 * for client-side tools such as Google Analytics.
 *
 * @package VWL_Ebook_Reader
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Analytics endpoint.
 */
class VWL_Ebook_Analytics {

	/**
	 * Hook.
	 */
	public static function init() {
		add_action( 'wp_ajax_vwl_ebook_event', array( __CLASS__, 'handle' ) );
		add_action( 'wp_ajax_nopriv_vwl_ebook_event', array( __CLASS__, 'handle' ) );
	}

	/**
	 * Handle an event beacon.
	 */
	public static function handle() {
		if ( ! VWL_Ebook_Settings::get( 'analytics' ) ) {
			wp_send_json_error( null, 404 );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- signed with the HMAC file token (cache-safe).
		$id    = isset( $_POST['id'] ) ? sanitize_text_field( wp_unslash( $_POST['id'] ) ) : '';
		$token = isset( $_POST['t'] ) ? sanitize_text_field( wp_unslash( $_POST['t'] ) ) : '';
		$event = isset( $_POST['event'] ) ? sanitize_key( wp_unslash( $_POST['event'] ) ) : '';
		$data  = array(
			'ebook_id'    => isset( $_POST['ebook'] ) ? absint( $_POST['ebook'] ) : 0,
			'file_id'     => $id,
			'pdf_url'     => VWL_Ebook_Drive::is_valid_id( $id ) ? VWL_Ebook_Drive::view_url( $id ) : '',
			'page'        => isset( $_POST['page'] ) ? absint( $_POST['page'] ) : 0,
			'total_pages' => isset( $_POST['total'] ) ? absint( $_POST['total'] ) : 0,
			'query'       => isset( $_POST['query'] ) ? mb_substr( sanitize_text_field( wp_unslash( $_POST['query'] ) ), 0, 120 ) : '',
			'source_url'  => isset( $_POST['source'] ) ? esc_url_raw( wp_unslash( $_POST['source'] ) ) : '',
		);
		// phpcs:enable

		if ( ! VWL_Ebook_Drive::verify( $id, $token ) ) {
			wp_send_json_error( null, 403 );
		}

		$allowed = array( 'opened', 'page_viewed', 'completed', 'search' );
		if ( ! in_array( $event, $allowed, true ) ) {
			wp_send_json_error( null, 400 );
		}

		do_action( 'vwl_ebook_' . $event, $data );
		wp_send_json_success();
	}
}
