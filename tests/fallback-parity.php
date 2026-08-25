<?php
// Emits the PHP fallback for every fixture, one JSON line each.
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
