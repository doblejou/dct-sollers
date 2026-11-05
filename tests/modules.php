<?php
// Ejecutar con wp eval-file tests/modules.php solo en un WordPress de pruebas.

$saved = get_option('dctwphero_modules', null);
require_once __DIR__ . '/checks.php';
$check = DCTWPHero_Checks::that(...);

try
{
    delete_option('dctwphero_modules');
    $check(DCTWPHero::module_enabled('login'), 'Instalaciones sin opción mantienen la protección activa');
    $check(DCTWPHero::module_enabled('migration'), 'Migración activa por defecto');
    $check(!DCTWPHero::module_enabled('cdn'), 'CDN desactivado por defecto');
    $check(!DCTWPHero::module_enabled('frontend'), 'Frontend desactivado por defecto');
    $check(!DCTWPHero::module_enabled('unknown'), 'Módulos desconocidos no se activan');
    $legacy = array();
    $legacy['login'] = 0;
    update_option('dctwphero_modules', $legacy);
    $check(DCTWPHero::module_enabled('migration'), 'Actualización con módulos anteriores activa migración por defecto');
    $check(!DCTWPHero::module_enabled('login'), 'Actualización conserva el estado anterior del login');
    $check(!DCTWPHero::module_enabled('cdn'), 'Actualización no activa CDN automáticamente');
    $check(!DCTWPHero::module_enabled('frontend'), 'Actualización no activa Frontend automáticamente');
    $disabled = array();
    $disabled['login'] = '0';
    $disabled['migration'] = '1';
    $disabled['cdn'] = '0';
    $disabled['frontend'] = '0';
    $validated = DCTWPHero_Admin::sanitize_modules($disabled);
    $check($validated['login'] === 0, 'Valida el estado desactivado');
    update_option('dctwphero_modules', $validated);
    $check(!DCTWPHero::module_enabled('login'), 'Lee el estado desactivado');

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
        $check(DCTWPHero_Admin::sanitize_modules($input) === $validated, 'Entrada inválida conserva el estado anterior');
    }

    $enabled = array();
    $enabled['login'] = '1';
    $enabled['migration'] = '1';
    $enabled['cdn'] = '1';
    $enabled['frontend'] = '1';
    $result = DCTWPHero_Admin::sanitize_modules($enabled);
    $check($result['login'] === 1, 'Valida la reactivación');
    $check($result['cdn'] === 1, 'Valida activación explícita del CDN');
    $check($result['frontend'] === 1, 'Valida activación explícita de Frontend');
    $check(DCTWPHero_Admin::sanitize_modules($result) === $result, 'Validación reejecutable');

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
                $input['frontend'] = '0';
                $expected = array();
                $expected['login'] = (int) $login;
                $expected['migration'] = (int) $migration;
                $expected['cdn'] = (int) $cdn;
                $expected['frontend'] = 0;
                $check(DCTWPHero_Admin::sanitize_modules($input) === $expected, 'Combinación válida de estados');
            }
        }
    }

    foreach (array(true, false, 1.0, 2, -1, null, array(), new stdClass()) as $value)
    {
        $input = $result;
        $input['migration'] = $value;
        $check(DCTWPHero_Admin::sanitize_modules($input) === $validated, 'Rechaza tipos de estado inválidos');
        $input = $result;
        $input['frontend'] = $value;
        $check(DCTWPHero_Admin::sanitize_modules($input) === $validated, 'Frontend rechaza tipos inválidos');
    }

    $input = $result;
    $input['unknown'] = '1';
    $check(DCTWPHero_Admin::sanitize_modules($input) === $validated, 'Rechaza módulos desconocidos con estados completos');

    foreach (array('invalid', true, false, 2, -1, 1.0, array(), new stdClass()) as $value)
    {
        $corrupt = array();
        $corrupt['login'] = $value;
        $corrupt['migration'] = '0';
        $corrupt['cdn'] = $value;
        update_option('dctwphero_modules', $corrupt);
        $states = DCTWPHero::module_states();
        $check($states['login'] === 1 && $states['cdn'] === 0, 'Estados persistidos inválidos usan el valor inicial de cada módulo');
        $check($states['migration'] === 0, 'Un estado corrupto no pierde otros estados válidos');
        $check(DCTWPHero_Admin::sanitize_modules(null) === $states, 'Formulario inválido reutiliza la lectura normalizada');
    }

    foreach (array('invalid', 1, new stdClass()) as $corrupt)
    {
        update_option('dctwphero_modules', $corrupt);
        $check(DCTWPHero::module_enabled('login') && !DCTWPHero::module_enabled('cdn'), 'Opción de módulos no matricial recupera valores seguros');
    }

    DCTWPHero_Checks::report();
}
finally
{
    if ($saved === null)
    {
        delete_option('dctwphero_modules');
    }
    else
    {
        update_option('dctwphero_modules', $saved);
    }
}
