<?php
// Citability scoring regression test. Command line only: it ships inside the
// plugin folder, so it must do nothing when requested over the web.
//
//   php tests/citability-scoring.php [path/to/aeo-god-mode-pro]
//
// Covers the fixes for: posts beyond the old 200 cap, non-article pages being
// graded, formatted question headings, currency amounts, the answer-first
// rule (hedges, "Let's be clear:"), and text in separate blocks being joined.
namespace {
    if ( 'cli' !== PHP_SAPI ) {
        exit;
    }
    define( 'ABSPATH', '/tmp/' );

    class WP_Post {
        public $ID; public $post_type = 'post'; public $post_status = 'publish';
        public $post_name = ''; public $post_content = ''; public $post_title = ''; public $post_author = 1;
        public function __construct( $a ) { foreach ( $a as $k => $v ) { $this->$k = $v; } }
    }

    $GLOBALS['__opts']  = array();
    $GLOBALS['__posts'] = array();
    $GLOBALS['__meta']  = array();

    function get_option( $k, $d = false ) { return $GLOBALS['__opts'][ $k ] ?? $d; }
    function update_option( $k, $v, $a = null ) { $GLOBALS['__opts'][ $k ] = $v; return true; }
    function delete_option( $k ) { unset( $GLOBALS['__opts'][ $k ] ); return true; }
    function get_post( $id ) { return $id instanceof WP_Post ? $id : ( $GLOBALS['__posts'][ (int) $id ] ?? null ); }
    function get_posts( $args ) {
        $ids = array();
        foreach ( $GLOBALS['__posts'] as $p ) {
            if ( in_array( $p->post_type, (array) $args['post_type'], true ) && 'publish' === $p->post_status ) {
                $ids[] = $p->ID;
            }
        }
        sort( $ids );
        return $ids;
    }
    function get_post_meta( $id, $k = '', $single = false ) { return $GLOBALS['__meta'][ $id ][ $k ] ?? ''; }
    function update_post_meta( $id, $k, $v ) { $GLOBALS['__meta'][ $id ][ $k ] = $v; return true; }
    function delete_post_meta( $id, $k ) { unset( $GLOBALS['__meta'][ $id ][ $k ] ); return true; }
    function get_the_title( $p ) { $p = get_post( $p ); return $p ? $p->post_title : ''; }
    function get_permalink( $p ) { $p = get_post( $p ); return $p ? 'https://example.com/' . $p->post_name . '/' : ''; }
    function get_site_url() { return 'https://example.com'; }
    function wp_parse_url( $u, $c = -1 ) { return -1 === $c ? parse_url( $u ) : parse_url( $u, $c ); }
    function current_time( $t ) { return '2026-11-23 12:00:00'; }
    function wp_list_pluck( $list, $f ) { return array_map( function( $x ) use ( $f ) { return is_array( $x ) ? $x[ $f ] : $x->$f; }, $list ); }
    function apply_filters( $tag, $v ) { return $v; }
    function has_shortcode( $c, $t ) { return false !== strpos( (string) $c, '[' . $t ); }
    function strip_shortcodes( $c ) { return $c; }
    function wp_strip_all_tags( $s, $break = false ) {
        $s = preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', (string) $s );
        $s = strip_tags( $s );
        return $break ? trim( preg_replace( '/[\r\n\t ]+/', ' ', $s ) ) : trim( $s );
    }
    function sanitize_text_field( $s ) { return trim( (string) $s ); }
    function sanitize_key( $k ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $k ) ); }
    function get_post_types( $a = array(), $o = 'names' ) { return array( 'post' => 'post', 'page' => 'page' ); }
    function wp_json_encode( $v ) { return json_encode( $v ); }
    function __( $s ) { return $s; }
    function is_wp_error( $x ) { return false; }
    function get_post_type( $p ) { $p = get_post( $p ); return $p ? $p->post_type : false; }
    function get_post_status( $p ) { $p = get_post( $p ); return $p ? $p->post_status : false; }

    $pro = $argv[1] ?? dirname( __DIR__, 2 ) . '/aeo-god-mode-pro';
    require dirname( __DIR__ ) . '/includes/class-answer-density.php';
    require $pro . '/includes/class-citability-score.php';
}

