<?php
/**
 * Admin page: Media → Alt Text (Bulk / Settings), bulk AJAX, privacy text.
 *
 * @package Image_Alt_Text_Autofill
 */

defined( 'ABSPATH' ) || exit;

/**
 * Admin page and bulk processing.
 */
class Tet_Alt_Admin {

	/** Plugin title: a name, stays English in every language, never wrapped in __(). */
	const PLUGIN_TITLE = 'Image Alt Text Autofill';

	const PAGE  = 'image-alt-text-autofill';
	const NONCE = 'tet_alt_bulk';

	/** Images per bulk request: AI requests take seconds each, templates are instant. */
	const BATCH_AI       = 3;
	const BATCH_TEMPLATE = 25;

	/** Most images one bulk run lists. */
	const MAX_IDS = 20000;

	/** @var string */
	private static $hook = '';

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'add_submenu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'wp_ajax_tet_alt_bulk_ids', array( __CLASS__, 'ajax_ids' ) );
		add_action( 'wp_ajax_tet_alt_bulk_run', array( __CLASS__, 'ajax_run' ) );
		add_action( 'admin_init', array( __CLASS__, 'privacy_policy_content' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( TET_ALT_FILE ), array( __CLASS__, 'action_links' ) );
	}

	public static function url( array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => self::PAGE ), $args ), admin_url( 'upload.php' ) );
	}

	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( self::url( array( 'tab' => 'settings' ) ) ) . '">' . esc_html__( 'Settings', 'image-alt-text-autofill' ) . '</a>' );
		return $links;
	}

	/* ── Menu: Media → Alt Text ─────────────────────────────── */

	public static function add_submenu(): void {
		self::$hook = (string) add_submenu_page( 'upload.php', self::PLUGIN_TITLE, __( 'Alt Text', 'image-alt-text-autofill' ), 'manage_options', self::PAGE, array( __CLASS__, 'render_page' ) );
	}

	public static function enqueue( $hook ): void {
		if ( $hook !== self::$hook ) {
			return;
		}
		wp_enqueue_style( 'tet-alt-admin', TET_ALT_URL . 'assets/css/admin.css', array(), TET_ALT_VERSION );
		wp_enqueue_script( 'tet-alt-bulk', TET_ALT_URL . 'assets/js/bulk.js', array(), TET_ALT_VERSION, true );
		wp_localize_script(
			'tet-alt-bulk',
			'tetAltBulk',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::NONCE ),
				'i18n'    => array(
					/* translators: 1: images done, 2: images in total. */
					'progress'  => __( '%1$d of %2$d images', 'image-alt-text-autofill' ),
					'none'      => __( 'No images match. Nothing to do.', 'image-alt-text-autofill' ),
					'finished'  => __( 'Finished.', 'image-alt-text-autofill' ),
					'stopped'   => __( 'Stopped.', 'image-alt-text-autofill' ),
					'failed'    => __( 'The request failed. Check your connection and try again.', 'image-alt-text-autofill' ),
					'saved'     => __( 'Saved', 'image-alt-text-autofill' ),
					'preview'   => __( 'Preview', 'image-alt-text-autofill' ),
					'skipped'   => __( 'Kept', 'image-alt-text-autofill' ),
					'error'     => __( 'Error', 'image-alt-text-autofill' ),
					'sourceAi'  => __( 'AI', 'image-alt-text-autofill' ),
					'sourceTemplate' => __( 'Template', 'image-alt-text-autofill' ),
					/* translators: %d: number of images. */
					'confirmAi' => __( 'Images to send to your AI provider: %d (one paid request each). Continue?', 'image-alt-text-autofill' ),
					'confirmAll' => __( 'This replaces every alt text, including ones written by people. Continue?', 'image-alt-text-autofill' ),
				),
			)
		);
	}

	/* ── Page ───────────────────────────────────────────────── */

	public static function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$tab  = isset( $_GET['tab'] ) && 'settings' === $_GET['tab'] ? 'settings' : 'bulk'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- tab switch.
		$tabs = array(
			'bulk'     => __( 'Bulk generate', 'image-alt-text-autofill' ),
			'settings' => __( 'Settings', 'image-alt-text-autofill' ),
		);
		?>
		<div class="wrap tet-alt-wrap">
			<h1><?php echo esc_html( self::PLUGIN_TITLE ); ?> <span class="tet-alt-version">v<?php echo esc_html( TET_ALT_VERSION ); ?></span></h1>
			<hr class="wp-header-end" />
			<nav class="nav-tab-wrapper">
				<?php foreach ( $tabs as $key => $label ) : ?>
					<a href="<?php echo esc_url( self::url( 'bulk' === $key ? array() : array( 'tab' => $key ) ) ); ?>" class="nav-tab <?php echo $key === $tab ? 'nav-tab-active' : ''; ?>"><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</nav>
			<?php
			settings_errors();
			if ( 'settings' === $tab ) {
				Tet_Alt_Settings::render();
			} else {
				self::render_bulk();
			}
			?>
		</div>
		<?php
	}

	private static function render_bulk(): void {
		$counts = self::counts();
		$ai     = Tet_Alt_AI::available();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- IDs from the Media Library bulk action; they only preselect the run.
		$ids = isset( $_GET['ids'] ) ? array_filter( array_map( 'absint', explode( ',', sanitize_text_field( wp_unslash( $_GET['ids'] ) ) ) ) ) : array();
		?>
		<div class="tet-alt-stats">
			<div class="tet-alt-stat"><strong><?php echo esc_html( number_format_i18n( $counts['total'] ) ); ?></strong><span><?php esc_html_e( 'Images', 'image-alt-text-autofill' ); ?></span></div>
			<div class="tet-alt-stat tet-alt-stat--warn"><strong><?php echo esc_html( number_format_i18n( $counts['missing'] ) ); ?></strong><span><?php esc_html_e( 'Without alt text', 'image-alt-text-autofill' ); ?></span></div>
			<div class="tet-alt-stat"><strong><?php echo esc_html( number_format_i18n( $counts['template'] ) ); ?></strong><span><?php esc_html_e( 'Automatic (template)', 'image-alt-text-autofill' ); ?></span></div>
			<div class="tet-alt-stat"><strong><?php echo esc_html( number_format_i18n( $counts['ai'] ) ); ?></strong><span><?php esc_html_e( 'Written by AI', 'image-alt-text-autofill' ); ?></span></div>
		</div>

		<form id="tet-alt-bulk-form" class="tet-alt-card">
			<?php if ( $ids ) : ?>
				<input type="hidden" name="ids" value="<?php echo esc_attr( implode( ',', $ids ) ); ?>" />
				<p><strong>
					<?php
					/* translators: %s: number of images. */
					echo esc_html( sprintf( _n( '%s image selected in the Media Library.', '%s images selected in the Media Library.', count( $ids ), 'image-alt-text-autofill' ), number_format_i18n( count( $ids ) ) ) );
					?>
				</strong> <a href="<?php echo esc_url( self::url() ); ?>"><?php esc_html_e( 'Use all images instead', 'image-alt-text-autofill' ); ?></a></p>
			<?php endif; ?>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Write with', 'image-alt-text-autofill' ); ?></th>
					<td>
						<label><input type="radio" name="source" value="template" <?php checked( ! $ai || 'template' === Tet_Alt_Settings::get( 'source' ) ); ?> /> <?php esc_html_e( 'Template', 'image-alt-text-autofill' ); ?></label>
						<label class="tet-alt-inline"><input type="radio" name="source" value="ai" <?php checked( $ai && 'ai' === Tet_Alt_Settings::get( 'source' ) ); ?> <?php disabled( ! $ai ); ?> /> <?php esc_html_e( 'AI', 'image-alt-text-autofill' ); ?></label>
						<div class="notice notice-warning inline tet-alt-cost" data-tet-alt-show="source=ai" <?php echo ( $ai && 'ai' === Tet_Alt_Settings::get( 'source' ) ) ? '' : 'hidden'; ?>>
							<p><strong><?php esc_html_e( 'AI can cost money.', 'image-alt-text-autofill' ); ?></strong> <?php echo esc_html( Tet_Alt_Settings::cost_text() ); ?></p>
						</div>
						<?php if ( ! $ai ) : ?>
							<p class="description"><a href="<?php echo esc_url( self::url( array( 'tab' => 'settings' ) ) ); ?>"><?php esc_html_e( 'Why is AI not available?', 'image-alt-text-autofill' ); ?></a></p>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Which images', 'image-alt-text-autofill' ); ?></th>
					<td>
						<fieldset>
							<label><input type="radio" name="scope" value="missing" checked /> <?php esc_html_e( 'Only those without alt text', 'image-alt-text-autofill' ); ?></label><br />
							<label><input type="radio" name="scope" value="auto" /> <?php esc_html_e( 'Also replace automatic (template) alt texts, e.g. with AI ones', 'image-alt-text-autofill' ); ?></label><br />
							<label><input type="radio" name="scope" value="all" /> <?php esc_html_e( 'All images, including alt texts written by people', 'image-alt-text-autofill' ); ?></label>
						</fieldset>
						<p class="description"><?php esc_html_e( 'A replaced alt text is kept in the image\'s data, so it is never lost.', 'image-alt-text-autofill' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Preview', 'image-alt-text-autofill' ); ?></th>
					<td><label><input type="checkbox" name="dry_run" value="1" /> <?php esc_html_e( 'Only show what would be written, save nothing', 'image-alt-text-autofill' ); ?></label></td>
				</tr>
			</table>
			<p>
				<button type="submit" class="button button-primary" id="tet-alt-start"><?php esc_html_e( 'Start', 'image-alt-text-autofill' ); ?></button>
				<button type="button" class="button" id="tet-alt-stop" hidden><?php esc_html_e( 'Stop', 'image-alt-text-autofill' ); ?></button>
				<span id="tet-alt-progress-text" aria-live="polite"></span>
			</p>
			<progress id="tet-alt-progress" max="100" value="0" hidden></progress>
			<p class="description"><?php esc_html_e( 'Keep this page open while it runs. With AI, every image is one request to your provider.', 'image-alt-text-autofill' ); ?></p>
		</form>

		<table class="widefat striped tet-alt-results" id="tet-alt-results" hidden>
			<thead><tr>
				<th class="tet-alt-col-thumb"></th>
				<th><?php esc_html_e( 'Image', 'image-alt-text-autofill' ); ?></th>
				<th><?php esc_html_e( 'Alt text', 'image-alt-text-autofill' ); ?></th>
				<th><?php esc_html_e( 'Result', 'image-alt-text-autofill' ); ?></th>
			</tr></thead>
			<tbody></tbody>
		</table>
		<?php
	}

	/* ── Data ───────────────────────────────────────────────── */

	/**
	 * Image counts for the stats row.
	 *
	 * @return array{total:int,missing:int,template:int,ai:int}
	 */
	public static function counts(): array {
		global $wpdb;
		$cached = wp_cache_get( 'counts', 'tet_alt' );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- aggregate over postmeta, cached above.
		$row = $wpdb->get_row(
			"SELECT COUNT(*) AS total,
				SUM( alt.meta_value IS NULL OR TRIM( alt.meta_value ) = '' ) AS missing,
				SUM( src.meta_value = 'template' ) AS template,
				SUM( src.meta_value = 'ai' ) AS ai
			FROM {$wpdb->posts} p
			LEFT JOIN {$wpdb->postmeta} alt ON alt.post_id = p.ID AND alt.meta_key = '_wp_attachment_image_alt'
			LEFT JOIN {$wpdb->postmeta} src ON src.post_id = p.ID AND src.meta_key = '_tet_alt_source'
			WHERE p.post_type = 'attachment' AND p.post_mime_type LIKE 'image/%'",
			ARRAY_A
		);
		// phpcs:enable
		$counts = array(
			'total'    => (int) ( $row['total'] ?? 0 ),
			'missing'  => (int) ( $row['missing'] ?? 0 ),
			'template' => (int) ( $row['template'] ?? 0 ),
			'ai'       => (int) ( $row['ai'] ?? 0 ),
		);
		wp_cache_set( 'counts', $counts, 'tet_alt', 60 );
		return $counts;
	}

	/**
	 * IDs of the images a run will look at, newest first.
	 *
	 * @param string $scope missing | auto | all.
	 * @return int[]
	 */
	public static function query_ids( string $scope ): array {
		global $wpdb;
		$image = $wpdb->esc_like( 'image/' ) . '%';
		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- run once when a bulk run starts; caching a one-off ID list gains nothing.
		if ( 'missing' === $scope ) {
			$ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT p.ID FROM {$wpdb->posts} p
					LEFT JOIN {$wpdb->postmeta} alt ON alt.post_id = p.ID AND alt.meta_key = '_wp_attachment_image_alt'
					WHERE p.post_type = 'attachment' AND p.post_mime_type LIKE %s
					AND ( alt.meta_value IS NULL OR TRIM( alt.meta_value ) = '' )
					ORDER BY p.ID DESC LIMIT %d",
					$image,
					self::MAX_IDS
				)
			);
		} elseif ( 'auto' === $scope ) {
			$ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT p.ID FROM {$wpdb->posts} p
					LEFT JOIN {$wpdb->postmeta} alt ON alt.post_id = p.ID AND alt.meta_key = '_wp_attachment_image_alt'
					LEFT JOIN {$wpdb->postmeta} src ON src.post_id = p.ID AND src.meta_key = '_tet_alt_source'
					WHERE p.post_type = 'attachment' AND p.post_mime_type LIKE %s
					AND ( alt.meta_value IS NULL OR TRIM( alt.meta_value ) = '' OR src.meta_value = 'template' )
					ORDER BY p.ID DESC LIMIT %d",
					$image,
					self::MAX_IDS
				)
			);
		} else {
			$ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT p.ID FROM {$wpdb->posts} p
					WHERE p.post_type = 'attachment' AND p.post_mime_type LIKE %s
					ORDER BY p.ID DESC LIMIT %d",
					$image,
					self::MAX_IDS
				)
			);
		}
		// phpcs:enable
		return array_map( 'intval', (array) $ids );
	}

	/* ── AJAX ───────────────────────────────────────────────── */

	private static function check(): void {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'image-alt-text-autofill' ) ), 403 );
		}
	}

	private static function posted_scope(): string {
		$scope = isset( $_POST['scope'] ) ? sanitize_key( wp_unslash( $_POST['scope'] ) ) : 'missing'; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked in check().
		return in_array( $scope, array( 'missing', 'auto', 'all' ), true ) ? $scope : 'missing';
	}

	/** Lists the IDs to process and the batch size. */
	public static function ajax_ids(): void {
		self::check();
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- checked in check().
		$source = isset( $_POST['source'] ) && 'ai' === $_POST['source'] && Tet_Alt_AI::available() ? 'ai' : 'template';
		$given  = isset( $_POST['ids'] ) ? sanitize_text_field( wp_unslash( $_POST['ids'] ) ) : '';
		// phpcs:enable
		$ids = '' !== $given
			? array_values( array_filter( array_map( 'absint', explode( ',', $given ) ), 'wp_attachment_is_image' ) )
			: self::query_ids( self::posted_scope() );
		wp_send_json_success(
			array(
				'ids'    => $ids,
				'source' => $source,
				'batch'  => 'ai' === $source ? self::BATCH_AI : self::BATCH_TEMPLATE,
			)
		);
	}

	/** Processes one batch. */
	public static function ajax_run(): void {
		self::check();
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- checked in check().
		$ids     = isset( $_POST['ids'] ) ? array_slice( array_map( 'absint', (array) wp_unslash( $_POST['ids'] ) ), 0, self::BATCH_TEMPLATE ) : array();
		$source  = isset( $_POST['source'] ) && 'ai' === $_POST['source'] ? 'ai' : 'template';
		$dry_run = ! empty( $_POST['dry_run'] );
		// phpcs:enable
		$scope = self::posted_scope();
		if ( 'ai' === $source ) {
			$ids = array_slice( $ids, 0, self::BATCH_AI );
		}

		$rows = array();
		foreach ( $ids as $id ) {
			if ( ! $id || ! wp_attachment_is_image( $id ) ) {
				continue;
			}
			$r      = Tet_Alt_Generator::apply( $id, $source, $scope, $dry_run );
			$rows[] = array(
				'id'       => $id,
				'title'    => wp_strip_all_tags( get_the_title( $id ) ),
				'thumb'    => (string) wp_get_attachment_image_url( $id, 'thumbnail' ),
				'edit'     => (string) get_edit_post_link( $id, 'raw' ),
				'status'   => $r['status'],
				'alt'      => $r['alt'],
				'previous' => $r['previous'],
				'source'   => $r['source'],
				'note'     => $r['note'],
			);
		}
		wp_cache_delete( 'counts', 'tet_alt' );
		wp_send_json_success( array( 'rows' => $rows ) );
	}

	/* ── Privacy ────────────────────────────────────────────── */

	/** Suggested text for Settings → Privacy → Policy Guide. */
	public static function privacy_policy_content(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		$text  = '<p>' . esc_html__( 'Image Alt Text Autofill writes descriptions (alt text) for the images in the media library, for visitors who use screen readers and for search engines.', 'image-alt-text-autofill' ) . '</p>';
		$text .= '<p>' . esc_html__( 'Without AI, it works only with data already on this site (file names and titles) and sends nothing anywhere.', 'image-alt-text-autofill' ) . '</p>';
		$text .= '<p>' . esc_html__( 'If AI descriptions are turned on, each image that gets a description is sent, with the site name and the title of the page or product it belongs to, to the AI provider the site owner set up in WordPress (Settings → Connectors). If an image shows people, that may be personal data; the provider\'s own privacy policy applies to what it receives. Visitors\' data is never sent.', 'image-alt-text-autofill' ) . '</p>';
		wp_add_privacy_policy_content( self::PLUGIN_TITLE, wp_kses_post( $text ) );
	}
}
