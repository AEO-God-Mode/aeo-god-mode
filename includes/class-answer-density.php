<?php
/**
 * Direct Answer Engine — answer-density detector.
 *
 * Scores how well each post's question-shaped headings are followed by
 * direct-answer content. Foundational AEO signal: extraction-style citations
 * by ChatGPT, Perplexity, Gemini, and Google AI Overviews depend on this
 * pattern. Setup-completeness ("schema coverage %", "llms.txt depth") does
 * not move citation likelihood; answer density does.
 *
 * Output is a per-post score (0-100) plus a list of flagged headings the
 * editor panel and dashboard surface for action.
 *
 * @package AEOGodMode
 */
namespace AISEOGodMode;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Answer_Density {

	const POSTMETA_KEY    = '_asgm_answer_density';
	const SCAN_TS_KEY     = '_asgm_ad_scanned'; // numeric post meta: unix time of last scan. Drives rotation ordering.
	const DISMISS_KEY     = '_asgm_ad_dismissed'; // post meta: array of normalized heading strings the user has dismissed.
	const SUMMARY_OPT     = 'asgm_answer_density_summary';
	const CRON_HOOK       = 'asgm_answer_density_nightly';
	const CONTINUE_HOOK   = 'asgm_answer_density_continue'; // one-off event that drains the tail of a full pass.
	const QUEUE_OPT       = 'asgm_answer_density_queue';    // pending post IDs for an in-progress full pass.
	const BATCH_SIZE      = 50; // posts refreshed per nightly rotation. NOT a cap on total coverage.
	const ANSWER_WINDOW_W = 50; // words after a heading we look at for an answer
	const CACHE_GROUP     = 'asgm_answer_density';

	/**
	 * Containment overlap of A inside B at the trigram level.
	 *
	 * Returns the share of A's trigrams that already appear in B. Asymmetric on
	 * purpose: we want to know "is the opener paraphrasing the body" (A=opener,
	 * B=body), not how similar two arbitrary strings are. Threshold for the
	 * `direct_but_derivative` verdict is intentionally provisional. Ship the
	 * raw score and let real usage tune the cutoff.
	 *
	 * @param string $a The candidate (e.g. AI rewrite).
	 * @param string $b The reference (e.g. body context below the opener).
	 * @return float    0.0 (no overlap) to 1.0 (every trigram already in B).
	 */
	public static function trigram_overlap_containment( $a, $b ) {
		$trigrams = function ( $text ) {
			$text  = strtolower( wp_strip_all_tags( (string) $text ) );
			$text  = preg_replace( '/[^\w\s]+/u', ' ', $text );
			$words = preg_split( '/\s+/', trim( $text ) );
			$words = array_values( array_filter( $words ) );
			$out   = array();
			$n     = count( $words );
			for ( $i = 0; $i + 2 < $n; $i++ ) {
				$out[] = $words[ $i ] . ' ' . $words[ $i + 1 ] . ' ' . $words[ $i + 2 ];
			}
			return array_unique( $out );
		};
		$A = $trigrams( $a );
		if ( empty( $A ) ) { return 0.0; }
		$B = array_flip( $trigrams( $b ) );
		$hits = 0;
		foreach ( $A as $t ) { if ( isset( $B[ $t ] ) ) { ++$hits; } }
		return round( $hits / count( $A ), 3 );
	}

	/**
	 * Pull the most likely "subject" word from a question-shaped heading. Skips
	 * the leading interrogative ("how", "what", etc.) and a small set of
	 * function words, then returns the first remaining content token. Naive on
	 * purpose. Used as a soft signal (subject_position), not as logic. If the
	 * heuristic misses the subject, the emitted position is just -1, which the
	 * UI renders as "n/a". No fake precision.
	 *
	 * @param string $heading
	 * @return string Lowercase subject token, or '' if no candidate found.
	 */
	public static function find_heading_subject( $heading ) {
		$skip = array(
			'how','what','why','when','where','who','which','should','can','could','do','does','did','is','are','was','were',
			'a','an','the','to','of','for','in','on','at','from','by','with','about','as','into','your','our','my',
		);
		$tokens = preg_split( '/\s+/', strtolower( trim( wp_strip_all_tags( (string) $heading ) ) ) );
		foreach ( (array) $tokens as $t ) {
			$t = preg_replace( '/[^\w]/u', '', $t );
			if ( '' === $t ) { continue; }
			if ( ! in_array( $t, $skip, true ) ) { return $t; }
		}
		return '';
	}

	/**
	 * Word index where $subject first appears in $text (case-insensitive). -1
	 * if the subject doesn't appear at all. The classifier's "Direct" verdict
	 * is most trustworthy when subject_position is small (0 to 5).
	 *
	 * @param string $text
	 * @param string $subject Lowercase token from find_heading_subject().
	 * @return int
	 */
	public static function subject_position_in( $text, $subject ) {
		if ( '' === $subject ) { return -1; }
		$tokens = preg_split( '/\s+/', strtolower( wp_strip_all_tags( (string) $text ) ) );
		foreach ( (array) $tokens as $i => $t ) {
			$t = preg_replace( '/[^\w]/u', '', $t );
			if ( $t === $subject ) { return $i; }
		}
		return -1;
	}

