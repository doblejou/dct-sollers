<?php

defined('ABSPATH') || exit;

final class DCTWPSollers_Store
{
    public const DB_VERSION = 1;

    ################################################
    #
    #  upgrade
    #
    #  Ejecuta las migraciones pendientes en orden y sin ejecuciones simultáneas.
    #
    ################################################
    public static function upgrade(): bool
    {
        global $wpdb;

        $version = (int) get_option('dctwpsollers_db_version', 0);

        if ($version === self::DB_VERSION)
        {
            return true;
        }

        $lock = 'dctwpsollers_' . md5(DB_NAME . ':' . self::table());

        if ((int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 10)', $lock)) !== 1)
        {
            error_log('DCT Sollers: no se ha podido obtener el bloqueo de migración.');
            return false;
        }

        try
        {
            wp_cache_delete('dctwpsollers_db_version', 'options');
            wp_cache_delete('notoptions', 'options');
            $version = (int) get_option('dctwpsollers_db_version', 0);

            if ($version > self::DB_VERSION)
            {
                error_log('DCT Sollers: el esquema es más reciente que el código; no se admite downgrade.');
                return false;
            }

            while ($version < self::DB_VERSION)
            {
                $next = $version + 1;

                switch ($next)
                {
                    case 1:
                        $success = self::install();
                        break;

                    default:
                        $success = false;
                }

                if (!$success)
                {
                    error_log('DCT Sollers: ha fallado la migración de esquema ' . $next . '.');
                    return false;
                }

                if (!update_option('dctwpsollers_db_version', $next, false))
                {
                    error_log('DCT Sollers: no se ha podido guardar la versión del esquema.');
                    return false;
                }

                $version = $next;
            }

            return true;
        }
        finally
        {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
        }
    }

    ################################################
    #
    #  table
    #
    #  Obtiene el nombre de la tabla propia de este sitio.
    #
    ################################################
    public static function table(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'dctwpsollers_ips';
    }

    ################################################
    #
    #  install
    #
    #  Migra al esquema inicial conservando las filas de instalaciones anteriores.
    #
    ################################################
    public static function install(): bool
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table = self::table();
        $collation = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE $table (
            ip varchar(45) NOT NULL,
            failures longtext NOT NULL,
            blocked_until bigint unsigned NOT NULL DEFAULT 0,
            updated_at bigint unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY  (ip),
            KEY blocked_until (blocked_until),
            KEY updated_at (updated_at)
        ) ENGINE=InnoDB $collation;");

        if ($wpdb->last_error !== '')
        {
            return false;
        }

        $engine = $wpdb->get_var($wpdb->prepare(
            'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
            $table
        ));

        if ($engine !== 'InnoDB')
        {
            return false;
        }

        $columns = $wpdb->get_col($wpdb->prepare('SHOW COLUMNS FROM %i', $table));
        $required = array
        (
            'ip',
            'failures',
            'blocked_until',
            'updated_at',

        );

        if ($wpdb->last_error !== '' || array_diff($required, $columns) !== array())
        {
            return false;
        }

        $indexes = $wpdb->get_results($wpdb->prepare('SHOW INDEX FROM %i', $table));
        $found = array();

        foreach ($indexes as $index)
        {
            $found[$index->Key_name] = $index->Column_name;
        }

        return $wpdb->last_error === ''
            && ($found['PRIMARY'] ?? '') === 'ip'
            && ($found['blocked_until'] ?? '') === 'blocked_until'
            && ($found['updated_at'] ?? '') === 'updated_at';
    }

