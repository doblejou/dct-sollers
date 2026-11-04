<?php

defined('ABSPATH') || exit;
defined('WP_CLI') && WP_CLI || exit;

global $wpdb;
$dctwphero_home = $args[0] ?? '';
$dctwphero_site = $args[1] ?? '';

if ($dctwphero_home === '' || $dctwphero_site === '')
{
    throw new RuntimeException('Faltan las URLs de finalización.');
}

wp_cache_flush();
$dctwphero_settings = array();
$dctwphero_settings['home'] = $dctwphero_home;
$dctwphero_settings['siteurl'] = $dctwphero_site;
$dctwphero_settings['upload_path'] = '';
$dctwphero_settings['upload_url_path'] = '';
$dctwphero_plugins = get_option('active_plugins', array());

if (!is_array($dctwphero_plugins))
{
    throw new RuntimeException('La lista de plugins activos importada no es válida.');
}

$dctwphero_self = plugin_basename(dirname(__DIR__, 2) . '/dctwphero.php');

if (!in_array($dctwphero_self, $dctwphero_plugins, true))
{
    $dctwphero_plugins[] = $dctwphero_self;
}

$dctwphero_settings['active_plugins'] = $dctwphero_plugins;

foreach ($dctwphero_settings as $dctwphero_key => $dctwphero_value)
{
    update_option($dctwphero_key, $dctwphero_value);
    $dctwphero_stored = $wpdb->get_var($wpdb->prepare('SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, $dctwphero_key));

    $dctwphero_default_upload = $dctwphero_stored === null && $dctwphero_value === '' && ($dctwphero_key === 'upload_path' || $dctwphero_key === 'upload_url_path');

    if (!$dctwphero_default_upload && $dctwphero_stored !== maybe_serialize($dctwphero_value))
    {
        throw new RuntimeException('No se ha podido guardar un ajuste final de la migración.');
    }
}

WP_CLI::success('Ajustes finales guardados en un proceso nuevo.');
