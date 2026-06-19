<?php
/**
 * Main plugin controller.
 *
 * @package AISEOGodMode
 */

namespace AISEOGodMode;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Main class — singleton that boots all modules.
 */
class Main {

    /**
     * Plugin instance.
     *
     * @var Main|null
     */
    private static $instance = null;

    /**
     * Loaded module instances.
     *
     * @var array
     */
    private $modules = array();

    /**
     * Returns the singleton instance.
     *
     * @return Main
     */
    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor — hooks everything.
     */
    private function __construct() {
        $this->load_dependencies();
        $this->init_hooks();
    }

    /**
     * Load all class files.
     */
    private function load_dependencies() {
        $includes = ASGM_PLUGIN_DIR . 'includes/';

        // License stub. Class_exists-guarded so the Pro plugin's real License
        // class (loaded at plugins_loaded priority 5, before this method runs
        // at priority 10) takes precedence when both plugins are active.
        // When Pro is not installed, this stub is the only License class and
        // returns false for everything.
        require_once $includes . 'class-license-stub.php';

        // Free classes — always loaded.
        require_once $includes . 'class-api.php';
        require_once $includes . 'class-detector.php';
        require_once $includes . 'class-schema.php';
        require_once $includes . 'class-robots.php';
        require_once $includes . 'class-aeo.php';
        require_once $includes . 'class-ai-crawlers.php';
        require_once $includes . 'class-llms.php';
        require_once $includes . 'class-crawler-log.php';
        require_once $includes . 'class-content-gaps.php';
        require_once $includes . 'class-validator.php';
        require_once $includes . 'class-conflict.php';
        require_once $includes . 'class-ai-plugin.php';
        require_once $includes . 'class-ai-headers.php';
        require_once $includes . 'class-editor-panel.php';
        require_once $includes . 'class-metadata-writer.php';
        require_once $includes . 'class-metadata-generator.php';
        require_once $includes . 'class-answer-density.php';
        require_once $includes . 'class-bulk-meta.php';
        require_once $includes . 'class-okf.php';

        // Pro classes live in the separate `aeo-god-mode-pro` plugin and load
        // themselves on plugins_loaded priority 5/10. Free does not require any
        // Pro files. Free code paths that need Pro at runtime use class_exists()
        // checks (see init_hooks() in this class) so they no-op gracefully when
        // Pro is not installed.
    }

