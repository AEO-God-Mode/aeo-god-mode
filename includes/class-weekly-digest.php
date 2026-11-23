<?php
/**
 * Weekly AI search email.
 *
 * An opt-in note, sent from the customer's own WordPress with wp_mail on a
 * WP-Cron schedule, that answers one question in 30 seconds: is AI search
 * sending me customers, and what is the one thing to fix next?
 *
 * Free builds the email from what Free measures (AI crawler reads and the
 * busiest page that answers worst). Pro adds citations, AI referral visits and
 * leads through the `asgm_weekly_digest_report` filter, so this file never
 * references a Pro class. See docs/weekly-digest.md for the concept and copy.
 *
 * Security notes:
 * - Settings and test sends sit behind manage_options and the REST nonce.
 * - Each recipient gets their own unsubscribe link. It carries a keyed hash of
 *   the address and an HMAC signature, never the address or the key.
 * - Opening the link only shows a confirm button, so mail scanners that fetch
 *   links cannot unsubscribe anyone. RFC 8058 one-click POST is honoured.
 * - Test sends, scheduled sends and unsubscribe attempts are all rate limited.
 *
 * @package AISEOGodMode
 */

namespace AISEOGodMode;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Weekly_Digest {

    const OPTION         = 'asgm_weekly_digest';
    const SECRET_OPTION  = 'asgm_weekly_digest_secret';
    const LOCK_OPTION    = 'asgm_weekly_digest_lock';
    const CRON_HOOK      = 'asgm_weekly_digest_send';
    const UNSUB_ACTION   = 'asgm_digest_unsub';
    const MAX_RECIPIENTS = 10;
    const SEND_HOUR      = 8;

    /** Test sends allowed per window, and the window length. */
    const TEST_LIMIT  = 3;
    const TEST_WINDOW = 600;

    /** Unsubscribe attempts allowed per IP per hour. */
    const UNSUB_LIMIT = 30;

    /** A scheduled send never repeats inside this gap, whatever cron does. */
    const MIN_GAP = 518400; // 6 days.

    /** Register hooks. Called on every request so unsubscribe always works. */
    public static function init() {
        add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
        add_action( self::CRON_HOOK, array( __CLASS__, 'cron_send' ) );
        add_action( 'admin_post_' . self::UNSUB_ACTION, array( __CLASS__, 'handle_unsubscribe' ) );
        add_action( 'admin_post_nopriv_' . self::UNSUB_ACTION, array( __CLASS__, 'handle_unsubscribe' ) );
        // A reactivated plugin loses its cron event; put it back if the email is on.
        add_action( 'admin_init', array( __CLASS__, 'ensure_schedule' ) );
    }

    /* ─────────────────────────── Settings ─────────────────────────── */

    /** Stored settings merged over the defaults. */
    public static function get_settings() {
        $saved = get_option( self::OPTION, array() );
        if ( ! is_array( $saved ) ) {
            $saved = array();
        }
        $settings = wp_parse_args( $saved, array(
            'enabled'    => false,
            'recipients' => array(),
            'day'        => 1,
            'last_sent'  => 0,
        ) );
        $settings['enabled']    = (bool) $settings['enabled'];
        $settings['day']        = max( 0, min( 6, (int) $settings['day'] ) );
        $settings['last_sent']  = (int) $settings['last_sent'];
        $settings['recipients'] = self::clean_recipients( $settings['recipients'] )['valid'];
        if ( empty( $settings['recipients'] ) ) {
            $admin = sanitize_email( (string) get_option( 'admin_email' ) );
            $settings['recipients'] = is_email( $admin ) ? array( $admin ) : array();
        }
        return $settings;
    }

    /**
     * Normalise a list of addresses.
     *
     * @param mixed $raw Array, or a comma / newline separated string.
     * @return array{valid: string[], rejected: string[]}
     */
    private static function clean_recipients( $raw ) {
        if ( is_string( $raw ) ) {
            $raw = preg_split( '/[\s,;]+/', $raw );
        }
        $valid    = array();
        $rejected = array();
        foreach ( (array) $raw as $item ) {
            $item = trim( (string) $item );
            if ( '' === $item ) {
                continue;
            }
            $email = sanitize_email( $item );
            if ( '' === $email || ! is_email( $email ) ) {
                $rejected[] = sanitize_text_field( $item );
                continue;
            }
            $key = strtolower( $email );
            if ( ! isset( $valid[ $key ] ) ) {
                $valid[ $key ] = $email;
            }
        }
        return array(
            'valid'    => array_slice( array_values( $valid ), 0, self::MAX_RECIPIENTS ),
            'rejected' => array_slice( $rejected, 0, 20 ),
        );
    }

    private static function save_settings( array $settings ) {
        update_option( self::OPTION, array(
            'enabled'    => (bool) $settings['enabled'],
            'recipients' => array_values( $settings['recipients'] ),
            'day'        => (int) $settings['day'],
            'last_sent'  => (int) $settings['last_sent'],
        ), false );
    }

    /* ─────────────────────────── Schedule ─────────────────────────── */

    /** Next {day} at 8am site time, as a Unix timestamp. */
    private static function next_run_timestamp( $day ) {
        $tz   = wp_timezone();
        $now  = new \DateTimeImmutable( 'now', $tz );
        $run  = $now->setTime( self::SEND_HOUR, 0, 0 );
        $diff = ( (int) $day - (int) $now->format( 'w' ) + 7 ) % 7;
        $run  = $run->modify( '+' . $diff . ' days' );
        if ( $run <= $now ) {
            $run = $run->modify( '+7 days' );
        }
        return $run->getTimestamp();
    }

    /** Clear any queued send, then queue the next one if the email is on. */
    public static function reschedule( array $settings ) {
        wp_clear_scheduled_hook( self::CRON_HOOK );
        if ( ! empty( $settings['enabled'] ) && ! empty( $settings['recipients'] ) ) {
            wp_schedule_event( self::next_run_timestamp( $settings['day'] ), 'weekly', self::CRON_HOOK );
        }
    }

