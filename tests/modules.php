<?php
// Ejecutar con wp eval-file tests/modules.php solo en un WordPress de pruebas.

$saved = get_option('dctwpsollers_modules', null);
require_once __DIR__ . '/checks.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
$check = DCTWPSollers_Checks::that(...);

try
{
    $identity = get_plugin_data(dirname(__DIR__) . '/dctwpsollers.php', false, false);
    $check($identity['Name'] === 'DCTWP Sollers', 'La cabecera declara la nueva identidad pública');
    $check($identity['TextDomain'] === 'dctwpsollers', 'El dominio de traducción coincide con el slug');
    $check(DCTWPSollers_Store::table() === $GLOBALS['wpdb']->prefix . 'dctwpsollers_ips', 'El almacén utiliza la tabla de la nueva identidad');

    $registrations = array();
    $registrations['dctwpsollers_modules'] = 'DCTWPSollers_Admin';
    $registrations['dctwpsollers_settings'] = 'DCTWPSollers_Login_Admin';
    $registrations['dctwpsollers_cdn_settings'] = 'DCTWPSollers_CDN_Admin';

    if (class_exists('DCTWPSollers_Frontend_Admin'))
    {
        $registrations['dctwpsollers_frontend_settings'] = 'DCTWPSollers_Frontend_Admin';
    }

    foreach ($registrations as $option => $class)
    {
        $class::register_settings();
        $registered = get_registered_settings()[$option];
        $callback = $registered['sanitize_callback'];
        $check($registered['type'] === 'array' && is_callable($callback), 'Settings API registra un validador invocable: ' . $option);
        $check(has_filter('sanitize_option_' . $option, $callback) !== false, 'Settings API conecta el saneamiento: ' . $option);
        $check(sanitize_option($option, null) === $callback(null), 'Settings API rechaza entradas inválidas conservando ajustes: ' . $option);
        $current = get_option($option, array());
        $check(sanitize_option($option, $current) === $callback($current), 'Settings API aplica el validador a los ajustes: ' . $option);
        unregister_setting($registered['group'], $option);
    }

    delete_option('dctwpsollers_modules');
    $check(DCTWPSollers::module_enabled('login'), 'Instalaciones sin opción mantienen la protección activa');
    $check(DCTWPSollers::module_enabled('migration'), 'Migración activa por defecto');
    $check(!DCTWPSollers::module_enabled('cdn'), 'CDN desactivado por defecto');
    $check(!DCTWPSollers::module_enabled('unknown'), 'Módulos desconocidos no se activan');
    $legacy = array();
    $legacy['login'] = 0;
    update_option('dctwpsollers_modules', $legacy);
    $check(DCTWPSollers::module_enabled('migration'), 'Actualización con módulos anteriores activa migración por defecto');
    $check(!DCTWPSollers::module_enabled('login'), 'Actualización conserva el estado anterior del login');
    $check(!DCTWPSollers::module_enabled('cdn'), 'Actualización no activa CDN automáticamente');
    $disabled = array();
    $disabled['login'] = '0';
    $disabled['migration'] = '1';
    $disabled['cdn'] = '0';
    $validated = DCTWPSollers_Admin::sanitize_modules($disabled);
    $check($validated['login'] === 0, 'Valida el estado desactivado');
    update_option('dctwpsollers_modules', $validated);
    $check(!DCTWPSollers::module_enabled('login'), 'Lee el estado desactivado');

    $invalids = array
    (
        null,
        'invalid',
        array(),

    );
    $invalid = array();
    $invalid['login'] = 'invalid';
    $invalids[] = $invalid;
    $invalid['login'] = array();
    $invalids[] = $invalid;
    $invalid['login'] = '1';
    $invalid['unknown'] = '1';
    $invalids[] = $invalid;

    foreach ($invalids as $input)
    {
        $check(DCTWPSollers_Admin::sanitize_modules($input) === $validated, 'Entrada inválida conserva el estado anterior');
    }

    $enabled = array();
    $enabled['login'] = '1';
    $enabled['migration'] = '1';
    $enabled['cdn'] = '1';
    $result = DCTWPSollers_Admin::sanitize_modules($enabled);
    $check($result['login'] === 1, 'Valida la reactivación');
    $check($result['cdn'] === 1, 'Valida activación explícita del CDN');
    $check(DCTWPSollers_Admin::sanitize_modules($result) === $result, 'Validación reejecutable');

    foreach (array(0, 1, '0', '1') as $login)
    {
        foreach (array(0, 1, '0', '1') as $migration)
        {
            foreach (array(0, 1, '0', '1') as $cdn)
            {
                $input = array();
                $input['login'] = $login;
                $input['migration'] = $migration;
                $input['cdn'] = $cdn;
                $expected = array();
                $expected['login'] = (int) $login;
                $expected['migration'] = (int) $migration;
                $expected['cdn'] = (int) $cdn;
                $check(DCTWPSollers_Admin::sanitize_modules($input) === $expected, 'Combinación válida de estados');
            }
        }
    }

    foreach (array(true, false, 1.0, 2, -1, null, array(), new stdClass()) as $value)
    {
        $input = $result;
        $input['migration'] = $value;
        $check(DCTWPSollers_Admin::sanitize_modules($input) === $validated, 'Rechaza tipos de estado inválidos');
    }

    $input = $result;
    $input['unknown'] = '1';
    $check(DCTWPSollers_Admin::sanitize_modules($input) === $validated, 'Rechaza módulos desconocidos con estados completos');

    foreach (array('invalid', true, false, 2, -1, 1.0, array(), new stdClass()) as $value)
    {
        $corrupt = array();
        $corrupt['login'] = $value;
        $corrupt['migration'] = '0';
        $corrupt['cdn'] = $value;
        update_option('dctwpsollers_modules', $corrupt);
        $states = DCTWPSollers::module_states();
        $check($states['login'] === 1 && $states['cdn'] === 0, 'Estados persistidos inválidos usan el valor inicial de cada módulo');
        $check($states['migration'] === 0, 'Un estado corrupto no pierde otros estados válidos');
        $check(DCTWPSollers_Admin::sanitize_modules(null) === $states, 'Formulario inválido reutiliza la lectura normalizada');
    }

    foreach (array('invalid', 1, new stdClass()) as $corrupt)
    {
        update_option('dctwpsollers_modules', $corrupt);
        $check(DCTWPSollers::module_enabled('login') && !DCTWPSollers::module_enabled('cdn'), 'Opción de módulos no matricial recupera valores seguros');
    }

    DCTWPSollers_Checks::report();
}
finally
{
    if ($saved === null)
    {
        delete_option('dctwpsollers_modules');
    }
    else
    {
        update_option('dctwpsollers_modules', $saved);
    }
}
