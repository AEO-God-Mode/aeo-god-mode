<?php
/**
 * AI crawler accessibility matrix.
 *
 * Answers one question a site owner actually asks: "can AI search engines
 * reach my site?" It reads the robots rules this plugin manages plus whatever
 * is really being served at /robots.txt, and reports allow/block per crawler.
 *
 * The grouping matters more than the list. Blocking a SEARCH crawler can keep
 * you out of that assistant's answers. Blocking a TRAINING crawler is a
 * legitimate content-licensing choice with no bearing on whether you get
 * cited today. Presenting both as one undifferentiated wall of bots is how
 * other tools scare people into the wrong decision.
 *
 * The catalogue therefore stores CAPABILITY FLAGS, never a single category.
 * One category per agent shipped two factual errors: Google-Extended and
 * Applebot-Extended were filed as "AI training crawlers" when neither fetches
 * anything, because "training" was the only bucket left once "search" was
 * ruled out. Flags let an agent be several things at once, and let the one
 * thing it is not be stated outright. The display grouping is derived from
 * the flags on every call, so the label and the facts cannot drift apart.
 *
 * @package AISEOGodMode
 */

namespace AISEOGodMode;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Crawler_Access {

    /**
     * Every flag an agent can carry, with the neutral value for each.
     *
     * Defaults are deliberately the least alarming reading. An agent we have
     * not researched should not accidentally claim to train models or to
     * ignore robots.txt, so unresearched means false and 'unknown', never a
     * guess dressed up as a fact.
     *
     * automated_crawl    does it fetch pages on its own schedule.
     * search_discovery   can it affect being discovered, surfaced or cited.
     * live_user_fetch    does it fetch because a person asked, right now.
     * training           may the content be used to train models.
     * usage_control_only NOT a crawler: a robots.txt token governing how
     *                    already-fetched content may be used.
     * respects_robots    yes | no | conditional | unknown.
     * robots_note        the vendor's own framing, empty unless they say
     *                    something a site owner would be wrong to assume.
     * safe_to_toggle     false for core search crawlers, where blocking
     *                    damages traditional search as well as AI answers.
     * verified           do we hold primary vendor documentation for this
     *                    exact agent.
     * deprecated         kept so an existing robots.txt rule still resolves,
     *                    not offered for new configuration.
     *
     * @return array<string,mixed>
     */
    public static function flag_defaults() {
        return array(
            'automated_crawl'    => false,
            'search_discovery'   => false,
            'live_user_fetch'    => false,
            'training'           => false,
            'usage_control_only' => false,
            'respects_robots'    => 'unknown',
            'robots_note'        => '',
            'safe_to_toggle'     => true,
            'verified'           => false,
            'deprecated'         => false,
        );
    }

