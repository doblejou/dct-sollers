<?php
/**
 * Plugin Name: DCT WP Hero
 * Description: Herramientas modulares para WordPress con protección de login configurable.
 * Author: Jose Gonzalez - Daycom Technologies
 * Version: 0.2.16
 * Requires at least: 7.0
 * Requires PHP: 8.4
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: dctwphero
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

final class DCTWPHero
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
        $login['label'] = __('Protección de login', 'dctwphero');
        $login['description'] = __('Limita los fallos por IP y bloquea temporalmente el acceso a wp-login.php.', 'dctwphero');
        $login['default'] = true;
        $login['register'] = DCTWPHero_Login_Module::register(...);
        $login['admin_register'] = DCTWPHero_Login_Admin::register(...);
        $login['render'] = DCTWPHero_Login_Admin::render(...);
        $modules = array();
        $modules['login'] = $login;
        $migration = array();
        $migration['label'] = __('Migración CLI', 'dctwphero');
        $migration['description'] = __('Exporta e importa el sitio exclusivamente mediante WP-CLI.', 'dctwphero');
        $migration['default'] = true;
        $migration['register'] = DCTWPHero_Migration_Module::register(...);
        $migration['render'] = DCTWPHero_Migration_Admin::render(...);
        $modules['migration'] = $migration;
        $cdn = array();
        $cdn['label'] = __('CDN', 'dctwphero');
        $cdn['description'] = __('Sirve recursos estáticos desde un dominio CDN mediante la reescritura de URL.', 'dctwphero');
        $cdn['default'] = false;
        $cdn['register'] = DCTWPHero_CDN_Module::register(...);
        $cdn['admin_register'] = DCTWPHero_CDN_Admin::register(...);
        $cdn['render'] = DCTWPHero_CDN_Admin::render(...);
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
        $saved = get_option('dctwphero_modules', array());
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
        if (!DCTWPHero_Store::upgrade())
        {
            wp_die(
                esc_html__('No se ha podido actualizar el almacenamiento de DCT WP Hero. Revisa el registro del servidor antes de reintentar.', 'dctwphero'),
                esc_html__('Actualización pendiente', 'dctwphero'),
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
        return DCTWPHero_Login_Module::settings();
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
        return DCTWPHero_Login_Module::client_ip();
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
            wp_die(esc_html__('DCT WP Hero requiere una instalación individual de WordPress.', 'dctwphero'));
        }

        self::maybe_upgrade();

        if (!wp_next_scheduled('dctwphero_cleanup'))
        {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'hourly', 'dctwphero_cleanup');
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
        wp_clear_scheduled_hook('dctwphero_cleanup');
    }
}

register_activation_hook(__FILE__, DCTWPHero::activate(...));
register_deactivation_hook(__FILE__, DCTWPHero::deactivate(...));
add_action('plugins_loaded', DCTWPHero::boot(...), 0);
add_action('dctwphero_cleanup', DCTWPHero_Store::cleanup(...));
add_action('admin_menu', DCTWPHero_Admin::menu(...));
add_action('admin_init', DCTWPHero_Admin::register_settings(...));
