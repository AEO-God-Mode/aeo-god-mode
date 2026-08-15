<?php
/**
 * Portable rendered-page quality evaluator.
 *
 * @package AISEOGodMode
 */

namespace AISEOGodMode;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Deterministically evaluates HTML without reading or writing WordPress state.
 *
 * WordPress posts and remote page audits provide different adapters, but both
 * receive the same answer-first, answer-density, metadata and schema verdicts.
 */
class RenderedPageEvaluator {

    /** Version of this portable output contract. */
    const SCORE_VERSION = 3;

    /** Minimum page-health score used by the generated-draft contract. */
    const MIN_AEO_SCORE = 85;

    /** Minimum answer-density score when question headings are present. */
    const MIN_ANSWER_DENSITY = 80;

    /** Page-health impacts, kept aligned with ContentGaps. */
    const IMPACTS = array(
        'thin_content'        => 25,
        'no_schema'           => 20,
        'schema_errors'       => 15,
        'no_meta_description' => 15,
        'aeo_weak'            => 12,
        'schema_warnings'     => 3,
    );

    /**
     * Evaluate a content fragment or full rendered document.
     *
     * Input keys:
     * - url, document_html/html, content_html
     * - title, meta_description
     * - schemas (normalized JSON-LD entities) or schema_validation
     * - page_kind (auto, editorial, homepage, listing, product, generic)
     *
     * Policy keys:
     * - profile (external_published or generated_draft)
     * - answerability_eligible, content_depth_eligible
     * - min_answer_first_ratio (0.0-1.0)
     * - min_word_count
     * - require_reliable_content
     *
     * @param array|string $input  Snapshot array or a full HTML document.
     * @param array        $policy Applicability and threshold overrides.
     * @return array Stable, persistence-free evaluation.
     */
    public static function evaluate( $input, $policy = array() ) {
        if ( is_string( $input ) ) {
            $input = array( 'document_html' => $input );
        }
        $input  = is_array( $input ) ? $input : array();
        $policy = self::normalize_policy( $policy );

        $document_html = (string) ( $input['document_html'] ?? $input['html'] ?? '' );
        $extracted     = self::extract_document( $document_html );

        if ( array_key_exists( 'content_html', $input ) ) {
            $content_html = (string) $input['content_html'];
            $extraction   = array(
                'selector'   => 'provided',
                'confidence' => (string) ( $input['extraction_confidence'] ?? 'high' ),
            );
        } else {
            $content_html = (string) $extracted['content_html'];
            $extraction   = $extracted['extraction'];
        }
        if ( ! empty( $input['document_truncated'] ) ) {
            $extraction['confidence'] = 'low';
            $extraction['truncated']  = true;
        }

        $title_known = array_key_exists( 'title', $input ) || '' !== $document_html;
        $meta_known  = array_key_exists( 'meta_description', $input ) || '' !== $document_html;
        $title       = array_key_exists( 'title', $input )
            ? trim( (string) $input['title'] )
            : (string) $extracted['title'];
        $meta_description = array_key_exists( 'meta_description', $input )
            ? trim( (string) $input['meta_description'] )
            : (string) $extracted['meta_description'];

        $schema_presence   = array_key_exists( 'schema_presence', $input )
            ? ( null === $input['schema_presence'] ? null : (bool) $input['schema_presence'] )
            : null;
        $schema_known      = array_key_exists( 'schemas', $input )
            || array_key_exists( 'schema_validation', $input )
            || null !== $schema_presence
            || '' !== $document_html;
        $schemas           = self::normalize_schemas( $input['schemas'] ?? array() );
        $schema_validation = is_array( $input['schema_validation'] ?? null )
            ? $input['schema_validation']
            : null;

        if ( null === $schema_validation && class_exists( __NAMESPACE__ . '\\Validator' ) ) {
            $validator = new Validator();
            if ( array_key_exists( 'schemas', $input ) ) {
                $schema_validation = $validator->validate_schemas( $schemas );
            } elseif ( '' !== $document_html ) {
                $schema_validation = $validator->validate_schema_document( $document_html );
                $schemas           = self::normalize_schemas( $schema_validation['schemas'] ?? array() );
            } elseif ( false === $schema_presence ) {
                $schema_validation = $validator->validate_schemas( array() );
            } elseif ( true === $schema_presence ) {
                // Presence was asserted by a local emitter, but no portable
                // entities were supplied, so structure remains unknown while
                // still recording which validator contract owns the result.
                $schema_validation = array(
                    'score_version' => Validator::SCORE_VERSION,
                    'overall'       => 'unknown',
                    'schema_count'  => 0,
                    'results'       => array(),
                );
            }
        }

        if ( null === $schema_validation ) {
            $schema_validation = array(
                'score_version' => null,
                'overall'       => $schema_known && null === $schema_presence ? 'pass' : 'unknown',
                'schema_count'  => count( $schemas ),
                'results'       => array(),
            );
        }

        $schema_types = self::schema_types( $schemas, $schema_validation );
        $word_count   = str_word_count( wp_strip_all_tags( $content_html ) );
        $heading      = self::heading_counts( $content_html );
        $density      = is_array( $input['answer_density'] ?? null )
            ? $input['answer_density']
            : ( class_exists( __NAMESPACE__ . '\\Answer_Density' )
                ? Answer_Density::scan_html( $content_html )
                : self::empty_density( $word_count ) );
        $opening      = self::opening_answer_report( $content_html );
        $h2_openers   = self::h2_opener_report( $content_html );

        $url       = esc_url_raw( (string) ( $input['url'] ?? '' ) );
        $page_kind = self::page_kind(
            (string) ( $input['page_kind'] ?? 'auto' ),
            $url,
            $schema_types,
            $word_count,
            $heading,
            $document_html
        );

        $reliable_content = 'low' !== ( $extraction['confidence'] ?? 'low' ) || ! $policy['require_reliable_content'];
        $answerability_eligible = null !== $policy['answerability_eligible']
            ? $policy['answerability_eligible']
            : 'editorial' === $page_kind;
        $depth_eligible = null !== $policy['content_depth_eligible']
            ? $policy['content_depth_eligible']
            : in_array( $page_kind, array( 'editorial', 'generic' ), true );

        $checks = array();

        if ( ! $depth_eligible || ! $reliable_content ) {
            self::add_check( $checks, 'thin_content', 'not_applicable', 0, __( 'Content depth is not applicable to this page type or could not be extracted reliably.', 'aeo-god-mode' ) );
        } elseif ( $word_count < $policy['min_word_count'] ) {
            self::add_check(
                $checks,
                'thin_content',
                'fail',
                self::IMPACTS['thin_content'],
                sprintf(
                    /* translators: 1: words found, 2: minimum words */
                    __( '%1$d words found; content pages should contain at least %2$d words.', 'aeo-god-mode' ),
                    $word_count,
                    $policy['min_word_count']
                )
            );
        } else {
            self::add_check( $checks, 'thin_content', 'pass', 0, sprintf( __( '%d words of primary content found.', 'aeo-god-mode' ), $word_count ) );
        }

        $meaningful_schema = array_values( array_diff( $schema_types, array( 'BreadcrumbList' ) ) );
        $has_meaningful_schema = true === $schema_presence || ! empty( $meaningful_schema );
        if ( ! $schema_known ) {
            self::add_check( $checks, 'no_schema', 'unknown', 0, __( 'Schema output was not supplied to the evaluator.', 'aeo-god-mode' ) );
        } elseif ( ! $has_meaningful_schema ) {
            self::add_check( $checks, 'no_schema', 'fail', self::IMPACTS['no_schema'], __( 'No meaningful JSON-LD schema was found.', 'aeo-god-mode' ) );
        } else {
            self::add_check(
                $checks,
                'no_schema',
                'pass',
                0,
                ! empty( $meaningful_schema )
                    ? sprintf( __( 'JSON-LD detected: %s.', 'aeo-god-mode' ), implode( ', ', array_slice( $meaningful_schema, 0, 6 ) ) )
                    : __( 'A schema provider is active, but its rendered entities were not available in this editor snapshot.', 'aeo-god-mode' )
            );
        }

        $schema_errors   = self::validation_messages( $schema_validation, 'errors' );
        $schema_warnings = self::validation_messages( $schema_validation, 'warnings' );
        $schema_quality_known = 'unknown' !== (string) ( $schema_validation['overall'] ?? 'unknown' );
        if ( ! $schema_known || ! $schema_quality_known ) {
            self::add_check( $checks, 'schema_errors', 'not_applicable', 0, __( 'Schema quality needs emitted JSON-LD.', 'aeo-god-mode' ) );
            self::add_check( $checks, 'schema_warnings', 'not_applicable', 0, __( 'Recommended schema fields need emitted JSON-LD.', 'aeo-god-mode' ) );
        } else {
            self::add_check(
                $checks,
                'schema_errors',
                empty( $schema_errors ) ? 'pass' : 'fail',
                empty( $schema_errors ) ? 0 : self::IMPACTS['schema_errors'],
                empty( $schema_errors )
                    ? __( 'No structural JSON-LD errors were found.', 'aeo-god-mode' )
                    : sprintf( __( '%d structural JSON-LD issue(s) found.', 'aeo-god-mode' ), count( $schema_errors ) ),
                array( 'messages' => $schema_errors )
            );
            if ( empty( $meaningful_schema ) ) {
                self::add_check( $checks, 'schema_warnings', 'not_applicable', 0, __( 'Recommended schema fields need a meaningful schema entity.', 'aeo-god-mode' ) );
            } else {
                self::add_check(
                    $checks,
                    'schema_warnings',
                    empty( $schema_warnings ) ? 'pass' : 'warn',
                    empty( $schema_warnings ) ? 0 : self::IMPACTS['schema_warnings'],
                    empty( $schema_warnings )
                        ? __( 'Recommended schema fields are present.', 'aeo-god-mode' )
                        : sprintf( __( '%d recommended schema field(s) are missing.', 'aeo-god-mode' ), count( $schema_warnings ) ),
                    array( 'messages' => $schema_warnings )
                );
            }
        }

        $description_len = self::text_length( $meta_description );
        if ( ! $meta_known ) {
            self::add_check( $checks, 'no_meta_description', 'unknown', 0, __( 'Document metadata was not supplied.', 'aeo-god-mode' ) );
        } elseif ( 0 === $description_len ) {
            self::add_check( $checks, 'no_meta_description', 'fail', self::IMPACTS['no_meta_description'], __( 'No meta description was found.', 'aeo-god-mode' ) );
        } elseif ( $description_len >= 145 && $description_len <= 158 ) {
            self::add_check( $checks, 'no_meta_description', 'pass', 0, sprintf( __( 'Meta description is %d characters.', 'aeo-god-mode' ), $description_len ) );
        } else {
            self::add_check(
                $checks,
                'no_meta_description',
                'warn',
                0,
                sprintf( __( 'Meta description is %d characters; the target is 145–158.', 'aeo-god-mode' ), $description_len )
            );
        }

        $section_ratio = null === $h2_openers['direct_ratio'] ? 0.0 : (float) $h2_openers['direct_ratio'];
        $answer_first_ok = $opening['direct']
            && $h2_openers['checked'] > 0
            && $section_ratio >= $policy['min_answer_first_ratio'];

        if ( ! $answerability_eligible ) {
            self::add_check( $checks, 'aeo_weak', 'not_applicable', 0, __( 'Answer-first prose is not applicable to this page type.', 'aeo-god-mode' ) );
        } elseif ( ! $reliable_content ) {
            self::add_check( $checks, 'aeo_weak', 'unknown', 0, __( 'Primary prose could not be extracted reliably.', 'aeo-god-mode' ) );
        } elseif ( ! $answer_first_ok ) {
            self::add_check(
                $checks,
                'aeo_weak',
                'fail',
                self::IMPACTS['aeo_weak'],
                __( 'The opening and substantive H2 sections do not consistently lead with direct answers.', 'aeo-god-mode' ),
                array( 'opening' => $opening, 'h2_openers' => $h2_openers )
            );
        } else {
            self::add_check( $checks, 'aeo_weak', 'pass', 0, __( 'The page and its substantive H2 sections lead with direct answers.', 'aeo-god-mode' ) );
        }

        $unanswered = array_key_exists( 'unanswered_answers', $density )
            ? (int) $density['unanswered_answers']
            : count( array_filter( (array) ( $density['issues'] ?? array() ), function ( $issue ) {
                return 'no_direct_answer' === ( $issue['issue'] ?? '' );
            } ) );
        if ( ! $reliable_content ) {
            self::add_check( $checks, 'answer_density', 'unknown', 0, __( 'Answer Density needs a complete, reliably extracted content body.', 'aeo-god-mode' ) );
            $density_score = null;
        } elseif ( empty( $density['applicable'] ) ) {
            self::add_check( $checks, 'answer_density', 'not_applicable', 0, __( 'No question-shaped H2 or H3 headings were found.', 'aeo-god-mode' ) );
            $density_score = null;
        } else {
            $density_score = (int) ( $density['answer_density_score'] ?? 0 );
            $density_ok    = $density_score >= self::MIN_ANSWER_DENSITY && 0 === $unanswered;
            self::add_check(
                $checks,
                'answer_density',
                $density_ok ? 'pass' : 'fail',
                0,
                sprintf(
                    __( '%1$d/100; %2$d of %3$d question headings lead with a direct answer.', 'aeo-god-mode' ),
                    $density_score,
                    (int) ( $density['direct_answers'] ?? 0 ),
                    (int) ( $density['question_headings'] ?? 0 )
                ),
                array( 'unanswered' => $unanswered )
            );
        }

        $title_len = self::text_length( $title );
        if ( ! $title_known ) {
            self::add_check( $checks, 'meta_title', 'unknown', 0, __( 'Document title was not supplied.', 'aeo-god-mode' ) );
        } elseif ( $title_len > 0 && $title_len <= 60 ) {
            self::add_check( $checks, 'meta_title', 'pass', 0, sprintf( __( 'Title is %d characters.', 'aeo-god-mode' ), $title_len ) );
        } else {
            self::add_check( $checks, 'meta_title', 'warn', 0, $title_len > 0 ? sprintf( __( 'Title is %d characters; keep it at 60 or fewer.', 'aeo-god-mode' ), $title_len ) : __( 'No document title was found.', 'aeo-god-mode' ) );
        }

        $score_impact = array_sum( array_map( function ( $check ) {
            return in_array( $check['status'], array( 'fail', 'warn' ), true ) ? (int) $check['score_impact'] : 0;
        }, $checks ) );
        $page_health = max( 0, 100 - min( 100, $score_impact ) );

        $issues = array_values( array_filter( $checks, function ( $check ) {
            return $check['score_impact'] > 0 && in_array( $check['status'], array( 'fail', 'warn' ), true );
        } ) );
        $blocking = array_values( array_filter( $checks, function ( $check ) {
            return 'fail' === $check['status'];
        } ) );
        $warnings = array_values( array_filter( $checks, function ( $check ) {
            return 'warn' === $check['status'];
        } ) );

        return array(
            'score_version'    => self::SCORE_VERSION,
            'profile'          => $policy['profile'],
            'url'              => $url,
            'page_kind'        => $page_kind,
            'content_hash'     => hash( 'sha256', $content_html . '|' . $title . '|' . $meta_description . '|' . wp_json_encode( $schemas ) ),
            'scores'           => array(
                'aeo'            => $page_health,
                'answer_density' => $density_score,
                'answer_first'   => null === $h2_openers['direct_ratio'] ? null : (int) round( $h2_openers['direct_ratio'] * 100 ),
            ),
            'thresholds'       => array(
                'aeo'                => self::MIN_AEO_SCORE,
                'answer_density'     => self::MIN_ANSWER_DENSITY,
                'answer_first_ratio' => $policy['min_answer_first_ratio'],
                'min_word_count'     => $policy['min_word_count'],
            ),
			// A generated-draft preflight cannot certify machine readiness from
			// an unobserved provider. Published-page audits may report unknown,
			// but drafts must have definitive schema presence and validation.
			'machine_ready'    => $page_health >= self::MIN_AEO_SCORE
				&& empty( $blocking )
				&& ( 'generated_draft' !== $policy['profile'] || ( $schema_known && $schema_quality_known ) ),
            'checks'           => $checks,
            'issues'           => $issues,
            'blocking_failures'=> $blocking,
            'warnings'         => $warnings,
            'answer_density'   => $density,
            'schema'           => array(
                'known'      => $schema_known,
                'observed_presence' => $schema_presence,
                'types'      => $schema_types,
                'meaningful' => $meaningful_schema,
                'validation' => $schema_validation,
            ),
            'metrics'          => array(
                'word_count'       => $word_count,
                'headings'         => $heading,
                'title_length'     => $title_len,
                'description_length' => $description_len,
                'opening_answer'   => $opening,
                'h2_openers'       => $h2_openers,
            ),
            'extraction'       => $extraction,
            'applicability'    => array(
                'answerability' => (bool) $answerability_eligible,
                'content_depth' => (bool) $depth_eligible,
            ),
        );
    }