    /**
     * The raw catalogue: what each agent is, expressed as flags.
     *
     * Only characteristics a vendor states in its own documentation are set
     * true here. 'robots_note' in particular is per agent and never inherited
     * from "this is a user fetch", because vendors genuinely differ. OpenAI,
     * Perplexity, Amazon and Meta each say robots.txt may not apply to their
     * user-initiated fetcher. Anthropic and Mistral do not. A blanket warning
     * would tell customers something those vendors' own docs deny.
     *
     * @return array<string,array>
     */
    private static function catalogue() {
        return array(
            // OpenAI. ChatGPT-User does no scheduled crawling of its own, so
            // automated_crawl is false even though it plainly fetches pages.
            'OAI-SearchBot'      => array( 'vendor' => 'OpenAI', 'label' => 'ChatGPT Search', 'note' => 'Indexes your pages for ChatGPT Search results and citations.',
                'flags' => array( 'automated_crawl' => true, 'search_discovery' => true, 'respects_robots' => 'yes', 'verified' => true ) ),
            'ChatGPT-User'       => array( 'vendor' => 'OpenAI', 'label' => 'ChatGPT User Fetch', 'note' => 'Fetches your page because a ChatGPT user asked for it.',
                'flags' => array( 'search_discovery' => true, 'live_user_fetch' => true, 'respects_robots' => 'conditional', 'verified' => true,
                    'robots_note' => 'OpenAI states robots.txt rules may not apply, because the request is user initiated.' ) ),
            'GPTBot'             => array( 'vendor' => 'OpenAI', 'label' => 'OpenAI Training', 'note' => 'May collect content to train OpenAI models. Separate from ChatGPT Search.',
                'flags' => array( 'automated_crawl' => true, 'training' => true, 'respects_robots' => 'yes', 'verified' => true ) ),

            // Anthropic publishes one robots.txt policy covering all its bots,
            // and says disallowing Claude-User stops retrieval for user
            // queries. That is the opposite of the OpenAI wording, so this
            // agent carries no warning.
            'Claude-SearchBot'   => array( 'vendor' => 'Anthropic', 'label' => 'Claude Search', 'note' => 'Indexes content to improve Claude search results.',
                'flags' => array( 'automated_crawl' => true, 'search_discovery' => true, 'respects_robots' => 'yes', 'verified' => true ) ),
            'Claude-User'        => array( 'vendor' => 'Anthropic', 'label' => 'Claude User Fetch', 'note' => 'Fetches your page when a Claude user asks about it. Disallowing it stops that retrieval.',
                'flags' => array( 'search_discovery' => true, 'live_user_fetch' => true, 'respects_robots' => 'yes', 'verified' => true ) ),
            'ClaudeBot'          => array( 'vendor' => 'Anthropic', 'label' => 'Anthropic Training', 'note' => 'May collect content that contributes to Anthropic model training.',
                'flags' => array( 'automated_crawl' => true, 'training' => true, 'respects_robots' => 'yes', 'verified' => true ) ),

            'PerplexityBot'      => array( 'vendor' => 'Perplexity', 'label' => 'Perplexity Search', 'note' => 'Indexes pages for Perplexity results and citations. Not training.',
                'flags' => array( 'automated_crawl' => true, 'search_discovery' => true, 'respects_robots' => 'yes', 'verified' => true ) ),
            'Perplexity-User'    => array( 'vendor' => 'Perplexity', 'label' => 'Perplexity User Fetch', 'note' => 'Fetches pages in response to a user question. Not training.',
                'flags' => array( 'search_discovery' => true, 'live_user_fetch' => true, 'respects_robots' => 'conditional', 'verified' => true,
                    'robots_note' => 'Perplexity states this generally ignores robots.txt, because the request is user initiated.' ) ),

            // Googlebot is the case the old single bucket could not express.
            // Google documents that Google-Extended has no user agent of its
            // own and that the crawling is done by the existing Google agents,
            // so Googlebot-fetched content is training eligible AND is the
            // whole of Google Search. Search wins the grouping, but the
            // training flag is now sayable instead of being lost.
            'Googlebot'          => array( 'vendor' => 'Google', 'label' => 'Google Search & AI Features', 'note' => 'Controls Google Search and its search features, including the AI ones. Google-Extended does not.',
                'flags' => array( 'automated_crawl' => true, 'search_discovery' => true, 'training' => true, 'respects_robots' => 'yes', 'safe_to_toggle' => false, 'verified' => true ) ),

            'Bingbot'            => array( 'vendor' => 'Microsoft', 'label' => 'Bing Search & Copilot', 'note' => 'Crawls content for Bing. Bing-indexed content can appear across Bing Search and Copilot experiences.',
                'flags' => array( 'automated_crawl' => true, 'search_discovery' => true, 'respects_robots' => 'yes', 'safe_to_toggle' => false, 'verified' => true ) ),

            'MistralAI-Index'    => array( 'vendor' => 'Mistral', 'label' => 'Mistral Search', 'note' => 'Indexes pages for Mistral and Vibe search. Not generative training.',
                'flags' => array( 'automated_crawl' => true, 'search_discovery' => true, 'respects_robots' => 'yes', 'verified' => true ) ),
            'MistralAI-User'     => array( 'vendor' => 'Mistral', 'label' => 'Mistral User Fetch', 'note' => 'Fetches pages in response to a Vibe user question.',
                'flags' => array( 'search_discovery' => true, 'live_user_fetch' => true, 'respects_robots' => 'yes', 'verified' => true ) ),
            'MistralAI-Training' => array( 'vendor' => 'Mistral', 'label' => 'Mistral Training', 'note' => 'Mistral generative-AI training crawler.',
                'flags' => array( 'automated_crawl' => true, 'training' => true, 'respects_robots' => 'yes', 'verified' => true ) ),

            // DuckDuckGo calls this a web crawler AND says it crawls in real
            // time for answers. Under one category we had to pick one and be
            // half wrong. Both flags are simply true.
            'DuckAssistBot'      => array( 'vendor' => 'DuckDuckGo', 'label' => 'DuckDuckGo AI Answers', 'note' => 'Crawls in real time for DuckDuckGo AI answers, which cite their sources. Not used for AI training.',
                'flags' => array( 'automated_crawl' => true, 'search_discovery' => true, 'live_user_fetch' => true, 'respects_robots' => 'yes', 'verified' => true ) ),

            'Amzn-SearchBot'     => array( 'vendor' => 'Amazon', 'label' => 'Amazon & Alexa Search', 'note' => 'Makes pages eligible for Amazon search experiences including Alexa. Amazon states it is not used for generative training.',
                'flags' => array( 'automated_crawl' => true, 'search_discovery' => true, 'respects_robots' => 'yes', 'verified' => true ) ),
            'Amzn-User'          => array( 'vendor' => 'Amazon', 'label' => 'Amazon User Fetch', 'note' => 'Fetches current web information in response to a user request.',
                'flags' => array( 'search_discovery' => true, 'live_user_fetch' => true, 'respects_robots' => 'conditional', 'verified' => true,
                    'robots_note' => 'Amazon states this may not follow all robots.txt directives, because requests can be user initiated.' ) ),
            'Amazonbot'          => array( 'vendor' => 'Amazon', 'label' => 'Amazon AI', 'note' => 'General Amazon crawler whose content may be used to train Amazon AI models.',
                'flags' => array( 'automated_crawl' => true, 'training' => true, 'respects_robots' => 'yes', 'verified' => true ) ),

            // Apple states outright that Applebot-crawled data may help train
            // its foundation models, and that Applebot-Extended is how you opt
            // out of exactly that. So the crawler carries the training flag and
            // the token carries usage_control_only. Neither statement forces
            // the other to be wrong.
            'Applebot'           => array( 'vendor' => 'Apple', 'label' => 'Apple Search, Siri & AI Answers', 'note' => 'Powers Spotlight, Siri and Safari search, and may give current web context to AI answers that link their sources.',
                'flags' => array( 'automated_crawl' => true, 'search_discovery' => true, 'training' => true, 'respects_robots' => 'yes', 'safe_to_toggle' => false, 'verified' => true ) ),

            'Meta-ExternalAgent' => array( 'vendor' => 'Meta', 'label' => 'Meta AI', 'note' => 'Crawls and indexes content for uses including foundation-model training.',
                'flags' => array( 'automated_crawl' => true, 'training' => true, 'respects_robots' => 'yes', 'verified' => true ) ),
            'Meta-ExternalFetcher' => array( 'vendor' => 'Meta', 'label' => 'Meta User Fetch', 'note' => 'Fetches a page because a user requested it.',
                'flags' => array( 'search_discovery' => true, 'live_user_fetch' => true, 'respects_robots' => 'conditional', 'verified' => true,
                    'robots_note' => 'Meta states this may bypass robots.txt, because a user asked for the page.' ) ),

            'CCBot'              => array( 'vendor' => 'Common Crawl', 'label' => 'Common Crawl', 'note' => 'Builds the open Common Crawl dataset, which many models train on.',
                'flags' => array( 'automated_crawl' => true, 'training' => true, 'respects_robots' => 'yes', 'verified' => true ) ),

            // ByteDance publishes no documentation for this agent at all, so
            // respects_robots stays 'unknown' and verified stays false. Third
            // party log studies are not a vendor statement and we do not
            // launder them into one.
            'Bytespider'         => array( 'vendor' => 'ByteDance', 'label' => 'ByteDance', 'note' => 'ByteDance AI crawler. Allowing it is not known to improve AI-search citations.',
                'flags' => array( 'automated_crawl' => true, 'training' => true ) ),

            // Neither of these fetches anything. usage_control_only is the flag
            // that makes the shipped mistake unsayable: definitions() forces
            // automated_crawl false whenever it is set, so no future edit can
            // reintroduce "AI training crawler" for a robots.txt token.
            'Google-Extended'    => array( 'vendor' => 'Google', 'label' => 'Gemini Training & Grounding Control', 'note' => 'Controls whether Google-crawled content may train future Gemini models and ground certain Gemini experiences. Does not crawl pages, and does not affect Google Search inclusion or ranking.',
                'flags' => array( 'training' => true, 'usage_control_only' => true, 'respects_robots' => 'yes', 'verified' => true ) ),
            'Applebot-Extended'  => array( 'vendor' => 'Apple', 'label' => 'Apple AI Training Control', 'note' => 'Controls whether content Applebot already crawled may train Apple foundation models. Does not crawl pages, and blocking it does not remove you from Apple search.',
                'flags' => array( 'training' => true, 'usage_control_only' => true, 'respects_robots' => 'yes', 'verified' => true ) ),
        );
    }

