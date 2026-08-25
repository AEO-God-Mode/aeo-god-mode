<?php
/**
 * Call-to-action renderer.
 *
 * A CTA is ordinary semantic HTML: a wrapper, optional heading, a sentence and
 * one anchor. No JavaScript, no web font, no image beyond an optional icon from
 * our own registry. That keeps it fast, keeps it accessible, and keeps it from
 * shifting layout as the page settles.
 *
 * The one genuinely load-bearing decision here is that internal destinations
 * store a post ID, not a URL, and resolve to a permalink at render time. Link
 * Health regularly finds broken internal links on real sites, and almost all of
 * them are hardcoded URLs that outlived a slug change. A CTA that stores an ID
 * cannot join them. The URL is stored too, but only as the fallback the saved
 * post content uses when the plugin is inactive.
 *
 * @package AISEOGodMode
 */

namespace AISEOGodMode;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CTARenderer {

    /** Bumped when a stored attribute changes meaning. */
    const SCHEMA_VERSION = 1;

    const LAYOUTS        = array( 'link', 'button', 'card', 'banner' );
    const HEADING_LEVELS = array( 'none', 'p', 'h2', 'h3', 'h4' );
    const RELS           = array( 'none', 'nofollow', 'sponsored' );
    const BACKGROUNDS    = array( 'light', 'dark', 'transparent' );
    const PADDINGS       = array( 'compact', 'comfortable', 'roomy' );
    const STYLES         = array( 'clean', 'bold', 'editorial', 'playful' );

    /**
     * Protocols an anchor may use.
     *
     * An allowlist rather than a denylist: `javascript:` is the obvious attack,
     * but `data:`, `vbscript:` and `blob:` are all equally unwelcome in a link
     * this plugin writes into someone's article.
     */
    const PROTOCOLS = array( 'http', 'https', 'mailto', 'tel' );

    /**
     * Anchor text that describes the mechanics of clicking rather than the
     * destination. Flagged for the editor to warn about, never rejected: "learn
     * more" alone is weak, but "learn more about our commercial plumbing
     * service" is perfectly descriptive, and a hard block would fight the
     * customer over their own copy.
     */
    const VAGUE_ANCHORS = array(
        'click here', 'here', 'read more', 'learn more', 'more', 'this',
        'link', 'this link', 'find out more', 'see more', 'go', 'click',
    );

    /* ─────────────────────────── Sanitising ─────────────────────────── */

    /**
     * Clean a full set of CTA attributes.
     *
     * @param array $attrs Untrusted attributes.
     * @return array|null Sanitised attributes, or null when unusable.
     */
    public static function sanitize( $attrs ) {
        $attrs = is_array( $attrs ) ? $attrs : array();

        $content = isset( $attrs['content'] ) && is_array( $attrs['content'] ) ? $attrs['content'] : array();
        $anchor  = self::text( $content['anchor'] ?? '', 80 );
        if ( '' === $anchor ) {
            return null;
        }

        $dest = self::destination( $attrs['destination'] ?? array() );
        if ( null === $dest ) {
            return null;
        }

        $layout = self::pick( $attrs['layout'] ?? '', self::LAYOUTS, 'button' );

        // A bare inline link has nowhere to put a heading or a body sentence,
        // so those are dropped rather than rendered somewhere they do not
        // belong. A button gets a body but still no heading.
        $heading = 'none';
        $body    = '';
        if ( in_array( $layout, array( 'card', 'banner' ), true ) ) {
            $heading = self::pick( $attrs['headingLevel'] ?? '', self::HEADING_LEVELS, 'h3' );
            $body    = self::text( $content['body'] ?? '', 220 );
        } elseif ( 'button' === $layout ) {
            $body = self::text( $content['body'] ?? '', 220 );
        }

        return array(
            'schemaVersion' => self::SCHEMA_VERSION,
            'layout'        => $layout,
            'headingLevel'  => $heading,
            'destination'   => $dest,
            'icon'          => VisualIcons::resolve( (string) ( $attrs['icon'] ?? '' ), 'cta' ),
            'background'    => self::pick( $attrs['background'] ?? '', self::BACKGROUNDS, 'light' ),
            'padding'       => self::pick( $attrs['padding'] ?? '', self::PADDINGS, 'comfortable' ),
            // Inherits the Brand Kit unless this block overrides it, so one
            // site-wide choice carries without setting it per CTA.
            'style'         => self::pick( $attrs['style'] ?? '', self::STYLES, BrandKit::resolve()['visual_style'] ),
            'content'       => array(
                'eyebrow' => 'none' === $heading ? '' : self::text( $content['eyebrow'] ?? '', 60 ),
                'heading' => 'none' === $heading ? '' : self::text( $content['heading'] ?? '', 90 ),
                'body'    => $body,
                'anchor'  => $anchor,
            ),
        );
    }

