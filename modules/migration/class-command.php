<?php

defined('ABSPATH') || exit;

final class DCTWPHero_Migration_Command
{
    ################################################
    #
    #  export
    #
    #  Exporta base de datos y wp-content a un ZIP privado sin sobrescribir archivos.
    #
    ################################################
    /**
     * Exporta el sitio a un ZIP propio de DCT WP Hero.
     *
     * ## OPTIONS
     *
     * --file=<path>
     * : Ruta absoluta del ZIP, fuera de WordPress y del directorio público.
     *
     * [--exclude=<paths>]
     * : Rutas relativas a wp-content, separadas por comas; sin patrones glob.
     *
     * [--exclude-tables=<tables>]
     * : Nombres completos de tablas a excluir, separados por comas.
     */
    public static function export(array $args, array $options): void
    {
        $operation = new DCTWPHero_Migration_Operation();

        try
        {
            $allowed = array
            (
                'file',
                'exclude',
                'exclude-tables',

            );
            self::arguments($args, $options, $allowed);
            DCTWPHero_Migration_Package::environment();
            $output = self::output($options['file']);
            $excluded = array_values(array_unique(array_merge(DCTWPHero_Migration_Files::exclusions(), self::list($options['exclude'] ?? ''))));

            foreach ($excluded as $path)
            {
                DCTWPHero_Migration_Files::path($path);
            }

            $tables = self::list($options['exclude-tables'] ?? '');
            $directory = $operation->start();
            $temporary = $operation->archive($output);
            WP_CLI::warning('Evita escrituras durante la exportación. La copia no es atómica entre archivos y base de datos.');
            DCTWPHero_Migration_Package::create($directory, $temporary, $excluded, $tables);
            $operation->assert_locked();
            $validation = $directory . '/verification';

            DCTWPHero_Migration_Files::directory($validation, 0700);

            DCTWPHero_Migration_Package::extract($temporary, $validation);
            $operation->assert_locked();

            // Publish a verified private ZIP without ever replacing an existing target; link() has no overwrite fallback.
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod
            if (!chmod($temporary, 0600) || !link($temporary, $output))
            {
                throw new RuntimeException('No se puede publicar el ZIP sin sobrescribir el destino.');
            }

            $success = 'Exportación verificada: ' . $output;
        }
        catch (Throwable $error)
        {
            $message = $error->getMessage();
        }
        finally
        {
            $message = $operation->finish($message ?? null);
        }

        if (isset($message))
        {
            if (isset($success))
            {
                $message .= ' El ZIP ya se ha publicado y verificado en ' . $output . '; consérvalo y revisa la limpieza pendiente.';
            }

            WP_CLI::error(self::terminal($message));
        }

        WP_CLI::success(self::terminal($success));
    }

