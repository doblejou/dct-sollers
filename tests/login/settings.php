<?php

// Ejecutar únicamente en un WordPress de pruebas; restaura siempre los ajustes.
require_once dirname(__DIR__) . '/checks.php';
$check = DCTWPHero_Checks::that(...);
$saved = get_option('dctwphero_settings', null);
$errors = $GLOBALS['wp_settings_errors'] ?? array();

try
{
    delete_option('dctwphero_settings');
    $defaults = DCTWPHero_Login_Settings::get();
    $check($defaults['threshold'] === 3 && $defaults['window'] === 1 && $defaults['duration'] === 15, 'Valores iniciales documentados');

    foreach (DCTWPHero_Login_Settings::limits() as $key => $maximum)
    {
        foreach (array(1, '1', $maximum, (string) $maximum, '0002') as $value)
        {
            $input = $defaults;
            $input[$key] = $value;
            $expected = $input;
            $expected[$key] = (int) $value;
            $check(DCTWPHero_Login_Admin::sanitize($input) === $expected, 'Acepta y normaliza límites: ' . $key);
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
            $check(DCTWPHero_Login_Admin::sanitize($input) === $defaults, 'Rechaza valor inválido: ' . $key);
        }

        $input = $defaults;
        unset($input[$key]);
        $check(DCTWPHero_Login_Admin::sanitize($input) === $defaults, 'Rechaza ajuste incompleto: ' . $key);
    }

    foreach (array(null, false, 1, 'threshold=1', new stdClass(), array()) as $input)
    {
        $check(DCTWPHero_Login_Admin::sanitize($input) === $defaults, 'Rechaza documento de ajustes inválido');
    }

    $previous = array();
    $previous['threshold'] = 5;
    $previous['window'] = 15;
    $previous['duration'] = 30;
    update_option('dctwphero_settings', $previous);
    $check(DCTWPHero_Login_Settings::get() === $previous, 'Conserva los ajustes guardados antes del cambio de valores iniciales');
    delete_option('dctwphero_settings');

    $partial = array();
    $partial['threshold'] = '7';
    update_option('dctwphero_settings', $partial);
    $expected = $defaults;
    $expected['threshold'] = 7;
    $check(DCTWPHero_Login_Settings::get() === $expected, 'Completa ajustes anteriores y normaliza enteros');

    foreach (array(false, 'threshold=0', new stdClass()) as $input)
    {
        update_option('dctwphero_settings', $input);
        $check(DCTWPHero_Login_Settings::get() === $defaults, 'Configuración persistida inválida usa valores seguros');
    }

    $invalid = $defaults;
    $invalid['window'] = 0;
    update_option('dctwphero_settings', $invalid);
    $check(DCTWPHero_Login_Settings::get() === $defaults, 'Rechaza rangos inválidos también al leer');
    DCTWPHero_Checks::report();
}
finally
{
    if ($saved === null)
    {
        delete_option('dctwphero_settings');
    }
    else
    {
        update_option('dctwphero_settings', $saved);
    }

    $GLOBALS['wp_settings_errors'] = $errors;
}
