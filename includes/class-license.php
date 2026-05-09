<?php
/**
 * EDD Software Licensing integration.
 *
 * Handles Pro license activation, deactivation, status checks,
 * and transient-cached validation against aeogodmode.io.
 *
 * @package AISEOGodMode
 */

namespace AISEOGodMode;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * License manager for EDD Software Licensing.
 */
class License {

    /**
     * EDD store URL.
     */
    const STORE_URL = 'https://aeogodmode.io';

    /**
     * EDD product name.
     */
    const ITEM_NAME = 'AEO God Mode Pro';

    /**
     * wp_options key for the raw license key.
     */
    const OPTION_KEY = 'agm_license_key';

    /**
     * Transient key for cached license data.
     */
    const TRANSIENT = 'agm_license_data';

    /**
     * How long to cache license data (in seconds). 24 hours.
     */
    const CACHE_TTL = 86400;

    /**
     * Pro feature module keys.
     *
     * @var array
     */
    private static $pro_features = array(
        'citation_tracker',
        'ai_referrals',
        'citability_score',
        'eeat',
        'gsc',
    );

    // ──────────────────────────────────────────────
    //  Static checks
    // ──────────────────────────────────────────────

    /**
     * Check if Pro is active.
     *
     * Reads the cached transient first. If the transient is gone and
     * a stored key exists, fires a fresh check. On network failure the
     * method fails open so paying customers are never locked out.
     *
     * @return bool
     */
    public static function is_pro() {
        // Free build guard: if the pro/ directory doesn't exist, Pro is never active.
        if ( ! self::is_pro_build() ) {
            return false;
        }

        $data = get_transient( self::TRANSIENT );

        // Transient exists and license is valid.
        if ( is_array( $data ) && ! empty( $data['license'] ) && 'valid' === $data['license'] ) {
            return true;
        }

        // Transient expired or missing — try a fresh check if we have a key.
        $key = get_option( self::OPTION_KEY, '' );
        if ( empty( $key ) ) {
            return false;
        }

        // If no transient but we have a key, do a live check.
        if ( false === $data ) {
            $fresh = self::remote_check( $key );

            if ( is_array( $fresh ) && 'valid' === ( $fresh['license'] ?? '' ) ) {
                set_transient( self::TRANSIENT, $fresh, self::CACHE_TTL );
                return true;
            }

            // Network error or unexpected response — fail open.
            if ( null === $fresh ) {
                set_transient( self::TRANSIENT, array( 'license' => 'valid', 'fail_open' => true ), self::CACHE_TTL );
                return true;
            }

            // Key is genuinely invalid / expired / limit reached.
            set_transient( self::TRANSIENT, $fresh, self::CACHE_TTL );
            return false;
        }

        return false;
    }

    /**
     * Check if a feature requires Pro.
     *
     * @param string $feature Feature key.
     * @return bool
     */
    public static function is_pro_feature( $feature ) {
        return in_array( $feature, self::$pro_features, true );
    }

    // ──────────────────────────────────────────────
    //  Actions
    // ──────────────────────────────────────────────

