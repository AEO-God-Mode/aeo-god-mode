<?php
/**
 * Content Health: the deterministic defects that stop a page being understood.
 *
 * Free tells you whether AI search can access, understand and extract your
 * content. This is the "understand" half: a page with three H1s has no single
 * stated subject, a page with no meta description hands the engine nothing to
 * quote in a snippet, and an image with no alt text is content that simply is
 * not there for anything reading text.
 *
 * Every check here is local and deterministic. No AI call, no network request,
 * nothing to bill. That is deliberate: these are the findings a site owner
 * should get for free, and Pro earns its money by fixing what this finds.
 *
 * Scope note: broken links (Bing's "Http 400-499 errors") are NOT here. That is
 * Link_Health's job and it does it better, because it can also offer the fix.
 *
 * HTML is read with WP_HTML_Tag_Processor, never with a regular expression. A
 * regex cannot tell an <h1> inside an HTML comment from a real one, and every
 * Gutenberg block delimiter is an HTML comment, so "comment out the old
 * heading" quietly produced a false finding on ordinary sites. The same class
 * handles script and style raw text, unquoted attribute values, and a ">" that
 * lives inside a quoted attribute value, all of which the old patterns got
 * wrong. WP_HTML_Tag_Processor is WordPress core from 6.2, and this plugin
 * declares "Requires at least: 6.2", so the dependency costs nothing and no
 * fallback path is carried.
 *
 * @package AISEOGodMode
 */

namespace AISEOGodMode;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Content_Health {

    /** Scan state + results. */
    const OPT = 'asgm_content_health';

    /**
     * Posts parsed per batch call.
     *
     * Larger than Link_Health's 12 because nothing here leaves the server: this
     * is a get_post() plus one pass of the HTML tag processor per item, not an
     * HTTP round trip. The batching exists for the same reason regardless, so a
     * 500-post site on shared hosting never sits inside one long request.
     */
    const BATCH = 25;

    /**
     * Ceiling on posts queued, matching Link_Health's harvest limit.
     *
     * A site with more published content than this gets its oldest MAX_POSTS
     * scanned, and public_state() reports the real published total alongside a
     * truncated flag so the client can say so out loud. A diagnostic that
     * silently scans a fifth of a site and prints a clean-looking total is
     * worse than one that admits its ceiling.
     */
    const MAX_POSTS = 500;

    /**
     * Character count above which a title is reported as long.
     *
     * Deliberately described as a rule of thumb everywhere it surfaces. Google
     * and Bing both truncate titles by PIXEL width in the rendered SERP, not by
     * character count, so no character number is a real limit: "Illinois" and
     * "lilliii" are the same length and nowhere near the same width. 60 is the
     * common working figure because it lands near the truncation point for
     * average-width text, and it is the number Bing Webmaster Tools itself uses
     * for its "Title too long" warning.
     *
     * We do not claim a pixel figure anywhere in the UI, because we cannot
     * measure pixels from PHP without knowing the font the SERP renders in.
     */
    const TITLE_MAX = 60;

    /**
     * Longest description still reported as "very short" rather than fine.
     *
     * A one-word value in the meta field is a placeholder somebody typed to
     * silence their SEO plugin, not a snippet. It is reported separately from a
     * missing description and never folded into it: a page storing "Coffee"
     * renders a real meta description, so listing it under "nothing stored"
     * would be a false statement about the user's own site.
     */
    const DESC_SHORT_MAX = 15;

    /**
     * Duplicate-description clusters drawn in the card.
     *
     * Purely a display limit, and it is applied where the drawing happens so
     * the count next to it can disclose the rest. Every number the card
     * headlines is taken from the full cluster list before this is applied.
     */
    const DUPE_CLUSTERS_SHOWN = 20;

    /**
     * Maximum reusable-block nesting followed during one page inspection.
     *
     * Core prevents recursive reusable blocks from rendering forever. We need
     * the same protection locally, plus a hard ceiling for malformed imports
     * that form a very deep acyclic chain. Twelve nested patterns is already an
     * extreme editor shape; 32 leaves ample room without making a scan an
     * unbounded walk.
     */
    const REUSABLE_BLOCK_MAX_DEPTH = 32;

    /**
     * Severity per finding, in one place.
     *
     * public_state() needs this twice, once to build the groups and once to
     * count how many pages carry a real problem as opposed to something merely
     * worth a look. Two lists that can drift apart would eventually disagree.
     */
    const SEVERITY = array(
        'h1_multiple'  => 'problem',
        'h1_in_body'   => 'check',
        'h1_missing'   => 'problem',
        'desc_missing' => 'problem',
        'desc_short'   => 'check',
        'title_long'   => 'check',
        'alt_missing'  => 'problem',
        'alt_empty'    => 'check',
        'alt_featured' => 'problem',
    );

    /** Read the stored state, shaped. */
    public static function get_state() {
        $raw = get_option( self::OPT, array() );
        if ( ! is_array( $raw ) ) {
            $raw = array();
        }
        return array(
            'status'          => (string) ( $raw['status'] ?? 'never' ), // never|scanning|done
            'queue'           => ( isset( $raw['queue'] ) && is_array( $raw['queue'] ) ) ? $raw['queue'] : array(),
            'checked'         => (int) ( $raw['checked'] ?? 0 ),
            'total'           => (int) ( $raw['total'] ?? 0 ),
            'published_total' => (int) ( $raw['published_total'] ?? 0 ),
            'pages'           => ( isset( $raw['pages'] ) && is_array( $raw['pages'] ) ) ? $raw['pages'] : array(),
            'desc_index'      => ( isset( $raw['desc_index'] ) && is_array( $raw['desc_index'] ) ) ? $raw['desc_index'] : array(),
            'has_content'     => ! empty( $raw['has_content'] ),
            'scanned_at'      => (string) ( $raw['scanned_at'] ?? '' ),
        );
    }

