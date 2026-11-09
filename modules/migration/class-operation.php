<?php

defined('ABSPATH') || exit;

final class DCTWPSollers_Migration_Operation
{
    private ?string $directory = null;
    private ?string $archive_directory = null;
    private bool $locked = false;
    private ?int $connection_id = null;
    private ?int $wait_timeout = null;
    private ?DCTWPSollers_Migration_Database $database = null;

    ################################################
    #
    #  start
    #
    #  Serializa la operación y crea su espacio privado, con limpieza aunque falle el inicio.
    #
    ################################################
    public function start(): string
    {
        if ($this->locked || $this->directory !== null || $this->wait_timeout !== null)
        {
            throw new RuntimeException('La operación ya se ha iniciado.');
        }

        global $wpdb;
        DCTWPSollers_Migration_Database::query('SELECT CONNECTION_ID() AS connection_id, @@SESSION.wait_timeout AS wait_timeout');
        $session = $wpdb->last_result[0];
        $this->connection_id = (int) $session->connection_id;
        $this->wait_timeout = (int) $session->wait_timeout;
        $timeout = max(86400, $this->wait_timeout);
        DCTWPSollers_Migration_Database::query('SET SESSION wait_timeout = ' . $timeout);
        DCTWPSollers_Migration_Database::query('SELECT CONNECTION_ID() AS connection_id, @@SESSION.wait_timeout AS wait_timeout');
        $session = $wpdb->last_result[0];

        if ((int) $session->connection_id !== $this->connection_id || (int) $session->wait_timeout < $timeout)
        {
            throw new RuntimeException('No se ha podido ampliar el timeout de la sesión SQL original de migración.');
        }

        $this->lock(true);
        $this->locked = true;
        $this->assert_locked();
        $base = sys_get_temp_dir();
        $this->directory = self::temporary($base);

        return $this->directory;
    }

    ################################################
    #
    #  assert_locked
    #
    #  Detecta pérdida del bloqueo o reconexión silenciosa antes de publicar cambios.
    #
    ################################################
    public function assert_locked(): void
    {
        global $wpdb;

        if (!$this->locked)
        {
            throw new RuntimeException('La operación no mantiene un bloqueo SQL de migración.');
        }

        DCTWPSollers_Migration_Database::query($wpdb->prepare('SELECT CONNECTION_ID() AS connection_id, IS_USED_LOCK(%s) AS owner', $this->lock_key()));
        $session = $wpdb->last_result[0];

        if ((int) $session->connection_id !== $this->connection_id || (int) $session->owner !== $this->connection_id)
        {
            throw new RuntimeException('Se ha perdido la conexión SQL original o el bloqueo de migración; se detiene la operación sin recuperar el bloqueo automáticamente.');
        }
    }

    ################################################
    #
    #  archive
    #
    #  Prepara un ZIP privado en el sistema de archivos del destino para publicarlo sin sobrescribir.
    #
    ################################################
    public function archive(string $output): string
    {
        if ($this->archive_directory !== null)
        {
            throw new RuntimeException('La operación ya tiene un ZIP temporal.');
        }

        $this->archive_directory = self::temporary(dirname($output));

        return $this->archive_directory . '/package.zip';
    }

    ################################################
    #
    #  database
    #
    #  Centraliza la instancia propietaria de las tablas de preparación.
    #
    ################################################
    public function database(): DCTWPSollers_Migration_Database
    {
        $this->database ??= new DCTWPSollers_Migration_Database();

        return $this->database;
    }

