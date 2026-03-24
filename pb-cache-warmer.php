<?php
/**
 * Plugin Name: Page Builder Cache Guard
 * Plugin URI:  https://github.com/avanrossum/pb-cache-warmer
 * Description: Prevents missing page-builder CSS (Divi, Elementor, Beaver Builder, Bricks, etc.) by warming the origin after cache purge events and optionally syncing Cloudflare's edge cache via the CF API.
 * Version:     1.0.0
 * Author:      Alex van Rossum
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: pb-cache-warmer
 * Domain Path: /languages
 */

defined( 'ABSPATH' ) || exit;

define( 'PBCW_VERSION',    '1.0.0' );
define( 'PBCW_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'PBCW_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once PBCW_PLUGIN_DIR . 'includes/class-cloudflare.php';
require_once PBCW_PLUGIN_DIR . 'includes/class-warmer.php';
require_once PBCW_PLUGIN_DIR . 'includes/class-scheduler.php';
require_once PBCW_PLUGIN_DIR . 'includes/class-hooks.php';
require_once PBCW_PLUGIN_DIR . 'includes/class-health-check.php';

if ( is_admin() ) {
    require_once PBCW_PLUGIN_DIR . 'includes/class-admin.php';
    new PBCW_Admin();
}

new PBCW_Health_Check();

new PBCW_Scheduler();
new PBCW_Hooks();

add_action( 'init', 'pbcw_load_textdomain' );
function pbcw_load_textdomain(): void {
    load_plugin_textdomain( 'pb-cache-warmer', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
}

register_activation_hook( __FILE__, [ 'PBCW_Scheduler', 'activate' ] );
register_deactivation_hook( __FILE__, [ 'PBCW_Scheduler', 'deactivate' ] );
