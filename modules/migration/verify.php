<?php

defined('ABSPATH') || exit;
defined('WP_CLI') && WP_CLI || exit;

require_once __DIR__ . '/class-database.php';
global $wpdb;

foreach (DCTWPSollers_Migration_Database::tables() as $dctwpsollers_table)
{
    DCTWPSollers_Migration_Database::query('CHECK TABLE ' . DCTWPSollers_Migration_Database::identifier($dctwpsollers_table));
    $dctwpsollers_healthy = false;

    foreach ($wpdb->last_result as $dctwpsollers_result)
    {
        if ($dctwpsollers_result->Msg_type === 'status' && $dctwpsollers_result->Msg_text === 'OK')
        {
            $dctwpsollers_healthy = true;
        }

        if ($dctwpsollers_result->Msg_type === 'error')
        {
            throw new RuntimeException('Una tabla restaurada no supera CHECK TABLE.');
        }
    }

    if (!$dctwpsollers_healthy)
    {
        throw new RuntimeException('No se ha podido confirmar la integridad de una tabla del sitio.');
    }
}

WP_CLI::success('Tablas del prefijo verificadas.');
