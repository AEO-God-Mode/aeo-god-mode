<?php
/**
 * Bulk Metadata actions on the WordPress Posts list.
 *
 * Adds three AEO-God-Mode-branded bulk actions to every public post type's
 * list table (Posts, Pages, Products, Downloads, custom CPTs):
 *
 *   AEO God Mode
 *     Write AEO Title + Meta  (2 credits per post)
 *     Write AEO Titles Only   (1 credit per post)
 *     Write AEO Meta Only     (1 credit per post)
 *
 * Selecting an action runs generation right there on the Posts list. A
 * progress overlay shows what is happening, processes the selection a few
 * posts at a time, reports any items that were skipped or failed, then
 * refreshes the list so the new titles and descriptions are visible. No
 * redirect to a separate screen.
 *
 * Generation flows through the same two endpoints the dashboard uses:
 *   POST /metadata/generate  writes nothing, returns the AI output, and is
 *                            where credits are charged server-side.
 *   POST /metadata/accept    saves the chosen fields. Empty fields are
 *                            skipped, so a Titles Only run never clears an
 *                            existing description and a Meta Only run never
 *                            clears an existing title.
 *
 * Differentiator vs Rank Math's bulk AI actions: ours generate answer-first
 * metadata tuned for AI engine extraction (BLUFF method, anti-AI-writing word
 * exclusions, year-aware, 145 to 158 char tightness band). Optimised to be
 * cited by ChatGPT, Perplexity and Google AI Overviews, not just ranked.
 *
 * Free tier uses the local 10-credit monthly allowance enforced server-side
 * in the AI proxy via asgm_ai_task_credit_cost(). Pro tier uses the 500
 * monthly allowance. The cost map is the single source of truth, so the
 * action labels here are descriptive and the actual charging happens
 * server-side on /metadata/generate.
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
     * Action prefix. Used to detect our actions and to keep our keys from
     * colliding with WordPress core or other plugins.
     */
    const ACTION_PREFIX = 'aeogm_meta_';

    /**
     * Action key to AI task type. The task type is sent to /metadata/generate
     * and read by asgm_ai_task_credit_cost() to charge the right number of
     * credits (combined = 2, titles = 1, meta only = 1).
     *
     * @var array
     */
    private static $action_tasks = array(
        'aeogm_meta_combined' => 'metadata',
        'aeogm_meta_titles'   => 'titles',
        'aeogm_meta_only'     => 'meta_only',
    );

    public function __construct() {
        add_action( 'admin_init', array( $this, 'register_bulk_actions' ) );
        add_action( 'admin_footer-edit.php', array( $this, 'inject_progress_ui' ) );
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
        }
    }

    /**
     * Inject the AEO God Mode actions into a list table's bulk-action menu.
     *
     * @param array $actions Existing bulk actions.
     * @return array
     */
    public function add_bulk_actions( $actions ) {
        // Nested array renders as an <optgroup> (WP 5.6+). The group label is
        // greyed out and cannot be selected, same as Rank Math's separators.
        $actions[ '⚡️ ' . __( 'AEO God Mode', 'aeo-god-mode' ) ] = array(
            'aeogm_meta_combined' => '⚡️ ' . __( 'Write AEO Title + Meta (2 credits/post)', 'aeo-god-mode' ),
            'aeogm_meta_titles'   => '⚡️ ' . __( 'Write AEO Titles Only (1 credit/post)', 'aeo-god-mode' ),
            'aeogm_meta_only'     => '⚡️ ' . __( 'Write AEO Meta Only (1 credit/post)', 'aeo-god-mode' ),
        );

        return $actions;
    }

    /**
     * Print the JavaScript that intercepts our bulk actions and runs the
     * generation in place with a progress overlay. Only loaded on the
     * edit.php list-table screen.
     */
    public function inject_progress_ui() {
        if ( ! current_user_can( 'edit_posts' ) ) {
            return;
        }

        $nonce        = wp_create_nonce( 'wp_rest' );
        $generate_url = esc_url_raw( rest_url( self::ADMIN_PAGE_SLUG . '/v1/metadata/generate' ) );
        $accept_url   = esc_url_raw( rest_url( self::ADMIN_PAGE_SLUG . '/v1/metadata/accept' ) );
        ?>
        <script>
        ( function() {
            document.addEventListener( 'DOMContentLoaded', function() {
                var form = document.getElementById( 'posts-filter' );
                if ( ! form ) {
                    return;
                }

                var GENERATE_URL = '<?php echo esc_url_raw( $generate_url ); ?>';
                var ACCEPT_URL   = '<?php echo esc_url_raw( $accept_url ); ?>';
                var NONCE        = '<?php echo esc_js( $nonce ); ?>';

                // Action key to generation settings. cost is per item and
                // mirrors the server-side credit cost map.
                var AEO_ACTIONS = {
                    'aeogm_meta_combined': { task: 'metadata',  cost: 2, doing: 'AEO titles and meta descriptions' },
                    'aeogm_meta_titles':   { task: 'titles',    cost: 1, doing: 'AEO titles' },
                    'aeogm_meta_only':     { task: 'meta_only', cost: 1, doing: 'AEO meta descriptions' }
                };

                form.addEventListener( 'submit', function( e ) {
                    var sel1 = document.getElementById( 'bulk-action-selector-top' );
                    var sel2 = document.getElementById( 'bulk-action-selector-bottom' );
                    var v1   = sel1 ? sel1.value : '';
                    var v2   = sel2 ? sel2.value : '';
                    var action = AEO_ACTIONS[ v1 ] ? v1 : ( AEO_ACTIONS[ v2 ] ? v2 : '' );

                    // Not one of our generation actions (could be the header
                    // separator or a core action). Let WordPress handle it.
                    if ( ! action ) {
                        return;
                    }

                    e.preventDefault();
                    var cfg = AEO_ACTIONS[ action ];

                    var checks  = document.querySelectorAll( 'input[name="post[]"]:checked' );
                    var postIds = [];
                    checks.forEach( function( cb ) { postIds.push( cb.value ); } );

                    if ( postIds.length === 0 ) {
                        alert( 'Select at least one item first, then choose an AEO God Mode action.' );
                        return;
                    }

                    // ----- Overlay + modal -----
                    if ( ! document.getElementById( 'aeo-bulk-style' ) ) {
                        var style = document.createElement( 'style' );
                        style.id = 'aeo-bulk-style';
                        style.innerHTML = '@keyframes aeo-shimmer { 0% { background-position: -1000px 0; } 100% { background-position: 1000px 0; } } .aeo-shimmer-bar { background: linear-gradient(90deg, #2563eb 25%, #60a5fa 50%, #2563eb 75%) !important; background-size: 1000px 100% !important; animation: aeo-shimmer 2s infinite linear !important; }';
                        document.head.appendChild( style );
                    }

                    var overlay = document.createElement( 'div' );
                    overlay.style.cssText = 'position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(15,23,42,0.85);backdrop-filter:blur(4px);z-index:999999;display:flex;align-items:center;justify-content:center;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Oxygen-Sans,Ubuntu,Cantarell,"Helvetica Neue",sans-serif;';

                    var modal = document.createElement( 'div' );
                    modal.style.cssText = 'background:#1e1e2d;padding:40px;border-radius:16px;width:460px;max-width:92vw;text-align:center;box-shadow:0 25px 50px -12px rgba(0,0,0,0.5);color:#fff;border:1px solid #333346;';

                    var title = document.createElement( 'h2' );
                    title.innerText = 'AEO God Mode';
                    title.style.cssText = 'margin-top:0;color:#fff;font-size:22px;margin-bottom:12px;text-shadow:0 0 15px rgba(79,70,229,0.4);font-weight:600;';

                    var text = document.createElement( 'p' );
                    text.innerText = 'Generating ' + cfg.doing + ' for ' + postIds.length + ' item(s). Uses up to ' + ( postIds.length * cfg.cost ) + ' AI credit(s).';
                    text.style.color = '#94a3b8';
                    text.style.fontSize = '15px';
                    text.style.marginBottom = '28px';

                    var progressContainer = document.createElement( 'div' );
                    progressContainer.style.cssText = 'width:100%;height:12px;background:#333346;border-radius:6px;overflow:hidden;margin-bottom:15px;position:relative;';

                    var progressBar = document.createElement( 'div' );
                    progressBar.style.cssText = 'height:100%;width:0%;transition:width 0.4s cubic-bezier(0.4, 0, 0.2, 1);border-radius:6px;min-width:5%;';
                    progressBar.className = 'aeo-shimmer-bar';

                    var status = document.createElement( 'div' );
                    status.innerText = '0 / ' + postIds.length + ' (Starting...)';
                    status.style.cssText = 'font-size:14px;font-weight:500;color:#cbd5e1;';

                    var loadingText = document.createElement( 'div' );
                    loadingText.innerText = 'Connecting to AI...';
                    loadingText.style.cssText = 'font-size:13px;color:#94a3b8;font-style:italic;margin-top:4px;';

                    var errorLog = document.createElement( 'div' );
                    errorLog.style.cssText = 'margin-top:15px;max-height:100px;overflow-y:auto;text-align:left;font-size:12px;color:#f87171;';

                    progressContainer.appendChild( progressBar );
                    modal.appendChild( title );
                    modal.appendChild( text );
                    modal.appendChild( progressContainer );
                    modal.appendChild( status );
                    modal.appendChild( loadingText );
                    modal.appendChild( errorLog );
                    overlay.appendChild( modal );
                    document.body.appendChild( overlay );

                    // ----- Async loop with a small concurrency limit -----
                    var current = 0, completed = 0, errors = 0, skipped = 0, active = 0;
                    var concurrencyLimit = 4;

                    var loadingPhrases = [
                        'Connecting to AI...',
                        'Analyzing content...',
                        'Extracting entities...',
                        'Writing answer-first metadata...',
                        'Optimizing for AI engines...'
                    ];
                    var phraseIdx = 0;
                    var loadingInterval = setInterval( function() {
                        if ( active > 0 || current < postIds.length ) {
                            phraseIdx = ( phraseIdx + 1 ) % loadingPhrases.length;
                            loadingText.innerText = loadingPhrases[ phraseIdx ];
                        }
                    }, 2500 );

                    function logErrorMsg( pid, msg ) {
                        var line = document.createElement( 'div' );
                        line.style.marginBottom = '4px';
                        line.innerText = 'ID ' + pid + ': ' + msg;
                        errorLog.appendChild( line );
                        errorLog.scrollTop = errorLog.scrollHeight;
                    }

                    function updateProgress() {
                        var percent = ( completed / postIds.length ) * 100;
                        progressBar.style.width = Math.max( percent, 5 ) + '%';
                        status.innerText = completed + ' / ' + postIds.length + ( active > 0 ? ' (Generating...)' : '' );
                    }

                    function processNext() {
                        if ( completed >= postIds.length && active === 0 ) {
                            clearInterval( loadingInterval );
                            loadingText.style.display = 'none';
                            progressBar.className = '';
                            progressBar.style.background = errors > 0 ? '#f59e0b' : '#10b981';
                            progressBar.style.width = '100%';
                            var updated = postIds.length - errors - skipped;
                            text.innerText = 'Done. Updated ' + updated + ', skipped ' + skipped + ', failed ' + errors + '. Refreshing...';
                            text.style.color = errors > 0 ? '#f59e0b' : '#10b981';
                            setTimeout( function() { window.location.reload(); }, 2500 );
                            return;
                        }

                        while ( active < concurrencyLimit && current < postIds.length ) {
                            var pid = postIds[ current ];
                            current++;
                            active++;
                            updateProgress();
                            processItem( pid );
                        }
                    }

                    function processItem( pid ) {
                        function finishItem() {
                            active--;
                            completed++;
                            updateProgress();
                            processNext();
                        }

                        fetch( GENERATE_URL, {
                            method: 'POST',
                            credentials: 'same-origin',
                            headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': NONCE },
                            body: JSON.stringify( { post_ids: [ pid ], style: 'smart_mix', task_type: cfg.task } )
                        } )
                        .then( function( res ) { return res.json(); } )
                        .then( function( data ) {
                            var item = data && data.results ? data.results[ 0 ] : null;

                            if ( ! data.success || ! item || ! item.success ) {
                                var err = ( item && item.error ) ? item.error : ( data.error || data.message || 'Generation failed' );
                                if ( item && item.error && item.error.indexOf( 'Skipped' ) !== -1 ) {
                                    skipped++;
                                } else {
                                    errors++;
                                    logErrorMsg( pid, err );
                                }
                                finishItem();
                                return;
                            }

                            // The proxy may return result as an object or a
                            // JSON string. Normalise to an object.
                            var gen = item.result;
                            if ( typeof gen === 'string' ) {
                                try {
                                    gen = JSON.parse( gen.replace( /```json\n?|```/g, '' ).trim() );
                                } catch ( ex ) {
                                    errors++;
                                    logErrorMsg( pid, 'Could not read AI output' );
                                    finishItem();
                                    return;
                                }
                            }
                            gen = gen || {};

                            // Save via accept. Empty fields are skipped server
                            // side, so titles only never clears a description
                            // and meta only never clears a title.
                            fetch( ACCEPT_URL, {
                                method: 'POST',
                                credentials: 'same-origin',
                                headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': NONCE },
                                body: JSON.stringify( { items: [ {
                                    post_id: pid,
                                    meta_title: gen.meta_title || '',
                                    meta_description: gen.meta_description || '',
                                    product_description: gen.product_description || ''
                                } ] } )
                            } )
                            .then( function( res ) { return res.json(); } )
                            .then( function( save ) {
                                if ( ! save.success ) {
                                    errors++;
                                    logErrorMsg( pid, 'Could not save metadata' );
                                }
                                finishItem();
                            } )
                            .catch( function() {
                                errors++;
                                logErrorMsg( pid, 'Network error while saving' );
                                finishItem();
                            } );
                        } )
                        .catch( function() {
                            errors++;
                            logErrorMsg( pid, 'Network error while generating' );
                            finishItem();
                        } );
                    }

                    processNext();
                } );
            } );
        } )();
        </script>
        <?php
    }

    /**
     * REST helper exposed to the admin app. Kept for backward compatibility
     * with the /metadata/bulk-payload route registered in class-api.php.
     * The Posts list no longer hands off through a transient (generation now
     * runs in place), so this returns null unless an older flow set one.
     *
     * @param string $token Token from the URL.
     * @return array|null Payload or null if expired, invalid or wrong user.
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
     * Build a transient key that is both token-scoped and user-scoped.
     */
    private static function transient_key_for_user( $token ) {
        return 'aeogm_bulk_meta_' . get_current_user_id() . '_' . substr( $token, 0, 32 );
    }
}