    /**
     * The catalogue with flags completed and display fields derived.
     *
     * 'group', 'kind', 'robots' and 'protected' are computed here rather than
     * stored, which is the entire point of the refactor: there is no second
     * copy of the truth that can disagree with the flags.
     *
     * @return array<string,array>
     */
    public static function definitions() {
        $out = array();
        foreach ( self::catalogue() as $ua => $def ) {
            $flags = array_merge( self::flag_defaults(), isset( $def['flags'] ) ? $def['flags'] : array() );

            // A token that governs use of already-fetched content cannot also
            // be fetching. Enforcing it here, not trusting the catalogue to
            // stay right, is what stops the Google-Extended error recurring.
            if ( $flags['usage_control_only'] ) {
                $flags['automated_crawl']  = false;
                $flags['live_user_fetch']  = false;
                $flags['search_discovery'] = false;
            }

            $def['flags']     = $flags;
            $def['group']     = self::group_for( $flags );
            $def['kind']      = self::kind_for( $flags );
            $def['robots']    = $flags['robots_note'];
            $def['protected'] = ! $flags['safe_to_toggle'];
            $out[ $ua ]       = $def;
        }
        return $out;
    }

    /**
     * Which display group a flag set belongs in.
     *
     * The order is a precedence, not a preference. An agent that can put you
     * in an answer is filed under the consequence the owner is deciding about,
     * even when its data also feeds training, because that is the choice that
     * costs them visibility if they get it wrong.
     *
     * @param array $flags Completed flag set.
     * @return string
     */
    public static function group_for( array $flags ) {
        if ( ! empty( $flags['usage_control_only'] ) ) {
            return 'control';
        }
        if ( ! empty( $flags['search_discovery'] ) || ! empty( $flags['live_user_fetch'] ) ) {
            return 'search';
        }
        // Anything left only collects. Falling back rather than dropping the
        // row, because a bot missing from the card reads as "we checked it and
        // it is fine" when it really means "we had nothing to say about it".
        return 'training';
    }

