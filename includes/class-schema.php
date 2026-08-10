<?php
/**
 * Schema markup engine.
 *
 * @package AISEOGodMode
 */

namespace AISEOGodMode;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Generates and injects JSON-LD schema markup.
 */
class Schema {

    /**
     * Initialise hooks.
     */
    public function __construct() {
        // Rendering is handled by Main::render_frontend_output() which calls $this->render()
        // directly via wp_head priority 1.  Do NOT add a second wp_head hook here
        // or every schema block will be output twice.
    }

    /**
     * Render schema markup in the page head.
     */
    public function render() {
        if ( is_admin() ) {
            return;
        }

        $settings = get_option( 'asgm_settings', array() );
        $modules  = isset( $settings['modules'] ) ? $settings['modules'] : array();

        if ( empty( $modules['schema'] ) ) {
            return;
        }

        $schemas = $this->generate_schemas( $settings );

        foreach ( $schemas as $schema ) {
            $json = wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_PRETTY_PRINT );
            if ( $json ) {
                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON_HEX_TAG | JSON_HEX_AMP encode <, >, & as unicode sequences preventing script breakout.
                echo "\n<script type=\"application/ld+json\">\n" . $json . "\n</script>\n";
            }
        }
    }

    /**
     * Generate all applicable schemas for the current page.
     *
     * @param array $settings Plugin settings.
     * @return array Array of schema objects.
     */
    private function generate_schemas( $settings ) {
        $schemas = array();
        $business = isset( $settings['business'] ) ? $settings['business'] : array();

        // Check for per-post overrides.
        if ( is_singular() ) {
            $post_id  = get_the_ID();
            $override = get_post_meta( $post_id, '_asgm_schema_override', true );

            if ( $override ) {
                $decoded = is_string( $override ) ? json_decode( $override, true ) : $override;
                if ( $decoded ) {
                    // Legacy: single schema with @type at top level.
                    if ( isset( $decoded['@type'] ) ) {
                        if ( ! isset( $decoded['@context'] ) ) {
                            $decoded['@context'] = 'https://schema.org';
                        }
                        return array( $decoded );
                    }
                    // New: keyed array of multiple schemas (e.g. FAQPage + HowTo).
                    if ( is_array( $decoded ) ) {
                        $override_schemas = array();
                        foreach ( $decoded as $s ) {
                            if ( is_array( $s ) && isset( $s['@type'] ) ) {
                                if ( ! isset( $s['@context'] ) ) {
                                    $s['@context'] = 'https://schema.org';
                                }
                                $override_schemas[] = $s;
                            }
                        }
                        if ( ! empty( $override_schemas ) ) {
                            return $override_schemas;
                        }
                    }
                }
            }
        }

        // Homepage: WebSite + Organization (skip if Yoast or Rank Math handles it).
        if ( is_front_page() || is_home() ) {
            if ( ! $this->third_party_handles( 'WebSite' ) ) {
                $schemas[] = $this->website_schema( $business );
            }
            if ( ! $this->third_party_handles( 'Organization' ) ) {
                $schemas[] = $this->organization_schema( $business );
            }
        }

        // Single posts: Article schema (skip if Yoast or Rank Math handles it).
        if ( is_singular( 'post' ) && ! $this->third_party_handles( 'Article' ) ) {
            $schemas[] = $this->article_schema();
        }

        // All singular EXCEPT homepage: BreadcrumbList (skip if Yoast or
        // Rank Math handles it). The homepage has no parent to breadcrumb
        // from — emitting a 2-item "Home -> Home" trail there is invalid
        // schema and a Rich Results validation warning.
        if ( is_singular() && ! is_front_page() && ! $this->third_party_handles( 'BreadcrumbList' ) ) {
            $breadcrumb = $this->breadcrumb_schema();
            if ( $breadcrumb ) {
                $schemas[] = $breadcrumb;
            }
        }

        // FAQ auto-detection (skip if Yoast or Rank Math handles it).
        if ( is_singular() && ! $this->third_party_handles( 'FAQPage' ) ) {
            $faq = $this->detect_faq_schema();
            if ( $faq ) {
                $schemas[] = $faq;
            }
        }

        // HowTo auto-detection (skip if Yoast or Rank Math handles it).
        if ( is_singular() && ! $this->third_party_handles( 'HowTo' ) ) {
            $howto = $this->detect_howto_schema();
            if ( $howto ) {
                $schemas[] = $howto;
            }
        }

        // VideoObject: detect YouTube/Vimeo embeds in singular content.
        if ( is_singular() && ! $this->third_party_handles( 'VideoObject' ) ) {
            $video = $this->detect_video_schema();
            if ( $video ) {
                $schemas[] = $video;
            }
        }

        // CollectionPage: category, tag, and date archives.
        if ( ( is_category() || is_tag() || is_date() ) && ! $this->third_party_handles( 'CollectionPage' ) ) {
            $schemas[] = $this->collection_page_schema();
        }

        // ProfilePage: author archive pages.
        if ( is_author() && ! $this->third_party_handles( 'ProfilePage' ) ) {
            $schemas[] = $this->profile_page_schema();
        }

        // LocalBusiness on homepage and contact page.
        $modules = isset( $settings['modules'] ) ? $settings['modules'] : array();
        if ( ! empty( $modules['local_business'] ) ) {
            if ( is_front_page() || $this->is_contact_page() ) {
                $local = $this->local_business_schema( $settings );
                if ( $local ) {
                    $schemas[] = $local;
                }
            }
        }

        // WooCommerce Product.
        if ( is_singular( 'product' ) && function_exists( 'wc_get_product' ) ) {
            $product_schema = $this->product_schema();
            if ( $product_schema ) {
                $schemas[] = $product_schema;
            }
        }

        // Easy Digital Downloads Product.
        if ( is_singular( 'download' ) && function_exists( 'edd_get_download' ) ) {
            $edd_schema = $this->edd_product_schema();
            if ( $edd_schema ) {
                $schemas[] = $edd_schema;
            }
        }

        return $schemas;
    }

    /**
     * Get schema output for a specific post (REST API preview).
     *
     * @param int $post_id Post ID.
     * Return the schemas this plugin would emit on the site homepage.
     *
     * Mirrors the homepage branch of generate_schemas() but is callable from
     * analysis contexts (e.g. health scoring) where is_front_page() can't be
     * relied on. Honours the same third_party_handles() gating so it reflects
     * what would actually be output, including the user's Conflicts choices.
     *
     * @return array { 'source' => 'homepage', 'schemas' => array }
     */
    public function get_for_homepage() {
        $settings = get_option( 'asgm_settings', array() );
        $business = isset( $settings['business'] ) ? $settings['business'] : array();
        $modules  = isset( $settings['modules'] ) ? $settings['modules'] : array();
        $schemas  = array();

        if ( ! $this->third_party_handles( 'WebSite' ) ) {
            $schemas[] = $this->website_schema( $business );
        }
        if ( ! $this->third_party_handles( 'Organization' ) ) {
            $schemas[] = $this->organization_schema( $business );
        }
        if ( ! empty( $modules['local_business'] ) ) {
            $local = $this->local_business_schema( $settings );
            if ( $local ) {
                $schemas[] = $local;
            }
        }

        return array(
            'source'  => 'homepage',
            'schemas' => $schemas,
        );
    }

    /**
     * Return @types emitted by Pro modules (E-E-A-T author Person, etc.) for the
     * given sample posts. This is the single source of truth health scoring uses
     * to know what types are emitted OUTSIDE the main Schema class — without
     * parsing live HTML. Add future Pro emitters here so all consumers (scorer,
     * editor panel, validator) stay in sync.
     *
     * @param int[] $post_ids Sample post IDs to probe.
     * @return string[] Unique list of @types (e.g. ['Person']).
     */
    public function get_pro_emitter_types( $post_ids ) {
        $types = array();

        // E-E-A-T author Person. Honours the same resolution gating the
        // emitter applies at runtime, so the scorer matches reality.
        if ( class_exists( __NAMESPACE__ . '\\EEAT' ) ) {
            $resolutions = get_option( 'asgm_schema_resolutions', array() );
            $person_res  = isset( $resolutions['Person'] ) ? $resolutions['Person'] : 'ours';
            $third_party = ( defined( 'WPSEO_VERSION' ) || defined( 'RANK_MATH_VERSION' ) || class_exists( '\\RankMath\\Paper\\Paper' ) );
            // EEAT::render_author_schema() returns early when third-party
            // is active AND user picked 'theirs'. Mirror that here so we don't
            // claim Person is covered by us when it's actually deferred.
            if ( ! ( $third_party && 'theirs' === $person_res ) ) {
                foreach ( $post_ids as $pid ) {
                    $post = get_post( $pid );
                    if ( ! $post ) {
                        continue;
                    }
                    $person = EEAT::build_person_schema( $post->post_author );
                    if ( ! empty( $person ) && isset( $person['@type'] ) && 'Person' === $person['@type'] ) {
                        $types[] = 'Person';
                        break;
                    }
                }
            }
        }

        return array_values( array_unique( $types ) );
    }

    /**
     * @return array
     */
    public function get_for_post( $post_id ) {
        $post = get_post( $post_id );
        if ( ! $post ) {
            return array( 'error' => 'Post not found.' );
        }

        $settings = get_option( 'asgm_settings', array() );
        $schemas  = array();
        $business = isset( $settings['business'] ) ? $settings['business'] : array();

        // Override check.
        $override = get_post_meta( $post_id, '_asgm_schema_override', true );
        if ( $override ) {
            $decoded = is_string( $override ) ? json_decode( $override, true ) : $override;
            if ( $decoded ) {
                $override_schemas = array();

                // Multi-schema format: { "FAQPage": {...}, "HowTo": {...} }.
                if ( ! isset( $decoded['@type'] ) ) {
                    foreach ( $decoded as $type_key => $schema_obj ) {
                        if ( is_array( $schema_obj ) ) {
                            // Inject @type from the key if not set inside the object.
                            if ( ! isset( $schema_obj['@type'] ) && is_string( $type_key ) && ! is_numeric( $type_key ) ) {
                                $schema_obj['@type'] = $type_key;
                            }
                            if ( ! isset( $schema_obj['@context'] ) ) {
                                $schema_obj['@context'] = 'https://schema.org';
                            }
                            $override_schemas[] = $schema_obj;
                        }
                    }
                }

                // Legacy single-schema format: { "@type": "FAQPage", ... }.
                if ( empty( $override_schemas ) && isset( $decoded['@type'] ) ) {
                    if ( ! isset( $decoded['@context'] ) ) {
                        $decoded['@context'] = 'https://schema.org';
                    }
                    $override_schemas[] = $decoded;
                }

                if ( ! empty( $override_schemas ) ) {
                    return array(
                        'post_id'  => $post_id,
                        'source'   => 'override',
                        'schemas'  => $override_schemas,
                    );
                }
            }
        }

        // Always include Article for posts.
        if ( 'post' === $post->post_type ) {
            $schemas[] = $this->article_schema_for_post( $post );
        }

        // Breadcrumb.
        $schemas[] = $this->breadcrumb_schema_for_post( $post );

        return array(
            'post_id' => $post_id,
            'source'  => 'auto',
            'schemas' => $schemas,
        );
    }

    // -----------------------------------------------------------------------
    // Schema Builders
    // -----------------------------------------------------------------------

    /**
     * WebSite schema.
     *
     * @param array $business Business info.
     * @return array
     */
    private function website_schema( $business ) {
        $name = ! empty( $business['name'] ) ? $business['name'] : get_bloginfo( 'name' );

        return array(
            '@context'        => 'https://schema.org',
            '@type'           => 'WebSite',
            'name'            => $name,
            'url'             => get_site_url(),
            'inLanguage'      => get_bloginfo( 'language' ),
            'potentialAction'  => array(
                '@type'       => 'SearchAction',
                'target'      => array(
                    '@type'        => 'EntryPoint',
                    'urlTemplate'  => get_site_url() . '/?s={search_term_string}',
                ),
                'query-input' => 'required name=search_term_string',
            ),
        );
    }

    /**
     * Organization schema.
     *
     * @param array $business Business info.
     * @return array
     */
    private function organization_schema( $business ) {
        $name = ! empty( $business['name'] ) ? $business['name'] : get_bloginfo( 'name' );

        $schema = array(
            '@context'   => 'https://schema.org',
            '@type'      => 'Organization',
            'name'       => $name,
            'url'        => get_site_url(),
            'inLanguage' => get_bloginfo( 'language' ),
        );

        // Logo.
        $custom_logo_id = get_theme_mod( 'custom_logo' );
        if ( $custom_logo_id ) {
            $logo_url = wp_get_attachment_image_url( $custom_logo_id, 'full' );
            if ( $logo_url ) {
                $schema['logo'] = $logo_url;
            }
        }

        // sameAs — pull from both legacy `business.social` (keyed map of platform => url)
        // and the new flexible `business.profiles` array (list of { platform, url }).
        // The flexible list is what Settings.tsx now writes; the keyed map remains for
        // backward compatibility with sites that haven't re-saved their settings yet.
        $same_as = array();

        $social = isset( $business['social'] ) ? (array) $business['social'] : array();
        foreach ( $social as $url ) {
            $url = is_string( $url ) ? trim( $url ) : '';
            if ( $url !== '' ) {
                $same_as[] = $url;
            }
        }

        $profiles = isset( $business['profiles'] ) ? (array) $business['profiles'] : array();
        foreach ( $profiles as $row ) {
            if ( ! is_array( $row ) ) { continue; }
            $url = isset( $row['url'] ) ? trim( (string) $row['url'] ) : '';
            if ( $url !== '' ) {
                $same_as[] = $url;
            }
        }

        // Dedupe while preserving order.
        $same_as = array_values( array_unique( $same_as ) );

        if ( ! empty( $same_as ) ) {
            $schema['sameAs'] = $same_as;
        }

        return $schema;
    }

    /**
     * Article schema for current post.
     *
     * @return array
     */
    private function article_schema() {
        $post = get_post();
        return $this->article_schema_for_post( $post );
    }

    /**
     * Article schema for a given post object.
     *
     * @param \WP_Post $post Post object.
     * @return array
     */
    private function article_schema_for_post( $post ) {
        $permalink = get_permalink( $post );

        $schema = array(
            '@context'         => 'https://schema.org',
            '@type'            => 'Article',
            'headline'         => get_the_title( $post ),
            'url'              => $permalink,
            'datePublished'    => get_the_date( 'c', $post ),
            'dateModified'     => get_the_modified_date( 'c', $post ),
            'mainEntityOfPage' => array(
                '@type' => 'WebPage',
                '@id'   => $permalink,
            ),
            'isPartOf'         => array(
                '@type' => 'WebPage',
                '@id'   => $permalink . '#webpage',
            ),
            'inLanguage'       => get_bloginfo( 'language' ),
            'author'           => $this->build_author_schema( $post->post_author ),
            'publisher'        => array(
                '@type' => 'Organization',
                'name'  => get_bloginfo( 'name' ),
            ),
        );

        // Publisher logo.
        $custom_logo_id = get_theme_mod( 'custom_logo' );
        if ( $custom_logo_id ) {
            $logo_url = wp_get_attachment_image_url( $custom_logo_id, 'full' );
            if ( $logo_url ) {
                $schema['publisher']['logo'] = array(
                    '@type' => 'ImageObject',
                    'url'   => $logo_url,
                );
            }
        }

        // Featured image (primary), inline image fallback (secondary).
        $thumb_id = get_post_thumbnail_id( $post );
        if ( $thumb_id ) {
            $img = wp_get_attachment_image_src( $thumb_id, 'full' );
            if ( $img ) {
                $schema['image'] = array(
                    '@type'  => 'ImageObject',
                    'url'    => $img[0],
                    'width'  => $img[1],
                    'height' => $img[2],
                );
            }
        }

        // Fallback: extract first inline image from post content.
        if ( empty( $schema['image'] ) && ! empty( $post->post_content ) ) {
            if ( preg_match( '/<img[^>]+src=["\']([^"\']+)["\']/', $post->post_content, $m ) ) {
                $schema['image'] = array(
                    '@type' => 'ImageObject',
                    'url'   => $m[1],
                );
            }
        }

        // Article section (primary category).
        $categories = get_the_category( $post->ID );
        if ( ! empty( $categories ) ) {
            $schema['articleSection'] = $categories[0]->name;
        }

        // Description (excerpt or auto-generated).
        $excerpt = get_the_excerpt( $post );
        if ( ! empty( $excerpt ) ) {
            $schema['description'] = wp_strip_all_tags( $excerpt );
        } else {
            $plain = wp_strip_all_tags( $post->post_content );
            if ( strlen( $plain ) > 20 ) {
                $schema['description'] = wp_trim_words( $plain, 30, '...' );
            }
        }

        // Word count.
        $schema['wordCount'] = str_word_count( wp_strip_all_tags( $post->post_content ) );

        return $schema;
    }

    /**
     * Build the best available author schema for a post.
     *
     * If Pro is active and the author has E-E-A-T data, returns the rich
     * Person schema with jobTitle, worksFor, knowsAbout, alumniOf,
     * hasCredential, sameAs, description, and image.
     *
     * Falls back to a minimal Person with name + url for Free users
     * or authors without E-E-A-T data.
     *
     * @param int $author_id WordPress user ID.
     * @return array Person schema array.
     */
    private function build_author_schema( $author_id ) {
        // When the Pro E-E-A-T module is emitting a standalone Person at wp_head
        // priority 5, reference it by @id instead of inlining a duplicate Person
        // here. Validators and AI engines treat the linked entities as one.
        if ( class_exists( '\\AISEOGodMode\\EEAT' ) ) {
            $eeat_will_emit = true;
            if ( defined( 'WPSEO_VERSION' ) || defined( 'RANK_MATH_VERSION' ) || class_exists( '\\RankMath\\Paper\\Paper' ) ) {
                $resolutions = get_option( 'asgm_schema_resolutions', array() );
                $person_res  = isset( $resolutions['Person'] ) ? $resolutions['Person'] : 'ours';
                if ( 'theirs' === $person_res ) {
                    $eeat_will_emit = false;
                }
            }

            $person = \AISEOGodMode\EEAT::build_person_schema( $author_id );
            if ( $eeat_will_emit && ! empty( $person ) && ! empty( $person['@id'] ) ) {
                return array( '@id' => $person['@id'] );
            }
            if ( ! empty( $person ) && ! empty( $person['name'] ) ) {
                unset( $person['@context'] );
                return $person;
            }
        }

        // Fallback: basic author info.
        return array(
            '@type' => 'Person',
            'name'  => get_the_author_meta( 'display_name', $author_id ),
            'url'   => get_author_posts_url( $author_id ),
        );
    }

    /**
     * BreadcrumbList schema.
     *
     * @return array|null
     */
    private function breadcrumb_schema() {
        $post = get_post();
        if ( ! $post ) {
            return null;
        }
        return $this->breadcrumb_schema_for_post( $post );
    }

    /**
     * BreadcrumbList schema for a given post.
     *
     * @param \WP_Post $post Post object.
     * @return array
     */
    private function breadcrumb_schema_for_post( $post ) {
        $items = array();

        $items[] = array(
            '@type'    => 'ListItem',
            'position' => 1,
            'name'     => __( 'Home', 'aeo-god-mode' ),
            'item'     => get_site_url(),
        );

        $categories = get_the_category( $post->ID );
        if ( ! empty( $categories ) ) {
            $cat = $categories[0];
            $items[] = array(
                '@type'    => 'ListItem',
                'position' => 2,
                'name'     => $cat->name,
                'item'     => get_category_link( $cat->term_id ),
            );
        }

        $items[] = array(
            '@type'    => 'ListItem',
            'position' => count( $items ) + 1,
            'name'     => get_the_title( $post ),
            'item'     => get_permalink( $post ),
        );

        return array(
            '@context'        => 'https://schema.org',
            '@type'           => 'BreadcrumbList',
            'itemListElement' => $items,
        );
    }

    /**
     * Detect FAQ-style content and generate FAQPage schema.
     *
     * @return array|null
     */
    private function detect_faq_schema() {
        $post = get_post();
        if ( ! $post ) {
            return null;
        }

        $content = $post->post_content;

        // Detect AEO God Mode's own [aeogm_faq q="Q"]A[/aeogm_faq] accordion
        // shortcodes (class-faq-blocks.php renders the accordion; schema is
        // emitted here, once, so the two never disagree or double up).
        if ( false !== stripos( $content, '[aeogm_faq' ) ) {
            $pattern = '/\[aeogm_faq\s+[^\]]*?(?:q|title)\s*=\s*(?:"([^"]+)"|\'([^\']+)\')[^\]]*\](.+?)\[\/aeogm_faq\]/si';
            if ( preg_match_all( $pattern, $content, $matches, PREG_SET_ORDER ) ) {
                $faqs = array();
                foreach ( $matches as $match ) {
                    $question = trim( $match[1] !== '' ? $match[1] : $match[2] );
                    $answer   = trim( wp_strip_all_tags( strip_shortcodes( $match[3] ) ) );
                    $answer   = preg_replace( '/\s+/', ' ', $answer );
                    if ( $question !== '' && $answer !== '' ) {
                        $faqs[] = array(
                            '@type'          => 'Question',
                            'name'           => $question,
                            'acceptedAnswer' => array(
                                '@type' => 'Answer',
                                'text'  => $answer,
                            ),
                        );
                    }
                }
                if ( count( $faqs ) >= 2 ) {
                    return array(
                        '@context'   => 'https://schema.org',
                        '@type'      => 'FAQPage',
                        'mainEntity' => $faqs,
                    );
                }
            }
        }

        // Detect [faq title="Q"]A[/faq] shortcode pairs (common third-party FAQ
        // plugin format: Easy FAQ, Quick and Easy FAQs, accordion FAQ themes,
        // and AEO God Mode's own Query Gap Detector writeback). Handles single
        // and double-quoted titles, plus optional surrounding [faq_wrap] block.
        if ( false !== stripos( $content, '[faq ' ) || false !== stripos( $content, '[faq_wrap' ) ) {
            $pattern = '/\[faq\s+[^\]]*?title\s*=\s*(?:"([^"]+)"|\'([^\']+)\')[^\]]*\](.+?)\[\/faq\]/si';
            if ( preg_match_all( $pattern, $content, $matches, PREG_SET_ORDER ) ) {
                $faqs = array();
                foreach ( $matches as $match ) {
                    $question = trim( $match[1] !== '' ? $match[1] : $match[2] );
                    // Strip nested shortcodes and HTML from the answer, collapse whitespace.
                    $answer = trim( wp_strip_all_tags( strip_shortcodes( $match[3] ) ) );
                    $answer = preg_replace( '/\s+/', ' ', $answer );
                    if ( $question !== '' && $answer !== '' ) {
                        $faqs[] = array(
                            '@type'          => 'Question',
                            'name'           => $question,
                            'acceptedAnswer' => array(
                                '@type' => 'Answer',
                                'text'  => $answer,
                            ),
                        );
                    }
                }
                // Require at least 2 valid pairs before producing FAQPage schema.
                // One isolated [faq] is more likely a stray than a real FAQ section.
                if ( count( $faqs ) >= 2 ) {
                    return array(
                        '@context'   => 'https://schema.org',
                        '@type'      => 'FAQPage',
                        'mainEntity' => $faqs,
                    );
                }
            }
        }

        // Detect Q: A: patterns.
        if ( preg_match_all( '/(?:<h[2-4][^>]*>|<strong>)\s*(?:Q:|Question:?)\s*(.*?)(?:<\/h[2-4]>|<\/strong>)\s*(?:<p>)?\s*(?:A:|Answer:?)?\s*(.*?)(?:<\/p>|(?=<h[2-4]))/si', $content, $matches, PREG_SET_ORDER ) ) {
            $faqs = array();
            foreach ( $matches as $match ) {
                $faqs[] = array(
                    '@type'          => 'Question',
                    'name'           => wp_strip_all_tags( $match[1] ),
                    'acceptedAnswer' => array(
                        '@type' => 'Answer',
                        'text'  => wp_strip_all_tags( $match[2] ),
                    ),
                );
            }

            if ( ! empty( $faqs ) ) {
                return array(
                    '@context'   => 'https://schema.org',
                    '@type'      => 'FAQPage',
                    'mainEntity' => $faqs,
                );
            }
        }

        // Detect Gutenberg FAQ blocks or accordion patterns.
        if ( has_block( 'yoast/faq-block', $post ) || has_block( 'rank-math/faq-block', $post ) ) {
            // These plugins handle their own FAQ schema, so defer.
            return null;
        }

        // Detect a plain FAQ section: an "FAQ" style H2/H3, followed by
        // question-shaped subheadings each answered by a paragraph. This is
        // the structure our own draft generator produces and the most common
        // hand-written FAQ format. Scoped to content AFTER the FAQ heading so
        // an article that is entirely question-headings (answer-first style)
        // is not mislabeled as an FAQPage.
        if ( preg_match( '/<h([23])\b[^>]*>\s*(?:faqs?|frequently asked questions|common questions)\s*:?\s*<\/h\1>/i', $content, $fh, PREG_OFFSET_CAPTURE ) ) {
            $section = substr( $content, $fh[0][1] + strlen( $fh[0][0] ) );
            if ( preg_match_all( '/<h([34])\b[^>]*>(.*?\?)\s*<\/h\1>.*?<p\b[^>]*>(.*?)<\/p>/si', $section, $qm, PREG_SET_ORDER ) ) {
                $faqs = array();
                foreach ( $qm as $match ) {
                    $question = trim( wp_strip_all_tags( $match[2] ) );
                    $answer   = trim( wp_strip_all_tags( $match[3] ) );
                    $answer   = preg_replace( '/\s+/', ' ', $answer );
                    if ( '' !== $question && '' !== $answer ) {
                        $faqs[] = array(
                            '@type'          => 'Question',
                            'name'           => $question,
                            'acceptedAnswer' => array(
                                '@type' => 'Answer',
                                'text'  => $answer,
                            ),
                        );
                    }
                }
                if ( count( $faqs ) >= 2 ) {
                    return array(
                        '@context'   => 'https://schema.org',
                        '@type'      => 'FAQPage',
                        'mainEntity' => $faqs,
                    );
                }
            }
        }

        return null;
    }

    /**
     * Detect HowTo-style content and generate HowTo schema.
     *
     * @return array|null
     */
    private function detect_howto_schema() {
        $post = get_post();
        if ( ! $post ) {
            return null;
        }

        $content = $post->post_content;
        $title   = get_the_title( $post );

        // ── Gate 1: Title must contain "How to". ──
        if ( ! preg_match( '/\bhow\s+to\b/i', $title ) ) {
            return null;
        }

        // ── Gate 2: Content must also show step-like structure. ──
        // Require at least one of:
        //   a) A heading (H2-H4) with step language: "steps", "step-by-step", "instructions"
        //   b) Explicit "Step N:" patterns anywhere in the content
        // This prevents informational guides (with lots of bullet points) from
        // being incorrectly marked as HowTo schema.
        $has_step_heading = preg_match( '/<h[2-4][^>]*>.*?(?:\bsteps?\b|\bstep[\s-]by[\s-]step\b|\binstructions?\b|\bprocess\b|\bprocedure\b)/si', $content );
        $has_explicit_steps = preg_match( '/\bStep\s+\d+\s*[:\-\.]/i', $content );

        if ( ! $has_step_heading && ! $has_explicit_steps ) {
            return null; // No step structure found. This is a guide, not a tutorial.
        }

        // Strategy 1: Ordered lists (<ol>) following step-related headings.
        $steps = $this->extract_steps_near_headings( $content );

        // Strategy 2: Explicit "Step N:" patterns in headings or paragraphs.
        if ( empty( $steps ) ) {
            $steps = $this->extract_explicit_step_patterns( $content );
        }

        // No "any ordered list" fallback. Too risky for false positives.

        // Minimum 5 steps (3 is too borderline), maximum 25.
        if ( count( $steps ) < 5 ) {
            return null;
        }
        $steps = array_slice( $steps, 0, 25 );

        // Re-number positions after slicing.
        foreach ( $steps as $i => &$step ) {
            $step['position'] = $i + 1;
        }
        unset( $step );

        return array(
            '@context' => 'https://schema.org',
            '@type'    => 'HowTo',
            'name'     => $title,
            'step'     => $steps,
        );
    }

    /**
     * Extract steps from ordered lists that appear after step-related headings.
     *
     * @param string $content Post content.
     * @return array Steps array.
     */
    private function extract_steps_near_headings( $content ) {
        $steps = array();

        // Match: heading with step language, followed by an ordered list.
        // Heading patterns: "How to...", "Steps to...", "Step-by-step...", "Instructions"
        $heading_pattern = '/<h[2-4][^>]*>(.*?(?:how\s+to|steps?\s|step-by-step|instructions?|process|procedure|guide).*?)<\/h[2-4]>/si';

        if ( ! preg_match_all( $heading_pattern, $content, $headings, PREG_OFFSET_CAPTURE ) ) {
            return $steps;
        }

        foreach ( $headings[0] as $match ) {
            $offset  = $match[1] + strlen( $match[0] );
            $rest    = substr( $content, $offset, 5000 ); // Look within 5000 chars after heading.

            // Find the first <ol> after this heading (before any next heading).
            $next_heading_pos = preg_match( '/<h[2-4]/i', $rest, $nh, PREG_OFFSET_CAPTURE ) ? $nh[0][1] : strlen( $rest );
            $section = substr( $rest, 0, $next_heading_pos );

            if ( preg_match( '/<ol[^>]*>(.*?)<\/ol>/si', $section, $ol_match ) ) {
                if ( preg_match_all( '/<li[^>]*>(.*?)<\/li>/si', $ol_match[1], $li_matches ) ) {
                    foreach ( $li_matches[1] as $li ) {
                        $clean = trim( wp_strip_all_tags( $li ) );
                        if ( $this->is_valid_step( $clean ) ) {
                            $steps[] = array(
                                '@type'    => 'HowToStep',
                                'position' => count( $steps ) + 1,
                                'text'     => $clean,
                            );
                        }
                    }
                }
            }
        }

        return $steps;
    }

    // extract_ordered_list_steps intentionally removed.
    // Grabbing "any ordered list" as steps was too aggressive and produced
    // false positives on informational articles with numbered bullet points.

    /**
     * Extract steps from explicit "Step N:" patterns in headings or paragraphs.
     *
     * @param string $content Post content.
     * @return array Steps array.
     */
    private function extract_explicit_step_patterns( $content ) {
        $steps = array();

        // Match: "Step 1: Do something", "Step 2 - Do something else"
        if ( preg_match_all( '/(?:<h[2-6][^>]*>|<p[^>]*>|<li[^>]*>)\s*(?:Step\s+\d+[\s:.\-]+)(.*?)(?:<\/h[2-6]>|<\/p>|<\/li>)/si', $content, $matches ) ) {
            foreach ( $matches[1] as $step_text ) {
                $clean = trim( wp_strip_all_tags( $step_text ) );
                if ( strlen( $clean ) > 15 && strlen( $clean ) < 300 ) {
                    $steps[] = array(
                        '@type'    => 'HowToStep',
                        'position' => count( $steps ) + 1,
                        'text'     => $clean,
                    );
                }
            }
        }

        return $steps;
    }

    /**
     * Validate whether a text string is a genuine actionable step.
     *
     * A valid step should:
     * - Be 15-300 characters (not too short, not a paragraph)
     * - Start with an action verb or contain imperative mood
     * - Not be a statistic, quote, or comparison
     *
     * @param string $text Step text to validate.
     * @return bool True if this looks like a genuine step.
     */
    private function is_valid_step( $text ) {
        $len = strlen( $text );

        // Length check: too short = fragment, too long = paragraph.
        if ( $len < 15 || $len > 300 ) {
            return false;
        }

        // Reject: pure statistics/numbers (e.g., "67% of ChatGPT responses...")
        if ( preg_match( '/^\d+%/', $text ) ) {
            return false;
        }

        // Reject: checkbox items from checklists (e.g., "[ ] Test your brand...")
        if ( preg_match( '/^\[[\sx]\]/i', $text ) ) {
            return false;
        }

        // Reject: items that look like comparisons or statements, not instructions.
        // Statements starting with nouns/articles without action verbs.
        $non_action_starts = array(
            '/^(ChatGPT|Perplexity|Google|Multiple|SEO|AI|The|A|An|Their|Our|His|Her|Its|Most|Many|Some|Several|Various|Free|Cost|Why|Best|Average|Default|Recognized|Helped)\b/i',
        );
        foreach ( $non_action_starts as $pattern ) {
            if ( preg_match( $pattern, $text ) ) {
                return false;
            }
        }

        // Reject: items that are quotes (start with quotation marks).
        if ( preg_match( '/^[\'""\x{201C}\x{201D}]/u', $text ) ) {
            return false;
        }

        // Require: should ideally start with an action verb (imperative mood).
        // Common step starters: Add, Create, Set, Configure, Open, Click, Enter, etc.
        $action_verbs = '/^(add|allow|apply|audit|ask|assemble|avoid|begin|build|calculate|check|choose|clean|click|close|collect|combine|compare|complete|configure|connect|consider|contact|continue|contribute|copy|create|cut|define|delete|deploy|describe|design|determine|develop|disable|download|drag|edit|enable|ensure|enter|establish|evaluate|examine|export|fill|find|fix|focus|follow|format|gather|generate|get|give|go|grant|handle|identify|implement|import|improve|include|insert|install|integrate|investigate|keep|launch|learn|link|list|load|locate|log|maintain|make|manage|map|mark|measure|merge|modify|monitor|move|name|navigate|note|obtain|open|optimize|order|organize|participate|paste|perform|pick|place|plan|post|prepare|press|prevent|print|provide|publish|pull|push|put|read|receive|record|reduce|register|release|remove|rename|repeat|replace|report|request|require|research|reset|resolve|respond|restart|restore|retrieve|review|revise|run|save|scan|schedule|search|secure|select|send|set|share|sign|specify|start|stop|store|structure|submit|subscribe|summarize|switch|sync|tag|take|target|tell|test|track|transfer|try|tune|turn|type|undo|uninstall|unlink|update|upgrade|upload|use|utilize|validate|verify|view|visit|wait|watch|work|write)\b/i';

        if ( preg_match( $action_verbs, $text ) ) {
            return true;
        }

        // Also accept if it contains step-like structure: "X, then Y" or "First, ..." etc.
        if ( preg_match( '/^(first|second|third|next|then|finally|lastly|after|before|once|when|if)\b/i', $text ) ) {
            return true;
        }

        return false;
    }

    /**
     * LocalBusiness schema.
     *
     * @param array $settings Plugin settings.
     * @return array|null
     */
    private function local_business_schema( $settings ) {
        $business = isset( $settings['business'] ) ? $settings['business'] : array();
        $local    = isset( $settings['local_business'] ) ? $settings['local_business'] : array();

        if ( empty( $business['name'] ) ) {
            return null;
        }

        $schema = array(
            '@context' => 'https://schema.org',
            '@type'    => 'LocalBusiness',
            'name'     => $business['name'],
            'url'      => get_site_url(),
        );

        if ( ! empty( $local['address'] ) ) {
            $schema['address'] = array(
                '@type'           => 'PostalAddress',
                'streetAddress'   => isset( $local['address']['street'] ) ? $local['address']['street'] : '',
                'addressLocality' => isset( $local['address']['city'] ) ? $local['address']['city'] : '',
                'addressRegion'   => isset( $local['address']['region'] ) ? $local['address']['region'] : '',
                'postalCode'      => isset( $local['address']['postal_code'] ) ? $local['address']['postal_code'] : '',
                'addressCountry'  => isset( $local['address']['country'] ) ? $local['address']['country'] : '',
            );
        }

        if ( ! empty( $local['phone'] ) ) {
            $schema['telephone'] = $local['phone'];
        }

        if ( ! empty( $local['email'] ) ) {
            $schema['email'] = $local['email'];
        }

        if ( ! empty( $local['geo'] ) ) {
            $schema['geo'] = array(
                '@type'     => 'GeoCoordinates',
                'latitude'  => $local['geo']['lat'],
                'longitude' => $local['geo']['lng'],
            );
        }

        if ( ! empty( $local['hours'] ) ) {
            $schema['openingHoursSpecification'] = $local['hours'];
        }

        if ( ! empty( $local['price_range'] ) ) {
            $schema['priceRange'] = $local['price_range'];
        }

        return $schema;
    }

    /**
     * WooCommerce Product schema.
     *
     * @return array|null
     */
    private function product_schema() {
        if ( ! function_exists( 'wc_get_product' ) ) {
            return null;
        }

        $product = wc_get_product( get_the_ID() );
        if ( ! $product ) {
            return null;
        }

        $schema = array(
            '@context'    => 'https://schema.org',
            '@type'       => 'Product',
            'name'        => $product->get_name(),
            'description' => wp_strip_all_tags( $product->get_short_description() ),
            'url'         => get_permalink(),
            'sku'         => $product->get_sku(),
            'offers'      => array(
                '@type'         => 'Offer',
                'price'         => $product->get_price(),
                'priceCurrency' => get_woocommerce_currency(),
                'availability'  => $product->is_in_stock() ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock',
                'url'           => get_permalink(),
            ),
        );

        $image_id = $product->get_image_id();
        if ( $image_id ) {
            $img = wp_get_attachment_image_src( $image_id, 'full' );
            if ( $img ) {
                $schema['image'] = $img[0];
            }
        }

        if ( $product->get_average_rating() > 0 ) {
            $schema['aggregateRating'] = array(
                '@type'       => 'AggregateRating',
                'ratingValue' => $product->get_average_rating(),
                'reviewCount' => $product->get_review_count(),
            );
        }

        return $schema;
    }

    /**
     * Easy Digital Downloads Product schema.
     *
     * @return array|null
     */
    private function edd_product_schema() {
        if ( ! function_exists( 'edd_get_download' ) ) {
            return null;
        }

        $post_id  = get_the_ID();
        $download = edd_get_download( $post_id );
        if ( ! $download ) {
            return null;
        }

        $price = edd_get_download_price( $post_id );

        // Handle variable pricing: use lowest price.
        if ( edd_has_variable_prices( $post_id ) ) {
            $prices = edd_get_variable_prices( $post_id );
            if ( ! empty( $prices ) ) {
                $amounts = wp_list_pluck( $prices, 'amount' );
                $price   = min( array_map( 'floatval', $amounts ) );
            }
        }

        $schema = array(
            '@context'    => 'https://schema.org',
            '@type'       => 'Product',
            'name'        => get_the_title( $post_id ),
            'description' => wp_strip_all_tags( get_the_excerpt( $post_id ) ),
            'url'         => get_permalink( $post_id ),
            'offers'      => array(
                '@type'         => 'Offer',
                'price'         => $price,
                'priceCurrency' => edd_get_currency(),
                'availability'  => 'https://schema.org/InStock',
                'url'           => get_permalink( $post_id ),
            ),
        );

        // SKU if set.
        $sku = get_post_meta( $post_id, 'edd_sku', true );
        if ( ! empty( $sku ) ) {
            $schema['sku'] = $sku;
        }

        // Featured image.
        $thumb_id = get_post_thumbnail_id( $post_id );
        if ( $thumb_id ) {
            $img = wp_get_attachment_image_src( $thumb_id, 'full' );
            if ( $img ) {
                $schema['image'] = $img[0];
            }
        }

        // Reviews if EDD Reviews extension is active.
        if ( function_exists( 'edd_reviews' ) ) {
            $rating = get_post_meta( $post_id, 'edd_reviews_average_rating', true );
            $count  = get_post_meta( $post_id, 'edd_reviews_count', true );
            if ( $rating && $count ) {
                $schema['aggregateRating'] = array(
                    '@type'       => 'AggregateRating',
                    'ratingValue' => floatval( $rating ),
                    'reviewCount' => intval( $count ),
                );
            }
        }

        return $schema;
    }

    /**
     * Check if the current page is a contact page.
     *
     * @return bool
     */
    private function is_contact_page() {
        if ( ! is_singular( 'page' ) ) {
            return false;
        }

        $post = get_post();
        $slug = $post ? $post->post_name : '';

        return in_array( $slug, array( 'contact', 'contact-us', 'get-in-touch' ), true );
    }

    /**
     * Detect embedded videos and generate VideoObject schema.
     *
     * Only fires when a YouTube or Vimeo iframe is found in post content.
     * Binary check with near-zero false positive risk.
     *
     * @return array|null
     */
    private function detect_video_schema() {
        $post = get_post();
        if ( ! $post ) {
            return null;
        }

        $content = $post->post_content;

        // Match YouTube embeds.
        if ( preg_match( '/(?:youtube\.com\/embed\/|youtu\.be\/)([a-zA-Z0-9_-]{11})/', $content, $yt ) ) {
            $video_id = $yt[1];
            return array(
                '@context'     => 'https://schema.org',
                '@type'        => 'VideoObject',
                'name'         => get_the_title( $post ),
                'description'  => wp_trim_words( wp_strip_all_tags( $post->post_content ), 30, '...' ),
                'thumbnailUrl' => 'https://img.youtube.com/vi/' . $video_id . '/maxresdefault.jpg',
                'uploadDate'   => get_the_date( 'c', $post ),
                'embedUrl'     => 'https://www.youtube.com/embed/' . $video_id,
            );
        }

        // Match Vimeo embeds.
        if ( preg_match( '/vimeo\.com\/(?:video\/)?([0-9]+)/', $content, $vm ) ) {
            return array(
                '@context'     => 'https://schema.org',
                '@type'        => 'VideoObject',
                'name'         => get_the_title( $post ),
                'description'  => wp_trim_words( wp_strip_all_tags( $post->post_content ), 30, '...' ),
                'uploadDate'   => get_the_date( 'c', $post ),
                'embedUrl'     => 'https://player.vimeo.com/video/' . $vm[1],
            );
        }

        return null;
    }

    /**
     * CollectionPage schema for archive pages.
     *
     * Fires on category, tag, and date archives. Template-level detection.
     *
     * @return array
     */
    private function collection_page_schema() {
        $title = '';
        $desc  = '';
        $url   = '';

        if ( is_category() ) {
            $cat   = get_queried_object();
            $title = $cat->name;
            $desc  = $cat->description ?: sprintf( 'Articles in %s', $cat->name );
            $url   = get_category_link( $cat->term_id );
        } elseif ( is_tag() ) {
            $tag   = get_queried_object();
            $title = $tag->name;
            $desc  = $tag->description ?: sprintf( 'Articles tagged %s', $tag->name );
            $url   = get_tag_link( $tag->term_id );
        } elseif ( is_date() ) {
            $title = get_the_archive_title();
            $desc  = sprintf( 'Content published in %s', $title );
            $url   = get_pagenum_link( 1 );
        }

        return array(
            '@context'    => 'https://schema.org',
            '@type'       => 'CollectionPage',
            'name'        => wp_strip_all_tags( $title ),
            'description' => wp_strip_all_tags( $desc ),
            'url'         => $url,
        );
    }

    /**
     * ProfilePage schema for author archive pages.
     *
     * Template-level detection via is_author(). Zero false positive risk.
     *
     * @return array
     */
    private function profile_page_schema() {
        $author = get_queried_object();

        $schema = array(
            '@context'   => 'https://schema.org',
            '@type'      => 'ProfilePage',
            'name'       => sprintf( '%s - Author Profile', $author->display_name ),
            'url'        => get_author_posts_url( $author->ID ),
            'mainEntity' => $this->build_author_schema( $author->ID ),
        );

        // Add @context to nested mainEntity since it is a top-level Person.
        $schema['mainEntity']['@context'] = 'https://schema.org';

        return $schema;
    }

    /**
     * Check if a third-party SEO plugin already handles a schema type.
     *
     * Prevents duplicate schema output when Yoast SEO or Rank Math is active.
     *
     * @param string $type Schema type (Article, FAQPage, HowTo, etc.).
     * @return bool True if another plugin handles this type.
     */
    private function third_party_handles( $type ) {
        $seo_active = defined( 'WPSEO_VERSION' ) || defined( 'RANK_MATH_VERSION' ) || class_exists( '\RankMath\Paper\Paper' );

        if ( ! $seo_active ) {
            return false; // No third-party SEO plugin active, we handle everything.
        }

        // Check user's per-type resolution preference.
        // Values: 'ours' (we output, their filter removes theirs),
        //         'theirs' (we skip, they output),
        //         'both' (we output alongside theirs).
        // Default: 'theirs' for types both plugins produce (safest, no duplication).
        $resolutions = get_option( 'asgm_schema_resolutions', array() );
        $resolution  = isset( $resolutions[ $type ] ) ? $resolutions[ $type ] : null;

        // If user explicitly chose, respect it.
        if ( 'ours' === $resolution || 'both' === $resolution ) {
            return false; // We output.
        }
        if ( 'theirs' === $resolution ) {
            return true; // We skip.
        }

        // No explicit preference set: auto-detect whether the other plugin outputs this type.
        // Default to 'theirs' (skip ours) for types they produce, to avoid duplication
        // until the user visits the Conflicts page and makes a choice.

        // Yoast SEO.
        if ( defined( 'WPSEO_VERSION' ) ) {
            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
            $yoast_on = apply_filters( 'wpseo_json_ld_output', true );
            if ( $yoast_on && in_array( $type, array( 'Article', 'WebSite', 'Organization', 'BreadcrumbList' ), true ) ) {
                return true;
            }
            if ( 'FAQPage' === $type && is_singular() ) {
                $post = get_post();
                if ( $post && has_block( 'yoast/faq-block', $post ) ) {
                    return true;
                }
            }
            if ( 'HowTo' === $type && is_singular() ) {
                $post = get_post();
                if ( $post && has_block( 'yoast/how-to-block', $post ) ) {
                    return true;
                }
            }
        }

        // Rank Math.
        if ( defined( 'RANK_MATH_VERSION' ) || class_exists( '\RankMath\Paper\Paper' ) ) {
            if ( in_array( $type, array( 'Article', 'WebSite', 'Organization', 'BreadcrumbList' ), true ) ) {
                return true;
            }
            if ( 'FAQPage' === $type && is_singular() ) {
                $post = get_post();
                if ( $post && has_block( 'rank-math/faq-block', $post ) ) {
                    return true;
                }
                $rm_snippet = get_post_meta( get_the_ID(), 'rank_math_rich_snippet', true );
                if ( 'faq' === $rm_snippet ) {
                    return true;
                }
            }
            if ( 'HowTo' === $type && is_singular() ) {
                $rm_snippet = get_post_meta( get_the_ID(), 'rank_math_rich_snippet', true );
                if ( 'howto' === $rm_snippet ) {
                    return true;
                }
            }
        }

        return false;
    }
}
