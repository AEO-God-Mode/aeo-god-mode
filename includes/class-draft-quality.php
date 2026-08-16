<?php
/**
 * Shared quality contract for generated WordPress drafts.
 *
 * @package AISEOGodMode
 */

namespace AISEOGodMode;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Evaluates a generated draft with the same engines used in the editor.
 */
class DraftQuality {

    /** Contract version persisted with each evaluation. */
    const CONTRACT_VERSION = 4;

    /** Post-meta key containing the latest evaluation. */
    const META_KEY = '_asgm_draft_quality';

    /** Version of the content/metadata/schema fingerprint. */
    const FINGERPRINT_VERSION = 1;

    /** Minimum sidebar AEO score a generated draft should reach unaided. */
    const MIN_AEO_SCORE = RenderedPageEvaluator::MIN_AEO_SCORE;

    /** Minimum score when the post contains question-shaped headings. */
    const MIN_ANSWER_DENSITY = RenderedPageEvaluator::MIN_ANSWER_DENSITY;

    /** Re-run a generated draft's contract after an editor save. */
    public static function init() {
        add_action( 'save_post', array( __CLASS__, 'refresh_on_save' ), 30, 3 );
    }

    /**
     * Refresh a persisted report after its draft content changes.
     *
     * @param int      $post_id Post ID.
     * @param \WP_Post $post    Post object.
     * @param bool     $update  Whether this is an update.
     */
    public static function refresh_on_save( $post_id, $post, $update ) {
        if ( ! $update || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
            return;
        }
        if ( ! $post || ! get_post_meta( $post_id, '_asgm_topic_draft', true ) ) {
            return;
        }
        if ( in_array( $post->post_status, array( 'trash', 'auto-draft', 'inherit' ), true ) ) {
            return;
        }

        $previous = get_post_meta( $post_id, self::META_KEY, true );
        if ( ! is_array( $previous ) ) {
            return;
        }

        $expectations               = (array) ( $previous['expectations'] ?? array() );
        $expectations['source']     = (string) ( $previous['source'] ?? 'topical_map' );
        $expectations['provenance'] = (array) ( $previous['provenance'] ?? array() );
        self::evaluate( $post_id, $expectations, true );
    }

