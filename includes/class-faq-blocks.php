<?php
/**
 * Portable content-block shortcodes.
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
 *   [aeogm_pro_tip title="Pro tip"]Useful practical advice.[/aeogm_pro_tip]
 *
 *   [aeogm_pros_cons]
 *     [aeogm_pros]- First advantage\n- Second advantage[/aeogm_pros]
 *     [aeogm_cons]- First limitation\n- Second limitation[/aeogm_cons]
 *   [/aeogm_pros_cons]
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
        add_shortcode( 'aeogm_pro_tip', array( $this, 'render_pro_tip' ) );
        add_shortcode( 'aeogm_pros_cons', array( $this, 'render_pros_cons' ) );
        add_shortcode( 'aeogm_pros', array( $this, 'render_pros' ) );
        add_shortcode( 'aeogm_cons', array( $this, 'render_cons' ) );
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
            . '<aside class="aeogm-content-block aeogm-tldr aeogm-speakable" aria-label="' . esc_attr( $atts['title'] ) . '">'
            . '<span class="aeogm-content-block__icon" aria-hidden="true">' . self::icon( 'summary' ) . '</span>'
            . '<div class="aeogm-content-block__body">'
            . ( '' !== trim( (string) $atts['title'] ) ? '<p class="aeogm-content-block__label">' . esc_html( $atts['title'] ) . '</p>' : '' )
            . wp_kses_post( $body )
            . '</div></aside>';
    }

    /** Render a practical callout without claiming that the advice is expert-certified. */
    public function render_pro_tip( $atts, $content = '' ) {
        $atts = shortcode_atts( array(
            'title' => __( 'Pro tip', 'aeo-god-mode' ),
        ), $atts, 'aeogm_pro_tip' );

        $body = trim( do_shortcode( wpautop( (string) $content ) ) );
        if ( '' === $body ) {
            return '';
        }

        return self::css()
            . '<aside class="aeogm-content-block aeogm-pro-tip" aria-label="' . esc_attr( $atts['title'] ) . '">'
            . '<span class="aeogm-content-block__icon" aria-hidden="true">' . self::icon( 'tip' ) . '</span>'
            . '<div class="aeogm-content-block__body">'
            . ( '' !== trim( (string) $atts['title'] ) ? '<p class="aeogm-content-block__label">' . esc_html( $atts['title'] ) . '</p>' : '' )
            . wp_kses_post( $body )
            . '</div></aside>';
    }

    /** Wrapper that keeps the two comparison cards together on wide screens. */
    public function render_pros_cons( $atts, $content = '' ) {
        // Claim the one-time stylesheet before rendering nested cards. Nested
        // shortcodes otherwise claim it first and their SVG/CSS can be removed
        // when the completed inner markup is sanitised a second time.
        $css   = self::css();
        $inner = trim( do_shortcode( wp_kses_post( (string) $content ) ) );
        if ( '' === $inner ) {
            return '';
        }

        return $css . '<div class="aeogm-pros-cons">' . $inner . '</div>';
    }

    public function render_pros( $atts, $content = '' ) {
        $atts = shortcode_atts( array(
            'title' => __( 'Pros', 'aeo-god-mode' ),
        ), $atts, 'aeogm_pros' );
        return $this->render_list_card( 'pros', $atts['title'], $content );
    }

    public function render_cons( $atts, $content = '' ) {
        $atts = shortcode_atts( array(
            'title' => __( 'Cons', 'aeo-god-mode' ),
        ), $atts, 'aeogm_cons' );
        return $this->render_list_card( 'cons', $atts['title'], $content );
    }

    /** Build a safe list from either real list markup or simple dash-separated lines. */
    private function render_list_card( $kind, $title, $content ) {
        $raw = trim( do_shortcode( (string) $content ) );
        if ( '' === $raw ) {
            return '';
        }

        if ( false !== stripos( $raw, '<li' ) ) {
            $list = wp_kses_post( $raw );
        } else {
            $plain = trim( wp_strip_all_tags( $raw ) );
            $lines = preg_split( '/\r\n|\r|\n/u', $plain );
            $items = array();
            foreach ( (array) $lines as $line ) {
                $line = trim( preg_replace( '/^[\s\-*+\x{2022}]+/u', '', (string) $line ) );
                if ( '' !== $line ) {
                    $items[] = '<li>' . esc_html( $line ) . '</li>';
                }
            }
            if ( empty( $items ) ) {
                return '';
            }
            $list = '<ul>' . implode( '', $items ) . '</ul>';
        }

        return self::css()
            . '<section class="aeogm-comparison-card aeogm-comparison-card--' . esc_attr( $kind ) . '">'
            . '<div class="aeogm-comparison-card__heading">'
            . '<span class="aeogm-comparison-card__icon" aria-hidden="true">' . self::icon( $kind ) . '</span>'
            . '<h3>' . esc_html( $title ) . '</h3>'
            . '</div>'
            . '<div class="aeogm-comparison-card__content">' . $list . '</div>'
            . '</section>';
    }

    /** Small inline SVGs avoid external assets, font icons and front-end JavaScript. */
    private static function icon( $name ) {
        $paths = array(
            'summary' => '<path d="M4 6h16M4 12h10M4 18h13"/>',
            'tip'     => '<path d="M9 18h6M10 22h4M8.5 14.5A7 7 0 1 1 15.5 14.5C14.5 15.3 14 16.2 14 18h-4c0-1.8-.5-2.7-1.5-3.5Z"/>',
            'pros'    => '<path d="m5 12 4 4L19 6"/>',
            'cons'    => '<path d="m6 6 12 12M18 6 6 18"/>',
        );
        $path = $paths[ $name ] ?? $paths['summary'];
        return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" focusable="false" aria-hidden="true">' . $path . '</svg>';
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
        return '<style id="aeogm-content-block-css">'
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
            . '.aeogm-content-block{--aeogm-accent:#2563eb;position:relative;display:flex;gap:1em;border:1px solid color-mix(in srgb,var(--aeogm-accent) 25%,transparent);border-radius:16px;padding:1.15em 1.25em;margin:1.6em 0;overflow:hidden;background:linear-gradient(135deg,color-mix(in srgb,var(--aeogm-accent) 10%,transparent),color-mix(in srgb,var(--aeogm-accent) 3%,transparent))}'
            . '.aeogm-content-block:before{content:"";position:absolute;inset:0 auto 0 0;width:4px;background:var(--aeogm-accent)}'
            . '.aeogm-content-block__icon{width:2.35em;height:2.35em;border-radius:12px;display:grid;place-items:center;flex:0 0 auto;color:var(--aeogm-accent);background:color-mix(in srgb,var(--aeogm-accent) 13%,transparent)}'
            . '.aeogm-content-block__icon svg{width:1.25em;height:1.25em}'
            . '.aeogm-content-block__body{min-width:0;flex:1}'
            . '.aeogm-content-block__label{font-size:.76em;font-weight:800;letter-spacing:.09em;text-transform:uppercase;color:var(--aeogm-accent);margin:0 0 .42em}'
            . '.aeogm-content-block__body>p:first-of-type:not(.aeogm-content-block__label){margin-top:0}'
            . '.aeogm-content-block__body>p:last-child{margin-bottom:0}'
            . '.aeogm-pro-tip{--aeogm-accent:#7c3aed}'
            . '.aeogm-pros-cons{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:1em;margin:1.6em 0}'
            . '.aeogm-comparison-card{--aeogm-compare:#16a34a;border:1px solid color-mix(in srgb,var(--aeogm-compare) 25%,transparent);border-radius:16px;padding:1.1em 1.2em;background:color-mix(in srgb,var(--aeogm-compare) 6%,transparent)}'
            . '.aeogm-comparison-card--cons{--aeogm-compare:#e11d48}'
            . '.aeogm-comparison-card__heading{display:flex;align-items:center;gap:.65em;margin:0 0 .75em}'
            . '.aeogm-comparison-card__heading h3{font-size:1em;margin:0;color:inherit}'
            . '.aeogm-comparison-card__icon{width:1.75em;height:1.75em;border-radius:999px;display:grid;place-items:center;color:#fff;background:var(--aeogm-compare)}'
            . '.aeogm-comparison-card__icon svg{width:1em;height:1em}'
            . '.aeogm-comparison-card__content ul{margin:0;padding-left:1.2em}'
            . '.aeogm-comparison-card__content li{margin:.42em 0}'
            . '@media(max-width:640px){.aeogm-pros-cons{grid-template-columns:1fr}.aeogm-content-block{padding:1em}.aeogm-content-block__icon{width:2em;height:2em}}'
            . '</style>';
    }
}
