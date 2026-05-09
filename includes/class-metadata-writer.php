<?php
/**
 * Metadata Writer — detects active SEO plugin and writes generated
 * meta titles + descriptions to the correct post meta fields.
 *
 * Falls back to native ASGM meta keys when neither Yoast nor Rank Math is active.
 * For WooCommerce/EDD product types, also writes to post_excerpt (short description).
 *
 * @package AISEOGodMode
 */

namespace AISEOGodMode;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MetadataWriter {

    // SEO plugin identifiers.
    const SEO_YOAST     = 'yoast';
    const SEO_RANKMATH  = 'rankmath';
    const SEO_NATIVE    = 'asgm';

    // Meta key map per SEO plugin.
    const META_KEYS = array(
        self::SEO_YOAST => array(
            'title' => '_yoast_wpseo_title',
            'desc'  => '_yoast_wpseo_metadesc',
        ),
        self::SEO_RANKMATH => array(
            'title' => 'rank_math_title',
            'desc'  => 'rank_math_description',
        ),
        self::SEO_NATIVE => array(
            'title' => '_asgm_meta_title',
            'desc'  => '_asgm_meta_description',
        ),
    );

    /**
     * Detect which SEO plugin is active.
     *
     * @return string One of SEO_YOAST, SEO_RANKMATH, or SEO_NATIVE.
     */
    public static function detect_seo_plugin() {
        // Rank Math check.
        if ( defined( 'RANK_MATH_VERSION' ) || class_exists( '\\RankMath\\Paper\\Paper' ) ) {
            return self::SEO_RANKMATH;
        }

        // Yoast check.
        if ( defined( 'WPSEO_VERSION' ) ) {
            return self::SEO_YOAST;
        }

        return self::SEO_NATIVE;
    }

    /**
     * Get detection info for the admin UI and setup wizard.
     *
     * @return array{plugin: string, label: string, title_key: string, desc_key: string}
     */
    public static function get_detection_info() {
        $plugin = self::detect_seo_plugin();

        $labels = array(
            self::SEO_YOAST    => 'Yoast SEO',
            self::SEO_RANKMATH => 'Rank Math',
            self::SEO_NATIVE   => 'AEO God Mode (native)',
        );

        $keys = self::META_KEYS[ $plugin ];

        return array(
            'plugin'    => $plugin,
            'label'     => $labels[ $plugin ],
            'title_key' => $keys['title'],
            'desc_key'  => $keys['desc'],
        );
    }

    /**
     * Write generated metadata to a post.
     *
     * @param int    $post_id            Post ID.
     * @param string $meta_title         Generated meta title.
     * @param string $meta_description   Generated meta description.
     * @param string $product_desc       Optional product short description (for WooCommerce/EDD).
     * @return array{success: bool, written: array, seo_plugin: string}
     */
    public static function write( $post_id, $meta_title, $meta_description, $product_desc = '' ) {
        $post = get_post( $post_id );
        if ( ! $post ) {
            return array(
                'success' => false,
                'error'   => 'Post not found.',
            );
        }

        $plugin  = self::detect_seo_plugin();
        $keys    = self::META_KEYS[ $plugin ];
        $written = array();

        // Write meta title.
        if ( ! empty( $meta_title ) ) {
            update_post_meta( $post_id, $keys['title'], sanitize_text_field( $meta_title ) );
            $written[] = 'meta_title';
        }

        // Write meta description.
        if ( ! empty( $meta_description ) ) {
            update_post_meta( $post_id, $keys['desc'], sanitize_text_field( $meta_description ) );
            $written[] = 'meta_description';
        }

        // Product short description — WooCommerce products and EDD downloads.
        $product_types = array( 'product', 'download' );
        if ( ! empty( $product_desc ) && in_array( $post->post_type, $product_types, true ) ) {
            wp_update_post( array(
                'ID'           => $post_id,
                'post_excerpt' => wp_kses_post( $product_desc ),
            ) );
            $written[] = 'product_description';
        }

        return array(
            'success'    => true,
            'written'    => $written,
            'seo_plugin' => $plugin,
            'post_type'  => $post->post_type,
        );
    }

    /**
     * Read existing metadata for a post (for preview/comparison).
     *
     * @param int $post_id Post ID.
     * @return array{meta_title: string, meta_description: string, product_desc: string, seo_plugin: string}
     */
    public static function read( $post_id ) {
        $plugin = self::detect_seo_plugin();
        $keys   = self::META_KEYS[ $plugin ];
        $post   = get_post( $post_id );

        return array(
            'meta_title'       => get_post_meta( $post_id, $keys['title'], true ) ?: '',
            'meta_description' => get_post_meta( $post_id, $keys['desc'], true ) ?: '',
            'product_desc'     => $post ? $post->post_excerpt : '',
            'seo_plugin'       => $plugin,
        );
    }