    public static function ensure_schedule() {
        $settings = self::get_settings();
        if ( $settings['enabled'] && ! wp_next_scheduled( self::CRON_HOOK ) ) {
            self::reschedule( $settings );
        }
    }

    /** Plugin deactivation: never leave a send queued. */
    public static function unschedule() {
        wp_clear_scheduled_hook( self::CRON_HOOK );
    }

    /* ─────────────────────────── REST ─────────────────────────── */

    public static function register_routes() {
        register_rest_route( API::NAMESPACE, '/weekly-digest', array(
            array(
                'methods'             => 'GET',
                'callback'            => array( __CLASS__, 'rest_get' ),
                'permission_callback' => array( __CLASS__, 'can_manage' ),
            ),
            array(
                'methods'             => 'POST',
                'callback'            => array( __CLASS__, 'rest_save' ),
                'permission_callback' => array( __CLASS__, 'can_manage' ),
            ),
        ) );
        register_rest_route( API::NAMESPACE, '/weekly-digest/preview', array(
            'methods'             => 'GET',
            'callback'            => array( __CLASS__, 'rest_preview' ),
            'permission_callback' => array( __CLASS__, 'can_manage' ),
        ) );
        register_rest_route( API::NAMESPACE, '/weekly-digest/test', array(
            'methods'             => 'POST',
            'callback'            => array( __CLASS__, 'rest_test' ),
            'permission_callback' => array( __CLASS__, 'can_manage' ),
        ) );
    }