    /**
     * Extract primary content and metadata from a full HTML document.
     *
     * @param string $html Rendered HTML.
     * @return array
     */
    public static function extract_document( $html ) {
        $html = (string) $html;
        $meta = self::document_metadata( $html );

        if ( '' === trim( $html ) ) {
            return array_merge(
                $meta,
                array(
                    'content_html' => '',
                    'extraction'   => array( 'selector' => 'none', 'confidence' => 'low' ),
                )
            );
        }

        if ( class_exists( '\\DOMDocument' ) ) {
            $previous = libxml_use_internal_errors( true );
            $dom      = new \DOMDocument();
            $flags    = defined( 'LIBXML_NONET' ) ? LIBXML_NONET : 0;
            $loaded   = $dom->loadHTML( '<?xml encoding="utf-8" ?>' . $html, $flags );
            libxml_clear_errors();
            libxml_use_internal_errors( $previous );

            if ( $loaded ) {
                $xpath  = new \DOMXPath( $dom );
                $groups = array(
                    array(
                        'selector'   => 'article-content',
                        'confidence' => 'high',
                        'query'      => '//*[@itemprop="articleBody"] | //*[contains(concat(" ", normalize-space(@class), " "), " entry-content ")] | //*[contains(concat(" ", normalize-space(@class), " "), " post-content ")] | //article',
                    ),
                    array(
                        'selector'   => 'main',
                        'confidence' => 'medium',
                        'query'      => '//main | //*[@role="main"]',
                    ),
                    array(
                        'selector'   => 'body',
                        'confidence' => 'low',
                        'query'      => '//body',
                    ),
                );

                foreach ( $groups as $group ) {
                    $nodes = $xpath->query( $group['query'] );
                    $best  = null;
                    $words = -1;
                    if ( $nodes ) {
                        foreach ( $nodes as $node ) {
                            $count = str_word_count( (string) $node->textContent );
                            if ( $count > $words ) {
                                $best  = $node;
                                $words = $count;
                            }
                        }
                    }
                    if ( $best ) {
                        return array_merge(
                            $meta,
                            array(
                                'content_html' => self::clean_node_html( $best ),
                                'extraction'   => array(
                                    'selector'   => $group['selector'],
                                    'confidence' => $group['confidence'],
                                ),
                            )
                        );
                    }
                }
            }
        }

        return array_merge(
            $meta,
            array(
                'content_html' => $html,
                'extraction'   => array( 'selector' => 'document-fallback', 'confidence' => 'low' ),
            )
        );
    }

