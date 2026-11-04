<?php

// --require únicamente en instalaciones desechables; simula pérdida de un GET_LOCK real.
WP_CLI::add_hook('after_wp_load', static function (): void
{
    global $wpdb;

    if (!preg_match('/^dcttest_[a-f0-9]{12}_$/D', $wpdb->prefix)
        || !preg_match('~^/tmp/(?:opencode/)?dct-migration-test-[^/]+/wordpress(?:-target)?$~D', realpath(ABSPATH) ?: ''))
    {
        throw new RuntimeException('Se rechaza inyectar fallos fuera de la instalación desechable.');
    }

    $limit = (int) getenv('DCTWPHERO_TEST_LOSS_AT');

    if ($limit < 2)
    {
        throw new RuntimeException('Falta el punto de pérdida de bloqueo para la prueba.');
    }

    $checks = 0;
    add_filter('query', static function (string $sql) use ($limit, &$checks): string
    {
        global $wpdb;

        if (str_starts_with($sql, 'SELECT CONNECTION_ID() AS connection_id, IS_USED_LOCK(') && ++$checks === $limit)
        {
            $key = 'dct-migration-' . substr(hash('sha256', DB_NAME . ':' . $wpdb->prefix), 0, 40);
            $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $key));
        }

        return $sql;
    });
});