    ################################################
    #
    #  import
    #
    #  Valida e importa un paquete confiable con modo obligatorio merge o mirror.
    #
    ################################################
    /**
     * Importa un ZIP confiable; no crea copia previa ni garantiza rollback.
     *
     * ## OPTIONS
     *
     * --file=<path>
     * : Ruta absoluta de un ZIP propio de DCT WP Hero.
     *
     * --mode=<mode>
     * : merge conserva archivos y tablas extra; mirror elimina extras no excluidos.
     * ---
     * options:
     *   - merge
     *   - mirror
     * ---
     *
     * [--dry-run]
     * : Valida integridad, destinos y datos sin cambiar archivos ni tablas del sitio.
     *
     * [--yes]
     * : Confirma la operación destructiva sin preguntar.
     *
     * [--home-url=<url>]
     * : URL pública final. Por defecto conserva la del destino.
     *
     * [--site-url=<url>]
     * : URL de WordPress final. Por defecto conserva la del destino.
     */
    public static function import(array $args, array $options): void
    {
        global $wpdb, $wp_version;
        $maintenance = false;
        $operation = new DCTWPHero_Migration_Operation();
        $database = $operation->database();

        try
        {
            $allowed = array
            (
                'file',
                'mode',
                'dry-run',
                'yes',
                'home-url',
                'site-url',

            );
            self::arguments($args, $options, $allowed);
            DCTWPHero_Migration_Package::environment();

            if (($options['mode'] ?? null) !== 'merge' && ($options['mode'] ?? null) !== 'mirror')
            {
                throw new RuntimeException('Debes indicar --mode=merge o --mode=mirror.');
            }

            $file = realpath($options['file']);

            if ($file === false || !is_file($file) || !is_readable($file))
            {
                throw new RuntimeException('El paquete no es un archivo legible.');
            }

            if (file_exists(ABSPATH . '.maintenance') || is_link(ABSPATH . '.maintenance'))
            {
                throw new RuntimeException('El sitio ya está en mantenimiento. No se modificará ese estado.');
            }

            if (!isset($options['dry-run']))
            {
                DCTWPHero_Migration_Process::isolate($options);
            }

            $target = DCTWPHero_Migration_Package::context();
            $original_site = $target['site'];
            $original_home = $target['home'];
            $target['home'] = DCTWPHero_Migration_Values::url($options['home-url'] ?? $target['home']);
            $target['site'] = DCTWPHero_Migration_Values::url($options['site-url'] ?? $target['site']);
            $original_urls = array();
            $original_urls[$original_site] = $target['site'];
            $original_urls[$original_home] ??= $target['home'];
            $target['uploads_url'] = strtr($target['uploads_url'], $original_urls);

            $constants = array();
            $constants['WP_HOME'] = 'home';
            $constants['WP_SITEURL'] = 'site';

            foreach ($constants as $constant => $key)
            {
                if (defined($constant) && untrailingslashit(constant($constant)) !== $target[$key])
                {
                    throw new RuntimeException('La URL solicitada entra en conflicto con ' . $constant . '.');
                }
            }

            $directory = $operation->start();
            WP_CLI::log('Validando ZIP, inventario e integridad…');
            $manifest = DCTWPHero_Migration_Package::extract($file, $directory);

            if (version_compare($manifest['context']['wordpress'], $wp_version, '>'))
            {
                throw new RuntimeException('Actualiza WordPress del destino antes de importar una versión posterior.');
            }

            if (version_compare($manifest['context']['php'], PHP_VERSION, '>'))
            {
                WP_CLI::warning('El origen usa una versión de PHP posterior. Comprueba la compatibilidad de sus plugins.');
            }

            $map = DCTWPHero_Migration_Values::map($manifest['context'], $target);
            $existing = DCTWPHero_Migration_Database::tables();

            foreach ($existing as $table)
            {
                DCTWPHero_Migration_Database::schema($table);
            }

            $excluded = array_unique(array_merge($manifest['excluded_paths'], DCTWPHero_Migration_Files::exclusions()));
            $files = DCTWPHero_Migration_Files::plan(WP_CONTENT_DIR, $manifest['entries'], $excluded, $options['mode']);

            if (!wp_is_writable(ABSPATH))
            {
                throw new RuntimeException('No se puede activar mantenimiento en el destino.');
            }

            $database->prepare($directory, $manifest, $map, true);
            WP_CLI::log('Tablas incluidas: ' . count($manifest['tables']));
            WP_CLI::log('Archivos a sustituir/crear: ' . count($files['write']));
            WP_CLI::log('Archivos extra a eliminar: ' . count($files['delete']));
            WP_CLI::log('Directorios extra a eliminar tras vaciarlos: ' . count($files['directories']));
            $included_tables = array_map(static fn(string $suffix): string => $wpdb->prefix . $suffix, array_keys($manifest['tables']));
            $extra_tables = DCTWPHero_Migration_Database::extra_tables($existing, $included_tables, $manifest['excluded_tables'], $options['mode']);
            DCTWPHero_Migration_Database::foreign_boundary(array_merge($included_tables, $extra_tables));
            WP_CLI::log('Tablas extra a eliminar: ' . count($extra_tables));
            WP_CLI::log('Modo: ' . $options['mode'] . '; home: ' . $target['home'] . '; site: ' . $target['site']);

            if (isset($options['dry-run']))
            {
                $success = 'Validación completada. No se han modificado archivos ni tablas del sitio.';
            }
            else
            {
                self::confirm($options);
                $operation->assert_locked();
                // Exclusively create WordPress maintenance at its required core path, never an uploads/data file.
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
                $handle = fopen(ABSPATH . '.maintenance', 'x');

                if ($handle === false)
                {
                    throw new RuntimeException('No se puede activar mantenimiento.');
                }

                $maintenance = true;
                $content = '<?php $upgrading = time();' . "\n";
                // Write through the exclusive handle so another maintenance file cannot be overwritten.
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite, PluginCheck.CodeAnalysis.WriteFile.ABSPATHDetected
                $written = fwrite($handle, $content);
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
                fclose($handle);

                if ($written !== strlen($content))
                {
                    throw new RuntimeException('No se puede escribir mantenimiento.');
                }

                WP_CLI::log('Preparando tablas y adaptando URLs y rutas…');
                $database->prepare($directory, $manifest, $map, false);
                $operation->assert_locked();
                WP_CLI::log('Restaurando archivos…');

                foreach ($files['write'] as $path)
                {
                    $metadata = $manifest['entries']['content/' . $path];
                    DCTWPHero_Migration_Files::copy(
                        $directory . '/content/' . $path,
                        WP_CONTENT_DIR,
                        $path,
                        $metadata['mode'] ?? 0644,
                        $metadata['mtime'] ?? null
                    );
                }

                foreach ($files['delete'] as $path)
                {
                    $destination = DCTWPHero_Migration_Files::destination(WP_CONTENT_DIR, $path);

                    // Validated mirror target: detect deletion failure, without wp_delete_file() filters changing the path.
                    // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
                    if (!unlink($destination))
                    {
                        throw new RuntimeException('No se puede eliminar el archivo extra: ' . $path);
                    }
                }

                foreach ($files['directories'] as $path)
                {
                    DCTWPHero_Migration_Files::remove_directory(WP_CONTENT_DIR, $path);
                }

                $operation->assert_locked();
                $database->publish($existing, $manifest['excluded_tables'], $options['mode']);
                self::finalize($target);
                $operation->assert_locked();

                // Remove only the maintenance file created by this import; filtering its path is unsafe here.
                // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
                if (!unlink(ABSPATH . '.maintenance'))
                {
                    throw new RuntimeException('Restauración completada, pero no se ha podido retirar mantenimiento.');
                }

                $maintenance = false;
                $success = 'Importación completada y verificada en un proceso nuevo.';
            }
        }
        catch (Throwable $error)
        {
            $message = $error->getMessage();

            if ($maintenance)
            {
                $message .= ' El sitio queda en mantenimiento. No hay rollback ni copia automática: revisa el estado parcial antes de retirar .maintenance.';
            }
        }
        finally
        {
            $message = $operation->finish($message ?? null);
        }

        if (isset($message))
        {
            WP_CLI::error(self::terminal($message));
        }

        WP_CLI::success(self::terminal($success));
    }

