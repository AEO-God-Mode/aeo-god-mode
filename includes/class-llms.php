<?php
/**
 * llms.txt generator.
 *
 * Generates a spec-compliant llms.txt file following the official structure
 * proposed by Jeremy Howard (llmstxt.org):
 *   H1 → Blockquote → Free-form context → H2 sections → Optional → Ignore
 *
 * @package AISEOGodMode
 */

namespace AISEOGodMode;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Generates and serves llms.txt for LLM discovery.
 */
class LLMS {

    public function __construct() {
        // NOTE: This constructor runs inside 'init' (via boot_modules), so we call
        // add_rewrite_rules directly rather than hooking to 'init' (which already fired).
        $this->add_rewrite_rules();
        add_filter( 'query_vars', array( $this, 'add_query_vars' ) );
        add_action( 'template_redirect', array( $this, 'serve_llms_txt' ) );

        // Prevent WordPress from adding a trailing slash redirect on /llms.txt.
        add_filter( 'redirect_canonical', function( $redirect_url, $requested_url ) {
            if ( get_query_var( 'asgm_llms' ) ) {
                return false;
            }
            return $redirect_url;
        }, 10, 2 );
    }

    /**
     * Add allowed query variables.
     *
     * @param array $vars Allowed query variables.
     * @return array
     */
    public function add_query_vars( $vars ) {
        $vars[] = 'asgm_llms';
        return $vars;
    }

    /**
     * Add rewrite rules.
     */
    public function add_rewrite_rules() {
        add_rewrite_rule( '^llms\.txt$', 'index.php?asgm_llms=1', 'top' );
    }

    /**
     * Serve llms.txt from the front-end.
     */
    public function serve_llms_txt() {
        if ( ! get_query_var( 'asgm_llms' ) ) {
            return;
        }

        $cached = get_transient( 'asgm_llms_txt' );
        if ( $cached ) {
            $this->output_text( $cached );
            return;
        }

        $content = $this->build_content();
        set_transient( 'asgm_llms_txt', $content, DAY_IN_SECONDS );
        update_option( 'asgm_llms_last_generated', current_time( 'mysql' ) );

        $this->output_text( $content );
    }

    /**
     * Get status for REST API.
     *
     * @return array
     */
    public function get_status() {
        $last_generated = get_option( 'asgm_llms_last_generated', '' );
        $cached         = get_transient( 'asgm_llms_txt' );
        $custom         = get_option( 'asgm_llms_custom_content', '' );

        return array(
            'last_generated' => $last_generated,
            'has_cache'      => ! empty( $cached ),
            'content'        => $cached ? $cached : $this->build_content(),
            'custom_content' => $custom,
            'url'            => get_site_url() . '/llms.txt',
        );
    }

    /**
     * Regenerate llms.txt content.
     *
     * @return array
     */
    public function regenerate() {
        delete_transient( 'asgm_llms_txt' );

        $content = $this->build_content();
        set_transient( 'asgm_llms_txt', $content, DAY_IN_SECONDS );
        update_option( 'asgm_llms_last_generated', current_time( 'mysql' ) );

        return array(
            'success'        => true,
            'last_generated' => get_option( 'asgm_llms_last_generated' ),
        );
    }

    /**
     * Save custom content from the frontend editor.
     *
     * @param string $content Custom free-form content.
     * @return array
     */
    public function save_custom_content( $content ) {
        update_option( 'asgm_llms_custom_content', sanitize_textarea_field( $content ) );
        // Clear cache so regeneration picks up the new content.
        delete_transient( 'asgm_llms_txt' );
        return array( 'success' => true );
    }

