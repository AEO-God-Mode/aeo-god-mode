<?php
/**
 * Link Health: broken link detection with one-click fixes.
 *
 * Broken links waste crawl budget, kill user trust and leak authority, and AI
 * engines reading a page hit the same dead ends a person does. This scanner
 * walks published content in small batches (REST-driven so shared hosting
 * never times out), records dead links with their source posts, and offers
 * one-click fixes: unlink (keep the words, drop the dead anchor) or follow a
 * redirect to the link's current home.
 *
 * Free-tier feature: the whole point is effortless value before any upsell.
 *
 * @package AISEOGodMode
 */

namespace AISEOGodMode;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Link_Health {

    /** Scan state + results. */
    const OPT = 'asgm_link_health';

    /** URLs checked per batch call. Small enough for shared hosting. */
    const BATCH = 12;

    /** Timeout per URL check (seconds). */
    const TIMEOUT = 8;

    /**
     * Gap between consecutive OUTBOUND requests (microseconds).
     *
     * Courtesy to the sites we check, not to ourselves. A real request already
     * costs 100-500ms of its own, requests are spread across many hosts, and
     * each batch is a separate REST round-trip with its own natural pacing, so
     * 100ms caps even the pathological same-host run at ~10 req/s. Nobody
     * treats that as abuse.
     *
     * This used to be 250ms applied to EVERY queued item, including the links
     * that never touch the network. Measured by seed/probe-link-pacing.php on
     * the coffee-kingz rig, one batch of 12 links:
     *
     *   12 internal links WordPress resolves   12 ms, 0 requests left the server
     *   12 external links                    1233 ms, 12 requests
     *   the same external URL 12 times        107 ms, 1 request (memo)
     *
     * Every one of those three took about 3000 ms before, because the sleep did
     * not care whether anything had been sent. On a site whose internal links
     * outnumber its external ones, which is most sites, nearly all of that was
     * the scanner waiting on itself.
     */
    const PACE_US = 100000;

    /**
     * How many words two slugs may differ by and still be the same page.
     *
     * Bounds the weak spot of the nesting rule in suggest_internal_target():
     * a short dead slug is a subset of a much longer live one by accident, so
     * /coffee-brewing nests inside "ultimate-guide-to-coffee-brewing-methods-
     * for-beginners", which is a broader page rather than the page that moved.
     *
     * 2 is the smallest bound that keeps a real move: /cited-in-perplexity to
     * "how-to-get-cited-in-perplexity" gains two content words, so a bound of 1
     * loses it. That is also the whole of what the bound achieves. It does not
     * make the nesting rule safe, because a wrong candidate can sit exactly on
     * the bound too: /coffee-brewing still reaches "turkish-coffee-brewing-
     * guide" at a drift of 2. See the harness named in suggest_internal_target()
     * for the measurement.
     */
    const SLUG_DRIFT = 2;

    /** Outbound HTTP checks made this request. Drives pacing, see PACE_US. */
    private static $outbound = 0;

    /**
     * Only an unambiguous gone/not-found response can safely offer an edit.
     * A 401, 403, 429, 5xx or network failure says the scanner could not
     * inspect the URL; it does not prove a visitor cannot use it.
     */
    private static function is_broken_status( $code ) {
        return in_array( (int) $code, array( 404, 410 ), true );
    }

    /** A response we must show honestly but never offer to auto-fix. */
    private static function is_unverified_status( $code ) {
        return (int) $code >= 400;
    }

    /** Read the stored state, shaped. */
    public static function get_state() {
        $raw = get_option( self::OPT, array() );
        if ( ! is_array( $raw ) ) {
            $raw = array();
        }
        $stored_broken = ( isset( $raw['broken'] ) && is_array( $raw['broken'] ) ) ? array_values( $raw['broken'] ) : array();
        $broken        = array();
        $unverified    = ( isset( $raw['unverified'] ) && is_array( $raw['unverified'] ) ) ? array_values( $raw['unverified'] ) : array();
        // Older scans classified every 4xx/5xx as broken. Preserve the
        // evidence, but withdraw its destructive action until it is rechecked.
        foreach ( $stored_broken as $row ) {
            if ( self::is_broken_status( (int) ( $row['code'] ?? 0 ) ) ) {
                $broken[] = $row;
            } else {
                $unverified[] = $row;
            }
        }
        return array(
            'status'     => (string) ( $raw['status'] ?? 'never' ), // never|scanning|done
            'queue'      => ( isset( $raw['queue'] ) && is_array( $raw['queue'] ) ) ? $raw['queue'] : array(),
            'checked'    => (int) ( $raw['checked'] ?? 0 ),
            'total'      => (int) ( $raw['total'] ?? 0 ),
            'broken'     => $broken,
            'unverified' => $unverified,
            'ignored'    => ( isset( $raw['ignored'] ) && is_array( $raw['ignored'] ) ) ? $raw['ignored'] : array(),
            'scanned_at' => (string) ( $raw['scanned_at'] ?? '' ),
        );
    }

