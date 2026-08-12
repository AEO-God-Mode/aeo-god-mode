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

        $cached = get_transient( 'asgm_llms_txt' );
        if ( $cached ) {
            $this->output_text( $cached );
            return;
        }

        // Generated fresh every time, with the owner's edits laid back over the
        // top. Editing one line must never stop a new page appearing.
        $content = $this->merged_content();
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

        $overrides = $this->manual_overrides();

        return array(
            'last_generated' => $last_generated,
            'has_cache'      => ! empty( $cached ),
            // The file as actually served: generated, with any edits applied.
            'content'        => $this->merged_content(),
            'custom_content' => $custom,
            'manual_enabled' => $this->has_manual_edits(),
            'manual_content' => $this->merged_content(),
            'edited_sections'=> array_values( array_map( function ( $k ) {
                return '__head__' === $k ? 'Summary' : $k;
            }, array_keys( $overrides['sections'] ) ) ),
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
     * EDITING WITHOUT FREEZING
     * ------------------------
     * The first version of manual editing served the owner's text verbatim and
     * stopped generating. That is the wrong trade: fixing one wrong word cost
     * them every future page.
     *
     * So edits are stored per section instead of as one blob. On save the text
     * is split on its "## " headings, compared against what the generator would
     * have produced, and only the parts that actually differ are kept. On every
     * request the file is generated fresh and those parts are dropped back in.
     *
     * The result is that correcting the summary line leaves Core Pages and
     * Guides updating themselves, and a page published tomorrow still appears.
     *
     * Stored shape:
     *   [ 'sections' => [ '__head__' => "...", 'Guides' => "...", ... ],
     *     'removed'  => [ 'Optional', ... ] ]
     *
     * One autoloaded row. Two non-autoloaded options were tried first and the
     * flag read back stale on the request after the save, so the plugin
     * reported success while still serving the generated file.
     */
    const MANUAL_OPT = 'asgm_llms_manual';

    /**
     * Split a file into its head and its "## " sections.
     *
     * @param string $text File contents.
     * @return array<string,string> Keyed by heading, head under __head__.
     */
    private function split_sections( $text ) {
        $out     = array();
        $current = '__head__';
        $buf     = array();

        foreach ( preg_split( '/\r\n|\n|\r/', (string) $text ) as $line ) {
            if ( preg_match( '/^##\s+(.+?)\s*$/', $line, $m ) ) {
                $out[ $current ] = trim( implode( "\n", $buf ) );
                $current         = $m[1];
                $buf             = array();
                continue;
            }
            $buf[] = $line;
        }
        $out[ $current ] = trim( implode( "\n", $buf ) );

        return $out;
    }

    /**
     * Reassemble a file from its parts, keeping the generator's ordering.
     *
     * @param array<string,string> $sections Section bodies keyed by heading.
     * @param string[]             $order    Heading order.
     * @return string
     */
    private function join_sections( $sections, $order ) {
        $parts = array();
        if ( ! empty( $sections['__head__'] ) ) {
            $parts[] = $sections['__head__'];
        }
        foreach ( $order as $heading ) {
            if ( '__head__' === $heading || ! isset( $sections[ $heading ] ) ) {
                continue;
            }
            $body    = trim( (string) $sections[ $heading ] );
            $parts[] = '## ' . $heading . ( '' !== $body ? "\n\n" . $body : '' );
        }
        return implode( "\n\n", $parts ) . "\n";
    }

    /**
     * The stored edits, or an empty structure.
     *
     * @return array{sections:array<string,string>,removed:string[]}
     */
    private function manual_overrides() {
        $stored = get_option( self::MANUAL_OPT, array() );
        return array(
            'sections' => ( is_array( $stored ) && is_array( $stored['sections'] ?? null ) ) ? $stored['sections'] : array(),
            'removed'  => ( is_array( $stored ) && is_array( $stored['removed'] ?? null ) ) ? $stored['removed'] : array(),
        );
    }

    /** Does the owner have any edits saved? @return bool */
    public function has_manual_edits() {
        $o = $this->manual_overrides();
        return ! empty( $o['sections'] ) || ! empty( $o['removed'] );
    }

    /**
     * The file as served: generated fresh, with the owner's edits laid back on.
     *
     * @return string
     */
    public function merged_content() {
        $generated = $this->build_content();
        $overrides = $this->manual_overrides();

        if ( empty( $overrides['sections'] ) && empty( $overrides['removed'] ) ) {
            return $generated;
        }

        $auto  = $this->split_sections( $generated );
        $order = array_keys( $auto );

        foreach ( $overrides['sections'] as $heading => $body ) {
            $auto[ $heading ] = $body;
            // An edited section the generator no longer produces still belongs
            // in the file: the owner put it there deliberately.
            if ( ! in_array( $heading, $order, true ) ) {
                $order[] = $heading;
            }
        }
        foreach ( $overrides['removed'] as $heading ) {
            unset( $auto[ $heading ] );
        }

        return $this->join_sections( $auto, $order );
    }

    /**
     * Save an edited file, keeping only what differs from the generated one.
     *
     * Stored without HTML stripping on purpose: llms.txt is plain text served
     * as text/plain, never rendered as markup, so removing angle brackets would
     * corrupt legitimate content such as a code sample.
     *
     * @param string $content Full file contents as edited.
     * @return array
     */
    public function save_manual( $content ) {
        $content = trim( (string) $content );
        if ( '' === $content ) {
            return $this->disable_manual();
        }

        $auto   = $this->split_sections( $this->build_content() );
        $theirs = $this->split_sections( $content );

        $sections = array();
        foreach ( $theirs as $heading => $body ) {
            $body = trim( $body );
            if ( ! isset( $auto[ $heading ] ) || trim( $auto[ $heading ] ) !== $body ) {
                $sections[ $heading ] = $body;
            }
        }
        $removed = array_values( array_diff( array_keys( $auto ), array_keys( $theirs ), array( '__head__' ) ) );

        $this->write_manual( array( 'sections' => $sections, 'removed' => $removed ) );
        delete_transient( 'asgm_llms_txt' );
        $this->purge_public_file();

        return array(
            'success'        => true,
            'manual_enabled' => ! empty( $sections ) || ! empty( $removed ),
            'edited_sections'=> array_values( array_map( function ( $k ) {
                return '__head__' === $k ? 'Summary' : $k;
            }, array_keys( $sections ) ) ),
            'manual_content' => $this->merged_content(),
        );
    }

    /**
     * Discard the edits and go back to a purely generated file.
     *
     * @return array
     */
    public function disable_manual() {
        $this->write_manual( array( 'sections' => array(), 'removed' => array() ) );
        delete_transient( 'asgm_llms_txt' );
        $this->purge_public_file();

        return array(
            'success'         => true,
            'manual_enabled'  => false,
            'edited_sections' => array(),
            'manual_content'  => $this->merged_content(),
        );
    }

    /**
     * Persist the edits, guaranteeing the row is autoloaded.
     *
     * @param array $value Override structure.
     * @return void
     */
    private function write_manual( $value ) {
        // add_option is the only way to set autoload on a row that already
        // exists with it off, so the old row goes first.
        delete_option( self::MANUAL_OPT );
        add_option( self::MANUAL_OPT, $value, '', 'yes' );

        // Retire the options the first two attempts used.
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

        $content = $this->merged_content();
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
     * The Guides section: the site's best content, posts and pages both.
     *
     * Selection order, strongest evidence of value first:
     *
     *   1. Anything whose owner wrote an llms.txt entry in the per-page
     *      panel (_asgm_llms_description). Writing that entry is the owner
     *      saying "I want this in the file", so it always appears. Before
     *      this rule the panel saved a description that only surfaced if
     *      the post also happened to rank in the fallback below, which
     *      made the panel look like it did nothing.
     *   2. The highest citability scores (_asgm_citability_score).
     *   3. The most Search Console impressions (asgm_gsc_page_data).
     *   4. Freshest published content, so a new site with no scores and
     *      no Search Console history still gets a sensible list.
     *
     * The old heuristic was top posts by comment count. Comments measure
     * how chatty a post's readers are, not how good the content is, and
     * most business sites have comments off entirely, which silently
     * froze the list to an arbitrary ten.
     *
     * @return array
     */
    private function get_top_posts() {
        // Utility pages never belong in Guides: real, necessary pages
        // that answer no search query. Same boundary the topical map
        // draws. Applied to pages only, a post about affiliate marketing
        // is content, an Affiliate Terms page is plumbing.
        $utility_slug = '#(^|-)(privacy|terms|conditions|cookie|cookies|legal|disclaimer|refund|returns|checkout|cart|basket|account|login|register|signup|thank|thanks|confirmation|receipt|order|orders|transaction|affiliate|affiliates|contact|sitemap|success|failed|password|unsubscribe)(-|$)#i';

        // Search Console impressions, keyed by permalink.
        $gsc = array();
        foreach ( (array) get_option( 'asgm_gsc_page_data', array() ) as $row ) {
            if ( is_array( $row ) && ! empty( $row['url'] ) ) {
                $gsc[ untrailingslashit( (string) $row['url'] ) ] = (int) ( $row['impressions'] ?? 0 );
            }
        }

        $candidates = get_posts( array(
            'post_type'        => array( 'post', 'page' ),
            'post_status'      => 'publish',
            'posts_per_page'   => 200,
            'orderby'          => 'date',
            'order'            => 'DESC',
            'suppress_filters' => true,
        ) );

        $authored = array();
        $scored   = array();
        foreach ( $candidates as $post ) {
            if ( 'page' === $post->post_type && preg_match( $utility_slug, (string) $post->post_name ) ) {
                continue;
            }
            $custom = get_post_meta( $post->ID, '_asgm_llms_description', true );
            if ( is_string( $custom ) && '' !== trim( $custom ) ) {
                $authored[] = $post;
                continue;
            }
            $cit  = get_post_meta( $post->ID, '_asgm_citability_score', true );
            $cit  = ( '' !== $cit && null !== $cit ) ? (int) $cit : -1;
            $imps = $gsc[ untrailingslashit( (string) get_permalink( $post ) ) ] ?? 0;
            $scored[] = array( 'post' => $post, 'cit' => $cit, 'imps' => $imps );
        }

        // Citability first, impressions break ties, recency breaks those
        // (candidates arrive newest first and the sort is stable).
        usort( $scored, static function ( $a, $b ) {
            if ( $a['cit'] !== $b['cit'] ) {
                return $b['cit'] <=> $a['cit'];
            }
            return $b['imps'] <=> $a['imps'];
        } );

        // Every authored entry is included (the owner curated those by
        // hand, capped only to keep the file sane), then the best of the
        // rest fill the list to twelve.
        $picked = array_slice( $authored, 0, 30 );
        foreach ( $scored as $row ) {
            if ( count( $picked ) >= 12 ) {
                break;
            }
            $picked[] = $row['post'];
        }

        $posts = array();
        foreach ( $picked as $post ) {
            // The author's own llms.txt entry wins over the excerpt.
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
