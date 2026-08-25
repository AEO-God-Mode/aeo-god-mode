<?php
/**
 * Block registration for visual blocks and CTAs.
 *
 * Both blocks are hybrid rather than fully dynamic. The editor saves plain
 * semantic HTML into post content, and the render callback here replaces it
 * with the branded version while the plugin is active. A fully dynamic block
 * that saves nothing would leave a hole in the article the day someone
 * deactivates the plugin, which is not acceptable behaviour for something that
 * writes into people's posts.
 *
 * The render callbacks ignore the saved HTML entirely and rebuild from the
 * block's attributes, so the enhanced output can never be poisoned by whatever
 * ended up in post content.
 *
 * @package AISEOGodMode
 */

namespace AISEOGodMode;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class VisualBlocks {

    /**
     * Boot the module.
     */
    public function __construct() {
        // Registered directly rather than through another add_action( 'init' ).
        // Main::boot_modules() already runs on init, so hooking init again from
        // inside it depends on WP_Hook picking up a callback added mid-dispatch.
        // That happens to work, but it is not a guarantee worth resting block
        // registration on. rest_api_init always fires after init, so the REST
        // routes are safe to hook normally.
        $this->register_blocks();
        add_action( 'rest_api_init', array( $this, 'register_routes' ) );

        // Styles go through the normal queue. Registering happens on every
        // front-end request; enqueueing happens only when a block actually
        // renders, so a page with neither block ships neither stylesheet.
        add_action( 'wp_enqueue_scripts', array( $this, 'register_styles' ), 5 );
    }

    /* ──────────────────────────── Styles ──────────────────────────── */

    /**
     * Register both stylesheets as inline styles on src-less handles.
     *
     * src-less handles mean no extra HTTP request: the CSS is small enough that
     * a round trip would cost more than the bytes. WordPress still owns
     * deduplication and placement, which is the part that matters. Doing this
     * by hand with a static "printed already" flag is what broke the first
     * version, because excerpt generation runs the_content() and consumed the
     * one allowed print before the real render happened.
     */
    public function register_styles() {
        foreach ( array(
            'aeogm-visual' => VisualTemplates::css(),
            'aeogm-cta'    => CTARenderer::css(),
        ) as $handle => $css ) {
            wp_register_style( $handle, false, array(), ASGM_VERSION );
            wp_add_inline_style( $handle, $css );
        }
    }

    /**
     * Ensure a stylesheet is queued, registering it first if we are rendering
     * outside a normal front-end request (REST, a shortcode in a feed, a
     * block rendered by a page builder before wp_enqueue_scripts has fired).
     *
     * @param string $handle Style handle.
     */
    private function need_style( $handle ) {
        if ( ! wp_style_is( $handle, 'registered' ) ) {
            $this->register_styles();
        }

        wp_enqueue_style( $handle );
    }

    /* ──────────────────────────── Blocks ──────────────────────────── */

    /**
     * Register both block types.
     *
     * Attributes are declared loosely (object/string) and validated properly in
     * the renderers. Block attribute schemas cannot express "one of these four
     * shapes, each with its own field limits", so treating them as the security
     * boundary would be a mistake. They exist to get the data through; the
     * renderers decide what is acceptable.
     */
    public function register_blocks() {
        if ( ! function_exists( 'register_block_type' ) ) {
            return;
        }

        register_block_type( 'aeogm/visual', array(
            'api_version'     => 3,
            'title'           => __( 'AEO Visual', 'aeo-god-mode' ),
            'category'        => 'media',
            'icon'            => 'chart-bar',
            'description'     => __( 'An on-brand stat, takeaway, checklist or pull quote.', 'aeo-god-mode' ),
            'render_callback' => array( $this, 'render_visual' ),
            'attributes'      => array(
                'schemaVersion'   => array( 'type' => 'number', 'default' => VisualTemplates::SCHEMA_VERSION ),
                'contentType'     => array( 'type' => 'string', 'default' => '' ),
                'template'        => array( 'type' => 'string', 'default' => '' ),
                'templateVersion' => array( 'type' => 'number', 'default' => 1 ),
                'content'         => array( 'type' => 'object', 'default' => array() ),
                'icon'            => array( 'type' => 'string', 'default' => '' ),
                'caption'         => array( 'type' => 'string', 'default' => '' ),
                'style'           => array( 'type' => 'string', 'default' => '' ),
                'shape'           => array( 'type' => 'string', 'default' => '' ),
                'layout'          => array( 'type' => 'object', 'default' => array() ),
            ),
        ) );

        register_block_type( 'aeogm/cta', array(
            'api_version'     => 3,
            'title'           => __( 'AEO Call to Action', 'aeo-god-mode' ),
            'category'        => 'design',
            'icon'            => 'megaphone',
            'description'     => __( 'An on-brand call to action whose internal link survives slug changes.', 'aeo-god-mode' ),
            'render_callback' => array( $this, 'render_cta' ),
            'attributes'      => array(
                'schemaVersion' => array( 'type' => 'number', 'default' => CTARenderer::SCHEMA_VERSION ),
                'layout'        => array( 'type' => 'string', 'default' => 'button' ),
                'headingLevel'  => array( 'type' => 'string', 'default' => 'h3' ),
                'destination'   => array( 'type' => 'object', 'default' => array() ),
                'content'       => array( 'type' => 'object', 'default' => array() ),
                'icon'          => array( 'type' => 'string', 'default' => '' ),
                'background'    => array( 'type' => 'string', 'default' => 'light' ),
                'padding'       => array( 'type' => 'string', 'default' => 'comfortable' ),
                'style'         => array( 'type' => 'string', 'default' => '' ),
            ),
        ) );
    }

