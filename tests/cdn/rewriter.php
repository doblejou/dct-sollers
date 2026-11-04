<?php

// Ejecutar con wp eval-file para comprobar el parser de URL real de WordPress.
defined('ABSPATH') || exit;
require_once dirname(__DIR__) . '/checks.php';
require_once dirname(__DIR__, 2) . '/modules/cdn/class-module.php';
require_once dirname(__DIR__, 2) . '/modules/cdn/class-rewriter.php';
$check = DCTWPHero_Checks::that(...);
$settings = DCTWPHero_CDN_Module::defaults();
$settings['hostname'] = 'cdn.example.test';
$settings['exclusions'] = "/private/\nSkip\n0-private";
$engine = new DCTWPHero_CDN_Rewriter($settings, array('https://example.test/sub', 'http://static.example.test:8080/wp'));

foreach (array('https://example.test', 'http://EXAMPLE.TEST', '//example.test', 'http://static.example.test:8080', '') as $origin)
{
    foreach (array('.jpg', '.JPG', '.css', '.js', '.woff', '.woff2', '.json') as $extension)
    {
        $source = $origin . '/sub/file' . $extension . '?v=2&amp;x=3#icon';
        $expected = ($origin === '' || str_starts_with($origin, '//') ? '//' : parse_url($origin, PHP_URL_SCHEME) . '://') . 'cdn.example.test/sub/file' . $extension . '?v=2&amp;x=3#icon';

        foreach (array(false, true) as $escaped)
        {
            $input = '<img src="' . ($escaped ? str_replace('/', '\\/', $source) : $source) . '">';
            $output = '<img src="' . ($escaped ? str_replace('/', '\\/', $expected) : $expected) . '">';
            $check($engine->rewrite($input) === $output, 'Formato, extensión, parámetros y fragmentos: ' . $source);
            $check($engine->rewrite($output) === $output, 'Motor idempotente');
        }
    }
}

foreach (array(
    'https://external.test/a.jpg', 'https://example.test.evil.test/a.jpg',
    'https://evil-example.test/a.jpg', 'https://example.test:8080/a.jpg',
    'https://static.example.test/a.jpg', 'https://user@example.test/a.jpg',
    'https://cdn.example.test/a.jpg', '/a.php?image=.jpg', '/a.jpg/extra',
    'images/a.jpg', 'data:image/png;base64,abc', '/private/a.jpg', '/Skip.jpg', '/0-private.jpg',
    'ftp://example.test/a.jpg', 'mailto:person@example.test/a.jpg',
) as $url)
{
    $input = '<a href="' . $url . '">';
    $check($engine->rewrite($input) === $input, 'No reescribe: ' . $url);
}

$check($engine->rewrite('/skip.jpg') === '//cdn.example.test/skip.jpg', 'Exclusión sensible a mayúsculas');
$check($engine->rewrite('url(/a.woff2)') === 'url(//cdn.example.test/a.woff2)', 'CSS inline');
$check($engine->rewrite('<img srcset="/a.jpg 1x, /b.webp 2x">') === '<img srcset="//cdn.example.test/a.jpg 1x, //cdn.example.test/b.webp 2x">', 'Srcset con varios candidatos');
$check($engine->rewrite('{"image":"https:\\/\\/example.test\\/a.jpg"}') === '{"image":"https:\\/\\/cdn.example.test\\/a.jpg"}', 'Cadena JSON escapada');
foreach (array('&amp;', '&#38;', '&#x26;') as $separator)
{
    $input = '<img src="/a.jpg?v=1' . $separator . 'Skip=1">';
    $check($engine->rewrite($input) === $input, 'Exclusión después de entidad HTML en parámetros');
    $input = '<img src="https://example.test/a.jpg?v=1' . $separator . 'Skip=1">';
    $check($engine->rewrite($input) === $input, 'Exclusión de URL absoluta completa con entidad');
    $escaped = str_replace('/', '\\/', $input);
    $check($engine->rewrite($escaped) === $escaped, 'Exclusión con barras escapadas y entidad');
}

$check($engine->rewrite('url(/a.jpg); color:red') === 'url(//cdn.example.test/a.jpg); color:red', 'Conserva delimitadores CSS al admitir entidades HTML');
$settings['relative'] = 0;
$engine = new DCTWPHero_CDN_Rewriter($settings, array('https://example.test'));
$check($engine->rewrite('/a.jpg') === '/a.jpg', 'Se puede omitir la reescritura relativa');
$check($engine->rewrite('//example.test/a.jpg') === '//cdn.example.test/a.jpg', 'La opción relativa no excluye URL sin protocolo');
$settings['hostname'] = '';
$check((new DCTWPHero_CDN_Rewriter($settings, array('https://example.test')))->rewrite('/a.jpg') === '/a.jpg', 'Sin dominio no hay reescritura');
$settings['hostname'] = 'cdn.example.test';
$settings['extensions'] = '';
$check((new DCTWPHero_CDN_Rewriter($settings, array('https://example.test')))->rewrite('/a.jpg') === '/a.jpg', 'Sin extensiones no hay reescritura');
DCTWPHero_Checks::report();
