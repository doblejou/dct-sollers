<?php

// Ejecutar solo en WordPress de pruebas: restaura la opción y los avisos.
require_once dirname(__DIR__) . '/checks.php';
$check = DCTWPSollers_Checks::that(...);
$saved = get_option('dctwpsollers_cdn_settings', null);
$errors = $GLOBALS['wp_settings_errors'] ?? array();

try
{
    delete_option('dctwpsollers_cdn_settings');
    $defaults = DCTWPSollers_CDN_Module::defaults();
    $check(DCTWPSollers_CDN_Module::settings() === $defaults, 'Valores iniciales');
    $check(str_contains($defaults['extensions'], ".woff\n.woff2"), 'Fuentes incluidas por defecto');
    $previous = $defaults;
    $previous['hostname'] = 'cdn.example.test';
    update_option('dctwpsollers_cdn_settings', $previous);

    foreach (array('', 'cdn.example.test', ' CDN.EXAMPLE.TEST ') as $hostname)
    {
        $input = $defaults;
        $input['hostname'] = $hostname;
        $check(DCTWPSollers_CDN_Module::validate_settings($input)['hostname'] === strtolower(trim($hostname)), 'Dominio normalizado');
    }

    foreach (array('https://cdn.example.test', '//cdn.example.test', 'cdn.example.test/path', 'cdn.example.test:8080', 'user@cdn.example.test', '127.0.0.1', '[::1]', 'localhost', '-cdn.example.test', "cdn.example.test\nother", array(), true) as $hostname)
    {
        $input = $defaults;
        $input['hostname'] = $hostname;
        $check(DCTWPSollers_CDN_Admin::sanitize($input) === $previous, 'Dominio inválido conserva ajustes');
    }

    foreach (array(null, false, array(), 'invalid', new stdClass()) as $input)
    {
        $check(DCTWPSollers_CDN_Admin::sanitize($input) === $previous, 'Rechaza documento inválido');
    }

    foreach (array_keys($defaults) as $key)
    {
        $input = $defaults;
        unset($input[$key]);
        $check(DCTWPSollers_CDN_Admin::sanitize($input) === $previous, 'Rechaza campo ausente');
    }

    foreach (array(true, false, 1.0, 2, null, array()) as $value)
    {
        $input = $defaults;
        $input['relative'] = $value;
        $check(DCTWPSollers_CDN_Admin::sanitize($input) === $previous, 'Rechaza booleano no normalizado');
    }

    foreach (array('jpg', '.jpg|.png', ".jpg\n<script>", str_repeat('x', 16385), array()) as $value)
    {
        $input = $defaults;
        $input['extensions'] = $value;
        $check(DCTWPSollers_CDN_Admin::sanitize($input) === $previous, 'Rechaza extensiones inválidas');
    }

    $input = $defaults;
    $input['extensions'] = " .WOFF2\r\n.woff\r.woff2\n\n";
    $input['exclusions'] = " /private/\r\nSkip\rSkip\n0";
    $input['relative'] = '0';
    $result = DCTWPSollers_CDN_Admin::sanitize($input);
    $check($result['extensions'] === ".woff2\n.woff" && $result['exclusions'] === "/private/\nSkip\n0" && $result['relative'] === 0, 'Normaliza listas y elimina duplicados');
    $check(DCTWPSollers_CDN_Admin::sanitize($result) === $result, 'Validación idempotente');
    $partial = array();
    $partial['hostname'] = 'partial.example.test';
    update_option('dctwpsollers_cdn_settings', $partial);
    $check(DCTWPSollers_CDN_Module::settings()['hostname'] === 'partial.example.test', 'Completa opción parcial');
    update_option('dctwpsollers_cdn_settings', 'invalid');
    $check(DCTWPSollers_CDN_Module::settings() === $defaults, 'Lectura inválida usa valores seguros');
    DCTWPSollers_Checks::report();
}
finally
{
    if ($saved === null)
    {
        delete_option('dctwpsollers_cdn_settings');
    }
    else
    {
        update_option('dctwpsollers_cdn_settings', $saved);
    }

    $GLOBALS['wp_settings_errors'] = $errors;
}
