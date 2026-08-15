<?php
/**
 * Plugin conflict detection and resolution.
 *
 * @package AISEOGodMode
 */

namespace AISEOGodMode;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Detects and manages conflicts with other SEO plugins.
 */
class Conflict {

    /** Exact rollback record for the physical llms.txt moved at takeover. */
    const LLMS_PRESERVED_OPT = 'asgm_llms_preserved_file';

    /**
     * Conflict types to check.
     *
     * @var array
     */
    private $checks = array(
        'meta_title',
        'meta_description',
        'schema_output',
        'robots_txt',
        'sitemap',
        'canonical',
        'og_tags',
        'llms_txt_file',
    );

    /**
     * Run a full conflict scan.
     *
     * @return array
     */
    public function scan() {
        $detector = new Detector();
        $detected = $detector->scan();
        $conflicts = array();

        foreach ( $this->checks as $check ) {
            $result = $this->check_conflict( $check, $detected );
            if ( $result ) {
                $conflicts[] = $result;
            }
        }

        $saved_resolutions = get_option( 'asgm_conflict_resolutions', array() );

        foreach ( $conflicts as &$conflict ) {
            if ( isset( $saved_resolutions[ $conflict['id'] ] ) ) {
                $conflict['resolution'] = $saved_resolutions[ $conflict['id'] ];
            }
        }

        return array(
            'conflicts'   => $conflicts,
            'total'       => count( $conflicts ),
            'has_errors'  => $this->has_severity( $conflicts, 'error' ),
            'has_warnings' => $this->has_severity( $conflicts, 'warning' ),
        );
    }

    /**
     * Resolve a specific conflict.
     *
     * @param string $id         Conflict ID.
     * @param string $resolution Resolution type (defer, override, merge).
     * @return array
     */
    public function resolve( $id, $resolution ) {
        $valid = array( 'defer', 'override', 'merge' );
        if ( ! in_array( $resolution, $valid, true ) ) {
            return array( 'success' => false, 'message' => __( 'Invalid resolution type.', 'aeo-god-mode' ) );
        }

        // The llms.txt clash is the one conflict with a physical cause, so
        // recording a preference is not enough: the file has to actually move
        // or nothing changes for the visitor. Renaming rather than deleting
        // keeps the other plugin's content, and sends it to the very filename
        // our wpseo_llmstxt_filesystem_path filter already points future
        // writes at, so the two stay consistent.
        $extra = array();
        if ( 'llms_txt_file' === $id && 'override' === $resolution ) {
            $moved = $this->move_stray_llms_txt();
            if ( ! $moved['success'] ) {
                return $moved;
            }
            delete_transient( 'asgm_llms_txt' );
            // Carry the outcome back so the UI can confirm what actually
            // happened rather than just going quiet.
            $extra = array(
                'message'  => $moved['message'] ?? '',
                'moved_to' => $moved['moved_to'] ?? '',
                'verify_url' => add_query_arg( 'asgm_verify', time(), home_url( '/llms.txt' ) ),
            );
        } elseif ( 'llms_txt_file' === $id && 'defer' === $resolution ) {
            $restored = $this->restore_other_llms_txt();
            if ( ! $restored['success'] ) {
                return $restored;
            }
            $extra = array(
                'message'    => $restored['message'] ?? '',
                'verify_url' => add_query_arg( 'asgm_verify', time(), home_url( '/llms.txt' ) ),
            );
        }

        $resolutions = get_option( 'asgm_conflict_resolutions', array() );
        $resolutions[ $id ] = $resolution;
        update_option( 'asgm_conflict_resolutions', $resolutions );

        return array_merge( array(
            'success'    => true,
            'id'         => $id,
            'resolution' => $resolution,
        ), $extra );
    }