    private static function save_state( $state ) {
        update_option( self::OPT, $state, false );
    }

    /**
     * Start a scan: queue every published post and page, drop prior results.
     *
     * Costs two queries, one for the queue and one cached count for the honest
     * site total. The parsing happens batch by batch, so this returns instantly
     * even on a site with hundreds of posts.
     */
    public static function start_scan() {
        $ids = get_posts( array(
            'post_type'        => array( 'post', 'page' ),
            'post_status'      => 'publish',
            'posts_per_page'   => self::MAX_POSTS,
            'fields'           => 'ids',
            'orderby'          => 'ID',
            'order'            => 'ASC',
            'suppress_filters' => true,
        ) );
        $ids   = array_map( 'intval', (array) $ids );
        $state = array(
            'status'          => empty( $ids ) ? 'done' : 'scanning',
            'queue'           => $ids,
            'checked'         => 0,
            'total'           => count( $ids ),
            // What the site actually publishes, as opposed to what this scan
            // will reach. The client needs both to tell the truth about a site
            // bigger than MAX_POSTS.
            'published_total' => self::published_total(),
            'pages'           => array(),
            'desc_index'      => array(),
            // Separate from total on purpose. total counts down as the queue
            // drains in some designs; this one records "there was something to
            // scan" so an empty site can never be reported as a clean site.
            'has_content'     => ! empty( $ids ),
            'scanned_at'      => current_time( 'mysql' ),
        );
        self::save_state( $state );
        return self::public_state( $state );
    }

    /**
     * Published posts and pages on the whole site.
     *
     * wp_count_posts() is cached per post type and reads the same statuses the
     * queue query asks for, so this is a cheap honest denominator rather than a
     * second unbounded ID query.
     */
    private static function published_total() {
        $total = 0;
        foreach ( array( 'post', 'page' ) as $type ) {
            $counts = wp_count_posts( $type );
            $total += isset( $counts->publish ) ? (int) $counts->publish : 0;
        }
        return $total;
    }

    /** Parse the next batch of queued posts. */
    public static function run_batch() {
        $state = self::get_state();
        if ( 'scanning' !== $state['status'] || empty( $state['queue'] ) ) {
            return self::public_state( $state );
        }

        $batch = array_splice( $state['queue'], 0, self::BATCH );
        foreach ( $batch as $post_id ) {
            $state['checked']++;
            $post = get_post( (int) $post_id );
            if ( ! $post ) {
                continue;
            }
            $row = self::inspect( $post );

            // The description index has to see every page, including the clean
            // ones, or "duplicate across pages" cannot be answered: two pages
            // sharing a description are both individually fine.
            if ( '' !== $row['desc'] ) {
                $hash = md5( self::normalise_desc( $row['desc'] ) );
                if ( ! isset( $state['desc_index'][ $hash ] ) ) {
                    $state['desc_index'][ $hash ] = array( 'text' => $row['desc'], 'ids' => array() );
                }
                $state['desc_index'][ $hash ]['ids'][] = (int) $post->ID;
            }

            // Only pages with something to say are stored. On a healthy site
            // this option stays near empty instead of carrying a row per page.
            if ( ! empty( $row['flags'] ) ) {
                unset( $row['desc'] );
                $state['pages'][] = $row;
            }
        }

        if ( empty( $state['queue'] ) ) {
            $state['status'] = 'done';
        }
        self::save_state( $state );
        return self::public_state( $state );
    }

    /**
     * Everything we can determine about one post without rendering it.
     *
     * Reads post_content rather than the_content(). Published synced patterns
     * are expanded because they are saved WordPress content, but shortcodes,
     * dynamic blocks and theme output are not rendered. Running the content
     * filters would pull in every other plugin's oEmbeds and callbacks on a
     * scan of 500 posts, which is both slow and a great way to trigger somebody
     * else's side effects. The cost of that choice is stated honestly in the H1
     * copy: we can count saved content, not the heading a theme adds.
     */
    private static function inspect( $post ) {
        $content = self::expand_reusable_blocks( (string) $post->post_content );
        $flags   = array();
        $html    = self::parse_html( $content );

        // ---- Headings ----
        $h1_count = $html['h1'];

        if ( $h1_count >= 2 ) {
            $flags['h1_multiple'] = $h1_count;
        } elseif ( 1 === $h1_count ) {
            // One H1 in the body is the interesting case and the one we cannot
            // settle from here. Nearly every theme prints the post title as an
            // H1, which would make this the second. Reported as "worth
            // checking" rather than as a defect, because a theme that does not
            // print a title H1 makes this page correct.
            $flags['h1_in_body'] = 1;
        } elseif ( '' === self::trim_text( (string) $post->post_title ) ) {
            // No H1 written and no title for a theme to promote into one. This
            // is the only genuinely certain "missing H1" available locally.
            $flags['h1_missing'] = 1;
        }

        // ---- Meta description ----
        $desc     = self::effective_description( $post->ID );
        $desc_len = self::visible_length( $desc['text'] );
        if ( 0 === $desc_len ) {
            $flags['desc_missing'] = 1;
        } elseif ( $desc_len <= self::DESC_SHORT_MAX ) {
            $flags['desc_short'] = $desc_len;
        }

        // ---- Title length ----
        $title     = self::effective_title( $post );
        $title_len = self::visible_length( $title );
        if ( $title_len > self::TITLE_MAX ) {
            $flags['title_long'] = $title_len;
        }

        // ---- Images ----
        // Three separate findings, because they are three separate decisions.
        // An image with no alt attribute is broken. An image with alt="" may be
        // a correctly marked decorative image or an alt box the author left
        // blank, and nothing stored can tell those apart. A featured image with
        // no alt on the attachment is neither, and is fixed somewhere else.
        if ( $html['img_no_alt'] > 0 ) {
            $flags['alt_missing'] = $html['img_no_alt'];
        }
        if ( $html['img_empty_alt'] > 0 ) {
            $flags['alt_empty'] = $html['img_empty_alt'];
        }
        if ( self::featured_image_missing_alt( $post->ID ) ) {
            $flags['alt_featured'] = 1;
        }

        return array(
            'post_id'    => (int) $post->ID,
            'title'      => (string) $post->post_title,
            'type'       => (string) $post->post_type,
            'title_len'  => $title_len,
            'images'     => (int) $html['img_total'],
            'img_empty'  => (int) $html['img_empty_alt'],
            'desc'       => ( $desc_len > 0 ) ? $desc['text'] : '',
            'desc_len'   => $desc_len,
            'desc_text'  => ( $desc_len > 0 && $desc_len <= self::DESC_SHORT_MAX ) ? $desc['text'] : '',
            // Named so the "no description" row can say which plugin is holding
            // a leftover, without ever counting that leftover as a pass.
            'desc_stale' => (string) $desc['stale_label'],
            'flags'      => $flags,
        );
    }

