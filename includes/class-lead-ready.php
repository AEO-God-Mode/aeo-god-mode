<?php
/**
 * Lead-ready check for pages AI search already sends people to.
 *
 * Free diagnoses: which AI-visited pages give a visitor a way to become a
 * lead (a clear next step near the top, a contact or buy option, a next step
 * after the answer). Pro adds more page sources through the
 * `aeogm_lead_ready_sources` filter and owns the one-click fix.
 *
 * Every check reads the page content (or the page builder's output), never
 * the theme's header, footer or sidebar, so it behaves the same on any theme.
 * When a theme template renders the whole page and the post body is empty,
 * the rendered page is read with its header, footer, nav and sidebars removed.
 *
 * A page that is itself the next step (pricing, checkout, contact, booking,
 * a product) is lead-ready by nature and never asked for another button.
 *
 * @package AISEOGodMode
 */

namespace AISEOGodMode;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Finds AI-visited pages and checks each for a path to a lead.
 */
class LeadReady {

    /** Saved CTA preset, picked once and reused by every fix. */
    const OPTION_PRESET = 'aeogm_lead_cta_preset';

    /** Cached results. Cleared on any post save and on preset change. */
    const TRANSIENT = 'aeogm_lead_ready_v1';

    /** Post meta written by the Pro fix: what was inserted, so it can be removed exactly. */
    const META_KEY = '_aeogm_lead_cta';

    /** Shortcode used for classic (non-block) content. */
    const SHORTCODE = 'aeogm_lead_cta';

    /** Pages checked per run. */
    const MAX_PAGES = 25;

    /** Below this many words the post body is treated as empty (template-rendered page). */
    const THIN_BODY_WORDS = 40;

    /** Rendered-page cache, per post and modified time. */
    const RENDER_TRANSIENT = 'aeogm_lr_render_';

    /** Slug words that mark a page as the next step itself, mapped to its kind. */
    const CONVERSION_WORDS = array(
        'pricing'       => 'pricing',
        'prices'        => 'pricing',
        'price'         => 'pricing',
        'plans'         => 'pricing',
        'rates'         => 'pricing',
        'tariffs'       => 'pricing',
        'packages'      => 'pricing',
        'checkout'      => 'checkout',
        'cart'          => 'checkout',
        'basket'        => 'checkout',
        'contact'       => 'contact',
        'enquiry'       => 'contact',
        'enquiries'     => 'contact',
        'inquiry'       => 'contact',
        'quote'         => 'contact',
        'estimate'      => 'contact',
        'book'          => 'booking',
        'booking'       => 'booking',
        'bookings'      => 'booking',
        'appointment'   => 'booking',
        'appointments'  => 'booking',
        'reservations'  => 'booking',
        'reserve'       => 'booking',
        'schedule'      => 'booking',
        'demo'          => 'booking',
        'consultation'  => 'booking',
        'order'         => 'checkout',
        'buy'           => 'checkout',
        'purchase'      => 'checkout',
        'shop'          => 'shop',
        'store'         => 'shop',
        'signup'        => 'signup',
        'register'      => 'signup',
        'subscribe'     => 'signup',
        'donate'        => 'checkout',
    );

    /** Words that count as "the first screen" of content. */
    const FIRST_SCREEN_WORDS = 150;

    /** AI fetches made live while answering someone. */
    const USER_FETCH_BOTS = array( 'ChatGPT-User', 'Perplexity-User', 'Claude-User', 'Amzn-User', 'MistralAI-User', 'Meta-ExternalFetcher' );

    /** AI search index crawlers. */
    const SEARCH_BOTS = array( 'OAI-SearchBot', 'PerplexityBot', 'Claude-SearchBot', 'Amzn-SearchBot', 'DuckAssistBot', 'MistralAI-Index' );

    /** Source ranking: stronger evidence that real people arrive comes first. */
    const SOURCE_WEIGHT = array(
        'referral'     => 5,
        'citation'     => 4,
        'user_fetch'   => 3,
        'gsc'          => 2,
        'search_crawl' => 1,
    );

    /** Link paths and anchor words that read as a lead step. */
    const LEAD_PATH = '~(contact|get-in-touch|book|booking|appointment|schedule|consult|quote|estimate|pricing|prices?|plans|demo|trial|buy|checkout|cart|order|shop|store|enquir|inquir|get-started|start|sign-?up|register|subscribe|hire|request|apply|call|visit-us|locations?|reserve|donate)~i';

