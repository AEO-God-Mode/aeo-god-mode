<?php
/**
 * Affiliate referral badge.
 *
 * Renders an opt-in "AI Search Optimized by AEO God Mode" badge on the site
 * front end, linking to aeogodmode.io via the site owner's affiliate referral
 * URL. OFF by default per WordPress.org Guideline 10 (credit links must be
 * opt-in). Self-contained: no external assets, no JS, no front-end cookies,
 * fail-closed.
 *
 * @package AISEOGodMode
 */

namespace AISEOGodMode;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AffiliateBadge module.
 */
class AffiliateBadge {

	const VENDOR_BASE   = 'https://aeogodmode.io/ref/';
	const DEFAULT_LABEL = 'AI Search Optimized by AEO God Mode';

	/** @var bool Badge already output this request (prevents double render). */
	private static $rendered = false;

	/** @var bool Inline CSS already printed this request. */
	private static $css_done = false;

	/**
	 * Hook the footer auto-inject and the manual shortcode.
	 */
	public function __construct() {
		add_action( 'wp_footer', array( $this, 'maybe_render_footer' ), 100 );
		add_shortcode( 'aeo_badge', array( $this, 'shortcode' ) );
	}

	/**
	 * Read + sanitize the affiliate settings subtree.
	 *
	 * @return array { id, enabled, style, label, placement }
	 */
	private function get_config() {
		$s   = get_option( 'asgm_settings', array() );
		$aff = ( isset( $s['affiliate'] ) && is_array( $s['affiliate'] ) ) ? $s['affiliate'] : array();
		$b   = ( isset( $aff['badge'] ) && is_array( $aff['badge'] ) ) ? $aff['badge'] : array();

		$id = isset( $aff['id'] ) ? preg_replace( '/[^A-Za-z0-9._\-]/', '', (string) $aff['id'] ) : '';

		$style = isset( $b['style'] ) ? (string) $b['style'] : 'light';
		if ( ! in_array( $style, array( 'light', 'dark', 'minimal' ), true ) ) {
			$style = 'light';
		}

		$placement = isset( $b['placement'] ) ? (string) $b['placement'] : 'footer';
		if ( ! in_array( $placement, array( 'footer', 'manual', 'off' ), true ) ) {
			$placement = 'footer';
		}

		$label = ( isset( $b['label'] ) && '' !== trim( (string) $b['label'] ) ) ? trim( (string) $b['label'] ) : self::DEFAULT_LABEL;

		return array(
			'id'        => $id,
			'enabled'   => ! empty( $b['enabled'] ),
			'style'     => $style,
			'label'     => $label,
			'placement' => $placement,
		);
	}

	/**
	 * wp_footer callback: auto-inject the badge when placement is "footer".
	 */
	public function maybe_render_footer() {
		if ( self::$rendered ) {
			return;
		}
		if ( is_admin() || is_feed() || is_preview() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}
		$c = $this->get_config();
		if ( ! $c['enabled'] || '' === $c['id'] || 'footer' !== $c['placement'] ) {
			return;
		}
		// build_html() escapes every dynamic part.
		echo '<div class="aeo-godmode-badge-wrap" style="text-align:center;padding:18px 12px;">' . $this->build_html( $c ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * [aeo_badge] shortcode for manual placement.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public function shortcode( $atts ) {
		if ( is_admin() ) {
			return '';
		}
		$c = $this->get_config();
		if ( ! $c['enabled'] || '' === $c['id'] || 'off' === $c['placement'] ) {
			return '';
		}
		$atts = shortcode_atts( array( 'style' => $c['style'] ), $atts, 'aeo_badge' );
		if ( in_array( $atts['style'], array( 'light', 'dark', 'minimal' ), true ) ) {
			$c['style'] = $atts['style'];
		}
		return $this->build_html( $c );
	}

	/**
	 * Build the badge anchor (with the inline CSS prepended on first output).
	 *
	 * @param array $c Config.
	 * @return string
	 */
	private function build_html( $c ) {
		self::$rendered = true;

		$url = add_query_arg(
			array(
				'campaign'   => 'badge',
				'utm_source' => 'badge',
				'utm_medium' => 'plugin',
			),
			self::VENDOR_BASE . rawurlencode( $c['id'] ) . '/'
		);

		$bolt = '<svg class="aeo-godmode-badge__mark" viewBox="0 0 24 24" width="14" height="14" fill="currentColor" aria-hidden="true" focusable="false"><path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/></svg>';

		/*
		 * rel is hardcoded "nofollow sponsored noopener" on purpose and is NOT
		 * exposed in the UI. AffiliateWP attributes commission from the click +
		 * its 45-day cookie, not from passed link equity, so nofollow costs zero
		 * commission. Thousands of identical dofollow badge links would read as a
		 * link scheme to Google and endanger aeogodmode.io's own rankings, which
		 * contradicts the product's anti-SEO-gaming brand. Do not make this
		 * configurable. See WordPress.org Plugin Guideline 12.
		 */
		$anchor = sprintf(
			'<a class="aeo-godmode-badge aeo-godmode-badge--%1$s" href="%2$s" target="_blank" rel="nofollow sponsored noopener">%3$s<span class="aeo-godmode-badge__text">%4$s</span><span class="screen-reader-text"> (%5$s)</span></a>',
			esc_attr( $c['style'] ),
			esc_url( $url ),
			$bolt,
			esc_html( $c['label'] ),
			esc_html__( 'opens in a new tab', 'aeo-god-mode' )
		);

		return $this->css() . $anchor;
	}

	/**
	 * Inline CSS, printed once per request.
	 *
	 * @return string
	 */
	private function css() {
		if ( self::$css_done ) {
			return '';
		}
		self::$css_done = true;

		$css = '.aeo-godmode-badge{display:inline-flex;align-items:center;gap:6px;padding:6px 10px;border-radius:6px;font-family:inherit;font-size:12px;font-weight:600;line-height:1;text-decoration:none;box-sizing:border-box;border:1px solid transparent;vertical-align:middle;transition:opacity .15s ease}'
			. '.aeo-godmode-badge .aeo-godmode-badge__mark{flex:0 0 auto}'
			. '.aeo-godmode-badge:hover{opacity:.85}'
			. '.aeo-godmode-badge:focus-visible{outline:2px solid #338dff;outline-offset:2px}'
			. '.aeo-godmode-badge--light{background:#fff;color:#1f2937;border-color:#e5e7eb}'
			. '.aeo-godmode-badge--light .aeo-godmode-badge__mark{color:#338dff}'
			. '.aeo-godmode-badge--dark{background:#0f172a;color:#f8fafc;border-color:#0f172a}'
			. '.aeo-godmode-badge--dark .aeo-godmode-badge__mark{color:#fff}'
			. '.aeo-godmode-badge--minimal{background:transparent;color:#6b7280;padding:4px 0;border:0}'
			. '.aeo-godmode-badge--minimal .aeo-godmode-badge__mark{color:#338dff}'
			. '.aeo-godmode-badge--minimal:hover{text-decoration:underline;opacity:1}';

		return '<style id="aeo-godmode-badge-css">' . $css . '</style>';
	}
}
