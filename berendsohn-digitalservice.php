<?php
/**
 * Plugin Name:       Berendsohn Digital Service
 * Plugin URI:        https://berendsohn-digitalservice.de
 * Description:       Übergeordnete Funktionen/Anpassungen für Berendsohn-Webseiten (Shortcodes, Login-Maske, Rollen, Design).
 * Version:           1.1.1
 * Author:            Berendsohn
 * Author URI:        https://berendsohn-digitalservice.de
 * Text Domain:       berendsohn-digitalservice
 * Domain Path:       /languages
 * Update URI:        https://github.com/freiraum-kd/berendsohn-digitalservice
 */
//Test Alex vom 
if ( ! defined( 'ABSPATH' ) ) exit;

define( 'BDS_VERSION', '1.1.1' );
define( 'BDS_FILE', __FILE__ );
define( 'BDS_PATH', plugin_dir_path( __FILE__ ) );
define( 'BDS_URL', plugin_dir_url( __FILE__ ) );

// Load updater safely (never fatal if file missing)
$__bds_updater = BDS_PATH . 'includes/updater.php';
if ( file_exists($__bds_updater) ) {
    require_once $__bds_updater;
}

require_once BDS_PATH . 'includes/helpers.php';
require_once BDS_PATH . 'includes/class-shortcodes.php';
require_once BDS_PATH . 'includes/class-login-mask.php';
require_once BDS_PATH . 'includes/class-admin-ui.php';

add_action( 'plugins_loaded', function() {
    load_plugin_textdomain( 'berendsohn-digitalservice', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
    \BDS\Shortcodes::init();
    \BDS\Login_Mask::init();
    \BDS\Admin_UI::init();
} );

register_activation_hook( BDS_FILE, function () {
    \BDS\Login_Mask::add_rewrite();
    flush_rewrite_rules();
});
register_deactivation_hook( BDS_FILE, function () {
    flush_rewrite_rules();
});
