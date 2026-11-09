<?php

// Ejecutar únicamente en una instalación desechable.
require_once dirname(__DIR__) . '/checks.php';
$check = DCTWPSollers_Checks::that(...);
$saved = get_option('dctwpsollers_frontend_settings', null);
$errors = $GLOBALS['wp_settings_errors'] ?? array();

try
{
    delete_option('dctwpsollers_frontend_settings');
    $defaults = DCTWPSollers_Frontend_Settings::defaults();
    $check(DCTWPSollers_Frontend_Settings::get() === $defaults, 'Inicialmente no hay optimizaciones');
    $new_flags = array
    (
        'rest_links',
        'oembed_links',
        'generator',
        'feed_links',
        'comments_feed_links',

    );

    foreach ($new_flags as $key)
    {
        $check($defaults[$key] === 0, 'La nueva limpieza no se activa automáticamente');

        foreach (array(0, 1, '0', '1') as $value)
        {
            $input = $defaults;
            $input[$key] = $value;
            $check(DCTWPSollers_Frontend_Settings::validate($input)[$key] === (int) $value, 'Acepta estados estrictos de la nueva limpieza');
        }

        foreach (array(true, false, 1.0, -1, 2, null, array()) as $value)
        {
            $input = $defaults;
            $input[$key] = $value;
            $check(DCTWPSollers_Frontend_Settings::validate($input) === null, 'Rechaza estados inválidos de la nueva limpieza');
        }
    }

    $legacy = $defaults;
    $legacy['rsd'] = 1;

    foreach ($new_flags as $key)
    {
        unset($legacy[$key]);
    }

    update_option('dctwpsollers_frontend_settings', $legacy);
    $upgraded = DCTWPSollers_Frontend_Settings::get();
    $check($upgraded['rsd'] === 1, 'Actualización conserva ajustes anteriores');

    foreach ($new_flags as $key)
    {
        $check($upgraded[$key] === 0, 'Actualización completa nuevas opciones desactivadas');
    }

    $valid = $defaults;
    $valid['emojis'] = '1';
    $valid['preconnect'] = " https://CDN.example.test/\r\nhttps://cdn.example.test\n";
    $valid['dns_prefetch'] = "DNS.example.test\r\ndns.example.test";
    $valid['fonts'] = "https://cdn.example.test/font.woff2?v=1&x=2\nhttps://cdn.example.test/font.woff2?v=1&x=2";
    $valid['image'] = 'https://cdn.example.test/hero.webp?v=2';
    $normalized = DCTWPSollers_Frontend_Settings::validate($valid);
    $check($normalized !== null && $normalized['preconnect'] === 'https://cdn.example.test', 'Normaliza y deduplica orígenes');
    $check($normalized['dns_prefetch'] === 'dns.example.test' && $normalized['emojis'] === 1, 'Normaliza DNS y estados');
    $check(DCTWPSollers_Frontend_Settings::validate($normalized) === $normalized, 'Validación idempotente');
    update_option('dctwpsollers_frontend_settings', $normalized);
    $hints = array('//s.w.org', '//s.w.org.evil.test');
    $entry = array();
    $entry['href'] = 'https://s.w.org';
    $hints[] = $entry;
    $hints = DCTWPSollers_Frontend_Module::resource_hints($hints, 'dns-prefetch');
    $check(!in_array('//s.w.org', $hints, true) && !in_array($entry, $hints, true), 'Retira DNS emojis en formatos string y array');
    $check(in_array('//s.w.org.evil.test', $hints, true), 'Compara host exacto, no prefijos');
    $check(in_array('//dns.example.test', $hints, true), 'Añade solo DNS configurado');
    $check(DCTWPSollers_Frontend_Module::resource_hints(array('existing'), 'prefetch') === array('existing'), 'Conserva otras relaciones');
    $preloads = DCTWPSollers_Frontend_Module::preload_resources(array());
    $check(count($preloads) === 1 && $preloads[0]['href'] === $normalized['fonts'], 'Fuentes conservan URL y parámetros');
    $check($preloads[0]['crossorigin'] === 'anonymous' && $preloads[0]['type'] === 'font/woff2', 'Fuentes declaran tipo y CORS');

    foreach ($defaults as $key => $value)
    {
        $input = $defaults;
        unset($input[$key]);
        $check(DCTWPSollers_Frontend_Admin::sanitize($input) === $normalized, 'Campo ausente conserva ajustes');
        $input = $defaults;
        $input[$key] = array();
        $check(DCTWPSollers_Frontend_Admin::sanitize($input) === $normalized, 'Tipos inválidos conservan ajustes');
    }

    foreach (array(true, false, 1.0, 2, null, new stdClass()) as $value)
    {
        $input = $defaults;
        $input['emojis'] = $value;
        $check(DCTWPSollers_Frontend_Settings::validate($input) === null, 'Estados estrictos');
    }

    foreach (array('javascript:alert(1)', '//cdn.example.test/x.woff2', 'https://user:pass@cdn.example.test/x.woff2', 'https://cdn.example.test/x.woff2#fragment', 'https://cdn.example.test/x.woff2"', "https://cdn.example.test/x\0.woff2", 'https://cdn.example.test/x.woff', 'https://cdn.example.test/x.woff2\\evil') as $value)
    {
        $input = $defaults;
        $input['fonts'] = $value;
        $check(DCTWPSollers_Frontend_Settings::validate($input) === null, 'Rechaza recursos ambiguos o inseguros');
    }

    foreach (array('https://cdn.example.test/path', 'https://cdn.example.test/?x=1', 'ftp://cdn.example.test') as $value)
    {
        $input = $defaults;
        $input['preconnect'] = $value;
        $check(DCTWPSollers_Frontend_Settings::validate($input) === null, 'Preconnect exige origen');
    }

    foreach (array('https://dns.example.test', '//dns.example.test', 'dns.example.test:443', '127.0.0.1', '-dns.example.test') as $value)
    {
        $input = $defaults;
        $input['dns_prefetch'] = $value;
        $check(DCTWPSollers_Frontend_Settings::validate($input) === null, 'DNS exige dominio');
    }

    foreach (array('fonts', 'preconnect', 'dns_prefetch', 'image') as $key)
    {
        $input = $defaults;
        $input[$key] = str_repeat('x', 16385);
        $check(DCTWPSollers_Frontend_Settings::validate($input) === null, 'Limita tamaño del texto');
        $input[$key] = '';

        for ($i = 0; $i < 11; $i++)
        {
            $input[$key] .= ($key === 'dns_prefetch' ? "d$i.example.test" : "https://d$i.example.test" . ($key === 'fonts' ? '/x.woff2' : ($key === 'image' ? '/x.webp' : ''))) . "\n";
        }

        $check(DCTWPSollers_Frontend_Settings::validate($input) === null, 'Limita cantidad de recursos');
    }

    $input = $defaults;
    $input['unknown'] = '';
    $check(DCTWPSollers_Frontend_Settings::validate($input) === null, 'Rechaza claves desconocidas');
    $partial = array();
    $partial['rsd'] = 1;
    update_option('dctwpsollers_frontend_settings', $partial);
    $check(DCTWPSollers_Frontend_Settings::get()['rsd'] === 1, 'Completa ajustes parciales');
    update_option('dctwpsollers_frontend_settings', 'invalid');
    $check(DCTWPSollers_Frontend_Settings::get() === $defaults, 'Corrupción recupera valores seguros');
    DCTWPSollers_Checks::report();
}
finally
{
    if ($saved === null)
    {
        delete_option('dctwpsollers_frontend_settings');
    }
    else
    {
        update_option('dctwpsollers_frontend_settings', $saved);
    }

    $GLOBALS['wp_settings_errors'] = $errors;
}
