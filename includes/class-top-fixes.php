<?php
/**
 * Top fixes: one ranked answer to "what do I fix next to get more customers
 * from AI search?"
 *
 * The plugin has several engines that each report problems in their own words
 * and scores (Citation Tracker, AI Referrals, Answer Density, Citability,
 * Content Health, Content Gaps, Crawler Access, Link Health, Conflicts). Some
 * of them report the same problem on the same page. This class reads what each
 * engine has already stored (no new scans, no AI calls), turns it into
 * candidate fixes, dedupes them per page and problem, and ranks them by how
 * close each one sits to a lead:
 *
 *   1. a lost buyer question (commercial intent)
 *   2. a page real people reach from AI search, with a weak answer
 *   3. an AI search crawler blocked in robots.txt
 *   4. a lost informational question
 *   5. hygiene (broken links, weakest answers elsewhere, tidy-ups)
 *
 * Free owns every REST route, so this lives in Free and reads Pro data only
 * when the Pro classes are loaded.
 *
 * @package AISEOGodMode
 */

namespace AISEOGodMode;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Top_Fixes {

	const DISMISS_OPT  = 'aeogm_top_fixes_dismissed';
	const CACHE_PREFIX = 'aeogm_top_fixes_v1_';
	const CACHE_TTL    = 600;
	const SNOOZE_DAYS  = 30;
	const SHOW         = 3;
	const UNDO_PREFIX  = 'aeogm_top_fix_undo_';
	const UNDO_DAYS    = 7;

	/** Answer Density score below which an answer counts as weak. */
	const WEAK_ANSWER = 70;

	/** Citability score below which a page counts as weak. */
	const WEAK_CITABILITY = 60;

	/** Crawlers that fetch a page because a person asked an assistant. */
	const USER_FETCHERS = array( 'ChatGPT-User', 'Claude-User', 'Perplexity-User', 'MistralAI-User', 'DuckAssistBot', 'Meta-ExternalFetcher' );

