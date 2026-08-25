<?php
/**
 * Optional Free account connection.
 *
 * This is deliberately independent of the paid add-on. It stores an opaque
 * installation token that is accepted only by Free AI-credit endpoints.
 *
 * @package AISEOGodMode
 */

namespace AISEOGodMode;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Account_Connection {

	const INSTALL_ID_OPTION = 'asgm_account_install_id';
	const TOKEN_OPTION      = 'asgm_account_installation_token';
	const EMAIL_OPTION      = 'asgm_account_email';
	const SERVER_URL        = 'https://aeogodmode.io';

	/** Register the local admin API and browser callback. */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_action( 'admin_post_asgm_account_callback', array( __CLASS__, 'handle_callback' ) );
	}

	/** Register routes used only by the authenticated WordPress administrator. */
	public static function register_routes() {
		$permission = function () {
			return current_user_can( 'manage_options' );
		};

		register_rest_route( 'aeo-god-mode/v1', '/account/status', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'status' ),
			'permission_callback' => $permission,
		) );
		register_rest_route( 'aeo-god-mode/v1', '/account/connect', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'start' ),
			'permission_callback' => $permission,
		) );
		register_rest_route( 'aeo-god-mode/v1', '/account/disconnect', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'disconnect' ),
			'permission_callback' => $permission,
		) );
	}

	/** Persistent UUID for this WordPress installation. */
	public static function get_install_id() {
		$id = (string) get_option( self::INSTALL_ID_OPTION, '' );
		if ( ! wp_is_uuid( $id, 4 ) ) {
			$id = wp_generate_uuid4();
			update_option( self::INSTALL_ID_OPTION, $id, false );
		}
		return $id;
	}

	/** Token sent only in server-to-server request bodies. */
	public static function get_token() {
		return (string) get_option( self::TOKEN_OPTION, '' );
	}

	/** Begin PKCE and return the dedicated hosted account-connect URL. */
	public static function start() {
		$verifier  = self::base64url( random_bytes( 48 ) );
		$challenge = self::base64url( hash( 'sha256', $verifier, true ) );
		$state     = self::base64url( random_bytes( 24 ) );
		$callback  = admin_url( 'admin-post.php?action=asgm_account_callback' );

		set_transient( 'asgm_account_flow_' . hash( 'sha256', $state ), array(
			'verifier'  => $verifier,
			'user_id'   => get_current_user_id(),
			'created_at' => time(),
		), 10 * MINUTE_IN_SECONDS );

		$url = add_query_arg( array(
			'site'       => home_url( '/' ),
			'return'     => $callback,
			'state'      => $state,
			'challenge'  => $challenge,
			'install_id' => self::get_install_id(),
		), self::SERVER_URL . '/connect/' );

		return rest_ensure_response( array( 'success' => true, 'url' => $url ) );
	}

	/** Complete the server-to-server exchange after hosted consent. */
	public static function handle_callback() {
		if ( ! current_user_can( 'manage_options' ) ) {
			auth_redirect();
		}

		$code  = isset( $_GET['asgm_code'] ) ? sanitize_text_field( wp_unslash( $_GET['asgm_code'] ) ) : '';
		$state = isset( $_GET['asgm_state'] ) ? sanitize_text_field( wp_unslash( $_GET['asgm_state'] ) ) : '';
		$flow  = get_transient( 'asgm_account_flow_' . hash( 'sha256', $state ) );

		if ( '' === $code || '' === $state || ! is_array( $flow )
			|| (int) $flow['user_id'] !== get_current_user_id()
			|| time() - (int) $flow['created_at'] > 10 * MINUTE_IN_SECONDS ) {
			self::render_result( false, 'That connection has expired. Close this window and start again from the plugin.' );
		}

		$response = wp_remote_post( self::SERVER_URL . '/wp-json/asgm/v1/connect/exchange', array(
			'timeout' => 15,
			'headers' => array( 'Content-Type' => 'application/json' ),
			'body'    => wp_json_encode( array(
				'code'       => $code,
				'verifier'   => (string) $flow['verifier'],
				'install_id' => self::get_install_id(),
				'site_url'   => home_url( '/' ),
			) ),
		) );

		if ( is_wp_error( $response ) ) {
			self::render_result( false, 'AEO God Mode could not finish connecting. Nothing was changed. Close this window and try again.' );
		}

		$body  = json_decode( wp_remote_retrieve_body( $response ), true );
		$token = is_array( $body ) ? (string) ( $body['installation_token'] ?? '' ) : '';
		if ( 200 !== wp_remote_retrieve_response_code( $response ) || 0 !== strpos( $token, 'aegmi.v1.' ) ) {
			$message = is_array( $body ) && ! empty( $body['error'] ) ? (string) $body['error'] : 'AEO God Mode could not finish connecting. Close this window and try again.';
			self::render_result( false, $message );
		}

		delete_transient( 'asgm_account_flow_' . hash( 'sha256', $state ) );
		update_option( self::TOKEN_OPTION, $token, false );
		update_option( self::EMAIL_OPTION, sanitize_email( (string) ( $body['account_email'] ?? '' ) ), false );

		self::render_result( true, 'Your free AEO God Mode account is connected.', sanitize_email( (string) ( $body['account_email'] ?? '' ) ) );
	}

	/** Return locally cached state, confirmed against the account server. */
	public static function status() {
		$token = self::get_token();
		if ( '' === $token ) {
			return rest_ensure_response( array( 'connected' => false ) );
		}

		$response = wp_remote_post( self::SERVER_URL . '/wp-json/asgm/v1/connect/status', array(
			'timeout' => 8,
			'headers' => array( 'Content-Type' => 'application/json' ),
			'body'    => wp_json_encode( array(
				'installation_token' => $token,
				'install_id'          => self::get_install_id(),
			) ),
		) );

		if ( is_wp_error( $response ) ) {
			return rest_ensure_response( array(
				'connected'     => true,
				'verified'      => false,
				'account_email' => (string) get_option( self::EMAIL_OPTION, '' ),
			) );
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) || empty( $body['connected'] ) ) {
			delete_option( self::TOKEN_OPTION );
			delete_option( self::EMAIL_OPTION );
			return rest_ensure_response( array( 'connected' => false ) );
		}

		$body['verified'] = true;
		return rest_ensure_response( $body );
	}

	/** Credit payload for Free UI components, or null when unconnected. */
	public static function get_credit_status() {
		if ( '' === self::get_token() ) {
			return null;
		}

		$response = self::status();
		$data     = $response instanceof \WP_REST_Response ? $response->get_data() : null;
		if ( ! is_array( $data ) || empty( $data['connected'] ) || empty( $data['credits'] ) ) {
			return null;
		}

		return array_merge( array( 'success' => true ), (array) $data['credits'] );
	}

	/** Revoke remotely before removing the local bearer credential. */
	public static function disconnect() {
		$token = self::get_token();
		if ( '' === $token ) {
			return rest_ensure_response( array( 'success' => true ) );
		}

		$response = wp_remote_post( self::SERVER_URL . '/wp-json/asgm/v1/connect/disconnect', array(
			'timeout' => 10,
			'headers' => array( 'Content-Type' => 'application/json' ),
			'body'    => wp_json_encode( array(
				'installation_token' => $token,
				'install_id'          => self::get_install_id(),
			) ),
		) );

		$body = is_wp_error( $response ) ? null : json_decode( wp_remote_retrieve_body( $response ), true );
		if ( is_wp_error( $response ) || ! is_array( $body ) || empty( $body['success'] ) ) {
			return new \WP_Error( 'asgm_disconnect_failed', 'Could not disconnect safely. Please try again.', array( 'status' => 502 ) );
		}

		delete_option( self::TOKEN_OPTION );
		delete_option( self::EMAIL_OPTION );
		return rest_ensure_response( array( 'success' => true ) );
	}

	/** Render a self-contained, cache-safe popup result. */
	private static function render_result( $success, $message, $email = '' ) {
		nocache_headers();
		status_header( $success ? 200 : 400 );
		header( 'Content-Type: text/html; charset=utf-8' );
		header( "Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; script-src 'unsafe-inline'; base-uri 'none'; frame-ancestors 'none'" );
		$site = wp_parse_url( home_url( '/' ), PHP_URL_HOST );
		$admin_parts  = wp_parse_url( admin_url() );
		$admin_origin = ( $admin_parts['scheme'] ?? 'https' ) . '://' . ( $admin_parts['host'] ?? $site );
		if ( ! empty( $admin_parts['port'] ) ) {
			$admin_origin .= ':' . (int) $admin_parts['port'];
		}
		?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?php echo $success ? 'AEO God Mode connected' : 'Connection incomplete'; ?></title>
<style>
*{box-sizing:border-box}body{margin:0;background:#fff;color:#0d1629;font:18px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;min-height:100vh;display:grid;place-items:center;padding:24px}.card{position:relative;overflow:hidden;width:min(620px,100%);border:1px solid #d5deeb;border-radius:28px;padding:48px;text-align:center;box-shadow:0 24px 70px rgba(13,22,41,.12)}.mark{width:92px;height:92px;border-radius:50%;margin:0 auto 24px;display:grid;place-items:center;background:<?php echo $success ? '#ffd52a' : '#fff2e2'; ?>;color:#111;font-size:52px;font-weight:900}.brand{font-weight:900;letter-spacing:-.04em}.brand i{color:#2684ff;font-style:normal}h1{font-size:38px;line-height:1.1;margin:16px 0 12px}p{color:#5d6c84;margin:8px 0}.site{margin:24px 0;padding:16px;border-radius:14px;background:#f4f7fb;font-weight:700;color:#16213a}.button{margin-top:24px;display:inline-flex;align-items:center;justify-content:center;border:0;border-radius:14px;background:#111;color:#fff;padding:15px 26px;font-weight:800;font-size:17px;cursor:pointer}.confetti{position:absolute;inset:0;pointer-events:none;background-image:radial-gradient(circle,#ffd52a 0 4px,transparent 5px),radial-gradient(circle,#2684ff 0 3px,transparent 4px),radial-gradient(circle,#111 0 3px,transparent 4px);background-size:83px 91px,107px 79px,97px 113px;opacity:.45;animation:fall 7s linear infinite}@keyframes fall{to{background-position:0 180px,0 140px,0 220px}}@media(prefers-reduced-motion:reduce){.confetti{animation:none}}@media(max-width:520px){.card{padding:34px 22px}h1{font-size:30px}}
</style></head><body><main class="card"><?php if ( $success ) : ?><div class="confetti"></div><?php endif; ?><div class="brand">AEO <i>⚡</i> GOD MODE</div><div class="mark" aria-hidden="true"><?php echo $success ? '✓' : '!'; ?></div><h1><?php echo esc_html( $success ? 'You’re connected!' : 'Connection incomplete' ); ?></h1><p><?php echo esc_html( $message ); ?></p><?php if ( $success ) : ?><div class="site"><?php echo esc_html( $site ); ?><?php echo $email ? ' · ' . esc_html( $email ) : ''; ?><br>20 AI credits every month</div><?php endif; ?><button class="button" onclick="window.opener&&window.opener.postMessage('asgm-account-connected','<?php echo esc_js( $admin_origin ); ?>');window.close()">Return to WordPress</button></main></body></html>
		<?php
		exit;
	}

	private static function base64url( $bytes ) {
		return rtrim( strtr( base64_encode( $bytes ), '+/', '-_' ), '=' );
	}
}