    ################################################
    #
    #  arguments
    #
    #  Rechaza opciones desconocidas, operandos y rutas no absolutas.
    #
    ################################################
    private static function arguments(array $args, array $options, array $allowed): void
    {
        if ($args !== array() || array_diff(array_keys($options), $allowed) !== array())
        {
            throw new RuntimeException('Argumentos no reconocidos. Consulta wp help dctwphero export/import.');
        }

        if (!is_string($options['file'] ?? null) || !str_starts_with($options['file'], '/') || str_contains($options['file'], "\0"))
        {
            throw new RuntimeException('--file requiere una ruta absoluta.');
        }

        foreach ($options as $name => $value)
        {
            if ($name === 'yes' || $name === 'dry-run')
            {
                if ($value !== true)
                {
                    // CLI-only diagnostic; terminal() escapes controls at the output boundary.
                    // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
                    throw new RuntimeException('--' . $name . ' no acepta valor.');
                }
            }
            elseif (!is_string($value) || $value === '')
            {
                // CLI-only diagnostic; terminal() escapes controls at the output boundary.
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
                throw new RuntimeException('--' . $name . ' necesita un valor.');
            }
        }
    }

    ################################################
    #
    #  list
    #
    #  Normaliza listas explícitas de rutas o tablas separadas por comas.
    #
    ################################################
    private static function list(string $value): array
    {
        if ($value === '')
        {
            return array();
        }

        $items = array_map('trim', explode(',', $value));

        if (in_array('', $items, true))
        {
            throw new RuntimeException('La lista contiene elementos vacíos.');
        }

        return array_values(array_unique($items));
    }