    public static function can_manage() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return new \WP_Error(
                'rest_forbidden',
                __( 'You do not have permission to access this endpoint.', 'aeo-god-mode' ),
                array( 'status' => 403 )
            );
        }
        return true;
    }

    /** The state the settings card needs. Never includes the signing key. */
    private static function state( array $settings, array $extra = array() ) {
        $next = wp_next_scheduled( self::CRON_HOOK );
        $fmt  = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
        return array_merge( array(
            'enabled'     => $settings['enabled'],
            'recipients'  => $settings['recipients'],
            'day'         => $settings['day'],
            'next_run'    => $next ? wp_date( $fmt, $next ) : '',
            'last_sent'   => $settings['last_sent'] ? wp_date( $fmt, $settings['last_sent'] ) : '',
            'admin_email' => sanitize_email( (string) get_option( 'admin_email' ) ),
            'max'         => self::MAX_RECIPIENTS,
        ), $extra );
    }

    public static function rest_get() {
        return rest_ensure_response( self::state( self::get_settings() ) );
    }

    public static function rest_save( \WP_REST_Request $request ) {
        $settings = self::get_settings();
        $rejected = array();

        if ( null !== $request->get_param( 'enabled' ) ) {
            $settings['enabled'] = rest_sanitize_boolean( $request->get_param( 'enabled' ) );
        }
        if ( null !== $request->get_param( 'day' ) ) {
            $settings['day'] = max( 0, min( 6, (int) $request->get_param( 'day' ) ) );
        }
        if ( null !== $request->get_param( 'recipients' ) ) {
            $clean    = self::clean_recipients( $request->get_param( 'recipients' ) );
            $rejected = $clean['rejected'];
            $settings['recipients'] = $clean['valid'];
            if ( empty( $settings['recipients'] ) ) {
                $admin = sanitize_email( (string) get_option( 'admin_email' ) );
                $settings['recipients'] = is_email( $admin ) ? array( $admin ) : array();
            }
        }

        self::save_settings( $settings );
        self::reschedule( $settings );

        return rest_ensure_response( self::state( self::get_settings(), array( 'rejected' => $rejected ) ) );
    }

    public static function rest_preview() {
        $settings  = self::get_settings();
        $recipient = $settings['recipients'][0] ?? sanitize_email( (string) get_option( 'admin_email' ) );
        $report    = self::build_report( true );
        return rest_ensure_response( array(
            'subject' => self::subject( $report ),
            'html'    => self::render_html( $report, self::unsubscribe_url( $recipient ) ),
            'text'    => self::render_text( $report, self::unsubscribe_url( $recipient ) ),
        ) );
    }

    public static function rest_test( \WP_REST_Request $request ) {
        $log = get_transient( 'asgm_weekly_digest_tests' );
        $log = is_array( $log ) ? $log : array();
        $now = time();
        $log = array_values( array_filter( $log, function ( $t ) use ( $now ) {
            return (int) $t > $now - self::TEST_WINDOW;
        } ) );
        if ( count( $log ) >= self::TEST_LIMIT ) {
            return new \WP_Error(
                'asgm_digest_rate_limited',
                __( 'You have sent 3 test emails in the last 10 minutes. Wait a few minutes and try again.', 'aeo-god-mode' ),
                array( 'status' => 429 )
            );
        }

        // Tests go to the configured list, or to an address typed in the card.
        $settings = self::get_settings();
        $to       = $settings['recipients'];
        if ( null !== $request->get_param( 'recipients' ) ) {
            $clean = self::clean_recipients( $request->get_param( 'recipients' ) );
            if ( ! empty( $clean['valid'] ) ) {
                $to = $clean['valid'];
            }
        }
        if ( empty( $to ) ) {
            return new \WP_Error( 'asgm_digest_no_recipients', __( 'Add an email address first.', 'aeo-god-mode' ), array( 'status' => 400 ) );
        }

        $log[] = $now;
        set_transient( 'asgm_weekly_digest_tests', $log, self::TEST_WINDOW );

        $report = self::build_report( true );
        $sent   = self::deliver( $report, $to );
        if ( 0 === $sent ) {
            return new \WP_Error(
                'asgm_digest_send_failed',
                __( 'WordPress could not send the email. Check that your site can send mail, for example with an SMTP plugin.', 'aeo-god-mode' ),
                array( 'status' => 500 )
            );
        }
        return rest_ensure_response( array( 'sent' => $sent, 'to' => $to ) );
    }

    /* ─────────────────────────── Sending ─────────────────────────── */

    /** WP-Cron callback. */
    public static function cron_send() {
        $settings = self::get_settings();
        if ( ! $settings['enabled'] || empty( $settings['recipients'] ) ) {
            self::unschedule();
            return;
        }
        if ( $settings['last_sent'] && ( time() - $settings['last_sent'] ) < self::MIN_GAP ) {
            return;
        }
        if ( ! self::acquire_lock() ) {
            return;
        }
        try {
            $report = self::build_report( false );
            $sent   = self::deliver( $report, $settings['recipients'] );
            if ( $sent > 0 ) {
                $settings['last_sent'] = time();
                self::save_settings( $settings );
                /**
                 * Fires after a scheduled weekly email went out, so add-ons can
                 * store the snapshot next week's email compares against.
                 *
                 * @param array $report The report that was sent.
                 */
                do_action( 'asgm_weekly_digest_sent', $report );
            }
        } finally {
            delete_option( self::LOCK_OPTION );
        }
    }

    /** add_option fails if the row exists, which makes it a cheap atomic lock. */
    private static function acquire_lock() {
        if ( add_option( self::LOCK_OPTION, time(), '', 'no' ) ) {
            return true;
        }
        $held = (int) get_option( self::LOCK_OPTION, 0 );
        if ( $held && ( time() - $held ) > 15 * MINUTE_IN_SECONDS ) {
            delete_option( self::LOCK_OPTION );
            return add_option( self::LOCK_OPTION, time(), '', 'no' );
        }
        return false;
    }

    /**
     * Send one message per recipient, each with its own unsubscribe link.
     *
     * @return int Number of messages wp_mail accepted.
     */
    private static function deliver( array $report, array $recipients ) {
        $subject = self::subject( $report );
        $sent    = 0;
        foreach ( $recipients as $email ) {
            $unsub = self::unsubscribe_url( $email );
            $html  = self::render_html( $report, $unsub );
            $text  = self::render_text( $report, $unsub );

            $alt = function ( $phpmailer ) use ( $text ) {
                $phpmailer->AltBody = $text; // phpcs:ignore WordPress.NamingConventions.ValidVariableName
                /*
                 * PHP's mail() transport makes PHPMailer encode any header
                 * longer than about 45 characters, which turns the
                 * List-Unsubscribe URL into text no inbox can use. On that
                 * transport, drop both headers; the footer link still works.
                 * SMTP plugins (the usual setup) keep them.
                 */
                if ( 'mail' === $phpmailer->Mailer && method_exists( $phpmailer, 'getCustomHeaders' ) ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName
                    $keep = array();
                    foreach ( $phpmailer->getCustomHeaders() as $h ) {
                        if ( 0 !== stripos( (string) $h[0], 'List-Unsubscribe' ) ) {
                            $keep[] = $h;
                        }
                    }
                    $phpmailer->clearCustomHeaders();
                    foreach ( $keep as $h ) {
                        $phpmailer->addCustomHeader( $h[0], $h[1] );
                    }
                }
            };
            add_action( 'phpmailer_init', $alt );
            $ok = wp_mail(
                $email,
                $subject,
                $html,
                array(
                    'Content-Type: text/html; charset=UTF-8',
                    'List-Unsubscribe: <' . esc_url_raw( $unsub ) . '>',
                    'List-Unsubscribe-Post: List-Unsubscribe=One-Click',
                )
            );
            remove_action( 'phpmailer_init', $alt );
            if ( $ok ) {
                $sent++;
            }
        }
        return $sent;
    }

    /* ─────────────────────────── Unsubscribe ─────────────────────────── */

    /** Signing key, created once and never sent anywhere. */
    private static function secret() {
        $secret = (string) get_option( self::SECRET_OPTION, '' );
        if ( strlen( $secret ) < 32 ) {
            $secret = wp_generate_password( 64, false, false );
            if ( ! add_option( self::SECRET_OPTION, $secret, '', 'no' ) ) {
                update_option( self::SECRET_OPTION, $secret, false );
            }
        }
        return $secret;
    }

    /** Keyed hash of the address, so the link never reveals it. */
    private static function recipient_id( $email ) {
        return substr( hash_hmac( 'sha256', 'rid|' . strtolower( trim( (string) $email ) ), self::secret() ), 0, 24 );
    }

    private static function signature( $rid ) {
        return hash_hmac( 'sha256', 'unsub|' . $rid . '|' . home_url( '/' ), self::secret() );
    }

    public static function unsubscribe_url( $email ) {
        $rid = self::recipient_id( $email );
        return add_query_arg(
            array(
                'action' => self::UNSUB_ACTION,
                'r'      => $rid,
                't'      => self::signature( $rid ),
            ),
            admin_url( 'admin-post.php' )
        );
    }

    /** GET shows a confirm button. POST (ours, or a mail client's one-click) acts. */
    public static function handle_unsubscribe() {
        nocache_headers();
        header( 'X-Robots-Tag: noindex, nofollow' );

        $ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
        $key = 'asgm_dg_unsub_' . md5( $ip );
        $hit = (int) get_transient( $key );
        if ( $hit >= self::UNSUB_LIMIT ) {
            self::unsub_page( __( 'Too many tries', 'aeo-god-mode' ), __( 'Please wait an hour and open the link again.', 'aeo-god-mode' ), '', 429 );
        }
        set_transient( $key, $hit + 1, HOUR_IN_SECONDS );

        // phpcs:disable WordPress.Security.NonceVerification -- the HMAC signature is the authorisation; the link is used logged out.
        $rid = isset( $_REQUEST['r'] ) ? preg_replace( '/[^a-f0-9]/', '', strtolower( (string) wp_unslash( $_REQUEST['r'] ) ) ) : '';
        $sig = isset( $_REQUEST['t'] ) ? preg_replace( '/[^a-f0-9]/', '', strtolower( (string) wp_unslash( $_REQUEST['t'] ) ) ) : '';
        // phpcs:enable

        if ( 24 !== strlen( $rid ) || ! hash_equals( self::signature( $rid ), $sig ) ) {
            self::unsub_page( __( 'This link has expired', 'aeo-god-mode' ), __( 'Ask the site admin to turn the weekly email off in AEO God Mode settings.', 'aeo-god-mode' ), '', 400 );
        }

        $settings = self::get_settings();
        $match    = null;
        foreach ( $settings['recipients'] as $email ) {
            if ( hash_equals( self::recipient_id( $email ), $rid ) ) {
                $match = $email;
                break;
            }
        }

        $method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';

        if ( null === $match || ! $settings['enabled'] ) {
            self::unsub_page( __( 'You are unsubscribed', 'aeo-god-mode' ), __( 'You will not get the weekly AI search email from this site.', 'aeo-god-mode' ) );
        }

        if ( 'POST' !== $method ) {
            $form  = '<form method="post" action="' . esc_url( self::unsubscribe_url( $match ) ) . '">';
            $form .= '<button type="submit" style="background:#0b1b3a;color:#fff;border:0;border-radius:10px;padding:14px 22px;font-size:16px;font-weight:600;cursor:pointer">' . esc_html__( 'Stop these emails', 'aeo-god-mode' ) . '</button></form>';
            self::unsub_page(
                __( 'Stop the weekly email?', 'aeo-god-mode' ),
                sprintf(
                    /* translators: %s: masked email address */
                    __( 'We will stop sending the weekly AI search email to %s.', 'aeo-god-mode' ),
                    self::mask_email( $match )
                ),
                $form
            );
        }

        $settings['recipients'] = array_values( array_filter( $settings['recipients'], function ( $e ) use ( $match ) {
            return strtolower( $e ) !== strtolower( $match );
        } ) );
        if ( empty( $settings['recipients'] ) ) {
            $settings['enabled'] = false;
        }
        self::save_settings( $settings );
        self::reschedule( $settings );

        self::unsub_page( __( 'You are unsubscribed', 'aeo-god-mode' ), __( 'You will not get the weekly AI search email from this site.', 'aeo-god-mode' ) );
    }

    private static function mask_email( $email ) {
        $parts = explode( '@', (string) $email, 2 );
        if ( 2 !== count( $parts ) ) {
            return '';
        }
        $name = $parts[0];
        return substr( $name, 0, 1 ) . str_repeat( '*', max( 2, min( 6, strlen( $name ) - 1 ) ) ) . '@' . $parts[1];
    }

    /** Small standalone page. Every dynamic value is escaped here. */
    private static function unsub_page( $title, $body, $form_html = '', $status = 200 ) {
        status_header( $status );
        header( 'Content-Type: text/html; charset=utf-8' );
        $site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
        echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow">';
        echo '<title>' . esc_html( $title ) . '</title></head>';
        echo '<body style="margin:0;background:#f3f5f9;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;color:#0b1b3a">';
        echo '<div style="max-width:460px;margin:12vh auto;padding:0 16px">';
        echo '<div style="background:#fff;border:1px solid #e3e7ef;border-radius:18px;padding:32px 28px;box-shadow:0 10px 30px rgba(11,27,58,.06)">';
        echo '<p style="margin:0 0 8px;font-size:13px;color:#5b6b86">' . esc_html( $site ) . '</p>';
        echo '<h1 style="margin:0 0 10px;font-size:24px;line-height:1.25">' . esc_html( $title ) . '</h1>';
        echo '<p style="margin:0 0 22px;font-size:16px;line-height:1.55;color:#334460">' . esc_html( $body ) . '</p>';
        echo $form_html; // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts above.
        echo '</div></div></body></html>';
        exit;
    }

    /* ─────────────────────────── Report ─────────────────────────── */

    /**
     * Collect the week's numbers.
     *
     * @param bool $is_test Test and preview sends must not move snapshots.
     * @return array
     */
    public static function build_report( $is_test = false ) {
        $end   = time();
        $start = $end - WEEK_IN_SECONDS;

        $report = array(
            'is_test'      => (bool) $is_test,
            'site_name'    => html_entity_decode( get_bloginfo( 'name' ), ENT_QUOTES, 'UTF-8' ),
            'site_url'     => home_url( '/' ),
            'admin_url'    => admin_url( 'admin.php?page=aeo-god-mode' ),
            'period_start' => $start,
            'period_end'   => $end,
            'crawl'        => self::crawl_stats(),
            'referrals'    => null,
            'citations'    => null,
            'leads'        => null,
            'fix'          => null,
            'upgrade'      => ! self::is_pro(),
        );
        $report['fix'] = self::free_fix( $report );

        /**
         * Lets add-ons add citations, AI referral visits, leads, or a better
         * next fix. Keys: referrals, citations, leads, fix. See
         * docs/weekly-digest.md for each shape.
         *
         * @param array $report
         */
        $report = apply_filters( 'asgm_weekly_digest_report', $report );

        /**
         * Lead counts from any attribution source. Return null when nothing is
         * measured, or array( 'this' => int, 'last' => int, 'by_engine' => array( name => int ) ).
         *
         * @param array|null $leads
         * @param int        $start Period start (Unix).
         * @param int        $end   Period end (Unix).
         */
        $leads = apply_filters( 'asgm_weekly_digest_leads', $report['leads'], $start, $end );
        $report['leads'] = ( is_array( $leads ) && isset( $leads['this'] ) ) ? $leads : null;

        return $report;
    }

    private static function is_pro() {
        return class_exists( __NAMESPACE__ . '\\License' )
            && method_exists( __NAMESPACE__ . '\\License', 'is_pro' )
            && License::is_pro();
    }

    /** AI bot reads this week vs last week. Null when the log has no data. */
    private static function crawl_stats() {
        global $wpdb;
        $table = $wpdb->prefix . 'asgm_crawler_log';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return null;
        }
        $this_week = (int) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            'SELECT COUNT(*) FROM %i WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)',
            $table
        ) );
        $last_week = (int) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            'SELECT COUNT(*) FROM %i WHERE created_at >= DATE_SUB(NOW(), INTERVAL 14 DAY) AND created_at < DATE_SUB(NOW(), INTERVAL 7 DAY)',
            $table
        ) );
        if ( 0 === $this_week && 0 === $last_week ) {
            $any = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            if ( 0 === $any ) {
                return null;
            }
        }
        $bots = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            'SELECT bot_name AS name, COUNT(*) AS visits FROM %i WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) GROUP BY bot_name ORDER BY visits DESC LIMIT 4',
            $table
        ), ARRAY_A );
        return array(
            'this' => $this_week,
            'last' => $last_week,
            'by'   => array_map( function ( $r ) {
                return array( 'name' => (string) $r['name'], 'visits' => (int) $r['visits'] );
            }, (array) $bots ),
        );
    }

    /** The one fix Free can name. */
    private static function free_fix( array $report ) {
        $admin = $report['admin_url'];
        if ( null === $report['crawl'] ) {
            return array(
                'body' => __( 'Turn on the AI crawler log so you can see which AI bots read your site, and which pages they read most.', 'aeo-god-mode' ),
                'cta'  => __( 'Turn it on', 'aeo-god-mode' ),
                'url'  => $admin . '#/crawlers',
            );
        }
        $priority = class_exists( __NAMESPACE__ . '\\Dashboard_Widget' ) ? Dashboard_Widget::compute_priority_page() : null;
        $best     = is_array( $priority ) ? ( $priority['best'] ?? null ) : null;
        if ( is_array( $best ) ) {
            return array(
                'body' => sprintf(
                    /* translators: 1: page title, 2: number of visits, 3: score out of 100 */
                    __( 'AI bots read "%1$s" %2$s times this month, but it scores %3$s/100 for answering the question. Put a clear, direct answer in its first paragraph.', 'aeo-god-mode' ),
                    html_entity_decode( wp_strip_all_tags( (string) $best['title'] ), ENT_QUOTES, 'UTF-8' ),
                    number_format_i18n( (int) $best['visits'] ),
                    number_format_i18n( (int) $best['score'] )
                ),
                'cta'  => __( 'Improve this page', 'aeo-god-mode' ),
                'url'  => admin_url( 'post.php?post=' . (int) $best['post_id'] . '&action=edit' ),
            );
        }
        return array(
            'body' => __( 'Scan your posts for missing answers and FAQ sections. It takes a minute and shows the quickest wins.', 'aeo-god-mode' ),
            'cta'  => __( 'Find content gaps', 'aeo-god-mode' ),
            'url'  => $admin . '#/content-gaps',
        );
    }

    /* ─────────────────────────── Copy ─────────────────────────── */

    /** The hero metric: AI referral visits on Pro, AI bot reads on Free. */
    private static function hero( array $report ) {
        if ( is_array( $report['referrals'] ) && isset( $report['referrals']['this'] ) ) {
            return array(
                'label' => __( 'Visits from AI answers', 'aeo-god-mode' ),
                'this'  => (int) $report['referrals']['this'],
                'last'  => (int) ( $report['referrals']['last'] ?? 0 ),
                'by'    => (array) ( $report['referrals']['by'] ?? array() ),
                'none'  => __( 'No one clicked through from an AI answer this week yet.', 'aeo-god-mode' ),
            );
        }
        if ( is_array( $report['crawl'] ) ) {
            return array(
                'label' => __( 'Times AI bots read your site', 'aeo-god-mode' ),
                'this'  => (int) $report['crawl']['this'],
                'last'  => (int) $report['crawl']['last'],
                'by'    => (array) $report['crawl']['by'],
                'none'  => __( 'No AI bots read your site this week.', 'aeo-god-mode' ),
            );
        }
        return null;
    }

    private static function change_line( $this_week, $last_week ) {
        $diff = (int) $this_week - (int) $last_week;
        if ( 0 === (int) $last_week && (int) $this_week > 0 ) {
            return array( 'up', __( 'New this week', 'aeo-god-mode' ) );
        }
        if ( $diff > 0 ) {
            /* translators: %s: increase on last week */
            return array( 'up', sprintf( __( 'Up %s on last week', 'aeo-god-mode' ), number_format_i18n( $diff ) ) );
        }
        if ( $diff < 0 ) {
            /* translators: %s: decrease on last week */
            return array( 'down', sprintf( __( 'Down %s on last week', 'aeo-god-mode' ), number_format_i18n( abs( $diff ) ) ) );
        }
        return array( 'same', __( 'Same as last week', 'aeo-god-mode' ) );
    }

    public static function subject( array $report ) {
        $site = $report['site_name'];
        $won  = is_array( $report['citations'] ) ? (array) ( $report['citations']['won'] ?? array() ) : array();
        if ( ! empty( $won ) ) {
            return sprintf(
                /* translators: 1: site name, 2: AI engine name */
                __( '%1$s: %2$s started quoting you', 'aeo-god-mode' ),
                $site,
                $won[0]['engine']
            );
        }
        $hero = self::hero( $report );
        if ( $hero && $hero['this'] > 0 ) {
            if ( is_array( $report['referrals'] ) ) {
                return sprintf(
                    /* translators: 1: site name, 2: number of visits */
                    _n( '%1$s: %2$s visit from AI search this week', '%1$s: %2$s visits from AI search this week', $hero['this'], 'aeo-god-mode' ),
                    $site,
                    number_format_i18n( $hero['this'] )
                );
            }
            return sprintf(
                /* translators: 1: site name, 2: number of reads */
                _n( '%1$s: AI bots read your site %2$s time this week', '%1$s: AI bots read your site %2$s times this week', $hero['this'], 'aeo-god-mode' ),
                $site,
                number_format_i18n( $hero['this'] )
            );
        }
        /* translators: %s: site name */
        return sprintf( __( '%s: your week in AI search', 'aeo-god-mode' ), $site );
    }

    private static function period_label( array $report ) {
        return sprintf(
            /* translators: 1: start date, 2: end date */
            __( '%1$s to %2$s', 'aeo-god-mode' ),
            wp_date( 'j M', $report['period_start'] ),
            wp_date( 'j M', $report['period_end'] )
        );
    }

    /** Citation rows as plain sentences: array( array( 'won'|'lost', sentence ) ). */
    private static function citation_lines( array $c ) {
        $lines = array();
        foreach ( (array) ( $c['won'] ?? array() ) as $row ) {
            /* translators: 1: AI engine name, 2: the question */
            $lines[] = array( 'won', sprintf( __( '%1$s now quotes you for "%2$s"', 'aeo-god-mode' ), $row['engine'], $row['query'] ) );
        }
        foreach ( (array) ( $c['lost'] ?? array() ) as $row ) {
            /* translators: 1: AI engine name, 2: the question */
            $lines[] = array( 'lost', sprintf( __( '%1$s stopped quoting you for "%2$s"', 'aeo-god-mode' ), $row['engine'], $row['query'] ) );
        }
        return $lines;
    }

    private static function citation_note( array $c ) {
        if ( empty( $c['checked_this_week'] ) ) {
            return __( 'No citation check ran this week, so there is nothing new to compare.', 'aeo-god-mode' );
        }
        if ( ! empty( $c['first'] ) ) {
            return sprintf(
                /* translators: 1: questions quoted, 2: questions tracked */
                __( 'Quoted for %1$s of %2$s questions you track. From next week you will see what changed.', 'aeo-god-mode' ),
                number_format_i18n( (int) ( $c['cited_now'] ?? 0 ) ),
                number_format_i18n( (int) ( $c['tracked'] ?? 0 ) )
            );
        }
        if ( empty( $c['won'] ) && empty( $c['lost'] ) ) {
            return sprintf(
                /* translators: 1: questions quoted, 2: questions tracked */
                __( 'No change this week. Quoted for %1$s of %2$s questions you track.', 'aeo-god-mode' ),
                number_format_i18n( (int) ( $c['cited_now'] ?? 0 ) ),
                number_format_i18n( (int) ( $c['tracked'] ?? 0 ) )
            );
        }
        return sprintf(
            /* translators: 1: questions quoted, 2: questions tracked */
            __( 'Quoted for %1$s of %2$s questions you track.', 'aeo-god-mode' ),
            number_format_i18n( (int) ( $c['cited_now'] ?? 0 ) ),
            number_format_i18n( (int) ( $c['tracked'] ?? 0 ) )
        );
    }

    /* ─────────────────────────── HTML ─────────────────────────── */

    /**
     * Email-safe HTML: tables, inline styles, one media query for phones.
     * Every dynamic value passes through esc_html / esc_url / esc_attr.
     */
    public static function render_html( array $report, $unsub_url ) {
        $font   = "-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif";
        $ink    = '#0b1b3a';
        $muted  = '#5b6b86';
        $line   = '#e6eaf2';
        $hero   = self::hero( $report );
        $pre    = __( 'Your week in AI search, and the one thing to fix next.', 'aeo-god-mode' );

        $h  = '<!doctype html><html lang="' . esc_attr( get_bloginfo( 'language' ) ) . '"><head><meta charset="utf-8">';
        $h .= '<meta name="viewport" content="width=device-width,initial-scale=1"><meta name="x-apple-disable-message-reformatting">';
        $h .= '<meta name="color-scheme" content="light"><meta name="supported-color-schemes" content="light">';
        $h .= '<title>' . esc_html( self::subject( $report ) ) . '</title>';
        $h .= '<style>@media (max-width:600px){.asgm-pad{padding-left:20px!important;padding-right:20px!important}.asgm-hero{font-size:44px!important}.asgm-outer{padding:12px 8px!important}}a{color:#1f6fe5}</style>';
        $h .= '</head><body style="margin:0;padding:0;background:#eef1f6;-webkit-text-size-adjust:100%">';
        $h .= '<div style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent">' . esc_html( $pre ) . '</div>';
        $h .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#eef1f6"><tr><td class="asgm-outer" align="center" style="padding:28px 12px">';
        $h .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:560px;background:#ffffff;border:1px solid ' . $line . ';border-radius:20px;border-collapse:separate;overflow:hidden">';

        // Top band.
        $h .= '<tr><td style="height:6px;line-height:6px;font-size:0;background:#338dff;background-image:linear-gradient(90deg,#338dff,#7c5cff)">&nbsp;</td></tr>';
        $h .= '<tr><td class="asgm-pad" style="padding:26px 36px 0;font-family:' . $font . '">';
        $h .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>';
        $h .= '<td style="font-size:13px;color:' . $muted . ';font-family:' . $font . '">' . esc_html__( 'Weekly AI search email', 'aeo-god-mode' ) . '</td>';
        $h .= '<td align="right" style="font-size:13px;color:' . $muted . ';font-family:' . $font . '">' . esc_html( self::period_label( $report ) ) . '</td>';
        $h .= '</tr></table>';
        $h .= '<p style="margin:14px 0 0;font-size:20px;line-height:1.35;font-weight:700;color:' . $ink . '">' . esc_html( $report['site_name'] ) . '</p>';
        $h .= '<p style="margin:6px 0 0;font-size:16px;line-height:1.5;color:#334460">'
            . esc_html(
                sprintf(
                    /* translators: %s: site name */
                    __( "Here's how %s did in AI search this week.", 'aeo-god-mode' ),
                    $report['site_name']
                )
            ) . '</p>';
        $h .= '</td></tr>';

        // Hero number.
        if ( $hero ) {
            list( $dir, $change ) = self::change_line( $hero['this'], $hero['last'] );
            $pill = array(
                'up'   => array( '#e7f7ee', '#11703f', '&#9650;' ),
                'down' => array( '#fff3e0', '#9a4b00', '&#9660;' ),
                'same' => array( '#eef1f6', '#475775', '&#8226;' ),
            )[ $dir ];
            $h .= '<tr><td class="asgm-pad" style="padding:24px 36px 0;font-family:' . $font . '">';
            $h .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f6f8fc;border:1px solid ' . $line . ';border-radius:16px;border-collapse:separate"><tr><td style="padding:22px 24px;font-family:' . $font . '">';
            $h .= '<p style="margin:0;font-size:13px;font-weight:600;letter-spacing:.04em;text-transform:uppercase;color:' . $muted . '">' . esc_html( $hero['label'] ) . '</p>';
            $h .= '<p class="asgm-hero" style="margin:6px 0 10px;font-size:52px;line-height:1;font-weight:800;letter-spacing:-.02em;color:' . $ink . '">' . esc_html( number_format_i18n( $hero['this'] ) ) . '</p>';
            $h .= '<span style="display:inline-block;padding:5px 11px;border-radius:999px;background:' . $pill[0] . ';color:' . $pill[1] . ';font-size:13px;font-weight:600">' . $pill[2] . ' ' . esc_html( $change ) . '</span>';
            if ( $hero['this'] > 0 && ! empty( $hero['by'] ) ) {
                $h .= '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin-top:16px"><tr><td style="font-size:14px;line-height:1.9;color:#334460;font-family:' . $font . '">';
                $parts = array();
                foreach ( array_slice( $hero['by'], 0, 4 ) as $b ) {
                    $parts[] = '<span style="white-space:nowrap"><strong style="color:' . $ink . '">' . esc_html( $b['name'] ) . '</strong>&nbsp;' . esc_html( number_format_i18n( (int) $b['visits'] ) ) . '</span>';
                }
                // Plain spaces around the dot so the row can wrap on a phone.
                $h .= implode( ' <span style="color:#b6c0d3">&nbsp;&middot;&nbsp;</span> ', $parts );
                $h .= '</td></tr></table>';
            } elseif ( 0 === $hero['this'] ) {
                $h .= '<p style="margin:14px 0 0;font-size:14px;line-height:1.5;color:#334460">' . esc_html( $hero['none'] ) . '</p>';
            }
            $h .= '</td></tr></table></td></tr>';
        }

        // Citations on buyer questions.
        if ( is_array( $report['citations'] ) ) {
            $c = $report['citations'];
            $h .= self::section_open( __( 'Buyer questions', 'aeo-god-mode' ), $font, $muted );
            foreach ( self::citation_lines( $c ) as $l ) {
                $won  = 'won' === $l[0];
                $h .= '<tr><td width="26" valign="top" style="padding:7px 0;font-size:15px;line-height:1.45;color:' . ( $won ? '#11703f' : '#b42318' ) . ';font-family:' . $font . '">' . ( $won ? '&#10003;' : '&#10007;' ) . '</td>';
                $h .= '<td style="padding:7px 0;font-size:15px;line-height:1.45;color:' . $ink . ';font-family:' . $font . '">' . esc_html( $l[1] ) . '</td></tr>';
            }
            $h .= '<tr><td colspan="2" style="padding:6px 0 0;font-size:14px;line-height:1.5;color:' . $muted . ';font-family:' . $font . '">' . esc_html( self::citation_note( $c ) ) . '</td></tr>';
            $h .= self::section_close();
        }

        // Leads.
        if ( is_array( $report['leads'] ) ) {
            $l = $report['leads'];
            list( , $change ) = self::change_line( (int) $l['this'], (int) ( $l['last'] ?? 0 ) );
            $h .= self::section_open( __( 'Leads', 'aeo-god-mode' ), $font, $muted );
            $h .= '<tr><td style="padding:4px 0;font-size:15px;line-height:1.5;color:' . $ink . ';font-family:' . $font . '">'
                . esc_html(
                    sprintf(
                        /* translators: %s: number of leads */
                        __( 'Leads from AI visitors: %s', 'aeo-god-mode' ),
                        number_format_i18n( (int) $l['this'] )
                    )
                ) . ' <span style="color:' . $muted . '">(' . esc_html( $change ) . ')</span></td></tr>';
            $h .= self::section_close();
        }

        // The one fix.
        if ( is_array( $report['fix'] ) ) {
            $f  = $report['fix'];
            $h .= '<tr><td class="asgm-pad" style="padding:26px 36px 0;font-family:' . $font . '">';
            $h .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#0b1b3a" style="background:#0b1b3a;background-image:linear-gradient(135deg,#0b1b3a,#16306b);border-radius:16px;border-collapse:separate"><tr><td style="padding:24px 24px 26px;font-family:' . $font . '">';
            $h .= '<p style="margin:0;font-size:13px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:#9cc3ff">' . esc_html__( 'Your one fix this week', 'aeo-god-mode' ) . '</p>';
            $h .= '<p style="margin:10px 0 18px;font-size:17px;line-height:1.5;color:#ffffff">' . esc_html( $f['body'] ) . '</p>';
            $h .= '<table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr><td bgcolor="#338dff" style="border-radius:10px;background:#338dff">';
            $h .= '<a href="' . esc_url( $f['url'] ) . '" style="display:inline-block;padding:13px 22px;font-size:16px;font-weight:700;color:#ffffff;text-decoration:none;border-radius:10px;font-family:' . $font . '">' . esc_html( $f['cta'] ) . ' &rarr;</a>';
            $h .= '</td></tr></table>';
            $h .= '</td></tr></table></td></tr>';
        }

        // One quiet line on Free about what it cannot see.
        if ( ! empty( $report['upgrade'] ) ) {
            $h .= '<tr><td class="asgm-pad" style="padding:18px 36px 0;font-size:14px;line-height:1.55;color:' . $muted . ';font-family:' . $font . '">'
                . esc_html__( 'Want to see which buyer questions ChatGPT and Perplexity quote you for, and how many visitors they send? That is in AEO God Mode Pro.', 'aeo-god-mode' )
                . ' <a href="' . esc_url( $report['admin_url'] . '#/citations' ) . '" style="color:#1f6fe5">' . esc_html__( 'Take a look', 'aeo-god-mode' ) . '</a></td></tr>';
        }

        // Footer.
        $h .= '<tr><td class="asgm-pad" style="padding:28px 36px 30px;font-family:' . $font . '">';
        $h .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr><td style="border-top:1px solid ' . $line . ';padding-top:18px;font-size:13px;line-height:1.6;color:' . $muted . ';font-family:' . $font . '">';
        $h .= esc_html(
            sprintf(
                /* translators: %s: site name */
                __( "You're getting this because the weekly email is on for %s.", 'aeo-god-mode' ),
                $report['site_name']
            )
        ) . '<br>';
        $h .= '<a href="' . esc_url( $unsub_url ) . '" style="color:' . $muted . ';text-decoration:underline">' . esc_html__( 'Stop these emails', 'aeo-god-mode' ) . '</a>';
        $h .= '&nbsp;&nbsp;&middot;&nbsp;&nbsp;<a href="' . esc_url( $report['admin_url'] . '#/settings?tab=email' ) . '" style="color:' . $muted . ';text-decoration:underline">' . esc_html__( 'Change who gets them', 'aeo-god-mode' ) . '</a>';
        $h .= '</td></tr></table></td></tr>';

        $h .= '</table></td></tr></table></body></html>';
        return $h;
    }

    private static function section_open( $title, $font, $muted ) {
        return '<tr><td class="asgm-pad" style="padding:24px 36px 0;font-family:' . $font . '">'
            . '<p style="margin:0 0 6px;font-size:13px;font-weight:600;letter-spacing:.04em;text-transform:uppercase;color:' . $muted . '">' . esc_html( $title ) . '</p>'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">';
    }

    private static function section_close() {
        return '</table></td></tr>';
    }

    /* ─────────────────────────── Text ─────────────────────────── */

    public static function render_text( array $report, $unsub_url ) {
        $out  = array();
        $out[] = $report['site_name'] . ' (' . self::period_label( $report ) . ')';
        /* translators: %s: site name */
        $out[] = sprintf( __( "Here's how %s did in AI search this week.", 'aeo-god-mode' ), $report['site_name'] );
        $out[] = '';

        $hero = self::hero( $report );
        if ( $hero ) {
            list( , $change ) = self::change_line( $hero['this'], $hero['last'] );
            $out[] = $hero['label'] . ': ' . number_format_i18n( $hero['this'] ) . ' (' . $change . ')';
            if ( $hero['this'] > 0 && ! empty( $hero['by'] ) ) {
                $parts = array();
                foreach ( array_slice( $hero['by'], 0, 4 ) as $b ) {
                    $parts[] = $b['name'] . ' ' . number_format_i18n( (int) $b['visits'] );
                }
                $out[] = implode( ', ', $parts );
            } elseif ( 0 === $hero['this'] ) {
                $out[] = $hero['none'];
            }
            $out[] = '';
        }

        if ( is_array( $report['citations'] ) ) {
            $out[] = strtoupper( __( 'Buyer questions', 'aeo-god-mode' ) );
            foreach ( self::citation_lines( $report['citations'] ) as $l ) {
                $out[] = ( 'won' === $l[0] ? '+ ' : '- ' ) . $l[1];
            }
            $out[] = self::citation_note( $report['citations'] );
            $out[] = '';
        }

        if ( is_array( $report['leads'] ) ) {
            list( , $change ) = self::change_line( (int) $report['leads']['this'], (int) ( $report['leads']['last'] ?? 0 ) );
            /* translators: %s: number of leads */
            $out[] = sprintf( __( 'Leads from AI visitors: %s', 'aeo-god-mode' ), number_format_i18n( (int) $report['leads']['this'] ) ) . ' (' . $change . ')';
            $out[] = '';
        }

        if ( is_array( $report['fix'] ) ) {
            $out[] = strtoupper( __( 'Your one fix this week', 'aeo-god-mode' ) );
            $out[] = $report['fix']['body'];
            $out[] = $report['fix']['cta'] . ': ' . esc_url_raw( $report['fix']['url'] );
            $out[] = '';
        }

        if ( ! empty( $report['upgrade'] ) ) {
            $out[] = __( 'Want to see which buyer questions ChatGPT and Perplexity quote you for, and how many visitors they send? That is in AEO God Mode Pro.', 'aeo-god-mode' );
            $out[] = esc_url_raw( $report['admin_url'] . '#/citations' );
            $out[] = '';
        }

        $out[] = '--';
        /* translators: %s: site name */
        $out[] = sprintf( __( "You're getting this because the weekly email is on for %s.", 'aeo-god-mode' ), $report['site_name'] );
        $out[] = __( 'Stop these emails', 'aeo-god-mode' ) . ': ' . esc_url_raw( $unsub_url );
        $out[] = __( 'Change who gets them', 'aeo-god-mode' ) . ': ' . esc_url_raw( $report['admin_url'] . '#/settings?tab=email' );

        return wp_strip_all_tags( implode( "\n", $out ) );
    }
}
