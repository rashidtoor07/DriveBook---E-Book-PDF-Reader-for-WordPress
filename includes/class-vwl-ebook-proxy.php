<?php
/**
 * Same-origin PDF relay for PUBLIC Google Drive files.
 *
 * Why this exists: Google Drive does not send CORS headers on file downloads, so
 * PDF.js running in a visitor's browser cannot read a Drive PDF directly. This class
 * fetches the file anonymously (no Google credentials, no cookies, no API keys), so it
 * only ever succeeds for files that are already publicly shared. Private files fail
 * exactly as they would for any anonymous visitor. Nothing is bypassed.
 *
 * The file is cached in uploads/vwl-ebook-cache/ and streamed with HTTP Range support so
 * PDF.js can load large books page by page.
 *
 * @package VWL_Ebook_Reader
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Relay and cache.
 */
class VWL_Ebook_Proxy {

	const CACHE_FOLDER = 'vwl-ebook-cache';
	const CRON_HOOK    = 'vwl_ebook_cleanup';

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'wp_ajax_vwl_ebook_file', array( __CLASS__, 'handle_file' ) );
		add_action( 'wp_ajax_nopriv_vwl_ebook_file', array( __CLASS__, 'handle_file' ) );
		add_action( 'wp_ajax_vwl_ebook_prepare', array( __CLASS__, 'handle_prepare' ) );
		add_action( 'wp_ajax_nopriv_vwl_ebook_prepare', array( __CLASS__, 'handle_prepare' ) );
		add_action( self::CRON_HOOK, array( __CLASS__, 'cleanup' ) );
	}

	/**
	 * Translatable, visitor-friendly messages for each error code.
	 *
	 * @return array
	 */
	public static function messages() {
		return array(
			'invalid_url'     => __( 'This book link is not a valid Google Drive file link.', 'vwl-ebook-reader' ),
			'invalid_request' => __( 'This book link has expired. Please reload the page.', 'vwl-ebook-reader' ),
			'private'         => __( 'This book cannot be opened because the Google Drive file is not shared publicly. The owner needs to set General access to "Anyone with the link".', 'vwl-ebook-reader' ),
			'not_found'       => __( 'Google Drive could not find this file. It may have been deleted, moved to the trash, or is not shared publicly.', 'vwl-ebook-reader' ),
			'not_pdf'         => __( 'This Google Drive file is not a PDF, or Google Drive did not return the PDF. Make sure the link points to a PDF file that is shared with "Anyone with the link".', 'vwl-ebook-reader' ),
			'too_large'       => __( 'This PDF is larger than the maximum size allowed by the site settings.', 'vwl-ebook-reader' ),
			'quota'           => __( 'Google Drive has temporarily limited access to this file because it was opened many times recently. Please try again later.', 'vwl-ebook-reader' ),
			'blocked'         => __( 'Google Drive temporarily refused the request from this website. Please try again later.', 'vwl-ebook-reader' ),
			'unavailable'     => __( 'Google Drive is not responding right now. Please try again in a few minutes.', 'vwl-ebook-reader' ),
			'network'         => __( 'The website could not connect to Google Drive. Please try again later.', 'vwl-ebook-reader' ),
			'storage'         => __( 'The website could not store the book temporarily. Please contact the site administrator.', 'vwl-ebook-reader' ),
			'corrupt'         => __( 'This PDF appears to be damaged and cannot be displayed.', 'vwl-ebook-reader' ),
			'password'        => __( 'This PDF is password-protected and cannot be displayed in the reader.', 'vwl-ebook-reader' ),
			'pdfjs'           => __( 'The book reader component could not be loaded. Please reload the page.', 'vwl-ebook-reader' ),
			'browser'         => __( 'Your browser does not support the book reader. Please update your browser or try another one.', 'vwl-ebook-reader' ),
			'generic'         => __( 'Unable to open this book. Please check that the Google Drive PDF is publicly accessible.', 'vwl-ebook-reader' ),
		);
	}

	/**
	 * Message for a code.
	 *
	 * @param string $code Error code.
	 * @return string
	 */
	public static function message( $code ) {
		$messages = self::messages();
		return isset( $messages[ $code ] ) ? $messages[ $code ] : $messages['generic'];
	}

	/**
	 * Cache directory (created and protected on demand).
	 *
	 * @return string Absolute path without trailing slash, or '' when unavailable.
	 */
	public static function cache_dir() {
		$uploads = wp_upload_dir( null, false );
		if ( ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) ) {
			return '';
		}
		$dir = trailingslashit( $uploads['basedir'] ) . self::CACHE_FOLDER;

		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return '';
		}

		// phpcs:disable WordPress.WP.AlternativeFunctions -- small static guard files in our own cache folder.
		if ( ! file_exists( $dir . '/index.php' ) ) {
			@file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		if ( ! file_exists( $dir . '/.htaccess' ) ) {
			@file_put_contents( // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				$dir . '/.htaccess',
				"<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nOrder deny,allow\nDeny from all\n</IfModule>\n"
			);
		}
		// phpcs:enable

		return wp_is_writable( $dir ) ? $dir : '';
	}

	/**
	 * Unguessable cache path for a file ID.
	 *
	 * @param string $dir Cache directory.
	 * @param string $id  File ID.
	 * @return string
	 */
	private static function cache_path( $dir, $id ) {
		return $dir . '/' . hash( 'sha256', $id . '|' . wp_salt( 'auth' ) ) . '.pdf';
	}

	/**
	 * Cache lifetime in seconds.
	 *
	 * @return int
	 */
	public static function ttl() {
		if ( VWL_Ebook_Settings::get( 'cache' ) ) {
			return absint( VWL_Ebook_Settings::get( 'cache_hours' ) ) * HOUR_IN_SECONDS;
		}
		// Caching "off" still needs a short-lived local copy so Range requests work.
		return 15 * MINUTE_IN_SECONDS;
	}

	/**
	 * Whether a cached copy is fresh.
	 *
	 * @param string $path Path.
	 * @return bool
	 */
	private static function is_fresh( $path ) {
		clearstatcache( true, $path );
		return file_exists( $path ) && filesize( $path ) > 0 && ( filemtime( $path ) + self::ttl() ) > time();
	}

	/**
	 * Make sure a local copy of a public Drive PDF exists.
	 *
	 * @param string $id    File ID.
	 * @param bool   $force Ignore cache and recent-error memory.
	 * @return array|WP_Error array( 'path' => string, 'size' => int )
	 */
	public static function ensure( $id, $force = false ) {
		if ( ! VWL_Ebook_Drive::is_valid_id( $id ) ) {
			return new WP_Error( 'invalid_url', self::message( 'invalid_url' ) );
		}

		$dir = self::cache_dir();
		if ( '' === $dir ) {
			return new WP_Error( 'storage', self::message( 'storage' ) );
		}

		$path      = self::cache_path( $dir, $id );
		$error_key = 'vwl_ebook_err_' . md5( $id );

		if ( ! $force && self::is_fresh( $path ) ) {
			return array(
				'path' => $path,
				'size' => (int) filesize( $path ),
			);
		}

		if ( $force ) {
			delete_transient( $error_key );
		} else {
			$recent = get_transient( $error_key );
			if ( $recent ) {
				return new WP_Error( (string) $recent, self::message( (string) $recent ) );
			}
		}

		// phpcs:disable WordPress.WP.AlternativeFunctions -- local lock file.
		$lock_path = $path . '.lock';
		$lock      = @fopen( $lock_path, 'c' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( $lock ) {
			flock( $lock, LOCK_EX );
		}

		// Another request may have finished the download while we waited.
		if ( ! $force && self::is_fresh( $path ) ) {
			$result = true;
		} else {
			$result = self::download( $id, $path );
		}

		if ( $lock ) {
			flock( $lock, LOCK_UN );
			fclose( $lock );
			@unlink( $lock_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		// phpcs:enable

		if ( is_wp_error( $result ) ) {
			$code = $result->get_error_code();
			// Remember failures briefly so a broken link does not hammer Google.
			$ttl = in_array( $code, array( 'network', 'unavailable', 'storage' ), true ) ? MINUTE_IN_SECONDS : 2 * MINUTE_IN_SECONDS;
			set_transient( $error_key, $code, $ttl );
			return $result;
		}

		clearstatcache( true, $path );
		return array(
			'path' => $path,
			'size' => (int) filesize( $path ),
		);
	}

	/**
	 * Download a public Drive file anonymously.
	 *
	 * @param string $id   File ID.
	 * @param string $dest Destination path.
	 * @return true|WP_Error
	 */
	private static function download( $id, $dest ) {
		if ( function_exists( 'set_time_limit' ) && false === strpos( (string) ini_get( 'disable_functions' ), 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		ignore_user_abort( true );

		$max_bytes = absint( VWL_Ebook_Settings::get( 'max_file_mb' ) ) * MB_IN_BYTES;
		$url       = 'https://drive.google.com/uc?export=download&id=' . rawurlencode( $id );

		for ( $attempt = 0; $attempt < 2; $attempt++ ) {
			$tmp = $dest . '.' . wp_generate_password( 8, false, false ) . '.part';

			$response = wp_safe_remote_get(
				$url,
				array(
					'timeout'             => 90,
					'redirection'         => 6,
					'stream'              => true,
					'filename'            => $tmp,
					'limit_response_size' => $max_bytes + 1,
					'user-agent'          => 'Mozilla/5.0 (compatible; VWL-Ebook-Reader/' . VWL_EBOOK_VERSION . ')',
					'headers'             => array( 'Accept' => 'application/pdf,*/*;q=0.8' ),
				)
			);

			// phpcs:disable WordPress.WP.AlternativeFunctions -- reading our own temp file.
			if ( is_wp_error( $response ) ) {
				if ( file_exists( $tmp ) ) {
					wp_delete_file( $tmp );
				}
				return new WP_Error( 'network', self::message( 'network' ), $response->get_error_message() );
			}

			$code = (int) wp_remote_retrieve_response_code( $response );
			clearstatcache( true, $tmp );
			$size = file_exists( $tmp ) ? (int) filesize( $tmp ) : 0;
			$head = $size ? (string) file_get_contents( $tmp, false, null, 0, 1024 ) : '';

			if ( 200 === $code && false !== strpos( $head, '%PDF-' ) ) {
				if ( $size > $max_bytes ) {
					wp_delete_file( $tmp );
					return new WP_Error( 'too_large', self::message( 'too_large' ) );
				}
				if ( file_exists( $dest ) ) {
					wp_delete_file( $dest );
				}
				if ( ! @rename( $tmp, $dest ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
					if ( ! @copy( $tmp, $dest ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
						wp_delete_file( $tmp );
						return new WP_Error( 'storage', self::message( 'storage' ) );
					}
					wp_delete_file( $tmp );
				}
				@touch( $dest ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				return true;
			}

			$body = $size ? (string) file_get_contents( $tmp, false, null, 0, 400000 ) : '';
			if ( file_exists( $tmp ) ) {
				wp_delete_file( $tmp );
			}
			// phpcs:enable

			// Large public files show Google's "can't scan for viruses" page first.
			// Following its public confirm form is what any browser does; it is not a permission bypass.
			if ( 0 === $attempt && 200 === $code ) {
				$next = self::confirm_url( $body );
				if ( '' !== $next ) {
					$url = $next;
					continue;
				}
			}

			$reason = self::classify( $code, $body );
			return new WP_Error( $reason, self::message( $reason ) );
		}

		return new WP_Error( 'not_pdf', self::message( 'not_pdf' ) );
	}

	/**
	 * Find the confirm URL on Google's large-file warning page.
	 *
	 * @param string $html HTML body.
	 * @return string URL or ''.
	 */
	private static function confirm_url( $html ) {
		if ( '' === $html ) {
			return '';
		}

		if ( preg_match( '#<form[^>]*action="([^"]+)"[^>]*>(.*?)</form>#is', $html, $form ) ) {
			$action = html_entity_decode( $form[1], ENT_QUOTES, 'UTF-8' );
			$host   = wp_parse_url( $action, PHP_URL_HOST );
			if ( in_array( $host, array( 'drive.usercontent.google.com', 'drive.google.com' ), true ) ) {
				$params = array();
				if ( preg_match_all( '#<input[^>]*>#i', $form[2], $inputs ) ) {
					foreach ( $inputs[0] as $input ) {
						if ( preg_match( '#name="([^"]+)"#i', $input, $n ) ) {
							$value                                         = preg_match( '#value="([^"]*)"#i', $input, $v ) ? $v[1] : '';
							$params[ html_entity_decode( $n[1], ENT_QUOTES, 'UTF-8' ) ] = html_entity_decode( $value, ENT_QUOTES, 'UTF-8' );
						}
					}
				}
				if ( ! empty( $params['id'] ) ) {
					return add_query_arg( array_map( 'rawurlencode', $params ), $action );
				}
			}
		}

		if ( preg_match( '#href="(/uc\?export=download[^"]*confirm=[^"]+)"#i', $html, $m ) ) {
			return 'https://drive.google.com' . html_entity_decode( $m[1], ENT_QUOTES, 'UTF-8' );
		}

		return '';
	}

	/**
	 * Turn a failed response into an error code.
	 *
	 * @param int    $code HTTP status.
	 * @param string $body Response body (HTML).
	 * @return string
	 */
	private static function classify( $code, $body ) {
		$lower = strtolower( $body );

		if ( 429 === $code || false !== strpos( $lower, 'quota exceeded' ) || false !== strpos( $lower, 'too many users have viewed or downloaded' ) ) {
			return 'quota';
		}
		if ( false !== strpos( $lower, 'unusual traffic' ) || false !== strpos( $lower, 'google.com/sorry' ) ) {
			return 'blocked';
		}
		if ( 401 === $code || 403 === $code || false !== strpos( $lower, 'servicelogin' ) || false !== strpos( $lower, 'accounts.google.com/v3/signin' ) || false !== strpos( $lower, 'you need access' ) ) {
			return 'private';
		}
		if ( 404 === $code ) {
			return 'not_found';
		}
		if ( $code >= 500 ) {
			return 'unavailable';
		}
		return 'not_pdf';
	}

	/**
	 * Read and validate the id/token pair from the request.
	 *
	 * @return string File ID or '' if invalid.
	 */
	private static function request_id() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- authenticated by HMAC token instead (cache-safe for anonymous visitors).
		$id    = isset( $_GET['id'] ) ? sanitize_text_field( wp_unslash( $_GET['id'] ) ) : '';
		$token = isset( $_GET['t'] ) ? sanitize_text_field( wp_unslash( $_GET['t'] ) ) : '';
		// phpcs:enable
		return VWL_Ebook_Drive::verify( $id, $token ) ? $id : '';
	}

	/**
	 * AJAX: prepare the file and report status as JSON.
	 */
	public static function handle_prepare() {
		$id = self::request_id();
		if ( '' === $id ) {
			wp_send_json_error( array( 'code' => 'invalid_request' ), 403 );
		}

		$result = self::ensure( $id );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'code' => $result->get_error_code() ) );
		}

		wp_send_json_success( array( 'size' => $result['size'] ) );
	}

	/**
	 * AJAX: stream the PDF.
	 */
	public static function handle_file() {
		$id = self::request_id();
		if ( '' === $id ) {
			status_header( 403 );
			nocache_headers();
			header( 'Content-Type: text/plain; charset=utf-8' );
			echo esc_html__( 'Invalid request.', 'vwl-ebook-reader' );
			exit;
		}

		$result = self::ensure( $id );
		if ( is_wp_error( $result ) ) {
			status_header( 'private' === $result->get_error_code() ? 403 : 502 );
			nocache_headers();
			header( 'Content-Type: text/plain; charset=utf-8' );
			echo esc_html( $result->get_error_message() );
			exit;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- download permission is an HMAC token.
		$dl_token = isset( $_GET['dl'] ) ? sanitize_text_field( wp_unslash( $_GET['dl'] ) ) : '';
		$name     = isset( $_GET['name'] ) ? sanitize_file_name( wp_unslash( $_GET['name'] ) ) : '';
		// phpcs:enable
		$download = '' !== $dl_token && VWL_Ebook_Drive::verify( $id, $dl_token, 'download' );
		$filename = ( '' !== $name ? $name : 'ebook' ) . '.pdf';

		self::stream( $result['path'], $filename, $download );
	}

	/**
	 * Stream a file with single-range support.
	 *
	 * @param string $path       File path.
	 * @param string $filename   Download file name.
	 * @param bool   $attachment Send as attachment.
	 */
	private static function stream( $path, $filename, $attachment ) {
		$size  = (int) filesize( $path );
		$mtime = (int) filemtime( $path );
		$etag  = '"' . substr( md5( $path . '|' . $size . '|' . $mtime ), 0, 24 ) . '"';

		if ( function_exists( 'apache_setenv' ) ) {
			@apache_setenv( 'no-gzip', '1' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		@ini_set( 'zlib.output_compression', 'Off' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.IniSet.Risky
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}
		if ( headers_sent() ) {
			exit;
		}

		header_remove( 'Pragma' );
		header_remove( 'Expires' );
		header( 'Content-Type: application/pdf' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'X-Robots-Tag: noindex, nofollow' );
		header( 'Accept-Ranges: bytes' );
		header( 'Cache-Control: private, max-age=3600' );
		header( 'ETag: ' . $etag );
		header( 'Last-Modified: ' . gmdate( 'D, d M Y H:i:s', $mtime ) . ' GMT' );
		header( 'Content-Disposition: ' . ( $attachment ? 'attachment' : 'inline' ) . '; filename="' . str_replace( '"', '', $filename ) . '"' );

		$range = isset( $_SERVER['HTTP_RANGE'] ) ? trim( sanitize_text_field( wp_unslash( $_SERVER['HTTP_RANGE'] ) ) ) : '';
		$inm   = isset( $_SERVER['HTTP_IF_NONE_MATCH'] ) ? trim( sanitize_text_field( wp_unslash( $_SERVER['HTTP_IF_NONE_MATCH'] ) ) ) : '';

		if ( '' === $range && '' !== $inm && $inm === $etag ) {
			status_header( 304 );
			exit;
		}

		$start = 0;
		$end   = $size - 1;

		if ( '' !== $range && preg_match( '/^bytes=(\d*)-(\d*)$/', $range, $m ) && ( '' !== $m[1] || '' !== $m[2] ) ) {
			if ( '' === $m[1] ) {
				$start = max( 0, $size - (int) $m[2] );
			} else {
				$start = (int) $m[1];
				if ( '' !== $m[2] ) {
					$end = min( (int) $m[2], $size - 1 );
				}
			}
			if ( $start > $end || $start >= $size ) {
				status_header( 416 );
				header( 'Content-Range: bytes */' . $size );
				exit;
			}
			status_header( 206 );
			header( 'Content-Range: bytes ' . $start . '-' . $end . '/' . $size );
		} else {
			status_header( 200 );
		}

		$length = $end - $start + 1;
		header( 'Content-Length: ' . $length );

		if ( isset( $_SERVER['REQUEST_METHOD'] ) && 'HEAD' === $_SERVER['REQUEST_METHOD'] ) {
			exit;
		}

		// phpcs:disable WordPress.WP.AlternativeFunctions -- binary streaming.
		$handle = fopen( $path, 'rb' );
		if ( ! $handle ) {
			exit;
		}
		fseek( $handle, $start );
		$remaining = $length;
		while ( $remaining > 0 && ! feof( $handle ) && ! connection_aborted() ) {
			$chunk = fread( $handle, (int) min( 262144, $remaining ) );
			if ( false === $chunk || '' === $chunk ) {
				break;
			}
			echo $chunk; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- binary PDF data.
			flush();
			$remaining -= strlen( $chunk );
		}
		fclose( $handle );
		// phpcs:enable
		exit;
	}

	/**
	 * Cron: remove expired cache files and stale partial downloads.
	 */
	public static function cleanup() {
		$dir = self::cache_dir();
		if ( '' === $dir ) {
			return;
		}
		$ttl   = self::ttl();
		$files = glob( $dir . '/*' );
		if ( ! $files ) {
			return;
		}
		foreach ( $files as $file ) {
			$base = basename( $file );
			if ( 'index.php' === $base || '.htaccess' === $base || ! is_file( $file ) ) {
				continue;
			}
			$age     = time() - (int) filemtime( $file );
			$is_part = ( '.part' === substr( $base, -5 ) || '.lock' === substr( $base, -5 ) );
			if ( ( $is_part && $age > HOUR_IN_SECONDS ) || ( ! $is_part && $age > $ttl ) ) {
				wp_delete_file( $file );
			}
		}
	}

	/**
	 * Delete every cached file.
	 *
	 * @param bool $remove_dir Also remove the folder.
	 * @return int Number of files removed.
	 */
	public static function clear_all( $remove_dir = false ) {
		$uploads = wp_upload_dir( null, false );
		if ( empty( $uploads['basedir'] ) ) {
			return 0;
		}
		$dir = trailingslashit( $uploads['basedir'] ) . self::CACHE_FOLDER;
		if ( ! is_dir( $dir ) ) {
			return 0;
		}
		$count = 0;
		$names = scandir( $dir );
		$names = $names ? $names : array();
		foreach ( $names as $base ) {
			$file = $dir . '/' . $base;
			if ( '.' === $base || '..' === $base || ! is_file( $file ) ) {
				continue;
			}
			if ( ! $remove_dir && ( 'index.php' === $base || '.htaccess' === $base ) ) {
				continue;
			}
			wp_delete_file( $file );
			if ( '.pdf' === substr( $base, -4 ) ) {
				++$count;
			}
		}
		if ( $remove_dir ) {
			@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions
		}
		return $count;
	}

	/**
	 * Cache statistics for the admin page.
	 *
	 * @return array
	 */
	public static function stats() {
		$uploads = wp_upload_dir( null, false );
		$dir     = empty( $uploads['basedir'] ) ? '' : trailingslashit( $uploads['basedir'] ) . self::CACHE_FOLDER;
		$files   = ( $dir && is_dir( $dir ) ) ? glob( $dir . '/*.pdf' ) : array();
		$files   = $files ? $files : array();
		$bytes   = 0;
		foreach ( $files as $file ) {
			$bytes += (int) filesize( $file );
		}
		return array(
			'count' => count( $files ),
			'bytes' => $bytes,
		);
	}
}