    private static function save_state( $state ) {
        update_option( self::OPT, $state, false );
    }

    /**
     * Start a scan: harvest every link from published posts/pages into the
     * queue, keep prior ignores, reset results. Ignored URLs never re-enter
     * the queue: they are not requested, counted or shown on later scans.
     * Costs nothing; the actual checking happens batch by batch.
     */
    public static function start_scan() {
        $prev    = self::get_state();
        $posts   = get_posts( array(
            'post_type'        => array( 'post', 'page' ),
            'post_status'      => 'publish',
            'posts_per_page'   => 500,
            'suppress_filters' => true,
        ) );
        $queue = array();
        $seen  = array();
        foreach ( $posts as $post ) {
            if ( ! preg_match_all( '#<a\s[^>]*href=["\']([^"\']+)["\']#i', (string) $post->post_content, $m ) ) {
                continue;
            }
            foreach ( array_unique( $m[1] ) as $url ) {
                // Only checkable web links: skip anchors, mail, tel, relative
                // fragments and shortcode-y placeholders.
                if ( ! preg_match( '#^https?://#i', $url ) ) {
                    continue;
                }
                if ( in_array( md5( $url ), $prev['ignored'], true ) ) {
                    continue;
                }
                $key = md5( $url . '|' . $post->ID );
                if ( isset( $seen[ $key ] ) ) {
                    continue;
                }
                $seen[ $key ] = true;
                $queue[]      = array(
                    'url'        => $url,
                    'post_id'    => $post->ID,
                    'post_title' => (string) $post->post_title,
                );
            }
        }
        $state = array(
            'status'     => empty( $queue ) ? 'done' : 'scanning',
            'queue'      => $queue,
            'checked'    => 0,
            'total'      => count( $queue ),
            'broken'     => array(),
            'unverified' => array(),
            'ignored'    => $prev['ignored'],
            'scanned_at' => current_time( 'mysql' ),
        );
        self::save_state( $state );
        return self::public_state( $state );
    }

    /**
     * Check the next batch of queued links. Same URL on several posts is
     * checked once per batch thanks to a per-call memo. HEAD first, GET on
     * 405/403 (some hosts reject HEAD), redirects followed and recorded so a
     * moved page can offer "use the new URL" as a fix.
     */
    public static function run_batch() {
        $state = self::get_state();
        if ( 'scanning' !== $state['status'] || empty( $state['queue'] ) ) {
            return self::public_state( $state );
        }

        $batch          = array_splice( $state['queue'], 0, self::BATCH );
        $memo           = array();
        $own_host       = wp_parse_url( home_url(), PHP_URL_HOST );
        foreach ( $batch as $item ) {
            $url = $item['url'];
            if ( ! isset( $memo[ $url ] ) ) {
                $before = self::$outbound;
                // Internal URLs resolve against WordPress itself, never over
                // HTTP: a loopback request from a server to its own domain
                // flakes on plenty of hosts, and a health tool that cries
                // wolf about the site's own live pages is worse than none.
                $memo[ $url ] = ( wp_parse_url( $url, PHP_URL_HOST ) === $own_host )
                    ? self::check_internal_url( $url )
                    : self::check_url( $url );
                // Pace only what actually left the server. A memo hit or a
                // link resolved out of the database has nobody to be polite
                // to, and the internal check is the common case on most sites.
                if ( self::$outbound > $before ) {
                    usleep( self::PACE_US );
                }
            }
            $res = $memo[ $url ];
            $state['checked']++;
            if ( self::is_broken_status( $res['code'] ) && ! in_array( md5( $url ), $state['ignored'], true ) ) {
                $state['broken'][] = array(
                    'url'        => $url,
                    'code'       => $res['code'],
                    'redirect'   => $res['redirect'],
                    'internal'   => ( wp_parse_url( $url, PHP_URL_HOST ) === $own_host ),
                    'post_id'    => $item['post_id'],
                    'post_title' => $item['post_title'],
                );
            } elseif ( self::is_unverified_status( $res['code'] ) && ! in_array( md5( $url ), $state['ignored'], true ) ) {
                $state['unverified'][] = array(
                    'url'        => $url,
                    'code'       => $res['code'],
                    'redirect'   => $res['redirect'],
                    'internal'   => ( wp_parse_url( $url, PHP_URL_HOST ) === $own_host ),
                    'post_id'    => $item['post_id'],
                    'post_title' => $item['post_title'],
                );
            }
        }
        if ( empty( $state['queue'] ) ) {
            $state['status'] = 'done';
        }
        self::save_state( $state );
        return self::public_state( $state );
    }