    const LEAD_TEXT = '~\b(contact|call|phone|ring|email us|book|schedule|reserve|get (a|your) (quote|estimate|price|demo)|request|buy|order|shop|purchase|add to (cart|basket)|checkout|sign ?up|get started|start (your|a|free)|try (it )?free|free trial|subscribe|enquire|inquire|talk to|speak (to|with)|hire|apply|donate|get in touch|see (pricing|plans|prices)|view (pricing|plans|prices)|pricing|demo)\b~i';

    /** Hosts that are a booking, chat or payment step in their own right. */
    const LEAD_HOSTS = '~(calendly\.com|acuityscheduling\.com|cal\.com|tidycal\.com|savvycal\.com|youcanbook\.me|setmore\.com|simplybook\.|square\.site|squareup\.com|booksy\.com|fresha\.com|opentable\.|resy\.com|wa\.me|api\.whatsapp\.com|m\.me|buy\.stripe\.com|checkout\.stripe\.com|paypal\.me|gumroad\.com|etsy\.com|amazon\.)~i';

    /** Form plugins, as shortcodes or blocks in raw post content. */
    const FORM_MARKERS = '~(\[(contact-form-7|wpforms|gravityform|gravityforms|ninja_form|ninja_forms|fluentform|formidable|caldera_form|forminator_form|contact-form|everest_form|ws_form|hubspot|mc4wp_form|bookly-form|ameliabooking|booking|edd_purchase|purchase_link|add_to_cart|product_page)\b|<!--\s+wp:(contact-form-7|wpforms|gravityforms|ninja-forms|fluentfom|fluentform|formidable|jetpack/contact-form|forminator|kadence/form|kadence/advanced-form|otter-blocks/form|woocommerce/add-to-cart|woocommerce/single-product|edd/buy-button)\b)~i';

    /**
     * Wire hooks. Called once from Main.
     */
    public static function init() {
        add_action( 'save_post', array( __CLASS__, 'flush' ) );
        add_action( 'deleted_post', array( __CLASS__, 'flush' ) );
        add_shortcode( self::SHORTCODE, array( __CLASS__, 'render_shortcode' ) );
    }

    /**
     * Clear cached results.
     */
    public static function flush() {
        delete_transient( self::TRANSIENT );
    }

    /* ───────────────────────────── Preset ───────────────────────────── */

    /**
     * The saved CTA preset as clean CTA block attributes, or null.
     *
     * @return array|null
     */
    public static function get_preset() {
        $raw = get_option( self::OPTION_PRESET, array() );
        if ( ! is_array( $raw ) || empty( $raw ) ) {
            return null;
        }
        return CTARenderer::sanitize( $raw );
    }

    /**
     * Validate and save a preset.
     *
     * @param array $raw Untrusted attributes from the admin.
     * @return array|\WP_Error Clean attributes.
     */
    public static function save_preset( $raw ) {
        $raw = is_array( $raw ) ? $raw : array();

        // The preset always points at a literal URL the owner typed. Local
        // links are kept literal too, so the saved block works even if the
        // target page is later renamed (WordPress redirects old slugs).
        $dest               = isset( $raw['destination'] ) && is_array( $raw['destination'] ) ? $raw['destination'] : array();
        $dest['type']       = 'external';
        $raw['destination'] = $dest;

        $clean = CTARenderer::sanitize( $raw );
        if ( null === $clean ) {
            return new \WP_Error(
                'aeogm_lead_preset_invalid',
                __( 'Add button text and a link that starts with https://, mailto: or tel:.', 'aeo-god-mode' ),
                array( 'status' => 400 )
            );
        }

        update_option( self::OPTION_PRESET, $clean, false );
        self::flush();
        return $clean;
    }

    /**
     * Shortcode used in classic content. Renders the saved preset, so a
     * change to the preset updates every classic page that has it.
     *
     * @return string
     */
    public static function render_shortcode() {
        $preset = self::get_preset();
        if ( null === $preset ) {
            return '';
        }
        if ( wp_style_is( 'aeogm-cta', 'registered' ) ) {
            wp_enqueue_style( 'aeogm-cta' );
        } else {
            wp_register_style( 'aeogm-cta', false, array(), defined( 'ASGM_VERSION' ) ? ASGM_VERSION : null );
            wp_add_inline_style( 'aeogm-cta', CTARenderer::css() );
            wp_enqueue_style( 'aeogm-cta' );
        }
        return CTARenderer::render( $preset );
    }

    /* ───────────────────────────── Results ───────────────────────────── */

