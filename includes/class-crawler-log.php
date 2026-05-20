<?php
/**
 * AI Crawler visit log.
 *
 * Reads and manages the AI crawler visit log stored in a custom table.
 * Uses %i placeholders for table identifiers (WP 6.2+) so all SQL goes
 * through $wpdb->prepare(), satisfying PHPCS + WordPress Plugin Check
 * security rules. Read queries are wrapped in a short-TTL transient
 * cache to avoid hitting the DB on every dashboard render.
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

	const CACHE_GROUP   = 'asgm_crawler_log';
	const CACHE_TTL     = 5 * MINUTE_IN_SECONDS;

	/**
	 * Constructor. Registers the automatic log purge cron.
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
	 * Get the fully qualified table name. Returned raw because every call
	 * site passes it through %i in $wpdb->prepare(), which handles
	 * identifier escaping.
	 *
	 * @return string
	 */
	private function table_name() {
		global $wpdb;
		return $wpdb->prefix . 'asgm_crawler_log';
	}

	/**
	 * Cache helper. Returns cached value or runs the callback and caches
	 * the result. Lets us hit the DB once per 5 minutes per query shape.
	 *
	 * @param string   $key      Unique cache key for this query shape.
	 * @param callable $callback Function that runs the query and returns
	 *                           the result to cache.
	 * @return mixed
	 */
	private function remember( $key, $callback ) {
		$cached = wp_cache_get( $key, self::CACHE_GROUP );
		if ( false !== $cached ) {
			return $cached;
		}
		$value = $callback();
		wp_cache_set( $key, $value, self::CACHE_GROUP, self::CACHE_TTL );
		return $value;
	}

	/**
	 * Invalidate the cache when the underlying table changes.
	 */
	private function flush_cache() {
		wp_cache_flush_group( self::CACHE_GROUP );
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
		$page   = max( 1, absint( $page ) );
		$per    = max( 1, absint( $per_page ) );
		$offset = ( $page - 1 ) * $per;

		$cache_key = 'entries_' . $page . '_' . $per;

		return $this->remember( $cache_key, function () use ( $wpdb, $table, $per, $offset, $page ) {
			$total = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Wrapped by the remember() cache layer above (wp_cache_get/set with CACHE_GROUP).
				$wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table )
			);

			$entries = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Wrapped by the remember() cache layer above (wp_cache_get/set with CACHE_GROUP).
				$wpdb->prepare(
					'SELECT * FROM %i ORDER BY created_at DESC LIMIT %d OFFSET %d',
					$table,
					$per,
					$offset
				),
				ARRAY_A
			);

			return array(
				'entries'  => $entries ? $entries : array(),
				'total'    => $total,
				'page'     => $page,
				'per_page' => $per,
				'pages'    => (int) ceil( $total / max( 1, $per ) ),
			);
		} );
	}

	/**
	 * Get summary statistics. Cached for 5 minutes per call.
	 *
	 * @return array
	 */
	public function get_summary() {
		return $this->remember( 'summary', array( $this, 'compute_summary' ) );
	}

	/**
	 * Build the summary statistics. Called by get_summary() through the
	 * cache wrapper. Split out so the caching layer stays simple.
	 *
	 * @return array
	 */
	private function compute_summary() {
		global $wpdb;
		$table = $this->table_name();

		// Per-bot counts (last 30 days).
		$by_bot = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Called via compute_summary() inside get_summary()->remember() cache wrapper.
			$wpdb->prepare(
				'SELECT bot_name, COUNT(*) as visits FROM %i WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) GROUP BY bot_name ORDER BY visits DESC',
				$table
			),
			ARRAY_A
		);

		// Per-page counts (last 30 days, top 20).
		$by_page = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Called via compute_summary() inside get_summary()->remember() cache wrapper.
			$wpdb->prepare(
				'SELECT url, COUNT(*) as visits FROM %i WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) GROUP BY url ORDER BY visits DESC LIMIT 20',
				$table
			),
			ARRAY_A
		);
		if ( ! $by_page ) {
			$by_page = array();
		}

		// Daily counts (last 30 days).
		$daily = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Called via compute_summary() inside get_summary()->remember() cache wrapper.
			$wpdb->prepare(
				'SELECT DATE(created_at) as date, COUNT(*) as visits FROM %i WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) GROUP BY DATE(created_at) ORDER BY date ASC',
				$table
			),
			ARRAY_A
		);

		// 7-day total.
		$seven_day = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Called via compute_summary() inside get_summary()->remember() cache wrapper.
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)',
				$table
			)
		);

		// 30-day total.
		$thirty_day = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Called via compute_summary() inside get_summary()->remember() cache wrapper.
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)',
				$table
			)
		);

		// Per-bot detail cards: visits, last seen, top page.
		$bot_details = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Called via compute_summary() inside get_summary()->remember() cache wrapper.
			$wpdb->prepare(
				'SELECT bot_name, COUNT(*) as visits, MAX(created_at) as last_seen FROM %i WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) GROUP BY bot_name ORDER BY visits DESC',
				$table
			),
			ARRAY_A
		);

		// Add top page per bot.
		if ( $bot_details ) {
			foreach ( $bot_details as &$bot ) {
				$top_page = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Called via compute_summary() inside get_summary()->remember() cache wrapper.
					$wpdb->prepare(
						"SELECT url FROM %i WHERE bot_name = %s AND url != '/robots.txt' AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) GROUP BY url ORDER BY COUNT(*) DESC LIMIT 1",
						$table,
						$bot['bot_name']
					)
				);
				$bot['top_page']    = $top_page ? $top_page : '/robots.txt';
				$bot['robots_only'] = ( '/robots.txt' === $bot['top_page'] );
			}
			unset( $bot );
		}

		// Most crawled page (excluding robots.txt).
		$most_crawled = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Called via compute_summary() inside get_summary()->remember() cache wrapper.
			$wpdb->prepare(
				"SELECT url, COUNT(*) as visits FROM %i WHERE url != '/robots.txt' AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) GROUP BY url ORDER BY visits DESC LIMIT 1",
				$table
			),
			ARRAY_A
		);

		// Trend: this week vs last week.
		$this_week = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Called via compute_summary() inside get_summary()->remember() cache wrapper.
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)',
				$table
			)
		);
		$last_week = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Called via compute_summary() inside get_summary()->remember() cache wrapper.
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE created_at >= DATE_SUB(NOW(), INTERVAL 14 DAY) AND created_at < DATE_SUB(NOW(), INTERVAL 7 DAY)',
				$table
			)
		);
		$trend_direction = 'stable';
		$trend_pct       = 0;
		if ( $last_week > 0 ) {
			$trend_pct = (int) round( ( ( $this_week - $last_week ) / $last_week ) * 100 );
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
		$crawled_urls = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Called via compute_summary() inside get_summary()->remember() cache wrapper.
			$wpdb->prepare(
				"SELECT DISTINCT url FROM %i WHERE url != '/robots.txt'",
				$table
			)
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

		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->prepare( 'TRUNCATE TABLE %i', $table )
		);
		$this->flush_cache();
	}

	/**
	 * Purge entries older than a specified number of days.
	 *
	 * @param int $days Number of days to keep.
	 */
	public function purge_old( $days = 90 ) {
		global $wpdb;
		$table = $this->table_name();

		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- DELETE on write path; caching not applicable. flush_cache() runs immediately after.
			$wpdb->prepare(
				'DELETE FROM %i WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)',
				$table,
				absint( $days )
			)
		);
		$this->flush_cache();
	}
}
