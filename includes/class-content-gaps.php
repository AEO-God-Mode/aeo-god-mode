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

    /**
     * Get cached scan results.
     *
     * @return array
     */
    public function get_results() {
        $results   = get_option( 'asgm_content_gap_results', array() );
        $last_scan = get_option( 'asgm_content_gap_last_scan', '' );

        return array(
            'results'   => $results,
            'last_scan' => $last_scan,
            'count'     => count( $results ),
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

                if ( ! empty( $gaps['issues'] ) ) {
                    $results[] = $gaps;
                }
            }
            wp_reset_postdata();
        }

        // Sort by gap score descending.
        usort( $results, function( $a, $b ) {
            return $b['gap_score'] - $a['gap_score'];
        } );

        update_option( 'asgm_content_gap_results', $results );
        update_option( 'asgm_content_gap_last_scan', current_time( 'mysql' ) );

        // Invalidate the site-health cache so the dashboard picks up any changes.
        delete_transient( 'asgm_site_health' );

        return array(
            'results'   => $results,
            'last_scan' => get_option( 'asgm_content_gap_last_scan' ),
            'count'     => count( $results ),
        );
    }



    /**
     * Analyze a single post for gaps.
     *
     * @param \WP_Post $post Post object.
     * @return array
     */
    private function analyze_post( $post ) {
        $issues   = array();
        $content  = $post->post_content;
        $wc       = str_word_count( wp_strip_all_tags( $content ) );

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
        $has_schema = get_post_meta( $post->ID, '_asgm_schema_override', true );
        $has_yoast_schema = get_post_meta( $post->ID, '_yoast_wpseo_schema_page_type', true );
        $has_rm_schema    = get_post_meta( $post->ID, 'rank_math_rich_snippet', true );

        $auto_schema = $this->has_auto_schema( $post );

        if ( ! $has_schema && ! $has_yoast_schema && ! $has_rm_schema && ! $auto_schema ) {
            $issues[] = array(
                'type'     => 'no_schema',
                'severity' => 'error',
                'message'  => __( 'No JSON-LD schema markup on this page.', 'aeo-god-mode' ),
                'fix_type' => 'add_article_schema',
            );
        }

        // 2b. Schema quality check (validate field completeness when schema exists).
        if ( $has_schema || $has_yoast_schema || $has_rm_schema || $auto_schema ) {
            $validator  = new Validator();
            $val_result = $validator->validate_post( $post->ID );
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

        // 3. FAQ opportunity.
        if ( $this->has_faq_content( $content ) && ! $this->has_faq_schema( $post ) ) {
            $issues[] = array(
                'type'     => 'faq_opportunity',
                'severity' => 'info',
                'message'  => __( 'FAQ-style content detected but no FAQPage schema.', 'aeo-god-mode' ),
                'fix_type' => 'add_faq_schema',
            );
        }

        // 4. HowTo opportunity.
        if ( $this->has_howto_content( $post ) && ! $this->has_howto_schema( $post ) ) {
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

        if ( empty( $has_yoast_desc ) && empty( $has_rm_desc ) && empty( $has_asgm_desc ) && empty( $has_aioseo ) && ! $rm_auto ) {
            $issues[] = array(
                'type'     => 'no_meta_description',
                'severity' => 'warning',
                'message'  => __( 'No meta description set (Yoast, Rank Math, or AIOSEO).', 'aeo-god-mode' ),
                'fix_type' => 'generate_meta_description',
            );
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
            if ( $wc >= 300 && ! $this->has_definitive_answer_structure( $content ) ) {
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
            $settings = get_option( 'asgm_settings', array() );
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

        // Calculate gap score with weighted issue types.
        $weights = array(
            'thin_content'           => 25,
            'no_schema'              => 20,
            'schema_errors'          => 15,
            'no_meta_description'    => 15,
            'aeo_weak'               => 12,
            'faq_opportunity'        => 8,
            'howto_opportunity'      => 8,
            'content_improvements'   => 5,
            'schema_warnings'        => 3,
            'no_speakable'           => 2,
            'no_llms_desc'           => 2,
        );
        $gap_score = 0;
        foreach ( $issues as $issue ) {
            $gap_score += isset( $weights[ $issue['type'] ] ) ? $weights[ $issue['type'] ] : 10;
        }

        $gap_score = min( 100, $gap_score );

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
        elseif ( $rm_auto )                  { $meta_source = 'Rank Math (auto-template)'; }

        // Schema: identify which types are active.
        $schema_sources = array();
        if ( $auto_schema )      $schema_sources[] = 'AEO God Mode (auto)';
        if ( $has_schema )       $schema_sources[] = 'AEO God Mode (custom)';
        if ( $has_yoast_schema ) $schema_sources[] = 'Yoast';
        if ( $has_rm_schema )    $schema_sources[] = 'Rank Math';

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
            'has_faq_schema'  => $this->has_faq_schema( $post ),
            'has_howto'       => $this->has_howto_content( $post ),
            'has_howto_schema' => $this->has_howto_schema( $post ),
            'has_speakable'   => ! empty( get_post_meta( $post->ID, '_asgm_speakable_summary', true ) ),
            'has_llms_desc'   => ! empty( get_post_meta( $post->ID, '_asgm_llms_description', true ) ),
        );

        return array(
            'post_id'           => $post->ID,
            'title'             => get_the_title( $post ),
            'url'               => get_permalink( $post ),
            'post_type'         => $post->post_type,
            'word_count'        => $wc,
            'gap_score'         => $gap_score,
            'gap_count'         => count( $issues ),
            'issues'            => $issues,
            'applicable_checks' => $applicable,
            'details'           => $details,
        );
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

                $steps = array();
                if ( class_exists( '\AISEOGodMode\AI_Assist' ) ) {
                    $ai = new \AISEOGodMode\AI_Assist();
                    $ai_result = $ai->extract_howto_steps( $post );
                    if ( ! is_wp_error( $ai_result ) && ! empty( $ai_result['steps'] ) ) {
                        foreach ( $ai_result['steps'] as $s ) {
                            $steps[] = array(
                                '@type' => 'HowToStep',
                                'name'  => sanitize_text_field( $s['name'] ?? '' ),
                                'text'  => wp_kses_post( $s['text'] ?? '' ),
                            );
                        }
                    }
                }

                $schema = array(
                    '@context' => 'https://schema.org',
                    '@type'    => 'HowTo',
                    'name'     => $post->post_title,
                    'step'     => $steps,
                );
                if ( ! empty( $ai_result['total_time'] ) ) {
                    $schema['totalTime'] = sanitize_text_field( $ai_result['total_time'] );
                }
                $this->merge_schema_override( $post_id, $schema );
                return array(
                    'success'    => true,
                    'fix_type'   => $fix_type,
                    'post_id'    => $post_id,
                    'step_count' => count( $steps ),
                    'source'     => ! empty( $steps ) ? 'ai' : 'empty',
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

                update_post_meta( $post_id, '_asgm_citability_score', intval( $ai_result['score'] ?? 0 ) );

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
    private function has_auto_schema( $post ) {
        $schema_engine = new Schema();
        $result = $schema_engine->get_for_post( $post->ID );
        if ( empty( $result['schemas'] ) ) {
            return false;
        }
        // Breadcrumb alone doesn't count as meaningful schema coverage.
        foreach ( $result['schemas'] as $schema ) {
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
        // Gutenberg FAQ blocks (Yoast, Rank Math).
        if ( false !== strpos( $content, 'wp:yoast/faq-block' ) ) {
            return true;
        }
        if ( false !== strpos( $content, 'wp:rank-math/faq-block' ) ) {
            return true;
        }

        // FAQ shortcodes ([faq], [q], or third-party like [accordion]).
        if ( preg_match( '/\[(faq|accordion|toggle|question|answer)\b/i', $content ) ) {
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
    private function has_faq_schema( $post ) {
        $content = $post->post_content;

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

        // [faq] / [faq title="..."] shortcodes. Many themes/plugins generate
        // FAQPage schema from these, and AEO God Mode's own Schema_Generator
        // produces FAQPage schema for [faq title="..."]...[/faq] pairs.
        // Require 2+ pairs to mirror what detect_faq_schema() actually emits.
        if ( preg_match_all( '/\[faq\b[^\]]*\]/i', $content, $shortcode_matches ) ) {
            if ( count( $shortcode_matches[0] ) >= 2 ) {
                return true;
            }
        }

        // Our plugin's schema override.
        $override = get_post_meta( $post->ID, '_asgm_schema_override', true );
        if ( $override && false !== strpos( $override, 'FAQPage' ) ) {
            return true;
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
    private function has_howto_content( $post ) {
        // Title starts with "How to".
        if ( preg_match( '/^how\s+to\b/i', $post->post_title ) ) {
            return true;
        }
        // Rank Math / Yoast HowTo blocks.
        if ( false !== strpos( $post->post_content, 'wp:yoast/how-to-block' ) ) {
            return true;
        }
        if ( false !== strpos( $post->post_content, 'wp:rank-math/howto-block' ) ) {
            return true;
        }
        return false;
    }

    /**
     * Check for existing HowTo schema.
     *
     * @param \WP_Post $post Post.
     * @return bool
     */
    private function has_howto_schema( $post ) {
        $override = get_post_meta( $post->ID, '_asgm_schema_override', true );
        if ( $override && false !== strpos( $override, 'HowTo' ) ) {
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
     * Check for definitive answer structure (H2 followed by a direct paragraph).
     *
     * @param string $content Post content.
     * @return bool
     */
    private function has_definitive_answer_structure( $content ) {
        return (bool) preg_match( '/<h[23][^>]*>.*?<\/h[23]>\s*<p>/si', $content );
    }
}