    /**
     * Look for a physical llms.txt sitting in the web root.
     *
     * Ours is virtual, so any real file here shadows it. Reads the first part
     * of the file to name the owner, because "some file exists" is far less
     * useful to the customer than "Yoast wrote this".
     *
     * @return array|null array{path:string,owner:string} or null when clear.
     */
    private function find_stray_llms_txt() {
        $roots = array();
        if ( function_exists( 'get_home_path' ) ) {
            $roots[] = get_home_path();
        }
        $roots[] = ABSPATH;
        if ( ! empty( $_SERVER['DOCUMENT_ROOT'] ) ) {
            $roots[] = trailingslashit( sanitize_text_field( wp_unslash( $_SERVER['DOCUMENT_ROOT'] ) ) );
        }

        foreach ( array_unique( array_filter( $roots ) ) as $root ) {
            $file = trailingslashit( $root ) . 'llms.txt';
            if ( ! @file_exists( $file ) || ! @is_readable( $file ) ) {
                continue;
            }

            $head  = (string) @file_get_contents( $file, false, null, 0, 2048 );
            $owner = __( 'another plugin', 'aeo-god-mode' );
            if ( false !== stripos( $head, 'Yoast' ) ) {
                $owner = 'Yoast SEO';
            } elseif ( false !== stripos( $head, 'Rank Math' ) ) {
                $owner = 'Rank Math';
            } elseif ( false !== stripos( $head, 'AEO God Mode' ) ) {
                // Ours, written out deliberately by the site owner. Not a clash.
                continue;
            }

            return array( 'path' => $file, 'owner' => $owner );
        }
        return null;
    }

    /**
     * Move a shadowing llms.txt aside so ours can serve.
     *
     * Renames rather than deletes. The destination matches where our Yoast
     * filter sends future writes, so the other plugin keeps its content at a
     * stable path and nothing is thrown away. Refuses to touch a file we
     * wrote ourselves, and refuses if a rename would clobber something.
     *
     * @return array
     */
    private function move_stray_llms_txt() {
        $stray = $this->find_stray_llms_txt();
        if ( ! $stray ) {
            return array( 'success' => true, 'message' => __( 'Nothing to move. Your llms.txt is already being served by AEO God Mode.', 'aeo-god-mode' ) );
        }

        $from = $stray['path'];
        $dir  = dirname( $from );
        $existing_record = get_option( self::LLMS_PRESERVED_OPT, array() );
        $existing_path   = is_array( $existing_record ) ? (string) ( $existing_record['path'] ?? '' ) : '';
        if ( '' !== $existing_path && file_exists( $existing_path ) ) {
            return array(
                'success' => false,
                'message' => __( 'A preserved llms.txt rollback file already exists. Restore it before taking ownership again so no version is lost.', 'aeo-god-mode' ),
            );
        }
        if ( ! is_writable( $dir ) ) {
            return array(
                'success' => false,
                'message' => sprintf(
                    /* translators: %s: directory path */
                    __( 'We cannot move the file because %s is not writable. Ask your host to allow writing there, or delete llms.txt yourself over FTP.', 'aeo-god-mode' ),
                    $dir
                ),
            );
        }

        // This is a rollback snapshot, not Yoast's future working file. The
        // wpseo filter writes future updates to llms-yoast.txt; sharing that
        // filename meant a background update could replace the exact file the
        // owner expected to get back.
        $to = $dir . '/llms-yoast-preserved-' . gmdate( 'Ymd-His' ) . '-' . wp_generate_password( 6, false, false ) . '.txt';

        if ( ! @rename( $from, $to ) ) {
            return array(
                'success' => false,
                'message' => __( 'The file could not be moved. Your host may be blocking writes to the site root.', 'aeo-god-mode' ),
            );
        }

        clearstatcache( true, $from );
        if ( file_exists( $from ) || ! file_exists( $to ) ) {
            return array(
                'success' => false,
                'message' => __( 'The file move could not be verified, so ownership was not changed.', 'aeo-god-mode' ),
            );
        }

        update_option( self::LLMS_PRESERVED_OPT, array(
            'path'     => $to,
            'sha256'   => (string) hash_file( 'sha256', $to ),
            'saved_at' => current_time( 'mysql' ),
            'owner'    => $stray['owner'],
        ), false );

        return array(
            'success' => true,
            'moved_to' => $to,
            'message'  => sprintf(
                /* translators: 1: owning plugin, 2: new filename */
                __( 'Done. %1$s content is now at %2$s and your llms.txt is live. Give any page cache a purge if you still see the old version.', 'aeo-god-mode' ),
                $stray['owner'],
                basename( $to )
            ),
        );
    }

