<?php

defined('ABSPATH') || exit;
defined('WP_CLI') && WP_CLI || exit;

require_once __DIR__ . '/class-database.php';
global $wpdb;

foreach (DCTWPHero_Migration_Database::tables() as $dctwphero_table)
{
    DCTWPHero_Migration_Database::query('CHECK TABLE ' . DCTWPHero_Migration_Database::identifier($dctwphero_table));
    $dctwphero_healthy = false;

    foreach ($wpdb->last_result as $dctwphero_result)
    {
        if ($dctwphero_result->Msg_type === 'status' && $dctwphero_result->Msg_text === 'OK')
        {
            $dctwphero_healthy = true;
        }

        if ($dctwphero_result->Msg_type === 'error')
        {
            throw new RuntimeException('Una tabla restaurada no supera CHECK TABLE.');
        }
    }

    if (!$dctwphero_healthy)
    {
        throw new RuntimeException('No se ha podido confirmar la integridad de una tabla del sitio.');
    }
}

WP_CLI::success('Tablas del prefijo verificadas.');