    /**
     * Back-compatible 'kind' for the existing UI, derived from the same flags.
     *
     * @param array $flags Completed flag set.
     * @return string
     */
    public static function kind_for( array $flags ) {
        if ( ! empty( $flags['usage_control_only'] ) ) {
            return 'token';
        }
        if ( ! empty( $flags['live_user_fetch'] ) ) {
            return 'fetch';
        }
        if ( ! empty( $flags['search_discovery'] ) ) {
            return 'index';
        }
        return 'crawler';
    }

    /** Human copy per group, stated without overclaiming. */
    public static function groups() {
        return array(
            'search' => array(
                'title' => 'AI search and answers',
                'blurb' => 'These decide whether your pages can be discovered, fetched and cited in AI answers. Blocking one can keep you out of that assistant.',
            ),
            'training' => array(
                'title' => 'AI training and data use',
                'blurb' => 'These collect content that may train models. Blocking them is a licensing choice about your work, and does not affect whether you get cited today.',
            ),
            'control' => array(
                'title' => 'Usage controls',
                'blurb' => 'Not crawlers. These robots.txt tokens control how content that was already fetched may be used.',
            ),
        );
    }

    /**
     * The count shown beside a group title.
     *
     * Usage controls need their own wording. "All 2 reachable" is a meaningless
     * claim about a token that never fetches anything, and it contradicts the
     * blurb printed directly above it, which says these are not crawlers. The
     * only thing a site owner has actually decided about one of these is
     * whether they opted out, so that is what gets counted.
     *
     * @param string $key     Derived group key.
     * @param int    $total   Agents in the group.
     * @param int    $blocked Agents carrying a disallow.
     * @return string
     */
    private static function group_summary( $key, $total, $blocked ) {
        if ( 'control' === $key ) {
            return 0 === $blocked
                ? 'no opt-out set'
                : sprintf( '%d of %d opted out', $blocked, $total );
        }
        return 0 === $blocked
            ? sprintf( 'all %d reachable', $total )
            : sprintf( '%d of %d blocked', $blocked, $total );
    }

