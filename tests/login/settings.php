<?php

// Ejecutar únicamente en un WordPress de pruebas; restaura siempre los ajustes.
require_once dirname(__DIR__) . '/checks.php';
$check = DCTWPSollers_Checks::that(...);
$saved = get_option('dctwpsollers_settings', null);
$errors = $GLOBALS['wp_settings_errors'] ?? array();

try
{
    delete_option('dctwpsollers_settings');
    $defaults = DCTWPSollers_Login_Settings::get();
    $check($defaults['threshold'] === 3 && $defaults['window'] === 1 && $defaults['duration'] === 15, 'Valores iniciales documentados');

    foreach (DCTWPSollers_Login_Settings::limits() as $key => $maximum)
    {
        foreach (array(1, '1', $maximum, (string) $maximum, '0002') as $value)
        {
            $input = $defaults;
            $input[$key] = $value;
            $expected = $input;
            $expected[$key] = (int) $value;
            $check(DCTWPSollers_Login_Admin::sanitize($input) === $expected, 'Acepta y normaliza límites: ' . $key);
        }

        $invalids = array
        (
            null,
            true,
            false,
            1.0,
            1.5,
            0,
            '0',
            -1,
            $maximum + 1,
            (string) PHP_INT_MAX . '0',
            '1.0',
            '1e2',
            '+1',
            ' 1',
            '1 ',
            '',
            array(),
            new stdClass(),

        );

        foreach ($invalids as $value)
        {
            $input = $defaults;
            $input[$key] = $value;
            $check(DCTWPSollers_Login_Admin::sanitize($input) === $defaults, 'Rechaza valor inválido: ' . $key);
        }

        $input = $defaults;
        unset($input[$key]);
        $check(DCTWPSollers_Login_Admin::sanitize($input) === $defaults, 'Rechaza ajuste incompleto: ' . $key);
    }

    foreach (array(null, false, 1, 'threshold=1', new stdClass(), array()) as $input)
    {
        $check(DCTWPSollers_Login_Admin::sanitize($input) === $defaults, 'Rechaza documento de ajustes inválido');
    }

    $previous = array();
    $previous['threshold'] = 5;
    $previous['window'] = 15;
    $previous['duration'] = 30;
    update_option('dctwpsollers_settings', $previous);
    $check(DCTWPSollers_Login_Settings::get() === $previous, 'Conserva los ajustes guardados antes del cambio de valores iniciales');
    delete_option('dctwpsollers_settings');

    $partial = array();
    $partial['threshold'] = '7';
    update_option('dctwpsollers_settings', $partial);
    $expected = $defaults;
    $expected['threshold'] = 7;
    $check(DCTWPSollers_Login_Settings::get() === $expected, 'Completa ajustes anteriores y normaliza enteros');

    foreach (array(false, 'threshold=0', new stdClass()) as $input)
    {
        update_option('dctwpsollers_settings', $input);
        $check(DCTWPSollers_Login_Settings::get() === $defaults, 'Configuración persistida inválida usa valores seguros');
    }

    $invalid = $defaults;
    $invalid['window'] = 0;
    update_option('dctwpsollers_settings', $invalid);
    $check(DCTWPSollers_Login_Settings::get() === $defaults, 'Rechaza rangos inválidos también al leer');
    DCTWPSollers_Checks::report();
}
finally
{
    if ($saved === null)
    {
        delete_option('dctwpsollers_settings');
    }
    else
    {
        update_option('dctwpsollers_settings', $saved);
    }

    $GLOBALS['wp_settings_errors'] = $errors;
}
