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
    const CONTRACT_VERSION = 6;

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
        if ( $expectations['word_min'] > 0 && $expectations['word_max'] >= $expectations['word_min'] ) {
            $length_target = array( $expectations['word_min'], $expectations['word_max'] );
            $length_limit  = array(
                max( 250, (int) floor( $expectations['word_min'] * 0.85 ) ),
                (int) ceil( $expectations['word_max'] * 1.15 ),
            );
        }
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
        $h2_min   = 3;
        $h2_max   = 8;
        if ( in_array( $expectations['recipe'], array( 'vs', 'alternatives', 'best_x' ), true ) ) {
            $verified_count = count( (array) $expectations['verified_options'] );
            $h2_min = max( 3, $verified_count );
            $h2_max = max( 8, $verified_count + 6 );
        } elseif ( in_array( $expectations['recipe'], array( 'switch', 'walkthrough', 'guide' ), true ) ) {
            $h2_max = 12;
        }
        self::add_check(
            $checks,
            'h2_structure',
            __( 'Section structure', 'aeo-god-mode' ),
            $h2_count >= $h2_min && $h2_count <= $h2_max ? 'pass' : 'fail',
            sprintf(
                /* translators: 1: actual H2 count, 2: minimum, 3: maximum */
                __( '%1$d H2 sections (expected: %2$d–%3$d for this content structure).', 'aeo-god-mode' ),
                $h2_count,
                $h2_min,
                $h2_max
            ),
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
        $faq_count  = count( (array) $faq_parse['pairs'] );
        $custom_faq = self::required_shortcode_by_name( $expectations['required_shortcodes'], 'faq' );
        if ( $custom_faq ) {
            $custom_faq_report = self::shortcode_report( (string) $post->post_content, $custom_faq );
            $faq_count          = (int) $custom_faq_report['complete'];
            $faq_parse          = array(
                'pairs'             => array_fill( 0, $faq_count, array() ),
                'wrapper_balanced'  => ! empty( $custom_faq_report['balanced'] ),
                'item_open_count'   => (int) $custom_faq_report['open'],
                'item_close_count'  => (int) $custom_faq_report['close'],
            );
        }
        $faq_absent = 0 === $faq_count
            && 0 === (int) ( $faq_parse['item_open_count'] ?? 0 )
            && 0 === (int) ( $faq_parse['item_close_count'] ?? 0 );
        $faq_required_min = $custom_faq ? max( 1, (int) ( $custom_faq['min_occurrences'] ?? 1 ) ) : $expectations['faq_min'];
        $faq_required_max = $custom_faq ? 20 : $expectations['faq_max'];
        $faq_ok     = ( 0 === $faq_required_min && $faq_absent )
            || ( $faq_count >= $faq_required_min
                && $faq_count <= $faq_required_max
                && ! empty( $faq_parse['wrapper_balanced'] )
                && $faq_count === (int) $faq_parse['item_open_count']
                && $faq_count === (int) $faq_parse['item_close_count'] );
        self::add_check(
            $checks,
            'faq_contract',
            __( 'FAQ contract', 'aeo-god-mode' ),
            $faq_ok ? 'pass' : 'fail',
            ( 0 === $faq_required_min && $faq_absent )
                ? __( 'FAQ is optional for this recipe; no FAQ block was added.', 'aeo-god-mode' )
                : sprintf(
                    /* translators: 1: FAQ count, 2: minimum, 3: maximum */
                    __( '%1$d complete FAQ items; expected %2$d–%3$d inside one balanced wrapper.', 'aeo-god-mode' ),
                    $faq_count,
                    $faq_required_min,
                    $faq_required_max
                ),
            true
        );

        self::add_required_formatting_check( $checks, (string) $post->post_content, $expectations['required_shortcodes'] );

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
        self::add_recipe_checks( $checks, $post, $expectations, $faq_parse, $gaps );

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

        $recipe = sanitize_key( (string) ( $expectations['recipe'] ?? $expectations['content_recipe'] ?? '' ) );
        if ( ! in_array( $recipe, array( 'vs', 'alternatives', 'best_x', 'switch', 'walkthrough', 'guide' ), true ) ) {
            $recipe = '';
        }

        $verified_options = array();
        foreach ( array_slice( (array) ( $expectations['verified_options'] ?? $expectations['recipe_options'] ?? array() ), 0, 20 ) as $option ) {
            if ( is_array( $option ) ) {
                $option = $option['name'] ?? $option['title'] ?? $option['label'] ?? $option['domain'] ?? '';
            }
            $option = sanitize_text_field( (string) $option );
            if ( '' !== $option ) {
                $verified_options[] = $option;
            }
        }

        $evidence_status = sanitize_key( (string) ( $expectations['evidence_status'] ?? '' ) );
        if ( ! in_array( $evidence_status, array( 'ready', 'limited', 'insufficient', 'not_required' ), true ) ) {
            $evidence_status = '';
        }
        $evidence_warnings = array();
        foreach ( array_slice( (array) ( $expectations['evidence_warnings'] ?? array() ), 0, 10 ) as $warning ) {
            $warning = sanitize_text_field( (string) $warning );
            if ( '' !== $warning ) {
                $evidence_warnings[] = $warning;
            }
        }

        $recipe_confidence = (float) ( $expectations['recipe_confidence'] ?? 0 );
        if ( $recipe_confidence > 1 ) {
            $recipe_confidence /= 100;
        }
        $recipe_confidence = max( 0, min( 1, $recipe_confidence ) );
        $faq_min = max( 0, min( 8, (int) ( $expectations['faq_min'] ?? 4 ) ) );
        $faq_max = max( $faq_min, min( 8, (int) ( $expectations['faq_max'] ?? 6 ) ) );
        $word_min = (int) ( $expectations['word_min'] ?? $expectations['target_min'] ?? 0 );
        $word_max = (int) ( $expectations['word_max'] ?? $expectations['target_max'] ?? 0 );
        if ( $word_min <= 0 || $word_max < $word_min ) {
            $word_min = 0;
            $word_max = 0;
        } else {
            $word_min = max( 300, min( 5000, $word_min ) );
            $word_max = max( $word_min, min( 6000, $word_max ) );
        }

        $required_shortcodes = array();
        foreach ( array_slice( (array) ( $expectations['required_shortcodes'] ?? array() ), 0, 20 ) as $shortcode ) {
            if ( ! is_array( $shortcode ) ) {
                continue;
            }
            $name = sanitize_key( (string) ( $shortcode['name'] ?? '' ) );
            if ( '' === $name ) {
                continue;
            }
            $attributes = array();
            foreach ( array_slice( (array) ( $shortcode['required_attributes'] ?? array() ), 0, 10 ) as $attribute ) {
                $attribute = sanitize_key( (string) $attribute );
                if ( '' !== $attribute ) {
                    $attributes[] = $attribute;
                }
            }
            $required_shortcodes[ $name ] = array(
                'name'                => $name,
                'opening_example'     => sanitize_text_field( (string) ( $shortcode['opening_example'] ?? '[' . $name . ']' ) ),
                'closing_example'     => '[/' . $name . ']',
                'required_attributes' => array_values( array_unique( $attributes ) ),
                'min_occurrences'     => max( 1, min( 10, (int) ( $shortcode['min_occurrences'] ?? 1 ) ) ),
            );
        }

        return array(
            'length'         => 'long' === ( $expectations['length'] ?? '' ) ? 'long' : 'standard',
            'format'         => sanitize_key( (string) ( $expectations['format'] ?? 'guide' ) ),
            'recipe'         => $recipe,
            'recipe_confidence' => $recipe_confidence,
            'recipe_reason'  => sanitize_text_field( (string) ( $expectations['recipe_reason'] ?? '' ) ),
            'verified_options' => array_values( array_unique( $verified_options ) ),
            'verified_option_count' => count( array_unique( $verified_options ) ),
            'evidence_status' => $evidence_status,
            'evidence_warnings' => array_values( array_unique( $evidence_warnings ) ),
            'word_min'       => $word_min,
            'word_max'       => $word_max,
            'faq_min'        => $faq_min,
            'faq_max'        => $faq_max,
            'internal_urls'  => array_values( array_unique( $urls ) ),
            'internal_links' => array_values( $links ),
            'required_shortcodes' => array_values( $required_shortcodes ),
        );
    }

    /** Find one required shortcode by canonical name. */
    private static function required_shortcode_by_name( $items, $name ) {
        foreach ( (array) $items as $item ) {
            if ( $name === (string) ( $item['name'] ?? '' ) ) {
                return $item;
            }
        }
        return null;
    }

    /** Count balanced shortcode pairs and required opening attributes. */
    private static function shortcode_report( $content, $rule ) {
        $name  = preg_quote( (string) ( $rule['name'] ?? '' ), '/' );
        $open  = preg_match_all( '/\[' . $name . '\b([^\]]*)\]/i', $content, $opening_matches );
        $close = preg_match_all( '/\[\/' . $name . '\s*\]/i', $content, $unused_closing );
        $attrs = (array) ( $rule['required_attributes'] ?? array() );
        $with_required_attributes = 0;
        foreach ( (array) ( $opening_matches[1] ?? array() ) as $opening_attributes ) {
            $has_all = true;
            foreach ( $attrs as $attribute ) {
                if ( ! preg_match( '/\b' . preg_quote( $attribute, '/' ) . '\s*=\s*(?:"[^"]+"|\'[^\']+\'|[^\s\]]+)/i', (string) $opening_attributes ) ) {
                    $has_all = false;
                    break;
                }
            }
            if ( $has_all ) {
                $with_required_attributes++;
            }
        }
        return array(
            'open'       => (int) $open,
            'close'      => (int) $close,
            'complete'   => min( (int) $open, (int) $close, $with_required_attributes ),
            'balanced'   => (int) $open > 0 && (int) $open === (int) $close && (int) $open === $with_required_attributes,
            'attributes' => $with_required_attributes,
        );
    }

    /** Add one blocking check for the owner's global WordPress format contract. */
    private static function add_required_formatting_check( &$checks, $content, $rules ) {
        $missing = array();
        foreach ( (array) $rules as $rule ) {
            $report = self::shortcode_report( $content, $rule );
            $minimum = max( 1, (int) ( $rule['min_occurrences'] ?? 1 ) );
            if ( empty( $report['balanced'] ) || $report['complete'] < $minimum ) {
                $missing[] = '[' . (string) ( $rule['name'] ?? 'shortcode' ) . ']';
            }
        }
        self::add_check(
            $checks,
            'required_wordpress_formatting',
            __( 'Required WordPress formatting', 'aeo-god-mode' ),
            empty( $rules ) ? 'not_applicable' : ( empty( $missing ) ? 'pass' : 'fail' ),
            empty( $rules )
                ? __( 'No site-wide formatting rules are configured.', 'aeo-god-mode' )
                : ( empty( $missing )
                    ? __( 'Every required shortcode block is present and balanced.', 'aeo-god-mode' )
                    : sprintf( __( 'Missing or incomplete required blocks: %s.', 'aeo-god-mode' ), implode( ', ', $missing ) ) ),
            ! empty( $rules ),
            array( 'missing' => $missing )
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
            $message = __( 'A how-to draft needs at least three extractable steps and automatic HowTo schema.', 'aeo-god-mode' );
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
            if ( count( $list_items[0] ) < 3 ) {
                $status  = 'fail';
                $message = __( 'A listicle needs at least three real list items.', 'aeo-god-mode' );
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

    /**
     * Add the stricter structure contract selected by the automatic content recipe.
     *
     * Older Topical Map rows do not have a recipe. They intentionally keep the
     * legacy format checks above until the planner next refreshes them.
     *
     * @param array    $checks       Check accumulator.
     * @param \WP_Post $post         Draft post.
     * @param array    $expectations Normalized generation expectations.
     * @param array    $faq_parse    Canonical FAQ parser result.
     * @param array    $gaps         Shared rendered-page analysis.
     */
    private static function add_recipe_checks( &$checks, $post, $expectations, $faq_parse, $gaps ) {
        $recipe = (string) ( $expectations['recipe'] ?? '' );
        if ( '' === $recipe ) {
            return;
        }

        $content          = (string) $post->post_content;
        $sections         = self::recipe_sections( $content );
        $verified_options = (array) ( $expectations['verified_options'] ?? array() );
        $table_rows       = preg_match_all( '/<tr\b/i', $content, $unused_rows );
        $table_headers    = preg_match_all( '/<th\b/i', $content, $unused_headers );
        $comparison       = in_array( $recipe, array( 'vs', 'alternatives', 'best_x' ), true );

        self::add_check(
            $checks,
            'content_recipe',
            __( 'Content recipe', 'aeo-god-mode' ),
            'pass',
            sprintf(
                /* translators: %s: content recipe name */
                __( 'Validated as the %s content structure.', 'aeo-god-mode' ),
                str_replace( '_', ' ', $recipe )
            ),
            true,
            array(
                'recipe'     => $recipe,
                'confidence' => (float) ( $expectations['recipe_confidence'] ?? 0 ),
                'reason'     => (string) ( $expectations['recipe_reason'] ?? '' ),
            )
        );

        if ( $comparison ) {
            $evidence_status = (string) ( $expectations['evidence_status'] ?? '' );
            $evidence_check_status = 'ready' === $evidence_status
                ? 'pass'
                : ( 'insufficient' === $evidence_status ? 'fail' : ( 'limited' === $evidence_status ? 'warn' : 'not_applicable' ) );
            self::add_check(
                $checks,
                'recipe_evidence',
                __( 'Comparison evidence', 'aeo-god-mode' ),
                $evidence_check_status,
                'ready' === $evidence_status
                    ? sprintf( __( '%d comparison options are backed by stored evidence.', 'aeo-god-mode' ), count( $verified_options ) )
                    : ( 'limited' === $evidence_status
                        ? __( 'The comparison scope was reduced to match the evidence available.', 'aeo-god-mode' )
                        : ( 'insufficient' === $evidence_status
                            ? __( 'There is not enough verified evidence for a safe comparison draft.', 'aeo-god-mode' )
                            : __( 'No comparison evidence status was returned.', 'aeo-god-mode' ) ) ),
                'not_applicable' !== $evidence_check_status,
                array( 'warnings' => (array) ( $expectations['evidence_warnings'] ?? array() ) )
            );

            if ( in_array( $recipe, array( 'alternatives', 'best_x' ), true ) ) {
                $promised_count = self::recipe_promised_option_count( (string) ( $post->post_title ?? '' ) );
                $verified_count = count( $verified_options );
                $count_status   = null === $promised_count || 0 === $verified_count
                    ? 'not_applicable'
                    : ( $promised_count === $verified_count ? 'pass' : 'fail' );
                self::add_check(
                    $checks,
                    'recipe_promised_option_count',
                    __( 'Promised option count', 'aeo-god-mode' ),
                    $count_status,
                    null === $promised_count
                        ? __( 'The title does not promise a numbered list.', 'aeo-god-mode' )
                        : ( 0 === $verified_count
                            ? __( 'No verified option list was supplied, so the title count cannot be checked.', 'aeo-god-mode' )
                            : sprintf(
                                /* translators: 1: number promised in the title, 2: verified option count */
                                __( 'The title promises %1$d options and the evidence contains %2$d verified options.', 'aeo-god-mode' ),
                                $promised_count,
                                $verified_count
                            ) ),
                    'not_applicable' !== $count_status,
                    array(
                        'promised_count' => $promised_count,
                        'verified_count' => $verified_count,
                    )
                );
            }

            $minimum_rows = max( 3, count( $verified_options ) + 1 );
            $table_ok     = $table_headers >= 2 && $table_rows >= $minimum_rows;
            self::add_check(
                $checks,
                'recipe_comparison_table',
                __( 'Useful comparison table', 'aeo-god-mode' ),
                $table_ok ? 'pass' : 'fail',
                $table_ok
                    ? sprintf( __( 'The comparison table has %d data rows.', 'aeo-god-mode' ), max( 0, $table_rows - 1 ) )
                    : sprintf( __( 'Add a real table header and at least %d option rows.', 'aeo-god-mode' ), $minimum_rows - 1 ),
                true,
                array( 'expected_option_rows' => $minimum_rows - 1 )
            );

            self::add_verified_option_checks( $checks, $sections, $verified_options, $recipe );
        }

        if ( 'switch' === $recipe ) {
            $ordered_steps = self::ordered_step_count( $content );
            $missing       = array();
            if ( $ordered_steps < 3 ) {
                $missing[] = __( 'at least three ordered migration steps', 'aeo-god-mode' );
            }
            if ( ! preg_match( '/\b(?:roll\s*back|rollback|revert|switch back|restore the previous)\b/i', $content ) ) {
                $missing[] = __( 'a rollback path', 'aeo-god-mode' );
            }
            if ( ! preg_match( '/\b(?:verify|verification|confirm (?:it|the change)|test (?:it|the change)|check (?:it|the result)|proof that it worked)\b/i', $content ) ) {
                $missing[] = __( 'a verification step', 'aeo-god-mode' );
            }
            if ( ! preg_match( '/\b(?:who should (?:stay|switch)|stay if|switch if|do not switch|not worth switching|when to switch)\b/i', $content ) ) {
                $missing[] = __( 'a clear stay-or-switch verdict', 'aeo-god-mode' );
            }
            self::add_check(
                $checks,
                'recipe_switch_safety',
                __( 'Safe switching plan', 'aeo-god-mode' ),
                empty( $missing ) ? 'pass' : 'fail',
                empty( $missing )
                    ? __( 'The draft explains who should switch, the migration, verification and rollback.', 'aeo-god-mode' )
                    : sprintf( __( 'The switching guide still needs: %s.', 'aeo-god-mode' ), implode( ', ', $missing ) ),
                true,
                array( 'missing' => $missing )
            );
        }

        if ( 'walkthrough' === $recipe ) {
            $ordered_steps = self::ordered_step_count( $content );
            $step_results  = self::walkthrough_step_result_report( $content );
            $has_howto     = ! empty( $gaps['details']['has_howto_schema'] );
            self::add_check(
                $checks,
                'recipe_walkthrough_steps',
                __( 'Numbered walkthrough', 'aeo-god-mode' ),
                $ordered_steps >= 3 ? 'pass' : 'fail',
                $ordered_steps >= 3
                    ? sprintf( __( '%d ordered, extractable steps were found.', 'aeo-god-mode' ), $ordered_steps )
                    : __( 'A walkthrough needs at least three ordered steps, not a loose paragraph or bullet list.', 'aeo-god-mode' ),
                true,
                array( 'step_count' => $ordered_steps )
            );
            self::add_check(
                $checks,
                'recipe_walkthrough_step_results',
                __( 'Expected result after every step', 'aeo-god-mode' ),
                $ordered_steps >= 3 && $step_results['total'] === $ordered_steps && $step_results['with_result'] === $ordered_steps ? 'pass' : 'fail',
                $ordered_steps >= 3 && $step_results['total'] === $ordered_steps && $step_results['with_result'] === $ordered_steps
                    ? __( 'Every ordered step tells the reader what they should see when it works.', 'aeo-god-mode' )
                    : sprintf(
                        /* translators: 1: steps with an expected result, 2: total ordered steps */
                        __( '%1$d of %2$d ordered steps include an explicit expected result.', 'aeo-god-mode' ),
                        $step_results['with_result'],
                        $ordered_steps
                    ),
                true,
                $step_results
            );
            self::add_check(
                $checks,
                'recipe_walkthrough_schema',
                __( 'Walkthrough HowTo schema', 'aeo-god-mode' ),
                $ordered_steps >= 3 && $has_howto ? 'pass' : 'fail',
                $ordered_steps >= 3 && $has_howto
                    ? __( 'The visible ordered steps produce automatic HowTo schema.', 'aeo-god-mode' )
                    : __( 'HowTo schema is only valid when this walkthrough contains at least three visible, extractable steps.', 'aeo-god-mode' ),
                true
            );
            $has_setup  = (bool) preg_match( '/\b(?:before you start|prerequisite|required first|what you need)\b/i', $content );
            $has_finish = (bool) preg_match( '/\b(?:verify|check your work|expected result|what you should see|troubleshoot|if it does not work)\b/i', $content );
            self::add_check(
                $checks,
                'recipe_walkthrough_completion',
                __( 'Walkthrough setup and result', 'aeo-god-mode' ),
                $has_setup && $has_finish ? 'pass' : 'fail',
                $has_setup && $has_finish
                    ? __( 'The walkthrough states what is needed and how to confirm the result.', 'aeo-god-mode' )
                    : __( 'Add prerequisites and a final check or expected result.', 'aeo-god-mode' ),
                true
            );
        } elseif ( ! empty( $gaps['details']['has_howto_schema'] ) ) {
            self::add_check(
                $checks,
                'recipe_schema_scope',
                __( 'Schema matches the content type', 'aeo-god-mode' ),
                'fail',
                __( 'HowTo schema is reserved for a real step-by-step walkthrough. Remove the step markup or change this page to the walkthrough content type.', 'aeo-god-mode' ),
                true
            );
        }

        if ( 'guide' === $recipe ) {
            $has_example = (bool) preg_match( '/\b(?:for example|worked example|example:|in practice)\b/i', $content );
            $has_pitfall = (bool) preg_match( '/\b(?:common mistake|mistakes to avoid|pitfall|troubleshoot|watch out|avoid this)\b/i', $content );
            $has_action  = self::ordered_step_count( $content ) >= 3
                || (bool) preg_match( '/\b(?:checklist|next steps|how to (?:do|set up|use|create|fix))\b/i', $content );
            self::add_check(
                $checks,
                'recipe_guide_depth',
                __( 'Practical guide depth', 'aeo-god-mode' ),
                $has_example && $has_pitfall && $has_action ? 'pass' : 'fail',
                $has_example && $has_pitfall && $has_action
                    ? __( 'The guide includes an example, a pitfall and practical next steps.', 'aeo-god-mode' )
                    : __( 'A guide needs a real example, a pitfall to avoid and practical next steps.', 'aeo-god-mode' ),
                true
            );
        }

        self::add_repetition_checks( $checks, $sections, $content, $faq_parse );
        self::add_unsupported_superlative_check( $checks, $content );
    }

    /** Require each verified comparison option to have its own useful section. */
    private static function add_verified_option_checks( &$checks, $sections, $verified_options, $recipe ) {
        if ( empty( $verified_options ) ) {
            self::add_check(
                $checks,
                'recipe_verified_options',
                __( 'Verified option sections', 'aeo-god-mode' ),
                'fail',
                __( 'No verified option list was supplied, so this comparison cannot be checked safely.', 'aeo-god-mode' ),
                true,
                array( 'recipe' => $recipe )
            );
            return;
        }

        $missing_sections    = array();
        $missing_limitations = array();
        $missing_verdicts    = array();
        foreach ( $verified_options as $option ) {
            $matched = null;
            foreach ( $sections as $section ) {
                if ( self::option_matches_heading( $option, $section['heading'] ) ) {
                    $matched = $section;
                    break;
                }
            }
            if ( null === $matched ) {
                $missing_sections[] = $option;
                continue;
            }
            if ( ! preg_match( '/\b(?:limitation|drawback|trade-?off|downside|weakness|not ideal|not suitable|falls short|lacks?|avoid if)\b/i', $matched['body'] ) ) {
                $missing_limitations[] = $option;
            }
            if ( ! preg_match( '/\b(?:best for|best if|choose\b.{0,35}\bif|use\b.{0,35}\bif|good fit|ideal for|suited to|works well for|right for|who should choose|verdict|recommended for)\b/is', $matched['body'] ) ) {
                $missing_verdicts[] = $option;
            }
        }

        $sections_ok = empty( $missing_sections );
        self::add_check(
            $checks,
            'recipe_verified_options',
            __( 'One section per verified option', 'aeo-god-mode' ),
            $sections_ok ? 'pass' : 'fail',
            $sections_ok
                ? sprintf( __( 'All %d verified options have their own H2 section.', 'aeo-god-mode' ), count( $verified_options ) )
                : sprintf( __( 'Missing an H2 section for: %s.', 'aeo-god-mode' ), implode( ', ', $missing_sections ) ),
            true,
            array( 'missing_options' => $missing_sections )
        );

        $judgement_ok = empty( $missing_limitations ) && empty( $missing_verdicts ) && $sections_ok;
        self::add_check(
            $checks,
            'recipe_option_judgement',
            __( 'Honest option verdicts', 'aeo-god-mode' ),
            $judgement_ok ? 'pass' : 'fail',
            $judgement_ok
                ? __( 'Every verified option includes a limitation and a clear best-fit verdict.', 'aeo-god-mode' )
                : __( 'Each option needs a meaningful limitation and a clear who-it-is-for verdict.', 'aeo-god-mode' ),
            true,
            array(
                'missing_limitations' => $missing_limitations,
                'missing_verdicts'    => $missing_verdicts,
            )
        );

        if ( in_array( $recipe, array( 'alternatives', 'best_x' ), true ) ) {
            $unverified_sections = array();
            foreach ( $sections as $section ) {
                $matches_verified = false;
                foreach ( $verified_options as $option ) {
                    if ( self::option_matches_heading( $option, $section['heading'] ) ) {
                        $matches_verified = true;
                        break;
                    }
                }
                if ( $matches_verified || self::is_utility_recipe_heading( $section['heading'] ) ) {
                    continue;
                }
                if ( self::section_looks_like_comparison_option( $section ) ) {
                    $unverified_sections[] = $section['heading'];
                }
            }

            self::add_check(
                $checks,
                'recipe_unverified_options',
                __( 'No unverified comparison options', 'aeo-god-mode' ),
                empty( $unverified_sections ) ? 'pass' : 'fail',
                empty( $unverified_sections )
                    ? __( 'Every option-shaped section is backed by the verified evidence list.', 'aeo-god-mode' )
                    : sprintf(
                        /* translators: %s: comma-separated list of unverified comparison section headings */
                        __( 'These option sections are not in the verified evidence list: %s.', 'aeo-god-mode' ),
                        implode( ', ', $unverified_sections )
                    ),
                true,
                array( 'unverified_sections' => $unverified_sections )
            );
        }
    }

    /**
     * Return true for normal structural headings that should never be treated
     * as a product merely because their copy includes comparison language.
     */
    private static function is_utility_recipe_heading( $heading ) {
        $raw_heading = trim( wp_strip_all_tags( (string) $heading ) );
        if ( '?' === substr( $raw_heading, -1 ) ) {
            return true;
        }
        $heading = self::normalized_recipe_text( $raw_heading );
        if ( '' === $heading ) {
            return true;
        }
        return (bool) preg_match(
            '/^(?:introduction|overview|quick answer|short answer|answer first|summary|key takeaways|at a glance|our picks|top picks|(?:(?:our|final|detailed) )?recommendations?|methodology|how we (?:chose|tested|rated|scored)|how to choose|choosing (?:the|an|a)|what to look for|selection criteria|evaluation criteria|comparison(?: table)?|feature comparison|price comparison|pricing comparison|decision matrix|buyer s guide|which .+ (?:is right|should you choose)|why (?:choose|consider|use|look for)|frequently asked questions|faq|common questions|conclusion|final thoughts|(?:final )?verdict|bottom line|next steps)(?:\b|:)/i',
            $heading
        );
    }

    /**
     * Identify an option section from explicit structure, not capitalization.
     * This deliberately avoids guessing that every proper noun is a product:
     * a section must be numbered, carry a best-fit label, or contain both the
     * same limitation and audience-verdict signals required of real options.
     */
    private static function section_looks_like_comparison_option( $section ) {
        $heading = trim( wp_strip_all_tags( (string) ( $section['heading'] ?? '' ) ) );
        $body    = (string) ( $section['body'] ?? '' );
        if ( preg_match( '/^(?:#?\d+[\s.):-]|(?:option|pick|tool|platform|plugin|alternative|choice)\s+#?\d+\b)/i', $heading ) ) {
            return true;
        }
        if ( preg_match( '/(?:\s[-–—:]\s*|\()(?:(?:our\s+)?(?:top\s+)?pick|best for|ideal for|recommended for|runner-up)\b/i', $heading ) ) {
            return true;
        }
        $has_limitation = (bool) preg_match( '/\b(?:limitation|drawback|trade-?off|downside|weakness|not ideal|not suitable|falls short|lacks?|avoid if)\b/i', $body );
        $has_verdict    = (bool) preg_match( '/\b(?:best for|best if|choose\b.{0,35}\bif|use\b.{0,35}\bif|good fit|ideal for|suited to|works well for|right for|who should choose|verdict|recommended for)\b/is', $body );
        return $has_limitation && $has_verdict;
    }

    /** Return H2 headings and their content through the next H2. */
    private static function recipe_sections( $content ) {
        $sections = array();
        if ( ! preg_match_all( '/<h2\b[^>]*>(.*?)<\/h2>/si', $content, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
            return $sections;
        }
        foreach ( $matches as $index => $match ) {
            $start = (int) $match[0][1] + strlen( $match[0][0] );
            $end   = isset( $matches[ $index + 1 ] ) ? (int) $matches[ $index + 1 ][0][1] : strlen( $content );
            $sections[] = array(
                'heading' => trim( wp_strip_all_tags( $match[1][0] ) ),
                'body'    => trim( wp_strip_all_tags( substr( $content, $start, max( 0, $end - $start ) ) ) ),
            );
        }
        return $sections;
    }

    /** Read a list-size promise from a comparison title without mistaking product versions for counts. */
    private static function recipe_promised_option_count( $title ) {
        $title = trim( wp_strip_all_tags( (string) $title ) );
        $count = null;
        if ( preg_match( '/\b(?:top|best)\s+([2-9]|[1-9][0-9])\b/i', $title, $match ) ) {
            $count = (int) $match[1];
        } elseif ( preg_match( '/(?<![-\p{L}\d])([2-9]|[1-9][0-9])\s+(?:best\s+)?(?:[\p{L}\p{N}&+.-]+\s+){0,4}(?:alternatives|options|tools|plugins|platforms|products|services|choices|competitors|apps|solutions)\b/iu', $title, $match ) ) {
            $count = (int) $match[1];
        }
        return $count;
    }

    /** Match a verified product/source name to an H2 without demanding punctuation parity. */
    private static function option_matches_heading( $option, $heading ) {
        $option  = self::normalized_recipe_text( preg_replace( '/\.(?:com|co\.uk|io|ai|org|net)$/i', '', (string) $option ) );
        $heading = self::normalized_recipe_text( $heading );
        if ( '' === $option || '' === $heading ) {
            return false;
        }
        if ( false !== strpos( ' ' . $heading . ' ', ' ' . $option . ' ' ) ) {
            return true;
        }
        $tokens = array_values( array_filter( explode( ' ', $option ), function ( $token ) {
            return strlen( $token ) >= 3;
        } ) );
        return ! empty( $tokens ) && empty( array_diff( $tokens, explode( ' ', $heading ) ) );
    }

    /** Count actual ordered-list items or explicitly numbered step headings. */
    private static function ordered_step_count( $content ) {
        $ordered = 0;
        if ( preg_match_all( '/<ol\b[^>]*>(.*?)<\/ol>/si', $content, $lists ) ) {
            foreach ( $lists[1] as $list ) {
                $ordered = max( $ordered, preg_match_all( '/<li\b/i', $list, $items ) );
            }
        }
        if ( preg_match_all( '/<h[23]\b[^>]*>\s*(?:step\s*)?(\d+)[\s.:\-)]/i', $content, $numbered ) ) {
            $numbers = array_values( array_unique( array_map( 'intval', $numbered[1] ) ) );
            sort( $numbers );
            $sequential = 0;
            foreach ( $numbers as $number ) {
                if ( $number !== $sequential + 1 ) {
                    break;
                }
                $sequential++;
            }
            $ordered = max( $ordered, $sequential );
        }
        return $ordered;
    }

    /**
     * Inspect the primary ordered walkthrough list for an explicit result in
     * every step. The writer uses a fixed label so this remains deterministic
     * and does not mistake vague promises elsewhere in the article for proof
     * that a particular action worked.
     */
    private static function walkthrough_step_result_report( $content ) {
        $report = array( 'total' => 0, 'with_result' => 0, 'missing_steps' => array() );
        if ( ! preg_match_all( '/<ol\b[^>]*>(.*?)<\/ol>/si', (string) $content, $lists ) ) {
            return $report;
        }

        $primary_items = array();
        foreach ( $lists[1] as $list ) {
            preg_match_all( '/<li\b[^>]*>(.*?)<\/li>/si', $list, $items );
            if ( count( $items[1] ) > count( $primary_items ) ) {
                $primary_items = $items[1];
            }
        }

        $report['total'] = count( $primary_items );
        foreach ( $primary_items as $index => $item ) {
            $text = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $item ) ) );
            if ( preg_match( '/\b(?:expected result|what you should see|you should now see)\s*:/iu', $text ) ) {
                $report['with_result']++;
            } else {
                $report['missing_steps'][] = $index + 1;
            }
        }
        return $report;
    }

    /** Flag repeated sections and FAQ answers that merely duplicate the body. */
    private static function add_repetition_checks( &$checks, $sections, $content, $faq_parse ) {
        $duplicates = array();
        foreach ( $sections as $index => $left ) {
            for ( $other = $index + 1; $other < count( $sections ); $other++ ) {
                $right = $sections[ $other ];
                if ( self::normalized_recipe_text( $left['heading'] ) === self::normalized_recipe_text( $right['heading'] )
                    || self::recipe_similarity( $left['body'], $right['body'] ) >= 0.88 ) {
                    $duplicates[] = $left['heading'] . ' / ' . $right['heading'];
                }
            }
        }
        self::add_check(
            $checks,
            'recipe_repeated_sections',
            __( 'No repeated sections', 'aeo-god-mode' ),
            empty( $duplicates ) ? 'pass' : 'warn',
            empty( $duplicates )
                ? __( 'No duplicated H2 sections were found.', 'aeo-god-mode' )
                : sprintf( __( '%d section pair(s) repeat the same heading or substantially the same copy.', 'aeo-god-mode' ), count( $duplicates ) ),
            true,
            array( 'duplicates' => array_slice( $duplicates, 0, 10 ) )
        );

        $faq_pairs      = (array) ( $faq_parse['pairs'] ?? array() );
        $faq_duplicates = array();
        $body_without_faq = preg_replace( '/\[aeogm_faqs\b[^\]]*\].*?\[\/aeogm_faqs\]/si', '', $content );
        foreach ( $faq_pairs as $index => $pair ) {
            $question = (string) ( $pair['question'] ?? '' );
            $answer   = (string) ( $pair['answer'] ?? '' );
            for ( $other = $index + 1; $other < count( $faq_pairs ); $other++ ) {
                $other_pair = $faq_pairs[ $other ];
                if ( self::recipe_similarity( $question, (string) ( $other_pair['question'] ?? '' ) ) >= 0.9
                    || self::recipe_similarity( $answer, (string) ( $other_pair['answer'] ?? '' ) ) >= 0.88 ) {
                    $faq_duplicates[] = $question;
                }
            }
            foreach ( self::recipe_paragraphs( $body_without_faq ) as $paragraph ) {
                if ( self::recipe_similarity( $answer, $paragraph ) >= 0.88 ) {
                    $faq_duplicates[] = $question;
                    break;
                }
            }
        }
        self::add_check(
            $checks,
            'recipe_faq_repetition',
            __( 'FAQ adds new value', 'aeo-god-mode' ),
            empty( $faq_duplicates ) ? 'pass' : 'warn',
            empty( $faq_duplicates )
                ? __( 'FAQ answers do not simply repeat another FAQ or body paragraph.', 'aeo-god-mode' )
                : sprintf( __( '%d FAQ item(s) substantially repeat existing copy.', 'aeo-god-mode' ), count( array_unique( $faq_duplicates ) ) ),
            true,
            array( 'repeated_questions' => array_values( array_unique( $faq_duplicates ) ) )
        );
    }

    /** Flag unsupported absolute marketing claims while allowing sourced or measured claims. */
    private static function add_unsupported_superlative_check( &$checks, $content ) {
        $unsupported = array();
        if ( preg_match_all( '/<(?:p|li)\b[^>]*>(.*?)<\/(?:p|li)>/si', $content, $blocks, PREG_SET_ORDER ) ) {
            foreach ( $blocks as $block ) {
                $html = (string) $block[0];
                $text = trim( wp_strip_all_tags( $block[1] ) );
                if ( ! preg_match( '/\b(?:#\s*1|number one|market-leading|industry-leading|top-rated|most powerful|most advanced|fastest|strongest|ultimate|unmatched|unrivalled|best (?:ever|overall|on the market))\b/i', $text ) ) {
                    continue;
                }
                $grounded = preg_match( '/<a\b[^>]*href=/i', $html )
                    || preg_match( '/\b(?:according to|our (?:test|data|survey|benchmark)|in (?:the|our) (?:test|data|survey|benchmark)|rated by|measured)\b/i', $text )
                    || preg_match( '/\b\d+(?:\.\d+)?%\b/', $text );
                if ( ! $grounded ) {
                    $unsupported[] = function_exists( 'mb_substr' ) ? mb_substr( $text, 0, 180 ) : substr( $text, 0, 180 );
                }
            }
        }
        self::add_check(
            $checks,
            'recipe_unsupported_superlatives',
            __( 'Claims are supportable', 'aeo-god-mode' ),
            empty( $unsupported ) ? 'pass' : 'warn',
            empty( $unsupported )
                ? __( 'No unsupported absolute superiority claims were found.', 'aeo-god-mode' )
                : sprintf( __( '%d absolute superiority claim(s) need evidence or more careful wording.', 'aeo-god-mode' ), count( $unsupported ) ),
            true,
            array( 'claims' => array_slice( $unsupported, 0, 10 ) )
        );
    }

    /** Extract ordinary paragraph/list copy for repetition comparisons. */
    private static function recipe_paragraphs( $content ) {
        $paragraphs = array();
        if ( preg_match_all( '/<(?:p|li)\b[^>]*>(.*?)<\/(?:p|li)>/si', (string) $content, $matches ) ) {
            foreach ( $matches[1] as $paragraph ) {
                $paragraph = trim( wp_strip_all_tags( $paragraph ) );
                if ( str_word_count( $paragraph ) >= 8 ) {
                    $paragraphs[] = $paragraph;
                }
            }
        }
        return $paragraphs;
    }

    /** Jaccard similarity over meaningful normalized words. */
    private static function recipe_similarity( $left, $right ) {
        $left  = array_unique( array_filter( explode( ' ', self::normalized_recipe_text( $left ) ), function ( $word ) {
            return strlen( $word ) >= 3;
        } ) );
        $right = array_unique( array_filter( explode( ' ', self::normalized_recipe_text( $right ) ), function ( $word ) {
            return strlen( $word ) >= 3;
        } ) );
        if ( count( $left ) < 4 || count( $right ) < 4 ) {
            return 0;
        }
        $union = array_unique( array_merge( $left, $right ) );
        return empty( $union ) ? 0 : count( array_intersect( $left, $right ) ) / count( $union );
    }

    /** Normalize punctuation/case for deterministic recipe comparisons. */
    private static function normalized_recipe_text( $text ) {
        $text = html_entity_decode( wp_strip_all_tags( (string) $text ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        $text = function_exists( 'mb_strtolower' ) ? mb_strtolower( $text, 'UTF-8' ) : strtolower( $text );
        $text = preg_replace( '/[^a-z0-9]+/u', ' ', $text );
        return trim( preg_replace( '/\s+/', ' ', $text ) );
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