    /**
     * Evaluate one draft.
     *
     * @param int   $post_id      WordPress post ID.
     * @param array $expectations Generator expectations (length, format, links).
     * @param bool  $persist      Whether to persist the report.
     * @return array|\WP_Error
     */
    public static function evaluate( $post_id, $expectations = array(), $persist = true ) {
        $post = get_post( $post_id );
        if ( ! $post ) {
            return new \WP_Error( 'not_found', __( 'Draft not found.', 'aeo-god-mode' ) );
        }

        $provenance   = self::normalize_provenance( (array) ( $expectations['provenance'] ?? array() ) );
        $source       = sanitize_key( (string) ( $expectations['source'] ?? 'generated' ) );
        $expectations = self::normalize_expectations( $expectations );
        $checks       = array();
        $plain        = wp_strip_all_tags( (string) $post->post_content );
        $word_count   = str_word_count( $plain );
        $metadata     = class_exists( __NAMESPACE__ . '\\MetadataWriter' )
            ? MetadataWriter::read( $post_id )
            : array( 'meta_title' => '', 'meta_description' => '' );
        $meta_title       = trim( (string) ( $metadata['meta_title'] ?? '' ) );
        $meta_description = trim( (string) ( $metadata['meta_description'] ?? '' ) );

        // Render once with the correct singular-post globals, then give that
        // immutable snapshot to every scorer in this evaluation.
        $rendered_content = ContentGaps::render_post_snapshot( $post );
        $gaps = ( new ContentGaps() )->analyze_post( $post, $rendered_content );

        $rendered_analysis = is_array( $gaps['rendered_analysis'] ?? null )
            ? $gaps['rendered_analysis']
            : null;
        $density = is_array( $rendered_analysis['answer_density'] ?? null )
            ? $rendered_analysis['answer_density']
            : ( class_exists( __NAMESPACE__ . '\\Answer_Density' )
                ? Answer_Density::scan_post( $post_id, $rendered_content )
                : array() );

        if ( class_exists( __NAMESPACE__ . '\\RenderedPageEvaluator' ) ) {
            if ( ! is_array( $rendered_analysis ) ) {
                $schema_data = class_exists( __NAMESPACE__ . '\\Schema' )
                    ? ( new Schema() )->get_for_post( $post_id )
                    : array( 'schemas' => array() );
                $rendered_analysis = RenderedPageEvaluator::evaluate(
                    array(
                        'url'              => get_permalink( $post ),
                        'content_html'     => $rendered_content,
                        'title'            => '' !== $meta_title ? $meta_title : get_the_title( $post ),
                        'meta_description' => $meta_description,
                        'schemas'          => (array) ( $schema_data['schemas'] ?? array() ),
                        'page_kind'        => 'editorial',
                        'answer_density'   => $density,
                    ),
                    array(
                        'profile'                  => 'generated_draft',
                        'answerability_eligible'   => true,
                        'content_depth_eligible'   => true,
                        'require_reliable_content' => false,
                    )
                );
            }
        }
        $aeo_score = is_array( $rendered_analysis ) && isset( $rendered_analysis['scores']['aeo'] )
            ? (int) $rendered_analysis['scores']['aeo']
            : max( 0, 100 - (int) ( $gaps['gap_score'] ?? 0 ) );
        $shared_ready = ! is_array( $rendered_analysis ) || ! empty( $rendered_analysis['machine_ready'] );
        $sidebar_ready = $aeo_score >= self::MIN_AEO_SCORE;
        self::add_check(
            $checks,
            'sidebar_aeo',
            __( 'Editor AEO score', 'aeo-god-mode' ),
            $sidebar_ready ? 'pass' : 'fail',
            sprintf(
                /* translators: 1: score, 2: target score */
                __( '%1$d/100 (target: %2$d+; shared rendered-page evaluator).', 'aeo-god-mode' ),
                $aeo_score,
                self::MIN_AEO_SCORE
            ),
            true
        );

        $schema_quality = self::shared_check( $rendered_analysis, 'schema_errors' );
        if ( is_array( $schema_quality ) ) {
            $schema_status = (string) ( $schema_quality['status'] ?? 'not_applicable' );
            self::add_check(
                $checks,
                'schema_quality',
                __( 'Schema quality', 'aeo-god-mode' ),
                in_array( $schema_status, array( 'pass', 'fail' ), true ) ? $schema_status : 'not_applicable',
                (string) ( $schema_quality['message'] ?? __( 'Schema quality could not be evaluated.', 'aeo-god-mode' ) ),
                true,
                array( 'messages' => (array) ( $schema_quality['details']['messages'] ?? array() ) )
            );
        }
		$schema_presence = self::shared_check( $rendered_analysis, 'no_schema' );
		$schema_presence_status = is_array( $schema_presence )
			? (string) ( $schema_presence['status'] ?? 'unknown' )
			: 'unknown';
		$schema_quality_status = is_array( $schema_quality )
			? (string) ( $schema_quality['status'] ?? 'not_applicable' )
			: 'not_applicable';
		$schema_verifiable = in_array( $schema_presence_status, array( 'pass', 'fail' ), true )
			&& in_array( $schema_quality_status, array( 'pass', 'fail' ), true );
		if ( ! $schema_verifiable ) {
			self::add_check(
				$checks,
				'schema_verification',
				__( 'Rendered schema verification', 'aeo-god-mode' ),
				'fail',
				__( 'Schema is expected from another provider, but its rendered JSON-LD was not available to this draft preflight. Preview the public page and verify schema presence and quality before publishing.', 'aeo-god-mode' ),
				false
			);
		}
        $question_count = (int) ( $density['question_headings'] ?? 0 );
        $density_score  = $question_count > 0 ? (int) ( $density['answer_density_score'] ?? 0 ) : null;
        if ( null === $density_score ) {
            self::add_check(
                $checks,
                'answer_density',
                __( 'Answer density', 'aeo-god-mode' ),
                'not_applicable',
                __( 'No question-shaped headings; this score is not applicable.', 'aeo-god-mode' )
            );
        } else {
            $unanswered = array_key_exists( 'unanswered_answers', $density )
                ? (int) $density['unanswered_answers']
                : count( array_filter( (array) ( $density['issues'] ?? array() ), function ( $issue ) {
                    return 'no_direct_answer' === ( $issue['issue'] ?? '' );
                } ) );
            $density_ok = $density_score >= self::MIN_ANSWER_DENSITY && 0 === $unanswered;
            self::add_check(
                $checks,
                'answer_density',
                __( 'Answer density', 'aeo-god-mode' ),
                $density_ok ? 'pass' : 'fail',
                sprintf(
                    /* translators: 1: score, 2: target, 3: direct answers, 4: headings */
                    __( '%1$d/100 (target: %2$d+); %3$d of %4$d headings lead with a direct answer.', 'aeo-god-mode' ),
                    $density_score,
                    self::MIN_ANSWER_DENSITY,
                    (int) ( $density['direct_answers'] ?? 0 ),
                    $question_count
                ),
                true
            );
        }

        $length_target = 'long' === $expectations['length']
            ? array( 1900, 2400 )
            : array( 1100, 1400 );
        $length_limit = 'long' === $expectations['length']
            ? array( 1700, 2700 )
            : array( 900, 1600 );
        $length_ok      = $word_count >= $length_target[0] && $word_count <= $length_target[1];
        $length_in_tol  = $word_count >= $length_limit[0] && $word_count <= $length_limit[1];
        self::add_check(
            $checks,
            'word_count',
            __( 'Planned depth', 'aeo-god-mode' ),
            $length_ok ? 'pass' : ( $length_in_tol ? 'warn' : 'fail' ),
            sprintf(
                /* translators: 1: actual word count, 2: minimum, 3: maximum */
                __( '%1$d words; expected %2$d–%3$d for this generation mode.', 'aeo-god-mode' ),
                $word_count,
                $length_target[0],
                $length_target[1]
            ),
            true
        );

        $body_nonempty = '' !== trim( $plain );
        self::add_check(
            $checks,
            'body',
            __( 'Article body', 'aeo-god-mode' ),
            $body_nonempty ? 'pass' : 'fail',
            $body_nonempty ? __( 'The draft has a non-empty article body.', 'aeo-god-mode' ) : __( 'The generated article body is empty.', 'aeo-god-mode' ),
            true
        );

        preg_match_all( '/<h2\b[^>]*>/i', (string) $post->post_content, $h2_matches );
        $h2_count = count( $h2_matches[0] );
        self::add_check(
            $checks,
            'h2_structure',
            __( 'Section structure', 'aeo-god-mode' ),
            $h2_count >= 3 && $h2_count <= 8 ? 'pass' : 'fail',
            sprintf( __( '%d H2 sections (expected: 3–8).', 'aeo-god-mode' ), $h2_count ),
            true
        );

        $opener_report = is_array( $rendered_analysis )
            ? (array) ( $rendered_analysis['metrics']['h2_openers'] ?? array( 'checked' => 0, 'issues' => array() ) )
            : self::h2_opener_report( (string) $post->post_content );
        self::add_check(
            $checks,
            'h2_openers',
            __( 'Answer-first section openers', 'aeo-god-mode' ),
            empty( $opener_report['issues'] ) ? 'pass' : 'fail',
            empty( $opener_report['issues'] )
                ? sprintf( __( 'All %d substantive H2 sections open with a direct answer.', 'aeo-god-mode' ), $opener_report['checked'] )
                : sprintf( __( '%d H2 section(s) do not open with a direct answer.', 'aeo-god-mode' ), count( $opener_report['issues'] ) ),
            true,
            array( 'section_issues' => $opener_report['issues'] )
        );

        $opening = is_array( $rendered_analysis )
            ? (array) ( $rendered_analysis['metrics']['opening_answer'] ?? array( 'direct' => false, 'first_sentence' => '' ) )
            : self::opening_answer_report( (string) $post->post_content );
        self::add_check(
            $checks,
            'opening_answer',
            __( 'Main-query opening answer', 'aeo-god-mode' ),
            $opening['direct'] ? 'pass' : 'fail',
            $opening['direct']
                ? __( 'The article opens with a standalone direct answer.', 'aeo-god-mode' )
                : __( 'Lead with a standalone answer before the first section heading.', 'aeo-god-mode' ),
            true,
            array( 'first_sentence' => $opening['first_sentence'] )
        );

        $unsafe_html = (bool) preg_match( '/<(?:script|style|iframe|form)\b/i', (string) $post->post_content );
        self::add_check(
            $checks,
            'safe_html',
            __( 'Safe article HTML', 'aeo-god-mode' ),
            $unsafe_html ? 'fail' : 'pass',
            $unsafe_html ? __( 'The body contains a disallowed script, style, iframe or form tag.', 'aeo-god-mode' ) : __( 'No disallowed executable or embedded HTML is present.', 'aeo-god-mode' ),
            true
        );

        $has_placeholder = (bool) preg_match( '/\[(?:YOUR\s+DETAIL|ADD\s+(?:FACT|DETAIL)|INSERT[^\]]*)\]|\bTODO\b/i', (string) $post->post_content );
        self::add_check(
            $checks,
            'no_placeholders',
            __( 'No publishing placeholders', 'aeo-god-mode' ),
            $has_placeholder ? 'fail' : 'pass',
            $has_placeholder ? __( 'Remove unresolved placeholder text.', 'aeo-god-mode' ) : __( 'No unresolved publishing placeholders were found.', 'aeo-god-mode' ),
            true
        );

        $has_h1 = (bool) preg_match( '/<h1\b/i', (string) $post->post_content );
        self::add_check(
            $checks,
            'no_h1',
            __( 'WordPress heading structure', 'aeo-god-mode' ),
            $has_h1 ? 'fail' : 'pass',
            $has_h1
                ? __( 'Remove the H1 from the body; WordPress renders the post title as H1.', 'aeo-god-mode' )
                : __( 'No duplicate H1 in the article body.', 'aeo-god-mode' ),
            true
        );

        $title_len  = self::text_length( $meta_title );
        self::add_check(
            $checks,
            'generated_meta_title',
            __( 'Generated metadata title', 'aeo-god-mode' ),
            $title_len > 0 && $title_len <= 60 ? 'pass' : 'fail',
            $title_len > 0
                ? sprintf( __( '%d characters (generation contract maximum: 60).', 'aeo-god-mode' ), $title_len )
                : __( 'No generated metadata title was saved.', 'aeo-god-mode' ),
            true
        );

        $description_len  = self::text_length( $meta_description );
        $description_ok   = $description_len >= 145 && $description_len <= 158;
        $description_safe = $description_len >= 120 && $description_len <= 160;
        self::add_check(
            $checks,
            'generated_meta_description',
            __( 'Generated metadata description', 'aeo-god-mode' ),
            $description_ok ? 'pass' : ( $description_safe ? 'warn' : 'fail' ),
            $description_len > 0
                ? sprintf( __( '%d characters (generation target: 145–158; accepted: 120–160).', 'aeo-god-mode' ), $description_len )
                : __( 'No generated metadata description was saved.', 'aeo-god-mode' ),
            true
        );

        $faq_parse = class_exists( __NAMESPACE__ . '\\FaqParser' )
            ? FaqParser::parse_aeogm( (string) $post->post_content )
            : array( 'pairs' => array(), 'wrapper_balanced' => false, 'item_open_count' => 0, 'item_close_count' => 0 );
        $faq_count = count( (array) $faq_parse['pairs'] );
        $faq_ok    = $faq_count >= $expectations['faq_min']
            && $faq_count <= $expectations['faq_max']
            && ! empty( $faq_parse['wrapper_balanced'] )
            && $faq_count === (int) $faq_parse['item_open_count'];
        self::add_check(
            $checks,
            'faq_contract',
            __( 'FAQ contract', 'aeo-god-mode' ),
            $faq_ok ? 'pass' : 'fail',
            sprintf(
                /* translators: 1: FAQ count, 2: minimum, 3: maximum */
                __( '%1$d complete FAQ items; expected %2$d–%3$d inside one balanced wrapper.', 'aeo-god-mode' ),
                $faq_count,
                $expectations['faq_min'],
                $expectations['faq_max']
            ),
            true
        );

        $missing_links     = array();
        $duplicate_links   = array();
        $anchor_mismatches = array();
        foreach ( $expectations['internal_urls'] as $url ) {
            $occurrences = self::url_occurrences( (string) $post->post_content, $url );
            if ( 0 === $occurrences ) {
                $missing_links[] = $url;
            } elseif ( $occurrences > 1 ) {
                $duplicate_links[] = $url;
            }
        }
        foreach ( $expectations['internal_links'] as $planned_link ) {
            $expected_anchor = self::normalize_anchor_text( (string) ( $planned_link['anchor'] ?? '' ) );
            if ( '' === $expected_anchor || in_array( $planned_link['url'], $missing_links, true ) ) {
                continue;
            }

            $actual_anchors = self::anchor_texts_for_url( (string) $post->post_content, $planned_link['url'] );
            if ( ! in_array( $expected_anchor, $actual_anchors, true ) ) {
                $anchor_mismatches[] = array(
                    'url'      => $planned_link['url'],
                    'expected' => $planned_link['anchor'],
                    'actual'   => $actual_anchors,
                );
            }
        }
        $link_status = ( ! empty( $missing_links ) || ! empty( $anchor_mismatches ) )
            ? 'fail'
            : ( ! empty( $duplicate_links ) ? 'warn' : 'pass' );
        self::add_check(
            $checks,
            'planned_links',
            __( 'Planned internal links', 'aeo-god-mode' ),
            $link_status,
            ! empty( $missing_links )
                ? sprintf( __( '%d planned link(s) are missing.', 'aeo-god-mode' ), count( $missing_links ) )
                : ( ! empty( $anchor_mismatches )
                    ? sprintf( __( '%d planned link(s) use different anchor text.', 'aeo-god-mode' ), count( $anchor_mismatches ) )
                    : ( empty( $duplicate_links )
                        ? sprintf( __( 'All %d planned links and anchors are present exactly once.', 'aeo-god-mode' ), count( $expectations['internal_urls'] ) )
                        : sprintf( __( '%d planned link(s) appear more than once.', 'aeo-god-mode' ), count( $duplicate_links ) ) ) ),
            true,
            array(
                'missing_urls'      => $missing_links,
                'duplicate_urls'    => $duplicate_links,
                'anchor_mismatches' => $anchor_mismatches,
            )
        );

        self::add_format_check( $checks, $post, $expectations['format'], $gaps, $faq_count );

        $has_image = ! empty( $gaps['details']['has_featured'] ) || ! empty( $gaps['details']['image_count'] );
        self::add_check(
            $checks,
            'editorial_image',
            __( 'Editorial image', 'aeo-god-mode' ),
            $has_image ? 'pass' : 'manual',
            $has_image
                ? __( 'A featured or inline image is available to Article schema.', 'aeo-god-mode' )
                : __( 'Choose a relevant featured image before publishing; this adds Google’s recommended Article image property.', 'aeo-god-mode' )
        );

        $author_name = get_the_author_meta( 'display_name', $post->post_author );
        $author_bio  = get_the_author_meta( 'description', $post->post_author );
        $author_job  = get_user_meta( $post->post_author, '_asgm_eeat_job_title', true );
        $author_ok   = self::text_length( $author_name ) > 2 && ( $author_bio || $author_job );
        self::add_check(
            $checks,
            'author_review',
            __( 'Author evidence', 'aeo-god-mode' ),
            $author_ok ? 'pass' : 'manual',
            $author_ok
                ? __( 'The assigned author has a usable name and profile evidence.', 'aeo-god-mode' )
                : __( 'Assign an expert author and complete their bio or job title before publishing.', 'aeo-god-mode' )
        );

        self::add_check(
            $checks,
            'fact_review',
            __( 'Facts and first-hand evidence', 'aeo-god-mode' ),
            'manual',
            __( 'Verify every claim and add first-hand examples, data or authoritative sources where the topic supports them.', 'aeo-god-mode' )
        );

        $citability = null;
        if ( class_exists( __NAMESPACE__ . '\\CitabilityScore' ) ) {
            $citability = ( new CitabilityScore() )->score_post( $post_id, true, false );
        }

        $citability_content = is_array( $citability )
            ? (int) ( $citability['dimensions']['content']['percent'] ?? 0 )
            : null;
        if ( null !== $citability_content ) {
            self::add_check(
                $checks,
                'citability_content',
                __( 'Citability content signals', 'aeo-god-mode' ),
                $citability_content >= 80 ? 'pass' : 'fail',
                sprintf( __( '%d%% of content-controlled citability points (target: 80%%+).', 'aeo-god-mode' ), $citability_content ),
                true
            );
        }

        $blocking = array_values( array_filter( $checks, function ( $check ) {
            return 'fail' === $check['status'];
        } ) );
        $warnings = array_values( array_filter( $checks, function ( $check ) {
            return 'warn' === $check['status'];
        } ) );
        $manual = array_values( array_filter( $checks, function ( $check ) {
            return 'manual' === $check['status'];
        } ) );

        $machine_ready = empty( $blocking ) && $shared_ready;
        $result = array(
            'contract_version'  => self::CONTRACT_VERSION,
            'contract_scope'    => 'full_generated_draft',
            'post_id'           => (int) $post_id,
            'source'            => $source,
            'evaluated_at'      => current_time( 'mysql' ),
            'fingerprint_version' => self::FINGERPRINT_VERSION,
            'content_hash'      => self::current_content_hash( $post_id ),
            'machine_ready'     => $machine_ready,
            'status'            => $machine_ready ? ( empty( $manual ) ? 'ready' : 'human_review' ) : 'needs_work',
            'scores'            => array(
                'aeo'            => $aeo_score,
                'answer_density' => $density_score,
                'citability'     => is_array( $citability ) ? (int) ( $citability['score'] ?? 0 ) : null,
                'citability_content' => $citability_content,
            ),
            'thresholds'        => array(
                'aeo'            => self::MIN_AEO_SCORE,
                'answer_density' => self::MIN_ANSWER_DENSITY,
            ),
            'checks'            => $checks,
            'blocking_failures' => $blocking,
            'warnings'          => $warnings,
            'manual_checks'     => $manual,
            'answer_density'    => $density,
            'rendered_analysis' => $rendered_analysis,
            'citability'        => $citability,
            'sidebar_analysis'  => $gaps,
            'expectations'      => $expectations,
            'provenance'        => $provenance,
            'evaluator_versions'=> array(
                'content_gaps'   => ContentGaps::SCORE_VERSION,
                'rendered_page'  => RenderedPageEvaluator::SCORE_VERSION,
                'answer_density' => Answer_Density::SCORE_VERSION,
                'schema_quality' => Validator::SCORE_VERSION,
                'citability'     => defined( __NAMESPACE__ . '\CitabilityScore::SCORE_VERSION' ) ? CitabilityScore::SCORE_VERSION : null,
            ),
        );

        if ( $persist ) {
            update_post_meta( $post_id, self::META_KEY, $result );
            delete_post_meta( $post_id, '_asgm_editor_panel_gaps' );
        }

        return $result;
    }