    /** Classify the opening prose before the first H2. */
    public static function opening_answer_report( $content ) {
        $intro = (string) $content;
        if ( preg_match( '/<h2\b/i', $intro, $match, PREG_OFFSET_CAPTURE ) ) {
            $intro = substr( $intro, 0, (int) $match[0][1] );
        }
        $intro = preg_replace( '#<h[1-6]\b[^>]*>.*?</h[1-6]>#is', ' ', $intro );

        if ( class_exists( __NAMESPACE__ . '\\Answer_Density' ) ) {
            $test = Answer_Density::classify_answer( $intro );
            return array(
                'direct'              => 'direct' === ( $test['classification'] ?? '' ),
                'classification'      => (string) ( $test['classification'] ?? 'no_answer' ),
                'words_before_answer' => (int) ( $test['words_before_answer'] ?? 0 ),
                'first_sentence'      => (string) ( $test['first_sentence'] ?? '' ),
            );
        }

        $plain = trim( wp_strip_all_tags( $intro ) );
        return array(
            'direct'              => '' !== $plain,
            'classification'      => '' !== $plain ? 'direct' : 'no_answer',
            'words_before_answer' => 0,
            'first_sentence'      => '',
        );
    }

    /** Classify the first answer beneath every substantive H2. */
    public static function h2_opener_report( $content ) {
        $issues = array();
        $counts = array( 'direct' => 0, 'buried' => 0, 'no_answer' => 0 );
        $checked = 0;

        if ( ! class_exists( __NAMESPACE__ . '\\Answer_Density' ) ) {
            return array_merge( array( 'checked' => 0, 'direct_ratio' => null, 'issues' => array() ), $counts );
        }

        preg_match_all( '/<h2\b[^>]*>(.*?)<\/h2>/si', (string) $content, $headings, PREG_SET_ORDER | PREG_OFFSET_CAPTURE );
        foreach ( $headings as $heading ) {
            $label = trim( wp_strip_all_tags( $heading[1][0] ) );
            if ( preg_match( '/^(?:faq|frequently asked questions|conclusion|summary|final thoughts|key takeaways|resources|references|sources|further reading|related(?: posts| articles| reading)?)\s*:?$/i', $label ) ) {
                continue;
            }

            $checked++;
            $end  = (int) $heading[0][1] + strlen( $heading[0][0] );
            $body = Answer_Density::extract_after_heading( (string) $content, $end, 2 );
            $test = Answer_Density::classify_answer( $body );
            $kind = (string) ( $test['classification'] ?? 'no_answer' );
            if ( ! isset( $counts[ $kind ] ) ) {
                $kind = 'no_answer';
            }
            $counts[ $kind ]++;
            if ( 'direct' !== $kind ) {
                $issues[] = array(
                    'heading'        => $label,
                    'classification' => $kind,
                    'first_sentence' => (string) ( $test['first_sentence'] ?? '' ),
                );
            }
        }

        return array_merge(
            array(
                'checked'      => $checked,
                'direct_ratio' => $checked > 0 ? round( $counts['direct'] / $checked, 3 ) : null,
                'issues'       => $issues,
            ),
            $counts
        );
    }

