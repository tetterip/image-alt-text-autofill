<?php
/**
 * Removes the plugin's settings and bookkeeping. The alt texts themselves stay: they are site content.
 *
 * @package Image_Alt_Text_Autofill
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'tet_alt_settings' );
delete_post_meta_by_key( '_tet_alt_source' );
delete_post_meta_by_key( '_tet_alt_previous' );
