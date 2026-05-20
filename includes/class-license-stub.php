<?php
/**
 * License stub for the Free build.
 *
 * The real EDD-licensing License class lives in /includes/pro/class-license.php
 * and never ships to WordPress.org. When Pro is not installed (Free-only sites),
 * this stub class fulfils the same interface so the rest of the codebase does
 * not fatal when it calls \AISEOGodMode\License::is_pro() etc.
 *
 * Rules this file follows so it can ship to WordPress.org:
 *   - No activation logic.
 *   - No remote calls.
 *   - No "enter your key" UI.
 *   - No paid integration.
 *   - No reference to paid-tier identifiers in any form (including comments).
 *
 * If someone needs to add ANY of those behaviours, the file is being misused.
 * Put the work in /includes/pro/class-license.php instead.
 *
 * @package AISEOGodMode
 */

namespace AISEOGodMode;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( __NAMESPACE__ . '\\License' ) ) {

	/**
	 * Minimal Free-build stub. Always reports "not Pro" so Pro-gated code paths
	 * stay closed. Returns inert responses for any instance method other code
	 * might call.
	 */
	class License {

		/**
		 * Free is never Pro.
		 *
		 * @return bool
		 */
		public static function is_pro() {
			return false;
		}

		/**
		 * Free is never the Pro build by definition.
		 *
		 * @return bool
		 */
		public static function is_pro_build() {
			return false;
		}

		/**
		 * No stored key in Free. Returns empty string so callers can safely
		 * use the result as a Bearer token without doing extra checks.
		 *
		 * @return string
		 */
		public static function get_key() {
			return '';
		}

		/**
		 * Pro features are gated off in Free.
		 *
		 * @param string $feature Feature slug (unused in Free).
		 * @return bool
		 */
		public static function is_pro_feature( $feature ) {
			unset( $feature );
			return false;
		}

		/**
		 * No upgrade UI is rendered in Free. Returns an empty string so callers
		 * that echo this in admin notices simply render nothing.
		 *
		 * @param string $feature_name Feature label (unused).
		 * @param string $benefit      Benefit copy (unused).
		 * @return string
		 */
		public static function upgrade_prompt( $feature_name, $benefit ) {
			unset( $feature_name, $benefit );
			return '';
		}

		/**
		 * Activation is not supported in Free. The REST endpoint returns a
		 * neutral message rather than acknowledging that a Pro flow exists.
		 *
		 * @param string $key Submitted key (ignored).
		 * @return array
		 */
		public function activate( $key ) {
			unset( $key );
			return array(
				'success' => false,
				'message' => __( 'This action is not available in the current build.', 'aeo-god-mode' ),
			);
		}

		/**
		 * Deactivation is a no-op in Free.
		 *
		 * @return array
		 */
		public function deactivate() {
			return array(
				'success' => true,
				'message' => __( 'No action required.', 'aeo-god-mode' ),
			);
		}

		/**
		 * Status reflects a Free install. No license metadata exposed.
		 *
		 * @return array
		 */
		public function get_status() {
			return array(
				'status' => 'free',
				'plan'   => 'free',
			);
		}

		/**
		 * Plan label.
		 *
		 * @return string
		 */
		public function get_plan() {
			return 'free';
		}
	}
}
