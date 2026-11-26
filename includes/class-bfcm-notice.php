<?php
/**
 * Black Friday / Cyber Monday upgrade notice (Free plugin only).
 *
 * Shown to site admins who do not run Pro or Growth, only inside the sale
 * window, only on the Dashboard, the Plugins screen and our own screens, and
 * remembered per user once dismissed. Pure presentation: it touches no
 * licensing, no REST routes, no Pro classes, and runs no new queries (the
 * crawler line reads the dashboard widget's existing cache if present).
 *
 * @package AISEOGodMode
 */

namespace AISEOGodMode;

defined( 'ABSPATH' ) || exit;

class BFCM_Notice {

	const CODE     = 'BFCM50';
	const START    = '2026-11-22 00:00:00';
	const END      = '2026-12-01 00:00:00';
	const META_KEY = 'asgm_bfcm_2026_dismissed';
	const ACTION   = 'asgm_bfcm_dismiss';

	public static function init() {
		add_action( 'admin_notices', array( __CLASS__, 'render' ) );
		add_action( 'wp_ajax_' . self::ACTION, array( __CLASS__, 'ajax_dismiss' ) );
	}

	private static function in_window() {
		$now = time();
		return $now >= strtotime( self::START . ' UTC' ) && $now < strtotime( self::END . ' UTC' );
	}

	private static function days_left() {
		return (int) max( 1, ceil( ( strtotime( self::END . ' UTC' ) - time() ) / DAY_IN_SECONDS ) );
	}

	private static function has_paid_plan() {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			include_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		if ( is_plugin_active( 'aeo-god-mode-pro/aeo-god-mode-pro.php' ) ) {
			return true;
		}
		return (bool) get_option( 'agm_license_key', '' );
	}

