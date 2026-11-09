<?php

// Fallos SQL reales sobre una tabla de prueba independiente, nunca sobre filas del sitio.
require_once dirname(__DIR__) . '/checks.php';
$check = DCTWPSollers_Checks::that(...);
global $wpdb;
$prefix = $wpdb->prefix;
$source = DCTWPSollers_Store::table();
$wpdb->prefix .= 'store_test_' . bin2hex(random_bytes(4)) . '_';
$table = DCTWPSollers_Store::table();
$suppressed = $wpdb->suppress_errors(true);
$ip = '192.0.2.249';
$settings = DCTWPSollers_Login_Module::settings();
$settings['threshold'] = 3;
$filter = null;

try
{
    $check($wpdb->query($wpdb->prepare('CREATE TABLE %i LIKE %i', $table, $source)) !== false, 'Crea tabla independiente para fallos SQL');

    foreach (array('START TRANSACTION', 'INSERT INTO', 'SELECT failures', 'UPDATE', 'COMMIT') as $statement)
    {
        $wpdb->query($wpdb->prepare('DELETE FROM %i', $table));
        $rollback = false;
        $failed = false;
        $filter = static function (string $sql) use ($statement, &$rollback, &$failed): string
        {
            if ($sql === 'ROLLBACK')
            {
                $rollback = true;
            }

            if (str_starts_with($sql, $statement))
            {
                $failed = true;

                return 'INVALID STORE TEST SQL';
            }

            return $sql;
        };
        add_filter('query', $filter);

        try
        {
            $check(!DCTWPSollers_Store::record_failure($ip, $settings), 'Propaga fallo SQL: ' . $statement);
        }
        finally
        {
            remove_filter('query', $filter);
            $filter = null;
        }

        $check($failed, 'Ejecutó la condición de error prevista: ' . $statement);
        $check($statement === 'START TRANSACTION' || $rollback, 'Cancela cambios pendientes tras fallo SQL');
        $check((int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i', $table)) === 0, 'No conserva incrementos fallidos');
        $check(DCTWPSollers_Store::record_failure($ip, $settings), 'Permite reintento tras fallo SQL');
    }

    $settings['threshold'] = 1;
    $check(DCTWPSollers_Store::record_failure($ip, $settings), 'Prepara bloqueo activo');
    $until = DCTWPSollers_Store::blocked_until($ip);
    $filter = static fn(string $sql): string => $sql === 'COMMIT' ? 'INVALID STORE TEST SQL' : $sql;
    add_filter('query', $filter);

    try
    {
        $check(!DCTWPSollers_Store::record_failure($ip, $settings), 'Bloqueo activo también propaga fallo de COMMIT');
    }
    finally
    {
        remove_filter('query', $filter);
        $filter = null;
    }

    $check(DCTWPSollers_Store::blocked_until($ip) === $until, 'Fallo de confirmación no altera bloqueo activo');
    $filter = static fn(string $sql): string => str_starts_with($sql, 'UPDATE') ? 'INVALID STORE TEST SQL' : $sql;
    add_filter('query', $filter);

    try
    {
        $check(!DCTWPSollers_Store::unlock($ip), 'Desbloqueo propaga fallo SQL');
    }
    finally
    {
        remove_filter('query', $filter);
        $filter = null;
    }

    $check(DCTWPSollers_Store::blocked_until($ip) === $until, 'Desbloqueo fallido conserva bloqueo');

    $check(DCTWPSollers_Store::unlock($ip), 'Retira bloqueo antes de simular espera por la fila');
    $locked_at = 0;
    $filter = static function (string $sql) use (&$locked_at): string
    {
        if (str_starts_with($sql, 'SELECT failures'))
        {
            sleep(2);
            $locked_at = time();
        }

        return $sql;
    };
    add_filter('query', $filter);

    try
    {
        $check(DCTWPSollers_Store::record_failure($ip, $settings), 'Registra fallo tras espera por el bloqueo de fila');
    }
    finally
    {
        remove_filter('query', $filter);
        $filter = null;
    }

    $check($locked_at > 0 && DCTWPSollers_Store::blocked_until($ip) >= $locked_at + $settings['duration'] * MINUTE_IN_SECONDS, 'La espera SQL no acorta la duración del bloqueo');

    $wpdb->query($wpdb->prepare('DELETE FROM %i', $table));
    $now = time();
    $expected = array();

    for ($index = 1; $index <= 51; $index++)
    {
        $address = '192.0.2.' . $index;
        $expected[] = $address;
        $row = array();
        $row['ip'] = $address;
        $row['failures'] = '[]';
        $row['blocked_until'] = $now + 600;
        $row['updated_at'] = $now;
        $check($wpdb->insert($table, $row) === 1, 'Prepara fila de paginación');
    }

    sort($expected, SORT_STRING);
    $first = DCTWPSollers_Store::active_blocks(1);
    $second = DCTWPSollers_Store::active_blocks(2);
    $check(DCTWPSollers_Store::active_count() === 51, 'Cuenta todos los bloqueos activos');
    $check(count($first) === 50 && count($second) === 1, 'Pagina bloques de cincuenta sin perder filas');
    $check(array_column(array_merge($first, $second), 'ip') === $expected, 'Orden estable por IP cuando coincide la expiración');
    $check(DCTWPSollers_Store::active_blocks(0) == $first, 'Página cero se limita a la primera');
    $check(DCTWPSollers_Store::active_blocks(3) === array(), 'Página posterior vacía');
    $wpdb->query($wpdb->prepare(
        "INSERT INTO %i (ip, failures, blocked_until, updated_at) VALUES (%s, '[]', %d, %d)",
        $table, $ip, $now - 1, $now - 604801
    ));
    $check(DCTWPSollers_Store::active_count() === 51, 'No cuenta bloqueos expirados');
    DCTWPSollers_Store::cleanup();
    $check((int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i', $table)) === 51, 'Limpia expirados y conserva todos los activos');
    DCTWPSollers_Checks::report();
}
finally
{
    if ($filter !== null)
    {
        remove_filter('query', $filter);
    }

    $wpdb->query('ROLLBACK');
    $wpdb->query($wpdb->prepare('DROP TABLE IF EXISTS %i', $table));
    $wpdb->prefix = $prefix;
    $wpdb->suppress_errors($suppressed);
}