    /**
     * Build the llms.txt content following the official spec.
     *
     * Structure: H1 → Blockquote → Free-form → Core Pages → Services/Products
     *            → Guides → FAQs → Optional → Ignore
     *
     * @return string
     */
    private function build_content() {
        $settings = get_option( 'asgm_settings', array() );
        $business = isset( $settings['business'] ) ? $settings['business'] : array();
        $name     = ! empty( $business['name'] ) ? $business['name'] : get_bloginfo( 'name' );
        $tagline  = get_bloginfo( 'description' );

        $lines = array();

        // ---- H1: Brand name (required) ----
        $lines[] = "# {$name}";
        $lines[] = "";

        // ---- Blockquote: One-liner summary (strongly recommended) ----
        $summary = ! empty( $business['llms_summary'] ) ? $business['llms_summary'] : $tagline;
        if ( ! empty( $summary ) ) {
            $lines[] = "> {$summary}";
            $lines[] = "";
        }

        // ---- Free-form context: briefing notes ----
        $custom = get_option( 'asgm_llms_custom_content', '' );
        if ( ! empty( $custom ) ) {
            $lines[] = trim( $custom );
            $lines[] = "";
        } else {
            // Auto-generate a brief context section.
            $context_parts = array();
            $context_parts[] = "{$name} is located at " . get_site_url() . ".";
            if ( ! empty( $business['type'] ) && $business['type'] !== 'Person' ) {
                $context_parts[] = "Business type: {$business['type']}.";
            }
            if ( ! empty( $business['location'] ) ) {
                $context_parts[] = "Location: {$business['location']}.";
            }
            $lines[] = implode( ' ', $context_parts );
            $lines[] = "";
        }

        // ---- H2: Core Pages ----
        $core_pages = $this->get_core_pages();
        if ( ! empty( $core_pages ) ) {
            $lines[] = "## Core Pages";
            $lines[] = "";
            foreach ( $core_pages as $page ) {
                $desc = ! empty( $page['desc'] ) ? ": {$page['desc']}" : '';
                $lines[] = "- [{$page['title']}]({$page['url']}){$desc}";
            }
            $lines[] = "";
        }

        // ---- H2: Products or Services (if WooCommerce or custom pages) ----
        $services = $this->get_service_pages();
        if ( ! empty( $services ) ) {
            $lines[] = "## Services";
            $lines[] = "";
            foreach ( $services as $page ) {
                $desc = ! empty( $page['desc'] ) ? ": {$page['desc']}" : '';
                $lines[] = "- [{$page['title']}]({$page['url']}){$desc}";
            }
            $lines[] = "";
        }

        // ---- H2: Key Guides / Blog Posts ----
        $guides = $this->get_top_posts();
        if ( ! empty( $guides ) ) {
            $lines[] = "## Guides";
            $lines[] = "";
            foreach ( $guides as $post ) {
                $desc = ! empty( $post['desc'] ) ? ": {$post['desc']}" : '';
                $lines[] = "- [{$post['title']}]({$post['url']}){$desc}";
            }
            $lines[] = "";
        }

        // ---- H2: Content Focus (categories) ----
        $categories = get_categories( array( 'orderby' => 'count', 'order' => 'DESC', 'number' => 10, 'hide_empty' => true ) );
        $real_cats  = array_filter( $categories, function( $c ) { return $c->slug !== 'uncategorized'; } );
        if ( ! empty( $real_cats ) ) {
            $lines[] = "## Content Focus";
            $lines[] = "";
            $topics = wp_list_pluck( $real_cats, 'name' );
            $lines[] = "Primary topics: " . implode( ', ', $topics ) . ".";
            $lines[] = "";
        }

        // ---- H2: FAQs ----
        $faq_page = get_page_by_path( 'faq' );
        if ( ! $faq_page ) {
            $faq_page = get_page_by_path( 'frequently-asked-questions' );
        }
        if ( $faq_page && $faq_page->post_status === 'publish' ) {
            $lines[] = "## FAQs";
            $lines[] = "";
            $lines[] = "- [Frequently Asked Questions](" . get_permalink( $faq_page ) . "): Common questions about our process, products, and services";
            $lines[] = "";
        }

        // ---- H2: Optional ----
        $optional_pages = $this->get_optional_pages();
        if ( ! empty( $optional_pages ) ) {
            $lines[] = "## Optional";
            $lines[] = "";
            foreach ( $optional_pages as $page ) {
                $desc = ! empty( $page['desc'] ) ? ": {$page['desc']}" : '';
                $lines[] = "- [{$page['title']}]({$page['url']}){$desc}";
            }
            $lines[] = "";
        }

        // ---- H2: Ignore ----
        $lines[] = "## Ignore";
        $lines[] = "";
        $ignore = array( '/wp-admin/', '/wp-login.php', '/cart/', '/checkout/', '/my-account/', '/account/', '/thank-you/', '/wp-json/' );
        foreach ( $ignore as $path ) {
            $lines[] = "- {$path}";
        }

        return implode( "\n", $lines );
    }