    /**
     * Whether a persisted draft report uses every current scorer contract.
     *
     * @param mixed $result Stored draft report.
     * @return bool
     */
    public static function is_current_result( $result ) {
        if ( ! is_array( $result )
            || self::CONTRACT_VERSION !== (int) ( $result['contract_version'] ?? 0 )
            || self::FINGERPRINT_VERSION !== (int) ( $result['fingerprint_version'] ?? 0 )
            || ContentGaps::SCORE_VERSION !== (int) ( $result['sidebar_analysis']['score_version'] ?? 0 )
            || RenderedPageEvaluator::SCORE_VERSION !== (int) ( $result['rendered_analysis']['score_version'] ?? 0 )
            || Answer_Density::SCORE_VERSION !== (int) ( $result['answer_density']['score_version'] ?? 0 ) ) {
            return false;
        }

        $schema_known   = ! empty( $result['rendered_analysis']['schema']['known'] );
        $schema_version = $result['rendered_analysis']['schema']['validation']['score_version'] ?? null;
        if ( $schema_known && Validator::SCORE_VERSION !== (int) $schema_version ) {
            return false;
        }
        if ( ! $schema_known && null !== $schema_version && Validator::SCORE_VERSION !== (int) $schema_version ) {
            return false;
        }

        $current_citability = defined( __NAMESPACE__ . '\\CitabilityScore::SCORE_VERSION' )
            ? (int) CitabilityScore::SCORE_VERSION
            : null;
        $stored_citability = $result['evaluator_versions']['citability'] ?? null;
        $stored_citability = null === $stored_citability ? null : (int) $stored_citability;
        if ( $current_citability !== $stored_citability ) {
            return false;
        }

        return true;
    }

