<?php

// Ejecutar únicamente en el WordPress desechable de integration.py.
require_once dirname(__DIR__) . '/checks.php';
$check = DCTWPSollers_Checks::that(...);
$rejects = DCTWPSollers_Checks::rejects(...);
$terminal = new ReflectionMethod(DCTWPSollers_Migration_Command::class, 'terminal');
$check($terminal->invoke(null, 'Ruta con espacios, comillas " y acentos: migración') === 'Ruta con espacios, comillas " y acentos: migración', 'Diagnósticos CLI preservan texto UTF-8 sin escape HTML');

foreach (array("\0", "\n", "\r", "\t", "\x1b[31m", "\x7f", "\xC2\x9B31m") as $control)
{
    $escaped = $terminal->invoke(null, 'ruta/' . $control);
    $check(!preg_match('/[\x00-\x1f\x7f]|\xC2[\x80-\x9f]/', $escaped) && str_contains($escaped, '\\x'), 'Diagnósticos CLI convierten controles en texto visible');
}

foreach (array('bad-name', 'x` DROP TABLE users', 'x; SELECT 1', "x\0y", str_repeat('x', 65), '') as $identifier)
{
    $rejects(static fn() => DCTWPSollers_Migration_Database::identifier($identifier), 'Rechaza identificadores SQL fuera del contrato');
}

$directory = sys_get_temp_dir() . '/dct-library-test-' . bin2hex(random_bytes(12));
mkdir($directory, 0700);