    /**
     * Get core pages (homepage, about, pricing, contact, blog).
     *
     * @return array
     */
    private function get_core_pages() {
        $pages = array();

        // Always include homepage.
        $pages[] = array(
            'title' => get_bloginfo( 'name' ) . ' - Homepage',
            'url'   => get_site_url() . '/',
            'desc'  => get_bloginfo( 'description' ) ?: 'Main landing page',
        );

        // Priority slugs for core pages.
        $priority_slugs = array(
            'about'      => 'Company background, team, and mission',
            'about-us'   => 'Company background, team, and mission',
            'services'   => 'Full list of services and offerings',
            'contact'    => 'Contact information and enquiry form',
            'contact-us' => 'Contact information and enquiry form',
            'pricing'    => 'Pricing plans and packages',
            'blog'       => 'Latest articles and insights',
            'faq'        => 'Frequently asked questions',
        );

        foreach ( $priority_slugs as $slug => $desc ) {
            $page = get_page_by_path( $slug );
            if ( $page && $page->post_status === 'publish' ) {
                $pages[] = array(
                    'title' => get_the_title( $page ),
                    'url'   => get_permalink( $page ),
                    'desc'  => $desc,
                );
            }
        }

        return $pages;
    }

    /**
     * Get service/product pages.
     *
     * Looks for child pages of a "services" or "products" parent.
     *
     * @return array
     */
    private function get_service_pages() {
        $parent_slugs = array( 'services', 'products', 'solutions' );
        $pages = array();

        foreach ( $parent_slugs as $slug ) {
            $parent = get_page_by_path( $slug );
            if ( ! $parent || $parent->post_status !== 'publish' ) {
                continue;
            }

            $children = get_children( array(
                'post_parent' => $parent->ID,
                'post_type'   => 'page',
                'post_status' => 'publish',
                'numberposts' => 15,
                'orderby'     => 'menu_order',
                'order'       => 'ASC',
            ) );

            foreach ( $children as $child ) {
                $excerpt = wp_strip_all_tags( $child->post_excerpt );
                $pages[] = array(
                    'title' => get_the_title( $child ),
                    'url'   => get_permalink( $child ),
                    'desc'  => $excerpt ?: '',
                );
            }
            break; // Only use the first matching parent.
        }

        return $pages;
    }

    /**
     * Get top blog posts by comment count (proxy for authority).
     *
     * @return array
     */
    private function get_top_posts() {
        $query = new \WP_Query( array(
            'post_type'      => 'post',
            'post_status'    => 'publish',
            'posts_per_page' => 10,
            'orderby'        => 'comment_count',
            'order'          => 'DESC',
            'no_found_rows'  => true,
        ) );

        $posts = array();
        if ( $query->have_posts() ) {
            while ( $query->have_posts() ) {
                $query->the_post();
                $post = get_post();
                $excerpt = wp_strip_all_tags( get_the_excerpt( $post ) );
                // Truncate to ~120 chars for description.
                if ( strlen( $excerpt ) > 120 ) {
                    $excerpt = substr( $excerpt, 0, 117 ) . '...';
                }
                $posts[] = array(
                    'title' => get_the_title( $post ),
                    'url'   => get_permalink( $post ),
                    'desc'  => $excerpt ?: '',
                );
            }
            wp_reset_postdata();
        }

        return $posts;
    }

    /**
     * Get optional/secondary pages (team, partners, press, testimonials, etc.).
     *
     * @return array
     */
    private function get_optional_pages() {
        $optional_slugs = array(
            'team'          => 'Individual team member profiles',
            'our-team'      => 'Individual team member profiles',
            'partners'      => 'Technology and agency partners',
            'press'         => 'Media coverage and press mentions',
            'testimonials'  => 'Client testimonials and reviews',
            'case-studies'  => 'Detailed results from client engagements',
            'privacy-policy'=> 'Privacy policy',
            'terms'         => 'Terms of service',
            'careers'       => 'Current job openings and company culture',
        );

        $pages = array();
        foreach ( $optional_slugs as $slug => $desc ) {
            $page = get_page_by_path( $slug );
            if ( $page && $page->post_status === 'publish' ) {
                $pages[] = array(
                    'title' => get_the_title( $page ),
                    'url'   => get_permalink( $page ),
                    'desc'  => $desc,
                );
            }
        }

        return $pages;
    }

    /**
     * Output plain text and exit.
     *
     * @param string $text Content.
     */
    private function output_text( $text ) {
        header( 'Content-Type: text/plain; charset=utf-8' );
        header( 'X-Robots-Tag: noindex' );
        header( 'Cache-Control: public, max-age=3600' );
        echo $text; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        exit;
    }
}