    /**
     * Activate a license key against EDD.
     *
     * @param string $key License key.
     * @return array Result with success boolean and message.
     */
    public function activate( $key ) {
        $key = sanitize_text_field( trim( $key ) );

        if ( empty( $key ) ) {
            return array(
                'success' => false,
                'message' => __( 'Please enter a license key.', 'aeo-god-mode' ),
            );
        }

        // Free build guard: reject license activation when Pro files are not present.
        if ( ! self::is_pro_build() ) {
            return array(
                'success' => false,
                'message' => __( 'This is the free version of AEO God Mode. To use a Pro license key, please download the Pro plugin from your account at aeogodmode.io/my-account/ and install it instead.', 'aeo-god-mode' ),
            );
        }

        $response = wp_remote_post( self::STORE_URL, array(
            'timeout'   => 15,
            'sslverify' => true,
            'body'      => array(
                'edd_action' => 'activate_license',
                'license'    => $key,
                'item_name'  => rawurlencode( self::ITEM_NAME ),
                'url'        => home_url(),
            ),
        ) );

        // Network failure.
        if ( is_wp_error( $response ) ) {
            // Store the key and fail open.
            update_option( self::OPTION_KEY, $key );
            set_transient( self::TRANSIENT, array(
                'license'   => 'valid',
                'fail_open' => true,
            ), self::CACHE_TTL );

            return array(
                'success' => true,
                'message' => __( 'License saved. Could not reach the license server right now, so Pro features are enabled with a 24-hour grace period.', 'aeo-god-mode' ),
                'status'  => $this->get_status(),
            );
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( ! is_array( $body ) ) {
            return array(
                'success' => false,
                'message' => __( 'Invalid response from the license server.', 'aeo-god-mode' ),
            );
        }

        $license_status = $body['license'] ?? 'invalid';

        // Store the key regardless so deactivation can clean up.
        update_option( self::OPTION_KEY, $key );

        if ( 'valid' === $license_status ) {
            set_transient( self::TRANSIENT, $body, self::CACHE_TTL );
            return array(
                'success' => true,
                'message' => __( 'Pro license activated. All features unlocked.', 'aeo-god-mode' ),
                'status'  => $this->get_status(),
            );
        }

        // Handle specific EDD error codes.
        $message = $this->error_message( $body );
        set_transient( self::TRANSIENT, $body, self::CACHE_TTL );

        return array(
            'success' => false,
            'message' => $message,
            'status'  => $this->get_status(),
        );
    }

    /**
     * Deactivate the current license to free the activation slot.
     *
     * @return array
     */
    public function deactivate() {
        $key = get_option( self::OPTION_KEY, '' );

        if ( ! empty( $key ) ) {
            wp_remote_post( self::STORE_URL, array(
                'timeout'   => 15,
                'sslverify' => true,
                'body'      => array(
                    'edd_action' => 'deactivate_license',
                    'license'    => $key,
                    'item_name'  => rawurlencode( self::ITEM_NAME ),
                    'url'        => home_url(),
                ),
            ) );
        }

        delete_option( self::OPTION_KEY );
        delete_transient( self::TRANSIENT );

        return array(
            'success' => true,
            'message' => __( 'License deactivated. Pro features disabled.', 'aeo-god-mode' ),
            'status'  => $this->get_status(),
        );
    }

    // ──────────────────────────────────────────────
    //  Status & Plan
    // ──────────────────────────────────────────────

    /**
     * Get the full license status for the admin UI.
     *
     * Returns one of four states: active, expired, invalid, site_limit_reached.
     *
     * @return array
     */
    public function get_status() {
        $key  = get_option( self::OPTION_KEY, '' );
        $data = get_transient( self::TRANSIENT );

        if ( empty( $key ) || ! is_array( $data ) ) {
            return array(
                'state'       => 'inactive',
                'is_pro'      => false,
                'license_key' => '',
                'plan'        => 'free',
                'message'     => __( 'No license key entered.', 'aeo-god-mode' ),
                'action_url'  => self::STORE_URL . '/pricing',
                'action_text' => __( 'Get Pro', 'aeo-god-mode' ),
                'expires'     => '',
            );
        }

        $license = $data['license'] ?? 'invalid';

        $states = array(
            'valid'   => array(
                'state'       => 'active',
                'is_pro'      => true,
                'message'     => __( 'License active. All Pro features unlocked.', 'aeo-god-mode' ),
                'action_url'  => self::STORE_URL . '/account',
                'action_text' => __( 'Manage Account', 'aeo-god-mode' ),
            ),
            'expired' => array(
                'state'       => 'expired',
                'is_pro'      => false,
                'message'     => __( 'Your license has expired. Renew to keep Pro features.', 'aeo-god-mode' ),
                'action_url'  => self::STORE_URL . '/checkout/?edd_license_key=' . $key . '&download_id=0',
                'action_text' => __( 'Renew License', 'aeo-god-mode' ),
            ),
            'no_activations_left' => array(
                'state'       => 'site_limit_reached',
                'is_pro'      => false,
                'message'     => __( 'This license has reached its site activation limit. Deactivate a site or upgrade to Agency.', 'aeo-god-mode' ),
                'action_url'  => self::STORE_URL . '/account',
                'action_text' => __( 'Manage Sites', 'aeo-god-mode' ),
            ),
        );

        $info = $states[ $license ] ?? array(
            'state'       => 'invalid',
            'is_pro'      => false,
            'message'     => __( 'License key is not valid. Please check and try again.', 'aeo-god-mode' ),
            'action_url'  => self::STORE_URL . '/pricing',
            'action_text' => __( 'Get a License', 'aeo-god-mode' ),
        );

        $info['license_key'] = $this->mask_key( $key );
        $info['plan']        = $this->get_plan();
        $info['expires']     = $data['expires'] ?? '';

        return $info;
    }

    /**
     * Get the plan tier from EDD response.
     *
     * @return string 'pro', 'agency', or 'free'.
     */
    public function get_plan() {
        $data = get_transient( self::TRANSIENT );

        if ( ! is_array( $data ) || 'valid' !== ( $data['license'] ?? '' ) ) {
            return 'free';
        }

        $price_name = strtolower( $data['price_name'] ?? '' );

        if ( false !== strpos( $price_name, 'agency' ) ) {
            return 'agency';
        }

        return 'pro';
    }

    // ──────────────────────────────────────────────
    //  Upgrade prompt
    // ──────────────────────────────────────────────

    /**
     * Get upgrade prompt data for a Pro feature.
     *
     * @param string $feature_name Display name of the feature.
     * @param string $benefit      One-line benefit statement.
     * @return array
     */
    public static function upgrade_prompt( $feature_name, $benefit ) {
        return array(
            'feature'    => $feature_name,
            'benefit'    => $benefit,
            'cta_text'   => __( 'Upgrade to Pro', 'aeo-god-mode' ),
            'cta_url'    => self::STORE_URL . '/pricing',
        );
    }

    // ──────────────────────────────────────────────
    //  Internal helpers
    // ──────────────────────────────────────────────

    /**
     * Fire a check_license call to EDD.
     *
     * @param string $key License key.
     * @return array|null Array on success, null on network error.
     */
    private static function remote_check( $key ) {
        $response = wp_remote_post( self::STORE_URL, array(
            'timeout'   => 15,
            'sslverify' => true,
            'body'      => array(
                'edd_action' => 'check_license',
                'license'    => $key,
                'item_name'  => rawurlencode( self::ITEM_NAME ),
                'url'        => home_url(),
            ),
        ) );

        if ( is_wp_error( $response ) ) {
            return null;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        return is_array( $body ) ? $body : null;
    }

    /**
     * Build a user-friendly error message from an EDD response.
     *
     * @param array $body EDD response body.
     * @return string
     */
    private function error_message( $body ) {
        $error = $body['error'] ?? '';

        $messages = array(
            'expired'              => __( 'Your license has expired. Please renew.', 'aeo-god-mode' ),
            'disabled'             => __( 'This license has been disabled. Contact support.', 'aeo-god-mode' ),
            'missing'              => __( 'This license key was not found. Please check and try again.', 'aeo-god-mode' ),
            'invalid'              => __( 'This license key is not valid for this product.', 'aeo-god-mode' ),
            'site_inactive'        => __( 'This license is not active for this site. Try activating it.', 'aeo-god-mode' ),
            'item_name_mismatch'   => __( 'This license key does not match this product.', 'aeo-god-mode' ),
            'no_activations_left'  => __( 'This license has reached its site activation limit. Deactivate a site in your account or upgrade to Agency.', 'aeo-god-mode' ),
        );

        return $messages[ $error ] ?? __( 'License activation failed. Please check your key and try again.', 'aeo-god-mode' );
    }

    /**
     * Check if this is a Pro build (the pro/ directory exists).
     *
     * @return bool
     */
    public static function is_pro_build() {
        return is_dir( ASGM_PLUGIN_DIR . 'includes/pro' );
    }

    /**
     * Mask a license key for display.
     *
     * @param string $key Full key.
     * @return string
     */
    private function mask_key( $key ) {
        if ( strlen( $key ) < 8 ) {
            return '****';
        }
        return substr( $key, 0, 4 ) . str_repeat( '*', strlen( $key ) - 8 ) . substr( $key, -4 );
    }
}
