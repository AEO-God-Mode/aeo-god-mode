<?php
/**
 * AEO (Answer Engine Optimization) meta layer.
 *
 * @package AISEOGodMode
 */

namespace AISEOGodMode;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Adds speakable schema and llms.txt discovery link for answer engine visibility.
 */
class AEO {

    /**
     * Constructor.
     */
    public function __construct() {
        // Rendering is handled by Main::render_frontend_output().
    }

    /**
     * Render AEO output in the page head.
     */
    public function render() {
        if ( is_admin() ) {
            return;
        }

        $settings = get_option( 'asgm_settings', array() );
        $modules  = isset( $settings['modules'] ) ? $settings['modules'] : array();

        if ( empty( $modules['aeo'] ) ) {
            return;
        }

        // Link to llms.txt (emerging convention for LLM context).
        if ( ! empty( $modules['llms_txt'] ) ) {
            echo '<link rel="alternate" type="text/plain" href="' . esc_url( get_site_url() . '/llms.txt' ) . '" title="LLM Context File">' . "\n";
        }

        // Speakable schema on singular pages (only if explicitly enabled).
        // Speakable is a beta Google feature for topical news queries on Google Assistant.
        if ( is_singular() && ! empty( $settings['speakable_enabled'] ) ) {
            $this->render_speakable_schema();
        }

        // Meta description fallback on singular pages.
        if ( is_singular() ) {
            $this->maybe_render_meta_description();
        }
    }

    /**
     * Output meta description when no SEO plugin handles it.
     * Uses AI-generated description stored in _asgm_meta_description.
     */
    private function maybe_render_meta_description() {
        // Skip if Yoast or Rank Math already handles meta descriptions.
        if ( class_exists( 'WPSEO_Meta' ) || class_exists( 'RankMath' ) || class_exists( 'JEsuspended\All_in_One_SEO_Pack' ) ) {
            return;
        }

        $desc = get_post_meta( get_the_ID(), '_asgm_meta_description', true );
        if ( ! empty( $desc ) ) {
            echo '<meta name="description" content="' . esc_attr( $desc ) . '">' . "\n";
        }
    }

    /**
     * Render speakable schema markup.
     */
    private function render_speakable_schema() {
        $settings   = get_option( 'asgm_settings', array() );
        $selectors  = ! empty( $settings['speakable_selectors'] )
            ? $settings['speakable_selectors']
            : get_option( 'asgm_speakable_selectors', array( '.entry-content h2', '.entry-content p:first-of-type' ) );

        $schema = array(
            '@context'  => 'https://schema.org',
            '@type'     => 'WebPage',
            'name'      => get_the_title(),
            'url'       => get_permalink(),
            'speakable' => array(
                '@type'       => 'SpeakableSpecification',
                'cssSelector' => $selectors,
            ),
        );

        $json = wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP );
        if ( $json ) {
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON_HEX_TAG | JSON_HEX_AMP encode <, >, & as unicode sequences preventing script breakout.
            echo '<script type="application/ld+json">' . $json . "</script>\n";
        }
    }
}
