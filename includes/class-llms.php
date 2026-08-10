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

        // Coexist with Yoast SEO's llms.txt feature.
        //
        // Yoast writes a REAL file to the site root (get_home_path(), falling
        // back to DOCUMENT_ROOT). We serve /llms.txt virtually through a
        // rewrite rule. A physical file is returned by the web server before
        // WordPress boots, so Yoast's copy silently wins and ours is never
        // reached. Verified on a live site: with a physical llms.txt present
        // the response loses its x-powered-by PHP header entirely.
        //
        // Yoast documents wpseo_llmstxt_filesystem_path for relocating their
        // file. Using their own public filter keeps this a supported
        // integration rather than us deleting or editing another plugin's
        // output. Their file keeps being generated and stays on disk, it just
        // no longer occupies the /llms.txt slot.
        add_filter( 'wpseo_llmstxt_filesystem_path', array( $this, 'relocate_yoast_llms_txt' ) );

        // Prevent WordPress from adding a trailing slash redirect on /llms.txt.
        add_filter( 'redirect_canonical', function( $redirect_url, $requested_url ) {
            if ( get_query_var( 'asgm_llms' ) ) {
                return false;
            }
            return $redirect_url;
        }, 10, 2 );
    }

    /**
     * Point Yoast SEO's llms.txt at a different filename so ours can serve.
     *
     * Only moves it when the incoming path is the root llms.txt we would
     * otherwise be shadowed by. Anything already customised by the site owner
     * or another filter is left alone.
     *
     * @param string $path Absolute path Yoast intends to write to.
     * @return string
     */
    public function relocate_yoast_llms_txt( $path ) {
        if ( ! is_string( $path ) || '' === $path ) {
            return $path;
        }
        if ( 'llms.txt' !== strtolower( basename( $path ) ) ) {
            return $path;
        }
        return dirname( $path ) . '/llms-yoast.txt';
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

        // A hand-written file is served exactly as the owner left it. No cache,
        // no merge, no regeneration: if they took control of the wording, the
        // plugin must not quietly edit it back.
        $manual = $this->manual_content();
        if ( '' !== $manual ) {
            $this->output_text( $manual );
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

        $manual = $this->manual_content();

        return array(
            'last_generated' => $last_generated,
            'has_cache'      => ! empty( $cached ),
            // What the generator would produce. Still shown while a manual file
            // is live, so the owner can compare and see what they are missing.
            'content'        => $cached ? $cached : $this->build_content(),
            'custom_content' => $custom,
            'manual_enabled' => ( '' !== $manual ),
            'manual_content' => $manual,
            'url'            => get_site_url() . '/llms.txt',
        );
    }

    /**
     * Drop llms.txt from whatever page cache the site runs.
     *
     * The file is served through a rewrite, so a caching plugin treats it like
     * any other URL and will happily hold it for days. Without this, an owner
     * edits their file, sees the change in the preview, and the public URL keeps
     * serving the old text until the cache happens to expire. Found on
     * aeogodmode.io where llms.txt was cached for a week.
     *
     * @return void
     */
    private function purge_public_file() {
        $url = home_url( '/llms.txt' );

        // LiteSpeed, W3 Total Cache, WP Rocket, Cache Enabler, Nginx Helper.
        do_action( 'litespeed_purge_url', $url );
        if ( function_exists( 'w3tc_flush_url' ) ) {
            w3tc_flush_url( $url );
        }
        if ( function_exists( 'rocket_clean_files' ) ) {
            rocket_clean_files( array( $url ) );
        }
        do_action( 'cache_enabler_clear_page_cache_by_url', $url );
        do_action( 'rt_nginx_helper_purge_url', $url );
    }

    /**
     * Where the hand-written file lives.
     *
     * One option, deliberately. The first version used two non-autoloaded
     * options and the flag read back stale on the request that follows the
     * save: the owner pressed the button, the plugin said yes, and the file
     * carried on being generated. A single autoloaded row travels in the
     * alloptions entry that update_option always refreshes, so every request
     * agrees. update_option cannot change an existing row's autoload, hence
     * the delete-then-add in save_manual().
     */
    const MANUAL_OPT = 'asgm_llms_manual';

    /**
     * The hand-written file, or an empty string when the generator is in charge.
     *
     * @return string
     */
    public function manual_content() {
        $stored = get_option( self::MANUAL_OPT, array() );
        if ( ! is_array( $stored ) || empty( $stored['enabled'] ) ) {
            return '';
        }
        return trim( (string) ( $stored['content'] ?? '' ) );
    }

    /**
     * Take manual control of llms.txt.
     *
     * The owner edits the text and from then on it is served verbatim. It stops
     * tracking the site, which is the trade they are making knowingly: new pages
     * will not appear until they add them or switch back to automatic.
     *
     * Stored without HTML stripping on purpose. llms.txt is plain text served as
     * text/plain, never rendered as markup, so removing angle brackets would
     * corrupt legitimate content such as a code sample.
     *
     * @param string $content Full file contents.
     * @return array
     */
    public function save_manual( $content ) {
        $content = trim( (string) $content );
        if ( '' === $content ) {
            return $this->disable_manual();
        }
        $this->write_manual( array( 'enabled' => true, 'content' => $content ) );
        delete_transient( 'asgm_llms_txt' );
        $this->purge_public_file();

        return array(
            'success'        => true,
            'manual_enabled' => true,
            'manual_content' => $content,
        );
    }

    /**
     * Hand llms.txt back to the generator, keeping the edited text so switching
     * back and forth never loses the owner's work.
     *
     * @return array
     */
    public function disable_manual() {
        $stored = get_option( self::MANUAL_OPT, array() );
        $kept   = is_array( $stored ) ? (string) ( $stored['content'] ?? '' ) : '';
        $this->write_manual( array( 'enabled' => false, 'content' => $kept ) );
        delete_transient( 'asgm_llms_txt' );
        $this->purge_public_file();

        return array(
            'success'        => true,
            'manual_enabled' => false,
            'manual_content' => $kept,
        );
    }

    /**
     * Persist the manual state, guaranteeing the row is autoloaded.
     *
     * @param array $value Manual state.
     * @return void
     */
    private function write_manual( $value ) {
        // add_option is the only way to set autoload on a row that already
        // exists with it off, so the old row goes first.
        delete_option( self::MANUAL_OPT );
        add_option( self::MANUAL_OPT, $value, '', 'yes' );

        // Retire the two options the first version used.
        delete_option( 'asgm_llms_manual_enabled' );
        delete_option( 'asgm_llms_manual_content' );
    }

    /**
     * Regenerate llms.txt content.
     *
     * @return array
     */
    public function regenerate() {
        delete_transient( 'asgm_llms_txt' );
        $this->purge_public_file();

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
                // Same precedence as the posts loop: a description set on the
                // page's own llms.txt Entry panel beats the excerpt.
                $custom  = get_post_meta( $child->ID, '_asgm_llms_description', true );
                $excerpt = is_string( $custom ) && '' !== trim( $custom )
                    ? wp_strip_all_tags( $custom )
                    : wp_strip_all_tags( $child->post_excerpt );
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

                // A description written by the per-post "llms.txt Entry" panel
                // wins over the excerpt. Without this the Generate Description
                // button saved to _asgm_llms_description and nothing ever read
                // it, so the author's chosen wording never reached the file and
                // there was no way to override what llms.txt said about a page.
                $custom  = get_post_meta( $post->ID, '_asgm_llms_description', true );
                $excerpt = is_string( $custom ) && '' !== trim( $custom )
                    ? wp_strip_all_tags( $custom )
                    : wp_strip_all_tags( get_the_excerpt( $post ) );

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
