<?php

// Ejecutar únicamente en una instalación desechable.
require_once dirname(__DIR__) . '/checks.php';
$check = DCTWPHero_Checks::that(...);
$saved = get_option('dctwphero_frontend_settings', null);
$errors = $GLOBALS['wp_settings_errors'] ?? array();

try
{
    delete_option('dctwphero_frontend_settings');
    $defaults = DCTWPHero_Frontend_Settings::defaults();
    $check(DCTWPHero_Frontend_Settings::get() === $defaults, 'Inicialmente no hay optimizaciones');
    $valid = $defaults;
    $valid['emojis'] = '1';
    $valid['preconnect'] = " https://CDN.example.test/\r\nhttps://cdn.example.test\n";
    $valid['dns_prefetch'] = "DNS.example.test\r\ndns.example.test";
    $valid['fonts'] = "https://cdn.example.test/font.woff2?v=1&x=2\nhttps://cdn.example.test/font.woff2?v=1&x=2";
    $valid['image'] = 'https://cdn.example.test/hero.webp?v=2';
    $normalized = DCTWPHero_Frontend_Settings::validate($valid);
    $check($normalized !== null && $normalized['preconnect'] === 'https://cdn.example.test', 'Normaliza y deduplica orígenes');
    $check($normalized['dns_prefetch'] === 'dns.example.test' && $normalized['emojis'] === 1, 'Normaliza DNS y estados');
    $check(DCTWPHero_Frontend_Settings::validate($normalized) === $normalized, 'Validación idempotente');
    update_option('dctwphero_frontend_settings', $normalized);
    $hints = array('//s.w.org', '//s.w.org.evil.test');
    $entry = array();
    $entry['href'] = 'https://s.w.org';
    $hints[] = $entry;
    $hints = DCTWPHero_Frontend_Module::resource_hints($hints, 'dns-prefetch');
    $check(!in_array('//s.w.org', $hints, true) && !in_array($entry, $hints, true), 'Retira DNS emojis en formatos string y array');
    $check(in_array('//s.w.org.evil.test', $hints, true), 'Compara host exacto, no prefijos');
    $check(in_array('//dns.example.test', $hints, true), 'Añade solo DNS configurado');
    $check(DCTWPHero_Frontend_Module::resource_hints(array('existing'), 'prefetch') === array('existing'), 'Conserva otras relaciones');
    $preloads = DCTWPHero_Frontend_Module::preload_resources(array());
    $check(count($preloads) === 1 && $preloads[0]['href'] === $normalized['fonts'], 'Fuentes conservan URL y parámetros');
    $check($preloads[0]['crossorigin'] === 'anonymous' && $preloads[0]['type'] === 'font/woff2', 'Fuentes declaran tipo y CORS');

    foreach ($defaults as $key => $value)
    {
        $input = $defaults;
        unset($input[$key]);
        $check(DCTWPHero_Frontend_Admin::sanitize($input) === $normalized, 'Campo ausente conserva ajustes');
        $input = $defaults;
        $input[$key] = array();
        $check(DCTWPHero_Frontend_Admin::sanitize($input) === $normalized, 'Tipos inválidos conservan ajustes');
    }

    foreach (array(true, false, 1.0, 2, null, new stdClass()) as $value)
    {
        $input = $defaults;
        $input['emojis'] = $value;
        $check(DCTWPHero_Frontend_Settings::validate($input) === null, 'Estados estrictos');
    }

    foreach (array('javascript:alert(1)', '//cdn.example.test/x.woff2', 'https://user:pass@cdn.example.test/x.woff2', 'https://cdn.example.test/x.woff2#fragment', 'https://cdn.example.test/x.woff2"', "https://cdn.example.test/x\0.woff2", 'https://cdn.example.test/x.woff', 'https://cdn.example.test/x.woff2\\evil') as $value)
    {
        $input = $defaults;
        $input['fonts'] = $value;
        $check(DCTWPHero_Frontend_Settings::validate($input) === null, 'Rechaza recursos ambiguos o inseguros');
    }

    foreach (array('https://cdn.example.test/path', 'https://cdn.example.test/?x=1', 'ftp://cdn.example.test') as $value)
    {
        $input = $defaults;
        $input['preconnect'] = $value;
        $check(DCTWPHero_Frontend_Settings::validate($input) === null, 'Preconnect exige origen');
    }

    foreach (array('https://dns.example.test', '//dns.example.test', 'dns.example.test:443', '127.0.0.1', '-dns.example.test') as $value)
    {
        $input = $defaults;
        $input['dns_prefetch'] = $value;
        $check(DCTWPHero_Frontend_Settings::validate($input) === null, 'DNS exige dominio');
    }

    foreach (array('fonts', 'preconnect', 'dns_prefetch', 'image') as $key)
    {
        $input = $defaults;
        $input[$key] = str_repeat('x', 16385);
        $check(DCTWPHero_Frontend_Settings::validate($input) === null, 'Limita tamaño del texto');
        $input[$key] = '';

        for ($i = 0; $i < 11; $i++)
        {
            $input[$key] .= ($key === 'dns_prefetch' ? "d$i.example.test" : "https://d$i.example.test" . ($key === 'fonts' ? '/x.woff2' : ($key === 'image' ? '/x.webp' : ''))) . "\n";
        }

        $check(DCTWPHero_Frontend_Settings::validate($input) === null, 'Limita cantidad de recursos');
    }

    $input = $defaults;
    $input['unknown'] = '';
    $check(DCTWPHero_Frontend_Settings::validate($input) === null, 'Rechaza claves desconocidas');
    update_option('dctwphero_frontend_settings', array('rsd' => 1));
    $check(DCTWPHero_Frontend_Settings::get()['rsd'] === 1, 'Completa ajustes parciales');
    update_option('dctwphero_frontend_settings', 'invalid');
    $check(DCTWPHero_Frontend_Settings::get() === $defaults, 'Corrupción recupera valores seguros');
    DCTWPHero_Checks::report();
}
finally
{
    if ($saved === null)
    {
        delete_option('dctwphero_frontend_settings');
    }
    else
    {
        update_option('dctwphero_frontend_settings', $saved);
    }

    $GLOBALS['wp_settings_errors'] = $errors;
}
