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

    /**
     * Required fields per schema type.
     *
     * @var array
     */
    private $required_fields = array(
        'Article'          => array( 'headline', 'author', 'datePublished', 'image' ),
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
        'Article'       => array( 'dateModified', 'publisher', 'description', 'wordCount' ),
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

        $results = array();

        foreach ( $schemas as $schema ) {
            $type = isset( $schema['@type'] ) ? $schema['@type'] : 'Unknown';
            $result = $this->validate_single( $schema, $type );
            $results[] = $result;
        }

        $overall = 'pass';
        foreach ( $results as $r ) {
            if ( 'error' === $r['status'] ) {
                $overall = 'error';
                break;
            }
            if ( 'warning' === $r['status'] ) {
                $overall = 'warning';
            }
        }

        $output = array(
            'post_id'       => $post_id,
            'overall'       => $overall,
            'schema_count'  => count( $schemas ),
            'results'       => $results,
            'validated_at'  => current_time( 'mysql' ),
        );

        // Cache per-post.
        update_post_meta( $post_id, '_asgm_validation_result', wp_json_encode( $output ) );

        return $output;
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
     * @param array  $schema Schema data.
     * @param string $type   Schema type.
     * @return array
     */
    private function validate_single( $schema, $type ) {
        $errors   = array();
        $warnings = array();

        // Check required fields.
        if ( isset( $this->required_fields[ $type ] ) ) {
            foreach ( $this->required_fields[ $type ] as $field ) {
                if ( ! isset( $schema[ $field ] ) || empty( $schema[ $field ] ) ) {
                    $errors[] = sprintf(
                        /* translators: 1: field name, 2: schema type */
                        __( 'Missing required field "%1$s" for %2$s schema.', 'aeo-god-mode' ),
                        $field,
                        $type
                    );
                }
            }
        }

        // Check recommended fields.
        if ( isset( $this->recommended_fields[ $type ] ) ) {
            foreach ( $this->recommended_fields[ $type ] as $field ) {
                if ( ! isset( $schema[ $field ] ) || empty( $schema[ $field ] ) ) {
                    $warnings[] = sprintf(
                        /* translators: 1: field name, 2: schema type */
                        __( 'Missing recommended field "%1$s" for %2$s schema.', 'aeo-god-mode' ),
                        $field,
                        $type
                    );
                }
            }
        }

        // JSON-LD structure checks.
        if ( ! isset( $schema['@context'] ) ) {
            $errors[] = __( 'Missing @context property.', 'aeo-god-mode' );
        }

        if ( ! isset( $schema['@type'] ) ) {
            $errors[] = __( 'Missing @type property.', 'aeo-god-mode' );
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
            'type'     => $type,
            'status'   => $status,
            'errors'   => $errors,
            'warnings' => $warnings,
        );
    }
}
