<?php
/**
 * AI-specific HTTP response headers.
 *
 * Adds edge-level signals for AI crawlers that complement robots.txt and llms.txt.
 * Headers are configurable via the AI Signals admin page.
 *
 * @package AISEOGodMode
 */

namespace AISEOGodMode;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Adds AI-specific HTTP headers to front-end responses.
 */
class AIHeaders {

    /**
     * Default header definitions.
     *
     * Each key maps to a header name, default value, and whether it's on by default.
     *
     * @var array
     */
    private static $header_definitions = array(
        'x_ai_crawl'        => array(
            'header'  => 'X-AI-Crawl',
            'value'   => 'allowed',
            'default' => 'on',
        ),
        'x_ai_citeable'     => array(
            'header'  => 'X-AI-Citeable',
            'value'   => 'true',
            'default' => 'on',
        ),
        'x_content_license' => array(
            'header'  => 'X-Content-License',
            'value'   => 'free-to-cite-with-attribution',
            'default' => 'on',
        ),
        'x_ai_content_type' => array(
            'header'  => 'X-AI-Content-Type',
            'value'   => 'human-authored',
            'default' => 'on',
        ),
        'x_ai_speakable'    => array(
            'header'  => 'X-AI-Speakable',
            'value'   => 'true',
            'default' => 'on',
            'condition' => 'singular', // Only on singular pages.
        ),
    );

    /**
     * Constructor.
     */
    public function __construct() {
        add_action( 'send_headers', array( $this, 'add_headers' ) );
    }

    /**
     * Add AI-specific headers based on saved settings.
     */
    public function add_headers() {
        if ( is_admin() || wp_doing_cron() || wp_doing_ajax() ) {
            return;
        }

        $settings = get_option( 'asgm_settings', array() );
        $modules  = isset( $settings['modules'] ) ? $settings['modules'] : array();
        $saved    = get_option( 'asgm_ai_headers', array() );

        foreach ( self::$header_definitions as $key => $def ) {
            // Determine state: use saved value if it exists, otherwise use default.
            // Explicit 'off' means user deliberately disabled it.
            $state = isset( $saved[ $key ] ) ? $saved[ $key ] : $def['default'];

            if ( 'off' === $state ) {
                continue;
            }

            // X-AI-Crawl depends on the AI crawlers module being enabled.
            if ( 'x_ai_crawl' === $key && empty( $modules['ai_crawlers'] ) ) {
                continue;
            }

            // Conditional headers (e.g. speakable only on singular pages).
            if ( ! empty( $def['condition'] ) && 'singular' === $def['condition'] && ! is_singular() ) {
                continue;
            }

            header( $def['header'] . ': ' . $def['value'] );
        }

        // Link header for llms.txt discovery.
        $site_url = get_site_url();
        if ( ! empty( $modules['llms_txt'] ) ) {
            header( "Link: <{$site_url}/llms.txt>; rel=\"ai-context\"; type=\"text/plain\"", false );
        }
    }

    /**
     * Get header definitions for the admin API.
     *
     * @return array
     */
    public static function get_definitions() {
        return self::$header_definitions;
    }

    /**
     * Get current header states (saved values merged with defaults).
     *
     * @return array
     */
    public static function get_states() {
        $saved  = get_option( 'asgm_ai_headers', array() );
        $states = array();

        foreach ( self::$header_definitions as $key => $def ) {
            $states[ $key ] = array(
                'header'  => $def['header'],
                'value'   => $def['value'],
                'state'   => isset( $saved[ $key ] ) ? $saved[ $key ] : $def['default'],
                'default' => $def['default'],
            );
        }

        return $states;
    }

    /**
     * Save header toggle states.
     *
     * Stores explicit 'on'/'off' for each header so we can distinguish between
     * "user deliberately disabled" and "setting doesn't exist yet."
     * This distinction matters for future IETF AIPref migration.
     *
     * @param array $data Associative array of header_key => 'on'|'off'.
     * @return array
     */
    public static function save_states( $data ) {
        $sanitized = array();

        foreach ( self::$header_definitions as $key => $def ) {
            if ( isset( $data[ $key ] ) ) {
                $sanitized[ $key ] = ( 'off' === $data[ $key ] ) ? 'off' : 'on';
            }
        }

        update_option( 'asgm_ai_headers', $sanitized );

        return array(
            'success' => true,
            'states'  => self::get_states(),
        );
    }
}
