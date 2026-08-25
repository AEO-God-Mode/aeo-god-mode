<?php
/**
 * Visual template registry and renderer.
 *
 * A visual block stores structured content, never markup. This class owns the
 * only mapping from that content to HTML, which is what makes resizing,
 * restyling, switching template and rebranding free operations: nothing about
 * the final appearance was ever written into the post.
 *
 * It also makes the AI layer that lands later cheap to secure. A model supplies
 * field values and a content type. It never supplies a class name, a colour, a
 * dimension or a tag. Everything a reader's browser receives is assembled here
 * from validated parts.
 *
 * Templates are grouped by content type. Every template within a type renders
 * the same fields, so a customer can switch between them without regenerating
 * anything and without losing content.
 *
 * @package AISEOGodMode
 */

namespace AISEOGodMode;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class VisualTemplates {

    /** Bumped when a stored attribute changes meaning. */
    const SCHEMA_VERSION = 1;

    /**
     * Content types, their fields and the templates that can render them.
     *
     * `max` is a hard character budget, not a suggestion. It keeps a block
     * inside the page-weight budget, keeps it legible at 320px, and gives the
     * AI layer an unambiguous limit to validate against rather than a vibe.
     */
    const TYPES = array(
        'metric' => array(
            'label'     => 'Stat highlight',
            'templates' => array( 'metric-card', 'metric-inline', 'metric-ring', 'metric-bleed' ),
            'fields'    => array(
                'value'           => array( 'max' => 16,  'required' => true ),
                'label'           => array( 'max' => 90,  'required' => true ),
                'supporting_text' => array( 'max' => 220, 'required' => false ),
                'source'          => array( 'max' => 140, 'required' => false ),
                // 0-100, or null when the text states no proportion.
                //
                // Explicit rather than parsed out of `value`, because "3.4x",
                // "£12/mo" and "9 out of 10" are all legitimate headline
                // figures and only one of them implies a fraction. Guessing a
                // proportion from a value that has none would be inventing
                // data, which is the one thing this feature must never do.
                'proportion'      => array( 'numeric' => true, 'required' => false ),
            ),
        ),
        'takeaway' => array(
            'label'     => 'Key takeaway',
            'templates' => array( 'takeaway-card', 'takeaway-banner', 'takeaway-rule' ),
            'fields'    => array(
                'title' => array( 'max' => 90,  'required' => false ),
                'body'  => array( 'max' => 420, 'required' => true ),
            ),
        ),
        'checklist' => array(
            'label'     => 'Checklist',
            'templates' => array( 'checklist-card', 'checklist-plain', 'checklist-numbered', 'checklist-timeline' ),
            'fields'    => array(
                'title' => array( 'max' => 90, 'required' => false ),
                'items' => array( 'max' => 150, 'required' => true, 'list' => true, 'max_items' => 5 ),
            ),
        ),
        'quote' => array(
            'label'     => 'Pull quote',
            'templates' => array( 'quote-card', 'quote-rule', 'quote-mark' ),
            'fields'    => array(
                'text'        => array( 'max' => 420, 'required' => true ),
                'attribution' => array( 'max' => 90,  'required' => false ),
                'role'        => array( 'max' => 90,  'required' => false ),
            ),
        ),
    );

    /**
     * Content a template cannot render without.
     *
     * A gauge with nothing to gauge is not a design choice, it is a bug that
     * looks deliberate. Rather than special-casing the ring, every template can
     * declare what it needs, the registry filters on it, and the renderer falls
     * back rather than drawing something meaningless. Adding a template later
     * means declaring its requirements, not patching another branch.
     */
    const TEMPLATE_REQUIRES = array(
        'metric-ring' => array( 'proportion' ),
    );

    const WIDTHS      = array( 'compact', 'content', 'wide', 'full' );
    const ALIGNMENTS  = array( 'left', 'center', 'right' );
    const BACKGROUNDS = array( 'light', 'dark', 'transparent' );
    const SPACINGS    = array( 'compact', 'comfortable', 'roomy' );

    /**
     * Visual styles.
     *
     * Not decoration on top of one design: each rebuilds the block's weight,
     * fill and typography, so the same content genuinely reads differently.
     * The Brand Kit collects this once and every block inherits it, which is
     * what stops a site looking like four plugins had a go at it.
     */
    const STYLES = array( 'clean', 'bold', 'editorial', 'playful' );

    /* ─────────────────────────── Introspection ─────────────────────────── */

    /**
     * The registry, in a shape the editor can build a picker from.
     *
     * @return array
     */
    public static function registry() {
        $out = array();

        foreach ( self::TYPES as $type => $def ) {
            $requires = array();
            foreach ( $def['templates'] as $template ) {
                $requires[ $template ] = self::TEMPLATE_REQUIRES[ $template ] ?? array();
            }

            $out[] = array(
                'type'      => $type,
                'label'     => $def['label'],
                'templates' => $def['templates'],
                'requires'  => $requires,
                'fields'    => $def['fields'],
                'icons'     => VisualIcons::names(),
            );
        }

        return $out;
    }

    /**
     * Whether a template name belongs to a content type.
     *
     * @param string $type     Content type.
     * @param string $template Template name.
     * @return bool
     */
    public static function template_belongs( $type, $template ) {
        return isset( self::TYPES[ $type ] )
            && in_array( $template, self::TYPES[ $type ]['templates'], true );
    }

