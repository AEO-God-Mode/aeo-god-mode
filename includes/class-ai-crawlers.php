<?php
/**
 * AI Crawlers management.
 *
 * @package AISEOGodMode
 */

namespace AISEOGodMode;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Manages AI crawler access and allowlisting.
 */
class AICrawlers {

    /**
     * Known AI crawler user-agent patterns.
     *
     * @var array
     */
    private $bot_patterns = array(
        // OpenAI (3 bots).
        'GPTBot'             => 'GPTBot',           // Training crawler for foundation models.
        'OAI-SearchBot'      => 'OAI-SearchBot',    // Powers ChatGPT search results.
        'ChatGPT-User'       => 'ChatGPT-User',     // User-triggered web fetches in ChatGPT.

        // Perplexity (2 bots).
        'PerplexityBot'      => 'PerplexityBot',    // Search index crawler (not training).
        'Perplexity-User'    => 'Perplexity-User',  // Real-time browsing for cited answers.

        // Anthropic (3 bots).
        'ClaudeBot'          => 'ClaudeBot',         // Training crawler for Claude models.
        'Claude-SearchBot'   => 'Claude-SearchBot',  // Indexes content for Claude search.
        'Claude-User'        => 'Claude-User',       // User-triggered fetches in Claude.
        'Anthropic-AI'       => 'anthropic-ai',      // Deprecated — kept for backward compat.

        // Google.
        'Google-Extended'    => 'Google-Extended',   // Gemini/Vertex AI training (not Search).

        // Apple.
        'Applebot-Extended'  => 'Applebot-Extended', // Apple Intelligence AI training only.

        // Meta.
        'Meta-ExternalAgent' => 'meta-externalagent', // LLaMA training + Meta AI features.
        'FacebookBot'        => 'facebookexternalhit', // Link previews (not strictly AI).

        // Other.
        'Amazonbot'          => 'Amazonbot',         // Amazon Alexa / AI services.
        'Bytespider'         => 'Bytespider',        // ByteDance / TikTok AI training.
        'CCBot'              => 'CCBot',             // Common Crawl open dataset.
        'Cohere-AI'          => 'cohere-ai',         // Cohere enterprise AI training.
        'DeepSeekBot'        => 'deepseek',          // DeepSeek AI models.
    );

    /**
     * Constructor — hooks into request processing for logging.
     */
    public function __construct() {
        add_action( 'wp', array( $this, 'detect_and_log' ), 1 );
    }

    /**
     * Detect AI bot visits and log them.
     */
    public function detect_and_log() {
        if ( is_admin() ) {
            return;
        }

        $ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
        if ( empty( $ua ) ) {
            return;
        }

        $bot_name = $this->identify_bot( $ua );
        if ( ! $bot_name ) {
            return;
        }

        $settings = get_option( 'asgm_settings', array() );
        $modules  = isset( $settings['modules'] ) ? $settings['modules'] : array();

        if ( empty( $modules['crawler_log'] ) ) {
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'asgm_crawler_log';

        $url = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
        $ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

        $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $table,
            array(
                'bot_name'      => $bot_name,
                'user_agent'    => substr( $ua, 0, 500 ),
                'url'           => substr( $url, 0, 2083 ),
                'response_code' => http_response_code() ?: 200,
                'ip_address'    => $ip,
                'created_at'    => current_time( 'mysql' ),
            ),
            array( '%s', '%s', '%s', '%d', '%s', '%s' )
        );
    }

    /**
     * Identify which AI bot is visiting.
     *
     * @param string $user_agent The user-agent string.
     * @return string|false Bot name or false.
     */
    public function identify_bot( $user_agent ) {
        $ua_lower = strtolower( $user_agent );

        foreach ( $this->bot_patterns as $name => $pattern ) {
            if ( false !== strpos( $ua_lower, strtolower( $pattern ) ) ) {
                return $name;
            }
        }

        return false;
    }

    /**
     * Get the list of managed bots.
     *
     * @return array
     */
    public function get_bot_list() {
        return $this->bot_patterns;
    }
}