    /**
     * Render a visual block.
     *
     * Falls back to the saved semantic HTML if the attributes no longer produce
     * anything, so an edge case degrades to the plain version rather than
     * silently deleting a section of somebody's article.
     *
     * @param array  $attrs   Block attributes.
     * @param string $content Saved inner HTML.
     * @return string
     */
    public function render_visual( $attrs, $content = '' ) {
        $html = VisualTemplates::render( $attrs );

        if ( '' === $html ) {
            return $content;
        }

        $this->need_style( 'aeogm-visual' );

        return $html;
    }

    /**
     * Render a CTA block.
     *
     * @param array  $attrs   Block attributes.
     * @param string $content Saved inner HTML.
     * @return string
     */
    public function render_cta( $attrs, $content = '' ) {
        $html = CTARenderer::render( $attrs );

        if ( '' === $html ) {
            return $content;
        }

        $this->need_style( 'aeogm-cta' );

        return $html;
    }

    /* ───────────────────────────── REST ───────────────────────────── */

    /**
     * Register editor support routes.
     */
    public function register_routes() {
        // API::NAMESPACE ('aeo-god-mode/v1'), not the 'asgm/v1' the editor
        // panel happens to use. On aeogodmode.io itself the licensing
        // mu-plugin also registers into 'asgm/v1', so sharing it invites a
        // path collision that would only ever show up on our own site.
        $can_edit = function () {
            return current_user_can( 'edit_posts' );
        };

        // The template registry, so the editor builds its picker from the same
        // definitions the renderer uses rather than a second hardcoded copy.
        register_rest_route( API::NAMESPACE, '/visuals/registry', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'get_registry' ),
            'permission_callback' => $can_edit,
        ) );

        // Server-rendered preview. The editor shows exactly what a reader will
        // see, using the one renderer, instead of a React lookalike that drifts.
        register_rest_route( API::NAMESPACE, '/visuals/preview', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'post_preview' ),
            'permission_callback' => $can_edit,
        ) );

        // Brand kit for the settings screen: what is saved, what it resolves
        // to, and what we could inherit if the customer saved nothing. Admin
        // only, because it exposes theme configuration.
        register_rest_route( API::NAMESPACE, '/brand', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'get_brand' ),
            'permission_callback' => function () {
                return current_user_can( 'manage_options' );
            },
        ) );

        // Destination search for the CTA combobox: posts, pages and any public
        // custom type, matched on title.
        register_rest_route( API::NAMESPACE, '/visuals/destinations', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'get_destinations' ),
            'permission_callback' => $can_edit,
            'args'                => array(
                'search' => array( 'type' => 'string', 'required' => false ),
            ),
        ) );
    }

    /**
     * The content-type and template registry, plus the resolved brand kit.
     *
     * @return \WP_REST_Response
     */
    public function get_registry( $request ) {
        $brand = BrandKit::resolve();

        return new \WP_REST_Response( array(
            'schemaVersion' => VisualTemplates::SCHEMA_VERSION,
            'types'         => VisualTemplates::registry(),
            'icons'         => VisualIcons::names(),
            'shapes'        => VisualIcons::shape_names(),
            'styles'        => VisualTemplates::STYLES,
            'layout'        => array(
                'widths'      => VisualTemplates::WIDTHS,
                'alignments'  => VisualTemplates::ALIGNMENTS,
                'backgrounds' => VisualTemplates::BACKGROUNDS,
                'spacings'    => VisualTemplates::SPACINGS,
            ),
            'cta'           => array(
                'layouts'       => CTARenderer::LAYOUTS,
                'headingLevels' => CTARenderer::HEADING_LEVELS,
                'rels'          => CTARenderer::RELS,
                'backgrounds'   => CTARenderer::BACKGROUNDS,
                'paddings'      => CTARenderer::PADDINGS,
                'styles'        => CTARenderer::STYLES,
            ),
            'brand'         => $brand,
        ), 200 );
    }

    /**
     * Everything the brand screen needs to render itself as a confirmation
     * rather than an empty form.
     *
     * `saved` is only what the customer chose, `resolved` is what actually
     * renders, and `suggestions` is what we could inherit. Keeping them
     * separate is what lets the UI say "this came from your theme" instead of
     * presenting an inherited value as if the customer had picked it.
     *
     * @return \WP_REST_Response
     */
    public function get_brand() {
        $resolved = BrandKit::resolve();
        $palette  = BrandKit::theme_palette();

        $logo_id  = (int) get_theme_mod( 'custom_logo' );
        $icon_id  = (int) get_option( 'site_icon' );

        $logo_url = function ( $id ) {
            if ( ! $id ) {
                return '';
            }
            $url = wp_get_attachment_image_url( $id, 'full' );

            return $url ? $url : '';
        };

        // Contrast for the two pairings a reader actually has to read: button
        // text on the primary fill, and body text on the page background.
        $contrast = array(
            'on_primary' => BrandKit::contrast_ratio( $resolved['on_primary'], $resolved['primary'] ),
            'text'       => BrandKit::contrast_ratio( $resolved['text'], $resolved['background'] ),
        );

        return new \WP_REST_Response( array(
            'saved'       => BrandKit::saved(),
            'resolved'    => $resolved,
            'suggestions' => array(
                'theme'     => $palette,
                'wordpress' => array(
                    'custom_logo' => array( 'id' => $logo_id, 'url' => $logo_url( $logo_id ) ),
                    'site_icon'   => array( 'id' => $icon_id, 'url' => $logo_url( $icon_id ) ),
                ),
            ),
            'options'     => array(
                'radii'         => array_keys( BrandKit::RADII ),
                'icon_styles'   => BrandKit::ICON_STYLES,
                'visual_styles' => BrandKit::VISUAL_STYLES,
                'tones'         => BrandKit::TONES,
                'color_keys'    => BrandKit::COLOR_KEYS,
            ),
            'contrast'    => $contrast,
        ), 200 );
    }

    /**
     * Render a block server-side for the editor preview.
     *
     * @param \WP_REST_Request $request Request.
     * @return \WP_REST_Response
     */
    public function post_preview( $request ) {
        $body  = $request->get_json_params();
        $kind  = isset( $body['kind'] ) ? (string) $body['kind'] : 'visual';
        $attrs = isset( $body['attributes'] ) && is_array( $body['attributes'] ) ? $body['attributes'] : array();

        // The brand screen previews values the customer has not saved yet.
        // Layered for this request only, never written, and flushed below.
        $previewing_brand = isset( $body['brand'] ) && is_array( $body['brand'] );
        if ( $previewing_brand ) {
            BrandKit::override( $body['brand'] );
        }

        if ( 'cta' === $kind ) {
            $html     = CTARenderer::render( $attrs );
            $fallback = CTARenderer::fallback( $attrs );
            $css      = CTARenderer::css();
        } else {
            $html     = VisualTemplates::render( $attrs );
            $fallback = VisualTemplates::fallback( $attrs );
            $css      = VisualTemplates::css();
        }

        if ( $previewing_brand ) {
            BrandKit::flush();
        }

        // The editor gets the stylesheet as data and injects it once itself.
        // Preview markup and published markup then come from the same renderer
        // and the same CSS, so no screen can drift from the front end.
        return new \WP_REST_Response( array(
            'html'     => $html,
            'fallback' => $fallback,
            'css'      => $css,
            'empty'    => ( '' === $html ),
        ), 200 );
    }

    /**
     * Search published content for a CTA destination.
     *
     * @param \WP_REST_Request $request Request.
     * @return \WP_REST_Response
     */
    public function get_destinations( $request ) {
        $search = sanitize_text_field( (string) $request->get_param( 'search' ) );

        $types = get_post_types( array( 'public' => true ), 'objects' );
        unset( $types['attachment'] );

        $query = new \WP_Query( array(
            'post_type'              => array_keys( $types ),
            'post_status'            => 'publish',
            's'                      => $search,
            'posts_per_page'         => 12,
            'orderby'                => $search ? 'relevance' : 'modified',
            'order'                  => 'DESC',
            'ignore_sticky_posts'    => true,
            'no_found_rows'          => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
        ) );

        $results = array();
        foreach ( $query->posts as $post ) {
            $label = isset( $types[ $post->post_type ] ) ? $types[ $post->post_type ]->labels->singular_name : $post->post_type;

            $results[] = array(
                'id'        => $post->ID,
                'title'     => html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' ),
                'url'       => get_permalink( $post ),
                'path'      => wp_make_link_relative( get_permalink( $post ) ),
                'post_type' => $post->post_type,
                'type_label'=> $label,
            );
        }

        return new \WP_REST_Response( array( 'results' => $results ), 200 );
    }
}