    ################################################
    #
    #  finish
    #
    #  Intenta todas las limpiezas por separado y conserva el error original si lo hubo.
    #
    ################################################
    public function finish(?string $message): ?string
    {
        $actions = array();
        $actions['tablas temporales'] = fn() => $this->database?->cleanup();
        $actions['ZIP temporal'] = fn() => self::cleanup_directory($this->archive_directory);
        $actions['directorio de trabajo'] = fn() => self::cleanup_directory($this->directory);
        $actions['bloqueo SQL'] = fn() => $this->locked ? $this->lock(false) : null;
        $actions['timeout de sesión SQL'] = fn() => $this->restore_timeout();

        foreach ($actions as $resource => $action)
        {
            try
            {
                $action();
            }
            catch (Throwable $error)
            {
                $detail = 'Limpieza incompleta (' . $resource . '): ';
                $detail .= $error instanceof RuntimeException ? $error->getMessage() : 'Fallo interno al limpiar el recurso.';
                WP_CLI::warning($detail);
                $message ??= $detail;
            }
        }

        return $message;
    }

    ################################################
    #
    #  cleanup_directory
    #
    #  Permite reintentar la limpieza sin repetir recursos ya eliminados.
    #
    ################################################
    private static function cleanup_directory(?string &$directory): void
    {
        if ($directory !== null)
        {
            DCTWPSollers_Migration_Files::remove_tree($directory);
            $directory = null;
        }
    }

    ################################################
    #
    #  temporary
    #
    #  Crea directorios privados independientes de la umask sin usar rutas del entorno de desarrollo.
    #
    ################################################
    private static function temporary(string $base): string
    {
        $directory = rtrim($base, '/') . '/dctwpsollers-' . bin2hex(random_bytes(16));

        // Create a new local private directory atomically; no recursive/remote filesystem fallback.
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
        if (!mkdir($directory, 0700))
        {
            throw new RuntimeException('No se puede crear el temporal privado.');
        }

        // Enforce the exact private permissions independently of umask.
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod
        if (!chmod($directory, 0700))
        {
            // This operation just created the directory; remove it only if still empty.
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
            rmdir($directory);
            throw new RuntimeException('No se pueden fijar los permisos del temporal privado.');
        }

        return $directory;
    }

    ################################################
    #
    #  lock
    #
    #  Mantiene el bloqueo de la instalación hasta finalizar todas sus limpiezas.
    #
    ################################################
    private function lock(bool $acquire): void
    {
        global $wpdb;

        if (!$acquire)
        {
            try
            {
                $this->assert_locked();
            }
            catch (RuntimeException $error)
            {
                $this->locked = false;
                throw $error;
            }
        }

        $sql = $acquire
            ? $wpdb->prepare('SELECT GET_LOCK(%s, 0)', $this->lock_key())
            : $wpdb->prepare('SELECT RELEASE_LOCK(%s)', $this->lock_key());
        DCTWPSollers_Migration_Database::query($sql);
        $row = (array) $wpdb->last_result[0];

        if ((int) reset($row) !== 1)
        {
            throw new RuntimeException($acquire
                ? 'Ya hay una migración en curso o no se puede adquirir el bloqueo SQL del sitio.'
                : 'No se ha podido liberar el bloqueo SQL de migración; la conexión puede haberse perdido.');
        }

        if (!$acquire)
        {
            $this->locked = false;
        }
    }

    ################################################
    #
    #  lock_key
    #
    #  Comparte la identidad del bloqueo entre adquisición, verificación y liberación.
    #
    ################################################
    private function lock_key(): string
    {
        global $wpdb;

        return 'dctwpsollers-migration-' . substr(hash('sha256', DB_NAME . ':' . $wpdb->prefix), 0, 40);
    }

    ################################################
    #
    #  restore_timeout
    #
    #  Restaura el timeout anterior únicamente si sigue viva la sesión original.
    #
    ################################################
    private function restore_timeout(): void
    {
        global $wpdb;

        if ($this->wait_timeout === null)
        {
            return;
        }

        DCTWPSollers_Migration_Database::query('SELECT CONNECTION_ID() AS connection_id');

        if ((int) $wpdb->last_result[0]->connection_id !== $this->connection_id)
        {
            $this->wait_timeout = null;
            throw new RuntimeException('La sesión SQL original se ha perdido; no se modifica el timeout de la nueva conexión.');
        }

        DCTWPSollers_Migration_Database::query('SET SESSION wait_timeout = ' . $this->wait_timeout);
        $this->wait_timeout = null;
    }

}
