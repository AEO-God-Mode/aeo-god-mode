<?php
/**
 * Canonical parser for AEO God Mode FAQ shortcodes.
 *
 * @package AISEOGodMode
 */

namespace AISEOGodMode;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** Shared FAQ shortcode contract used by schema and quality scorers. */
class FaqParser {

    /**
     * Parse complete, non-empty [aeogm_faq] pairs.
     *
     * @param string $content Raw post content.
     * @return array{pairs:array,wrapper_balanced:bool,item_open_count:int,item_close_count:int}
     */
    public static function parse_aeogm( $content ) {
        $content = (string) $content;
        $pairs   = array();

        $wrapper_open  = preg_match_all( '/\[aeogm_faqs\b[^\]]*\]/i', $content, $wrapper_open_matches );
        $wrapper_close = preg_match_all( '/\[\/aeogm_faqs\]/i', $content, $wrapper_close_matches );
        $item_open     = preg_match_all( '/\[aeogm_faq\b[^\]]*\]/i', $content, $item_open_matches );
        $item_close    = preg_match_all( '/\[\/aeogm_faq\]/i', $content, $item_close_matches );
        $item_structure_balanced = true;

        $pattern = '/\[aeogm_faq\s+[^\]]*?(?:q|title)\s*=\s*(?:"([^"]+)"|\'([^\']+)\')[^\]]*\](.+?)\[\/aeogm_faq\]/si';
        if ( preg_match_all( $pattern, $content, $matches, PREG_SET_ORDER ) ) {
            foreach ( $matches as $match ) {
                $question = trim( '' !== $match[1] ? $match[1] : $match[2] );
                $answer   = trim( wp_strip_all_tags( strip_shortcodes( $match[3] ) ) );
                $answer   = preg_replace( '/\s+/', ' ', $answer );
                if ( '' === $question || '' === $answer ) {
                    continue;
                }
                $pairs[] = array(
                    'question' => $question,
                    'answer'   => $answer,
                );
            }
        }

        /*
         * aeogodmode.io predates the portable plugin blocks and uses the
         * theme-native [faq][q]Question[/q][a]Answer[/a][/faq] form. Treat it
         * as the same canonical FAQ structure so validation, schema and
         * Citability agree with what the live theme renders.
         */
        if ( 0 === $wrapper_open && 0 === $wrapper_close && 0 === $item_open && 0 === $item_close ) {
            $wrapper_open  = preg_match_all( '/\[faq\b[^\]]*\]/i', $content, $wrapper_open_matches );
            $wrapper_close = preg_match_all( '/\[\/faq\]/i', $content, $wrapper_close_matches );
            $question_open = preg_match_all( '/\[q\]/i', $content, $question_open_matches );
            $question_close = preg_match_all( '/\[\/q\]/i', $content, $question_close_matches );
            $answer_open   = preg_match_all( '/\[a\]/i', $content, $answer_open_matches );
            $answer_close  = preg_match_all( '/\[\/a\]/i', $content, $answer_close_matches );
            $item_structure_balanced = (int) $question_open === (int) $question_close
                && (int) $answer_open === (int) $answer_close
                && (int) $question_open === (int) $answer_open;

            $theme_pattern = '/\[q\](.*?)\[\/q\]\s*\[a\](.*?)\[\/a\]/si';
            if ( preg_match_all( $theme_pattern, $content, $theme_matches, PREG_SET_ORDER ) ) {
                foreach ( $theme_matches as $theme_match ) {
                    $question = trim( wp_strip_all_tags( strip_shortcodes( $theme_match[1] ) ) );
                    $answer   = trim( wp_strip_all_tags( strip_shortcodes( $theme_match[2] ) ) );
                    $question = preg_replace( '/\s+/', ' ', $question );
                    $answer   = preg_replace( '/\s+/', ' ', $answer );
                    if ( '' === $question || '' === $answer ) {
                        continue;
                    }
                    $pairs[] = array(
                        'question' => $question,
                        'answer'   => $answer,
                    );
                }
            }

            $item_open  = min( (int) $question_open, (int) $answer_open );
            $item_close = min( (int) $question_close, (int) $answer_close );
        }

        return array(
            'pairs'            => $pairs,
            'wrapper_balanced' => 1 === $wrapper_open && 1 === $wrapper_close && $item_open === $item_close && $item_structure_balanced,
            'item_open_count'  => (int) $item_open,
            'item_close_count' => (int) $item_close,
        );
    }
}
