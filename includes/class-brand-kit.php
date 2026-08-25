<?php
/**
 * Brand Kit — one resolved set of brand tokens for every surface that renders
 * something on a customer's behalf.
 *
 * Content blocks, visual blocks, CTAs and Organization schema all need to know
 * the same handful of facts: the logo, five colours, how round the corners are
 * and how the brand talks. Before this class each surface guessed separately
 * (class-faq-blocks.php hardcoded #2563eb, schema fell back to the site icon),
 * so a site could look like three different brands in one post.
 *
 * Nothing here is required input. Every value resolves through three steps:
 *
 *   1. What the customer saved.
 *   2. What their theme already declares (theme.json / global styles) or WP
 *      already knows (custom logo, site icon).
 *   3. A neutral default that works on a light or dark theme.
 *
 * That ordering is the whole point: setup should be a confirmation screen, not
 * a form. A site with a well-built block theme and a custom logo gets a
 * complete, correct brand kit without the customer typing anything.
 *
 * Derived values (button text colour, muted fills, borders) are never stored.
 * They are computed, so changing one brand colour updates everything downstream
 * and no saved row can drift out of sync with its source.
 *
 * @package AISEOGodMode
 */

namespace AISEOGodMode;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class BrandKit {

    /**
     * Current shape of the saved brand array. Bumped only when a stored key
     * changes meaning, so a future migration can tell old data from new.
     */
    const SCHEMA_VERSION = 1;

    /** Neutral last-resort palette. Readable on light and dark themes alike. */
    const FALLBACK_COLORS = array(
        'primary'    => '#2563eb',
        'secondary'  => '#7c3aed',
        'accent'     => '#2563eb',
        'text'       => '#111827',
        'background' => '#ffffff',
    );

    /** Corner radius presets, in the customer's words. Used for buttons. */
    const RADII = array(
        'square'  => '0px',
        'soft'    => '4px',
        'medium'  => '10px',
        'rounded' => '18px',
        'pill'    => '999px',
    );

    /**
     * The same presets, capped for containers.
     *
     * A pill button is a deliberate look. A pill card is a stadium, and the
     * text inside it collides with the curve at the corners. Boxes therefore
     * get their own token, capped where a corner stops being a corner, so a
     * customer can pick pill buttons without their content blocks turning into
     * lozenges.
     */
    const RADII_BOX = array(
        'square'  => '0px',
        'soft'    => '4px',
        'medium'  => '10px',
        'rounded' => '18px',
        'pill'    => '24px',
    );

    const ICON_STYLES   = array( 'outline', 'solid' );
    const VISUAL_STYLES = array( 'clean', 'bold', 'editorial', 'playful' );
    const TONES         = array( 'direct', 'friendly', 'professional', 'understated' );

    /** Colour roles, in the order the setup screen presents them. */
    const COLOR_KEYS = array( 'primary', 'secondary', 'accent', 'text', 'background' );

    /** Memoised resolve() result. Cleared by flush(). */
    private static $resolved = null;

    /* ───────────────────────────── Reading ───────────────────────────── */

    /**
     * Exactly what the customer saved, sanitised, with nothing filled in.
     *
     * The settings screen needs this so it can show which values are the
     * customer's own choice and which are inherited from their theme. Use
     * resolve() for anything that renders.
     *
     * @return array Saved brand values; missing keys are absent, not defaulted.
     */
    public static function saved() {
        $settings = get_option( 'asgm_settings', array() );
        $brand    = isset( $settings['brand'] ) && is_array( $settings['brand'] ) ? $settings['brand'] : array();

        return self::sanitize( $brand, false );
    }

