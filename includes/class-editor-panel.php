<?php
/**
 * Editor Panel — per-post AEO Content Gaps sidebar for Gutenberg.
 *
 * @package AISEOGodMode
 */

namespace AISEOGodMode;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Registers a Gutenberg sidebar panel that shows AEO content gap analysis
 * for the current post being edited.
 */
class EditorPanel {

    /**
     * Boot the module.
     */
    public function __construct() {
        add_action( 'rest_api_init', array( $this, 'register_routes' ) );
        add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_editor_assets' ) );
        add_action( 'save_post', array( $this, 'invalidate_cache' ), 20, 1 );
    }

    /**
     * Register REST endpoint for single-post gap analysis.
     */
    public function register_routes() {
        register_rest_route( 'asgm/v1', '/editor-panel/(?P<post_id>\d+)', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'get_post_gaps' ),
            'permission_callback' => function () {
                return current_user_can( 'edit_posts' );
            },
            'args'                => array(
                'post_id' => array(
                    'required'          => true,
                    'validate_callback' => function ( $val ) {
                        return is_numeric( $val );
                    },
                ),
                'force' => array(
                    'default'           => false,
                    'validate_callback' => function ( $val ) {
                        return is_bool( $val ) || in_array( $val, array( '0', '1', 'true', 'false' ), true );
                    },
                ),
            ),
        ) );

        // Fix action endpoint.
        register_rest_route( 'asgm/v1', '/editor-panel/(?P<post_id>\d+)/fix', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'apply_fix' ),
            'permission_callback' => function () {
                return current_user_can( 'edit_posts' );
            },
            'args'                => array(
                'post_id'  => array( 'required' => true ),
                'fix_type' => array( 'required' => true ),
            ),
        ) );
    }

    /**
     * Get content gap analysis for a single post.
     *
     * @param \WP_REST_Request $request Request object.
     * @return \WP_REST_Response
     */
    public function get_post_gaps( $request ) {
        $post_id = absint( $request['post_id'] );
        $force   = filter_var( $request->get_param( 'force' ), FILTER_VALIDATE_BOOLEAN );

        // Check cache first.
        if ( ! $force ) {
            $cached = get_post_meta( $post_id, '_asgm_editor_panel_gaps', true );
            if ( ! empty( $cached ) && is_array( $cached ) ) {
                $cached['cached'] = true;
                return rest_ensure_response( $cached );
            }
        }

        $post = get_post( $post_id );
        if ( ! $post || 'publish' !== $post->post_status ) {
            // For draft posts, analyze anyway but note it.
            if ( ! $post ) {
                return new \WP_Error( 'not_found', 'Post not found.', array( 'status' => 404 ) );
            }
        }

        $scanner = new ContentGaps();
        // Use reflection to call the private analyze_post method.
        $reflection = new \ReflectionMethod( $scanner, 'analyze_post' );
        $reflection->setAccessible( true );
        $result = $reflection->invoke( $scanner, $post );

        // Build AEO score: 100 minus gap_score (so 0 gaps = 100 score).
        $aeo_score = max( 0, 100 - ( $result['gap_score'] ?? 0 ) );
        $result['aeo_score'] = $aeo_score;
        $result['cached']    = false;

        // Cache the result.
        update_post_meta( $post_id, '_asgm_editor_panel_gaps', $result );

        return rest_ensure_response( $result );
    }

    /**
     * Apply a fix action from the editor panel.
     *
     * @param \WP_REST_Request $request Request object.
     * @return \WP_REST_Response
     */
    public function apply_fix( $request ) {
        $post_id  = absint( $request['post_id'] );
        $fix_type = sanitize_text_field( $request['fix_type'] );

        $scanner = new ContentGaps();
        $result  = $scanner->apply_fix( $post_id, $fix_type, $request->get_params() );

        // Invalidate the cache so next scan picks up changes.
        delete_post_meta( $post_id, '_asgm_editor_panel_gaps' );

        return rest_ensure_response( $result );
    }

    /**
     * Invalidate cached panel data when a post is saved.
     *
     * @param int $post_id Post ID.
     */
    public function invalidate_cache( $post_id ) {
        if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
            return;
        }
        delete_post_meta( $post_id, '_asgm_editor_panel_gaps' );
    }

    /**
     * Enqueue the editor panel JS/CSS in the block editor.
     */
    public function enqueue_editor_assets() {
        $screen = get_current_screen();
        if ( ! $screen || ! $screen->is_block_editor() ) {
            return;
        }

        $manifest_path = ASGM_PLUGIN_DIR . 'assets/editor/.vite/manifest.json';
        if ( ! file_exists( $manifest_path ) ) {
            return;
        }

        $manifest = json_decode( file_get_contents( $manifest_path ), true );
        if ( empty( $manifest ) ) {
            return;
        }

        $entry = $manifest['src/editor-panel.tsx'] ?? null;
        if ( ! $entry ) {
            return;
        }

        $base_url = ASGM_PLUGIN_URL . 'assets/editor/';

        wp_enqueue_script(
            'asgm-editor-panel',
            $base_url . $entry['file'],
            array( 'wp-plugins', 'wp-edit-post', 'wp-element', 'wp-components', 'wp-data', 'wp-api-fetch' ),
            ASGM_VERSION,
            true
        );

        // Load linked CSS from the JS entry (standard Vite format).
        if ( ! empty( $entry['css'] ) ) {
            foreach ( $entry['css'] as $i => $css_file ) {
                wp_enqueue_style(
                    'asgm-editor-panel-' . $i,
                    $base_url . $css_file,
                    array(),
                    ASGM_VERSION
                );
            }
        }

        // Fallback: IIFE builds store CSS as separate manifest entry.
        $style_entry = $manifest['style.css'] ?? null;
        if ( $style_entry && empty( $entry['css'] ) ) {
            wp_enqueue_style(
                'asgm-editor-panel-style',
                $base_url . $style_entry['file'],
                array(),
                ASGM_VERSION
            );
        }

        wp_localize_script( 'asgm-editor-panel', 'asgmEditorPanel', array(
            'restUrl' => rest_url( 'asgm/v1/editor-panel/' ),
            'nonce'   => wp_create_nonce( 'wp_rest' ),
            'isPro'   => License::is_pro(),
        ) );
    }
}
