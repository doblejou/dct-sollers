<?php

defined('ABSPATH') || exit;

final class DCTWPSollers_Admin
{
    ################################################
    #
    #  menu
    #
    #  Añade la pantalla del plugin al menú de ajustes de WordPress.
    #
    ################################################
    public static function menu(): void
    {
        add_options_page('DCTWP Sollers', 'DCTWP Sollers', 'manage_options', 'dctwpsollers', self::render(...));
    }

    ################################################
    #
    #  register_settings
    #
    #  Registra la opción y su validación en la Settings API.
    #
    ################################################
    public static function register_settings(): void
    {
        $modules = array();
        $modules['type'] = 'array';
        $modules['sanitize_callback'] = 'DCTWPSollers_Admin::sanitize_modules';
        register_setting('dctwpsollers_modules', 'dctwpsollers_modules', $modules);
    }

    ################################################
    #
    #  sanitize_modules
    #
    #  Valida los estados de todos los módulos sin aceptar módulos desconocidos.
    #
    ################################################
    public static function sanitize_modules(mixed $input): array
    {
        $result = array();
        $valid = is_array($input);

        foreach (DCTWPSollers::modules() as $id => $module)
        {
            $value = is_array($input) ? ($input[$id] ?? null) : null;

            if (!DCTWPSollers::valid_module_state($value))
            {
                $valid = false;
            }

            $result[$id] = $value === 1 || $value === '1' ? 1 : 0;
        }

        if (!$valid || array_diff_key($input, $result) !== array())
        {
            add_settings_error('dctwpsollers_modules', 'invalid_modules', __('Los estados de los módulos no son válidos.', 'dctwpsollers'));

            return DCTWPSollers::module_states();
        }

        return $result;
    }

    ################################################
    #
    #  render
    #
    #  Selecciona una pestaña conocida y prepara la navegación de administración.
    #
    ################################################
    public static function render(): void
    {
        if (!current_user_can('manage_options'))
        {
            wp_die(esc_html__('No tienes permisos.', 'dctwpsollers'), '', 403);
        }

        $modules = DCTWPSollers::modules();
        $states = DCTWPSollers::module_states();
        // Read-only navigation; no state changes, so a nonce is not required.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $tab = isset($_GET['tab']) && is_string($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'modules';

        if ($tab !== 'modules' && !isset($modules[$tab]['render']))
        {
            $tab = 'modules';
        }

        $headers = array();
        $headers['version'] = 'Version';
        $plugin_data = get_file_data(dirname(__DIR__) . '/dctwpsollers.php', $headers);
        $version = $plugin_data['version'];

        require dirname(__DIR__) . '/templates/admin.php';
    }

}
