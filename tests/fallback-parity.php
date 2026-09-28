<?php
// Emits the PHP fallback for every fixture, one JSON line each.
// Test harness, run from the command line only. It ships inside the plugin
// folder, so it must do nothing when requested over the web.
if ( 'cli' !== PHP_SAPI ) {
    exit;
}
require __DIR__ . '/php-stubs.php';
require dirname(__DIR__) . '/includes/class-brand-kit.php';
require dirname(__DIR__) . '/includes/class-visual-icons.php';
require dirname(__DIR__) . '/includes/class-visual-templates.php';
require dirname(__DIR__) . '/includes/class-cta-renderer.php';

$fixtures = json_decode( file_get_contents( __DIR__ . '/fixtures.json' ), true );
$out = array();
foreach ( $fixtures as $f ) {
    $out[] = 'cta' === $f['kind']
        ? AISEOGodMode\CTARenderer::fallback( $f['attrs'] )
        : AISEOGodMode\VisualTemplates::fallback( $f['attrs'] );
}
echo json_encode( $out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
