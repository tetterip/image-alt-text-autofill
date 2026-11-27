<?php
/**
 * Plugin Name:       Image Alt Text Autofill
 * Plugin URI:        https://github.com/tetterip/image-alt-text-autofill
 * Description:       Fills in missing image alt text from file names and titles, or with AI through the provider set up in WordPress.
 * Version:           0.1.0
 * Author:            Michalis Tetteris
 * Author URI:        https://ttrp.gr
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       image-alt-text-autofill
 * Domain Path:       /languages
 * Requires at least: 6.2
 * Requires PHP:      7.4
 *
 * @package Image_Alt_Text_Autofill
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
		load_plugin_textdomain( 'image-alt-text-autofill', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
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
			WP_CLI::add_command( 'alt-autofill', 'Tet_Alt_CLI' );
		}
	}
);