    /**
     * Return a current stored report, rebuilding stale scorer contracts.
     *
     * @param int        $post_id Post ID.
     * @param array|null $result  Optional report already read by the caller.
     * @param bool       $persist Persist a rebuilt report.
     * @return array|\WP_Error
     */
    public static function get_current_or_refresh( $post_id, $result = null, $persist = true ) {
        if ( null === $result ) {
            $result = get_post_meta( $post_id, self::META_KEY, true );
        }
        $current_hash = self::current_content_hash( $post_id );
        $stored_hash  = is_array( $result ) ? (string) ( $result['content_hash'] ?? '' ) : '';
        if ( self::is_current_result( $result )
            && '' !== $current_hash
            && '' !== $stored_hash
            && hash_equals( $stored_hash, $current_hash ) ) {
            return $result;
        }

        $expectations = is_array( $result ) ? (array) ( $result['expectations'] ?? array() ) : array();
        if ( is_array( $result ) ) {
            $expectations['source']     = (string) ( $result['source'] ?? 'topical_map' );
            $expectations['provenance'] = (array) ( $result['provenance'] ?? array() );
        }

        return self::evaluate( $post_id, $expectations, $persist );
    }

    /**
     * Fingerprint every deterministic input that can change a draft verdict
     * without firing save_post (metadata and schema fix actions included).
     *
     * @param int $post_id Post ID.
     * @return string
     */
    private static function current_content_hash( $post_id ) {
        $post = get_post( $post_id );
        if ( ! $post ) {
            return '';
        }

        $metadata = class_exists( __NAMESPACE__ . '\MetadataWriter' )
            ? MetadataWriter::read( $post_id )
            : array();
        $schema = class_exists( __NAMESPACE__ . '\Schema' )
            ? ContentGaps::schema_post_snapshot( $post )
            : array();
        $settings = get_option( 'asgm_settings', array() );
        $author_id = (int) $post->post_author;

        return hash(
            'sha256',
            wp_json_encode(
                array(
                    'content'          => (string) $post->post_content,
                    'post_title'       => (string) $post->post_title,
                    'post_name'        => (string) $post->post_name,
                    'post_excerpt'     => (string) $post->post_excerpt,
                    'meta_title'       => (string) ( $metadata['meta_title'] ?? '' ),
                    'description'      => (string) ( $metadata['meta_description'] ?? '' ),
                    'post_author'      => $author_id,
                    'author_name'      => (string) get_the_author_meta( 'display_name', $author_id ),
                    'author_bio'       => (string) get_the_author_meta( 'description', $author_id ),
                    'author_job'       => (string) get_user_meta( $author_id, '_asgm_eeat_job_title', true ),
                    'thumbnail_id'     => (int) get_post_thumbnail_id( $post_id ),
                    'schema'           => (array) ( $schema['schemas'] ?? array() ),
                    'safe_mode'        => ! empty( $settings['safe_mode'] ),
                    'schema_module'    => ! empty( $settings['modules']['schema'] ),
                    'schema_ownership' => (array) get_option( 'asgm_schema_resolutions', array() ),
                    'schema_disabled'  => (bool) get_post_meta( $post_id, '_asgm_disable_schema', true ),
                    'faq_disabled'     => (bool) get_post_meta( $post_id, '_asgm_disable_faq', true ),
                    'howto_disabled'   => (bool) get_post_meta( $post_id, '_asgm_disable_howto', true ),
                )
            )
        );
    }