    /**
     * Full results: summary plus the page list.
     *
     * @param bool $refresh Recompute instead of using the cache.
     * @return array
     */
    public static function get_results( $refresh = false ) {
        if ( ! $refresh ) {
            $cached = get_transient( self::TRANSIENT );
            if ( is_array( $cached ) ) {
                $cached['preset'] = self::get_preset();
                return $cached;
            }
        }

        $pages = array();
        foreach ( self::gather_pages() as $row ) {
            $checked = self::check_page( $row );
            if ( null !== $checked ) {
                $pages[] = $checked;
            }
        }

        // Worst first, then strongest evidence.
        $order = array( 'none' => 0, 'partial' => 1, 'ready' => 2 );
        usort( $pages, function ( $a, $b ) use ( $order ) {
            $s = $order[ $a['status'] ] <=> $order[ $b['status'] ];
            return 0 !== $s ? $s : ( $b['rank'] <=> $a['rank'] );
        } );

        $summary = array( 'total' => count( $pages ), 'none' => 0, 'partial' => 0, 'ready' => 0 );
        foreach ( $pages as $p ) {
            $summary[ $p['status'] ]++;
        }

        $result = array(
            'summary'     => $summary,
            'pages'       => $pages,
            'pro_sources' => (bool) has_filter( 'aeogm_lead_ready_sources' ),
            'computed_at' => gmdate( 'c' ),
        );
        set_transient( self::TRANSIENT, $result, 15 * MINUTE_IN_SECONDS );

        $result['preset'] = self::get_preset();
        return $result;
    }

    /**
     * Collect AI-visited pages from the crawler log plus any added sources.
     *
     * @return array<int, array> Keyed by post ID.
     */
    public static function gather_pages() {
        $rows = array();

        global $wpdb;
        $table = $wpdb->prefix . 'asgm_crawler_log';
        $bots  = array_merge( self::USER_FETCH_BOTS, self::SEARCH_BOTS );
        $in    = implode( ',', array_fill( 0, count( $bots ), '%s' ) );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Results are cached in a transient by get_results(); $in is a list of %s placeholders.
        $log = $wpdb->get_results( $wpdb->prepare( "SELECT url, bot_name, COUNT(*) AS visits FROM %i WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) AND bot_name IN ($in) GROUP BY url, bot_name ORDER BY visits DESC LIMIT 600", array_merge( array( $table ), $bots ) ), ARRAY_A );

        foreach ( (array) $log as $l ) {
            $source = in_array( $l['bot_name'], self::USER_FETCH_BOTS, true ) ? 'user_fetch' : 'search_crawl';
            $rows[] = array(
                'url'    => (string) $l['url'],
                'source' => $source,
                'count'  => (int) $l['visits'],
                'detail' => (string) $l['bot_name'],
            );
        }

        /**
         * Add AI landing pages from other sources.
         *
         * Each row: url (absolute or site-relative), source (referral,
         * citation or gsc), count (int) and detail (engine or query).
         *
         * @param array $rows Rows so far.
         */
        $rows = apply_filters( 'aeogm_lead_ready_sources', $rows );

        $pages = array();
        foreach ( (array) $rows as $r ) {
            if ( ! is_array( $r ) || empty( $r['url'] ) || empty( $r['source'] ) || ! isset( self::SOURCE_WEIGHT[ $r['source'] ] ) ) {
                continue;
            }
            $post_id = self::resolve_post_id( (string) $r['url'] );
            if ( ! $post_id ) {
                continue;
            }
            if ( ! isset( $pages[ $post_id ] ) ) {
                $pages[ $post_id ] = array( 'post_id' => $post_id, 'evidence' => array(), 'rank' => 0 );
            }
            $src   = $r['source'];
            $count = max( 1, (int) ( $r['count'] ?? 1 ) );
            if ( ! isset( $pages[ $post_id ]['evidence'][ $src ] ) ) {
                $pages[ $post_id ]['evidence'][ $src ] = array( 'source' => $src, 'count' => 0, 'details' => array() );
            }
            $pages[ $post_id ]['evidence'][ $src ]['count'] += $count;
            $detail = sanitize_text_field( (string) ( $r['detail'] ?? '' ) );
            if ( '' !== $detail && count( $pages[ $post_id ]['evidence'][ $src ]['details'] ) < 3
                && ! in_array( $detail, $pages[ $post_id ]['evidence'][ $src ]['details'], true ) ) {
                $pages[ $post_id ]['evidence'][ $src ]['details'][] = $detail;
            }
        }

        foreach ( $pages as &$p ) {
            $best = 0;
            $sum  = 0;
            foreach ( $p['evidence'] as $e ) {
                $best = max( $best, self::SOURCE_WEIGHT[ $e['source'] ] );
                $sum += $e['count'];
            }
            $p['rank'] = $best * 100000 + min( 99999, $sum );
            uasort( $p['evidence'], function ( $a, $b ) {
                return self::SOURCE_WEIGHT[ $b['source'] ] <=> self::SOURCE_WEIGHT[ $a['source'] ];
            } );
            $p['evidence'] = array_values( $p['evidence'] );
        }
        unset( $p );

        uasort( $pages, function ( $a, $b ) {
            return $b['rank'] <=> $a['rank'];
        } );

        return array_slice( $pages, 0, self::MAX_PAGES, true );
    }

