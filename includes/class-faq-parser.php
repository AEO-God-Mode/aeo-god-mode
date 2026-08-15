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

        return array(
            'pairs'            => $pairs,
            'wrapper_balanced' => 1 === $wrapper_open && 1 === $wrapper_close && $item_open === $item_close,
            'item_open_count'  => (int) $item_open,
            'item_close_count' => (int) $item_close,
        );
    }
}

