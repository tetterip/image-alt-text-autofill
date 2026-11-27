<?php
/**
 * Admin page: ttrp.gr Plugins → Smart Alt Text (Bulk / Settings), bulk AJAX, privacy text.
 *
 * @package Tet_Smart_Alt_Text
 */

defined( 'ABSPATH' ) || exit;

/**
 * Admin page and bulk processing.
 */
class Tet_Alt_Admin {

	/** Plugin title: stays English in every language (suite rule), never wrapped in __(). */
	const PLUGIN_TITLE = 'Smart Alt Text';

	const PAGE  = 'tet-smart-alt-text';
	const NONCE = 'tet_alt_bulk';

	/** Images per bulk request: AI requests take seconds each, templates are instant. */
	const BATCH_AI       = 3;
	const BATCH_TEMPLATE = 25;

	/** Most images one bulk run lists. */
	const MAX_IDS = 20000;

	/** @var string */
	private static $hook = '';

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'maybe_register_brand_menu' ), 9 );
		add_action( 'admin_menu', array( __CLASS__, 'add_submenu' ), 20 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'wp_ajax_tet_alt_bulk_ids', array( __CLASS__, 'ajax_ids' ) );
		add_action( 'wp_ajax_tet_alt_bulk_run', array( __CLASS__, 'ajax_run' ) );
		add_action( 'admin_init', array( __CLASS__, 'privacy_policy_content' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( TET_ALT_FILE ), array( __CLASS__, 'action_links' ) );
	}

	public static function url( array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => self::PAGE ), $args ), admin_url( 'admin.php' ) );
	}

	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( self::url( array( 'tab' => 'settings' ) ) ) . '">' . esc_html__( 'Settings', 'tet-smart-alt-text' ) . '</a>' );
		return $links;
	}

	/* ── Menu (shared ttrp.gr pattern) ──────────────────────── */

	public static function maybe_register_brand_menu(): void {
		global $menu;
		if ( is_array( $menu ) ) {
			foreach ( $menu as $item ) {
				if ( isset( $item[2] ) && 'ttrp-plugins' === $item[2] ) {
					return;
				}
			}
		}
		if ( ! function_exists( 'ttrp_brand_dashboard' ) ) {
			/** Redirects the bare top-level item to its first submenu page. */
			function ttrp_brand_dashboard(): void {
				global $submenu;
				$target = null;
				if ( ! empty( $submenu['ttrp-plugins'] ) ) {
					foreach ( $submenu['ttrp-plugins'] as $item ) {
						if ( 'ttrp-plugins' !== $item[2] ) {
							$target = $item[2];
							break;
						}
					}
				}
				if ( $target ) {
					$url = admin_url( 'admin.php?page=' . $target );
					echo '<meta http-equiv="refresh" content="0;url=' . esc_url( $url ) . '">';
					echo '<script>window.location.replace("' . esc_js( esc_url_raw( $url ) ) . '");</script>';
				}
			}
		}
		$svg  = TET_ALT_PATH . 'assets/ttrp-logo.svg';
		$icon = file_exists( $svg )
			? 'data:image/svg+xml;base64,' . base64_encode( (string) file_get_contents( $svg ) ) // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode,WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- menu icon.
			: 'dashicons-admin-plugins';
		add_menu_page( 'ttrp.gr Plugins', 'ttrp.gr Plugins', 'manage_options', 'ttrp-plugins', 'ttrp_brand_dashboard', $icon, 56 );
		add_action(
			'admin_menu',
			function () {
				remove_submenu_page( 'ttrp-plugins', 'ttrp-plugins' );
			},
			999
		);
	}

	public static function add_submenu(): void {
		self::$hook = (string) add_submenu_page( 'ttrp-plugins', self::PLUGIN_TITLE, self::PLUGIN_TITLE, 'manage_options', self::PAGE, array( __CLASS__, 'render_page' ) );
	}

	public static function enqueue( $hook ): void {
		if ( $hook !== self::$hook ) {
			return;
		}
		wp_enqueue_style( 'ttrp-admin', TET_ALT_URL . 'assets/ttrp-admin.css', array(), TET_ALT_VERSION );
		wp_enqueue_style( 'tet-alt-admin', TET_ALT_URL . 'assets/css/admin.css', array( 'ttrp-admin' ), TET_ALT_VERSION );
		wp_enqueue_script( 'tet-alt-bulk', TET_ALT_URL . 'assets/js/bulk.js', array(), TET_ALT_VERSION, true );
		wp_localize_script(
			'tet-alt-bulk',
			'tetAltBulk',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::NONCE ),
				'i18n'    => array(
					/* translators: 1: images done, 2: images in total. */
					'progress'  => __( '%1$d of %2$d images', 'tet-smart-alt-text' ),
					'none'      => __( 'No images match. Nothing to do.', 'tet-smart-alt-text' ),
					'finished'  => __( 'Finished.', 'tet-smart-alt-text' ),
					'stopped'   => __( 'Stopped.', 'tet-smart-alt-text' ),
					'failed'    => __( 'The request failed. Check your connection and try again.', 'tet-smart-alt-text' ),
					'saved'     => __( 'Saved', 'tet-smart-alt-text' ),
					'preview'   => __( 'Preview', 'tet-smart-alt-text' ),
					'skipped'   => __( 'Kept', 'tet-smart-alt-text' ),
					'error'     => __( 'Error', 'tet-smart-alt-text' ),
					'sourceAi'  => __( 'AI', 'tet-smart-alt-text' ),
					'sourceTemplate' => __( 'Template', 'tet-smart-alt-text' ),
					'confirmAll' => __( 'This replaces every alt text, including ones written by people. Continue?', 'tet-smart-alt-text' ),
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
			'bulk'     => __( 'Bulk generate', 'tet-smart-alt-text' ),
			'settings' => __( 'Settings', 'tet-smart-alt-text' ),
		);
		?>
		<div class="wrap ttrp-wrap tet-alt-wrap">
			<div class="ttrp-plugin-header">
				<h1><?php echo esc_html( self::PLUGIN_TITLE ); ?></h1>
				<span class="ttrp-plugin-version">v<?php echo esc_html( TET_ALT_VERSION ); ?></span>
			</div>
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
			<div class="ttrp-settings-footer">
				<img src="<?php echo esc_url( TET_ALT_URL . 'assets/ttrp.svg' ); ?>" alt="" />
				<span><?php echo esc_html( self::PLUGIN_TITLE ); ?> by <a href="https://ttrp.gr" target="_blank" rel="noopener">ttrp.gr</a></span>
			</div>
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
			<div class="tet-alt-stat"><strong><?php echo esc_html( number_format_i18n( $counts['total'] ) ); ?></strong><span><?php esc_html_e( 'Images', 'tet-smart-alt-text' ); ?></span></div>
			<div class="tet-alt-stat tet-alt-stat--warn"><strong><?php echo esc_html( number_format_i18n( $counts['missing'] ) ); ?></strong><span><?php esc_html_e( 'Without alt text', 'tet-smart-alt-text' ); ?></span></div>
			<div class="tet-alt-stat"><strong><?php echo esc_html( number_format_i18n( $counts['template'] ) ); ?></strong><span><?php esc_html_e( 'Automatic (template)', 'tet-smart-alt-text' ); ?></span></div>
			<div class="tet-alt-stat"><strong><?php echo esc_html( number_format_i18n( $counts['ai'] ) ); ?></strong><span><?php esc_html_e( 'Written by AI', 'tet-smart-alt-text' ); ?></span></div>
		</div>

		<form id="tet-alt-bulk-form" class="tet-alt-card">
			<?php if ( $ids ) : ?>
				<input type="hidden" name="ids" value="<?php echo esc_attr( implode( ',', $ids ) ); ?>" />
				<p><strong>
					<?php
					/* translators: %s: number of images. */
					echo esc_html( sprintf( _n( '%s image selected in the Media Library.', '%s images selected in the Media Library.', count( $ids ), 'tet-smart-alt-text' ), number_format_i18n( count( $ids ) ) ) );
					?>
				</strong> <a href="<?php echo esc_url( self::url() ); ?>"><?php esc_html_e( 'Use all images instead', 'tet-smart-alt-text' ); ?></a></p>
			<?php endif; ?>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Write with', 'tet-smart-alt-text' ); ?></th>
					<td>
						<label><input type="radio" name="source" value="template" <?php checked( ! $ai || 'template' === Tet_Alt_Settings::get( 'source' ) ); ?> /> <?php esc_html_e( 'Template', 'tet-smart-alt-text' ); ?></label>
						<label class="tet-alt-inline"><input type="radio" name="source" value="ai" <?php checked( $ai && 'ai' === Tet_Alt_Settings::get( 'source' ) ); ?> <?php disabled( ! $ai ); ?> /> <?php esc_html_e( 'AI', 'tet-smart-alt-text' ); ?></label>
						<?php if ( ! $ai ) : ?>
							<p class="description"><a href="<?php echo esc_url( self::url( array( 'tab' => 'settings' ) ) ); ?>"><?php esc_html_e( 'Why is AI not available?', 'tet-smart-alt-text' ); ?></a></p>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Which images', 'tet-smart-alt-text' ); ?></th>
					<td>
						<fieldset>
							<label><input type="radio" name="scope" value="missing" checked /> <?php esc_html_e( 'Only those without alt text', 'tet-smart-alt-text' ); ?></label><br />
							<label><input type="radio" name="scope" value="auto" /> <?php esc_html_e( 'Also replace automatic (template) alt texts, e.g. with AI ones', 'tet-smart-alt-text' ); ?></label><br />
							<label><input type="radio" name="scope" value="all" /> <?php esc_html_e( 'All images, including alt texts written by people', 'tet-smart-alt-text' ); ?></label>
						</fieldset>
						<p class="description"><?php esc_html_e( 'A replaced alt text is kept in the image\'s data, so it is never lost.', 'tet-smart-alt-text' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Preview', 'tet-smart-alt-text' ); ?></th>
					<td><label><input type="checkbox" name="dry_run" value="1" /> <?php esc_html_e( 'Only show what would be written, save nothing', 'tet-smart-alt-text' ); ?></label></td>
				</tr>
			</table>
			<p>
				<button type="submit" class="button button-primary" id="tet-alt-start"><?php esc_html_e( 'Start', 'tet-smart-alt-text' ); ?></button>
				<button type="button" class="button" id="tet-alt-stop" hidden><?php esc_html_e( 'Stop', 'tet-smart-alt-text' ); ?></button>
				<span id="tet-alt-progress-text" aria-live="polite"></span>
			</p>
			<progress id="tet-alt-progress" max="100" value="0" hidden></progress>
			<p class="description"><?php esc_html_e( 'Keep this page open while it runs. With AI, every image is one request to your provider.', 'tet-smart-alt-text' ); ?></p>
		</form>

		<table class="widefat striped tet-alt-results" id="tet-alt-results" hidden>
			<thead><tr>
				<th class="tet-alt-col-thumb"></th>
				<th><?php esc_html_e( 'Image', 'tet-smart-alt-text' ); ?></th>
				<th><?php esc_html_e( 'Alt text', 'tet-smart-alt-text' ); ?></th>
				<th><?php esc_html_e( 'Result', 'tet-smart-alt-text' ); ?></th>
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
		$where = '';
		if ( 'missing' === $scope ) {
			$where = "AND ( alt.meta_value IS NULL OR TRIM( alt.meta_value ) = '' )";
		} elseif ( 'auto' === $scope ) {
			$where = "AND ( alt.meta_value IS NULL OR TRIM( alt.meta_value ) = '' OR src.meta_value = 'template' )";
		}
		// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where is one of three fixed strings; run once per bulk start.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p
				LEFT JOIN {$wpdb->postmeta} alt ON alt.post_id = p.ID AND alt.meta_key = '_wp_attachment_image_alt'
				LEFT JOIN {$wpdb->postmeta} src ON src.post_id = p.ID AND src.meta_key = '_tet_alt_source'
				WHERE p.post_type = 'attachment' AND p.post_mime_type LIKE 'image/%%' {$where}
				ORDER BY p.ID DESC LIMIT %d",
				self::MAX_IDS
			)
		);
		// phpcs:enable
		return array_map( 'intval', (array) $ids );
	}

	/* ── AJAX ───────────────────────────────────────────────── */

	private static function check(): void {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'tet-smart-alt-text' ) ), 403 );
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
		$text  = '<p>' . esc_html__( 'Smart Alt Text writes descriptions (alt text) for the images in the media library, for visitors who use screen readers and for search engines.', 'tet-smart-alt-text' ) . '</p>';
		$text .= '<p>' . esc_html__( 'Without AI, it works only with data already on this site (file names and titles) and sends nothing anywhere.', 'tet-smart-alt-text' ) . '</p>';
		$text .= '<p>' . esc_html__( 'If AI descriptions are turned on, each image that gets a description is sent, with the site name and the title of the page or product it belongs to, to the AI provider the site owner set up in WordPress (Settings → Connectors). If an image shows people, that may be personal data; the provider\'s own privacy policy applies to what it receives. Visitors\' data is never sent.', 'tet-smart-alt-text' ) . '</p>';
		wp_add_privacy_policy_content( self::PLUGIN_TITLE, wp_kses_post( $text ) );
	}
}