    /**
     * Turn a logged or reported URL into a published post ID on this site.
     *
     * @param string $url URL or path.
     * @return int 0 when it is not a published page here.
     */
    public static function resolve_post_id( $url ) {
        $url = trim( $url );
        if ( '' === $url ) {
            return 0;
        }

        $path = (string) wp_parse_url( $url, PHP_URL_PATH );
        $host = (string) wp_parse_url( $url, PHP_URL_HOST );
        if ( '' !== $host ) {
            $home_host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
            if ( strtolower( preg_replace( '/^www\./i', '', $host ) ) !== strtolower( preg_replace( '/^www\./i', '', $home_host ) ) ) {
                return 0;
            }
        }
        if ( '' === $path ) {
            $path = '/';
        }

        // Files, feeds, APIs and admin screens are never a landing page.
        if ( preg_match( '~(^/wp-(admin|json|content|includes|login)|/feed/?$|\.(txt|xml|json|js|css|png|jpe?g|gif|webp|svg|ico|pdf|zip|php)$|^/okf/)~i', $path ) ) {
            return 0;
        }

        $home_path = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
        if ( '/' === $path || untrailingslashit( $path ) === untrailingslashit( $home_path ) ) {
            $front = ( 'page' === get_option( 'show_on_front' ) ) ? (int) get_option( 'page_on_front' ) : 0;
            return $front;
        }

        $post_id = (int) url_to_postid( home_url( $path ) );
        if ( ! $post_id ) {
            return 0;
        }
        $post = get_post( $post_id );
        if ( ! $post || 'publish' !== $post->post_status || ! is_post_type_viewable( $post->post_type ) ) {
            return 0;
        }
        return $post_id;
    }

    /* ───────────────────────────── Checks ───────────────────────────── */

    /**
     * Check one page.
     *
     * @param array $row Gathered row.
     * @return array|null
     */
    public static function check_page( $row ) {
        $post = get_post( (int) $row['post_id'] );
        if ( ! $post ) {
            return null;
        }

        $raw     = (string) $post->post_content;
        $builder = self::builder_name( $post );
        $html    = class_exists( __NAMESPACE__ . '\\ContentGaps' ) ? ContentGaps::page_html( $post ) : $raw;

        // Our own lead block counts as a CTA wherever it sits. page_html()
        // strips shortcodes from classic content, so swap ours in first.
        $sc     = '[' . self::SHORTCODE . ']';
        $marker = '<a class="wp-block-button__link aeogm-cta" href="#aeogm-lead">lead</a>';
        if ( '' === $builder && false !== strpos( $raw, $sc ) && false === strpos( $html, $sc ) ) {
            $html = strip_shortcodes( str_replace( $sc, $marker, $raw ) );
        }
        $html = str_replace( $sc, $marker, $html );

        // A template-rendered page (empty body, the theme draws everything)
        // would fail every check for content it does not hold. Read what a
        // visitor actually sees instead.
        if ( '' === $builder && str_word_count( wp_strip_all_tags( $html ) ) < self::THIN_BODY_WORDS ) {
            $rendered = self::rendered_main( $post );
            if ( '' !== $rendered ) {
                $html = $rendered;
                $raw .= $rendered;
            }
        }

        $checks     = self::run_checks( $html, $raw );
        $conversion = self::conversion_kind( $post );

        $passed = (int) $checks['cta_top'] + (int) $checks['contact'] + (int) $checks['next_step'];
        $status = 3 === $passed ? 'ready' : ( 0 === $passed ? 'none' : 'partial' );
        if ( '' !== $conversion ) {
            // Pricing, checkout, contact and booking pages are the next step.
            // Asking them for a button to somewhere else gets it backwards.
            $status = 'ready';
        }

        $meta  = get_post_meta( $post->ID, self::META_KEY, true );
        $added = is_array( $meta ) && ! empty( $meta['inserted'] ) && false !== strpos( $raw, trim( (string) $meta['inserted'] ) );

        return array(
            'post_id'   => (int) $post->ID,
            'title'     => html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' ),
            'path'      => (string) wp_parse_url( get_permalink( $post ), PHP_URL_PATH ),
            'view_url'  => get_permalink( $post ),
            'edit_url'  => current_user_can( 'edit_post', $post->ID ) ? (string) get_edit_post_link( $post->ID, 'raw' ) : '',
            'evidence'  => $row['evidence'],
            'rank'      => (int) $row['rank'],
            'checks'    => $checks,
            'status'    => $status,
            'builder'   => $builder,
            'conversion' => $conversion,
            'added'     => $added,
            'added_at'  => $added && ! empty( $meta['at'] ) ? (string) $meta['at'] : '',
        );
    }

