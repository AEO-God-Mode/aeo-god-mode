<?php
/**
 * Where repurposing appears in wp-admin.
 *
 * Two surfaces, one React component:
 *
 *  - The post editor gets a meta box below the content, because the copy needs
 *    real width. The controls and the live preview sit side by side there,
 *    which is not possible in the ~280px document sidebar.
 *  - The posts list gets a column of platform marks. PHP paints those marks so
 *    the coverage is readable before any script runs; the script only upgrades
 *    them into buttons that open the same panel in a modal.
 *
 * @package AISEOGodMode
 */

namespace AISEOGodMode;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Repurpose_UI {

    const META_KEY = '_asgm_repurpose';

    public function __construct() {
        add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );

        foreach ( array( 'post', 'page' ) as $type ) {
            add_filter( "manage_{$type}_posts_columns", array( $this, 'add_column' ) );
            add_action( "manage_{$type}_posts_custom_column", array( $this, 'render_column' ), 10, 2 );
            add_filter( "bulk_actions-edit-{$type}", array( $this, 'add_bulk_actions' ) );
            add_filter( "handle_bulk_actions-edit-{$type}", array( $this, 'handle_bulk_action' ), 10, 3 );
        }
        add_action( 'admin_notices', array( $this, 'bulk_notice' ) );
    }

    /* ─────────────────────────── Bulk generation ─────────────────────────── */

    public function add_bulk_actions( $actions ) {
        if ( ! $this->is_pro() ) {
            return $actions;
        }
        $actions['asgm_repurpose_x']        = __( 'Repurpose: create X thread', 'aeo-god-mode' );
        $actions['asgm_repurpose_linkedin'] = __( 'Repurpose: create LinkedIn post', 'aeo-god-mode' );
        return $actions;
    }

    /**
     * Generate for each selected post.
     *
     * Deliberately skips posts that already have copy: a bulk run is for
     * filling gaps, and silently spending credits to overwrite work someone
     * edited by hand would be the wrong default. Regenerating stays a
     * per-post, deliberate action.
     */
    public function handle_bulk_action( $redirect, $action, $post_ids ) {
        if ( 0 !== strpos( (string) $action, 'asgm_repurpose_' ) ) {
            return $redirect;
        }
        if ( ! $this->is_pro() || ! class_exists( __NAMESPACE__ . '\\Repurpose' ) ) {
            return $redirect;
        }
        $platform = substr( (string) $action, strlen( 'asgm_repurpose_' ) );
        $done     = 0;
        $skipped  = 0;
        $failed   = 0;

        // Bounded so one click cannot start an unbounded run of paid calls.
        foreach ( array_slice( (array) $post_ids, 0, 20 ) as $post_id ) {
            $post_id = (int) $post_id;
            if ( ! current_user_can( 'edit_post', $post_id ) ) {
                $skipped++;
                continue;
            }
            $existing = Repurpose::get_for_post( $post_id );
            if ( isset( $existing[ $platform ] ) && 'none' !== $existing[ $platform ]['status'] ) {
                $skipped++;
                continue;
            }
            $result = Repurpose::generate( $post_id, $platform, '', 5, 1 );
            if ( is_wp_error( $result ) ) {
                $failed++;
                continue;
            }
            $done++;
        }

        return add_query_arg( array(
            'asgm_rp_done'    => $done,
            'asgm_rp_skipped' => $skipped,
            'asgm_rp_failed'  => $failed,
        ), $redirect );
    }

    public function bulk_notice() {
        if ( ! isset( $_GET['asgm_rp_done'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            return;
        }
        $done    = (int) $_GET['asgm_rp_done']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $skipped = isset( $_GET['asgm_rp_skipped'] ) ? (int) $_GET['asgm_rp_skipped'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $failed  = isset( $_GET['asgm_rp_failed'] ) ? (int) $_GET['asgm_rp_failed'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        $parts = array();
        /* translators: %d: number of posts */
        $parts[] = sprintf( _n( '%d post repurposed.', '%d posts repurposed.', $done, 'aeo-god-mode' ), $done );
        if ( $skipped ) {
            /* translators: %d: number of posts */
            $parts[] = sprintf( _n( '%d skipped, it already had copy.', '%d skipped, they already had copy.', $skipped, 'aeo-god-mode' ), $skipped );
        }
        if ( $failed ) {
            /* translators: %d: number of posts */
            $parts[] = sprintf( _n( '%d failed.', '%d failed.', $failed, 'aeo-god-mode' ), $failed );
        }
        printf(
            '<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
            $failed ? 'warning' : 'success',
            esc_html( implode( ' ', $parts ) )
        );
    }

    /** Post types worth repurposing: public, with an editor. */
    private function supported_types() {
        return array( 'post', 'page' );
    }

    private function is_pro() {
        return class_exists( __NAMESPACE__ . '\\License' ) && License::is_pro();
    }

    /* ─────────────────────────── Editor meta box ─────────────────────────── */

    public function add_meta_box() {
        foreach ( $this->supported_types() as $type ) {
            add_meta_box(
                'asgm-repurpose',
                __( 'Repurpose', 'aeo-god-mode' ),
                array( $this, 'render_meta_box' ),
                $type,
                'normal',
                'default'
            );
        }
    }

    public function render_meta_box( $post ) {
        // The panel mounts here. Nothing is printed server side, so the box is
        // empty until the script runs; that is deliberate, because a static
        // copy of the panel would be a second thing to keep in step.
        echo '<div id="asgm-repurpose-root" data-post="' . esc_attr( (int) $post->ID ) . '"></div>';
    }

    /* ─────────────────────────── Posts list column ─────────────────────────── */

    public function add_column( $columns ) {
        // Sits before the date so it reads as part of the post's own status.
        $out = array();
        foreach ( $columns as $key => $label ) {
            if ( 'date' === $key ) {
                $out['asgm_repurpose'] = __( 'Repurpose', 'aeo-god-mode' );
            }
            $out[ $key ] = $label;
        }
        if ( ! isset( $out['asgm_repurpose'] ) ) {
            $out['asgm_repurpose'] = __( 'Repurpose', 'aeo-god-mode' );
        }
        return $out;
    }

    public function render_column( $column, $post_id ) {
        if ( 'asgm_repurpose' !== $column ) {
            return;
        }
        $is_pro = $this->is_pro();
        $states = class_exists( __NAMESPACE__ . '\\Repurpose' )
            ? Repurpose::get_for_post( $post_id )
            : array();

        echo '<span class="asgm-rp-col">';
        foreach ( array( 'x' => 'X', 'linkedin' => 'LinkedIn', 'youtube' => 'YouTube' ) as $key => $label ) {
            $state = isset( $states[ $key ] ) ? $states[ $key ] : array( 'status' => 'none', 'stale' => false );
            $done  = ( 'none' !== ( $state['status'] ?? 'none' ) );
            $stale = ! empty( $state['stale'] );

            $class = 'asgm-rp-col__btn';
            if ( $stale ) {
                $class .= ' is-stale';
            } elseif ( $done ) {
                $class .= ' is-done';
            }

            if ( ! $is_pro ) {
                /* translators: %s: platform name */
                $title = sprintf( __( 'Turn this post into %s content. Available on Pro.', 'aeo-god-mode' ), $label );
            } elseif ( $stale ) {
                /* translators: %s: platform name */
                $title = sprintf( __( '%s copy is out of date, the post changed since it was written.', 'aeo-god-mode' ), $label );
            } elseif ( $done ) {
                /* translators: %s: platform name */
                $title = sprintf( __( 'Edit the %s copy for this post.', 'aeo-god-mode' ), $label );
            } else {
                /* translators: %s: platform name */
                $title = sprintf( __( 'Create %s content from this post.', 'aeo-god-mode' ), $label );
            }

            printf(
                '<button type="button" class="%s" data-post="%d" data-platform="%s" title="%s" aria-label="%s"%s>%s</button>',
                esc_attr( $class ),
                (int) $post_id,
                esc_attr( $key ),
                esc_attr( $title ),
                esc_attr( $title ),
                $is_pro ? '' : ' disabled',
                self::mark( $key ) // phpcs:ignore WordPress.Security.EscapeOutput -- fixed inline SVG below.
            );
        }
        echo '</span>';
    }

    /** Fixed inline SVG marks. No user input reaches these. */
    private static function mark( $platform ) {
        $paths = array(
            'x'        => 'M18.24 2.25h3.31l-7.23 8.26 8.5 11.24h-6.66l-5.21-6.82-5.97 6.82H1.66l7.73-8.84L1.25 2.25h6.83l4.71 6.23 5.45-6.23Zm-1.16 17.52h1.83L7.02 4.13H5.06l12.02 15.64Z',
            'linkedin' => 'M20.45 20.45h-3.56v-5.57c0-1.33-.02-3.04-1.85-3.04-1.85 0-2.14 1.45-2.14 2.94v5.67H9.35V9h3.41v1.56h.05c.48-.9 1.63-1.85 3.36-1.85 3.6 0 4.27 2.37 4.27 5.45v6.29ZM5.34 7.43a2.06 2.06 0 1 1 0-4.13 2.06 2.06 0 0 1 0 4.13Zm1.78 13.02H3.56V9h3.56v11.45ZM22.22 0H1.77C.79 0 0 .77 0 1.72v20.56C0 23.23.79 24 1.77 24h20.45c.98 0 1.78-.77 1.78-1.72V1.72C24 .77 23.2 0 22.22 0Z',
            'youtube'  => 'M23.5 6.19a3.02 3.02 0 0 0-2.12-2.14C19.5 3.55 12 3.55 12 3.55s-7.5 0-9.38.5A3.02 3.02 0 0 0 .5 6.19C0 8.08 0 12 0 12s0 3.92.5 5.81a3.02 3.02 0 0 0 2.12 2.14c1.88.5 9.38.5 9.38.5s7.5 0 9.38-.5a3.02 3.02 0 0 0 2.12-2.14C24 15.92 24 12 24 12s0-3.92-.5-5.81ZM9.55 15.57V8.43L15.82 12l-6.27 3.57Z',
        );
        $path = $paths[ $platform ] ?? $paths['x'];
        return '<svg width="15" height="15" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" focusable="false"><path d="' . $path . '"></path></svg>';
    }

    /* ─────────────────────────── Assets ─────────────────────────── */

    public function enqueue( $hook ) {
        $on_editor = in_array( $hook, array( 'post.php', 'post-new.php' ), true );
        $on_list   = ( 'edit.php' === $hook );
        if ( ! $on_editor && ! $on_list ) {
            return;
        }
        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        if ( $screen && ! in_array( $screen->post_type, $this->supported_types(), true ) ) {
            return;
        }

        $manifest_path = ASGM_PLUGIN_DIR . 'assets/repurpose/.vite/manifest.json';
        if ( ! file_exists( $manifest_path ) ) {
            return;
        }
        $manifest = json_decode( (string) file_get_contents( $manifest_path ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions
        $entry    = $manifest['src/repurpose-list.tsx'] ?? null;
        if ( ! is_array( $entry ) || empty( $entry['file'] ) ) {
            return;
        }

        $base = ASGM_PLUGIN_URL . 'assets/repurpose/';
        wp_enqueue_script(
            'asgm-repurpose',
            $base . $entry['file'],
            array( 'wp-element', 'wp-api-fetch' ),
            ASGM_VERSION,
            true
        );
        /*
         * With cssCodeSplit off, Vite emits one stylesheet under its own
         * "style.css" manifest key rather than listing it on the entry, so
         * both shapes are checked. Missing the stylesheet leaves the column
         * marks styled as raw browser buttons.
         */
        $styles = (array) ( $entry['css'] ?? array() );
        if ( empty( $styles ) && ! empty( $manifest['style.css']['file'] ) ) {
            $styles[] = (string) $manifest['style.css']['file'];
        }
        foreach ( $styles as $css ) {
            wp_enqueue_style( 'asgm-repurpose', $base . $css, array(), ASGM_VERSION );
        }

        // Titles and permalinks for the rows on screen, so the modal can label
        // itself and build share links without another request.
        $posts = array();
        if ( $on_list ) {
            global $wp_query;
            foreach ( (array) ( $wp_query->posts ?? array() ) as $p ) {
                $posts[ (string) $p->ID ] = array(
                    'title' => get_the_title( $p ),
                    'url'   => (string) get_permalink( $p ),
                );
            }
        } else {
            $id = isset( $_GET['post'] ) ? (int) $_GET['post'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            if ( $id ) {
                $posts[ (string) $id ] = array(
                    'title' => get_the_title( $id ),
                    'url'   => (string) get_permalink( $id ),
                );
            }
        }

        wp_localize_script( 'asgm-repurpose', 'asgmRepurposeList', array(
            'isPro' => $this->is_pro(),
            'posts' => $posts,
        ) );
    }
}
