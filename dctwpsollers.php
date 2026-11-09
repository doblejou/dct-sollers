<?php
/**
 * Plugin Name: DCTWP Sollers
 * Description: Herramientas modulares para WordPress con protección de login configurable.
 * Author: Jose Gonzalez - Daycom Technologies
 * Version: 0.2.16
 * Requires at least: 7.0
 * Requires PHP: 8.4
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: dctwpsollers
 */

defined('ABSPATH') || exit;

require_once __DIR__ . '/includes/class-admin.php';
require_once __DIR__ . '/modules/login/class-store.php';
require_once __DIR__ . '/modules/login/class-admin.php';
require_once __DIR__ . '/modules/login/class-module.php';
require_once __DIR__ . '/modules/migration/class-module.php';
require_once __DIR__ . '/modules/migration/class-admin.php';
require_once __DIR__ . '/modules/cdn/class-rewriter.php';
require_once __DIR__ . '/modules/cdn/class-module.php';
require_once __DIR__ . '/modules/cdn/class-admin.php';

final class DCTWPSollers
{
    ################################################
    #
    #  modules
    #
    #  Declara los módulos disponibles y sus valores iniciales.
    #
    ################################################
    public static function modules(): array
    {
        $login = array();
        $login['label'] = __('Protección de login', 'dctwpsollers');
        $login['description'] = __('Limita los fallos por IP y bloquea temporalmente el acceso a wp-login.php.', 'dctwpsollers');
        $login['default'] = true;
        $login['register'] = DCTWPSollers_Login_Module::register(...);
        $login['admin_register'] = DCTWPSollers_Login_Admin::register(...);
        $login['render'] = DCTWPSollers_Login_Admin::render(...);
        $modules = array();
        $modules['login'] = $login;
        $migration = array();
        $migration['label'] = __('Migración CLI', 'dctwpsollers');
        $migration['description'] = __('Exporta e importa el sitio exclusivamente mediante WP-CLI.', 'dctwpsollers');
        $migration['default'] = true;
        $migration['register'] = DCTWPSollers_Migration_Module::register(...);
        $migration['render'] = DCTWPSollers_Migration_Admin::render(...);
        $modules['migration'] = $migration;
        $cdn = array();
        $cdn['label'] = __('CDN', 'dctwpsollers');
        $cdn['description'] = __('Sirve recursos estáticos desde un dominio CDN mediante la reescritura de URL.', 'dctwpsollers');
        $cdn['default'] = false;
        $cdn['register'] = DCTWPSollers_CDN_Module::register(...);
        $cdn['admin_register'] = DCTWPSollers_CDN_Admin::register(...);
        $cdn['render'] = DCTWPSollers_CDN_Admin::render(...);
        $modules['cdn'] = $cdn;

        return $modules;
    }

    ################################################
    #
    #  module_enabled
    #
    #  Consulta el estado de un módulo conocido, conservando sus valores iniciales.
    #
    ################################################
    public static function module_enabled(string $id): bool
    {
        return (bool) (self::module_states()[$id] ?? false);
    }

    ################################################
    #
    #  module_states
    #
    #  Normaliza estados persistidos una sola vez y recupera valores iniciales seguros.
    #
    ################################################
    public static function module_states(): array
    {
        $saved = get_option('dctwpsollers_modules', array());
        $states = array();

        foreach (self::modules() as $id => $module)
        {
            $value = is_array($saved) ? ($saved[$id] ?? null) : null;
            $states[$id] = self::valid_module_state($value) ? (int) $value : (int) $module['default'];
        }

        return $states;
    }

    ################################################
    #
    #  valid_module_state
    #
    #  Comparte el contrato estricto de estados entre lectura y formularios.
    #
    ################################################
    public static function valid_module_state(mixed $value): bool
    {
        return $value === 0 || $value === 1 || $value === '0' || $value === '1';
    }

    ################################################
    #
    #  boot
    #
    #  Prepara el almacenamiento y registra únicamente los módulos activos.
    #
    ################################################
    public static function boot(): void
    {
        self::maybe_upgrade();
        $states = self::module_states();

        foreach (self::modules() as $id => $module)
        {
            if (isset($module['admin_register']))
            {
                ($module['admin_register'])();
            }

            if ($states[$id])
            {
                ($module['register'])();
            }
        }
    }

    ################################################
    #
    #  maybe_upgrade
    #
    #  Actualiza el esquema antes de utilizar el almacenamiento del plugin.
    #
    ################################################
    public static function maybe_upgrade(): void
    {
        if (!DCTWPSollers_Store::upgrade())
        {
            wp_die(
                esc_html__('No se ha podido actualizar el almacenamiento de DCTWP Sollers. Revisa el registro del servidor antes de reintentar.', 'dctwpsollers'),
                esc_html__('Actualización pendiente', 'dctwpsollers'),
                503
            );
        }
    }

    ################################################
    #
    #  settings
    #
    #  Mantiene el acceso a los ajustes del módulo de login.
    #
    ################################################
    public static function settings(): array
    {
        return DCTWPSollers_Login_Module::settings();
    }

    ################################################
    #
    #  client_ip
    #
    #  Mantiene el acceso a la normalización de IP del módulo de login.
    #
    ################################################
    public static function client_ip(): string
    {
        return DCTWPSollers_Login_Module::client_ip();
    }

    ################################################
    #
    #  activate
    #
    #  Crea el almacenamiento y programa la limpieza de datos caducados.
    #
    ################################################
    public static function activate(): void
    {
        if (is_multisite())
        {
            wp_die(esc_html__('DCTWP Sollers requiere una instalación individual de WordPress.', 'dctwpsollers'));
        }

        self::maybe_upgrade();

        if (!wp_next_scheduled('dctwpsollers_cleanup'))
        {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'hourly', 'dctwpsollers_cleanup');
        }
    }

    ################################################
    #
    #  deactivate
    #
    #  Retira la tarea periódica conservando ajustes y datos.
    #
    ################################################
    public static function deactivate(): void
    {
        wp_clear_scheduled_hook('dctwpsollers_cleanup');
    }
}

register_activation_hook(__FILE__, DCTWPSollers::activate(...));
register_deactivation_hook(__FILE__, DCTWPSollers::deactivate(...));
add_action('plugins_loaded', DCTWPSollers::boot(...), 0);
add_action('dctwpsollers_cleanup', DCTWPSollers_Store::cleanup(...));
add_action('admin_menu', DCTWPSollers_Admin::menu(...));
add_action('admin_init', DCTWPSollers_Admin::register_settings(...));
