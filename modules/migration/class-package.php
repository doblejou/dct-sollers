<?php

defined('ABSPATH') || exit;

final class DCTWPHero_Migration_Package
{
    private const REQUIRED_TABLES = array
    (
        'options',
        'users',
        'usermeta',

    );

    ################################################
    #
    #  context
    #
    #  Describe el entorno sin credenciales ni configuración privada del servidor.
    #
    ################################################
    public static function context(): array
    {
        global $wpdb, $wp_version;
        $context = array();
        $context['home'] = untrailingslashit(home_url());
        $context['site'] = untrailingslashit(site_url());
        $context['stored_home'] = $wpdb->get_var($wpdb->prepare('SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, 'home'));
        $context['stored_site'] = $wpdb->get_var($wpdb->prepare('SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, 'siteurl'));
        $context['root'] = untrailingslashit(ABSPATH);
        $context['content'] = untrailingslashit(WP_CONTENT_DIR);
        $context['uploads'] = untrailingslashit(wp_get_upload_dir()['basedir']);
        $context['uploads_url'] = untrailingslashit(wp_get_upload_dir()['baseurl']);
        $context['wordpress'] = $wp_version;
        $context['php'] = PHP_VERSION;
        $context['database'] = $wpdb->db_version();

        return $context;
    }

    ################################################
    #
    #  environment
    #
    #  Rechaza plataformas o distribuciones de contenido no soportadas por el formato inicial.
    #
    ################################################
    public static function environment(): void
    {
        global $wpdb;
        DCTWPHero_Migration_Database::identifier($wpdb->prefix);
        if (is_multisite() || PHP_INT_SIZE < 8 || !class_exists('ZipArchive') || !extension_loaded('mysqli'))
        {
            throw new RuntimeException('Se requiere WordPress individual, PHP de 64 bits, mysqli y ZipArchive.');
        }

        $root = realpath(WP_CONTENT_DIR);
        $uploads = wp_get_upload_dir();

        if ($root === false || is_link(WP_CONTENT_DIR)
            || wp_normalize_path(WP_PLUGIN_DIR) !== wp_normalize_path(WP_CONTENT_DIR . '/plugins')
            || wp_normalize_path(get_theme_root()) !== wp_normalize_path(WP_CONTENT_DIR . '/themes')
            || wp_normalize_path($uploads['basedir']) !== wp_normalize_path(WP_CONTENT_DIR . '/uploads')
            || (defined('WPMU_PLUGIN_DIR') && !(defined('DCTWPHERO_MIGRATION_ISOLATED') && DCTWPHERO_MIGRATION_ISOLATED)
                && wp_normalize_path(WPMU_PLUGIN_DIR) !== wp_normalize_path(WP_CONTENT_DIR . '/mu-plugins')))
        {
            throw new RuntimeException('Esta versión requiere plugins, temas, mu-plugins y uploads en sus rutas estándar de wp-content.');
        }
    }

    ################################################
    #
    #  create
    #
    #  Empaqueta contenido y tablas preparados junto con su inventario SHA-256.
    #
    ################################################
    public static function create(string $directory, string $output, array $excluded, array $excluded_tables): void
    {
        global $wpdb;

        foreach (self::REQUIRED_TABLES as $required)
        {
            if (in_array($wpdb->prefix . $required, $excluded_tables, true))
            {
                throw new RuntimeException('No se pueden excluir options, users ni usermeta.');
            }
        }

        DCTWPHero_Migration_Files::directory($directory . '/database', 0700);

        $manifest = array();
        $manifest['format'] = 'dctwphero';
        $manifest['version'] = 1;
        $manifest['created'] = gmdate('c');
        $manifest['context'] = self::context();
        $manifest['prefix'] = $wpdb->prefix;
        $manifest['excluded_paths'] = $excluded;
        $manifest['excluded_tables'] = array_map(static fn(string $table): string => substr($table, strlen($wpdb->prefix)), $excluded_tables);
        $manifest['tables'] = DCTWPHero_Migration_Database::export($directory, $excluded_tables);
        $manifest['entries'] = array();
        $zip = new ZipArchive();

        if ($zip->open($output, ZipArchive::CREATE | ZipArchive::EXCL) !== true)
        {
            throw new RuntimeException('No se puede crear el ZIP temporal.');
        }

        try
        {
            foreach ($manifest['tables'] as $entry)
            {
                self::add($zip, $directory . '/' . $entry, $entry, $manifest);
            }

            WP_CLI::log('Preparando archivos de wp-content…');

            foreach (DCTWPHero_Migration_Files::inventory(WP_CONTENT_DIR, $excluded) as $path => $source)
            {
                $entry = 'content/' . $path;
                $prepared = $directory . '/' . $entry;
                $parent = dirname($prepared);

                if (filesize($source) > disk_free_space($directory))
                {
                    throw new RuntimeException('Espacio insuficiente para preparar los archivos de exportación.');
                }

                DCTWPHero_Migration_Files::directory($parent, 0700);

                if (!copy($source, $prepared))
                {
                    throw new RuntimeException('No se puede preparar el archivo: ' . $path);
                }

                self::add($zip, $prepared, $entry, $manifest, $source);
            }

            $estimate = array_sum(array_column($manifest['entries'], 'size')) * 1.01 + 1024 * 1024;

            if ($estimate > disk_free_space(dirname($output)))
            {
                throw new RuntimeException('Espacio insuficiente para el ZIP según la estimación conservadora sin compresión.');
            }

            if (!$zip->addFromString('manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)))
            {
                throw new RuntimeException('No se puede escribir el manifiesto.');
            }
        }
        catch (Throwable $error)
        {
            $zip->close();
            throw $error;
        }

        if (!$zip->close())
        {
            throw new RuntimeException('No se ha podido finalizar el ZIP. Comprueba el espacio libre.');
        }
    }

    ################################################
    #
    #  add
    #
    #  Añade un archivo al ZIP y registra su tamaño y resumen criptográfico.
    #
    ################################################
    private static function add(ZipArchive $zip, string $file, string $entry, array &$manifest, ?string $original = null): void
    {
        $metadata = array();
        $metadata['size'] = filesize($file);
        $metadata['sha256'] = hash_file('sha256', $file);
        $metadata['mode'] = DCTWPHero_Migration_Files::mode(fileperms($original ?? $file) & 0755);
        $metadata['mtime'] = filemtime($original ?? $file);

        if ($metadata['size'] === false || $metadata['sha256'] === false || !$zip->addFile($file, $entry))
        {
            throw new RuntimeException('No se puede añadir un archivo al ZIP.');
        }

        $manifest['entries'][$entry] = $metadata;
    }

    ################################################
    #
    #  extract
    #
    #  Valida el contrato ZIP y extrae por bloques a un directorio privado, nunca al sitio.
    #
    ################################################
    public static function extract(string $file, string $directory): array
    {
        $zip = new ZipArchive();

        if ($zip->open($file, ZipArchive::RDONLY | ZipArchive::CHECKCONS) !== true)
        {
            throw new RuntimeException('ZIP ilegible, corrupto o no compatible.');
        }

        try
        {
            $stat = $zip->statName('manifest.json');

            if ($stat === false || $stat['size'] > 64 * 1024 * 1024)
            {
                throw new RuntimeException('Manifiesto ausente o demasiado grande para validarlo con seguridad.');
            }

            $manifest = json_decode($zip->getFromName('manifest.json'), true, 512, JSON_THROW_ON_ERROR);
            self::validate($manifest);
            $seen = array();
            $total = 0;

            for ($index = 0; $index < $zip->numFiles; $index++)
            {
                $stat = $zip->statIndex($index);
                $entry = DCTWPHero_Migration_Files::path($stat['name']);
                $folded = strtolower($entry);
                $system = 0;
                $attributes = 0;
                $zip->getExternalAttributesIndex($index, $system, $attributes);
                $type = ($attributes >> 16) & 0170000;

                if (isset($seen[$folded]) || ($type !== 0 && $type !== 0100000)
                    || ($stat['encryption_method'] ?? 0) !== 0)
                {
                    throw new RuntimeException('ZIP con duplicados, enlaces, archivos especiales o cifrado.');
                }

                $seen[$folded] = true;

                if ($entry === 'manifest.json')
                {
                    continue;
                }

                if (!isset($manifest['entries'][$entry]) || $manifest['entries'][$entry]['size'] !== $stat['size'])
                {
                    throw new RuntimeException('Entrada ZIP inesperada o tamaño incorrecto: ' . $entry);
                }

                $total += $stat['size'];
            }

            if (count($seen) !== count($manifest['entries']) + 1 || $total > disk_free_space($directory))
            {
                throw new RuntimeException('Faltan entradas ZIP o no hay espacio suficiente para prepararlas.');
            }

            foreach ($manifest['entries'] as $entry => $metadata)
            {
                $target = DCTWPHero_Migration_Files::destination($directory, $entry);
                $parent = dirname($target);

                DCTWPHero_Migration_Files::directory($parent, 0700);

                $source = $zip->getStream($entry);
                // Exclusive local stream in validated private staging; extraction stays bounded by the manifest size.
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
                $output = fopen($target, 'xb');

                if ($source === false || $output === false)
                {
                    if (is_resource($source))
                    {
                        // Release the native ZIP stream after a failed destination open.
                        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
                        fclose($source);
                    }

                    if (is_resource($output))
                    {
                        // Release the exclusive local output stream after a failed ZIP open.
                        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
                        fclose($output);
                    }

                    throw new RuntimeException('No se puede preparar una entrada ZIP.');
                }

                try
                {
                    $written = stream_copy_to_stream($source, $output, $metadata['size'] + 1);
                }
                finally
                {
                    // Release both streaming handles; WP_Filesystem has no equivalent resource-based streaming API.
                    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
                    fclose($source);
                    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
                    fclose($output);
                }

                if ($written !== $metadata['size'] || hash_file('sha256', $target) !== $metadata['sha256'])
                {
                    throw new RuntimeException('Integridad incorrecta: ' . $entry);
                }
            }

            return $manifest;
        }
        finally
        {
            $zip->close();
        }
    }

    ################################################
    #
    #  validate
    #
    #  Comprueba tipos, correspondencias y exclusiones antes de confiar en el manifiesto.
    #
    ################################################
    public static function validate(mixed $manifest): void
    {
        if (!is_array($manifest) || ($manifest['format'] ?? null) !== 'dctwphero' || ($manifest['version'] ?? null) !== 1)
        {
            throw new RuntimeException('Formato de paquete no compatible.');
        }

        $fields = array
        (
            'context',
            'tables',
            'entries',
            'excluded_paths',
            'excluded_tables',

        );

        foreach ($fields as $field)
        {
            if (!is_array($manifest[$field] ?? null))
            {
                // CLI-only diagnostic; Command::terminal() escapes controls before output.
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
                throw new RuntimeException('Campo de manifiesto inválido: ' . $field);
            }
        }

        if (!is_string($manifest['prefix'] ?? null) || $manifest['prefix'] === '')
        {
            throw new RuntimeException('Prefijo de origen inválido.');
        }

        DCTWPHero_Migration_Database::identifier($manifest['prefix']);

        $keys = array
        (
            'home',
            'site',
            'root',
            'content',
            'uploads',
            'uploads_url',
            'wordpress',
            'php',

        );

        foreach ($keys as $key)
        {
            if (!is_string($manifest['context'][$key] ?? null) || $manifest['context'][$key] === '')
            {
                throw new RuntimeException('Contexto de origen incompleto.');
            }
        }

        foreach ($manifest['context'] as $key => $value)
        {
            if (!is_string($value) || str_contains($value, "\0"))
            {
                throw new RuntimeException('Valor de contexto inválido.');
            }
        }

        foreach (array('wordpress', 'php') as $key)
        {
            if (!preg_match('/^\d+\.\d+(?:[.\w+-]*)$/D', $manifest['context'][$key]))
            {
                throw new RuntimeException('Versión de origen inválida.');
            }
        }

        foreach (array('home', 'site', 'uploads_url', 'stored_home', 'stored_site') as $key)
        {
            if (isset($manifest['context'][$key]) && $manifest['context'][$key] !== '')
            {
                DCTWPHero_Migration_Values::url($manifest['context'][$key]);
            }
        }

        foreach (array('root', 'content', 'uploads') as $key)
        {
            if (!str_starts_with($manifest['context'][$key], '/'))
            {
                throw new RuntimeException('Ruta absoluta de origen inválida.');
            }
        }

        if (!array_is_list($manifest['excluded_paths']) || !array_is_list($manifest['excluded_tables']))
        {
            throw new RuntimeException('Las exclusiones deben ser listas.');
        }

        foreach ($manifest['excluded_paths'] as $path)
        {
            if (!is_string($path))
            {
                throw new RuntimeException('Exclusión de ruta inválida.');
            }

            DCTWPHero_Migration_Files::path($path);
        }

        foreach ($manifest['excluded_tables'] as $suffix)
        {
            if (!is_string($suffix))
            {
                throw new RuntimeException('Exclusión de tabla inválida.');
            }

            DCTWPHero_Migration_Database::identifier($suffix);
        }

        foreach (self::REQUIRED_TABLES as $required)
        {
            if (!isset($manifest['tables'][$required]))
            {
                throw new RuntimeException('El paquete requiere options, users y usermeta.');
            }
        }

        foreach ($manifest['tables'] as $suffix => $entry)
        {
            DCTWPHero_Migration_Database::identifier((string) $suffix);

            if ($entry !== 'database/' . $suffix . '.ndjson' || !isset($manifest['entries'][$entry])
                || in_array($suffix, $manifest['excluded_tables'], true))
            {
                throw new RuntimeException('Inventario de tablas incoherente.');
            }
        }

        foreach ($manifest['entries'] as $entry => $metadata)
        {
            if (!is_string($entry))
            {
                throw new RuntimeException('Nombre de entrada inválido.');
            }

            DCTWPHero_Migration_Files::path($entry);

            if (!is_array($metadata) || !is_int($metadata['size'] ?? null) || $metadata['size'] < 0
                || !is_string($metadata['sha256'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $metadata['sha256']))
            {
                throw new RuntimeException('Metadatos de archivo inválidos.');
            }

            if (array_key_exists('mode', $metadata))
            {
                DCTWPHero_Migration_Files::mode($metadata['mode']);
            }

            if (array_key_exists('mtime', $metadata) && !is_int($metadata['mtime']))
            {
                throw new RuntimeException('Fecha de archivo inválida.');
            }

            if (str_starts_with($entry, 'content/'))
            {
                if (DCTWPHero_Migration_Files::excluded(substr($entry, 8), $manifest['excluded_paths']))
                {
                    throw new RuntimeException('El paquete contiene una ruta declarada excluida.');
                }
            }
            elseif (!in_array($entry, $manifest['tables'], true))
            {
                throw new RuntimeException('Entrada de paquete no admitida.');
            }
        }
    }

}
