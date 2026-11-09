<?php

// Comprueba el diagnóstico con respuestas simuladas: no accede a ningún CDN real.
require_once dirname(__DIR__) . '/checks.php';
$check = DCTWPSollers_Checks::that(...);
$settings = DCTWPSollers_CDN_Module::defaults();
$check(!DCTWPSollers_CDN_Admin::probe($settings)['success'], 'Sin dominio no hay comprobación remota');
$settings['hostname'] = 'cdn.example.test';
$calls = 0;
$status = 200;
$failure = false;
$intercept = static function (mixed $preempt, array $arguments, string $url) use (&$calls, &$status, &$failure, $check): mixed
{
    $calls++;
    $check(parse_url($url, PHP_URL_HOST) === 'cdn.example.test', 'Comprueba únicamente el dominio configurado');
    $check(str_ends_with($url, '/wp-includes/images/blank.gif'), 'Recurso de prueba conocido');
    $check($arguments['method'] === 'HEAD' && $arguments['redirection'] === 0 && $arguments['reject_unsafe_urls'], 'HEAD seguro y sin redirecciones');

    if ($failure)
    {
        return new WP_Error('fixture', 'Error de red simulado');
    }

    $response = array();
    $response['response'] = array();
    $response['response']['code'] = $status;
    $response['headers'] = array();
    $response['body'] = '';

    return $response;
};
add_filter('pre_http_request', $intercept, 10, 3);

try
{
    $check(DCTWPSollers_CDN_Admin::probe($settings)['success'], 'HTTP 200 informa de éxito limitado');

    foreach (array(301, 403, 404, 405, 500) as $status)
    {
        $result = DCTWPSollers_CDN_Admin::probe($settings);
        $check(!$result['success'] && str_contains($result['message'], (string) $status), 'Respuesta no satisfactoria no se anuncia como éxito');
    }

    $failure = true;
    $check(!DCTWPSollers_CDN_Admin::probe($settings)['success'], 'Error de red controlado');
    $check($calls === 7, 'Número de llamadas explícitas esperado');
    DCTWPSollers_Checks::report();
}
finally
{
    remove_filter('pre_http_request', $intercept, 10);
}
