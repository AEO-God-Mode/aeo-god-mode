<?php
/**
 * Plugin Name:       AEO God Mode: Answer Engine Optimization, GEO, AIO and LLM SEO
 * Plugin URI:        https://aeogodmode.io
 * Description:       A comprehensive plugin for Answer Engine Optimization. Manage AI crawlers, citation tracking, schema, and llms.txt in one place.
 * Version:           1.6.6
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            Metronyx AI SEO
 * Author URI:        https://metronyxai.com/
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       aeo-god-mode
 * Domain Path:       /languages
 *
 * @package AISEOGodMode
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Plugin constants.
define( 'ASGM_VERSION', '1.6.6' );
define( 'ASGM_PLUGIN_FILE', __FILE__ );
define( 'ASGM_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'ASGM_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'ASGM_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

// Autoload classes.
require_once ASGM_PLUGIN_DIR . 'includes/class-main.php';

/**
 * Returns the main plugin instance.
 *
 * @return \AISEOGodMode\Main
 */
function asgm() {
    return \AISEOGodMode\Main::get_instance();
}

/**
 * Global helper — check if Pro license is active.
 *
 * @return bool
 */
function asgm_is_pro_active() {
    return \AISEOGodMode\License::is_pro();
}

// Boot the plugin.
asgm();

// Pro plugin updates via EDD Software Licensing.
if ( asgm_is_pro_active() && file_exists( ASGM_PLUGIN_DIR . 'includes/class-updater.php' ) ) {
    require_once ASGM_PLUGIN_DIR . 'includes/class-updater.php';
    new ASGM_Plugin_Updater(
        'https://aeogodmode.io',
        __FILE__,
        array(
            'version'   => ASGM_VERSION,
            'license'   => get_option( 'agm_license_key' ),
            'item_name' => 'AEO God Mode Pro',
            'author'    => 'Metronyx AI SEO',
            'url'       => home_url(),
        )
    );
}

// Activation hook.
register_activation_hook( __FILE__, array( '\AISEOGodMode\Main', 'activate' ) );

// Deactivation hook.
register_deactivation_hook( __FILE__, array( '\AISEOGodMode\Main', 'deactivate' ) );