	private static function on_allowed_screen() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen ) {
			return false;
		}
		return in_array( $screen->id, array( 'dashboard', 'plugins' ), true )
			|| false !== strpos( (string) $screen->id, 'aeo-god-mode' );
	}

	/** AI crawler visits in the last 30 days, from the dashboard widget cache only. */
	private static function crawler_visits() {
		$cached = get_transient( 'asgm_dw_crawl' );
		if ( is_array( $cached ) && ! empty( $cached['thirty'] ) ) {
			return (int) $cached['thirty'];
		}
		return 0;
	}

	private static function url( $content ) {
		return add_query_arg(
			array(
				'utm_source'   => 'plugin',
				'utm_medium'   => 'admin-notice',
				'utm_campaign' => 'bfcm-2026',
				'utm_content'  => $content,
				'discount'     => self::CODE,
			),
			'https://aeogodmode.io/pricing/'
		);
	}

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) || ! self::in_window() || self::has_paid_plan() || ! self::on_allowed_screen() ) {
			return;
		}
		if ( get_user_meta( get_current_user_id(), self::META_KEY, true ) ) {
			return;
		}

		$days   = self::days_left();
		$visits = self::crawler_visits();

		if ( 1 === $days ) {
			$deadline = __( 'Last day', 'aeo-god-mode' );
		} else {
			/* translators: %d: number of days left in the sale */
			$deadline = sprintf( _n( '%d day left', '%d days left', $days, 'aeo-god-mode' ), $days );
		}

		if ( $visits > 0 ) {
			$hook = sprintf(
				/* translators: %s: number of AI crawler visits */
				__( 'AI crawlers visited your site %s times this month. Free shows you what they find. Pro helps you improve it.', 'aeo-god-mode' ),
				number_format_i18n( $visits )
			);
		} else {
			$hook = __( 'Free shows you what AI search can find on your site. Pro helps you improve what it finds.', 'aeo-god-mode' );
		}

		$modules = array(
			array( 'generate', __( 'Generate', 'aeo-god-mode' ), __( 'FAQ and HowTo blocks, meta descriptions, speakable summaries, bulk titles', 'aeo-god-mode' ) ),
			array( 'optimize', __( 'Optimize', 'aeo-god-mode' ), __( 'Content Improvements and Keyword Optimize with one-click apply', 'aeo-god-mode' ) ),
			array( 'connect', __( 'Connect', 'aeo-god-mode' ), __( 'Smart Internal Linking fixes the orphan pages Free finds, plus IndexNow', 'aeo-god-mode' ) ),
			array( 'measure', __( 'Measure', 'aeo-god-mode' ), __( 'Citation Tracker across 4 AI engines, AI referral traffic, Citability Score', 'aeo-god-mode' ) ),
			array( 'map', __( 'Topical Map', 'aeo-god-mode' ), __( 'The pages to write next, mapped from your Search Console searches, ranked and drafted for you', 'aeo-god-mode' ) ),
		);

		$nonce = wp_create_nonce( self::ACTION );
		?>
		<div class="notice asgm-bfcm is-dismissible" data-nonce="<?php echo esc_attr( $nonce ); ?>">
			<style>
				.asgm-bfcm{--ink:#0f172a;--ink2:#1a2233;--body:#475569;--soft:#64748b;--line:#e2e8f0;--wash:#f4f6fa;--blue:#0062ff;--red:#e11d48;position:relative;border:1px solid var(--line)!important;padding:0!important;margin:20px 20px 10px 2px!important;border-radius:14px;background:#fff;color:var(--ink);box-shadow:0 1px 2px rgba(15,23,42,.04),0 12px 32px -16px rgba(15,23,42,.18)}
				.asgm-bfcm__grid{display:grid;grid-template-columns:minmax(0,1fr) 300px;gap:32px;padding:26px 52px 26px 30px;align-items:center}
				.asgm-bfcm .asgm-bfcm__tag{margin:0;font-size:12px;font-weight:600;color:var(--soft)}
				.asgm-bfcm__tag b{color:var(--red);font-weight:800;letter-spacing:.06em;text-transform:uppercase;margin-right:6px}
				.asgm-bfcm h2{margin:8px 0 6px;padding:0;font-size:30px;line-height:1.12;font-weight:800;color:var(--ink);letter-spacing:-.02em}
				.asgm-bfcm__hl{position:relative;white-space:nowrap;color:var(--red)}
				.asgm-bfcm__hl svg{position:absolute;left:-2%;bottom:-9px;width:104%;height:12px;overflow:visible}
				.asgm-bfcm .asgm-bfcm__hook{margin:14px 0 20px;font-size:14px;line-height:1.55;color:var(--body);max-width:620px}
				.asgm-bfcm__mods{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:12px;margin:0;padding:0;list-style:none}
				.asgm-bfcm__mods li{margin:0;padding:0}
				.asgm-bfcm__art{display:flex;align-items:center;justify-content:center;height:76px;border-radius:10px;background:var(--wash);margin-bottom:10px}
				.asgm-bfcm__art svg{width:84px;height:63px}
				.asgm-bfcm__mods b{display:block;font-size:13px;color:var(--ink);margin-bottom:2px}
				.asgm-bfcm__mods span{display:block;font-size:12px;line-height:1.45;color:var(--soft)}
				.asgm-bfcm__ticket{position:relative;border-radius:14px;background:var(--ink);color:#fff;font-variant-numeric:tabular-nums}
				.asgm-bfcm__top{padding:18px 20px 16px}
				.asgm-bfcm__days{display:inline-block;font-size:11px;font-weight:700;color:#fecdd3;background:rgba(225,29,72,.22);border-radius:4px;padding:3px 7px;margin-bottom:10px}
				.asgm-bfcm__row{display:flex;justify-content:space-between;align-items:baseline;gap:10px}
				.asgm-bfcm__row+.asgm-bfcm__row{margin-top:10px;padding-top:10px;border-top:1px solid rgba(255,255,255,.1)}
				.asgm-bfcm__plan{font-size:14px;font-weight:700}
				.asgm-bfcm__plan small{display:block;font-size:11px;line-height:1.4;font-weight:500;color:#94a3b8;margin-top:2px}
				.asgm-bfcm__price{text-align:right;white-space:nowrap}
				.asgm-bfcm__price s{font-size:13px;color:#64748b;margin-right:6px}
				.asgm-bfcm__price strong{font-size:24px;font-weight:800;letter-spacing:-.02em}
				.asgm-bfcm__price small{display:block;font-size:11px;color:#94a3b8}
				.asgm-bfcm__tear{position:relative;height:0;border-top:2px dashed rgba(255,255,255,.18);margin:0 14px}
				.asgm-bfcm__tear:before,.asgm-bfcm__tear:after{content:"";position:absolute;top:-12px;width:22px;height:22px;border-radius:50%;background:#fff}
				.asgm-bfcm__tear:before{left:-26px}.asgm-bfcm__tear:after{right:-26px}
				.asgm-bfcm__bot{padding:16px 20px 18px}
				.asgm-bfcm__code{display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:12px}
				.asgm-bfcm__code small{display:block;font-size:11px;color:#94a3b8;margin-bottom:2px}
				.asgm-bfcm__code code{background:none;padding:0;font:800 20px/1 ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;letter-spacing:.12em;color:#fff}
				.asgm-bfcm__copy{border:1px solid rgba(255,255,255,.25);border-radius:7px;padding:6px 11px;background:transparent;color:#fff;font-weight:600;font-size:12px;cursor:pointer}
				.asgm-bfcm__copy:hover,.asgm-bfcm__copy:focus{background:rgba(255,255,255,.1)}
				.asgm-bfcm__cta{display:block;text-align:center;padding:12px 16px;border-radius:9px;background:var(--red);color:#fff!important;font-size:15px;font-weight:700;text-decoration:none!important;box-shadow:inset 0 -2px 0 rgba(0,0,0,.18)}
				.asgm-bfcm__cta:hover,.asgm-bfcm__cta:focus{background:#be123c;color:#fff!important}
				.asgm-bfcm .asgm-bfcm__fine{margin:10px 0 0;padding:0;font-size:12px;line-height:1.4;color:#94a3b8;text-align:center}
				@media (max-width:1100px){.asgm-bfcm__grid{grid-template-columns:1fr;gap:22px;padding:22px 48px 22px 22px}.asgm-bfcm__mods{grid-template-columns:repeat(5,minmax(0,1fr));gap:10px}.asgm-bfcm__art{height:64px}.asgm-bfcm__art svg{width:70px;height:52px}}
				@media (min-width:783px) and (max-width:1100px){.asgm-bfcm__ticket{display:grid;grid-template-columns:minmax(0,1.15fr) 0 minmax(0,1fr);align-items:center}.asgm-bfcm__top{padding:18px 22px}.asgm-bfcm__tear{height:auto;align-self:stretch;border-top:0;border-left:2px dashed rgba(255,255,255,.18);margin:14px 0}.asgm-bfcm__tear:before,.asgm-bfcm__tear:after{left:-12px;right:auto}.asgm-bfcm__tear:before{top:-26px}.asgm-bfcm__tear:after{top:auto;bottom:-26px}.asgm-bfcm__bot{padding:18px 22px}}
				@media (max-width:782px){.asgm-bfcm{margin:12px 0 8px!important}.asgm-bfcm__grid{padding:20px 16px}.asgm-bfcm h2{font-size:24px;padding-right:28px}.asgm-bfcm__mods{grid-template-columns:1fr;gap:10px}.asgm-bfcm__mods li{display:grid;grid-template-columns:72px 1fr;column-gap:12px;align-items:center}.asgm-bfcm__art{grid-row:span 2;height:60px;margin:0}.asgm-bfcm__art svg{width:60px;height:45px}.asgm-bfcm__mods b{align-self:end}.asgm-bfcm__mods span{align-self:start}}
			</style>
			<div class="asgm-bfcm__grid">
				<div>
					<p class="asgm-bfcm__tag"><b><?php echo esc_html__( 'Black Friday deal!', 'aeo-god-mode' ); ?></b></p>
					<h2><?php
						printf(
							/* translators: %s: "50% off", underlined */
							esc_html__( 'Get %s your first year of Pro', 'aeo-god-mode' ),
							'<span class="asgm-bfcm__hl">' . esc_html__( '50% off', 'aeo-god-mode' ) . '<svg viewBox="0 0 120 12" preserveAspectRatio="none" aria-hidden="true"><path d="M2 8.5C30 3.5 70 2.5 118 6" fill="none" stroke="#e11d48" stroke-width="3.2" stroke-linecap="round"/><path d="M14 10.5C45 7 80 6.5 104 8.5" fill="none" stroke="#e11d48" stroke-width="1.6" stroke-linecap="round" opacity=".55"/></svg></span>'
						);
					?></h2>
					<p class="asgm-bfcm__hook"><?php echo esc_html( $hook ); ?></p>
					<ul class="asgm-bfcm__mods">
						<?php foreach ( $modules as $m ) : ?>
							<li><div class="asgm-bfcm__art"><?php echo self::art( $m[0] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG markup. ?></div><b><?php echo esc_html( $m[1] ); ?></b><span><?php echo esc_html( $m[2] ); ?></span></li>
						<?php endforeach; ?>
					</ul>
				</div>
				<div class="asgm-bfcm__ticket">
					<div class="asgm-bfcm__top">
						<span class="asgm-bfcm__days"><?php echo esc_html( $deadline ); ?></span>
						<div class="asgm-bfcm__row">
							<span class="asgm-bfcm__plan"><?php echo esc_html__( 'Pro', 'aeo-god-mode' ); ?><small><?php echo esc_html__( '500 AI credits a month (Free has 10)', 'aeo-god-mode' ); ?></small></span>
							<span class="asgm-bfcm__price"><s>$228</s><strong>$114</strong><small><?php echo esc_html__( 'first year', 'aeo-god-mode' ); ?></small></span>
						</div>
						<div class="asgm-bfcm__row">
							<span class="asgm-bfcm__plan"><?php echo esc_html__( 'Growth', 'aeo-god-mode' ); ?><small><?php echo esc_html__( '1,500 AI credits a month, market data', 'aeo-god-mode' ); ?></small></span>
							<span class="asgm-bfcm__price"><s>$588</s><strong>$294</strong><small><?php echo esc_html__( 'first year', 'aeo-god-mode' ); ?></small></span>
						</div>
					</div>
					<div class="asgm-bfcm__tear"></div>
					<div class="asgm-bfcm__bot">
						<div class="asgm-bfcm__code">
							<span><small><?php echo esc_html__( 'Your code', 'aeo-god-mode' ); ?></small><code><?php echo esc_html( self::CODE ); ?></code></span>
							<button type="button" class="asgm-bfcm__copy" data-done="<?php echo esc_attr__( 'Copied', 'aeo-god-mode' ); ?>"><?php echo esc_html__( 'Copy', 'aeo-god-mode' ); ?></button>
						</div>
						<a class="asgm-bfcm__cta" href="<?php echo esc_url( self::url( 'cta' ) ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html__( 'Claim 50% off Pro', 'aeo-god-mode' ); ?></a>
						<p class="asgm-bfcm__fine"><?php echo esc_html__( 'Enter the code at checkout. Ends 30 November.', 'aeo-god-mode' ); ?></p>
					</div>
				</div>
			</div>
		</div>
		<script>
		jQuery(function($){
			var n=$('.asgm-bfcm');
			n.on('click','.notice-dismiss',function(){
				$.post(ajaxurl,{action:'<?php echo esc_js( self::ACTION ); ?>',_wpnonce:n.data('nonce')});
			});
			n.on('click','.asgm-bfcm__copy',function(){
				var b=$(this),c=n.find('code').text();
				if(navigator.clipboard){navigator.clipboard.writeText(c).then(function(){b.text(b.data('done'));});}
			});
		});
		</script>
		<?php
	}

	/** Static, hand-drawn module illustrations. No user data, no text. */
	private static function art( $key ) {
		$ink  = '#0f172a';
		$blue = '#0062ff';
		$svgs = array(
			// A page that writes its own FAQ block: question row, answer lines, a pen nib, a spark.
			'generate' => '<rect x="14" y="6" width="40" height="51" rx="5" fill="#fff" stroke="' . $ink . '" stroke-width="2"/>'
				. '<path d="M21 15h22M21 21h15" stroke="#cbd5e1" stroke-width="2.5" stroke-linecap="round"/>'
				. '<rect x="20" y="28" width="28" height="22" rx="4" fill="#e6efff" stroke="' . $blue . '" stroke-width="1.6"/>'
				. '<circle cx="26" cy="34.5" r="2.4" fill="' . $blue . '"/><path d="M31 34.5h12" stroke="' . $blue . '" stroke-width="2.4" stroke-linecap="round"/>'
				. '<path d="M25 41h17M25 45.5h12" stroke="#93b4ff" stroke-width="2" stroke-linecap="round"/>'
				. '<path d="M58 30l9-9 4 4-9 9-5.5 1.5z" fill="' . $ink . '"/><path d="M58 30l4 4" stroke="#fff" stroke-width="1.2"/>'
				. '<path d="M67 7l1.6 4.4L73 13l-4.4 1.6L67 19l-1.6-4.4L61 13l4.4-1.6z" fill="#e11d48"/>',
			// Before and after: a weak line struck out, the rewrite applied with one click.
			'optimize' => '<rect x="8" y="7" width="52" height="49" rx="5" fill="#fff" stroke="' . $ink . '" stroke-width="2"/>'
				. '<path d="M16 18h30" stroke="#cbd5e1" stroke-width="3" stroke-linecap="round"/>'
				. '<path d="M14 18.5c8-1.5 22-1 34-.5" stroke="#e11d48" stroke-width="1.8" fill="none" stroke-linecap="round"/>'
				. '<path d="M31 24v8m-3.5-3.5L31 32l3.5-3.5" stroke="' . $ink . '" stroke-width="1.8" fill="none" stroke-linecap="round" stroke-linejoin="round"/>'
				. '<path d="M16 39h34M16 46h22" stroke="' . $blue . '" stroke-width="3" stroke-linecap="round"/>'
				. '<circle cx="66" cy="42" r="9" fill="' . $blue . '"/><path d="M62 42.2l2.8 2.8 5.2-5.6" stroke="#fff" stroke-width="2.2" fill="none" stroke-linecap="round" stroke-linejoin="round"/>',
			// Three linked pages pull a fourth, orphaned page into the site.
			'connect'  => '<path d="M23 15c14 0 20 0 34 0M16 24v16M23 49c10 0 18-4 30-12" stroke="' . $ink . '" stroke-width="1.8" fill="none"/>'
				. '<path d="M64 24v6" stroke="' . $blue . '" stroke-width="2" stroke-dasharray="2.5 3" fill="none"/>'
				. '<path d="M23 49c13 0 22 0 34-2" stroke="' . $blue . '" stroke-width="2.2" fill="none" stroke-linecap="round"/>'
				. '<rect x="8" y="6" width="15" height="18" rx="3" fill="#fff" stroke="' . $ink . '" stroke-width="2"/>'
				. '<rect x="8" y="40" width="15" height="18" rx="3" fill="#fff" stroke="' . $ink . '" stroke-width="2"/>'
				. '<rect x="57" y="6" width="15" height="18" rx="3" fill="#fff" stroke="' . $ink . '" stroke-width="2"/>'
				. '<rect x="57" y="32" width="15" height="21" rx="3" fill="' . $blue . '"/>'
				. '<path d="M61 39h7M61 43.5h5" stroke="#fff" stroke-width="1.8" stroke-linecap="round"/>'
				. '<path d="M12 12h7M12 46h7M61 12h7" stroke="#cbd5e1" stroke-width="1.8" stroke-linecap="round"/>',
			// Citations climbing: rising bars, the tallest one quoted by an AI answer.
			'measure'  => '<path d="M8 57h64" stroke="' . $ink . '" stroke-width="2" stroke-linecap="round"/>'
				. '<rect x="14" y="43" width="11" height="14" rx="2" fill="#cbd5e1"/>'
				. '<rect x="31" y="33" width="11" height="24" rx="2" fill="#93b4ff"/>'
				. '<rect x="48" y="22" width="11" height="35" rx="2" fill="' . $blue . '"/>'
				. '<path d="M10 37c8-3 14-8 20-12s13-9 22-13" stroke="' . $ink . '" stroke-width="1.6" stroke-dasharray="3 3" fill="none"/>'
				. '<path d="M53 4h18a4 4 0 014 4v8a4 4 0 01-4 4h-9l-5 4v-4h-4a4 4 0 01-4-4V8a4 4 0 014-4z" fill="#fff" stroke="' . $ink . '" stroke-width="1.8"/>'
				. '<path d="M57.5 14.5c0-3 1-5 3-6M63.5 14.5c0-3 1-5 3-6" stroke="#e11d48" stroke-width="2.2" fill="none" stroke-linecap="round"/>',
			// A pillar topic with its cluster: written pages solid, the gaps to write next dashed and ranked.
			'map'      => '<path d="M40 30L17 13M40 30L64 12M40 30L14 48M40 30L66 49" stroke="' . $ink . '" stroke-width="1.8"/>'
				. '<path d="M40 30L40 7" stroke="' . $blue . '" stroke-width="1.8" stroke-dasharray="2.5 2.5"/>'
				. '<circle cx="40" cy="30" r="10" fill="' . $blue . '"/><circle cx="40" cy="30" r="4" fill="#fff"/>'
				. '<rect x="9" y="6" width="15" height="13" rx="3" fill="#fff" stroke="' . $ink . '" stroke-width="2"/>'
				. '<rect x="57" y="5" width="15" height="13" rx="3" fill="#fff" stroke="' . $ink . '" stroke-width="2"/>'
				. '<rect x="6" y="42" width="15" height="13" rx="3" fill="#fff" stroke="' . $ink . '" stroke-width="2"/>'
				. '<rect x="58" y="43" width="15" height="13" rx="3" fill="#fff" stroke="' . $blue . '" stroke-width="2" stroke-dasharray="3 2.5"/>'
				. '<path d="M65.5 46.5v6M62.5 49.5h6" stroke="' . $blue . '" stroke-width="2" stroke-linecap="round"/>'
				. '<circle cx="40" cy="5" r="4.5" fill="#e11d48"/><path d="M40 3v4M38 5h4" stroke="#fff" stroke-width="1.5" stroke-linecap="round"/>',
		);
		if ( ! isset( $svgs[ $key ] ) ) {
			return '';
		}
		return '<svg viewBox="0 0 80 60" fill="none" aria-hidden="true" focusable="false">' . $svgs[ $key ] . '</svg>';
	}

	public static function ajax_dismiss() {
		check_ajax_referer( self::ACTION );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( null, 403 );
		}
		update_user_meta( get_current_user_id(), self::META_KEY, 1 );
		wp_send_json_success();
	}
}
