<?php
/**
 * "E-Books" library: custom post type, category taxonomy, meta box.
 *
 * @package VWL_Ebook_Reader
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * E-Book post type.
 */
class VWL_Ebook_Post_Type {

	const POST_TYPE = 'vwl_ebook';
	const TAXONOMY  = 'vwl_ebook_category';
	const NONCE     = 'vwl_ebook_meta_nonce';

	/**
	 * Hook everything.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_boxes' ) );
		add_action( 'save_post_' . self::POST_TYPE, array( __CLASS__, 'save' ), 10, 2 );
		add_filter( 'manage_' . self::POST_TYPE . '_posts_columns', array( __CLASS__, 'columns' ) );
		add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', array( __CLASS__, 'column_content' ), 10, 2 );
		add_filter( 'the_content', array( __CLASS__, 'single_content' ), 20 );
	}

	/**
	 * Register post type and taxonomy.
	 */
	public static function register() {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'          => array(
					'name'               => __( 'E-Books', 'vwl-ebook-reader' ),
					'singular_name'      => __( 'E-Book', 'vwl-ebook-reader' ),
					'add_new'            => __( 'Add New', 'vwl-ebook-reader' ),
					'add_new_item'       => __( 'Add New E-Book', 'vwl-ebook-reader' ),
					'edit_item'          => __( 'Edit E-Book', 'vwl-ebook-reader' ),
					'new_item'           => __( 'New E-Book', 'vwl-ebook-reader' ),
					'view_item'          => __( 'View E-Book', 'vwl-ebook-reader' ),
					'search_items'       => __( 'Search E-Books', 'vwl-ebook-reader' ),
					'not_found'          => __( 'No e-books found.', 'vwl-ebook-reader' ),
					'not_found_in_trash' => __( 'No e-books found in Trash.', 'vwl-ebook-reader' ),
					'all_items'          => __( 'All E-Books', 'vwl-ebook-reader' ),
					'menu_name'          => __( 'E-Books', 'vwl-ebook-reader' ),
				),
				'public'          => true,
				'has_archive'     => true,
				'show_in_rest'    => true,
				'menu_icon'       => 'dashicons-book-alt',
				'menu_position'   => 21,
				'supports'        => array( 'title', 'editor', 'thumbnail', 'excerpt', 'author' ),
				'rewrite'         => array(
					'slug'       => 'ebooks',
					'with_front' => false,
				),
				'capability_type' => 'post',
			)
		);

		register_taxonomy(
			self::TAXONOMY,
			self::POST_TYPE,
			array(
				'labels'            => array(
					'name'          => __( 'E-Book Categories', 'vwl-ebook-reader' ),
					'singular_name' => __( 'E-Book Category', 'vwl-ebook-reader' ),
					'menu_name'     => __( 'Categories', 'vwl-ebook-reader' ),
				),
				'hierarchical'      => true,
				'show_in_rest'      => true,
				'show_admin_column' => true,
				'rewrite'           => array(
					'slug'       => 'ebook-category',
					'with_front' => false,
				),
			)
		);
	}

	/**
	 * Add the details meta box.
	 */
	public static function add_meta_boxes() {
		add_meta_box(
			'vwl-ebook-details',
			__( 'Book details', 'vwl-ebook-reader' ),
			array( __CLASS__, 'render_meta_box' ),
			self::POST_TYPE,
			'normal',
			'high'
		);
	}

	/**
	 * Tri-state select options.
	 *
	 * @return array
	 */
	private static function tri_options() {
		return array(
			''    => __( 'Use site default', 'vwl-ebook-reader' ),
			'on'  => __( 'On', 'vwl-ebook-reader' ),
			'off' => __( 'Off', 'vwl-ebook-reader' ),
		);
	}

	/**
	 * Render the meta box.
	 *
	 * @param WP_Post $post Post.
	 */
	public static function render_meta_box( $post ) {
		wp_nonce_field( 'vwl_ebook_save_' . $post->ID, self::NONCE );

		$url      = (string) get_post_meta( $post->ID, '_vwl_ebook_url', true );
		$author   = (string) get_post_meta( $post->ID, '_vwl_ebook_author', true );
		$file_id  = VWL_Ebook_Drive::extract_id( $url );
		$selects  = array(
			'_vwl_ebook_cover'    => __( 'Book cover screen', 'vwl-ebook-reader' ),
			'_vwl_ebook_download' => __( 'Download button', 'vwl-ebook-reader' ),
			'_vwl_ebook_print'    => __( 'Print button', 'vwl-ebook-reader' ),
		);
		?>
		<table class="form-table vwl-ebook-meta" role="presentation">
			<tr>
				<th scope="row"><label for="vwl-ebook-url"><?php esc_html_e( 'Google Drive PDF URL', 'vwl-ebook-reader' ); ?></label></th>
				<td>
					<input type="text" class="large-text code" id="vwl-ebook-url" name="vwl_ebook_url" value="<?php echo esc_attr( $url ); ?>" placeholder="https://drive.google.com/file/d/FILE_ID/view" data-vwl-drive-input />
					<p class="description" data-vwl-drive-status>
						<?php
						if ( '' === $url ) {
							esc_html_e( 'Paste a Google Drive share link or the file ID. The file must be shared as "Anyone with the link".', 'vwl-ebook-reader' );
						} elseif ( '' === $file_id ) {
							echo '<span class="vwl-ebook-bad">' . esc_html__( 'No Google Drive file ID found in this link.', 'vwl-ebook-reader' ) . '</span>';
						} else {
							/* translators: %s: file ID */
							echo '<span class="vwl-ebook-ok">' . esc_html( sprintf( __( 'File ID detected: %s', 'vwl-ebook-reader' ), $file_id ) ) . '</span>';
						}
						?>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="vwl-ebook-author"><?php esc_html_e( 'Book author', 'vwl-ebook-reader' ); ?></label></th>
				<td><input type="text" class="regular-text" id="vwl-ebook-author" name="vwl_ebook_author" value="<?php echo esc_attr( $author ); ?>" /></td>
			</tr>
			<?php foreach ( $selects as $key => $label ) : ?>
				<?php $value = (string) get_post_meta( $post->ID, $key, true ); ?>
			<tr>
				<th scope="row"><label for="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label></th>
				<td>
					<select id="<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( ltrim( $key, '_' ) ); ?>">
						<?php foreach ( self::tri_options() as $opt => $opt_label ) : ?>
						<option value="<?php echo esc_attr( $opt ); ?>" <?php selected( $value, $opt ); ?>><?php echo esc_html( $opt_label ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<?php endforeach; ?>
			<tr>
				<th scope="row"><?php esc_html_e( 'Shortcode', 'vwl-ebook-reader' ); ?></th>
				<td>
					<?php if ( 'auto-draft' === $post->post_status ) : ?>
						<p class="description"><?php esc_html_e( 'Save the e-book to get its shortcode.', 'vwl-ebook-reader' ); ?></p>
					<?php else : ?>
						<code class="vwl-ebook-shortcode">[e_book id="<?php echo absint( $post->ID ); ?>"]</code>
						<button type="button" class="button button-small" data-vwl-copy="[e_book id=&quot;<?php echo absint( $post->ID ); ?>&quot;]"><?php esc_html_e( 'Copy', 'vwl-ebook-reader' ); ?></button>
					<?php endif; ?>
					<p class="description"><?php esc_html_e( 'The featured image is used as the book cover. The main content area is the book description shown above the reader.', 'vwl-ebook-reader' ); ?></p>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Save meta.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post.
	 */
	public static function save( $post_id, $post ) {
		if ( ! isset( $_POST[ self::NONCE ] ) ) {
			return;
		}
		$nonce = sanitize_text_field( wp_unslash( $_POST[ self::NONCE ] ) );
		if ( ! wp_verify_nonce( $nonce, 'vwl_ebook_save_' . $post_id ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) ) {
			return;
		}
		if ( self::POST_TYPE !== $post->post_type || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$url = isset( $_POST['vwl_ebook_url'] ) ? sanitize_text_field( wp_unslash( $_POST['vwl_ebook_url'] ) ) : '';
		if ( '' !== $url && preg_match( '#^https?://#i', $url ) ) {
			$url = esc_url_raw( $url, array( 'http', 'https' ) );
		}
		update_post_meta( $post_id, '_vwl_ebook_url', $url );

		$author = isset( $_POST['vwl_ebook_author'] ) ? sanitize_text_field( wp_unslash( $_POST['vwl_ebook_author'] ) ) : '';
		update_post_meta( $post_id, '_vwl_ebook_author', $author );

		foreach ( array( 'vwl_ebook_cover', 'vwl_ebook_download', 'vwl_ebook_print' ) as $field ) {
			$value = isset( $_POST[ $field ] ) ? sanitize_key( wp_unslash( $_POST[ $field ] ) ) : '';
			$value = in_array( $value, array( 'on', 'off' ), true ) ? $value : '';
			update_post_meta( $post_id, '_' . $field, $value );
		}
	}

	/**
	 * Attributes for rendering a library book.
	 *
	 * @param int $post_id Post ID.
	 * @return array Empty array when not found / not viewable.
	 */
	public static function get_book_atts( $post_id ) {
		$post = $post_id ? get_post( $post_id ) : null;
		if ( ! $post || self::POST_TYPE !== $post->post_type ) {
			return array();
		}
		if ( 'publish' !== $post->post_status && ! current_user_can( 'read_post', $post->ID ) ) {
			return array();
		}
		if ( post_password_required( $post ) ) {
			return array();
		}

		$atts = array(
			'url'      => (string) get_post_meta( $post->ID, '_vwl_ebook_url', true ),
			'title'    => get_the_title( $post ),
			'author'   => (string) get_post_meta( $post->ID, '_vwl_ebook_author', true ),
			'ebook_id' => $post->ID,
		);

		$thumb = get_the_post_thumbnail_url( $post, 'large' );
		if ( $thumb ) {
			$atts['cover_image'] = $thumb;
		}

		$map = array(
			'_vwl_ebook_cover'    => 'cover',
			'_vwl_ebook_download' => 'download',
			'_vwl_ebook_print'    => 'print',
		);
		foreach ( $map as $meta => $att ) {
			$value = (string) get_post_meta( $post->ID, $meta, true );
			if ( 'on' === $value || 'off' === $value ) {
				$atts[ $att ] = $value;
			}
		}

		return $atts;
	}

	/**
	 * Admin list columns.
	 *
	 * @param array $columns Columns.
	 * @return array
	 */
	public static function columns( $columns ) {
		$new = array();
		foreach ( $columns as $key => $label ) {
			$new[ $key ] = $label;
			if ( 'title' === $key ) {
				$new['vwl_shortcode'] = __( 'Shortcode', 'vwl-ebook-reader' );
				$new['vwl_drive']     = __( 'Google Drive file', 'vwl-ebook-reader' );
			}
		}
		return $new;
	}

	/**
	 * Admin column content.
	 *
	 * @param string $column  Column.
	 * @param int    $post_id Post ID.
	 */
	public static function column_content( $column, $post_id ) {
		if ( 'vwl_shortcode' === $column ) {
			echo '<code>[e_book id="' . absint( $post_id ) . '"]</code>';
		} elseif ( 'vwl_drive' === $column ) {
			$id = VWL_Ebook_Drive::extract_id( (string) get_post_meta( $post_id, '_vwl_ebook_url', true ) );
			if ( '' === $id ) {
				echo '<span class="vwl-ebook-bad">' . esc_html__( 'Missing or invalid link', 'vwl-ebook-reader' ) . '</span>';
			} else {
				echo '<a href="' . esc_url( VWL_Ebook_Drive::view_url( $id ) ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( substr( $id, 0, 12 ) ) . '…</a>';
			}
		}
	}

	/**
	 * Append the reader to single e-book pages.
	 *
	 * @param string $content Content.
	 * @return string
	 */
	public static function single_content( $content ) {
		if ( ! is_singular( self::POST_TYPE ) || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}
		$post_id = get_the_ID();
		if ( get_queried_object_id() !== $post_id ) {
			return $content;
		}
		$atts = self::get_book_atts( $post_id );
		if ( empty( $atts ) ) {
			return $content;
		}
		return $content . VWL_Ebook_Renderer::render( $atts );
	}
}
