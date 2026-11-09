<?php

// Ejecutar con wp eval-file solo en una instalación desechable sin tráfico.
require_once __DIR__ . '/checks.php';
$check = DCTWPSollers_Checks::that(...);
$saved_modules = get_option('dctwpsollers_modules', null);
$saved_cron = get_option('cron', array());
$saved_settings = get_option('dctwpsollers_settings', null);

try
{
    $disabled = array();

    foreach (DCTWPSollers::modules() as $id => $module)
    {
        $disabled[$id] = 0;

        foreach (array('register', 'initialize', 'activate', 'deactivate', 'admin_register', 'render') as $callback)
        {
            $check(!isset($module[$callback]) || is_callable($module[$callback]), 'Callback de módulo válido: ' . $id . '/' . $callback);
        }
    }

    update_option('dctwpsollers_modules', $disabled);
    remove_all_actions('login_init');
    remove_all_actions('wp_login_failed');
    remove_all_actions('dctwpsollers_cleanup');
    DCTWPSollers::boot();
    $check(!has_action('login_init'), 'Inicializar login desactivado no registra protección');
    $check(!has_action('wp_login_failed'), 'Inicializar login desactivado no registra fallos');
    $check(has_action('dctwpsollers_cleanup', 'DCTWPSollers_Store::cleanup') !== false, 'Login desactivado conserva limpieza');
    $check(has_action('admin_post_dctwpsollers_unlock'), 'Login desactivado conserva administración');
    $check((int) get_option('dctwpsollers_db_version') === DCTWPSollers_Store::DB_VERSION, 'Inicialización conserva el esquema vigente');
    DCTWPSollers::deactivate();
    $check(wp_next_scheduled('dctwpsollers_cleanup') === false, 'Desactivar plugin retira cron del módulo');
    DCTWPSollers::activate();
    $scheduled = wp_next_scheduled('dctwpsollers_cleanup');
    $check($scheduled !== false, 'Activar plugin programa limpieza incluso con login desactivado');
    DCTWPSollers::activate();
    $check(wp_next_scheduled('dctwpsollers_cleanup') === $scheduled, 'Activación reejecutable sin duplicar cron');
    $check(get_option('dctwpsollers_modules') === $disabled, 'Ciclo de vida no activa módulos desactivados');
    $check(get_option('dctwpsollers_settings', null) === $saved_settings, 'Ciclo de vida conserva ajustes');
    $check(!method_exists(DCTWPSollers::class, 'settings') && !method_exists(DCTWPSollers::class, 'client_ip'), 'Coordinador general sin API específica de login');
    DCTWPSollers_Checks::report();
}
finally
{
    update_option('cron', $saved_cron);

    if ($saved_modules === null)
    {
        delete_option('dctwpsollers_modules');
    }
    else
    {
        update_option('dctwpsollers_modules', $saved_modules);
    }
}