    /** Normalize and cap caller-controlled expectations. */
    private static function normalize_expectations( $expectations ) {
        $urls = array();
        foreach ( (array) ( $expectations['internal_urls'] ?? array() ) as $url ) {
            $url = esc_url_raw( (string) $url );
            if ( $url ) {
                $urls[] = $url;
            }
        }

        $links = array();
        foreach ( (array) ( $expectations['internal_links'] ?? array() ) as $link ) {
            if ( ! is_array( $link ) ) {
                continue;
            }
            $url    = esc_url_raw( (string) ( $link['url'] ?? '' ) );
            $anchor = sanitize_text_field( (string) ( $link['anchor'] ?? '' ) );
            if ( ! $url ) {
                continue;
            }
            $urls[]        = $url;
            $links[ $url ] = array(
                'url'    => $url,
                'anchor' => $anchor,
            );
        }

        return array(
            'length'         => 'long' === ( $expectations['length'] ?? '' ) ? 'long' : 'standard',
            'format'         => sanitize_key( (string) ( $expectations['format'] ?? 'guide' ) ),
            'faq_min'        => max( 2, min( 8, (int) ( $expectations['faq_min'] ?? 4 ) ) ),
            'faq_max'        => max( 2, min( 8, (int) ( $expectations['faq_max'] ?? 6 ) ) ),
            'internal_urls'  => array_values( array_unique( $urls ) ),
            'internal_links' => array_values( $links ),
        );
    }