    /** Restore the preserved Yoast file when the owner switches back. */
    private function restore_other_llms_txt() {
        $root = trailingslashit( function_exists( 'get_home_path' ) ? get_home_path() : ABSPATH );
        $to   = $root . 'llms.txt';
        $record = get_option( self::LLMS_PRESERVED_OPT, array() );
        $from   = is_array( $record ) ? (string) ( $record['path'] ?? '' ) : '';

        // Never trust an option containing an arbitrary filesystem path. The
        // preserved file must live in this site root and use our exact prefix.
        $valid_preserved = '' !== $from
            && dirname( $from ) === untrailingslashit( $root )
            && 0 === strpos( basename( $from ), 'llms-yoast-preserved-' )
            && file_exists( $from );

        if ( $valid_preserved ) {
            $expected = (string) ( $record['sha256'] ?? '' );
            $actual   = (string) hash_file( 'sha256', $from );
            if ( '' === $expected || ! hash_equals( $expected, $actual ) ) {
                return array( 'success' => false, 'message' => __( 'The preserved Yoast file failed its integrity check, so it was not restored.', 'aeo-god-mode' ) );
            }

            // A physical file may have appeared while AEO owned the route.
            // Archive it instead of deleting or overwriting it.
            if ( file_exists( $to ) ) {
                $archive = $root . 'llms-before-yoast-restore-' . gmdate( 'Ymd-His' ) . '.txt';
                if ( ! @rename( $to, $archive ) ) {
                    return array( 'success' => false, 'message' => __( 'The current llms.txt could not be archived safely, so the Yoast file was not restored.', 'aeo-god-mode' ) );
                }
            }
        } else {
            if ( file_exists( $to ) ) {
                return array( 'success' => true, 'message' => __( 'Yoast SEO is already serving llms.txt.', 'aeo-god-mode' ) );
            }
            // Backward-compatible fallback for sites that took ownership
            // before exact preservation records existed.
            $from = $root . 'llms-yoast.txt';
        }
        if ( ! file_exists( $from ) ) {
            return array( 'success' => false, 'message' => __( 'The preserved Yoast llms.txt file could not be found. Ask Yoast to regenerate it, then try again.', 'aeo-god-mode' ) );
        }
        if ( ! is_writable( $root ) || ! @rename( $from, $to ) ) {
            if ( ! empty( $archive ) && file_exists( $archive ) && ! file_exists( $to ) ) {
                @rename( $archive, $to );
            }
            return array( 'success' => false, 'message' => __( 'The Yoast file could not be restored because the site root is not writable.', 'aeo-god-mode' ) );
        }
        clearstatcache( true, $to );
        if ( ! file_exists( $to ) ) {
            return array( 'success' => false, 'message' => __( 'The restored file could not be verified.', 'aeo-god-mode' ) );
        }
        if ( $valid_preserved ) {
            $restored_hash = (string) hash_file( 'sha256', $to );
            if ( ! hash_equals( (string) $record['sha256'], $restored_hash ) ) {
                return array( 'success' => false, 'message' => __( 'The restored Yoast file did not match the preserved original.', 'aeo-god-mode' ) );
            }
            delete_option( self::LLMS_PRESERVED_OPT );
        }
        return array( 'success' => true, 'message' => __( 'The exact Yoast llms.txt saved at takeover is serving again. You can switch back here at any time.', 'aeo-god-mode' ) );
    }

