<?php
/**
 * Uninstall: always removes cached PDFs and transient data. Settings and E-Book
 * library entries are removed only when "Delete all plugin data on uninstall" is on.
 *
 * @package VWL_Ebook_Reader
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Cached PDF copies.
$vwl_uploads = wp_upload_dir( null, false );
if ( ! empty( $vwl_uploads['basedir'] ) ) {
	$vwl_dir = trailingslashit( $vwl_uploads['basedir'] ) . 'vwl-ebook-cache';
	if ( is_dir( $vwl_dir ) ) {
		$vwl_names = scandir( $vwl_dir );
		foreach ( (array) $vwl_names as $vwl_name ) {
			if ( '.' === $vwl_name || '..' === $vwl_name ) {
				continue;
			}
			$vwl_file = $vwl_dir . '/' . $vwl_name;
			if ( is_file( $vwl_file ) ) {
				wp_delete_file( $vwl_file );
			}
		}
		@rmdir( $vwl_dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions
	}
}

global $wpdb;
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", $wpdb->esc_like( '_transient_vwl_ebook_' ) . '%', $wpdb->esc_like( '_transient_timeout_vwl_ebook_' ) . '%' ) );

wp_clear_scheduled_hook( 'vwl_ebook_cleanup' );
delete_option( 'vwl_ebook_show_welcome' );

$vwl_settings = get_option( 'vwl_ebook_settings', array() );
if ( is_array( $vwl_settings ) && ! empty( $vwl_settings['delete_data'] ) ) {
	$vwl_posts = get_posts(
		array(
			'post_type'      => 'vwl_ebook',
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
		)
	);
	foreach ( $vwl_posts as $vwl_post_id ) {
		wp_delete_post( $vwl_post_id, true );
	}
	if ( ! taxonomy_exists( 'vwl_ebook_category' ) ) {
		register_taxonomy( 'vwl_ebook_category', 'vwl_ebook' );
	}
	$vwl_terms = get_terms(
		array(
			'taxonomy'   => 'vwl_ebook_category',
			'hide_empty' => false,
			'fields'     => 'ids',
		)
	);
	if ( ! is_wp_error( $vwl_terms ) ) {
		foreach ( $vwl_terms as $vwl_term_id ) {
			wp_delete_term( $vwl_term_id, 'vwl_ebook_category' );
		}
	}
	delete_option( 'vwl_ebook_settings' );
}