    /**
     * Render native ASGM meta tags in wp_head when no SEO plugin is active.
     *
     * Called from the Main class render_frontend_output method.
     */
    public static function render_native_meta() {
        if ( self::detect_seo_plugin() !== self::SEO_NATIVE ) {
            return; // Yoast or Rank Math handles this.
        }

        if ( ! is_singular() ) {
            return;
        }

        $post_id = get_the_ID();
        if ( ! $post_id ) {
            return;
        }

        $title = get_post_meta( $post_id, '_asgm_meta_title', true );
        $desc  = get_post_meta( $post_id, '_asgm_meta_description', true );

        if ( ! empty( $title ) ) {
            // Override the document title via WordPress filter.
            add_filter( 'pre_get_document_title', function() use ( $title ) {
                return esc_html( $title );
            }, 99 );
        }

        if ( ! empty( $desc ) ) {
            echo '<meta name="description" content="' . esc_attr( $desc ) . '" />' . "\n";
        }
    }

    /**
     * Get context data from a post for AI generation.
     *
     * Collects title, content, categories, tags, price, and attributes
     * to send as context for the AI metadata generator.
     *
     * @param int $post_id   Post ID.
     * @param int $max_chars Max characters of content to include.
     * @return array Context data for the AI prompt.
     */
    public static function get_post_context( $post_id, $max_chars = 5000 ) {
        $post = get_post( $post_id );
        if ( ! $post ) {
            return array();
        }

        $content = trim( wp_strip_all_tags( strip_shortcodes( $post->post_content ) ) );
        
        // Fallback: If content is too short (e.g. Page Builders, Custom Templates) fetch the frontend HTML.
        // This ensures the AI metadata generator has actual context for these pages.
        if ( str_word_count( $content ) < 15 && 'publish' === $post->post_status ) {
            $url = get_permalink( $post_id );
            if ( $url ) {
                $response = wp_remote_get( $url, array(
                    'timeout'   => 5,
                    'sslverify' => false, // Ensure local/dev environments work
                ) );
                if ( ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response ) ) {
                    $body = wp_remote_retrieve_body( $response );
                    $body = preg_replace( '@<script[^>]*?>.*?</script>@si', '', $body );
                    $body = preg_replace( '@<style[^>]*?>.*?</style>@si', '', $body );
                    $body = preg_replace( '@<noscript[^>]*?>.*?</noscript>@si', '', $body );
                    $body = preg_replace( '@<nav[^>]*?>.*?</nav>@si', '', $body ); // Skip navigation header/footer
                    $body = preg_replace( '@<footer[^>]*?>.*?</footer>@si', '', $body ); // Skip footer
                    $fallback = trim( wp_strip_all_tags( $body ) );
                    
                    if ( str_word_count( $fallback ) > str_word_count( $content ) ) {
                        $content = $fallback;
                    }
                }
            }
        }

        // Additional cleanup: Removing excessive whitespaces and newlines
        $content = preg_replace( '/\s+/', ' ', $content );

        if ( strlen( $content ) > $max_chars ) {
            $content = substr( $content, 0, $max_chars );
        }

        $context = array(
            'post_id'    => $post_id,
            'post_type'  => $post->post_type,
            'title'      => $post->post_title,
            'content'    => $content,
            'categories' => array(),
            'tags'       => array(),
        );

        // Categories and tags vary by post type.
        switch ( $post->post_type ) {
            case 'product':
                $context['categories'] = wp_list_pluck( wp_get_post_terms( $post_id, 'product_cat' ), 'name' );
                $context['tags']       = wp_list_pluck( wp_get_post_terms( $post_id, 'product_tag' ), 'name' );
                // WooCommerce price.
                if ( function_exists( 'wc_get_product' ) ) {
                    $product = wc_get_product( $post_id );
                    if ( $product ) {
                        $context['price']      = $product->get_price();
                        $context['attributes'] = array();
                        foreach ( $product->get_attributes() as $attr ) {
                            if ( is_object( $attr ) && method_exists( $attr, 'get_name' ) ) {
                                $context['attributes'][ $attr->get_name() ] = $attr->get_options();
                            }
                        }
                    }
                }
                break;

            case 'download':
                $context['categories'] = wp_list_pluck( wp_get_post_terms( $post_id, 'download_category' ), 'name' );
                $context['tags']       = wp_list_pluck( wp_get_post_terms( $post_id, 'download_tag' ), 'name' );
                // EDD price.
                if ( function_exists( 'edd_get_download_price' ) ) {
                    $context['price'] = edd_get_download_price( $post_id );
                }
                break;

            default:
                $cats = wp_get_post_categories( $post_id, array( 'fields' => 'names' ) );
                $context['categories'] = is_array( $cats ) ? $cats : array();
                $tags = wp_get_post_tags( $post_id, array( 'fields' => 'names' ) );
                $context['tags'] = is_array( $tags ) ? $tags : array();
                break;
        }

        // Existing metadata for comparison.
        $context['existing_meta'] = self::read( $post_id );

        return $context;
    }
}