    /**
     * The full brand kit, every key populated, ready to render with.
     *
     * @return array{
     *   version:int, logo_id:int, logo_dark_id:int, mark_id:int,
     *   primary:string, secondary:string, accent:string, text:string,
     *   background:string, radius:string, radius_px:string,
     *   icon_style:string, visual_style:string, tone:string,
     *   on_primary:string, on_accent:string, sources:array
     * }
     */
    public static function resolve() {
        if ( null !== self::$resolved ) {
            return self::$resolved;
        }

        $saved   = self::saved();
        $theme   = self::theme_palette();
        $sources = array();

        $colors = array();
        foreach ( self::COLOR_KEYS as $key ) {
            if ( ! empty( $saved[ $key ] ) ) {
                $colors[ $key ]  = $saved[ $key ];
                $sources[ $key ] = 'saved';
            } elseif ( ! empty( $theme[ $key ] ) ) {
                $colors[ $key ]  = $theme[ $key ];
                $sources[ $key ] = 'theme';
            } else {
                $colors[ $key ]  = self::FALLBACK_COLORS[ $key ];
                $sources[ $key ] = 'default';
            }
        }

        // Accent is the one colour that reads better inheriting from primary
        // than from a neutral default: a site that set only a primary colour
        // wants its callouts in that colour, not in our blue.
        if ( 'default' === $sources['accent'] && 'default' !== $sources['primary'] ) {
            $colors['accent']  = $colors['primary'];
            $sources['accent'] = 'derived';
        }

        $logo_id = (int) ( $saved['logo_id'] ?? 0 );
        if ( $logo_id ) {
            $sources['logo'] = 'saved';
        } else {
            // Which fallback we landed on matters to the customer. A custom
            // logo is a logo; a site icon is a cropped square favicon, and
            // showing one as "your brand logo" without saying so invites the
            // reasonable conclusion that the feature is broken.
            $custom = (int) get_theme_mod( 'custom_logo' );
            if ( $custom ) {
                $logo_id         = $custom;
                $sources['logo'] = 'custom_logo';
            } else {
                $icon            = (int) get_option( 'site_icon' );
                $logo_id         = $icon;
                $sources['logo'] = $icon ? 'site_icon' : 'none';
            }
        }

        $radius = $saved['radius'] ?? '';
        if ( ! isset( self::RADII[ $radius ] ) ) {
            $radius            = self::theme_radius();
            $sources['radius'] = 'theme';
        } else {
            $sources['radius'] = 'saved';
        }

        self::$resolved = array(
            'version'      => self::SCHEMA_VERSION,
            'logo_id'      => $logo_id,
            'logo_dark_id' => (int) ( $saved['logo_dark_id'] ?? 0 ),
            'mark_id'      => (int) ( $saved['mark_id'] ?? 0 ),
            'radius'       => $radius,
            'radius_px'    => self::RADII[ $radius ],
            'radius_box'   => self::RADII_BOX[ $radius ],
            'icon_style'   => $saved['icon_style'] ?? 'outline',
            'visual_style' => $saved['visual_style'] ?? 'clean',
            'tone'         => $saved['tone'] ?? 'direct',
            // Button and badge text, picked for contrast rather than stored, so
            // it can never be left unreadable by a later colour change.
            'on_primary'   => self::readable_on( $colors['primary'] ),
            'on_accent'    => self::readable_on( $colors['accent'] ),
            'sources'      => $sources,
        ) + $colors;

        return self::$resolved;
    }

    /** Drop the memoised kit. Call after saving settings. */
    public static function flush() {
        self::$resolved = null;
    }

    /**
     * Resolve with unsaved values layered on top, for a live preview.
     *
     * The settings screen has to show what a block will look like before the
     * customer commits to it, and the only honest way to do that is to render
     * it with the real renderer rather than approximate it in React. So the
     * renderer needs to see values that are not in the database yet.
     *
     * Deliberately request-scoped and never written anywhere. Call flush()
     * afterwards; the preview endpoint does.
     *
     * @param array $overrides Unsaved brand values, sanitised here.
     */
    public static function override( $overrides ) {
        $clean = self::sanitize( $overrides, false );

        // Resolve first so inherited values are present, then layer the
        // customer's pending edits over the top. Only non-empty values win, so
        // clearing a colour in the form still previews the inherited one, which
        // is what saving it would actually do.
        $base = self::resolve();

        foreach ( $clean as $key => $value ) {
            if ( 'version' === $key || '' === $value || null === $value ) {
                continue;
            }
            $base[ $key ] = $value;
        }

        // Derived values must follow the override, not the saved palette.
        $base['on_primary'] = self::readable_on( $base['primary'] );
        $base['on_accent']  = self::readable_on( $base['accent'] );
        $base['radius_px']  = self::RADII[ $base['radius'] ] ?? self::RADII['medium'];
        $base['radius_box'] = self::RADII_BOX[ $base['radius'] ] ?? self::RADII_BOX['medium'];

        self::$resolved = $base;
    }