    /**
     * Is this user-agent disallowed by the robots.txt actually being served?
     *
     * Parses the live file rather than trusting our own saved settings, because
     * another plugin, a static robots.txt on disk or a host-level rule can all
     * override what this plugin thinks it set. What is served is what crawlers
     * obey, so that is what we report.
     *
     * @param string $robots_txt Full robots.txt body.
     * @param string $ua         User-agent token.
     * @return bool|null True blocked, false allowed, null when no rule mentions it.
     */
    private static function blocked_in_robots( $robots_txt, $ua ) {
        if ( '' === trim( (string) $robots_txt ) ) {
            return null;
        }

        // Parse groups, not individual lines. A robots group may name several
        // user agents before its directives, and a wildcard group applies only
        // when no more-specific group names this agent. The earlier line-state
        // parser missed both rules, which could turn a site-wide Disallow: /
        // into a false all-clear and could ignore the first agent in a shared
        // group entirely.
        $lines      = preg_split( '/\r\n|\r|\n/', $robots_txt );
        $groups     = array();
        $agents     = array();
        $directives = array();
        $has_rules  = false;

        $flush = static function () use ( &$groups, &$agents, &$directives, &$has_rules ) {
            if ( ! empty( $agents ) ) {
                $groups[] = array(
                    'agents'     => array_values( array_unique( $agents ) ),
                    'directives' => $directives,
                );
            }
            $agents     = array();
            $directives = array();
            $has_rules  = false;
        };

        foreach ( $lines as $line ) {
            $line = trim( preg_replace( '/#.*$/', '', $line ) );
            if ( '' === $line ) {
                continue;
            }
            if ( preg_match( '/^user-agent\s*:\s*(.+)$/i', $line, $m ) ) {
                // A user-agent after directives starts a new group. Consecutive
                // user-agent lines before any directive belong to one group.
                if ( $has_rules ) {
                    $flush();
                }
                $agents[] = strtolower( trim( $m[1] ) );
                continue;
            }
            if ( empty( $agents ) ) {
                continue;
            }
            if ( preg_match( '/^disallow\s*:\s*(.*)$/i', $line, $m ) ) {
                $directives[] = array( 'type' => 'disallow', 'path' => trim( $m[1] ) );
                $has_rules    = true;
                continue;
            }
            if ( preg_match( '/^allow\s*:\s*(.*)$/i', $line, $m ) ) {
                $directives[] = array( 'type' => 'allow', 'path' => trim( $m[1] ) );
                $has_rules    = true;
            }
        }
        $flush();

        $needle = strtolower( trim( $ua ) );
        $exact  = array();
        $global = array();
        foreach ( $groups as $group ) {
            if ( in_array( $needle, $group['agents'], true ) ) {
                $exact[] = $group;
            } elseif ( in_array( '*', $group['agents'], true ) ) {
                $global[] = $group;
            }
        }

        // RFC 9309: the most-specific matching user-agent group wins; wildcard
        // is the fallback. Repeated groups for the same agent are combined.
        $matched = ! empty( $exact ) ? $exact : $global;
        if ( empty( $matched ) ) {
            return null;
        }

        $allow_root    = false;
        $disallow_root = false;
        foreach ( $matched as $group ) {
            foreach ( $group['directives'] as $directive ) {
                if ( '/' !== $directive['path'] ) {
                    continue;
                }
                if ( 'allow' === $directive['type'] ) {
                    $allow_root = true;
                } elseif ( 'disallow' === $directive['type'] ) {
                    $disallow_root = true;
                }
            }
        }

        // For equally specific root rules, Allow wins. A matched group that
        // only limits a path (the standard WordPress /wp-admin/ rule) says
        // nothing about whole-site access. Return null so the UI calls that
        // "No rule (permitted by default)", not "explicitly allowed".
        if ( ! $allow_root && ! $disallow_root ) {
            return null;
        }
        return $allow_root ? false : true;
    }