    /**
     * Check a specific conflict type.
     *
     * @param string $type     Conflict type.
     * @param array  $detected Detected plugins data.
     * @return array|null Conflict data or null.
     */
    private function check_conflict( $type, $detected ) {
        $plugins = $detected['plugins'];
        $yoast_active = ! empty( $plugins['yoast']['active'] );
        $rm_active    = ! empty( $plugins['rank_math']['active'] );
        $settings     = get_option( 'asgm_settings', array() );
        $modules      = isset( $settings['modules'] ) ? $settings['modules'] : array();

        switch ( $type ) {
            case 'meta_title':
            case 'meta_description':
                if ( ! empty( $modules['basic_meta'] ) && ( $yoast_active || $rm_active ) ) {
                    $other = $yoast_active ? 'Yoast SEO' : 'Rank Math';
                    return array(
                        'id'          => $type,
                        'type'        => $type,
                        'severity'    => 'error',
                        'title'       => 'meta_title' === $type
                            ? __( 'Duplicate Meta Title Output', 'aeo-god-mode' )
                            : __( 'Duplicate Meta Description Output', 'aeo-god-mode' ),
                        'description' => sprintf(
                            /* translators: %s: other plugin name */
                            __( '%s is already outputting this. Having both active will cause duplicate tags.', 'aeo-god-mode' ),
                            $other
                        ),
                        'affected_plugin' => $other,
                        'resolution'      => 'defer',
                    );
                }
                break;

            case 'llms_txt_file':
                // A REAL llms.txt on disk is served by the web server before
                // WordPress boots, so our virtual /llms.txt never runs and the
                // customer sees stale content with no explanation. Yoast writes
                // one by design. Detect it and say so plainly.
                $stray       = $this->find_stray_llms_txt();
                $saved       = get_option( 'asgm_conflict_resolutions', array() );
                $resolution  = (string) ( $saved['llms_txt_file'] ?? '' );
                $intentional = ( 'defer' === $resolution && $stray ) || ( 'override' === $resolution && ! $stray );
                if ( $stray || ( $yoast_active && in_array( $resolution, array( 'defer', 'override' ), true ) ) ) {
                    $owner = $stray['owner'] ?? 'Yoast SEO';
                    return array(
                        'id'              => 'llms_txt_file',
                        'type'            => 'llms_txt_file',
                        'severity'        => $intentional ? 'resolved' : 'error',
                        'title'           => ( $intentional && 'override' === $resolution )
                            ? __( 'AEO God Mode is serving your llms.txt', 'aeo-god-mode' )
                            : sprintf( __( '%s is currently serving your llms.txt', 'aeo-god-mode' ), $owner ),
                        'description'     => $intentional
                            ? __( 'This ownership choice stays visible so you can switch it later. Open the verification link after changing it to bypass an older browser-cached copy.', 'aeo-god-mode' )
                            : sprintf(
                                /* translators: %s: owning plugin name */
                                __( '%1$s saved an llms.txt file straight onto your server, and your server hands that file out before WordPress even starts. We have already told %1$s to write future updates to llms-yoast.txt instead, so this will not happen again. One click moves the existing file across and your llms.txt takes over. Nothing is deleted.', 'aeo-god-mode' ),
                                $owner
                            ),
                        'affected_plugin' => $owner,
                        'file_path'       => $stray['path'] ?? '',
                        'fix_label'       => __( 'Move it aside and use mine', 'aeo-god-mode' ),
                        'can_auto_fix'    => $stray ? is_writable( dirname( $stray['path'] ) ) : true,
                        'resolution'      => $resolution ?: 'defer',
                        'verify_url'      => add_query_arg( 'asgm_verify', time(), home_url( '/llms.txt' ) ),
                    );
                }
                break;

            case 'schema_output':
                if ( ! empty( $modules['schema'] ) && ( $yoast_active || $rm_active ) ) {
                    $other = $yoast_active ? 'Yoast SEO' : 'Rank Math';
                    return array(
                        'id'              => 'schema_output',
                        'type'            => 'schema_output',
                        'severity'        => 'warning',
                        'title'           => __( 'Potential Schema Duplication', 'aeo-god-mode' ),
                        'description'     => sprintf(
                            /* translators: %s: other plugin name */
                            __( '%s also generates schema markup. We detect duplicates and only output schema types they do not cover.', 'aeo-god-mode' ),
                            $other
                        ),
                        'affected_plugin' => $other,
                        'resolution'      => 'merge',
                    );
                }
                break;

            case 'robots_txt':
                if ( ! empty( $modules['robots'] ) && ( $yoast_active || $rm_active ) ) {
                    $other = $yoast_active ? 'Yoast SEO' : 'Rank Math';
                    return array(
                        'id'              => 'robots_txt',
                        'type'            => 'robots_txt',
                        'severity'        => 'warning',
                        'title'           => __( 'Robots.txt Writer Conflict', 'aeo-god-mode' ),
                        'description'     => sprintf(
                            /* translators: %s: other plugin name */
                            __( '%s manages robots.txt. We append AI bot rules without overwriting existing rules.', 'aeo-god-mode' ),
                            $other
                        ),
                        'affected_plugin' => $other,
                        'resolution'      => 'merge',
                    );
                }
                break;

            case 'canonical':
                if ( ( $yoast_active || $rm_active ) ) {
                    // We do not output canonicals, so no conflict here.
                }
                break;

            case 'og_tags':
                // We do not output OG tags, so no conflict.
                break;
        }

        return null;
    }

