<?php
/**
 * Plugin Name: DCT Sollers
 * Description: Herramientas modulares para WordPress con protección de login configurable.
 * Author: Jose Gonzalez - Daycom Technologies
 * Version: 0.2.23
 * Requires at least: 7.0
 * Requires PHP: 8.4
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: dct-sollers
 */

defined('ABSPATH') || exit;

require_once __DIR__ . '/includes/class-admin.php';
require_once __DIR__ . '/modules/login/class-settings.php';
require_once __DIR__ . '/modules/login/class-store.php';
require_once __DIR__ . '/modules/login/class-admin.php';
require_once __DIR__ . '/modules/login/class-module.php';
require_once __DIR__ . '/modules/migration/class-module.php';
require_once __DIR__ . '/modules/migration/class-admin.php';
require_once __DIR__ . '/modules/cdn/class-rewriter.php';
require_once __DIR__ . '/modules/cdn/class-settings.php';
require_once __DIR__ . '/modules/cdn/class-module.php';
require_once __DIR__ . '/modules/cdn/class-admin.php';
require_once __DIR__ . '/modules/frontend/class-settings.php';
require_once __DIR__ . '/modules/frontend/class-module.php';
require_once __DIR__ . '/modules/frontend/class-admin.php';

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
        $login['label'] = __('Protección de login', 'dct-sollers');
        $login['description'] = __('Limita los fallos por IP y bloquea temporalmente el acceso a wp-login.php.', 'dct-sollers');
        $login['default'] = true;
        $login['register'] = DCTWPSollers_Login_Module::register(...);
        $login['initialize'] = DCTWPSollers_Login_Module::initialize(...);
        $login['activate'] = DCTWPSollers_Login_Module::activate(...);
        $login['deactivate'] = DCTWPSollers_Login_Module::deactivate(...);
        $login['admin_register'] = DCTWPSollers_Login_Admin::register(...);
        $login['render'] = DCTWPSollers_Login_Admin::render(...);
        $modules = array();
        $modules['login'] = $login;
        $migration = array();
        $migration['label'] = __('Migración CLI', 'dct-sollers');
        $migration['description'] = __('Exporta e importa el sitio exclusivamente mediante WP-CLI.', 'dct-sollers');
        $migration['default'] = true;
        $migration['register'] = DCTWPSollers_Migration_Module::register(...);
        $migration['render'] = DCTWPSollers_Migration_Admin::render(...);
        $modules['migration'] = $migration;
        $cdn = array();
        $cdn['label'] = __('CDN', 'dct-sollers');
        $cdn['description'] = __('Sirve recursos estáticos desde un dominio CDN mediante la reescritura de URL.', 'dct-sollers');
        $cdn['default'] = false;
        $cdn['register'] = DCTWPSollers_CDN_Module::register(...);
        $cdn['admin_register'] = DCTWPSollers_CDN_Admin::register(...);
        $cdn['render'] = DCTWPSollers_CDN_Admin::render(...);
        $modules['cdn'] = $cdn;
        $frontend = array();
        $frontend['label'] = __('Frontend', 'dct-sollers');
        $frontend['description'] = __('Limpia recursos prescindibles y anticipa conexiones y recursos importantes del frontend.', 'dct-sollers');
        $frontend['default'] = false;
        $frontend['register'] = DCTWPSollers_Frontend_Module::register(...);
        $frontend['admin_register'] = DCTWPSollers_Frontend_Admin::register(...);
        $frontend['render'] = DCTWPSollers_Frontend_Admin::render(...);
        $modules['frontend'] = $frontend;

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
        self::initialize_modules();
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
    #  initialize_modules
    #
    #  Inicializa los módulos incluso desactivados antes de registrar su ejecución.
    #
    ################################################
    public static function initialize_modules(): void
    {
        foreach (self::modules() as $module)
        {
            if (isset($module['initialize']))
            {
                ($module['initialize'])();
            }
        }
    }

    ################################################
    #
    #  activate
    #
    #  Valida la instalación y ejecuta la activación propia de cada módulo.
    #
    ################################################
    public static function activate(): void
    {
        if (is_multisite())
        {
            wp_die(esc_html__('DCT Sollers requiere una instalación individual de WordPress.', 'dct-sollers'));
        }

        foreach (self::modules() as $module)
        {
            if (isset($module['activate']))
            {
                ($module['activate'])();
            }
        }
    }

    ################################################
    #
    #  deactivate
    #
    #  Ejecuta la desactivación propia de cada módulo conservando sus datos.
    #
    ################################################
    public static function deactivate(): void
    {
        foreach (self::modules() as $module)
        {
            if (isset($module['deactivate']))
            {
                ($module['deactivate'])();
            }
        }
    }
}

register_activation_hook(__FILE__, DCTWPSollers::activate(...));
register_deactivation_hook(__FILE__, DCTWPSollers::deactivate(...));
add_action('plugins_loaded', DCTWPSollers::boot(...), 0);
add_action('admin_menu', DCTWPSollers_Admin::menu(...));
add_action('admin_init', DCTWPSollers_Admin::register_settings(...));
