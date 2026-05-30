<?php
/**
 * Bulk Metadata actions on the WordPress Posts list.
 *
 * Adds three AEO-God-Mode-branded bulk actions to every public post type's
 * list table (Posts, Pages, Products, Downloads, custom CPTs):
 *
 *   ↓ AEO God Mode
 *     Write AEO Title + Meta  (2 credits per post)
 *     Write AEO Titles Only   (1 credit per post)
 *     Write AEO Meta Only     (1 credit per post)
 *
 * Selecting an action does NOT run AI inline. Instead the user is redirected
 * to our React admin Metadata page with the selected posts pre-loaded so they
 * can review the generated output BEFORE accepting (no silent overwrites of
 * carefully-crafted existing meta titles or descriptions).
 *
 * Differentiator vs Rank Math's bulk AI actions: ours generate answer-first
 * metadata tuned for AI engine extraction (BLUFF method, anti-AI-writing word
 * exclusions, year-aware, 145-158 char tightness band). Optimised to be
 * cited by ChatGPT / Perplexity / Google AI Overviews, not just ranked.
 *
 * Free tier uses the local 10-credit monthly allowance enforced server-side
 * in the AI proxy via asgm_ai_task_credit_cost(). Pro tier uses the 500
 * monthly allowance. The cost map is the single source of truth, so the
 * action labels here are descriptive — actual charging happens server-side
 * when the customer clicks Generate in the Metadata UI.
 *
 * @package AISEOGodMode
 * @since   1.6.9
 */

namespace AISEOGodMode;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class BulkMeta {

    /**
     * Slug of our admin page (registered in class-main.php).
     */
    const ADMIN_PAGE_SLUG = 'aeo-god-mode';

    /**
     * Action prefix. Used to detect our actions in the bulk handler and to
     * keep our keys from colliding with WordPress core or other plugins.
     */
    const ACTION_PREFIX = 'aeogm_meta_';

    /**
     * Action → mode mapping. The mode value is sent in the AI proxy payload
     * (`mode` field) and read by asgm_ai_task_credit_cost() to charge the
     * right number of credits.
     *
     * @var array
     */
    private static $action_modes = array(
        'aeogm_meta_combined' => 'combined',
        'aeogm_meta_titles'   => 'titles_only',
        'aeogm_meta_only'     => 'meta_only',
    );

    public function __construct() {
        add_action( 'admin_init', array( $this, 'register_bulk_actions' ) );
        add_action( 'admin_notices', array( $this, 'maybe_show_notice' ) );
    }

    /**
     * Register the bulk-action filter for every public post type.
     *
     * Excludes `attachment` (Media library has its own bulk semantics) and
     * any post type that opts out via the `aeogm_bulk_meta_post_types` filter
     * (third-party plugins can deny-list custom types they own).
     */
    public function register_bulk_actions() {
        $post_types = get_post_types( array( 'public' => true ), 'names' );
        unset( $post_types['attachment'] );

        /**
         * Filter the post types that receive AEO God Mode bulk actions.
         *
         * @since 1.6.9
         * @param string[] $post_types Post type names.
         */
        $post_types = apply_filters( 'aeogm_bulk_meta_post_types', $post_types );

        foreach ( $post_types as $pt ) {
            add_filter( "bulk_actions-edit-{$pt}", array( $this, 'add_bulk_actions' ), 20 );
            add_filter( "handle_bulk_actions-edit-{$pt}", array( $this, 'handle_bulk_action' ), 10, 3 );
        }
    }

    /**
     * Inject the AEO God Mode actions into a list table's bulk-action menu.
     *
     * @param array $actions Existing bulk actions.
     * @return array
     */
    public function add_bulk_actions( $actions ) {
        // Brand header. Disabled-looking visually but still selectable; the
        // bulk handler treats it as a no-op so accidentally clicking Apply
        // with this selected does nothing instead of throwing an error.
        $aeogm_actions = array(
            'aeogm_meta_header'   => '── ' . __( 'AEO God Mode', 'aeo-god-mode' ) . ' ──',
            'aeogm_meta_combined' => __( 'Write AEO Title + Meta (2 credits/post)', 'aeo-god-mode' ),
            'aeogm_meta_titles'   => __( 'Write AEO Titles Only (1 credit/post)', 'aeo-god-mode' ),
            'aeogm_meta_only'     => __( 'Write AEO Meta Only (1 credit/post)', 'aeo-god-mode' ),
        );

        return array_merge( $actions, $aeogm_actions );
    }

