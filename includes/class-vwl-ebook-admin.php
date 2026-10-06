<?php
/**
 * Admin: Settings → VWL Flip Book, notices and tools.
 *
 * @package VWL_Ebook_Reader
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin.
 */
class VWL_Ebook_Admin {

	const PAGE = 'vwl-flip-book';

	/**
	 * Settings page hook suffix.
	 *
	 * @var string
	 */
	private static $hook = '';

	/**
	 * Hook.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_setting' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
		add_action( 'admin_post_vwl_ebook_clear_cache', array( __CLASS__, 'clear_cache' ) );
		add_action( 'admin_post_vwl_ebook_dismiss_welcome', array( __CLASS__, 'dismiss_welcome' ) );
		add_action( 'wp_ajax_vwl_ebook_test_link', array( __CLASS__, 'test_link' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( VWL_EBOOK_FILE ), array( __CLASS__, 'action_links' ) );
	}

	/**
	 * Settings menu.
	 */
	public static function menu() {
		self::$hook = add_options_page(
			__( 'VWL Flip Book', 'vwl-flip-book' ),
			__( 'VWL Flip Book', 'vwl-flip-book' ),
			'manage_options',
			self::PAGE,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Register option.
	 */
	public static function register_setting() {
		register_setting(
			'vwl_ebook',
			VWL_Ebook_Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( 'VWL_Ebook_Settings', 'sanitize' ),
				'default'           => VWL_Ebook_Settings::defaults(),
				'show_in_rest'      => false,
			)
		);
	}

	/**
	 * Admin assets.
	 *
	 * @param string $hook Hook suffix.
	 */
	public static function assets( $hook ) {
		$screen  = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$is_book = $screen && VWL_Ebook_Post_Type::POST_TYPE === $screen->post_type;
		if ( $hook !== self::$hook && ! $is_book ) {
			return;
		}
		wp_enqueue_style( 'wp-color-picker' );
		wp_enqueue_style( 'vwl-ebook-admin', VWL_EBOOK_URL . 'assets/css/admin.css', array(), VWL_EBOOK_VERSION );
		wp_enqueue_script( 'vwl-ebook-admin', VWL_EBOOK_URL . 'assets/js/admin.js', array( 'jquery', 'wp-color-picker' ), VWL_EBOOK_VERSION, true );
		wp_localize_script(
			'vwl-ebook-admin',
			'vwlEbookAdmin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'vwl_ebook_test' ),
				'i18n'    => array(
					'testing'  => __( 'Checking the link with Google Drive…', 'vwl-flip-book' ),
					'copied'   => __( 'Copied', 'vwl-flip-book' ),
					'idFound'  => __( 'File ID detected: %s', 'vwl-flip-book' ),
					'idNone'   => __( 'No Google Drive file ID found in this link.', 'vwl-flip-book' ),
					'idEmpty'  => __( 'Paste a Google Drive share link or the file ID.', 'vwl-flip-book' ),
					'failed'   => __( 'The check could not be completed. Please try again.', 'vwl-flip-book' ),
				),
			)
		);
	}

	/**
	 * Settings link on the Plugins screen.
	 *
	 * @param array $links Links.
	 * @return array
	 */
	public static function action_links( $links ) {
		array_unshift(
			$links,
			'<a href="' . esc_url( admin_url( 'options-general.php?page=' . self::PAGE ) ) . '">' . esc_html__( 'Settings', 'vwl-flip-book' ) . '</a>'
		);
		return $links;
	}

