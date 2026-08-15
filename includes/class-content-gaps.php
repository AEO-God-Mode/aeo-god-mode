<?php
/**
 * Content gap scanner.
 *
 * @package AISEOGodMode
 */

namespace AISEOGodMode;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Scans all published content and identifies SEO gaps.
 */
class ContentGaps {

    /** Version of the scoring contract stored in editor-panel caches. */
    const SCORE_VERSION = 7;

    /** Option recording which scoring contract produced the site-wide rows. */
    const RESULTS_VERSION_OPT = 'asgm_content_gap_score_version';

    /** Non-autoloaded detailed rows consumed by the Content Gaps UI. */
    const ACTION_RESULTS_OPT = 'asgm_content_gap_action_results';

    /** Remove all site-wide scan rows after a content/schema mutation. */
    public static function invalidate_cached_results() {
        delete_option( 'asgm_content_gap_results' );
        delete_option( self::ACTION_RESULTS_OPT );
        delete_option( 'asgm_content_gap_last_scan' );
        delete_option( 'asgm_content_gap_scan_summary' );
        delete_transient( 'asgm_site_health' );
    }

    /**
     * Remove site-wide rows written by an older scorer.
     *
     * This runs during boot, including safe mode, because Site Health reads the
     * option directly. Leaving old rows in place would present historical
     * scores as if they had been produced by the current contract.
     *
     * @return bool True when stale data was removed.
     */
    public static function maybe_invalidate_cached_results() {
        $results        = get_option( 'asgm_content_gap_results', array() );
        $action_results = get_option( self::ACTION_RESULTS_OPT, array() );
        $stored_version = (int) get_option( self::RESULTS_VERSION_OPT, 0 );
        $last_scan      = (string) get_option( 'asgm_content_gap_last_scan', '' );
        $stale          = self::SCORE_VERSION !== $stored_version && ( ! empty( $results ) || '' !== $last_scan );

        if ( ! $stale && ! empty( $results ) ) {
            foreach ( (array) $results as $row ) {
                if ( ! is_array( $row ) || self::SCORE_VERSION !== (int) ( $row['score_version'] ?? 0 ) ) {
                    $stale = true;
                    break;
                }
            }
        }
        if ( ! $stale && ! empty( $action_results ) ) {
            foreach ( (array) $action_results as $row ) {
                if ( ! is_array( $row ) || self::SCORE_VERSION !== (int) ( $row['score_version'] ?? 0 ) ) {
                    $stale = true;
                    break;
                }
            }
        }

        if ( $stale ) {
            self::invalidate_cached_results();
        }

        update_option( self::RESULTS_VERSION_OPT, self::SCORE_VERSION, false );
        return $stale;
    }

    /**
     * Get cached scan results.
     *
     * @return array
     */
    public function get_results() {
        self::maybe_invalidate_cached_results();
        $stored    = get_option( 'asgm_content_gap_results', array() );
        $detailed  = get_option( self::ACTION_RESULTS_OPT, null );
        $results   = is_array( $detailed )
            ? $detailed
            : array_values( array_filter( (array) $stored, function ( $row ) {
                return is_array( $row ) && ( (int) ( $row['action_count'] ?? 0 ) > 0 || ! empty( $row['issues'] ) );
            } ) );
        $last_scan = get_option( 'asgm_content_gap_last_scan', '' );
        $summary   = get_option( 'asgm_content_gap_scan_summary', array() );
        if ( ! is_array( $summary ) || self::SCORE_VERSION !== (int) ( $summary['score_version'] ?? 0 ) ) {
            $summary = array();
        }

        return array(
            'results'   => $results,
            'last_scan' => $last_scan,
            'count'     => count( $results ),
            'total_scanned' => (int) ( $summary['total_scanned'] ?? count( (array) $stored ) ),
            'summary'   => is_array( $summary ) ? $summary : array(),
        );
    }

    /**
     * Run a full content gap scan.
     *
     * @return array
     */
    public function scan() {
        $results    = array();
        $post_types = get_post_types( array( 'public' => true ), 'names' );
        $post_types = array_diff( $post_types, array( 'attachment', 'download' ) );

        // Exclude EDD utility pages.
        $edd_slugs    = array( 'checkout', 'purchase-confirmation', 'purchase-history', 'transaction-failed' );
        $exclude_ids  = array();
        foreach ( $edd_slugs as $slug ) {
            $page = get_page_by_path( $slug );
            if ( $page ) {
                $exclude_ids[] = $page->ID;
            }
        }
        // Also exclude EDD settings pages.
        if ( function_exists( 'edd_get_option' ) ) {
            $edd_pages = array( 'purchase_page', 'success_page', 'failure_page', 'purchase_history_page' );
            foreach ( $edd_pages as $edd_opt ) {
                $pid = edd_get_option( $edd_opt, 0 );
                if ( $pid ) {
                    $exclude_ids[] = absint( $pid );
                }
            }
        }

        $query_args = array(
            'post_type'      => array_values( $post_types ),
            'post_status'    => 'publish',
            'posts_per_page' => 500,
            'no_found_rows'  => true,
        );
        if ( ! empty( $exclude_ids ) ) {
            // Exclusion list is the user's small manual "ignore these posts"
            // list, capped at a few dozen entries. The VIP performance note
            // about post__not_in only matters at millions-of-posts scale.
            $query_args['post__not_in'] = array_unique( $exclude_ids ); // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_post__not_in
        }

        $query = new \WP_Query( $query_args );

        if ( $query->have_posts() ) {
            while ( $query->have_posts() ) {
                $query->the_post();
                $post = get_post();
                $gaps = $this->analyze_post( $post );

                // Store every scanned page, including clean pages. Site Health
                // needs a real denominator rather than a list containing only
                // failures, and an all-clean scan must remain distinguishable
                // from a scan that never ran.
                $results[] = $gaps;
            }
            wp_reset_postdata();
        }

        // Sort by gap score descending.
        usort( $results, function( $a, $b ) {
            return $b['gap_score'] - $a['gap_score'];
        } );

        $action_results = array_values( array_filter( $results, function ( $row ) {
            return (int) ( $row['action_count'] ?? 0 ) > 0 || ! empty( $row['issues'] );
        } ) );
        $site_rows = array_map( function ( $row ) {
            return array(
                'post_id'       => (int) ( $row['post_id'] ?? 0 ),
                'score_version' => self::SCORE_VERSION,
                'gap_count'     => (int) ( $row['gap_count'] ?? 0 ),
                'aeo_score'     => (int) ( $row['aeo_score'] ?? 0 ),
            );
        }, $results );

        // Site Health still reads this historical option directly, so retain a
        // lightweight row for every scanned page while keeping the potentially
        // large diagnostic payload in a separate non-autoloaded option.
        update_option( 'asgm_content_gap_results', $site_rows, false );
        update_option( self::ACTION_RESULTS_OPT, $action_results, false );
        update_option( 'asgm_content_gap_last_scan', current_time( 'mysql' ) );
        update_option( self::RESULTS_VERSION_OPT, self::SCORE_VERSION, false );
        $pages_with_actions = count( $action_results );
        $critical_posts = count( array_filter( $results, function ( $row ) {
            return (int) ( $row['gap_count'] ?? 0 ) >= 3;
        } ) );
        update_option(
            'asgm_content_gap_scan_summary',
            array(
                'score_version'     => self::SCORE_VERSION,
                'total_scanned'     => count( $results ),
                'pages_with_actions'=> $pages_with_actions,
                'critical_posts'    => $critical_posts,
            ),
            false
        );

        // Invalidate the site-health cache so the dashboard picks up any changes.
        delete_transient( 'asgm_site_health' );

        return array(
            'results'       => $action_results,
            'last_scan'     => get_option( 'asgm_content_gap_last_scan' ),
            'count'         => $pages_with_actions,
            'total_scanned' => count( $results ),
        );
    }



