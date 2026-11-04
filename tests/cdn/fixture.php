<?php

// Tema mínimo utilizado exclusivamente en la instalación desechable de pruebas.
$mode = $_GET['cdn_mode'] ?? 'html';
$image = home_url('/wp-content/uploads/cdn-fixture.jpg');

if ($mode === 'json')
{
    header('Content-Type: application/json');
    echo wp_json_encode($image);
    return;
}

if ($mode === 'download')
{
    header('Content-Disposition: attachment; filename="fixture.html"');
}

if ($mode === 'fragment')
{
    echo '<img src="' . esc_url($image) . '">';
    return;
}

echo '<!doctype html><html><head><title>CDN fixture</title></head><body>';
echo '<img src="' . esc_url($image) . '">';
echo '<img srcset="/first.jpg 1x, /second.webp 2x">';
echo '<style>@font-face{src:url(/font.woff2)}</style>';
echo '<img src="https://external.example.test/external.jpg">';
echo '<img src="/private/excluded.jpg">';
echo '</body></html>';
