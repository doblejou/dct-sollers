<?php

use Elementor\Plugin as ElementorPlugin;

defined('ABSPATH') || exit;

final class DCTWPHero_Migration_Cache
{
    ################################################
    #
    #  flush
    #
    #  Invalida el CSS de Elementor activo y termina limpiando la caché de objetos.
    #
    ################################################
    public static function flush(): void
    {
        if (did_action('elementor/loaded'))
        {
            $manager = class_exists(ElementorPlugin::class, false) ? ElementorPlugin::$instance->files_manager ?? null : null;

            if (!is_object($manager) || !is_callable(array($manager, 'clear_cache')))
            {
                throw new RuntimeException('Elementor está cargado pero no permite limpiar su caché CSS.');
            }

            $manager->clear_cache();
            WP_CLI::log('Caché CSS de Elementor limpiada; se regenerará al visitar las páginas.');
        }
        else
        {
            WP_CLI::log('Limpieza CSS omitida: Elementor no está activo o no se ha inicializado.');
        }

        if (!wp_cache_flush())
        {
            throw new RuntimeException('No se ha podido limpiar la caché de objetos de WordPress.');
        }

        WP_CLI::success('Caché de objetos de WordPress limpiada.');
    }

}
