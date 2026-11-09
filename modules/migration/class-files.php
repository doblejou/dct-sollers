<?php

defined('ABSPATH') || exit;

final class DCTWPSollers_Migration_Files
{
    ################################################
    #
    #  path
    #
    #  Acepta únicamente nombres relativos canónicos, sin escapes ni rutas especiales.
    #
    ################################################
    public static function path(string $path): string
    {
        if ($path === '' || preg_match('/[\\\\\x00-\x1f\x7f:]/', $path) || str_starts_with($path, '/'))
        {
            // CLI-only diagnostic; Command::terminal() escapes controls before output.
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
            throw new RuntimeException('Ruta relativa inválida: ' . $path);
        }

        foreach (explode('/', $path) as $part)
        {
            if ($part === '' || $part === '.' || $part === '..')
            {
                // CLI-only diagnostic; Command::terminal() escapes controls before output.
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
                throw new RuntimeException('Ruta no canónica: ' . $path);
            }
        }

        return $path;
    }

    ################################################
    #
    #  exclusions
    #
    #  Protege cachés, copias, drop-ins de entorno y el plugin que ejecuta la migración.
    #
    ################################################
    public static function exclusions(): array
    {
        return array
        (
            'cache',
            'upgrade',
            'upgrade-temp-backup',
            'ai1wm-backups',
            'updraft',
            'backups',
            'debug.log',
            'db.php',
            'object-cache.php',
            'advanced-cache.php',
            'plugins/' . basename(dirname(__DIR__, 2)),

        );
    }

    ################################################
    #
    #  excluded
    #
    #  Comprueba exclusiones exactas o de subárbol, sin interpretar patrones glob.
    #
    ################################################
    public static function excluded(string $path, array $exclusions): bool
    {
        foreach ($exclusions as $excluded)
        {
            if ($path === $excluded || str_starts_with($path, $excluded . '/'))
            {
                return true;
            }
        }

        return false;
    }

    ################################################
    #
    #  inventory
    #
    #  Recorre archivos sin seguir enlaces ni entrar en directorios excluidos.
    #
    ################################################
    public static function inventory(string $root, array $exclusions, string $relative = ''): Generator
    {
        foreach (self::entries($root, $exclusions, $relative) as $path => $absolute)
        {
            if (is_file($absolute))
            {
                yield $path => $absolute;
            }
        }
    }

    ################################################
    #
    #  entries
    #
    #  Reutiliza el recorrido seguro para archivos y directorios, hijos antes que padres.
    #
    ################################################
    private static function entries(string $root, array $exclusions, string $relative = ''): Generator
    {
        $directory = $relative === '' ? $root : $root . '/' . $relative;
        $items = scandir($directory);

        if ($items === false)
        {
            throw new RuntimeException('No se puede leer un directorio del contenido.');
        }

        foreach ($items as $name)
        {
            if ($name === '.' || $name === '..')
            {
                continue;
            }

            $path = $relative === '' ? $name : $relative . '/' . $name;
            self::path($path);

            if (self::excluded($path, $exclusions))
            {
                continue;
            }

            $absolute = $root . '/' . $path;

            if (is_link($absolute))
            {
                // CLI-only diagnostic; Command::terminal() escapes controls before output.
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
                throw new RuntimeException('Enlace simbólico no admitido: ' . $path);
            }

            if (is_dir($absolute))
            {
                yield from self::entries($root, $exclusions, $path);
                yield $path => $absolute;
            }
            elseif (is_file($absolute) && is_readable($absolute))
            {
                yield $path => $absolute;
            }
            else
            {
                // CLI-only diagnostic; Command::terminal() escapes controls before output.
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
                throw new RuntimeException('Archivo especial o ilegible: ' . $path);
            }
        }
    }

    ################################################
    #
    #  destination
    #
    #  Resuelve un destino sin atravesar enlaces y comprueba conflictos de directorios.
    #
    ################################################
    public static function destination(string $root, string $path): string
    {
        return self::resolve($root, $path, false);
    }

