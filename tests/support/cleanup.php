<?php

// Elimina exclusivamente tablas con el prefijo aleatorio reservado al entorno desechable.
global $wpdb;

if (!preg_match('/^dcttest_[a-f0-9]{12}_$/D', $wpdb->prefix)
    || !preg_match('~^/tmp/(?:opencode/)?dctwpsollers-test-[^/]+/wordpress(?:-target)?$~D', realpath(ABSPATH) ?: ''))
{
    throw new RuntimeException('Se rechaza limpiar una instalación ajena a estas pruebas.');
}

$checks = (int) $wpdb->get_var('SELECT @@SESSION.FOREIGN_KEY_CHECKS');

try
{
    $wpdb->query('SET SESSION FOREIGN_KEY_CHECKS = 0');

    foreach ($wpdb->get_col('SHOW TABLES') as $table)
    {
        if (str_starts_with($table, $wpdb->prefix) && preg_match('/^[a-zA-Z0-9_]+$/D', $table))
        {
            if ($wpdb->query('DROP TABLE `' . $table . '`') === false)
            {
                throw new RuntimeException('No se puede limpiar una tabla de pruebas.');
            }
        }
    }
}
finally
{
    $wpdb->query('SET SESSION FOREIGN_KEY_CHECKS = ' . $checks);
}

echo "Tablas de pruebas eliminadas.\n";