    /* ──────────────────────────── Rendering ──────────────────────────── */

    /**
     * Brand tokens as CSS custom properties, for inlining on a block wrapper.
     *
     * Returned as declarations only (no selector, no braces) so the caller can
     * drop them straight into a style attribute. Every downstream stylesheet
     * reads these, which is what makes "change a brand colour" a zero-credit,
     * zero-regeneration operation across every published post.
     *
     * @return string e.g. "--aeogm-brand-primary:#2563eb;--aeogm-brand-on-primary:#ffffff;"
     */
    public static function css_vars() {
        $b   = self::resolve();
        $map = array(
            'primary'    => $b['primary'],
            'secondary'  => $b['secondary'],
            'accent'     => $b['accent'],
            'text'       => $b['text'],
            'background' => $b['background'],
            'on-primary' => $b['on_primary'],
            'on-accent'  => $b['on_accent'],
            'radius'     => $b['radius_px'],
            'radius-box' => $b['radius_box'],
        );

        $out = '';
        foreach ( $map as $name => $value ) {
            $out .= '--aeogm-brand-' . $name . ':' . $value . ';';
        }

        return $out;
    }

    /**
     * Resolve a logo to a usable URL.
     *
     * @param string $variant 'primary', 'dark' (falls back to primary) or 'mark'.
     * @return string Attachment URL, or '' when the site has no logo at all.
     */
    public static function logo_url( $variant = 'primary' ) {
        $b  = self::resolve();
        $id = 0;

        if ( 'dark' === $variant ) {
            $id = $b['logo_dark_id'] ? $b['logo_dark_id'] : $b['logo_id'];
        } elseif ( 'mark' === $variant ) {
            $id = $b['mark_id'] ? $b['mark_id'] : $b['logo_id'];
        } else {
            $id = $b['logo_id'];
        }

        if ( ! $id ) {
            return '';
        }

        $url = wp_get_attachment_image_url( $id, 'full' );

        return $url ? $url : '';
    }

    /* ─────────────────────────── Sanitising ─────────────────────────── */

    /**
     * Clean an incoming brand array.
     *
     * @param array $incoming Untrusted input.
     * @param bool  $fill     True to default every key, false to keep only the
     *                        keys actually supplied (used by saved()).
     * @return array
     */
    public static function sanitize( $incoming, $fill = true ) {
        $incoming = is_array( $incoming ) ? $incoming : array();
        $out      = array( 'version' => self::SCHEMA_VERSION );

        foreach ( self::COLOR_KEYS as $key ) {
            if ( ! array_key_exists( $key, $incoming ) ) {
                if ( $fill ) {
                    $out[ $key ] = self::FALLBACK_COLORS[ $key ];
                }
                continue;
            }
            $hex = self::hex( $incoming[ $key ] );
            // An explicitly cleared colour stays cleared, so resolve() can fall
            // back to the theme rather than pinning our default forever.
            if ( '' !== $hex || ! $fill ) {
                $out[ $key ] = $hex;
            } else {
                $out[ $key ] = self::FALLBACK_COLORS[ $key ];
            }
        }

        foreach ( array( 'logo_id', 'logo_dark_id', 'mark_id' ) as $key ) {
            if ( array_key_exists( $key, $incoming ) ) {
                $out[ $key ] = max( 0, (int) $incoming[ $key ] );
            } elseif ( $fill ) {
                $out[ $key ] = 0;
            }
        }

        $enums = array(
            'radius'       => array_keys( self::RADII ),
            'icon_style'   => self::ICON_STYLES,
            'visual_style' => self::VISUAL_STYLES,
            'tone'         => self::TONES,
        );
        $enum_defaults = array(
            'radius'       => 'medium',
            'icon_style'   => 'outline',
            'visual_style' => 'clean',
            'tone'         => 'direct',
        );

        foreach ( $enums as $key => $allowed ) {
            if ( array_key_exists( $key, $incoming ) ) {
                $value = is_string( $incoming[ $key ] ) ? strtolower( trim( $incoming[ $key ] ) ) : '';
                if ( in_array( $value, $allowed, true ) ) {
                    $out[ $key ] = $value;
                } elseif ( $fill ) {
                    $out[ $key ] = $enum_defaults[ $key ];
                }
            } elseif ( $fill ) {
                $out[ $key ] = $enum_defaults[ $key ];
            }
        }

        return $out;
    }