    /** Keep provenance useful for audits without accepting arbitrary data. */
    private static function normalize_provenance( $provenance ) {
        $secondary_keywords = array();
        foreach ( array_slice( (array) ( $provenance['secondary_keywords'] ?? array() ), 0, 12 ) as $keyword ) {
            $item = is_array( $keyword ) ? $keyword : array( 'keyword' => $keyword );
            $text = sanitize_text_field( (string) ( $item['keyword'] ?? '' ) );
            if ( '' === $text ) {
                continue;
            }
            $secondary_keywords[] = array(
                'keyword' => $text,
                'volume'  => max( 0, (int) ( $item['volume'] ?? 0 ) ),
                'source'  => sanitize_key( (string) ( $item['source'] ?? '' ) ),
            );
        }

        return array(
            'source'            => sanitize_key( (string) ( $provenance['source'] ?? '' ) ),
            'map_item_id'       => absint( $provenance['map_item_id'] ?? 0 ),
            'model'             => sanitize_text_field( (string) ( $provenance['model'] ?? '' ) ),
            'prompt_version'    => sanitize_text_field( (string) ( $provenance['prompt_version'] ?? '' ) ),
            'request_id'        => sanitize_text_field( (string) ( $provenance['request_id'] ?? '' ) ),
            'generated_at'      => sanitize_text_field( (string) ( $provenance['generated_at'] ?? '' ) ),
            'planned_slug'      => sanitize_title( (string) ( $provenance['planned_slug'] ?? '' ) ),
            'planned_url'       => esc_url_raw( (string) ( $provenance['planned_url'] ?? '' ) ),
            'target_provenance' => self::normalize_target_provenance( $provenance['target_provenance'] ?? null ),
            'secondary_keywords' => $secondary_keywords,
            'scorers'           => array(
                'page_health'   => ContentGaps::SCORE_VERSION,
                'rendered_page' => defined( __NAMESPACE__ . '\\RenderedPageEvaluator::SCORE_VERSION' ) ? RenderedPageEvaluator::SCORE_VERSION : null,
                'answer_density' => defined( __NAMESPACE__ . '\\Answer_Density::SCORE_VERSION' ) ? Answer_Density::SCORE_VERSION : null,
                'citability'    => defined( __NAMESPACE__ . '\\CitabilityScore::SCORE_VERSION' ) ? CitabilityScore::SCORE_VERSION : null,
            ),
        );
    }

