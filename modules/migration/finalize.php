<?php

defined('ABSPATH') || exit;
defined('WP_CLI') && WP_CLI || exit;

global $wpdb;
$dctwpsollers_home = $args[0] ?? '';
$dctwpsollers_site = $args[1] ?? '';

if ($dctwpsollers_home === '' || $dctwpsollers_site === '')
{
    throw new RuntimeException('Faltan las URLs de finalización.');
}

wp_cache_flush();
$dctwpsollers_settings = array();
$dctwpsollers_settings['home'] = $dctwpsollers_home;
$dctwpsollers_settings['siteurl'] = $dctwpsollers_site;
$dctwpsollers_settings['upload_path'] = '';
$dctwpsollers_settings['upload_url_path'] = '';
$dctwpsollers_plugins = get_option('active_plugins', array());

if (!is_array($dctwpsollers_plugins))
{
    throw new RuntimeException('La lista de plugins activos importada no es válida.');
}

$dctwpsollers_self = plugin_basename(dirname(__DIR__, 2) . '/dct-sollers.php');

if (!in_array($dctwpsollers_self, $dctwpsollers_plugins, true))
{
    $dctwpsollers_plugins[] = $dctwpsollers_self;
}

$dctwpsollers_settings['active_plugins'] = $dctwpsollers_plugins;

foreach ($dctwpsollers_settings as $dctwpsollers_key => $dctwpsollers_value)
{
    update_option($dctwpsollers_key, $dctwpsollers_value);
    $dctwpsollers_stored = $wpdb->get_var($wpdb->prepare('SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, $dctwpsollers_key));

    $dctwpsollers_default_upload = $dctwpsollers_stored === null && $dctwpsollers_value === '' && ($dctwpsollers_key === 'upload_path' || $dctwpsollers_key === 'upload_url_path');

    if (!$dctwpsollers_default_upload && $dctwpsollers_stored !== maybe_serialize($dctwpsollers_value))
    {
        throw new RuntimeException('No se ha podido guardar un ajuste final de la migración.');
    }
}

WP_CLI::success('Ajustes finales guardados en un proceso nuevo.');
