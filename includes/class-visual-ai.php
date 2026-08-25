<?php
/**
 * AI generation for visual blocks and CTAs.
 *
 * The model never produces markup. It returns named fields, this class checks
 * them against the same registry the renderer uses, and VisualTemplates builds
 * the HTML from vetted templates. So the worst a bad response can do is give
 * the customer a poor sentence to edit, never a broken page and never an
 * injection.
 *
 * Two rules shape the rest of it. A visual is a reformat of text the customer
 * already wrote, so "there is nothing here worth pulling out" is a correct
 * answer that must cost nothing. And one CTA call returns three finished
 * variants, because charging per regeneration for something this small is how
 * a feature earns a reputation for eating credits.
 *
 * @package AISEOGodMode
 */

namespace AISEOGodMode;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class VisualAI {

    /** Shared AI proxy endpoint. Same one the metadata generator uses. */
    const API_URL = 'https://aeogodmode.io/wp-json/asgm/v1/ai-assist';

    /**
     * Boot the module.
     */
    public function __construct() {
        add_action( 'rest_api_init', array( $this, 'register_routes' ) );
    }

    /**
     * Register generation routes.
     */
    public function register_routes() {
        $can_edit = function () {
            return current_user_can( 'edit_posts' );
        };

        register_rest_route( API::NAMESPACE, '/visuals/generate', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'generate_visual' ),
            'permission_callback' => $can_edit,
        ) );

        register_rest_route( API::NAMESPACE, '/visuals/cta-copy', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'generate_cta_copy' ),
            'permission_callback' => $can_edit,
        ) );
    }

    /* ─────────────────────────── Endpoints ─────────────────────────── */

    /**
     * Turn a passage of the customer's own text into one structured visual.
     *
     * @param \WP_REST_Request $request Request.
     * @return \WP_REST_Response
     */
    public function generate_visual( $request ) {
        $body = $request->get_json_params();
        $text = trim( wp_strip_all_tags( (string) ( $body['text'] ?? '' ) ) );

        // Refuse before spending anything. A handful of words cannot support a
        // visual, and generating one from them would mean inventing the content.
        if ( str_word_count( $text ) < 12 ) {
            return new \WP_REST_Response( array(
                'success' => false,
                'error'   => 'Select at least a sentence or two. There is not enough here to pull a visual out of without inventing something, so nothing was charged.',
            ), 200 );
        }

        $type = (string) ( $body['content_type'] ?? 'auto' );
        if ( 'auto' !== $type && ! isset( VisualTemplates::TYPES[ $type ] ) ) {
            $type = 'auto';
        }

        // Send the template registry so the model recommends from what exists.
        // Choosing between valid designs is the one place its judgement adds
        // something a lookup cannot: a four-item feature list reads better
        // numbered than ticked, and only something that has read the passage
        // knows which it is.
        $templates = array();
        foreach ( VisualTemplates::TYPES as $type_key => $def ) {
            $templates[ $type_key ] = $def['templates'];
        }

        $result = $this->call_proxy( 'visual_content', array(
            'text'          => mb_substr( $text, 0, 4000 ),
            'content_type'  => $type,
            'page_title'    => sanitize_text_field( (string) ( $body['page_title'] ?? '' ) ),
            'templates'     => $templates,
            'tone'          => BrandKit::resolve()['tone'],
            'business_name' => $this->business_name(),
        ) );

        if ( ! $result['success'] ) {
            return new \WP_REST_Response( $result, 200 );
        }

        $data = $result['data'];

        // The model declining is a real answer. Pass the reason through so the
        // editor can suggest something else rather than showing an error.
        if ( 'none' === ( $data['content_type'] ?? '' ) ) {
            return new \WP_REST_Response( array(
                'success'  => false,
                'declined' => true,
                'error'    => sanitize_text_field( (string) ( $data['reason'] ?? 'That passage does not contain one clear point to pull out.' ) ),
                'credits'  => $result['credits'],
            ), 200 );
        }

        // The template is a recommendation, not an instruction. sanitize()
        // checks it belongs to the type and that the content can actually fill
        // it, falling back to the type's default otherwise, so a bad guess
        // costs a plainer design rather than a broken block.
        $attrs = VisualTemplates::sanitize( array(
            'contentType' => $data['content_type'] ?? '',
            'template'    => $data['template'] ?? '',
            'content'     => $data['content'] ?? array(),
        ) );

        // Sanitising to null means the response did not carry a usable set of
        // fields. That is our contract failing, not the customer's text, so
        // say so plainly rather than blaming their selection.
        if ( null === $attrs ) {
            return new \WP_REST_Response( array(
                'success' => false,
                'error'   => 'The generated visual came back incomplete. Try again, or write the block by hand.',
                'credits' => $result['credits'],
            ), 200 );
        }

        // Every other figure the passage stated, so switching the headline is a
        // local swap rather than another call. Same reasoning as the three CTA
        // variants: when the model has already read the text, making someone
        // pay again to see its second choice is indefensible.
        $alternates = array();
        foreach ( (array) ( $data['alternates'] ?? array() ) as $alt ) {
            if ( ! is_array( $alt ) ) {
                continue;
            }

            $candidate = VisualTemplates::sanitize( array(
                'contentType' => 'metric',
                'content'     => array(
                    'value'           => $alt['value'] ?? '',
                    'label'           => $alt['label'] ?? '',
                    'supporting_text' => $attrs['content']['supporting_text'] ?? '',
                    'source'          => $attrs['content']['source'] ?? '',
                    'proportion'      => $alt['proportion'] ?? null,
                ),
            ) );

            if ( null === $candidate ) {
                continue;
            }

            $alternates[] = array(
                'content'   => $candidate['content'],
                // Which templates this figure could actually fill, so the
                // editor can offer the ring only where it would mean something.
                'templates' => VisualTemplates::usable_templates( 'metric', $candidate['content'] ),
            );

            if ( count( $alternates ) >= 4 ) {
                break;
            }
        }

        return new \WP_REST_Response( array(
            'success'    => true,
            'attributes' => $attrs,
            'alternates' => $alternates,
            'templates'  => VisualTemplates::usable_templates( $attrs['contentType'], $attrs['content'] ),
            'credits'    => $result['credits'],
        ), 200 );
    }

    /**
     * Draft three CTA copy variants for one destination.
     *
     * @param \WP_REST_Request $request Request.
     * @return \WP_REST_Response
     */
    public function generate_cta_copy( $request ) {
        $body    = $request->get_json_params();
        $dest_id = absint( $body['destination_id'] ?? 0 );
        $dest_t  = sanitize_text_field( (string) ( $body['destination_title'] ?? '' ) );
        $excerpt = '';

        if ( $dest_id ) {
            $post = get_post( $dest_id );
            if ( $post && 'publish' === $post->post_status ) {
                $dest_t  = get_the_title( $post );
                $excerpt = wp_strip_all_tags( get_the_excerpt( $post ) );
            }
        }

        if ( '' === $dest_t ) {
            return new \WP_REST_Response( array(
                'success' => false,
                'error'   => 'Choose where this links to first. Without knowing the destination the copy would be generic, so nothing was charged.',
            ), 200 );
        }

        $result = $this->call_proxy( 'cta_copy', array(
            'context'             => mb_substr( trim( wp_strip_all_tags( (string) ( $body['context'] ?? '' ) ) ), 0, 1500 ),
            'page_title'          => sanitize_text_field( (string) ( $body['page_title'] ?? '' ) ),
            'destination_title'   => $dest_t,
            'destination_excerpt' => mb_substr( $excerpt, 0, 600 ),
            'intent'              => sanitize_text_field( (string) ( $body['intent'] ?? 'read next' ) ),
            'tone'                => BrandKit::resolve()['tone'],
            'business_name'       => $this->business_name(),
        ) );

        if ( ! $result['success'] ) {
            return new \WP_REST_Response( $result, 200 );
        }

        $variants = array();
        foreach ( (array) ( $result['data']['variants'] ?? array() ) as $variant ) {
            if ( ! is_array( $variant ) ) {
                continue;
            }

            $anchor = $this->clip( $variant['anchor'] ?? '', 60 );
            if ( '' === $anchor ) {
                continue;
            }

            $variants[] = array(
                'eyebrow' => $this->clip( $variant['eyebrow'] ?? '', 40 ),
                'heading' => $this->clip( $variant['heading'] ?? '', 90 ),
                'body'    => $this->clip( $variant['body'] ?? '', 160 ),
                'anchor'  => $anchor,
                // Surfaced so the editor can flag it in place rather than the
                // customer discovering it after publishing.
                'vague'   => CTARenderer::anchor_is_vague( $anchor ),
            );

            if ( count( $variants ) >= 3 ) {
                break;
            }
        }

        if ( ! $variants ) {
            return new \WP_REST_Response( array(
                'success' => false,
                'error'   => 'The generated copy came back unusable. Try again, or write it by hand.',
                'credits' => $result['credits'],
            ), 200 );
        }

        return new \WP_REST_Response( array(
            'success'  => true,
            'variants' => $variants,
            'credits'  => $result['credits'],
        ), 200 );
    }

    /* ──────────────────────────── Internals ──────────────────────────── */

    /**
     * Call the AI proxy and decode its JSON result.
     *
     * @param string $task    Proxy task name.
     * @param array  $payload Structured payload; the prompt is built server-side.
     * @return array{success:bool,data?:array,error?:string,credits:mixed}
     */
    private function call_proxy( $task, $payload ) {
        $key = License::is_pro_build() ? License::get_key() : '';

        // Free has no credit metering, so these tasks are not on the
        // unlicensed allowlist. Say that plainly instead of letting the call
        // fail with a 401 the customer cannot act on.
        if ( '' === $key ) {
            return array(
                'success' => false,
                'error'   => 'Generating visuals needs a Pro licence. You can still build these blocks by hand, with your own brand colours, on the free plan.',
                'credits' => null,
            );
        }

        $response = wp_remote_post( self::API_URL, array(
            'body'    => wp_json_encode( array(
                'license_key' => $key,
                'task'        => $task,
                // Base64 so the structured payload survives transport intact,
                // matching the topical map tasks.
                'payload'     => base64_encode( wp_json_encode( $payload ) ),
                'request_id'  => $this->request_id(),
                'site_url'    => home_url(),
            ) ),
            'headers' => array( 'Content-Type' => 'application/json' ),
            'timeout' => 45,
        ) );

        if ( is_wp_error( $response ) ) {
            return array(
                'success' => false,
                'error'   => 'Could not reach the AI service. Nothing was charged. Try again in a moment.',
                'credits' => null,
            );
        }

        $decoded = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( ! is_array( $decoded ) ) {
            return array(
                'success' => false,
                'error'   => 'The AI service returned something unreadable. Nothing was charged.',
                'credits' => null,
            );
        }

        $credits = $decoded['credits'] ?? null;

        if ( empty( $decoded['success'] ) ) {
            return array(
                'success' => false,
                'error'   => sanitize_text_field( (string) ( $decoded['error'] ?? 'The AI service could not complete that request.' ) ),
                'credits' => $credits,
            );
        }

        $result = $decoded['result'] ?? null;
        if ( is_string( $result ) ) {
            $result = json_decode( $result, true );
        }

        if ( ! is_array( $result ) ) {
            return array(
                'success' => false,
                'error'   => 'The generated result came back in an unexpected shape.',
                'credits' => $credits,
            );
        }

        return array( 'success' => true, 'data' => $result, 'credits' => $credits );
    }

    /**
     * Strip a value to plain text within a budget. Mirrors the renderer's own
     * treatment so a generated field cannot arrive longer than a typed one.
     *
     * @param mixed $value Raw value.
     * @param int   $max   Character budget.
     * @return string
     */
    private function clip( $value, $max ) {
        if ( ! is_scalar( $value ) ) {
            return '';
        }

        $value = trim( (string) preg_replace( '/\s+/u', ' ', wp_strip_all_tags( (string) $value ) ) );

        return mb_strlen( $value ) > $max ? rtrim( mb_substr( $value, 0, $max ) ) : $value;
    }

    /**
     * The business name the customer already gave us during setup.
     *
     * @return string
     */
    private function business_name() {
        $settings = get_option( 'asgm_settings', array() );
        $business = isset( $settings['business'] ) && is_array( $settings['business'] ) ? $settings['business'] : array();

        return sanitize_text_field( (string) ( $business['name'] ?? get_bloginfo( 'name' ) ) );
    }

    /**
     * Per-call idempotency key, so a retry after a network blip cannot be
     * charged twice.
     *
     * @return string
     */
    private function request_id() {
        return 'vis-' . bin2hex( random_bytes( 12 ) );
    }
}
