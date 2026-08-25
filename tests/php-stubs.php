<?php
define('ABSPATH', '/tmp/');
$GLOBALS['__opts'] = array();
function get_option($k, $d = false) { return $GLOBALS['__opts'][$k] ?? $d; }
function update_option($k, $v, $a = null) { $GLOBALS['__opts'][$k] = $v; return true; }
function get_theme_mod($k, $d = false) { return $d; }
function esc_html($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function esc_attr($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function esc_url_raw($s, $protocols = null) { return _asgm_clean_url($s, $protocols); }
function wp_strip_all_tags($s, $break = false) {
    $s = preg_replace('@<(script|style)[^>]*?>.*?</\\1>@si', '', (string)$s);
    $s = strip_tags($s);
    return $break ? trim(preg_replace('/[\r\n\t ]+/', ' ', $s)) : trim($s);
}
function wp_get_attachment_image_url($id, $size = 'full') { return $id ? "https://example.com/logo-{$id}.png" : false; }
$GLOBALS['__posts'] = array();
function sanitize_key($k) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string)$k)); }
function get_post_status($id) { return $GLOBALS['__posts'][$id]['status'] ?? false; }
function get_permalink($id) { return $GLOBALS['__posts'][$id]['url'] ?? false; }
function wp_parse_url($u, $c = -1) { return $c === -1 ? parse_url($u) : parse_url($u, $c); }
function esc_url($u) {
    $u = _asgm_clean_url($u);
    return htmlspecialchars($u, ENT_QUOTES, 'UTF-8');
}
function _asgm_clean_url($u, $protocols = null) {
    $u = trim((string)$u);
    if ($u === '') return '';
    $protocols = $protocols ?: array('http','https','mailto','tel');
    if (strpos($u, '#') === 0 || strpos($u, '/') === 0) return $u;
    $scheme = strtolower((string)parse_url($u, PHP_URL_SCHEME));
    if ($scheme === '' ) return '';
    if (!in_array($scheme, $protocols, true)) return '';
    return $u;
}
