<?php
/**
 * Schema validator.
 *
 * @package AISEOGodMode
 */

namespace AISEOGodMode;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Validates JSON-LD schema output against required and recommended fields.
 */
class Validator {

    /** Version of the portable schema-validation contract. */
    const SCORE_VERSION = 2;

    /**
     * Required fields per schema type.
     *
     * @var array
     */
    private $required_fields = array(
        // Google currently lists no required Article properties. These are
        // quality recommendations, so absence must produce a warning rather
        // than a false rich-results error.
        'Article'          => array(),
        'FAQPage'          => array( 'mainEntity' ),
        'HowTo'            => array( 'name', 'step' ),
        'LocalBusiness'    => array( 'name', 'address' ),
        'Product'          => array( 'name', 'offers' ),
        'Organization'     => array( 'name', 'url' ),
        'WebSite'          => array( 'name', 'url' ),
        'BreadcrumbList'   => array( 'itemListElement' ),
    );

    /**
     * Recommended fields per schema type.
     *
     * @var array
     */
    private $recommended_fields = array(
        'Article'       => array( 'headline', 'author', 'datePublished', 'image', 'dateModified', 'publisher', 'description', 'wordCount' ),
        'LocalBusiness' => array( 'telephone', 'openingHoursSpecification', 'geo', 'priceRange' ),
        'Product'       => array( 'description', 'sku', 'image', 'aggregateRating' ),
        'Organization'  => array( 'logo', 'sameAs', 'description' ),
    );

    /**
     * Validate schema for a single post.
     *
     * @param int $post_id Post ID.
     * @return array
     */
    public function validate_post( $post_id ) {
        $schema_engine = new Schema();
        $schema_data   = $schema_engine->get_for_post( $post_id );
        $schemas       = isset( $schema_data['schemas'] ) ? $schema_data['schemas'] : array();

        $portable = $this->validate_schemas( $schemas );

        $output = array(
            'post_id'       => $post_id,
            'score_version'  => self::SCORE_VERSION,
            'overall'       => $portable['overall'],
            'schema_count'  => $portable['schema_count'],
            'results'       => $portable['results'],
            'validated_at'  => current_time( 'mysql' ),
        );

        // Cache per-post.
        update_post_meta( $post_id, '_asgm_validation_result', wp_json_encode( $output ) );

        return $output;
    }

    /**
     * Validate an already-normalized list of schema entities without reading
     * or writing WordPress state.
     *
     * This is the portable seam shared by post previews and rendered-page
     * audits. Callers may pass an inherited JSON-LD context for entities that
     * came from an @graph whose @context lives on the graph container.
     *
     * @param array $schemas Schema entities.
     * @param array $options Optional inherited_context value.
     * @return array
     */
    public function validate_schemas( $schemas, $options = array() ) {
        $results           = array();
        $inherited_context = $options['inherited_context'] ?? null;

        foreach ( (array) $schemas as $schema ) {
            if ( ! is_array( $schema ) ) {
                $results[] = array(
                    'type'     => 'Unknown',
                    'status'   => 'error',
                    'errors'   => array( __( 'Schema entity is not a JSON object.', 'aeo-god-mode' ) ),
                    'warnings' => array(),
                );
                continue;
            }

            if ( $inherited_context && empty( $schema['@context'] ) ) {
                $schema['@context'] = $inherited_context;
            }

            $types = $this->schema_types( $schema['@type'] ?? null );
            if ( empty( $types ) ) {
                $types = array( 'Unknown' );
            }

            // One JSON-LD entity can declare multiple compatible types. Apply
            // every known type's field contract once and return one combined
            // result so the entity is not double-counted in coverage metrics.
            $results[] = $this->validate_single( $schema, $types );
        }

        return array(
            'score_version' => self::SCORE_VERSION,
            'overall'       => $this->overall_status( $results ),
            'schema_count'  => count( (array) $schemas ),
            'results'       => $results,
        );
    }

