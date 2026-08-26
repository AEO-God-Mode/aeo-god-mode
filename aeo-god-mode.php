<?php
/**
 * Plugin Name:       AEO God Mode – AI Search Visibility
 * Plugin URI:        https://aeogodmode.io
 * Description:       See and improve AI visibility with crawler controls, content audits, schema and llms.txt. Pro adds citation tracking and Search Console.
 * Version:           1.6.81
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
 *
 * Scope (see /docs/architecture-free-pro-split.md):
 *   - This is the Free plugin. Pro features live in the separate plugin
 *     `aeo-god-mode-pro`, source at `Plugins/aeo-god-mode-pro/`.
 *   - License activation code lives only in the Pro plugin. Free ships
 *     `includes/class-license-stub.php`, a no-op stub class.
 *   - `build.sh` and `tools/check-free-release.sh` scan this source on every
 *     build and every commit. Pro source or license code in this directory
 *     blocks the release.
 *   - See CONTRIBUTING.md for the dev workflow and RELEASE_RULES.md for the
 *     full release checklist.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Plugin constants.
define( 'ASGM_VERSION', '1.6.81' );
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

// Boot Free at plugins_loaded priority 10. Pro plugin (if installed) loads its
// real License class at priority 5 first; Free's stub then sees the class is
// already defined and skips. This is the load-order contract from the 2026-05-19
// Free/Pro plugin split. See /docs/architecture-free-pro-split.md.
add_action( 'plugins_loaded', 'asgm', 10 );

// Activation hook.
register_activation_hook( __FILE__, array( '\AISEOGodMode\Main', 'activate' ) );

// Deactivation hook.
register_deactivation_hook( __FILE__, array( '\AISEOGodMode\Main', 'deactivate' ) );