    /**
     * Analyze a single post for gaps.
     *
     * @param \WP_Post   $post             Post object.
     * @param string|null $rendered_content Optional single rendered snapshot.
     * @return array
     */
    public function analyze_post( $post, $rendered_content = null ) {
        $issues   = array();
        $content  = $post->post_content;
        $wc       = str_word_count( wp_strip_all_tags( $content ) );
        $settings = get_option( 'asgm_settings', array() );

        // 1. Word count check (skip for template-driven pages).
        $template = get_page_template_slug( $post->ID );
        $is_template_page = ( ! empty( $template ) && 0 === $wc );

        if ( $wc < 300 && ! $is_template_page ) {
            $issues[] = array(
                'type'     => 'thin_content',
                'severity' => 'warning',
                'message'  => sprintf(
                    /* translators: %d: word count */
                    __( 'Only %d words. Pages under 300 words are unlikely to be cited by AI systems.', 'aeo-god-mode' ),
                    $wc
                ),
                'fix_type' => null,
            );
        }

        // 2. Schema check (include third-party schema plugins).
        $has_yoast_schema = get_post_meta( $post->ID, '_yoast_wpseo_schema_page_type', true );
        $has_rm_schema    = get_post_meta( $post->ID, 'rank_math_rich_snippet', true );
        $yoast_active     = defined( 'WPSEO_VERSION' );
        $rankmath_active  = defined( 'RANK_MATH_VERSION' ) || class_exists( '\\RankMath\\Paper\\Paper' );
        $aioseo_active    = defined( 'AIOSEO_VERSION' ) || class_exists( '\AIOSEO\Plugin\AIOSEO' );
        $seopress_active  = defined( 'SEOPRESS_VERSION' ) || defined( 'SEOPRESS_PRO_VERSION' );
        $other_schema_plugin = $aioseo_active || $seopress_active;
        $has_third_party_schema = $yoast_active || $rankmath_active || $other_schema_plugin;

        $schema_data = class_exists( __NAMESPACE__ . '\\Schema' )
            ? self::schema_post_snapshot( $post )
            : array( 'schemas' => array() );
        $schemas     = (array) ( $schema_data['schemas'] ?? array() );
        $auto_schema = $this->has_auto_schema( $post, $schemas );
        $has_schema_override = ! empty( $schema_data['applied_override_types'] );

        if ( ! $has_third_party_schema && ! $auto_schema ) {
            $issues[] = array(
                'type'     => 'no_schema',
                'severity' => 'error',
                'message'  => __( 'No JSON-LD schema markup on this page.', 'aeo-god-mode' ),
                'fix_type' => 'add_article_schema',
            );
        }

        // 2b. Schema quality check (validate field completeness when schema exists).
        // Only validate schema we can actually observe. A third-party plugin
        // being active says the front end may emit schema; it does not make an
        // empty or Breadcrumb-only local graph evidence that its Article/FAQ
        // output is valid.
        if ( $auto_schema ) {
            $validator  = new Validator();
            $val_result = $validator->validate_schemas( $schemas );
            if ( 'error' === $val_result['overall'] ) {
                $error_msgs = array();
                foreach ( $val_result['results'] as $r ) {
                    foreach ( $r['errors'] as $e ) {
                        $error_msgs[] = $e;
                    }
                }

                // Make "missing image" messages more actionable.
                $friendly_msgs = array_map( function ( $msg ) {
                    if ( stripos( $msg, 'missing required field "image"' ) !== false ) {
                        return __( 'No featured image or inline image detected. Add one to qualify for Google rich results and improve visual search visibility.', 'aeo-god-mode' );
                    }
                    return $msg;
                }, $error_msgs );

                $issues[] = array(
                    'type'     => 'schema_errors',
                    'severity' => 'warning',
                    'message'  => sprintf(
                        /* translators: 1: number of issues, 2: issue descriptions */
                        __( 'Schema has %1$d field issue(s): %2$s', 'aeo-god-mode' ),
                        count( $friendly_msgs ),
                        implode( '; ', array_slice( $friendly_msgs, 0, 3 ) )
                    ),
                    'fix_type' => null,
                );
            } elseif ( 'warning' === $val_result['overall'] ) {
                $warn_msgs = array();
                foreach ( $val_result['results'] as $r ) {
                    foreach ( $r['warnings'] as $w ) {
                        $warn_msgs[] = $w;
                    }
                }
                $issues[] = array(
                    'type'     => 'schema_warnings',
                    'severity' => 'info',
                    'message'  => sprintf(
                        /* translators: 1: number of missing fields, 2: field descriptions */
                        __( '%1$d recommended schema field(s) missing: %2$s', 'aeo-god-mode' ),
                        count( $warn_msgs ),
                        implode( '; ', array_slice( $warn_msgs, 0, 3 ) )
                    ),
                    'fix_type' => null,
                );
            }
        }

        // 3. FAQ opportunity. As with HowTo, do not offer an AEO-only fix
        // when output is disabled or assigned to another schema provider.
        $can_add_faq = 'disabled' !== ( $schema_data['source'] ?? '' )
            && ! in_array( 'FAQPage', (array) ( $schema_data['disabled_types'] ?? array() ), true )
            && ! in_array( 'FAQPage', (array) ( $schema_data['delegated_types'] ?? array() ), true );
        if ( $can_add_faq && $this->has_faq_content( $content ) && ! $this->has_faq_schema( $post, $schemas ) ) {
            $issues[] = array(
                'type'     => 'faq_opportunity',
                'severity' => 'info',
                'message'  => __( 'FAQ-style content detected but no FAQPage schema.', 'aeo-god-mode' ),
                'fix_type' => 'add_faq_schema',
            );
        }

        // 4. HowTo opportunity. Only offer the one-click AEO fix when AEO is
        // actually allowed to emit the result. Delegated or globally disabled
        // output otherwise creates an override that is immediately suppressed
        // and leaves the issue permanently unresolved.
        $can_add_howto = 'disabled' !== ( $schema_data['source'] ?? '' )
            && ! in_array( 'HowTo', (array) ( $schema_data['disabled_types'] ?? array() ), true )
            && ! in_array( 'HowTo', (array) ( $schema_data['delegated_types'] ?? array() ), true );
        if ( $can_add_howto && $this->has_howto_content( $post, $schemas ) && ! $this->has_howto_schema( $post, $schemas ) ) {
            $issues[] = array(
                'type'     => 'howto_opportunity',
                'severity' => 'info',
                'message'  => __( 'Step-by-step content detected but no HowTo schema.', 'aeo-god-mode' ),
                'fix_type' => 'add_howto_schema',
            );
        }

        // 5. Missing meta description.
        $has_yoast_desc = get_post_meta( $post->ID, '_yoast_wpseo_metadesc', true );
        $has_rm_desc    = get_post_meta( $post->ID, 'rank_math_description', true );
        $has_asgm_desc  = get_post_meta( $post->ID, '_asgm_meta_description', true );
        $has_aioseo     = get_post_meta( $post->ID, '_aioseo_description', true );
        $has_seopress_desc = get_post_meta( $post->ID, '_seopress_titles_desc', true );

        // Rank Math auto-generates descriptions from a global template when no explicit one is set.
        // If Rank Math is active, treat it as having a meta description.
        $rm_auto = false;
        if ( class_exists( 'RankMath' ) ) {
            $rm_titles = get_option( 'rank-math-options-titles', array() );
            $pt = $post->post_type;
            $template_key = 'pt_' . $pt . '_description';
            if ( ! empty( $rm_titles[ $template_key ] ) ) {
                $rm_auto = true;
            }
        }

        if ( empty( $has_yoast_desc ) && empty( $has_rm_desc ) && empty( $has_asgm_desc ) && empty( $has_aioseo ) && empty( $has_seopress_desc ) && ! $rm_auto ) {
            $issues[] = array(
                'type'     => 'no_meta_description',
                'severity' => 'warning',
                'message'  => __( 'No meta description set (Yoast, Rank Math, or AIOSEO).', 'aeo-god-mode' ),
                'fix_type' => 'generate_meta_description',
            );
        }

        // The editor toolbar, generated-draft gate and public Domain Auditor
        // must use one scoring implementation. Content Gaps remains the owner
        // of local fix actions, but its scored findings now come from the pure
        // rendered-page evaluator instead of a parallel heuristic.
        $rendered_analysis = null;
        if ( class_exists( __NAMESPACE__ . '\\RenderedPageEvaluator' ) ) {
            $rendered_content = null === $rendered_content
                ? self::render_post_snapshot( $post )
                : (string) $rendered_content;
            $metadata         = class_exists( __NAMESPACE__ . '\\MetadataWriter' )
                ? MetadataWriter::read( $post->ID )
                : array( 'meta_title' => '', 'meta_description' => '' );
            $resolved_desc    = trim( (string) ( $metadata['meta_description'] ?? '' ) );
            if ( '' === $resolved_desc ) {
                foreach ( array( $has_asgm_desc, $has_rm_desc, $has_yoast_desc, $has_aioseo, $has_seopress_desc ) as $candidate_desc ) {
                    if ( '' !== trim( (string) $candidate_desc ) ) {
                        $resolved_desc = trim( (string) $candidate_desc );
                        break;
                    }
                }
            }
            $has_runtime_metadata_provider = $yoast_active || $rankmath_active || $aioseo_active || $seopress_active || $rm_auto;

            $evaluator_input = array(
                'url'          => get_permalink( $post ),
                'content_html' => $rendered_content,
                'title'        => trim( (string) ( $metadata['meta_title'] ?? '' ) ) ?: get_the_title( $post ),
                'page_kind'    => 'post' === $post->post_type ? 'editorial' : 'auto',
            );
            if ( '' !== $resolved_desc ) {
                $evaluator_input['meta_description'] = $resolved_desc;
            } elseif ( ! $has_runtime_metadata_provider ) {
                // Native metadata is fully observable from post meta.
                $evaluator_input['meta_description'] = '';
            }
            if ( $auto_schema ) {
                $evaluator_input['schemas'] = $schemas;
            } elseif ( $has_third_party_schema ) {
                // Plugin activation alone cannot prove this post emits schema.
                $evaluator_input['schema_presence'] = null;
            } else {
                $evaluator_input['schema_presence'] = false;
            }

            $rendered_analysis = RenderedPageEvaluator::evaluate(
                $evaluator_input,
                array(
                    'profile'                  => 'generated_draft',
                    'content_depth_eligible'   => ! $is_template_page,
                    'require_reliable_content' => false,
                )
            );

            // Replace only scored diagnostic families. Non-scoring ideas such
            // as FAQ/HowTo opportunities and AI actions stay in this adapter.
            $scored_types = array_keys( RenderedPageEvaluator::IMPACTS );
            $issues = array_values( array_filter( $issues, function ( $issue ) use ( $scored_types ) {
                return ! in_array( (string) ( $issue['type'] ?? '' ), $scored_types, true );
            } ) );

            $fix_map = array(
                'no_schema'           => 'add_article_schema',
                'no_meta_description' => 'generate_meta_description',
                'aeo_weak'             => License::is_pro_build() ? 'analyze_citability' : null,
            );
            foreach ( (array) ( $rendered_analysis['checks'] ?? array() ) as $shared_check ) {
                $type   = (string) ( $shared_check['id'] ?? '' );
                $impact = (int) ( $shared_check['score_impact'] ?? 0 );
                $status = (string) ( $shared_check['status'] ?? '' );
                if ( ! in_array( $type, $scored_types, true ) || $impact <= 0 || ! in_array( $status, array( 'fail', 'warn' ), true ) ) {
                    continue;
                }
                $issues[] = array(
                    'type'            => $type,
                    'severity'        => 'fail' === $status ? 'warning' : 'info',
                    'message'         => (string) ( $shared_check['message'] ?? '' ),
                    'fix_type'        => $fix_map[ $type ] ?? null,
                    'score_impact'    => $impact,
                    'counts_as_issue' => true,
                    'scoring_engine'  => 'rendered_page_v' . RenderedPageEvaluator::SCORE_VERSION,
                );
            }

            // Unknown runtime output is a review action, never a green pass and
            // never a score penalty. The public page audit can make a definitive
            // decision later from actual rendered HTML.
            foreach ( (array) ( $rendered_analysis['checks'] ?? array() ) as $shared_check ) {
                $type   = (string) ( $shared_check['id'] ?? '' );
                $status = (string) ( $shared_check['status'] ?? '' );
                if ( 'unknown' !== $status || ! in_array( $type, array( 'no_schema', 'no_meta_description' ), true ) ) {
                    continue;
                }
                $issues[] = array(
                    'type'            => $type,
                    'severity'        => 'info',
                    'message'         => (string) ( $shared_check['message'] ?? '' ) . ' ' . __( 'Verify the rendered front-end output.', 'aeo-god-mode' ),
                    'fix_type'        => null,
                    'score_impact'    => 0,
                    'counts_as_issue' => false,
                    'scoring_engine'  => 'rendered_page_v' . RenderedPageEvaluator::SCORE_VERSION,
                );
            }
        }

        // Only show AI-driven improvement opportunities if on the Pro build.
        if ( \AISEOGodMode\License::is_pro_build() ) {
            // 5a. AEO Title check.
            $has_generated_title = get_post_meta( $post->ID, '_asgm_generated_title', true );
            if ( empty( $has_generated_title ) ) {
                $issues[] = array(
                    'type'     => 'aeo_title',
                    'severity' => 'info',
                    'message'  => __( 'Title has not been actively optimized for AEO/LLM extraction. Generate 5 optimized options.', 'aeo-god-mode' ),
                    'fix_type' => 'generate_aeo_titles',
                );
            }

            // 6. AEO readiness / Citability.
            if ( ! is_array( $rendered_analysis ) && $wc >= 300 && ! $this->has_definitive_answer_structure( $content ) ) {
                $issues[] = array(
                    'type'     => 'aeo_weak',
                    'severity' => 'info',
                    'message'  => __( 'No clear definitive answer structure found. Run AI analysis to get a citability score and actionable improvements.', 'aeo-god-mode' ),
                    'fix_type' => 'analyze_citability',
                );
            }

            // 7. Content improvements (always show for posts 500+ words so user can view/re-run AI suggestions).
            if ( $wc >= 500 ) {
                $has_improvements = get_post_meta( $post->ID, '_asgm_ai_cache_suggest_content_improvements', true );
                if ( ! empty( $has_improvements ) && isset( $has_improvements['data'] ) ) {
                    // Already analyzed — show as info so user can still view results and re-run.
                    $cached_data = $has_improvements['data'];
                    $count = isset( $cached_data['improvements'] ) ? count( $cached_data['improvements'] ) : 0;
                    $grade = isset( $cached_data['overall_aeo_grade'] ) ? $cached_data['overall_aeo_grade'] : 'N/A';
                    $issues[] = array(
                        'type'     => 'content_improvements',
                        'severity' => 'info',
                        'message'  => sprintf(
                            /* translators: 1: number of improvements, 2: grade */
                            __( 'Previous analysis found %1$d improvement(s) (Grade: %2$s). Click to view or re-run.', 'aeo-god-mode' ),
                            $count,
                            $grade
                        ),
                        'fix_type' => 'suggest_content_improvements',
                    );
                } else {
                    $issues[] = array(
                        'type'     => 'content_improvements',
                        'severity' => 'info',
                        'message'  => __( 'AI can analyze this post and suggest specific AEO improvements.', 'aeo-god-mode' ),
                        'fix_type' => 'suggest_content_improvements',
                    );
                }
            }

            // 8. No speakable summary (only if speakable is enabled in settings).
            // Speakable schema is beta and used for topical news queries on Google Assistant.
            if ( ! empty( $settings['speakable_enabled'] ) && $wc >= 500 && in_array( $post->post_type, array( 'post' ), true ) ) {
                $has_speakable = get_post_meta( $post->ID, '_asgm_speakable_summary', true );
                if ( empty( $has_speakable ) ) {
                    $issues[] = array(
                        'type'     => 'no_speakable',
                        'severity' => 'info',
                        'message'  => __( 'No speakable summary. Voice assistants can read this to users if a concise summary is provided.', 'aeo-god-mode' ),
                        'fix_type' => 'generate_speakable_summary',
                    );
                }
            }

            // 9. No llms.txt description (only for cornerstone content 1000+ words).
            // llms.txt is a curated site-level file; not every page needs an entry.
            if ( $wc >= 1000 ) {
                $has_llms_desc = get_post_meta( $post->ID, '_asgm_llms_description', true );
                if ( empty( $has_llms_desc ) ) {
                    $issues[] = array(
                        'type'     => 'no_llms_desc',
                        'severity' => 'info',
                        'message'  => __( 'This cornerstone content has no llms.txt description. Adding one makes it available for your curated llms.txt file.', 'aeo-god-mode' ),
                        'fix_type' => 'generate_llms_description',
                    );
                }
            }
        }

        // Calculate the page-health score from confirmed problems only. Product
        // opportunities (for example, generating an AEO title or running an AI
        // analysis) remain useful actions, but must never lower a page's score or
        // masquerade as a failed check.
        $weights = array(
            'thin_content'           => 25,
            'no_schema'              => 20,
            'schema_errors'          => 15,
            'no_meta_description'    => 15,
            'aeo_weak'               => 12,
            'schema_warnings'        => 3,
        );

        $gap_score         = 0;
        $issue_count       = 0;
        $opportunity_count = 0;
        foreach ( $issues as &$issue ) {
            $impact                   = isset( $issue['score_impact'] )
                ? (int) $issue['score_impact']
                : ( isset( $weights[ $issue['type'] ] ) ? $weights[ $issue['type'] ] : 0 );
            $issue['score_impact']    = $impact;
            $issue['counts_as_issue'] = $impact > 0;
            $gap_score               += $impact;

            if ( $impact > 0 ) {
                $issue_count++;
            } else {
                $opportunity_count++;
            }
        }
        unset( $issue );

        $gap_score = is_array( $rendered_analysis ) && isset( $rendered_analysis['scores']['aeo'] )
            ? 100 - (int) $rendered_analysis['scores']['aeo']
            : min( 100, $gap_score );

        // Build list of checks that were actually evaluated for this post.
        $applicable = array(
            'thin_content',
            'no_schema',
            'schema_errors',
            'schema_warnings',
            'no_meta_description',
            'aeo_title',
            'aeo_weak',
            'faq_opportunity',
            'howto_opportunity',
        );
        if ( $wc >= 500 ) {
            $applicable[] = 'content_improvements';
        }
        if ( ! empty( $settings['speakable_enabled'] ) && $wc >= 500 && in_array( $post->post_type, array( 'post' ), true ) ) {
            $applicable[] = 'no_speakable';
        }
        if ( $wc >= 1000 ) {
            $applicable[] = 'no_llms_desc';
        }

        // Gather rich detail data for each criterion.
        $plain = wp_strip_all_tags( $content );

        // Meta description: find the active one.
        $meta_desc = '';
        $meta_source = '';
        if ( ! empty( $has_asgm_desc ) )   { $meta_desc = $has_asgm_desc; $meta_source = 'AEO God Mode'; }
        elseif ( ! empty( $has_rm_desc ) )  { $meta_desc = $has_rm_desc;   $meta_source = 'Rank Math'; }
        elseif ( ! empty( $has_yoast_desc ) ) { $meta_desc = $has_yoast_desc; $meta_source = 'Yoast'; }
        elseif ( ! empty( $has_aioseo ) )   { $meta_desc = $has_aioseo;     $meta_source = 'AIOSEO'; }
        elseif ( ! empty( $has_seopress_desc ) ) { $meta_desc = $has_seopress_desc; $meta_source = 'SEOPress'; }
        elseif ( $rm_auto )                  { $meta_source = 'Rank Math (auto-template)'; }

        // Schema: identify which types are active.
        $schema_sources = array();
        if ( $auto_schema )      $schema_sources[] = 'AEO God Mode (auto)';
        if ( $has_schema_override ) $schema_sources[] = 'AEO God Mode (custom)';
        if ( $has_yoast_schema || $yoast_active ) $schema_sources[] = 'Yoast';
        if ( $has_rm_schema || $rankmath_active ) $schema_sources[] = 'Rank Math';
        if ( $other_schema_plugin ) $schema_sources[] = 'Third-party SEO plugin';

        // Image count.
        preg_match_all( '/<img[^>]+>/i', $content, $img_matches );
        $image_count = count( $img_matches[0] );
        $has_featured = ! empty( get_post_thumbnail_id( $post ) );

        // Data points (statistics/numbers with context).
        preg_match_all( '/\b\d[\d,.]*\s*(?:%|percent|million|billion|thousand|users|customers|increase|decrease|growth|revenue|visits|views|clicks|downloads|subscribers|members)\b/i', $plain, $data_matches );
        $data_point_count = count( $data_matches[0] );

        // Internal/external links.
        preg_match_all( '/<a\s[^>]*href=["\']([^"\']+)["\'][^>]*>/i', $content, $link_matches );
        $internal_links = 0;
        $external_links = 0;
        $site_host = wp_parse_url( home_url(), PHP_URL_HOST );
        foreach ( ( $link_matches[1] ?? array() ) as $href ) {
            $link_host = wp_parse_url( $href, PHP_URL_HOST );
            if ( empty( $link_host ) || $link_host === $site_host ) {
                $internal_links++;
            } else {
                $external_links++;
            }
        }

        // Heading count.
        preg_match_all( '/<h[2-6][^>]*>/i', $content, $heading_matches );
        $heading_count = count( $heading_matches[0] );

        // Citability score if cached.
        $citability = get_post_meta( $post->ID, '_asgm_citability_score', true );

        // Reading time (avg 238 wpm).
        $reading_time = max( 1, round( $wc / 238 ) );

        $details = array(
            'word_count'      => $wc,
            'reading_time'    => $reading_time,
            'heading_count'   => $heading_count,
            'image_count'     => $image_count,
            'has_featured'    => $has_featured,
            'data_points'     => $data_point_count,
            'internal_links'  => $internal_links,
            'external_links'  => $external_links,
            'meta_desc'       => $meta_desc,
            'meta_desc_len'   => strlen( $meta_desc ),
            'meta_source'     => $meta_source,
            'schema_sources'  => $schema_sources,
            'citability'      => $citability ? $citability : null,
            'has_faq_content' => $this->has_faq_content( $content ),
            'has_faq_schema'  => $this->has_faq_schema( $post, $schemas ),
            'has_howto'       => $this->has_howto_content( $post, $schemas ),
            'has_howto_schema' => $this->has_howto_schema( $post, $schemas ),
            'has_speakable'   => ! empty( get_post_meta( $post->ID, '_asgm_speakable_summary', true ) ),
            'has_llms_desc'   => ! empty( get_post_meta( $post->ID, '_asgm_llms_description', true ) ),
        );

        return array(
            'post_id'           => $post->ID,
            'title'             => get_the_title( $post ),
            'url'               => get_permalink( $post ),
            'post_type'         => $post->post_type,
            'word_count'        => $wc,
            'score_version'     => self::SCORE_VERSION,
            'scoring_engine'    => is_array( $rendered_analysis ) ? 'rendered_page_v' . RenderedPageEvaluator::SCORE_VERSION : 'legacy',
            'aeo_score'         => max( 0, 100 - $gap_score ),
            'gap_score'         => $gap_score,
            // Site Health consumes gap_count as a defect count. Keep optional
            // actions separate so they cannot lower the site-health result.
            'gap_count'         => $issue_count,
            'action_count'      => count( $issues ),
            'issue_count'       => $issue_count,
            'opportunity_count' => $opportunity_count,
            'issues'            => $issues,
            'applicable_checks' => $applicable,
            'details'           => $details,
            'rendered_analysis' => $rendered_analysis,
        );
    }