    /**
     * Parse and validate JSON-LD scripts from an untrusted rendered document.
     *
     * No scripts or shortcodes are executed. Malformed JSON-LD is returned as
     * an explicit validation error rather than being mistaken for no schema.
     *
     * @param string $html Rendered HTML document.
     * @return array
     */
    public function validate_schema_document( $html ) {
        $schemas         = array();
        $invalid_scripts = array();
        $script_count    = 0;

        // Parse script tags first, then inspect their attributes. This accepts
        // any attribute order and valid quoted or unquoted MIME values without
        // accidentally treating unrelated scripts as JSON-LD.
        if ( preg_match_all( '#<script\b([^>]*)>(.*?)</script>#is', (string) $html, $matches, PREG_SET_ORDER ) ) {
            foreach ( $matches as $match ) {
                $attributes = (string) $match[1];
                $is_json_ld = (bool) preg_match(
                    '#(?:^|\s)type\s*=\s*(?:"\s*application/ld\+json\s*"|\'\s*application/ld\+json\s*\'|application/ld\+json)(?=\s|$)#i',
                    $attributes
                );
                if ( ! $is_json_ld ) {
                    continue;
                }

                $script_count++;
                $json = trim( html_entity_decode( (string) $match[2], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
                $json = preg_replace( '/^\s*<!--|-->\s*$/', '', $json );
                $json = preg_replace( '/^\s*<!\[CDATA\[|\]\]>\s*$/', '', $json );
                $data = json_decode( trim( $json ), true );

                if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $data ) ) {
                    $invalid_scripts[] = array(
                        'script' => $script_count,
                        'error'  => function_exists( 'json_last_error_msg' ) ? json_last_error_msg() : __( 'Invalid JSON.', 'aeo-god-mode' ),
                    );
                    continue;
                }

                $schemas = array_merge( $schemas, $this->flatten_document( $data ) );
            }
        }

        $validation = $this->validate_schemas( $schemas );
        foreach ( $invalid_scripts as $invalid ) {
            $validation['results'][] = array(
                'type'     => 'MalformedJSONLD',
                'status'   => 'error',
                'errors'   => array(
                    sprintf(
                        /* translators: 1: script number, 2: JSON parser error */
                        __( 'JSON-LD script %1$d is malformed: %2$s', 'aeo-god-mode' ),
                        (int) $invalid['script'],
                        (string) $invalid['error']
                    ),
                ),
                'warnings' => array(),
            );
        }

        $validation['overall']         = $this->overall_status( $validation['results'] );
        $validation['script_count']    = $script_count;
        $validation['invalid_scripts'] = $invalid_scripts;
        $validation['schemas']         = $schemas;

        return $validation;
    }

    /**
     * Validate all published posts.
     *
     * @return array
     */
    public function validate_all() {
        $query = new \WP_Query( array(
            'post_type'      => array( 'post', 'page', 'product' ),
            'post_status'    => 'publish',
            'posts_per_page' => 200,
            'no_found_rows'  => true,
        ) );

        $summary = array( 'pass' => 0, 'warning' => 0, 'error' => 0 );
        $details = array();

        if ( $query->have_posts() ) {
            while ( $query->have_posts() ) {
                $query->the_post();
                $result = $this->validate_post( get_the_ID() );
                $summary[ $result['overall'] ]++;
                $details[] = array(
                    'post_id' => get_the_ID(),
                    'title'   => get_the_title(),
                    'status'  => $result['overall'],
                );
            }
            wp_reset_postdata();
        }

        return array(
            'summary' => $summary,
            'total'   => array_sum( $summary ),
            'details' => $details,
        );
    }