namespace AISEOGodMode {
    $fails = 0;
    $pass  = 0;
    function check( $name, $ok, $detail = '' ) {
        global $fails, $pass;
        if ( $ok ) { $pass++; echo "ok   $name\n"; }
        else { $fails++; echo "FAIL $name" . ( '' !== $detail ? "  ($detail)" : '' ) . "\n"; }
    }
    function call_private( $obj, $method, ...$args ) {
        $m = new \ReflectionMethod( $obj, $method );
        $m->setAccessible( true );
        return $m->invoke( $obj, ...$args );
    }
    function words( $n, $word = 'auction' ) { return trim( str_repeat( $word . ' ', $n ) ); }
    function add_post( $id, $type, $slug, $content, $title = '' ) {
        $GLOBALS['__posts'][ $id ] = new \WP_Post( array(
            'ID' => $id, 'post_type' => $type, 'post_name' => $slug,
            'post_content' => $content, 'post_title' => $title ?: $slug,
        ) );
    }

    $c = new CitabilityScore();

    // 1. No cap: a site with 260 posts has every one scored, oldest included.
    $article = '<h2>What is a tax deed?</h2><p>A tax deed is a legal document that transfers a property after unpaid taxes.</p><p>' . words( 200 ) . '</p>';
    for ( $i = 1; $i <= 260; $i++ ) {
        add_post( $i, 'post', 'post-' . $i, $article );
    }
    $after = 0;
    $guard = 0;
    do {
        $b     = $c->score_batch( $after, 50 );
        $after = (int) $b['next_cursor'];
    } while ( empty( $b['done'] ) && ++$guard < 20 );
    $ids = array_map( 'intval', wp_list_pluck( $b['data']['results'], 'post_id' ) );
    check( 'all 260 posts scored (no 200 cap)', 260 === count( $ids ), count( $ids ) . ' scored' );
    check( 'oldest post (ID 1) is scored', in_array( 1, $ids, true ) );
    $GLOBALS['__posts'] = array();

    // 2. Non-article pages are skipped with a plain reason; real guides are not.
    $long = '<p>' . words( 400 ) . '</p>';
    add_post( 10, 'page', 'home', $long );
    add_post( 11, 'page', 'blog', $long );
    add_post( 12, 'page', 'book-a-call', $long );
    add_post( 13, 'page', 'testimonials', $long );
    add_post( 14, 'page', 'tax-lien-calculator', $long );
    add_post( 15, 'page', 'payment-schedule-explained', $long );
    add_post( 16, 'post', 'hidden-yoast', $long );
    add_post( 17, 'post', 'hidden-rank-math', $long );
    add_post( 18, 'page', 'hidden-seopress', $long );
    add_post( 19, 'page', 'privacy-policy', $long );
    add_post( 20, 'page', 'examplearticle', $long );
    add_post( 21, 'post', 'tiny', '<p>Watch the video.</p>' );
    add_post( 22, 'post', 'booking-mistakes-guide', $long );
    $GLOBALS['__opts']['page_on_front']  = 10;
    $GLOBALS['__opts']['page_for_posts'] = 11;
    $GLOBALS['__meta'][16]['_yoast_wpseo_meta-robots-noindex'] = '1';
    $GLOBALS['__meta'][17]['rank_math_robots']                 = array( 'noindex', 'nofollow' );
    $GLOBALS['__meta'][18]['_seopress_robots_index']           = 'yes';
    $expect = array(
        10 => 'Home page or blog index',
        11 => 'Home page or blog index',
        12 => 'Booking, testimonial or tool page',
        13 => 'Booking, testimonial or tool page',
        14 => 'Booking, testimonial or tool page',
        15 => '',
        16 => 'Hidden from search engines',
        17 => 'Hidden from search engines',
        18 => 'Hidden from search engines',
        19 => 'Legal, account or checkout page',
        20 => 'Booking, testimonial or tool page',
        21 => 'Too little text to score (under 150 words)',
        22 => '',
    );
    foreach ( $expect as $id => $reason ) {
        $got = call_private( $c, 'skip_reason', get_post( $id ) );
        check( 'skip reason for ' . get_post( $id )->post_name, $reason === $got, "got '$got'" );
    }
    $GLOBALS['__posts'] = array();
    $GLOBALS['__meta']  = array();
    $GLOBALS['__opts']  = array();