try
{
    $output = new ReflectionMethod(DCTWPSollers_Migration_Command::class, 'output');
    $had_document_root = array_key_exists('DOCUMENT_ROOT', $_SERVER);
    $original_document_root = $_SERVER['DOCUMENT_ROOT'] ?? null;
    $output_file = $directory . '/document-root.zip';

    try
    {
        unset($_SERVER['DOCUMENT_ROOT']);
        $check($output->invoke(null, $output_file) === $output_file, 'Exportación CLI admite DOCUMENT_ROOT ausente');

        foreach (array('', null) as $document_root)
        {
            $_SERVER['DOCUMENT_ROOT'] = $document_root;
            $check($output->invoke(null, $output_file) === $output_file, 'Exportación CLI admite DOCUMENT_ROOT vacío o null');
        }

        $_SERVER['DOCUMENT_ROOT'] = $directory;
        $rejects(static fn() => $output->invoke(null, $output_file), 'Exportación rechaza ZIP bajo DOCUMENT_ROOT');

        foreach (array(array(), 123, false) as $document_root)
        {
            $_SERVER['DOCUMENT_ROOT'] = $document_root;
            $rejects(static fn() => $output->invoke(null, $output_file), 'Exportación rechaza DOCUMENT_ROOT de tipo inesperado');
        }
    }
    finally
    {
        if ($had_document_root)
        {
            $_SERVER['DOCUMENT_ROOT'] = $original_document_root;
        }
        else
        {
            unset($_SERVER['DOCUMENT_ROOT']);
        }
    }

    $manifest = array();
    $manifest['format'] = 'dctwpsollers';
    $manifest['version'] = 1;
    $manifest['context'] = DCTWPSollers_Migration_Package::context();
    $manifest['prefix'] = 'source_';
    $manifest['tables'] = array();
    $manifest['entries'] = array();
    $manifest['excluded_paths'] = array();
    $manifest['excluded_tables'] = array();

    foreach (array('options', 'users', 'usermeta') as $table)
    {
        $entry = 'database/' . $table . '.ndjson';
        $manifest['tables'][$table] = $entry;
        $metadata = array();
        $metadata['size'] = 0;
        $metadata['sha256'] = hash('sha256', '');
        $manifest['entries'][$entry] = $metadata;
    }

    DCTWPSollers_Migration_Package::validate($manifest);
    $check(true, 'Manifiesto válido');
    $mutations = array
    (
        array('format', 'wpress'),
        array('version', 2),
        array('prefix', ''),
        array('prefix', 'bad-prefix'),
        array('tables>users', null),
        array('tables>users', '../outside'),
        array('entries>database/users.ndjson>size', -1),
        array('entries>database/users.ndjson>size', '1'),
        array('entries>database/users.ndjson>sha256', 'invalid'),
        array('excluded_paths>0', '../bad'),
        array('excluded_paths>0', 1),
        array('excluded_paths>named', 'uploads'),
        array('excluded_tables>0', 'users'),
        array('excluded_tables>0', 1),
        array('context>php', 'future'),
        array('context>home', null),
        array('context>root', "/bad\0root"),
        array('context>site', 'ftp://example.test'),
        array('context>root', 'relative/path'),
        array('entries>database/users.ndjson>mode', 04755),
        array('entries>database/users.ndjson>mode', '644'),
        array('entries>database/users.ndjson>mode', 0777),
        array('entries>database/users.ndjson>mtime', 'yesterday'),

    );

    foreach ($mutations as $case)
    {
        $invalid = DCTWPSollers_Checks::changed($manifest, $case[0], $case[1]);
        $rejects(static fn() => DCTWPSollers_Migration_Package::validate($invalid), 'Manifiesto inválido: ' . $case[0]);
    }

    foreach (array(null, 1, 'bad', array()) as $invalid)
    {
        $rejects(static fn() => DCTWPSollers_Migration_Package::validate($invalid), 'Tipo de manifiesto inválido');
    }

    $schema = 'CREATE TABLE `source_fixture` (`id` int, `value` longblob) ENGINE=InnoDB';
    DCTWPSollers_Migration_Database::validate_schema($schema . " COMMENT='FOREIGN KEY is only a comment'", 'source_fixture');
    $check(true, 'Palabras reservadas dentro de comentarios literales');

    $foreign = "CREATE TABLE `source_fixture` (\n  `id` int,\n  CONSTRAINT `old_key` FOREIGN KEY (`id`) REFERENCES `source_parent` (`id`) ON DELETE CASCADE ON UPDATE RESTRICT\n) ENGINE=InnoDB";
    DCTWPSollers_Migration_Database::validate_schema($foreign, 'source_fixture');
    $definition = DCTWPSollers_Migration_Database::split_schema($foreign);
    $check(count($definition['keys']) === 1 && $definition['keys'][0]['table'] === 'source_parent', 'Extrae referencias canónicas');
    $check(!str_contains($definition['schema'], 'CONSTRAINT'), 'Carga temporal sin relaciones prematuras');
    $literal = $schema . " COMMENT=',\n  CONSTRAINT `fake` FOREIGN KEY (`id`) REFERENCES `other` (`id`)\n'";
    $definition = DCTWPSollers_Migration_Database::split_schema($literal);
    $check($definition['keys'] === array() && $definition['schema'] === $literal, 'No extrae relaciones de literales multilínea');
    $rejects(static fn() => DCTWPSollers_Migration_Database::validate_schema(str_replace('`source_parent`', '`outside`.`source_parent`', $foreign), 'source_fixture'), 'Rechaza referencias SQL calificadas');
    $rejects(static fn() => DCTWPSollers_Migration_Database::validate_schema(str_replace('ON DELETE CASCADE', 'ON DELETE SET DEFAULT', $foreign), 'source_fixture'), 'Rechaza acciones fuera de contrato');

    foreach (array('MyISAM', 'InnoDB PARTITION BY HASH(id)', "InnoDB DATA DIRECTORY='/tmp'") as $engine)
    {
        $invalid = str_replace('InnoDB', $engine, $schema);
        $rejects(static fn() => DCTWPSollers_Migration_Database::validate_schema($invalid, 'source_fixture'), 'Esquema fuera de contrato');
    }

    $header = array();
    $header['table'] = 'source_fixture';
    $header['schema'] = $schema;
    $header['columns'] = array('id', 'value');
    $record = array('MQ==', null);
    $file = $directory . '/table.ndjson';
    file_put_contents($file, json_encode($header) . "\n" . json_encode($record) . "\n");
    $rows = iterator_to_array(DCTWPSollers_Migration_Database::records($file, 'source_fixture'));
    $check($rows[1] === array('1', null), 'Decodificación binaria y NULL');
    $invalid_records = array
    (
        array('MQ'),
        array('@@@', null),
        array('MQ', null),
        array(1, null),

    );

    foreach ($invalid_records as $row)
    {
        file_put_contents($file, json_encode($header) . "\n" . json_encode($row) . "\n");
        $rejects(static fn() => iterator_to_array(DCTWPSollers_Migration_Database::records($file, 'source_fixture')), 'Fila inválida');
    }

    $root = $directory . '/content';
    mkdir($root);
    mkdir($root . '/private');
    file_put_contents($root . '/extra', 'extra');
    file_put_contents($root . '/private/keep', 'private');
    $entry = array();
    $entry['content/new'] = $manifest['entries']['database/users.ndjson'];
    $plan = DCTWPSollers_Migration_Files::plan($root, $entry, array('private'), 'mirror');
    $check($plan['write'] === array('new') && $plan['delete'] === array('extra'), 'Plan mirror respeta exclusiones');
    $plan = DCTWPSollers_Migration_Files::plan($root, $entry, array('private'), 'merge');
    $check($plan['delete'] === array(), 'Plan merge no borra extras');
    mkdir($root . '/empty/child', 0755, true);
    $plan = DCTWPSollers_Migration_Files::plan($root, $entry, array('private'), 'mirror');
    $check($plan['directories'] === array('empty/child', 'empty'), 'Planifica directorios vacíos de hijos a padres');
    $plan = DCTWPSollers_Migration_Files::plan($root, $entry, array('private', 'empty/missing/cache'), 'mirror');
    $check($plan['directories'] === array('empty/child'), 'Conserva ancestros de exclusiones aún inexistentes');
    DCTWPSollers_Migration_Files::remove_directory($root, 'empty/child');
    DCTWPSollers_Migration_Files::remove_directory($root, 'empty');
    $check(!file_exists($root . '/empty'), 'Elimina directorios vacíos sin borrado recursivo');
    symlink($directory, $root . '/link');
    $rejects(static fn() => DCTWPSollers_Migration_Files::destination($root, 'link/escape'), 'Enlace intermedio');
    $rejects(static fn() => iterator_to_array(DCTWPSollers_Migration_Files::inventory($root, array())), 'Inventario no sigue enlaces');
    unlink($root . '/link');
    $rejects(static fn() => DCTWPSollers_Migration_Files::destination($root, 'private'), 'Directorio sobre archivo');
    $rejects(static fn() => DCTWPSollers_Migration_Files::destination($root, 'extra/child'), 'Archivo sobre directorio');
    file_put_contents($directory . '/source', 'installed');
    DCTWPSollers_Migration_Files::copy($directory . '/source', $root, 'new/path');
    $check(file_get_contents($root . '/new/path') === 'installed', 'Copia crea directorios y publica archivo');
    $operation = new DCTWPSollers_Migration_Operation();
    global $wpdb;
    $original_timeout = (int) $wpdb->get_var('SELECT @@SESSION.wait_timeout');
    $global_timeout = (int) $wpdb->get_var('SELECT @@GLOBAL.wait_timeout');

    try
    {
        DCTWPSollers_Migration_Database::query('SET SESSION wait_timeout = 1');
        $temporary = $operation->start();
        $check((int) $wpdb->get_var('SELECT @@SESSION.wait_timeout') === 86400, 'Amplía solo el timeout de la sesión de migración');
        sleep(2);
        $operation->assert_locked();
        $check(true, 'La sesión y el bloqueo sobreviven al timeout inicial de un segundo');
        $archive = $operation->archive($directory . '/output.zip');
        $check((fileperms(dirname($archive)) & 0777) === 0700, 'ZIP temporal inaccesible a otros usuarios');
        $rejects(static fn() => $operation->start(), 'No duplica recursos de una operación iniciada');
        $rejects(static fn() => $operation->archive($directory . '/other.zip'), 'No duplica ZIP temporal');
        $check($operation->finish(null) === null && !file_exists($temporary) && !file_exists(dirname($archive)), 'Limpieza completa');
        $check((int) $wpdb->get_var('SELECT @@SESSION.wait_timeout') === 1, 'Restaura el timeout original tras liberar el bloqueo');
        $check($operation->finish(null) === null, 'Limpieza idempotente');
        DCTWPSollers_Migration_Database::query('SET SESSION wait_timeout = 172800');
        $operation = new DCTWPSollers_Migration_Operation();
        $operation->start();
        $check((int) $wpdb->get_var('SELECT @@SESSION.wait_timeout') === 172800, 'No reduce un timeout mayor de 24 horas');
        $check($operation->finish(null) === null, 'Finaliza con timeout inicial largo');
        $check((int) $wpdb->get_var('SELECT @@GLOBAL.wait_timeout') === $global_timeout, 'No modifica el timeout global SQL');
        $operation = new DCTWPSollers_Migration_Operation();
        $operation->start();
        $old_connection = (int) $wpdb->get_var('SELECT CONNECTION_ID()');
        $check($wpdb->close() && $wpdb->db_connect(false), 'Reconecta únicamente la conexión de la instalación desechable');
        $check((int) $wpdb->get_var('SELECT CONNECTION_ID()') !== $old_connection, 'La nueva conexión tiene otra identidad');
        DCTWPSollers_Migration_Database::query('SET SESSION wait_timeout = 57');
        $rejects($operation->assert_locked(...), 'Detecta una reconexión y no readquiere automáticamente el bloqueo');
        $message = $operation->finish(null);
        $check(is_string($message) && str_contains($message, 'bloqueo SQL'), 'Distingue la pérdida del bloqueo de la limpieza de archivos');
        $check((int) $wpdb->get_var('SELECT @@SESSION.wait_timeout') === 57, 'No restaura el timeout original sobre otra sesión');
        $check($operation->finish(null) === null, 'No reintenta liberar un bloqueo perdido ni alterar otra sesión');
    }
    finally
    {
        $operation->finish(null);
        DCTWPSollers_Migration_Database::query('SET SESSION wait_timeout = ' . $original_timeout);
    }
    DCTWPSollers_Migration_Files::copy($directory . '/source', $root, 'executable', 0755, 1700000000);
    $check((fileperms($root . '/executable') & 0777) === 0755 && filemtime($root . '/executable') === 1700000000, 'Conserva ejecución y fecha');
    $mask = umask(0077);

    try
    {
        DCTWPSollers_Migration_Files::copy($directory . '/source', $root, 'restrictive/sub/file');
        $check((fileperms($root . '/restrictive/sub') & 0777) === 0755, 'Directorios web transitables con umask restrictiva');
    }
    finally
    {
        umask($mask);
    }

    DCTWPSollers_Migration_Files::copy($directory . '/source', $root, 'readonly', 0400);
    DCTWPSollers_Migration_Files::copy($directory . '/source', $root, 'readonly', 0400);
    $check(file_get_contents($root . '/readonly') === 'installed', 'Sustituye atómicamente un archivo de solo lectura con permiso en el directorio');

    if (function_exists('posix_geteuid') && posix_geteuid() !== 0)
    {
        $operation = new DCTWPSollers_Migration_Operation();
        $temporary = $operation->start();
        file_put_contents($temporary . '/keep', 'test');
        chmod($temporary, 0500);
        set_error_handler(static fn(): bool => true);

        try
        {
            $message = $operation->finish('original failure');
        }
        finally
        {
            restore_error_handler();
            chmod($temporary, 0700);
        }

        global $wpdb;
        $key = 'dctwpsollers-migration-' . substr(hash('sha256', DB_NAME . ':' . $wpdb->prefix), 0, 40);
        $check($message === 'original failure', 'Conserva el error original si falla la limpieza');
        $check($wpdb->get_var($wpdb->prepare('SELECT IS_USED_LOCK(%s)', $key)) === null, 'Libera bloqueo aunque falle borrar temporales');
        $check($operation->finish(null) === null, 'Reintenta limpiar solo los recursos pendientes');
    }

    DCTWPSollers_Checks::report();
}
finally
{
    DCTWPSollers_Migration_Files::remove_tree($directory);
}
