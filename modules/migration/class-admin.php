<?php

defined('ABSPATH') || exit;

final class DCTWPHero_Migration_Admin
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
            wp_die(esc_html__('No tienes permisos.', 'dctwphero'), '', 403);
        }

        $enabled = DCTWPHero::module_enabled('migration');

        require __DIR__ . '/templates/usage.php';
    }

}