	/**
	 * Admin notices: welcome + cache cleared.
	 */
	public static function notices() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only flag.
		if ( isset( $_GET['vwl_ebook_cleared'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$count = absint( $_GET['vwl_ebook_cleared'] );
			/* translators: %d: number of files */
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( sprintf( _n( 'Cleared %d cached book.', 'Cleared %d cached books.', $count, 'vwl-flip-book' ), $count ) ) . '</p></div>';
		}

		if ( ! get_option( 'vwl_ebook_show_welcome' ) ) {
			return;
		}
		$screen = get_current_screen();
		if ( ! $screen || ! in_array( $screen->id, array( 'plugins', 'settings_page_' . self::PAGE, 'dashboard' ), true ) ) {
			return;
		}
		$dismiss = wp_nonce_url( admin_url( 'admin-post.php?action=vwl_ebook_dismiss_welcome' ), 'vwl_ebook_dismiss_welcome' );
		?>
		<div class="notice notice-info vwl-ebook-welcome">
			<p><strong><?php esc_html_e( 'VWL Flip Book is ready.', 'vwl-flip-book' ); ?></strong>
			<?php esc_html_e( 'Before embedding a book, open the PDF in Google Drive, choose Share → General access → "Anyone with the link" (Viewer). Private files cannot be displayed.', 'vwl-flip-book' ); ?></p>
			<p>
				<a class="button button-primary" href="<?php echo esc_url( admin_url( 'options-general.php?page=' . self::PAGE . '#vwl-tab-help' ) ); ?>"><?php esc_html_e( 'Setup guide', 'vwl-flip-book' ); ?></a>
				<a class="button" href="<?php echo esc_url( $dismiss ); ?>"><?php esc_html_e( 'Dismiss', 'vwl-flip-book' ); ?></a>
			</p>
		</div>
		<?php
	}

	/**
	 * Dismiss the welcome notice.
	 */
	public static function dismiss_welcome() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'vwl-flip-book' ), 403 );
		}
		check_admin_referer( 'vwl_ebook_dismiss_welcome' );
		delete_option( 'vwl_ebook_show_welcome' );
		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url() );
		exit;
	}

	/**
	 * Clear the PDF cache.
	 */
	public static function clear_cache() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'vwl-flip-book' ), 403 );
		}
		check_admin_referer( 'vwl_ebook_clear_cache' );
		$count = VWL_Ebook_Proxy::clear_all();
		global $wpdb;
		// Forget remembered errors so links are re-checked immediately.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", $wpdb->esc_like( '_transient_vwl_ebook_err_' ) . '%', $wpdb->esc_like( '_transient_timeout_vwl_ebook_err_' ) . '%' ) );
		wp_safe_redirect( add_query_arg( 'vwl_ebook_cleared', $count, admin_url( 'options-general.php?page=' . self::PAGE ) ) . '#vwl-tab-tools' );
		exit;
	}

	/**
	 * AJAX: test a Google Drive link from the server.
	 */
	public static function test_link() {
		check_ajax_referer( 'vwl_ebook_test', 'nonce' );
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'vwl-flip-book' ) ), 403 );
		}

		$input = isset( $_POST['url'] ) ? sanitize_text_field( wp_unslash( $_POST['url'] ) ) : '';
		$id    = VWL_Ebook_Drive::extract_id( $input );
		if ( '' === $id ) {
			wp_send_json_error(
				array(
					'code'    => 'invalid_url',
					'message' => VWL_Ebook_Proxy::message( 'invalid_url' ),
				)
			);
		}

		$result = VWL_Ebook_Proxy::ensure( $id, true );
		if ( is_wp_error( $result ) ) {
			$detail = $result->get_error_data();
			wp_send_json_error(
				array(
					'code'    => $result->get_error_code(),
					'message' => $result->get_error_message(),
					'detail'  => is_string( $detail ) ? $detail : '',
				)
			);
		}

		wp_send_json_success(
			array(
				'id'        => $id,
				/* translators: %s: file size */
				'message'   => sprintf( __( 'This PDF is public and ready (%s). It will open in the reader.', 'vwl-flip-book' ), size_format( $result['size'] ) ),
				'shortcode' => '[pdf_ebook url="' . VWL_Ebook_Drive::view_url( $id ) . '"]',
			)
		);
	}

	/**
	 * Field definitions.
	 *
	 * @return array
	 */
	private static function fields() {
		return array(
			'general'     => array(
				'label'  => __( 'General', 'vwl-flip-book' ),
				'fields' => array(
					'height'          => array( 'number', __( 'Default reader height (px)', 'vwl-flip-book' ), __( 'Phones automatically use a shorter height that fits the screen.', 'vwl-flip-book' ), 320, 2000, 10 ),
					'theme'           => array(
						'select',
						__( 'Default theme', 'vwl-flip-book' ),
						__( 'Visitors can switch with the dark mode button; their choice is remembered in their browser.', 'vwl-flip-book' ),
						array(
							'light' => __( 'Light', 'vwl-flip-book' ),
							'dark'  => __( 'Dark', 'vwl-flip-book' ),
							'auto'  => __( 'Match the visitor’s device', 'vwl-flip-book' ),
						),
					),
					'page_mode'       => array(
						'select',
						__( 'Default page mode', 'vwl-flip-book' ),
						__( 'Phones always use single pages.', 'vwl-flip-book' ),
						array(
							'auto'   => __( 'Two-page spread on wide screens, single page on phones', 'vwl-flip-book' ),
							'single' => __( 'Always single page', 'vwl-flip-book' ),
						),
					),
					'animations'      => array( 'checkbox', __( 'Enable page-turn animations', 'vwl-flip-book' ), __( 'Visitors who prefer reduced motion never see animations.', 'vwl-flip-book' ) ),
					'remember'        => array( 'checkbox', __( 'Remember reading position', 'vwl-flip-book' ), __( 'Stored only in the visitor’s browser (localStorage). No login, no tracking.', 'vwl-flip-book' ) ),
					'cover'           => array( 'checkbox', __( 'Show a book cover screen before reading', 'vwl-flip-book' ), __( 'Uses the featured image for library books, otherwise the first page.', 'vwl-flip-book' ) ),
					'fallback_iframe' => array( 'checkbox', __( 'Offer the Google Drive viewer if the reader cannot load a book', 'vwl-flip-book' ), __( 'Useful when your host blocks outgoing connections to Google.', 'vwl-flip-book' ) ),
				),
			),
			'toolbar'     => array(
				'label'  => __( 'Toolbar', 'vwl-flip-book' ),
				'fields' => array(
					'btn_nav'        => array( 'checkbox', __( 'Previous / Next buttons', 'vwl-flip-book' ), __( 'Keyboard and swipe navigation always work.', 'vwl-flip-book' ) ),
					'btn_zoom'       => array( 'checkbox', __( 'Zoom controls', 'vwl-flip-book' ), '' ),
					'btn_search'     => array( 'checkbox', __( 'Search', 'vwl-flip-book' ), '' ),
					'btn_fullscreen' => array( 'checkbox', __( 'Fullscreen', 'vwl-flip-book' ), '' ),
					'btn_theme'      => array( 'checkbox', __( 'Dark mode toggle', 'vwl-flip-book' ), '' ),
					'btn_toc'        => array( 'checkbox', __( 'Table of contents', 'vwl-flip-book' ), __( 'Shown only when the PDF has bookmarks.', 'vwl-flip-book' ) ),
					'btn_download'   => array( 'checkbox', __( 'Download button', 'vwl-flip-book' ), __( 'Turning this off hides the button. A browser-based reader cannot make a PDF impossible to save.', 'vwl-flip-book' ) ),
					'btn_print'      => array( 'checkbox', __( 'Print button', 'vwl-flip-book' ), __( 'Turning this off hides the button only. It is not copy protection.', 'vwl-flip-book' ) ),
				),
			),
			'performance' => array(
				'label'  => __( 'Performance', 'vwl-flip-book' ),
				'fields' => array(
					'lazy_load'   => array( 'checkbox', __( 'Lazy-load books', 'vwl-flip-book' ), __( 'Start loading a book only when it scrolls into view.', 'vwl-flip-book' ) ),
					'preload'     => array( 'checkbox', __( 'Preload nearby pages', 'vwl-flip-book' ), __( 'Renders the next and previous pages in the background for instant page turns.', 'vwl-flip-book' ) ),
					'max_scale'   => array( 'number', __( 'Maximum rendering scale', 'vwl-flip-book' ), __( 'Caps sharpness on high-density screens. 2 is crisp on phones; lower values save memory on very large pages.', 'vwl-flip-book' ), 1, 4, 0.25 ),
					'cache'       => array( 'checkbox', __( 'Cache books on this server', 'vwl-flip-book' ), __( 'Recommended. Public PDFs are kept in wp-content/uploads/vwl-ebook-cache so Google Drive is not contacted on every visit.', 'vwl-flip-book' ) ),
					'cache_hours' => array( 'number', __( 'Cache duration (hours)', 'vwl-flip-book' ), __( 'After you replace a file in Google Drive, the new version appears when the cache expires or when you clear it on the Tools tab.', 'vwl-flip-book' ), 1, 720, 1 ),
					'max_file_mb' => array( 'number', __( 'Maximum PDF size (MB)', 'vwl-flip-book' ), '', 5, 1024, 1 ),
				),
			),
			'appearance'  => array(
				'label'  => __( 'Appearance', 'vwl-flip-book' ),
				'fields' => array(
					'primary_color'    => array( 'color', __( 'Interface color', 'vwl-flip-book' ), __( 'Used for buttons, progress and highlights.', 'vwl-flip-book' ) ),
					'background_color' => array( 'color', __( 'Reader background (light mode)', 'vwl-flip-book' ), '' ),
					'page_shadow'      => array(
						'select',
						__( 'Page shadow', 'vwl-flip-book' ),
						'',
						array(
							'none'   => __( 'None', 'vwl-flip-book' ),
							'soft'   => __( 'Soft', 'vwl-flip-book' ),
							'strong' => __( 'Strong', 'vwl-flip-book' ),
						),
					),
					'toolbar_position' => array(
						'select',
						__( 'Toolbar position', 'vwl-flip-book' ),
						'',
						array(
							'top'    => __( 'Top', 'vwl-flip-book' ),
							'bottom' => __( 'Bottom', 'vwl-flip-book' ),
						),
					),
					'animation_speed'  => array( 'number', __( 'Page-turn speed (ms)', 'vwl-flip-book' ), '', 200, 1500, 50 ),
				),
			),
			'completion'  => array(
				'label'  => __( 'Completion', 'vwl-flip-book' ),
				'fields' => array(
					'completion'              => array( 'checkbox', __( 'Show a message on the last page', 'vwl-flip-book' ), '' ),
					'completion_message'      => array( 'textarea', __( 'Custom message', 'vwl-flip-book' ), __( 'Leave empty for: "You\'ve reached the end of this book."', 'vwl-flip-book' ) ),
					'completion_button_label' => array( 'text', __( 'Extra button label', 'vwl-flip-book' ), __( 'For example: Get the full course', 'vwl-flip-book' ) ),
					'completion_button_url'   => array( 'url', __( 'Extra button link', 'vwl-flip-book' ), '' ),
					'analytics'               => array( 'checkbox', __( 'Send reader events to WordPress hooks', 'vwl-flip-book' ), __( 'Fires vwl_ebook_opened, vwl_ebook_page_viewed, vwl_ebook_completed and vwl_ebook_search for your own analytics code. Off by default; no personal data is collected.', 'vwl-flip-book' ) ),
					'delete_data'             => array( 'checkbox', __( 'Delete all plugin data on uninstall', 'vwl-flip-book' ), __( 'Includes settings, cached files and every E-Book library entry.', 'vwl-flip-book' ) ),
				),
			),
		);
	}

	/**
	 * Render a field.
	 *
	 * @param string $key   Key.
	 * @param array  $def   Definition.
	 * @param array  $value Current settings.
	 */
	private static function field( $key, $def, $value ) {
		$name = VWL_Ebook_Settings::OPTION . '[' . $key . ']';
		$id   = 'vwl-ebook-' . str_replace( '_', '-', $key );
		$val  = isset( $value[ $key ] ) ? $value[ $key ] : '';
		$type = $def[0];
		$desc = isset( $def[2] ) ? $def[2] : '';

		echo '<tr><th scope="row">';
		if ( 'checkbox' !== $type ) {
			echo '<label for="' . esc_attr( $id ) . '">' . esc_html( $def[1] ) . '</label>';
		} else {
			echo esc_html( $def[1] );
		}
		echo '</th><td>';

		switch ( $type ) {
			case 'checkbox':
				echo '<label for="' . esc_attr( $id ) . '"><input type="checkbox" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="1" ' . checked( 1, (int) $val, false ) . ' /> ' . esc_html__( 'Enabled', 'vwl-flip-book' ) . '</label>';
				break;
			case 'number':
				echo '<input type="number" class="small-text" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $val ) . '" min="' . esc_attr( $def[3] ) . '" max="' . esc_attr( $def[4] ) . '" step="' . esc_attr( $def[5] ) . '" />';
				break;
			case 'select':
				echo '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '">';
				foreach ( $def[3] as $opt => $label ) {
					echo '<option value="' . esc_attr( $opt ) . '" ' . selected( $val, $opt, false ) . '>' . esc_html( $label ) . '</option>';
				}
				echo '</select>';
				break;
			case 'color':
				echo '<input type="text" class="vwl-ebook-color" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $val ) . '" data-default-color="' . esc_attr( VWL_Ebook_Settings::defaults()[ $key ] ) . '" />';
				break;
			case 'textarea':
				echo '<textarea class="large-text" rows="3" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '">' . esc_textarea( $val ) . '</textarea>';
				break;
			case 'url':
				echo '<input type="url" class="regular-text" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $val ) . '" placeholder="https://" />';
				break;
			default:
				echo '<input type="text" class="regular-text" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $val ) . '" />';
		}

		if ( '' !== $desc ) {
			echo '<p class="description">' . esc_html( $desc ) . '</p>';
		}
		echo '</td></tr>';
	}

	/**
	 * Render settings page.
	 */
	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$settings = VWL_Ebook_Settings::all();
		$sections = self::fields();
		$stats    = VWL_Ebook_Proxy::stats();
		?>
		<div class="wrap vwl-ebook-admin">
			<h1><?php esc_html_e( 'VWL Flip Book', 'vwl-flip-book' ); ?></h1>

			<nav class="nav-tab-wrapper vwl-ebook-tabs" aria-label="<?php esc_attr_e( 'Settings sections', 'vwl-flip-book' ); ?>">
				<?php foreach ( $sections as $slug => $section ) : ?>
				<a href="#vwl-tab-<?php echo esc_attr( $slug ); ?>" class="nav-tab" data-vwl-tab="<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $section['label'] ); ?></a>
				<?php endforeach; ?>
				<a href="#vwl-tab-help" class="nav-tab" data-vwl-tab="help"><?php esc_html_e( 'Google Drive setup', 'vwl-flip-book' ); ?></a>
				<a href="#vwl-tab-tools" class="nav-tab" data-vwl-tab="tools"><?php esc_html_e( 'Tools', 'vwl-flip-book' ); ?></a>
			</nav>

			<form method="post" action="options.php" class="vwl-ebook-settings-form">
				<?php settings_fields( 'vwl_ebook' ); ?>
				<?php foreach ( $sections as $slug => $section ) : ?>
				<div class="vwl-ebook-panel" id="vwl-tab-<?php echo esc_attr( $slug ); ?>" data-vwl-panel="<?php echo esc_attr( $slug ); ?>">
					<table class="form-table" role="presentation">
						<?php
						foreach ( $section['fields'] as $key => $def ) {
							self::field( $key, $def, $settings );
						}
						?>
					</table>
				</div>
				<?php endforeach; ?>
				<div class="vwl-ebook-submit"><?php submit_button(); ?></div>
			</form>

			<div class="vwl-ebook-panel" id="vwl-tab-help" data-vwl-panel="help">
				<h2><?php esc_html_e( 'Share the PDF publicly in Google Drive', 'vwl-flip-book' ); ?></h2>
				<ol>
					<li><?php esc_html_e( 'Open drive.google.com and find the PDF.', 'vwl-flip-book' ); ?></li>
					<li><?php esc_html_e( 'Right-click it and choose Share → Share.', 'vwl-flip-book' ); ?></li>
					<li><?php esc_html_e( 'Under General access, change "Restricted" to "Anyone with the link" and keep the role as Viewer.', 'vwl-flip-book' ); ?></li>
					<li><?php esc_html_e( 'Click Copy link, then Done.', 'vwl-flip-book' ); ?></li>
					<li><?php esc_html_e( 'Paste the link into the shortcode, the block, or an E-Book entry.', 'vwl-flip-book' ); ?></li>
				</ol>
				<p><?php esc_html_e( 'If the file owner turned off "Viewers can download, print and copy", Google Drive also blocks the download that the reader needs, and the book will not open. Leave that option on.', 'vwl-flip-book' ); ?></p>
				<p><?php esc_html_e( 'Google Workspace (company) accounts may prevent sharing outside the organization. Ask your Workspace admin, or move the PDF to a personal Drive.', 'vwl-flip-book' ); ?></p>

				<h2><?php esc_html_e( 'Shortcode examples', 'vwl-flip-book' ); ?></h2>
				<p><code>[pdf_ebook url="https://drive.google.com/file/d/FILE_ID/view"]</code></p>
				<p><code>[pdf_ebook url="FILE_ID" title="Digital Marketing Guide" height="800" theme="light" download="false" print="false" search="true" fullscreen="true" toc="true" cover="true"]</code></p>
				<p><code>[e_book id="123"]</code></p>

				<h2><?php esc_html_e( 'Troubleshooting', 'vwl-flip-book' ); ?></h2>
				<ul class="ul-disc">
					<li><?php esc_html_e( '"Not shared publicly": the sharing setting is still Restricted. Change it and click "Try again" in the reader.', 'vwl-flip-book' ); ?></li>
					<li><?php esc_html_e( '"Could not connect to Google Drive": your host blocks outgoing HTTPS requests. Ask the host to allow connections to drive.google.com and drive.usercontent.google.com.', 'vwl-flip-book' ); ?></li>
					<li><?php esc_html_e( '"Temporarily limited": Google applies download quotas to very popular files. Keep caching on so the file is downloaded rarely.', 'vwl-flip-book' ); ?></li>
					<li><?php esc_html_e( 'Old version still showing after replacing the file: clear the cache on the Tools tab.', 'vwl-flip-book' ); ?></li>
					<li><?php esc_html_e( 'Use the link tester on the Tools tab to see exactly what Google Drive returns for a link.', 'vwl-flip-book' ); ?></li>
				</ul>
			</div>

			<div class="vwl-ebook-panel" id="vwl-tab-tools" data-vwl-panel="tools">
				<h2><?php esc_html_e( 'Test a Google Drive link', 'vwl-flip-book' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Checks the link from this server exactly as the reader will, and refreshes its cached copy.', 'vwl-flip-book' ); ?></p>
				<p>
					<input type="text" class="large-text code" id="vwl-ebook-test-url" placeholder="https://drive.google.com/file/d/FILE_ID/view" data-vwl-drive-input />
				</p>
				<p class="description" data-vwl-drive-status></p>
				<p><button type="button" class="button button-primary" id="vwl-ebook-test-btn"><?php esc_html_e( 'Test link', 'vwl-flip-book' ); ?></button></p>
				<div id="vwl-ebook-test-result" class="vwl-ebook-test-result" aria-live="polite"></div>

				<h2><?php esc_html_e( 'Cache', 'vwl-flip-book' ); ?></h2>
				<p>
					<?php
					/* translators: 1: number of files, 2: total size */
					echo esc_html( sprintf( __( '%1$d cached books using %2$s.', 'vwl-flip-book' ), $stats['count'], size_format( $stats['bytes'] ) ? size_format( $stats['bytes'] ) : '0 B' ) );
					?>
				</p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="vwl_ebook_clear_cache" />
					<?php wp_nonce_field( 'vwl_ebook_clear_cache' ); ?>
					<?php submit_button( __( 'Clear cached books', 'vwl-flip-book' ), 'secondary', 'submit', false ); ?>
				</form>
			</div>
		</div>
		<?php
	}
}