    /**
     * Clean a destination.
     *
     * Internal destinations keep their post ID as the source of truth and the
     * URL only as a fallback. External destinations keep a validated literal
     * URL, because there is nothing local to resolve against.
     *
     * @param mixed $raw Untrusted destination.
     * @return array|null
     */
    private static function destination( $raw ) {
        $raw  = is_array( $raw ) ? $raw : array();
        $type = ( 'external' === ( $raw['type'] ?? '' ) ) ? 'external' : 'post';

        $fragment = self::fragment( $raw['fragment'] ?? '' );

        if ( 'post' === $type ) {
            $id = max( 0, (int) ( $raw['id'] ?? 0 ) );
            if ( ! $id ) {
                return null;
            }

            return array(
                'type'      => 'post',
                'id'        => $id,
                'post_type' => sanitize_key( (string) ( $raw['post_type'] ?? 'post' ) ),
                // Captured when the CTA was inserted, used only by the saved
                // fallback and if the post is later deleted.
                'url'       => self::url( $raw['url'] ?? '' ),
                'fragment'  => $fragment,
                'new_tab'   => false,
                'rel'       => 'none',
            );
        }

        $url = self::url( $raw['url'] ?? '' );
        if ( '' === $url ) {
            return null;
        }

        return array(
            'type'      => 'external',
            'id'        => 0,
            'post_type' => '',
            'url'       => $url,
            'fragment'  => $fragment,
            'new_tab'   => ! empty( $raw['new_tab'] ),
            'rel'       => self::pick( $raw['rel'] ?? '', self::RELS, 'none' ),
        );
    }

    /**
     * Validate a URL against the protocol allowlist.
     *
     * @param mixed $value Raw input.
     * @return string Safe URL, or '' when unusable.
     */
    private static function url( $value ) {
        if ( ! is_string( $value ) ) {
            return '';
        }

        $value = trim( $value );
        if ( '' === $value ) {
            return '';
        }

        $safe = esc_url_raw( $value, self::PROTOCOLS );
        if ( '' === $safe ) {
            return '';
        }

        // esc_url_raw() strips a disallowed scheme rather than failing, which
        // can turn "javascript:alert(1)" into something that still resolves.
        // Require an explicit allowed scheme instead of trusting the strip.
        $scheme = strtolower( (string) wp_parse_url( $safe, PHP_URL_SCHEME ) );

        return in_array( $scheme, self::PROTOCOLS, true ) ? $safe : '';
    }

    /**
     * Clean an optional anchor fragment ("#pricing").
     *
     * @param mixed $value Raw input.
     * @return string Fragment including its leading '#', or ''.
     */
    private static function fragment( $value ) {
        if ( ! is_string( $value ) ) {
            return '';
        }

        $value = ltrim( trim( $value ), '#' );
        $value = preg_replace( '/[^A-Za-z0-9_\-]/', '', $value );

        return '' === $value ? '' : '#' . substr( $value, 0, 60 );
    }

