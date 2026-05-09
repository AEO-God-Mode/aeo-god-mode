<?php
/**
 * AI Crawler visit log.
 *
 * @package AISEOGodMode
 */

namespace AISEOGodMode;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Reads and manages the AI crawler visit log stored in a custom table.
 */
class CrawlerLog {

    /**
     * Constructor — registers automatic log purge cron.
     */
    public function __construct() {
        add_action( 'asgm_purge_old_crawler_logs', array( $this, 'run_scheduled_purge' ) );

        if ( ! wp_next_scheduled( 'asgm_purge_old_crawler_logs' ) ) {
            wp_schedule_event( time(), 'daily', 'asgm_purge_old_crawler_logs' );
        }
    }

    /**
     * Run the scheduled purge (entries older than 90 days).
     */
    public function run_scheduled_purge() {
        $this->purge_old( 90 );
    }

    /**
     * Get the fully qualified table name.
     *
     * Table names cannot use %s placeholders in $wpdb->prepare() — that
     * would wrap the name in quotes and break the query. Instead we
     * apply esc_sql() once here so every call site is covered.
     *
     * @return string Escaped table name safe for interpolation.
     */
    private function table_name() {
        global $wpdb;
        return esc_sql( $wpdb->prefix . 'asgm_crawler_log' );
    }

    /**
     * Get paginated log entries.
     *
     * @param int $page     Page number.
     * @param int $per_page Items per page.
     * @return array
     */
    public function get_entries( $page = 1, $per_page = 50 ) {
        global $wpdb;
        $table  = $this->table_name();
        $offset = ( absint( $page ) - 1 ) * absint( $per_page );

        $total = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
            "SELECT COUNT(*) FROM `{$table}`" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        );

