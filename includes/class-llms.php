<?php
/**
 * llms.txt generator.
 *
 * Generates a spec-compliant llms.txt file following the official structure
 * proposed by Jeremy Howard (llmstxt.org):
 *   H1 → Blockquote → Free-form context → H2 sections → Optional
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

        // The served file is cached in a day-long transient, so the per-post
        // "llms.txt entry" toggle in the editor must bust it or the change
        // sits invisible for up to 24 hours and the toggle reads as broken.
        $bust_on_flag = function ( $meta_id, $post_id, $meta_key ) {
            if ( '_asgm_disable_llms' === $meta_key || '_asgm_llms_description' === $meta_key ) {
                delete_transient( 'asgm_llms_txt' );
            }
        };
        add_action( 'updated_post_meta', $bust_on_flag, 10, 3 );
        add_action( 'added_post_meta', $bust_on_flag, 10, 3 );
        add_action( 'deleted_post_meta', $bust_on_flag, 10, 3 );
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
        $resolutions = get_option( 'asgm_conflict_resolutions', array() );
        if ( 'defer' === ( $resolutions['llms_txt_file'] ?? '' ) ) {
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
     * The result is that correcting the summary line leaves generated content
     * type sections updating themselves, and newly published content appears.
     *
     * Stored shape:
     *   [ 'sections' => [ '__head__' => "...", 'Posts' => "...", ... ],
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
     * Structure: H1 → Blockquote → provenance → one section per public
     * post type → one section per public taxonomy → Optional.
     *
     * Section names come from WordPress itself. A shop therefore gets
     * Products, an EDD site gets Downloads, and a site that registers Case
     * Studies gets Case Studies. We must not impose an editorial label such
     * as "Guides" on somebody else's content model.
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
        }

        $lines[] = 'Generated by AEO God Mode. This llms.txt file is provided for AI systems that choose to read it.';
        $lines[] = '';

        foreach ( $this->get_content_sections() as $section ) {
            $lines[] = '## ' . $section['label'];
            $lines[] = '';
            foreach ( $section['entries'] as $entry ) {
                $desc = ! empty( $entry['desc'] ) ? ': ' . $entry['desc'] : '';
                $lines[] = '- [' . $entry['title'] . '](' . $entry['url'] . ')' . $desc;
            }
            $lines[] = '';
        }

        foreach ( $this->get_taxonomy_sections() as $section ) {
            $lines[] = '## ' . $section['label'];
            $lines[] = '';
            foreach ( $section['entries'] as $entry ) {
                $lines[] = '- [' . $entry['title'] . '](' . $entry['url'] . ')';
            }
            $lines[] = '';
        }

        $lines[] = '## Optional';
        $lines[] = '';
        $lines[] = '- [Sitemap index](' . $this->get_sitemap_url() . ')';

        return implode( "\n", $lines );
    }

    /**
     * Build sections for every public, front-end post type registered by the
     * site. Built-ins are ordered Pages, Posts; plugin/custom types follow by
     * their singular/plural label.
     *
     * @return array
     */
    private function get_content_sections() {
        $objects = get_post_types( array( 'public' => true ), 'objects' );
        unset( $objects['attachment'] );

        uasort( $objects, static function ( $a, $b ) {
            $priority = array( 'page' => 0, 'post' => 1 );
            $a_rank = $priority[ $a->name ] ?? 2;
            $b_rank = $priority[ $b->name ] ?? 2;
            return $a_rank === $b_rank
                ? strcasecmp( (string) $a->labels->name, (string) $b->labels->name )
                : $a_rank <=> $b_rank;
        } );

        $sections = array();
        foreach ( $objects as $object ) {
            if ( ! is_post_type_viewable( $object ) ) {
                continue;
            }
            $entries = $this->get_entries_for_post_type( $object->name );
            if ( empty( $entries ) ) {
                continue;
            }
            $sections[] = array(
                'label'   => $object->labels->name,
                'entries' => $entries,
            );
        }

        return apply_filters( 'asgm_llms_content_sections', $sections, $objects );
    }

    /** @return array */
    private function get_entries_for_post_type( $post_type ) {
        $posts = get_posts( array(
            'post_type'        => $post_type,
            'post_status'      => 'publish',
            'posts_per_page'   => 100,
            'orderby'          => 'date',
            'order'            => 'DESC',
            'suppress_filters' => false,
        ) );

        // A static front page should be represented as Home, matching the
        // common Yoast output, even when it is older than the newest pages.
        if ( 'page' === $post_type && 'page' === get_option( 'show_on_front' ) ) {
            $front_id = (int) get_option( 'page_on_front' );
            $front = $front_id ? get_post( $front_id ) : null;
            if ( $front && 'publish' === $front->post_status ) {
                $posts = array_values( array_filter( $posts, static function ( $post ) use ( $front_id ) {
                    return (int) $post->ID !== $front_id;
                } ) );
                array_unshift( $posts, $front );
                $posts = array_slice( $posts, 0, 12 );
            }
        }

        // Owner-curated entries come first within their real content type,
        // while retaining WordPress's order inside each group.
        $front_id = ( 'page' === $post_type ) ? (int) get_option( 'page_on_front' ) : 0;
        $front    = array();
        $curated  = array();
        $regular  = array();
        foreach ( $posts as $post ) {
            if ( $front_id && $front_id === (int) $post->ID ) {
                $front[] = $post;
            } elseif ( '' !== trim( (string) get_post_meta( $post->ID, '_asgm_llms_description', true ) ) ) {
                $curated[] = $post;
            } else {
                $regular[] = $post;
            }
        }
        $posts = array_merge( $front, $curated, $regular );

        $entries = array();
        foreach ( $posts as $post ) {
            if ( get_post_meta( $post->ID, '_asgm_disable_llms', true ) ) {
                continue;
            }
            $custom = trim( (string) get_post_meta( $post->ID, '_asgm_llms_description', true ) );
            $entries[] = array(
                'title' => ( 'page' === $post_type && (int) get_option( 'page_on_front' ) === (int) $post->ID ) ? 'Home' : get_the_title( $post ),
                'url'   => get_permalink( $post ),
                'desc'  => $custom ? wp_strip_all_tags( $custom ) : '',
            );
            if ( count( $entries ) >= 12 ) {
                break;
            }
        }
        return $entries;
    }

    /** @return array */
    private function get_taxonomy_sections() {
        $post_types = array_keys( get_post_types( array( 'public' => true ), 'objects' ) );
        $objects = get_taxonomies( array( 'public' => true ), 'objects' );
        unset( $objects['post_format'] );
        $sections = array();

        foreach ( $objects as $object ) {
            if ( empty( array_intersect( (array) $object->object_type, $post_types ) ) ) {
                continue;
            }
            $terms = get_terms( array(
                'taxonomy'   => $object->name,
                'hide_empty' => true,
                'number'     => 10,
                'orderby'    => 'count',
                'order'      => 'DESC',
            ) );
            if ( is_wp_error( $terms ) || empty( $terms ) ) {
                continue;
            }
            $entries = array();
            foreach ( $terms as $term ) {
                if ( 'category' === $object->name && 'uncategorized' === $term->slug ) {
                    continue;
                }
                $url = get_term_link( $term );
                if ( ! is_wp_error( $url ) ) {
                    $entries[] = array( 'title' => $term->name, 'url' => $url );
                }
            }
            if ( $entries ) {
                $sections[] = array( 'label' => $object->labels->name, 'entries' => $entries );
            }
        }
        return apply_filters( 'asgm_llms_taxonomy_sections', $sections, $objects );
    }

    /** @return string */
    private function get_sitemap_url() {
        if ( defined( 'WPSEO_VERSION' ) ) {
            return home_url( '/sitemap_index.xml' );
        }
        return home_url( '/wp-sitemap.xml' );
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
            // Per-post opt-out from llms.txt (editor sidebar toggle).
            if ( get_post_meta( $post->ID, '_asgm_disable_llms', true ) ) {
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