    /**
     * The robots.txt this site really serves, obtained WITHOUT a network call.
     *
     * Asking the site for its own URL fails on plenty of hosts (containers,
     * loopback restrictions, proxies) and returns an empty string, which would
     * make this card announce "no robots.txt rules found" while the site is
     * plainly serving rules. Saying that to a customer is worse than saying
     * nothing.
     *
     * A physical file on disk wins, exactly as WordPress treats it. Otherwise
     * we render the virtual file through the same filter WordPress uses, so
     * our rules, Yoast's and any other plugin's all appear.
     *
     * @return array{content:string,known:bool}
     */
    private static function robots_content() {
        $file = ABSPATH . 'robots.txt';
        if ( file_exists( $file ) && is_readable( $file ) ) {
            $body = (string) file_get_contents( $file );
            return array( 'content' => $body, 'known' => true );
        }
        if ( function_exists( 'apply_filters' ) ) {
            $default = "User-agent: *\nDisallow: /wp-admin/\nAllow: /wp-admin/admin-ajax.php\n";
            $body    = (string) apply_filters( 'robots_txt', $default, (bool) get_option( 'blog_public' ) );
            if ( '' !== trim( $body ) ) {
                return array( 'content' => $body, 'known' => true );
            }
        }
        return array( 'content' => '', 'known' => false );
    }