    /** Keep the selected GSC/market target and its bounded evidence auditable. */
    private static function normalize_target_provenance( $target ) {
        if ( ! is_array( $target ) ) {
            return null;
        }

        $evidence = is_array( $target['evidence'] ?? null ) ? $target['evidence'] : null;
        if ( is_array( $evidence ) ) {
            $rows = array();
            foreach ( array_slice( (array) ( $evidence['rows'] ?? array() ), 0, 10 ) as $row ) {
                if ( ! is_array( $row ) ) {
                    continue;
                }
                $rows[] = array(
                    'page'        => esc_url_raw( (string) ( $row['page'] ?? '' ) ),
                    'impressions' => max( 0, (int) ( $row['impressions'] ?? 0 ) ),
                    'clicks'      => max( 0, (int) ( $row['clicks'] ?? 0 ) ),
                    'ctr'         => max( 0, (float) ( $row['ctr'] ?? 0 ) ),
                    'position'    => max( 0, (float) ( $row['position'] ?? 0 ) ),
                );
            }
            $evidence = array(
                'query'       => sanitize_text_field( (string) ( $evidence['query'] ?? '' ) ),
                'keyword'     => sanitize_text_field( (string) ( $evidence['keyword'] ?? '' ) ),
                'page'        => esc_url_raw( (string) ( $evidence['page'] ?? '' ) ),
                'impressions' => max( 0, (int) ( $evidence['impressions'] ?? 0 ) ),
                'clicks'      => max( 0, (int) ( $evidence['clicks'] ?? 0 ) ),
                'ctr'         => max( 0, (float) ( $evidence['ctr'] ?? 0 ) ),
                'position'    => max( 0, (float) ( $evidence['position'] ?? 0 ) ),
                'volume'      => max( 0, (int) ( $evidence['volume'] ?? 0 ) ),
                'intent'      => sanitize_key( (string) ( $evidence['intent'] ?? '' ) ),
                'rows'        => $rows,
            );
        }

        return array(
            'driver'      => sanitize_key( (string) ( $target['driver'] ?? '' ) ),
            'target'      => sanitize_text_field( (string) ( $target['target'] ?? '' ) ),
            'source'      => sanitize_key( (string) ( $target['source'] ?? '' ) ),
            'exact_match' => ! empty( $target['exact_match'] ),
            'evidence'    => $evidence,
        );
    }

    /** Find one check in a shared rendered-page report. */
    private static function shared_check( $analysis, $id ) {
        foreach ( (array) ( $analysis['checks'] ?? array() ) as $check ) {
            if ( $id === (string) ( $check['id'] ?? '' ) ) {
                return $check;
            }
        }
        return null;
    }

    /** Add a format-specific deterministic check. */
    private static function add_format_check( &$checks, $post, $format, $gaps, $faq_count ) {
        $content = (string) $post->post_content;
        $status  = 'pass';
        $message = __( 'The generated structure matches the planned format.', 'aeo-god-mode' );

        if ( 'howto_steps' === $format && empty( $gaps['details']['has_howto_schema'] ) ) {
            $status  = 'fail';
            $message = __( 'A how-to draft needs at least five extractable steps and automatic HowTo schema.', 'aeo-god-mode' );
        } elseif ( 'comparison_table' === $format ) {
            preg_match_all( '/<th\b/i', $content, $table_headers );
            preg_match_all( '/<tr\b/i', $content, $table_rows );
            if ( count( $table_headers[0] ) < 2 || count( $table_rows[0] ) < 3 ) {
                $status  = 'fail';
                $message = __( 'A comparison draft needs a table header and at least two comparison rows.', 'aeo-god-mode' );
            }
        } elseif ( 'faq_page' === $format && $faq_count < 4 ) {
            $status  = 'fail';
            $message = __( 'An FAQ page needs at least four valid FAQ shortcode items.', 'aeo-god-mode' );
        } elseif ( 'listicle' === $format ) {
            preg_match_all( '/<li\b/i', $content, $list_items );
            if ( count( $list_items[0] ) < 5 ) {
                $status  = 'fail';
                $message = __( 'A listicle needs at least five real list items.', 'aeo-god-mode' );
            }
        }

        self::add_check(
            $checks,
            'planned_format',
            __( 'Planned content format', 'aeo-god-mode' ),
            $status,
            $message,
            true,
            array( 'format' => $format )
        );
    }

