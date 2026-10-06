<?php
/**
 * Plugin Name:       VWL Flip Book
 * Plugin URI:        https://visionweblabs.com/
 * Description:       Display Google Drive PDFs as a responsive, interactive e-book reader with page-turn animation, search, table of contents, zoom, dark mode, fullscreen and reading-position memory. Use the [pdf_ebook] shortcode, the Gutenberg block, or the E-Books library.
 * Version:           1.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Vision Web Labs
 * Author URI:        https://visionweblabs.com/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       vwl-flip-book
 * Domain Path:       /languages
 *
 * @package VWL_Ebook_Reader
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'VWL_EBOOK_VERSION', '1.0.0' );
define( 'VWL_EBOOK_PDFJS_VERSION', '3.11.174' );
define( 'VWL_EBOOK_FILE', __FILE__ );
define( 'VWL_EBOOK_DIR', plugin_dir_path( __FILE__ ) );
define( 'VWL_EBOOK_URL', plugin_dir_url( __FILE__ ) );

require_once VWL_EBOOK_DIR . 'includes/class-vwl-ebook-settings.php';
require_once VWL_EBOOK_DIR . 'includes/class-vwl-ebook-icons.php';
require_once VWL_EBOOK_DIR . 'includes/class-vwl-ebook-drive.php';
require_once VWL_EBOOK_DIR . 'includes/class-vwl-ebook-proxy.php';
require_once VWL_EBOOK_DIR . 'includes/class-vwl-ebook-renderer.php';
require_once VWL_EBOOK_DIR . 'includes/class-vwl-ebook-shortcode.php';
require_once VWL_EBOOK_DIR . 'includes/class-vwl-ebook-post-type.php';
require_once VWL_EBOOK_DIR . 'includes/class-vwl-ebook-block.php';
require_once VWL_EBOOK_DIR . 'includes/class-vwl-ebook-analytics.php';
require_once VWL_EBOOK_DIR . 'includes/class-vwl-ebook-admin.php';
require_once VWL_EBOOK_DIR . 'includes/class-vwl-ebook-plugin.php';

register_activation_hook( __FILE__, array( 'VWL_Ebook_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'VWL_Ebook_Plugin', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'VWL_Ebook_Plugin', 'instance' ) );
