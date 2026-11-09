<?php

defined('ABSPATH') || exit;

final class DCTWPSollers_Migration_Admin
{
    ################################################
    #
    #  render
    #
    #  Muestra ejemplos de uso sin cargar ni ejecutar el motor de migración.
    #
    ################################################
    public static function render(): void
    {
        if (!current_user_can('manage_options'))
        {
            wp_die(esc_html__('No tienes permisos.', 'dctwpsollers'), '', 403);
        }

        $enabled = DCTWPSollers::module_enabled('migration');

        require __DIR__ . '/templates/usage.php';
    }

}