    /**
     * Initialise WordPress hooks.
     */
    private function init_hooks() {
        add_action( 'admin_menu', array( $this, 'register_admin_menu' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
        add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );

        // Defensive cache-busting: every REST response from this plugin
        // explicitly tells page caches (LiteSpeed, WP Rocket, W3TC, etc.)
        // never to cache. Activation also writes path-based excludes, but
        // this header is the floor in case a customer adds a new cache
        // plugin after activation.
        add_filter( 'rest_post_dispatch', array( $this, 'set_rest_no_cache_headers' ), 10, 3 );
        add_action( 'admin_init', array( $this, 'maybe_redirect_to_wizard' ) );
        add_action( 'admin_notices', array( $this, 'maybe_show_setup_notice' ) );
        add_action( 'admin_notices', array( $this, 'maybe_show_pro_migration_notice' ) );

        // Front-end hooks — schema, meta, crawler logging.
        add_action( 'wp_head', array( $this, 'render_frontend_output' ), 1 );
        add_action( 'init', array( $this, 'boot_modules' ) );

        // Bust the site-health transient whenever the inputs that feed it
        // change. Keeps the dashboard's Setup Details panel in sync after
        // robots rules edits, settings saves, scan runs, or conflict
        // resolutions, without waiting on the 5-minute cache.
        $invalidate = function () { delete_transient( 'asgm_site_health' ); };
        foreach ( array( 'asgm_settings', 'asgm_robots_rules', 'asgm_content_gap_results', 'asgm_schema_resolutions' ) as $opt ) {
            add_action( "update_option_{$opt}", $invalidate );
            add_action( "add_option_{$opt}", $invalidate );
        }

        // Schema conflict resolution: when user picks "ours" for a type, strip it from Rank Math / Yoast.
        // Deferred to plugins_loaded p20 so RANK_MATH_VERSION / WPSEO_VERSION are defined when we check.
        // (Plugin load order is alphabetical: aeo-god-mode loads before seo-by-rank-math.)
        add_action( 'plugins_loaded', array( $this, 'register_schema_override_filters' ), 20 );

        // GSC background batch inspection via WP Cron.
        add_action( 'asgm_gsc_batch_inspect', function () {
            if ( class_exists( 'AISEOGodMode\\GSC' ) ) {
                $gsc = new \AISEOGodMode\GSC();
                $gsc->batch_inspect();
            }
        } );

        // Local avatar upload — replaces Gravatar for users with uploaded photos.
        API::init_avatar_filters();

        // Ensure user profile form supports file uploads.
        add_action( 'user_edit_form_tag', function() {
            echo ' enctype="multipart/form-data"';
        } );

        // E-E-A-T user profile fields (Pro only).
        if ( License::is_pro() ) {
            add_action( 'show_user_profile', array( 'AISEOGodMode\EEAT', 'show_user_fields' ) );
            add_action( 'edit_user_profile', array( 'AISEOGodMode\EEAT', 'show_user_fields' ) );
            add_action( 'personal_options_update', array( 'AISEOGodMode\EEAT', 'save_user_fields' ) );
            add_action( 'edit_user_profile_update', array( 'AISEOGodMode\EEAT', 'save_user_fields' ) );
        }

        // Bulk Actions (Pro only)
        if ( License::is_pro() && class_exists( 'AISEOGodMode\BulkActions' ) ) {
            \AISEOGodMode\BulkActions::init();
        }

        // Plugin action links
        add_filter( 'plugin_action_links_' . ASGM_PLUGIN_BASENAME, array( $this, 'add_plugin_action_links' ) );
    }

    /**
     * Add links to the plugin listing on the Plugins page.
     *
     * @param array $links Existing links.
     * @return array Modified links.
     */
    public function add_plugin_action_links( $links ) {
        $setup_link = '<a href="' . esc_url( admin_url( 'admin.php?page=aeo-god-mode#/wizard' ) ) . '">' . __( 'Setup Wizard', 'aeo-god-mode' ) . '</a>';
        $docs_link  = '<a href="https://aeogodmode.io/docs/" target="_blank">' . __( 'Documentation', 'aeo-god-mode' ) . '</a>';
        array_unshift( $links, $setup_link, $docs_link );
        return $links;
    }




    /**
     * Redirect to the Setup Wizard on first activation.
     *
     * Runs once after `activate()` sets the transient. Silently does nothing
     * on bulk activations, AJAX, network admin, or when a user lacks the capability.
     */
    public function maybe_redirect_to_wizard() {
        if ( ! get_transient( 'asgm_activation_redirect' ) ) {
            return;
        }

        delete_transient( 'asgm_activation_redirect' );

        if ( wp_doing_ajax() || is_network_admin() ) {
            return;
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        wp_safe_redirect( admin_url( 'admin.php?page=aeo-god-mode#/wizard' ) );
        exit;
    }

    /**
     * Show a dismissible setup notice until the wizard is completed.
     *
     * Suppressed on the plugin's own screen (the React app shows its own wizard UI).
     */
    public function maybe_show_setup_notice() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $settings = get_option( 'asgm_settings', array() );
        if ( ! empty( $settings['wizard_completed'] ) ) {
            return;
        }

        // Don't double up on our own page.
        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        if ( $screen && false !== strpos( $screen->id, 'aeo-god-mode' ) ) {
            return;
        }

        $wizard_url = admin_url( 'admin.php?page=aeo-god-mode#/wizard' );
        echo '<div class="notice notice-info is-dismissible"><p><strong>' .
            esc_html__( 'AEO God Mode is ready to set up.', 'aeo-god-mode' ) .
            '</strong> ' .
            esc_html__( 'Run the 5-step Setup Wizard to configure AI crawlers, schema, and llms.txt for your site.', 'aeo-god-mode' ) .
            ' <a href="' . esc_url( $wizard_url ) . '" class="button button-primary" style="margin-left:8px;">' .
            esc_html__( 'Run Setup Wizard', 'aeo-god-mode' ) .
            '</a></p></div>';
    }

    /**
     * Register the admin menu page.
     */
    public function register_admin_menu() {
        add_menu_page(
            __( 'AEO God Mode', 'aeo-god-mode' ),
            __( 'AEO', 'aeo-god-mode' ),
            'manage_options',
            'aeo-god-mode',
            array( $this, 'render_admin_page' ),
            'dashicons-superhero-alt',
            81
        );
        
        // We do not add submenu pages here. The React App controls all sub-navigation via its own 
        // custom dark-mode sidebar UI. This prevents redundant, confusing sidebars in the WordPress admin.
    }

    /**
     * Render the React root container.
     */
    public function render_admin_page() {
        // Since we removed all submenus, the React router depends purely on hash routing.
        $react_route = '/';
        $this->react_route = $react_route;

        $wizard_url = esc_url( admin_url( 'admin.php?page=aeo-god-mode#/wizard' ) );

        // The inner loading state is replaced by React on mount.
        // It gives reviewers and users something meaningful on the initial paint
        // and provides a fallback link to the wizard if JS fails to run.
        ?>
        <style>
            /* Hide the default WP admin footer strings on our full-screen page — they overlap our sidebar footer. */
            #wpfooter { display: none !important; }
            #wpcontent, #wpbody-content { padding-bottom: 0 !important; margin-bottom: 0 !important; }
            .update-nag, .notice-warning.update-nag { display: none; }
        </style>
        <div id="asgm-root" data-route="<?php echo esc_attr( $react_route ); ?>">
            <div class="asgm-boot" style="min-height:60vh;display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;padding:40px 20px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;">
                <div style="width:48px;height:48px;border:3px solid #e5e7eb;border-top-color:#2563eb;border-radius:50%;animation:asgm-spin 0.9s linear infinite;margin-bottom:20px;"></div>
                <h2 style="margin:0 0 8px;font-size:18px;color:#1f2937;"><?php esc_html_e( 'Loading AEO God Mode…', 'aeo-god-mode' ); ?></h2>
                <p style="margin:0 0 16px;color:#6b7280;font-size:14px;max-width:480px;"><?php esc_html_e( 'Preparing your dashboard. This should only take a second.', 'aeo-god-mode' ); ?></p>
                <noscript>
                    <div style="margin-top:16px;padding:14px 18px;background:#fef3c7;border:1px solid #fbbf24;border-radius:6px;color:#78350f;max-width:520px;">
                        <strong><?php esc_html_e( 'JavaScript is required.', 'aeo-god-mode' ); ?></strong><br>
                        <?php esc_html_e( 'AEO God Mode uses an interactive dashboard. Please enable JavaScript in your browser.', 'aeo-god-mode' ); ?>
                    </div>
                </noscript>
                <p style="margin-top:24px;font-size:13px;">
                    <a href="<?php echo $wizard_url; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already escaped above. ?>"><?php esc_html_e( 'Go to Setup Wizard', 'aeo-god-mode' ); ?></a>
                </p>
            </div>
            <style>@keyframes asgm-spin{to{transform:rotate(360deg);}}</style>
        </div>
        <?php
    }

    /**
     * Enqueue admin assets (React app).
     *
     * @param string $hook The current admin page hook.
     */
    public function enqueue_admin_assets( $hook ) {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Menu page routing, no data mutation.
        $current_page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';
        if ( 'aeo-god-mode' !== $current_page ) {
            return;
        }

        // Hide the default WordPress admin notices on our page.
        remove_all_actions( 'admin_notices' );

        $asset_url = ASGM_PLUGIN_URL . 'assets/admin/';
        $asset_dir = ASGM_PLUGIN_DIR . 'assets/admin/';

        // Vite manifest.
        $manifest_path = $asset_dir . '.vite/manifest.json';
        if ( ! file_exists( $manifest_path ) ) {
            // Dev mode fallback — Vite dev server.
            wp_enqueue_script(
                'asgm-vite-client',
                'http://localhost:5173/@vite/client',
                array(),
                null, // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
                true
            );
            wp_enqueue_script(
                'asgm-admin-app',
                'http://localhost:5173/src/main.tsx',
                array(),
                null, // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
                true
            );
            return;
        }

        $manifest = json_decode( file_get_contents( $manifest_path ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

        if ( isset( $manifest['src/main.tsx'] ) ) {
            $entry = $manifest['src/main.tsx'];

            // CSS.
            if ( ! empty( $entry['css'] ) ) {
                foreach ( $entry['css'] as $index => $css_file ) {
                    wp_enqueue_style(
                        'asgm-admin-css-' . $index,
                        $asset_url . $css_file,
                        array(),
                        ASGM_VERSION
                    );
                }
            }

            // JS.
            wp_enqueue_script(
                'asgm-admin-app',
                $asset_url . $entry['file'],
                array(),
                ASGM_VERSION,
                true
            );

            // Load the admin bundle as type="module" so its top-level
            // declarations are module-scoped. Without this, the Vite bundle's
            // `var _ = ...` (React alias after minification) leaks to window._
            // and collides with Underscore.js / Lodash loaded by themes like
            // Salient or builders like WPBakery, producing
            // `TypeError: _.useContext is not a function` on the admin page.
            // The bundle is already self-contained (no runtime ES imports),
            // so module scoping is purely additive.
            add_filter( 'script_loader_tag', array( $this, 'admin_app_as_module' ), 10, 3 );

            // Pass data to the React app.
            $license = new License();
            wp_localize_script( 'asgm-admin-app', 'asgmData', array(
                'restUrl'    => esc_url_raw( rest_url( 'aeo-god-mode/v1' ) ),
                'nonce'      => wp_create_nonce( 'wp_rest' ),
                'adminUrl'   => admin_url(),
                'pluginUrl'  => ASGM_PLUGIN_URL,
                'version'    => ASGM_VERSION,
                'siteUrl'    => get_site_url(),
                'siteName'   => get_bloginfo( 'name' ),
                'isPro'      => License::is_pro(),
                'isProBuild' => License::is_pro_build(),
                'plan'       => $license->get_plan(),
                'pricingUrl' => 'https://aeogodmode.io/pricing',
            ) );
        }
    }

    /**
     * Add type="module" to the admin React bundle <script> tag so its
     * top-level `var` declarations stay module-scoped and don't collide
     * with globals (notably window._ from Underscore.js / Lodash).
     *
     * @param string $tag    The full <script> tag HTML.
     * @param string $handle Script handle being filtered.
     * @param string $src    Script src attribute.
     * @return string
     */
    public function admin_app_as_module( $tag, $handle, $src ) {
        if ( 'asgm-admin-app' !== $handle ) {
            return $tag;
        }
        // Idempotent — bail if some other plugin already injected a type attr.
        if ( false !== strpos( $tag, ' type=' ) ) {
            return $tag;
        }
        return str_replace( '<script ', '<script type="module" ', $tag );
    }

    /**
     * Register all REST API routes.
     */
    public function register_rest_routes() {
        $api = new API();
        $api->register_routes();
    }

    /**
     * Boot all feature modules.
     */
    public function boot_modules() {
        $settings = get_option( 'asgm_settings', array() );
        $safe_mode = ! empty( $settings['safe_mode'] );

        if ( $safe_mode ) {
            return;
        }

        // Free modules — always boot.
        Answer_Density::init();
        $this->modules['detector']     = new Detector();
        $this->modules['schema']       = new Schema();
        $this->modules['robots']       = new Robots();
        $this->modules['aeo']          = new AEO();
        $this->modules['ai_crawlers']  = new AICrawlers();
        $this->modules['llms']         = new LLMS();
        $this->modules['crawler_log']  = new CrawlerLog();
        $this->modules['content_gaps'] = new ContentGaps();
        $this->modules['validator']    = new Validator();
        $this->modules['conflict']     = new Conflict();
        $this->modules['ai_plugin']    = new AIPlugin();
        $this->modules['ai_headers']   = new AIHeaders();
    $this->modules['editor_panel'] = new EditorPanel();
    $this->modules['bulk_meta']    = new BulkMeta();
    $this->modules['okf']          = new OKF();

        // Pro modules — only boot with an active license AND class exists.
        // class_exists() guards protect against Free builds where pro/ is stripped
        // but the user has entered a Pro license key.
        if ( License::is_pro() ) {
            if ( class_exists( __NAMESPACE__ . '\\CitationTracker' ) ) {
                $this->modules['citation_tracker'] = new CitationTracker();
            }
            if ( class_exists( __NAMESPACE__ . '\\AIReferrals' ) ) {
                $this->modules['ai_referrals'] = new AIReferrals();
            }
            if ( class_exists( __NAMESPACE__ . '\\CitabilityScore' ) ) {
                $this->modules['citability'] = new CitabilityScore();
            }
            if ( class_exists( __NAMESPACE__ . '\\EEAT' ) ) {
                $this->modules['eeat'] = new EEAT();
            }
            if ( class_exists( __NAMESPACE__ . '\\GSC' ) ) {
                $this->modules['gsc'] = new GSC();
            }
        }
    }

    /**
     * Render front-end output (schema, AEO meta).
     */
    public function render_frontend_output() {
        $settings = get_option( 'asgm_settings', array() );

        if ( ! empty( $settings['safe_mode'] ) ) {
            return;
        }

        if ( isset( $this->modules['schema'] ) ) {
            $this->modules['schema']->render();
        }

        if ( isset( $this->modules['aeo'] ) ) {
            $this->modules['aeo']->render();
        }

        // Render native ASGM meta tags when no SEO plugin handles them.
        MetadataWriter::render_native_meta();
    }

    /**
     * Get a loaded module instance.
     *
     * @param string $key Module key.
     * @return object|null
     */
    public function get_module( $key ) {
        return isset( $this->modules[ $key ] ) ? $this->modules[ $key ] : null;
    }

    /**
     * Plugin activation.
     */
    public static function activate() {
        // Flag a redirect to the setup wizard on first activation.
        // Suppressed during bulk activation of multiple plugins.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading WP core $_GET to detect bulk action, no data mutation.
        if ( ! isset( $_GET['activate-multi'] ) ) {
            set_transient( 'asgm_activation_redirect', 1, 30 );
        }

        // Set default settings.
        if ( false === get_option( 'asgm_settings' ) ) {
            $defaults = array(
                'safe_mode'        => false,
                'wizard_completed' => false,
                'modules'          => array(
                    'ai_crawlers'    => true,
                    'llms_txt'       => true,
                    'schema'         => true,
                    'aeo'            => true,
                    'local_business' => false,
                    'robots'         => false,
                    'basic_meta'     => false,
                    'crawler_log'    => true,
                    'content_gaps'   => true,
                    'validator'      => true,
                    'gsc'            => false,
                ),
                'business'         => array(
                    'name'     => get_bloginfo( 'name' ),
                    'url'      => get_site_url(),
                    'type'     => 'Blog',
                    'location' => '',
                    'social'   => array(
                        'twitter'   => '',
                        'linkedin'  => '',
                        'facebook'  => '',
                        'instagram' => '',
                    ),
                ),
            );
            update_option( 'asgm_settings', $defaults );
        }

        // Create database tables.
        self::create_tables();

        // Pro tables — only if Pro classes are loaded.
        if ( class_exists( 'AISEOGodMode\\AIReferrals' ) ) {
            AIReferrals::create_table();
        }
        if ( class_exists( 'AISEOGodMode\\Section_Index' ) ) {
            Section_Index::create_table();
        }

        // Flush rewrite rules for custom endpoints.
        add_rewrite_rule( '^llms\.txt$', 'index.php?asgm_llms=1', 'top' );
        add_rewrite_rule( '^okf\.zip$', 'index.php?asgm_okf=__zip', 'top' );
        add_rewrite_rule( '^okf/?$', 'index.php?asgm_okf=index.md', 'top' );
        add_rewrite_rule( '^okf/(.+?)/?$', 'index.php?asgm_okf=$matches[1]', 'top' );
        flush_rewrite_rules();

        // Prime llms.txt on activation so Site Health doesn't incorrectly report
        // "not generated" before the first front-end request hits the URL.
        if ( class_exists( 'AISEOGodMode\\LLMS' ) ) {
            $llms = new LLMS();
            $llms->regenerate();
        }
    }

    /**
     * Create custom database tables.
     */
    private static function create_tables() {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();
        $table_name      = $wpdb->prefix . 'asgm_crawler_log';

        $sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            bot_name varchar(100) NOT NULL DEFAULT '',
            user_agent varchar(500) NOT NULL DEFAULT '',
            url varchar(2083) NOT NULL DEFAULT '',
            response_code smallint(5) unsigned NOT NULL DEFAULT 200,
            ip_address varchar(45) NOT NULL DEFAULT '',
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY bot_name (bot_name),
            KEY created_at (created_at),
            KEY url (url(191))
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );

        // Tell page caches (LiteSpeed et al.) never to cache our REST endpoints.
        // Without this, an aggressive cache can store a 404 from the brief window
        // between plugin install and route registration, then keep serving the
        // stale 404 for hours. The React admin app then sits in "Loading..."
        // forever because /status returns a cached 404.
        self::configure_cache_exclusions();
    }

    /**
     * Add /wp-json/aeo-god-mode/ and /wp-json/asgm/ to known page-cache exclusion
     * lists so REST API responses never get cached. Runs on activation and is
     * idempotent. Safe no-op on sites without a recognised cache plugin.
     */
    private static function configure_cache_exclusions() {
        $excludes_to_add = array(
            '/wp-json/aeo-god-mode/',
            '/wp-json/asgm/',
        );

        // LiteSpeed Cache: cache-exc option holds a newline-separated list.
        $ls_key = 'litespeed.conf.cache-exc';
        $current = get_option( $ls_key );
        if ( $current !== false ) {
            $items = is_array( $current )
                ? $current
                : array_filter( array_map( 'trim', preg_split( '/[\r\n]+/', (string) $current ) ) );
            $changed = false;
            foreach ( $excludes_to_add as $path ) {
                if ( ! in_array( $path, $items, true ) ) {
                    $items[]  = $path;
                    $changed = true;
                }
            }
            if ( $changed ) {
                update_option( $ls_key, implode( "\n", $items ) );
            }
        }

        // WP Rocket: cache_reject_uri option also accepts a list.
        $rocket = get_option( 'wp_rocket_settings' );
        if ( is_array( $rocket ) && isset( $rocket['cache_reject_uri'] ) ) {
            $list = is_array( $rocket['cache_reject_uri'] )
                ? $rocket['cache_reject_uri']
                : array_filter( array_map( 'trim', preg_split( '/[\r\n]+/', (string) $rocket['cache_reject_uri'] ) ) );
            $changed = false;
            foreach ( $excludes_to_add as $path ) {
                if ( ! in_array( $path, $list, true ) ) {
                    $list[]  = $path;
                    $changed = true;
                }
            }
            if ( $changed ) {
                $rocket['cache_reject_uri'] = $list;
                update_option( 'wp_rocket_settings', $rocket );
            }
        }

        // Purge whatever cache is active so any pre-existing stale 404s clear immediately.
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- This is LiteSpeed Cache's own action; we are calling their hook so they purge their cache. Not a hook owned by this plugin.
        do_action( 'litespeed_purge_all' );
        if ( function_exists( 'rocket_clean_domain' ) ) {
            rocket_clean_domain();
        }
        if ( function_exists( 'w3tc_pgcache_flush' ) ) {
            w3tc_pgcache_flush();
        }
        if ( function_exists( 'wp_cache_clear_cache' ) ) {
            wp_cache_clear_cache();
        }
        if ( function_exists( 'wp_cache_flush' ) ) {
            wp_cache_flush();
        }
    }

    /**
     * Plugin deactivation.
     */
    public static function deactivate() {
        flush_rewrite_rules();
    }

    /**
     * Force-disable page-cache storage for every REST response under our
     * plugin's namespaces. Runs on `rest_post_dispatch` so headers land on
     * BOTH successful responses and error responses (404 rest_no_route,
     * 401/403 rest_forbidden, etc.). Without this, a cache plugin can
     * store an early 404 and serve it back for hours, breaking the React
     * admin UI's init flow.
     *
     * @param \WP_REST_Response $response The response object.
     * @param \WP_REST_Server   $server   The REST server.
     * @param \WP_REST_Request  $request  The request object.
     * @return \WP_REST_Response
     */
    public function set_rest_no_cache_headers( $response, $server, $request ) {
        unset( $server );
        if ( ! is_a( $response, '\WP_REST_Response' ) ) {
            return $response;
        }

        // Detect our plugin's namespaces from either the matched route
        // (success path) or the raw request URL (so rest_no_route 404s
        // for our namespace still get the header — that's the case that
        // actually caused the LSCache-stale-404 incident on 2026-05-20).
        $matched_route = is_a( $request, '\WP_REST_Request' ) ? (string) $request->get_route() : '';
        $raw_path      = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';

        $is_ours = (
            strpos( $matched_route, '/aeo-god-mode/' ) === 0
            || strpos( $matched_route, '/asgm/' ) === 0
            || strpos( $raw_path, '/wp-json/aeo-god-mode/' ) !== false
            || strpos( $raw_path, '/wp-json/asgm/' ) !== false
            || strpos( $raw_path, 'rest_route=/aeo-god-mode/' ) !== false
            || strpos( $raw_path, 'rest_route=/asgm/' ) !== false
        );

        if ( $is_ours ) {
            $response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0', true );
            $response->header( 'Pragma', 'no-cache', true );
            // LiteSpeed reads this header and skips caching.
            $response->header( 'X-LiteSpeed-Cache-Control', 'no-cache', true );
            // WP Rocket / W3TC respect this too.
            $response->header( 'X-Accel-Expires', '0', true );
        }
        return $response;
    }
    /**
     * Register filters that remove specific schema types from Rank Math / Yoast output
     * when the user has chosen "ours" for that schema type in conflict resolution.
     */
    public function register_schema_override_filters() {
        // Helper: should we strip this @type from a third-party graph based on
        // the user's per-type resolution choice? Centralised here so the
        // Rank Math + Yoast filters stay consistent and test the same logic.
        $should_strip = function ( $type, $resolutions ) {
            if ( is_array( $type ) ) { $type = $type[0]; }
            // Canonicalise: Article and BlogPosting share one resolution row.
            $mapped = ( 'BlogPosting' === $type ) ? 'Article' : $type;
            return isset( $resolutions[ $mapped ] ) && 'ours' === $resolutions[ $mapped ];
        };

        // Rank Math: the data array of @graph nodes is finalised right
        // before output via `rank_math/schema/validated_data`. The earlier
        // `rank_math/json_ld` filter fires with an empty array because
        // Rank Math's snippet generators don't hook into it; they run
        // synchronously and aggregate inline. Hooking the wrong filter is
        // why Organization/etc. survived even when the user picked
        // "AEO God Mode" in the Conflicts UI. We hook the correct filter
        // first, and keep the legacy `rank_math/json_ld` hook as a no-op
        // safety net for any third-party that does populate it.
        if ( defined( 'RANK_MATH_VERSION' ) || class_exists( '\RankMath\Paper\Paper' ) ) {
            add_filter( 'rank_math/schema/validated_data', function ( $data ) use ( $should_strip ) {
                $resolutions = get_option( 'asgm_schema_resolutions', array() );
                if ( empty( $resolutions ) || ! is_array( $data ) ) {
                    return $data;
                }
                foreach ( $data as $key => $node ) {
                    if ( ! is_array( $node ) || ! isset( $node['@type'] ) ) { continue; }
                    if ( $should_strip( $node['@type'], $resolutions ) ) {
                        unset( $data[ $key ] );
                    }
                }
                return $data;
            }, 99 );

            // Legacy Rank Math + safety net for future versions.
            add_filter( 'rank_math/json_ld', function ( $data, $jsonld ) use ( $should_strip ) {
                $resolutions = get_option( 'asgm_schema_resolutions', array() );
                if ( empty( $resolutions ) ) {
                    return $data;
                }

                // Case A: nested @graph (current Rank Math default).
                if ( is_array( $data ) && isset( $data['@graph'] ) && is_array( $data['@graph'] ) ) {
                    $data['@graph'] = array_values( array_filter( $data['@graph'], function ( $node ) use ( $should_strip, $resolutions ) {
                        if ( ! is_array( $node ) || ! isset( $node['@type'] ) ) { return true; }
                        return ! $should_strip( $node['@type'], $resolutions );
                    } ) );
                    return $data;
                }

                // Case B: flat array of nodes keyed by type/slug.
                foreach ( $data as $key => $node ) {
                    if ( ! is_array( $node ) || ! isset( $node['@type'] ) ) { continue; }
                    if ( $should_strip( $node['@type'], $resolutions ) ) {
                        unset( $data[ $key ] );
                    }
                }
                return array_values( $data );
            }, 99, 2 );
        }

        // Yoast: filter the schema graph array.
        if ( defined( 'WPSEO_VERSION' ) ) {
            add_filter( 'wpseo_schema_graph', function ( $graph ) use ( $should_strip ) {
                $resolutions = get_option( 'asgm_schema_resolutions', array() );
                if ( empty( $resolutions ) ) {
                    return $graph;
                }
                return array_values( array_filter( $graph, function ( $piece ) use ( $should_strip, $resolutions ) {
                    if ( ! isset( $piece['@type'] ) ) { return true; }
                    return ! $should_strip( $piece['@type'], $resolutions );
                } ) );
            }, 99 );
        }
    }

    /**
     * Show a migration notice to legacy Pro customers who have not yet
     * installed the dedicated Pro plugin (slug `aeo-god-mode-pro`).
     *
     * Fires when:
     *   - The current user can activate plugins.
     *   - A Pro license key is stored from a previous install.
     *   - The new Pro plugin is NOT active.
     *
     * Customers in this state had Pro running inside the legacy mixed plugin
     * folder before the 2026-05-19 split. After the next WordPress.org Free
     * update, the legacy /includes/pro/ folder gets removed and Pro features
     * stop working unless they install the new Pro plugin first.
     */
    public function maybe_show_pro_migration_notice() {
        if ( ! current_user_can( 'activate_plugins' ) ) {
            return;
        }

        // Only shown to legacy Pro customers (those with a stored key).
        if ( ! get_option( 'agm_license_key', '' ) ) {
            return;
        }

        // If the new Pro plugin is active, nothing to do.
        if ( ! function_exists( 'is_plugin_active' ) ) {
            include_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        if ( function_exists( 'is_plugin_active' ) && is_plugin_active( 'aeo-god-mode-pro/aeo-god-mode-pro.php' ) ) {
            return;
        }

        $download_url = 'https://aeogodmode.io/my-account/';
        echo '<div class="notice notice-warning"><p><strong>'
            . esc_html__( 'Action needed: AEO God Mode Pro is now a separate plugin.', 'aeo-god-mode' )
            . '</strong><br>'
            . esc_html__( 'We have split Pro into its own plugin so future WordPress.org updates can never overwrite it. Your license stays valid; you just need to install the new Pro plugin alongside Free.', 'aeo-god-mode' )
            . '<br><a href="' . esc_url( $download_url ) . '" class="button button-primary" style="margin-top:8px;">'
            . esc_html__( 'Get the new Pro plugin', 'aeo-god-mode' )
            . '</a></p></div>';
    }
}