    /**
     * Resolve an internal URL against WordPress content instead of HTTP.
     * Published post/page/attachment = fine. Known non-content routes
     * (feeds, llms.txt, uploads, categories/tags via term lookup) = fine.
     * Anything unresolvable = the 404 a visitor would get.
     */
    private static function check_internal_url( $url ) {
        $path = (string) wp_parse_url( $url, PHP_URL_PATH );
        // Non-post routes that always exist on this site.
        if ( preg_match( '#^/(wp-content|wp-json|feed|llms\.txt|robots\.txt|sitemap[^/]*\.xml)($|/)#i', $path ) || '/' === $path || '' === $path ) {
            return array( 'code' => 200, 'redirect' => '' );
        }
        $post_id = url_to_postid( $url );
        if ( $post_id && 'publish' === get_post_status( $post_id ) ) {
            return array( 'code' => 200, 'redirect' => '' );
        }
        // Pages resolve by path, terms by slug; url_to_postid misses some.
        $page = get_page_by_path( trim( $path, '/' ) );
        if ( $page && 'publish' === $page->post_status ) {
            return array( 'code' => 200, 'redirect' => '' );
        }
        $slug = basename( untrailingslashit( $path ) );
        foreach ( array( 'category', 'post_tag' ) as $tax ) {
            if ( get_term_by( 'slug', $slug, $tax ) ) {
                return array( 'code' => 200, 'redirect' => '' );
            }
        }
        // Custom routes from other plugins (shop pages, event calendars, member
        // areas) resolve through the rewrite rules rather than through content
        // lookups. Ask WordPress's own rules before calling anything dead: a
        // false "your shop is broken" destroys trust in the whole feature.
        global $wp_rewrite;
        if ( $wp_rewrite ) {
            $probe = ltrim( (string) $path, '/' );
            foreach ( (array) $wp_rewrite->wp_rewrite_rules() as $pattern => $query ) {
                // Skip the content catch-alls. WordPress's pagename/name rules
                // match nearly every path, so trusting them would green-light
                // every dead URL on the site. Those rules only resolve when the
                // content exists, and the lookups above already proved it does
                // not, so WordPress would serve a 404 here itself.
                if ( preg_match( '#(^|&|\?)(pagename|name|category_name|tag|attachment|year)=#', (string) $query ) ) {
                    continue;
                }
                if ( @preg_match( "#^{$pattern}#", $probe ) ) {
                    return array( 'code' => 200, 'redirect' => '' );
                }
            }
        }

        // Nothing local claims this URL. One HTTP request settles it.
        $res = self::check_url( $url );
        if ( 599 !== $res['code'] ) {
            return $res;
        }

        // The HTTP check could not run at all (host blocks loopback requests,
        // or we are behind a proxy that cannot see our own hostname). We are
        // NOT flying blind here: WordPress itself has already said no post,
        // page, term or rewrite rule answers this path, and WordPress is what
        // serves the URL. That is a 404. An earlier version returned 200 in
        // this branch to avoid false alarms and quietly hid real dead links
        // instead, which is the worse failure for a tool whose entire job is
        // finding them.
        return array( 'code' => 404, 'redirect' => '' );
    }

