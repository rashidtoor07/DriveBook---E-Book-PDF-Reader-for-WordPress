<?php
/**
 * Google Drive URL parsing and signed request tokens.
 *
 * @package VWL_Ebook_Reader
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Google Drive helpers.
 */
class VWL_Ebook_Drive {

	/**
	 * Allowed Google hosts for pasted URLs.
	 *
	 * @var string[]
	 */
	private static $hosts = array(
		'drive.google.com',
		'docs.google.com',
		'drive.usercontent.google.com',
	);

	/**
	 * Check that a string looks like a Google Drive file ID.
	 *
	 * @param string $id Candidate.
	 * @return bool
	 */
	public static function is_valid_id( $id ) {
		return is_string( $id ) && (bool) preg_match( '/^[A-Za-z0-9_-]{20,128}$/', $id );
	}

	/**
	 * Extract a Drive file ID from a URL or a bare ID.
	 *
	 * Supported:
	 *  - https://drive.google.com/file/d/FILE_ID/view?usp=sharing
	 *  - https://drive.google.com/file/d/FILE_ID/preview
	 *  - https://drive.google.com/open?id=FILE_ID
	 *  - https://drive.google.com/uc?id=FILE_ID&export=download
	 *  - https://docs.google.com/file/d/FILE_ID/edit
	 *  - https://drive.usercontent.google.com/download?id=FILE_ID
	 *  - FILE_ID
	 *
	 * @param string $input URL or ID.
	 * @return string File ID, or empty string when none found.
	 */
	public static function extract_id( $input ) {
		$input = trim( wp_strip_all_tags( (string) $input ) );
		$input = html_entity_decode( $input, ENT_QUOTES, 'UTF-8' );

		if ( '' === $input ) {
			return '';
		}

		if ( self::is_valid_id( $input ) ) {
			return $input;
		}

		$parts = wp_parse_url( $input );
		if ( empty( $parts['host'] ) ) {
			return '';
		}

		$host = strtolower( $parts['host'] );
		if ( ! in_array( $host, self::$hosts, true ) ) {
			return '';
		}

		if ( ! empty( $parts['path'] ) && preg_match( '#/(?:file/)?d/([A-Za-z0-9_-]{20,128})#', $parts['path'], $m ) ) {
			return $m[1];
		}

		if ( ! empty( $parts['query'] ) ) {
			parse_str( $parts['query'], $query );
			if ( ! empty( $query['id'] ) && is_string( $query['id'] ) && self::is_valid_id( $query['id'] ) ) {
				return $query['id'];
			}
		}

		return '';
	}

	/**
	 * Sign a file ID so the server only relays files this site actually embeds.
	 *
	 * A keyed HMAC is used instead of a nonce on purpose: nonces are tied to a user
	 * session and expire, which breaks readers on cached pages for anonymous visitors.
	 *
	 * @param string $id    File ID.
	 * @param string $scope Token scope (view|download).
	 * @return string
	 */
	public static function token( $id, $scope = 'view' ) {
		return substr( hash_hmac( 'sha256', 'vwl-ebook|' . $scope . '|' . $id, wp_salt( 'nonce' ) ), 0, 32 );
	}

	/**
	 * Verify a token.
	 *
	 * @param string $id    File ID.
	 * @param string $token Provided token.
	 * @param string $scope Scope.
	 * @return bool
	 */
	public static function verify( $id, $token, $scope = 'view' ) {
		if ( ! self::is_valid_id( $id ) || ! is_string( $token ) || 32 !== strlen( $token ) ) {
			return false;
		}
		return hash_equals( self::token( $id, $scope ), $token );
	}

	/**
	 * Relative admin-ajax URL (avoids http/https and domain mismatches).
	 *
	 * @return string
	 */
	public static function ajax_url() {
		return admin_url( 'admin-ajax.php', 'relative' );
	}

	/**
	 * URL the reader streams the PDF from.
	 *
	 * @param string $id File ID.
	 * @return string
	 */
	public static function file_url( $id ) {
		return add_query_arg(
			array(
				'action' => 'vwl_ebook_file',
				'id'     => rawurlencode( $id ),
				't'      => self::token( $id ),
				'v'      => VWL_EBOOK_VERSION,
			),
			self::ajax_url()
		);
	}

	/**
	 * URL that prepares (caches) the PDF and reports problems as JSON.
	 *
	 * @param string $id File ID.
	 * @return string
	 */
	public static function prepare_url( $id ) {
		return add_query_arg(
			array(
				'action' => 'vwl_ebook_prepare',
				'id'     => rawurlencode( $id ),
				't'      => self::token( $id ),
			),
			self::ajax_url()
		);
	}

	/**
	 * Download URL (only generated when downloads are allowed).
	 *
	 * @param string $id    File ID.
	 * @param string $title Book title used for the file name.
	 * @return string
	 */
	public static function download_url( $id, $title ) {
		return add_query_arg(
			array(
				'action' => 'vwl_ebook_file',
				'id'     => rawurlencode( $id ),
				't'      => self::token( $id ),
				'dl'     => self::token( $id, 'download' ),
				'name'   => rawurlencode( sanitize_title( $title ) ),
			),
			self::ajax_url()
		);
	}

	/**
	 * Google's own preview URL (used only as an optional fallback viewer).
	 *
	 * @param string $id File ID.
	 * @return string
	 */
	public static function preview_url( $id ) {
		return 'https://drive.google.com/file/d/' . rawurlencode( $id ) . '/preview';
	}

	/**
	 * Public "view" URL.
	 *
	 * @param string $id File ID.
	 * @return string
	 */
	public static function view_url( $id ) {
		return 'https://drive.google.com/file/d/' . rawurlencode( $id ) . '/view';
	}
}
