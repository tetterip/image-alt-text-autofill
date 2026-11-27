<?php
/**
 * Plugin Name:       Smart Alt Text
 * Plugin URI:        https://github.com/tetterip/tet-smart-alt-text
 * Description:       Writes missing image alt text automatically: from the file name and titles, or with AI through the AI provider set up in WordPress. Bulk tool for existing images.
 * Version:           0.1.0
 * Author:            Michalis Tetteris
 * Author URI:        https://ttrp.gr
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Update URI:        https://plugins.ttrp.gr/
 * Text Domain:       tet-smart-alt-text
 * Domain Path:       /languages
 * Requires at least: 6.2
 * Tested up to:      7.1
 * Requires PHP:      7.4
 *
 * @package Tet_Smart_Alt_Text
 */

defined( 'ABSPATH' ) || exit;

define( 'TET_ALT_FILE', __FILE__ );
define( 'TET_ALT_VERSION', get_file_data( __FILE__, array( 'Version' => 'Version' ) )['Version'] );
define( 'TET_ALT_PATH', plugin_dir_path( __FILE__ ) );
define( 'TET_ALT_URL', plugin_dir_url( __FILE__ ) );

require_once TET_ALT_PATH . 'includes/class-tet-alt-logic.php';
require_once TET_ALT_PATH . 'includes/class-tet-alt-settings.php';
require_once TET_ALT_PATH . 'includes/class-tet-alt-ai.php';
require_once TET_ALT_PATH . 'includes/class-tet-alt-generator.php';
require_once TET_ALT_PATH . 'includes/class-tet-alt-media.php';
require_once TET_ALT_PATH . 'includes/class-tet-alt-admin.php';

// Load translations on init (avoids the WP 6.7+ just-in-time loading notice).
add_action(
	'init',
	function () {
		load_plugin_textdomain( 'tet-smart-alt-text', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
	}
);

// WooCommerce: works without it; declare HPOS compatibility so WooCommerce doesn't warn (no order data is touched).
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);

add_action(
	'plugins_loaded',
	function () {
		Tet_Alt_Settings::init();
		Tet_Alt_Generator::init();
		Tet_Alt_Media::init();
		if ( is_admin() ) {
			Tet_Alt_Admin::init();
		}
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			require_once TET_ALT_PATH . 'includes/class-tet-alt-cli.php';
			WP_CLI::add_command( 'smart-alt', 'Tet_Alt_CLI' );
		}
	}
);

// Updates + "All Plugins" page (shared ttrp-common library; left out of WordPress.org builds).
if ( file_exists( TET_ALT_PATH . 'ttrp-common/loader.php' ) ) {
	require_once TET_ALT_PATH . 'ttrp-common/loader.php';
	ttrp_common_register( __FILE__, 'tet-smart-alt-text' );
}