    ################################################
    #
    #  resolve
    #
    #  Valida rutas de archivos o directorios sin atravesar enlaces y comprueba su padre.
    #
    ################################################
    private static function resolve(string $root, string $path, bool $directory): string
    {
        self::path($path);
        $current = rtrim($root, '/');
        $parts = explode('/', $path);
        $last = count($parts) - 1;

        foreach ($parts as $index => $part)
        {
            $current .= '/' . $part;

            if (is_link($current) || ($index < $last && file_exists($current) && !is_dir($current)))
            {
                // CLI-only diagnostic; Command::terminal() escapes controls before output.
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
                throw new RuntimeException('Conflicto o enlace en el destino: ' . $path);
            }

            if ($index === $last && (($directory && !is_dir($current)) || (!$directory && is_dir($current))))
            {
                // CLI-only diagnostic; Command::terminal() escapes controls before output.
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
                throw new RuntimeException('Tipo de destino inesperado: ' . $path);
            }
        }

        $parent = dirname($current);

        while (!file_exists($parent))
        {
            $parent = dirname($parent);
        }

        if (!wp_is_writable($parent))
        {
            // CLI-only diagnostic; Command::terminal() escapes controls before output.
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
            throw new RuntimeException('Destino sin permiso de escritura: ' . $path);
        }

        return $current;
    }

    ################################################
    #
    #  plan
    #
    #  Calcula y valida las escrituras y borrados permitidos antes de modificar el contenido.
    #
    ################################################
    public static function plan(string $root, array $entries, array $excluded, string $mode): array
    {
        $plan = array();
        $plan['write'] = array();
        $plan['delete'] = array();
        $plan['directories'] = array();
        $included = array();
        $keep_directories = array_fill_keys(array('plugins', 'themes', 'uploads', 'mu-plugins'), true);
        $bytes = 0;

        foreach ($entries as $entry => $metadata)
        {
            if (!str_starts_with($entry, 'content/'))
            {
                continue;
            }

            $path = substr($entry, 8);

            if (self::excluded($path, $excluded))
            {
                continue;
            }

            self::destination($root, $path);
            $included[$path] = true;
            self::keep_parents($path, $keep_directories);
            $plan['write'][] = $path;
            $bytes += $metadata['size'];
        }

        if ($bytes > disk_free_space($root))
        {
            throw new RuntimeException('Espacio insuficiente para los archivos del destino.');
        }

        if ($mode === 'mirror')
        {
            foreach ($excluded as $path)
            {
                self::keep_parents($path, $keep_directories);
            }

            foreach (self::entries($root, $excluded) as $path => $file)
            {
                if (is_dir($file))
                {
                    if (!isset($keep_directories[$path]))
                    {
                        self::resolve($root, $path, true);
                        $plan['directories'][] = $path;
                    }

                    continue;
                }

                if (!isset($included[$path]))
                {
                    self::destination($root, $path);

                    if (!wp_is_writable(dirname($file)))
                    {
                        // CLI-only diagnostic; Command::terminal() escapes controls before output.
                        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
                        throw new RuntimeException('No se puede eliminar el archivo extra: ' . $path);
                    }

                    $plan['delete'][] = $path;
                }
            }
        }

        return $plan;
    }

    ################################################
    #
    #  keep_parents
    #
    #  Conserva los padres de archivos importados y de exclusiones, incluso si están vacíos.
    #
    ################################################
    private static function keep_parents(string $path, array &$directories): void
    {
        for ($parent = dirname($path); $parent !== '.' && $parent !== ''; $parent = dirname($parent))
        {
            $directories[$parent] = true;
        }
    }

