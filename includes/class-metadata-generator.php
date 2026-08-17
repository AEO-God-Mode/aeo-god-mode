<?php
/**
 * AI Metadata Generator — generates AEO-optimized meta titles,
 * meta descriptions, and product descriptions using the Anti-AI
 * writing framework.
 *
 * Supports 4 description formats + Smart Mix (auto-select best per content).
 * Integrates with the server-side credit system on aeogodmode.io.
 *
 * @package AISEOGodMode
 */

namespace AISEOGodMode;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MetadataGenerator {

    /**
     * AI proxy endpoint on the license server.
     */
    const API_URL = 'https://aeogodmode.io/wp-json/asgm/v1/ai-assist';

    /**
     * Credit balance endpoint on the license server.
     */
    const CREDIT_URL = 'https://aeogodmode.io/wp-json/asgm/v1/credits/balance';

    /**
     * Credit consumption endpoint on the license server.
     *
     * As of plugin v1.6.8 / proxy v3.1.0 the proxy charges credits inline
     * with the AI call, so the plugin no longer calls this endpoint directly.
     * It's kept as a constant for backward reference and for the dedup safety
     * net on the proxy side, which short-circuits this URL when it's hit by
     * older plugin builds still in the wild.
     */
    const CREDIT_USE_URL = 'https://aeogodmode.io/wp-json/asgm/v1/credits/use';

    /**
     * Generate a per-call request_id (RFC4122-like, 36 chars) used by the
     * proxy for idempotency. Replays of the same id return the cached row
     * without charging a second credit.
     */
    private static function new_request_id() {
        try {
            $b = random_bytes( 16 );
            $b[6] = chr( ( ord( $b[6] ) & 0x0f ) | 0x40 );
            $b[8] = chr( ( ord( $b[8] ) & 0x3f ) | 0x80 );
            return vsprintf( '%s%s-%s-%s-%s-%s%s%s', str_split( bin2hex( $b ), 4 ) );
        } catch ( \Exception $e ) {
            return 'aegm-' . substr( md5( uniqid( '', true ) . wp_rand() ), 0, 28 );
        }
    }

    /**
     * Available meta description styles.
     */
    const STYLES = array(
        'direct_answer'   => 'Direct Answer',
        'high_cta'        => 'High-CTA Human Question',
        'bold_statement'  => 'Bold Statement + Proof',
        'problem_agitate' => 'Problem-Agitate',
        'factual_summary' => 'Plain Factual Summary',
        'benefit_led'     => 'Benefit-Led',
        'search_snippet'  => 'Search Snippet',
        'smart_mix'       => 'Smart Mix (auto-selects the best format per content)',
    );