    /** Normalize profile and applicability options. */
    private static function normalize_policy( $policy ) {
        $profile = 'generated_draft' === ( $policy['profile'] ?? '' ) ? 'generated_draft' : 'external_published';
        $ratio   = isset( $policy['min_answer_first_ratio'] )
            ? (float) $policy['min_answer_first_ratio']
            : ( 'generated_draft' === $profile ? 1.0 : 0.8 );

        return array(
            'profile'                  => $profile,
            'answerability_eligible'   => array_key_exists( 'answerability_eligible', $policy ) ? (bool) $policy['answerability_eligible'] : null,
            'content_depth_eligible'   => array_key_exists( 'content_depth_eligible', $policy ) ? (bool) $policy['content_depth_eligible'] : null,
            'min_answer_first_ratio'   => max( 0.0, min( 1.0, $ratio ) ),
            'min_word_count'           => max( 1, (int) ( $policy['min_word_count'] ?? 300 ) ),
            'require_reliable_content' => array_key_exists( 'require_reliable_content', $policy ) ? (bool) $policy['require_reliable_content'] : 'external_published' === $profile,
        );
    }

    /** Normalize a flat, keyed or single schema collection. */
    private static function normalize_schemas( $raw ) {
        if ( ! is_array( $raw ) || empty( $raw ) ) {
            return array();
        }
        if ( isset( $raw['@type'] ) ) {
            return array( $raw );
        }
        if ( isset( $raw['@graph'] ) && is_array( $raw['@graph'] ) ) {
            $context = $raw['@context'] ?? null;
            $out     = array();
            foreach ( $raw['@graph'] as $schema ) {
                if ( is_array( $schema ) ) {
                    if ( $context && empty( $schema['@context'] ) ) {
                        $schema['@context'] = $context;
                    }
                    $out[] = $schema;
                }
            }
            return $out;
        }

        $out = array();
        foreach ( $raw as $schema ) {
            if ( is_array( $schema ) && isset( $schema['@type'] ) ) {
                $out[] = $schema;
            }
        }
        return $out;
    }

