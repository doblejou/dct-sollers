<?php
// Ejecutar con wp eval-file tests/admin-ui.php en la instalación desechable.

require_once __DIR__ . '/checks.php';
require_once ABSPATH . 'wp-admin/includes/template.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
$check = DCTWPSollers_Checks::that(...);
$saved_user = get_current_user_id();
$saved_get = $_GET;
$query = array();
$query['role'] = 'administrator';
$query['number'] = 1;
$query['fields'] = 'ID';
$admins = get_users($query);
$check(count($admins) === 1, 'La instalación de pruebas dispone de administrador');
$tabs = array
(
    'modules',
    'login',
    'cdn',
    'frontend',
    'migration',

);
$buffer_level = ob_get_level();

try
{
    wp_set_current_user((int) $admins[0]);

    foreach ($tabs as $tab)
    {
        $_GET['tab'] = $tab;
        ob_start();
        DCTWPSollers_Admin::render();
        $html = ob_get_clean();
        $check(str_contains($html, 'wrap dctwpsollers'), 'La presentación está limitada al contenedor del plugin');
        $check(substr_count($html, 'aria-current="page"') === 1, 'Una única sección se identifica como actual');
        $check(!str_contains($html, '<script'), 'La interfaz no requiere JavaScript propio');
        preg_match_all('/\bid="([^"]+)"/', $html, $ids);
        $check(count($ids[1]) === count(array_unique($ids[1])), 'Los identificadores son únicos en ' . $tab);

        if ($tab === 'modules')
        {
            $check(substr_count($html, 'class="dctwpsollers-module-card"') === count(DCTWPSollers::modules()), 'Cada módulo dispone de una tarjeta');
            $check(str_contains($html, 'Los indicadores muestran el estado guardado'), 'Se distingue el estado guardado de los cambios pendientes');
        }
        else
        {
            $check(str_contains($html, 'Gestionar módulos'), 'Los ajustes permiten volver a la activación de módulos');
        }

        if ($tab === 'frontend')
        {
            $check(substr_count($html, 'class="dctwpsollers-cleanup-card"') === 9, 'Las nueve limpiezas conservan su ayuda visible');
        }

        if ($tab === 'migration')
        {
            $check(!str_contains($html, '<form'), 'Migración sigue siendo exclusivamente informativa');
        }

    }

    DCTWPSollers_Checks::report();
}
catch (Throwable $error)
{
    while (ob_get_level() > $buffer_level)
    {
        ob_end_clean();
    }

    fwrite(STDERR, $error->getMessage() . "\n");

    throw $error;
}
finally
{
    $_GET = $saved_get;
    wp_set_current_user($saved_user);
}