    /**
     * Whether anchor text is too vague to describe its destination.
     *
     * Exposed so the editor can warn at authoring time. Only an exact match on
     * the whole string counts, so a descriptive phrase that happens to begin
     * with "learn more" passes.
     *
     * @param string $anchor Anchor text.
     * @return bool
     */
    public static function anchor_is_vague( $anchor ) {
        $anchor = strtolower( trim( (string) $anchor ) );
        $anchor = rtrim( $anchor, ' .!>-→' );

        return in_array( $anchor, self::VAGUE_ANCHORS, true );
    }

    private static function text( $value, $max ) {
        if ( ! is_scalar( $value ) ) {
            return '';
        }

        $value = wp_strip_all_tags( (string) $value, true );
        $value = trim( (string) preg_replace( '/\s+/u', ' ', $value ) );

        if ( '' === $value ) {
            return '';
        }

        if ( function_exists( 'mb_strlen' ) && mb_strlen( $value ) > $max ) {
            return rtrim( mb_substr( $value, 0, $max ) );
        }

        return strlen( $value ) > $max ? rtrim( substr( $value, 0, $max ) ) : $value;
    }

    private static function pick( $value, $allowed, $default ) {
        $value = is_string( $value ) ? strtolower( trim( $value ) ) : '';

        return in_array( $value, $allowed, true ) ? $value : $default;
    }

    /* ──────────────────────────── Resolving ──────────────────────────── */

    /**
     * Work out where this CTA points, right now.
     *
     * An internal destination resolves through get_permalink() every render, so
     * the link follows its target through slug changes, hierarchy moves and
     * domain changes. If the post has since been deleted or unpublished the
     * stored URL is used, because a stale link is better than an empty href.
     *
     * @param array $dest Sanitised destination.
     * @return string URL, or '' when nothing resolves.
     */
    public static function resolve_url( $dest ) {
        $url = '';

        if ( 'post' === $dest['type'] && $dest['id'] ) {
            $status = get_post_status( $dest['id'] );
            if ( 'publish' === $status ) {
                $permalink = get_permalink( $dest['id'] );
                if ( $permalink ) {
                    $url = $permalink;
                }
            }
        }

        if ( '' === $url ) {
            $url = $dest['url'];
        }

        if ( '' === $url ) {
            return '';
        }

        return $url . $dest['fragment'];
    }

    /* ──────────────────────────── Rendering ──────────────────────────── */

    /**
     * Render a CTA.
     *
     * @param array $attrs Attributes; sanitised here, so callers may pass raw.
     * @return string HTML, or '' when there is nothing renderable.
     */
    public static function render( $attrs ) {
        $a = self::sanitize( $attrs );
        if ( null === $a ) {
            return '';
        }

        $url = self::resolve_url( $a['destination'] );
        if ( '' === $url ) {
            return '';
        }

        $c    = $a['content'];
        $link = self::anchor( $a, $url );

        // An inline link is a sentence-level element, so it renders as one
        // rather than being boxed. Wrapping it in a figure would break the
        // paragraph it belongs to.
        if ( 'link' === $a['layout'] ) {
            return '<span class="aeogm-cta aeogm-cta--link"'
                . ' style="' . esc_attr( BrandKit::css_vars() ) . '">' . $link . '</span>';
        }

        $inner = '';
        if ( '' !== $c['eyebrow'] ) {
            $inner .= '<p class="aeogm-cta__eyebrow">' . esc_html( $c['eyebrow'] ) . '</p>';
        }
        if ( '' !== $c['heading'] && 'none' !== $a['headingLevel'] ) {
            $tag    = 'p' === $a['headingLevel'] ? 'p' : $a['headingLevel'];
            $class  = 'p' === $tag ? ' class="aeogm-cta__heading aeogm-cta__heading--plain"' : ' class="aeogm-cta__heading"';
            $inner .= '<' . $tag . $class . '>' . esc_html( $c['heading'] ) . '</' . $tag . '>';
        }
        if ( '' !== $c['body'] ) {
            $inner .= '<p class="aeogm-cta__body">' . esc_html( $c['body'] ) . '</p>';
        }

        $classes = array(
            'aeogm-cta',
            'aeogm-cta--' . $a['layout'],
            'aeogm-cta--bg-' . $a['background'],
            'aeogm-cta--p-' . $a['padding'],
            'aeogm-cta--style-' . $a['style'],
        );

        return '<div class="' . esc_attr( implode( ' ', $classes ) ) . '"'
            . ' style="' . esc_attr( BrandKit::css_vars() ) . '">'
            . $inner . $link
            . '</div>';
    }

