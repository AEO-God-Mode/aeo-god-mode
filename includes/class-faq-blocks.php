<?php
/**
 * FAQ accordion + TL;DR shortcodes.
 *
 * Theme-agnostic content blocks that render on any site, any theme, any
 * editor, with zero JavaScript:
 *
 *   [aeogm_faqs title="Frequently Asked Questions" open="first" style="boxed"]
 *     [aeogm_faq q="Question text?"]Answer text.[/aeogm_faq]
 *     ...
 *   [/aeogm_faqs]
 *
 *   [aeogm_tldr title="TL;DR"]Two or three sentence direct answer.[/aeogm_tldr]
 *
 * The accordion uses native <details>/<summary>, so it is accessible and
 * needs no scripts. Styling is a small CSS block printed once per page and
 * inherits the theme's colors, so it looks native in light or dark themes.
 *
 * Schema stays with the Schema Engine (class-schema.php), which parses these
 * shortcodes from post content and emits FAQPage JSON-LD exactly once,
 * respecting third-party dedupe. These renderers emit NO schema themselves.
 *
 * The TL;DR box carries the stable `aeogm-speakable` class, giving Speakable
 * schema a reliable CSS selector.
 *
 * @package AISEOGodMode
 */

namespace AISEOGodMode;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FaqBlocks {

    /** Ensures the CSS is printed at most once per page. */
    private static $css_done = false;

    /** Collects answers between [aeogm_faqs] open and close. */
    private static $wrap_attrs = null;

    public function __construct() {
        add_shortcode( 'aeogm_faqs', array( $this, 'render_wrap' ) );
        add_shortcode( 'aeogm_faq', array( $this, 'render_item' ) );
        add_shortcode( 'aeogm_tldr', array( $this, 'render_tldr' ) );
        // Before block rendering (do_blocks runs at 9): drop an author-written
        // FAQ heading that sits immediately before the wrapper, because the
        // wrapper renders its own heading and the pair reads as a stutter
        // ("FAQ" then "Frequently Asked Questions"). Old generated drafts
        // carry exactly this, so they self-heal with no editing.
        add_filter( 'the_content', array( $this, 'dedupe_faq_heading' ), 8 );
    }

    /** Remove `<h2>FAQ</h2>` (and variants) directly preceding [aeogm_faqs]. */
    public function dedupe_faq_heading( $content ) {
        if ( false === stripos( (string) $content, '[aeogm_faqs' ) ) {
            return $content;
        }
        return preg_replace(
            '/(?:<!--\s*wp:heading[^>]*-->\s*)?<h[23][^>]*>\s*(?:faqs?|frequently asked questions|common questions)\s*:?\s*<\/h[23]>\s*(?:<!--\s*\/wp:heading\s*-->\s*)?(?=(?:<p[^>]*>\s*|<!--\s*wp:(?:shortcode|paragraph)[^>]*-->\s*)*\[aeogm_faqs)/i',
            '',
            (string) $content
        );
    }

    /* ─────────────────────────── Renderers ─────────────────────────── */

    public function render_wrap( $atts, $content = '' ) {
        $atts = shortcode_atts( array(
            'title' => __( 'Frequently Asked Questions', 'aeo-god-mode' ),
            'open'  => 'first',   // first | all | none
            'style' => 'boxed',   // boxed | minimal
        ), $atts, 'aeogm_faqs' );

        // Let the inner [aeogm_faq] items know their position and wrap style.
        self::$wrap_attrs = array(
            'open'  => in_array( $atts['open'], array( 'first', 'all', 'none' ), true ) ? $atts['open'] : 'first',
            'style' => ( 'minimal' === $atts['style'] ) ? 'minimal' : 'boxed',
            'index' => 0,
        );

        $inner = do_shortcode( (string) $content );
        self::$wrap_attrs = null;

        if ( '' === trim( $inner ) ) {
            return '';
        }

        $out  = self::css();
        $out .= '<div class="aeogm-faqs aeogm-faqs--' . esc_attr( $atts['style'] ) . '">';
        if ( '' !== trim( (string) $atts['title'] ) ) {
            $out .= '<h2 class="aeogm-faqs__title">' . esc_html( $atts['title'] ) . '</h2>';
        }
        $out .= $inner . '</div>';
        return $out;
    }