    /**
     * Handle an AEO bulk action click.
     *
     * WP's bulk-action machinery already nonce-verifies the request before
     * this filter runs, so we focus on:
     *   - confirming the user can edit posts (capability gate)
     *   - filtering to a clean integer list of post IDs
     *   - mapping the action key to the AI proxy mode
     *   - redirecting to our React admin Metadata page with the selection
     *     pre-loaded for review
     *
     * Returning the original $redirect_url unchanged is the WP convention
     * for "not my action" — keeps other bulk handlers running normally.
     *
     * @param string $redirect_url The URL WP would otherwise redirect to.
     * @param string $action       The selected bulk action key.
     * @param int[]  $post_ids     IDs the user ticked.
     * @return string
     */
    public function handle_bulk_action( $redirect_url, $action, $post_ids ) {
        // Header is a non-action; treat as no-op silently.
        if ( 'aeogm_meta_header' === $action ) {
            return $redirect_url;
        }

        if ( ! isset( self::$action_modes[ $action ] ) ) {
            return $redirect_url;
        }

        if ( ! current_user_can( 'edit_posts' ) ) {
            // No capability — let WP show its standard "you cannot do this"
            // error rather than us inventing a custom one.
            return $redirect_url;
        }

        $mode     = self::$action_modes[ $action ];
        $post_ids = array_values( array_filter( array_map( 'absint', (array) $post_ids ) ) );

        if ( empty( $post_ids ) ) {
            return add_query_arg( 'aeogm_bulk', 'no_posts', $redirect_url );
        }

        // Hand-off via a short-lived transient keyed to the current user.
        // Avoids 2KB-URL-length problems on large selections, and keeps the
        // post IDs out of the address bar (cleaner UX, less to copy-paste
        // wrong into a support ticket).
        $token   = wp_generate_password( 20, false, false );
        $payload = array(
            'mode'     => $mode,
            'post_ids' => $post_ids,
            'created'  => time(),
        );
        set_transient( $this->transient_key_for_user( $token ), $payload, 5 * MINUTE_IN_SECONDS );

        $admin_url = admin_url( 'admin.php?page=' . self::ADMIN_PAGE_SLUG );
        $hash      = '#/metadata?from=bulk&token=' . rawurlencode( $token );

        wp_safe_redirect( $admin_url . $hash );
        exit;
    }

    /**
     * Show an admin notice for the "no posts selected" edge case so the user
     * understands why nothing happened. Other failure modes (capability,
     * unknown action) are handled by WP's own admin notice plumbing.
     */
    public function maybe_show_notice() {
        if ( ! isset( $_GET['aeogm_bulk'] ) ) {
            return;
        }
        $key = sanitize_key( wp_unslash( $_GET['aeogm_bulk'] ) );
        if ( 'no_posts' !== $key ) {
            return;
        }
        printf(
            '<div class="notice notice-warning is-dismissible"><p>%s</p></div>',
            esc_html__( 'Select at least one post before running an AEO God Mode bulk action.', 'aeo-god-mode' )
        );
    }

    /**
     * REST endpoint helper exposed to our React admin so it can fetch the
     * IDs + mode from the transient using the URL token. Called from the
     * Metadata page when ?from=bulk&token=... is present in the URL.
     *
     * Returns the payload and deletes the transient so the token is one-use.
     *
     * @param string $token Token from the URL.
     * @return array|null Payload or null if expired / invalid / wrong user.
     */
    public static function consume_bulk_payload( $token ) {
        $token = sanitize_text_field( $token );
        if ( '' === $token ) {
            return null;
        }
        $key     = self::transient_key_for_user( $token );
        $payload = get_transient( $key );
        if ( ! is_array( $payload ) ) {
            return null;
        }
        delete_transient( $key );
        return $payload;
    }

    /**
     * Build a transient key that's both token-scoped AND user-scoped, so a
     * URL someone copies cannot be used by a different admin to pop the
     * other admin's selection.
     */
    private static function transient_key_for_user( $token ) {
        return 'aeogm_bulk_meta_' . get_current_user_id() . '_' . substr( $token, 0, 32 );
    }

    /**
     * Instance helper that proxies to the static version (used by the
     * REST endpoint registration in class-api.php).
     */
    private function transient_key_for_user_inst( $token ) {
        return self::transient_key_for_user( $token );
    }
}