    /** Classify the opening sentence beneath every substantive H2. */
    private static function h2_opener_report( $content ) {
        $issues = array();
        $checked = 0;
        if ( ! class_exists( __NAMESPACE__ . '\\Answer_Density' ) ) {
            return array( 'checked' => 0, 'issues' => array() );
        }

        preg_match_all( '/<h2\b[^>]*>(.*?)<\/h2>/si', $content, $headings, PREG_SET_ORDER | PREG_OFFSET_CAPTURE );
        foreach ( $headings as $heading ) {
            $label = trim( wp_strip_all_tags( $heading[1][0] ) );
            if ( preg_match( '/^(?:faq|frequently asked questions|conclusion|summary|final thoughts|key takeaways|resources|references|sources|further reading|related(?: posts| articles| reading)?)\s*:?$/i', $label ) ) {
                continue;
            }

            $checked++;
            $end  = (int) $heading[0][1] + strlen( $heading[0][0] );
            $body = Answer_Density::extract_after_heading( $content, $end, 2 );
            $test = Answer_Density::classify_answer( $body );
            if ( 'direct' !== ( $test['classification'] ?? '' ) ) {
                $issues[] = array(
                    'heading'        => $label,
                    'classification' => (string) ( $test['classification'] ?? 'no_answer' ),
                    'first_sentence' => (string) ( $test['first_sentence'] ?? '' ),
                );
            }
        }
        return array( 'checked' => $checked, 'issues' => $issues );
    }

    /** Classify the prose before the first H2 as the main-query answer. */
    private static function opening_answer_report( $content ) {
        $intro = $content;
        if ( preg_match( '/<h2\b/i', $content, $match, PREG_OFFSET_CAPTURE ) ) {
            $intro = substr( $content, 0, (int) $match[0][1] );
        }
        if ( class_exists( __NAMESPACE__ . '\\Answer_Density' ) ) {
            $test = Answer_Density::classify_answer( $intro );
            return array(
                'direct'         => 'direct' === ( $test['classification'] ?? '' ),
                'first_sentence' => (string) ( $test['first_sentence'] ?? '' ),
            );
        }
        return array( 'direct' => '' !== trim( wp_strip_all_tags( $intro ) ), 'first_sentence' => '' );
    }

    /** Append a stable check object. */
    private static function add_check( &$checks, $id, $label, $status, $message, $machine_fixable = false, $extra = array() ) {
        $checks[] = array_merge(
            array(
                'id'              => $id,
                'label'           => $label,
                'status'          => $status,
                'message'         => $message,
                'machine_fixable' => (bool) $machine_fixable,
            ),
            $extra
        );
    }

    /** Unicode-safe text length with a PHP fallback. */
    private static function text_length( $text ) {
        return function_exists( 'mb_strlen' ) ? mb_strlen( (string) $text ) : strlen( (string) $text );
    }

    /** Count a planned URL while tolerating a trailing slash difference. */
    private static function url_occurrences( $content, $url ) {
        $content = html_entity_decode( (string) $content, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        $needle  = untrailingslashit( (string) $url );

        if ( '' === $needle ) {
            return 0;
        }

        $pattern = '/href\s*=\s*(["\'])' . preg_quote( $needle, '/' ) . '\/?(?:[#?][^"\']*)?\1/i';
        return preg_match_all( $pattern, $content, $matches );
    }

    /** Return normalized linked text for every anchor pointing at a planned URL. */
    private static function anchor_texts_for_url( $content, $url ) {
        $content = html_entity_decode( (string) $content, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        $needle  = untrailingslashit( (string) $url );
        if ( '' === $needle ) {
            return array();
        }

        $pattern = '/<a\b[^>]*href\s*=\s*(["\'])' . preg_quote( $needle, '/' ) . '\/?(?:[#?][^"\']*)?\1[^>]*>(.*?)<\/a>/is';
        if ( ! preg_match_all( $pattern, $content, $matches ) ) {
            return array();
        }

        return array_values( array_filter( array_map( array( __CLASS__, 'normalize_anchor_text' ), $matches[2] ) ) );
    }

    /** Normalize planned and rendered anchor text for a deterministic comparison. */
    private static function normalize_anchor_text( $text ) {
        $text = html_entity_decode( wp_strip_all_tags( (string) $text ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        $text = preg_replace( '/\s+/u', ' ', trim( $text ) );
        return function_exists( 'mb_strtolower' ) ? mb_strtolower( $text, 'UTF-8' ) : strtolower( $text );
    }
}
