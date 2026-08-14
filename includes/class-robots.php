<?php
/**
 * Robots.txt manager.
 *
 * @package AISEOGodMode
 */

namespace AISEOGodMode;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Manages robots.txt rules, focusing on AI crawler access.
 */
class Robots {

    /**
     * Use the same capability catalogue as the dashboard access matrix.
     * Keeping a second hand-written bot list is how the management page fell
     * behind the dashboard and omitted Googlebot, Bingbot and Applebot.
     *
     * @return array<string,array>
     */
    private function ai_bots() {
        $bots = array();
        foreach ( Crawler_Access::definitions() as $ua => $def ) {
            $bots[ $ua ] = array(
                'name'       => $def['label'],
                'note'       => $def['note'],
                'group'      => $def['group'],
                'protected'  => ! empty( $def['protected'] ),
                'deprecated' => ! empty( $def['flags']['deprecated'] ),
                'default'    => 'allow',
            );
        }
        return $bots;
    }

    /**
     * Constructor — hooks into WordPress robots.txt filter.
     */
    public function __construct() {
        add_filter( 'robots_txt', array( $this, 'filter_robots_txt' ), 999, 2 );
    }

    /**
     * Get the current robots configuration.
     *
     * @return array
     */
    public function get_config() {
        $saved_rules  = get_option( 'asgm_robots_rules', array() );
        $current_txt  = $this->get_current_robots_txt();
        $other_plugin = $this->detect_other_robots_manager();
        $ai_bots      = $this->ai_bots();

        $bots = array();
        foreach ( $ai_bots as $ua => $info ) {
            $status = 'not_set';
            if ( isset( $saved_rules[ $ua ] ) ) {
                $status = $saved_rules[ $ua ];
            }

            $bots[ $ua ] = array(
                'name'    => $info['name'],
                'status'  => $status,
                'default' => $info['default'],
                'note'       => $info['note'],
                'group'      => $info['group'],
                'protected'  => $info['protected'],
                'deprecated' => $info['deprecated'],
            );
        }

        return array(
            'bots'                  => $bots,
            'groups'                => Crawler_Access::groups(),
            'current_robots_txt'    => $current_txt,
            'other_plugin_managing' => $other_plugin,
        );
    }

    /**
     * Save robots configuration.
     *
     * @param array $data Configuration data with bot statuses.
     * @return array
     */
    public function save_config( $data ) {
        $rules   = array();
        $ai_bots = $this->ai_bots();

        if ( isset( $data['bots'] ) && is_array( $data['bots'] ) ) {
            foreach ( $data['bots'] as $ua => $status ) {
                if ( ! isset( $ai_bots[ $ua ] ) || ! empty( $ai_bots[ $ua ]['protected'] ) ) {
                    continue;
                }
                // Only the three real states are storable. An unrecognised
                // value used to be saved verbatim, where the robots.txt writer
                // ignored it (writing no directive) while other readers treated
                // it as a decision. Silent disagreement between two parts of
                // the plugin about whether a crawler is blocked is exactly the
                // bug class worth refusing at the door.
                $status = sanitize_text_field( $status );
                if ( ! in_array( $status, array( 'allow', 'disallow', 'not_set' ), true ) ) {
                    continue;
                }
                if ( 'not_set' === $status ) {
                    continue; // absent means not set; do not store noise
                }
                $rules[ sanitize_text_field( $ua ) ] = $status;
            }
        }

        update_option( 'asgm_robots_rules', $rules );

        return array(
            'success' => true,
            'rules'   => $rules,
        );
    }