    /**
     * Normalise a colour to a lowercase six-digit hex, or '' if unusable.
     *
     * Accepts #abc and #aabbcc. Anything else (rgb(), a CSS variable, a colour
     * name, an injection attempt) is rejected outright rather than coerced,
     * because these values end up inside a style attribute.
     *
     * @param mixed $value Raw input.
     * @return string
     */
    public static function hex( $value ) {
        if ( ! is_string( $value ) ) {
            return '';
        }

        $value = strtolower( trim( $value ) );

        if ( preg_match( '/^#([0-9a-f]{3})$/', $value, $m ) ) {
            $c = $m[1];
            return '#' . $c[0] . $c[0] . $c[1] . $c[1] . $c[2] . $c[2];
        }

        return preg_match( '/^#[0-9a-f]{6}$/', $value ) ? $value : '';
    }

    /* ─────────────────────────── Contrast ─────────────────────────── */

    /**
     * Pick black or white text for a background, whichever reads better.
     *
     * Uses the WCAG relative-luminance formula rather than a naive brightness
     * average, because the two disagree on exactly the saturated brand colours
     * customers actually pick (a mid-blue reads as "bright" to the average but
     * genuinely needs white text).
     *
     * @param string $background Six-digit hex.
     * @return string '#ffffff' or '#111827'.
     */
    public static function readable_on( $background ) {
        $dark  = '#111827';
        $light = '#ffffff';
        $hex   = self::hex( $background );

        if ( '' === $hex ) {
            return $dark;
        }

        $lum = self::luminance( $hex );

        // Contrast against white vs against near-black, higher wins.
        $vs_white = ( 1.05 ) / ( $lum + 0.05 );
        $vs_dark  = ( $lum + 0.05 ) / ( self::luminance( $dark ) + 0.05 );

        return $vs_white >= $vs_dark ? $light : $dark;
    }

    /**
     * WCAG contrast ratio between two colours, 1.0 to 21.0.
     *
     * Exposed so the setup screen can warn at pick time instead of shipping an
     * unreadable button to a customer's readers.
     *
     * @param string $a Six-digit hex.
     * @param string $b Six-digit hex.
     * @return float Ratio, or 0.0 when either colour is unusable.
     */
    public static function contrast_ratio( $a, $b ) {
        $a = self::hex( $a );
        $b = self::hex( $b );

        if ( '' === $a || '' === $b ) {
            return 0.0;
        }

        $la = self::luminance( $a );
        $lb = self::luminance( $b );

        $light = max( $la, $lb );
        $dark  = min( $la, $lb );

        return round( ( $light + 0.05 ) / ( $dark + 0.05 ), 2 );
    }