    /**
     * Render one post with the loop globals shortcodes expect, then restore
     * the caller's context. This gives REST/sidebar scans the same post context
     * as a singular template without issuing a network request.
     *
     * @param \WP_Post $post_obj Post to render.
     * @return string Rendered content snapshot.
     */
    public static function render_post_snapshot( $post_obj ) {
        global $post;

        $previous_post = $post;
        $post          = $post_obj; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
        setup_postdata( $post_obj );

        try {
            $rendered = (string) apply_filters( 'the_content', (string) $post_obj->post_content ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
        } finally {
            if ( $previous_post instanceof \WP_Post ) {
                $post = $previous_post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
                setup_postdata( $previous_post );
            } else {
                wp_reset_postdata();
                $post = $previous_post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
            }
        }

        return $rendered;
    }

    /**
     * Build local schema with the same post globals a singular request exposes.
     *
     * @param \WP_Post $post_obj Post to inspect.
     * @return array Schema engine result.
     */
    public static function schema_post_snapshot( $post_obj ) {
        global $post;

        $previous_post = $post;
        $post          = $post_obj; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
        setup_postdata( $post_obj );

        try {
            $result = ( new Schema() )->get_for_post( $post_obj->ID );
        } finally {
            if ( $previous_post instanceof \WP_Post ) {
                $post = $previous_post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
                setup_postdata( $previous_post );
            } else {
                wp_reset_postdata();
                $post = $previous_post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
            }
        }

        return is_array( $result ) ? $result : array( 'schemas' => array() );
    }

    /**
     * Apply a specific fix to a post.
     *
     * @param int    $post_id  Post ID.
     * @param string $fix_type Type of fix to apply.
     * @return array
     */
    public function apply_fix( $post_id, $fix_type, $extra = array() ) {
        switch ( $fix_type ) {
            case 'add_article_schema':
                $schema_engine = new Schema();
                $schema_data   = $schema_engine->get_for_post( $post_id );
                if ( ! empty( $schema_data['schemas'] ) ) {
                    $article_schema = $schema_data['schemas'][0];
                    if ( ! isset( $article_schema['@context'] ) ) {
                        $article_schema['@context'] = 'https://schema.org';
                    }
                    $this->merge_schema_override( $post_id, $article_schema );
                }
                break;

            case 'add_faq_schema':
                $post = get_post( $post_id );
                if ( ! $post ) break;

                $faq_preview = ( new Schema() )->get_for_post( $post_id );
                if ( 'disabled' === ( $faq_preview['source'] ?? '' ) ) {
                    return array( 'success' => false, 'message' => __( 'Enable the AEO Schema Engine for this post before generating FAQ markup.', 'aeo-god-mode' ) );
                }
                if ( in_array( 'FAQPage', (array) ( $faq_preview['disabled_types'] ?? array() ), true ) ) {
                    return array( 'success' => false, 'message' => __( 'Turn on FAQ output for this post before generating it.', 'aeo-god-mode' ) );
                }
                if ( in_array( 'FAQPage', (array) ( $faq_preview['delegated_types'] ?? array() ), true ) ) {
                    return array( 'success' => false, 'message' => __( 'FAQPage ownership is delegated to another SEO plugin. Change the FAQPage resolution in Schema Conflicts before generating with AEO God Mode.', 'aeo-god-mode' ) );
                }

                $faq    = array();
                $source = 'regex';

                // 1. Try regex extraction FIRST (free, fast, reliable for structured HTML).
                $faq = $this->extract_faq_pairs( $post->post_content );

                // 2. AI fallback only if regex found fewer than 2 pairs (Pro/Agency).
                if ( count( $faq ) < 2 && class_exists( '\AISEOGodMode\AI_Assist' ) ) {
                    $ai = new \AISEOGodMode\AI_Assist();
                        $ai_result = $ai->extract_faq( $post );
                        if ( ! is_wp_error( $ai_result ) && ! empty( $ai_result['faq_pairs'] ) && count( $ai_result['faq_pairs'] ) > count( $faq ) ) {
                            $faq    = array();
                            $source = 'ai';
                            foreach ( $ai_result['faq_pairs'] as $pair ) {
                                $faq[] = array(
                                    '@type'          => 'Question',
                                    'name'           => sanitize_text_field( $pair['question'] ),
                                    'acceptedAnswer' => array(
                                        '@type' => 'Answer',
                                        'text'  => wp_kses_post( $pair['answer'] ),
                                    ),
                                );
                            }
                        }
                    }

                if ( ! empty( $faq ) ) {
                    $schema = array(
                        '@context'   => 'https://schema.org',
                        '@type'      => 'FAQPage',
                        'mainEntity' => $faq,
                    );
                    $this->merge_schema_override( $post_id, $schema );
                    return array(
                        'success'   => true,
                        'fix_type'  => $fix_type,
                        'post_id'   => $post_id,
                        'faq_count' => count( $faq ),
                        'source'    => $source,
                    );
                }
                break;

            case 'generate_meta_description':
                $post = get_post( $post_id );
                if ( ! $post ) break;

                $result = \AISEOGodMode\MetadataGenerator::generate( $post_id );
                if ( is_wp_error( $result ) ) {
                    return array( 'success' => false, 'message' => $result->get_error_message() );
                }

                if ( empty( $result['success'] ) ) {
                    return array( 'success' => false, 'message' => $result['error'] ?? 'AI generation failed.' );
                }

                $meta_desc = sanitize_text_field( $result['result']['meta_description'] ?? '' );
                if ( ! empty( $meta_desc ) ) {
                    if ( class_exists( 'WPSEO_Meta' ) ) {
                        update_post_meta( $post_id, '_yoast_wpseo_metadesc', $meta_desc );
                    } elseif ( class_exists( 'RankMath' ) ) {
                        update_post_meta( $post_id, 'rank_math_description', $meta_desc );
                    } else {
                        update_post_meta( $post_id, '_asgm_meta_description', $meta_desc );
                    }
                    return array(
                        'success'          => true,
                        'fix_type'         => $fix_type,
                        'post_id'          => $post_id,
                        'meta_description' => $meta_desc,
                        'source'           => 'ai',
                    );
                }
                return array( 'success' => false, 'message' => 'No meta description was generated.' );
                break;

            case 'generate_aeo_titles':
                $result = \AISEOGodMode\MetadataGenerator::generate_titles( $post_id );
                if ( ! empty( $result['success'] ) ) {
                    return array(
                        'success'  => true,
                        'fix_type' => $fix_type,
                        'result'   => $result['result'], // Contains the raw completion or metadata string
                    );
                }
                return array(
                    'success' => false,
                    'message' => $result['error'] ?? 'Failed to generate titles.',
                );

            case 'save_post_title':
                $new_title = sanitize_text_field( $extra['post_title'] ?? '' );
                if ( empty( $new_title ) ) {
                    return array( 'success' => false, 'message' => 'Title is empty.' );
                }
                $update_result = wp_update_post( array(
                    'ID'         => $post_id,
                    'post_title' => $new_title,
                ) );
                if ( is_wp_error( $update_result ) ) {
                    return array( 'success' => false, 'message' => $update_result->get_error_message() );
                }
                update_post_meta( $post_id, '_asgm_generated_title', 'yes' );
                return array(
                    'success'    => true,
                    'fix_type'   => $fix_type,
                    'post_id'    => $post_id,
                    'post_title' => $new_title,
                );

            case 'add_howto_schema':
                $post = get_post( $post_id );
                if ( ! $post ) break;

                // Free must be able to complete this fix without a Pro-only
                // extractor. Use the exact deterministic parser that powers
                // preview/front-end output, so this action never spends an AI
                // credit unexpectedly or freezes a snapshot of current steps.
                $schema_engine = new Schema();
                $preview       = $schema_engine->get_for_post( $post_id );
                if ( 'disabled' === ( $preview['source'] ?? '' ) ) {
                    return array(
                        'success' => false,
                        'message' => __( 'Enable the AEO Schema Engine for this post before regenerating HowTo markup.', 'aeo-god-mode' ),
                    );
                }
                if ( in_array( 'HowTo', (array) ( $preview['disabled_types'] ?? array() ), true ) ) {
                    return array(
                        'success' => false,
                        'message' => __( 'Turn on HowTo output for this post before regenerating it.', 'aeo-god-mode' ),
                    );
                }
                if ( in_array( 'HowTo', (array) ( $preview['delegated_types'] ?? array() ), true ) ) {
                    return array(
                        'success' => false,
                        'message' => __( 'HowTo ownership is delegated to another SEO plugin. Change the HowTo resolution in Schema Conflicts before regenerating with AEO God Mode.', 'aeo-god-mode' ),
                    );
                }
                $schema        = $schema_engine->detect_howto_for_post( $post_id );
                $steps         = $schema ? (array) ( $schema['step'] ?? array() ) : array();

                if ( empty( $schema ) || count( $steps ) < 5 ) {
                    return array(
                        'success' => false,
                        'message' => __( 'No HowTo was generated. Use a “How to” title and at least five complete, sequential steps (an ordered list under a steps heading or explicit “Step 1:” sections).', 'aeo-god-mode' ),
                    );
                }

                // Automatic detection already emits this exact schema. Clear
                // any old HowTo override so future title/step edits continue
                // to update automatically instead of becoming stale again.
                $schema_engine->clear_override( $post_id, 'HowTo' );
                self::invalidate_cached_results();
                return array(
                    'success'    => true,
                    'fix_type'   => $fix_type,
                    'post_id'    => $post_id,
                    'step_count' => count( $steps ),
                    'source'     => 'pattern',
                    'persisted'  => false,
                );

            case 'analyze_citability':
                $post = get_post( $post_id );
                if ( ! $post ) break;

                if ( ! class_exists( '\AISEOGodMode\AI_Assist' ) ) {
                    return array( 'success' => false, 'message' => 'AI Assist module requires Pro.' );
                }
                $ai = new \AISEOGodMode\AI_Assist();

                $ai_result = $ai->analyze_citability( $post );
                if ( is_wp_error( $ai_result ) ) {
                    return array( 'success' => false, 'message' => $ai_result->get_error_message() );
                }

                update_post_meta( $post_id, '_asgm_ai_citability_score', intval( $ai_result['score'] ?? 0 ) );

                // Also run rule-based scoring for the breakdown tooltip.
                $rule_score = null;
                if ( class_exists( '\\AISEOGodMode\\CitabilityScore' ) ) {
                    $scorer     = new \AISEOGodMode\CitabilityScore();
                    $rule_score = $scorer->score_post( $post_id );
                }

                return array(
                    'success'         => true,
                    'fix_type'        => $fix_type,
                    'post_id'         => $post_id,
                    'score'           => $rule_score ? $rule_score['score'] : ( $ai_result['score'] ?? 0 ),
                    'breakdown'       => $rule_score ? $rule_score['breakdown'] : null,
                    'tips'            => $rule_score ? $rule_score['tips'] : array(),
                    'top_improvement' => $ai_result['top_improvement'] ?? '',
                    'source'          => 'ai',
                );

            case 'suggest_content_improvements':
                $post = get_post( $post_id );
                if ( ! $post ) break;

                if ( ! class_exists( '\AISEOGodMode\AI_Assist' ) ) {
                    return array( 'success' => false, 'message' => 'AI Assist module requires Pro.' );
                }
                $ai = new \AISEOGodMode\AI_Assist();

                $ai_result = $ai->suggest_improvements( $post );
                if ( is_wp_error( $ai_result ) ) {
                    return array( 'success' => false, 'message' => $ai_result->get_error_message() );
                }

                $count = count( $ai_result['improvements'] ?? array() );
                $grade = $ai_result['overall_aeo_grade'] ?? 'N/A';
                return array(
                    'success'      => true,
                    'fix_type'     => $fix_type,
                    'post_id'      => $post_id,
                    'improvements' => $ai_result['improvements'] ?? array(),
                    'grade'        => $grade,
                    'count'        => $count,
                    'source'       => 'ai',
                );

            case 'apply_improvement':
                $post = get_post( $post_id );
                if ( ! $post ) break;
                if ( ! class_exists( '\AISEOGodMode\AI_Assist' ) ) {
                    return array( 'success' => false, 'message' => 'AI Assist module requires Pro.' );
                }
                $suggestion = isset( $extra['suggestion'] ) ? trim( (string) wp_unslash( $extra['suggestion'] ) ) : '';
                if ( $suggestion === '' ) {
                    return array( 'success' => false, 'message' => 'No suggestion text provided.' );
                }
                $ai  = new \AISEOGodMode\AI_Assist();
                $res = $ai->apply_improvement( $post, $suggestion );
                if ( is_wp_error( $res ) ) {
                    return array( 'success' => false, 'message' => $res->get_error_message() );
                }
                $find    = isset( $res['find'] ) ? (string) $res['find'] : '';
                $replace = isset( $res['replace'] ) ? (string) $res['replace'] : '';
                $summary = isset( $res['summary'] ) ? (string) $res['summary'] : '';
                if ( $find === '' || strpos( $post->post_content, $find ) === false ) {
                    return array(
                        'success'  => false,
                        'fix_type' => $fix_type,
                        'matched'  => false,
                        'message'  => 'Could not pinpoint the exact spot to edit automatically. Here is the suggested rewrite to paste in by hand.',
                        'replace'  => $replace,
                        'summary'  => $summary,
                    );
                }
                $pos         = strpos( $post->post_content, $find );
                $new_content = substr( $post->post_content, 0, $pos ) . $replace . substr( $post->post_content, $pos + strlen( $find ) );
                return array(
                    'success'         => true,
                    'fix_type'        => $fix_type,
                    'post_id'         => $post_id,
                    'matched'         => true,
                    'applied_content' => $new_content,
                    'summary'         => $summary,
                    'source'          => 'ai',
                );

            case 'generate_speakable_summary':
                $post = get_post( $post_id );
                if ( ! $post ) break;

                if ( ! class_exists( '\AISEOGodMode\AI_Assist' ) ) {
                    return array( 'success' => false, 'message' => 'AI Assist module requires Pro.' );
                }
                $ai = new \AISEOGodMode\AI_Assist();

                $ai_result = $ai->generate_speakable( $post );
                if ( is_wp_error( $ai_result ) ) {
                    return array( 'success' => false, 'message' => $ai_result->get_error_message() );
                }

                $summary = sanitize_text_field( $ai_result['summary'] ?? '' );
                if ( ! empty( $summary ) ) {
                    update_post_meta( $post_id, '_asgm_speakable_summary', $summary );
                    return array(
                        'success'  => true,
                        'fix_type' => $fix_type,
                        'post_id'  => $post_id,
                        'summary'  => $summary,
                        'source'   => 'ai',
                    );
                }
                break;

            case 'generate_llms_description':
                $post = get_post( $post_id );
                if ( ! $post ) break;

                if ( ! class_exists( '\AISEOGodMode\AI_Assist' ) ) {
                    return array( 'success' => false, 'message' => 'AI Assist module requires Pro.' );
                }
                $ai = new \AISEOGodMode\AI_Assist();

                $ai_result = $ai->generate_llms_desc( $post );
                if ( is_wp_error( $ai_result ) ) {
                    return array( 'success' => false, 'message' => $ai_result->get_error_message() );
                }

                $desc = sanitize_text_field( $ai_result['description'] ?? '' );
                if ( ! empty( $desc ) ) {
                    update_post_meta( $post_id, '_asgm_llms_description', $desc );
                    // The generated file is cached for a day. Without dropping
                    // that transient the new description sits in the database
                    // while /llms.txt keeps serving the previous build, which
                    // reads to the customer as "the button did nothing".
                    delete_transient( 'asgm_llms_txt' );
                    return array(
                        'success'     => true,
                        'fix_type'    => $fix_type,
                        'post_id'     => $post_id,
                        'description' => $desc,
                        'source'      => 'ai',
                    );
                }
                break;

            default:
                return array( 'success' => false, 'message' => __( 'Unknown fix type.', 'aeo-god-mode' ) );
        }

        return array( 'success' => true, 'fix_type' => $fix_type, 'post_id' => $post_id );
    }

    /**
     * Merge a schema into the post's override storage.
     *
     * Stores an array of schemas keyed by @type so that adding FAQ schema
     * does not overwrite a previously added HowTo or Article schema.
     *
     * @param int   $post_id Post ID.
     * @param array $schema  The schema array (must include @type).
     */
    private function merge_schema_override( $post_id, $schema ) {
        if ( class_exists( __NAMESPACE__ . '\\Schema' ) ) {
            $result = ( new Schema() )->upsert_override( $post_id, $schema );
            if ( ! is_wp_error( $result ) ) {
                self::invalidate_cached_results();
            }
            return;
        }

        $existing_raw = get_post_meta( $post_id, '_asgm_schema_override', true );
        $existing     = array();

        if ( $existing_raw ) {
            $decoded = is_string( $existing_raw ) ? json_decode( $existing_raw, true ) : $existing_raw;
            if ( $decoded ) {
                // Legacy: single schema object (has @type at top level).
                if ( isset( $decoded['@type'] ) ) {
                    $existing[ $decoded['@type'] ] = $decoded;
                }
                // New: keyed array of schemas.
                elseif ( is_array( $decoded ) ) {
                    $existing = $decoded;
                }
            }
        }

        $type = isset( $schema['@type'] ) ? $schema['@type'] : 'unknown';

        // Guarantee structural properties are present.
        if ( ! isset( $schema['@context'] ) ) {
            $schema['@context'] = 'https://schema.org';
        }
        if ( ! isset( $schema['@type'] ) ) {
            $schema['@type'] = $type;
        }

        $existing[ $type ] = $schema;

        // If only one schema, store it flat for backward compatibility.
        if ( count( $existing ) === 1 ) {
            update_post_meta( $post_id, '_asgm_schema_override', wp_json_encode( reset( $existing ) ) );
        } else {
            update_post_meta( $post_id, '_asgm_schema_override', wp_json_encode( $existing ) );
        }
    }

    /**
     * Extract FAQ question/answer pairs from content.
     *
     * Supports all major WordPress FAQ formats:
     * 1. Yoast FAQ block markup
     * 2. Rank Math FAQ block markup
     * 3. [q]...[/q] [a]...[/a] shortcodes
     * 4. HTML5 <details><summary> accordions
     * 5. <dt>/<dd> definition lists within FAQ context
     * 6. Accordion divs with common FAQ CSS classes
     * 7. Heading tags (h2-h4) ending in ? followed by paragraph content
     * 8. Text-based Q:/A: patterns
     *
     * @param string $content Post content (raw post_content).
     * @return array Array of schema.org Question entities.
     */
    private function extract_faq_pairs( $content ) {
        $pairs = array();

        // ---------------------------------------------------------------
        // 1. Yoast FAQ block (<!-- wp:yoast/faq-block -->)
        //    Structure: <div class="schema-faq-section">
        //      <strong class="schema-faq-question">Q</strong>
        //      <p class="schema-faq-answer">A</p>
        //    </div>
        // ---------------------------------------------------------------
        if ( false !== strpos( $content, 'schema-faq-question' ) ) {
            if ( preg_match_all(
                '/<[^>]*class="[^"]*schema-faq-question[^"]*"[^>]*>(.*?)<\/[^>]+>\s*<[^>]*class="[^"]*schema-faq-answer[^"]*"[^>]*>(.*?)<\/[^>]+>/si',
                $content, $matches, PREG_SET_ORDER
            ) ) {
                foreach ( $matches as $m ) {
                    $pairs[] = $this->build_faq_pair( $m[1], $m[2] );
                }
                return $pairs;
            }
        }

        // ---------------------------------------------------------------
        // 2. Rank Math FAQ block
        //    Rendered structure:
        //    <div class="wp-block-rank-math-faq-block">
        //      <div class="rank-math-faq-item">
        //        <h3 class="rank-math-question">Q</h3>
        //        <div class="rank-math-answer"><p>A</p></div>
        //      </div>
        //    </div>
        //    Note: question and answer are siblings inside a wrapper div,
        //    so we match within each faq-item container.
        // ---------------------------------------------------------------
        if ( false !== strpos( $content, 'rank-math-question' ) ) {
            if ( preg_match_all(
                '/<[^>]*class="[^"]*rank-math-faq-item[^"]*"[^>]*>\s*<[^>]*class="[^"]*rank-math-question[^"]*"[^>]*>(.*?)<\/[^>]+>\s*<[^>]*class="[^"]*rank-math-answer[^"]*"[^>]*>(.*?)<\/[^>]+>/si',
                $content, $matches, PREG_SET_ORDER
            ) ) {
                foreach ( $matches as $m ) {
                    $pairs[] = $this->build_faq_pair( $m[1], $m[2] );
                }
                return $pairs;
            }
            // Fallback: question/answer as direct siblings (no wrapper).
            if ( preg_match_all(
                '/<[^>]*class="[^"]*rank-math-question[^"]*"[^>]*>(.*?)<\/[^>]+>\s*<[^>]*class="[^"]*rank-math-answer[^"]*"[^>]*>(.*?)<\/[^>]+>/si',
                $content, $matches, PREG_SET_ORDER
            ) ) {
                foreach ( $matches as $m ) {
                    $pairs[] = $this->build_faq_pair( $m[1], $m[2] );
                }
                return $pairs;
            }
        }

        // ---------------------------------------------------------------
        // 3. [q]...[/q] [a]...[/a] shortcodes
        // ---------------------------------------------------------------
        if ( preg_match_all( '/\[q\](.*?)\[\/q\]\s*\[a\](.*?)\[\/a\]/si', $content, $matches, PREG_SET_ORDER ) ) {
            foreach ( $matches as $m ) {
                $pairs[] = $this->build_faq_pair( $m[1], $m[2] );
            }
            return $pairs;
        }

        // ---------------------------------------------------------------
        // 4. HTML5 <details><summary>Question</summary>Answer</details>
        // ---------------------------------------------------------------
        if ( preg_match_all(
            '/<details[^>]*>\s*<summary[^>]*>(.*?)<\/summary>(.*?)<\/details>/si',
            $content, $matches, PREG_SET_ORDER
        ) ) {
            foreach ( $matches as $m ) {
                $pairs[] = $this->build_faq_pair( $m[1], $m[2] );
            }
            if ( ! empty( $pairs ) ) {
                return $pairs;
            }
        }

        // ---------------------------------------------------------------
        // 5. <dt>/<dd> definition lists in FAQ context
        // ---------------------------------------------------------------
        if ( false !== strpos( $content, '<dt' ) && false !== strpos( $content, '<dd' ) ) {
            if ( preg_match_all(
                '/<dt[^>]*>(.*?)<\/dt>\s*<dd[^>]*>(.*?)<\/dd>/si',
                $content, $matches, PREG_SET_ORDER
            ) ) {
                foreach ( $matches as $m ) {
                    $q = wp_strip_all_tags( trim( $m[1] ) );
                    // Only treat as FAQ if the term looks like a question.
                    if ( false !== strpos( $q, '?' ) || strlen( $q ) > 15 ) {
                        $pairs[] = $this->build_faq_pair( $m[1], $m[2] );
                    }
                }
                if ( ! empty( $pairs ) ) {
                    return $pairs;
                }
            }
        }

        // ---------------------------------------------------------------
        // 6. Accordion / toggle divs (Elementor, generic, custom themes)
        //    Common class patterns: faq-question/faq-answer,
        //    accordion-title/accordion-content, elementor-tab-title/content,
        //    toggle-title/toggle-content, vc_toggle
        // ---------------------------------------------------------------
        $accordion_patterns = array(
            // Generic FAQ classes
            '/<[^>]*class="[^"]*faq[_-]?question[^"]*"[^>]*>(.*?)<\/[^>]+>\s*<[^>]*class="[^"]*faq[_-]?answer[^"]*"[^>]*>(.*?)<\/[^>]+>/si',
            // Accordion title/content
            '/<[^>]*class="[^"]*accordion[_-]?title[^"]*"[^>]*>(.*?)<\/[^>]+>\s*<[^>]*class="[^"]*accordion[_-]?(?:content|body|panel)[^"]*"[^>]*>(.*?)<\/[^>]+>/si',
            // Toggle title/content (Elementor, WPBakery)
            '/<[^>]*class="[^"]*toggle[_-]?title[^"]*"[^>]*>(.*?)<\/[^>]+>\s*<[^>]*class="[^"]*toggle[_-]?content[^"]*"[^>]*>(.*?)<\/[^>]+>/si',
            // Elementor toggle widget
            '/<[^>]*class="[^"]*elementor-tab-title[^"]*"[^>]*>(.*?)<\/[^>]+>\s*<[^>]*class="[^"]*elementor-tab-content[^"]*"[^>]*>(.*?)<\/[^>]+>/si',
        );

        foreach ( $accordion_patterns as $pattern ) {
            if ( preg_match_all( $pattern, $content, $matches, PREG_SET_ORDER ) ) {
                foreach ( $matches as $m ) {
                    $pairs[] = $this->build_faq_pair( $m[1], $m[2] );
                }
                if ( ! empty( $pairs ) ) {
                    return $pairs;
                }
            }
        }

        // ---------------------------------------------------------------
        // 7. Heading tags (h2-h4) ending in ? followed by <p> content
        //    This catches the very common pattern of FAQ sections built
        //    with standard headings and paragraphs.
        // ---------------------------------------------------------------
        if ( preg_match_all(
            '/<h[2-4][^>]*>([^<]*\?)\s*<\/h[2-4]>\s*(<p[^>]*>.*?<\/p>(?:\s*<p[^>]*>.*?<\/p>)*)/si',
            $content, $matches, PREG_SET_ORDER
        ) ) {
            foreach ( $matches as $m ) {
                $pairs[] = $this->build_faq_pair( $m[1], $m[2] );
            }
            if ( ! empty( $pairs ) ) {
                return $pairs;
            }
        }

        // ---------------------------------------------------------------
        // 8. Text-based Q:/A: or Question:/Answer: patterns (fallback)
        // ---------------------------------------------------------------
        $stripped = wp_strip_all_tags( $content );
        if ( preg_match_all(
            '/(?:Q:|Question:)\s*(.+?)\n+(?:A:|Answer:)\s*(.+?)(?=\n\s*(?:Q:|Question:)|\n\n\n|$)/si',
            $stripped, $matches, PREG_SET_ORDER
        ) ) {
            foreach ( $matches as $m ) {
                $pairs[] = $this->build_faq_pair( $m[1], $m[2] );
            }
        }

        return $pairs;
    }

    /**
     * Build a single FAQ schema pair (Question + AcceptedAnswer).
     *
     * @param string $question Raw question text (may contain HTML).
     * @param string $answer   Raw answer text (may contain HTML).
     * @return array Schema.org Question entity.
     */
    private function build_faq_pair( $question, $answer ) {
        return array(
            '@type'          => 'Question',
            'name'           => wp_strip_all_tags( trim( $question ) ),
            'acceptedAnswer' => array(
                '@type' => 'Answer',
                'text'  => wp_strip_all_tags( trim( $answer ) ),
            ),
        );
    }

    // -----------------------------------------------------------------------
    // Detection Helpers
    // -----------------------------------------------------------------------

    /**
     * Check if auto-detected schema would fire for this post.
     *
     * @param \WP_Post $post Post.
     * @return bool
     */
    private function has_auto_schema( $post, $schemas = null ) {
        if ( null === $schemas ) {
            $schema_engine = new Schema();
            $result        = $schema_engine->get_for_post( $post->ID );
            $schemas       = (array) ( $result['schemas'] ?? array() );
        }
        if ( empty( $schemas ) ) {
            return false;
        }
        // Breadcrumb alone doesn't count as meaningful schema coverage.
        foreach ( $schemas as $schema ) {
            $type = isset( $schema['@type'] ) ? $schema['@type'] : '';
            if ( $type && 'BreadcrumbList' !== $type ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Detect FAQ-style content across all common WordPress patterns.
     *
     * Checks for: Yoast/Rank Math FAQ blocks, shortcodes, HTML5 details,
     * definition lists, accordion markup, question headings, and text Q/A.
     *
     * @param string $content Post content (raw post_content).
     * @return bool
     */
    private function has_faq_content( $content ) {
        if ( class_exists( __NAMESPACE__ . '\\FaqParser' ) ) {
            $own_faq = FaqParser::parse_aeogm( $content );
            if ( ! empty( $own_faq['pairs'] ) ) {
                return true;
            }
        }

        // Gutenberg FAQ blocks (Yoast, Rank Math).
        if ( false !== strpos( $content, 'wp:yoast/faq-block' ) ) {
            return true;
        }
        if ( false !== strpos( $content, 'wp:rank-math/faq-block' ) ) {
            return true;
        }

        // FAQ shortcodes, including AEO God Mode's own accordion contract.
        if ( preg_match( '/\[(?:aeogm_faqs?|faq|accordion|toggle|question|answer)\b/i', $content ) ) {
            return true;
        }

        // Schema FAQ classes from Yoast / Rank Math.
        if ( false !== strpos( $content, 'schema-faq-question' ) ) {
            return true;
        }
        if ( false !== strpos( $content, 'rank-math-question' ) ) {
            return true;
        }

        // HTML5 details/summary (native accordion).
        if ( false !== strpos( $content, '<details' ) && false !== strpos( $content, '<summary' ) ) {
            return true;
        }

        // Common FAQ CSS class patterns.
        if ( preg_match( '/class="[^"]*(?:faq[_-]?(?:item|question|section|list)|accordion[_-]?(?:item|title|header))[^"]*"/i', $content ) ) {
            return true;
        }

        // Definition lists with question-like terms.
        if ( false !== strpos( $content, '<dt' ) && false !== strpos( $content, '<dd' ) ) {
            if ( preg_match( '/<dt[^>]*>[^<]*\?<\/dt>/i', $content ) ) {
                return true;
            }
        }

        // Heading tags ending in ? (strong signal of FAQ content).
        if ( preg_match( '/<h[2-4][^>]*>[^<]*\?\s*<\/h[2-4]>/i', $content ) ) {
            // Count how many question headings. Single one could be anything,
            // but 2+ strongly suggests an FAQ section.
            preg_match_all( '/<h[2-4][^>]*>[^<]*\?\s*<\/h[2-4]>/i', $content, $qh );
            if ( count( $qh[0] ) >= 2 ) {
                return true;
            }
        }

        // Text-based patterns: Q:, Question:, FAQ heading, "Frequently Asked".
        if ( preg_match( '/(?:^|\n)\s*(?:Q\d*[\.:]\s|Question\s*\d*[\.:]\s)/mi', wp_strip_all_tags( $content ) ) ) {
            return true;
        }

        // Section heading containing "FAQ" or "Frequently Asked".
        if ( preg_match( '/<h[2-4][^>]*>[^<]*(?:FAQ|Frequently\s+Asked)[^<]*<\/h[2-4]>/i', $content ) ) {
            return true;
        }

        return false;
    }

    /**
     * Check for existing FAQ schema from any source.
     *
     * Covers: our schema override, Yoast FAQ blocks (auto-generate schema),
     * Rank Math FAQ blocks (auto-generate schema), Yoast/Rank Math meta,
     * [faq] shortcodes that produce schema via theme, and third-party
     * FAQ plugins' shortcodes that auto-generate schema.
     *
     * @param \WP_Post $post Post.
     * @return bool
     */
    private function has_faq_schema( $post, $schemas = null ) {
        $content = $post->post_content;

        // AEO God Mode's schema engine is the source of truth for its own
        // automatic markup. If it emits FAQPage, the editor checker must not
        // call the same content an unhandled FAQ opportunity.
        if ( $this->has_generated_schema_type( $post, 'FAQPage', $schemas ) ) {
            return true;
        }

        // Yoast FAQ block auto-generates FAQPage schema on the front end.
        if ( false !== strpos( $content, 'wp:yoast/faq-block' ) ) {
            return true;
        }

        // Rank Math FAQ block auto-generates FAQPage schema on the front end.
        // Check both block comment format and rendered CSS class format.
        if ( false !== strpos( $content, 'wp:rank-math/faq-block' ) ) {
            return true;
        }
        if ( false !== strpos( $content, 'wp-block-rank-math-faq-block' ) ) {
            return true;
        }
        if ( false !== strpos( $content, 'rank-math-question' ) ) {
            return true;
        }

        // Generic [faq] shortcodes may be handled by their owning third-party
        // plugin. Our own [aeogm_faq] is intentionally not counted here: its
        // truth comes from has_generated_schema_type(), which honours module
        // and per-post disable switches.
        if ( preg_match_all( '/\[faq\b[^\]]*\]/i', $content, $shortcode_matches ) ) {
            if ( count( $shortcode_matches[0] ) >= 2 ) {
                return true;
            }
        }

        // [faq] / [faq title="..."] shortcodes. Many themes/plugins generate
        // FAQPage schema from these, and AEO God Mode's own Schema_Generator
        // produces FAQPage schema for [faq title="..."]...[/faq] pairs.
        // Require 2+ pairs to mirror what detect_faq_schema() actually emits.
        if ( preg_match_all( '/\[faq\b[^\]]*\]/i', $content, $shortcode_matches ) ) {
            if ( count( $shortcode_matches[0] ) >= 2 ) {
                return true;
            }
        }

        // Yoast schema page type meta.
        $yoast_schema = get_post_meta( $post->ID, '_yoast_wpseo_schema_page_type', true );
        if ( $yoast_schema && false !== strpos( $yoast_schema, 'FAQ' ) ) {
            return true;
        }

        // Rank Math rich snippet type.
        $rm_snippet = get_post_meta( $post->ID, 'rank_math_rich_snippet', true );
        if ( 'faq' === $rm_snippet ) {
            return true;
        }

        // Rank Math schema FAQ type meta.
        $rm_schema = get_post_meta( $post->ID, 'rank_math_schema_FAQPage', true );
        if ( ! empty( $rm_schema ) ) {
            return true;
        }

        return false;
    }

    /**
     * Detect HowTo-style content.
     *
     * @param \WP_Post $post Post.
     * @return bool
     */
    private function has_howto_content( $post, $schemas = null ) {
        if ( get_post_meta( $post->ID, '_asgm_disable_schema', true ) || get_post_meta( $post->ID, '_asgm_disable_howto', true ) ) {
            return false;
        }

        // Rank Math / Yoast HowTo blocks.
        if ( false !== strpos( $post->post_content, 'wp:yoast/how-to-block' ) ) {
            return true;
        }
        if ( false !== strpos( $post->post_content, 'wp:rank-math/howto-block' ) ) {
            return true;
        }

        // Eligibility is distinct from effective output. Asking the merged
        // graph here made the old opportunity condition logically impossible
        // because has_howto_schema() queried that same graph again.
        return class_exists( __NAMESPACE__ . '\\Schema' )
            && null !== ( new Schema() )->detect_howto_for_post( $post->ID );
    }

    /**
     * Check for existing HowTo schema.
     *
     * @param \WP_Post $post Post.
     * @return bool
     */
    private function has_howto_schema( $post, $schemas = null ) {
        if ( $this->has_generated_schema_type( $post, 'HowTo', $schemas ) ) {
            return true;
        }

        // Yoast HowTo block auto-generates schema.
        if ( false !== strpos( $post->post_content, 'wp:yoast/how-to-block' ) ) {
            return true;
        }
        // Rank Math HowTo block auto-generates schema.
        if ( false !== strpos( $post->post_content, 'wp:rank-math/howto-block' ) ) {
            return true;
        }
        // Rank Math rich snippet type.
        $rm_snippet = get_post_meta( $post->ID, 'rank_math_rich_snippet', true );
        if ( 'howto' === $rm_snippet ) {
            return true;
        }
        return false;
    }

    /**
     * Whether AEO God Mode's automatic schema graph contains a type.
     *
     * This deliberately asks the schema engine instead of duplicating its
     * content detectors. It is the shared contract used by the generator,
     * front-end output and editor checker.
     *
     * @param \WP_Post $post Post object.
     * @param string   $type Schema.org type.
     * @return bool
     */
    private function has_generated_schema_type( $post, $type, $schemas = null ) {
        if ( null === $schemas ) {
            $previous_post  = isset( $GLOBALS['post'] ) ? $GLOBALS['post'] : null;
            $GLOBALS['post'] = $post;
            $result          = self::schema_post_snapshot( $post );
            $schemas         = (array) ( $result['schemas'] ?? array() );

            if ( null !== $previous_post ) {
                $GLOBALS['post'] = $previous_post;
            } else {
                unset( $GLOBALS['post'] );
            }
        }

        foreach ( (array) $schemas as $schema ) {
            if ( $type === ( $schema['@type'] ?? '' ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check for definitive answer structure (H2 followed by a direct paragraph).
     *
     * @param string $content Post content.
     * @return bool
     */
    private function has_definitive_answer_structure( $content ) {
        // Use the same detector that powers Answer Density when a question-shaped
        // heading exists. This prevents one panel from saying "direct" while the
        // other says "no answer structure" for the exact same opener.
        if ( class_exists( '\\AISEOGodMode\\Answer_Density' ) ) {
            $headings = Answer_Density::find_question_headings( $content );
            foreach ( $headings as $heading ) {
                $body = Answer_Density::extract_after_heading(
                    $content,
                    $heading['end'],
                    $heading['level']
                );
                $classification = Answer_Density::classify_answer( $body );
                if ( in_array( $classification['classification'], array( 'direct', 'buried' ), true ) ) {
                    return true;
                }
            }
        }

        // Gutenberg stores block delimiters between the closing heading and the
        // opening paragraph. The former `\s*<p>` check treated those comments as
        // content and falsely failed valid answer-first posts after a block edit.
        $without_block_comments = preg_replace( '/<!--\s*\/?wp:[\s\S]*?-->/', '', (string) $content );

        return (bool) preg_match(
            '/<h[23]\b[^>]*>.*?<\/h[23]>\s*<p\b[^>]*>/si',
            $without_block_comments
        );
    }
}