    /**
     * Validate a single schema object.
     *
     * @param array $schema Schema data.
     * @param array $types  Declared schema types.
     * @return array
     */
    private function validate_single( $schema, $types ) {
        $errors   = array();
        $warnings = array();

        $types = (array) $types;

        if ( empty( $schema['@type'] ) ) {
            $errors[] = __( 'Missing @type property.', 'aeo-god-mode' );
        }

        if ( empty( $schema['@context'] ) ) {
            $errors[] = __( 'Missing @context property.', 'aeo-god-mode' );
        }

        foreach ( $types as $declared_type ) {
            $contract_type = $this->contract_type( $declared_type );

            // Check required fields.
            foreach ( $this->required_fields[ $contract_type ] ?? array() as $field ) {
                if ( ! isset( $schema[ $field ] ) || empty( $schema[ $field ] ) ) {
                    $errors[] = sprintf(
                        /* translators: 1: field name, 2: schema type */
                        __( 'Missing required field "%1$s" for %2$s schema.', 'aeo-god-mode' ),
                        $field,
                        $declared_type
                    );
                }
            }

            // Check recommended fields.
            foreach ( $this->recommended_fields[ $contract_type ] ?? array() as $field ) {
                if ( ! isset( $schema[ $field ] ) || empty( $schema[ $field ] ) ) {
                    $warnings[] = sprintf(
                        /* translators: 1: field name, 2: schema type */
                        __( 'Missing recommended field "%1$s" for %2$s schema.', 'aeo-god-mode' ),
                        $field,
                        $declared_type
                    );
                }
            }
        }

        if ( in_array( 'FAQPage', $types, true ) && ! empty( $schema['mainEntity'] ) ) {
            foreach ( $this->entity_list( $schema['mainEntity'] ) as $index => $question ) {
                $answer = is_array( $question ) ? ( $question['acceptedAnswer'] ?? null ) : null;
                $is_reference = is_array( $question ) && ! empty( $question['@id'] ) && empty( $question['name'] ) && empty( $question['acceptedAnswer'] );
                if ( ! $is_reference && ( ! is_array( $question ) || empty( $question['name'] ) || ! is_array( $answer ) || empty( $answer['text'] ) ) ) {
                    $errors[] = sprintf(
                        /* translators: %d: one-based FAQ item number */
                        __( 'FAQ item %d needs a non-empty question and accepted answer.', 'aeo-god-mode' ),
                        $index + 1
                    );
                }
            }
        }

        if ( in_array( 'HowTo', $types, true ) ) {
            $complete_steps = 0;
            foreach ( $this->entity_list( $schema['step'] ?? array() ) as $index => $step ) {
                $is_reference = is_array( $step ) && ! empty( $step['@id'] ) && empty( $step['name'] ) && empty( $step['text'] );
                $is_complete  = $is_reference || ( is_array( $step ) && ( ! empty( $step['name'] ) || ! empty( $step['text'] ) ) );
                if ( ! $is_complete ) {
                    $errors[] = sprintf(
                        /* translators: %d: one-based HowTo step number */
                        __( 'HowTo step %d needs a non-empty name or text.', 'aeo-god-mode' ),
                        $index + 1
                    );
                } else {
                    $complete_steps++;
                }
            }

            if ( $complete_steps < 2 ) {
                $errors[] = sprintf(
                    /* translators: %d: complete HowTo step count */
                    __( 'HowTo schema needs at least 2 complete steps; %d found.', 'aeo-god-mode' ),
                    $complete_steps
                );
            } elseif ( $complete_steps < 3 ) {
                $warnings[] = sprintf(
                    /* translators: %d: complete HowTo step count */
                    __( 'HowTo schema has %d complete steps; 3 or more is the quality target.', 'aeo-god-mode' ),
                    $complete_steps
                );
            }
        }

        // Status.
        $status = 'pass';
        if ( ! empty( $warnings ) ) {
            $status = 'warning';
        }
        if ( ! empty( $errors ) ) {
            $status = 'error';
        }

        return array(
            'type'     => implode( ', ', $types ),
            'types'    => $types,
            'status'   => $status,
            'errors'   => array_values( array_unique( $errors ) ),
            'warnings' => array_values( array_unique( $warnings ) ),
        );
    }

    /** Normalize @type into a list of non-empty strings. */
    private function schema_types( $raw ) {
        $types = array();
        foreach ( is_array( $raw ) ? $raw : array( $raw ) as $type ) {
            if ( is_scalar( $type ) && '' !== trim( (string) $type ) ) {
                $types[] = trim( (string) $type );
            }
        }
        return array_values( array_unique( $types ) );
    }

    /** Map Article subtypes onto the shared Article recommendation contract. */
    private function contract_type( $type ) {
        if ( in_array( $type, array( 'Article', 'BlogPosting', 'NewsArticle', 'TechArticle', 'ScholarlyArticle' ), true ) ) {
            return 'Article';
        }
        return $type;
    }

    /** Normalize a single JSON-LD entity or a list of entities. */
    private function entity_list( $value ) {
        if ( ! is_array( $value ) ) {
            return array();
        }
        if ( isset( $value['@type'] ) || isset( $value['@id'] ) ) {
            return array( $value );
        }
        return array_values( $value );
    }

    /** Compute the overall state for a list of entity results. */
    private function overall_status( $results ) {
        $overall = 'pass';
        foreach ( (array) $results as $result ) {
            if ( 'error' === ( $result['status'] ?? '' ) ) {
                return 'error';
            }
            if ( 'warning' === ( $result['status'] ?? '' ) ) {
                $overall = 'warning';
            }
        }
        return $overall;
    }

    /** Flatten a decoded JSON-LD document while inheriting graph context. */
    private function flatten_document( $data ) {
        if ( empty( $data ) ) {
            return array();
        }
        if ( array_keys( $data ) === range( 0, count( $data ) - 1 ) ) {
            $out = array();
            foreach ( $data as $entity ) {
                if ( is_array( $entity ) ) {
                    $out = array_merge( $out, $this->flatten_document( $entity ) );
                }
            }
            return $out;
        }

        if ( isset( $data['@graph'] ) && is_array( $data['@graph'] ) ) {
            $out     = array();
            $context = $data['@context'] ?? null;
            foreach ( $data['@graph'] as $entity ) {
                if ( ! is_array( $entity ) ) {
                    continue;
                }
                if ( $context && empty( $entity['@context'] ) ) {
                    $entity['@context'] = $context;
                }
                $out[] = $entity;
            }
            return $out;
        }

        return array( $data );
    }
}