    /**
     * WCAG relative luminance of a six-digit hex colour.
     *
     * @param string $hex Six-digit hex, already validated.
     * @return float 0.0 to 1.0.
     */
    private static function luminance( $hex ) {
        $channels = array(
            hexdec( substr( $hex, 1, 2 ) ),
            hexdec( substr( $hex, 3, 2 ) ),
            hexdec( substr( $hex, 5, 2 ) ),
        );

        $linear = array();
        foreach ( $channels as $channel ) {
            $c        = $channel / 255;
            $linear[] = $c <= 0.03928 ? $c / 12.92 : pow( ( $c + 0.055 ) / 1.055, 2.4 );
        }

        return ( 0.2126 * $linear[0] ) + ( 0.7152 * $linear[1] ) + ( 0.0722 * $linear[2] );
    }

    /* ──────────────────────── Theme inheritance ──────────────────────── */

    /**
     * Best-guess brand colours from the active theme's global styles.
     *
     * Block themes name their palette slugs conventionally enough that this
     * lands correctly most of the time, and when it does not the customer
     * overrides it on a screen that already shows a preview. Classic themes
     * simply return nothing and fall through to the defaults.
     *
     * @return array Subset of COLOR_KEYS that the theme could supply.
     */
    public static function theme_palette() {
        if ( ! function_exists( 'wp_get_global_settings' ) ) {
            return array();
        }

        $palette = wp_get_global_settings( array( 'color', 'palette' ) );
        $entries = array();

        // Theme, user and core palettes are separate lists; later ones win
        // because a user override should beat the theme's own default.
        foreach ( array( 'default', 'theme', 'custom' ) as $origin ) {
            if ( ! empty( $palette[ $origin ] ) && is_array( $palette[ $origin ] ) ) {
                foreach ( $palette[ $origin ] as $entry ) {
                    if ( ! empty( $entry['slug'] ) && ! empty( $entry['color'] ) ) {
                        $entries[ strtolower( $entry['slug'] ) ] = $entry['color'];
                    }
                }
            }
        }

        if ( ! $entries ) {
            return array();
        }

        // Slug candidates per role, most specific first.
        $candidates = array(
            'primary'    => array( 'primary', 'brand', 'accent', 'accent-1', 'theme-1' ),
            'secondary'  => array( 'secondary', 'accent-2', 'theme-2' ),
            'accent'     => array( 'accent', 'primary', 'brand', 'accent-1' ),
            'text'       => array( 'foreground', 'text', 'contrast', 'base-text' ),
            'background' => array( 'background', 'base', 'body-background' ),
        );

        $out = array();
        foreach ( $candidates as $role => $slugs ) {
            foreach ( $slugs as $slug ) {
                if ( isset( $entries[ $slug ] ) ) {
                    $hex = self::hex( $entries[ $slug ] );
                    if ( '' !== $hex ) {
                        $out[ $role ] = $hex;
                        break;
                    }
                }
            }
        }

        return $out;
    }

    /**
     * Map the theme's global border radius onto our preset names.
     *
     * @return string One of RADII's keys.
     */
    private static function theme_radius() {
        if ( ! function_exists( 'wp_get_global_styles' ) ) {
            return 'medium';
        }

        $styles = wp_get_global_styles( array( 'border' ) );
        $raw    = isset( $styles['radius'] ) && is_string( $styles['radius'] ) ? trim( $styles['radius'] ) : '';

        if ( '' === $raw || ! preg_match( '/^([0-9.]+)\s*px$/', $raw, $m ) ) {
            return 'medium';
        }

        $px = (float) $m[1];

        if ( $px <= 1 ) {
            return 'square';
        }
        if ( $px <= 6 ) {
            return 'soft';
        }
        if ( $px <= 13 ) {
            return 'medium';
        }
        if ( $px <= 40 ) {
            return 'rounded';
        }

        return 'pill';
    }

    /**
     * The logo WordPress already knows about, if any.
     *
     * @return int Attachment ID, or 0.
     */
    private static function wp_logo_id() {
        $custom = (int) get_theme_mod( 'custom_logo' );
        if ( $custom ) {
            return $custom;
        }

        return (int) get_option( 'site_icon' );
    }
}