    /** One URL check. Returns final status code + redirect target when moved. */
    private static function check_url( $url ) {
        // Counted here rather than at the call site because check_internal_url()
        // also lands here for paths WordPress cannot resolve, and those deserve
        // the same pacing as any other outbound request.
        self::$outbound++;
        $args = array(
            'timeout'     => self::TIMEOUT,
            'redirection' => 0,
            'user-agent'  => 'Mozilla/5.0 (compatible; AEOGodMode-LinkHealth/1.0; +' . home_url( '/' ) . ')',
        );
        $redirect_target = '';
        $current         = $url;
        // Follow up to 5 redirects by hand so the FIRST hop's target is known.
        for ( $hop = 0; $hop <= 5; $hop++ ) {
            $resp = wp_remote_head( $current, $args );
            $code = is_wp_error( $resp ) ? 0 : (int) wp_remote_retrieve_response_code( $resp );
            if ( in_array( $code, array( 403, 405, 0 ), true ) ) {
                // Host dislikes HEAD (or errored): one GET attempt.
                $resp = wp_remote_get( $current, $args );
                $code = is_wp_error( $resp ) ? 0 : (int) wp_remote_retrieve_response_code( $resp );
            }
            if ( $code >= 300 && $code < 400 ) {
                $loc = wp_remote_retrieve_header( $resp, 'location' );
                if ( ! $loc ) {
                    break;
                }
                if ( 0 === strpos( $loc, '/' ) ) {
                    $p   = wp_parse_url( $current );
                    $loc = $p['scheme'] . '://' . $p['host'] . $loc;
                }
                if ( '' === $redirect_target ) {
                    $redirect_target = $loc;
                }
                $current = $loc;
                continue;
            }
            // A network error counts as broken only when even GET failed.
            if ( 0 === $code ) {
                $code = 599;
            }
            return array( 'code' => $code, 'redirect' => $redirect_target );
        }
        return array( 'code' => 599, 'redirect' => $redirect_target );
    }

    /**
     * One-click fix. 'unlink' removes the <a> wrapper and keeps the words;
     * 'redirect' rewrites the href to the recorded redirect target; 'ignore'
     * hides the row from future scans. Every content edit is anchored to the
     * exact stored URL, so nothing else in the post is touched.
     */
    public static function apply_fix( $url, $post_id, $action, $new_url = '' ) {
        $state = self::get_state();
        if ( 'ignore' === $action ) {
            $state['ignored'][] = md5( $url );
            $state['ignored']   = array_values( array_unique( $state['ignored'] ) );
            $state['broken']    = array_values( array_filter( $state['broken'], function ( $b ) use ( $url ) {
                return $b['url'] !== $url;
            } ) );
            $state['unverified'] = array_values( array_filter( $state['unverified'], function ( $b ) use ( $url ) {
                return $b['url'] !== $url;
            } ) );
            self::save_state( $state );
            return array( 'success' => true, 'state' => self::public_state( $state ) );
        }

        // post_id 0 means "everywhere this URL appears". A dead URL used in
        // eight posts is one decision, not eight, so the UI offers one button
        // and this resolves it to the full set.
        if ( 0 === (int) $post_id ) {
            $targets = array();
            foreach ( $state['broken'] as $b ) {
                if ( $b['url'] === $url ) {
                    $targets[] = (int) $b['post_id'];
                }
            }
            $targets = array_values( array_unique( $targets ) );
            if ( empty( $targets ) ) {
                return new \WP_Error( 'not_found', 'That link is no longer in the scan results.' );
            }
            $done = 0;
            foreach ( $targets as $pid ) {
                $res = self::apply_fix( $url, $pid, $action, $new_url );
                if ( ! is_wp_error( $res ) ) {
                    $done++;
                }
            }
            if ( 0 === $done ) {
                return new \WP_Error( 'not_found', 'That link was not found in any post content (it may already be fixed).' );
            }
            return array( 'success' => true, 'fixed' => $done, 'state' => self::public_state() );
        }

        $post = get_post( (int) $post_id );
        if ( ! $post ) {
            return new \WP_Error( 'no_post', 'Source post not found.' );
        }
        $content = (string) $post->post_content;
        $quoted  = preg_quote( $url, '#' );

        if ( 'unlink' === $action ) {
            $updated = preg_replace( '#<a\s[^>]*href=["\']' . $quoted . '["\'][^>]*>(.*?)</a>#is', '$1', $content );
        } elseif ( 'redirect' === $action ) {
            $new_url = esc_url_raw( $new_url );
            if ( '' === $new_url ) {
                return new \WP_Error( 'no_target', 'No redirect target recorded for that link.' );
            }
            $updated = str_replace( $url, $new_url, $content );
        } else {
            return new \WP_Error( 'bad_action', 'Unknown fix action.' );
        }

        if ( null === $updated || $updated === $content ) {
            return new \WP_Error( 'not_found', 'That link was not found in the post content (it may already be fixed).' );
        }
        wp_update_post( array( 'ID' => $post->ID, 'post_content' => $updated ) );

        $state['broken'] = array_values( array_filter( $state['broken'], function ( $b ) use ( $url, $post ) {
            return ! ( $b['url'] === $url && (int) $b['post_id'] === (int) $post->ID );
        } ) );
        self::save_state( $state );
        return array( 'success' => true, 'state' => self::public_state( $state ) );
    }