    /**
     * Whether this content can actually fill this template.
     *
     * @param string $template Template name.
     * @param array  $content  Sanitised content fields.
     * @return bool
     */
    public static function template_supported( $template, $content ) {
        foreach ( self::TEMPLATE_REQUIRES[ $template ] ?? array() as $needs ) {
            if ( ! isset( $content[ $needs ] ) || null === $content[ $needs ] || '' === $content[ $needs ] ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Every template of a type that this content could actually fill.
     *
     * The editor uses it to disable the ones that would not work rather than
     * letting someone pick a gauge and wonder why it is full.
     *
     * @param string $type    Content type.
     * @param array  $content Sanitised content fields.
     * @return string[]
     */
    public static function usable_templates( $type, $content ) {
        $all = self::TYPES[ $type ]['templates'] ?? array();

        return array_values( array_filter( $all, function ( $t ) use ( $content ) {
            return self::template_supported( $t, $content );
        } ) );
    }

    /* ─────────────────────────── Sanitising ─────────────────────────── */

    /**
     * Clean a full set of block attributes.
     *
     * Returns null when the block has no usable content, so callers can render
     * nothing rather than an empty shell. Over-long values are truncated rather
     * than rejected, because silently dropping a customer's paragraph is worse
     * than shortening it, and the editor shows the same limits live.
     *
     * @param array $attrs Untrusted attributes.
     * @return array|null Sanitised attributes, or null when unusable.
     */
    public static function sanitize( $attrs ) {
        $attrs = is_array( $attrs ) ? $attrs : array();

        $type = isset( $attrs['contentType'] ) ? (string) $attrs['contentType'] : '';
        if ( ! isset( self::TYPES[ $type ] ) ) {
            return null;
        }

        $def      = self::TYPES[ $type ];
        $template = isset( $attrs['template'] ) ? (string) $attrs['template'] : '';
        if ( ! self::template_belongs( $type, $template ) ) {
            $template = $def['templates'][0];
        }

        $incoming = isset( $attrs['content'] ) && is_array( $attrs['content'] ) ? $attrs['content'] : array();
        $content  = array();

        foreach ( $def['fields'] as $field => $rules ) {
            if ( ! empty( $rules['numeric'] ) ) {
                $raw = $incoming[ $field ] ?? null;
                // Absent and "not stated" are the same thing here, and both
                // must stay null so template_supported() can see the gap.
                // Always a float, never sometimes an int: min()/max() return
                // whichever operand wins, so clamping 420 handed back an int
                // while an ordinary value came back as a float. A field whose
                // type depends on its value is a trap for every later caller.
                $content[ $field ] = is_numeric( $raw )
                    ? (float) max( 0, min( 100, round( (float) $raw, 1 ) ) )
                    : null;
                continue;
            }
            if ( ! empty( $rules['list'] ) ) {
                $items = isset( $incoming[ $field ] ) && is_array( $incoming[ $field ] ) ? $incoming[ $field ] : array();
                $clean = array();
                foreach ( $items as $item ) {
                    $item = self::text( $item, $rules['max'] );
                    if ( '' !== $item ) {
                        $clean[] = $item;
                    }
                    if ( count( $clean ) >= $rules['max_items'] ) {
                        break;
                    }
                }
                $content[ $field ] = $clean;
                continue;
            }

            $content[ $field ] = self::text( $incoming[ $field ] ?? '', $rules['max'] );
        }

        // A block missing a required field has nothing worth rendering.
        foreach ( $def['fields'] as $field => $rules ) {
            if ( empty( $rules['required'] ) ) {
                continue;
            }
            if ( empty( $content[ $field ] ) ) {
                return null;
            }
        }

        $layout = isset( $attrs['layout'] ) && is_array( $attrs['layout'] ) ? $attrs['layout'] : array();

        // Degrade rather than render something meaningless. A ring with no
        // proportion falls back to the type's default card, keeping every
        // field the customer or the model supplied.
        if ( ! self::template_supported( $template, $content ) ) {
            $template = $def['templates'][0];
        }

        $brand = BrandKit::resolve();

        // Style defaults to the Brand Kit rather than to a constant, so a site
        // that chose "bold" once gets bold blocks without setting it per block.
        // An explicit per-block value still wins.
        $style = self::pick( $attrs['style'] ?? '', self::STYLES, $brand['visual_style'] );

        return array(
            'schemaVersion'   => self::SCHEMA_VERSION,
            'contentType'     => $type,
            'template'        => $template,
            'templateVersion' => max( 1, (int) ( $attrs['templateVersion'] ?? 1 ) ),
            'content'         => $content,
            'icon'            => VisualIcons::resolve( (string) ( $attrs['icon'] ?? '' ), $type ),
            'caption'         => self::text( $attrs['caption'] ?? '', 220 ),
            'style'           => $style,
            'shape'           => in_array( (string) ( $attrs['shape'] ?? '' ), VisualIcons::shape_names(), true )
                ? (string) $attrs['shape']
                : self::default_shape( $style, $type ),
            'layout'          => array(
                'width'      => self::pick( $layout['width'] ?? '', self::WIDTHS, 'content' ),
                'align'      => self::pick( $layout['align'] ?? '', self::ALIGNMENTS, 'left' ),
                'background' => self::pick( $layout['background'] ?? '', self::BACKGROUNDS, 'light' ),
                'spacing'    => self::pick( $layout['spacing'] ?? '', self::SPACINGS, 'comfortable' ),
            ),
        );
    }

    /**
     * The decorative shape a style reaches for by default.
     *
     * Clean stays bare on purpose. Anything else and "clean" would just be
     * "playful with fewer dots", and a customer who picked restraint would
     * not get it.
     *
     * @param string $style Visual style.
     * @param string $type  Content type.
     * @return string Shape name, or '' for none.
     */
    private static function default_shape( $style, $type ) {
        if ( 'clean' === $style ) {
            return '';
        }

        $by_type = array(
            'metric'    => array( 'bold' => 'arc',   'editorial' => 'grid',  'playful' => 'blob' ),
            'takeaway'  => array( 'bold' => 'wedge', 'editorial' => 'grid',  'playful' => 'blob' ),
            'checklist' => array( 'bold' => 'arc',   'editorial' => 'dots',  'playful' => 'dots' ),
            'quote'     => array( 'bold' => 'rings', 'editorial' => 'dots',  'playful' => 'blob' ),
        );

        return $by_type[ $type ][ $style ] ?? '';
    }

    /**
     * Strip a value to safe plain text within a length budget.
     *
     * Blocks carry no inline markup by design: a stat card is a stat, not a
     * rich-text field. Tags are removed rather than escaped so that pasted
     * markup collapses to its text instead of showing as literal angle
     * brackets to the reader.
     *
     * @param mixed $value Raw input.
     * @param int   $max   Character budget.
     * @return string
     */
    private static function text( $value, $max ) {
        if ( ! is_scalar( $value ) ) {
            return '';
        }

        $value = wp_strip_all_tags( (string) $value, true );
        $value = preg_replace( '/\s+/u', ' ', $value );
        $value = trim( (string) $value );

        if ( '' === $value ) {
            return '';
        }

        if ( function_exists( 'mb_substr' ) && mb_strlen( $value ) > $max ) {
            $value = rtrim( mb_substr( $value, 0, $max ) );
        } elseif ( strlen( $value ) > $max ) {
            $value = rtrim( substr( $value, 0, $max ) );
        }

        return $value;
    }

    /**
     * Constrain a value to an allowlist.
     *
     * @param mixed  $value    Raw input.
     * @param array  $allowed  Permitted values.
     * @param string $default  Value to use when input is not permitted.
     * @return string
     */
    private static function pick( $value, $allowed, $default ) {
        $value = is_string( $value ) ? strtolower( trim( $value ) ) : '';

        return in_array( $value, $allowed, true ) ? $value : $default;
    }

    /* ──────────────────────────── Rendering ──────────────────────────── */

    /**
     * Render a visual block.
     *
     * @param array $attrs Attributes; sanitised here, so callers may pass raw.
     * @return string HTML, or '' when there is nothing renderable.
     */
    public static function render( $attrs ) {
        $a = self::sanitize( $attrs );
        if ( null === $a ) {
            return '';
        }

        $method = 'render_' . str_replace( '-', '_', $a['template'] );
        if ( ! method_exists( __CLASS__, $method ) ) {
            return '';
        }

        $layout  = $a['layout'];
        $classes = array(
            'aeogm-visual',
            'aeogm-visual--' . $a['contentType'],
            'aeogm-visual--' . $a['template'],
            'aeogm-visual--w-' . $layout['width'],
            'aeogm-visual--a-' . $layout['align'],
            'aeogm-visual--bg-' . $layout['background'],
            'aeogm-visual--s-' . $layout['spacing'],
            'aeogm-visual--style-' . $a['style'],
        );
        if ( '' !== $a['shape'] ) {
            $classes[] = 'aeogm-visual--has-shape';
        }

        $inner = VisualIcons::shape( $a['shape'] ) . self::$method( $a );
        if ( '' === trim( $inner ) ) {
            return '';
        }

        if ( '' !== $a['caption'] ) {
            $inner .= '<figcaption class="aeogm-visual__caption">' . esc_html( $a['caption'] ) . '</figcaption>';
        }

        return '<figure class="' . esc_attr( implode( ' ', $classes ) ) . '"'
            . ' style="' . esc_attr( BrandKit::css_vars() ) . '">'
            . $inner
            . '</figure>';
    }

    /**
     * The plain-HTML version saved into post content.
     *
     * This is what a reader sees if the plugin is ever deactivated, so it must
     * carry the whole point of the block in ordinary tags. A dynamic block that
     * saves nothing leaves a hole in the article the day the plugin is switched
     * off, which is indefensible for something that writes into posts.
     *
     * @param array $attrs Attributes.
     * @return string HTML, or '' when there is nothing renderable.
     */
    public static function fallback( $attrs ) {
        $a = self::sanitize( $attrs );
        if ( null === $a ) {
            return '';
        }

        $c    = $a['content'];
        $body = '';

        switch ( $a['contentType'] ) {
            case 'metric':
                $body = '<p><strong>' . esc_html( $c['value'] ) . '</strong> ' . esc_html( $c['label'] ) . '</p>';
                if ( '' !== $c['supporting_text'] ) {
                    $body .= '<p>' . esc_html( $c['supporting_text'] ) . '</p>';
                }
                if ( '' !== $c['source'] ) {
                    $body .= '<p><small>' . esc_html( $c['source'] ) . '</small></p>';
                }
                break;

            case 'takeaway':
                if ( '' !== $c['title'] ) {
                    $body = '<p><strong>' . esc_html( $c['title'] ) . '</strong></p>';
                }
                $body .= '<p>' . esc_html( $c['body'] ) . '</p>';
                break;

            case 'checklist':
                if ( '' !== $c['title'] ) {
                    $body = '<p><strong>' . esc_html( $c['title'] ) . '</strong></p>';
                }
                $body .= '<ul>';
                foreach ( $c['items'] as $item ) {
                    $body .= '<li>' . esc_html( $item ) . '</li>';
                }
                $body .= '</ul>';
                break;

            case 'quote':
                $body = '<blockquote><p>' . esc_html( $c['text'] ) . '</p>';
                if ( '' !== $c['attribution'] ) {
                    $cite  = $c['attribution'] . ( '' !== $c['role'] ? ', ' . $c['role'] : '' );
                    $body .= '<cite>' . esc_html( $cite ) . '</cite>';
                }
                $body .= '</blockquote>';
                break;
        }

        if ( '' === $body ) {
            return '';
        }

        if ( '' !== $a['caption'] ) {
            $body .= '<figcaption>' . esc_html( $a['caption'] ) . '</figcaption>';
        }

        return '<figure class="aeogm-visual-fallback">' . $body . '</figure>';
    }

    /* ─────────────────────────── Templates ─────────────────────────── */

    private static function icon_chip( $a ) {
        $svg = VisualIcons::svg( $a['icon'] );

        return '' === $svg ? '' : '<div class="aeogm-visual__icon">' . $svg . '</div>';
    }

    private static function render_metric_card( $a ) {
        $c   = $a['content'];
        $out = self::icon_chip( $a ) . '<div class="aeogm-visual__body">'
            . '<p class="aeogm-visual__value">' . esc_html( $c['value'] ) . '</p>'
            . '<p class="aeogm-visual__label">' . esc_html( $c['label'] ) . '</p>';

        if ( '' !== $c['supporting_text'] ) {
            $out .= '<p class="aeogm-visual__support">' . esc_html( $c['supporting_text'] ) . '</p>';
        }
        if ( '' !== $c['source'] ) {
            $out .= '<p class="aeogm-visual__source">' . esc_html( $c['source'] ) . '</p>';
        }

        return $out . '</div>';
    }

    private static function render_metric_inline( $a ) {
        $c   = $a['content'];
        $out = '<div class="aeogm-visual__body">'
            . '<p class="aeogm-visual__line">'
            . '<span class="aeogm-visual__value">' . esc_html( $c['value'] ) . '</span>'
            . '<span class="aeogm-visual__label">' . esc_html( $c['label'] ) . '</span>'
            . '</p>';

        if ( '' !== $c['supporting_text'] ) {
            $out .= '<p class="aeogm-visual__support">' . esc_html( $c['supporting_text'] ) . '</p>';
        }
        if ( '' !== $c['source'] ) {
            $out .= '<p class="aeogm-visual__source">' . esc_html( $c['source'] ) . '</p>';
        }

        return $out . '</div>';
    }

    private static function render_takeaway_card( $a ) {
        $c   = $a['content'];
        $out = self::icon_chip( $a ) . '<div class="aeogm-visual__body">';

        if ( '' !== $c['title'] ) {
            $out .= '<p class="aeogm-visual__eyebrow">' . esc_html( $c['title'] ) . '</p>';
        }

        return $out . '<p class="aeogm-visual__lead">' . esc_html( $c['body'] ) . '</p></div>';
    }

    private static function render_takeaway_banner( $a ) {
        return self::render_takeaway_card( $a );
    }

    private static function render_checklist_card( $a ) {
        $c   = $a['content'];
        $out = self::icon_chip( $a ) . '<div class="aeogm-visual__body">';

        if ( '' !== $c['title'] ) {
            $out .= '<p class="aeogm-visual__eyebrow">' . esc_html( $c['title'] ) . '</p>';
        }

        $out .= '<ul class="aeogm-visual__list">';
        foreach ( $c['items'] as $item ) {
            $out .= '<li>' . VisualIcons::svg( 'check' ) . '<span>' . esc_html( $item ) . '</span></li>';
        }

        return $out . '</ul></div>';
    }

    private static function render_checklist_plain( $a ) {
        return self::render_checklist_card( $a );
    }

    private static function render_quote_card( $a ) {
        $c   = $a['content'];
        $out = self::icon_chip( $a ) . '<div class="aeogm-visual__body">'
            . '<blockquote class="aeogm-visual__quote"><p>' . esc_html( $c['text'] ) . '</p></blockquote>';

        if ( '' !== $c['attribution'] ) {
            $out .= '<p class="aeogm-visual__cite"><cite>' . esc_html( $c['attribution'] ) . '</cite>';
            if ( '' !== $c['role'] ) {
                $out .= '<span class="aeogm-visual__role">' . esc_html( $c['role'] ) . '</span>';
            }
            $out .= '</p>';
        }

        return $out . '</div>';
    }

    private static function render_quote_rule( $a ) {
        return self::render_quote_card( $a );
    }

    /**
     * A metric wrapped in an SVG progress ring.
     *
     * This is where SVG genuinely earns its place rather than being used for
     * its own sake: a ring is geometry, not text, and drawing it in CSS would
     * mean conic-gradient hacks that behave differently across browsers. The
     * ring is decorative and the number is real text beside it, so nothing is
     * lost to anything that cannot read the graphic.
     */
    private static function render_metric_ring( $a ) {
        $c = $a['content'];

        // The proportion is a field, not something inferred from the value
        // string. sanitize() guarantees a number here, because a ring with no
        // proportion degrades to metric-card before it reaches this method.
        $pct  = (float) $c['proportion'];
        $r    = 52;
        $circ = 2 * M_PI * $r;
        $dash = $circ * ( max( 0, min( 100, $pct ) ) / 100 );

        $ring = '<svg class="aeogm-visual__ring" viewBox="0 0 120 120" aria-hidden="true" focusable="false" role="presentation">'
            . '<circle class="aeogm-visual__ring-track" cx="60" cy="60" r="' . $r . '"/>'
            . '<circle class="aeogm-visual__ring-value" cx="60" cy="60" r="' . $r . '"'
            . ' stroke-dasharray="' . round( $dash, 2 ) . ' ' . round( $circ, 2 ) . '"/>'
            . '</svg>';

        // "68%" and "9 out of 10" are both legitimate headline figures, and one
        // of them does not fit a circle at the same size. Size by length rather
        // than assuming every value is short, which is what pushed "9 out of
        // 10" straight through the stroke.
        $len = function_exists( 'mb_strlen' ) ? mb_strlen( $c['value'] ) : strlen( $c['value'] );
        $fit = $len <= 4 ? 'short' : ( $len <= 8 ? 'medium' : 'long' );

        $out = '<div class="aeogm-visual__ring-wrap aeogm-visual__ring-wrap--' . $fit . '">' . $ring
            . '<span class="aeogm-visual__ring-label">' . esc_html( $c['value'] ) . '</span></div>'
            . '<div class="aeogm-visual__body">'
            . '<p class="aeogm-visual__label">' . esc_html( $c['label'] ) . '</p>';

        if ( '' !== $c['supporting_text'] ) {
            $out .= '<p class="aeogm-visual__support">' . esc_html( $c['supporting_text'] ) . '</p>';
        }
        if ( '' !== $c['source'] ) {
            $out .= '<p class="aeogm-visual__source">' . esc_html( $c['source'] ) . '</p>';
        }

        return $out . '</div>';
    }

    /** An oversized numeral running to the edge, with the label beneath it. */
    private static function render_metric_bleed( $a ) {
        $c   = $a['content'];
        $out = '<div class="aeogm-visual__body">'
            . '<p class="aeogm-visual__value aeogm-visual__value--bleed">' . esc_html( $c['value'] ) . '</p>'
            . '<p class="aeogm-visual__label">' . esc_html( $c['label'] ) . '</p>';

        if ( '' !== $c['supporting_text'] ) {
            $out .= '<p class="aeogm-visual__support">' . esc_html( $c['supporting_text'] ) . '</p>';
        }
        if ( '' !== $c['source'] ) {
            $out .= '<p class="aeogm-visual__source">' . esc_html( $c['source'] ) . '</p>';
        }

        return $out . '</div>';
    }

    /** A takeaway carried by a heavy accent rule instead of a panel. */
    private static function render_takeaway_rule( $a ) {
        return self::render_takeaway_card( $a );
    }

    /** Numbered steps. Reads as a sequence, where ticks read as a set. */
    private static function render_checklist_numbered( $a ) {
        $c   = $a['content'];
        $out = self::icon_chip( $a ) . '<div class="aeogm-visual__body">';

        if ( '' !== $c['title'] ) {
            $out .= '<p class="aeogm-visual__eyebrow">' . esc_html( $c['title'] ) . '</p>';
        }

        $out .= '<ol class="aeogm-visual__list aeogm-visual__list--numbered">';
        foreach ( $c['items'] as $i => $item ) {
            $out .= '<li><span class="aeogm-visual__num">' . ( $i + 1 ) . '</span>'
                . '<span>' . esc_html( $item ) . '</span></li>';
        }

        return $out . '</ol></div>';
    }

    /** The same steps, joined by a connecting line. */
    private static function render_checklist_timeline( $a ) {
        return self::render_checklist_numbered( $a );
    }

    /** A quote against an oversized quotation mark watermark. */
    private static function render_quote_mark( $a ) {
        return self::render_quote_card( $a );
    }

    /* ────────────────────────────── Styles ────────────────────────────── */

    /**
     * The block stylesheet, as raw CSS.
     *
     * Deliberately not a <style> element, and deliberately not self-limiting to
     * one call. An earlier version printed the tag inline behind a static
     * "already done" flag, which broke the moment anything rendered content
     * twice in one request: WordPress builds an excerpt by running
     * the_content(), so the excerpt pass consumed the stylesheet and the real
     * render emitted none. VisualBlocks puts this through the normal style
     * queue instead, which is what that queue exists for.
     *
     * Every colour reads a brand custom property set on the wrapper, which is
     * what makes rebranding every published post a settings change rather than
     * a migration.
     *
     * @return string CSS.
     */
    public static function css() {
        $css =
            // ── Skeleton ──
              '.aeogm-visual{position:relative;overflow:hidden;display:flex;gap:1.15em;'
            . 'margin:1.8em 0;padding:var(--aeogm-vp,1.35em);box-sizing:border-box;max-width:100%;'
            . 'border-radius:var(--aeogm-brand-radius-box,10px)}'
            . '.aeogm-visual *{box-sizing:border-box;min-width:0}'
            . '.aeogm-visual__body{flex:1;min-width:0;position:relative;z-index:1}'
            . '.aeogm-visual__body>:first-child{margin-top:0}'
            . '.aeogm-visual__body>:last-child{margin-bottom:0}'
            . '.aeogm-visual__icon{position:relative;z-index:1;flex:0 0 auto;width:2.6em;height:2.6em;'
            . 'display:grid;place-items:center;border-radius:var(--aeogm-brand-radius,10px);'
            . 'color:var(--aeogm-brand-accent)}'
            . '.aeogm-icon{width:1.35em;height:1.35em;display:block}'

            // ── Decorative layer ──
            // Wallpaper. Behind everything, clipped by the block, and never in
            // the layout, so it cannot push text around or overflow a phone.
            . '.aeogm-visual__shape{position:absolute;top:-15%;right:-12%;width:52%;height:130%;'
            . 'z-index:0;pointer-events:none;color:var(--aeogm-brand-accent);opacity:.09}'
            . '.aeogm-visual--style-editorial .aeogm-visual__shape{opacity:.07}'
            . '.aeogm-visual--style-playful .aeogm-visual__shape{opacity:.14;top:-25%;right:-18%;width:62%;height:150%}'
            . '.aeogm-visual--bg-dark .aeogm-visual__shape{color:var(--aeogm-brand-on-primary);opacity:.13}'

            // ── Type ──
            . '.aeogm-visual__value{font-size:clamp(2rem,1.2rem + 2.6vw,3.1rem);line-height:1;'
            . 'font-weight:800;letter-spacing:-.03em;margin:0;color:var(--aeogm-brand-accent)}'
            . '.aeogm-visual__value--bleed{font-size:clamp(3rem,1.6rem + 6vw,5.5rem);letter-spacing:-.045em;'
            . 'margin-bottom:.06em}'
            . '.aeogm-visual__label{font-size:1.05em;font-weight:650;margin:.3em 0 0;line-height:1.3}'
            . '.aeogm-visual__eyebrow{font-size:.74em;font-weight:800;letter-spacing:.1em;'
            . 'text-transform:uppercase;margin:0 0 .5em;color:var(--aeogm-brand-accent)}'
            . '.aeogm-visual__lead{font-size:1.08em;line-height:1.55;margin:0}'
            . '.aeogm-visual__support{font-size:.95em;opacity:.85;margin:.55em 0 0;line-height:1.5}'
            . '.aeogm-visual__source{font-size:.79em;opacity:.65;margin:.65em 0 0}'
            . '.aeogm-visual__caption{position:relative;z-index:1;font-size:.82em;opacity:.7;'
            . 'margin:.8em 0 0;flex-basis:100%}'

            // ── Inline metric ──
            . '.aeogm-visual__line{display:flex;align-items:baseline;flex-wrap:wrap;gap:.5em;margin:0}'
            . '.aeogm-visual--metric-inline .aeogm-visual__value{font-size:clamp(1.6rem,1.1rem + 1.6vw,2.2rem)}'
            . '.aeogm-visual--metric-inline .aeogm-visual__label{margin:0}'

            // ── Ring metric ──
            . '.aeogm-visual__ring-wrap{position:relative;z-index:1;flex:0 0 auto;width:7.2em;height:7.2em;'
            . 'display:grid;place-items:center}'
            . '.aeogm-visual__ring{position:absolute;inset:0;width:100%;height:100%;transform:rotate(-90deg)}'
            . '.aeogm-visual__ring circle{fill:none;stroke-width:9;stroke-linecap:round}'
            . '.aeogm-visual__ring-track{stroke:color-mix(in srgb,var(--aeogm-brand-accent) 18%,transparent)}'
            . '.aeogm-visual__ring-value{stroke:var(--aeogm-brand-accent)}'
            . '.aeogm-visual__ring-label{position:relative;font-weight:800;letter-spacing:-.02em;'
            . 'color:var(--aeogm-brand-accent);text-align:center;line-height:1.05;'
            . 'max-width:74%;overflow-wrap:anywhere;hyphens:auto}'
            . '.aeogm-visual__ring-wrap--short .aeogm-visual__ring-label{font-size:1.5em}'
            . '.aeogm-visual__ring-wrap--medium .aeogm-visual__ring-label{font-size:1.05em}'
            . '.aeogm-visual__ring-wrap--long .aeogm-visual__ring-label{font-size:.8em;letter-spacing:0}'
            . '.aeogm-visual--bg-dark .aeogm-visual__ring-value,'
            . '.aeogm-visual--bg-dark .aeogm-visual__ring-label{stroke:var(--aeogm-brand-on-primary);'
            . 'color:var(--aeogm-brand-on-primary)}'
            . '.aeogm-visual--bg-dark .aeogm-visual__ring-track{stroke:color-mix(in srgb,var(--aeogm-brand-on-primary) 25%,transparent)}'

            // ── Lists ──
            . '.aeogm-visual__list{list-style:none;margin:0;padding:0}'
            . '.aeogm-visual__list li{display:flex;gap:.7em;align-items:flex-start;margin:.5em 0;line-height:1.45}'
            . '.aeogm-visual__list li .aeogm-icon{flex:0 0 auto;width:1.15em;height:1.15em;margin-top:.18em;'
            . 'color:var(--aeogm-brand-accent);stroke-width:2.8}'
            . '.aeogm-visual__num{flex:0 0 auto;width:1.6em;height:1.6em;border-radius:999px;'
            . 'display:grid;place-items:center;font-size:.82em;font-weight:800;line-height:1;'
            . 'background:var(--aeogm-brand-accent);color:var(--aeogm-brand-on-accent)}'
            . '.aeogm-visual--bg-dark .aeogm-visual__num{background:var(--aeogm-brand-on-primary);'
            . 'color:var(--aeogm-brand-primary)}'
            . '.aeogm-visual--bg-dark .aeogm-visual__list li .aeogm-icon{color:var(--aeogm-brand-on-primary)}'
            // Timeline: the connector is a pseudo-element on each row rather
            // than a full-height rule, so it stops cleanly at the last item.
            . '.aeogm-visual--checklist-timeline .aeogm-visual__list li{position:relative;padding-bottom:.75em;margin:0}'
            . '.aeogm-visual--checklist-timeline .aeogm-visual__list li:not(:last-child):before{content:"";'
            . 'position:absolute;left:.8em;top:1.9em;bottom:0;width:2px;'
            . 'background:color-mix(in srgb,var(--aeogm-brand-accent) 30%,transparent)}'
            . '.aeogm-visual--checklist-timeline .aeogm-visual__list li:last-child{padding-bottom:0}'

            // ── Quote ──
            . '.aeogm-visual__quote{margin:0;padding:0;border:0;font-size:1.18em;line-height:1.5;font-style:normal}'
            . '.aeogm-visual__quote p{margin:0}'
            . '.aeogm-visual__cite{display:flex;flex-wrap:wrap;gap:.15em .5em;margin:.8em 0 0;font-size:.9em}'
            . '.aeogm-visual__cite cite{font-style:normal;font-weight:700}'
            . '.aeogm-visual__role{opacity:.7}'
            // Watermark quote mark. A pseudo-element, so it costs no markup and
            // is invisible to a screen reader by construction.
            . '.aeogm-visual--quote-mark{padding-top:2.4em}'
            // \201C is the CSS escape for a left double quotation mark. Written
            // as a PHP unicode escape it would need double quotes; in a single
            // quoted string \u{201C} is literal text, which is what shipped.
            // The block clips its own overflow, so a glyph hung off the top
            // edge loses everything above the baseline and reads as a smudge.
            // Georgia puts most of a quotation mark in the upper third of the
            // em box, so it needs to sit fully inside, not straddle the edge.
            . '.aeogm-visual--quote-mark:before{content:"\201C";position:absolute;top:.06em;left:.1em;'
            . 'font-size:5.6em;line-height:.8;font-family:Georgia,"Times New Roman",serif;'
            . 'color:var(--aeogm-brand-accent);opacity:.16;z-index:0;pointer-events:none}'
            . '.aeogm-visual--quote-mark .aeogm-visual__icon{display:none}'
            . '.aeogm-visual--bg-dark.aeogm-visual--quote-mark:before{color:var(--aeogm-brand-on-primary);opacity:.2}'

            // ── Template variants that share a renderer ──
            // These call the same PHP method on purpose (same fields, same
            // markup) and earn their place as separate templates here, in CSS.
            // Shipping them as visual duplicates was the bug.
            //
            // takeaway-banner: a full-width band, icon and text centred.
            . '.aeogm-visual--takeaway-banner{flex-direction:column;align-items:center;text-align:center;gap:.75em}'
            . '.aeogm-visual--takeaway-banner .aeogm-visual__lead{font-size:1.25em;line-height:1.45;max-width:44rem}'
            . '.aeogm-visual--takeaway-banner .aeogm-visual__icon{width:3em;height:3em;border-radius:999px}'
            // takeaway-rule: no panel at all, a heavy accent bar and big type.
            . '.aeogm-visual--takeaway-rule{background:none;border:0;border-left:5px solid var(--aeogm-brand-accent);'
            . 'border-radius:0;padding:.3em 0 .3em 1.3em}'
            . '.aeogm-visual--takeaway-rule .aeogm-visual__icon{display:none}'
            . '.aeogm-visual--takeaway-rule .aeogm-visual__lead{font-size:1.28em;line-height:1.45;font-weight:500}'
            // checklist-plain: stripped back, no panel, no chip, tighter rows.
            . '.aeogm-visual--checklist-plain{background:none;border:0;padding:.2em 0}'
            . '.aeogm-visual--checklist-plain .aeogm-visual__icon{display:none}'
            . '.aeogm-visual--checklist-plain .aeogm-visual__list li{margin:.3em 0}'
            . '.aeogm-visual--checklist-plain .aeogm-visual__eyebrow{margin-bottom:.35em}'
            // checklist-timeline: numbers become hollow markers on the line.
            . '.aeogm-visual--checklist-timeline .aeogm-visual__num{background:transparent;'
            . 'color:var(--aeogm-brand-accent);border:2px solid var(--aeogm-brand-accent);width:1.75em;height:1.75em}'
            . '.aeogm-visual--bg-dark.aeogm-visual--checklist-timeline .aeogm-visual__num{'
            . 'background:transparent;color:var(--aeogm-brand-on-primary);border-color:var(--aeogm-brand-on-primary)}'
            // quote-rule: editorial pull quote, no panel, rule on the left.
            . '.aeogm-visual--quote-rule{background:none;border:0;border-left:4px solid var(--aeogm-brand-accent);'
            . 'border-radius:0;padding:.2em 0 .2em 1.3em}'
            . '.aeogm-visual--quote-rule .aeogm-visual__icon{display:none}'
            . '.aeogm-visual--quote-rule .aeogm-visual__quote{font-size:1.32em;line-height:1.45}'

            // ── Backgrounds ──
            . '.aeogm-visual--bg-light{background:color-mix(in srgb,var(--aeogm-brand-accent) 7%,transparent);'
            . 'border:1px solid color-mix(in srgb,var(--aeogm-brand-accent) 18%,transparent)}'
            . '.aeogm-visual--bg-light .aeogm-visual__icon{background:color-mix(in srgb,var(--aeogm-brand-accent) 13%,transparent)}'
            . '.aeogm-visual--bg-dark{background:var(--aeogm-brand-primary);color:var(--aeogm-brand-on-primary);border:0}'
            . '.aeogm-visual--bg-dark .aeogm-visual__value,'
            . '.aeogm-visual--bg-dark .aeogm-visual__eyebrow,'
            . '.aeogm-visual--bg-dark .aeogm-visual__icon{color:var(--aeogm-brand-on-primary)}'
            . '.aeogm-visual--bg-dark .aeogm-visual__icon{background:color-mix(in srgb,var(--aeogm-brand-on-primary) 16%,transparent)}'
            . '.aeogm-visual--bg-transparent{background:none;border:0;padding-left:0;padding-right:0}'

            // ── Styles ──
            // Each rebuilds weight, fill and type rather than tinting one look.
            // Bold: solid accent edge, heavier type, tighter tracking.
            . '.aeogm-visual--style-bold{border-width:0;border-left:5px solid var(--aeogm-brand-accent);'
            . 'border-top-left-radius:0;border-bottom-left-radius:0}'
            . '.aeogm-visual--style-bold .aeogm-visual__value{font-weight:900;letter-spacing:-.04em}'
            . '.aeogm-visual--style-bold .aeogm-visual__label{font-weight:750}'
            . '.aeogm-visual--style-bold.aeogm-visual--bg-light{background:color-mix(in srgb,var(--aeogm-brand-accent) 11%,transparent)}'
            // Editorial: no fill, hairline rules top and bottom, serif numerals.
            . '.aeogm-visual--style-editorial{background:none;border:0;border-top:1px solid color-mix(in srgb,currentColor 22%,transparent);'
            . 'border-bottom:1px solid color-mix(in srgb,currentColor 22%,transparent);border-radius:0;'
            . 'padding-left:0;padding-right:0}'
            . '.aeogm-visual--style-editorial .aeogm-visual__value{font-family:Georgia,"Times New Roman",serif;'
            . 'font-weight:600;letter-spacing:-.01em}'
            . '.aeogm-visual--style-editorial .aeogm-visual__eyebrow{color:inherit;opacity:.6}'
            . '.aeogm-visual--style-editorial .aeogm-visual__icon{background:none;border:1px solid color-mix(in srgb,var(--aeogm-brand-accent) 40%,transparent)}'
            . '.aeogm-visual--style-editorial.aeogm-visual--bg-dark{background:var(--aeogm-brand-primary);'
            . 'padding-left:var(--aeogm-vp,1.35em);padding-right:var(--aeogm-vp,1.35em);border:0;'
            . 'border-radius:var(--aeogm-brand-radius-box,10px)}'
            // Playful: rounder, softer, a gradient wash and a lifted icon chip.
            . '.aeogm-visual--style-playful{border-radius:calc(var(--aeogm-brand-radius-box,10px) + 10px);'
            . 'border:0}'
            . '.aeogm-visual--style-playful.aeogm-visual--bg-light{background:linear-gradient(135deg,'
            . 'color-mix(in srgb,var(--aeogm-brand-accent) 16%,transparent),'
            . 'color-mix(in srgb,var(--aeogm-brand-secondary) 9%,transparent))}'
            . '.aeogm-visual--style-playful .aeogm-visual__icon{border-radius:999px;'
            . 'background:var(--aeogm-brand-accent);color:var(--aeogm-brand-on-accent)}'
            . '.aeogm-visual--style-playful .aeogm-visual__value{letter-spacing:-.02em}'

            // ── Widths, alignment, padding ──
            . '.aeogm-visual--w-compact{max-width:24rem}'
            . '.aeogm-visual--w-content{max-width:100%}'
            . '.aeogm-visual--w-wide{max-width:min(100%,52rem)}'
            . '.aeogm-visual--w-full{max-width:100%}'
            . '.aeogm-visual--a-center{margin-left:auto;margin-right:auto;flex-direction:column;align-items:center}'
            . '.aeogm-visual--a-center .aeogm-visual__body{text-align:center}'
            . '.aeogm-visual--a-right{margin-left:auto}'
            . '.aeogm-visual--s-compact{--aeogm-vp:.9em;margin:1.2em 0;gap:.85em}'
            . '.aeogm-visual--s-comfortable{--aeogm-vp:1.35em}'
            . '.aeogm-visual--s-roomy{--aeogm-vp:2.1em;gap:1.5em;margin:2.2em 0}'
            // A large corner radius eats its own corners, so padding has to
            // grow with it or the first word sits inside the curve.
            . '.aeogm-visual{padding-left:max(var(--aeogm-vp,1.35em),calc(var(--aeogm-brand-radius-box,10px) * .85));'
            . 'padding-right:max(var(--aeogm-vp,1.35em),calc(var(--aeogm-brand-radius-box,10px) * .85))}'
            . '.aeogm-visual--bg-transparent,.aeogm-visual--style-editorial:not(.aeogm-visual--bg-dark){padding-left:0;padding-right:0}'

            // ── Deactivated-plugin fallback ──
            . '.aeogm-visual-fallback{margin:1.6em 0;padding:0 0 0 1.1em;border-left:3px solid currentColor;opacity:.95}'
            . '.aeogm-visual-fallback>:first-child{margin-top:0}'
            . '.aeogm-visual-fallback>:last-child{margin-bottom:0}'

            // ── Small screens ──
            . '@media(max-width:480px){.aeogm-visual{flex-direction:column;gap:.8em}'
            . '.aeogm-visual__icon{width:2.2em;height:2.2em}'
            . '.aeogm-visual__ring-wrap{width:5.6em;height:5.6em}'
            . '.aeogm-visual__ring-label{font-size:1.15em}'
            . '.aeogm-visual--quote-mark:before{font-size:5em}}';

        return $css;
    }
}