    ################################################
    #
    #  remove_directory
    #
    #  Elimina solo un directorio vacío validado; nunca borra recursivamente contenido inesperado.
    #
    ################################################
    public static function remove_directory(string $root, string $path): void
    {
        $directory = self::resolve($root, $path, true);

        // Native rmdir refuses non-empty directories; mirror must never fall back to recursive deletion.
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
        if (!rmdir($directory))
        {
            // CLI-only diagnostic; Command::terminal() escapes controls before output.
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
            throw new RuntimeException('No se puede eliminar un directorio extra o ha dejado de estar vacío: ' . $path);
        }
    }

    ################################################
    #
    #  copy
    #
    #  Instala un archivo preparado mediante temporal y rename en el mismo directorio.
    #
    ################################################
    public static function copy(string $source, string $root, string $path, int $mode = 0644, ?int $mtime = null): void
    {
        self::mode($mode);
        $target = self::destination($root, $path);
        $parent = dirname($target);

        self::directory($parent, 0755);

        $temporary = tempnam($parent, '.dctwpsollers-migration-');

        if ($temporary === false)
        {
            throw new RuntimeException('No se puede preparar el archivo de destino.');
        }

        try
        {
            // Local metadata and atomic same-directory rename must succeed before publishing; no remote/copy fallback.
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod
            if (!copy($source, $temporary) || !chmod($temporary, $mode)
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_touch, WordPress.WP.AlternativeFunctions.rename_rename
                || ($mtime !== null && !touch($temporary, $mtime)) || !rename($temporary, $target))
            {
                throw new RuntimeException('No se puede instalar el archivo: ' . $path);
            }
        }
        finally
        {
            if (is_file($temporary))
            {
                // Remove this operation's exact temporary file; wp_delete_file() filters could redirect or skip deletion.
                // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
                unlink($temporary);
            }
        }
    }

    ################################################
    #
    #  mode
    #
    #  Valida permisos restaurables sin bits especiales ni escritura para grupo u otros.
    #
    ################################################
    public static function mode(mixed $mode): int
    {
        if (!is_int($mode) || ($mode & ~0755) !== 0 || ($mode & 0400) === 0)
        {
            throw new RuntimeException('Permisos de archivo no admitidos.');
        }

        return $mode;
    }

    ################################################
    #
    #  directory
    #
    #  Crea padres con permisos explícitos sin cambiar los directorios ya existentes.
    #
    ################################################
    public static function directory(string $directory, int $mode): void
    {
        if (is_link($directory))
        {
            throw new RuntimeException('No se creará un directorio a través de un enlace.');
        }

        if (is_dir($directory))
        {
            return;
        }

        self::directory(dirname($directory), $mode);

        // Explicit modes are required for private staging directories, independent of umask.
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir, WordPress.WP.AlternativeFunctions.file_system_operations_chmod
        if (!mkdir($directory, $mode) || !chmod($directory, $mode))
        {
            throw new RuntimeException('No se puede crear un directorio con los permisos requeridos.');
        }
    }

    ################################################
    #
    #  remove_tree
    #
    #  Limpia exclusivamente el árbol temporal privado creado por esta ejecución.
    #
    ################################################
    public static function remove_tree(string $directory): void
    {
        if (is_link($directory))
        {
            throw new RuntimeException('No se limpiará un directorio temporal sustituido por un enlace.');
        }

        foreach (new FilesystemIterator($directory, FilesystemIterator::SKIP_DOTS) as $item)
        {
            if ($item->isDir() && !$item->isLink())
            {
                self::remove_tree($item->getPathname());
            }
            else
            {
                // Only the private temporary tree is cleaned; native deletion reports failure and cannot be redirected by filters.
                // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
                if (!unlink($item->getPathname()))
                {
                    throw new RuntimeException('No se puede limpiar un archivo temporal.');
                }
            }
        }

        // Refuse to remove a directory if unexpected contents appeared during cleanup.
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
        if (!rmdir($directory))
        {
            throw new RuntimeException('No se puede limpiar un directorio temporal.');
        }
    }

}
