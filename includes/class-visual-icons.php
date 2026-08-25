<?php
/**
 * Visual icon registry.
 *
 * Every icon that can appear in a content block or CTA lives here, as path
 * geometry this plugin controls. Nothing else may supply icon markup.
 *
 * That rule exists for the AI layer that lands later: a model asks for
 * "trend-up" by name and gets whatever this file says "trend-up" means. It can
 * never hand us path data, a data: URI or a remote image, so the whole class of
 * "the model emitted hostile SVG" simply cannot occur. Requesting an unknown
 * name returns nothing rather than falling back to something arbitrary.
 *
 * Icons are stroke-drawn on a 24x24 grid and inherit currentColor, so one path
 * set serves both icon styles: `outline` draws the glyph on a tinted chip,
 * `solid` draws it on a filled brand chip. Two path sets would be two things to
 * keep in sync for no visual gain.
 *
 * @package AISEOGodMode
 */

namespace AISEOGodMode;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class VisualIcons {

    /**
     * name => inner markup of a 24x24 stroke icon.
     *
     * Deliberately geometry only: no fill, no colour, no width, no height. The
     * wrapper in svg() owns every one of those, so a path can never carry
     * styling of its own.
     */
    const ICONS = array(
        'trend-up'   => '<polyline points="3 17 9 11 13 15 21 7"/><polyline points="15 7 21 7 21 13"/>',
        'check'      => '<polyline points="20 6 9 17 4 12"/>',
        'star'       => '<polygon points="12 3 14.9 9.1 21.5 10 16.7 14.6 17.9 21.2 12 18.1 6.1 21.2 7.3 14.6 2.5 10 9.1 9.1"/>',
        'lightbulb'  => '<path d="M9.5 18h5"/><path d="M10 21.5h4"/><path d="M12 2.5a6 6 0 0 0-3.5 10.9c.6.5.9 1.1.9 1.8v.3h5.2v-.3c0-.7.3-1.3.9-1.8A6 6 0 0 0 12 2.5Z"/>',
        'quote'      => '<path d="M9.5 5.5C6.5 6.9 5 9.4 5 13v5.5h6V13H8c0-2.4.8-4 2.6-4.8Z"/><path d="M19.5 5.5C16.5 6.9 15 9.4 15 13v5.5h6V13h-3c0-2.4.8-4 2.6-4.8Z"/>',
        'info'       => '<circle cx="12" cy="12" r="9"/><path d="M12 11v5.5"/><path d="M12 7.6v.6"/>',
        'target'     => '<circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="5"/><circle cx="12" cy="12" r="1.4"/>',
        'clock'      => '<circle cx="12" cy="12" r="9"/><polyline points="12 6.8 12 12 15.6 14"/>',
        'arrow-right'=> '<path d="M4 12h15"/><polyline points="13 6 19 12 13 18"/>',
        'download'   => '<path d="M12 3.5v11"/><polyline points="7.5 10.5 12 15 16.5 10.5"/><path d="M4.5 18.5h15"/>',
        'calendar'   => '<rect x="3.5" y="5" width="17" height="15.5" rx="2.5"/><path d="M3.5 10h17"/><path d="M8 2.8v3.4"/><path d="M16 2.8v3.4"/>',
        'sparkle'    => '<path d="M12 3.5 13.7 9 19 10.7 13.7 12.4 12 18 10.3 12.4 5 10.7 10.3 9Z"/><path d="M18.5 16.5l.7 2.1 2.1.7-2.1.7-.7 2.1-.7-2.1-2.1-.7 2.1-.7Z"/>',
    );

    /** Default icon per content type, used when none is specified. */
    const DEFAULTS = array(
        'metric'    => 'trend-up',
        'takeaway'  => 'lightbulb',
        'checklist' => 'check',
        'quote'     => 'quote',
        'cta'       => 'arrow-right',
    );