    /**
     * Get the current credit balance from the license server.
     *
     * @return array Credit balance data or error.
     */
    public static function get_credits() {
        $key = License::get_key();

        if ( empty( $key ) || ! License::is_pro_build() ) {
            // Free tier: check local usage.
            return self::get_free_tier_credits();
        }

        $response = wp_remote_post( self::CREDIT_URL, array(
            'body'    => wp_json_encode( array( 'license_key' => $key ) ),
            'headers' => array( 'Content-Type' => 'application/json' ),
            'timeout' => 10,
        ) );

        if ( is_wp_error( $response ) ) {
            return array(
                'success'   => false,
                'error'     => 'Could not reach the license server.',
                'remaining' => 0,
            );
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        return is_array( $body ) ? $body : array( 'success' => false, 'error' => 'Invalid response.' );
    }

    /**
     * Free tier monthly credit allowance. Centralised constant so a future
     * bump only needs to change one place and we don't end up with the
     * "5 credits/month" wording fixed in three files while the code charges 10.
     *
     * Bumped from 5 → 10 in v1.6.8 alongside the per-task credit cost map
     * (Title + Meta combined now costs 2 credits per call; 10/month still
     * covers 5 combined generations or 10 titles-only / meta-only generations).
     */
    const FREE_TIER_MONTHLY = 10;

    /**
     * Track free tier credits locally (10/month, no rollover).
     *
     * @return array
     */
    private static function get_free_tier_credits() {
        $usage = get_option( 'asgm_free_metadata_usage', array() );
        $month = gmdate( 'Y-m' );

        if ( ! isset( $usage['month'] ) || $usage['month'] !== $month ) {
            $usage = array( 'month' => $month, 'used' => 0 );
            update_option( 'asgm_free_metadata_usage', $usage );
        }

        $limit = self::FREE_TIER_MONTHLY;
        return array(
            'success'       => true,
            'monthly_limit' => $limit,
            'used'          => (int) $usage['used'],
            'remaining'     => max( 0, $limit - (int) $usage['used'] ),
            'bonus_credits' => 0,
            'plan'          => 'free',
            'resets_on'     => gmdate( 'Y-m-01', strtotime( '+1 month' ) ),
        );
    }

    /** Increment local free-tier usage by the server-defined task cost. */
    public static function use_free_credits( $amount = 1 ) {
        $usage = get_option( 'asgm_free_metadata_usage', array() );
        $month = gmdate( 'Y-m' );

        if ( ! isset( $usage['month'] ) || $usage['month'] !== $month ) {
            $usage = array( 'month' => $month, 'used' => 0 );
        }

        $amount        = max( 1, min( self::FREE_TIER_MONTHLY, absint( $amount ) ) );
        $usage['used'] = min( self::FREE_TIER_MONTHLY, (int) $usage['used'] + $amount );
        update_option( 'asgm_free_metadata_usage', $usage );
    }

    /**
     * Generate metadata for a post.
     *
     * @param int    $post_id Post ID.
     * @param string $style   One of: direct_answer, high_cta, bold_statement, problem_agitate, smart_mix.
     * @return array Generated metadata or error.
     */
    public static function generate( $post_id, $style = 'smart_mix' ) {
        $context = MetadataWriter::get_post_context( $post_id );
        if ( empty( $context ) ) {
            return array( 'success' => false, 'error' => 'Post not found.' );
        }

        $clean_content = trim( wp_strip_all_tags( $context['content'] ) );
        if ( empty( $clean_content ) || str_word_count( $clean_content ) < 15 ) {
            return array( 'success' => false, 'error' => 'Skipped before AI: this page has fewer than 15 words of body content, so a description would require invented details. No credit was used.' );
        }

        // Truncate content to first 500 words for speed
        $words = explode( ' ', $clean_content );
        if ( count( $words ) > 500 ) {
            $words = array_slice( $words, 0, 500 );
            $clean_content = implode( ' ', $words ) . '...';
        }

        $key = License::is_pro_build() ? License::get_key() : '';

        // Build the AI prompt.
        $prompt = self::build_prompt( $context, $style );

        // Call the AI proxy. Proxy v3.1.0+ charges credits inline and returns
        // the updated balance, so this is the only network call we make.
        $request_id = self::new_request_id();
        $payload = array(
            'license_key' => $key,
            'task'        => 'generate_aeo_metadata',
            'mode'        => 'combined', // Title + Meta in one call → 2 credits.
            'content'     => $clean_content,
            'title'       => $context['title'],
            'post_type'   => $context['post_type'],
            'style'       => $style,
            'prompt'      => base64_encode( $prompt ),
            'request_id'  => $request_id,
            'site_url'    => home_url(),
        );

        $response = wp_remote_post( self::API_URL, array(
            'body'    => wp_json_encode( $payload ),
            'headers' => array( 'Content-Type' => 'application/json' ),
            'timeout' => 45,
        ) );

        if ( is_wp_error( $response ) ) {
            return array( 'success' => false, 'error' => 'AI service unavailable: ' . $response->get_error_message() );
        }

        $status = wp_remote_retrieve_response_code( $response );
        $body   = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( 200 !== $status || empty( $body['success'] ) ) {
            // Proxy now returns a user-facing "you weren't charged" message
            // when OpenAI returned but the response was unparseable. Surface
            // that verbatim so the writer knows their balance is intact.
            return array(
                'success'        => false,
                'error'          => $body['error'] ?? ( 'AI generation failed (status ' . $status . ').' ),
                'status'         => $body['status'] ?? null,
                'credit_charged' => isset( $body['credit_charged'] ) ? (bool) $body['credit_charged'] : null,
                'credits'        => $body['credits'] ?? null,
            );
        }

        // Free tier still tracks its own local 10/month allowance (see
        // self::FREE_TIER_MONTHLY). Pro credit charging happens server-side
        // inside the proxy.
        if ( empty( $key ) ) {
            self::use_free_credits();
        }

        return array(
            'success'        => true,
            'post_id'        => $post_id,
            'style'          => $style,
            'result'         => $body['result'],
            'existing'       => $context['existing_meta'],
            'status'         => $body['status'] ?? 'success',
            'credit_charged' => isset( $body['credit_charged'] ) ? (bool) $body['credit_charged'] : null,
            'credits'        => $body['credits'] ?? null,
        );
    }

    /**
     * Generate 5 AEO-optimized titles for a post.
     *
     * @param int    $post_id Post ID.
     * @param string $style   Description style.
     * @return array Generated titles or error.
     */
    public static function generate_titles( $post_id ) {
        $context = MetadataWriter::get_post_context( $post_id );
        if ( empty( $context ) ) {
            return array( 'success' => false, 'error' => 'Post not found.' );
        }

        $clean_content = trim( wp_strip_all_tags( $context['content'] ) );
        if ( empty( $clean_content ) || str_word_count( $clean_content ) < 15 ) {
            return array( 'success' => false, 'error' => 'Skipped before AI: this page has fewer than 15 words of body content, so a description would require invented details. No credit was used.' );
        }

        // Truncate content for speed
        $words = explode( ' ', $clean_content );
        if ( count( $words ) > 600 ) {
            $words = array_slice( $words, 0, 600 );
            $clean_content = implode( ' ', $words ) . '...';
        }

    $key = License::is_pro_build() ? License::get_key() : '';

        $prompt = self::build_title_prompt( $context, $clean_content );

        $request_id = self::new_request_id();
        $payload = array(
            'license_key' => $key,
            'task'        => 'generate_aeo_metadata', // Routes custom encoded prompt to the dynamic AI agent.
            'mode'        => 'titles_only', // Title output only → 1 credit.
            'content'     => $clean_content,
            'title'       => $context['title'],
            'post_type'   => $context['post_type'],
            'prompt'      => base64_encode( $prompt ),
            'request_id'  => $request_id,
            'site_url'    => home_url(),
        );

        $response = wp_remote_post( self::API_URL, array(
            'body'    => wp_json_encode( $payload ),
            'headers' => array( 'Content-Type' => 'application/json' ),
            'timeout' => 45,
        ) );

        if ( is_wp_error( $response ) ) {
            return array( 'success' => false, 'error' => 'AI service unavailable: ' . $response->get_error_message() );
        }

        $status = wp_remote_retrieve_response_code( $response );
        $body   = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( 200 !== $status || empty( $body['success'] ) ) {
            return array(
                'success'        => false,
                'error'          => $body['error'] ?? ( 'AI generation failed (status ' . $status . ').' ),
                'status'         => $body['status'] ?? null,
                'credit_charged' => isset( $body['credit_charged'] ) ? (bool) $body['credit_charged'] : null,
                'credits'        => $body['credits'] ?? null,
            );
        }

        // Free tier still tracks its own local 10/month allowance (see
        // self::FREE_TIER_MONTHLY). Pro credit charging happens server-side
        // inside the proxy.
        if ( empty( $key ) ) {
            self::use_free_credits();
        }

        // Extract the suggested titles from the response.
        $result_data = $body['result'] ?? array();

        return array(
            'success'        => true,
            'post_id'        => $post_id,
            'result'         => $result_data,
            'status'         => $body['status'] ?? 'success',
            'credit_charged' => isset( $body['credit_charged'] ) ? (bool) $body['credit_charged'] : null,
            'credits'        => $body['credits'] ?? null,
        );
    }

    /**
     * Generate ONLY the AEO meta description for a post (no title rewrite).
     * Costs 1 credit. Useful when the customer already has a good title but
     * wants a tighter, AEO-aligned meta description.
     *
     * @param int $post_id Post ID.
     * @return array { success, post_id, result: { meta_description }, ... }
     */
    public static function generate_meta_only( $post_id, $style = 'smart_mix' ) {
        $context = MetadataWriter::get_post_context( $post_id );
        if ( empty( $context ) ) {
            return array( 'success' => false, 'error' => 'Post not found.' );
        }

        $clean_content = trim( wp_strip_all_tags( $context['content'] ) );
        if ( empty( $clean_content ) || str_word_count( $clean_content ) < 15 ) {
            return array( 'success' => false, 'error' => 'Skipped: Not enough content (minimum ~15 words required).' );
        }

        $words = explode( ' ', $clean_content );
        if ( count( $words ) > 600 ) {
            $words = array_slice( $words, 0, 600 );
            $clean_content = implode( ' ', $words ) . '...';
        }

        $key    = License::is_pro_build() ? License::get_key() : '';
        $prompt = self::build_meta_only_prompt( $context, $clean_content, $style );

        $request_id = self::new_request_id();
        $payload = array(
            'license_key' => $key,
            'task'        => 'generate_aeo_metadata',
            'mode'        => 'meta_only', // Meta description only → 1 credit.
            'content'     => $clean_content,
            'title'       => $context['title'],
            'post_type'   => $context['post_type'],
            'prompt'      => base64_encode( $prompt ),
            'request_id'  => $request_id,
            'site_url'    => home_url(),
        );

        $response = wp_remote_post( self::API_URL, array(
            'body'    => wp_json_encode( $payload ),
            'headers' => array( 'Content-Type' => 'application/json' ),
            'timeout' => 45,
        ) );

        if ( is_wp_error( $response ) ) {
            return array( 'success' => false, 'error' => 'AI service unavailable: ' . $response->get_error_message() );
        }

        $status = wp_remote_retrieve_response_code( $response );
        $body   = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( 200 !== $status || empty( $body['success'] ) ) {
            return array(
                'success'        => false,
                'error'          => $body['error'] ?? ( 'AI generation failed (status ' . $status . ').' ),
                'status'         => $body['status'] ?? null,
                'credit_charged' => isset( $body['credit_charged'] ) ? (bool) $body['credit_charged'] : null,
                'credits'        => $body['credits'] ?? null,
            );
        }

        if ( empty( $key ) ) {
            self::use_free_credits();
        }

        return array(
            'success'        => true,
            'post_id'        => $post_id,
            'result'         => $body['result'] ?? array(),
            'existing'       => $context['existing_meta'],
            'status'         => $body['status'] ?? 'success',
            'credit_charged' => isset( $body['credit_charged'] ) ? (bool) $body['credit_charged'] : null,
            'credits'        => $body['credits'] ?? null,
        );
    }

    /**
     * Build the prompt for meta-description-only generation. Same AEO rules
     * as the combined prompt minus the title and product_description sections.
     */
    private static function build_meta_only_prompt( $context, $clean_content, $style = 'smart_mix' ) {
        $is_product = in_array( $context['post_type'], array( 'product', 'download' ), true );
        $categories = self::format_list( $context['categories'] );
        $tags       = self::format_list( $context['tags'] );

        $style_instructions = self::get_style_instructions( $style, $context );
        $prompt  = 'Write an accurate, useful meta description for this specific page. It may be shown as a search snippet or used as a concise page summary. Do not claim that metadata guarantees rankings, rich results, or AI citations.' . "\n\n";

        $prompt .= "## YOUR TASK\n";
        $prompt .= 'Generate ONLY the meta_description (145-158 characters) for this ' . $context['post_type'] . ". The existing title is kept as-is — do not rewrite it.\n\n";

        $prompt .= "## WRITING STYLE\n" . $style_instructions . "\n\n";

        $prompt .= "## AEO META DESCRIPTION RULES (MANDATORY)\n";
        $prompt .= "YEAR RULE: Do not add a year merely to make the description look current. Use a year only when it already appears in the source and is essential to a genuinely date-sensitive page such as an annual report, event, tax rule, dated comparison, or current-year guide. Never add a year to receipts, account pages, checkout flows, order history, confirmations, evergreen products, or ordinary service pages.\n";
        $prompt .= "Follow every rule. Breaking any means the output fails:\n\n";
        $prompt .= "1. Lead with the direct answer or outcome — never with the brand name or a question (BLUFF method).\n";
        $prompt .= "2. Include the primary keyword naturally in the first 60 characters.\n";
        if ( $is_product ) {
            $prompt .= "3. Include critical product specs, price (if provided), or who the product is directly for.\n";
            $prompt .= "4. Do NOT use generic sales copy. Treat this like an answer to 'What is [product name]?'\n";
        } else {
            $prompt .= "3. Include a useful concrete detail only when the source supports it. Never invent a number, timeframe, result, process, or benefit.\n";
        }
        $prompt .= "5. Standalone sentence that makes complete sense out of context. No 'this article' or 'this page'.\n";
        $prompt .= "6. Tightly 145-158 characters including spaces.\n";
        $prompt .= "7. No clickbait, no ellipsis, no em dashes.\n";
        $prompt .= "8. End with a clear implication of value, not a generic CTA.\n\n";
        $prompt .= "9. Describe the page's real purpose. For utility pages such as Receipt, Order History, Confirmation, login, checkout, or account screens, state what the user can view or do without pretending the page is an article.\n";
        $prompt .= "10. Do not use unsupported promises such as 'in under 2 minutes', 'automatically', 'accurately', or 'prevents errors' unless the source explicitly proves them.\n\n";

        $prompt .= "## ANTI-AI WRITING EXCLUSIONS\n";
        $prompt .= "NEVER use: delve, comprehensive, unlock, harness, elevate, revolutionize, landscape, embark, journey, transformative, groundbreaking, discover, uncover, explore, dive, crucial, pivotal, robust, seamlessly, leverage, facilitate, intricate, nuanced, multifaceted, paramount.\n\n";

        $prompt .= "## CONTENT CONTEXT\n";
        $prompt .= 'Page title (keep as-is): ' . $context['title'] . "\n";
        $prompt .= 'Page type: ' . $context['post_type'] . "\n";
        $prompt .= 'Categories: ' . $categories . "\n";
        $prompt .= 'Tags: ' . $tags . "\n";
        if ( ! empty( $context['price'] ) ) {
            $prompt .= 'Price: ' . $context['price'] . "\n";
        }

        $prompt .= "\n## OUTPUT FORMAT\n";
        $prompt .= "Return ONLY a valid JSON object with this single key:\n";
        $prompt .= "- \"meta_description\": string (145-158 chars)\n";
        $prompt .= "\nNo explanations, no markdown fences, no other keys.\n";

        return $prompt;
    }

    /**
     * Build the AI system prompt for AEO Titles.
     *
     * @param array  $context       Post context.
     * @param string $clean_content Cleaned content.
     * @return string Complete system prompt.
     */
    private static function build_title_prompt( $context, $clean_content ) {
        $prompt = "You are an AEO/SEO title strategist. Write 5 title options for the content below, optimized for both Google rankings AND AI engine citation. Titles are the #1 entity signal AI uses to categorize a page — vague, clever, or question-only titles get deprioritized by AI retrieval systems.\n\n";

        $prompt .= "INPUTS:\n";
        $prompt .= "- Existing title: " . $context['title'] . "\n";
        $prompt .= "- Page type: " . $context['post_type'] . "\n";

        if ( ! empty( $context['categories'] ) ) {
            $prompt .= "- Categories: " . self::format_list( $context['categories'] ) . "\n";
        }
        if ( ! empty( $context['tags'] ) ) {
            $prompt .= "- Tags: " . self::format_list( $context['tags'] ) . "\n";
        }
        if ( ! empty( $context['price'] ) ) {
            $prompt .= "- Price: " . $context['price'] . "\n";
        }
        if ( ! empty( $context['attributes'] ) && is_array( $context['attributes'] ) ) {
            $prompt .= "Product Attributes:\n";
            foreach ( $context['attributes'] as $attr_name => $attr_options ) {
                $prompt .= '- ' . $attr_name . ': ' . implode( ', ', $attr_options ) . "\n";
            }
        }

        $prompt .= "- Content summary / Primary terms: Evaluate the content below to extract the primary keyword, specific results, and target audience.\n\n";
        
        $prompt .= "CONTENT:\n" . $clean_content . "\n\n";

        $prompt .= "TITLE RULES:\n";
        $prompt .= "1. Primary keyword must appear in the first 4 words where possible.\n";
        $prompt .= "2. Include at least one specific, concrete element — a number, year, timeframe, outcome, or named entity (vague titles like 'The Ultimate Guide' have low AI citation rates).\n";
        $prompt .= "3. Each title must make the page's content unambiguous — AI engines must be able to infer what answer this page provides from the title alone.\n";
        $prompt .= "4. No clickbait phrasing, rhetorical questions, or manufactured urgency — AI retrieval systems deprioritize titles that don't signal factual content.\n";
        $prompt .= "5. Keep all titles between 50–60 characters for Google display.\n";
        $prompt .= "6. Write one title using a 'definition/explainer' format ('What Is X' / 'How X Works') — these are heavily extracted by AI for zero-click answers.\n";
        $prompt .= "7. Write one title using a 'list/comparison' format — listicle and comparison titles account for 50% of top AI citations.\n";
        $prompt .= "8. Write one title that includes a specific number or year (the current year is " . gmdate('Y') . ").\n";
        $prompt .= "9. Write one title optimized for a voice/conversational query (natural question phrasing).\n";
        $prompt .= "10. Write one title that leads with the outcome or result (benefit-first).\n\n";

        $prompt .= "The reasoning behind the key rules: The 'keyword in first 4 words' rule combines classic SEO title weighting with AEO entity detection — AI engines parse titles left to right and front-load the topic classification. The 'no vague titles' rule comes directly from E-E-A-T correlation data and factual density findings. NEVER use em dashes.\n\n";

        $prompt .= "OUTPUT FORMAT:\n";
        $prompt .= "Return ONLY a valid JSON object. No explanation, no markdown fences.\n";
        $prompt .= "The JSON object MUST have this exact structure:\n";
        $prompt .= "{\n";
        $prompt .= "  \"titles\": [\n";
        $prompt .= "    {\n";
        $prompt .= "      \"title\": \"[text]\",\n";
        $prompt .= "      \"characters\": [count],\n";
        $prompt .= "      \"format\": \"[definition / list / number / voice / outcome]\",\n";
        $prompt .= "      \"strength\": \"[one sentence — why will AI engines trust this title as a signal]\",\n";
        $prompt .= "      \"seoNote\": \"[one sentence — search intent match or ranking consideration]\"\n";
        $prompt .= "    }\n";
        $prompt .= "  ],\n";
        $prompt .= "  \"recommended\": \"[exact text of the best title option from the list]\"\n";
        $prompt .= "}\n";

        return $prompt;
    }

    /**
     * Build the AI system prompt using the Anti-AI framework.
     *
     * @param array  $context Post context data.
     * @param string $style   Description style.
     * @return string Complete system prompt.
     */
    private static function build_prompt( $context, $style ) {
        $is_product = in_array( $context['post_type'], array( 'product', 'download' ), true );
        $style_instructions = self::get_style_instructions( $style, $context );
        $categories = self::format_list( $context['categories'] );
        $tags       = self::format_list( $context['tags'] );

        $prompt = 'You are an AEO (Answer Engine Optimization) copywriter. Write metadata that works for two audiences simultaneously: standard search display limits AND AI engines that extract it as a standalone answer chunk.' . "\n\n";

        $prompt .= "## YOUR TASK\n";
        $prompt .= 'Generate the following for this ' . $context['post_type'] . ":\n";
        $prompt .= "1. **meta_title** (max 60 characters): clear, specific, front-loaded with the primary keyword\n";
        $prompt .= "2. **meta_description** (145-158 characters): strictly following the AEO rules below\n";

        if ( $is_product ) {
            $prompt .= "3. **product_description** (50-80 words): answer-first short description for the product listing\n";
        }

        $prompt .= "\n## STYLE (If applicable): " . $style_instructions . "\n\n";

        $prompt .= "## AEO META DESCRIPTION RULES (MANDATORY)\n";
        $prompt .= "YEAR RULE: Do not add a year merely to make metadata look current. Use a year only when it already appears in the source and is essential to a genuinely date-sensitive page such as an annual report, event, tax rule, dated comparison, or current-year guide. Never add a year to receipts, account pages, checkout flows, order history, confirmations, evergreen products, or ordinary service pages.\n";
        $prompt .= "Follow every single rule. Breaking any rule means the output fails:\n\n";
        $prompt .= "1. Lead with the direct answer or outcome — never with the brand name or a question. (BLUFF method)\n";
        $prompt .= "2. Include the primary keyword naturally in the first 60 characters.\n";

        if ( $is_product ) {
            $prompt .= "3. Include critical product specs, price (if provided), or who the product is directly for.\n";
            $prompt .= "4. Do NOT use generic sales copy. Treat this like an answer to 'What is [product name]?'\n";
        } else {
            $prompt .= "3. Include a useful concrete detail only when the source supports it. Never invent a number, timeframe, result, process, or benefit.\n";
        }

        $prompt .= "5. Write it as a standalone sentence that makes complete sense out of context, with no references to \"this article\" or \"this page\".\n";
        $prompt .= "6. Keep the meta description tightly between 145–158 characters including spaces.\n";
        $prompt .= "7. No clickbait, no ellipsis, no em dashes — factual and direct.\n";
        $prompt .= "8. End with a clear implication of value, not a generic call-to-action.\n\n";
        $prompt .= "9. Describe the page's real purpose. Utility pages such as Receipt, Order History, Confirmation, login, checkout, or account screens are not articles; state what the visitor can view or do.\n";
        $prompt .= "10. Do not use unsupported promises such as 'in under 2 minutes', 'automatically', 'accurately', or 'prevents errors' unless the source explicitly proves them.\n\n";

        $prompt .= "## ANTI-AI WRITING EXCLUSIONS\n";
        $prompt .= "NEVER use these words: delve, comprehensive, unlock, harness, elevate, revolutionize, landscape, embark, journey, transformative, groundbreaking, discover, uncover, explore, dive, crucial, pivotal, robust, seamlessly, leverage, facilitate, intricate, nuanced, multifaceted, paramount.\n\n";

        $prompt .= "## CONTENT CONTEXT\n";
        $prompt .= 'Page title: ' . $context['title'] . "\n";
        $prompt .= 'Page type: ' . $context['post_type'] . "\n";
        $prompt .= 'Categories: ' . $categories . "\n";
        $prompt .= 'Tags: ' . $tags . "\n";

        if ( ! empty( $context['price'] ) ) {
            $prompt .= 'Price: ' . $context['price'] . "\n";
        }
        
        if ( ! empty( $context['attributes'] ) && is_array( $context['attributes'] ) ) {
            $prompt .= "Product Attributes:\n";
            foreach ( $context['attributes'] as $attr_name => $attr_options ) {
                $prompt .= '- ' . $attr_name . ': ' . implode( ', ', $attr_options ) . "\n";
            }
        }

        $prompt .= "\n## OUTPUT FORMAT\n";
        $prompt .= "Return a valid JSON object with these keys:\n";
        $prompt .= "- \"meta_title\": string (max 60 chars)\n";
        $prompt .= "- \"meta_description\": string (max 155 chars)\n";

        if ( $is_product ) {
            $prompt .= "- \"product_description\": string (50-80 words)\n";
        }

        $prompt .= "\nReturn ONLY the JSON object. No explanations, no markdown fences.\n";

        return $prompt;
    }

    /**
     * Get style-specific generation instructions.
     *
     * @param string $style   Style key.
     * @param array  $context Post context.
     * @return string Instructions for the AI.
     */
    private static function get_style_instructions( $style, $context ) {
        $styles = array(
            'direct_answer' => "DIRECT ANSWER FORMAT\n" .
                "Write the meta description as if you're answering the user's search query directly. " .
                "Start with the answer, then add supporting context. " .
                "Example: 'AEO God Mode auto-detects 18 AI crawlers and logs every visit. Free for WordPress sites.' " .
                "The goal: AI answer engines should be able to cite this description word-for-word as an answer.",

            'high_cta' => "HIGH-CTA HUMAN QUESTION FORMAT\n" .
                "Start with a real question a human would ask (not generic). " .
                "Follow with a sharp answer and a clear action step. " .
                "Example: 'How do you track which AI bots crawl your site? AEO God Mode logs GPTBot, ClaudeBot, and 16 others automatically. Install it free.' " .
                "Keep it conversational. No corporate tone.",

            'bold_statement' => "BOLD STATEMENT + PROOF FORMAT\n" .
                "Open with a confident claim. Back it up with a specific fact, number, or feature. " .
                "Example: 'Most WordPress sites are invisible to AI search. AEO God Mode fixes that with 18-bot detection, auto schema, and llms.txt in one plugin.' " .
                "No hedging. No 'might' or 'could'. State facts.",

            'problem_agitate' => "PROBLEM-AGITATE FORMAT\n" .
                "Name a specific pain point. Make it feel real. Then present the solution. " .
                "Example: 'AI answer engines skip your content because it lacks structured signals. AEO God Mode adds schema, llms.txt, and crawler detection so you stop being invisible.' " .
                "Keep the agitation to one clause. Don't overdramatize.",

            'factual_summary' => "PLAIN FACTUAL SUMMARY\n" .
                "State what this specific page contains or lets the visitor do. Use only facts present in the source. No sales language, urgency, year, or invented benefit. Best for utility, account, legal, receipt, checkout, and system pages.",

            'benefit_led' => "BENEFIT-LED SUMMARY\n" .
                "Lead with the real outcome for the intended visitor, then name the feature, method, or evidence that delivers it. Use only benefits directly supported by the page. Do not add a year or generic CTA.",

            'search_snippet' => "SEARCH-SNIPPET SUMMARY\n" .
                "Write a natural, specific summary matching likely search intent. Include the main topic and one supported detail. Do not force a direct-answer pattern when the page is transactional or navigational.",
        );

        if ( 'smart_mix' === $style ) {
            // Auto-select based on content signals.
            $is_product = in_array( $context['post_type'], array( 'product', 'download' ), true );
            $has_question = (bool) preg_match( '/\?/', $context['title'] );

            if ( $is_product ) {
                return $styles['bold_statement'];
            } elseif ( $has_question ) {
                return $styles['direct_answer'];
            } else {
                // Rotate based on post ID for variety.
                $rotation = array( 'direct_answer', 'factual_summary', 'benefit_led', 'search_snippet' );
                $index = $context['post_id'] % 4;
                return $styles[ $rotation[ $index ] ];
            }
        }

        return $styles[ $style ] ?? $styles['direct_answer'];
    }

    /**
     * Format an array as a comma-separated list for the prompt.
     *
     * @param array $items Items.
     * @return string
     */
    private static function format_list( $items ) {
        if ( empty( $items ) || ! is_array( $items ) ) {
            return 'none';
        }
        return implode( ', ', array_map( 'strval', $items ) );
    }

    /**
     * Pro-only: rewrite a buried opener so sentence 1 directly answers the
     * heading. The proxy holds the OpenAI key — we just send the structured
     * context (heading + original paragraph + classification) and consume one
     * credit on success.
     *
     * @param int    $post_id              Post being rewritten (for context).
     * @param string $heading              The question-shaped heading text.
     * @param string $original_paragraph   First ~200 words under the heading.
     * @param string $buried_opener        The current first sentence.
     * @param string $classification       setup | hedge | filler | indirect | no_answer.
     * @return array{success:bool,rewrite?:string,answer_sentence?:string,error?:string}
     */
    public static function rewrite_opener( $post_id, $heading, $original_paragraph, $buried_opener, $classification, $extra_context = '', $use_kb = null ) {
        $context = MetadataWriter::get_post_context( $post_id );
        if ( empty( $context ) ) {
            return array( 'success' => false, 'error' => 'Post not found.' );
        }

        $heading            = trim( wp_strip_all_tags( $heading ) );
        $original_paragraph = trim( wp_strip_all_tags( $original_paragraph ) );
        $buried_opener      = trim( wp_strip_all_tags( $buried_opener ) );
        $classification     = preg_match( '/^(setup|hedge|filler|indirect|no_answer)$/', $classification ) ? $classification : 'setup';

        if ( $heading === '' || $original_paragraph === '' ) {
            return array( 'success' => false, 'error' => 'Missing heading or paragraph context.' );
        }

        $key = License::is_pro_build() ? License::get_key() : '';
        if ( empty( $key ) ) {
            return array( 'success' => false, 'error' => 'Pro license required for AI Rewrite.' );
        }

        $credits = self::get_credits();
        if ( ! empty( $credits['success'] ) && (int) $credits['remaining'] < 1 ) {
            return array( 'success' => false, 'error' => 'Out of AI credits.', 'remaining' => $credits['remaining'] );
        }

        // The proxy's `prompt_tasks` flow takes a full prompt as a base64
        // payload — that's how we ship our domain-specific instructions
        // without having to maintain them in proxy admin templates.
        $extra_context = trim( wp_strip_all_tags( $extra_context ) );
        $extra_block   = '';
        if ( $extra_context !== '' ) {
            $extra_block = "\nEXTRA_CONTEXT (writer-supplied — must not invent facts beyond ORIGINAL_PARAGRAPH; use to clarify angle, audience, or constraints): " . substr( $extra_context, 0, 500 );
        }

        // Sentence one is the product. Previous wording over-emphasised
        // novelty versus the body, which encouraged process-led openers such as
        // "We reviewed the tools..." even when the heading asked which tool to
        // choose. Directness wins: repeat a necessary grounded fact before
        // inventing a fresh but indirect framing.
        $prompt = "Rewrite the opening paragraph under a question-shaped heading. Sentence 1 must give the actual answer immediately.\n\n"
            . "PRIORITY:\n"
            . "Directness is more important than novelty. Use the BODY_CONTEXT as evidence. It is fine to compress or reuse a necessary fact from it. Never replace the answer with a description of the article, the research process, the scoring method, or what the section will cover unless the HEADING itself asks about that method.\n\n"
            . "INPUTS (below):\n"
            . "- HEADING: a question-shaped H2 or H3.\n"
            . "- BURIED_OPENER: the current first sentence (do not reuse this verbatim).\n"
            . "- BODY_CONTEXT: up to 500 words from the section. It is the only factual evidence you may use.\n"
            . "- CLASSIFICATION: one of setup, hedge, filler, indirect, no_answer (tells you why the existing opener fails).\n"
            . "- EXTRA_CONTEXT (optional): writer-supplied angle, audience, or constraints.\n\n"
            . "REQUIREMENTS:\n"
            . "1. Sentence 1 must name the real subject from the HEADING in its first 6 words, then state the answer with a concrete verb. Do not replace that subject with this, it, these, they, the tools, the options, or a similar pointer. Example shapes: \"X is Y because Z.\", \"X works by doing Y.\", \"Choose X when Y.\", or \"Use X to do Y.\"\n"
            . "2. Sentence 1 must stand alone as a citation. If a search engine showed only the HEADING and your sentence 1 in a citation card, the reader must understand the answer without seeing the body. Self-contained.\n"
            . "3. WRITE AT GRADE 4 TO 5 READING LEVEL. Plain English. Short sentences (15 to 20 words each). One idea per sentence. No jargon, no acronyms unless the heading already uses them, no marketing voice. If you would not say it out loud to a non-expert, do not write it.\n"
            . "4. Use only facts present in BODY_CONTEXT or EXTRA_CONTEXT. Do NOT invent statistics, names, dates, or claims.\n"
            . "5. Answer the HEADING, not a nearby question. For a best/alternatives heading, name the recommended choices or the rule for choosing. For a why heading, state the cause. For a how heading, state the action or method. For a yes/no heading, begin Yes or No when the evidence supports it.\n"
            . "6. Keep sentence 1 to 24 words or fewer. You may add one short evidence sentence after it. Keep the whole rewrite under 45 words.\n"
            . "7. Plain prose. No markdown, no bullets, no headings, no quote marks around the answer.\n"
            . "8. Do not begin with: Before, To understand, In order to, It depends, There are, Let's, In today, Picture this, Imagine, Welcome, This means, This is, We reviewed, We researched, We compared, We scored. The last four are allowed only when the HEADING explicitly asks how the review, research, comparison, or scoring was done.\n"
            . "9. NEVER use em dashes, en dashes, or hyphenated parentheticals. Use commas, semicolons, or full stops. Em dashes are an AI tell.\n\n"
            . "BANNED words/phrases (never use): comprehensive, crucial, delve, dive into, unlock, leverage, harness, empower, seamlessly, robust, transformative, valuable insights, in today's, navigate, landscape, realm, pivotal, myriad, multifaceted, foster, encompass, facilitate, beacon, testament, journey.\n\n"
            . "FINAL SILENT CHECK: Read only HEADING + sentence 1. If sentence 1 does not answer that exact heading, rewrite it before returning. The answer_sentence value must exactly match sentence 1 of rewrite.\n\n"
            . "Return JSON only: {\"rewrite\": \"the rewritten paragraph as a single string\", \"answer_sentence\": \"the exact first sentence of rewrite\"}\n\n"
            . "---\n"
            . "HEADING: {$heading}\n"
            . "BURIED_OPENER: {$buried_opener}\n"
            . "BODY_CONTEXT: {$original_paragraph}\n"
            . "CLASSIFICATION: {$classification}"
            . $extra_block;

        // Knowledge Base (Pro): verified owner facts relevant to this heading.
        // Class only exists on Pro installs; Free ships no reference to it
        // beyond this guarded name string.
        if ( class_exists( '\AISEOGodMode\Knowledge_Base' ) ) {
            $kb_block = \AISEOGodMode\Knowledge_Base::context_for( $heading . ' ' . $buried_opener . ' ' . $original_paragraph, $use_kb );
            if ( '' !== $kb_block ) {
                $prompt .= "\n\n" . $kb_block;
            }
        }

        $request_id = self::new_request_id();
        $payload = array(
            'license_key' => $key,
            'task'        => 'rewrite_opener',
            'prompt'      => base64_encode( $prompt ),
            'content'     => '',
            'title'       => $context['title'],
            'post_type'   => $context['post_type'],
            // The licensing proxy must be able to judge whether the paid
            // result actually passes Answer Density before it charges. Keep
            // these bounded, plain-text fields separate from the prompt so a
            // malformed but valid JSON response cannot consume a credit.
            'rewrite_heading'      => mb_substr( $heading, 0, 300 ),
            'rewrite_body_context' => mb_substr( $original_paragraph, 0, 4000 ),
            'request_id'  => $request_id,
            'site_url'    => home_url(),
        );

        $response = wp_remote_post( self::API_URL, array(
            'body'    => wp_json_encode( $payload ),
            'headers' => array( 'Content-Type' => 'application/json' ),
            'timeout' => 45,
        ) );

        if ( is_wp_error( $response ) ) {
            return array( 'success' => false, 'error' => 'AI service unavailable: ' . $response->get_error_message() );
        }

        $status = wp_remote_retrieve_response_code( $response );
        $body   = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( 200 !== $status || empty( $body['success'] ) ) {
            // Proxy returns status=parse_error + a "you weren't charged"
            // message when OpenAI returned but JSON was malformed. Surface it
            // verbatim along with the unchanged credits block.
            return array(
                'success'        => false,
                'error'          => $body['error'] ?? ( 'AI rewrite failed (status ' . $status . ').' ),
                'status'         => $body['status'] ?? null,
                'credit_charged' => isset( $body['credit_charged'] ) ? (bool) $body['credit_charged'] : null,
                'credits'        => $body['credits'] ?? null,
            );
        }

        // Proxy returns the model's text in $body['result']. The prompt asks
        // for {"rewrite":"...","answer_sentence":"..."} — but the model
        // sometimes wraps the JSON in fences. Strip and parse defensively.
        $raw = is_string( $body['result'] ) ? $body['result'] : wp_json_encode( $body['result'] );
        $raw = trim( $raw );
        $raw = preg_replace( '/^```(?:json)?\s*|\s*```$/m', '', $raw );

        $parsed = json_decode( $raw, true );
        if ( ! is_array( $parsed ) || empty( $parsed['rewrite'] ) ) {
            return array( 'success' => false, 'error' => 'AI returned an unparseable response.' );
        }

        // Belt and braces: GPT-class models leak em/en dashes even when told
        // not to. Strip them server-side so the user never sees an AI tell.
        $strip_dashes = function ( $s ) {
            $s = str_replace( array( '—', '–' ), ', ', (string) $s );
            $s = preg_replace( '/\s+,/', ',', $s );
            $s = preg_replace( '/\s{2,}/', ' ', $s );
            return trim( $s );
        };
        $parsed['rewrite']         = $strip_dashes( $parsed['rewrite'] );
        $parsed['answer_sentence'] = isset( $parsed['answer_sentence'] ) ? $strip_dashes( $parsed['answer_sentence'] ) : '';

        // Credit charging moved server-side into the proxy as of v3.1.0.
        // The credits block returned from the proxy is forwarded to the
        // caller below so the dashboard widget can reflect the new total.

        $final_rewrite = trim( (string) $parsed['rewrite'] );

        // Run the same classifier the dashboard uses against the candidate
        // rewrite so the UI can show "Direct" / "Buried" / "No answer" the
        // moment generation completes. No extra round-trips, no guessing.
        // Wrap in a fake paragraph so classify_answer (which expects HTML
        // body content under a heading) sees a single rendered block.
        $verdict          = array( 'classification' => 'unknown', 'words_before_answer' => -1 );
        $overlap          = 0.0;
        $subject_position = -1;
        $subject_token    = '';
        if ( class_exists( __NAMESPACE__ . '\\Answer_Density' ) ) {
            $faux_body = '<p>' . esc_html( $final_rewrite ) . '</p>';
            $verdict   = Answer_Density::classify_answer( $faux_body, $heading );
            $overlap          = Answer_Density::trigram_overlap_containment( $final_rewrite, $original_paragraph );
            $subject_token    = Answer_Density::find_heading_subject( $heading );
            $subject_position = Answer_Density::subject_position_in( $final_rewrite, $subject_token );
        }

        // A grounded answer-first opener will often compress a fact that also
        // appears in the section below. That is desirable, not a reason to ask
        // the user to buy another generation. Keep the overlap score as a
        // diagnostic only; the directness and heading-relevance gates decide
        // whether the paid result is usable.
        $final_classification = $verdict['classification'];

        return array(
            'success'             => true,
            'post_id'             => $post_id,
            'rewrite'             => $final_rewrite,
            'answer_sentence'     => trim( (string) ( $parsed['answer_sentence'] ?? '' ) ),
            'classification'      => $final_classification,         // 'direct' | 'buried' | 'no_answer' | 'unknown'
            'words_before_answer' => $verdict['words_before_answer'],
            'overlap_score'       => $overlap,                      // 0..1 trigram containment of opener in body. Hidden in UI for now; surfaced for tuning.
            'subject_position'    => $subject_position,             // word index of the heading subject inside the rewrite. -1 if not found.
            'subject_token'       => $subject_token,                // the heading subject we looked for (for debugging only).
            'status'              => $body['status'] ?? 'success',
            'credit_charged'      => isset( $body['credit_charged'] ) ? (bool) $body['credit_charged'] : null,
            'credits'             => $body['credits'] ?? null,
        );
    }
}