    /**
     * The matrix, grouped and ready to render.
     *
     * @return array
     */
    public static function matrix() {
        $robots     = new Robots();
        $config     = $robots->get_config();
        $robots     = self::robots_content();
        $robots_txt = $robots['content'];
        $saved      = get_option( 'asgm_robots_rules', array() );
        $saved      = is_array( $saved ) ? $saved : array();

        $rows = array();
        foreach ( self::definitions() as $ua => $def ) {
            $live = self::blocked_in_robots( $robots_txt, $ua );

            // Three states, matching the AI Crawlers screen exactly. Collapsing
            // "no rule" into "allowed" made this card disagree with that screen
            // about the same bots, and two screens contradicting each other is
            // worse than either one being slightly less decisive.
            if ( null !== $live ) {
                $state  = $live ? 'blocked' : 'allowed';
                $source = 'robots.txt';
            } elseif ( isset( $saved[ $ua ] ) && in_array( $saved[ $ua ], array( 'allow', 'disallow' ), true ) ) {
                // The stored vocabulary is allow / disallow / not_set, matching
                // the AI Crawlers screen. Reading it as anything else would show
                // a bot the owner explicitly disallowed as "no rule", which is a
                // false all-clear in the one direction that actually costs them.
                $state  = ( 'allow' === $saved[ $ua ] ) ? 'allowed' : 'blocked';
                $source = 'your setting';
            } else {
                $state  = 'no_rule';
                $source = 'no directive written';
            }

            $rows[ $def['group'] ][] = array(
                'ua'      => $ua,
                'label'   => $def['label'],
                'vendor'  => $def['vendor'],
                'note'    => $def['note'],
                // 'robots', 'protected' and 'kind' are the pre-flags shape and
                // are kept so the existing card keeps rendering. All three are
                // now derived, so they cannot contradict 'flags'.
                'robots'    => $def['robots'],
                'protected' => $def['protected'],
                'kind'    => $def['kind'],
                'state'   => $state,
                // Reachable is the question that matters for visibility: no
                // rule means the crawler may visit.
                'allowed' => ( 'blocked' !== $state ),
                'source'  => $source,
                'flags'   => $def['flags'],
                // Lifted out of 'flags' as well, because these three drive
                // visible affordances and the card should not have to know the
                // internal flag layout to render a padlock.
                'respects_robots' => $def['flags']['respects_robots'],
                'verified'        => $def['flags']['verified'],
                'deprecated'      => $def['flags']['deprecated'],
            );
        }

        $groups = array();
        foreach ( self::groups() as $key => $meta ) {
            $bots    = $rows[ $key ] ?? array();
            $blocked = count( array_filter( $bots, static function ( $b ) { return ! $b['allowed']; } ) );
            $groups[] = array(
                'key'     => $key,
                'title'   => $meta['title'],
                'blurb'   => $meta['blurb'],
                'bots'    => $bots,
                'total'   => count( $bots ),
                'blocked' => $blocked,
                'summary' => self::group_summary( $key, count( $bots ), $blocked ),
            );
        }


        // The headline is deliberately about SEARCH access only. A site that
        // blocks every training crawler is not "at risk"; a site that blocks
        // OAI-SearchBot is.
        $search        = $rows['search'] ?? array();
        $search_blocked = array_values( array_filter( $search, static function ( $b ) { return ! $b['allowed']; } ) );
        $search_allowed = array_values( array_filter( $search, static function ( $b ) { return 'allowed' === $b['state']; } ) );
        $search_no_rule = array_values( array_filter( $search, static function ( $b ) { return 'no_rule' === $b['state']; } ) );

        return array(
            'groups'          => $groups,
            'search_total'    => count( $search ),
            'search_blocked'  => array_map( static function ( $b ) { return $b['label']; }, $search_blocked ),
            'search_allowed'  => count( $search_allowed ),
            'search_no_rule'  => count( $search_no_rule ),
            'has_robots_txt'  => '' !== trim( $robots_txt ),
            // False only when we genuinely could not read the file. Never
            // report "no rules" for a file we failed to fetch.
            'robots_known'    => (bool) $robots['known'],
            'managed_by'      => (string) ( $config['other_plugin_managing'] ?? '' ),
        );
    }
}