    /**
     * Is this page itself the next step a visitor takes?
     *
     * Returns the kind (pricing, checkout, contact, booking, shop, signup,
     * product) or '' for an ordinary content page. Shop pages, products and
     * store pages from WooCommerce and Easy Digital Downloads are recognised
     * from their own settings first; ordinary pages by their slug or template.
     * Only pages are matched by slug, so a blog post called "how to book a
     * plumber" is still checked like any article.
     *
     * @param \WP_Post $post Post.
     * @return string
     */
    public static function conversion_kind( $post ) {
        $kind = '';
        $id   = (int) $post->ID;

        if ( in_array( $post->post_type, array( 'product', 'download' ), true ) ) {
            $kind = 'product';
        }

        if ( '' === $kind ) {
            $store_pages = array(
                'checkout' => array( (int) get_option( 'woocommerce_checkout_page_id' ), (int) get_option( 'woocommerce_cart_page_id' ) ),
                'shop'     => array( (int) get_option( 'woocommerce_shop_page_id' ) ),
            );
            $edd = get_option( 'edd_settings' );
            if ( is_array( $edd ) && ! empty( $edd['purchase_page'] ) ) {
                $store_pages['checkout'][] = (int) $edd['purchase_page'];
            }
            foreach ( $store_pages as $k => $ids ) {
                if ( in_array( $id, array_filter( $ids ), true ) ) {
                    $kind = $k;
                    break;
                }
            }
        }

        if ( '' === $kind && 'page' === $post->post_type ) {
            $template = (string) get_page_template_slug( $post );
            $sources  = array( (string) $post->post_name, $template ? basename( $template, '.php' ) : '' );
            foreach ( $sources as $src ) {
                $words = array_values( array_filter( preg_split( '~[^a-z]+~', strtolower( $src ) ) ) );
                $words = array_diff( $words, array( 'page', 'template', 'tpl', 'us', 'our', 'now', 'a', 'get', 'in', 'touch', 'online', 'and', 'the' ) );
                // Short slugs only: "pricing", "contact-us", "book-now". A long
                // slug that happens to contain "plans" is an article, not a page
                // whose whole job is the sale.
                if ( empty( $words ) || count( $words ) > 3 ) {
                    continue;
                }
                foreach ( $words as $w ) {
                    if ( isset( self::CONVERSION_WORDS[ $w ] ) ) {
                        $kind = self::CONVERSION_WORDS[ $w ];
                        break 2;
                    }
                }
            }
            if ( '' === $kind && in_array( strtolower( (string) $post->post_name ), array( 'get-in-touch', 'hire-us', 'work-with-us' ), true ) ) {
                $kind = 'contact';
            }
        }

        /**
         * Filter whether a page is itself a conversion step.
         *
         * @param string   $kind '' for an ordinary page, or pricing, checkout, contact, booking, shop, signup, product.
         * @param \WP_Post $post The page.
         */
        return (string) apply_filters( 'aeogm_lead_ready_conversion_kind', $kind, $post );
    }

    /**
     * The main content of the page as a visitor sees it.
     *
     * Used only when the post body is empty because a theme template draws
     * the page. Fetches the public permalink, keeps <main> (or <article>),
     * and strips header, footer, nav, aside, scripts and styles so a menu
     * "Contact" link never passes a check on its own. Cached per post and
     * modified time; a failed fetch is cached too so a slow host is asked once.
     *
     * @param \WP_Post $post Post.
     * @return string HTML, or '' when the page cannot be read.
     */
    private static function rendered_main( $post ) {
        if ( 'publish' !== $post->post_status || ! empty( $post->post_password ) ) {
            return '';
        }
        $key    = self::RENDER_TRANSIENT . $post->ID;
        $cached = get_transient( $key );
        $stamp  = (string) $post->post_modified_gmt;
        if ( is_array( $cached ) && isset( $cached['m'], $cached['h'] ) && $cached['m'] === $stamp ) {
            return (string) $cached['h'];
        }

        $html = '';
        $url  = get_permalink( $post );
        if ( $url ) {
            $res = wp_remote_get(
                $url,
                array(
                    'timeout'     => 6,
                    'redirection' => 2,
                    'user-agent'  => 'AEO God Mode lead check; ' . home_url( '/' ),
                    'headers'     => array( 'Accept' => 'text/html' ),
                )
            );
            if ( ! is_wp_error( $res ) && 200 === (int) wp_remote_retrieve_response_code( $res ) ) {
                $html = self::main_region( (string) wp_remote_retrieve_body( $res ) );
            }
        }

        set_transient( $key, array( 'm' => $stamp, 'h' => $html ), '' === $html ? HOUR_IN_SECONDS : DAY_IN_SECONDS );
        return $html;
    }