    /** Get unique top-level schema types from normalized entities/results. */
    private static function schema_types( $schemas, $validation ) {
        $types = array();
        foreach ( (array) $schemas as $schema ) {
            $declared = is_array( $schema['@type'] ?? null ) ? $schema['@type'] : array( $schema['@type'] ?? '' );
            foreach ( $declared as $type ) {
                if ( is_string( $type ) && '' !== $type ) {
                    $types[ $type ] = true;
                }
            }
        }
        if ( empty( $types ) ) {
            foreach ( (array) ( $validation['results'] ?? array() ) as $result ) {
                foreach ( (array) ( $result['types'] ?? array() ) as $type ) {
                    if ( is_string( $type ) && '' !== $type && 'Unknown' !== $type && 'MalformedJSONLD' !== $type ) {
                        $types[ $type ] = true;
                    }
                }
            }
        }
        return array_keys( $types );
    }

    /** Resolve page applicability without treating home/listing pages as posts. */
    private static function page_kind( $requested, $url, $types, $word_count, $headings, $document_html ) {
        if ( in_array( $requested, array( 'editorial', 'homepage', 'listing', 'product', 'generic' ), true ) ) {
            return $requested;
        }

        $path = $url ? (string) wp_parse_url( $url, PHP_URL_PATH ) : '';
        if ( $url && ( '' === trim( $path, '/' ) ) ) {
            return 'homepage';
        }
        if ( array_intersect( $types, array( 'Product', 'ProductGroup' ) ) ) {
            return 'product';
        }
        if ( array_intersect( $types, array( 'CollectionPage', 'ItemList' ) ) ) {
            return 'listing';
        }
        if ( array_intersect( $types, array( 'Article', 'BlogPosting', 'NewsArticle', 'TechArticle', 'ScholarlyArticle' ) ) ) {
            return 'editorial';
        }
        if ( preg_match( '/<meta\b[^>]+property=[\'"]article:(?:published_time|modified_time)[\'"]/i', (string) $document_html ) ) {
            return 'editorial';
        }
        if ( $word_count >= 300 && (int) ( $headings['h2'] ?? 0 ) >= 2 ) {
            return 'editorial';
        }
        return 'generic';
    }