    /**
     * Best replacement for a dead INTERNAL link, when we can find one.
     *
     * Most internal 404s are a page that moved: /blog/get-cited-in-gemini when
     * the post now lives at /how-to-get-cited-in-gemini/. Unlinking that throws
     * away a link the author meant to make. Matching the dead slug against
     * published content recovers the intent instead, which is the difference
     * between a tidy-up tool and one that actually helps.
     *
     * Conservative on purpose: repointing a link at the WRONG page is far worse
     * than leaving a dead link alone, because the author never finds out. So
     * this refuses to guess, and a page it stays quiet about simply keeps the
     * unlink button it already had.
     *
     * The test is nesting, not similarity: every word of one slug appears in
     * the other. A page that moved keeps its words and may gain or lose a few
     * ("get-cited-in-gemini" -> "how-to-get-cited-in-gemini"), while a genuinely
     * different page always brings a word of its own that argues against the
     * match ("...-in-claude" against "...-in-gemini", "turkish" against
     * "pour-over"). A similarity score cannot tell those two apart, because
     * both differ from their candidate by exactly one word, so every threshold
     * either accepts both or rejects both.
     *
     * Measured by tools/coffee-kingz/seed/probe-link-suggest.php, which seeds a
     * 44-slug corpus and asks for a suggestion on 41 labelled dead slugs (18
     * real moves, 23 near-miss siblings that must stay silent). Re-run it before
     * changing anything here. On that corpus:
     *
     *   overlap >= 0.6    17 wrong, 18 right      overlap >= 0.8    3 wrong, 17 right
     *   overlap >= 0.75   10 wrong, 17 right      overlap >= 0.9    1 wrong, 17 right
     *   nesting            3 wrong, 16 right
     *
     * Read that honestly: nesting is not the most accurate rule on this corpus,
     * and 0.75 (the rule this replaced) is plainly the worst of the four. What
     * nesting buys is not a better score, it is a failure mode an author can
     * live with. An overlap threshold divides by the DEAD slug's word count, so
     * any short dead slug scores 1.0 against every longer slug containing its
     * words, several candidates tie on that perfect score, and the old code
     * silently took whichever post came back first. A suggestion decided by
     * post order is not conservative at any threshold; it only looks accurate
     * here because this corpus is small enough for the arbitrary pick to land
     * well. Nesting bounds the size difference and refuses outright when two
     * candidates fit, so the cases it cannot resolve become silence.
     *
     * The three it still gets wrong are all one shape: a qualifier that changes
     * which page is meant but not which words appear (gemini-citation-tracking-
     * setup, nitro-cold-brew-ratio-calculator-pro, coffee-brewing). Separating
     * those needs to know that "gemini" and "nitro" narrow a topic while "guide"
     * and "explained" do not, which is not knowable from slug words alone.
     *
     * @return array{url:string,title:string}|null
     */
    private static function suggest_internal_target( $url ) {
        $slug = basename( untrailingslashit( (string) wp_parse_url( $url, PHP_URL_PATH ) ) );
        $slug = preg_replace( '/\.(html?|php)$/i', '', $slug );
        if ( strlen( $slug ) < 6 ) {
            return null;
        }
        $want = self::slug_tokens( $slug );
        if ( count( $want ) < 2 ) {
            return null;
        }

        $hits  = array();
        $posts = get_posts( array(
            'post_type'        => array( 'post', 'page' ),
            'post_status'      => 'publish',
            'posts_per_page'   => 300,
            'fields'           => 'ids',
            'suppress_filters' => true,
        ) );
        foreach ( (array) $posts as $pid ) {
            $have = self::slug_tokens( get_post_field( 'post_name', $pid ) );
            if ( empty( $have ) ) {
                continue;
            }
            $shared = count( array_intersect( $want, $have ) );
            // One word in common is a coincidence, not evidence: /agency-pricing
            // is not /pricing and /guest-checkout is not /checkout.
            if ( $shared < 2 ) {
                continue;
            }
            if ( $shared !== count( $want ) && $shared !== count( $have ) ) {
                continue;
            }
            if ( abs( count( $want ) - count( $have ) ) > self::SLUG_DRIFT ) {
                continue;
            }
            $hits[] = $pid;
            // Two live pages fit the dead slug equally well, so there is no
            // way to tell which one the author meant. Picking either is a coin
            // flip on the reader's behalf. The old code took whichever post
            // was published most recently and said nothing about the tie.
            if ( count( $hits ) > 1 ) {
                return null;
            }
        }
        if ( 1 !== count( $hits ) ) {
            return null;
        }
        return array( 'url' => get_permalink( $hits[0] ), 'title' => get_the_title( $hits[0] ) );
    }

