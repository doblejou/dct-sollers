<?php
// Ejecutar con wp eval-file tests/login/migrations.php solo en un WordPress de pruebas.

require_once dirname(__DIR__) . '/checks.php';
$check = DCTWPSollers_Checks::that(...);

global $wpdb;
$prefix = $wpdb->prefix;
$saved = get_option('dctwpsollers_db_version', null);
$wpdb->prefix = $prefix . 'migration_test_' . bin2hex(random_bytes(4)) . '_';
$table = DCTWPSollers_Store::table();
$suppress = $wpdb->suppress_errors(true);
$fail_create = static function (string $query): string
{
    if (str_starts_with($query, 'CREATE TABLE'))
    {
        return 'INVALID MIGRATION TEST SQL';
    }

    return $query;
};

try
{
    delete_option('dctwpsollers_db_version');
    add_filter('query', $fail_create);

    try
    {
        $check(!DCTWPSollers_Store::upgrade(), 'Una migración fallida comunica el error');
        $check(get_option('dctwpsollers_db_version', null) === null, 'El fallo no guarda la versión');
    }
    finally
    {
        remove_filter('query', $fail_create);
    }

    $lock = 'dctwpsollers_' . md5(DB_NAME . ':' . $table);
    $check((int) $wpdb->get_var($wpdb->prepare('SELECT IS_FREE_LOCK(%s)', $lock)) === 1, 'Libera el bloqueo tras un fallo');

    $fail_version = static function ($value, $old_value)
    {
        return $old_value;
    };

    add_filter('pre_update_option_dctwpsollers_db_version', $fail_version, 10, 2);

    try
    {
        $check(!DCTWPSollers_Store::upgrade(), 'Detecta el fallo al guardar la versión');
        $check(get_option('dctwpsollers_db_version', null) === null, 'No anuncia una versión no persistida');
    }
    finally
    {
        remove_filter('pre_update_option_dctwpsollers_db_version', $fail_version);
    }

    $check(DCTWPSollers_Store::upgrade(), 'Instalación nueva y reintento tras fallo');
    $check((int) get_option('dctwpsollers_db_version') === DCTWPSollers_Store::DB_VERSION, 'Versión guardada');

    $row = array();
    $row['ip'] = '192.0.2.245';
    $row['failures'] = '[1234567890]';
    $row['blocked_until'] = time() + 600;
    $row['updated_at'] = time();
    $check($wpdb->insert($table, $row) === 1, 'Fila de prueba creada');
    $before = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i', $table), ARRAY_A);

    delete_option('dctwpsollers_db_version');
    $wpdb->query($wpdb->prepare('ALTER TABLE %i DROP INDEX updated_at', $table));
    $check(DCTWPSollers_Store::upgrade(), 'Instalación antigua sin versión adaptada');
    $after = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i', $table), ARRAY_A);
    $check($before === $after, 'La migración conserva todos los datos');
    $indexes = $wpdb->get_results($wpdb->prepare('SHOW INDEX FROM %i WHERE Key_name = %s', $table, 'updated_at'));
    $check(count($indexes) === 1, 'Índice ausente reconstruido');
    $check(DCTWPSollers_Store::upgrade(), 'Reejecución sin cambios');
    $check((int) $wpdb->get_var($wpdb->prepare('SELECT IS_FREE_LOCK(%s)', $lock)) === 1, 'Libera el bloqueo tras completar la migración');

    update_option('dctwpsollers_db_version', DCTWPSollers_Store::DB_VERSION + 1, false);
    $check(!DCTWPSollers_Store::upgrade(), 'Rechaza esquemas futuros');
    $check((int) get_option('dctwpsollers_db_version') === DCTWPSollers_Store::DB_VERSION + 1, 'No rebaja la versión guardada');
    DCTWPSollers_Checks::report();
}
finally
{
    $wpdb->query($wpdb->prepare('DROP TABLE IF EXISTS %i', $table));
    $wpdb->prefix = $prefix;
    $wpdb->suppress_errors($suppress);

    if ($saved === null)
    {
        delete_option('dctwpsollers_db_version');
    }
    else
    {
        update_option('dctwpsollers_db_version', $saved, false);
    }
}
