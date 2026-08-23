<?php
/**
 * WordPress dashboard widget.
 *
 * Other SEO plugins put housekeeping on the dashboard: 404s, redirect counts,
 * their own blog feed. This widget answers the one question a site owner
 * cannot answer anywhere else in WordPress: is AI actually reading my site,
 * and does it quote me?
 *
 * Everything here works on the Free plugin. Citation figures are read only if
 * the Pro tracker is present, and their absence is shown as an invitation
 * rather than an empty stat.
 *
 * @package AISEOGodMode
 */

namespace AISEOGodMode;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Dashboard_Widget {

    public function __construct() {
        add_action( 'wp_dashboard_setup', array( $this, 'register' ) );
    }

    /** Only users who can act on the numbers get the widget. */
    public function register() {
        if ( ! current_user_can( 'edit_posts' ) ) {
            return;
        }
        wp_add_dashboard_widget(
            'asgm_overview',
            $this->widget_title(),
            array( $this, 'render' )
        );
    }

    /** Agency installs show the managing brand, never a second product name. */
    private function brand() {
        if ( class_exists( __NAMESPACE__ . '\\White_Label' ) && method_exists( __NAMESPACE__ . '\\White_Label', 'get' ) ) {
            $brand = White_Label::get();
            if ( is_array( $brand ) && ! empty( $brand['is_agency'] ) ) {
                return $brand;
            }
        }
        return array();
    }

    private function widget_title() {
        return __( 'AEO God Mode', 'aeo-god-mode' );
    }

    private function is_pro() {
        return class_exists( __NAMESPACE__ . '\\License' )
            && method_exists( __NAMESPACE__ . '\\License', 'is_pro' )
            && License::is_pro();
    }

    /**
     * The site's actual tier: free, pro, growth or agency.
     *
     * The footer upsell needs the tier, not just "is this Pro". A Growth site
     * has nothing left to buy, and a Pro site should be offered Growth rather
     * than the Pro page it already owns.
     */
    private function plan() {
        if ( ! class_exists( __NAMESPACE__ . '\\License' ) ) {
            return 'free';
        }
        $license = new License();
        $plan    = method_exists( $license, 'get_plan' ) ? strtolower( (string) $license->get_plan() ) : '';
        if ( in_array( $plan, array( 'free', 'pro', 'growth', 'agency' ), true ) ) {
            return $plan;
        }
        return $this->is_pro() ? 'pro' : 'free';
    }

    /* ─────────────────────────── Data ─────────────────────────── */

    /**
     * AI crawler activity. Returns null when the log has never recorded a
     * visit, so the caller can invite the user to switch it on instead of
     * showing a confident zero.
     */
    private function crawler_stats() {
        /*
         * The dashboard loads for every admin on every login, and this table
         * grows unbounded on a crawled site, so the aggregates are cached.
         * Fifteen minutes keeps the "last visit" heartbeat feeling live while
         * making repeat loads free.
         */
        $cached = get_transient( 'asgm_dw_crawl' );
        if ( is_array( $cached ) ) {
            return $cached;
        }
        if ( 'none' === $cached ) {
            return null;
        }

        $stats = $this->compute_crawler_stats();
        set_transient( 'asgm_dw_crawl', null === $stats ? 'none' : $stats, 15 * MINUTE_IN_SECONDS );
        return $stats;
    }

    private function compute_crawler_stats() {
        global $wpdb;
        $table = $wpdb->prefix . 'asgm_crawler_log';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return null;
        }