    /**
     * Decorative background shapes.
     *
     * These are what stop a block reading as a bootstrap panel: a soft arc
     * behind a number, a dot field behind a quote. They carry no meaning, sit
     * behind the content at low opacity, and are clipped by the block's own
     * overflow, so they can never push text around or overflow on a phone.
     *
     * Drawn on a 100x100 grid and stretched with preserveAspectRatio="none",
     * because a decorative wash should fill whatever box it is given rather
     * than keeping its proportions.
     *
     * Same rule as the icons: the AI selects one by name and can never supply
     * geometry.
     */
    const SHAPES = array(
        'arc'   => '<path d="M100 0 A100 100 0 0 0 0 100 L100 100 Z" fill="currentColor"/>',
        'blob'  => '<path d="M78 6c14 10 27 26 21 44-7 18-32 27-52 33-19 5-33 6-42-3-8-9-11-27-4-42C8 22 25 12 43 6c17-6 34-7 35 0Z" fill="currentColor"/>',
        'dots'  => '<g fill="currentColor"><circle cx="10" cy="10" r="3"/><circle cx="30" cy="10" r="3"/><circle cx="50" cy="10" r="3"/><circle cx="70" cy="10" r="3"/><circle cx="90" cy="10" r="3"/><circle cx="10" cy="30" r="3"/><circle cx="30" cy="30" r="3"/><circle cx="50" cy="30" r="3"/><circle cx="70" cy="30" r="3"/><circle cx="90" cy="30" r="3"/><circle cx="10" cy="50" r="3"/><circle cx="30" cy="50" r="3"/><circle cx="50" cy="50" r="3"/><circle cx="70" cy="50" r="3"/><circle cx="90" cy="50" r="3"/><circle cx="10" cy="70" r="3"/><circle cx="30" cy="70" r="3"/><circle cx="50" cy="70" r="3"/><circle cx="70" cy="70" r="3"/><circle cx="90" cy="70" r="3"/><circle cx="10" cy="90" r="3"/><circle cx="30" cy="90" r="3"/><circle cx="50" cy="90" r="3"/><circle cx="70" cy="90" r="3"/><circle cx="90" cy="90" r="3"/></g>',
        'rings' => '<g fill="none" stroke="currentColor" stroke-width="6"><circle cx="50" cy="50" r="46"/><circle cx="50" cy="50" r="30"/><circle cx="50" cy="50" r="14"/></g>',
        'grid'  => '<g fill="none" stroke="currentColor" stroke-width="2"><path d="M0 20h100M0 40h100M0 60h100M0 80h100M20 0v100M40 0v100M60 0v100M80 0v100"/></g>',
        'wedge' => '<path d="M100 0 L100 100 L0 100 Z" fill="currentColor"/>',
    );

    /**
     * Render a decorative shape.
     *
     * Always aria-hidden. It is wallpaper, and a screen reader announcing it
     * would be announcing nothing.
     *
     * @param string $name Shape name. Unknown names render nothing.
     * @return string
     */
    public static function shape( $name ) {
        if ( ! is_string( $name ) || ! isset( self::SHAPES[ $name ] ) ) {
            return '';
        }

        return '<svg class="aeogm-visual__shape" viewBox="0 0 100 100" preserveAspectRatio="none"'
            . ' aria-hidden="true" focusable="false" role="presentation">'
            . self::SHAPES[ $name ] . '</svg>';
    }

    /**
     * Every shape name, for the editor picker and for validating AI output.
     *
     * @return string[]
     */
    public static function shape_names() {
        return array_keys( self::SHAPES );
    }

    /**
     * Whether an icon name is one we can draw.
     *
     * @param string $name Icon name.
     * @return bool
     */
    public static function exists( $name ) {
        return is_string( $name ) && isset( self::ICONS[ $name ] );
    }

    /**
     * Every icon name, for the editor's picker and for validating AI output.
     *
     * @return string[]
     */
    public static function names() {
        return array_keys( self::ICONS );
    }

    /**
     * Resolve a requested icon to something drawable.
     *
     * @param string $name         Requested name; may be empty or unknown.
     * @param string $content_type Content type, used to pick a sensible default.
     * @return string A valid icon name, or '' when there is no sensible default.
     */
    public static function resolve( $name, $content_type = '' ) {
        if ( self::exists( $name ) ) {
            return $name;
        }

        $fallback = self::DEFAULTS[ $content_type ] ?? '';

        return self::exists( $fallback ) ? $fallback : '';
    }

    /**
     * Render an icon as inline SVG.
     *
     * Always decorative: the surrounding block carries the real text, so the
     * icon is hidden from assistive technology rather than given a label
     * screen-reader users would hear twice.
     *
     * @param string $name Icon name. Unknown names render nothing.
     * @return string SVG markup, or '' when the name is unknown.
     */
    public static function svg( $name ) {
        if ( ! self::exists( $name ) ) {
            return '';
        }

        return '<svg class="aeogm-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor"'
            . ' stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"'
            . ' aria-hidden="true" focusable="false" role="presentation">'
            . self::ICONS[ $name ]
            . '</svg>';
    }
}