    /**
     * Build the anchor element itself.
     *
     * @param array  $a   Sanitised attributes.
     * @param string $url Resolved URL.
     * @return string
     */
    private static function anchor( $a, $url ) {
        $dest = $a['destination'];
        $rel  = array();

        if ( 'external' === $dest['type'] ) {
            if ( 'none' !== $dest['rel'] ) {
                $rel[] = $dest['rel'];
            }
            if ( $dest['new_tab'] ) {
                // noopener is not optional on a target="_blank" link: without
                // it the opened page gets scripting access back to this one.
                $rel[] = 'noopener';
                $rel[] = 'noreferrer';
            }
        }

        $attrs = ' class="aeogm-cta__action" href="' . esc_url( $url ) . '"';
        if ( $rel ) {
            $attrs .= ' rel="' . esc_attr( implode( ' ', array_unique( $rel ) ) ) . '"';
        }
        if ( 'external' === $dest['type'] && $dest['new_tab'] ) {
            $attrs .= ' target="_blank"';
        }

        $icon = ( 'link' === $a['layout'] ) ? '' : VisualIcons::svg( $a['icon'] );

        return '<a' . $attrs . '><span>' . esc_html( $a['content']['anchor'] ) . '</span>' . $icon . '</a>';
    }

    /**
     * Plain HTML for saving into post content.
     *
     * Uses the stored URL rather than a resolved permalink, because this is
     * exactly the markup that has to work when the plugin is not running.
     *
     * @param array $attrs Attributes.
     * @return string
     */
    public static function fallback( $attrs ) {
        $a = self::sanitize( $attrs );
        if ( null === $a ) {
            return '';
        }

        $url = $a['destination']['url'] . $a['destination']['fragment'];
        if ( '' === trim( $url ) ) {
            return '';
        }

        $c    = $a['content'];
        $body = '';

        if ( '' !== $c['heading'] && 'none' !== $a['headingLevel'] ) {
            $tag   = 'p' === $a['headingLevel'] ? 'p' : $a['headingLevel'];
            $body .= '<' . $tag . '>' . esc_html( $c['heading'] ) . '</' . $tag . '>';
        }
        if ( '' !== $c['body'] ) {
            $body .= '<p>' . esc_html( $c['body'] ) . '</p>';
        }

        $rel = array();
        if ( 'external' === $a['destination']['type'] ) {
            if ( 'none' !== $a['destination']['rel'] ) {
                $rel[] = $a['destination']['rel'];
            }
            if ( $a['destination']['new_tab'] ) {
                $rel[] = 'noopener';
                $rel[] = 'noreferrer';
            }
        }

        $link = '<a href="' . esc_url( $url ) . '"'
            . ( $rel ? ' rel="' . esc_attr( implode( ' ', array_unique( $rel ) ) ) . '"' : '' )
            . ( ( 'external' === $a['destination']['type'] && $a['destination']['new_tab'] ) ? ' target="_blank"' : '' )
            . '>' . esc_html( $c['anchor'] ) . '</a>';

        if ( 'link' === $a['layout'] ) {
            return $link;
        }

        return '<div class="aeogm-cta-fallback">' . $body . '<p>' . $link . '</p></div>';
    }