    // 3. A bold or italic question heading counts as a question heading.
    $bold = '<h2>Overview</h2><p>' . words( 50 ) . '</p><h2><strong>What is a tax deed?</strong></h2><p>A tax deed is a legal document.</p>';
    check( 'bold question heading counts', true === call_private( $c, 'has_question_heading', $bold ) );
    check( 'italic question heading counts', true === call_private( $c, 'has_question_heading', '<h3><em>How do auctions work</em></h3>' ) );
    $ad = Answer_Density::scan_html( $bold );
    check( 'Answer Density finds the bold question heading', 1 === (int) ( $ad['question_headings'] ?? 0 ), json_encode( $ad['question_headings'] ?? null ) );

    // 4. Currency amounts count as concrete figures, however they are written.
    $amounts = array(
        '<p>Opening bids start at $500.</p>'          => 1,
        '<p>Opening bids start at &#36;500.</p>'      => 1,
        '<p>Opening bids start at $&nbsp;500.</p>'    => 1,
        '<p>Fees run &pound;1,250 at auction.</p>'    => 1,
        '<p>The fee is USD 500 per lien.</p>'         => 1,
        '<p>The fee is 500 USD per lien.</p>'         => 1,
        '<p>Certificates sold for 750 dollars.</p>'   => 1,
        '<p>Most buyers pay US$2,000.</p>'            => 1,
        '<p>A $500 lien earned 18% a year.</p>'       => 2,
        '<p>No figures in this sentence at all.</p>'  => 0,
    );
    foreach ( $amounts as $html => $n ) {
        $got = call_private( $c, 'count_original_data', CitabilityScore::plain_text( $html ) );
        check( 'figures in ' . strip_tags( $html ), $n === $got, "got $got, want $n" );
    }

    // 5. Text in separate blocks stays separate sentences.
    $blocks = '<p>Tax deeds are legal documents.</p><p>Liens are claims on a property.</p><ul><li>Bids are public</li><li>Fees are fixed</li></ul>';
    $plain  = CitabilityScore::plain_text( $blocks );
    check( 'blocks are not glued together', false === strpos( $plain, 'documents.Liens' ) && false === strpos( $plain, 'publicFees' ), $plain );
    check( 'each short block line is quotable', 2 <= call_private( $c, 'count_quotable_sentences', $plain ), (string) call_private( $c, 'count_quotable_sentences', $plain ) );
    $first = Answer_Density::classify_answer( '<p>A tax deed transfers ownership after unpaid taxes.</p><p>Next, the county sets a date.</p>', 'What does a tax deed transfer?' );
    check( 'Answer Density first sentence stops at the block', 'A tax deed transfers ownership after unpaid taxes.' === $first['first_sentence'], $first['first_sentence'] );

    // 6. Answer-first rule fixes from the Jev study.
    $cases = array(
        array( 'What is the closest Xagio alternative?', '<p>The closest match depends on the job you need done most.</p>', false ),
        array( 'How much does a tax lien cost?', '<p>Tax lien pricing depends on the county and the size of the debt owed.</p>', false ),
        array( 'Which AI crawlers render JavaScript?', '<p>Not all crawlers render JavaScript the same way when they visit.</p>', false ),
        array( 'Is llms.txt a ranking factor?', '<p>Let\'s be perfectly clear: llms.txt is not a confirmed ranking factor for any search engine.</p>', true ),
        array( 'Is llms.txt a ranking factor?', '<p>To be clear, llms.txt is not a confirmed ranking factor for any search engine.</p>', true ),
        array( 'What is a tax deed?', '<p>A tax deed is a legal document that transfers a property after unpaid taxes.</p>', true ),
        array( 'Whose API keys power citation tracking?', '<p>Yours.</p>', true ),
    );
    foreach ( $cases as $case ) {
        $r  = Answer_Density::classify_answer( $case[1], $case[0] );
        $ok = ( 'direct' === $r['classification'] ) === $case[2];
        check( ( $case[2] ? 'direct: ' : 'not direct: ' ) . strip_tags( $case[1] ), $ok, $r['classification'] );
    }

    // 7. Versions bumped so stored scores are flagged for a re-scan.
    check( 'Citability score version is 5', 5 === CitabilityScore::SCORE_VERSION );
    check( 'Answer Density score version is 8', 8 === Answer_Density::SCORE_VERSION );

    echo "\n$pass passed, $fails failed\n";
    exit( $fails ? 1 : 0 );
}