    /**
     * Expand published core/block references without rendering the page.
     *
     * A synced pattern is not present as HTML in the host post. Gutenberg saves
     * only `<!-- wp:block {"ref":123} /-->`, so scanning post_content verbatim
     * misses every heading and image inside it. parse_blocks() gives us a safe
     * structural walk and lets us resolve only this one core storage primitive.
     * No shortcode, dynamic block, render callback or content filter runs.
     *
     * Cycles are cut at the repeated reference, matching the practical result
     * of core's reusable-block recursion protection. Draft, private, trashed,
     * password-protected and deleted patterns contribute nothing because they
     * do not render for an ordinary visitor.
     *
     * @param string $content Saved block content.
     * @param array  $seen    Reusable block IDs already on this branch.
     * @param int    $depth   Current reusable block depth.
     * @return string HTML fragments that are actually saved to render.
     */
    private static function expand_reusable_blocks( $content, $seen = array(), $depth = 0 ) {
        $content = (string) $content;
        if ( '' === $content || $depth >= self::REUSABLE_BLOCK_MAX_DEPTH ) {
            return '';
        }

        $blocks = parse_blocks( $content );
        $out    = '';
        foreach ( (array) $blocks as $block ) {
            $out .= self::expand_saved_block( $block, $seen, $depth );
        }
        return $out;
    }

    /** Expand one parsed block while preserving its saved static HTML. */
    private static function expand_saved_block( $block, $seen, $depth ) {
        if ( ! is_array( $block ) ) {
            return '';
        }

        if ( 'core/block' === ( $block['blockName'] ?? null ) ) {
            $ref = (int) ( $block['attrs']['ref'] ?? 0 );
            if ( ! $ref || isset( $seen[ $ref ] ) || $depth >= self::REUSABLE_BLOCK_MAX_DEPTH ) {
                return '';
            }

            $pattern = get_post( $ref );
            if (
                ! $pattern
                || 'wp_block' !== $pattern->post_type
                || 'publish' !== $pattern->post_status
                || '' !== (string) $pattern->post_password
            ) {
                return '';
            }

            $seen[ $ref ] = true;
            return self::expand_reusable_blocks( (string) $pattern->post_content, $seen, $depth + 1 );
        }

        $inner_blocks  = (array) ( $block['innerBlocks'] ?? array() );
        $inner_content = (array) ( $block['innerContent'] ?? array() );

        // Freeform HTML has no block wrapper and no innerContent on some older
        // parse_blocks() shapes, so retain innerHTML as the final fallback.
        if ( empty( $inner_content ) ) {
            return (string) ( $block['innerHTML'] ?? '' );
        }

        $out   = '';
        $index = 0;
        foreach ( $inner_content as $fragment ) {
            if ( null !== $fragment ) {
                $out .= (string) $fragment;
                continue;
            }
            if ( isset( $inner_blocks[ $index ] ) ) {
                $out .= self::expand_saved_block( $inner_blocks[ $index ], $seen, $depth );
            }
            $index++;
        }
        return $out;
    }

