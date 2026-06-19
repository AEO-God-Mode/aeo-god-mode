<?php
/**
 * Open Knowledge Format (OKF) Publisher.
 *
 * Generates a spec-conformant OKF v0.1 bundle from published content and serves
 * it live at /okf/ as a directory of Markdown files (one concept per page).
 *
 * OKF v0.1 (Google Cloud, Apache-2.0):
 *   - Each non-reserved .md file is a concept with YAML frontmatter.
 *   - Required frontmatter: `type`. Recommended: title, description, resource,
 *     tags, timestamp.
 *   - Reserved files: index.md (directory listing, carries okf_version) and
 *     log.md (change history).
 *   - Relationships are plain markdown links (bundle-root or relative); the
 *     kind of relationship lives in the prose.
 *
 * @package AISEOGodMode
 */

namespace AISEOGodMode;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Builds and serves the site's OKF bundle.
 */
class OKF {

    const OKF_VERSION   = '0.1';
    const BUNDLE_OPTION = 'asgm_okf_bundle';
    const LOG_OPTION    = 'asgm_okf_log';
    const GEN_OPTION    = 'asgm_okf_last_generated';
    const DIRTY_OPTION  = 'asgm_okf_dirty';

    /**
     * Post types included in the bundle.
     *
     * @return array
     */
    private function included_types() {
        $types = array( 'post', 'page' );
        // Include public custom post types (e.g. docs, products) so the bundle
        // reflects everything the site actually publishes.
        $cpts = get_post_types( array( 'public' => true, '_builtin' => false ), 'names' );
        foreach ( $cpts as $cpt ) {
            if ( ! in_array( $cpt, array( 'attachment' ), true ) ) {
                $types[] = $cpt;
            }
        }
        /** Allow sites to tailor what enters the bundle. */
        return apply_filters( 'asgm_okf_post_types', array_values( array_unique( $types ) ) );
    }

    public function __construct() {
        // Runs inside 'init' (boot_modules) so add the rule directly.
        $this->add_rewrite_rules();
        add_filter( 'query_vars', array( $this, 'add_query_vars' ) );
        add_action( 'template_redirect', array( $this, 'serve' ) );

        // Don't let WP append a trailing-slash redirect on our markdown paths.
        add_filter( 'redirect_canonical', function ( $redirect_url, $requested_url ) {
            if ( get_query_var( 'asgm_okf' ) !== '' ) {
                return false;
            }
            return $redirect_url;
        }, 10, 2 );

        // Mark the bundle dirty when content changes; rebuild lazily on next
        // serve (and via a debounced single event so admin stats stay fresh).
        foreach ( array( 'save_post', 'deleted_post', 'trashed_post', 'untrashed_post' ) as $hook ) {
            add_action( $hook, array( $this, 'mark_dirty' ) );
        }
        add_action( 'asgm_okf_regen_event', array( $this, 'regenerate' ) );
    }

    public function add_query_vars( $vars ) {
        $vars[] = 'asgm_okf';
        return $vars;
    }

    public function add_rewrite_rules() {
        add_rewrite_rule( '^okf\.zip$', 'index.php?asgm_okf=__zip', 'top' );
        add_rewrite_rule( '^okf/?$', 'index.php?asgm_okf=index.md', 'top' );
        add_rewrite_rule( '^okf/(.+?)/?$', 'index.php?asgm_okf=$matches[1]', 'top' );
    }