    /**
     * Check if any conflict has a given severity.
     *
     * @param array  $conflicts Array of conflicts.
     * @param string $severity  Severity to check.
     * @return bool
     */
    private function has_severity( $conflicts, $severity ) {
        foreach ( $conflicts as $c ) {
            if ( isset( $c['severity'] ) && $c['severity'] === $severity ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Get current per-type schema resolutions.
     *
     * @return array
     */
    public function get_schema_resolutions() {
        return get_option( 'asgm_schema_resolutions', array() );
    }

    /**
     * Save a per-type schema resolution.
     *
     * @param string $type   Schema type (Article, Person, BreadcrumbList, etc.).
     * @param string $choice 'ours', 'theirs', or 'both'.
     * @return array
     */
    public function resolve_schema_type( $type, $choice ) {
        $valid_types   = array( 'Article', 'BreadcrumbList', 'Person', 'WebSite', 'Organization', 'HowTo', 'FAQPage' );
        $valid_choices = array( 'ours', 'theirs', 'both' );

        if ( ! in_array( $type, $valid_types, true ) ) {
            return array( 'success' => false, 'message' => __( 'Invalid schema type.', 'aeo-god-mode' ) );
        }
        if ( ! in_array( $choice, $valid_choices, true ) ) {
            return array( 'success' => false, 'message' => __( 'Invalid choice. Use ours, theirs, or both.', 'aeo-god-mode' ) );
        }

        $resolutions           = get_option( 'asgm_schema_resolutions', array() );
        $resolutions[ $type ]  = $choice;
        update_option( 'asgm_schema_resolutions', $resolutions );

        return array(
            'success'    => true,
            'type'       => $type,
            'choice'     => $choice,
            'resolutions' => $resolutions,
        );
    }

    /**
     * Build side-by-side schema comparison for a given post.
     *
     * @param int $post_id Post ID (0 for homepage types).
     * @return array
     */
    public function get_schema_comparison( $post_id = 0 ) {
        $detector = new Detector();
        $detected = $detector->scan();
        $plugins  = $detected['plugins'];

        $yoast_active = ! empty( $plugins['yoast']['active'] );
        $rm_active    = ! empty( $plugins['rank_math']['active'] );

        if ( ! $yoast_active && ! $rm_active ) {
            return array(
                'active_seo'   => null,
                'comparisons'  => array(),
                'message'      => __( 'No third-party SEO plugin detected. AEO God Mode handles all schema output.', 'aeo-god-mode' ),
            );
        }

        $seo_name    = $rm_active ? 'Rank Math' : 'Yoast SEO';
        $settings    = get_option( 'asgm_settings', array() );
        $business    = isset( $settings['business'] ) ? $settings['business'] : array();
        $resolutions = get_option( 'asgm_schema_resolutions', array() );

        // Build comparison for each schema type.
        $types = array(
            'Article'        => array(
                'label'       => 'Article / BlogPosting',
                'description' => __( 'Post-level article markup with headline, dates, author, and publisher.', 'aeo-god-mode' ),
            ),
            'Person'         => array(
                'label'       => 'Person (Author / E-E-A-T)',
                'description' => __( 'Author identity with credentials, expertise, and social profiles.', 'aeo-god-mode' ),
            ),
            'BreadcrumbList' => array(
                'label'       => 'BreadcrumbList',
                'description' => __( 'Navigation breadcrumb trail for search results display.', 'aeo-god-mode' ),
            ),
            'WebSite'        => array(
                'label'       => 'WebSite',
                'description' => __( 'Site-level identity with name, URL, and sitelinks search box.', 'aeo-god-mode' ),
            ),
            'Organization'   => array(
                'label'       => 'Organization',
                'description' => __( 'Business entity with name, logo, social profiles, and contact.', 'aeo-god-mode' ),
            ),
            'HowTo'          => array(
                'label'       => 'HowTo',
                'description' => __( 'Step-by-step instructions auto-detected from post content.', 'aeo-god-mode' ),
            ),
            'FAQPage'        => array(
                'label'       => 'FAQPage',
                'description' => __( 'Question/Answer pairs auto-detected from post content.', 'aeo-god-mode' ),
            ),
        );

        $comparisons = array();

        foreach ( $types as $type => $meta ) {
            $ours_fields   = $this->get_our_schema_fields( $type, $post_id, $business );
            $theirs_fields = $this->get_their_schema_fields( $type, $seo_name, $rm_active );

            // Count unique fields on each side.
            $ours_keys   = array_keys( $ours_fields );
            $theirs_keys = array_keys( $theirs_fields );
            $shared      = array_intersect( $ours_keys, $theirs_keys );
            $ours_unique = array_diff( $ours_keys, $theirs_keys );
            $theirs_unique = array_diff( $theirs_keys, $ours_keys );

            // Determine recommendation.
            $recommendation = 'theirs'; // Safe default.
            if ( count( $ours_unique ) > count( $theirs_unique ) ) {
                $recommendation = 'ours';
            }
            if ( 'Person' === $type ) {
                $recommendation = 'ours'; // Our E-E-A-T Person is always richer.
            }
            if ( in_array( $type, array( 'HowTo', 'FAQPage' ), true ) ) {
                $recommendation = 'ours'; // Only we auto-detect these.
            }

            $current_resolution = isset( $resolutions[ $type ] ) ? $resolutions[ $type ] : null;

            $comparisons[] = array(
                'type'               => $type,
                'label'              => $meta['label'],
                'description'        => $meta['description'],
                'ours_fields'        => $ours_fields,
                'theirs_fields'      => $theirs_fields,
                'ours_field_count'   => count( $ours_keys ),
                'theirs_field_count' => count( $theirs_keys ),
                'shared_count'       => count( $shared ),
                'ours_unique'        => array_values( $ours_unique ),
                'theirs_unique'      => array_values( $theirs_unique ),
                'recommendation'     => $recommendation,
                'current_resolution' => $current_resolution,
                'theirs_produces'    => ! empty( $theirs_fields ),
                'ours_produces'      => ! empty( $ours_fields ),
            );
        }

        return array(
            'active_seo'  => $seo_name,
            'comparisons' => $comparisons,
            'resolutions' => $resolutions,
        );
    }

    /**
     * Get fields our plugin outputs for a schema type.
     *
     * @param string $type     Schema type.
     * @param int    $post_id  Post ID.
     * @param array  $business Business settings.
     * @return array Field name => description or value preview.
     */
    private function get_our_schema_fields( $type, $post_id, $business ) {
        switch ( $type ) {
            case 'Article':
                $fields = array(
                    '@type'            => 'Article',
                    'headline'         => 'Post title',
                    'datePublished'    => 'ISO 8601 date',
                    'dateModified'     => 'ISO 8601 date',
                    'mainEntityOfPage' => 'WebPage @id reference',
                    'isPartOf'         => 'WebPage @id reference',
                    'inLanguage'       => 'Site language code',
                    'articleSection'   => 'Primary category name',
                    'wordCount'        => 'Integer',
                    'url'              => 'Permalink',
                    'image'            => 'Featured image object',
                    'author'           => 'Person (name, url)',
                    'publisher'        => 'Organization (name, logo)',
                    'description'      => 'Post excerpt or auto-summary',
                );
                // Only include speakable if the setting is actually enabled.
                $settings = get_option( 'asgm_settings', array() );
                if ( ! empty( $settings['speakable_enabled'] ) ) {
                    $fields['speakable'] = 'CSS selectors for voice search';
                }
                return $fields;

            case 'Person':
                return array(
                    '@type'          => 'Person',
                    'name'           => 'Display name',
                    'url'            => 'Author archive URL',
                    'image'          => 'Avatar URL',
                    'jobTitle'       => 'E-E-A-T: Professional title',
                    'description'    => 'E-E-A-T: Bio',
                    'knowsAbout'     => 'E-E-A-T: Areas of expertise',
                    'hasCredential'  => 'E-E-A-T: Certifications',
                    'alumniOf'       => 'E-E-A-T: Education',
                    'worksFor'       => 'E-E-A-T: Organization',
                    'sameAs'         => 'Social profiles (up to 8)',
                    'memberOf'       => 'E-E-A-T: Professional orgs',
                );

            case 'BreadcrumbList':
                return array(
                    '@type'           => 'BreadcrumbList',
                    'itemListElement' => 'Home > Category > Post',
                );

            case 'WebSite':
                return array(
                    '@type'           => 'WebSite',
                    'name'            => 'Business name from settings',
                    'url'             => 'Home URL',
                    'inLanguage'      => 'Site language code',
                    'potentialAction' => 'SearchAction (sitelinks)',
                );

            case 'Organization':
                return array(
                    '@type'      => 'Organization',
                    'name'       => 'Business name from settings',
                    'url'        => 'Home URL',
                    'inLanguage' => 'Site language code',
                    'logo'       => 'Logo URL (if set)',
                    'sameAs'     => 'Social profiles from settings',
                );

            case 'HowTo':
                return array(
                    '@type'           => 'HowTo',
                    'name'            => 'Auto-detected from H2',
                    'step'            => 'Auto-detected numbered/ordered steps',
                    'totalTime'       => 'Estimated from step count',
                    'description'     => 'Intro paragraph',
                );

            case 'FAQPage':
                return array(
                    '@type'      => 'FAQPage',
                    'mainEntity' => 'Auto-detected Q&A pairs',
                );

            default:
                return array();
        }
    }

    /**
     * Get fields the third-party plugin outputs for a schema type.
     *
     * @param string $type     Schema type.
     * @param string $seo_name Plugin name (Rank Math, Yoast SEO).
     * @param bool   $is_rm    Whether Rank Math is the active plugin.
     * @return array Field name => description.
     */
    private function get_their_schema_fields( $type, $seo_name, $is_rm ) {
        if ( $is_rm ) {
            return $this->rank_math_fields( $type );
        }
        return $this->yoast_fields( $type );
    }

    /**
     * Known schema fields Rank Math outputs.
     */
    private function rank_math_fields( $type ) {
        switch ( $type ) {
            case 'Article':
                return array(
                    '@type'            => 'BlogPosting',
                    'headline'         => 'Post title',
                    'datePublished'    => 'ISO 8601 date',
                    'dateModified'     => 'ISO 8601 date',
                    'url'              => 'Permalink',
                    'mainEntityOfPage' => 'WebPage @id reference',
                    'author'           => 'Person @id reference',
                    'publisher'        => 'Organization @id reference',
                    'image'            => 'Featured image object',
                    'isPartOf'         => 'WebPage @id reference',
                    'inLanguage'       => 'Site language code',
                );

            case 'Person':
                return array(
                    '@type' => 'Person',
                    'name'  => 'Display name',
                    'url'   => 'Author archive URL',
                    'image' => 'Gravatar URL',
                );

            case 'BreadcrumbList':
                return array(
                    '@type'           => 'BreadcrumbList',
                    'itemListElement' => 'Home > Category > Post',
                );

            case 'WebSite':
                return array(
                    '@type'          => 'WebSite',
                    'name'           => 'Site name',
                    'url'            => 'Home URL',
                    'potentialAction' => 'SearchAction',
                    'inLanguage'     => 'Site language code',
                );

            case 'Organization':
                return array(
                    '@type' => 'Organization (from Knowledge Graph)',
                    'name'  => 'Business name',
                    'url'   => 'Home URL',
                    'logo'  => 'Logo URL',
                );

            case 'HowTo':
            case 'FAQPage':
                return array(); // Only if user configures RM's FAQ/HowTo blocks.
        }
        return array();
    }

    /**
     * Known schema fields Yoast SEO outputs.
     */
    private function yoast_fields( $type ) {
        switch ( $type ) {
            case 'Article':
                return array(
                    '@type'            => 'Article',
                    'headline'         => 'Post title',
                    'datePublished'    => 'ISO 8601 date',
                    'dateModified'     => 'ISO 8601 date',
                    'mainEntityOfPage' => 'WebPage @id reference',
                    'author'           => 'Person @id reference',
                    'publisher'        => 'Organization @id reference',
                    'image'            => 'Featured image object',
                    'wordCount'        => 'Integer',
                    'inLanguage'       => 'Site language code',
                );

            case 'Person':
                return array(
                    '@type'       => 'Person',
                    'name'        => 'Display name',
                    'url'         => 'Author archive URL',
                    'image'       => 'Gravatar URL',
                    'description' => 'Author bio',
                    'sameAs'      => 'Social profiles (if set)',
                );

            case 'BreadcrumbList':
                return array(
                    '@type'           => 'BreadcrumbList',
                    'itemListElement' => 'Home > Category > Post',
                );

            case 'WebSite':
                return array(
                    '@type'          => 'WebSite',
                    'name'           => 'Site name',
                    'url'            => 'Home URL',
                    'potentialAction' => 'SearchAction',
                    'inLanguage'     => 'Site language code',
                    'publisher'      => 'Organization @id reference',
                );

            case 'Organization':
                return array(
                    '@type' => 'Organization',
                    'name'  => 'Business name',
                    'url'   => 'Home URL',
                    'logo'  => 'Logo image object',
                    'image' => 'Logo reference',
                );

            case 'HowTo':
            case 'FAQPage':
                return array(); // Only if using Yoast's deprecated blocks.
        }
        return array();
    }
}