    /**
     * Keep the page's main region and drop site chrome.
     *
     * @param string $doc Full HTML document.
     * @return string
     */
    private static function main_region( $doc ) {
        if ( '' === $doc ) {
            return '';
        }
        $doc = preg_replace( '~<(script|style|noscript|template|svg)\b[^>]*>.*?</\1>~is', '', $doc );
        if ( preg_match( '~<main\b[^>]*>(.*)</main>~is', $doc, $m ) ) {
            $doc = $m[1];
        } elseif ( preg_match( '~<body\b[^>]*>(.*)</body>~is', $doc, $m ) ) {
            $doc = $m[1];
        }
        // Remove chrome even inside <main>; some themes put the nav there.
        for ( $i = 0; $i < 3; $i++ ) {
            $doc = preg_replace( '~<(header|footer|nav|aside)\b[^>]*>(?:(?!<\1\b).)*?</\1>~is', '', $doc );
        }
        return trim( (string) $doc );
    }

    /**
     * Run the three checks.
     *
     * @param string $html Page content HTML as readers see it (builder aware).
     * @param string $raw  Raw post_content, for form shortcodes and blocks.
     * @return array{cta_top: bool, contact: bool, next_step: bool}
     */
    public static function run_checks( $html, $raw ) {
        $html   = (string) $html;
        $cutoff = self::first_screen_offset( $html );
        $answer = self::answer_end_offset( $html, $cutoff );

        $links      = self::lead_links( $html );
        $forms      = self::form_offsets( $html );
        $has_marker = (bool) preg_match( self::FORM_MARKERS, (string) $raw );

        $cta_top   = false;
        $next_step = false;
        foreach ( $links as $l ) {
            if ( $l['offset'] < $cutoff ) {
                $cta_top = true;
            }
            if ( $l['offset'] >= $answer || self::closes_answer( $html, $l, $answer ) ) {
                $next_step = true;
            }
        }
        foreach ( $forms as $offset ) {
            if ( $offset < $cutoff ) {
                $cta_top = true;
            }
            if ( $offset >= $answer ) {
                $next_step = true;
            }
        }

        $contact = $has_marker || ! empty( $forms );
        foreach ( $links as $l ) {
            if ( $l['contact'] ) {
                $contact = true;
                break;
            }
        }
        if ( ! $contact ) {
            $text = wp_strip_all_tags( $html );
            // A visible phone number or email address is a way to reach you.
            if ( preg_match( '/(?:\+|\b0)\d[\d\s().-]{7,}\d\b/', $text ) || preg_match( '/\b[\w.+-]+@[\w-]+\.[\w.-]+\b/', $text ) ) {
                $contact = true;
            }
        }

        // A form or contact option in the first screen also reads as a step.
        return array(
            'cta_top'   => $cta_top,
            'contact'   => $contact,
            'next_step' => $next_step,
        );
    }

