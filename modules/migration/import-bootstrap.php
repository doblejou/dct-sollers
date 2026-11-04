<?php

defined('WP_CLI') && WP_CLI || exit;

if (defined('ABSPATH') || WP_CLI::get_config('skip-plugins') !== true || WP_CLI::get_config('skip-themes') !== true)
{
    WP_CLI::error('El arranque de importación debe cargarse mediante --require, antes de WordPress, con --skip-plugins y --skip-themes.');
}

define('DCTWPHERO_MIGRATION_ISOLATED', true);
define('WPMU_PLUGIN_DIR', sys_get_temp_dir() . '/dctwphero-unused-mu-' . bin2hex(random_bytes(16)));

WP_CLI::add_wp_hook('enable_loading_object_cache_dropin', static fn(): bool => false);
WP_CLI::add_wp_hook('enable_loading_advanced_cache_dropin', static fn(): bool => false);

WP_CLI::add_hook('after_wp_load', static function (): void
{
    require_once dirname(__DIR__, 2) . '/dctwphero.php';
    $self = plugin_basename(dirname(__DIR__, 2) . '/dctwphero.php');

    if (!in_array($self, get_option('active_plugins', array()), true) || !DCTWPHero::module_enabled('migration'))
    {
        WP_CLI::error('El plugin DCT WP Hero y su módulo de migración deben estar activos.');
    }

    DCTWPHero::maybe_upgrade();
    DCTWPHero_Migration_Module::register();
});
