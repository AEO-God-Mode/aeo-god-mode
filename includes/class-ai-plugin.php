<?php
/**
 * .well-known/ai-plugin.json generator.
 *
 * Declares site identity and AI permissions at the protocol level.
 *
 * @package AISEOGodMode
 */

namespace AISEOGodMode;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Generates and serves .well-known/ai-plugin.json.
 */
class AIPlugin {

    /**
     * Constructor.
     */
    public function __construct() {
        add_action( 'init', array( $this, 'add_rewrite_rules' ) );
        add_action( 'template_redirect', array( $this, 'serve_manifest' ) );
    }

    /**
     * Add rewrite rules.
     */
    public function add_rewrite_rules() {
        add_rewrite_rule( '^\.well-known/ai-plugin\.json$', 'index.php?asgm_ai_plugin=1', 'top' );
        add_rewrite_tag( '%asgm_ai_plugin%', '1' );
    }

    /**
     * Serve the manifest.
     */
    public function serve_manifest() {
        if ( ! get_query_var( 'asgm_ai_plugin' ) ) {
            return;
        }

        $manifest = $this->build_manifest();
        $json     = wp_json_encode( $manifest, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_PRETTY_PRINT );

        header( 'Content-Type: application/json; charset=utf-8' );
        header( 'Cache-Control: public, max-age=86400' );
        header( 'X-Robots-Tag: noindex' );
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- API JSON response, Content-Type is application/json, and JSON_HEX_TAG | JSON_HEX_AMP provide additional safety.
        echo $json;
        exit;
    }

    /**
     * Get manifest data for REST API.
     *
     * @return array
     */
    public function get_status() {
        return array(
            'url'      => get_site_url() . '/.well-known/ai-plugin.json',
            'manifest' => $this->build_manifest(),
        );
    }

    /**
     * Build the ai-plugin.json manifest.
     *
     * @return array
     */
    private function build_manifest() {
        $settings = get_option( 'asgm_settings', array() );
        $business = isset( $settings['business'] ) ? $settings['business'] : array();
        $name     = ! empty( $business['name'] ) ? $business['name'] : get_bloginfo( 'name' );
        $desc     = get_bloginfo( 'description' );
        $logo     = get_site_icon_url( 512 );

        return array(
            'schema_version'    => 'v1',
            'name_for_human'    => $name,
            'name_for_model'    => sanitize_title( $name ),
            'description_for_human' => $desc,
            'description_for_model' => sprintf(
                '%s is a website at %s. %s',
                $name,
                get_site_url(),
                $desc
            ),
            'auth'              => array(
                'type' => 'none',
            ),
            'api'               => array(
                'type' => 'openapi',
                'url'  => get_site_url() . '/wp-json',
            ),
            'logo_url'          => $logo ?: get_site_url() . '/favicon.ico',
            'contact_email'     => get_bloginfo( 'admin_email' ),
            'legal_info_url'    => $this->find_legal_page(),
            'endpoints'         => array(
                'llms_txt'    => get_site_url() . '/llms.txt',
            ),
            'permissions'       => array(
                'crawl'    => true,
                'cite'     => true,
                'summarize' => true,
                'index'    => true,
            ),
            'content_license'   => 'free-to-cite-with-attribution',
            'generated_by'      => 'AEO God Mode v' . ASGM_VERSION,
        );
    }

    /**
     * Try to find a privacy/terms page.
     *
     * @return string
     */
    private function find_legal_page() {
        $privacy = get_privacy_policy_url();
        if ( ! empty( $privacy ) ) {
            return $privacy;
        }

        $terms = get_page_by_path( 'terms-of-service' );
        if ( $terms ) {
            return get_permalink( $terms );
        }

        return get_site_url() . '/privacy-policy';
    }
}