	/** @var array<string,int> path => post id lookups made during one build. */
	private static $path_cache = array();

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes() {
		register_rest_route( API::NAMESPACE, '/top-fixes', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'rest_get' ),
			'permission_callback' => array( __CLASS__, 'permission' ),
		) );
		register_rest_route( API::NAMESPACE, '/top-fixes/dismiss', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'rest_dismiss' ),
			'permission_callback' => array( __CLASS__, 'permission' ),
		) );
		register_rest_route( API::NAMESPACE, '/top-fixes/allow-crawler', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'rest_allow_crawler' ),
			'permission_callback' => array( __CLASS__, 'permission' ),
		) );
		register_rest_route( API::NAMESPACE, '/top-fixes/run', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'rest_run' ),
			'permission_callback' => array( __CLASS__, 'permission' ),
		) );
		register_rest_route( API::NAMESPACE, '/top-fixes/undo', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'rest_undo' ),
			'permission_callback' => array( __CLASS__, 'permission' ),
		) );
	}

	public static function permission() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new \WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to access this endpoint.', 'aeo-god-mode' ),
				array( 'status' => 403 )
			);
		}
		return true;
	}

	// ---------------------------------------------------------------------
	// REST
	// ---------------------------------------------------------------------

	public static function rest_get( $request ) {
		$is_pro = self::is_pro();
		$key    = self::CACHE_PREFIX . ( $is_pro ? 'pro' : 'free' );

		if ( ! $request->get_param( 'refresh' ) ) {
			$cached = get_transient( $key );
			if ( is_array( $cached ) ) {
				return rest_ensure_response( $cached );
			}
		}

		$data = self::build( $is_pro );
		set_transient( $key, $data, self::CACHE_TTL );
		return rest_ensure_response( $data );
	}

	public static function rest_dismiss( $request ) {
		$id = sanitize_key( (string) $request->get_param( 'id' ) );
		if ( '' === $id ) {
			return new \WP_REST_Response( array( 'success' => false, 'message' => __( 'That fix could not be found.', 'aeo-god-mode' ) ), 400 );
		}
		$dismissed        = self::dismissed();
		$dismissed[ $id ] = time() + self::SNOOZE_DAYS * DAY_IN_SECONDS;
		update_option( self::DISMISS_OPT, $dismissed, false );
		self::flush();
		return rest_ensure_response( array_merge( array( 'success' => true ), self::build( self::is_pro() ) ) );
	}

	/**
	 * Allow AI search crawlers that are blocked today.
	 *
	 * Robots::save_config() rebuilds the whole rule set from what it is
	 * given, so the existing rules are merged in first. Only crawlers in the
	 * search group that the matrix reports as blocked are touched, so this
	 * route cannot be used to change any other robots.txt decision.
	 */
	public static function rest_allow_crawler( $request ) {
		$asked = $request->get_param( 'uas' );
		$asked = is_array( $asked ) ? array_map( 'sanitize_text_field', $asked ) : array();

		$blocked = array();
		foreach ( self::blocked_search_bots( Crawler_Access::matrix() ) as $bot ) {
			$blocked[ $bot['ua'] ] = $bot;
		}
		$targets = array_values( array_intersect( $asked, array_keys( $blocked ) ) );
		if ( empty( $targets ) ) {
			return new \WP_REST_Response( array( 'success' => false, 'message' => __( 'Those crawlers are not blocked any more.', 'aeo-god-mode' ) ), 400 );
		}

		$rules = get_option( 'asgm_robots_rules', array() );
		$rules = is_array( $rules ) ? $rules : array();
		foreach ( $targets as $ua ) {
			$rules[ $ua ] = 'allow';
		}
		$robots = new Robots();
		$robots->save_config( array( 'bots' => $rules ) );
		update_option( 'asgm_robots_rules_updated', gmdate( 'c' ) );

		// Check the robots.txt actually served now. A physical robots.txt or
		// another plugin can still carry the Disallow, and saying "done" when
		// it is not would be worse than saying nothing.
		$still = array();
		foreach ( self::blocked_search_bots( Crawler_Access::matrix() ) as $bot ) {
			if ( in_array( $bot['ua'], $targets, true ) ) {
				$still[] = $bot['label'];
			}
		}
		self::flush();

		if ( ! empty( $still ) ) {
			return rest_ensure_response( array(
				'success'       => false,
				'still_blocked' => $still,
				'message'       => sprintf(
					/* translators: %s: crawler names, e.g. "OAI-SearchBot, PerplexityBot". */
					__( 'We allowed it here, but your robots.txt still blocks %s. That rule comes from a robots.txt file or another plugin. Open AI Crawlers to see where.', 'aeo-god-mode' ),
					implode( ', ', $still )
				),
			) );
		}

		return rest_ensure_response( array_merge( array( 'success' => true ), self::build( self::is_pro() ) ) );
	}

	/**
	 * Do one fix for the customer: draft with AI and apply it in one call.
	 *
	 * The fix is looked up by id in a fresh build, so the browser can only
	 * name a fix the server itself ranked; it cannot pass its own question,
	 * page or crawler list. Drafting and applying go through the same REST
	 * handlers the review flows use (credit checks, idempotence, escaping),
	 * dispatched internally. The page is snapshotted first so the change can
	 * be undone in one click.
	 */
	public static function rest_run( $request ) {
		$id     = sanitize_key( (string) $request->get_param( 'id' ) );
		$is_pro = self::is_pro();
		$fix    = null;
		foreach ( self::build( $is_pro )['fixes'] as $candidate ) {
			if ( $candidate['id'] === $id ) {
				$fix = $candidate;
				break;
			}
		}
		if ( ! $fix ) {
			return new \WP_REST_Response( array(
				'success' => false,
				'message' => __( 'This fix has changed since the list loaded. We refreshed it for you.', 'aeo-god-mode' ),
				'stale'   => true,
			), 409 );
		}

		$action = $fix['action'];
		if ( empty( $action['auto'] ) ) {
			return new \WP_REST_Response( array( 'success' => false, 'message' => __( 'This one needs a quick look from you. Use the button on the fix.', 'aeo-god-mode' ) ), 400 );
		}
		if ( ! empty( $action['pro_only'] ) && ! $is_pro ) {
			return new \WP_REST_Response( array( 'success' => false, 'message' => __( 'Pro does this one for you.', 'aeo-god-mode' ) ), 403 );
		}

		switch ( $action['type'] ) {
			case 'allow_crawler':
				$result = self::run_allow_crawler( $action );
				break;
			case 'lost_question':
				$result = self::run_lost_question( $fix );
				break;
			case 'rewrite_opener':
				$result = self::run_rewrite_opener( $fix );
				break;
			default:
				$result = array( 'success' => false, 'message' => __( 'This one needs a quick look from you.', 'aeo-god-mode' ) );
		}

		self::flush();
		if ( empty( $result['success'] ) ) {
			return rest_ensure_response( array_merge( $result, array( 'credits' => self::credits_after( $result ) ) ) );
		}
		return rest_ensure_response( array_merge(
			$result,
			array( 'fix_id' => $id, 'credits' => self::credits_after( $result ) ),
			self::build( $is_pro )
		) );
	}

	/** Put a page (or the crawler rules) back the way it was before a fix. */
	public static function rest_undo( $request ) {
		$token = preg_replace( '/[^A-Za-z0-9]/', '', (string) $request->get_param( 'token' ) );
		$snap  = '' !== $token ? get_transient( self::UNDO_PREFIX . $token ) : false;
		if ( ! is_array( $snap ) || (int) ( $snap['user'] ?? 0 ) !== get_current_user_id() ) {
			return new \WP_REST_Response( array( 'success' => false, 'message' => __( 'This fix can no longer be undone here. WordPress keeps the old version under Revisions in the editor.', 'aeo-god-mode' ) ), 410 );
		}

		if ( 'robots' === $snap['kind'] ) {
			$now = get_option( 'asgm_robots_rules', array() );
			if ( md5( wp_json_encode( $now ) ) !== $snap['after_hash'] ) {
				return new \WP_REST_Response( array( 'success' => false, 'message' => __( 'Your crawler settings changed since this fix, so we left them alone. Open AI Crawlers to change them.', 'aeo-god-mode' ) ), 409 );
			}
			$robots = new Robots();
			$robots->save_config( array( 'bots' => (array) $snap['rules'] ) );
			update_option( 'asgm_robots_rules_updated', gmdate( 'c' ) );
		} else {
			$post_id = (int) $snap['post_id'];
			if ( ! current_user_can( 'edit_post', $post_id ) ) {
				return new \WP_REST_Response( array( 'success' => false, 'message' => __( 'You cannot edit this page.', 'aeo-god-mode' ) ), 403 );
			}
			if ( self::page_hash( $post_id ) !== $snap['after_hash'] ) {
				return new \WP_REST_Response( array( 'success' => false, 'message' => __( 'This page was edited after the fix, so we left it alone. WordPress keeps the old version under Revisions in the editor.', 'aeo-god-mode' ) ), 409 );
			}
			$updated = wp_update_post( wp_slash( array( 'ID' => $post_id, 'post_content' => (string) $snap['content'] ) ), true );
			if ( is_wp_error( $updated ) ) {
				return new \WP_REST_Response( array( 'success' => false, 'message' => __( 'We could not put the page back. WordPress keeps the old version under Revisions in the editor.', 'aeo-god-mode' ) ), 500 );
			}
			if ( $snap['meta_exists'] ) {
				update_post_meta( $post_id, 'feature_faqs', wp_slash( (string) $snap['meta'] ) );
			} else {
				delete_post_meta( $post_id, 'feature_faqs' );
			}
			if ( class_exists( __NAMESPACE__ . '\\Answer_Density' ) ) {
				Answer_Density::scan_post( $post_id );
				Answer_Density::refresh_summary();
			}
		}

		if ( ! empty( $snap['fix_id'] ) ) {
			$dismissed = self::dismissed();
			unset( $dismissed[ $snap['fix_id'] ] );
			update_option( self::DISMISS_OPT, $dismissed, false );
		}
		delete_transient( self::UNDO_PREFIX . $token );
		self::flush();

		return rest_ensure_response( array_merge(
			array( 'success' => true, 'message' => __( 'Undone. The page is back the way it was.', 'aeo-god-mode' ) ),
			self::build( self::is_pro() )
		) );
	}

	/** Allow blocked AI search crawlers, keeping the old rules for undo. */
	private static function run_allow_crawler( array $action ) {
		$before = get_option( 'asgm_robots_rules', array() );
		$req    = new \WP_REST_Request( 'POST' );
		$req->set_param( 'uas', (array) ( $action['payload']['uas'] ?? array() ) );
		$res  = self::rest_allow_crawler( $req );
		$data = $res instanceof \WP_REST_Response ? $res->get_data() : array();
		if ( empty( $data['success'] ) ) {
			return array( 'success' => false, 'message' => (string) ( $data['message'] ?? __( 'That did not go through. Open AI Crawlers to check.', 'aeo-god-mode' ) ) );
		}
		return array(
			'success'      => true,
			'message'      => __( 'Done. AI search crawlers can read your site now.', 'aeo-god-mode' ),
			'credits_used' => 0,
			'undo_token'   => self::save_undo( array(
				'kind'       => 'robots',
				'rules'      => is_array( $before ) ? $before : array(),
				'after_hash' => md5( wp_json_encode( get_option( 'asgm_robots_rules', array() ) ) ),
			) ),
			'view_url'     => home_url( '/robots.txt' ),
		);
	}

	/**
	 * Answer a question AI answered without the site.
	 *
	 * Short questions become an FAQ entry, longer ones a new section, the
	 * same split the review flow suggests. If the page already has a similar
	 * heading, the drafted section goes in as an FAQ instead, with no second
	 * charge.
	 */
	private static function run_lost_question( array $fix ) {
		$question = (string) $fix['action']['payload']['question'];
		$post_id  = (int) $fix['action']['payload']['post_id'];
		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			return array( 'success' => false, 'message' => __( 'You cannot edit this page.', 'aeo-god-mode' ) );
		}
		$snap  = self::snapshot( $post_id );
		$words = count( preg_split( '/\s+/', trim( $question ) ) );
		$used  = 0;

		if ( $words <= 8 ) {
			$draft = self::dispatch( '/query-gap/draft-answer', array( 'query' => $question, 'post_id' => $post_id ) );
			if ( empty( $draft['success'] ) ) {
				return array( 'success' => false, 'message' => self::friendly( $draft ) );
			}
			$used    = empty( $draft['cached'] ) ? 1 : 0;
			$applied = self::dispatch( '/query-gap/apply-faq', array(
				'query'    => $question,
				'question' => (string) $draft['question'],
				'answer'   => (string) $draft['answer'],
				'post_id'  => $post_id,
			) );
			$message = sprintf(
				/* translators: %s: the question. */
				__( 'Added a clear answer to "%s" to your page.', 'aeo-god-mode' ),
				self::trim_words( $question, 12 )
			);
		} else {
			$draft = self::dispatch( '/query-gap/draft-heading', array( 'query' => $question, 'post_id' => $post_id ) );
			if ( empty( $draft['success'] ) ) {
				return array( 'success' => false, 'message' => self::friendly( $draft ) );
			}
			$used    = empty( $draft['cached'] ) ? 1 : 0;
			$applied = self::dispatch( '/query-gap/apply-heading', array(
				'query'   => $question,
				'heading' => (string) $draft['heading'],
				'intro'   => (string) $draft['intro'],
				'post_id' => $post_id,
				'level'   => 'h2',
			) );
			$message = sprintf(
				/* translators: %s: the question. */
				__( 'Added a new section to your page that answers "%s".', 'aeo-god-mode' ),
				self::trim_words( $question, 12 )
			);
			if ( empty( $applied['success'] ) && false !== stripos( (string) ( $applied['error'] ?? '' ), 'already exists' ) ) {
				$q = ucfirst( trim( $question ) );
				$applied = self::dispatch( '/query-gap/apply-faq', array(
					'query'    => $question,
					'question' => '?' === substr( $q, -1 ) ? $q : $q . '?',
					'answer'   => (string) $draft['intro'],
					'post_id'  => $post_id,
				) );
				$message = sprintf(
					/* translators: %s: the question. */
					__( 'Added a clear answer to "%s" to your page.', 'aeo-god-mode' ),
					self::trim_words( $question, 12 )
				);
			}
		}

		if ( empty( $applied['success'] ) ) {
			if ( false !== stripos( (string) ( $applied['error'] ?? '' ), 'already in the page faq' ) ) {
				self::mark_done( $fix['id'] );
				return array(
					'success'      => true,
					'message'      => __( 'Your page already answers this in its FAQ, so we marked it done.', 'aeo-god-mode' ),
					'credits_used' => $used,
					'view_url'     => (string) get_permalink( $post_id ),
				);
			}
			return array( 'success' => false, 'message' => self::friendly( $applied ), 'credits_used' => $used );
		}

		// The Citation Tracker keeps listing this question until its next
		// check, so hold it off the list instead of offering it again.
		self::mark_done( $fix['id'] );
		$snap['fix_id'] = $fix['id'];
		return array(
			'success'      => true,
			'message'      => $message,
			'credits_used' => $used,
			'undo_token'   => self::save_undo( $snap + array( 'after_hash' => self::page_hash( $post_id ) ) ),
			'view_url'     => (string) get_permalink( $post_id ),
			'edit_url'     => (string) get_edit_post_link( $post_id, 'raw' ),
		);
	}

	/** Rewrite a buried opening so the answer comes first, then save it. */
	private static function run_rewrite_opener( array $fix ) {
		$row     = (array) ( $fix['action']['payload']['row'] ?? array() );
		$post_id = (int) ( $row['post_id'] ?? 0 );
		$issue   = (array) ( $row['first_issue'] ?? array() );
		$heading = (string) ( $issue['heading'] ?? '' );
		if ( ! $post_id || '' === $heading || ! current_user_can( 'edit_post', $post_id ) ) {
			return array( 'success' => false, 'message' => __( 'You cannot edit this page.', 'aeo-god-mode' ) );
		}
		$snap = self::snapshot( $post_id );

		$draft = self::dispatch( '/answer-density/rewrite-opener', array(
			'post_id'        => $post_id,
			'heading'        => $heading,
			'classification' => (string) ( $issue['opener_kind'] ?? '' ) ?: 'setup',
		) );
		if ( empty( $draft['success'] ) || empty( $draft['rewrite'] ) ) {
			return array( 'success' => false, 'message' => self::friendly( $draft ), 'credits' => $draft['credits'] ?? null );
		}

		$applied = self::dispatch( '/answer-density/apply-rewrite', array(
			'post_id' => $post_id,
			'heading' => $heading,
			'rewrite' => (string) $draft['rewrite'],
		) );
		if ( empty( $applied['success'] ) ) {
			return array(
				'success'      => false,
				'message'      => __( 'AI wrote the new opening but we could not place it on the page. Open the page and use Rewrite there.', 'aeo-god-mode' ),
				'credits_used' => 1,
				'credits'      => $draft['credits'] ?? null,
			);
		}

		return array(
			'success'      => true,
			'message'      => sprintf(
				/* translators: %s: a heading on the page. */
				__( 'Rewrote the opening under "%s" so it answers first.', 'aeo-god-mode' ),
				self::trim_words( $heading, 10 )
			),
			'credits_used' => 1,
			'credits'      => $draft['credits'] ?? null,
			'undo_token'   => self::save_undo( $snap + array( 'after_hash' => self::page_hash( $post_id ) ) ),
			'view_url'     => (string) get_permalink( $post_id ),
			'edit_url'     => (string) get_edit_post_link( $post_id, 'raw' ),
		);
	}

	/**
	 * Call one of the plugin's own REST routes in-process, as the current
	 * user, so its permission check, credit check and validation all run.
	 *
	 * @return array Response data, with WP_Error turned into success=false.
	 */
	private static function dispatch( $route, array $params ) {
		$req = new \WP_REST_Request( 'POST', '/' . API::NAMESPACE . $route );
		foreach ( $params as $k => $v ) {
			$req->set_param( $k, $v );
		}
		$res = rest_do_request( $req );
		if ( $res->is_error() ) {
			$err = $res->as_error();
			return array( 'success' => false, 'error' => $err->get_error_message(), 'code' => $err->get_error_code() );
		}
		$data = $res->get_data();
		return is_array( $data ) ? $data : array( 'success' => false, 'error' => '' );
	}

	/** Customer wording for a failed draft or apply. Never a raw server string. */
	private static function friendly( array $res ) {
		$err = strtolower( (string) ( $res['error'] ?? '' ) );
		if ( false !== strpos( $err, 'credit' ) ) {
			return __( 'You are out of AI credits. Top up to let AI do this for you.', 'aeo-god-mode' );
		}
		if ( false !== strpos( $err, 'not published' ) ) {
			return __( 'This page is not published, so there is nothing for AI to cite yet.', 'aeo-god-mode' );
		}
		if ( false !== strpos( $err, 'no buried-opener' ) || false !== strpos( $err, 'heading_not_found' ) || false !== strpos( $err, 'locate heading' ) ) {
			return __( 'This page changed since it was checked. Click refresh on Top fixes and try again.', 'aeo-god-mode' );
		}
		if ( false !== strpos( $err, 'license' ) ) {
			return __( 'Pro does this one for you.', 'aeo-god-mode' );
		}
		return __( 'AI could not finish this one just now. Try again in a minute, or use Review first.', 'aeo-god-mode' );
	}

	/** Page content and the FAQ meta some pages keep, before a change. */
	private static function snapshot( $post_id ) {
		$meta = get_post_meta( $post_id, 'feature_faqs', true );
		return array(
			'kind'        => 'page',
			'post_id'     => (int) $post_id,
			'content'     => (string) get_post_field( 'post_content', $post_id, 'raw' ),
			'meta_exists' => metadata_exists( 'post', $post_id, 'feature_faqs' ),
			'meta'        => is_string( $meta ) ? $meta : wp_json_encode( $meta ),
		);
	}

	/** What the page looks like now, so undo never overwrites later edits. */
	private static function page_hash( $post_id ) {
		clean_post_cache( $post_id );
		$meta = get_post_meta( $post_id, 'feature_faqs', true );
		return md5( (string) get_post_field( 'post_content', $post_id, 'raw' ) . '|' . ( is_string( $meta ) ? $meta : wp_json_encode( $meta ) ) );
	}

	/** Keep a snapshot for one week, readable only by the user who ran the fix. */
	private static function save_undo( array $snap ) {
		$token        = wp_generate_password( 24, false, false );
		$snap['user'] = get_current_user_id();
		set_transient( self::UNDO_PREFIX . $token, $snap, self::UNDO_DAYS * DAY_IN_SECONDS );
		return $token;
	}

	/** Hold a done fix off the list until the engine that found it checks again. */
	private static function mark_done( $id ) {
		$dismissed        = self::dismissed();
		$dismissed[ $id ] = time() + self::SNOOZE_DAYS * DAY_IN_SECONDS;
		update_option( self::DISMISS_OPT, $dismissed, false );
	}

	/** Latest credit balance after a run, for the header counter. */
	private static function credits_after( array $result ) {
		if ( isset( $result['credits'] ) && is_array( $result['credits'] ) && isset( $result['credits']['remaining'] ) ) {
			return $result['credits'];
		}
		if ( empty( $result['credits_used'] ) || ! class_exists( __NAMESPACE__ . '\\MetadataGenerator' ) ) {
			return null;
		}
		return MetadataGenerator::get_credits();
	}

	/** Forget cached rankings, e.g. after any fix is applied. */
	public static function flush() {
		delete_transient( self::CACHE_PREFIX . 'pro' );
		delete_transient( self::CACHE_PREFIX . 'free' );
	}

	// ---------------------------------------------------------------------
	// Build
	// ---------------------------------------------------------------------

	/**
	 * Read every engine, rank, dedupe and cut to the top fixes.
	 *
	 * @param bool $is_pro Whether Pro fixes can run here.
	 * @return array
	 */
	public static function build( $is_pro ) {
		self::$path_cache = array();

		$ad_rows    = self::answer_density_rows();
		$cit_rows   = $is_pro ? self::citability_rows() : array();
		$visits     = $is_pro ? self::referral_visits() : array();
		$fetches    = self::user_fetches();
		$candidates = array();
		$sources    = array(
			'answers'   => ! empty( $ad_rows ),
			'citations' => false,
			'referrals' => ! empty( $visits ),
			'crawlers'  => true,
		);

		// Lost questions (Citation Tracker, Pro).
		$lost_posts = array();
		foreach ( self::lost_questions() as $lost ) {
			$sources['citations'] = true;
			$post_id    = (int) $lost['post_id'];
			$commercial = self::is_commercial( $lost['query'] );
			$ad         = $post_id && isset( $ad_rows[ $post_id ] ) ? $ad_rows[ $post_id ] : null;
			$traffic    = self::traffic_for( $lost['path'], $visits, $fetches );
			$engines    = $lost['engines'];

			$evidence = array( sprintf(
				/* translators: %s: list of AI assistants, e.g. "ChatGPT, Perplexity and Claude". */
				__( 'Answered without you on %s.', 'aeo-god-mode' ),
				self::join_list( $engines )
			) );
			if ( $traffic['visits'] > 0 ) {
				$evidence[] = self::visits_line( $traffic );
			}
			$fix_sources = array( __( 'Citation Tracker', 'aeo-god-mode' ) );
			if ( $ad && (int) $ad['score'] < self::WEAK_ANSWER ) {
				$evidence[]    = __( 'This page also buries its answers under setup text.', 'aeo-god-mode' );
				$fix_sources[] = __( 'Answer check', 'aeo-god-mode' );
			}

			$score = ( $commercial ? 1000 : 450 ) + 40 * count( $engines ) + min( 100, 5 * $traffic['visits'] ) + ( $ad ? 30 : 0 );

			$candidates[] = array(
				'id'       => 'lost-' . $post_id . '-' . substr( md5( strtolower( $lost['query'] ) ), 0, 8 ),
				'kind'     => 'lost_question',
				'tier'     => $commercial ? 1 : 4,
				'label'    => $commercial ? __( 'Buyer question', 'aeo-god-mode' ) : __( 'AI answer', 'aeo-god-mode' ),
				'score'    => $score,
				'group'    => 'lost-' . $post_id,
				'title'    => sprintf(
					/* translators: %s: the question people ask AI. */
					__( 'Win back "%s"', 'aeo-god-mode' ),
					self::trim_words( $lost['query'], 12 )
				),
				'reason'   => $commercial
					? sprintf(
						/* translators: %s: list of AI assistants. */
						__( 'People ask AI this when they are choosing who to buy from. %s answered without your site, so those buyers are being sent somewhere else.', 'aeo-god-mode' ),
						self::join_list( $engines )
					)
					: sprintf(
						/* translators: %s: list of AI assistants. */
						__( '%s answered this without your site. Adding a clear answer to your page gives AI something of yours to quote.', 'aeo-god-mode' ),
						self::join_list( $engines )
					),
				'evidence' => $evidence,
				'sources'  => $fix_sources,
				'page'     => self::page( $post_id, $lost['title'], $lost['url'] ),
				'action'   => $post_id
					? array(
						'type'     => 'lost_question',
						'label'    => __( 'Write the answer', 'aeo-god-mode' ),
						'pro_only' => true,
						'auto'     => true,
						'cost'     => 1,
						'payload'  => array( 'question' => $lost['query'], 'post_id' => $post_id ),
					)
					: self::link_action( __( 'Open Citation Tracker', 'aeo-god-mode' ), '#/citations' ),
			);
			if ( $post_id ) {
				$lost_posts[ $post_id ] = true;
			}
		}

		// Weak answers, merged across Answer Density, Citability and Content
		// Gaps so one page with one problem is one fix.
		$gap_rows = self::content_gap_rows();
		$weak     = array();
		foreach ( $ad_rows as $post_id => $row ) {
			if ( (int) $row['score'] < self::WEAK_ANSWER && ! empty( $row['first_issue']['heading'] ) ) {
				$weak[ $post_id ]['ad'] = $row;
			}
		}
		foreach ( $cit_rows as $post_id => $row ) {
			if ( (int) $row['score'] < self::WEAK_CITABILITY ) {
				$weak[ $post_id ]['cit'] = $row;
			}
		}
		foreach ( $gap_rows as $post_id => $row ) {
			foreach ( (array) ( $row['issues'] ?? array() ) as $issue ) {
				if ( 'aeo_weak' === ( $issue['type'] ?? '' ) && isset( $weak[ $post_id ] ) ) {
					$weak[ $post_id ]['gap'] = $row;
				}
			}
		}

		foreach ( $weak as $post_id => $hits ) {
			if ( isset( $lost_posts[ $post_id ] ) ) {
				continue; // Named in that page's lost-question fix instead.
			}
			$ad    = $hits['ad'] ?? null;
			$cit   = $hits['cit'] ?? null;
			$title = $ad['title'] ?? ( $cit['title'] ?? get_the_title( $post_id ) );
			$url   = $ad['view_url'] ?? ( $cit['url'] ?? get_permalink( $post_id ) );
			$path  = self::path_of( (string) $url );

			$traffic = self::traffic_for( $path, $visits, $fetches );
			$visited = $traffic['visits'] > 0;
			$weakest = $ad ? ( self::WEAK_ANSWER - (int) $ad['score'] ) : ( self::WEAK_CITABILITY - (int) $cit['score'] );

			$fix_sources = array();
			if ( $ad ) {
				$fix_sources[] = __( 'Answer check', 'aeo-god-mode' );
			}
			if ( $cit ) {
				$fix_sources[] = __( 'Citability', 'aeo-god-mode' );
			}
			if ( isset( $hits['gap'] ) ) {
				$fix_sources[] = __( 'Content Gaps', 'aeo-god-mode' );
			}

			$evidence = array();
			if ( $visited ) {
				$evidence[] = self::visits_line( $traffic );
			}
			if ( $ad ) {
				$evidence[] = sprintf(
					/* translators: 1: headings answered straight away, 2: question headings on the page. */
					__( '%1$d of %2$d question headings answer straight away.', 'aeo-god-mode' ),
					(int) ( $ad['direct_answers'] ?? 0 ),
					(int) ( $ad['question_headings'] ?? 0 )
				);
			}
			if ( $cit ) {
				$evidence[] = sprintf(
					/* translators: %d: citability score out of 100. */
					__( 'Citability %d out of 100.', 'aeo-god-mode' ),
					(int) $cit['score']
				);
			}

			$heading = $ad['first_issue']['heading'] ?? ( $cit['answer_fix']['heading'] ?? '' );
			if ( $visited ) {
				$reason = $heading
					? sprintf(
						/* translators: 1: number of AI visits, 2: a heading on the page. */
						_n( '%1$d person reached this page from AI search, but the answer under "%2$s" is buried. People who cannot find the answer fast usually leave.', '%1$d people reached this page from AI search, but the answer under "%2$s" is buried. People who cannot find the answer fast usually leave.', $traffic['visits'], 'aeo-god-mode' ),
						$traffic['visits'],
						self::trim_words( $heading, 10 )
					)
					: __( 'People already reach this page from AI search, but it does not answer their question straight away. People who cannot find the answer fast usually leave.', 'aeo-god-mode' );
			} else {
				$reason = $heading
					? sprintf(
						/* translators: %s: a heading on the page. */
						__( 'The answer under "%s" is buried below setup text. AI quotes pages that answer first, so this page is easy to pass over.', 'aeo-god-mode' ),
						self::trim_words( $heading, 10 )
					)
					: __( 'This page does not answer its questions straight away. AI quotes pages that answer first, so this page is easy to pass over.', 'aeo-god-mode' );
			}

			if ( $is_pro && $ad && ! empty( $ad['first_issue']['heading'] ) ) {
				$action = array(
					'type'     => 'rewrite_opener',
					'label'    => __( 'Rewrite the opening', 'aeo-god-mode' ),
					'pro_only' => true,
					'auto'     => true,
					'cost'     => 1,
					'payload'  => array(
						'row' => array(
							'post_id'     => (int) $post_id,
							'title'       => $title,
							'edit_url'    => $ad['edit_url'] ?? '',
							'first_issue' => $ad['first_issue'],
						),
					),
				);
			} elseif ( $is_pro && $cit && ! empty( $cit['answer_fix']['heading'] ) ) {
				$action = self::link_action( __( 'Fix it in Citability', 'aeo-god-mode' ), '#/citability' );
			} else {
				$action = array(
					'type'     => 'edit',
					'label'    => __( 'Open the page', 'aeo-god-mode' ),
					'pro_only' => false,
					'url'      => (string) get_edit_post_link( $post_id, 'raw' ),
					'upsell'   => ! $is_pro,
				);
			}

			$candidates[] = array(
				'id'       => 'answer-' . (int) $post_id,
				'kind'     => 'weak_answer',
				'tier'     => $visited ? 2 : 5,
				'label'    => $visited ? __( 'AI visitors', 'aeo-god-mode' ) : __( 'Tidy-up', 'aeo-god-mode' ),
				'score'    => ( $visited ? 800 + min( 150, 15 * $traffic['visits'] ) : 200 ) + max( 0, min( 70, $weakest ) ) + 10 * ( count( $fix_sources ) - 1 ),
				'group'    => 'answer-' . (int) $post_id,
				'title'    => sprintf(
					/* translators: %s: page title. */
					__( 'Make "%s" answer straight away', 'aeo-god-mode' ),
					self::trim_words( wp_strip_all_tags( (string) $title ), 9 )
				),
				'reason'   => $reason,
				'evidence' => $evidence,
				'sources'  => $fix_sources,
				'page'     => self::page( $post_id, $title, $url ),
				'action'   => $action,
			);
		}

		// Blocked AI search crawlers, as one fix.
		$matrix  = Crawler_Access::matrix();
		$blocked = self::blocked_search_bots( $matrix );
		if ( ! empty( $blocked ) && ! empty( $matrix['robots_known'] ) ) {
			$labels = array_map( static function ( $b ) { return $b['label']; }, $blocked );
			$first  = $blocked[0];
			$title  = 1 === count( $blocked )
				? sprintf(
					/* translators: %s: crawler name, e.g. "OAI-SearchBot". */
					__( 'Let %s see your site', 'aeo-god-mode' ),
					$first['label']
				)
				: sprintf(
					/* translators: 1: crawler name, 2: number of other crawlers. */
					_n( 'Let %1$s and %2$d more AI crawler see your site', 'Let %1$s and %2$d more AI crawlers see your site', count( $blocked ) - 1, 'aeo-god-mode' ),
					$first['label'],
					count( $blocked ) - 1
				);
			$candidates[] = array(
				'id'       => 'crawler-search',
				'kind'     => 'blocked_crawler',
				'tier'     => 3,
				'label'    => __( 'AI access', 'aeo-god-mode' ),
				'score'    => 600 + 50 * min( 4, count( $blocked ) ),
				'group'    => 'crawler-search',
				'title'    => $title,
				'reason'   => sprintf(
					/* translators: %s: list of crawler names. */
					__( 'Your robots.txt blocks %s, so those assistants cannot read or cite your pages when someone asks about a business like yours.', 'aeo-god-mode' ),
					self::join_list( $labels )
				),
				'evidence' => array_map( static function ( $b ) {
					return sprintf(
						/* translators: 1: crawler name, 2: company, e.g. "OpenAI". */
						__( '%1$s (%2$s) is blocked.', 'aeo-god-mode' ),
						$b['label'],
						$b['vendor']
					);
				}, array_slice( $blocked, 0, 3 ) ),
				'sources'  => array( __( 'AI Crawlers', 'aeo-god-mode' ) ),
				'page'     => null,
				'action'   => array(
					'type'     => 'allow_crawler',
					'label'    => 1 === count( $blocked ) ? __( 'Allow it', 'aeo-god-mode' ) : __( 'Allow them', 'aeo-god-mode' ),
					'pro_only' => false,
					'auto'     => true,
					'cost'     => 0,
					'payload'  => array( 'uas' => array_values( array_map( static function ( $b ) { return $b['ua']; }, $blocked ) ) ),
				),
			);
		}

		// Broken links, as one fix.
		$links = self::broken_links();
		if ( $links['urls'] > 0 ) {
			$candidates[] = array(
				'id'       => 'links-broken',
				'kind'     => 'broken_links',
				'tier'     => 5,
				'label'    => __( 'Tidy-up', 'aeo-god-mode' ),
				'score'    => 260 + min( 90, 3 * $links['urls'] ) + ( $links['internal'] > 0 ? 20 : 0 ),
				'group'    => 'links-broken',
				'title'    => sprintf(
					/* translators: %d: number of broken links. */
					_n( 'Fix %d broken link', 'Fix %d broken links', $links['urls'], 'aeo-god-mode' ),
					$links['urls']
				),
				'reason'   => sprintf(
					/* translators: %d: number of pages with a broken link. */
					_n( 'A visitor who clicks a dead link usually leaves before getting in touch. %d page has one.', 'A visitor who clicks a dead link usually leaves before getting in touch. %d pages have one.', $links['pages'], 'aeo-god-mode' ),
					$links['pages']
				),
				'evidence' => $links['internal'] > 0
					? array( sprintf(
						/* translators: %d: number of broken links to the site's own pages. */
						_n( '%d of them points to one of your own pages.', '%d of them point to your own pages.', $links['internal'], 'aeo-god-mode' ),
						$links['internal']
					) )
					: array(),
				'sources'  => array( __( 'Link Health', 'aeo-god-mode' ) ),
				'page'     => null,
				'action'   => array(
					'type'     => 'scroll',
					'label'    => __( 'Fix broken links', 'aeo-god-mode' ),
					'pro_only' => false,
					'target'   => 'asgm-link-health',
				),
			);
		}

		// Content Health problems: the biggest group, as one fix.
		$health = self::content_health_group();
		if ( $health ) {
			$candidates[] = array(
				'id'       => 'health-' . sanitize_key( $health['key'] ),
				'kind'     => 'content_health',
				'tier'     => 5,
				'label'    => __( 'Tidy-up', 'aeo-god-mode' ),
				'score'    => 150 + min( 60, 2 * (int) $health['count'] ),
				'group'    => 'health',
				'title'    => sprintf(
					/* translators: 1: number of pages, 2: the problem, e.g. "Missing meta descriptions". */
					_n( '%2$s on %1$d page', '%2$s on %1$d pages', (int) $health['count'], 'aeo-god-mode' ),
					(int) $health['count'],
					wp_strip_all_tags( (string) $health['title'] )
				),
				'reason'   => wp_strip_all_tags( (string) ( $health['blurb'] ?? '' ) ),
				'evidence' => array(),
				'sources'  => array( __( 'Content Health', 'aeo-god-mode' ) ),
				'page'     => null,
				'action'   => self::link_action( __( 'Review and fix', 'aeo-god-mode' ), '#/content-health' ),
			);
		}

		// Plugin conflicts that are real errors (warnings are already handled).
		foreach ( self::conflict_errors() as $conflict ) {
			$candidates[] = array(
				'id'       => 'conflict-' . sanitize_key( $conflict['id'] ),
				'kind'     => 'conflict',
				'tier'     => 5,
				'label'    => __( 'Tidy-up', 'aeo-god-mode' ),
				'score'    => 350,
				'group'    => 'conflict-' . sanitize_key( $conflict['id'] ),
				'title'    => wp_strip_all_tags( (string) $conflict['title'] ),
				'reason'   => wp_strip_all_tags( (string) ( $conflict['description'] ?? '' ) ),
				'evidence' => array(),
				'sources'  => array( __( 'Conflicts', 'aeo-god-mode' ) ),
				'page'     => null,
				'action'   => self::link_action( __( 'Sort it out', 'aeo-god-mode' ), '#/conflicts' ),
			);
		}

		// Drop snoozed fixes, then rank.
		$dismissed  = self::dismissed();
		$candidates = array_values( array_filter( $candidates, static function ( $c ) use ( $dismissed ) {
			return ! isset( $dismissed[ $c['id'] ] );
		} ) );
		usort( $candidates, static function ( $a, $b ) {
			return $b['score'] <=> $a['score'];
		} );

		// One fix per page per problem on screen. More lost questions on the
		// same page are counted into the one shown, not listed beside it.
		$top   = array();
		$seen  = array();
		$extra = array();
		foreach ( $candidates as $c ) {
			if ( isset( $seen[ $c['group'] ] ) ) {
				$extra[ $c['group'] ] = ( $extra[ $c['group'] ] ?? 0 ) + 1;
				continue;
			}
			$seen[ $c['group'] ] = true;
			$top[]               = $c;
		}
		$total = count( $top );
		$top   = array_slice( $top, 0, self::SHOW );
		foreach ( $top as &$fix ) {
			if ( ! empty( $extra[ $fix['group'] ] ) && 'lost_question' === $fix['kind'] ) {
				$fix['evidence'][] = sprintf(
					/* translators: %d: number of other questions lost on the same page. */
					_n( '%d more question on this page is being answered without you.', '%d more questions on this page are being answered without you.', $extra[ $fix['group'] ], 'aeo-god-mode' ),
					$extra[ $fix['group'] ]
				);
			}
			unset( $fix['group'] );
		}
		unset( $fix );

		return array(
			'fixes'        => $top,
			'total_found'  => $total,
			'is_pro'       => (bool) $is_pro,
			'sources'      => $sources,
			'generated_at' => gmdate( 'c' ),
		);
	}

	// ---------------------------------------------------------------------
	// Engine readers. Each one reads stored results only and fails soft.
	// ---------------------------------------------------------------------

	/** @return array<int,array> Answer Density weakest rows keyed by post id. */
	private static function answer_density_rows() {
		if ( ! class_exists( __NAMESPACE__ . '\\Answer_Density' ) ) {
			return array();
		}
		$summary = Answer_Density::get_summary();
		$rows    = array();
		foreach ( (array) ( $summary['weakest'] ?? array() ) as $row ) {
			$pid = (int) ( $row['post_id'] ?? 0 );
			if ( ! $pid || 'publish' !== get_post_status( $pid ) ) {
				continue;
			}
			$row['title']    = get_the_title( $pid );
			$row['edit_url'] = (string) get_edit_post_link( $pid, 'raw' );
			$row['view_url'] = (string) get_permalink( $pid );
			$rows[ $pid ]    = $row;
		}
		return $rows;
	}

	/** @return array<int,array> Citability results keyed by post id. */
	private static function citability_rows() {
		if ( ! class_exists( __NAMESPACE__ . '\\CitabilityScore' ) ) {
			return array();
		}
		$cached = ( new CitabilityScore() )->get_cached();
		$rows   = array();
		foreach ( (array) ( $cached['results'] ?? array() ) as $row ) {
			$pid = (int) ( $row['post_id'] ?? 0 );
			if ( $pid && empty( $row['provisional'] ) && 'publish' === get_post_status( $pid ) ) {
				$rows[ $pid ] = $row;
			}
		}
		return $rows;
	}

	/** @return array<int,array> Content Gaps rows keyed by post id. */
	private static function content_gap_rows() {
		$rows = get_option( 'asgm_content_gap_action_results', array() );
		$out  = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$pid = (int) ( $row['post_id'] ?? 0 );
			if ( $pid ) {
				$out[ $pid ] = $row;
			}
		}
		return $out;
	}

	/**
	 * Lost Citation Tracker questions, one entry per page and question with
	 * every engine that answered without the site.
	 *
	 * @return array<int,array{post_id:int,title:string,url:string,path:string,query:string,engines:string[]}>
	 */
	private static function lost_questions() {
		if ( ! class_exists( __NAMESPACE__ . '\\CitationTracker' ) || ! self::is_pro() ) {
			return array();
		}
		$reports = ( new CitationTracker() )->get_page_reports();
		$out     = array();
		foreach ( (array) ( $reports['pages'] ?? array() ) as $page ) {
			$by_query = array();
			foreach ( (array) ( $page['lost'] ?? array() ) as $lost ) {
				$q = trim( (string) ( $lost['query'] ?? '' ) );
				if ( '' === $q ) {
					continue;
				}
				$k = strtolower( $q );
				if ( ! isset( $by_query[ $k ] ) ) {
					$by_query[ $k ] = array( 'query' => $q, 'engines' => array() );
				}
				$engine = self::engine_name( (string) ( $lost['engine'] ?? '' ) );
				if ( $engine && ! in_array( $engine, $by_query[ $k ]['engines'], true ) ) {
					$by_query[ $k ]['engines'][] = $engine;
				}
			}
			foreach ( $by_query as $item ) {
				$out[] = array(
					'post_id' => (int) ( $page['post_id'] ?? 0 ),
					'title'   => (string) ( $page['title'] ?? '' ),
					'url'     => (string) ( $page['url'] ?? '' ),
					'path'    => (string) ( $page['path'] ?? self::path_of( (string) ( $page['url'] ?? '' ) ) ),
					'query'   => $item['query'],
					'engines' => $item['engines'] ? $item['engines'] : array( __( 'AI search', 'aeo-god-mode' ) ),
				);
			}
		}
		return $out;
	}

	/**
	 * Real AI referral visits in the last 30 days, by path (Pro).
	 *
	 * Read straight from the table: instantiating AIReferrals hooks the front
	 * end and checks the table, which a read here does not need.
	 *
	 * @return array<string,array{visits:int,sources:string[]}>
	 */
	private static function referral_visits() {
		if ( ! class_exists( __NAMESPACE__ . '\\AIReferrals' ) ) {
			return array();
		}
		global $wpdb;
		$table = $wpdb->prefix . 'asgm_ai_referrals';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return array();
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT landing_url, source, COUNT(*) AS visits FROM %i WHERE landing_url NOT LIKE %s AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) GROUP BY landing_url, source",
			$table,
			'%aeotest%'
		), ARRAY_A );
		return self::fold_by_path( (array) $rows, 'landing_url', 'source' );
	}

	/**
	 * Fetches by assistants acting for a person (ChatGPT-User and friends) in
	 * the last 30 days, by path. Free has no referral tracking, and a user
	 * fetch means a real person asked an assistant about this page.
	 *
	 * @return array<string,array{visits:int,sources:string[]}>
	 */
	private static function user_fetches() {
		global $wpdb;
		$table = $wpdb->prefix . 'asgm_crawler_log';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return array();
		}
		$bots = self::USER_FETCHERS;
		$in   = implode( ', ', array_fill( 0, count( $bots ), '%s' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQLPlaceholders
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT url, bot_name, COUNT(*) AS visits FROM %i WHERE bot_name IN ($in) AND url != '/robots.txt' AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) GROUP BY url, bot_name",
			array_merge( array( $table ), $bots )
		), ARRAY_A );
		return self::fold_by_path( (array) $rows, 'url', 'bot_name' );
	}

	private static function fold_by_path( array $rows, $url_key, $source_key ) {
		$out = array();
		foreach ( $rows as $row ) {
			$path = self::path_of( (string) ( $row[ $url_key ] ?? '' ) );
			if ( '' === $path ) {
				continue;
			}
			if ( ! isset( $out[ $path ] ) ) {
				$out[ $path ] = array( 'visits' => 0, 'sources' => array() );
			}
			$out[ $path ]['visits'] += (int) ( $row['visits'] ?? 0 );
			$src = (string) ( $row[ $source_key ] ?? '' );
			if ( $src && ! in_array( $src, $out[ $path ]['sources'], true ) ) {
				$out[ $path ]['sources'][] = $src;
			}
		}
		return $out;
	}

	/**
	 * People who reached a path from AI: referral visits when Pro tracks
	 * them, otherwise assistant fetches made for a person.
	 */
	private static function traffic_for( $path, array $visits, array $fetches ) {
		$path = self::path_of( (string) $path );
		if ( isset( $visits[ $path ] ) && $visits[ $path ]['visits'] > 0 ) {
			return array( 'visits' => $visits[ $path ]['visits'], 'sources' => $visits[ $path ]['sources'], 'kind' => 'referral' );
		}
		if ( isset( $fetches[ $path ] ) && $fetches[ $path ]['visits'] > 0 ) {
			return array( 'visits' => $fetches[ $path ]['visits'], 'sources' => $fetches[ $path ]['sources'], 'kind' => 'fetch' );
		}
		return array( 'visits' => 0, 'sources' => array(), 'kind' => '' );
	}

	private static function visits_line( array $traffic ) {
		$names = array_map( array( __CLASS__, 'engine_name' ), $traffic['sources'] );
		$names = array_values( array_unique( array_filter( $names ) ) );
		if ( 'fetch' === $traffic['kind'] ) {
			return sprintf(
				/* translators: 1: number of times, 2: list of assistants. */
				_n( 'Opened %1$d time by %2$s for someone asking about it in the last 30 days.', 'Opened %1$d times by %2$s for people asking about it in the last 30 days.', $traffic['visits'], 'aeo-god-mode' ),
				$traffic['visits'],
				self::join_list( $names )
			);
		}
		return sprintf(
			/* translators: 1: number of visits, 2: list of assistants. */
			_n( '%1$d visit from %2$s in the last 30 days.', '%1$d visits from %2$s in the last 30 days.', $traffic['visits'], 'aeo-god-mode' ),
			$traffic['visits'],
			self::join_list( $names )
		);
	}

	/** Search-group crawlers that are blocked and that the owner can allow. */
	private static function blocked_search_bots( array $matrix ) {
		$out = array();
		foreach ( (array) ( $matrix['groups'] ?? array() ) as $group ) {
			if ( 'search' !== ( $group['key'] ?? '' ) ) {
				continue;
			}
			foreach ( (array) ( $group['bots'] ?? array() ) as $bot ) {
				if ( empty( $bot['allowed'] ) && empty( $bot['protected'] ) && empty( $bot['deprecated'] ) ) {
					$out[] = $bot;
				}
			}
		}
		return $out;
	}

	/** @return array{urls:int,pages:int,internal:int} */
	private static function broken_links() {
		$out = array( 'urls' => 0, 'pages' => 0, 'internal' => 0 );
		if ( ! class_exists( __NAMESPACE__ . '\\Link_Health' ) ) {
			return $out;
		}
		$state   = Link_Health::get_state();
		$ignored = (array) ( $state['ignored'] ?? array() );
		$urls    = array();
		$pages   = array();
		foreach ( (array) ( $state['broken'] ?? array() ) as $row ) {
			$url = (string) ( $row['url'] ?? '' );
			if ( '' === $url || in_array( md5( $url ), $ignored, true ) ) {
				continue;
			}
			$urls[ $url ] = ! empty( $row['internal'] );
			if ( ! empty( $row['post_id'] ) ) {
				$pages[ (int) $row['post_id'] ] = true;
			}
		}
		$out['urls']     = count( $urls );
		$out['pages']    = count( $pages );
		$out['internal'] = count( array_filter( $urls ) );
		return $out;
	}

	/** The Content Health "problem" group touching the most pages. */
	private static function content_health_group() {
		if ( ! class_exists( __NAMESPACE__ . '\\Content_Health' ) ) {
			return null;
		}
		$state = Content_Health::public_state();
		$best  = null;
		foreach ( (array) ( $state['groups'] ?? array() ) as $group ) {
			if ( 'problem' !== ( $group['severity'] ?? '' ) || (int) ( $group['count'] ?? 0 ) < 1 ) {
				continue;
			}
			if ( ! $best || (int) $group['count'] > (int) $best['count'] ) {
				$best = $group;
			}
		}
		return $best;
	}

	private static function conflict_errors() {
		if ( ! class_exists( __NAMESPACE__ . '\\Conflict' ) ) {
			return array();
		}
		$scan = ( new Conflict() )->scan();
		return array_values( array_filter( (array) ( $scan['conflicts'] ?? array() ), static function ( $c ) {
			return 'error' === ( $c['severity'] ?? '' );
		} ) );
	}

	// ---------------------------------------------------------------------
	// Helpers
	// ---------------------------------------------------------------------

	/**
	 * Does this question sound like someone choosing who to buy from?
	 *
	 * The Citation Tracker stores no intent, so this reads the wording. It is
	 * deliberately generous: missing a buyer question costs more than ranking
	 * one research question a little too high.
	 */
	public static function is_commercial( $query ) {
		$q = strtolower( (string) $query );
		$patterns = array(
			'\bbest\b', '\btop\s+\d*', '\bvs\.?\b', '\bversus\b', '\balternatives?\b', '\bcompar(e|ed|ison)\b',
			'\bprices?\b', '\bpricing\b', '\bcosts?\b', '\bhow much\b', '\bcheap(est)?\b', '\baffordable\b', '\bfees?\b',
			'\bbuy\b', '\bpurchase\b', '\bhire\b', '\bnear me\b', '\breviews?\b', '\brecommend(ed|ation)?s?\b',
			'\bworth it\b', '\bquotes?\b', '\bdeals?\b', '\bdiscount\b', '\bwho (should|can) i\b', '\bwhich\b.*\b(should|is better)\b',
			'\b(company|companies|agency|agencies|provider|providers|supplier|suppliers|contractor|contractors|service|services|firm|firms|consultant|consultants)\b',
			'\b(plugin|plugins|tool|tools|software|app|apps|platform|platforms)\b',
			'\bfor (small )?business(es)?\b',
		);
		foreach ( $patterns as $p ) {
			if ( preg_match( '/' . $p . '/', $q ) ) {
				return true;
			}
		}
		return false;
	}

	private static function engine_name( $engine ) {
		$map = array(
			'chatgpt'              => 'ChatGPT',
			'openai'               => 'ChatGPT',
			'chatgpt-user'         => 'ChatGPT',
			'perplexity'           => 'Perplexity',
			'perplexity-user'      => 'Perplexity',
			'claude'               => 'Claude',
			'claude-user'          => 'Claude',
			'anthropic'            => 'Claude',
			'gemini'               => 'Gemini',
			'google'               => 'Gemini',
			'google_ai'            => 'Google AI',
			'copilot'              => 'Copilot',
			'mistralai-user'       => 'Mistral',
			'duckassistbot'        => 'DuckDuckGo',
			'meta-externalfetcher' => 'Meta AI',
		);
		$k = strtolower( trim( (string) $engine ) );
		return $map[ $k ] ?? trim( (string) $engine );
	}

	private static function join_list( array $items ) {
		$items = array_values( array_filter( array_map( 'strval', $items ) ) );
		$n     = count( $items );
		if ( 0 === $n ) {
			return '';
		}
		if ( 1 === $n ) {
			return $items[0];
		}
		$last = array_pop( $items );
		return sprintf(
			/* translators: 1: comma separated items, 2: the last item. */
			__( '%1$s and %2$s', 'aeo-god-mode' ),
			implode( ', ', $items ),
			$last
		);
	}

	private static function trim_words( $text, $words ) {
		return wp_trim_words( wp_strip_all_tags( (string) $text ), $words, '...' );
	}

	/** Normalise a URL or path to "/path/" with no query string. */
	private static function path_of( $url ) {
		$url = trim( (string) $url );
		if ( '' === $url ) {
			return '';
		}
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		$home = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		if ( $home && '/' !== $home && 0 === strpos( $path, rtrim( $home, '/' ) ) ) {
			$path = substr( $path, strlen( rtrim( $home, '/' ) ) );
		}
		$path = '/' . trim( $path, '/' );
		return '/' === $path ? '/' : $path . '/';
	}

	private static function page( $post_id, $title, $url ) {
		$post_id = (int) $post_id;
		return array(
			'post_id'  => $post_id,
			'title'    => wp_strip_all_tags( (string) ( $title ? $title : ( $post_id ? get_the_title( $post_id ) : '' ) ) ),
			'url'      => (string) ( $url ? $url : ( $post_id ? get_permalink( $post_id ) : '' ) ),
			'edit_url' => $post_id ? (string) get_edit_post_link( $post_id, 'raw' ) : '',
		);
	}

	private static function link_action( $label, $route ) {
		return array(
			'type'     => 'route',
			'label'    => $label,
			'pro_only' => false,
			'route'    => $route,
		);
	}

	private static function dismissed() {
		$raw = get_option( self::DISMISS_OPT, array() );
		$now = time();
		$out = array();
		foreach ( is_array( $raw ) ? $raw : array() as $id => $until ) {
			if ( (int) $until > $now ) {
				$out[ sanitize_key( (string) $id ) ] = (int) $until;
			}
		}
		return $out;
	}

	private static function is_pro() {
		return class_exists( __NAMESPACE__ . '\\License' ) && License::is_pro();
	}
}