    /**
     * Content words in a slug, deduplicated.
     *
     * Short words are dropped because "in", "of" and "to" are glue, not
     * evidence. Deduplication matters because array_intersect() keeps repeats
     * from its first argument: "cold-brew-vs-hot-brew" counted "brew" twice
     * and scored itself a match it had not earned.
     */
    private static function slug_tokens( $slug ) {
        return array_values( array_unique( array_filter(
            preg_split( '/[^a-z0-9]+/', strtolower( (string) $slug ), -1, PREG_SPLIT_NO_EMPTY ),
            static function ( $t ) { return strlen( $t ) > 2; }
        ) ) );
    }

    /**
     * Client-shaped state: everything except the raw queue.
     *
     * Broken links are grouped BY URL, not per occurrence. One dead URL used in
     * eight posts is one problem with eight instances, and listing it eight
     * times turns a 12-problem list into 43 rows of the same few URLs, which is
     * how a maintenance tool becomes a chore nobody finishes.
     */
    public static function public_state( $state = null ) {
        $state = $state ?: self::get_state();

        $group_rows = static function ( $rows ) {
            $groups = array();
            foreach ( $rows as $b ) {
            $key = $b['url'];
            if ( ! isset( $groups[ $key ] ) ) {
                $groups[ $key ] = array(
                    'url'         => $b['url'],
                    'code'        => $b['code'],
                    'redirect'    => $b['redirect'],
                    'internal'    => ! empty( $b['internal'] ),
                    'occurrences' => array(),
                );
            }
            $groups[ $key ]['occurrences'][] = array(
                'post_id'    => (int) $b['post_id'],
                'post_title' => (string) $b['post_title'],
                'edit_url'   => get_edit_post_link( (int) $b['post_id'], 'raw' ),
            );
            }
            return array_values( $groups );
        };

        $groups = $group_rows( $state['broken'] );
        $unverified_groups = $group_rows( $state['unverified'] );

        // Suggestions cost a query each, so only for the rows a user will see.
        $groups = array_slice( array_values( $groups ), 0, 60 );
        foreach ( $groups as &$g ) {
            $g['count']      = count( $g['occurrences'] );
            $g['suggestion'] = ( $g['internal'] && ! $g['redirect'] ) ? self::suggest_internal_target( $g['url'] ) : null;
        }
        unset( $g );
        $unverified_groups = array_slice( $unverified_groups, 0, 60 );
        foreach ( $unverified_groups as &$g ) {
            $g['count'] = count( $g['occurrences'] );
        }
        unset( $g );

        return array(
            'status'       => $state['status'],
            'checked'      => $state['checked'],
            'total'        => $state['total'],
            'remaining'    => count( $state['queue'] ),
            'broken_count' => count( $state['broken'] ),
            'url_count'    => count( $groups ),
            'broken'       => $groups,
            'unverified'   => $unverified_groups,
            'scanned_at'   => $state['scanned_at'],
        );
    }
}