    /* ────────────────────────────── Styles ────────────────────────────── */

    /**
     * The CTA stylesheet, as raw CSS.
     *
     * Enqueued by VisualBlocks rather than inlined per render, for the same
     * reason as VisualTemplates::css(). Sizing is fixed in em and padding,
     * never computed at runtime, so the element occupies its final space on
     * first paint and contributes nothing to cumulative layout shift.
     *
     * @return string CSS.
     */
    public static function css() {
        $css =
              '.aeogm-cta{--aeogm-cp:1.5em;position:relative;overflow:hidden;box-sizing:border-box;max-width:100%}'
            . '.aeogm-cta *{box-sizing:border-box;min-width:0}'
            . '.aeogm-cta__eyebrow{position:relative;z-index:1;font-size:.74em;font-weight:800;'
            . 'letter-spacing:.1em;text-transform:uppercase;margin:0 0 .5em;color:var(--aeogm-brand-accent)}'
            . '.aeogm-cta__heading{position:relative;z-index:1;margin:0 0 .4em;font-size:1.3em;line-height:1.25;letter-spacing:-.01em}'
            . '.aeogm-cta__heading--plain{font-weight:700}'
            . '.aeogm-cta__body{position:relative;z-index:1;margin:0 0 1.15em;line-height:1.55;opacity:.88}'

            // ── Anchor ──
            . '.aeogm-cta__action{position:relative;z-index:1;display:inline-flex;align-items:center;'
            . 'gap:.55em;text-decoration:none;font-weight:650;line-height:1.25;'
            . 'transition:transform .15s ease,opacity .15s ease}'
            . '.aeogm-cta__action .aeogm-icon{width:1.05em;height:1.05em;flex:0 0 auto;'
            . 'transition:transform .15s ease}'
            . '.aeogm-cta__action:hover .aeogm-icon{transform:translateX(3px)}'
            . '.aeogm-cta__action:focus-visible{outline:3px solid var(--aeogm-brand-accent);outline-offset:3px}'

            // ── Inline link ──
            . '.aeogm-cta--link{display:inline;overflow:visible}'
            . '.aeogm-cta--link .aeogm-cta__action{display:inline;color:var(--aeogm-brand-accent);'
            . 'text-decoration:underline;text-underline-offset:.15em;text-decoration-thickness:2px}'

            // ── Button treatment ──
            . '.aeogm-cta--button .aeogm-cta__action,'
            . '.aeogm-cta--card .aeogm-cta__action,'
            . '.aeogm-cta--banner .aeogm-cta__action{padding:.78em 1.4em;'
            . 'border-radius:var(--aeogm-brand-radius,10px);background:var(--aeogm-brand-primary);'
            . 'color:var(--aeogm-brand-on-primary)}'
            . '.aeogm-cta--button .aeogm-cta__action:hover,'
            . '.aeogm-cta--card .aeogm-cta__action:hover,'
            . '.aeogm-cta--banner .aeogm-cta__action:hover{transform:translateY(-1px)}'

            // ── Boxes ──
            . '.aeogm-cta--button{margin:1.5em 0}'
            . '.aeogm-cta--card,.aeogm-cta--banner{margin:2em 0;'
            // Padding grows with the corner radius, or the first word sits
            // inside the curve on a heavily rounded brand.
            . 'padding:var(--aeogm-cp) max(var(--aeogm-cp),calc(var(--aeogm-brand-radius-box,10px) * .95));'
            . 'border-radius:var(--aeogm-brand-radius-box,10px)}'
            // Banner: content left, action right, stacking when there is no room.
            . '.aeogm-cta--banner{display:flex;flex-wrap:wrap;align-items:center;gap:1.1em 2em}'
            . '.aeogm-cta--banner .aeogm-cta__eyebrow{flex-basis:100%;margin-bottom:.35em}'
            . '.aeogm-cta--banner .aeogm-cta__heading{flex:1 1 18rem;margin:0}'
            . '.aeogm-cta--banner .aeogm-cta__body{flex-basis:100%;margin:0}'
            . '.aeogm-cta--banner .aeogm-cta__action{flex:0 0 auto;margin-left:auto}'

            // ── Padding scale ──
            . '.aeogm-cta--p-compact{--aeogm-cp:1em}'
            . '.aeogm-cta--p-comfortable{--aeogm-cp:1.5em}'
            . '.aeogm-cta--p-roomy{--aeogm-cp:2.4em}'

            // ── Backgrounds ──
            . '.aeogm-cta--bg-light{background:color-mix(in srgb,var(--aeogm-brand-accent) 7%,transparent);'
            . 'border:1px solid color-mix(in srgb,var(--aeogm-brand-accent) 18%,transparent)}'
            . '.aeogm-cta--bg-dark{background:var(--aeogm-brand-primary);color:var(--aeogm-brand-on-primary);border:0}'
            . '.aeogm-cta--bg-dark .aeogm-cta__eyebrow{color:var(--aeogm-brand-on-primary);opacity:.8}'
            . '.aeogm-cta--bg-dark .aeogm-cta__action{background:var(--aeogm-brand-on-primary);'
            . 'color:var(--aeogm-brand-primary)}'
            . '.aeogm-cta--bg-transparent{background:none;border:0;padding-left:0;padding-right:0}'

            // ── Styles ──
            . '.aeogm-cta--style-bold.aeogm-cta--card,.aeogm-cta--style-bold.aeogm-cta--banner{'
            . 'border:0;border-left:5px solid var(--aeogm-brand-accent);'
            . 'border-top-left-radius:0;border-bottom-left-radius:0}'
            . '.aeogm-cta--style-bold .aeogm-cta__heading{font-weight:800;letter-spacing:-.02em}'
            . '.aeogm-cta--style-bold.aeogm-cta--bg-light{background:color-mix(in srgb,var(--aeogm-brand-accent) 11%,transparent)}'
            . '.aeogm-cta--style-editorial.aeogm-cta--card,.aeogm-cta--style-editorial.aeogm-cta--banner{'
            . 'background:none;border:0;border-top:2px solid currentColor;border-bottom:1px solid color-mix(in srgb,currentColor 20%,transparent);'
            . 'border-radius:0;padding-left:0;padding-right:0}'
            . '.aeogm-cta--style-editorial .aeogm-cta__heading{font-family:Georgia,"Times New Roman",serif;font-weight:600}'
            . '.aeogm-cta--style-editorial .aeogm-cta__eyebrow{color:inherit;opacity:.6}'
            . '.aeogm-cta--style-playful.aeogm-cta--card,.aeogm-cta--style-playful.aeogm-cta--banner{'
            . 'border:0;border-radius:calc(var(--aeogm-brand-radius-box,10px) + 12px)}'
            . '.aeogm-cta--style-playful.aeogm-cta--bg-light{background:linear-gradient(135deg,'
            . 'color-mix(in srgb,var(--aeogm-brand-accent) 16%,transparent),'
            . 'color-mix(in srgb,var(--aeogm-brand-secondary) 10%,transparent))}'
            . '.aeogm-cta--style-playful .aeogm-cta__action{border-radius:999px}'

            . '.aeogm-cta-fallback{margin:1.6em 0}'

            . '@media(max-width:520px){.aeogm-cta--card,.aeogm-cta--banner{--aeogm-cp:1.1em}'
            . '.aeogm-cta--banner .aeogm-cta__action{margin-left:0;width:100%;justify-content:center}'
            . '.aeogm-cta--button .aeogm-cta__action{width:100%;justify-content:center}}';

        return $css;
    }
}