    /**
     * Flag the bundle for regeneration on the next serve, and schedule a
     * debounced rebuild so the admin graph/stats refresh without a visit.
     *
     * @param int $post_id Post ID.
     */
    public function mark_dirty( $post_id = 0 ) {
        if ( $post_id && ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) ) {
            return;
        }
        update_option( self::DIRTY_OPTION, 1 );
        if ( ! wp_next_scheduled( 'asgm_okf_regen_event' ) ) {
            wp_schedule_single_event( time() + 30, 'asgm_okf_regen_event' );
        }
    }

    /**
     * Serve a file from the bundle.
     */
    public function serve() {
        $path = get_query_var( 'asgm_okf' );
        if ( '' === $path && isset( $_GET['okf'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $path = sanitize_text_field( wp_unslash( $_GET['okf'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        }
        if ( '' === $path || null === $path ) {
            return;
        }

        $bundle = $this->get_bundle();

        if ( '__zip' === $path || 'bundle.zip' === $path ) {
            $this->output_zip( $bundle );
            return;
        }

        $path = ltrim( $path, '/' );
        if ( '' === $path ) {
            $path = 'index.md';
        }

        if ( isset( $bundle['files'][ $path ] ) ) {
            $this->output_markdown( $bundle['files'][ $path ] );
        }

        status_header( 404 );
        $this->output_markdown( "---\ntype: error\ntitle: Not found\n---\n\n# 404\n\n`{$path}` is not part of this OKF bundle. See [the index](/okf/)." );
    }

    /**
     * Emit markdown with the right headers and stop.
     *
     * @param string $content Markdown.
     */
    private function output_markdown( $content ) {
        if ( ! headers_sent() ) {
            header( 'Content-Type: text/markdown; charset=utf-8' );
            header( 'X-Robots-Tag: noindex' );
            header( 'Access-Control-Allow-Origin: *' );
        }
        echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- raw markdown by design.
        exit;
    }

    /**
     * Stream the whole bundle as a .zip (git-cloneable / shippable).
     *
     * @param array $bundle Bundle.
     */
    private function output_zip( $bundle ) {
        if ( ! class_exists( 'ZipArchive' ) ) {
            status_header( 501 );
            $this->output_markdown( "# Zip unavailable\n\nThis server has no ZipArchive support. Fetch files individually from [the index](/okf/)." );
        }
        $tmp = wp_tempnam( 'okf-bundle' );
        $zip = new \ZipArchive();
        $zip->open( $tmp, \ZipArchive::OVERWRITE );
        foreach ( $bundle['files'] as $rel => $content ) {
            $zip->addFromString( 'okf/' . $rel, $content );
        }
        $zip->close();
        $data = file_get_contents( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
        @unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        if ( ! headers_sent() ) {
            header( 'Content-Type: application/zip' );
            header( 'Content-Disposition: attachment; filename="okf-bundle.zip"' );
            header( 'Content-Length: ' . strlen( $data ) );
        }
        echo $data; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        exit;
    }

    /**
     * Get the bundle, rebuilding if missing or dirty.
     *
     * @return array
     */
    public function get_bundle() {
        $bundle = get_option( self::BUNDLE_OPTION );
        if ( empty( $bundle ) || ! empty( get_option( self::DIRTY_OPTION ) ) ) {
            $bundle = $this->build_bundle();
        }
        return $bundle;
    }

    /**
     * Force a rebuild (manual "Generate now" + scheduled event).
     *
     * @return array Status.
     */
    public function regenerate() {
        $this->build_bundle();
        return $this->get_status();
    }

    /**
     * Status payload for the admin UI / REST.
     *
     * @return array
     */
    public function get_status() {
        $bundle = get_option( self::BUNDLE_OPTION );
        if ( empty( $bundle ) ) {
            $bundle = $this->build_bundle();
        }
        $meta = isset( $bundle['meta'] ) ? $bundle['meta'] : array();
        return array(
            'okf_version'    => self::OKF_VERSION,
            'concepts'       => isset( $meta['concepts'] ) ? (int) $meta['concepts'] : 0,
            'pages'          => isset( $meta['pages'] ) ? (int) $meta['pages'] : 0,
            'links'          => isset( $meta['links'] ) ? (int) $meta['links'] : 0,
            'conformant'     => isset( $meta['conformant'] ) ? (bool) $meta['conformant'] : false,
            'graph'          => isset( $meta['graph'] ) ? $meta['graph'] : array( 'nodes' => array(), 'edges' => array() ),
            'last_generated' => get_option( self::GEN_OPTION, '' ),
            'url'            => home_url( '/okf/' ),
            'url_fallback'   => home_url( '/?okf=index.md' ),
            'url_zip'        => home_url( '/okf.zip' ),
        );
    }

    /**
     * Build the full bundle from published content.
     *
     * @return array { files: [path => markdown], meta: {...} }
     */
    public function build_bundle() {
        $types = $this->included_types();

        $query = new \WP_Query( array(
            'post_type'      => $types,
            'post_status'    => 'publish',
            'posts_per_page' => 5000,
            'orderby'        => 'modified',
            'order'          => 'DESC',
            'no_found_rows'  => true,
        ) );

        $posts = $query->posts;

        // Map permalink -> bundle path so we can rewrite internal links into
        // bundle-relative markdown links (this is what builds the graph).
        $path_for      = array();
        $title_for     = array();
        $type_for      = array();
        foreach ( $posts as $p ) {
            $rel               = $this->concept_path( $p );
            $path_for[ $p->ID ] = $rel;
            $title_for[ $p->ID ] = $p->post_title;
            $type_for[ $p->ID ] = $p->post_type;
        }

        $files = array();
        $nodes = array();
        $edges = array();
        $link_count = 0;
        $conformant = true;

        foreach ( $posts as $p ) {
            $rel  = $path_for[ $p->ID ];
            $type = $p->post_type;
            $tags = $this->collect_tags( $p );

            // Convert body, rewriting internal links to bundle paths + counting edges.
            list( $body_md, $targets ) = $this->render_body( $p, $path_for );
            foreach ( $targets as $target_id ) {
                $edges[] = array( 'source' => $rel, 'target' => $path_for[ $target_id ] );
                $link_count++;
            }

            $front = $this->frontmatter( array(
                'type'        => $type,
                'title'       => $p->post_title,
                'description' => $this->description_for( $p ),
                'resource'    => get_permalink( $p ),
                'tags'        => $tags,
                'timestamp'   => get_post_modified_time( 'c', true, $p ),
            ) );

            if ( '' === trim( $type ) ) {
                $conformant = false; // type is the one required field.
            }

            $files[ $rel ] = $front . "\n" . $body_md . "\n";

            $nodes[] = array(
                'id'    => $rel,
                'label' => html_entity_decode( wp_strip_all_tags( $p->post_title ), ENT_QUOTES ),
                'type'  => $type,
                'url'   => get_permalink( $p ),
            );
        }

        // Reserved files.
        $files['index.md'] = $this->build_index( $posts, $path_for, $type_for );
        $files['log.md']   = $this->build_log( count( $files ), $link_count );

        $meta = array(
            'concepts'   => count( $posts ),
            'pages'      => count( $posts ),
            'links'      => $link_count,
            'conformant' => $conformant,
            'graph'      => array( 'nodes' => $nodes, 'edges' => $edges ),
        );

        $bundle = array( 'files' => $files, 'meta' => $meta );

        update_option( self::BUNDLE_OPTION, $bundle, false );
        update_option( self::GEN_OPTION, current_time( 'mysql', true ) );
        delete_option( self::DIRTY_OPTION );

        return $bundle;
    }

    /**
     * Bundle-relative path for a post's concept file, e.g. posts/hello-world.md.
     *
     * @param \WP_Post $p Post.
     * @return string
     */
    private function concept_path( $p ) {
        $dir  = sanitize_title( $p->post_type ) . 's';
        $slug = $p->post_name ? $p->post_name : sanitize_title( $p->post_title );
        if ( '' === $slug ) {
            $slug = 'post-' . $p->ID;
        }
        return $dir . '/' . $slug . '.md';
    }

    /**
     * Build a concept's category + tag list.
     *
     * @param \WP_Post $p Post.
     * @return array
     */
    private function collect_tags( $p ) {
        $out  = array();
        $taxes = get_object_taxonomies( $p->post_type );
        foreach ( $taxes as $tax ) {
            if ( in_array( $tax, array( 'post_format' ), true ) ) {
                continue;
            }
            $terms = get_the_terms( $p->ID, $tax );
            if ( is_array( $terms ) ) {
                foreach ( $terms as $t ) {
                    $out[] = $t->name;
                }
            }
        }
        return array_values( array_unique( $out ) );
    }

    /**
     * Best available one-line description for a concept.
     *
     * @param \WP_Post $p Post.
     * @return string
     */
    private function description_for( $p ) {
        $candidates = array(
            get_post_meta( $p->ID, '_yoast_wpseo_metadesc', true ),
            get_post_meta( $p->ID, 'rank_math_description', true ),
            get_post_meta( $p->ID, '_asgm_meta_description', true ),
            get_post_meta( $p->ID, '_aioseo_description', true ),
            has_excerpt( $p ) ? get_the_excerpt( $p ) : '',
        );
        foreach ( $candidates as $c ) {
            $c = trim( wp_strip_all_tags( (string) $c ) );
            if ( '' !== $c ) {
                return $this->one_line( $c, 200 );
            }
        }
        // Fall back to the first sentence of the content.
        $text = trim( wp_strip_all_tags( strip_shortcodes( $p->post_content ) ) );
        return $this->one_line( $text, 200 );
    }

    private function one_line( $text, $max ) {
        $text = preg_replace( '/\s+/', ' ', (string) $text );
        if ( mb_strlen( $text ) > $max ) {
            $text = mb_substr( $text, 0, $max - 1 ) . '…';
        }
        return $text;
    }

    /**
     * Emit a YAML frontmatter block. Empty fields are omitted.
     *
     * @param array $fields Field map.
     * @return string
     */
    private function frontmatter( $fields ) {
        $lines = array( '---' );
        foreach ( $fields as $key => $val ) {
            if ( 'tags' === $key ) {
                if ( empty( $val ) ) {
                    continue;
                }
                $lines[] = 'tags:';
                foreach ( $val as $t ) {
                    $lines[] = '  - ' . $this->yaml_scalar( $t );
                }
                continue;
            }
            if ( null === $val || '' === $val ) {
                continue;
            }
            $lines[] = $key . ': ' . $this->yaml_scalar( $val );
        }
        $lines[] = '---';
        return implode( "\n", $lines );
    }

    /**
     * Quote a YAML scalar when needed.
     *
     * @param string $v Value.
     * @return string
     */
    private function yaml_scalar( $v ) {
        $v = (string) $v;
        $v = str_replace( array( "\r", "\n" ), ' ', $v );
        if ( preg_match( '/^[A-Za-z0-9 ._\/:+\-]+$/u', $v ) && '' !== trim( $v ) ) {
            // Still quote if it could be misread as a YAML type.
            if ( ! preg_match( '/^(true|false|null|yes|no|on|off|\d+(\.\d+)?)$/i', $v ) ) {
                return $v;
            }
        }
        return '"' . str_replace( '"', '\\"', $v ) . '"';
    }

    /**
     * Render a post body to Markdown, rewriting internal links to bundle paths.
     *
     * @param \WP_Post $p        Post.
     * @param array    $path_for Map of post ID => bundle path.
     * @return array [ markdown, array of linked target post IDs ]
     */
    private function render_body( $p, $path_for ) {
        $html = apply_filters( 'the_content', $p->post_content );
        $html = strip_shortcodes( $html );

        $targets = array();

        if ( '' === trim( $html ) ) {
            return array( '', $targets );
        }

        $dom = new \DOMDocument();
        libxml_use_internal_errors( true );
        $dom->loadHTML( '<?xml encoding="utf-8"?><div id="okf-root">' . $html . '</div>' );
        libxml_clear_errors();

        // Rewrite internal <a> links to bundle paths and record edges.
        $anchors = $dom->getElementsByTagName( 'a' );
        foreach ( $anchors as $a ) {
            $href = $a->getAttribute( 'href' );
            if ( ! $href ) {
                continue;
            }
            $target_id = url_to_postid( $href );
            if ( $target_id && isset( $path_for[ $target_id ] ) && $target_id !== $p->ID ) {
                $a->setAttribute( 'href', '/' . $path_for[ $target_id ] );
                $targets[] = $target_id;
            }
        }

        $root = $dom->getElementById( 'okf-root' );
        $md   = $root ? $this->node_to_md( $root ) : '';
        $md   = preg_replace( "/\n{3,}/", "\n\n", trim( $md ) );

        return array( $md, array_values( array_unique( $targets ) ) );
    }

    /**
     * Minimal, dependency-free HTML→Markdown for the common tags.
     *
     * @param \DOMNode $node Node.
     * @return string
     */
    private function node_to_md( $node ) {
        $out = '';
        foreach ( $node->childNodes as $child ) {
            if ( XML_TEXT_NODE === $child->nodeType ) {
                $out .= preg_replace( '/\s+/', ' ', $child->nodeValue );
                continue;
            }
            if ( XML_ELEMENT_NODE !== $child->nodeType ) {
                continue;
            }
            $tag   = strtolower( $child->nodeName );
            $inner = $this->node_to_md( $child );
            switch ( $tag ) {
                case 'h1': $out .= "\n\n# " . trim( $inner ) . "\n\n"; break;
                case 'h2': $out .= "\n\n## " . trim( $inner ) . "\n\n"; break;
                case 'h3': $out .= "\n\n### " . trim( $inner ) . "\n\n"; break;
                case 'h4': $out .= "\n\n#### " . trim( $inner ) . "\n\n"; break;
                case 'h5':
                case 'h6': $out .= "\n\n##### " . trim( $inner ) . "\n\n"; break;
                case 'p':  $out .= "\n\n" . trim( $inner ) . "\n\n"; break;
                case 'br': $out .= "  \n"; break;
                case 'hr': $out .= "\n\n---\n\n"; break;
                case 'strong':
                case 'b':  $out .= '**' . trim( $inner ) . '**'; break;
                case 'em':
                case 'i':  $out .= '*' . trim( $inner ) . '*'; break;
                case 'code': $out .= '`' . trim( $inner ) . '`'; break;
                case 'pre': $out .= "\n\n```\n" . trim( $child->textContent ) . "\n```\n\n"; break;
                case 'blockquote':
                    $q = trim( $inner );
                    $out .= "\n\n" . preg_replace( '/^/m', '> ', $q ) . "\n\n";
                    break;
                case 'a':
                    $href = $child->getAttribute( 'href' );
                    $txt  = trim( $inner );
                    $out .= $href ? '[' . $txt . '](' . $href . ')' : $txt;
                    break;
                case 'ul':
                case 'ol':
                    $out .= "\n\n" . $this->list_to_md( $child, $tag ) . "\n\n";
                    break;
                case 'img':
                    $alt = $child->getAttribute( 'alt' );
                    $src = $child->getAttribute( 'src' );
                    if ( $src ) {
                        $out .= '![' . $alt . '](' . $src . ')';
                    }
                    break;
                case 'table':
                    $out .= "\n\n" . $this->table_to_md( $child ) . "\n\n";
                    break;
                case 'script':
                case 'style':
                case 'noscript':
                    break;
                default:
                    $out .= $inner;
            }
        }
        return $out;
    }

    private function list_to_md( $node, $type, $depth = 0 ) {
        $lines = array();
        $i     = 1;
        foreach ( $node->childNodes as $li ) {
            if ( XML_ELEMENT_NODE === $li->nodeType && 'li' === strtolower( $li->nodeName ) ) {
                $marker = ( 'ol' === $type ) ? ( $i . '.' ) : '-';
                $text   = trim( $this->node_to_md( $li ) );
                $text   = preg_replace( "/\n+/", ' ', $text );
                $lines[] = str_repeat( '  ', $depth ) . $marker . ' ' . $text;
                $i++;
            }
        }
        return implode( "\n", $lines );
    }

    private function table_to_md( $table ) {
        $rows = array();
        foreach ( $table->getElementsByTagName( 'tr' ) as $tr ) {
            $cells = array();
            foreach ( $tr->childNodes as $cell ) {
                if ( XML_ELEMENT_NODE === $cell->nodeType && in_array( strtolower( $cell->nodeName ), array( 'td', 'th' ), true ) ) {
                    $cells[] = trim( preg_replace( '/\s+/', ' ', $this->node_to_md( $cell ) ) );
                }
            }
            if ( $cells ) {
                $rows[] = '| ' . implode( ' | ', $cells ) . ' |';
            }
        }
        if ( count( $rows ) > 1 ) {
            $col_count = substr_count( $rows[0], '|' ) - 1;
            $divider   = '| ' . implode( ' | ', array_fill( 0, max( 1, $col_count ), '---' ) ) . ' |';
            array_splice( $rows, 1, 0, $divider );
        }
        return implode( "\n", $rows );
    }

    /**
     * Build the reserved index.md (directory listing + okf_version).
     *
     * @param array $posts    Posts.
     * @param array $path_for ID => path.
     * @param array $type_for ID => type.
     * @return string
     */
    private function build_index( $posts, $path_for, $type_for ) {
        $site = get_bloginfo( 'name' );
        $out  = "---\nokf_version: \"" . self::OKF_VERSION . "\"\n---\n\n";
        $out .= '# ' . $site . " — Knowledge Bundle\n\n";
        $out .= 'Open Knowledge Format bundle generated from the published content of ' . home_url() . ". One file per concept. Reserved files: this index and [log.md](/log.md).\n\n";

        // Group by type.
        $by_type = array();
        foreach ( $posts as $p ) {
            $by_type[ $p->post_type ][] = $p;
        }
        foreach ( $by_type as $type => $list ) {
            $label = ucfirst( $type ) . 's';
            $out  .= "\n## " . $label . "\n\n";
            foreach ( $list as $p ) {
                $title = html_entity_decode( wp_strip_all_tags( $p->post_title ), ENT_QUOTES );
                $out  .= '- [' . $title . '](/' . $path_for[ $p->ID ] . ')' . "\n";
            }
        }
        return $out;
    }

    /**
     * Build the reserved log.md (change history of bundle generations).
     *
     * @param int $file_count Number of files.
     * @param int $link_count Number of internal links.
     * @return string
     */
    private function build_log( $file_count, $link_count ) {
        $log = get_option( self::LOG_OPTION, array() );
        if ( ! is_array( $log ) ) {
            $log = array();
        }
        array_unshift( $log, array(
            'time'  => current_time( 'mysql', true ),
            'files' => $file_count,
            'links' => $link_count,
        ) );
        $log = array_slice( $log, 0, 50 );
        update_option( self::LOG_OPTION, $log, false );

        $out = "# Change log\n\nBundle regeneration history (most recent first). The bundle rebuilds automatically when content is published, updated, or removed.\n\n";
        foreach ( $log as $entry ) {
            $out .= '- **' . $entry['time'] . " UTC** — " . $entry['files'] . ' files, ' . $entry['links'] . " internal links\n";
        }
        return $out;
    }
}
