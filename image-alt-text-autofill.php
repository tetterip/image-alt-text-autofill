<?php
/**
 * Plugin Name:       Image Alt Text Autofill
 * Plugin URI:        https://github.com/tetterip/image-alt-text-autofill
 * Description:       Fills in missing image alt text from file names and titles, or with AI through the provider set up in WordPress.
 * Version:           1.0.0
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

/**
 * Version string for an asset: plugin version plus the file's modification time, so browsers fetch the new
 * file after an update even when the plugin version stayed the same (e.g. a reinstalled zip).
 *
 * @param string $rel Path relative to the plugin folder.
 */
function tet_alt_asset_ver( string $rel ): string {
	$file = TET_ALT_PATH . $rel;
	$time = file_exists( $file ) ? filemtime( $file ) : false;
	return TET_ALT_VERSION . ( $time ? '.' . $time : '' );
}

require_once TET_ALT_PATH . 'includes/class-tet-alt-logic.php';
require_once TET_ALT_PATH . 'includes/class-tet-alt-settings.php';
require_once TET_ALT_PATH . 'includes/class-tet-alt-ai.php';
require_once TET_ALT_PATH . 'includes/class-tet-alt-generator.php';
require_once TET_ALT_PATH . 'includes/class-tet-alt-media.php';
require_once TET_ALT_PATH . 'includes/class-tet-alt-admin.php';

// Translations: WordPress loads them by itself (WordPress.org language packs; since 6.8 also the bundled
// languages/ folder through the Domain Path header), so there is no load_plugin_textdomain() call.

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
