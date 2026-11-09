<?php

// Ejecutar con PHP directamente: simula filtros anteriores a las funciones de WordPress.
require_once dirname(__DIR__) . '/checks.php';
define('WP_CLI', true);

final class WP_CLI
{
    public static array $filters = array();
    public static array $hooks = array();

    ################################################
    #
    #  get_config
    #
    #  Simula los flags obligatorios del arranque aislado.
    #
    ################################################
    public static function get_config(string $name): bool
    {
        return $name === 'skip-plugins' || $name === 'skip-themes';
    }

    ################################################
    #
    #  add_wp_hook
    #
    #  Ejecuta cada filtro sin cargar las funciones auxiliares de WordPress.
    #
    ################################################
    public static function add_wp_hook(string $name, callable $callback): void
    {
        self::$filters[$name] = $callback();
    }

    ################################################
    #
    #  add_hook
    #
    #  Conserva el registro posterior sin arrancar WordPress ni tocar sus datos.
    #
    ################################################
    public static function add_hook(string $name, callable $callback): void
    {
        self::$hooks[$name] = $callback;
    }

}

DCTWPSollers_Checks::that(!function_exists('__return_false'), 'Prueba sin funciones auxiliares de WordPress');
require dirname(__DIR__, 2) . '/modules/migration/import-bootstrap.php';
DCTWPSollers_Checks::that(WP_CLI::$filters['enable_loading_object_cache_dropin'] === false, 'Desactiva object-cache antes de cargar WordPress');
DCTWPSollers_Checks::that(WP_CLI::$filters['enable_loading_advanced_cache_dropin'] === false, 'Desactiva advanced-cache antes de cargar WordPress');
DCTWPSollers_Checks::that(DCTWPSOLLERS_MIGRATION_ISOLATED === true, 'Identifica el proceso aislado');
DCTWPSollers_Checks::that(is_callable(WP_CLI::$hooks['after_wp_load'] ?? null), 'Aplaza el registro del migrador hasta cargar WordPress');
DCTWPSollers_Checks::report();