        $entries = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->prepare(
                "SELECT * FROM `{$table}` ORDER BY created_at DESC LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                absint( $per_page ),
                $offset
            ),
            ARRAY_A
        );

        return array(
            'entries'  => $entries ? $entries : array(),
            'total'    => $total,
            'page'     => $page,
            'per_page' => $per_page,
            'pages'    => ceil( $total / max( 1, $per_page ) ),
        );
    }

    /**
     * Get summary statistics.
     *
     * @return array
     */
    public function get_summary() {
        global $wpdb;
        $table = $this->table_name();

        // Per-bot counts (last 30 days).
        $by_bot = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
            "SELECT bot_name, COUNT(*) as visits FROM `{$table}` WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) GROUP BY bot_name ORDER BY visits DESC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            ARRAY_A
        );

        // Per-page counts (last 30 days, top 20).
        if ( true ) {
            $by_page = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
                "SELECT url, COUNT(*) as visits FROM `{$table}` WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) GROUP BY url ORDER BY visits DESC LIMIT 20", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                ARRAY_A
            );
            if ( ! $by_page ) {
                $by_page = array();
            }
        }

        // Daily counts (last 30 days).
        $daily = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
            "SELECT DATE(created_at) as date, COUNT(*) as visits FROM `{$table}` WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) GROUP BY DATE(created_at) ORDER BY date ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            ARRAY_A
        );

        // 7-day total.
        $seven_day = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
            "SELECT COUNT(*) FROM `{$table}` WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        );

        // 30-day total.
        $thirty_day = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
            "SELECT COUNT(*) FROM `{$table}` WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        );

        // Per-bot detail cards: visits, last seen, top page.
        $bot_details = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
            "SELECT bot_name, COUNT(*) as visits,
                        MAX(created_at) as last_seen
                 FROM `{$table}`
                 WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                 GROUP BY bot_name
                 ORDER BY visits DESC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            ARRAY_A
        );

        // Add top page per bot.
        if ( $bot_details ) {
            foreach ( $bot_details as &$bot ) {
                $top_page = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
                    $wpdb->prepare(
                        "SELECT url FROM `{$table}` WHERE bot_name = %s AND url != '/robots.txt' AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) GROUP BY url ORDER BY COUNT(*) DESC LIMIT 1",
                        $bot['bot_name']
                    )
                );
                $bot['top_page']      = $top_page ? $top_page : '/robots.txt';
                $bot['robots_only']   = ( '/robots.txt' === $bot['top_page'] );
            }
            unset( $bot );
        }

        // Most crawled page (excluding robots.txt).
        $most_crawled = null;
        if ( true ) {
            $most_crawled = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
                "SELECT url, COUNT(*) as visits FROM `{$table}` WHERE url != '/robots.txt' AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) GROUP BY url ORDER BY visits DESC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                ARRAY_A
            );
        }

        // Trend: this week vs last week.
        $this_week = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
            "SELECT COUNT(*) FROM `{$table}` WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        );
        $last_week = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
            "SELECT COUNT(*) FROM `{$table}` WHERE created_at >= DATE_SUB(NOW(), INTERVAL 14 DAY) AND created_at < DATE_SUB(NOW(), INTERVAL 7 DAY)" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        );
        $trend_direction = 'stable';
        $trend_pct       = 0;
        if ( $last_week > 0 ) {
            $trend_pct = round( ( ( $this_week - $last_week ) / $last_week ) * 100 );
            if ( $trend_pct > 10 ) {
                $trend_direction = 'up';
            } elseif ( $trend_pct < -10 ) {
                $trend_direction = 'down';
            }
        } elseif ( $this_week > 0 ) {
            $trend_direction = 'up';
            $trend_pct       = 100;
        }

        // Blind spots: published posts with zero AI bot visits.
        $blind_spots = array();
        $all_posts   = array();

        if ( true ) {
            $crawled_urls = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
                "SELECT DISTINCT url FROM `{$table}` WHERE url != '/robots.txt'" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            );

            $all_posts = get_posts( array(
                'post_type'      => 'post',
                'post_status'    => 'publish',
                'posts_per_page' => 100,
                'orderby'        => 'date',
                'order'          => 'DESC',
                'fields'         => 'ids',
            ) );

            if ( ! empty( $all_posts ) ) {
                $crawled_set = array_flip( $crawled_urls ? $crawled_urls : array() );
                foreach ( $all_posts as $pid ) {
                    $post_path = wp_make_link_relative( get_permalink( $pid ) );
                    if ( ! isset( $crawled_set[ $post_path ] ) ) {
                        $blind_spots[] = array(
                            'id'    => $pid,
                            'title' => get_the_title( $pid ),
                            'url'   => $post_path,
                            'date'  => get_the_date( 'Y-m-d', $pid ),
                        );
                    }
                    if ( count( $blind_spots ) >= 10 ) {
                        break; // Cap at 10 for performance.
                    }
                }
            }
        }

        return array(
            'by_bot'           => $by_bot ? $by_bot : array(),
            'bot_details'      => $bot_details ? $bot_details : array(),
            'by_page'          => $by_page,
            'daily'            => $daily ? $daily : array(),
            'seven_day'        => $seven_day,
            'thirty_day'       => $thirty_day,
            'most_crawled'     => $most_crawled,
            'trend_direction'  => $trend_direction,
            'trend_pct'        => $trend_pct,
            'blind_spots'      => $blind_spots,
            'blind_spot_count' => count( $blind_spots ),
            'total_posts'      => count( $all_posts ),
        );
    }

    /**
     * Clear all log entries.
     */
    public function clear() {
        global $wpdb;
        $table = $this->table_name();
        $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
            "TRUNCATE TABLE `{$table}`" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        );
    }

    /**
     * Purge entries older than a specified number of days.
     *
     * @param int $days Number of days to keep.
     */
    public function purge_old( $days = 90 ) {
        global $wpdb;
        $table = $this->table_name();

        $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->prepare(
                "DELETE FROM `{$table}` WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
                absint( $days )
            )
        );
    }
}
