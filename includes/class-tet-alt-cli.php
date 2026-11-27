<?php
/**
 * WP-CLI: wp smart-alt …
 *
 * @package Tet_Smart_Alt_Text
 */

defined( 'ABSPATH' ) || exit;

/**
 * Generates image alt text from the command line.
 */
class Tet_Alt_CLI {

	/**
	 * Generates alt text for images.
	 *
	 * ## OPTIONS
	 *
	 * [<id>...]
	 * : Attachment IDs. Without them, every image that matches --scope.
	 *
	 * [--source=<source>]
	 * : template or ai. Default: the plugin setting.
	 *
	 * [--scope=<scope>]
	 * : missing (default), auto (also replace template alt texts) or all.
	 *
	 * [--limit=<n>]
	 * : Process at most this many images.
	 *
	 * [--dry-run]
	 * : Show what would be written without saving.
	 *
	 * ## EXAMPLES
	 *
	 *     wp smart-alt generate --dry-run --limit=10
	 *     wp smart-alt generate --source=ai --scope=auto
	 *     wp smart-alt generate 123 456 --scope=all
	 *
	 * @param array $args       IDs.
	 * @param array $assoc_args Options.
	 */
	public function generate( $args, $assoc_args ) {
		$source = (string) \WP_CLI\Utils\get_flag_value( $assoc_args, 'source', '' );
		$scope  = (string) \WP_CLI\Utils\get_flag_value( $assoc_args, 'scope', 'missing' );
		$limit  = (int) \WP_CLI\Utils\get_flag_value( $assoc_args, 'limit', 0 );
		$dry    = (bool) \WP_CLI\Utils\get_flag_value( $assoc_args, 'dry-run', false );

		if ( ! in_array( $scope, array( 'missing', 'auto', 'all' ), true ) ) {
			WP_CLI::error( 'Unknown --scope. Use missing, auto or all.' );
		}
		if ( '' !== $source && ! in_array( $source, array( 'template', 'ai' ), true ) ) {
			WP_CLI::error( 'Unknown --source. Use template or ai.' );
		}
		if ( 'ai' === ( '' !== $source ? $source : Tet_Alt_Settings::get( 'source' ) ) && ! Tet_Alt_AI::available() ) {
			WP_CLI::warning( 'No AI provider that can read images is set up (Settings → Connectors).' . ( Tet_Alt_Settings::on( 'ai_fallback' ) ? ' Using the template.' : '' ) );
		}

		$ids = $args ? array_map( 'absint', $args ) : Tet_Alt_Admin::query_ids( $scope );
		if ( $limit > 0 ) {
			$ids = array_slice( $ids, 0, $limit );
		}
		if ( ! $ids ) {
			WP_CLI::success( 'No images match.' );
			return;
		}

		$counts   = array( 'saved' => 0, 'preview' => 0, 'skipped' => 0, 'error' => 0 );
		$progress = \WP_CLI\Utils\make_progress_bar( 'Generating alt text', count( $ids ) );
		$rows     = array();
		foreach ( $ids as $id ) {
			$r = Tet_Alt_Generator::apply( $id, $source, $scope, $dry );
			++$counts[ $r['status'] ];
			$rows[] = array(
				'id'     => $id,
				'status' => $r['status'],
				'source' => $r['source'],
				'alt'    => 'error' === $r['status'] ? $r['note'] : $r['alt'],
			);
			$progress->tick();
		}
		$progress->finish();
		\WP_CLI\Utils\format_items( 'table', $rows, array( 'id', 'status', 'source', 'alt' ) );
		WP_CLI::success( sprintf( '%d saved, %d preview, %d kept, %d errors.', $counts['saved'], $counts['preview'], $counts['skipped'], $counts['error'] ) );
	}

	/**
	 * Shows how many images have, lack or got automatic alt text.
	 *
	 * @param array $args       Unused.
	 * @param array $assoc_args Unused.
	 */
	public function stats( $args, $assoc_args ) {
		$c = Tet_Alt_Admin::counts();
		WP_CLI::line( sprintf( 'Images: %d, without alt: %d, template: %d, AI: %d. AI available: %s.', $c['total'], $c['missing'], $c['template'], $c['ai'], Tet_Alt_AI::available() ? 'yes' : 'no' ) );
	}
}