    public function render_item( $atts, $content = '' ) {
        $atts = shortcode_atts( array(
            'q'     => '',
            'title' => '', // alias so hand-writers can use title= like other FAQ plugins
        ), $atts, 'aeogm_faq' );

        $question = trim( (string) ( '' !== $atts['q'] ? $atts['q'] : $atts['title'] ) );
        $answer   = trim( do_shortcode( wpautop( (string) $content ) ) );
        if ( '' === $question || '' === $answer ) {
            return '';
        }

        $open = '';
        if ( is_array( self::$wrap_attrs ) ) {
            $mode = self::$wrap_attrs['open'];
            if ( 'all' === $mode || ( 'first' === $mode && 0 === self::$wrap_attrs['index'] ) ) {
                $open = ' open';
            }
            self::$wrap_attrs['index']++;
        }

        // Standalone use (no wrapper) still renders and still gets the CSS.
        $css = is_array( self::$wrap_attrs ) ? '' : self::css();

        return $css
            . '<details class="aeogm-faq"' . $open . '>'
            . '<summary class="aeogm-faq__q">' . esc_html( $question ) . '</summary>'
            . '<div class="aeogm-faq__a">' . wp_kses_post( $answer ) . '</div>'
            . '</details>';
    }

    public function render_tldr( $atts, $content = '' ) {
        $atts = shortcode_atts( array(
            'title' => 'TL;DR',
        ), $atts, 'aeogm_tldr' );

        $body = trim( do_shortcode( wpautop( (string) $content ) ) );
        if ( '' === $body ) {
            return '';
        }

        return self::css()
            . '<div class="aeogm-tldr aeogm-speakable">'
            . ( '' !== trim( (string) $atts['title'] ) ? '<p class="aeogm-tldr__label">' . esc_html( $atts['title'] ) . '</p>' : '' )
            . wp_kses_post( $body )
            . '</div>';
    }

    /* ─────────────────────────── Styles ─────────────────────────── */

    /**
     * Small, theme-neutral CSS printed once per page, only on pages that
     * actually use a block. currentColor + transparent tints inherit the
     * theme, so the blocks look native in light and dark themes alike.
     */
    private static function css() {
        if ( self::$css_done ) {
            return '';
        }
        self::$css_done = true;
        return '<style id="aeogm-faq-css">'
            . '.aeogm-faqs{margin:1.5em 0}'
            . '.aeogm-faqs__title{margin:0 0 .6em}'
            . '.aeogm-faq{border:1px solid rgba(128,128,128,.35);border-radius:10px;margin:0 0 .6em;overflow:hidden}'
            . '.aeogm-faqs--minimal .aeogm-faq{border:0;border-bottom:1px solid rgba(128,128,128,.3);border-radius:0;margin:0}'
            . '.aeogm-faq__q{cursor:pointer;padding:.85em 1em;font-weight:600;list-style:none;display:flex;align-items:center;justify-content:space-between;gap:.75em}'
            . '.aeogm-faq__q::-webkit-details-marker{display:none}'
            . '.aeogm-faq__q::after{content:"+";font-weight:700;opacity:.55;flex-shrink:0;transition:transform .18s ease}'
            . '.aeogm-faq[open]>.aeogm-faq__q::after{transform:rotate(45deg)}'
            . '.aeogm-faq[open]>.aeogm-faq__q{border-bottom:1px solid rgba(128,128,128,.25)}'
            . '.aeogm-faq__a{padding:.85em 1em}'
            . '.aeogm-faq__a>p:first-child{margin-top:0}'
            . '.aeogm-faq__a>p:last-child{margin-bottom:0}'
            . '.aeogm-tldr{border:1px solid rgba(128,128,128,.35);border-left:4px solid currentColor;border-radius:10px;padding:1em 1.2em;margin:1.5em 0}'
            . '.aeogm-tldr__label{font-size:.78em;font-weight:700;letter-spacing:.08em;text-transform:uppercase;opacity:.65;margin:0 0 .4em}'
            . '.aeogm-tldr>p:last-child{margin-bottom:0}'
            . '</style>';
    }
}