    ################################################
    #
    #  blocked_until
    #
    #  Consulta cuándo termina el bloqueo de una dirección.
    #
    ################################################
    public static function blocked_until(string $ip): int
    {
        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare(
            'SELECT blocked_until FROM %i WHERE ip = %s', self::table(), $ip
        ));
    }

    ################################################
    #
    #  record_failure
    #
    #  Actualiza la ventana móvil bajo bloqueo de fila para evitar pérdidas.
    #
    ################################################
    public static function record_failure(string $ip, array $settings): bool
    {
        global $wpdb;

        $now = time();
        $table = self::table();

        if ($wpdb->query('START TRANSACTION') === false)
        {
            return false;
        }

        $inserted = $wpdb->query($wpdb->prepare(
            "INSERT INTO %i (ip, failures, blocked_until, updated_at) VALUES (%s, '[]', 0, %d) ON DUPLICATE KEY UPDATE ip = VALUES(ip)",
            $table, $ip, $now
        ));
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT failures, blocked_until FROM %i WHERE ip = %s FOR UPDATE', $table, $ip
        ));

        if ($inserted === false || !$row)
        {
            $wpdb->query('ROLLBACK');

            return false;
        }

        $now = time();

        if ((int) $row->blocked_until > $now)
        {
            return self::commit();
        }

        $failures = array();
        $previous = json_decode($row->failures, true);
        $cutoff = $now - (int) $settings['window'] * MINUTE_IN_SECONDS;

        if ((int) $row->blocked_until === 0 && is_array($previous))
        {
            foreach ($previous as $timestamp)
            {
                if ((int) $timestamp > $cutoff)
                {
                    $failures[] = (int) $timestamp;
                }
            }
        }

        $failures[] = $now;
        $until = 0;

        if (count($failures) >= (int) $settings['threshold'])
        {
            $until = $now + (int) $settings['duration'] * MINUTE_IN_SECONDS;
            $failures = array();
        }

        $updated = $wpdb->query($wpdb->prepare(
            'UPDATE %i SET failures = %s, blocked_until = %d, updated_at = %d WHERE ip = %s',
            $table, wp_json_encode($failures), $until, $now, $ip
        ));

        if ($updated === false)
        {
            $wpdb->query('ROLLBACK');

            return false;
        }

        return self::commit();
    }

    ################################################
    #
    #  commit
    #
    #  Confirma los cambios o cancela la transacción si la confirmación falla.
    #
    ################################################
    private static function commit(): bool
    {
        global $wpdb;

        if ($wpdb->query('COMMIT') === false)
        {
            $wpdb->query('ROLLBACK');

            return false;
        }

        return true;
    }

    ################################################
    #
    #  unlock
    #
    #  Reinicia el bloqueo y el historial de una dirección sin perder la fila.
    #
    ################################################
    public static function unlock(string $ip): bool
    {
        global $wpdb;

        return $wpdb->query($wpdb->prepare(
            "UPDATE %i SET failures = '[]', blocked_until = 0, updated_at = %d WHERE ip = %s",
            self::table(), time(), $ip
        )) !== false;
    }

    ################################################
    #
    #  active_count
    #
    #  Cuenta los bloqueos vigentes para paginar la administración.
    #
    ################################################
    public static function active_count(): int
    {
        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM %i WHERE blocked_until > %d', self::table(), time()
        ));
    }

    ################################################
    #
    #  active_blocks
    #
    #  Recupera una página de bloqueos vigentes, ordenada por caducidad.
    #
    ################################################
    public static function active_blocks(int $page): array
    {
        global $wpdb;

        return $wpdb->get_results($wpdb->prepare(
            'SELECT ip, updated_at, blocked_until FROM %i WHERE blocked_until > %d ORDER BY blocked_until DESC, ip ASC LIMIT 50 OFFSET %d',
            self::table(), time(), (max(1, $page) - 1) * 50
        ));
    }

    ################################################
    #
    #  cleanup
    #
    #  Elimina registros inactivos sin afectar a ventanas o bloqueos vigentes.
    #
    ################################################
    public static function cleanup(): void
    {
        global $wpdb;

        $settings = DCTWPSollers_Login_Settings::get();
        $now = time();
        $wpdb->query($wpdb->prepare(
            'DELETE FROM %i WHERE blocked_until <= %d AND updated_at < %d',
            self::table(), $now, $now - (int) $settings['window'] * MINUTE_IN_SECONDS
        ));
    }
}