    ################################################
    #
    #  output
    #
    #  Exige un ZIP nuevo fuera de las rutas públicas conocidas del sitio.
    #
    ################################################
    private static function output(string $file): string
    {
        $parent = realpath(dirname($file));

        if ($parent === false || !wp_is_writable($parent) || strtolower(pathinfo($file, PATHINFO_EXTENSION)) !== 'zip')
        {
            throw new RuntimeException('El destino debe ser un ZIP en un directorio existente y escribible.');
        }

        $document_root = $_SERVER['DOCUMENT_ROOT'] ?? ABSPATH;

        if (!is_string($document_root))
        {
            throw new RuntimeException('DOCUMENT_ROOT debe ser una cadena cuando esté definido.');
        }

        foreach (array(ABSPATH, WP_CONTENT_DIR, $document_root) as $public)
        {
            $root = realpath($public);

            if ($root !== false && ($parent === $root || str_starts_with($parent . '/', rtrim($root, '/') . '/')))
            {
                throw new RuntimeException('Guarda el ZIP fuera del directorio público del sitio.');
            }
        }

        $target = $parent . '/' . basename($file);

        if (file_exists($target) || is_link($target))
        {
            throw new RuntimeException('El archivo de destino ya existe; no se sobrescribirá.');
        }

        return $target;
    }

    ################################################
    #
    #  confirm
    #
    #  Exige confirmación explícita sin interrumpir la limpieza de recursos al cancelar.
    #
    ################################################
    private static function confirm(array $options): void
    {
        WP_CLI::warning('Se sobrescribirán archivos, tablas, usuarios y contraseñas. NO se creará copia previa ni habrá rollback. Importa solo paquetes de confianza.');

        if (!isset($options['yes']))
        {
            // WP_CLI::confirm() exits on rejection; a native prompt lets our finally blocks clean up before failing.
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
            fwrite(STDERR, '¿Continuar? [y/N] ');
            $answer = fgets(STDIN);

            if ($answer === false || strtolower(trim($answer)) !== 'y')
            {
                throw new RuntimeException('Importación cancelada sin modificar el sitio.');
            }
        }
    }

    ################################################
    #
    #  finalize
    #
    #  Conserva el migrador activo, fija URLs y verifica WordPress en un proceso nuevo.
    #
    ################################################
    private static function finalize(array $target): void
    {
        $command = 'eval-file ' . escapeshellarg(__DIR__ . '/finalize.php');
        $command .= ' ' . escapeshellarg($target['home']) . ' ' . escapeshellarg($target['site']);
        self::subcommand($command, true);
        self::subcommand('core update-db', true);
        self::subcommand('rewrite flush', false);
        self::subcommand('core is-installed', false);
        self::subcommand('eval-file ' . escapeshellarg(__DIR__ . '/verify.php'), true);
        self::subcommand('eval-file ' . escapeshellarg(__DIR__ . '/flush-cache.php'), false, true);
    }

    ################################################
    #
    #  subcommand
    #
    #  Arranca verificaciones sin heredar el --require ni los skips del trabajador aislado.
    #
    ################################################
    private static function subcommand(string $command, bool $skip_plugins, bool $show_output = false): void
    {
        $flags = array();
        $flags['path'] = ABSPATH;
        $flags['skip-packages'] = true;

        if ($skip_plugins)
        {
            $flags['skip-plugins'] = true;
            $flags['skip-themes'] = true;
        }

        $result = WP_CLI::launch_self($command, array(), $flags, false, true);

        if ($result->return_code !== 0)
        {
            // CLI-only diagnostic; terminal() escapes controls before the exception is printed.
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
            throw new RuntimeException('Falló la comprobación posterior: ' . $command . '. Revisa compatibilidad y permisos del destino.');
        }

        if ($show_output && trim($result->stdout) !== '')
        {
            WP_CLI::log(trim($result->stdout));
        }

        WP_CLI::log(self::terminal('Verificado: ' . $command));
    }

    ################################################
    #
    #  terminal
    #
    #  Hace visibles los controles de diagnósticos CLI sin aplicar escape HTML al terminal.
    #
    ################################################
    private static function terminal(string $message): string
    {
        return preg_replace_callback('/[\x00-\x1f\x7f]|\xC2[\x80-\x9f]/', static function (array $match): string
        {
            $escaped = '';

            foreach (str_split($match[0]) as $byte)
            {
                $escaped .= sprintf('\\x%02X', ord($byte));
            }

            return $escaped;
        }, $message);
    }

}