    /**
     * Links that read as a lead step, with their offsets.
     *
     * @param string $html HTML.
     * @return array<int, array{offset: int, length: int, contact: bool}>
     */
    private static function lead_links( $html ) {
        $out = array();
        if ( ! preg_match_all( '~<a\b([^>]*)>(.*?)</a>~is', $html, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
            return $out;
        }
        $home_host = strtolower( preg_replace( '/^www\./i', '', (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) );

        foreach ( $m as $match ) {
            $attrs = $match[1][0];
            $text  = trim( wp_strip_all_tags( html_entity_decode( $match[2][0], ENT_QUOTES, 'UTF-8' ) ) );
            $href  = preg_match( '~\bhref\s*=\s*(["\'])(.*?)\1~is', $attrs, $h ) ? html_entity_decode( $h[2], ENT_QUOTES, 'UTF-8' ) : '';
            $class = preg_match( '~\bclass\s*=\s*(["\'])(.*?)\1~is', $attrs, $c ) ? $c[2] : '';

            if ( '' === $href || ( 0 === strpos( $href, '#' ) && false === strpos( $class, 'aeogm-cta' ) ) ) {
                continue;
            }

            $is_contact = (bool) preg_match( '~^(tel|mailto|sms):~i', $href ) || (bool) preg_match( self::LEAD_HOSTS, $href )
                || (bool) preg_match( '~(add-to-cart=|edd_action=add_to_cart|/checkout|/cart)~i', $href );

            $is_button = (bool) preg_match( '~(wp-block-button__link|wp-element-button|aeogm-cta|\bbutton\b|\bbtn\b|elementor-button|fl-button|brxe-button|add_to_cart_button|edd-add-to-cart)~i', $class );

            $path      = (string) wp_parse_url( $href, PHP_URL_PATH );
            $host      = strtolower( preg_replace( '/^www\./i', '', (string) wp_parse_url( $href, PHP_URL_HOST ) ) );
            $is_local  = '' === $host || $host === $home_host;
            $path_lead = $is_local && '' !== $path && preg_match( self::LEAD_PATH, $path );
            $text_lead = '' !== $text && preg_match( self::LEAD_TEXT, $text );

            if ( $is_contact || $is_button || $path_lead || $text_lead ) {
                $out[] = array(
                    'offset'  => (int) $match[0][1],
                    'length'  => strlen( $match[0][0] ),
                    'contact' => $is_contact || ( $path_lead && preg_match( '~(contact|book|appointment|schedule|quote|demo|checkout|cart|buy|order|enquir|inquir|get-in-touch|reserve)~i', $path ) ),
                );
            }
        }
        return $out;
    }

    /**
     * A link that sits at the tail of the answer section (answer text before
     * it, almost none after it before the next heading) is the step after
     * the answer, even though it comes before that heading.
     *
     * @param string $html   HTML.
     * @param array  $link   Link from lead_links().
     * @param int    $answer Answer end offset.
     * @return bool
     */
    private static function closes_answer( $html, $link, $answer ) {
        $start = (int) $link['offset'];
        if ( $start >= $answer ) {
            return true;
        }
        $before = str_word_count( wp_strip_all_tags( substr( $html, 0, $start ) ) );
        $after  = str_word_count( wp_strip_all_tags( substr( $html, $start + (int) $link['length'], $answer - $start - (int) $link['length'] ) ) );
        return $before >= 8 && $after < 8;
    }

    /**
     * Offsets of forms that are not search forms.
     *
     * @param string $html HTML.
     * @return int[]
     */
    private static function form_offsets( $html ) {
        $out = array();
        if ( preg_match_all( '~<form\b([^>]*)>~i', $html, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
            foreach ( $m as $f ) {
                if ( preg_match( '~(role\s*=\s*["\']search|search-?form|wp-block-search)~i', $f[1][0] ) ) {
                    continue;
                }
                $out[] = (int) $f[0][1];
            }
        }
        return $out;
    }

    /**
     * Byte offset where the first screen of content ends.
     *
     * @param string $html HTML.
     * @return int
     */
    public static function first_screen_offset( $html ) {
        $words = 0;
        if ( preg_match_all( '~(?:^|>)([^<]+)~', $html, $m, PREG_OFFSET_CAPTURE ) ) {
            foreach ( $m[1] as $node ) {
                $words += (int) preg_match_all( '~[\p{L}\p{N}][\p{L}\p{N}\'\-]*~u', html_entity_decode( $node[0], ENT_QUOTES, 'UTF-8' ) );
                if ( $words >= self::FIRST_SCREEN_WORDS ) {
                    return (int) $node[1] + strlen( $node[0] );
                }
            }
        }
        return strlen( $html );
    }

    /**
     * Byte offset where the answer-first section ends: the next heading after
     * the opening text, or the end of the first screen, whichever is first.
     *
     * @param string $html   HTML.
     * @param int    $cutoff First screen offset.
     * @return int
     */
    private static function answer_end_offset( $html, $cutoff ) {
        if ( preg_match_all( '~<h[2-4]\b~i', $html, $m, PREG_OFFSET_CAPTURE ) ) {
            foreach ( $m[0] as $h ) {
                $before = wp_strip_all_tags( substr( $html, 0, $h[1] ) );
                if ( str_word_count( $before ) >= 8 ) {
                    return min( (int) $h[1], $cutoff );
                }
            }
        }
        return $cutoff;
    }

    /**
     * Name the page builder when the content does not live in post_content.
     *
     * @param \WP_Post $post Post.
     * @return string '' for normal block or classic content.
     */
    public static function builder_name( $post ) {
        $id = (int) $post->ID;
        if ( 'builder' === get_post_meta( $id, '_elementor_edit_mode', true ) ) {
            return 'Elementor';
        }
        if ( get_post_meta( $id, '_fl_builder_enabled', true ) ) {
            return 'Beaver Builder';
        }
        if ( get_post_meta( $id, '_bricks_page_content_2', true ) ) {
            return 'Bricks';
        }
        if ( 'true' === get_post_meta( $id, '_et_pb_use_builder', true ) || false !== strpos( (string) $post->post_content, '[et_pb_section' ) ) {
            return 'Divi';
        }
        if ( false !== strpos( (string) $post->post_content, '[vc_row' ) ) {
            return 'WPBakery';
        }
        if ( get_post_meta( $id, 'ct_builder_shortcodes', true ) || get_post_meta( $id, '_ct_builder_json', true ) ) {
            return 'Oxygen';
        }
        return '';
    }

    /**
     * Where a lead block could send people, found on the site itself.
     *
     * Free, no AI. The owner should not have to type a URL: their contact,
     * booking and pricing pages are already published, and the phone and
     * email are already in the business profile. Ordered by how directly
     * each one turns a visitor into a lead, so the first is a sensible
     * default.
     *
     * @return array{destinations: array, business: array}
     */
    public static function destinations() {
        $priority = array( 'booking' => 0, 'contact' => 1, 'pricing' => 2, 'signup' => 3, 'shop' => 4 );
        $found    = array();

        $ids = get_posts( array(
            'post_type'        => 'page',
            'post_status'      => 'publish',
            'posts_per_page'   => 300,
            'fields'           => 'ids',
            'orderby'          => 'menu_order',
            'order'            => 'ASC',
            'has_password'     => false,
            'suppress_filters' => false,
        ) );

        foreach ( $ids as $id ) {
            $post = get_post( $id );
            if ( ! $post ) {
                continue;
            }
            $kind = self::conversion_kind( $post );
            if ( ! isset( $priority[ $kind ] ) ) {
                continue;
            }
            // One page per kind is enough; the shortest slug is usually the
            // main one ("contact" over "contact-thanks").
            if ( isset( $found[ $kind ] ) && strlen( $found[ $kind ]['path'] ) <= strlen( (string) wp_parse_url( get_permalink( $post ), PHP_URL_PATH ) ) ) {
                continue;
            }
            $url            = get_permalink( $post );
            $found[ $kind ] = array(
                'kind'    => $kind,
                'post_id' => (int) $id,
                'title'   => html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' ),
                'url'     => $url,
                'path'    => (string) wp_parse_url( $url, PHP_URL_PATH ),
            );
        }

        uksort( $found, function ( $a, $b ) use ( $priority ) {
            return $priority[ $a ] - $priority[ $b ];
        } );
        $out = array_values( $found );

        $settings = get_option( 'asgm_settings', array() );
        $local    = isset( $settings['local_business'] ) && is_array( $settings['local_business'] ) ? $settings['local_business'] : array();
        $phone    = trim( (string) ( $local['phone'] ?? '' ) );
        $email    = sanitize_email( (string) ( $local['email'] ?? '' ) );

        if ( '' !== $phone ) {
            $digits = preg_replace( '~[^0-9+]~', '', $phone );
            if ( strlen( $digits ) >= 6 ) {
                $out[] = array( 'kind' => 'phone', 'post_id' => 0, 'title' => $phone, 'url' => 'tel:' . $digits, 'path' => $phone );
            }
        }
        if ( '' !== $email && is_email( $email ) ) {
            $out[] = array( 'kind' => 'email', 'post_id' => 0, 'title' => $email, 'url' => 'mailto:' . $email, 'path' => $email );
        }

        $profile = class_exists( __NAMESPACE__ . '\\Site_Profile' ) ? Site_Profile::get() : array();

        return array(
            'destinations' => $out,
            'business'     => array(
                'name'        => (string) ( $profile['identity']['business_name'] ?? get_bloginfo( 'name' ) ),
                'description' => (string) ( $profile['context']['description'] ?? get_bloginfo( 'description' ) ),
                'audience'    => (string) ( $profile['context']['primary_audience'] ?? '' ),
            ),
        );
    }

    /**
     * Dashboard summary only.
     *
     * @return array
     */
    public static function summary() {
        $r = self::get_results( false );
        return array(
            'summary'     => $r['summary'],
            'has_preset'  => null !== $r['preset'],
            'computed_at' => $r['computed_at'],
        );
    }
}