    /** Count visible semantic headings in the selected content root. */
    private static function heading_counts( $content ) {
        $out = array();
        foreach ( range( 1, 6 ) as $level ) {
            $out[ 'h' . $level ] = preg_match_all( '/<h' . $level . '\b/i', (string) $content, $matches );
        }
        return $out;
    }

    /** Pull all validator messages for one severity. */
    private static function validation_messages( $validation, $key ) {
        $messages = array();
        foreach ( (array) ( $validation['results'] ?? array() ) as $result ) {
            foreach ( (array) ( $result[ $key ] ?? array() ) as $message ) {
                $messages[] = (string) $message;
            }
        }
        return array_values( array_unique( $messages ) );
    }

    /** Add a stable, machine-readable check. */
    private static function add_check( &$checks, $id, $status, $impact, $message, $extra = array() ) {
        $checks[] = array_merge(
            array(
                'id'              => $id,
                'status'          => $status,
                'message'         => $message,
                'score_impact'    => (int) $impact,
                'counts_as_issue' => $impact > 0,
            ),
            $extra
        );
    }

    /** Extract title and description without requiring DOM. */
    private static function document_metadata( $html ) {
        $title = '';
        if ( preg_match( '#<title\b[^>]*>(.*?)</title>#is', (string) $html, $match ) ) {
            $title = trim( html_entity_decode( wp_strip_all_tags( $match[1] ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
        }

        $description = '';
        if ( preg_match_all( '#<meta\b[^>]*>#i', (string) $html, $tags ) ) {
            foreach ( $tags[0] as $tag ) {
                $attrs = self::html_attributes( $tag );
                $name  = strtolower( (string) ( $attrs['name'] ?? $attrs['property'] ?? '' ) );
                if ( 'description' === $name && isset( $attrs['content'] ) ) {
                    $description = trim( html_entity_decode( $attrs['content'], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
                    break;
                }
            }
        }

        return array(
            'title'            => $title,
            'meta_description' => $description,
        );
    }

    /** Parse the simple quoted/unquoted attributes used by meta tags. */
    private static function html_attributes( $tag ) {
        $attrs = array();
        if ( preg_match_all( '/([:\w-]+)\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))/u', (string) $tag, $matches, PREG_SET_ORDER ) ) {
            foreach ( $matches as $match ) {
                $value = '' !== $match[2] ? $match[2] : ( '' !== $match[3] ? $match[3] : $match[4] );
                $attrs[ strtolower( $match[1] ) ] = $value;
            }
        }
        return $attrs;
    }

    /** Clone a selected DOM node, remove chrome, and return its inner HTML. */
    private static function clean_node_html( $node ) {
        $clean = new \DOMDocument();
        $root  = $clean->importNode( $node, true );
        $clean->appendChild( $root );
        $xpath = new \DOMXPath( $clean );
        $remove = $xpath->query( './/script | .//style | .//nav | .//header | .//footer | .//aside | .//form | .//noscript | .//svg | .//template', $root );
        $list   = array();
        if ( $remove ) {
            foreach ( $remove as $item ) {
                $list[] = $item;
            }
        }
        foreach ( $list as $item ) {
            if ( $item->parentNode ) {
                $item->parentNode->removeChild( $item );
            }
        }

        $html = '';
        foreach ( $root->childNodes as $child ) {
            $html .= $clean->saveHTML( $child );
        }
        return $html;
    }

    /** Empty fallback when Answer Density is unavailable. */
    private static function empty_density( $word_count ) {
        return array(
            'score_version'         => null,
            'word_count'           => (int) $word_count,
            'question_headings'    => 0,
            'direct_answers'       => 0,
            'buried_answers'       => 0,
            'unanswered_answers'   => 0,
            'dismissed_issues'     => 0,
            'answer_density_score' => 0,
            'applicable'           => false,
            'issues'               => array(),
            'above_fold_ratio'     => 0,
        );
    }

    /** Unicode-safe text length with a PHP fallback. */
    private static function text_length( $text ) {
        return function_exists( 'mb_strlen' ) ? mb_strlen( (string) $text ) : strlen( (string) $text );
    }
}
