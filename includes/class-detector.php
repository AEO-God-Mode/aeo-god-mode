<?php
/**
 * SEO plugin detector and settings importer.
 *
 * @package AISEOGodMode
 */

namespace AISEOGodMode;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Detects Yoast, Rank Math, and other SEO plugins. Imports their settings.
 */
class Detector {

    /**
     * Known SEO plugins to scan for.
     *
     * @var array
     */
    private $known_plugins = array(
        'rank_math' => array(
            'file'  => 'seo-by-rank-math/rank-math.php',
            'name'  => 'Rank Math',
            'class' => 'RankMath',
        ),
        'yoast'     => array(
            'file'  => 'wordpress-seo/wp-seo.php',
            'name'  => 'Yoast SEO',
            'class' => 'WPSEO_Options',
        ),
        'aioseo'    => array(
            'file'  => 'all-in-one-seo-pack/all_in_one_seo_pack.php',
            'name'  => 'All in One SEO',
            'class' => 'AIOSEO',
        ),
        'seopress'  => array(
            'file'  => 'wp-seopress/seopress.php',
            'name'  => 'SEOPress',
            'class' => 'FLAVflavor',
        ),
    );

    /**
     * Scan for installed and active SEO plugins.
     *
     * @return array Detection results.
     */
    public function scan() {
        if ( ! function_exists( 'is_plugin_active' ) ) {
            include_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $detected = array();
        $primary  = null;

        foreach ( $this->known_plugins as $key => $plugin ) {
            $is_active = is_plugin_active( $plugin['file'] );

            $detected[ $key ] = array(
                'name'      => $plugin['name'],
                'active'    => $is_active,
                'installed' => file_exists( WP_PLUGIN_DIR . '/' . $plugin['file'] ),
            );

            if ( $is_active && null === $primary ) {
                $primary = $key;
            }
        }

        $conflict = false;
        $active_count = 0;
        foreach ( $detected as $info ) {
            if ( $info['active'] ) {
                $active_count++;
            }
        }
        if ( $active_count > 1 ) {
            $conflict = true;
        }

        return array(
            'plugins'  => $detected,
            'primary'  => $primary,
            'conflict' => $conflict,
            'message'  => $this->get_status_message( $detected, $primary, $conflict ),
        );
    }

    /**
     * Import settings from a detected SEO plugin.
     *
     * @param string $source Plugin key (rank_math, yoast, etc.).
     * @return array|\WP_Error Imported data or error.
     */
    public function import_from( $source ) {
        switch ( $source ) {
            case 'rank_math':
                return $this->import_rank_math();
            case 'yoast':
                return $this->import_yoast();
            default:
                return new \WP_Error(
                    'unsupported_source',
                    __( 'Import from this plugin is not yet supported.', 'aeo-god-mode' )
                );
        }
    }

    /**
     * Import settings from Rank Math.
     *
     * @return array Imported fields.
     */
    private function import_rank_math() {
        $imported = array();

        $titles = get_option( 'rank-math-options-titles', array() );
        $general = get_option( 'rank-math-options-general', array() );

        $settings = get_option( 'asgm_settings', array() );

        if ( ! empty( $titles['knowledgegraph_name'] ) ) {
            $settings['business']['name'] = sanitize_text_field( $titles['knowledgegraph_name'] );
            $imported[] = 'business_name';
        }

        if ( ! empty( $titles['social_url_facebook'] ) ) {
            $settings['business']['social']['facebook'] = esc_url_raw( $titles['social_url_facebook'] );
            $imported[] = 'facebook';
        }

        if ( ! empty( $titles['social_url_twitter'] ) ) {
            $settings['business']['social']['twitter'] = esc_url_raw( $titles['social_url_twitter'] );
            $imported[] = 'twitter';
        }

        if ( ! empty( $titles['social_url_linkedin'] ) ) {
            $settings['business']['social']['linkedin'] = esc_url_raw( $titles['social_url_linkedin'] );
            $imported[] = 'linkedin';
        }

        if ( ! empty( $titles['social_url_instagram'] ) ) {
            $settings['business']['social']['instagram'] = esc_url_raw( $titles['social_url_instagram'] );
            $imported[] = 'instagram';
        }

        // Parity with the Yoast importer: logo, site name and the wider profile
        // list all feed the same schema, so a Rank Math user should not end up
        // with a thinner entity than a Yoast user.
        if ( ! empty( $titles['knowledgegraph_logo'] ) ) {
            $settings['business']['logo'] = esc_url_raw( $titles['knowledgegraph_logo'] );
            $imported[] = 'logo';
        }
        if ( ! empty( $titles['website_name'] ) ) {
            $settings['business']['site_name'] = sanitize_text_field( $titles['website_name'] );
            $imported[] = 'site_name';
        }
        $rm_extra = array(
            'social_url_youtube'   => 'youtube',
            'social_url_pinterest' => 'pinterest',
            'social_url_wikipedia' => 'wikipedia',
        );
        foreach ( $rm_extra as $rm_key => $ours ) {
            if ( ! empty( $titles[ $rm_key ] ) ) {
                $settings['business']['social'][ $ours ] = esc_url_raw( $titles[ $rm_key ] );
                $imported[] = $ours;
            }
        }

        if ( ! empty( $titles['knowledgegraph_type'] ) ) {
            $type_map = array(
                'company' => 'Organization',
                'person'  => 'Person',
            );
            $kg_type = $titles['knowledgegraph_type'];
            if ( isset( $type_map[ $kg_type ] ) ) {
                $settings['business']['type'] = $type_map[ $kg_type ];
                $imported[] = 'business_type';
            }
        }

        update_option( 'asgm_settings', $settings );

        return $imported;
    }

    /**
     * Import settings from Yoast SEO.
     *
     * @return array Imported fields.
     */
    private function import_yoast() {
        $imported = array();

        $yoast_titles = get_option( 'wpseo_titles', array() );
        $yoast_social = get_option( 'wpseo_social', array() );

        $settings = get_option( 'asgm_settings', array() );

        if ( ! empty( $yoast_titles['company_name'] ) ) {
            $settings['business']['name'] = sanitize_text_field( $yoast_titles['company_name'] );
            $imported[] = 'business_name';
        }

        if ( ! empty( $yoast_social['facebook_site'] ) ) {
            $settings['business']['social']['facebook'] = esc_url_raw( $yoast_social['facebook_site'] );
            $imported[] = 'facebook';
        }

        if ( ! empty( $yoast_social['twitter_site'] ) ) {
            $settings['business']['social']['twitter'] = sanitize_text_field( $yoast_social['twitter_site'] );
            $imported[] = 'twitter';
        }

        if ( ! empty( $yoast_social['instagram_url'] ) ) {
            $settings['business']['social']['instagram'] = esc_url_raw( $yoast_social['instagram_url'] );
            $imported[] = 'instagram';
        }

        if ( ! empty( $yoast_social['linkedin_url'] ) ) {
            $settings['business']['social']['linkedin'] = esc_url_raw( $yoast_social['linkedin_url'] );
            $imported[] = 'linkedin';
        }

        // Site representation: is this site an Organization or a Person? It is
        // the single most consequential thing Yoast asks in its setup, because
        // it decides which entity the whole site resolves to. We already import
        // the Rank Math equivalent, so omitting Yoast's meant a Yoast user
        // re-answered a question they had already answered.
        if ( ! empty( $yoast_titles['company_or_person'] ) ) {
            $type_map = array(
                'company' => 'Organization',
                'person'  => 'Person',
            );
            $rep = $yoast_titles['company_or_person'];
            if ( isset( $type_map[ $rep ] ) ) {
                $settings['business']['type'] = $type_map[ $rep ];
                $imported[] = 'business_type';
            }
        }

        // Site name for WebSite schema. Yoast keeps this separate from the
        // organization name and the two are often deliberately different.
        if ( ! empty( $yoast_titles['website_name'] ) ) {
            $settings['business']['site_name'] = sanitize_text_field( $yoast_titles['website_name'] );
            $imported[] = 'site_name';
        }

        // Organization logo, used by Organization schema.
        if ( ! empty( $yoast_titles['company_logo'] ) ) {
            $settings['business']['logo'] = esc_url_raw( $yoast_titles['company_logo'] );
            $imported[] = 'logo';
        }

        // The rest of Yoast's profile list. These become sameAs, which is how a
        // machine confirms this entity is the same one it has seen elsewhere.
        // Wikipedia especially: a corroborating third-party source carries far
        // more weight than anything the site says about itself.
        $extra_social = array(
            'youtube_url'   => 'youtube',
            'pinterest_url' => 'pinterest',
            'wikipedia_url' => 'wikipedia',
            'mastodon_url'  => 'mastodon',
        );
        foreach ( $extra_social as $yoast_key => $ours ) {
            if ( ! empty( $yoast_social[ $yoast_key ] ) ) {
                $settings['business']['social'][ $ours ] = esc_url_raw( $yoast_social[ $yoast_key ] );
                $imported[] = $ours;
            }
        }

        // Yoast 20+ keeps any additional profiles in a free-form list.
        if ( ! empty( $yoast_social['other_social_urls'] ) && is_array( $yoast_social['other_social_urls'] ) ) {
            $others = array();
            foreach ( $yoast_social['other_social_urls'] as $u ) {
                $u = esc_url_raw( (string) $u );
                if ( '' !== $u ) {
                    $others[] = $u;
                }
            }
            if ( ! empty( $others ) ) {
                $settings['business']['social']['other'] = $others;
                $imported[] = 'other_profiles';
            }
        }

        update_option( 'asgm_settings', $settings );

        return $imported;
    }

    /**
     * Build a human-readable status message.
     *
     * @param array       $detected Detected plugins.
     * @param string|null $primary  Primary plugin key.
     * @param bool        $conflict Whether there is a conflict.
     * @return string
     */
    private function get_status_message( $detected, $primary, $conflict ) {
        if ( $conflict ) {
            return __( 'Multiple SEO plugins detected. We will import from the primary one and flag conflicts.', 'aeo-god-mode' );
        }

        if ( $primary ) {
            return sprintf(
                /* translators: %s: plugin name */
                __( '%s detected. We will import its settings and work alongside it.', 'aeo-god-mode' ),
                $detected[ $primary ]['name']
            );
        }

        return __( 'No SEO plugin detected. AEO God Mode will handle the full SEO stack.', 'aeo-god-mode' );
    }
}
