<?php
/**
 * REST contracts for Earned Source Opportunities.
 *
 * The paid implementation is supplied at runtime by the Pro companion plugin.
 * Keeping the route contract in Free is intentional: Free owns the shared admin
 * application and every REST route used by Pro and Growth.
 *
 * @package AISEOGodMode
 */

namespace AISEOGodMode;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register and dispatch Earned Source Opportunities REST requests.
 */
class Earned_Source_API {

	/** REST namespace shared by the main API class. */
	const REST_NAMESPACE = 'aeo-god-mode/v1';

	/**
	 * Attach route registration once.
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Register the stable route contract consumed by the shared React app.
	 */
	public static function register_routes() {
		register_rest_route(
			self::REST_NAMESPACE,
			'/citations/earned-sources',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'workspace' ),
				'permission_callback' => array( __CLASS__, 'admin_permission' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/citations/earned-sources/pages',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'search_pages' ),
				'permission_callback' => array( __CLASS__, 'admin_permission' ),
				'args'                => array(
					'search' => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'query'  => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/citations/earned-sources/owned-page',
			array(
				'methods'             => 'PUT',
				'callback'            => array( __CLASS__, 'save_owned_page' ),
				'permission_callback' => array( __CLASS__, 'admin_permission' ),
				'args'                => array(
					'query'   => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'post_id' => array(
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
					'url'     => array(
						'type'              => 'string',
						'sanitize_callback' => 'esc_url_raw',
					),
					'locked'  => array(
						'type'              => 'boolean',
						'sanitize_callback' => 'rest_sanitize_boolean',
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/citations/earned-sources/topical-map',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'add_to_topical_map' ),
				'permission_callback' => array( __CLASS__, 'admin_permission' ),
				'args'                => array(
					'query' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'reject_match' => array(
						'type'              => 'boolean',
						'sanitize_callback' => 'rest_sanitize_boolean',
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/citations/earned-sources/draft',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'create_draft' ),
				'permission_callback' => array( __CLASS__, 'admin_permission' ),
				'args'                => array(
					'query' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'title' => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/citations/earned-sources/placement',
			array(
				'methods'             => 'PUT',
				'callback'            => array( __CLASS__, 'save_placement' ),
				'permission_callback' => array( __CLASS__, 'admin_permission' ),
				'args'                => array(
					'query'      => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'source_url' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'esc_url_raw',
					),
					'status'     => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_key',
					),
					'notes'      => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_textarea_field',
					),
				),
			)
		);
	}

	/** Require the same administrator capability as Citation Tracker. */
	public static function admin_permission() {
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
	 * Resolve the paid implementation without placing paid source in Free.
	 *
	 * @return Earned_Source_Opportunities|\WP_Error
	 */
	private static function service() {
		if ( ! class_exists( __NAMESPACE__ . '\\License' ) || ! License::is_pro() ) {
			return new \WP_Error(
				'earned_sources_requires_pro',
				__( 'Earned Source Opportunities is available with AEO God Mode Pro.', 'aeo-god-mode' ),
				array( 'status' => 403 )
			);
		}

		if ( ! class_exists( __NAMESPACE__ . '\\Earned_Source_Opportunities' ) ) {
			return new \WP_Error(
				'earned_sources_unavailable',
				__( 'Update both AEO God Mode and AEO God Mode Pro to use this workspace.', 'aeo-god-mode' ),
				array( 'status' => 409 )
			);
		}

		return Earned_Source_Opportunities::instance();
	}

	/** GET workspace. */
	public static function workspace() {
		$service = self::service();
		return is_wp_error( $service ) ? $service : rest_ensure_response( $service->get_workspace() );
	}

	/** GET candidate owned pages. */
	public static function search_pages( \WP_REST_Request $request ) {
		$service = self::service();
		if ( is_wp_error( $service ) ) {
			return $service;
		}
		return rest_ensure_response(
			$service->search_pages(
				(string) $request->get_param( 'search' ),
				(string) $request->get_param( 'query' )
			)
		);
	}

	/** PUT a manual owned-page selection, or restore automatic matching. */
	public static function save_owned_page( \WP_REST_Request $request ) {
		$service = self::service();
		if ( is_wp_error( $service ) ) {
			return $service;
		}
		$result = $service->save_owned_page(
			(string) $request->get_param( 'query' ),
			absint( $request->get_param( 'post_id' ) ),
			(string) $request->get_param( 'url' ),
			rest_sanitize_boolean( $request->get_param( 'locked' ) )
		);
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	/** POST a prefilled owned-answer draft. */
	public static function create_draft( \WP_REST_Request $request ) {
		$service = self::service();
		if ( is_wp_error( $service ) ) {
			return $service;
		}
		$result = $service->create_draft(
			(string) $request->get_param( 'query' ),
			(string) $request->get_param( 'title' )
		);
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	/** POST an exact tracked question into the durable Topical Map plan. */
	public static function add_to_topical_map( \WP_REST_Request $request ) {
		$service = self::service();
		if ( is_wp_error( $service ) ) {
			return $service;
		}
		$result = $service->add_to_topical_map(
			(string) $request->get_param( 'query' ),
			rest_sanitize_boolean( $request->get_param( 'reject_match' ) )
		);
		return is_wp_error( $result ) ? $result : rest_ensure_response( array_merge( array( 'success' => true ), $result ) );
	}

	/** PUT placement progress for one source opportunity. */
	public static function save_placement( \WP_REST_Request $request ) {
		$service = self::service();
		if ( is_wp_error( $service ) ) {
			return $service;
		}
		$result = $service->save_placement(
			(string) $request->get_param( 'query' ),
			(string) $request->get_param( 'source_url' ),
			(string) $request->get_param( 'status' ),
			(string) $request->get_param( 'notes' )
		);
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}
}