	/**
	 * Normalize a heading for use as a stable dismissal key. Strips whitespace
	 * and lowercases so trivial edits like extra spaces or capitalization don't
	 * break the dismissal silently. If the user materially edits the heading,
	 * the dismissal correctly stops applying (new heading, fresh scan).
	 *
	 * @param string $heading
	 * @return string
	 */
	public static function normalize_heading( $heading ) {
		return strtolower( trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( (string) $heading ) ) ) );
	}

	/**
	 * Get the list of dismissed heading keys for a post.
	 *
	 * @param int $post_id
	 * @return string[]
	 */
	public static function get_dismissed( $post_id ) {
		$raw = get_post_meta( (int) $post_id, self::DISMISS_KEY, true );
		return is_array( $raw ) ? array_values( array_unique( array_map( 'strval', $raw ) ) ) : array();
	}

	/**
	 * @param int    $post_id
	 * @param string $heading
	 * @return bool
	 */
	public static function is_dismissed( $post_id, $heading ) {
		$key = self::normalize_heading( $heading );
		return $key !== '' && in_array( $key, self::get_dismissed( $post_id ), true );
	}

	/**
	 * User-asserted "this answer is fine, stop flagging it." Stored per heading
	 * (not per post) so dismissing one heading on a post doesn't silence others.
	 * Triggers a rescan so the score immediately reflects the dismissal.
	 *
	 * @param int    $post_id
	 * @param string $heading
	 * @return array Updated scan result.
	 */
	public static function dismiss( $post_id, $heading ) {
		$key  = self::normalize_heading( $heading );
		if ( '' === $key ) { return array( 'error' => 'empty heading' ); }
		$list = self::get_dismissed( $post_id );
		if ( ! in_array( $key, $list, true ) ) {
			$list[] = $key;
			update_post_meta( (int) $post_id, self::DISMISS_KEY, $list );
		}
		return self::scan_post( (int) $post_id );
	}

	/**
	 * Reverse of dismiss(). Lets the user surface a heading again if they
	 * change their mind.
	 *
	 * @param int    $post_id
	 * @param string $heading
	 * @return array Updated scan result.
	 */
	public static function undismiss( $post_id, $heading ) {
		$key  = self::normalize_heading( $heading );
		if ( '' === $key ) { return array( 'error' => 'empty heading' ); }
		$list = self::get_dismissed( $post_id );
		$list = array_values( array_filter( $list, function ( $k ) use ( $key ) { return $k !== $key; } ) );
		update_post_meta( (int) $post_id, self::DISMISS_KEY, $list );
		return self::scan_post( (int) $post_id );
	}

	/**
	 * Detect H2/H3 question-shaped headings.
	 *
	 * Returns an ordered list with byte offsets so extract_after_heading()
	 * can slice the text between this heading and the next same-or-higher
	 * heading. We deliberately do NOT stop at H4/H5: well-structured posts
	 * use H4s for sub-points inside an answer block — under-counting valid
	 * answers on those posts would punish the right behavior.
	 *
	 * @param string $html Raw post content.
	 * @return array<int, array{level:int,text:string,offset:int,end:int}>
	 */
	public static function find_question_headings( $html ) {
		$out = array();
		if ( empty( $html ) ) { return $out; }

		// Match H2/H3 with their inner content. `$1` = level digit, `$2` = inner.
		if ( ! preg_match_all( '#<h([23])\b[^>]*>(.*?)</h\1>#is', $html, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
			return $out;
		}

		foreach ( $matches as $m ) {
			$level     = (int) $m[1][0];
			$raw_inner = $m[2][0];
			$text      = trim( wp_strip_all_tags( $raw_inner ) );
			$text      = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			if ( $text === '' ) { continue; }

			if ( ! self::is_question( $text ) ) { continue; }

			$tag_offset = (int) $m[0][1];
			$tag_length = strlen( $m[0][0] );

			$out[] = array(
				'level'  => $level,
				'text'   => $text,
				'offset' => $tag_offset,                // start of opening heading tag
				'end'    => $tag_offset + $tag_length,  // first byte after closing heading tag
			);
		}

		return $out;
	}

	/**
	 * Question-shape detector. Three signals — any one qualifies:
	 *  1. Ends with `?`
	 *  2. Starts with an interrogative word.
	 *  3. Imperative "How to X" pattern even without `?` ("How to block GPTBot").
	 */
	private static function is_question( $heading_text ) {
		$t = trim( $heading_text );
		if ( $t === '' ) { return false; }

		if ( substr( $t, -1 ) === '?' ) { return true; }

		// Leading interrogatives — case-insensitive, word boundary.
		if ( preg_match( '/^(How|What|Why|When|Where|Who|Which|Can|Should|Is|Are|Does|Do|Will|Would|Could)\b/i', $t ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Slice the text that "answers" a heading: everything between this
	 * heading and the next SAME-LEVEL OR HIGHER heading (per the spec
	 * refinement — H3 questions can have H4 sub-headings inside the
	 * answer block without us bailing early). We also stop at images and
	 * tables because those are visual breaks.
	 *
	 * @param string $html         Post content.
	 * @param int    $heading_end  Byte offset right after the heading's </h?>.
	 * @param int    $heading_lvl  2 or 3.
	 * @return string Sliced HTML body of the answer block.
	 */
	public static function extract_after_heading( $html, $heading_end, $heading_lvl ) {
		$tail = substr( $html, $heading_end );

		// Find next same-level-or-higher heading. For an H3 question, that's
		// next H2 OR next H3 (NOT H4/H5). For an H2 question, just next H2.
		$level_class = $heading_lvl === 2 ? '2' : '2|3';
		$boundary_re = '#<h(?:' . $level_class . ')\b#i';

		$next_heading = PHP_INT_MAX;
		if ( preg_match( $boundary_re, $tail, $bm, PREG_OFFSET_CAPTURE ) ) {
			$next_heading = (int) $bm[0][1];
		}

		// Visual breaks. Tables and images often signal the answer is over.
		// We pick the FIRST of these or the next heading, whichever comes first.
		$next_break = $next_heading;
		foreach ( array( '#<table\b#i', '#<img\b#i', '#<figure\b#i' ) as $rx ) {
			if ( preg_match( $rx, $tail, $bm2, PREG_OFFSET_CAPTURE ) ) {
				$pos = (int) $bm2[0][1];
				if ( $pos < $next_break ) { $next_break = $pos; }
			}
		}

		if ( $next_break === PHP_INT_MAX ) { return $tail; }
		return substr( $tail, 0, $next_break );
	}

	/**
	 * Direct-answer classifier. Order matters: direct-answer patterns are
	 * checked BEFORE filler so that legitimate definitional sentences like
	 * "This means X" win over the filler regex `^This\s+(is|means)\b`.
	 *
	 * @param string $body_html Body text after a heading (already sliced).
	 * @return array{classification:string,words_before_answer:int,first_sentence:string}
	 */
	public static function classify_answer( $body_html ) {
		$plain = self::body_to_plain( $body_html );
		$plain = trim( $plain );
		if ( $plain === '' ) {
			return array( 'classification' => 'no_answer', 'words_before_answer' => 0, 'first_sentence' => '' );
		}

		// Numbered/bulleted list immediately after the heading (≤ 60 visible
		// chars of leading prose) is itself the answer.
		if ( preg_match( '/^[^.<\n]{0,60}<(ol|ul)\b/i', $body_html ) ) {
			return array(
				'classification'      => 'direct',
				'words_before_answer' => 0,
				'first_sentence'      => self::extract_sentence( $plain, 0 ),
			);
		}

		// Walk the first ~5 sentences and ANSWER_WINDOW_W words. Filler vetoes
		// direct: a sentence like "This means X" technically matches the
		// definitional pattern, but the pronoun subject ("This") needs prior
		// context that AI extraction can't resolve, so it's treated as
		// buried. The filler regex set is the user's curated list of those
		// pronoun-led / hedge-led openers that look definitional but aren't.
		$sentences = self::split_sentences( $plain );
		$word_cursor = 0;

		foreach ( $sentences as $i => $sentence ) {
			$s = trim( $sentence );
			if ( $s === '' ) { continue; }

			$is_answer = self::sentence_is_direct_answer( $s );
			$is_filler = self::sentence_is_filler( $s );

			if ( $is_answer && ! $is_filler ) {
				return array(
					'classification'      => $word_cursor === 0 ? 'direct' : 'buried',
					'words_before_answer' => $word_cursor,
					'first_sentence'      => $sentences[0],
				);
			}

			$word_cursor += str_word_count( $s );
			if ( $word_cursor >= self::ANSWER_WINDOW_W ) { break; }
			if ( $i >= 5 ) { break; }
		}

		return array(
			'classification'      => 'no_answer',
			'words_before_answer' => $word_cursor,
			'first_sentence'      => $sentences[0] ?? '',
		);
	}

	/**
	 * Direct-answer pattern set. A sentence qualifies if any of:
	 *  - Definitional: starts with a noun phrase + is/are/refers to/means.
	 *  - Affirmative: starts with Yes / No.
	 *  - Fact-first: contains a digit OR a date OR a proper-noun pair AND
	 *    is reasonably short (< 35 words — long sentences usually bury).
	 */
	private static function sentence_is_direct_answer( $s ) {
		// Subject character set: letters, digits, spaces, apostrophes,
		// hyphens, AND periods (for "robots.txt", "U.S.", file extensions,
		// version numbers). Without periods we miss legitimate definitional
		// openers like "The robots.txt file works on..."
		$subject = "[A-Z][A-Za-z0-9][A-Za-z0-9\\s'\\-\\.]{0,50}";

		// Definitional copulas: "X is Y", "This means Y", "AEO refers to Y".
		if ( preg_match( '/^' . $subject . '\s+(is|are|refers\s+to|means|stands\s+for|describes|equals|represents)\b/u', $s ) ) {
			return true;
		}

		// Action-verb thesis statements: "X works on Y", "Y uses Z", "X
		// requires N steps". A noun-phrase subject followed by a concrete
		// action verb that names what the thing DOES counts as a direct
		// answer for any "How X works", "What X does", "Why X happens" style
		// heading. Without this, sincere answers get downgraded to indirect.
		// Verb list is broad on purpose: a narrow list keeps surfacing real
		// answers as "no_answer" (e.g. "Perplexity selects sources..." when
		// `selects` wasn't on the list). Pronoun-led subjects (This, It, These)
		// are still caught by filler patterns below, so over-permissive matching
		// here doesn't leak buried-with-pronoun cases through.
		$action_verbs = implode( '|', array(
			// Action / process
			'works','uses','applies','operates','handles','runs','executes','performs','processes','produces',
			'returns','triggers','generates','sends','receives','stores','accepts','rejects','starts','begins',
			'ends','starts','stops','launched','built','created','introduced','releases','provides','offers',
			'powers','controls','manages','requires','allows','prevents','enables','disables','restricts','blocks',
			'permits','limits','enforces','determines','defines','describes','indicates','signals','marks',
			// Selection / ranking / scoring (the bug we found)
			'selects','chooses','picks','identifies','evaluates','prioritizes','prioritises','ranks','scores',
			'rates','grades','measures','tracks','monitors','assesses','filters','sorts','orders','prefers','favors','favours',
			// Retrieval / fetching / crawling
			'crawls','indexes','retrieves','fetches','reads','parses','scans','queries','searches','finds',
			'collects','gathers','captures','records','logs','observes','detects',
			// Computation / output
			'calculates','computes','analyses','analyzes','compares','aggregates','summarises','summarizes',
			'displays','shows','renders','outputs','exports','reports','presents','surfaces','suggests',
			'recommends','highlights','flags',
			// Schema / linking / referencing
			'links','cites','mentions','references','points\s+to','maps\s+to','relies\s+on','depends\s+on',
			'connects','integrates','communicates',
			// State changes
			'updates','replaces','removes','adds','inserts','injects','prepends','appends','rewrites','edits',
			'overwrites','syncs','synchronises','synchronizes','imports','installs','activates','deactivates',
			// Composition phrases
			'consists\s+of','contains','includes','excludes',
		) );
		if ( preg_match( '/^' . $subject . '\s+(' . $action_verbs . ')\b/u', $s ) ) {
			return true;
		}

		// Imperative how-to thesis: "Add X", "Use Y", "Set Z to N".
		if ( preg_match( '/^(Add|Use|Set|Configure|Install|Enable|Disable|Place|Put|Write|Include|Choose|Pick|Run|Block|Allow|Open|Close|Delete|Edit|Update|Replace)\s+[A-Z]?\w/u', $s ) ) {
			return true;
		}

		// Affirmative.
		if ( preg_match( '/^(Yes|No)[\s,\-—:.]/i', $s ) ) { return true; }

		// Fact-first. Concrete numbers, dates, multi-cap proper nouns. Short.
		$word_count = str_word_count( $s );
		if ( $word_count <= 35 ) {
			$has_digit = (bool) preg_match( '/\b\d/', $s );
			$has_capital_pair = (bool) preg_match( '/\b[A-Z][a-zA-Z]+\s+[A-Z][a-zA-Z]+/', $s );
			if ( $has_digit || $has_capital_pair ) { return true; }
		}

		return false;
	}

	/**
	 * Filler / buried-answer opener detector. Augmented with the patterns
	 * the user flagged as common WP buried-answer leads:
	 *   "So, when it comes to..."
	 *   "There are a few things to think about..."
	 *   "It depends..."
	 *   "Now, let's talk about..."
	 *
	 * NOTE: filler is checked AFTER the direct-answer pattern set, so a
	 * sentence like "This means X" hits the definitional pattern first and
	 * never reaches the `^This\s+(is|means)` filler check.
	 */
	private static function sentence_is_filler( $s ) {
		return self::classify_filler( $s ) !== '';
	}

	/**
	 * Classify the FLAVOR of filler so the to-do can give specific advice
	 * instead of a generic "buried" warning. Returns one of:
	 *   'filler'  — content-free intros ("In today's...", "Picture this...")
	 *   'setup'   — defers the answer to a later sentence ("Before we dive in...", "First let's...")
	 *   'hedge'   — refuses to commit ("It depends", "There are several...")
	 *   ''        — not filler
	 *
	 * Order matters: the more specific patterns (setup, hedge) win over the
	 * generic filler bucket.
	 */
	public static function classify_filler( $s ) {
		// Setup — defers the answer to later. Highest priority.
		$setup = array(
			'/^(Before\s+(we|talking|going|jumping)|First,?\s+let|Let.?s\s+(start|begin|first))/i',
			'/^(To\s+(understand|appreciate|grasp)|In\s+order\s+to)\b/i',
			'/^(Welcome\s+to|Before\s+we\s+(dive|get)\s+in)\b/i',
		);
		foreach ( $setup as $rx ) { if ( preg_match( $rx, $s ) ) { return 'setup'; } }

		// Hedge — refuses to give a clear answer.
		$hedge = array(
			'/^(It\s+depends|It\s+varies|There\s+(is|are)\s+(no|several|a\s+few|many))/i',
			'/^(This\s+depends|This\s+varies)/i',
			'/^(The\s+answer\s+(is|depends))/i',
		);
		foreach ( $hedge as $rx ) { if ( preg_match( $rx, $s ) ) { return 'hedge'; } }

		// Generic filler — content-free intros.
		$filler = array(
			'/^(Let.?s|In\s+(today|this|the\s+world\s+of)|When\s+you\s+think\s+about|Picture\s+this|Imagine|Have\s+you\s+ever|It.?s\s+no\s+secret)\b/i',
			'/^(The\s+world\s+of)\b/i',
			'/^(So,?\s|So\s+what|Now,?\s)/i',
			'/^(This\s+(is|can\s+be|means))\b/i',
			'/^(It\s+(is|can\s+be))\b/i',
		);
		foreach ( $filler as $rx ) { if ( preg_match( $rx, $s ) ) { return 'filler'; } }

		return '';
	}

	/**
	 * Body → plain text, capped at $word_limit words. Used as grounding
	 * context for the AI-rewrite flow (gpt-4o-mini token budget is ~500).
	 */
	public static function body_to_first_paragraph( $html, $word_limit = 200 ) {
		$plain = self::body_to_plain( $html );
		$plain = trim( $plain );
		if ( $plain === '' ) { return ''; }

		$words = preg_split( '/\s+/u', $plain );
		if ( count( $words ) > $word_limit ) {
			$words = array_slice( $words, 0, $word_limit );
			$plain = implode( ' ', $words ) . '…';
		}
		return $plain;
	}

	/**
	 * Strip HTML, normalize entities, collapse whitespace.
	 */
	private static function body_to_plain( $html ) {
		$t = wp_strip_all_tags( $html, true );
		$t = html_entity_decode( $t, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$t = preg_replace( '/\s+/u', ' ', $t );
		return trim( $t );
	}

	/**
	 * Naive sentence splitter — good enough for our purposes (we only look
	 * at the first 5 sentences and we never persist the splits).
	 */
	private static function split_sentences( $plain ) {
		$parts = preg_split( '/(?<=[\.\?\!])\s+(?=[A-Z0-9"\'])/u', $plain );
		return $parts ?: array( $plain );
	}

	private static function extract_sentence( $plain, $idx = 0 ) {
		$parts = self::split_sentences( $plain );
		return isset( $parts[ $idx ] ) ? trim( $parts[ $idx ] ) : '';
	}

	/**
	 * Score a single post and persist to postmeta.
	 *
	 * @param int $post_id
	 * @return array Same JSON shape returned by the REST endpoint.
	 */
	public static function scan_post( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post || $post->post_status !== 'publish' ) {
			$blank = array(
				'post_id'              => (int) $post_id,
				'scanned_at'           => gmdate( 'c' ),
				'word_count'           => 0,
				'question_headings'    => 0,
				'direct_answers'       => 0,
				'answer_density_score' => 0,
				'issues'               => array(),
				'above_fold_ratio'     => 0,
			);
			return $blank;
		}

		// "the_content" is a core WordPress filter; we are intentionally invoking
		// it so shortcodes / blocks render before we count headings.
		$content    = (string) apply_filters( 'the_content', $post->post_content ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		$word_count = str_word_count( wp_strip_all_tags( $content ) );

		$headings = self::find_question_headings( $content );

		$direct          = 0;
		$first_sentence  = 0;
		$buried_total    = 0;
		$words_before    = array();
		$issues          = array();

		$dismissed = self::get_dismissed( $post_id );

		foreach ( $headings as $h ) {
			// User-asserted dismissal: count this heading as direct full-credit
			// and skip surfacing it as an issue. The score lifts accordingly,
			// the heading stays off the to-do list, and a fresh scan after a
			// content edit picks the heading up again only if the heading text
			// itself materially changes (normalize_heading uses whitespace and
			// case-insensitive matching, so trivial edits stay dismissed).
			if ( ! empty( $dismissed ) && in_array( self::normalize_heading( $h['text'] ), $dismissed, true ) ) {
				$direct++;
				$first_sentence++;
				$words_before[] = 0;
				continue;
			}

			$body  = self::extract_after_heading( $content, $h['end'], $h['level'] );
			$class = self::classify_answer( $body );

			// Capture the first ~200 words of the body as plain text. The
			// AI-rewrite flow needs this as context: far more grounding than
			// the first sentence alone and cheap to store.
			// 500-word window. The AI rewrite prompt uses this as the
			// "what comes next, do not repeat" block. 200 words was thin:
			// a dense intro paragraph eats it on its own and the model
			// has nothing to know what to differ FROM, so it restated
			// content that already appeared below it.
			$first_paragraph = self::body_to_first_paragraph( $body, 500 );

			// Classify the FLAVOR of the buried opener so the to-do can
			// prescribe a targeted fix (setup vs hedge vs filler vs no_answer).
			$opener_kind = self::classify_filler( $class['first_sentence'] );

			if ( $class['classification'] === 'direct' ) {
				$direct++;
				if ( $class['words_before_answer'] === 0 ) { $first_sentence++; }
				$words_before[] = $class['words_before_answer'];
			} elseif ( $class['classification'] === 'buried' ) {
				$buried_total++;
				$words_before[] = $class['words_before_answer'];
				// Falling back from explicit filler-pattern matches to
				// "indirect" (rather than "setup") is honest: when the opener
				// isn't filler AND isn't direct, the writer attempted an
				// answer that just didn't lead with the thesis. Calling it
				// "Setup" was misleading — the sentence may not be a setup
				// at all, just an indirect angle.
				$issues[] = array(
					'heading'              => $h['text'],
					'heading_level'        => $h['level'],
					'issue'                => 'buried_answer',
					'opener_kind'          => $opener_kind ?: 'indirect',
					'words_before_answer'  => $class['words_before_answer'],
					'first_sentence'       => $class['first_sentence'],
					'first_paragraph'      => $first_paragraph,
				);
			} else {
				// No direct answer found at all in the first 50 words.
				// Still surface the opener_kind if filler patterns matched,
				// otherwise label as no_answer (truly missing).
				$issues[] = array(
					'heading'              => $h['text'],
					'heading_level'        => $h['level'],
					'issue'                => 'no_direct_answer',
					'opener_kind'          => $opener_kind ?: 'no_answer',
					'words_before_answer'  => $class['words_before_answer'],
					'first_sentence'       => $class['first_sentence'],
					'first_paragraph'      => $first_paragraph,
				);
			}
		}

		$Q                = count( $headings );
		$A                = $direct;
		$above_fold_ratio = $Q > 0 ? ( $first_sentence / $Q ) : 0.0;

		// Base ratio with partial credit for buried answers.
		// A buried answer is one where the answer exists in the body but isn't
		// in sentence 1 (the writer answered the heading, just took a roundabout
		// path). AI engines can still extract from there — it's worse than direct
		// but materially better than no_answer. Weighting 0.5 keeps the rubric
		// honest: full direct = 1.0, partial buried = 0.5, no_answer = 0.0.
		// Without partial credit, every "could be more direct" post collapses to
		// score 0 alongside posts that didn't answer the question at all, which
		// hides real improvement from AI Rewrite passes.
		$weighted_answers = $direct + ( $buried_total * 0.5 );
		$ratio            = $Q > 0 ? ( $weighted_answers / $Q ) : 0.0;
		$score            = $ratio * 100;

		// Quality modifier.
		$score += $first_sentence * 5;             // +5 per above-fold answer
		$avg_before = $words_before ? array_sum( $words_before ) / count( $words_before ) : 0;
		if ( $avg_before > 30 ) { $score -= 10; }
		if ( $Q >= 3 ) { $score += 10; }

		// Long-form fluff penalty — with the user's exemption: a deep tutorial
		// that nails one question with one above-fold answer is doing exactly
		// the right thing and should not be penalized.
		$single_topic_pass = ( $Q === 1 && $A === 1 && $above_fold_ratio === 1.0 );
		if ( $word_count > 1500 && $Q < 2 && ! $single_topic_pass ) {
			$score -= 20;
		}

		$score = max( 0, min( 100, (int) round( $score ) ) );

		$result = array(
			'post_id'              => (int) $post_id,
			'scanned_at'           => gmdate( 'c' ),
			'word_count'           => (int) $word_count,
			'question_headings'    => $Q,
			'direct_answers'       => $A,
			'buried_answers'       => $buried_total,
			'answer_density_score' => $score,
			'issues'               => $issues,
			'above_fold_ratio'     => round( $above_fold_ratio, 2 ),
		);

		update_post_meta( $post_id, self::POSTMETA_KEY, $result );
		// Lightweight numeric stamp used purely for rotation ordering. Lets the
		// nightly cron find never-scanned and least-recently-scanned posts with
		// a plain indexed meta query instead of unserializing every scan row.
		update_post_meta( $post_id, self::SCAN_TS_KEY, time() );
		// Invalidate the cached postmeta scan so the dashboard picks up the
		// new value on its next refresh_summary() call.
		wp_cache_delete( 'asgm_density_rows', self::CACHE_GROUP );
		return $result;
	}

	/**
	 * Read the persisted scan for a post (does not trigger a fresh scan).
	 */
	public static function get_for_post( $post_id ) {
		$saved = get_post_meta( $post_id, self::POSTMETA_KEY, true );
		return is_array( $saved ) ? $saved : null;
	}

	/**
	 * Full-catalog scan — the manual "Re-scan" path.
	 *
	 * Walks EVERY published post and page, scanning in a time-budgeted loop so a
	 * single request never runs away. Whatever can't be reached inside the
	 * budget is queued and a background event drains the rest, chaining until
	 * the entire catalog is covered. Coverage is complete regardless of how many
	 * posts the site has — the old fixed 50-post cap that left larger sites
	 * partially graded is gone.
	 *
	 * @return array Site summary reflecting the synchronous portion of the pass.
	 */
	public static function scan_all_posts() {
		self::run_scan_pass( self::all_published_ids(), self::time_budget() );
		return self::get_summary();
	}

	/**
	 * Background continuation of a full pass. Drains the queued tail the same
	 * time-budgeted way and reschedules itself until the queue is empty.
	 */
	public static function continue_scan() {
		$ids = get_option( self::QUEUE_OPT, array() );
		if ( empty( $ids ) || ! is_array( $ids ) ) {
			delete_option( self::QUEUE_OPT );
			return;
		}
		self::run_scan_pass( $ids, self::time_budget() );
	}

	/**
	 * Nightly rotation — the cron path.
	 *
	 * Refreshes up to BATCH_SIZE posts per night, never-scanned first and then
	 * least-recently-scanned, so the whole catalog cycles through over a few
	 * nights without re-grading the same set every run. Any site left
	 * under-covered by an older build self-heals within a few nightly cycles.
	 */
	public static function scan_rotation() {
		foreach ( self::rotation_targets( self::BATCH_SIZE ) as $pid ) {
			self::scan_post( (int) $pid );
		}
		self::refresh_summary();
	}

	/**
	 * Scan a list of post IDs within a wall-clock budget. IDs not reached are
	 * persisted to the queue and a single background event is scheduled to
	 * continue. Always refreshes the summary so the dashboard tracks progress
	 * after each chunk.
	 *
	 * @param int[] $ids
	 * @param float $budget Seconds of wall-clock to spend this request.
	 */
	private static function run_scan_pass( $ids, $budget ) {
		$ids   = array_values( array_unique( array_map( 'intval', (array) $ids ) ) );
		$start = microtime( true );

		while ( ! empty( $ids ) ) {
			self::scan_post( (int) array_shift( $ids ) );
			if ( ( microtime( true ) - $start ) >= $budget ) { break; }
		}

		if ( ! empty( $ids ) ) {
			// Tail remains — persist it and schedule one continuation. The
			// wp_next_scheduled guard keeps a single chain even if traffic
			// fires cron while a continuation is already pending.
			update_option( self::QUEUE_OPT, array_values( $ids ), false );
			if ( ! wp_next_scheduled( self::CONTINUE_HOOK ) ) {
				wp_schedule_single_event( time() + 30, self::CONTINUE_HOOK );
			}
		} else {
			delete_option( self::QUEUE_OPT );
		}

		self::refresh_summary();
	}

	/**
	 * Every published post + page ID, oldest-modified first. IDs only, so this
	 * stays cheap even on catalogs with thousands of entries.
	 *
	 * @return int[]
	 */
	private static function all_published_ids() {
		$ids = get_posts( array(
			'post_type'           => array( 'post', 'page' ),
			'post_status'         => 'publish',
			'posts_per_page'      => -1,
			'orderby'             => 'modified',
			'order'               => 'ASC',
			'fields'              => 'ids',
			'no_found_rows'       => true,
			'ignore_sticky_posts' => true,
			'suppress_filters'    => true,
		) );
		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Rotation targets for the nightly cron: never-scanned posts first (no scan
	 * stamp yet), then the least-recently-scanned, up to $limit. Two plain
	 * indexed meta queries — no full-table unserialization.
	 *
	 * @param int $limit
	 * @return int[]
	 */
	private static function rotation_targets( $limit ) {
		$limit = max( 1, (int) $limit );

		// 1) Never-scanned posts (no rotation stamp) take priority.
		$never = get_posts( array(
			'post_type'        => array( 'post', 'page' ),
			'post_status'      => 'publish',
			'posts_per_page'   => $limit,
			'orderby'          => 'modified',
			'order'            => 'ASC',
			'fields'           => 'ids',
			'no_found_rows'    => true,
			'suppress_filters' => true,
			'meta_query'       => array(
				array( 'key' => self::SCAN_TS_KEY, 'compare' => 'NOT EXISTS' ),
			),
		) );
		$never = array_map( 'intval', (array) $never );

		if ( count( $never ) >= $limit ) {
			return array_slice( $never, 0, $limit );
		}

		// 2) Fill the remainder with the oldest-scanned posts. The meta_key +
		// meta_value_num orderby inner-joins on the stamp, so this returns only
		// already-scanned posts — no overlap with step 1.
		$old = get_posts( array(
			'post_type'        => array( 'post', 'page' ),
			'post_status'      => 'publish',
			'posts_per_page'   => $limit - count( $never ),
			'orderby'          => 'meta_value_num',
			'meta_key'         => self::SCAN_TS_KEY,
			'order'            => 'ASC',
			'fields'           => 'ids',
			'no_found_rows'    => true,
			'suppress_filters' => true,
		) );
		$old = array_map( 'intval', (array) $old );

		return array_values( array_unique( array_merge( $never, $old ) ) );
	}

	/**
	 * Wall-clock budget for one synchronous scan request. Uses most of the
	 * host's max_execution_time but stays well under common proxy/CDN timeouts
	 * so the Re-scan call returns promptly; the tail (if any) finishes in the
	 * background.
	 *
	 * @return float Seconds.
	 */
	private static function time_budget() {
		$max = (int) ini_get( 'max_execution_time' ); // 0 = unlimited (CLI / some hosts).
		if ( $max <= 0 ) { return 25.0; }
		return max( 8.0, min( 25.0, $max * 0.6 ) );
	}

	/**
	 * Aggregate across all scanned posts. Cached in the summary option so
	 * the dashboard read is O(1).
	 */
	public static function refresh_summary() {
		global $wpdb;

		// Cache the postmeta scan for 5 minutes. The dashboard hits this
		// repeatedly on render; the underlying data only changes when a post
		// is scanned. flush_summary_cache() invalidates it after each scan.
		$cache_key = 'asgm_density_rows';
		$rows      = wp_cache_get( $cache_key, self::CACHE_GROUP );
		if ( false === $rows ) {
			$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				$wpdb->prepare(
					"SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s",
					self::POSTMETA_KEY
				)
			);
			wp_cache_set( $cache_key, $rows, self::CACHE_GROUP, 5 * MINUTE_IN_SECONDS );
		}

		$total_scanned    = 0;
		$applicable_posts = 0;   // posts that have at least 1 question heading
		$na_posts         = 0;   // posts with no question headings (not graded)
		$sum_score        = 0;
		$weak             = 0;
		$weakest          = array();
		$last_scan        = '';

		foreach ( (array) $rows as $r ) {
			$data = maybe_unserialize( $r->meta_value );
			if ( ! is_array( $data ) ) { continue; }
			$total_scanned++;

			$Q = isset( $data['question_headings'] ) ? (int) $data['question_headings'] : 0;
			$score = isset( $data['answer_density_score'] ) ? (int) $data['answer_density_score'] : 0;

			// Posts with no question headings aren't informational Q&A pages —
			// e.g. Pricing, Blog index, Cart. Don't grade them, don't average
			// them into the site score, don't list them as "weakest." They
			// simply have no direct-answer surface.
			if ( $Q === 0 ) { $na_posts++; continue; }

			$applicable_posts++;
			$sum_score += $score;
			if ( $score < 50 ) { $weak++; }

			$direct = isset( $data['direct_answers'] ) ? (int) $data['direct_answers'] : 0;
			$buried = isset( $data['buried_answers'] ) ? (int) $data['buried_answers'] : 0;
			$missing = max( 0, $Q - $direct - $buried );

			// One-line "issue summary" for the dashboard row — readable.
			$bits = array();
			if ( $missing > 0 ) { $bits[] = $missing . ' missing'; }
			if ( $buried > 0 )  { $bits[] = $buried . ' buried'; }
			if ( $direct > 0 )  { $bits[] = $direct . ' direct'; }

			// Pull the first issue so the dashboard's to-do list can show
			// the specific heading + buried opener + classification
			// without an extra round trip per row.
			$first_issue = null;
			if ( ! empty( $data['issues'] ) && is_array( $data['issues'] ) ) {
				$f = $data['issues'][0];
				$first_issue = array(
					'heading'             => isset( $f['heading'] ) ? (string) $f['heading'] : '',
					'heading_level'       => isset( $f['heading_level'] ) ? (int) $f['heading_level'] : 0,
					'issue'               => isset( $f['issue'] ) ? (string) $f['issue'] : '',
					'opener_kind'         => isset( $f['opener_kind'] ) ? (string) $f['opener_kind'] : '',
					'words_before_answer' => isset( $f['words_before_answer'] ) ? (int) $f['words_before_answer'] : 0,
					'first_sentence'      => isset( $f['first_sentence'] ) ? (string) $f['first_sentence'] : '',
				);
			}

			$weakest[] = array(
				'post_id'           => (int) $r->post_id,
				'score'             => $score,
				'question_headings' => $Q,
				'direct_answers'    => $direct,
				'buried_answers'    => $buried,
				'missing_answers'   => $missing,
				'summary'           => implode( ' · ', $bits ),
				'first_issue'       => $first_issue,
			);

			if ( ! empty( $data['scanned_at'] ) && $data['scanned_at'] > $last_scan ) {
				$last_scan = $data['scanned_at'];
			}
		}

		usort( $weakest, function ( $a, $b ) { return $a['score'] - $b['score']; } );
		$weakest = array_slice( $weakest, 0, 10 );

		// Status label so the dashboard doesn't have to invent one.
		$avg = $applicable_posts > 0 ? (int) round( $sum_score / $applicable_posts ) : 0;
		if ( $applicable_posts === 0 )       { $status = 'no_data'; }
		elseif ( $avg >= 75 )                 { $status = 'excellent'; }
		elseif ( $avg >= 60 )                 { $status = 'good'; }
		elseif ( $avg >= 40 )                 { $status = 'needs_work'; }
		else                                  { $status = 'weak'; }

		$summary = array(
			'avg_score'        => $avg,
			'status'           => $status,
			'applicable_posts' => $applicable_posts,
			'na_posts'         => $na_posts,
			'total_scanned'    => $total_scanned,
			'weak_posts'       => $weak,
			'weakest'          => $weakest,
			'last_scanned_at'  => $last_scan,
			'refreshed_at'     => gmdate( 'c' ),
		);
		update_option( self::SUMMARY_OPT, $summary, false );
		return $summary;
	}

	public static function get_summary() {
		$s = get_option( self::SUMMARY_OPT, array() );
		return is_array( $s ) ? $s : array();
	}

	/**
	 * Bootstrap: cron + on-save scanning. Called from class-main.php.
	 */
	public static function init() {
		// Nightly rotation (bounded + self-healing).
		add_action( self::CRON_HOOK, array( __CLASS__, 'scan_rotation' ) );
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + 600, 'daily', self::CRON_HOOK );
		}

		// Background drain of a full (manual) pass on large catalogs.
		add_action( self::CONTINUE_HOOK, array( __CLASS__, 'continue_scan' ) );

		// On-save: scan changed posts immediately so the editor panel sees
		// the new score on next refresh.
		add_action( 'save_post', function ( $post_id, $post, $update ) {
			if ( wp_is_post_revision( $post_id ) ) { return; }
			if ( wp_is_post_autosave( $post_id ) ) { return; }
			if ( ! in_array( $post->post_type, array( 'post', 'page' ), true ) ) { return; }
			if ( $post->post_status !== 'publish' ) { return; }
			self::scan_post( $post_id );
		}, 20, 3 );
	}
}
