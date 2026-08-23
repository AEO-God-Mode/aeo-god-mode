<?php
/**
 * REST API controller.
 *
 * @package AISEOGodMode
 */

namespace AISEOGodMode;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Handles all REST API endpoints for the React admin.
 */
class API {

    /**
     * API namespace.
     *
     * @var string
     */
    const NAMESPACE = 'aeo-god-mode/v1';

    /**
     * Register all REST routes.
     */
    public function register_routes() {

        // ---- Dashboard ----
        register_rest_route( self::NAMESPACE, '/status', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'get_status' ),
            'permission_callback' => array( $this, 'admin_permission' ),
        ) );

        register_rest_route( self::NAMESPACE, '/site-health', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'get_site_health' ),
            'permission_callback' => array( $this, 'admin_permission' ),
        ) );

        // ---- Settings ----
        register_rest_route( self::NAMESPACE, '/settings', array(
            array(
                'methods'             => 'GET',
                'callback'            => array( $this, 'get_settings' ),
                'permission_callback' => array( $this, 'admin_permission' ),
            ),
            array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'save_settings' ),
                'permission_callback' => array( $this, 'admin_permission' ),
            ),
        ) );

        // ---- Detect SEO plugins ----
        register_rest_route( self::NAMESPACE, '/detect', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'detect_plugins' ),
            'permission_callback' => array( $this, 'admin_permission' ),
        ) );

        // ---- Import from detected plugin ----
        register_rest_route( self::NAMESPACE, '/import', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'import_settings' ),
            'permission_callback' => array( $this, 'admin_permission' ),
        ) );

        // ---- Direct Answer Engine (Answer Density) ----
        register_rest_route( self::NAMESPACE, '/answer-density', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'get_answer_density_summary' ),
            'permission_callback' => array( $this, 'admin_permission' ),
        ) );

        register_rest_route( self::NAMESPACE, '/answer-density/(?P<post_id>\d+)', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'get_answer_density_for_post' ),
            'permission_callback' => array( $this, 'edit_post_permission' ),
        ) );

        register_rest_route( self::NAMESPACE, '/answer-density/rescan/(?P<post_id>\d+)', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'rescan_answer_density' ),
            'permission_callback' => array( $this, 'edit_post_permission' ),
        ) );

        register_rest_route( self::NAMESPACE, '/answer-density/rewrite-opener', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'rewrite_opener' ),
            'permission_callback' => array( $this, 'edit_post_permission' ),
            'args'                => array(
                'post_id'        => array( 'required' => true ),
                'heading'        => array( 'required' => true ),
                'classification' => array( 'required' => true ),
                'editor_content' => array(
                    'required' => false,
                    'type'     => 'string',
                ),
            ),
        ) );

        register_rest_route( self::NAMESPACE, '/answer-density/apply-rewrite', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'apply_rewrite' ),
            'permission_callback' => array( $this, 'edit_post_permission' ),
            'args'                => array(
                'post_id'        => array( 'required' => true ),
                'heading'        => array( 'required' => true ),
                'rewrite'        => array( 'required' => true ),
                // Gutenberg sends its current serialized document so an
                // Answer Density fix cannot discard unrelated unsaved edits.
                // When omitted (Dashboard flow), the endpoint persists the
                // transformed saved post directly as before.
                'editor_content' => array(
                    'required' => false,
                    'type'     => 'string',
                ),
            ),
        ) );

        register_rest_route( self::NAMESPACE, '/answer-density/scan-all', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'scan_all_answer_density' ),
            'permission_callback' => array( $this, 'admin_permission' ),
        ) );

        register_rest_route( self::NAMESPACE, '/answer-density/(?P<post_id>\d+)/dismiss', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'dismiss_answer_density' ),
            'permission_callback' => array( $this, 'edit_post_permission' ),
            'args'                => array(
                'heading' => array( 'required' => true ),
                'all'     => array(
                    'required' => false,
                    'type'     => 'boolean',
                ),
            ),
        ) );

        register_rest_route( self::NAMESPACE, '/answer-density/(?P<post_id>\d+)/undismiss', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'undismiss_answer_density' ),
            'permission_callback' => array( $this, 'edit_post_permission' ),
            'args'                => array(
                'heading' => array( 'required' => true ),
                'all'     => array(
                    'required' => false,
                    'type'     => 'boolean',
                ),
            ),
        ) );

        // ---- Schema ----
        register_rest_route( self::NAMESPACE, '/schema/(?P<post_id>\d+)', array(
            array(
                'methods'             => 'GET',
                'callback'            => array( $this, 'get_schema' ),
                'permission_callback' => array( $this, 'admin_permission' ),
            ),
            array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'save_schema' ),
                'permission_callback' => array( $this, 'admin_permission' ),
            ),
            array(
                'methods'             => 'DELETE',
                'callback'            => array( $this, 'clear_schema_override' ),
                'permission_callback' => array( $this, 'admin_permission' ),
            ),
        ) );

        register_rest_route( self::NAMESPACE, '/schema/(?P<post_id>\d+)/output', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'set_schema_type_output' ),
            'permission_callback' => array( $this, 'admin_permission' ),
            'args'                => array(
                'type'    => array( 'required' => true ),
                'enabled' => array( 'required' => true ),
            ),
        ) );

        // ---- Robots ----
        register_rest_route( self::NAMESPACE, '/robots', array(
            array(
                'methods'             => 'GET',
                'callback'            => array( $this, 'get_robots' ),
                'permission_callback' => array( $this, 'admin_permission' ),
            ),
            array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'save_robots' ),
                'permission_callback' => array( $this, 'admin_permission' ),
            ),
        ) );

        // ---- LLMS.txt ----
        register_rest_route( self::NAMESPACE, '/llms', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'get_llms' ),
            'permission_callback' => array( $this, 'admin_permission' ),
        ) );

        register_rest_route( self::NAMESPACE, '/llms/regenerate', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'regenerate_llms' ),
            'permission_callback' => array( $this, 'admin_permission' ),
        ) );

        register_rest_route( self::NAMESPACE, '/llms/manual', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'save_llms_manual' ),
            'permission_callback' => array( $this, 'admin_permission' ),
        ) );

        register_rest_route( self::NAMESPACE, '/llms/manual/revert', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'revert_llms_manual' ),
            'permission_callback' => array( $this, 'admin_permission' ),
        ) );

        register_rest_route( self::NAMESPACE, '/llms/custom', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'save_llms_custom' ),
            'permission_callback' => array( $this, 'admin_permission' ),
        ) );

        // ---- Conflicts ----
        register_rest_route( self::NAMESPACE, '/conflicts', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'get_conflicts' ),
            'permission_callback' => array( $this, 'admin_permission' ),
        ) );

        register_rest_route( self::NAMESPACE, '/conflicts/(?P<id>[\w-]+)/resolve', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'resolve_conflict' ),
            'permission_callback' => array( $this, 'admin_permission' ),
        ) );

        register_rest_route( self::NAMESPACE, '/conflicts/schema-compare', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'get_schema_comparison' ),
            'permission_callback' => array( $this, 'admin_permission' ), // Admin-only: reads plugin detection data.
        ) );

        register_rest_route( self::NAMESPACE, '/conflicts/schema-type', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'resolve_schema_type' ),
            'permission_callback' => array( $this, 'admin_permission' ), // Admin-only: writes resolution preferences.
        ) );

        // ---- Crawler Log ----
        register_rest_route( self::NAMESPACE, '/crawler-log', array(
            array(
                'methods'             => 'GET',
                'callback'            => array( $this, 'get_crawler_log' ),
                'permission_callback' => array( $this, 'admin_permission' ),
            ),
            array(
                'methods'             => 'DELETE',
                'callback'            => array( $this, 'clear_crawler_log' ),
                'permission_callback' => array( $this, 'admin_permission' ),
            ),
        ) );

        register_rest_route( self::NAMESPACE, '/crawler-log/summary', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'get_crawler_log_summary' ),
            'permission_callback' => array( $this, 'admin_permission' ),
        ) );

        // ---- Content Gaps ----
        register_rest_route( self::NAMESPACE, '/content-gaps', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'get_content_gaps' ),
            'permission_callback' => array( $this, 'admin_permission' ),
        ) );

        register_rest_route( self::NAMESPACE, '/content-gaps/scan', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'run_content_gap_scan' ),
            'permission_callback' => array( $this, 'admin_permission' ),
        ) );

        register_rest_route( self::NAMESPACE, '/content-gaps/fix', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'apply_content_gap_fix' ),
            'permission_callback' => array( $this, 'admin_permission' ),
        ) );

        // ---- Setup checklist (Dashboard activation card) ----
        register_rest_route( self::NAMESPACE, '/setup-checklist', array(
            array(
                'methods'             => 'GET',
                'callback'            => array( $this, 'get_setup_checklist' ),
                'permission_callback' => array( $this, 'admin_permission' ),
            ),
            array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'save_setup_checklist_flags' ),
                'permission_callback' => array( $this, 'admin_permission' ),
            ),
        ) );

        // ---- Open Knowledge Format ----
        register_rest_route( self::NAMESPACE, '/okf', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'get_okf' ),
            'permission_callback' => array( $this, 'admin_permission' ),
        ) );

        register_rest_route( self::NAMESPACE, '/okf/generate', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'generate_okf' ),
            'permission_callback' => array( $this, 'admin_permission' ),
        ) );

        // ---- Validation ----
        register_rest_route( self::NAMESPACE, '/validate/(?P<post_id>\d+)', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'validate_schema' ),
            'permission_callback' => array( $this, 'admin_permission' ),
        ) );

        register_rest_route( self::NAMESPACE, '/validate/bulk', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'validate_bulk' ),
            'permission_callback' => array( $this, 'admin_permission' ),
        ) );

        if ( License::is_pro_build() ) {
            // ---- Topical Map (Content Gaps tab) ----
            register_rest_route( self::NAMESPACE, '/content-gaps/topical-map', array(
                'methods'             => 'GET',
                'callback'            => array( $this, 'get_topical_map' ),
                'permission_callback' => array( $this, 'admin_permission' ),
            ) );

            register_rest_route( self::NAMESPACE, '/content-gaps/topical-map/build', array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'build_topical_map' ),
                'permission_callback' => array( $this, 'admin_permission' ),
            ) );

            register_rest_route( self::NAMESPACE, '/content-gaps/topical-map/(?P<id>\d+)/generate', array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'generate_topical_map_item' ),
                'permission_callback' => array( $this, 'admin_permission' ),
            ) );

            register_rest_route( self::NAMESPACE, '/content-gaps/topical-map/(?P<id>\d+)/outline', array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'outline_topical_map_item' ),
                'permission_callback' => array( $this, 'admin_permission' ),
            ) );

            register_rest_route( self::NAMESPACE, '/content-gaps/topical-map/(?P<id>\d+)/titles', array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'titles_topical_map_item' ),
                'permission_callback' => array( $this, 'admin_permission' ),
            ) );

            register_rest_route( self::NAMESPACE, '/content-gaps/topical-map/(?P<id>\d+)/dismiss', array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'dismiss_topical_map_item' ),
                'permission_callback' => array( $this, 'admin_permission' ),
            ) );

            // Full-market keyword pull (Growth only). The handler enforces the
            // Growth tier; registering the route for every Pro build is fine.
            register_rest_route( self::NAMESPACE, '/content-gaps/topical-map/market', array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'market_topical_map' ),
                'permission_callback' => array( $this, 'admin_permission' ),
            ) );

            register_rest_route( self::NAMESPACE, '/content-gaps/topical-map/design', array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'design_topical_map' ),
                'permission_callback' => array( $this, 'admin_permission' ),
            ) );

            register_rest_route( self::NAMESPACE, '/content-gaps/topical-map/seeds', array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'save_topical_seeds' ),
                'permission_callback' => array( $this, 'admin_permission' ),
            ) );

            register_rest_route( self::NAMESPACE, '/content-gaps/topical-map/research', array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'save_topical_research' ),
                'permission_callback' => array( $this, 'admin_permission' ),
            ) );

            // ---- Consensus Score (Growth) ----
            register_rest_route( self::NAMESPACE, '/consensus-score', array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'consensus_score' ),
                'permission_callback' => array( $this, 'admin_permission' ),
            ) );

            // ---- Knowledge Base (RAG) ----
            register_rest_route( self::NAMESPACE, '/kb', array(
                'methods'             => 'GET',
                'callback'            => array( $this, 'get_kb' ),
                'permission_callback' => array( $this, 'admin_permission' ),
            ) );

            register_rest_route( self::NAMESPACE, '/kb/upload', array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'upload_kb' ),
                'permission_callback' => array( $this, 'admin_permission' ),
            ) );

            register_rest_route( self::NAMESPACE, '/kb/delete', array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'delete_kb' ),
                'permission_callback' => array( $this, 'admin_permission' ),
            ) );

            register_rest_route( self::NAMESPACE, '/kb/view', array(
                'methods'             => 'GET',
                'callback'            => array( $this, 'view_kb' ),
                'permission_callback' => array( $this, 'admin_permission' ),
            ) );

            // ---- GSC ----
            register_rest_route( self::NAMESPACE, '/gsc/status', array(
                'methods'             => 'GET',
                'callback'            => array( $this, 'get_gsc_status' ),
                'permission_callback' => array( $this, 'admin_permission' ),
            ) );

            register_rest_route( self::NAMESPACE, '/gsc/connect', array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'connect_gsc' ),
                'permission_callback' => array( $this, 'admin_permission' ),
            ) );

            register_rest_route( self::NAMESPACE, '/gsc/pages', array(
                'methods'             => 'GET',
                'callback'            => array( $this, 'get_gsc_pages' ),
                'permission_callback' => array( $this, 'admin_permission' ),
            ) );

            register_rest_route( self::NAMESPACE, '/gsc/alerts', array(
                'methods'             => 'GET',
                'callback'            => array( $this, 'get_gsc_alerts' ),
                'permission_callback' => array( $this, 'admin_permission' ),
            ) );

            register_rest_route( self::NAMESPACE, '/gsc/callback', array(
                'methods'             => 'GET',
                'callback'            => array( $this, 'gsc_oauth_callback' ),
                // Public on purpose. OAuth redirects from Google can arrive
                // without the user's WP session cookie (Safari ITP, SameSite,
                // incognito, separate admin/front domains, ...). Security is
                // enforced inside handle_callback() via the CSRF state token
                // (wp_create_nonce stored as a 10-min transient before we
                // bounce the user to Google). Anyone hitting this URL without
                // a valid state token gets rejected with "Invalid OAuth state."
                // Previously this was admin_permission, which 403'd a real
                // chunk of customers (reported 2026-05-29 by Christian @ IC).
                'permission_callback' => '__return_true',
            ) );

            register_rest_route( self::NAMESPACE, '/gsc/disconnect', array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'disconnect_gsc' ),
                'permission_callback' => array( $this, 'admin_permission' ),
            ) );

            register_rest_route( self::NAMESPACE, '/gsc/sync', array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'sync_gsc' ),
                'permission_callback' => array( $this, 'admin_permission' ),
            ) );

            register_rest_route( self::NAMESPACE, '/gsc/sync-progress', array(
                'methods'             => 'GET',
                'callback'            => array( $this, 'get_gsc_sync_progress' ),
                'permission_callback' => array( $this, 'admin_permission' ),
            ) );

            register_rest_route( self::NAMESPACE, '/gsc/queries', array(
                'methods'             => 'GET',
                'callback'            => array( $this, 'get_gsc_queries' ),
                'permission_callback' => array( $this, 'admin_permission' ),
            ) );

            register_rest_route( self::NAMESPACE, '/gsc/ai-summary', array(
                'methods'             => 'GET',
                'callback'            => array( $this, 'get_gsc_ai_summary' ),
                'permission_callback' => array( $this, 'admin_permission' ),
            ) );

            register_rest_route( self::NAMESPACE, '/gsc/daily-series', array(
                'methods'             => 'GET',
                'callback'            => array( $this, 'get_gsc_daily_series' ),
                'permission_callback' => array( $this, 'admin_permission' ),
            ) );

            register_rest_route( self::NAMESPACE, '/gsc/recommendations', array(
                'methods'             => 'GET',
                'callback'            => array( $this, 'get_gsc_recommendations' ),
                'permission_callback' => array( $this, 'admin_permission' ),
            ) );

            register_rest_route( self::NAMESPACE, '/gsc/extended', array(
                'methods'             => 'GET',
                'callback'            => array( $this, 'get_gsc_extended' ),
                'permission_callback' => array( $this, 'admin_permission' ),
            ) );

            register_rest_route( self::NAMESPACE, '/gsc/ai-visibility/import', array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'import_gsc_ai_visibility' ),
                'permission_callback' => array( $this, 'admin_permission' ),
                'args'                => array(
                    'csv' => array( 'required' => true, 'type' => 'string' ),
                    'filename' => array( 'required' => false, 'type' => 'string' ),
                ),
            ) );

            register_rest_route( self::NAMESPACE, '/gsc/keyword-optimize', array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'gsc_keyword_optimize' ),
                'permission_callback' => array( $this, 'admin_permission' ),
            ) );

            // ---- Query Gap Detector ----
            register_rest_route( self::NAMESPACE, '/query-gap/scan', array(
                'methods'             => 'GET',
                'callback'            => array( $this, 'query_gap_scan' ),
                'permission_callback' => array( $this, 'admin_permission' ),
            ) );

            register_rest_route( self::NAMESPACE, '/query-gap/draft-answer', array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'query_gap_draft_answer' ),
                'permission_callback' => array( $this, 'admin_permission' ),
            ) );

            register_rest_route( self::NAMESPACE, '/query-gap/apply-faq', array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'query_gap_apply_faq' ),
                'permission_callback' => array( $this, 'admin_permission' ),
            ) );

            register_rest_route( self::NAMESPACE, '/query-gap/draft-heading', array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'query_gap_draft_heading' ),
                'permission_callback' => array( $this, 'admin_permission' ),
            ) );

            register_rest_route( self::NAMESPACE, '/query-gap/apply-heading', array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'query_gap_apply_heading' ),
                'permission_callback' => array( $this, 'admin_permission' ),
            ) );

            register_rest_route( self::NAMESPACE, '/gsc/index-now', array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'gsc_index_now' ),
                'permission_callback' => array( $this, 'admin_permission' ),
            ) );

            // ---- Internal Link Builder ----
            register_rest_route( self::NAMESPACE, '/gsc/build-links', array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'gsc_build_links' ),
                'permission_callback' => array( $this, 'admin_permission' ),
            ) );

            register_rest_route( self::NAMESPACE, '/gsc/apply-link', array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'gsc_apply_link' ),
                'permission_callback' => array( $this, 'admin_permission' ),
            ) );

            register_rest_route( self::NAMESPACE, '/gsc/section-index', array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'gsc_build_section_index' ),
                'permission_callback' => array( $this, 'admin_permission' ),
            ) );

            register_rest_route( self::NAMESPACE, '/gsc/section-index/stats', array(
                'methods'             => 'GET',
                'callback'            => array( $this, 'gsc_section_index_stats' ),
                'permission_callback' => array( $this, 'admin_permission' ),
            ) );

            register_rest_route( self::NAMESPACE, '/gsc/section-index/backfill', array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'gsc_backfill_best_sentences' ),
                'permission_callback' => array( $this, 'admin_permission' ),
            ) );

            // ---- Citation Tracker ----
            register_rest_route( self::NAMESPACE, '/citations', array(
                'methods'             => 'GET',
                'callback'            => array( $this, 'get_citations' ),
                'permission_callback' => array( $this, 'admin_permission' ),
            ) );

            register_rest_route( self::NAMESPACE, '/citations/run', array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'run_citation_check' ),
                'permission_callback' => array( $this, 'admin_permission' ),
            ) );

            register_rest_route( self::NAMESPACE, '/citations/api-key', array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'save_citation_api_key' ),
                'permission_callback' => array( $this, 'admin_permission' ),
            ) );

            // Which ChatGPT account citation checks run on: the customer's
            // own key, or the plan's account at a credit cost per question.
            register_rest_route( self::NAMESPACE, '/citations/engine-source', array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'save_citation_engine_source' ),
                'permission_callback' => array( $this, 'admin_permission' ),
            ) );

            register_rest_route( self::NAMESPACE, '/citations/page-reports', array(
                'methods'             => 'GET',
                'callback'            => array( $this, 'get_citation_page_reports' ),
                'permission_callback' => array( $this, 'admin_permission' ),
            ) );

            register_rest_route( self::NAMESPACE, '/citations/queries', array(
                array(
                    'methods'             => 'GET',
                    'callback'            => array( $this, 'get_citation_queries' ),
                    'permission_callback' => array( $this, 'admin_permission' ),
                ),
                array(
                    'methods'             => 'POST',
                    'callback'            => array( $this, 'add_citation_queries' ),
                    'permission_callback' => array( $this, 'admin_permission' ),
                ),
                array(
                    'methods'             => 'PUT',
                    'callback'            => array( $this, 'replace_citation_queries' ),
                    'permission_callback' => array( $this, 'admin_permission' ),
                ),
                array(
                    'methods'             => 'DELETE',
                    'callback'            => array( $this, 'remove_citation_query' ),
                    'permission_callback' => array( $this, 'admin_permission' ),
                ),
            ) );

            register_rest_route( self::NAMESPACE, '/citations/queries/generate', array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'ai_generate_citation_queries' ),
                'permission_callback' => array( $this, 'admin_permission' ),
            ) );

            // Per-query delete (removes every stored result row matching the given query string).
            register_rest_route( self::NAMESPACE, '/citations/results/by-query', array(
                'methods'             => 'DELETE',
                'callback'            => array( $this, 'delete_citation_results_by_query' ),
                'permission_callback' => array( $this, 'admin_permission' ),
            ) );

            // Wipe every stored citation result + history. Saved query list is preserved.
            register_rest_route( self::NAMESPACE, '/citations/results/all', array(
                'methods'             => 'DELETE',
                'callback'            => array( $this, 'clear_all_citation_results' ),
                'permission_callback' => array( $this, 'admin_permission' ),
            ) );

            // ---- AI Mentions & Citation Tracking (Growth, no keys) ----
            // Each handler enforces the Growth tier server-side; registering the
            // routes for every Pro build is fine (the handler 403s a non-Growth
            // licence, on top of the proxy's own plan check).
            register_rest_route( self::NAMESPACE, '/citations/ai-mentions', array(
                array(
                    'methods'             => 'GET',
                    'callback'            => array( $this, 'get_ai_mentions' ),
                    'permission_callback' => array( $this, 'admin_permission' ),
                ),
                array(
                    'methods'             => 'POST',
                    'callback'            => array( $this, 'run_ai_mentions' ),
                    'permission_callback' => array( $this, 'admin_permission' ),
                ),
            ) );

            register_rest_route( self::NAMESPACE, '/citations/competitor-spy', array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'run_competitor_spy' ),
                'permission_callback' => array( $this, 'admin_permission' ),
            ) );

            register_rest_route( self::NAMESPACE, '/citations/competitor-spy/topic', array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'select_competitor_topic' ),
                'permission_callback' => array( $this, 'admin_permission' ),
            ) );

            register_rest_route( self::NAMESPACE, '/citations/competitor-spy/classify', array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'run_competitor_classify' ),
                'permission_callback' => array( $this, 'admin_permission' ),
            ) );

            register_rest_route( self::NAMESPACE, '/citations/will-ai-quote', array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'run_will_ai_quote' ),
                'permission_callback' => array( $this, 'admin_permission' ),
            ) );

            register_rest_route( self::NAMESPACE, '/citations/competitors', array(
                array(
                    'methods'             => 'GET',
                    'callback'            => array( $this, 'get_competitors' ),
                    'permission_callback' => array( $this, 'admin_permission' ),
                ),
                array(
                    'methods'             => 'POST',
                    'callback'            => array( $this, 'set_competitors' ),
                    'permission_callback' => array( $this, 'admin_permission' ),
                ),
            ) );

            // ---- Consensus (Pro: corroboration view + content kits) ----
            register_rest_route( self::NAMESPACE, '/citations/consensus', array(
                'methods'             => 'GET',
                'callback'            => array( $this, 'get_citation_consensus' ),
                'permission_callback' => array( $this, 'admin_permission' ),
            ) );
            register_rest_route( self::NAMESPACE, '/citations/consensus-kit', array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'build_consensus_kit' ),
                'permission_callback' => array( $this, 'admin_permission' ),
            ) );

            // ---- AI Referrals ----
            register_rest_route( self::NAMESPACE, '/referrals', array(
                'methods'             => 'GET',
                'callback'            => array( $this, 'get_ai_referrals' ),
                'permission_callback' => array( $this, 'admin_permission' ),
            ) );

            register_rest_route( self::NAMESPACE, '/referrals/entries', array(
                'methods'             => 'GET',
                'callback'            => array( $this, 'get_ai_referral_entries' ),
                'permission_callback' => array( $this, 'admin_permission' ),
            ) );

            // Public on purpose: anonymous visitors fire this from the
            // front-end beacon (cached pages cannot carry a fresh nonce).
            // The handler re-identifies the engine server-side and rate
            // limits per IP, so a forged body cannot invent a source.
            register_rest_route( self::NAMESPACE, '/ai-referrals/beacon', array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'log_ai_referral_beacon' ),
                'permission_callback' => '__return_true',
            ) );

            // ---- Citability Score ----
            register_rest_route( self::NAMESPACE, '/citability/(?P<post_id>\d+)', array(
                'methods'             => 'GET',
                'callback'            => array( $this, 'get_citability_score' ),
                'permission_callback' => array( $this, 'admin_permission' ),
            ) );

            register_rest_route( self::NAMESPACE, '/citability/all', array(
                'methods'             => 'GET',
                'callback'            => array( $this, 'get_citability_all' ),
                'permission_callback' => array( $this, 'admin_permission' ),
            ) );

            register_rest_route( self::NAMESPACE, '/citability/cached', array(
                'methods'             => 'GET',
                'callback'            => array( $this, 'get_citability_cached' ),
                'permission_callback' => array( $this, 'admin_permission' ),
            ) );

            register_rest_route( self::NAMESPACE, '/citability/exclude', array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'citability_exclude' ),
                'permission_callback' => array( $this, 'admin_permission' ),
            ) );

            register_rest_route( self::NAMESPACE, '/citability/include', array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'citability_include' ),
                'permission_callback' => array( $this, 'admin_permission' ),
            ) );

            // ---- E-E-A-T Author Profiles ----
            register_rest_route( self::NAMESPACE, '/eeat/authors', array(
                'methods'             => 'GET',
                'callback'            => array( $this, 'get_eeat_authors' ),
                'permission_callback' => array( $this, 'admin_permission' ),
            ) );

            register_rest_route( self::NAMESPACE, '/eeat/author', array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'save_eeat_author' ),
                'permission_callback' => array( $this, 'admin_permission' ),
            ) );

            register_rest_route( self::NAMESPACE, '/eeat/author-card', array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'toggle_author_card' ),
                'permission_callback' => array( $this, 'admin_permission' ),
            ) );

            register_rest_route( self::NAMESPACE, '/eeat/card-fields', array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'save_card_fields' ),
                'permission_callback' => array( $this, 'admin_permission' ),
            ) );

            register_rest_route( self::NAMESPACE, '/eeat/avatar', array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'upload_eeat_avatar' ),
                'permission_callback' => array( $this, 'admin_permission' ),
            ) );
        }

        // =====================================================================
        // Free diagnosis routes. Everything below is outside the
        // is_pro_build() block above on purpose: these routes back dashboard
        // cards that render for every install, and their handlers only ever
        // touch classes that ship inside this plugin. A Free-only site gets
        // is_pro_build() === false from the License stub, so anything a Free
        // feature needs that sits inside that block is a guaranteed 404 for
        // every Free user. Gate Pro DATA inside a response, never the route.
        // =====================================================================

        // ---- AI crawler accessibility matrix ----
        register_rest_route( self::NAMESPACE, '/crawler-access', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'get_crawler_access' ),
            'permission_callback' => array( $this, 'admin_permission' ),
        ) );

        // ---- Link Health (broken links + one-click fixes) ----
        register_rest_route( self::NAMESPACE, '/link-health', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'get_link_health' ),
            'permission_callback' => array( $this, 'admin_permission' ),
        ) );
        register_rest_route( self::NAMESPACE, '/link-health/scan', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'run_link_health_scan' ),
            'permission_callback' => array( $this, 'admin_permission' ),
        ) );
        register_rest_route( self::NAMESPACE, '/link-health/fix', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'fix_link_health' ),
            'permission_callback' => array( $this, 'admin_permission' ),
        ) );

        // ---- Content Health (H1s, descriptions, titles, alt text) ----
        register_rest_route( self::NAMESPACE, '/content-health', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'get_content_health' ),
            'permission_callback' => array( $this, 'admin_permission' ),
        ) );
        register_rest_route( self::NAMESPACE, '/content-health/scan', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'run_content_health_scan' ),
            'permission_callback' => array( $this, 'admin_permission' ),
        ) );
        register_rest_route( self::NAMESPACE, '/content-health/details', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'get_content_health_details' ),
            'permission_callback' => array( $this, 'admin_permission' ),
        ) );
        register_rest_route( self::NAMESPACE, '/content-health/fix', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'fix_content_health' ),
            'permission_callback' => array( $this, 'admin_permission' ),
        ) );
        register_rest_route( self::NAMESPACE, '/content-health/featured-alt/generate', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'generate_content_health_featured_alt' ),
            'permission_callback' => array( $this, 'admin_permission' ),
        ) );
        register_rest_route( self::NAMESPACE, '/content-health/image-alt/generate', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'generate_content_health_image_alt' ),
            'permission_callback' => array( $this, 'admin_permission' ),
        ) );

        // ---- AI Plugin Manifest ----
        register_rest_route( self::NAMESPACE, '/ai-plugin', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'get_ai_plugin_status' ),
            'permission_callback' => array( $this, 'admin_permission' ),
        ) );

        // ---- AI Signals Dashboard ----
        register_rest_route( self::NAMESPACE, '/ai-signals-health', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'get_ai_signals_health' ),
            'permission_callback' => array( $this, 'admin_permission' ),
        ) );

        register_rest_route( self::NAMESPACE, '/ai-headers', array(
            array(
                'methods'             => 'GET',
                'callback'            => array( $this, 'get_ai_headers' ),
                'permission_callback' => array( $this, 'admin_permission' ),
            ),
            array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'save_ai_headers' ),
                'permission_callback' => array( $this, 'admin_permission' ),
            ),
        ) );

        // ---- Activity Log ----
        register_rest_route( self::NAMESPACE, '/activity', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'get_activity' ),
            'permission_callback' => array( $this, 'admin_permission' ),
        ) );

        // ---- License ----
        register_rest_route( self::NAMESPACE, '/license', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'get_license_status' ),
            'permission_callback' => array( $this, 'admin_permission' ),
        ) );

        register_rest_route( self::NAMESPACE, '/license/refresh', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'refresh_license_status' ),
            'permission_callback' => array( $this, 'admin_permission' ),
        ) );

        register_rest_route( self::NAMESPACE, '/license/activate', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'activate_license' ),
            'permission_callback' => array( $this, 'admin_permission' ),
        ) );

        register_rest_route( self::NAMESPACE, '/license/deactivate', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'deactivate_license' ),
            'permission_callback' => array( $this, 'admin_permission' ),
        ) );

        // ---- Metadata Generation ----
        register_rest_route( self::NAMESPACE, '/metadata/credits', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'get_metadata_credits' ),
            'permission_callback' => array( $this, 'admin_permission' ),
        ) );

        register_rest_route( self::NAMESPACE, '/metadata/detect', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'get_metadata_detection' ),
            'permission_callback' => array( $this, 'admin_permission' ),
        ) );

        register_rest_route( self::NAMESPACE, '/metadata/generate', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'generate_metadata' ),
            'permission_callback' => array( $this, 'admin_permission' ),
        ) );

        register_rest_route( self::NAMESPACE, '/metadata/accept', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'accept_metadata' ),
            'permission_callback' => array( $this, 'admin_permission' ),
        ) );

        // Consumes a short-lived bulk-action transient created when the user
        // clicks an AEO God Mode bulk action on the WP Posts list. Returns
        // the selected post IDs + mode so the React Metadata page can
        // pre-populate the selection for review-before-commit. One-use:
        // hitting the endpoint deletes the transient.
        register_rest_route( self::NAMESPACE, '/metadata/bulk-payload', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'consume_bulk_payload' ),
            'permission_callback' => array( $this, 'admin_permission' ),
        ) );
    }

    /**
     * Consume the bulk-meta hand-off transient and return the selection.
     *
     * @param \WP_REST_Request $request Request.
     * @return \WP_REST_Response
     */
    public function consume_bulk_payload( $request ) {
        $token = sanitize_text_field( $request->get_param( 'token' ) ?? '' );
        if ( '' === $token ) {
            return new \WP_REST_Response( array( 'success' => false, 'error' => 'Missing token.' ), 400 );
        }
        $payload = \AISEOGodMode\BulkMeta::consume_bulk_payload( $token );
        if ( ! $payload ) {
            return new \WP_REST_Response( array( 'success' => false, 'error' => 'Bulk selection expired or already used. Re-select posts and try again.' ), 410 );
        }
        return rest_ensure_response( array(
            'success'  => true,
            'mode'     => $payload['mode'],
            'post_ids' => array_values( array_map( 'absint', (array) $payload['post_ids'] ) ),
        ) );
    }

    /**
     * Check that the user is an admin.
     *
     * @return bool|\WP_Error
     */
    public function admin_permission() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return new \WP_Error(
                'rest_forbidden',
                __( 'You do not have permission to access this endpoint.', 'aeo-god-mode' ),
                array( 'status' => 403 )
            );
        }
        return true;
    }

    /**
     * Check that the current user may edit the requested post.
     *
     * The Answer Density panel appears for Authors and Editors as well as site
     * administrators. Its per-post routes must follow WordPress's own post
     * capability checks instead of requiring manage_options.
     *
     * @param \WP_REST_Request $request Request object.
     * @return bool|\WP_Error
     */
    public function edit_post_permission( $request ) {
        $post_id = absint( $request->get_param( 'post_id' ) );
        if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
            return new \WP_Error(
                'rest_forbidden',
                __( 'You do not have permission to edit this post.', 'aeo-god-mode' ),
                array( 'status' => 403 )
            );
        }
        return true;
    }

    // -----------------------------------------------------------------------
    // Dashboard
    // -----------------------------------------------------------------------

    /**
     * Get dashboard status with health score.
     *
     * @return \WP_REST_Response
     */
    public function get_status() {
        $settings = get_option( 'asgm_settings', array() );
        $modules  = isset( $settings['modules'] ) ? $settings['modules'] : array();

        $health = $this->calculate_health_score( $settings );

        // Detect e-commerce platforms for upgrade prompts.
        $ecommerce = array();
        if ( class_exists( 'WooCommerce' ) || function_exists( 'wc_get_product' ) ) {
            $ecommerce[] = 'woocommerce';
        }
        if ( class_exists( 'Easy_Digital_Downloads' ) || function_exists( 'edd_get_download' ) ) {
            $ecommerce[] = 'edd';
        }

        // Growth is a Pro superset. Agency includes Growth capabilities but
        // remains its own plan for UI, entitlement and console reporting.
        $is_growth = method_exists( '\AISEOGodMode\License', 'is_growth' ) && \AISEOGodMode\License::is_growth();
        $plan       = 'free';
        if ( License::is_pro() ) {
            $license = new License();
            $plan    = method_exists( $license, 'get_plan' ) ? $license->get_plan() : 'pro';
            if ( ! in_array( $plan, array( 'pro', 'growth', 'agency' ), true ) ) {
                $plan = 'pro';
            }
        }

        return rest_ensure_response( array(
            'version'              => ASGM_VERSION,
            'is_pro'               => License::is_pro(),
            'is_growth'            => $is_growth,
            'is_pro_build'         => License::is_pro_build(),
            'plan'                 => $plan,
            'safe_mode'            => ! empty( $settings['safe_mode'] ),
            'wizard_completed'     => ! empty( $settings['wizard_completed'] ),
            'health_score'         => $health['score'],
            'health_breakdown'     => $health['breakdown'],
            'health_breakdown_reasons' => isset( $health['breakdown_reasons'] ) ? $health['breakdown_reasons'] : array(),
            'modules'              => $modules,
            'detected_ecommerce'   => $ecommerce,
        ) );
    }

    /**
     * Get site-level AEO health.
     *
     * Checks are specific to Answer Engine Optimization:
     * whether AI systems can find, read, and cite this site.
     * Results are cached for 6 hours to keep the dashboard fast.
     *
     * @return \WP_REST_Response
     */
    public function get_site_health() {
        $cached = get_transient( 'asgm_site_health' );
        if ( false !== $cached ) {
            return rest_ensure_response( $cached );
        }

        $result = $this->run_site_health_checks();
        // Short TTL so the dashboard reflects recent changes (running a scan,
        // saving robots rules, resolving a schema conflict) without forcing a
        // 6-hour wait. Underlying writes also invalidate this transient via
        // hooks in Main::boot().
        set_transient( 'asgm_site_health', $result, 5 * MINUTE_IN_SECONDS );

        return rest_ensure_response( $result );
    }

    /**
     * Run AEO-specific site health checks.
     *
     * @return array
     */
    private function run_site_health_checks() {
        global $wpdb;
        $issues   = array();
        $settings = get_option( 'asgm_settings', array() );
        $modules  = isset( $settings['modules'] ) ? $settings['modules'] : array();

        // Crawler allow/block rules are an owner policy choice, not a setup
        // defect. The dedicated Crawler Access card reports their exact impact
        // and separates search access from training and data-use controls. Do
        // not duplicate those choices here as a warning or health-score penalty.

        // 1. llms.txt not yet served to an AI crawler.
        // Prefer the cached transient; fall back to the "last generated" option
        // which is set whenever the file is built (activation, regenerate, or first front-end hit).
        $llms_ready = get_transient( 'asgm_llms_txt' ) || get_option( 'asgm_llms_last_generated', '' );
        if ( ! $llms_ready ) {
            $issues[] = array(
                'type'     => 'no_llms_txt',
                'severity' => 'warning',
                'message'  => __( 'llms.txt has not been generated yet. Click Regenerate on the llms.txt page to build it now.', 'aeo-god-mode' ),
                'fix_url'  => admin_url( 'admin.php?page=aeo-god-mode#/llms' ),
            );
        }

        // 2. Zero AI crawler activity (if module is on but nothing logged).
        if ( ! empty( $modules['ai_crawlers'] ) ) {
            $table = $wpdb->prefix . 'asgm_crawler_log';
            if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
                // Table name is safe: built from $wpdb->prefix (trusted) + hardcoded suffix.
                $count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `" . esc_sql( $table ) . "`" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
                if ( 0 === $count ) {
                    $issues[] = array(
                        'type'     => 'no_crawler_activity',
                        'severity' => 'info',
                        'message'  => __( 'No AI crawler activity logged yet. If your site has been live for a while, check that AI bots are not blocked at the server level (WAF, Cloudflare, etc.).', 'aeo-god-mode' ),
                        'fix_url'  => admin_url( 'admin.php?page=aeo-god-mode#/crawler-log' ),
                    );
                }
            }
        }

        // 3. No Organization schema configured.
        // Settings.tsx persists business info under asgm_settings.business — the
        // legacy asgm_business option is never written, so reading it gave a false
        // negative on configured sites. Mirror class-schema.php's source of truth.
        $settings_for_business = get_option( 'asgm_settings', array() );
        $business              = isset( $settings_for_business['business'] ) ? (array) $settings_for_business['business'] : array();
        $has_org               = ! empty( $business['name'] ) || ! empty( $business['org_name'] );
        if ( ! $has_org ) {
            $issues[] = array(
                'type'     => 'no_org_schema',
                'severity' => 'warning',
                'message'  => __( 'No Organization schema configured. AI systems use this to identify who you are and how to attribute citations. Set your business name in Settings.', 'aeo-god-mode' ),
                'fix_url'  => admin_url( 'admin.php?page=aeo-god-mode#/settings' ),
            );
        }

        // 4. "Discourage search engines" is enabled.
        $blog_public = get_option( 'blog_public' );
        if ( '0' === $blog_public || 0 === $blog_public ) {
            $issues[] = array(
                'type'     => 'noindex_enabled',
                'severity' => 'error',
                'message'  => __( '"Discourage search engines from indexing this site" is enabled. This blocks Google, AI crawlers, and all search engines from indexing your content.', 'aeo-god-mode' ),
                'fix_url'  => admin_url( 'options-reading.php' ),
            );
        }

        // 5. Key AEO modules disabled.
        $key_modules = array(
            'schema'       => 'Schema Engine',
            'ai_crawlers'  => 'AI Crawler Manager',
            'content_gaps' => 'Content Gap Scanner',
        );
        $disabled = array();
        foreach ( $key_modules as $mod_key => $mod_label ) {
            if ( empty( $modules[ $mod_key ] ) ) {
                $disabled[] = $mod_label;
            }
        }
        if ( ! empty( $disabled ) ) {
            $issues[] = array(
                'type'     => 'modules_disabled',
                'severity' => 'info',
                'message'  => sprintf(
                    /* translators: %s: comma-separated list of disabled module names */
                    __( 'Core AEO modules disabled: %s. Enable them in Settings.', 'aeo-god-mode' ),
                    implode( ', ', $disabled )
                ),
                'fix_url'  => admin_url( 'admin.php?page=aeo-god-mode#/settings' ),
            );
        }

        // Score: start at 100, deduct by severity.
        $score = 100;
        foreach ( $issues as $issue ) {
            switch ( $issue['severity'] ) {
                case 'error':
                    $score -= 25;
                    break;
                case 'warning':
                    $score -= 15;
                    break;
                case 'info':
                    $score -= 5;
                    break;
            }
        }

        return array(
            'issues' => $issues,
            'count'  => count( $issues ),
            'score'  => max( 0, $score ),
        );
    }

    /**
     * Calculate the site health score using real signal checks.
     *
     * @param array $settings Plugin settings.
     * @return array Score and breakdown.
     */
    private function calculate_health_score( $settings ) {
        $modules = isset( $settings['modules'] ) ? $settings['modules'] : array();

        $schema_url   = admin_url( 'admin.php?page=aeo-god-mode#/settings' );
        $llms_url     = admin_url( 'admin.php?page=aeo-god-mode#/llms' );
        $gaps_url     = admin_url( 'admin.php?page=aeo-god-mode#/content-gaps' );
        $settings_url = admin_url( 'admin.php?page=aeo-god-mode#/settings' );

        // ── AI Crawler Monitoring (0-100) ──
        // Allow, Disallow and No rule are all valid owner policy choices. The
        // access matrix explains their consequences; this score measures only
        // whether monitoring is enabled and whether crawls have been observed.
        $crawler_score   = 0;
        $crawler_reasons = array();

        if ( empty( $modules['ai_crawlers'] ) ) {
            $crawler_reasons[] = array(
                'message' => __( 'AI Crawlers module is off. Robots.txt rules aren\'t being managed.', 'aeo-god-mode' ),
                'fix_url' => $settings_url,
                'cost'    => 100,
            );
        } else {
            // The manager being enabled is the configuration portion. Do not
            // inspect the selected policy here: blocking training is a valid
            // licensing choice, and blocking search can be deliberate too.
            $crawler_score += 60;

            global $wpdb;
            $table = $wpdb->prefix . 'asgm_crawler_log';
            $thirty_days = 0;
            if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
                $thirty_days = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `" . esc_sql( $table ) . "` WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
            }

            $activity_award = 0;
            if ( $thirty_days >= 50 )      { $activity_award = 40; }
            elseif ( $thirty_days >= 10 )  { $activity_award = 25; }
            elseif ( $thirty_days >= 1 )   { $activity_award = 10; }
            $crawler_score += $activity_award;

            if ( $activity_award < 40 ) {
                $crawler_reasons[] = array(
                    'message' => sprintf(
                        /* translators: 1: number of crawl events in the last 30 days */
                        __( 'Only %1$d AI-bot crawl events in the last 30 days. Reach 50+ for full credit. This is ambient: bots crawl when they crawl.', 'aeo-god-mode' ),
                        $thirty_days
                    ),
                    'cost'    => 40 - $activity_award,
                );
            }
        }

        // ── Schema Coverage (0-100) ──
        // Multi-factor quality assessment, not just "does schema exist."
        $schema_score   = 0;
        $schema_reasons = array();

        if ( empty( $modules['schema'] ) ) {
            $schema_reasons[] = array(
                'message' => __( 'Schema Engine is off. No structured data is being injected on your pages.', 'aeo-god-mode' ),
                'fix_url' => $settings_url,
            );
        } else {
            // 1. Module enabled (10 points).
            $schema_score += 10;

            // Check actual posts for schema output.
            $posts = get_posts( array(
                'post_type'      => array( 'post', 'page' ),
                'post_status'    => 'publish',
                'posts_per_page' => 20,
                'orderby'        => 'date',
                'order'          => 'DESC',
                'fields'         => 'ids',
            ) );

            if ( ! empty( $posts ) ) {
                $schema       = new Schema();
                $with_schema  = 0;
                $type_set     = array(); // Track unique schema types across all posts.
                $field_scores = array(); // Track Article field completeness per post.

                foreach ( $posts as $pid ) {
                    $output = $schema->get_for_post( $pid );
                    if ( ! empty( $output['schemas'] ) ) {
                        ++$with_schema;

                        foreach ( $output['schemas'] as $s ) {
                            $stype = isset( $s['@type'] ) ? $s['@type'] : '';
                            if ( $stype ) {
                                $type_set[ $stype ] = true;
                            }

                            // Score Article field completeness.
                            if ( 'Article' === $stype ) {
                                $required_fields  = array( 'headline', 'datePublished', 'author', 'publisher', 'url' );
                                $enriched_fields  = array( 'image', 'description', 'inLanguage', 'mainEntityOfPage', 'articleSection', 'wordCount', 'isPartOf' );
                                $present_required = 0;
                                $present_enriched = 0;

                                foreach ( $required_fields as $f ) {
                                    if ( ! empty( $s[ $f ] ) ) {
                                        ++$present_required;
                                    }
                                }
                                foreach ( $enriched_fields as $f ) {
                                    if ( ! empty( $s[ $f ] ) ) {
                                        ++$present_enriched;
                                    }
                                }

                                // Required: 0-1.0, Enriched: 0-1.0.
                                $req_ratio = count( $required_fields ) > 0 ? $present_required / count( $required_fields ) : 0;
                                $enr_ratio = count( $enriched_fields ) > 0 ? $present_enriched / count( $enriched_fields ) : 0;

                                // Weighted: 60% required, 40% enriched.
                                $field_scores[] = ( $req_ratio * 0.6 ) + ( $enr_ratio * 0.4 );
                            }
                        }
                    }
                }

                // 2. Post coverage ratio (20 points).
                $coverage_ratio = $with_schema / count( $posts );
                $schema_score  += (int) round( $coverage_ratio * 20 );
                if ( $coverage_ratio < 1.0 ) {
                    $missing = count( $posts ) - $with_schema;
                    $schema_reasons[] = array(
                        'message' => sprintf(
                            /* translators: 1: posts without schema, 2: total posts in sample */
                            __( '%1$d of the last %2$d posts have no schema markup.', 'aeo-god-mode' ),
                            $missing,
                            count( $posts )
                        ),
                    );
                }

                // 3. Schema type diversity (20 points).
                // Ideal: Article + BreadcrumbList + Person + Organization + WebSite = 5 types,
                // counted across the whole site (homepage + posts), with credit given for types
                // the user has delegated to a competitor SEO plugin via the Conflicts UI.
                $ideal_types_list = array( 'Article', 'BreadcrumbList', 'Person', 'Organization', 'WebSite' );
                $ideal_count      = count( $ideal_types_list );

                // Add what we emit on the homepage (separate code path from get_for_post).
                $home_output = $schema->get_for_homepage();
                if ( ! empty( $home_output['schemas'] ) ) {
                    foreach ( $home_output['schemas'] as $hs ) {
                        $hstype = isset( $hs['@type'] ) ? $hs['@type'] : '';
                        if ( $hstype ) {
                            $type_set[ $hstype ] = true;
                        }
                    }
                }

                // Add types emitted by Pro modules (E-E-A-T Person, etc.).
                // These hook wp_head independently of the main Schema class, so
                // get_for_post() doesn't see them. The Schema class exposes a
                // single accessor so future Pro emitters stay in sync.
                $pro_types = $schema->get_pro_emitter_types( $posts );
                foreach ( $pro_types as $pt ) {
                    $type_set[ $pt ] = true;
                }

                // Honor user resolutions: types delegated to a third-party SEO plugin still count
                // as covered, since that plugin emits them. Maps BlogPosting -> Article.
                $resolutions  = get_option( 'asgm_schema_resolutions', array() );
                $third_party  = ( defined( 'RANK_MATH_VERSION' ) || defined( 'WPSEO_VERSION' ) );
                if ( $third_party && ! empty( $resolutions ) ) {
                    foreach ( $resolutions as $rtype => $rchoice ) {
                        if ( ! in_array( $rchoice, array( 'theirs', 'both' ), true ) ) {
                            continue;
                        }
                        $canonical = ( 'BlogPosting' === $rtype ) ? 'Article' : $rtype;
                        $type_set[ $canonical ] = true;
                    }
                }

                $covered    = array_values( array_filter( $ideal_types_list, function ( $t ) use ( $type_set ) {
                    return isset( $type_set[ $t ] );
                } ) );
                $missing    = array_values( array_diff( $ideal_types_list, $covered ) );
                $type_count = count( $covered );
                $type_ratio = min( $type_count / $ideal_count, 1.0 );
                $schema_score += (int) round( $type_ratio * 20 );

                if ( ! empty( $missing ) ) {
                    $conflicts_url = admin_url( 'admin.php?page=aeo-god-mode#/conflicts' );
                    $schema_reasons[] = array(
                        'message' => sprintf(
                            /* translators: 1: covered count, 2: ideal count (5), 3: comma-separated missing types */
                            __( 'Schema diversity: %1$d of %2$d ideal types covered. Missing: %3$s. Set the missing types in Conflicts (use either AEO God Mode or your other SEO plugin).', 'aeo-god-mode' ),
                            $type_count,
                            $ideal_count,
                            implode( ', ', $missing )
                        ),
                        'fix_url' => $conflicts_url,
                    );
                }

                // 4. Article field completeness (20 points).
                if ( ! empty( $field_scores ) ) {
                    $avg_field = array_sum( $field_scores ) / count( $field_scores );
                    $schema_score += (int) round( $avg_field * 20 );
                    if ( $avg_field < 0.85 ) {
                        $schema_reasons[] = array(
                            'message' => sprintf(
                                /* translators: %s: percent of Article fields filled */
                                __( 'Article schema is %s%% complete on average. Missing fields like image, description, or wordCount cost points.', 'aeo-god-mode' ),
                                (int) round( $avg_field * 100 )
                            ),
                        );
                    }
                }

                // 5. Conflict resolution (15 points). Always awarded: conflict
                // scans are computed on demand, never persisted, so there is
                // no stored conflict list to grade against.
                $schema_score += 15;

                // 6. Valid structure (15 points).
                // Deduct if we detect issues: duplicates, empty required fields.
                $structure_score = 15;
                // Check for potential duplicate schema types on the sample post.
                if ( ! empty( $posts[0] ) ) {
                    $sample_output = $schema->get_for_post( $posts[0] );
                    if ( ! empty( $sample_output['schemas'] ) ) {
                        $seen_types = array();
                        foreach ( $sample_output['schemas'] as $s ) {
                            $st = isset( $s['@type'] ) ? $s['@type'] : '';
                            if ( isset( $seen_types[ $st ] ) ) {
                                $structure_score -= 5; // Penalty per duplicate.
                            }
                            $seen_types[ $st ] = true;
                        }
                    }
                }
                $schema_score += max( 0, $structure_score );
            }
        }

        // Cap at 100.
        $schema_score = min( $schema_score, 100 );

        // ── AEO Readiness (0-100) ──
        // Checks llms.txt content, AEO layer, and content gap health.
        $aeo_score = 0;

        $aeo_reasons = array();

        // llms.txt exists and has real content (40 points).
        if ( empty( $modules['llms_txt'] ) ) {
            $aeo_reasons[] = array(
                'message' => __( 'llms.txt module is off. AI engines have no map of your site.', 'aeo-god-mode' ),
                'fix_url' => $settings_url,
            );
        } else {
            // Use LLMS::get_status() which builds content if the transient expired.
            // Reading the raw transient lies during the 24h window after a flush
            // when no visitor has hit /llms.txt to repopulate it.
            $llms_content = '';
            if ( class_exists( '\\AISEOGodMode\\LLMS' ) ) {
                $llms_status  = ( new LLMS() )->get_status();
                $llms_content = isset( $llms_status['content'] ) ? $llms_status['content'] : '';
            } else {
                $llms_content = get_transient( 'asgm_llms_txt' );
            }
            if ( ! empty( $llms_content ) && strlen( $llms_content ) > 100 ) {
                $aeo_score += 40;
            } else {
                $aeo_score += 10;
                $aeo_reasons[] = array(
                    'message' => __( 'llms.txt is empty. Click Regenerate on the llms.txt page to build it.', 'aeo-god-mode' ),
                    'fix_url' => $llms_url,
                );
            }
        }

        // AEO layer active (20 points).
        if ( ! empty( $modules['aeo'] ) ) {
            $aeo_score += 20;
        } else {
            $aeo_reasons[] = array(
                'message' => __( 'AEO layer module is off. Answer-extraction-friendly markup isn\'t being added.', 'aeo-god-mode' ),
                'fix_url' => $settings_url,
            );
        }

        // Content gap health: fewer critical gaps = higher score (40 points).
        // Source of truth is the option `asgm_content_gap_results` (set by ContentGaps::scan).
        // The stored value is a flat list of records; each record has a `gap_count` integer.
        $gap_results = get_option( 'asgm_content_gap_results', array() );
        if ( is_array( $gap_results ) && ! empty( $gap_results ) ) {
            $total_posts    = count( $gap_results );
            $critical_posts = 0;

            foreach ( $gap_results as $gp ) {
                if ( isset( $gp['gap_count'] ) ) {
                    $gap_count = (int) $gp['gap_count'];
                } elseif ( isset( $gp['gaps'] ) && is_array( $gp['gaps'] ) ) {
                    $gap_count = count( $gp['gaps'] );
                } else {
                    $gap_count = 0;
                }
                if ( $gap_count >= 3 ) {
                    ++$critical_posts;
                }
            }

            if ( $total_posts > 0 ) {
                $healthy_ratio = 1 - ( $critical_posts / $total_posts );
                $aeo_score    += (int) round( $healthy_ratio * 40 );
                if ( $critical_posts > 0 ) {
                    $aeo_reasons[] = array(
                        'message' => sprintf(
                            /* translators: 1: count of posts with 3+ gaps, 2: total scanned */
                            __( '%1$d of %2$d scanned posts have 3+ unaddressed content gaps.', 'aeo-god-mode' ),
                            $critical_posts,
                            $total_posts
                        ),
                        'fix_url' => $gaps_url,
                    );
                }
            }
        } elseif ( ! empty( $modules['content_gaps'] ) ) {
            $aeo_score += 5;
            $aeo_reasons[] = array(
                'message' => __( 'Content Gaps module is on but no scan has run. Trigger one from the Content Gaps page.', 'aeo-god-mode' ),
                'fix_url' => $gaps_url,
            );
        } else {
            $aeo_reasons[] = array(
                'message' => __( 'Content Gaps module is off. No analysis of where your posts are missing competitor coverage.', 'aeo-god-mode' ),
                'fix_url' => $settings_url,
            );
        }

        $breakdown = array(
            'ai_crawler_access' => $crawler_score,
            'schema_coverage'   => $schema_score,
            'aeo_readiness'     => $aeo_score,
        );

        $breakdown_reasons = array(
            'ai_crawler_access' => $crawler_reasons,
            'schema_coverage'   => $schema_reasons,
            'aeo_readiness'     => $aeo_reasons,
        );

        // Overall score: weighted average of 3 pillars.
        $score = (int) round( array_sum( $breakdown ) / count( $breakdown ) );

        // Persist the headline score. The agency console shows this exact
        // number, and the Pro license check-in reads it from here, so a
        // client never has to open this dashboard for its score to report.
        update_option( 'asgm_site_score', array( 'score' => $score, 'at' => time() ), false );

        return array(
            'score'             => $score,
            'breakdown'         => $breakdown,
            'breakdown_reasons' => $breakdown_reasons,
        );
    }

    /**
     * Recompute and cache the site AEO score. Cheap enough for a daily cron,
     * and the single source of truth for the agency console. Returns the
     * fresh score, or null if it could not be computed.
     *
     * @return int|null
     */
    public function refresh_site_score() {
        $settings = get_option( 'asgm_settings', array() );
        $result   = $this->calculate_health_score( $settings );
        return isset( $result['score'] ) ? (int) $result['score'] : null;
    }

    // -----------------------------------------------------------------------
    // Settings
    // -----------------------------------------------------------------------

    /**
     * Get all plugin settings.
     *
     * @return \WP_REST_Response
     */
    public function get_settings() {
        $settings = get_option( 'asgm_settings', array() );
        if ( class_exists( __NAMESPACE__ . '\\Answer_Density' ) ) {
            $settings = Answer_Density::settings_payload( $settings );
        }
        return rest_ensure_response( $settings );
    }

    /**
     * Save plugin settings.
     *
     * @param \WP_REST_Request $request Request object.
     * @return \WP_REST_Response
     */
    public function save_settings( $request ) {
        $body     = $request->get_json_params();
        $current  = get_option( 'asgm_settings', array() );
		$old_scope = class_exists( __NAMESPACE__ . '\\Answer_Density' ) ? Answer_Density::selected_post_types( $current ) : array( 'post', 'page' );
        $merged   = $this->deep_merge( $current, $body );
        $merged   = $this->sanitize_affiliate_settings( $merged );
        $merged   = $this->sanitize_knowledge_base_settings( $merged );
		if ( class_exists( __NAMESPACE__ . '\\Answer_Density' ) ) {
			$merged = Answer_Density::sanitize_settings_scope( $merged );
		}

        update_option( 'asgm_settings', $merged );

		$new_scope = class_exists( __NAMESPACE__ . '\\Answer_Density' ) ? Answer_Density::selected_post_types( $merged ) : $old_scope;
		if ( $old_scope !== $new_scope ) {
			Answer_Density::handle_scope_change();
			if ( class_exists( __NAMESPACE__ . '\\Content_Health' ) ) { Content_Health::invalidate_for_scope_change(); }
			if ( class_exists( __NAMESPACE__ . '\\ContentGaps' ) ) { ContentGaps::invalidate_cached_results(); }
		}

        // Log the activity.
        $this->log_activity( 'settings_updated', __( 'Plugin settings updated.', 'aeo-god-mode' ) );

        return rest_ensure_response( array(
            'success'  => true,
            'settings' => class_exists( __NAMESPACE__ . '\\Answer_Density' ) ? Answer_Density::settings_payload( $merged ) : $merged,
        ) );
    }

    /**
     * Sanitize the affiliate settings subtree server-side (defense in depth).
     *
     * Affiliate ID is restricted to a safe character set; badge style and
     * placement are allow-listed; the badge cannot be enabled without an ID.
     *
     * @param array $settings Merged settings.
     * @return array
     */
    private function sanitize_affiliate_settings( $settings ) {
        if ( empty( $settings['affiliate'] ) || ! is_array( $settings['affiliate'] ) ) {
            return $settings;
        }
        $aff = $settings['affiliate'];

        if ( isset( $aff['id'] ) ) {
            $aff['id'] = substr( preg_replace( '/[^A-Za-z0-9._\-]/', '', (string) $aff['id'] ), 0, 64 );
        }
        if ( isset( $aff['connected'] ) ) {
            $aff['connected'] = ! empty( $aff['connected'] );
        }
        if ( isset( $aff['name'] ) ) {
            $aff['name'] = sanitize_text_field( (string) $aff['name'] );
        }

        if ( isset( $aff['badge'] ) && is_array( $aff['badge'] ) ) {
            $b = $aff['badge'];
            if ( isset( $b['enabled'] ) ) {
                $b['enabled'] = ! empty( $b['enabled'] );
            }
            if ( isset( $b['style'] ) && ! in_array( $b['style'], array( 'light', 'dark', 'minimal' ), true ) ) {
                $b['style'] = 'light';
            }
            if ( isset( $b['placement'] ) && ! in_array( $b['placement'], array( 'footer', 'manual', 'off' ), true ) ) {
                $b['placement'] = 'footer';
            }
            if ( isset( $b['label'] ) ) {
                $b['label'] = substr( sanitize_text_field( (string) $b['label'] ), 0, 80 );
            }
            // A badge can never be enabled without a connected affiliate ID.
            if ( empty( $aff['id'] ) ) {
                $b['enabled'] = false;
            }
            $aff['badge'] = $b;
        }

        $settings['affiliate'] = $aff;
        return $settings;
    }

    /**
     * Keep the dedicated generation-format contract bounded while preserving
     * WordPress shortcode brackets, attributes, quotes and line breaks.
     *
     * @param array $settings Merged settings.
     * @return array
     */
    private function sanitize_knowledge_base_settings( $settings ) {
        if ( isset( $settings['kb_mode'] ) && ! in_array( $settings['kb_mode'], array( 'off', 'always', 'ask' ), true ) ) {
            $settings['kb_mode'] = 'ask';
        }
        if ( array_key_exists( 'kb_formatting_guidelines', $settings ) ) {
            $settings['kb_formatting_guidelines'] = mb_substr(
                sanitize_textarea_field( (string) $settings['kb_formatting_guidelines'] ),
                0,
                4000
            );
        }
        if ( array_key_exists( 'content_block_design', $settings ) ) {
            $incoming = is_array( $settings['content_block_design'] ) ? $settings['content_block_design'] : array();
            $accent   = (string) ( $incoming['accent'] ?? '' );
            $settings['content_block_design'] = array(
                'style'       => in_array( $incoming['style'] ?? '', array( 'boxed', 'minimal', 'outline', 'bold' ), true ) ? $incoming['style'] : 'boxed',
                'accent'      => preg_match( '/^#[0-9a-fA-F]{6}$/', $accent ) ? strtolower( $accent ) : '',
                'radius'      => in_array( $incoming['radius'] ?? '', array( 'rounded', 'square' ), true ) ? $incoming['radius'] : 'rounded',
                'tldr_marker' => in_array( $incoming['tldr_marker'] ?? '', array( 'bullet', 'hollow', 'arrow', 'check', 'star' ), true ) ? $incoming['tldr_marker'] : 'bullet',
            );
        }
        if ( array_key_exists( 'kb_content_blocks', $settings ) ) {
            $incoming = is_array( $settings['kb_content_blocks'] ) ? $settings['kb_content_blocks'] : array();
            $settings['kb_content_blocks'] = array(
                'tldr'      => ! empty( $incoming['tldr'] ),
                'faq'       => ! empty( $incoming['faq'] ),
                'pro_tip'   => ! empty( $incoming['pro_tip'] ),
                'pros_cons' => ! empty( $incoming['pros_cons'] ),
            );
        }
        return $settings;
    }

    // -----------------------------------------------------------------------
    // Detection + Import
    // -----------------------------------------------------------------------

    /**
     * Detect installed SEO plugins.
     *
     * @return \WP_REST_Response
     */
    public function detect_plugins() {
        $detector = new Detector();
        return rest_ensure_response( $detector->scan() );
    }

    /**
     * Import settings from detected plugin.
     *
     * @param \WP_REST_Request $request Request object.
     * @return \WP_REST_Response
     */
    public function import_settings( $request ) {
        $source   = sanitize_text_field( $request->get_param( 'source' ) );
        $detector = new Detector();
        $result   = $detector->import_from( $source );

        if ( is_wp_error( $result ) ) {
            return rest_ensure_response( $result );
        }

        $this->log_activity( 'settings_imported', sprintf(
            /* translators: %s: plugin name */
            __( 'Settings imported from %s.', 'aeo-god-mode' ),
            $source
        ) );

        return rest_ensure_response( array(
            'success'  => true,
            'imported' => $result,
        ) );
    }

    // -----------------------------------------------------------------------
    // Schema
    // -----------------------------------------------------------------------

    /**
     * Get schema preview for a post.
     *
     * @param \WP_REST_Request $request Request.
     * @return \WP_REST_Response
     */
    public function get_schema( $request ) {
        $post_id = absint( $request->get_param( 'post_id' ) );
        if ( ! get_post( $post_id ) ) {
            return new \WP_REST_Response( array( 'success' => false, 'error' => __( 'Post not found.', 'aeo-god-mode' ) ), 404 );
        }
        $schema  = new Schema();
        return rest_ensure_response( $schema->get_for_post( $post_id ) );
    }

    /**
     * Save schema override for a post.
     *
     * @param \WP_REST_Request $request Request.
     * @return \WP_REST_Response
     */
    public function save_schema( $request ) {
        $post_id = absint( $request->get_param( 'post_id' ) );
        if ( ! get_post( $post_id ) ) {
            return new \WP_REST_Response( array( 'success' => false, 'error' => __( 'Post not found.', 'aeo-god-mode' ) ), 404 );
        }
        $data    = $request->get_json_params();
        $schema  = new Schema();
        $result  = $schema->save_override( $post_id, $data );

        if ( is_wp_error( $result ) ) {
            return new \WP_REST_Response(
                array( 'success' => false, 'error' => $result->get_error_message() ),
                400
            );
        }

        $this->invalidate_schema_scan_caches();
        return rest_ensure_response( array_merge( $result, $schema->get_for_post( $post_id ) ) );
    }

    /**
     * Clear one stored schema type, or all saved overrides when type is empty.
     */
    public function clear_schema_override( $request ) {
        $post_id = absint( $request->get_param( 'post_id' ) );
        if ( ! get_post( $post_id ) ) {
            return new \WP_REST_Response( array( 'success' => false, 'error' => __( 'Post not found.', 'aeo-god-mode' ) ), 404 );
        }
        $type    = sanitize_text_field( (string) $request->get_param( 'type' ) );
        $schema  = new Schema();
        $result  = $schema->clear_override( $post_id, $type );

        $this->invalidate_schema_scan_caches();
        return rest_ensure_response( array_merge( $result, $schema->get_for_post( $post_id ) ) );
    }

    /**
     * Toggle one AEO schema type for a selected post without disabling the
     * rest of the page graph.
     */
    public function set_schema_type_output( $request ) {
        $post_id = absint( $request->get_param( 'post_id' ) );
        if ( ! get_post( $post_id ) ) {
            return new \WP_REST_Response( array( 'success' => false, 'error' => __( 'Post not found.', 'aeo-god-mode' ) ), 404 );
        }
        $type    = sanitize_text_field( (string) $request->get_param( 'type' ) );
        $enabled = rest_sanitize_boolean( $request->get_param( 'enabled' ) );
        $meta    = array(
            'HowTo'   => '_asgm_disable_howto',
            'FAQPage' => '_asgm_disable_faq',
        );

        if ( ! isset( $meta[ $type ] ) ) {
            return new \WP_REST_Response(
                array( 'success' => false, 'error' => __( 'Only HowTo and FAQPage can be toggled here.', 'aeo-god-mode' ) ),
                400
            );
        }

        if ( $enabled ) {
            delete_post_meta( $post_id, $meta[ $type ] );
        } else {
            update_post_meta( $post_id, $meta[ $type ], true );
        }

        $this->invalidate_schema_scan_caches();
        $schema = new Schema();
        return rest_ensure_response( array_merge(
            array( 'success' => true, 'type' => $type, 'enabled' => $enabled ),
            $schema->get_for_post( $post_id )
        ) );
    }

    /** Drop cached scan rows after a schema mutation. */
    private function invalidate_schema_scan_caches() {
        ContentGaps::invalidate_cached_results();
    }

    // -----------------------------------------------------------------------
    // Direct Answer Engine — Answer Density
    // -----------------------------------------------------------------------

    /**
     * Aggregate score across the whole site. Cached in the summary option,
     * refreshed by the nightly cron and on each post save. Returns
     * `last_scanned_at` so the dashboard can warn when WP cron has gone
     * stale (low-traffic sites where the cron-on-page-load pattern fails).
     */
    public function get_answer_density_summary() {
        $summary = Answer_Density::get_summary();

        // If the summary is empty (first run), build it on demand.
        if ( empty( $summary ) ) {
            $summary = Answer_Density::refresh_summary();
        }

        $last = isset( $summary['last_scanned_at'] ) ? $summary['last_scanned_at'] : '';
        $stale = false;
        if ( $last ) {
            $age_h = ( time() - strtotime( $last ) ) / 3600;
            $stale = $age_h > 36; // > 36h since last scan = cron likely missed
        } elseif ( ! empty( $summary['total_posts'] ) ) {
            $stale = true;
        }

        $summary['stale'] = $stale;

        // A full manual pass can continue in the background on a large site.
        // Expose the remaining count on the normal summary response so the
        // dashboard can keep reporting real progress instead of declaring the
        // scan finished after only the synchronous first chunk.
        $queue = get_option( Answer_Density::QUEUE_OPT, array() );
        $summary['scan_remaining']   = is_array( $queue ) ? count( $queue ) : 0;
        $summary['scan_in_progress'] = $summary['scan_remaining'] > 0;

        // Hydrate the weakest list with title + permalink so the dashboard
        // doesn't have to do N round-trips.
        if ( ! empty( $summary['weakest'] ) && is_array( $summary['weakest'] ) ) {
            foreach ( $summary['weakest'] as &$row ) {
                $pid = isset( $row['post_id'] ) ? (int) $row['post_id'] : 0;
                $row['title']     = $pid ? get_the_title( $pid ) : '';
                $row['edit_url']  = $pid ? get_edit_post_link( $pid, 'raw' ) : '';
                $row['view_url']  = $pid ? get_permalink( $pid ) : '';
            }
            unset( $row );
        }

        if ( ! empty( $summary['hidden'] ) && is_array( $summary['hidden'] ) ) {
            foreach ( $summary['hidden'] as &$row ) {
                $pid = isset( $row['post_id'] ) ? (int) $row['post_id'] : 0;
                $row['title']    = $pid ? get_the_title( $pid ) : '';
                $row['edit_url'] = $pid ? get_edit_post_link( $pid, 'raw' ) : '';
            }
            unset( $row );
        }

        return rest_ensure_response( $summary );
    }

    /**
     * Read the persisted scan for one post. Triggers a fresh scan if no
     * cached result exists (so first-load on the editor panel is correct).
     */
    public function get_answer_density_for_post( $request ) {
        $post_id = absint( $request->get_param( 'post_id' ) );
        $force   = filter_var( $request->get_param( 'force' ), FILTER_VALIDATE_BOOLEAN );
        if ( $force ) {
            // Editor "check now" forces a fresh scan via GET (the POST /rescan/
            // route is blocked by the host WAF on some installs).
            $data = Answer_Density::scan_post( $post_id );
        } else {
            $data = Answer_Density::get_for_post( $post_id );
            if ( ! Answer_Density::is_current_result( $data ) ) {
                $data = Answer_Density::scan_post( $post_id );
            }
        }
        return rest_ensure_response( $data );
    }

    /**
     * Force a fresh scan for one post — used by the "Re-scan" button in the
     * editor panel and by the bulk-fix flow.
     */
    public function rescan_answer_density( $request ) {
        $post_id = absint( $request->get_param( 'post_id' ) );
        $data    = Answer_Density::scan_post( $post_id );
        Answer_Density::refresh_summary();
        return rest_ensure_response( $data );
    }

    /**
     * Manually run the batch scanner — admin-only escape hatch when WP cron
     * has been silent and the site needs an immediate full refresh.
     *
     * Routes through get_answer_density_summary() so the response carries the
     * same hydrated shape as a GET — titles, edit/view URLs, stale flag.
     * Returning the raw summary here was the bug behind missing titles + dead
     * Fix links right after a re-scan.
     */
    public function scan_all_answer_density() {
        $scan     = Answer_Density::scan_all_posts();
        $response = $this->get_answer_density_summary();
        $data     = $response->get_data();
        if ( isset( $scan['scan_progress'] ) ) {
            $data['scan_progress'] = $scan['scan_progress'];
        }
        return rest_ensure_response( $data );
    }

    /**
     * User-asserted "this answer is fine" for a specific heading on a specific
     * post. Persists in post meta, rescans, refreshes the site summary so the
     * dashboard reflects the change immediately.
     */
    public function dismiss_answer_density( $request ) {
        $post_id = absint( $request->get_param( 'post_id' ) );
        $heading = (string) $request->get_param( 'heading' );
        $all     = filter_var( $request->get_param( 'all' ), FILTER_VALIDATE_BOOLEAN );
        $data    = $all
            ? Answer_Density::dismiss_all( $post_id )
            : Answer_Density::dismiss( $post_id, $heading );
        Answer_Density::refresh_summary();
        return rest_ensure_response( $data );
    }

    /**
     * Reverse of dismiss(). Surfaces the heading again as an issue.
     */
    public function undismiss_answer_density( $request ) {
        $post_id = absint( $request->get_param( 'post_id' ) );
        $heading = (string) $request->get_param( 'heading' );
        $all     = filter_var( $request->get_param( 'all' ), FILTER_VALIDATE_BOOLEAN );
        $data    = $all
            ? Answer_Density::undismiss_all( $post_id )
            : Answer_Density::undismiss( $post_id, $heading );
        Answer_Density::refresh_summary();
        return rest_ensure_response( $data );
    }

    /**
     * Pro AI Rewrite — generate an answer-first opener for a buried heading.
     * Pulls the cached scan to grab the original_paragraph + buried_opener
     * for the named heading, then asks the proxy. One credit on success.
     */
    public function rewrite_opener( $request ) {
        if ( ! \AISEOGodMode\License::is_pro() ) {
            return new \WP_Error( 'pro_required', 'AI Rewrite requires a Pro license.', array( 'status' => 403 ) );
        }

        $post_id        = absint( $request->get_param( 'post_id' ) );
        $heading        = (string) $request->get_param( 'heading' );
        $classification = (string) $request->get_param( 'classification' );
        $extra_context  = (string) $request->get_param( 'context' );

        $editor_content = $request->has_param( 'editor_content' )
            ? (string) $request->get_param( 'editor_content' )
            : '';
        if ( '' !== trim( $editor_content ) ) {
            // Generate from the live Gutenberg document, including unrelated
            // unsaved edits, rather than stale postmeta or database content.
            $scan = Answer_Density::scan_html(
                $editor_content,
                array( 'dismissed' => Answer_Density::get_dismissed( $post_id ) )
            );
        } else {
            $scan = Answer_Density::get_for_post( $post_id );
            if ( ! is_array( $scan ) || empty( $scan['issues'] ) ) {
                // Trigger a fresh scan in case the cache is stale.
                $scan = Answer_Density::scan_post( $post_id );
            }
        }

        $target = null;
        if ( ! empty( $scan['issues'] ) ) {
            foreach ( $scan['issues'] as $iss ) {
                if ( ! empty( $iss['heading'] ) && $iss['heading'] === $heading ) {
                    $target = $iss;
                    break;
                }
            }
        }

        if ( ! $target || empty( $target['first_paragraph'] ) ) {
            return new \WP_Error( 'no_context', 'No buried-opener context found for that heading. Re-scan the post and try again.', array( 'status' => 422 ) );
        }

        $result = MetadataGenerator::rewrite_opener(
            $post_id,
            $heading,
            (string) $target['first_paragraph'],
            (string) ( $target['first_sentence'] ?? '' ),
            $classification ?: ( $target['opener_kind'] ?? 'setup' ),
            $extra_context,
            $request->has_param( 'use_kb' ) ? rest_sanitize_boolean( $request->get_param( 'use_kb' ) ) : null
        );

        return rest_ensure_response( $result );
    }

    /**
     * Apply an AI rewrite after a named heading.
     *
     * Dashboard requests transform and persist the saved post immediately.
     * Gutenberg requests include `editor_content`; those transform the live
     * serialized editor document and return it without touching the database,
     * allowing the normal editor Save/Update action to create the revision.
     *
     * @param \WP_REST_Request $request Request object.
     * @return \WP_REST_Response|\WP_Error
     */
    public function apply_rewrite( $request ) {
        if ( ! \AISEOGodMode\License::is_pro() ) {
            return new \WP_Error( 'pro_required', 'AI Rewrite requires a Pro license.', array( 'status' => 403 ) );
        }

        $post_id = absint( $request->get_param( 'post_id' ) );
        $heading = trim( (string) $request->get_param( 'heading' ) );
        $rewrite = trim( (string) $request->get_param( 'rewrite' ) );

        if ( ! $post_id || $heading === '' || $rewrite === '' ) {
            return new \WP_Error( 'bad_input', 'post_id, heading, and rewrite are all required.', array( 'status' => 400 ) );
        }

        $post = get_post( $post_id );
        if ( ! $post ) {
            return new \WP_Error( 'no_post', 'Post not found.', array( 'status' => 404 ) );
        }
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return new \WP_Error( 'forbidden', 'You cannot edit this post.', array( 'status' => 403 ) );
        }

        $editor_mode = $request->has_param( 'editor_content' );
        $content     = $editor_mode
            ? (string) $request->get_param( 'editor_content' )
            : (string) $post->post_content;

        if ( $editor_mode && '' === trim( $content ) ) {
            return new \WP_Error( 'empty_editor_content', 'The editor content is empty. Reload the editor and try again.', array( 'status' => 422 ) );
        }

        // Find the matching heading (h2/h3) by NORMALIZED text match. The
        // scanner reads the rendered content, where wptexturize has curled
        // apostrophes and quotes ("you're" becomes "you’re"), while this
        // method searches the raw post_content, which still holds straight
        // ones. An exact-text match therefore fails for any heading with an
        // apostrophe, quote, dash, or entity. Normalize both sides the same
        // way and compare, keeping the raw offsets for the splice below.
        $normalize = static function ( $s ) {
            $s = wp_strip_all_tags( (string) $s );
            $s = html_entity_decode( $s, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
            $s = str_replace( array( "\xE2\x80\x99", "\xE2\x80\x98", "\xE2\x80\x9C", "\xE2\x80\x9D" ), array( "'", "'", '"', '"' ), $s );
            $s = str_replace( array( "\xE2\x80\x93", "\xE2\x80\x94", "\xC2\xA0" ), array( '-', '-', ' ' ), $s );
            $s = preg_replace( '/\s+/u', ' ', $s );
            return function_exists( 'mb_strtolower' ) ? mb_strtolower( trim( $s ), 'UTF-8' ) : strtolower( trim( $s ) );
        };

        $want = $normalize( $heading );
        $hm   = null;
        if ( '' !== $want && preg_match_all( '#<h([23])\b[^>]*>(.*?)</h\1>#isu', $content, $all_headings, PREG_OFFSET_CAPTURE | PREG_SET_ORDER ) ) {
            foreach ( $all_headings as $cand ) {
                if ( $normalize( $cand[2][0] ) === $want ) {
                    $hm = array( $cand[0] ); // [full match, offset], same shape the splice math expects
                    break;
                }
            }
        }

        if ( ! $hm ) {
            return new \WP_Error( 'heading_not_found', 'Could not locate heading in post content.', array( 'status' => 422 ) );
        }

        $cursor = $hm[0][1] + strlen( $hm[0][0] );

        // Block-editor content closes the heading with a serializer comment;
        // any replacement or insertion must land AFTER it or the block breaks.
        $tail = substr( $content, $cursor );
        if ( preg_match( '#^\s*<!--\s*/wp:heading\s*-->#', $tail, $cm ) ) {
            $cursor += strlen( $cm[0] );
            $tail    = substr( $content, $cursor );
        }

        // Skip whitespace between the heading and the opener.
        $ws        = strlen( $tail ) - strlen( ltrim( $tail, " \t\r\n" ) );
        $cursor   += $ws;
        $tail_trim = substr( $tail, $ws );
		$target_structure = Answer_Density::opener_structure( $tail_trim );

        // Block-editor paragraph opener: skip the wp:paragraph comment so the
        // replacement swaps only the <p> and the serializer comments survive.
        if ( preg_match( '#^<!--\s*wp:paragraph[^>]*-->\s*#', $tail_trim, $bm ) ) {
            $cursor   += strlen( $bm[0] );
            $tail_trim = substr( $tail_trim, strlen( $bm[0] ) );
        }

        $is_blocks = ( false !== strpos( $content, '<!-- wp:' ) );

        if ( preg_match( '#^(<p\b[^>]*>)(.*?)</p>#is', $tail_trim, $pm ) ) {
            // Opener is an explicit <p>...</p>: swap it.
            $p_full_length = strlen( $pm[0] );
            // Keep class, style, anchor and other attributes on the paragraph.
            // Stripping them can invalidate a Gutenberg block whose comment
            // attributes still describe the original HTML wrapper.
            $new_p         = $pm[1] . esc_html( $rewrite ) . '</p>';
			$operation     = 'replaced_opener';
		} elseif ( ! preg_match( '#^(?:<(?:ul|ol|li|h[1-6]|table|blockquote|figure|div|pre|hr|section|aside|dl|details)\b|<!--\s*wp:)#i', $tail_trim )
                   && preg_match( '#^(.+?)(?=\R\s*\R|\R?\s*<(?:ul|ol|h[1-6]|p|table|blockquote|figure|div|pre|hr)\b|\R?\s*<!--\s*wp:|$)#isu', $tail_trim, $pm )
                   && trim( wp_strip_all_tags( $pm[1] ) ) !== '' ) {
            // Opener is bare text (wpautop style): replace the text run.
            $p_full_length = strlen( $pm[1] );
            $new_p         = esc_html( $rewrite );
			$operation     = 'replaced_opener';
        } else {
            // No opener exists: the heading is followed directly by a list,
            // table, or other block (checklist-style sections do this). The
            // answer-first opener's whole job is to give such sections a direct
            // opening answer, so INSERT a new paragraph between the heading and
            // the block instead of failing. Nothing is replaced.
            $p_full_length = 0;
            $new_p         = $is_blocks
                ? "<!-- wp:paragraph -->\n<p>" . esc_html( $rewrite ) . "</p>\n<!-- /wp:paragraph -->\n\n"
                : '<p>' . esc_html( $rewrite ) . "</p>\n\n";
			$operation     = 'inserted_before_' . ( in_array( $target_structure, array( 'list', 'table', 'block' ), true ) ? $target_structure : 'content' );
        }

        $new_content = substr( $content, 0, $cursor ) . $new_p . substr( $content, $cursor + $p_full_length );

        // In the block editor, return the transformed CURRENT document and
        // let Gutenberg own the dirty/save lifecycle. Saving here would update
        // the database behind Gutenberg's back; its stale in-memory blocks
        // would then overwrite the rewrite when the user pressed Save.
        if ( $editor_mode ) {
            return rest_ensure_response( array(
                'success' => true,
                'post_id' => $post_id,
                'saved'   => false,
                'changed' => $new_content !== $content,
                'content' => $new_content,
				'operation' => $operation,
				'preserved_structure' => 0 === $p_full_length,
            ) );
        }

        // Dashboard actions do not have an editor document to save later, so
        // persist through core and run revisions, cache invalidation and scans.
        $updated = wp_update_post( wp_slash( array(
            'ID'           => $post_id,
            'post_content' => $new_content,
        ) ), true );
        if ( is_wp_error( $updated ) ) {
            return new \WP_Error( 'save_failed', $updated->get_error_message(), array( 'status' => 500 ) );
        }

        $scan    = Answer_Density::scan_post( $post_id );
        $summary = Answer_Density::refresh_summary();

        return rest_ensure_response( array(
            'success' => true,
            'post_id' => $post_id,
            'saved'   => true,
            'scan'    => $scan,
            'summary' => $summary,
			'operation' => $operation,
			'preserved_structure' => 0 === $p_full_length,
        ) );
    }

    // -----------------------------------------------------------------------
    // Robots
    // -----------------------------------------------------------------------

    /**
     * Get current robots.txt config.
     *
     * @return \WP_REST_Response
     */
    public function get_robots() {
        $robots = new Robots();
        return rest_ensure_response( $robots->get_config() );
    }

    /**
     * Save robots.txt changes.
     *
     * @param \WP_REST_Request $request Request.
     * @return \WP_REST_Response
     */
    public function save_robots( $request ) {
        $data   = $request->get_json_params();
        $robots = new Robots();
        $result = $robots->save_config( $data );
        update_option( 'asgm_robots_rules_updated', gmdate( 'c' ) );
        return rest_ensure_response( $result );
    }



    // -----------------------------------------------------------------------
    // LLMS.txt
    // -----------------------------------------------------------------------

    /**
     * Get llms.txt content and status.
     *
     * @return \WP_REST_Response
     */
    public function get_llms() {
        $llms = new LLMS();
        return rest_ensure_response( $llms->get_status() );
    }

    /**
     * Regenerate llms.txt.
     *
     * @return \WP_REST_Response
     */
    public function regenerate_llms() {
        $llms   = new LLMS();
        $result = $llms->regenerate();
        update_option( 'asgm_llms_txt_updated', gmdate( 'c' ) );
        $this->log_activity( 'llms_regenerated', __( 'llms.txt regenerated.', 'aeo-god-mode' ) );
        return rest_ensure_response( $result );
    }

    /**
     * Save llms.txt custom free-form content.
     *
     * @param \WP_REST_Request $request Request.
     * @return \WP_REST_Response
     */
    public function save_llms_custom( $request ) {
        $content = $request->get_param( 'content' );
        $llms    = new LLMS();
        $result  = $llms->save_custom_content( $content ?? '' );
        return rest_ensure_response( $result );
    }

    /**
     * Take manual control of llms.txt: serve exactly what the owner wrote.
     *
     * @param \WP_REST_Request $request Request.
     * @return \WP_REST_Response
     */
    public function save_llms_manual( $request ) {
        $llms = new LLMS();
        return rest_ensure_response( $llms->save_manual( (string) $request->get_param( 'content' ) ) );
    }

    /**
     * Hand llms.txt back to the generator. The edited text is kept.
     *
     * @param \WP_REST_Request $request Request.
     * @return \WP_REST_Response
     */
    public function revert_llms_manual( $request ) {
        $llms = new LLMS();
        return rest_ensure_response( $llms->disable_manual() );
    }

    // -----------------------------------------------------------------------
    // Conflicts
    // -----------------------------------------------------------------------

    /**
     * Get full conflict report.
     *
     * @return \WP_REST_Response
     */
    public function get_conflicts() {
        $conflict = new Conflict();
        return rest_ensure_response( $conflict->scan() );
    }

    /**
     * Resolve a conflict.
     *
     * @param \WP_REST_Request $request Request.
     * @return \WP_REST_Response
     */
    public function resolve_conflict( $request ) {
        $id         = sanitize_text_field( $request->get_param( 'id' ) );
        $resolution = sanitize_text_field( $request->get_param( 'resolution' ) );
        $conflict   = new Conflict();
        return rest_ensure_response( $conflict->resolve( $id, $resolution ) );
    }

    /**
     * Get side-by-side schema comparison.
     *
     * @param \WP_REST_Request $request Request.
     * @return \WP_REST_Response
     */
    public function get_schema_comparison( $request ) {
        $post_id  = absint( $request->get_param( 'post_id' ) );
        $conflict = new Conflict();
        return rest_ensure_response( $conflict->get_schema_comparison( $post_id ) );
    }

    /**
     * Save a per-type schema resolution.
     *
     * @param \WP_REST_Request $request Request.
     * @return \WP_REST_Response
     */
    public function resolve_schema_type( $request ) {
        $type   = sanitize_text_field( $request->get_param( 'type' ) );
        $choice = sanitize_text_field( $request->get_param( 'choice' ) );
        $conflict = new Conflict();
        return rest_ensure_response( $conflict->resolve_schema_type( $type, $choice ) );
    }

    // -----------------------------------------------------------------------
    // Crawler Log
    // -----------------------------------------------------------------------

    /**
     * Get paginated crawler log.
     *
     * @param \WP_REST_Request $request Request.
     * @return \WP_REST_Response
     */
    public function get_crawler_log( $request ) {
        $page     = absint( $request->get_param( 'page' ) ) ?: 1;
        $per_page = absint( $request->get_param( 'per_page' ) ) ?: 50;
        $log      = new CrawlerLog();
        return rest_ensure_response( $log->get_entries( $page, $per_page ) );
    }

    /**
     * Get crawler log summary stats.
     *
     * @return \WP_REST_Response
     */
    public function get_crawler_log_summary() {
        $log = new CrawlerLog();
        return rest_ensure_response( $log->get_summary() );
    }

    /**
     * Clear crawler log.
     *
     * @return \WP_REST_Response
     */
    public function clear_crawler_log() {
        $log = new CrawlerLog();
        $log->clear();
        return rest_ensure_response( array( 'success' => true ) );
    }

    // -----------------------------------------------------------------------
    // Content Gaps
    // -----------------------------------------------------------------------

    /**
     * Get content gap scan results.
     *
     * @return \WP_REST_Response
     */
    public function get_content_gaps() {
        $gaps = new ContentGaps();
        return rest_ensure_response( $gaps->get_results() );
    }

    /**
     * Run a fresh content gap scan.
     *
     * @return \WP_REST_Response
     */
    public function run_content_gap_scan() {
        $gaps   = new ContentGaps();
        $result = $gaps->scan();
        $this->log_activity( 'content_gap_scan', __( 'Content gap scan completed.', 'aeo-god-mode' ) );
        return rest_ensure_response( $result );
    }

    /**
     * Apply a content gap fix.
     *
     * @param \WP_REST_Request $request Request.
     * @return \WP_REST_Response
     */
    public function apply_content_gap_fix( $request ) {
        $post_id  = absint( $request->get_param( 'post_id' ) );
        $fix_type = sanitize_text_field( $request->get_param( 'fix_type' ) );
        $gaps     = new ContentGaps();
        $result   = $gaps->apply_fix( $post_id, $fix_type, $request->get_params() );
        if ( is_array( $result ) && ! empty( $result['success'] ) ) {
            $operation = (string) ( $result['operation'] ?? '' );
            if ( 'analysis' === $operation ) {
                $this->log_activity( 'content_gap_analysis', __( 'Content gap analysis ready.', 'aeo-god-mode' ) );
            } elseif ( 'preview' === $operation ) {
                $this->log_activity( 'content_gap_preview', __( 'Content improvement preview ready; nothing saved.', 'aeo-god-mode' ) );
            } elseif ( 'draft' === $operation ) {
                $this->log_activity( 'content_gap_draft', __( 'Content gap draft options ready; nothing saved.', 'aeo-god-mode' ) );
            } else {
                update_option( 'asgm_content_gap_fixes_applied', (int) get_option( 'asgm_content_gap_fixes_applied', 0 ) + 1, false );
                $this->log_activity( 'content_gap_fix', __( 'Content gap fix saved.', 'aeo-god-mode' ) );
            }
        }
        return rest_ensure_response( $result );
    }

    /**
     * Setup checklist state for the Dashboard activation card.
     *
     * Every step's done-state is derived from feature state that already
     * exists; nothing here is stored except the two UI flags (dismissed,
     * celebrated). Result numbers are read from the same state used for
     * detection so the card never shows canned copy.
     *
     * @param \WP_REST_Request $request Request.
     * @return \WP_REST_Response
     */
    public function get_setup_checklist( $request ) {
        global $wpdb;

        $flags = get_option( 'asgm_setup_checklist', array() );
        $plan  = \AISEOGodMode\License::is_pro() ? 'pro' : 'free';

        // 1. Content Gaps scan.
        $gap_results = get_option( 'asgm_content_gap_results', array() );
        $gaps_done   = '' !== (string) get_option( 'asgm_content_gap_last_scan', '' );

        // Free variant step 2: at least one auto-fix applied.
        $fixes_applied = (int) get_option( 'asgm_content_gap_fixes_applied', 0 );

        // 2. Search Console connected.
        $gsc_connected = false;
        $gsc_queries   = 0;
        if ( class_exists( '\AISEOGodMode\GSC' ) ) {
            $gsc_status    = ( new \AISEOGodMode\GSC() )->get_status();
            $gsc_connected = ! empty( $gsc_status['connected'] );
            $gsc_snapshot  = is_callable( array( '\AISEOGodMode\GSC', 'get_coherent_snapshot' ) )
                ? (array) \AISEOGodMode\GSC::get_coherent_snapshot()
                : array();
            $gsc_queries   = count( isset( $gsc_snapshot['queries'] )
                ? (array) $gsc_snapshot['queries']
                : (array) get_option( 'asgm_gsc_query_data', array() ) );
        }

        // 3. Topical Map rows. Direct table query so the check works even
        // when the Pro class is not loaded (expired license, Free build).
        $map_topics = 0;
        $map_table  = $wpdb->prefix . 'asgm_topical_map';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $map_table ) ) === $map_table ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $map_topics = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$map_table}` WHERE status NOT IN ('dismissed')" );
        }

        // 4. First draft generated from the map.
        $draft_ids = get_posts( array(
            'post_type'      => 'any',
            'post_status'    => 'any',
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'meta_key'       => '_asgm_topic_draft', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
            'no_found_rows'  => true,
        ) );
        $draft_id  = $draft_ids ? (int) $draft_ids[0] : 0;

        // 5. Citation check has run; count engines currently citing the site.
        $cite_results = (array) get_option( 'asgm_citation_results', array() );
        $cite_done    = ! empty( $cite_results ) || '' !== (string) get_option( 'asgm_citation_last_check', '' );
        $cite_engines = array();
        foreach ( $cite_results as $row ) {
            if ( ! empty( $row['cited'] ) && ! empty( $row['engine'] ) ) {
                $cite_engines[ strtolower( (string) $row['engine'] ) ] = true;
            }
        }

        return rest_ensure_response( array(
            'plan'       => $plan,
            'dismissed'  => ! empty( $flags['dismissed'] ),
            'celebrated' => ! empty( $flags['celebrated'] ),
            'steps'      => array(
                'gaps'  => array(
                    'done'  => $gaps_done,
                    'count' => is_array( $gap_results ) ? count( $gap_results ) : 0,
                ),
                'fix'   => array(
                    'done'  => $fixes_applied > 0,
                    'count' => $fixes_applied,
                ),
                'gsc'   => array(
                    'done'    => $gsc_connected,
                    'queries' => $gsc_queries,
                ),
                'map'   => array(
                    'done'   => $map_topics > 0,
                    'topics' => $map_topics,
                ),
                'draft' => array(
                    'done'     => $draft_id > 0,
                    'edit_url' => $draft_id ? admin_url( 'post.php?post=' . $draft_id . '&action=edit' ) : '',
                ),
                'cite'  => array(
                    'done'    => $cite_done,
                    'engines' => count( $cite_engines ),
                ),
            ),
        ) );
    }

    /**
     * Persist the two setup-checklist UI flags. Done-states are never stored.
     *
     * @param \WP_REST_Request $request Request.
     * @return \WP_REST_Response
     */
    public function save_setup_checklist_flags( $request ) {
        $flags = get_option( 'asgm_setup_checklist', array() );
        if ( null !== $request->get_param( 'dismissed' ) ) {
            $flags['dismissed'] = rest_sanitize_boolean( $request->get_param( 'dismissed' ) );
        }
        if ( null !== $request->get_param( 'celebrated' ) ) {
            $flags['celebrated'] = rest_sanitize_boolean( $request->get_param( 'celebrated' ) );
        }
        update_option( 'asgm_setup_checklist', $flags, false );
        return rest_ensure_response( array(
            'success'    => true,
            'dismissed'  => ! empty( $flags['dismissed'] ),
            'celebrated' => ! empty( $flags['celebrated'] ),
        ) );
    }

    /**
     * OKF bundle status (stats, graph, serving URLs).
     *
     * @param \WP_REST_Request $request Request.
     * @return \WP_REST_Response
     */
    public function get_okf( $request ) {
        $okf = new OKF();
        return rest_ensure_response( $okf->get_status() );
    }

    /**
     * Force-regenerate the OKF bundle.
     *
     * @param \WP_REST_Request $request Request.
     * @return \WP_REST_Response
     */
    public function generate_okf( $request ) {
        $okf = new OKF();
        return rest_ensure_response( $okf->regenerate() );
    }

    // -----------------------------------------------------------------------
    // Validation
    // -----------------------------------------------------------------------

    /**
     * Validate schema for a single post.
     *
     * @param \WP_REST_Request $request Request.
     * @return \WP_REST_Response
     */
    public function validate_schema( $request ) {
        $post_id   = absint( $request->get_param( 'post_id' ) );
        $validator = new Validator();
        return rest_ensure_response( $validator->validate_post( $post_id ) );
    }

    /**
     * Bulk validate all posts.
     *
     * @return \WP_REST_Response
     */
    public function validate_bulk() {
        $validator = new Validator();
        return rest_ensure_response( $validator->validate_all() );
    }

    // -----------------------------------------------------------------------
    // GSC
    // -----------------------------------------------------------------------

    /**
     * Get GSC connection status.
     *
     * @return \WP_REST_Response
     */
    public function get_gsc_status() {
        $gsc = new GSC();
        return rest_ensure_response( $gsc->get_status() );
    }

    /**
     * Start GSC OAuth connection.
     *
     * @return \WP_REST_Response
     */
    public function connect_gsc() {
        $gsc = new GSC();
        return rest_ensure_response( $gsc->initiate_connection() );
    }

    /**
     * Get per-page GSC data.
     *
     * @return \WP_REST_Response
     */
    public function get_gsc_pages() {
        $gsc = new GSC();
        return rest_ensure_response( $gsc->get_pages() );
    }

    /**
     * Get GSC alerts.
     *
     * @return \WP_REST_Response
     */
    public function get_gsc_alerts() {
        $gsc = new GSC();
        return rest_ensure_response( $gsc->get_alerts() );
    }

    /**
     * Handle Google OAuth callback (redirect from Google).
     *
     * @param \WP_REST_Request $request Request.
     * @return \WP_REST_Response|\WP_Error
     */
    public function gsc_oauth_callback( $request ) {
        $code         = $request->get_param( 'code' );
        $state        = $request->get_param( 'state' );
        $error        = $request->get_param( 'error' );
        $proxy_tokens = $request->get_param( 'proxy_tokens' );
        $proxy_error  = $request->get_param( 'proxy_error' );

        // If Google returned an error (user denied access etc).
        if ( ! empty( $error ) ) {
            wp_safe_redirect( admin_url( 'admin.php?page=aeo-god-mode&gsc_error=' . rawurlencode( $error ) ) );
            exit;
        }

        $gsc    = new GSC();
        $result = $gsc->handle_callback( $code, $state, $proxy_tokens, $proxy_error );

        if ( $result['success'] ) {
            wp_safe_redirect( admin_url( 'admin.php?page=aeo-god-mode&gsc_connected=1' ) );
        } else {
            wp_safe_redirect( admin_url( 'admin.php?page=aeo-god-mode&gsc_error=' . rawurlencode( $result['message'] ) ) );
        }
        exit;
    }

    /**
     * Disconnect GSC.
     *
     * @return \WP_REST_Response
     */
    public function disconnect_gsc() {
        $gsc = new GSC();
        return rest_ensure_response( $gsc->disconnect() );
    }

    /**
     * Sync data from GSC.
     *
     * @return \WP_REST_Response
     */
    public function sync_gsc() {
        $gsc = new GSC();
        return rest_ensure_response( $gsc->sync() );
    }

    /**
     * Get sync progress for background URL inspection.
     *
     * @return \WP_REST_Response
     */
    public function get_gsc_sync_progress() {
        $gsc = new GSC();
        return rest_ensure_response( $gsc->get_sync_progress() );
    }

    /**
     * Get classified GSC query data.
     *
     * @return \WP_REST_Response
     */
    public function get_gsc_queries() {
        $gsc = new GSC();
        return rest_ensure_response( $gsc->get_queries() );
    }

    /**
     * Get GSC AI query summary with fan-out clusters.
     *
     * @return \WP_REST_Response
     */
    public function get_gsc_ai_summary() {
        $gsc = new GSC();
        return rest_ensure_response( $gsc->get_ai_summary() );
    }

    /**
     * Get the cached 90-day daily series for sparklines and date-range
     * windows. Each row is { date, clicks, impressions, ctr, position }.
     *
     * @return \WP_REST_Response
     */
    public function get_gsc_daily_series() {
        $gsc = new GSC();
        return rest_ensure_response( $gsc->get_daily_series() );
    }

    /**
     * Keyword Optimize: weave a cluster's queries into the page (Pro).
     * Thin delegate; the implementation lives in the Pro plugin.
     *
     * @param \WP_REST_Request $request Request.
     * @return \WP_REST_Response
     */
    public function gsc_keyword_optimize( $request ) {
        if ( ! class_exists( '\AISEOGodMode\KeywordOptimize' ) ) {
            return new \WP_REST_Response( array( 'success' => false, 'error' => 'AEO God Mode Pro is required for Keyword Optimize.' ), 403 );
        }
        return KeywordOptimize::handle( $request );
    }

    /**
     * Get computed dashboard recommendations.
     *
     * @return \WP_REST_Response
     */
    public function get_gsc_recommendations() {
        $gsc = new GSC();
        return rest_ensure_response( $gsc->get_recommendations() );
    }

    /**
     * Get sitemap, device/country, hourly and imported Google AI evidence.
     *
     * @return \WP_REST_Response
     */
    public function get_gsc_extended() {
        $gsc = new GSC();
        return rest_ensure_response( $gsc->get_extended_data() );
    }

    /**
     * Import a CSV exported from Google's generative AI performance report.
     *
     * @param \WP_REST_Request $request Request.
     * @return \WP_REST_Response|\WP_Error
     */
    public function import_gsc_ai_visibility( $request ) {
        $gsc = new GSC();
        return rest_ensure_response( $gsc->import_ai_visibility_csv(
            (string) $request->get_param( 'csv' ),
            (string) $request->get_param( 'filename' )
        ) );
    }

    // -----------------------------------------------------------------------
    // Query Gap Detector
    // -----------------------------------------------------------------------

    /**
     * Scan every GSC query against its landing page and classify coverage.
     *
     * @return \WP_REST_Response
     */
    public function query_gap_scan() {
        if ( ! class_exists( '\AISEOGodMode\Query_Gap' ) ) {
            return rest_ensure_response( array( 'rows' => array(), 'error' => 'Query_Gap class not loaded.' ) );
        }
        $rows = \AISEOGodMode\Query_Gap::scan_all();
        return rest_ensure_response( array(
            'rows'  => $rows,
            'count' => count( $rows ),
        ) );
    }

    /**
     * Draft an AI-written answer for a question (the GSC query) targeted at
     * a specific page. Uses the same AI proxy as the Internal Link Builder
     * with a custom prompt asking for a short, direct, on-brand answer.
     * Costs 1 credit per draft.
     *
     * Caches drafts per (query, post_id) so reopening the modal doesn't re-bill.
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response
     */
    public function query_gap_draft_answer( $request ) {
        $query   = sanitize_text_field( (string) $request->get_param( 'query' ) );
        $post_id = absint( $request->get_param( 'post_id' ) );

        if ( empty( $query ) || ! $post_id ) {
            return rest_ensure_response( array( 'success' => false, 'error' => 'Missing query or post_id.' ) );
        }
        $post = get_post( $post_id );
        if ( ! $post || $post->post_status !== 'publish' ) {
            return rest_ensure_response( array( 'success' => false, 'error' => 'Target page not published.' ) );
        }

        // ── Draft cache (in-option, not transient — survives sync, cheap reads) ──
        $cache_key = md5( strtolower( trim( $query ) ) . '|' . $post_id );
        $cache     = (array) get_option( 'asgm_query_gap_draft_cache', array() );
        if ( isset( $cache[ $cache_key ] ) && ! empty( $cache[ $cache_key ]['answer'] ) ) {
            $cached_q = ! empty( $cache[ $cache_key ]['question'] ) ? (string) $cache[ $cache_key ]['question'] : '';
            if ( '' === $cached_q ) {
                $cached_q = ucfirst( trim( $query ) );
                if ( substr( $cached_q, -1 ) !== '?' ) {
                    $cached_q .= '?';
                }
            }
            return rest_ensure_response( array(
                'success'  => true,
                'question' => $cached_q,
                'answer'   => (string) $cache[ $cache_key ]['answer'],
                'cached'   => true,
                'credits'  => \AISEOGodMode\MetadataGenerator::get_credits(),
            ) );
        }

        // ── Credit check ──
        $credits = \AISEOGodMode\MetadataGenerator::get_credits();
        $credit_cost = 1;
        if ( isset( $credits['remaining'] ) && $credits['remaining'] < $credit_cost ) {
            return rest_ensure_response( array(
                'success' => false,
                'error'   => 'Not enough credits. This action requires ' . $credit_cost . ' credit. You have ' . $credits['remaining'] . ' remaining.',
            ) );
        }

        $license_key = \AISEOGodMode\License::get_key();
        if ( empty( $license_key ) ) {
            return rest_ensure_response( array(
                'success' => false,
                'error'   => 'A Pro or Agency license is required to draft FAQ answers.',
            ) );
        }

        // ── Build the prompt ──
        // We give the AI: the page title, the page summary (first 1200 chars of
        // plain text), any existing FAQ Q/A pairs for tone-matching, and the
        // question. We ask for a 1-3 sentence direct answer in JSON.
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- "the_content" is a WordPress core filter; applying it here is the canonical way to get processed post HTML.
        $plain_content   = wp_strip_all_tags( apply_filters( 'the_content', $post->post_content ) );
        $context_sample  = mb_substr( $plain_content, 0, 1200 );
        $existing_faqs   = array();
        $meta_faqs_json  = get_post_meta( $post_id, 'feature_faqs', true );
        if ( ! empty( $meta_faqs_json ) ) {
            $parsed = json_decode( $meta_faqs_json, true );
            if ( is_array( $parsed ) ) {
                foreach ( array_slice( $parsed, 0, 3 ) as $f ) {
                    if ( ! empty( $f['q'] ) && ! empty( $f['a'] ) ) {
                        $existing_faqs[] = "Q: {$f['q']}\nA: {$f['a']}";
                    }
                }
            }
        }
        $existing_block = empty( $existing_faqs ) ? '' : "\n\nEXISTING FAQ PAIRS ON THIS PAGE (match this tone exactly):\n" . implode( "\n\n", $existing_faqs );

        // Detect query shape so the prompt can reformulate noun-phrase queries
        // into proper questions instead of pretending "schema checker" is a Q.
        $shape = ( class_exists( '\AISEOGodMode\Query_Gap' ) )
            ? \AISEOGodMode\Query_Gap::query_shape( $query )
            : 'phrase';

        $shape_guidance = ( 'question' === $shape )
            ? "The query is already a well-formed question. Use it as the question (sentence-cased, ending with ?), with very light cleanup only."
            : "The query is a noun phrase, not a question. Reformulate it into the most natural question a real user would ask about that phrase on this page. Examples: 'schema checker' → 'What is a schema checker?'; 'gpt citation tracker' → 'How does a GPT citation tracker work?'; 'aeo wordpress plugin' → 'What is the best AEO plugin for WordPress?'. The reformulated question must still feature the original phrase words verbatim where natural.";

        $prompt = "You write ONE short FAQ Q&A pair for a query someone is already searching on Google. The output must match the tone of the page exactly.

PAGE TITLE
" . $post->post_title . "

PAGE CONTEXT (first 1200 chars of body text)
" . $context_sample . $existing_block . "

THE QUERY (a real string from Google Search Console)
" . $query . "

QUERY SHAPE
" . $shape_guidance . "

YOUR TASK
Return ONE JSON object only, no markdown fences:

{
  \"question\": \"<the final question, sentence-cased, ending with a question mark>\",
  \"answer\":   \"<a single answer of 1-3 sentences. Direct first sentence. No marketing fluff. Match the existing FAQ tone if present. Use plain text, no HTML except an inline <strong> for the product name if useful. Never start with 'Yes' alone — explain. Never start with 'Well' or 'So' or 'Basically'.>\"
}

HARD RULES
- The question MUST end with a question mark.
- The question MUST contain the meaningful words from the original query (so it still answers the searcher's actual intent).
- The answer is 1 to 3 sentences total. Anything longer is wrong.
- Plain English. No em dashes anywhere — use commas, periods, or parens.
- Never claim a feature exists if the PAGE CONTEXT does not support it.
- Output ONLY the JSON object.";

        // ── Send to proxy ──
        $payload = wp_json_encode( array(
            'license_key' => $license_key,
            'task'        => 'link_micro_rewrite',  // reusing the generic prompt-relay task
            'content'     => '',
            'title'       => '',
            'prompt'      => base64_encode( $prompt ),
        ) );

        $response = wp_remote_post( 'https://aeogodmode.io/wp-json/asgm/v1/ai-assist', array(
            'body'    => $payload,
            'headers' => array( 'Content-Type' => 'application/json' ),
            'timeout' => 30,
        ) );

        if ( is_wp_error( $response ) ) {
            return rest_ensure_response( array(
                'success' => false,
                'error'   => 'AI proxy error: ' . $response->get_error_message(),
            ) );
        }
        $status = wp_remote_retrieve_response_code( $response );
        $body   = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( $status !== 200 || empty( $body['success'] ) ) {
            $err = $body['error'] ?? ( 'AI proxy returned status ' . $status );
            return rest_ensure_response( array( 'success' => false, 'error' => $err ) );
        }

        $result   = $body['result'] ?? array();
        $answer   = is_array( $result ) && ! empty( $result['answer'] )   ? (string) $result['answer']   : '';
        $question = is_array( $result ) && ! empty( $result['question'] ) ? (string) $result['question'] : '';

        // Backward-compat default: derive question from query if AI omitted it.
        if ( '' === $question ) {
            $question = ucfirst( trim( $query ) );
            if ( substr( $question, -1 ) !== '?' ) {
                $question .= '?';
            }
        }
        if ( '' === $answer ) {
            return rest_ensure_response( array(
                'success' => false,
                'error'   => 'AI returned an empty answer. Try editing the question or rerunning.',
            ) );
        }

        // Strip em dashes defensively (matches site copy rules).
        $answer   = strtr( $answer,   array( '—' => ',', '–' => ',' ) );
        $question = strtr( $question, array( '—' => ',', '–' => ',' ) );

        // Cache the draft.
        $cache[ $cache_key ] = array(
            'question'  => $question,
            'answer'    => $answer,
            'cached_at' => time(),
        );
        if ( count( $cache ) > 500 ) {
            $cache = array_slice( $cache, -500, null, true );
        }
        update_option( 'asgm_query_gap_draft_cache', $cache, false );

        return rest_ensure_response( array(
            'success'  => true,
            'question' => $question,
            'answer'   => $answer,
            'cached'   => false,
            'credits'  => \AISEOGodMode\MetadataGenerator::get_credits(),
        ) );
    }

    /**
     * Apply a drafted FAQ to the target page. Handles four page types:
     *   1. Pages using feature_faqs meta (marketing /plugin/* pages)
     *   2. Posts containing a portable [aeogm_faqs] block
     *   3. Posts containing a valid legacy [faq] block
     *   4. Posts without an FAQ block — append a portable block at the end
     *
     * Records to asgm_query_gap_applied for audit + idempotence.
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response
     */
    public function query_gap_apply_faq( $request ) {
        $query     = sanitize_text_field( (string) $request->get_param( 'query' ) );
        // The "question" param is the editable, user-confirmed phrasing.
        // For backward compat, fall back to deriving it from the raw query.
        $question  = sanitize_text_field( (string) $request->get_param( 'question' ) );
        $answer    = wp_kses_post( (string) $request->get_param( 'answer' ) );
        $post_id   = absint( $request->get_param( 'post_id' ) );

        if ( empty( $query ) || empty( $answer ) || ! $post_id ) {
            return rest_ensure_response( array( 'success' => false, 'error' => 'Missing query, answer, or post_id.' ) );
        }
        $post = get_post( $post_id );
        if ( ! $post || $post->post_status !== 'publish' ) {
            return rest_ensure_response( array( 'success' => false, 'error' => 'Target page not published.' ) );
        }
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return rest_ensure_response( array( 'success' => false, 'error' => 'No permission to edit this page.' ) );
        }

        // Prefer the user-edited question; only fall back to the raw query if
        // the caller didn't send one.
        if ( '' === $question ) {
            $question = ucfirst( trim( $query ) );
        }
        $question = trim( $question );
        if ( '' !== $question && substr( $question, -1 ) !== '?' ) {
            $question .= '?';
        }
        $answer = trim( $answer );

        $mode = 'unknown';

        // ── Path 1: feature_faqs meta (marketing pages) ──
        $existing_meta = get_post_meta( $post_id, 'feature_faqs', true );
        if ( ! empty( $existing_meta ) ) {
            $faqs = json_decode( $existing_meta, true );
            if ( is_array( $faqs ) ) {
                // Idempotence: bail if a near-identical question is already there.
                foreach ( $faqs as $f ) {
                    if ( ! empty( $f['q'] ) && strtolower( trim( $f['q'] ) ) === strtolower( $question ) ) {
                        return rest_ensure_response( array(
                            'success' => false,
                            'error'   => 'This question is already in the page FAQ.',
                            'mode'    => 'feature_meta',
                        ) );
                    }
                }
                $faqs[] = array( 'q' => $question, 'a' => $answer );
                update_post_meta( $post_id, 'feature_faqs', wp_slash( wp_json_encode( $faqs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ) );
                $mode = 'feature_meta';
            }
        }

        // ── Paths 2–4: post_content with or without an existing FAQ block ──
        if ( 'unknown' === $mode ) {
            $content = $post->post_content;
            $portable_question = strtr( $question, array( '"' => "'", ']' => ')' ) );
            $portable_item     = '[aeogm_faq q="' . $portable_question . '"]' . $answer . '[/aeogm_faq]';
            $legacy_item       = '[q]' . $question . '[/q]' . "\n" . '[a]' . $answer . '[/a]';

            // One parser owns idempotence across both portable and legacy formats.
            if ( class_exists( '\\AISEOGodMode\\FaqParser' ) ) {
                $parsed = \AISEOGodMode\FaqParser::parse_aeogm( $content );
                foreach ( $parsed['pairs'] as $pair ) {
                    if ( strtolower( trim( $pair['question'] ) ) === strtolower( $question ) ) {
                        return rest_ensure_response( array(
                            'success' => false,
                            'error'   => 'This question is already in the page FAQ.',
                            'mode'    => 'faq_shortcode',
                        ) );
                    }
                }
            }

            if ( preg_match( '/\[aeogm_faqs\b[^\]]*\]/i', $content ) ) {
                // Add to the first portable wrapper. The callback prevents $-style
                // replacement tokens inside an AI-written answer being interpreted.
                $new_content = preg_replace_callback(
                    '/\[\/aeogm_faqs\]/i',
                    function ( $match ) use ( $portable_item ) {
                        return $portable_item . "\n" . $match[0];
                    },
                    $content,
                    1
                );
                $mode = 'faq_shortcode';
            } elseif ( preg_match( '/\[faq\b[^\]]*\]/i', $content ) ) {
                // Preserve a valid legacy wrapper already owned by the active theme.
                if ( false !== stripos( $content, '[q]' . $question . '[/q]' ) ) {
                    return rest_ensure_response( array(
                        'success' => false,
                        'error'   => 'This question is already in the page FAQ.',
                        'mode'    => 'faq_shortcode',
                    ) );
                }
                $new_content = preg_replace_callback(
                    '/\[\/faq\]/i',
                    function ( $match ) use ( $legacy_item ) {
                        return $legacy_item . "\n" . $match[0];
                    },
                    $content,
                    1
                );
                $mode = 'faq_shortcode';
            } else {
                // New content always gets the theme-independent, native-details block.
                $new_content = $content . "\n\n"
                    . '<!-- wp:shortcode -->' . "\n"
                    . '[aeogm_faqs title="Frequently Asked Questions" open="first" style="boxed"]' . "\n"
                    . $portable_item . "\n"
                    . '[/aeogm_faqs]' . "\n"
                    . '<!-- /wp:shortcode -->';
                $mode = 'new_faq_block';
            }

            $upd = wp_update_post( array(
                'ID'           => $post_id,
                'post_content' => wp_slash( $new_content ),
            ), true );
            if ( is_wp_error( $upd ) ) {
                return rest_ensure_response( array( 'success' => false, 'error' => 'Failed to update page: ' . $upd->get_error_message() ) );
            }
        }

        // ── Audit log + cache invalidation ──
        if ( class_exists( '\AISEOGodMode\Query_Gap' ) ) {
            \AISEOGodMode\Query_Gap::record_applied( $query, $post_id, $answer, $mode );
        }

        return rest_ensure_response( array(
            'success' => true,
            'mode'    => $mode,
            'edit_url' => get_edit_post_link( $post_id, 'raw' ),
        ) );
    }

    /**
     * Draft an AI-written H2 heading + intro paragraph for a phrase-shaped
     * query, so phrase queries like "schema checker" can be added as a real
     * page section instead of being forced into a fake FAQ pair.
     *
     * Caches per (query, post_id) in `asgm_query_gap_draft_heading_cache`.
     * Costs 1 credit per fresh draft. Reuses the existing AI proxy.
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response
     */
    public function query_gap_draft_heading( $request ) {
        $query   = sanitize_text_field( (string) $request->get_param( 'query' ) );
        $post_id = absint( $request->get_param( 'post_id' ) );

        if ( empty( $query ) || ! $post_id ) {
            return rest_ensure_response( array( 'success' => false, 'error' => 'Missing query or post_id.' ) );
        }
        $post = get_post( $post_id );
        if ( ! $post || $post->post_status !== 'publish' ) {
            return rest_ensure_response( array( 'success' => false, 'error' => 'Target page not published.' ) );
        }

        $cache_key = md5( strtolower( trim( $query ) ) . '|' . $post_id );
        $cache     = (array) get_option( 'asgm_query_gap_draft_heading_cache', array() );
        if ( isset( $cache[ $cache_key ] ) && ! empty( $cache[ $cache_key ]['heading'] ) ) {
            return rest_ensure_response( array(
                'success' => true,
                'heading' => (string) $cache[ $cache_key ]['heading'],
                'intro'   => (string) ( $cache[ $cache_key ]['intro'] ?? '' ),
                'cached'  => true,
                'credits' => \AISEOGodMode\MetadataGenerator::get_credits(),
            ) );
        }

        $credits = \AISEOGodMode\MetadataGenerator::get_credits();
        if ( isset( $credits['remaining'] ) && $credits['remaining'] < 1 ) {
            return rest_ensure_response( array(
                'success' => false,
                'error'   => 'Not enough credits. This action requires 1 credit. You have ' . $credits['remaining'] . ' remaining.',
            ) );
        }

        $license_key = \AISEOGodMode\License::get_key();
        if ( empty( $license_key ) ) {
            return rest_ensure_response( array(
                'success' => false,
                'error'   => 'A Pro or Agency license is required to draft headings.',
            ) );
        }

        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- "the_content" is a WordPress core filter; applying it here is the canonical way to get processed post HTML.
        $plain_content  = wp_strip_all_tags( apply_filters( 'the_content', $post->post_content ) );
        $context_sample = mb_substr( $plain_content, 0, 1200 );

        // Pull existing H2s so the AI can match site voice + avoid duplicates.
        $existing_h2s = array();
        if ( preg_match_all( '#<h2[^>]*>(.*?)</h2>#is', $post->post_content, $h2m ) ) {
            foreach ( array_slice( $h2m[1], 0, 8 ) as $h ) {
                $existing_h2s[] = trim( wp_strip_all_tags( $h ) );
            }
        }
        $existing_block = empty( $existing_h2s ) ? '' : "\n\nEXISTING H2 HEADINGS ON THIS PAGE (match this voice + style; do not duplicate):\n- " . implode( "\n- ", $existing_h2s );

        $prompt = "You draft ONE new H2 section heading + a short intro paragraph for a topical query a user is searching on Google. The heading goes on an existing page, so the wording must match the page's tone.

PAGE TITLE
" . $post->post_title . "

PAGE CONTEXT (first 1200 chars of body text)
" . $context_sample . $existing_block . "

THE QUERY (a real noun-phrase string from Google Search Console)
" . $query . "

YOUR TASK
Return ONE JSON object only, no markdown fences:

{
  \"heading\": \"<short H2 text, 3-8 words, contains the meaningful words of the query verbatim where natural. Sentence case. Do not end with punctuation unless it's a colon.>\",
  \"intro\":   \"<a single intro paragraph of 2-3 sentences that opens this new section. Direct first sentence. Match the page's tone. Use plain text, no HTML except an inline <strong> for the product name if useful.>\"
}

HARD RULES
- The heading is a HEADLINE, not a question. Never end it with ?.
- The heading MUST contain the meaningful words from the original query (so the section actually targets the search intent).
- The intro is 2 to 3 sentences. Anything longer is wrong.
- Plain English. No em dashes anywhere — use commas, periods, or parens.
- Never claim a feature exists if the PAGE CONTEXT does not support it.
- Do not reuse an existing heading on this page.
- Output ONLY the JSON object.";

        $payload = wp_json_encode( array(
            'license_key' => $license_key,
            'task'        => 'link_micro_rewrite',
            'content'     => '',
            'title'       => '',
            'prompt'      => base64_encode( $prompt ),
        ) );

        $response = wp_remote_post( 'https://aeogodmode.io/wp-json/asgm/v1/ai-assist', array(
            'body'    => $payload,
            'headers' => array( 'Content-Type' => 'application/json' ),
            'timeout' => 30,
        ) );

        if ( is_wp_error( $response ) ) {
            return rest_ensure_response( array(
                'success' => false,
                'error'   => 'AI proxy error: ' . $response->get_error_message(),
            ) );
        }
        $status = wp_remote_retrieve_response_code( $response );
        $body   = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( $status !== 200 || empty( $body['success'] ) ) {
            $err = $body['error'] ?? ( 'AI proxy returned status ' . $status );
            return rest_ensure_response( array( 'success' => false, 'error' => $err ) );
        }

        $result  = $body['result'] ?? array();
        $heading = is_array( $result ) && ! empty( $result['heading'] ) ? (string) $result['heading'] : '';
        $intro   = is_array( $result ) && ! empty( $result['intro'] )   ? (string) $result['intro']   : '';
        if ( '' === $heading || '' === $intro ) {
            return rest_ensure_response( array(
                'success' => false,
                'error'   => 'AI returned an incomplete draft. Try regenerating.',
            ) );
        }

        // Strip em dashes defensively.
        $heading = strtr( $heading, array( '—' => ',', '–' => ',' ) );
        $intro   = strtr( $intro,   array( '—' => ',', '–' => ',' ) );

        // Trim a trailing question mark if the AI ignored "do not end with ?".
        $heading = rtrim( $heading, "?.;:!" );

        $cache[ $cache_key ] = array(
            'heading'   => $heading,
            'intro'     => $intro,
            'cached_at' => time(),
        );
        if ( count( $cache ) > 500 ) {
            $cache = array_slice( $cache, -500, null, true );
        }
        update_option( 'asgm_query_gap_draft_heading_cache', $cache, false );

        return rest_ensure_response( array(
            'success' => true,
            'heading' => $heading,
            'intro'   => $intro,
            'cached'  => false,
            'credits' => \AISEOGodMode\MetadataGenerator::get_credits(),
        ) );
    }

    /**
     * Apply a drafted H2 heading + intro paragraph to the target page.
     * Appends to the end of post_content so the position is predictable.
     * The success response tells the user the section was added at the
     * bottom so they can reposition in the editor if they prefer.
     *
     * Idempotence: refuses if an existing heading on the page already has
     * >=70% token overlap with the proposed heading.
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response
     */
    public function query_gap_apply_heading( $request ) {
        $query   = sanitize_text_field( (string) $request->get_param( 'query' ) );
        $heading = sanitize_text_field( (string) $request->get_param( 'heading' ) );
        $intro   = wp_kses_post( (string) $request->get_param( 'intro' ) );
        $post_id = absint( $request->get_param( 'post_id' ) );

        if ( empty( $query ) || empty( $heading ) || empty( $intro ) || ! $post_id ) {
            return rest_ensure_response( array( 'success' => false, 'error' => 'Missing query, heading, intro, or post_id.' ) );
        }
        $post = get_post( $post_id );
        if ( ! $post || $post->post_status !== 'publish' ) {
            return rest_ensure_response( array( 'success' => false, 'error' => 'Target page not published.' ) );
        }
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return rest_ensure_response( array( 'success' => false, 'error' => 'No permission to edit this page.' ) );
        }

        // The caller chooses the level. Anything other than h3 is treated as h2,
        // so a malformed or absent value can never emit an unexpected tag.
        $level = strtolower( trim( (string) $request->get_param( 'level' ) ) );
        $level = ( 'h3' === $level ) ? 'h3' : 'h2';

        $heading = trim( rtrim( $heading, '?.!:;' ) );
        $intro   = trim( $intro );

        // Idempotence. Check BOTH levels: a duplicate H3 competes with an
        // existing H2 on the same topic just as badly as a duplicate H2 would.
        if ( class_exists( '\AISEOGodMode\Query_Gap' ) ) {
            $h_tokens = \AISEOGodMode\Query_Gap::tokens( $heading );
            if ( ! empty( $h_tokens ) && preg_match_all( '#<h([23])[^>]*>(.*?)</h\1>#is', $post->post_content, $hm ) ) {
                foreach ( $hm[2] as $existing_h ) {
                    $e_tokens = \AISEOGodMode\Query_Gap::tokens( wp_strip_all_tags( $existing_h ) );
                    if ( empty( $e_tokens ) ) continue;
                    $inter = array_intersect( $h_tokens, $e_tokens );
                    $denom = min( count( $h_tokens ), count( $e_tokens ) );
                    if ( $denom > 0 && ( count( $inter ) / $denom ) >= 0.7 ) {
                        return rest_ensure_response( array(
                            'success' => false,
                            'error'   => 'A similar heading already exists on this page: "' . wp_strip_all_tags( $existing_h ) . '".',
                        ) );
                    }
                }
            }
        }

        // Build the section HTML. Two clean elements only — wpautop handles the rest.
        $section = "\n\n<{$level}>" . esc_html( $heading ) . "</{$level}>\n<p>" . wp_kses_post( $intro ) . "</p>";

        // An H2 is a new top-level section, so it belongs at the end. An H3 is a
        // sub-point of an existing section, and appending it to the end of the
        // post would file it under whichever H2 happens to be last. Place it at
        // the end of the section it actually belongs to instead.
        $new_content = $post->post_content . $section;
        if ( 'h3' === $level ) {
            $anchor = self::find_parent_section_end( $post->post_content, $heading );
            if ( null !== $anchor ) {
                $new_content = substr( $post->post_content, 0, $anchor )
                    . $section . "\n\n"
                    . substr( $post->post_content, $anchor );
            }
        }
        $upd = wp_update_post( array(
            'ID'           => $post_id,
            'post_content' => wp_slash( $new_content ),
        ), true );
        if ( is_wp_error( $upd ) ) {
            return rest_ensure_response( array(
                'success' => false,
                'error'   => 'Failed to update page: ' . $upd->get_error_message(),
            ) );
        }

        if ( class_exists( '\AISEOGodMode\Query_Gap' ) ) {
            \AISEOGodMode\Query_Gap::record_applied(
                $query,
                $post_id,
                $heading . "\n\n" . $intro,
                $level . '_section'
            );
        }

        return rest_ensure_response( array(
            'success'  => true,
            'mode'     => $level . '_section',
            'level'    => $level,
            'edit_url' => get_edit_post_link( $post_id, 'raw' ),
        ) );
    }

    /**
     * Where an H3 should go: the end of the H2 section it belongs to.
     *
     * Picks the existing H2 whose wording overlaps the new heading most, then
     * returns the offset just before the next H2 (or the end of the post if the
     * matched section is the last one). Returns null when nothing matches well
     * enough, in which case the caller appends and the H3 trails the final
     * section, which is the same behaviour as before this existed.
     *
     * @param string $content Post content.
     * @param string $heading The new heading text.
     * @return int|null Byte offset to insert at, or null for no good parent.
     */
    private static function find_parent_section_end( $content, $heading ) {
        if ( ! class_exists( '\AISEOGodMode\Query_Gap' ) ) {
            return null;
        }
        $tokens = \AISEOGodMode\Query_Gap::tokens( $heading );
        if ( empty( $tokens ) ) {
            return null;
        }
        if ( ! preg_match_all( '#<h2[^>]*>(.*?)</h2>#is', $content, $m, PREG_OFFSET_CAPTURE ) ) {
            return null;
        }

        // Query_Gap::tokens does not stem, so "grind" and "grinding" look like
        // different words to a plain intersection and a question about grind
        // size would never find the Grinding section. Count a pair as matching
        // when one token is a prefix of the other, which covers the plural and
        // participle endings that separate a question from a heading.
        $matches = function ( $a, $b ) {
            foreach ( $a as $x ) {
                foreach ( $b as $y ) {
                    if ( $x === $y ) {
                        return true;
                    }
                    $short = strlen( $x ) < strlen( $y ) ? $x : $y;
                    $long  = strlen( $x ) < strlen( $y ) ? $y : $x;
                    if ( strlen( $short ) >= 4 && 0 === strpos( $long, $short ) ) {
                        return true;
                    }
                }
            }
            return false;
        };

        $best       = null;
        $best_score = 0.0;
        foreach ( $m[1] as $i => $cap ) {
            $h_tokens = \AISEOGodMode\Query_Gap::tokens( wp_strip_all_tags( $cap[0] ) );
            if ( empty( $h_tokens ) ) {
                continue;
            }
            $hits = 0;
            foreach ( $h_tokens as $ht ) {
                if ( $matches( array( $ht ), $tokens ) ) {
                    $hits++;
                }
            }
            $score = $hits / count( $h_tokens );
            if ( $score > $best_score ) {
                $best_score = $score;
                $best       = $i;
            }
        }

        // Needs a real topical relationship. Below this the H3 would be filed
        // under a section it has nothing to do with, which is worse than
        // appending it at the end. One matching word out of a three word
        // heading clears this; zero does not.
        if ( null === $best || $best_score < 0.3 ) {
            return null;
        }

        // End of the matched section = start of the next H2, or end of content.
        $next = $best + 1;
        if ( isset( $m[0][ $next ] ) ) {
            return (int) $m[0][ $next ][1];
        }
        return strlen( $content );
    }

    /**
     * Submit URLs to IndexNow.
     *
     * @param \WP_REST_Request $request The API request.
     * @return \WP_REST_Response
     */
    public function gsc_index_now( $request ) {
        $gsc  = new GSC();
        $urls = $request->get_param( 'urls' );
        return rest_ensure_response( $gsc->submit_index_now( $urls ) );
    }

    // -----------------------------------------------------------------------
    // Internal Link Builder
    // -----------------------------------------------------------------------

    /**
     * Find internal link opportunities for a target URL.
     *
     * @param \WP_REST_Request $request Request object.
     * @return \WP_REST_Response
     */
    public function gsc_build_links( $request ) {
        $target_url   = esc_url_raw( $request->get_param( 'target_url' ) );
        $anchor_hint  = sanitize_text_field( $request->get_param( 'anchor_hint' ) ?? '' );
        $max_results  = absint( $request->get_param( 'max_results' ) ?: 5 );
        $max_results  = min( max( $max_results, 1 ), 9 );
        $ignore_cache = (bool) $request->get_param( 'ignore_cache' );

        if ( empty( $target_url ) ) {
            return rest_ensure_response( array( 'success' => false, 'error' => 'target_url is required.' ) );
        }

        $result = Internal_Link_Builder::find_opportunities( $target_url, $anchor_hint, $max_results, $ignore_cache );
        return rest_ensure_response( $result );
    }

    /**
     * Apply a single link suggestion to a post.
     *
     * @param \WP_REST_Request $request Request object.
     * @return \WP_REST_Response
     */
    public function gsc_apply_link( $request ) {
        $source_post_id     = absint( $request->get_param( 'source_post_id' ) );
        $original_sentence  = $request->get_param( 'original_sentence' );
        $rewritten_sentence = $request->get_param( 'rewritten_sentence' );
        $target_url         = esc_url_raw( $request->get_param( 'target_url' ) ?? '' );

        if ( ! $source_post_id || empty( $original_sentence ) || empty( $rewritten_sentence ) ) {
            return rest_ensure_response( array( 'success' => false, 'error' => 'Missing required parameters.' ) );
        }

        $result = Internal_Link_Builder::apply_suggestion( $source_post_id, $original_sentence, $rewritten_sentence, $target_url );
        return rest_ensure_response( $result );
    }

    /**
     * Build or rebuild the section index for all posts.
     *
     * @return \WP_REST_Response
     */
    public function gsc_build_section_index( $request ) {
        // Indexing is incremental: unchanged sections are skipped by content
        // hash, so a healthy rebuild legitimately reports zero added. That
        // looked like a dead button, so a force rebuild is now possible and
        // the response always says what actually happened.
        $force = $request instanceof \WP_REST_Request ? (bool) $request->get_param( 'force' ) : false;
        if ( $force && method_exists( '\AISEOGodMode\Section_Index', 'clear_all' ) ) {
            Section_Index::clear_all();
        }

        $index_result = Section_Index::index_all();
        $embed_result = Section_Index::embed_all();
        $stats        = Section_Index::get_stats();

        $added    = (int) ( $index_result['sections_added'] ?? 0 );
        $embedded = (int) ( $embed_result['posts_embedded'] ?? 0 );
        if ( $added > 0 || $embedded > 0 ) {
            $message = sprintf(
                'Reindexed %d section%s across %d post%s.',
                $added,
                1 === $added ? '' : 's',
                $embedded,
                1 === $embedded ? '' : 's'
            );
        } else {
            $message = sprintf(
                'Index already up to date: %s sections from %s posts, all searchable.',
                number_format_i18n( (int) ( $stats['total_sections'] ?? 0 ) ),
                number_format_i18n( (int) ( $stats['total_posts'] ?? 0 ) )
            );
        }

        return rest_ensure_response( array(
            'success' => true,
            'forced'  => $force,
            'message' => $message,
            'index'   => $index_result,
            'embed'   => $embed_result,
            'stats'   => $stats,
        ) );
    }

    /**
     * Get section index stats.
     *
     * @return \WP_REST_Response
     */
    public function gsc_section_index_stats() {
        return rest_ensure_response( Section_Index::get_stats() );
    }

    /**
     * Backfill best_sentences column for existing sections.
     *
     * @param \WP_REST_Request $request Request object.
     * @return \WP_REST_Response
     */
    public function gsc_backfill_best_sentences( $request ) {
        $limit  = absint( $request->get_param( 'limit' ) ?: 100 );
        $result = Section_Index::backfill_best_sentences( $limit );
        return rest_ensure_response( array_merge( array( 'success' => true ), $result ) );
    }

    // -----------------------------------------------------------------------
    // Citation Tracker
    // -----------------------------------------------------------------------

    /**
     * Get citation status and results.
     *
     * @return \WP_REST_Response
     */
    public function get_citations() {
        $tracker = new CitationTracker();
        return rest_ensure_response( $tracker->get_status() );
    }

    /**
     * Run a manual citation check.
     *
     * @return \WP_REST_Response
     */
    public function run_citation_check() {
        $tracker = new CitationTracker();
        $result  = $tracker->run_check();
        $this->log_activity( 'citation_check', __( 'Citation check completed.', 'aeo-god-mode' ) );
        return rest_ensure_response( $result );
    }

    /**
     * Get citation page readiness reports.
     *
     * @return \WP_REST_Response
     */
    public function get_citation_page_reports() {
        $tracker = new CitationTracker();
        return rest_ensure_response( $tracker->get_page_reports() );
    }

    /**
     * GET /citations/queries — return the saved query list.
     */
    public function get_citation_queries() {
        $tracker = new CitationTracker();
        return rest_ensure_response( array(
            'queries' => $tracker->get_user_queries(),
        ) );
    }

    /**
     * POST /citations/queries — append one or many queries to the saved list.
     * Accepts either { query: "..." } or { queries: [..., ...] }.
     */
    public function add_citation_queries( \WP_REST_Request $request ) {
        $tracker = new CitationTracker();
        $params  = $request->get_json_params();
        $list    = array();
        if ( isset( $params['queries'] ) && is_array( $params['queries'] ) ) {
            $list = $params['queries'];
        } elseif ( isset( $params['query'] ) ) {
            $list = array( (string) $params['query'] );
        }
        if ( empty( $list ) ) {
            return new \WP_Error( 'no_input', __( 'No queries provided.', 'aeo-god-mode' ), array( 'status' => 400 ) );
        }
        $saved = $tracker->add_user_queries( $list );
        return rest_ensure_response( array(
            'queries' => $saved,
            'count'   => count( $saved ),
        ) );
    }

    /**
     * PUT /citations/queries — replace the entire saved list.
     */
    public function replace_citation_queries( \WP_REST_Request $request ) {
        $tracker = new CitationTracker();
        $params  = $request->get_json_params();
        $list    = isset( $params['queries'] ) && is_array( $params['queries'] ) ? $params['queries'] : array();
        $saved   = $tracker->set_user_queries( $list );
        return rest_ensure_response( array(
            'queries' => $saved,
            'count'   => count( $saved ),
        ) );
    }

    /**
     * DELETE /citations/queries?index=N — remove the query at the given index.
     */
    public function remove_citation_query( \WP_REST_Request $request ) {
        $tracker = new CitationTracker();
        $index   = $request->get_param( 'index' );
        if ( $index === null || ! is_numeric( $index ) ) {
            return new \WP_Error( 'bad_index', __( 'Missing or invalid index.', 'aeo-god-mode' ), array( 'status' => 400 ) );
        }
        $saved = $tracker->remove_user_query( (int) $index );
        return rest_ensure_response( array(
            'queries' => $saved,
            'count'   => count( $saved ),
        ) );
    }

    /**
     * POST /citations/queries/generate — use the user's first connected engine
     * to draft query suggestions. Does NOT save the suggestions; the frontend
     * lets the user pick which to keep.
     */
    public function ai_generate_citation_queries( \WP_REST_Request $request ) {
        $tracker = new CitationTracker();
        $count   = (int) $request->get_param( 'count' );
        if ( $count < 3 ) {
            $count = 8;
        }
        $result = $tracker->ai_generate_queries( $count );
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        return rest_ensure_response( $result );
    }

    /**
     * DELETE /citations/results/by-query, body: { query: "..." }
     * Removes every stored result row matching the given query.
     */
    public function delete_citation_results_by_query( \WP_REST_Request $request ) {
        $params = $request->get_json_params();
        $query  = isset( $params['query'] ) ? trim( (string) $params['query'] ) : '';
        if ( $query === '' ) {
            return new \WP_Error( 'no_query', __( 'No query supplied.', 'aeo-god-mode' ), array( 'status' => 400 ) );
        }
        $tracker = new CitationTracker();
        return rest_ensure_response( $tracker->delete_results_by_query( $query ) );
    }

    /**
     * DELETE /citations/results/all
     * Wipes asgm_citation_results and asgm_citation_history. Saved queries are preserved.
     */
    public function clear_all_citation_results( \WP_REST_Request $request ) {
        $tracker = new CitationTracker();
        return rest_ensure_response( $tracker->clear_all_results() );
    }

    /**
     * Save an API key for a citation tracker engine.
     *
     * @param \WP_REST_Request $request Request.
     * @return \WP_REST_Response
     */
    public function save_citation_api_key( $request ) {
        $engine  = sanitize_text_field( $request->get_param( 'engine' ) );
        $api_key = sanitize_text_field( $request->get_param( 'api_key' ) );
        $tracker = new CitationTracker();
        return rest_ensure_response( $tracker->save_api_key( $engine, $api_key ) );
    }

    /**
     * Choose whether ChatGPT citation checks run on the customer's own key
     * or on the plan's account. Saving a preference never deletes a stored
     * key, so switching back and forth costs nothing.
     *
     * @param \WP_REST_Request $request Request.
     * @return \WP_REST_Response
     */
    public function save_citation_engine_source( $request ) {
        $source = 'plan' === $request->get_param( 'source' ) ? 'plan' : 'key';
        // Defaults to openai so an older admin bundle keeps working.
        $engine = sanitize_key( (string) ( $request->get_param( 'engine' ) ?: 'openai' ) );
        if ( ! in_array( $engine, array( 'openai', 'gemini' ), true ) ) {
            return new \WP_REST_Response( array( 'success' => false, 'error' => 'That engine cannot run on plan credits.' ), 400 );
        }
        $option = 'openai' === $engine ? 'asgm_citation_openai_source' : 'asgm_citation_' . $engine . '_source';
        update_option( $option, $source, false );
        return rest_ensure_response( array( 'success' => true, 'engine' => $engine, 'source' => $source ) );
    }

    // -----------------------------------------------------------------------
    // AI Mentions & Citation Tracking (Growth, no keys — DataForSEO)
    // -----------------------------------------------------------------------

    /**
     * Shared Growth gate for the no-keys AI mention/citation features. The Pro
     * class must exist, the licence must be Pro (superset check), AND the
     * licence must be Growth and carry the given capability. Mirrors the
     * server-side gate in market_topical_map(): a non-Growth licence is
     * rejected here regardless of what the client sends, on top of the same
     * check inside the Pro class and the plan check in the proxy.
     *
     * @param string $feature Growth feature key.
     * @return true|\WP_REST_Response
     */
    private function ai_mentions_guard( $feature ) {
        if ( ! class_exists( '\AISEOGodMode\AI_Mentions' ) || ! \AISEOGodMode\License::is_pro() ) {
            return new \WP_REST_Response( array( 'success' => false, 'error' => 'AI mention tracking is a Growth feature.' ), 403 );
        }
        if ( ! method_exists( '\AISEOGodMode\License', 'is_growth' )
            || ! \AISEOGodMode\License::is_growth_feature( $feature )
            || ! \AISEOGodMode\License::is_growth() ) {
            return new \WP_REST_Response( array( 'success' => false, 'error' => 'This is a Growth feature.' ), 403 );
        }
        return true;
    }

    /** Normalise AI_Mentions results into REST responses (forwards cap fields). */
    private function ai_mentions_respond( $result ) {
        if ( is_wp_error( $result ) ) {
            $data    = $result->get_error_data();
            $payload = array( 'success' => false, 'error' => $result->get_error_message() );
            if ( is_array( $data ) ) {
                foreach ( array( 'credits', 'cap', 'cap_used' ) as $k ) {
                    if ( isset( $data[ $k ] ) ) {
                        $payload[ $k ] = $data[ $k ];
                    }
                }
            }
            return new \WP_REST_Response( $payload, 400 );
        }
        return rest_ensure_response( array_merge( array( 'success' => true ), (array) $result ) );
    }

    /** GET /citations/ai-mentions — the stored Growth overview (no charge). */
    public function get_ai_mentions() {
        $guard = $this->ai_mentions_guard( 'ai_mentions' );
        if ( true !== $guard ) {
            return $guard;
        }
        return rest_ensure_response( array_merge( array( 'success' => true ), \AISEOGodMode\AI_Mentions::get_overview() ) );
    }

    /** POST /citations/ai-mentions — search the indexed mention data (3 credits). */
    public function run_ai_mentions( \WP_REST_Request $request ) {
        $guard = $this->ai_mentions_guard( 'ai_mentions' );
        if ( true !== $guard ) {
            return $guard;
        }
        $queries = $request->get_param( 'queries' );
        $queries = is_array( $queries ) ? array_map( 'sanitize_text_field', $queries ) : array();
        $loc     = $request->has_param( 'location_code' ) ? (int) $request->get_param( 'location_code' ) : 2840;
        $lang    = sanitize_text_field( (string) ( $request->get_param( 'language_code' ) ?? 'en' ) );
        return $this->ai_mentions_respond( \AISEOGodMode\AI_Mentions::ai_mentions( $queries, $loc, $lang ) );
    }

    /** POST /citations/competitor-spy — who is being quoted instead of you (2 credits). */
    public function run_competitor_spy( \WP_REST_Request $request ) {
        $guard = $this->ai_mentions_guard( 'competitor_spy' );
        if ( true !== $guard ) {
            return $guard;
        }
        $keywords = $request->get_param( 'keywords' );
        $keywords = is_array( $keywords ) ? array_map( 'sanitize_text_field', $keywords ) : array();
        $mode     = sanitize_key( (string) ( $request->get_param( 'mode' ) ?? 'pages' ) );
        $loc      = $request->has_param( 'location_code' ) ? (int) $request->get_param( 'location_code' ) : 2840;
        $lang     = sanitize_text_field( (string) ( $request->get_param( 'language_code' ) ?? 'en' ) );
        // Only an explicit Refresh goes to the network. Everything else is
        // served from the site's own saved topics, free.
        $force    = (bool) $request->get_param( 'force' );
        return $this->ai_mentions_respond( \AISEOGodMode\AI_Mentions::competitor_spy( $keywords, $mode, $loc, $lang, $force ) );
    }

    /** POST /citations/competitor-spy/topic — switch or forget a saved topic. Never charges. */
    public function select_competitor_topic( \WP_REST_Request $request ) {
        $guard = $this->ai_mentions_guard( 'competitor_spy' );
        if ( true !== $guard ) {
            return $guard;
        }
        $key = sanitize_text_field( (string) $request->get_param( 'key' ) );
        if ( 'delete' === sanitize_key( (string) $request->get_param( 'action' ) ) ) {
            return $this->ai_mentions_respond( \AISEOGodMode\AI_Mentions::forget_spy_topic( $key ) );
        }
        return $this->ai_mentions_respond( \AISEOGodMode\AI_Mentions::select_spy_topic( $key ) );
    }

    /**
     * POST /citations/competitor-spy/classify — AI-sort the current topic's
     * not-yet-categorised domains into rivals versus non-rivals (1 credit;
     * a repeat of the same list is served from the proxy cache free). The
     * verdicts are advisory: the named competitor list only changes when the
     * customer confirms their picks through POST /citations/competitors.
     */
    public function run_competitor_classify() {
        $guard = $this->ai_mentions_guard( 'competitor_spy' );
        if ( true !== $guard ) {
            return $guard;
        }
        if ( ! method_exists( '\AISEOGodMode\AI_Mentions', 'classify_competitors' ) ) {
            return new \WP_REST_Response( array( 'success' => false, 'error' => 'Update AEO God Mode Pro to use competitor sorting.' ), 400 );
        }
        return $this->ai_mentions_respond( \AISEOGodMode\AI_Mentions::classify_competitors() );
    }

    /**
     * POST /citations/will-ai-quote — the expensive pre-publish simulator
     * (40 credits, monthly count cap enforced in the proxy).
     */
    public function run_will_ai_quote( \WP_REST_Request $request ) {
        $guard = $this->ai_mentions_guard( 'will_ai_quote' );
        if ( true !== $guard ) {
            return $guard;
        }
        $question = sanitize_textarea_field( (string) ( $request->get_param( 'question' ) ?? '' ) );
        $loc      = $request->has_param( 'location_code' ) ? (int) $request->get_param( 'location_code' ) : 2840;
        $lang     = sanitize_text_field( (string) ( $request->get_param( 'language_code' ) ?? 'en' ) );
        return $this->ai_mentions_respond( \AISEOGodMode\AI_Mentions::will_ai_quote( $question, $loc, $lang ) );
    }

    /**
     * GET /citations/competitors. Returns the saved competitor domain list.
     * Growth only (same guard as the other AI-mention features); never trusts
     * the client for the tier.
     */
    public function get_competitors() {
        $guard = $this->ai_mentions_guard( 'competitor_spy' );
        if ( true !== $guard ) {
            return $guard;
        }
        return rest_ensure_response( array(
            'success'     => true,
            'competitors' => \AISEOGodMode\AI_Mentions::get_competitors(),
        ) );
    }

    /**
     * POST /citations/competitors. Replaces the competitor domain list. Growth
     * only. Body {competitors:[...]} is sanitized to bare domains server-side
     * and the cleaned list is returned.
     */
    public function set_competitors( \WP_REST_Request $request ) {
        $guard = $this->ai_mentions_guard( 'competitor_spy' );
        if ( true !== $guard ) {
            return $guard;
        }
        $list = $request->get_param( 'competitors' );
        if ( ! is_array( $list ) ) {
            $list = array();
        }
        // Light REST-layer scrub; AI_Mentions::set_competitors() does the
        // authoritative bare-domain sanitize, dedupe and cap.
        $list  = array_map( 'sanitize_text_field', $list );
        $clean = \AISEOGodMode\AI_Mentions::set_competitors( $list );
        return rest_ensure_response( array(
            'success'     => true,
            'competitors' => $clean,
        ) );
    }

    // -----------------------------------------------------------------------
    // Consensus (Pro)
    // -----------------------------------------------------------------------

    /** Pro gate for the consensus features (Citation Tracker is a Pro module). */
    private function consensus_guard() {
        if ( ! class_exists( '\AISEOGodMode\CitationTracker' ) || ! \AISEOGodMode\License::is_pro() ) {
            return new \WP_REST_Response( array( 'success' => false, 'error' => 'Consensus tools are part of the Pro Citation Tracker.' ), 403 );
        }
        return true;
    }

    /** GET /citations/consensus — per-query corroboration view, no charge. */
    public function get_citation_consensus() {
        $guard = $this->consensus_guard();
        if ( true !== $guard ) {
            return $guard;
        }
        $tracker = new CitationTracker();
        return rest_ensure_response( array_merge( array( 'success' => true ), $tracker->consensus_overview() ) );
    }

    /** POST /citations/consensus-kit — {query}. 2 credits via the proxy. */
    public function build_consensus_kit( \WP_REST_Request $request ) {
        $guard = $this->consensus_guard();
        if ( true !== $guard ) {
            return $guard;
        }
        $tracker = new CitationTracker();
        $result  = $tracker->consensus_kit( sanitize_text_field( (string) $request->get_param( 'query' ) ) );
        if ( is_wp_error( $result ) ) {
            return new \WP_REST_Response( array( 'success' => false, 'error' => $result->get_error_message() ), 400 );
        }
        return rest_ensure_response( array_merge( array( 'success' => true ), $result ) );
    }

    /** GET /crawler-access — which AI crawlers can reach this site. */
    public function get_crawler_access() {
        return rest_ensure_response( array_merge( array( 'success' => true ), Crawler_Access::matrix() ) );
    }

    // -----------------------------------------------------------------------
    // Link Health
    // -----------------------------------------------------------------------

    /** GET /link-health — stored scan state, no side effects. */
    public function get_link_health() {
        return rest_ensure_response( array_merge( array( 'success' => true ), Link_Health::public_state() ) );
    }

    /**
     * POST /link-health/scan — body {mode:"start"} harvests the queue,
     * {mode:"batch"} checks the next batch. The client loops batches until
     * status is done, so shared hosting never sees a long request.
     */
    public function run_link_health_scan( \WP_REST_Request $request ) {
        $mode  = sanitize_key( (string) ( $request->get_param( 'mode' ) ?? 'batch' ) );
        $state = ( 'start' === $mode ) ? Link_Health::start_scan() : Link_Health::run_batch();
        return rest_ensure_response( array_merge( array( 'success' => true ), $state ) );
    }

    /** POST /link-health/fix — {url, post_id, action: unlink|redirect|ignore, new_url?}. */
    public function fix_link_health( \WP_REST_Request $request ) {
        $result = Link_Health::apply_fix(
            esc_url_raw( (string) $request->get_param( 'url' ) ),
            (int) $request->get_param( 'post_id' ),
            sanitize_key( (string) $request->get_param( 'action' ) ),
            (string) ( $request->get_param( 'new_url' ) ?? '' )
        );
        if ( is_wp_error( $result ) ) {
            return new \WP_REST_Response( array( 'success' => false, 'error' => $result->get_error_message() ), 400 );
        }
        return rest_ensure_response( $result );
    }

    // -----------------------------------------------------------------------
    // Content Health
    // -----------------------------------------------------------------------

    /** GET /content-health, stored scan state, no side effects. */
    public function get_content_health() {
        return rest_ensure_response( array_merge( array( 'success' => true ), Content_Health::public_state() ) );
    }

    /**
     * POST /content-health/scan. Body {mode:"start"} queues published posts
     * and pages, {mode:"batch"} parses the next batch. Same client-driven loop
     * as link health, so no single request ever runs long.
     */
    public function run_content_health_scan( \WP_REST_Request $request ) {
        $mode  = sanitize_key( (string) ( $request->get_param( 'mode' ) ?? 'batch' ) );
        $state = ( 'start' === $mode ) ? Content_Health::start_scan() : Content_Health::run_batch();
        return rest_ensure_response( array_merge( array( 'success' => true ), $state ) );
    }

    /** GET /content-health/details, one paginated issue or duplicate set. */
    public function get_content_health_details( \WP_REST_Request $request ) {
        $cluster = $request->get_param( 'cluster' );
        $result  = Content_Health::group_detail(
            sanitize_key( (string) $request->get_param( 'group' ) ),
            max( 1, absint( $request->get_param( 'page' ) ) ),
            min( 50, max( 1, absint( $request->get_param( 'per_page' ) ) ) ),
            null === $cluster ? null : (int) $cluster,
            sanitize_text_field( (string) $request->get_param( 'search' ) )
        );
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        return rest_ensure_response( $result );
    }

    /** POST /content-health/fix, applies only changes explicitly reviewed in Content Health. */
    public function fix_content_health( \WP_REST_Request $request ) {
        $result = Content_Health::apply_fixes(
            sanitize_key( (string) $request->get_param( 'group' ) ),
            (array) $request->get_param( 'items' )
        );
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        return rest_ensure_response( $result );
    }

    /** POST /content-health/featured-alt/generate — inspect one unique image, 2 credits. */
    public function generate_content_health_featured_alt( \WP_REST_Request $request ) {
        $result = Content_Health::generate_featured_alt( absint( $request->get_param( 'attachment_id' ) ) );
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        return rest_ensure_response( $result );
    }

    /** POST /content-health/image-alt/generate — inspect one in-content image, 2 credits. */
    public function generate_content_health_image_alt( \WP_REST_Request $request ) {
        $result = Content_Health::generate_image_alt(
            absint( $request->get_param( 'post_id' ) ),
            esc_url_raw( (string) $request->get_param( 'src' ) )
        );
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        return rest_ensure_response( $result );
    }

    // -----------------------------------------------------------------------
    // AI Referrals
    // -----------------------------------------------------------------------

    /**
     * Get AI referral stats.
     *
     * @param \WP_REST_Request $request Request.
     * @return \WP_REST_Response
     */
    public function get_ai_referrals( $request ) {
        $days      = absint( $request->get_param( 'days' ) ) ?: 30;
        $referrals = new AIReferrals();
        return rest_ensure_response( $referrals->get_stats( $days ) );
    }

    /**
     * Record a front-end AI referral beacon hit.
     *
     * @param \WP_REST_Request $request Request.
     * @return \WP_REST_Response
     */
    public function log_ai_referral_beacon( $request ) {
        $referrer = (string) $request->get_param( 'referrer' );
        $url      = (string) $request->get_param( 'url' );
        if ( strlen( $referrer ) > 2083 || strlen( $url ) > 2083 ) {
            return rest_ensure_response( array( 'recorded' => false ) );
        }
        $referrals = new AIReferrals();
        $recorded  = $referrals->log_from_beacon( $referrer, $url );
        return rest_ensure_response( array( 'recorded' => (bool) $recorded ) );
    }

    /**
     * Get paginated AI referral entries.
     *
     * @param \WP_REST_Request $request Request.
     * @return \WP_REST_Response
     */
    public function get_ai_referral_entries( $request ) {
        $page     = absint( $request->get_param( 'page' ) ) ?: 1;
        $per_page = absint( $request->get_param( 'per_page' ) ) ?: 50;
        $referrals = new AIReferrals();
        return rest_ensure_response( $referrals->get_entries( $page, $per_page ) );
    }

    // -----------------------------------------------------------------------
    // Citability Score
    // -----------------------------------------------------------------------

    /**
     * Get citability score for a single post.
     *
     * @param \WP_REST_Request $request Request.
     * @return \WP_REST_Response
     */
    public function get_citability_score( $request ) {
        $post_id   = absint( $request->get_param( 'post_id' ) );
        $citability = new CitabilityScore();
        return rest_ensure_response( $citability->score_post( $post_id ) );
    }

    /**
     * Score all published posts.
     *
     * @return \WP_REST_Response
     */
    public function get_citability_all() {
        $citability = new CitabilityScore();
        return rest_ensure_response( $citability->score_all() );
    }

    /**
     * Get cached citability results (no re-scan).
     *
     * @return \WP_REST_Response
     */
    public function get_citability_cached() {
        $citability = new CitabilityScore();
        $cached     = $citability->get_cached();
        return rest_ensure_response( $cached ?: array( 'results' => null ) );
    }

    /**
     * Exclude a post from citability scoring.
     *
     * @param \WP_REST_Request $request Request.
     * @return \WP_REST_Response
     */
    public function citability_exclude( $request ) {
        $post_id = absint( $request->get_param( 'post_id' ) );
        if ( ! $post_id ) {
            return rest_ensure_response( array( 'success' => false, 'message' => 'Missing post_id' ) );
        }
        $citability = new CitabilityScore();
        return rest_ensure_response( $citability->exclude_post( $post_id ) );
    }

    /**
     * Re-include a previously excluded post.
     *
     * @param \WP_REST_Request $request Request.
     * @return \WP_REST_Response
     */
    public function citability_include( $request ) {
        $post_id = absint( $request->get_param( 'post_id' ) );
        if ( ! $post_id ) {
            return rest_ensure_response( array( 'success' => false, 'message' => 'Missing post_id' ) );
        }
        $citability = new CitabilityScore();
        return rest_ensure_response( $citability->include_post( $post_id ) );
    }

    // -----------------------------------------------------------------------
    // E-E-A-T Author Profiles
    // -----------------------------------------------------------------------

    /**
     * Get all authors with E-E-A-T meta.
     *
     * @return \WP_REST_Response
     */
    public function get_eeat_authors() {
        $users = get_users( array(
            'role__in' => array( 'administrator', 'editor', 'author' ),
            'orderby'  => 'display_name',
            'order'    => 'ASC',
        ) );

        $authors = array();
        foreach ( $users as $user ) {
            $authors[] = $this->format_eeat_author( $user );
        }

        $settings        = get_option( 'asgm_settings', array() );
        $author_card_on  = ! empty( $settings['author_card_enabled'] );
        $default_fields  = array( 'avatar', 'name', 'job_title', 'employer', 'bio', 'expertise', 'credentials', 'education', 'socials', 'view_all_posts' );
        $card_fields     = isset( $settings['author_card_fields'] ) ? $settings['author_card_fields'] : $default_fields;

        return rest_ensure_response( array(
            'authors'             => $authors,
            'author_card_enabled' => $author_card_on,
            'card_fields'         => $card_fields,
        ) );
    }

    /**
     * Format a single user's E-E-A-T data.
     *
     * @param \WP_User $user User object.
     * @return array
     */
    private function format_eeat_author( $user ) {
        $meta_keys = array(
            'first_name', 'last_name', 'description',
            'asgm_job_title', 'asgm_employer', 'asgm_expertise',
            'asgm_education', 'asgm_credentials', 'asgm_social_profiles',
        );

        // Individual social platform fields.
        $social_keys = array( 'linkedin', 'twitter', 'youtube', 'facebook', 'instagram', 'github' );

        $data = array(
            'id'         => $user->ID,
            'username'   => $user->user_login,
            'name'       => $user->display_name,
            'email'      => $user->user_email,
            'avatar'     => $this->get_local_avatar_url( $user->ID, 128 ),
            'post_count' => count_user_posts( $user->ID, 'post', true ),
        );

        foreach ( $meta_keys as $key ) {
            $data[ $key ] = get_the_author_meta( $key, $user->ID );
        }

        foreach ( $social_keys as $key ) {
            $data[ $key ] = get_the_author_meta( $key, $user->ID );
        }

        // Completion scoring (weighted, 8 criteria).
        $score = 0;
        $max   = 0;

        // Name fields (15 pts each = 30).
        $max += 30;
        if ( ! empty( $data['first_name'] ) ) $score += 15;
        if ( ! empty( $data['last_name'] ) )  $score += 15;

        // Bio (15 pts).
        $max += 15;
        if ( ! empty( $data['description'] ) && strlen( $data['description'] ) > 20 ) $score += 15;

        // Job title (15 pts).
        $max += 15;
        if ( ! empty( $data['asgm_job_title'] ) ) $score += 15;

        // Employer (10 pts).
        $max += 10;
        if ( ! empty( $data['asgm_employer'] ) ) $score += 10;

        // Expertise (10 pts).
        $max += 10;
        if ( ! empty( $data['asgm_expertise'] ) ) $score += 10;

        // Education (10 pts).
        $max += 10;
        if ( ! empty( $data['asgm_education'] ) ) $score += 10;

        // Credentials (10 pts).
        $max += 10;
        if ( ! empty( $data['asgm_credentials'] ) ) $score += 10;

        $data['completion_score'] = $max > 0 ? (int) round( ( $score / $max ) * 100 ) : 0;

        // Per-field status for UI indicators.
        $data['missing_fields'] = array();
        if ( empty( $data['first_name'] ) )       $data['missing_fields'][] = 'First Name';
        if ( empty( $data['last_name'] ) )        $data['missing_fields'][] = 'Last Name';
        if ( empty( $data['description'] ) || strlen( $data['description'] ) < 20 ) $data['missing_fields'][] = 'Bio';
        if ( empty( $data['asgm_job_title'] ) )   $data['missing_fields'][] = 'Job Title';
        if ( empty( $data['asgm_employer'] ) )    $data['missing_fields'][] = 'Employer';
        if ( empty( $data['asgm_expertise'] ) )   $data['missing_fields'][] = 'Expertise';
        if ( empty( $data['asgm_education'] ) )   $data['missing_fields'][] = 'Education';
        if ( empty( $data['asgm_credentials'] ) ) $data['missing_fields'][] = 'Credentials';

        return $data;
    }

    /**
     * Save E-E-A-T author profile fields.
     *
     * @param \WP_REST_Request $request Request.
     * @return \WP_REST_Response
     */
    public function save_eeat_author( $request ) {
        $user_id = absint( $request->get_param( 'user_id' ) );
        if ( ! $user_id || ! get_userdata( $user_id ) ) {
            return rest_ensure_response( array( 'success' => false, 'message' => 'Invalid user.' ) );
        }

        // Core WP fields.
        $core_fields = array( 'first_name', 'last_name', 'description' );
        foreach ( $core_fields as $field ) {
            $value = $request->get_param( $field );
            if ( null !== $value ) {
                update_user_meta( $user_id, $field, sanitize_text_field( $value ) );
            }
        }

        // Custom E-E-A-T fields.
        $eeat_fields = array(
            'asgm_job_title', 'asgm_employer', 'asgm_expertise',
            'asgm_education', 'asgm_credentials', 'asgm_social_profiles',
        );
        foreach ( $eeat_fields as $field ) {
            $value = $request->get_param( $field );
            if ( null !== $value ) {
                if ( 'asgm_social_profiles' === $field ) {
                    update_user_meta( $user_id, $field, sanitize_textarea_field( $value ) );
                } else {
                    update_user_meta( $user_id, $field, sanitize_text_field( $value ) );
                }
            }
        }

        // Individual social platform URLs.
        $social_fields = array( 'linkedin', 'twitter', 'youtube', 'facebook', 'instagram', 'github' );
        foreach ( $social_fields as $field ) {
            $value = $request->get_param( $field );
            if ( null !== $value ) {
                update_user_meta( $user_id, $field, esc_url_raw( $value ) );
            }
        }

        // Update display_name if first + last provided.
        $first = get_the_author_meta( 'first_name', $user_id );
        $last  = get_the_author_meta( 'last_name', $user_id );
        if ( ! empty( $first ) && ! empty( $last ) ) {
            wp_update_user( array( 'ID' => $user_id, 'display_name' => "$first $last" ) );
        }

        $user = get_userdata( $user_id );
        return rest_ensure_response( array(
            'success' => true,
            'author'  => $this->format_eeat_author( $user ),
        ) );
    }

    /**
     * Toggle author card display on frontend.
     *
     * @param \WP_REST_Request $request Request.
     * @return \WP_REST_Response
     */
    public function toggle_author_card( $request ) {
        $enabled  = (bool) $request->get_param( 'enabled' );
        $settings = get_option( 'asgm_settings', array() );
        $settings['author_card_enabled'] = $enabled;
        update_option( 'asgm_settings', $settings );

        return rest_ensure_response( array( 'success' => true, 'enabled' => $enabled ) );
    }

    /**
     * Save per-field display toggles for the author card.
     *
     * @param \WP_REST_Request $request Request.
     * @return \WP_REST_Response
     */
    public function save_card_fields( $request ) {
        $allowed  = array( 'avatar', 'name', 'job_title', 'employer', 'bio', 'expertise', 'credentials', 'education', 'socials', 'view_all_posts' );
        $raw      = $request->get_param( 'fields' );
        $fields   = is_array( $raw ) ? array_values( array_intersect( $raw, $allowed ) ) : $allowed;
        $settings = get_option( 'asgm_settings', array() );
        $settings['author_card_fields'] = $fields;
        update_option( 'asgm_settings', $settings );

        return rest_ensure_response( array( 'success' => true, 'card_fields' => $fields ) );
    }

    // -----------------------------------------------------------------------
    // AI Plugin Manifest
    // -----------------------------------------------------------------------

    /**
     * Get AI plugin manifest status.
     *
     * @return \WP_REST_Response
     */
    public function get_ai_plugin_status() {
        $plugin = new AIPlugin();
        return rest_ensure_response( $plugin->get_status() );
    }

    /**
     * Get aggregated AI signals health for the dashboard.
     *
     * Pulls live status from robots, llms, and headers modules.
     *
     * @return \WP_REST_Response
     */
    public function get_ai_signals_health() {
        // --- Robots / AI Crawlers ---
        $robots        = new Robots();
        $robots_config = $robots->get_config();
        $bots          = isset( $robots_config['bots'] ) ? $robots_config['bots'] : array();
        $total_bots    = count( $bots );
        $configured    = 0;
        $allowed       = 0;
        $disallowed    = 0;

        foreach ( $bots as $info ) {
            $status = isset( $info['status'] ) ? $info['status'] : 'not_set';
            if ( 'not_set' !== $status ) {
                ++$configured;
            }
            if ( 'allow' === $status ) {
                ++$allowed;
            }
            if ( 'disallow' === $status ) {
                ++$disallowed;
            }
        }

        $robots_last_saved = get_option( 'asgm_robots_rules_updated', '' );

        // --- LLMS ---
        $llms         = new LLMS();
        $llms_status  = $llms->get_status();
        $llms_content = isset( $llms_status['content'] ) ? $llms_status['content'] : '';
        $llms_updated = get_option( 'asgm_llms_txt_updated', '' );

        // Run spec compliance checks.
        $compliance = array(
            'has_h1'          => (bool) preg_match( '/^# .+/m', $llms_content ),
            'has_blockquote'  => (bool) preg_match( '/^> .+/m', $llms_content ),
            'has_links'       => (bool) preg_match( '/\[.+\]\(.+\)/', $llms_content ),
        );
        $spec_compliant = $compliance['has_h1'] && $compliance['has_blockquote'] && $compliance['has_links'];

        // --- Headers ---
        $header_states = AIHeaders::get_states();
        $active_count  = 0;
        foreach ( $header_states as $state ) {
            if ( 'on' === $state['state'] ) {
                ++$active_count;
            }
        }

        return rest_ensure_response( array(
            'crawlers'   => array(
                'total'       => $total_bots,
                'configured'  => $configured,
                'allowed'     => $allowed,
                'disallowed'  => $disallowed,
                'last_saved'  => $robots_last_saved,
            ),
            'llms'       => array(
                'exists'         => ! empty( $llms_content ),
                'spec_compliant' => $spec_compliant,
                'compliance'     => $compliance,
                'last_updated'   => $llms_updated,
                'word_count'     => str_word_count( $llms_content ),
            ),
            'headers'    => array(
                'total'    => count( $header_states ),
                'active'   => $active_count,
                'states'   => $header_states,
            ),
        ) );
    }

    /**
     * Get AI header toggle states.
     *
     * @return \WP_REST_Response
     */
    public function get_ai_headers() {
        return rest_ensure_response( AIHeaders::get_states() );
    }

    /**
     * Save AI header toggle states.
     *
     * @param \WP_REST_Request $request Request object.
     * @return \WP_REST_Response
     */
    public function save_ai_headers( $request ) {
        $data   = $request->get_json_params();
        $result = AIHeaders::save_states( $data );
        $this->log_activity( 'ai_headers_updated', __( 'AI header settings updated.', 'aeo-god-mode' ) );
        return rest_ensure_response( $result );
    }

    // -----------------------------------------------------------------------
    // Activity Log
    // -----------------------------------------------------------------------

    /**
     * Get recent activity entries.
     *
     * @return \WP_REST_Response
     */
    public function get_activity() {
        $activity = get_option( 'asgm_activity_log', array() );
        return rest_ensure_response( array_slice( $activity, 0, 20 ) );
    }

    // -----------------------------------------------------------------------
    // License
    // -----------------------------------------------------------------------

    /**
     * Get license status.
     *
     * @return \WP_REST_Response
     */
    public function get_license_status() {
        $license = new License();
        return rest_ensure_response( $license->get_status() );
    }

    /**
     * Force a fresh license check, bypassing the cached result. Without this,
     * a plan change made on the store (Pro upgraded to Growth, renewal, seat
     * bump) can take up to 24 hours to reflect on the customer's site, which
     * reads as "I paid and nothing changed". Deleting the transient makes the
     * next status read hit the licensing server live.
     */
    public function refresh_license_status() {
        delete_transient( 'agm_license_data' );
        // get_status() is a passive reader; with the cache gone it would just
        // report "inactive". is_pro() performs the live licensing-server check
        // and repopulates the cache, so the status read below is truly fresh.
        \AISEOGodMode\License::is_pro();
        $license = new License();
        $status  = $license->get_status();
        $this->log_activity( 'license_refreshed', __( 'License status refreshed from the licensing server.', 'aeo-god-mode' ) );
        return rest_ensure_response( $status );
    }

    /**
     * Activate a license key.
     *
     * @param \WP_REST_Request $request Request.
     * @return \WP_REST_Response
     */
    public function activate_license( $request ) {
        $key     = sanitize_text_field( $request->get_param( 'license_key' ) );
        $license = new License();
        $result  = $license->activate( $key );

        if ( ! empty( $result['success'] ) ) {
            $this->log_activity( 'license_activated', __( 'Pro license activated.', 'aeo-god-mode' ) );
        }

        return rest_ensure_response( $result );
    }

    /**
     * Deactivate the current license.
     *
     * @return \WP_REST_Response
     */
    public function deactivate_license() {
        $license = new License();
        $result  = $license->deactivate();
        $this->log_activity( 'license_deactivated', __( 'Pro license deactivated.', 'aeo-god-mode' ) );
        return rest_ensure_response( $result );
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * Log a plugin activity event.
     *
     * @param string $type    Event type.
     * @param string $message Description.
     */
    private function log_activity( $type, $message ) {
        $activity = get_option( 'asgm_activity_log', array() );

        array_unshift( $activity, array(
            'type'      => $type,
            'message'   => $message,
            'timestamp' => current_time( 'mysql' ),
        ) );

        // Keep last 100 entries.
        $activity = array_slice( $activity, 0, 100 );

        update_option( 'asgm_activity_log', $activity );
    }

    /**
     * Deep-merge two associative arrays.
     *
     * @param array $base    Existing values.
     * @param array $overlay New values.
     * @return array
     */
    private function deep_merge( $base, $overlay ) {
        foreach ( $overlay as $key => $value ) {
            if ( is_array( $value ) && isset( $base[ $key ] ) && is_array( $base[ $key ] ) ) {
                $base[ $key ] = $this->deep_merge( $base[ $key ], $value );
            } else {
                $base[ $key ] = $value;
            }
        }
        return $base;
    }

    // -----------------------------------------------------------------------
    // Local Avatar Upload
    // -----------------------------------------------------------------------

    /**
     * Upload author profile photo (local avatar, no Gravatar needed).
     *
     * POST /aeo-god-mode/v1/eeat/avatar
     * Form data: user_id (int), avatar (file, JPEG only)
     *
     * @param \WP_REST_Request $request Request.
     * @return \WP_REST_Response
     */
    public function upload_eeat_avatar( $request ) {
        $user_id = absint( $request->get_param( 'user_id' ) );
        if ( ! $user_id || ! get_userdata( $user_id ) ) {
            return rest_ensure_response( array( 'success' => false, 'message' => 'Invalid user.' ) );
        }

        $files = $request->get_file_params();
        if ( empty( $files['avatar'] ) ) {
            return rest_ensure_response( array( 'success' => false, 'message' => 'No avatar file uploaded.' ) );
        }

        $file = $files['avatar'];

        // Validate file type (JPEG/JPG only).
        $allowed = array( 'image/jpeg', 'image/jpg' );
        $finfo   = wp_check_filetype( $file['name'] );
        if ( empty( $finfo['type'] ) || ! in_array( $finfo['type'], $allowed, true ) ) {
            return rest_ensure_response( array(
                'success' => false,
                'message' => 'Only JPEG/JPG files are accepted.',
            ) );
        }

        // Upload to WordPress media library.
        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';

        // Temporarily set the upload to associate with user context.
        $_FILES['avatar'] = $file;
        $attachment_id = media_handle_upload( 'avatar', 0 );

        if ( is_wp_error( $attachment_id ) ) {
            return rest_ensure_response( array(
                'success' => false,
                'message' => $attachment_id->get_error_message(),
            ) );
        }

        // Delete old avatar attachment if exists.
        $old_id = get_user_meta( $user_id, 'asgm_avatar_id', true );
        if ( $old_id ) {
            wp_delete_attachment( intval( $old_id ), true );
        }

        // Save attachment ID and URL to user meta.
        $avatar_url = wp_get_attachment_image_url( $attachment_id, 'thumbnail' );
        update_user_meta( $user_id, 'asgm_avatar_id', $attachment_id );
        update_user_meta( $user_id, 'asgm_avatar_url', esc_url_raw( $avatar_url ) );

        return rest_ensure_response( array(
            'success'    => true,
            'avatar_url' => $avatar_url,
            'message'    => 'Profile photo updated.',
        ) );
    }

    /**
     * Get local avatar URL for a user, falling back to Gravatar.
     *
     * @param int $user_id User ID.
     * @param int $size    Image size.
     * @return string Avatar URL.
     */
    private function get_local_avatar_url( $user_id, $size = 128 ) {
        $local = get_user_meta( $user_id, 'asgm_avatar_url', true );
        if ( ! empty( $local ) ) {
            return $local;
        }
        return get_avatar_url( $user_id, array( 'size' => $size ) );
    }

    /**
     * Initialize WordPress avatar filter overrides.
     * Call this from the main plugin init to replace Gravatar globally.
     */
    public static function init_avatar_filters() {
        // Override avatar URL for users who have a local avatar.
        add_filter( 'get_avatar_url', function( $url, $id_or_email, $args ) {
            $user_id = 0;

            if ( is_numeric( $id_or_email ) ) {
                $user_id = absint( $id_or_email );
            } elseif ( is_string( $id_or_email ) ) {
                $user = get_user_by( 'email', $id_or_email );
                if ( $user ) {
                    $user_id = $user->ID;
                }
            } elseif ( $id_or_email instanceof \WP_User ) {
                $user_id = $id_or_email->ID;
            } elseif ( $id_or_email instanceof \WP_Post ) {
                $user_id = $id_or_email->post_author;
            } elseif ( $id_or_email instanceof \WP_Comment ) {
                if ( ! empty( $id_or_email->user_id ) ) {
                    $user_id = absint( $id_or_email->user_id );
                }
            }

            if ( $user_id ) {
                $local = get_user_meta( $user_id, 'asgm_avatar_url', true );
                if ( ! empty( $local ) ) {
                    return $local;
                }
            }

            return $url;
        }, 10, 3 );

        // Add upload field to WordPress user profile page.
        add_action( 'show_user_profile', array( __CLASS__, 'render_avatar_field' ) );
        add_action( 'edit_user_profile', array( __CLASS__, 'render_avatar_field' ) );
        add_action( 'personal_options_update', array( __CLASS__, 'save_avatar_field' ) );
        add_action( 'edit_user_profile_update', array( __CLASS__, 'save_avatar_field' ) );
    }

    /**
     * Render the custom avatar upload field on the WP user profile page.
     *
     * @param \WP_User $user User being edited.
     */
    public static function render_avatar_field( $user ) {
        $avatar_url = get_user_meta( $user->ID, 'asgm_avatar_url', true );
        ?>
        <h3><?php esc_html_e( 'Profile Photo', 'aeo-god-mode' ); ?></h3>
        <table class="form-table">
            <tr>
                <th><label for="asgm_avatar"><?php esc_html_e( 'Upload Photo', 'aeo-god-mode' ); ?></label></th>
                <td>
                    <?php if ( ! empty( $avatar_url ) ) : ?>
                        <img src="<?php echo esc_url( $avatar_url ); ?>" alt="Profile Photo"
                             style="width:96px;height:96px;border-radius:50%;object-fit:cover;display:block;margin-bottom:10px;" />
                    <?php endif; ?>
                    <input type="file" name="asgm_avatar" id="asgm_avatar" accept=".jpg,.jpeg,image/jpeg" />
                    <p class="description"><?php esc_html_e( 'Upload a JPEG photo. This replaces Gravatar for your profile.', 'aeo-god-mode' ); ?></p>
                    <?php if ( ! empty( $avatar_url ) ) : ?>
                        <label>
                            <input type="checkbox" name="asgm_remove_avatar" value="1" />
                            <?php esc_html_e( 'Remove current photo', 'aeo-god-mode' ); ?>
                        </label>
                    <?php endif; ?>
                </td>
            </tr>
        </table>
        <?php
    }

    /**
     * Save avatar from native WordPress user profile form.
     *
     * @param int $user_id User ID being saved.
     */
    public static function save_avatar_field( $user_id ) {
        if ( ! current_user_can( 'edit_user', $user_id ) ) {
            return;
        }

        // Nonce is verified by WordPress core in wp-admin/user-edit.php before
        // the personal_options_update / edit_user_profile_update actions fire.
        // Adding explicit check here to satisfy static analysis tools.
        if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'update-user_' . $user_id ) ) {
            return;
        }

        // Handle removal.
        if ( ! empty( $_POST['asgm_remove_avatar'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.
            $old_id = get_user_meta( $user_id, 'asgm_avatar_id', true );
            if ( $old_id ) {
                wp_delete_attachment( intval( $old_id ), true );
            }
            delete_user_meta( $user_id, 'asgm_avatar_id' );
            delete_user_meta( $user_id, 'asgm_avatar_url' );
            return;
        }

        // Handle upload.
        if ( ! empty( $_FILES['asgm_avatar'] ) && ! empty( $_FILES['asgm_avatar']['name'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.
            $filename = sanitize_file_name( wp_unslash( $_FILES['asgm_avatar']['name'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized via sanitize_file_name.
            $finfo    = wp_check_filetype( $filename );
            if ( empty( $finfo['type'] ) || ! in_array( $finfo['type'], array( 'image/jpeg', 'image/jpg' ), true ) ) {
                return; // Not a JPEG, skip silently.
            }

            require_once ABSPATH . 'wp-admin/includes/image.php';
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/media.php';

            $attachment_id = media_handle_upload( 'asgm_avatar', 0 );

            if ( ! is_wp_error( $attachment_id ) ) {
                // Delete old avatar.
                $old_id = get_user_meta( $user_id, 'asgm_avatar_id', true );
                if ( $old_id ) {
                    wp_delete_attachment( intval( $old_id ), true );
                }

                $avatar_url = wp_get_attachment_image_url( $attachment_id, 'thumbnail' );
                update_user_meta( $user_id, 'asgm_avatar_id', $attachment_id );
                update_user_meta( $user_id, 'asgm_avatar_url', esc_url_raw( $avatar_url ) );
            }
        }
    }

    // -----------------------------------------------------------------------
    // Metadata Generation
    // -----------------------------------------------------------------------

    /**
     * Get credit balance for the current license.
     *
     * @return \WP_REST_Response
     */
    public function get_metadata_credits() {
        $credits = MetadataGenerator::get_credits();
        $credits['styles'] = MetadataGenerator::STYLES;

        return rest_ensure_response( $credits );
    }

    /**
     * Get SEO plugin detection info.
     *
     * @return \WP_REST_Response
     */
    public function get_metadata_detection() {
        return rest_ensure_response( MetadataWriter::get_detection_info() );
    }

    /**
     * Generate metadata for one or more posts.
     *
     * @param \WP_REST_Request $request Request.
     * @return \WP_REST_Response
     */
    public function generate_metadata( $request ) {
        $post_ids  = $request->get_param( 'post_ids' );
        $style     = sanitize_text_field( $request->get_param( 'style' ) ?? 'smart_mix' );
        $task_type = sanitize_text_field( $request->get_param( 'task_type' ) ?? 'metadata' );

        if ( empty( $post_ids ) || ! is_array( $post_ids ) ) {
            return new \WP_REST_Response( array( 'success' => false, 'error' => 'post_ids is required (array).' ), 400 );
        }

        // Batch cap: max 50 items per run.
        $post_ids = array_slice( array_map( 'absint', $post_ids ), 0, 50 );

        // Validate style.
        if ( ! array_key_exists( $style, MetadataGenerator::STYLES ) ) {
            $style = 'smart_mix';
        }

        // Pre-check credits. Cost depends on the task type so the pre-check
        // matches what the server-side proxy will actually charge per call.
        // Source of truth: asgm_ai_task_credit_cost() in asgm-ai-proxy.php.
        //   metadata  = Title + Meta combined → 2 credits
        //   titles    = AEO Titles Only       → 1 credit
        //   meta_only = Meta Description only → 1 credit
        $is_titles_task    = ( 'titles' === $task_type || 'generate_aeo_titles' === $task_type );
        $is_meta_only_task = ( 'meta_only' === $task_type );
        if ( $is_titles_task || $is_meta_only_task ) {
            $cost_per_item = 1;
        } else {
            $cost_per_item = 2; // combined Title + Meta
        }
        $total_cost = count( $post_ids ) * $cost_per_item;

        $credits = MetadataGenerator::get_credits();
        if ( ! empty( $credits['success'] ) && $credits['remaining'] < $total_cost ) {
            return new \WP_REST_Response( array(
                'success'   => false,
                'error'     => 'Not enough credits. You have ' . $credits['remaining'] . ' remaining, but this batch needs ' . $total_cost . '.',
                'remaining' => $credits['remaining'],
                'needed'    => $total_cost,
            ), 429 );
        }

        $results = array();
        foreach ( $post_ids as $pid ) {
            $context = MetadataWriter::get_post_context( $pid );
            if ( empty( $context ) ) continue;

            if ( $is_titles_task ) {
                $result = MetadataGenerator::generate_titles( $pid );
                if ( ! empty( $result['success'] ) && ! empty( $result['result'] ) ) {
                    // The proxy already returns decoded JSON on current
                    // versions. Legacy proxies returned a JSON string. PHP 8
                    // throws a TypeError when json_decode receives the modern
                    // array, which previously turned a successful paid title
                    // request into a visible WordPress critical error.
                    $parsed      = is_array( $result['result'] ) ? $result['result'] : json_decode( $result['result'], true );
                    $recommended = $parsed['recommended'] ?? '';
                    if ( empty( $recommended ) && ! empty( $parsed['titles'][0]['title'] ) ) {
                        $recommended = $parsed['titles'][0]['title'];
                    }

                    $results[] = array(
                        'success'  => true,
                        'post_id'  => $pid,
                        'style'    => 'titles',
                        'result'   => array(
                            'meta_title'       => $recommended,
                            'meta_description' => '',
                        ),
                        'existing' => $context['existing_meta'],
                    );
                } else {
                    $results[] = array( 'success' => false, 'post_id' => $pid, 'error' => $result['error'] ?? 'Title generation failed' );
                }
            } elseif ( $is_meta_only_task ) {
                $result = MetadataGenerator::generate_meta_only( $pid, $style );
                if ( ! empty( $result['success'] ) && ! empty( $result['result'] ) ) {
                    $parsed = is_array( $result['result'] ) ? $result['result'] : json_decode( $result['result'], true );
                    $desc   = $parsed['meta_description'] ?? '';
                    $results[] = array(
                        'success'  => true,
                        'post_id'  => $pid,
                        'style'    => $style,
                        'result'   => array(
                            'meta_title'       => '', // Title left untouched on purpose.
                            'meta_description' => $desc,
                        ),
                        'existing' => $context['existing_meta'],
                    );
                } else {
                    $results[] = array( 'success' => false, 'post_id' => $pid, 'error' => $result['error'] ?? 'Meta description generation failed' );
                }
            } else {
                $result = MetadataGenerator::generate( $pid, $style );
                $results[] = $result;
            }
        }

        // Get updated credit balance.
        $updated_credits = MetadataGenerator::get_credits();

        return rest_ensure_response( array(
            'success' => true,
            'results' => $results,
            'credits' => $updated_credits,
        ) );
    }

    /**
     * Accept and write generated metadata to post(s).
     *
     * @param \WP_REST_Request $request Request.
     * @return \WP_REST_Response
     */
    public function accept_metadata( $request ) {
        $items = $request->get_param( 'items' );

        if ( empty( $items ) || ! is_array( $items ) ) {
            return new \WP_REST_Response( array( 'success' => false, 'error' => 'items is required (array).' ), 400 );
        }

        $written = array();
        foreach ( $items as $item ) {
            $post_id  = absint( $item['post_id'] ?? 0 );
            $title    = sanitize_text_field( $item['meta_title'] ?? '' );
            $desc     = sanitize_text_field( $item['meta_description'] ?? '' );
            $prod     = wp_kses_post( $item['product_description'] ?? '' );

            if ( ! $post_id ) {
                continue;
            }

            $result = MetadataWriter::write( $post_id, $title, $desc, $prod );
            $result['post_id'] = $post_id;
            $written[] = $result;
        }

        return rest_ensure_response( array(
            'success'    => true,
            'written'    => $written,
            'count'      => count( $written ),
            'seo_plugin' => MetadataWriter::detect_seo_plugin(),
        ) );
    }

    /* ─── Topical Map (Pro) ─── */

    /**
     * Shared guard: the Pro class must exist and the license must be active.
     *
     * @return true|\WP_REST_Response
     */
    private function topical_map_guard() {
        if ( ! class_exists( '\AISEOGodMode\Topical_Map' ) || ! \AISEOGodMode\License::is_pro() ) {
            return new \WP_REST_Response( array( 'success' => false, 'error' => 'Topical Map is a Pro feature.' ), 403 );
        }
        return true;
    }

    /** Return a supported content-recipe override, null for auto, or a request error. */
    private function topical_map_recipe( $request ) {
        if ( ! $request->has_param( 'recipe' ) ) {
            return null;
        }
        $recipe = sanitize_key( (string) $request->get_param( 'recipe' ) );
        if ( '' === $recipe ) {
            return null;
        }
        if ( ! in_array( $recipe, array( 'vs', 'alternatives', 'best_x', 'switch', 'walkthrough', 'guide' ), true ) ) {
            return new \WP_Error(
                'invalid_recipe',
                __( 'Choose a supported content type before generating.', 'aeo-god-mode' ),
                array( 'status' => 400 )
            );
        }
        return $recipe;
    }

    /** Normalise Topical_Map results into REST responses. */
    private function topical_map_respond( $result ) {
        if ( is_wp_error( $result ) ) {
            $data    = $result->get_error_data();
            $payload = array(
                'success'    => false,
                'error'      => $result->get_error_message(),
                'error_code' => $result->get_error_code(),
            );
            if ( is_array( $data ) && isset( $data['credits'] ) ) {
                $payload['credits'] = $data['credits'];
            }
            return new \WP_REST_Response( $payload, 400 );
        }
        return rest_ensure_response( array_merge( array( 'success' => true ), (array) $result ) );
    }

    public function get_topical_map() {
        $guard = $this->topical_map_guard();
        if ( true !== $guard ) {
            return $guard;
        }
        return rest_ensure_response( array_merge( array( 'success' => true ), \AISEOGodMode\Topical_Map::get_map() ) );
    }

    public function build_topical_map() {
        $guard = $this->topical_map_guard();
        if ( true !== $guard ) {
            return $guard;
        }
        return $this->topical_map_respond( \AISEOGodMode\Topical_Map::build() );
    }

    public function generate_topical_map_item( $request ) {
        $guard = $this->topical_map_guard();
        if ( true !== $guard ) {
            return $guard;
        }
        $length   = sanitize_key( $request->get_param( 'length' ) ?? 'standard' );
        $guidance = sanitize_textarea_field( (string) ( $request->get_param( 'guidance' ) ?? '' ) );
        $use_kb   = $request->has_param( 'use_kb' ) ? rest_sanitize_boolean( $request->get_param( 'use_kb' ) ) : null;
        $recipe   = $this->topical_map_recipe( $request );
        if ( is_wp_error( $recipe ) ) {
            return $this->topical_map_respond( $recipe );
        }
        return $this->topical_map_respond(
            \AISEOGodMode\Topical_Map::generate( (int) $request['id'], $length, $guidance, $use_kb, $recipe )
        );
    }

    public function outline_topical_map_item( $request ) {
        $guard = $this->topical_map_guard();
        if ( true !== $guard ) {
            return $guard;
        }
        $guidance = sanitize_textarea_field( (string) ( $request->get_param( 'guidance' ) ?? '' ) );
        $use_kb   = $request->has_param( 'use_kb' ) ? rest_sanitize_boolean( $request->get_param( 'use_kb' ) ) : null;
        $recipe   = $this->topical_map_recipe( $request );
        if ( is_wp_error( $recipe ) ) {
            return $this->topical_map_respond( $recipe );
        }
        return $this->topical_map_respond(
            \AISEOGodMode\Topical_Map::outline( (int) $request['id'], $guidance, $use_kb, $recipe )
        );
    }

    public function titles_topical_map_item( $request ) {
        $guard = $this->topical_map_guard();
        if ( true !== $guard ) {
            return $guard;
        }
        $recipe = $this->topical_map_recipe( $request );
        if ( is_wp_error( $recipe ) ) {
            return $this->topical_map_respond( $recipe );
        }
        return $this->topical_map_respond( \AISEOGodMode\Topical_Map::titles( (int) $request['id'], $recipe ) );
    }

    public function dismiss_topical_map_item( $request ) {
        $guard = $this->topical_map_guard();
        if ( true !== $guard ) {
            return $guard;
        }
        $reason = sanitize_key( (string) ( $request->get_param( 'reason' ) ?? 'dismissed' ) );
        $ok = \AISEOGodMode\Topical_Map::dismiss( (int) $request['id'], $reason );
        return rest_ensure_response( array( 'success' => (bool) $ok ) );
    }

    /**
     * Full-market pull (Growth only). Server-side tier gate: a non-Growth
     * licence is rejected here regardless of what the client sends, on top of
     * the same check inside the Pro class and the plan check in the proxy.
     */
    public function market_topical_map( $request ) {
        $guard = $this->topical_map_guard();
        if ( true !== $guard ) {
            return $guard;
        }
        if ( ! method_exists( '\AISEOGodMode\License', 'is_growth' )
            || ! \AISEOGodMode\License::is_growth_feature( 'market_map' )
            || ! \AISEOGodMode\License::is_growth() ) {
            return new \WP_REST_Response( array( 'success' => false, 'error' => 'Full market keywords are a Growth feature.' ), 403 );
        }
        $seed = sanitize_text_field( (string) ( $request->get_param( 'seed' ) ?? '' ) );
        $loc  = $request->has_param( 'location_code' ) ? (int) $request->get_param( 'location_code' ) : 2840;
        $lang = sanitize_text_field( (string) ( $request->get_param( 'language_code' ) ?? 'en' ) );
        return $this->topical_map_respond(
            \AISEOGodMode\Topical_Map::market_keywords( $seed, $loc, $lang )
        );
    }

    /**
     * Territory Designer (Growth): one strong-model pass that designs the
     * site's whole topic taxonomy from every data source at once. 5 credits.
     */
    public function design_topical_map( $request ) {
        $guard = $this->topical_map_guard();
        if ( true !== $guard ) {
            return $guard;
        }
        if ( ! method_exists( '\AISEOGodMode\License', 'is_growth' )
            || ! \AISEOGodMode\License::is_growth_feature( 'market_map' )
            || ! \AISEOGodMode\License::is_growth() ) {
            return new \WP_REST_Response( array( 'success' => false, 'error' => 'The Territory Designer is a Growth feature.' ), 403 );
        }
        $guidance = sanitize_textarea_field( (string) ( $request->get_param( 'guidance' ) ?? '' ) );
        return $this->topical_map_respond(
            \AISEOGodMode\Topical_Map::design_taxonomy( $guidance )
        );
    }

    /**
     * Save the owner's seed keywords for the topical map. Free to save
     * (spending happens on the market pull and design that use them).
     */
    public function save_topical_seeds( $request ) {
        $guard = $this->topical_map_guard();
        if ( true !== $guard ) {
            return $guard;
        }
        $list = $request->get_param( 'keywords' );
        if ( ! is_array( $list ) ) {
            return new \WP_REST_Response( array( 'success' => false, 'error' => 'Send keywords as a list.' ), 400 );
        }
        $stored = \AISEOGodMode\Topical_Map::save_seeds( $list );
        return new \WP_REST_Response( array( 'success' => true, 'seed_keywords' => $stored ), 200 );
    }

    /**
     * Save cleaned third-party topic research for the Territory Designer.
     * It is deliberately kept separate from measured market keywords so an
     * imported AEO angle never inherits the source phrase's search volume.
     */
    public function save_topical_research( $request ) {
        $guard = $this->topical_map_guard();
        if ( true !== $guard ) {
            return $guard;
        }
        $rows = $request->get_param( 'rows' );
        if ( ! is_array( $rows ) ) {
            return new \WP_REST_Response( array( 'success' => false, 'error' => 'Send research rows as a list.' ), 400 );
        }
        $summary = \AISEOGodMode\Topical_Map::save_research(
            $rows,
            sanitize_text_field( (string) ( $request->get_param( 'source' ) ?? '' ) )
        );
        return new \WP_REST_Response( array( 'success' => true, 'research' => $summary ), 200 );
    }

    /* ─── Consensus Score (Growth) ─── */

    public function consensus_score( $request ) {
        if ( ! class_exists( '\AISEOGodMode\Consensus' ) || ! \AISEOGodMode\License::is_pro() ) {
            return new \WP_REST_Response( array( 'success' => false, 'error' => 'Consensus Score is a Growth feature.' ), 403 );
        }
        $keyword = sanitize_text_field( (string) $request->get_param( 'keyword' ) );
        $post_id = absint( $request->get_param( 'post_id' ) );

        if ( $post_id ) {
            $result = \AISEOGodMode\Consensus::score_post( $post_id, $keyword );
        } else {
            $title   = sanitize_text_field( (string) $request->get_param( 'title' ) );
            $content = wp_kses_post( (string) $request->get_param( 'content' ) );
            $result  = \AISEOGodMode\Consensus::score_content( $title, $content, $keyword );
        }

        if ( is_wp_error( $result ) ) {
            $data    = $result->get_error_data();
            $payload = array( 'success' => false, 'error' => $result->get_error_message() );
            if ( is_array( $data ) && isset( $data['credits'] ) ) {
                $payload['credits'] = $data['credits'];
            }
            return new \WP_REST_Response( $payload, 400 );
        }

        return rest_ensure_response( array(
            'success' => true,
            'score'   => $result['data'],
            'credits' => $result['credits'] ?? null,
        ) );
    }

    /* ─── Knowledge Base (Pro) ─── */

    private function kb_guard() {
        if ( ! class_exists( '\AISEOGodMode\Knowledge_Base' ) || ! \AISEOGodMode\License::is_pro() ) {
            return new \WP_REST_Response( array( 'success' => false, 'error' => 'The Knowledge Base is a Pro feature.' ), 403 );
        }
        return true;
    }

    public function get_kb() {
        $guard = $this->kb_guard();
        if ( true !== $guard ) {
            return $guard;
        }
        return rest_ensure_response( array_merge( array( 'success' => true ), \AISEOGodMode\Knowledge_Base::status() ) );
    }

    public function upload_kb( $request ) {
        $guard = $this->kb_guard();
        if ( true !== $guard ) {
            return $guard;
        }
        $name = (string) $request->get_param( 'name' );
        $b64  = (string) $request->get_param( 'content_base64' );
        $raw  = base64_decode( $b64, true );
        if ( false === $raw || '' === $raw ) {
            return new \WP_REST_Response( array( 'success' => false, 'error' => 'Upload payload was empty or corrupted.' ), 400 );
        }
        $result = \AISEOGodMode\Knowledge_Base::ingest( $name, $raw );
        if ( is_wp_error( $result ) ) {
            return new \WP_REST_Response( array( 'success' => false, 'error' => $result->get_error_message() ), 400 );
        }
        return rest_ensure_response( array_merge( array( 'success' => true ), $result ) );
    }

    public function delete_kb( $request ) {
        $guard = $this->kb_guard();
        if ( true !== $guard ) {
            return $guard;
        }
        $result = \AISEOGodMode\Knowledge_Base::delete_file( (string) $request->get_param( 'name' ) );
        return rest_ensure_response( array_merge( array( 'success' => true ), $result ) );
    }

    public function view_kb( $request ) {
        $guard = $this->kb_guard();
        if ( true !== $guard ) {
            return $guard;
        }
        $doc = \AISEOGodMode\Knowledge_Base::get_document( (string) $request->get_param( 'name' ) );
        if ( null === $doc ) {
            return new \WP_REST_Response( array( 'success' => false, 'error' => 'Document not found.' ), 404 );
        }
        return rest_ensure_response( array_merge( array( 'success' => true ), $doc ) );
    }
}