    /**
     * One pass of the HTML tag processor: H1s and the image alt breakdown.
     *
     * Both findings come from the same walk because both need the same thing,
     * a real tokenizer. The processor skips HTML comments, script and style raw
     * text, and reads unquoted attribute values and values containing ">",
     * which is the whole reason the regular expressions this replaced produced
     * findings that were not on the page.
     *
     * It is a tokenizer and not a tree builder, though, so it walks happily into
     * three kinds of markup a browser never renders, and every tag it finds in
     * there was reported as a real finding on the page. Each is handled below
     * against what Chrome actually does with it, because DOMDocument agrees with
     * neither the tokenizer nor the browser and is no use as a referee.
     */
    private static function parse_html( $content ) {
        $out = array(
            'h1'            => 0,
            'img_total'     => 0,
            'img_no_alt'    => 0,
            'img_empty_alt' => 0,
        );

        if ( '' === self::trim_text( $content ) ) {
            return $out;
        }

        // What we are inside of, if anything: '' | 'rawtext' | 'template'.
        $skip  = '';
        $depth = 0;

        $tags = new \WP_HTML_Tag_Processor( $content );
        while ( $tags->next_tag( array( 'tag_closers' => 'visit' ) ) ) {
            $tag    = $tags->get_tag();
            $closer = $tags->is_tag_closer();

            if ( 'rawtext' === $skip ) {
                // Raw text really is text: a second <noscript> in here is
                // characters, not an element, so the FIRST closer ends the
                // region and counting depth would swallow the rest of the post.
                if ( $closer && 'NOSCRIPT' === $tag ) {
                    $skip = '';
                }
                continue;
            }

            if ( 'template' === $skip ) {
                // A template is ordinary markup parked in a fragment, so unlike
                // raw text it genuinely nests and the depth has to be counted,
                // and a <plaintext> in here is a real element rather than the
                // characters the same tag would be inside raw text.
                if ( 'PLAINTEXT' === $tag && ! $closer ) {
                    break;
                }
                if ( 'TEMPLATE' === $tag ) {
                    $depth += $closer ? -1 : 1;
                    if ( $depth <= 0 ) {
                        $skip = '';
                    }
                }
                continue;
            }

            // A closer with nothing open is discarded by the parser, so a stray
            // </noscript> pasted into an editor must not open anything here.
            if ( $closer ) {
                continue;
            }

            // Everything after <plaintext> is text to the end of the document,
            // and no close tag exists that can end it. Verified in Chrome: a
            // <plaintext> opened inside a <template> still swallows the markup
            // that follows the template's own closing tag.
            if ( 'PLAINTEXT' === $tag ) {
                break;
            }

            // The tag processor runs with the scripting flag off and descends
            // into NOSCRIPT by design, which is correct for its job and wrong
            // for ours: every browser and crawler worth reporting on has
            // scripting on, so a lazy-load <noscript><img> fallback renders
            // nothing at all. Note the self-closing slash is ignored here on
            // purpose, because HTML ignores it too: <noscript/> opens the
            // region just as <noscript> does.
            if ( 'NOSCRIPT' === $tag ) {
                $skip = 'rawtext';
                continue;
            }

            if ( 'TEMPLATE' === $tag ) {
                $skip  = 'template';
                $depth = 1;
                continue;
            }

            if ( 'H1' === $tag ) {
                $out['h1']++;
                continue;
            }

            if ( 'IMG' !== $tag ) {
                continue;
            }

            $out['img_total']++;
            $alt = $tags->get_attribute( 'alt' );

            // null means the attribute is not on the tag at all. true means it
            // is present with no value, which HTML defines as the empty string,
            // so it belongs with alt="" rather than with the absent ones.
            if ( null === $alt ) {
                $out['img_no_alt']++;
                continue;
            }
            if ( true === $alt || '' === self::trim_text( html_entity_decode( (string) $alt, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) ) {
                $out['img_empty_alt']++;
            }
        }

        return $out;
    }

    /**
     * Does this post have a featured image with no alt text on the attachment?
     *
     * Kept apart from the inline images because it is a different finding with
     * a different fix. There is no way to mark a featured image as decorative,
     * so an attachment with nothing stored is always missing text rather than a
     * deliberate choice, and the fix lives in the Media library rather than in
     * the editor.
     */
    private static function featured_image_missing_alt( $post_id ) {
        $thumb_id = (int) get_post_thumbnail_id( $post_id );
        if ( ! $thumb_id ) {
            return false;
        }
        $alt = get_post_meta( $thumb_id, '_wp_attachment_image_alt', true );
        return '' === self::trim_text( (string) $alt );
    }

    /**
     * The description this page actually publishes.
     *
     * Only the ACTIVE SEO plugin's key counts. Yoast does not read
     * rank_math_description and Rank Math does not read _yoast_wpseo_metadesc,
     * and our own native key is only rendered when neither of them is running
     * (see MetadataWriter::render_native_meta). So a value under an inactive
     * plugin's key is a leftover from a migration, not a description: treating
     * it as one told people a page was fine while the rendered page emitted no
     * description at all.
     *
     * The leftover is still worth naming, because "Rank Math has one stored"
     * turns a rewrite into a copy and paste. It is returned as context only and
     * never as the description.
     *
     * @return array{text: string, stale_label: string}
     */
    private static function effective_description( $post_id ) {
        $active = MetadataWriter::detect_seo_plugin();

        $key = MetadataWriter::META_KEYS[ $active ]['desc'] ?? '';
        if ( '' !== $key ) {
            $val = self::trim_text( (string) get_post_meta( $post_id, $key, true ) );
            if ( '' !== $val ) {
                return array( 'text' => $val, 'stale_label' => '' );
            }
        }

        foreach ( MetadataWriter::META_KEYS as $plugin => $keys ) {
            if ( $plugin === $active || empty( $keys['desc'] ) ) {
                continue;
            }
            $val = self::trim_text( (string) get_post_meta( $post_id, $keys['desc'], true ) );
            if ( '' !== $val ) {
                return array( 'text' => '', 'stale_label' => self::plugin_label( $plugin ) );
            }
        }

        return array( 'text' => '', 'stale_label' => '' );
    }

    /** Human name for an SEO plugin identifier. */
    private static function plugin_label( $plugin ) {
        $labels = array(
            MetadataWriter::SEO_YOAST    => 'Yoast SEO',
            MetadataWriter::SEO_RANKMATH => 'Rank Math',
            MetadataWriter::SEO_NATIVE   => 'AEO God Mode',
        );
        return $labels[ $plugin ] ?? $plugin;
    }

    /**
     * The title this page publishes: the SEO title if one is set literally,
     * otherwise the post title.
     *
     * Only the active plugin's key is read, for the same reason as the
     * description: an inactive plugin's stored title is not rendered anywhere.
     *
     * A stored SEO title containing %%placeholders%% (Yoast) or %variables%
     * (Rank Math) is skipped rather than measured. Its rendered length depends
     * on the site name and separator, and reporting "68 characters" for a
     * string that is really "%%title%% %%sep%% %%sitename%%" would be a made-up
     * number.
     */
    private static function effective_title( $post ) {
        $active = MetadataWriter::detect_seo_plugin();
        $key    = MetadataWriter::META_KEYS[ $active ]['title'] ?? '';
        if ( '' !== $key ) {
            $val = self::trim_text( (string) get_post_meta( $post->ID, $key, true ) );
            if ( '' !== $val && ! preg_match( '/%%?[a-z_]+%%?/i', $val ) ) {
                return $val;
            }
        }
        return self::trim_text( (string) $post->post_title );
    }

    /**
     * Length of a string as a reader sees it.
     *
     * Titles and descriptions are stored HTML-encoded, so "Coffee &amp; Tea" is
     * 16 stored characters and 12 read ones. Counting the stored form reported
     * titles as over the limit that render well under it, which is the same
     * class of made-up number as measuring an unexpanded Yoast template.
     */
    private static function visible_length( $text ) {
        return mb_strlen( self::display_text( $text ) );
    }

    /**
     * A stored string as the reader sees it.
     *
     * The client inserts these as text nodes, so an undecoded title renders
     * literally as "Coffee &amp;amp; Tea" and, worse, sits next to a character
     * count taken from the decoded form. The number and the string it describes
     * have to be the same string.
     */
    private static function display_text( $text ) {
        return html_entity_decode( (string) $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
    }

    /**
     * The characters a browser treats as blank, as one regex class.
     *
     * PHP's trim() strips seven ASCII bytes and stops there, so alt="&nbsp;"
     * survived it and an image whose alt text is a single non-breaking space was
     * reported as described. In a browser it is blank: Chrome's .trim() removes
     * U+00A0 along with the rest of the Unicode space separators.
     *
     * This is deliberately the ECMAScript trim set rather than "anything
     * invisible", so U+200B ZERO WIDTH SPACE is NOT in it. A browser keeps that
     * character, so calling an alt that contains one empty would be us inventing
     * a finding the page does not have.
     */
    const BLANK_CHARS = '\x{0000}\x{0009}-\x{000D}\x{0020}\x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}';

    /**
     * trim() as a browser would do it.
     *
     * Done with a UTF-8 aware pattern rather than by handing trim() the bytes
     * "\xc2\xa0", because trim() works on single bytes: that list also eats the
     * trailing 0xA0 of "Café" spelled with U+00E0 and leaves a broken lead byte
     * behind. On invalid UTF-8 preg_replace returns null, and falling back to
     * plain trim() keeps a mangled string intact instead of blanking it, which
     * would turn a real alt into a false "no alt text" finding.
     */
    private static function trim_text( $text ) {
        $text = (string) $text;
        if ( '' === $text ) {
            return '';
        }
        $out = preg_replace( '/^[' . self::BLANK_CHARS . ']+|[' . self::BLANK_CHARS . ']+$/u', '', $text );
        return ( null === $out ) ? trim( $text ) : $out;
    }

    /**
     * Whitespace and case folded so formatting differences are not new content.
     *
     * The run collapsed here is the browser blank set, not PHP's \s, so a
     * description written with a non-breaking space matches the identical one
     * written with an ordinary space. They render the same, so they are the same
     * duplicate.
     */
    private static function normalise_desc( $desc ) {
        $lower = mb_strtolower( (string) $desc );
        $flat  = preg_replace( '/[' . self::BLANK_CHARS . ']+/u', ' ', $lower );
        return self::trim_text( ( null === $flat ) ? $lower : $flat );
    }

    /**
     * Does the active SEO plugin hold a site-wide description template for this
     * post type?
     *
     * If it does, a page with no per-post description may still output one, and
     * saying "12 pages have no meta description" without that caveat overstates
     * the finding. Rank Math ships %excerpt% as its default post template, so
     * on a Rank Math site this is the normal case rather than the exception.
     *
     * Returns the plugin name to name in the caveat, or '' when there is no
     * template and the finding stands on its own.
     */
    private static function description_template_source() {
        $active = MetadataWriter::detect_seo_plugin();

        if ( MetadataWriter::SEO_RANKMATH === $active ) {
            $opts = get_option( 'rank-math-options-titles', array() );
            if ( is_array( $opts ) ) {
                foreach ( array( 'pt_post_description', 'pt_page_description' ) as $key ) {
                    if ( '' !== self::trim_text( (string) ( $opts[ $key ] ?? '' ) ) ) {
                        return 'Rank Math';
                    }
                }
            }
            return '';
        }

        if ( MetadataWriter::SEO_YOAST === $active ) {
            $opts = get_option( 'wpseo_titles', array() );
            if ( is_array( $opts ) ) {
                foreach ( array( 'metadesc-post', 'metadesc-page' ) as $key ) {
                    if ( '' !== self::trim_text( (string) ( $opts[ $key ] ?? '' ) ) ) {
                        return 'Yoast SEO';
                    }
                }
            }
            return '';
        }

        return '';
    }

    /**
     * Client-shaped state: findings grouped by PROBLEM, never by page.
     *
     * A per-page list is how this kind of report becomes a chore nobody
     * finishes. "37 pages have more than one H1" is one decision with 37
     * instances; 37 rows each saying one thing is 37 decisions. Same reasoning
     * as Link_Health grouping by URL rather than by occurrence.
     */
    public static function public_state( $state = null ) {
        $state = $state ?: self::get_state();

        $by_flag = array();
        foreach ( $state['pages'] as $p ) {
            foreach ( (array) $p['flags'] as $flag => $value ) {
                $by_flag[ $flag ][] = array(
                    'post_id'  => (int) $p['post_id'],
                    'title'    => self::display_text( $p['title'] ),
                    'type'     => (string) $p['type'],
                    'edit_url' => get_edit_post_link( (int) $p['post_id'], 'raw' ),
                    'view_url' => get_permalink( (int) $p['post_id'] ),
                    'detail'   => self::detail_line( $flag, $value, $p ),
                );
            }
        }

        $groups   = array();
        $template = self::description_template_source();

        $groups[] = self::group(
            $by_flag,
            'h1_multiple',
            'More than one H1',
            'Two or more H1 headings inside the content itself. An H1 states what the page is about, so a page with several states several things and none of them clearly. Your theme very likely adds another for the title on top of these.',
            null
        );

        $groups[] = self::group(
            $by_flag,
            'h1_in_body',
            'H1 inside the content',
            'One H1 written into the content. Most themes already publish the page title as an H1, which would make this the second one on the page. We read your content, not your rendered theme output, so this is worth a look rather than a certainty. View the page and check.',
            null
        );

        $groups[] = self::group(
            $by_flag,
            'h1_missing',
            'No H1 available',
            'No H1 in the content and no page title for a theme to turn into one, so nothing on the page announces its subject.',
            null
        );

        $groups[] = self::group(
            $by_flag,
            'desc_missing',
            'No meta description',
            'Nothing stored for the description an engine quotes underneath your result. Without one it writes its own from whatever text it finds first.'
                . ( $template ? ' ' . $template . ' has a site-wide description template set, so some of these may still output something. Open one and check before working through the list.' : '' ),
            array(
                'text' => 'Generate, review, and save descriptions here, 1 credit per page.',
                'href' => '',
            )
        );

        $groups[] = self::group(
            $by_flag,
            'desc_short',
            'Very short meta description',
            'These pages do store a description, so something renders under the result, but a few characters is not a snippet. It is usually a placeholder somebody typed to stop an SEO plugin complaining. The stored text and its length are next to each page.',
            array(
                'text' => 'Generate, review, and save descriptions here, 1 credit per page.',
                'href' => '',
            )
        );

        $groups[] = self::group(
            $by_flag,
            'title_long',
            'Title longer than ' . self::TITLE_MAX . ' characters',
            'Long enough that search engines are likely to cut it off. Both Google and Bing truncate by pixel width rather than character count, so ' . self::TITLE_MAX . ' is a working rule of thumb, not a hard limit. Front-load the words that matter and it stops mattering. Lengths are counted as a reader sees them, with HTML entities decoded.',
            array(
                'text' => 'Generate, review, and save titles here, 1 credit per page.',
                'href' => '',
            )
        );

        $groups[] = self::group(
            $by_flag,
            'alt_missing',
            'Images with no alt attribute at all',
            'These images in your content carry no alt attribute. There is nothing for a screen reader to announce and nothing for an engine summarising the page to work with, so the image is simply absent for anything reading text.',
            null
        );

        $groups[] = self::group(
            $by_flag,
            'alt_empty',
            'Images with an empty alt',
            'These images carry an alt attribute with nothing in it. An empty alt is the correct way to mark a decorative image, and a screen reader will skip it exactly as intended. WordPress also saves a blank alt field the same way, so an image whose alt box was simply left empty in the editor is stored identically. We cannot tell the two apart from here, so some of these are almost certainly images that need alt text. Open the ones that carry meaning and write it.',
            null
        );

        $groups[] = self::group(
            $by_flag,
            'alt_featured',
            'Featured images with no alt text',
            'The attachment behind these featured images has no alt text stored. Unlike an image in the content there is no way to mark a featured image as decorative, so this is missing text rather than a deliberate choice. Fix it on the attachment itself, in the Media library, and every page using that image is fixed at once.',
            null
        );

        $groups = array_values( array_filter( $groups ) );

        // Duplicate descriptions are the one finding that is not a property of
        // a single page, so it is built from the cross-page index rather than
        // from the per-page flags.
        $dupes = self::duplicate_groups( $state['desc_index'] );
        if ( ! empty( $dupes ) ) {
            // Counted over every cluster, not over the ones about to be drawn.
            $dupe_pages = 0;
            foreach ( $dupes as $d ) {
                $dupe_pages += count( $d['pages'] );
            }
            $shown    = array_slice( $dupes, 0, self::DUPE_CLUSTERS_SHOWN );
            $groups[] = array(
                'key'            => 'desc_duplicate',
                'severity'       => 'problem',
                'title'          => 'The same meta description on several pages',
                'blurb'          => 'These pages describe themselves identically, so nothing in the description tells an engine, or a reader, which one answers their question. Duplicates usually come from a template or from copying a page and forgetting the description.',
                'count'          => $dupe_pages,
                'pages'          => array(),
                'clusters'       => $shown,
                // Both numbers travel so the client can name what it is not
                // showing instead of quietly showing less than it found.
                'cluster_total'  => count( $dupes ),
                'cluster_shown'  => count( $shown ),
                'pro'            => array(
                    'text' => 'Generate, review, and save a distinct description for each page here, 1 credit per page.',
                    'href' => '',
                ),
            );
        }

        // Pages counted once each, and split by whether what they carry is a
        // defect or something worth a look. Counting both together turned a
        // page whose only finding was "check this H1" into evidence that the
        // site was broken.
        $problem_pages = array();
        $check_pages   = array();
        foreach ( $state['pages'] as $p ) {
            $id = (int) $p['post_id'];
            foreach ( array_keys( (array) $p['flags'] ) as $flag ) {
                if ( 'problem' === ( self::SEVERITY[ $flag ] ?? 'check' ) ) {
                    $problem_pages[ $id ] = true;
                } else {
                    $check_pages[ $id ] = true;
                }
            }
        }
        foreach ( $dupes as $d ) {
            foreach ( $d['pages'] as $pg ) {
                $problem_pages[ (int) $pg['post_id'] ] = true;
            }
        }
        // A page with a real defect is not also "worth a look", it is broken.
        $check_pages = array_diff_key( $check_pages, $problem_pages );

        $problem_findings = 0;
        foreach ( $groups as $g ) {
            if ( 'problem' === $g['severity'] ) {
                $problem_findings += $g['count'];
            }
        }

        $total      = (int) $state['total'];
        $published_total = (int) ( $state['published_total'] ?? 0 );

        return array(
            'status'           => $state['status'],
            'checked'          => (int) $state['checked'],
            'total'            => $total,
            // What the site publishes, versus what this scan could reach.
            'published_total'  => $published_total,
            'max_posts'        => self::MAX_POSTS,
            'truncated'        => $published_total > $total,
            'remaining'        => count( $state['queue'] ),
            // The honest empty state lives here. A site with nothing published
            // has has_content false, and the client must say "nothing to check"
            // rather than draw a pass. Link_Health shipped that bug once.
            'has_content'      => ! empty( $state['has_content'] ),
            'pages_affected'   => count( $problem_pages ) + count( $check_pages ),
            'problem_pages'    => count( $problem_pages ),
            'check_pages'      => count( $check_pages ),
            'problem_findings' => $problem_findings,
            'group_count'      => count( $groups ),
            'title_max'        => self::TITLE_MAX,
            'groups'           => $groups,
            'scanned_at'       => (string) $state['scanned_at'],
        );
    }

    /** One issue group, or null when nothing tripped it. */
    private static function group( $by_flag, $key, $title, $blurb, $pro ) {
        if ( empty( $by_flag[ $key ] ) ) {
            return null;
        }
        $pages = $by_flag[ $key ];
        return array(
            'key'      => $key,
            'severity' => self::SEVERITY[ $key ] ?? 'check',
            'title'    => $title,
            'blurb'    => $blurb,
            'count'    => count( $pages ),
            // Capped for the same reason Link_Health caps its rows: a list
            // longer than this is read as a number, not as a list.
            'pages'    => array_slice( $pages, 0, 50 ),
            'clusters' => array(),
            'pro'      => $pro,
        );
    }

    /**
     * Paginated rows for one issue in the dedicated Content Health workspace.
     *
     * The summary response stays deliberately small so the Dashboard remains
     * quick on a 500-page site. This endpoint is what makes "View all" honest:
     * ordinary findings paginate every affected page, while duplicate
     * descriptions paginate their sets and then the pages inside one set.
     */
    public static function group_detail( $key, $page = 1, $per_page = 20, $cluster_index = null, $search = '' ) {
        $state      = self::get_state();
        $key        = sanitize_key( (string) $key );
        $page       = max( 1, (int) $page );
        $per_page   = min( 50, max( 1, (int) $per_page ) );
        $search     = self::trim_text( (string) $search );
        $valid_keys = array_merge( array_keys( self::SEVERITY ), array( 'desc_duplicate' ) );

        if ( ! in_array( $key, $valid_keys, true ) ) {
            return new \WP_Error( 'asgm_content_health_group', 'Unknown Content Health issue.', array( 'status' => 400 ) );
        }

        if ( 'desc_duplicate' === $key ) {
            $clusters = self::duplicate_groups( $state['desc_index'] );

            if ( '' !== $search ) {
                $needle   = function_exists( 'mb_strtolower' ) ? mb_strtolower( $search ) : strtolower( $search );
                $clusters = array_values( array_filter( $clusters, static function ( $cluster ) use ( $needle ) {
                    $haystacks = array( (string) ( $cluster['text'] ?? '' ) );
                    foreach ( (array) ( $cluster['pages'] ?? array() ) as $row ) {
                        $haystacks[] = (string) ( $row['title'] ?? '' );
                    }
                    foreach ( $haystacks as $haystack ) {
                        $lower = function_exists( 'mb_strtolower' ) ? mb_strtolower( $haystack ) : strtolower( $haystack );
                        if ( false !== strpos( $lower, $needle ) ) {
                            return true;
                        }
                    }
                    return false;
                } ) );
            }

            if ( null !== $cluster_index && '' !== (string) $cluster_index ) {
                $cluster_index = (int) $cluster_index;
                if ( ! isset( $clusters[ $cluster_index ] ) ) {
                    return new \WP_Error( 'asgm_content_health_cluster', 'That duplicate-description set no longer exists. Re-open the issue list.', array( 'status' => 404 ) );
                }

                $cluster = $clusters[ $cluster_index ];
                $rows    = array_values( (array) ( $cluster['pages'] ?? array() ) );

                return self::paginated_detail_response(
                    'cluster_pages',
                    $key,
                    $rows,
                    $page,
                    $per_page,
                    array(
                        'cluster_index' => $cluster_index,
                        'cluster'       => array(
                            'text'  => (string) ( $cluster['text'] ?? '' ),
                            'count' => (int) ( $cluster['count'] ?? count( $rows ) ),
                        ),
                    )
                );
            }

            $summaries = array();
            foreach ( $clusters as $index => $cluster ) {
                $summaries[] = array(
                    'cluster_index' => (int) $index,
                    'text'          => (string) ( $cluster['text'] ?? '' ),
                    'count'         => (int) ( $cluster['count'] ?? count( (array) ( $cluster['pages'] ?? array() ) ) ),
                    'preview'       => array_slice( (array) ( $cluster['pages'] ?? array() ), 0, 3 ),
                );
            }

            return self::paginated_detail_response( 'clusters', $key, $summaries, $page, $per_page );
        }

        $rows = array();
        foreach ( (array) $state['pages'] as $stored_page ) {
            if ( ! array_key_exists( $key, (array) ( $stored_page['flags'] ?? array() ) ) ) {
                continue;
            }
            $post_id = (int) ( $stored_page['post_id'] ?? 0 );
            $row     = array(
                'post_id'  => $post_id,
                'title'    => self::display_text( $stored_page['title'] ?? '' ),
                'type'     => (string) ( $stored_page['type'] ?? '' ),
                'edit_url' => get_edit_post_link( $post_id, 'raw' ),
                'view_url' => get_permalink( $post_id ),
                'detail'   => self::detail_line( $key, $stored_page['flags'][ $key ], $stored_page ),
            );
            if ( '' !== $search ) {
                $haystack = $row['title'] . ' ' . $row['detail'];
                $needle   = function_exists( 'mb_strtolower' ) ? mb_strtolower( $search ) : strtolower( $search );
                $lower    = function_exists( 'mb_strtolower' ) ? mb_strtolower( $haystack ) : strtolower( $haystack );
                if ( false === strpos( $lower, $needle ) ) {
                    continue;
                }
            }
            $rows[] = $row;
        }

        return self::paginated_detail_response( 'pages', $key, $rows, $page, $per_page );
    }

    /** Shape one paginated detail response without ever changing its total. */
    private static function paginated_detail_response( $mode, $key, $items, $page, $per_page, $extra = array() ) {
        $total       = count( $items );
        $total_pages = max( 1, (int) ceil( $total / $per_page ) );
        $page        = min( $page, $total_pages );
        $offset      = ( $page - 1 ) * $per_page;

        return array_merge( array(
            'success'     => true,
            'mode'        => (string) $mode,
            'key'         => (string) $key,
            'page'        => $page,
            'per_page'    => $per_page,
            'total'       => $total,
            'total_pages' => $total_pages,
            'items'       => array_slice( array_values( $items ), $offset, $per_page ),
        ), $extra );
    }

    /** Per-page wording for a finding, so the client renders text not codes. */
    private static function detail_line( $flag, $value, $page ) {
        $total = (int) ( $page['images'] ?? 0 );

        switch ( $flag ) {
            case 'h1_multiple':
                return sprintf( '%d H1 headings in the content', (int) $value );
            case 'h1_in_body':
                return '1 H1 in the content, plus whatever your theme adds';
            case 'h1_missing':
                return 'No H1 and no title';
            case 'title_long':
                return sprintf( '%d characters', (int) $value );
            case 'alt_missing':
                return sprintf( '%d of %d %s', (int) $value, $total, 1 === $total ? 'image' : 'images' );
            case 'alt_empty':
                return sprintf( '%d of %d %s', (int) $value, $total, 1 === $total ? 'image' : 'images' );
            case 'alt_featured':
                return 'Featured image, no alt text on the attachment';
            case 'desc_short':
                $text = self::display_text( $page['desc_text'] ?? '' );
                return $text
                    ? sprintf( '%d characters: "%s"', (int) $value, $text )
                    : sprintf( '%d characters', (int) $value );
            case 'desc_missing':
                $stale = (string) ( $page['desc_stale'] ?? '' );
                return $stale
                    ? $stale . ' has one stored, but ' . $stale . ' is not the active SEO plugin, so nothing renders it'
                    : '';
        }
        return '';
    }

    /**
     * Descriptions shared by two or more pages, as clusters.
     *
     * A flag saying "this page has a duplicate description" is useless on its
     * own, because the fix is a decision about the whole set: which of these
     * pages keeps the wording and what the others say instead. So the group is
     * the unit reported.
     */
    private static function duplicate_groups( $index ) {
        $out = array();
        foreach ( (array) $index as $entry ) {
            $ids = array_values( array_unique( array_map( 'intval', (array) ( $entry['ids'] ?? array() ) ) ) );
            if ( count( $ids ) < 2 ) {
                continue;
            }
            $pages = array();
            foreach ( $ids as $id ) {
                $pages[] = array(
                    'post_id'  => $id,
                    'title'    => self::display_text( get_the_title( $id ) ),
                    'edit_url' => get_edit_post_link( $id, 'raw' ),
                    'view_url' => get_permalink( $id ),
                );
            }
            $text  = self::display_text( $entry['text'] ?? '' );
            $out[] = array(
                'text'  => ( mb_strlen( $text ) > 160 ) ? ( mb_substr( $text, 0, 160 ) . '…' ) : $text,
                'count' => count( $pages ),
                'pages' => $pages,
            );
        }
        usort( $out, static function ( $a, $b ) {
            return $b['count'] - $a['count'];
        } );

        // Returned whole. This used to hand back only the first 20 clusters,
        // and public_state() then counted the headline "pages to fix" off that
        // truncated list, so the card printed 70 while the tool's own stored
        // index held 84. A display cap that changes a headline number is not a
        // display cap. The cap now lives at the point of display, where the
        // count beside it can say what is being withheld.
        return $out;
    }
}