    /**
     * Filter the WordPress robots.txt output.
     *
     * @param string $output  Existing robots.txt content.
     * @param bool   $public  Whether the site is public.
     * @return string
     */
    public function filter_robots_txt( $output, $public ) {
        $settings = get_option( 'asgm_settings', array() );
        $modules  = isset( $settings['modules'] ) ? $settings['modules'] : array();

        // Only modify if the AI crawlers module is on.
        if ( empty( $modules['ai_crawlers'] ) ) {
            return $output;
        }

        $rules = get_option( 'asgm_robots_rules', array() );
        $bot_lines = array();
        $ai_bots = $this->ai_bots();

        foreach ( $ai_bots as $ua => $info ) {
            if ( ! empty( $info['protected'] ) ) {
                continue;
            }
            // Use the saved rule if it exists, otherwise treat as not_set.
            $status = isset( $rules[ $ua ] ) ? $rules[ $ua ] : 'not_set';

            if ( 'allow' === $status ) {
                $bot_lines[] = "User-agent: {$ua}";
                $bot_lines[] = "Allow: /";
                $bot_lines[] = "";
            } elseif ( 'disallow' === $status ) {
                $bot_lines[] = "User-agent: {$ua}";
                $bot_lines[] = "Disallow: /";
                $bot_lines[] = "";
            }
            // 'not_set' — write nothing, bot follows wildcard rules.
        }

        // Legacy passthrough. The catalogue of bots we RECOMMEND and the rules a
        // customer has deliberately SAVED are two different things. A bot
        // leaving the catalogue means "no longer offered for new
        // configuration", never "delete the directive they chose". The same
        // applies to a renamed agent: a rename is only a migration when the new
        // token has genuinely equivalent semantics, and until that is
        // established the original directive stays exactly as written.
        foreach ( $rules as $ua => $status ) {
            if ( isset( $ai_bots[ $ua ] ) ) {
                continue;
            }
            if ( 'disallow' === $status ) {
                $bot_lines[] = "User-agent: {$ua}";
                $bot_lines[] = "Disallow: /";
                $bot_lines[] = "";
            }
        }

        $lines = array();
        if ( ! empty( $bot_lines ) ) {
            $lines[] = "\n# AEO God Mode — AI Crawler Rules";
            $lines   = array_merge( $lines, $bot_lines );
        }

        // Sitemap discovery — auto-detect from active SEO plugin or use WP core.
        $sitemap_url = $this->detect_sitemap_url();
        $lines[] = "";
        $lines[] = "# AI-readable endpoints";
        $lines[] = "Sitemap: " . $sitemap_url;
        $lines[] = "# LLMs.txt: " . get_site_url() . "/llms.txt";

        return $output . implode( "\n", $lines ) . "\n";
    }

    /**
     * Get the current robots.txt content.
     *
     * @return string
     */
    private function get_current_robots_txt() {
        $url = get_site_url() . '/robots.txt';

        $response = wp_remote_get( $url, array( 'timeout' => 5 ) );
        if ( is_wp_error( $response ) ) {
            return '';
        }

        return wp_remote_retrieve_body( $response );
    }

    /**
     * Detect if another plugin is managing robots.txt.
     *
     * @return string|null Plugin name or null.
     */
    private function detect_other_robots_manager() {
        if ( ! function_exists( 'is_plugin_active' ) ) {
            include_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        if ( is_plugin_active( 'seo-by-rank-math/rank-math.php' ) ) {
            $general = get_option( 'rank-math-options-general', array() );
            if ( ! empty( $general['robots_txt_content'] ) ) {
                return 'Rank Math';
            }
        }

        if ( is_plugin_active( 'wordpress-seo/wp-seo.php' ) ) {
            return 'Yoast SEO';
        }

        return null;
    }

    /**
     * Detect the active sitemap URL from SEO plugins or WP core.
     *
     * @return string
     */
    private function detect_sitemap_url() {
        $site = get_site_url();

        if ( ! function_exists( 'is_plugin_active' ) ) {
            include_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        // Yoast SEO.
        if ( is_plugin_active( 'wordpress-seo/wp-seo.php' ) ) {
            return $site . '/sitemap_index.xml';
        }

        // Rank Math.
        if ( is_plugin_active( 'seo-by-rank-math/rank-math.php' ) ) {
            return $site . '/sitemap_index.xml';
        }

        // AIOSEO.
        if ( is_plugin_active( 'all-in-one-seo-pack/all_in_one_seo_pack.php' ) ) {
            return $site . '/sitemap.xml';
        }

        // SEOPress.
        if ( is_plugin_active( 'wp-seopress/seopress.php' ) ) {
            return $site . '/sitemaps.xml';
        }

        // WordPress core sitemap (default).
        return $site . '/wp-sitemap.xml';
    }
}
