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
            'permission_callback' => function ( $request ) {
                return current_user_can( 'edit_post', absint( $request['post_id'] ) );
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

        // Search-engine snippet: the title and description this plugin will
        // output for this post, and the ability to edit them.
        //
        // Without Yoast or Rank Math installed these values live in
        // _asgm_meta_title / _asgm_meta_description and had no interface at
        // all. The plugin would generate a description, write it, render it in
        // the page head, and give the author no way to see it, which reads
        // exactly like the feature doing nothing.
        register_rest_route( 'asgm/v1', '/editor-panel/(?P<post_id>\d+)/snippet', array(
            array(
                'methods'             => 'GET',
                'callback'            => array( $this, 'get_snippet' ),
                'permission_callback' => function ( $request ) {
                    return current_user_can( 'edit_post', absint( $request['post_id'] ) );
                },
            ),
            array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'save_snippet' ),
                'permission_callback' => function ( $request ) {
                    return current_user_can( 'edit_post', absint( $request['post_id'] ) );
                },
            ),
        ) );

        // Fix action endpoint.
        register_rest_route( 'asgm/v1', '/editor-panel/(?P<post_id>\d+)/fix', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'apply_fix' ),
            'permission_callback' => function ( $request ) {
                return current_user_can( 'edit_post', absint( $request['post_id'] ) );
            },
            'args'                => array(
                'post_id'        => array( 'required' => true ),
                'fix_type'       => array( 'required' => true ),
                'editor_content' => array(
                    'required' => false,
                    'type'     => 'string',
                ),
            ),
        ) );
    }

    /**
     * The search snippet for a post: what is stored, who owns it, and what a
     * search engine would fall back to if nothing is stored.
     *
     * @param \WP_REST_Request $request Request.
     * @return \WP_REST_Response
     */
    public function get_snippet( $request ) {
        $post_id   = absint( $request['post_id'] );
        $detection = MetadataWriter::get_detection_info();

        $title = (string) get_post_meta( $post_id, $detection['title_key'], true );
        $desc  = (string) get_post_meta( $post_id, $detection['desc_key'], true );

        return new \WP_REST_Response( array(
            'owner'       => $detection['plugin'],
            'owner_label' => $detection['label'],
            // Editable here only when nothing else owns these fields. Writing
            // into Yoast's or Rank Math's meta from a second box in the same
            // sidebar is how two plugins end up fighting over one value.
            'editable'    => MetadataWriter::SEO_NATIVE === $detection['plugin'],
            'title'       => $title,
            'description' => $desc,
            'fallback'    => array(
                'title'       => get_the_title( $post_id ),
                'description' => wp_strip_all_tags( (string) get_the_excerpt( $post_id ) ),
            ),
            'url'         => get_permalink( $post_id ),
        ), 200 );
    }

    /**
     * Save an edited snippet.
     *
     * @param \WP_REST_Request $request Request.
     * @return \WP_REST_Response
     */
    public function save_snippet( $request ) {
        $post_id   = absint( $request['post_id'] );
        $detection = MetadataWriter::get_detection_info();

        if ( MetadataWriter::SEO_NATIVE !== $detection['plugin'] ) {
            return new \WP_REST_Response( array(
                'success' => false,
                'error'   => sprintf(
                    /* translators: %s: name of the SEO plugin that owns these fields. */
                    __( '%s owns these fields on this site, so edit them there instead.', 'aeo-god-mode' ),
                    $detection['label']
                ),
            ), 200 );
        }

        $body  = $request->get_json_params();
        $title = sanitize_text_field( (string) ( $body['title'] ?? '' ) );
        $desc  = sanitize_text_field( (string) ( $body['description'] ?? '' ) );

        // An emptied field means "go back to the theme default", so delete the
        // row rather than storing an empty string that would render as one.
        foreach ( array( $detection['title_key'] => $title, $detection['desc_key'] => $desc ) as $key => $value ) {
            if ( '' === $value ) {
                delete_post_meta( $post_id, $key );
            } else {
                update_post_meta( $post_id, $key, $value );
            }
        }

        return new \WP_REST_Response( array( 'success' => true, 'title' => $title, 'description' => $desc ), 200 );
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
            if ( ! empty( $cached )
                && is_array( $cached )
                && isset( $cached['score_version'] )
                && ContentGaps::SCORE_VERSION === (int) $cached['score_version']
                && isset( $cached['rendered_analysis']['score_version'] )
                && RenderedPageEvaluator::SCORE_VERSION === (int) $cached['rendered_analysis']['score_version'] ) {
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
        $result  = $scanner->analyze_post( $post );

        // ContentGaps delegates scored checks to RenderedPageEvaluator. Keep a
        // legacy fallback for mixed-version installs, but never recompute a
        // second score when the shared engine supplied one.
        $aeo_score = isset( $result['aeo_score'] )
            ? (int) $result['aeo_score']
            : max( 0, 100 - ( $result['gap_score'] ?? 0 ) );
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
        $base_dir = ASGM_PLUGIN_DIR . 'assets/editor/';

        // Cache-bust on the built file's mtime so every deploy ships fresh assets
        // without waiting for a plugin version bump. One stat per asset; falls back
        // to the plugin version if the file is missing or unreadable (filemtime false).
        $asset_ver = function ( $rel ) use ( $base_dir ) {
            $mtime = @filemtime( $base_dir . $rel );
            return ( false !== $mtime ) ? ASGM_VERSION . '.' . $mtime : ASGM_VERSION;
        };

        wp_enqueue_script(
            'asgm-editor-panel',
            $base_url . $entry['file'],
            array( 'wp-plugins', 'wp-edit-post', 'wp-element', 'wp-components', 'wp-data', 'wp-blocks', 'wp-block-editor', 'wp-rich-text', 'wp-i18n', 'wp-api-fetch' ),
            $asset_ver( $entry['file'] ),
            true
        );

        // Load linked CSS from the JS entry (standard Vite format).
        if ( ! empty( $entry['css'] ) ) {
            foreach ( $entry['css'] as $i => $css_file ) {
                wp_enqueue_style(
                    'asgm-editor-panel-' . $i,
                    $base_url . $css_file,
                    array(),
                    $asset_ver( $css_file )
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
                $asset_ver( $style_entry['file'] )
            );
        }

        wp_localize_script( 'asgm-editor-panel', 'asgmEditorPanel', array(
            'restUrl' => rest_url( 'asgm/v1/editor-panel/' ),
            'nonce'   => wp_create_nonce( 'wp_rest' ),
            'isPro'   => License::is_pro(),
        ) );
    }
}
