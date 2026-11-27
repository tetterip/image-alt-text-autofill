<?php
/**
 * Upload hook, front-end fill-in, Media Library column / buttons and the single-image AJAX action.
 *
 * @package Image_Alt_Text_Autofill
 */

defined( 'ABSPATH' ) || exit;

/**
 * Everything that touches the media library or image output.
 */
class Tet_Alt_Media {

	const NONCE = 'tet_alt_generate';

	public static function init(): void {
		// On upload: after the sizes exist, so AI can use the "large" size. Runs inside the upload
		// request, so the alt text is in the REST response the editor inserts.
		add_filter( 'wp_generate_attachment_metadata', array( __CLASS__, 'on_upload' ), 20, 3 );

		if ( ! is_admin() ) {
			add_filter( 'wp_get_attachment_image_attributes', array( __CLASS__, 'fill_attachment_attributes' ), 20, 2 );
			add_filter( 'wp_content_img_tag', array( __CLASS__, 'fill_content_img' ), 20, 3 );
		}

		add_filter( 'manage_media_columns', array( __CLASS__, 'add_column' ) );
		add_action( 'manage_media_custom_column', array( __CLASS__, 'render_column' ), 10, 2 );
		add_filter( 'bulk_actions-upload', array( __CLASS__, 'add_bulk_action' ) );
		add_filter( 'handle_bulk_actions-upload', array( __CLASS__, 'handle_bulk_action' ), 10, 3 );
		add_filter( 'attachment_fields_to_edit', array( __CLASS__, 'add_modal_button' ), 10, 2 );
		add_action( 'wp_enqueue_media', array( __CLASS__, 'enqueue' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin' ) );
		add_action( 'wp_ajax_tet_alt_generate', array( __CLASS__, 'ajax_generate' ) );
	}

	/* ── Upload ─────────────────────────────────────────────── */

	/**
	 * Writes the alt text of a newly uploaded image if it is empty.
	 *
	 * @param array  $metadata Attachment metadata.
	 * @param int    $id       Attachment ID.
	 * @param string $context  create | update (regenerating sizes).
	 * @return array Unchanged metadata.
	 */
	public static function on_upload( $metadata, $id, $context = 'create' ) {
		if ( 'create' !== $context || ! Tet_Alt_Settings::on( 'on_upload' ) || ! wp_attachment_is_image( (int) $id ) ) {
			return $metadata;
		}
		// The sizes are saved after this filter returns; AI reads them from the metadata passed in.
		self::$upload_id   = (int) $id;
		self::$upload_meta = is_array( $metadata ) ? $metadata : null;
		add_filter( 'wp_get_attachment_metadata', array( __CLASS__, 'provide_upload_meta' ), 10, 2 );
		try {
			Tet_Alt_Generator::apply( (int) $id, '', 'missing' );
		} finally {
			remove_filter( 'wp_get_attachment_metadata', array( __CLASS__, 'provide_upload_meta' ), 10 );
			self::$upload_meta = null;
			self::$upload_id   = 0;
		}
		return $metadata;
	}

	/** @var array|null Metadata of the image being uploaded. */
	private static $upload_meta = null;

	/** @var int ID of the image being uploaded. */
	private static $upload_id = 0;

	/**
	 * Supplies the not-yet-saved metadata of the uploading image.
	 *
	 * @param mixed $data Saved metadata.
	 * @param int   $id   Attachment ID.
	 * @return mixed
	 */
	public static function provide_upload_meta( $data, $id ) {
		return ( (int) $id === self::$upload_id && null !== self::$upload_meta && empty( $data['sizes'] ) ) ? self::$upload_meta : $data;
	}

	/* ── Front end ──────────────────────────────────────────── */

	/**
	 * Images from wp_get_attachment_image() (featured images, galleries, WooCommerce product images).
	 *
	 * @param array   $attr       Attributes.
	 * @param WP_Post $attachment Attachment.
	 * @return array
	 */
	public static function fill_attachment_attributes( $attr, $attachment ) {
		if ( ! Tet_Alt_Settings::on( 'on_render' ) || ! $attachment instanceof WP_Post ) {
			return $attr;
		}
		$alt = isset( $attr['alt'] ) ? trim( (string) $attr['alt'] ) : null;
		if ( null !== $alt && ( '' !== $alt || ! Tet_Alt_Settings::on( 'empty_is_missing' ) ) ) {
			return $attr;
		}
		$generated = self::render_alt( $attachment->ID );
		if ( '' !== $generated ) {
			$attr['alt'] = $generated;
		}
		return $attr;
	}

	/**
	 * <img> tags in post content (blocks, classic editor). Uses the saved alt of the attachment
	 * when the block was inserted before it had one, otherwise a template alt.
	 *
	 * @param string $tag     The <img> tag.
	 * @param string $context Filter context.
	 * @param int    $id      Attachment ID, 0 if unknown.
	 */
	public static function fill_content_img( $tag, $context, $id ) {
		if ( ! Tet_Alt_Settings::on( 'on_render' ) || ! Tet_Alt_Logic::img_needs_alt( (string) $tag, Tet_Alt_Settings::on( 'empty_is_missing' ) ) ) {
			return $tag;
		}
		$id  = (int) $id;
		$alt = $id ? trim( (string) get_post_meta( $id, '_wp_attachment_image_alt', true ) ) : '';
		if ( '' === $alt ) {
			$alt = $id ? self::render_alt( $id ) : Tet_Alt_Logic::clean_filename( (string) Tet_Alt_Logic::img_attr( (string) $tag, 'src' ) );
		}
		return '' !== $alt ? Tet_Alt_Logic::img_set_alt( (string) $tag, $alt ) : $tag;
	}

	/**
	 * Template alt for output, cached per request.
	 *
	 * @param int $id Attachment ID.
	 */
	private static function render_alt( int $id ): string {
		static $cache = array();
		if ( ! isset( $cache[ $id ] ) ) {
			$cache[ $id ] = wp_attachment_is_image( $id ) ? Tet_Alt_Generator::from_template( $id ) : '';
		}
		return $cache[ $id ];
	}

	/* ── Media Library ──────────────────────────────────────── */

	public static function add_column( $columns ) {
		$columns['tet_alt'] = esc_html__( 'Alt text', 'image-alt-text-autofill' );
		return $columns;
	}

	public static function render_column( $column, $id ) {
		if ( 'tet_alt' !== $column || ! wp_attachment_is_image( (int) $id ) ) {
			return;
		}
		$alt    = (string) get_post_meta( (int) $id, '_wp_attachment_image_alt', true );
		$source = (string) get_post_meta( (int) $id, Tet_Alt_Generator::META_SOURCE, true );
		echo '<div class="tet-alt-cell" data-id="' . esc_attr( (string) $id ) . '">';
		echo '<span class="tet-alt-text">' . ( '' !== $alt ? esc_html( $alt ) : '<em class="tet-alt-missing">' . esc_html__( 'Missing', 'image-alt-text-autofill' ) . '</em>' ) . '</span> ';
		echo self::source_badge( $source ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
		if ( current_user_can( 'edit_post', (int) $id ) ) {
			echo '<br /><button type="button" class="button-link tet-alt-generate" data-id="' . esc_attr( (string) $id ) . '">' . esc_html( '' !== $alt ? __( 'Regenerate', 'image-alt-text-autofill' ) : __( 'Generate', 'image-alt-text-autofill' ) ) . '</button>';
		}
		echo '</div>';
	}

	/**
	 * Small label saying who wrote the alt.
	 *
	 * @param string $source template | ai | ''.
	 */
	public static function source_badge( string $source ): string {
		if ( 'ai' === $source ) {
			return '<span class="tet-alt-badge tet-alt-badge--ai">' . esc_html__( 'AI', 'image-alt-text-autofill' ) . '</span>';
		}
		if ( 'template' === $source ) {
			return '<span class="tet-alt-badge">' . esc_html__( 'Auto', 'image-alt-text-autofill' ) . '</span>';
		}
		return '';
	}

	public static function add_bulk_action( $actions ) {
		if ( current_user_can( 'manage_options' ) ) {
			$actions['tet_alt_generate'] = __( 'Generate alt text', 'image-alt-text-autofill' );
		}
		return $actions;
	}

	/**
	 * Sends the selected images to the bulk page, which processes them in small batches
	 * (AI requests take seconds each; doing them here would time out).
	 *
	 * @param string $redirect Redirect URL.
	 * @param string $action   Action.
	 * @param int[]  $ids      Selected IDs.
	 */
	public static function handle_bulk_action( $redirect, $action, $ids ) {
		if ( 'tet_alt_generate' !== $action || ! current_user_can( 'manage_options' ) ) {
			return $redirect;
		}
		$ids = array_slice( array_filter( array_map( 'absint', (array) $ids ) ), 0, 500 );
		return Tet_Alt_Admin::url( array( 'ids' => implode( ',', $ids ) ) );
	}

	/**
	 * "Generate alt text" button in the media modal and on the attachment edit screen.
	 *
	 * @param array   $fields Fields.
	 * @param WP_Post $post   Attachment.
	 */
	public static function add_modal_button( $fields, $post ) {
		if ( ! $post instanceof WP_Post || ! wp_attachment_is_image( $post->ID ) || ! current_user_can( 'edit_post', $post->ID ) ) {
			return $fields;
		}
		$fields['tet_alt_generate'] = array(
			'label' => '',
			'input' => 'html',
			'html'  => '<button type="button" class="button tet-alt-generate" data-id="' . esc_attr( (string) $post->ID ) . '" data-fill="1">' . esc_html__( 'Generate alt text', 'image-alt-text-autofill' ) . '</button> <span class="tet-alt-modal-status" aria-live="polite"></span>',
		);
		return $fields;
	}

	/** Script for the media modal (block editor, Media Library grid, classic editor). */
	public static function enqueue(): void {
		if ( ! current_user_can( 'upload_files' ) ) {
			return;
		}
		wp_enqueue_script( 'tet-alt-media', TET_ALT_URL . 'assets/js/media.js', array(), TET_ALT_VERSION, true );
		wp_localize_script(
			'tet-alt-media',
			'tetAltMedia',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::NONCE ),
				'i18n'    => array(
					'working' => __( 'Writing…', 'image-alt-text-autofill' ),
					'done'    => __( 'Done', 'image-alt-text-autofill' ),
					'error'   => __( 'Could not generate the alt text.', 'image-alt-text-autofill' ),
					'missing' => __( 'Missing', 'image-alt-text-autofill' ),
					'regen'   => __( 'Regenerate', 'image-alt-text-autofill' ),
					'confirm' => __( 'Replace the current alt text?', 'image-alt-text-autofill' ),
				),
			)
		);
		wp_enqueue_style( 'tet-alt-admin', TET_ALT_URL . 'assets/css/admin.css', array(), TET_ALT_VERSION );
	}

	/**
	 * Media Library list view and attachment edit screen.
	 *
	 * @param string $hook Admin page hook.
	 */
	public static function enqueue_admin( $hook ): void {
		if ( 'upload.php' === $hook || 'post.php' === $hook ) {
			self::enqueue();
		}
	}

	/** AJAX: generate and save one image's alt text. */
	public static function ajax_generate(): void {
		check_ajax_referer( self::NONCE, 'nonce' );
		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		if ( ! $id || ! current_user_can( 'edit_post', $id ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to edit this image.', 'image-alt-text-autofill' ) ), 403 );
		}
		$result = Tet_Alt_Generator::apply( $id, '', 'all' );
		if ( 'saved' !== $result['status'] ) {
			wp_send_json_error( array( 'message' => $result['note'] ) );
		}
		wp_send_json_success(
			array(
				'alt'    => $result['alt'],
				'source' => $result['source'],
				'badge'  => self::source_badge( $result['source'] ),
				'note'   => $result['note'],
			)
		);
	}
}