        $seven = (int) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            'SELECT COUNT(*) FROM %i WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)',
            $table
        ) );
        $prev = (int) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            'SELECT COUNT(*) FROM %i WHERE created_at >= DATE_SUB(NOW(), INTERVAL 14 DAY) AND created_at < DATE_SUB(NOW(), INTERVAL 7 DAY)',
            $table
        ) );
        $thirty = (int) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            'SELECT COUNT(*) FROM %i WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)',
            $table
        ) );
        /*
         * Both windows, because the hero widens to 30 days on a quiet week.
         * Chips that reported a different period from the headline number
         * could never be reconciled by the reader.
         */
        $bots_7 = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            'SELECT bot_name, COUNT(*) AS visits FROM %i WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) GROUP BY bot_name ORDER BY visits DESC LIMIT 4',
            $table
        ), ARRAY_A );
        $bots_30 = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            'SELECT bot_name, COUNT(*) AS visits FROM %i WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) GROUP BY bot_name ORDER BY visits DESC LIMIT 4',
            $table
        ), ARRAY_A );
        $last = $wpdb->get_row( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            'SELECT bot_name, created_at FROM %i ORDER BY created_at DESC LIMIT 1',
            $table
        ), ARRAY_A );
        $daily = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            'SELECT DATE(created_at) AS d, COUNT(*) AS visits FROM %i WHERE created_at >= DATE_SUB(NOW(), INTERVAL 14 DAY) GROUP BY DATE(created_at) ORDER BY d ASC',
            $table
        ), ARRAY_A );

        if ( empty( $last ) ) {
            return null;
        }

        return array(
            'seven'  => $seven,
            'thirty' => $thirty,
            'prev'   => $prev,
            'bots_7'  => (array) $bots_7,
            'bots_30' => (array) $bots_30,
            'last'  => $last,
            'daily' => (array) $daily,
        );
    }

    /** Citations in the last 7 days. Null when Pro is absent or unused. */
    private function citation_stats() {
        if ( ! $this->is_pro() ) {
            return null;
        }
        $results = get_option( 'asgm_citation_results', null );
        if ( ! is_array( $results ) || empty( $results ) ) {
            return null;
        }
        $cutoff = strtotime( current_time( 'mysql' ) ) - ( 7 * DAY_IN_SECONDS );
        $cited  = 0;
        $checks = 0;
        $engines = array();
        foreach ( $results as $row ) {
            if ( ! is_array( $row ) || empty( $row['checked_at'] ) ) {
                continue;
            }
            if ( strtotime( (string) $row['checked_at'] ) < $cutoff ) {
                continue;
            }
            $checks++;
            if ( ! empty( $row['cited'] ) ) {
                $cited++;
                $engine = (string) ( $row['engine'] ?? '' );
                if ( '' !== $engine ) {
                    $engines[ $engine ] = ( $engines[ $engine ] ?? 0 ) + 1;
                }
            }
        }
        if ( 0 === $checks ) {
            return null;
        }
        arsort( $engines );
        return array( 'cited' => $cited, 'checks' => $checks, 'engines' => $engines );
    }

    private function site_score() {
        $cached = get_option( 'asgm_site_score', null );
        if ( is_array( $cached ) && isset( $cached['score'] ) && is_numeric( $cached['score'] ) ) {
            return max( 0, min( 100, (int) $cached['score'] ) );
        }
        return null;
    }


    /**
     * The single most valuable thing on this widget: the page AI reads most
     * that answers worst.
     *
     * Crawl volume alone says nothing about quality, and a quality score alone
     * says nothing about attention. Crossed together they name one page where
     * effort actually pays, ranked by visits weighted by how much room the
     * page has left to improve.
     *
     * Only pages inside the owner's chosen content scope are ever named as a
     * fix: nagging someone about a post type they deliberately excluded is the
     * plugin arguing with its own settings. A heavily crawled URL that sits
     * outside the scope is reported separately, as a settings choice.
     *
     * @return array|null
     */
    private function priority_page() {
        $cached = get_transient( 'asgm_dw_priority' );
        if ( is_array( $cached ) ) {
            return $cached;
        }
        if ( 'none' === $cached ) {
            return null;
        }
        $found = $this->compute_priority_page();
        set_transient( 'asgm_dw_priority', null === $found ? 'none' : $found, HOUR_IN_SECONDS );
        return $found;
    }

    private function compute_priority_page() {
        global $wpdb;
        $table = $wpdb->prefix . 'asgm_crawler_log';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return null;
        }
        if ( ! class_exists( __NAMESPACE__ . '\\Answer_Density' ) ) {
            return null;
        }

        $rows = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            'SELECT url, COUNT(*) AS visits FROM %i WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) GROUP BY url ORDER BY visits DESC LIMIT 25',
            $table
        ), ARRAY_A );
        if ( empty( $rows ) ) {
            return null;
        }

        $scope     = Answer_Density::selected_post_types();
        $best      = null;
        $off_scope = null;

        foreach ( $rows as $row ) {
            $visits = (int) $row['visits'];
            $post_id = url_to_postid( (string) $row['url'] );
            if ( ! $post_id ) {
                continue;
            }
            // A crawler can hold a URL that has since been unpublished; the
            // widget is shared by every editor, so only public posts are named.
            if ( 'publish' !== get_post_status( $post_id ) ) {
                continue;
            }
            $type = get_post_type( $post_id );

            if ( ! in_array( $type, $scope, true ) ) {
                // Remember only the single busiest excluded URL, never a list.
                if ( null === $off_scope ) {
                    $off_scope = array(
                        'url'    => get_permalink( $post_id ),
                        'visits' => $visits,
                        'type'   => $type,
                        'label'  => get_post_type_object( $type ) ? get_post_type_object( $type )->labels->name : $type,
                    );
                }
                continue;
            }

            $meta = get_post_meta( $post_id, '_asgm_answer_density', true );
            if ( ! is_array( $meta ) || ! empty( $meta['excluded'] ) || ! isset( $meta['answer_density_score'] ) ) {
                continue;
            }
            /*
             * A page whose content is built by a theme template or a page
             * builder stores nothing in post_content, so the scorer reports
             * applicable=false rather than a real score. Treating that as a
             * zero would accuse a perfectly good page of answering nothing.
             */
            if ( isset( $meta['applicable'] ) && ! $meta['applicable'] ) {
                continue;
            }
            if ( (int) ( $meta['word_count'] ?? 0 ) < 1 ) {
                continue;
            }
            $score = (int) $meta['answer_density_score'];
            // Attention multiplied by headroom: a busy page scoring 90 is fine.
            $weight = $visits * ( 100 - $score );
            if ( $score >= 80 || $weight <= 0 ) {
                continue;
            }
            if ( null === $best || $weight > $best['weight'] ) {
                $best = array(
                    'post_id' => $post_id,
                    'title'   => get_the_title( $post_id ),
                    'url'     => get_permalink( $post_id ),
                    'visits'  => $visits,
                    'score'   => $score,
                    'weight'  => $weight,
                );
            }
        }

        if ( null === $best && null === $off_scope ) {
            return null;
        }
        return array( 'best' => $best, 'off_scope' => $off_scope );
    }

    /** Render the finding, at most one fix line and one scope line. */
    private function priority_line( $priority, $admin ) {
        if ( ! is_array( $priority ) ) {
            return;
        }
        $best = $priority['best'] ?? null;
        $off  = $priority['off_scope'] ?? null;

        if ( is_array( $best ) ) {
            echo '<div class="asgm-dw__alert">';
            echo '<span class="asgm-dw__alert-icon" aria-hidden="true">!</span>';
            echo '<div>';
            echo '<p class="asgm-dw__alert-text">'
                . sprintf(
                    /* translators: 1: number of visits, 2: page title, 3: score out of 100 */
                    esc_html__( 'AI bots read %1$s %2$s times this month. It scores %3$s/100 for answering.', 'aeo-god-mode' ),
                    '<strong>' . esc_html( $best['title'] ) . '</strong>', // phpcs:ignore WordPress.Security.EscapeOutput
                    esc_html( number_format_i18n( (int) $best['visits'] ) ),
                    esc_html( number_format_i18n( (int) $best['score'] ) )
                )
                . '</p>';
            /*
             * A contributor can read the finding but cannot edit someone
             * else's post, and get_edit_post_link() returns nothing for them.
             * Offer the link only to a user who can actually act on it, so
             * nobody is handed an empty href.
             */
            $edit = current_user_can( 'edit_post', $best['post_id'] ) ? get_edit_post_link( $best['post_id'] ) : '';
            if ( $edit ) {
                echo '<a class="asgm-dw__alert-link" href="' . esc_url( $edit ) . '">'
                    . esc_html__( 'Improve this page', 'aeo-god-mode' ) . ' &rarr;</a>';
            }
            echo '</div></div>';
        }

        // Quieter, and only when the busiest crawled URL is out of scope.
        if ( is_array( $off ) ) {
            echo '<p class="asgm-dw__scope">'
                . sprintf(
                    /* translators: 1: visit count, 2: post type label */
                    esc_html__( 'AI bots also read a page %1$s times that is not being scored, because %2$s are outside your chosen content types.', 'aeo-god-mode' ),
                    esc_html( number_format_i18n( (int) $off['visits'] ) ),
                    esc_html( strtolower( (string) $off['label'] ) )
                )
                . ' <a href="' . esc_url( $admin . '#/settings' ) . '">' . esc_html__( 'Change content types', 'aeo-god-mode' ) . '</a></p>';
        }
    }

    /* ─────────────────────────── Render ─────────────────────────── */

    public function render() {
        $crawl    = $this->crawler_stats();
        $cites    = $this->citation_stats();
        $score    = $this->site_score();
        $brand    = $this->brand();
        $is_pro   = $this->is_pro();
        $admin    = admin_url( 'admin.php?page=aeo-god-mode' );

        echo '<div class="asgm-dw">';
        $this->styles();

        if ( null === $crawl ) {
            $this->empty_state( $admin );
        } else {
            $this->hero( $crawl, $admin );
        }

        $this->priority_line( $this->priority_page(), $admin );

        echo '<div class="asgm-dw__row">';
        $this->citation_tile( $cites, $is_pro, $admin );
        $this->score_tile( $score, $admin );
        echo '</div>';

        $this->footer( $brand, $is_pro );
        echo '</div>';
    }

    /** First run, or the log is off: point at the one action that starts data. */
    private function empty_state( $admin ) {
        echo '<div class="asgm-dw__empty">';
        echo '<p class="asgm-dw__empty-title">' . esc_html__( 'No AI crawler visits recorded yet', 'aeo-god-mode' ) . '</p>';
        echo '<p class="asgm-dw__empty-note">' . esc_html__( 'Once the AI Crawler Log is on, this shows every time ChatGPT, Claude, Perplexity or Google reads your site.', 'aeo-god-mode' ) . '</p>';
        echo '<a class="button button-primary" href="' . esc_url( $admin . '#/crawlers' ) . '">' . esc_html__( 'Turn on crawler logging', 'aeo-god-mode' ) . '</a>';
        echo '</div>';
    }

    private function hero( $crawl, $admin ) {
        $seven  = (int) $crawl['seven'];
        $thirty = (int) ( $crawl['thirty'] ?? $seven );
        $prev   = (int) $crawl['prev'];

        /*
         * Crawling is bursty: an engine can read a site hard for two days then
         * go quiet for a fortnight. Leading with a bare 0 would read as "this
         * is broken" on a site that is being crawled perfectly well, so a
         * silent week widens the window instead of reporting nothing.
         */
        $wide  = ( 0 === $seven && $thirty > 0 );
        $value = $wide ? $thirty : $seven;
        $label = $wide
            ? __( 'AI crawler visits, last 30 days', 'aeo-god-mode' )
            : __( 'AI crawler visits, last 7 days', 'aeo-god-mode' );

        echo '<div class="asgm-dw__hero">';
        echo '<div class="asgm-dw__hero-main">';
        echo '<div class="asgm-dw__big">' . esc_html( number_format_i18n( $value ) ) . '</div>';
        echo '<div class="asgm-dw__big-label">' . esc_html( $label ) . '</div>';

        // A trend needs both a previous week and a current one worth comparing.
        if ( ! $wide && $prev > 0 ) {
            $delta = (int) round( ( ( $seven - $prev ) / $prev ) * 100 );
            $dir   = $delta >= 0 ? 'up' : 'down';
            $arrow = $delta >= 0 ? '&uarr;' : '&darr;';
            echo '<div class="asgm-dw__delta asgm-dw__delta--' . esc_attr( $dir ) . '">' . $arrow . ' ' // phpcs:ignore WordPress.Security.EscapeOutput
                . esc_html( abs( $delta ) . '% vs previous 7 days' ) . '</div>';
        }
        echo '</div>';
        echo '<div class="asgm-dw__spark">' . $this->sparkline( $crawl['daily'] ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput
        echo '</div>';

        /*
         * The heartbeat. A named bot with a human interval is the line that
         * makes this worth opening: it is live proof, not a monthly report.
         */
        if ( ! empty( $crawl['last']['created_at'] ) ) {
            $ago = human_time_diff( strtotime( (string) $crawl['last']['created_at'] ), time() );
            echo '<p class="asgm-dw__last">'
                . '<span class="asgm-dw__pulse"></span> '
                . sprintf(
                    /* translators: 1: bot name, 2: human readable time difference */
                    esc_html__( 'Last visit: %1$s, %2$s ago', 'aeo-god-mode' ),
                    '<strong>' . esc_html( $crawl['last']['bot_name'] ) . '</strong>', // phpcs:ignore WordPress.Security.EscapeOutput
                    esc_html( $ago )
                )
                . '</p>';
        }

        $bots = $wide ? ( $crawl['bots_30'] ?? array() ) : ( $crawl['bots_7'] ?? array() );
        if ( ! empty( $bots ) ) {
            echo '<div class="asgm-dw__chips">';
            echo '<span class="asgm-dw__chips-label">' . esc_html__( 'Requests by bot:', 'aeo-god-mode' ) . '</span>';
            foreach ( $bots as $bot ) {
                echo '<span class="asgm-dw__chip">' . esc_html( $bot['bot_name'] )
                    . ' <b>' . esc_html( number_format_i18n( (int) $bot['visits'] ) ) . '</b></span>';
            }
            echo '</div>';
        }

        echo '<p class="asgm-dw__more"><a href="' . esc_url( $admin . '#/crawler-log' ) . '">'
            . esc_html__( 'See every visit', 'aeo-god-mode' ) . ' &rarr;</a></p>';
    }

    /** Inline SVG, so the widget needs no chart library and no extra request. */
    private function sparkline( $daily ) {
        $values = array();
        foreach ( (array) $daily as $row ) {
            $values[] = (int) ( $row['visits'] ?? 0 );
        }
        if ( count( $values ) < 2 ) {
            return '';
        }
        $max = max( $values );
        if ( $max <= 0 ) {
            return '';
        }
        $w   = 120;
        $h   = 34;
        $step = $w / ( count( $values ) - 1 );
        $points = array();
        foreach ( $values as $i => $v ) {
            $x = round( $i * $step, 1 );
            $y = round( $h - ( ( $v / $max ) * ( $h - 4 ) ) - 2, 1 );
            $points[] = $x . ',' . $y;
        }
        $line = implode( ' ', $points );
        $area = '0,' . $h . ' ' . $line . ' ' . $w . ',' . $h;

        return '<svg viewBox="0 0 ' . $w . ' ' . $h . '" width="' . $w . '" height="' . $h . '" aria-hidden="true" focusable="false">'
            . '<polygon points="' . esc_attr( $area ) . '" fill="rgba(37,99,235,0.12)"></polygon>'
            . '<polyline points="' . esc_attr( $line ) . '" fill="none" stroke="#2563eb" stroke-width="2" stroke-linejoin="round" stroke-linecap="round"></polyline>'
            . '</svg>';
    }

    private function citation_tile( $cites, $is_pro, $admin ) {
        echo '<div class="asgm-dw__tile">';
        if ( is_array( $cites ) ) {
            echo '<div class="asgm-dw__tile-num">' . esc_html( number_format_i18n( (int) $cites['cited'] ) ) . '</div>';
            echo '<div class="asgm-dw__tile-label">'
                . sprintf(
                    /* translators: %s: number of checks run */
                    esc_html__( 'citations found in %s checks, last 7 days', 'aeo-god-mode' ),
                    esc_html( number_format_i18n( (int) $cites['checks'] ) )
                )
                . '</div>';
            if ( ! empty( $cites['engines'] ) ) {
                echo '<div class="asgm-dw__tile-note">' . esc_html( implode( ', ', array_keys( $cites['engines'] ) ) ) . '</div>';
            }
            echo '<a class="asgm-dw__tile-link" href="' . esc_url( $admin . '#/citations' ) . '">' . esc_html__( 'Open Citation Tracker', 'aeo-god-mode' ) . ' &rarr;</a>';
        } elseif ( $is_pro ) {
            echo '<div class="asgm-dw__tile-label">' . esc_html__( 'AI engines are reading your site. Run a check to see whether they quote you.', 'aeo-god-mode' ) . '</div>';
            echo '<a class="asgm-dw__tile-link" href="' . esc_url( $admin . '#/citations' ) . '">' . esc_html__( 'Run a citation check', 'aeo-god-mode' ) . ' &rarr;</a>';
        } else {
            // The question the crawler number provokes, answered by Pro.
            echo '<div class="asgm-dw__tile-label">' . esc_html__( 'They are reading you. Do they quote you?', 'aeo-god-mode' ) . '</div>';
            echo '<div class="asgm-dw__tile-note">' . esc_html__( 'Citation Tracker checks ChatGPT, Perplexity, Gemini and Claude for your pages.', 'aeo-god-mode' ) . '</div>';
        }
        echo '</div>';
    }

    private function score_tile( $score, $admin ) {
        echo '<div class="asgm-dw__tile">';
        if ( null === $score ) {
            echo '<div class="asgm-dw__tile-label">' . esc_html__( 'Answer Density is not scored yet.', 'aeo-god-mode' ) . '</div>';
            echo '<a class="asgm-dw__tile-link" href="' . esc_url( $admin . '#/content-health' ) . '">' . esc_html__( 'Score my pages', 'aeo-god-mode' ) . ' &rarr;</a>';
        } else {
            echo '<div class="asgm-dw__tile-num">' . esc_html( number_format_i18n( $score ) ) . '<span class="asgm-dw__tile-den">/100</span></div>';
            echo '<div class="asgm-dw__tile-label">' . esc_html__( 'Answer Density score', 'aeo-god-mode' ) . '</div>';
            echo '<a class="asgm-dw__tile-link" href="' . esc_url( $admin . '#/content-health' ) . '">' . esc_html__( 'Improve it', 'aeo-god-mode' ) . ' &rarr;</a>';
        }
        echo '</div>';
    }

    private function footer( $brand, $is_pro ) {
        $docs = ! empty( $brand['docs_url'] ) ? $brand['docs_url'] : 'https://aeogodmode.io/docs/';
        $blog = 'https://aeogodmode.io/blog/';

        echo '<div class="asgm-dw__footer">';
        // An agency client should never be sent to our blog or pricing.
        if ( empty( $brand ) ) {
            echo '<a href="' . esc_url( $blog ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Blog', 'aeo-god-mode' ) . '</a>';
        }
        echo '<a href="' . esc_url( $docs ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Docs', 'aeo-god-mode' ) . '</a>';
        if ( ! empty( $brand['support_url'] ) ) {
            echo '<a href="' . esc_url( $brand['support_url'] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Support', 'aeo-god-mode' ) . '</a>';
        }
        /*
         * One rung up the ladder, never a link to what they already own:
         * Free is offered Pro, Pro is offered Growth, Growth and Agency get
         * nothing. Agency clients never see our pricing at all.
         */
        if ( empty( $brand ) ) {
            $plan = $this->plan();
            if ( 'free' === $plan ) {
                echo '<a class="asgm-dw__pro" href="https://aeogodmode.io/pricing/" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Go Pro', 'aeo-god-mode' ) . '</a>';
            } elseif ( 'pro' === $plan ) {
                echo '<a class="asgm-dw__pro" href="https://aeogodmode.io/pricing/" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Upgrade to Growth', 'aeo-god-mode' ) . '</a>';
            }
        }
        echo '</div>';
    }

    /** Scoped to the widget, printed once, sized for the dashboard column. */
    private function styles() {
        static $done = false;
        if ( $done ) {
            return;
        }
        $done = true;
        echo '<style>
        .asgm-dw{font-size:13px}
        .asgm-dw__hero{display:flex;align-items:flex-start;justify-content:space-between;gap:12px}
        .asgm-dw__big{font-size:34px;font-weight:600;line-height:1.1;color:#1d2327}
        .asgm-dw__big-label{color:#646970;margin-top:2px}
        .asgm-dw__delta{margin-top:6px;font-weight:600}
        .asgm-dw__delta--up{color:#00792b}
        .asgm-dw__delta--down{color:#b32d2e}
        .asgm-dw__spark{flex:0 0 auto;padding-top:6px}
        .asgm-dw__last{margin:12px 0 0;color:#50575e;display:flex;align-items:center;gap:7px}
        .asgm-dw__pulse{width:8px;height:8px;border-radius:50%;background:#00a32a;display:inline-block;flex:0 0 auto}
        .asgm-dw__chips{display:flex;flex-wrap:wrap;gap:6px;margin-top:10px;align-items:center}
        .asgm-dw__chips-label{color:#787c82;font-size:12px;margin-right:2px}
        .asgm-dw__chip{background:#f0f0f1;border-radius:999px;padding:3px 10px;color:#50575e;font-size:12px}
        .asgm-dw__chip b{color:#1d2327}
        .asgm-dw__more{margin:10px 0 0}
        .asgm-dw__alert{display:flex;gap:9px;align-items:flex-start;margin-top:14px;padding:11px 13px;background:#fcf9e8;border-left:3px solid #dba617;border-radius:0 4px 4px 0}
        .asgm-dw__alert-icon{flex:0 0 auto;width:17px;height:17px;border-radius:50%;background:#dba617;color:#fff;font-weight:700;font-size:12px;line-height:17px;text-align:center}
        .asgm-dw__alert-text{margin:0 0 3px;color:#1d2327;line-height:1.45}
        .asgm-dw__scope{margin:9px 0 0;color:#787c82;font-size:12px;line-height:1.45}
        .asgm-dw__row{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:16px;padding-top:16px;border-top:1px solid #f0f0f1}
        .asgm-dw__tile-num{font-size:22px;font-weight:600;color:#1d2327;line-height:1.2}
        .asgm-dw__tile-den{font-size:13px;color:#646970;font-weight:400}
        .asgm-dw__tile-label{color:#50575e;margin-top:2px;line-height:1.45}
        .asgm-dw__tile-note{color:#787c82;font-size:12px;margin-top:4px;line-height:1.4}
        .asgm-dw__tile-link{display:inline-block;margin-top:6px}
        .asgm-dw__footer{display:flex;flex-wrap:wrap;gap:14px;margin-top:16px;padding-top:12px;border-top:1px solid #f0f0f1}
        .asgm-dw__pro{color:#00792b;font-weight:600}
        .asgm-dw__empty{text-align:center;padding:8px 0 4px}
        .asgm-dw__empty-title{font-size:14px;font-weight:600;color:#1d2327;margin:0 0 4px}
        .asgm-dw__empty-note{color:#646970;margin:0 0 12px;line-height:1.5}
        @media (max-width:480px){.asgm-dw__row{grid-template-columns:1fr}}
        </style>';
    }
}
