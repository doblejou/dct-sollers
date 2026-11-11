<?php

defined('ABSPATH') || exit;

final class DCTWPSollers_Migration_Process
{
    ################################################
    #
    #  isolate
    #
    #  Reemplaza el proceso antes de borrar código para no ejecutar cierres de plugins antiguos.
    #
    ################################################
    public static function isolate(array $options): void
    {
        if (defined('DCTWPSOLLERS_MIGRATION_ISOLATED') && DCTWPSOLLERS_MIGRATION_ISOLATED)
        {
            self::assert_isolated();

            return;
        }

        $arguments = array
        (
            $GLOBALS['argv'][0],
            '--path=' . ABSPATH,
            '--skip-plugins',
            '--skip-themes',
            '--skip-packages',
            '--require=' . __DIR__ . '/import-bootstrap.php',
            'dct-sollers',
            'import',

        );

        foreach ($options as $key => $value)
        {
            $arguments[] = '--' . $key . ($value === true ? '' : '=' . $value);
        }

        foreach (array('allow-root', 'quiet') as $flag)
        {
            if (WP_CLI::has_config($flag) && WP_CLI::get_config($flag) === true)
            {
                $arguments[] = '--' . $flag;
            }
        }

        if (WP_CLI::get_config('color') === false)
        {
            $arguments[] = '--no-color';
        }

        foreach (array('url', 'user') as $key)
        {
            $value = WP_CLI::get_config($key);

            if (is_string($value) && $value !== '')
            {
                $arguments[] = '--' . $key . '=' . $value;
            }
        }

        if (!function_exists('pcntl_exec'))
        {
            $command = 'wp ' . implode(' ', array_map('escapeshellarg', array_slice($arguments, 1)));
            // CLI-only diagnostic; Command::terminal() escapes controls before output, not HTML here.
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
            throw new RuntimeException('La importación necesita un proceso sin plugins del destino. PHP no dispone de pcntl_exec; ejecuta directamente: ' . $command);
        }

        WP_CLI::log('Reiniciando la importación sin plugins, mu-plugins ni temas del destino…');
        fflush(STDOUT);
        fflush(STDERR);
        pcntl_exec(PHP_BINARY, $arguments);

        throw new RuntimeException('No se ha podido reiniciar la importación de forma aislada. No se ha modificado el sitio.');
    }

    ################################################
    #
    #  assert_isolated
    #
    #  Detiene la restauración si una configuración externa ha cargado código que podría borrarse.
    #
    ################################################
    private static function assert_isolated(): void
    {
        $self = realpath(dirname(__DIR__, 2)) . '/';
        $roots = array
        (
            WP_PLUGIN_DIR,
            WP_CONTENT_DIR . '/themes',
            WP_CONTENT_DIR . '/mu-plugins',

        );

        foreach (get_included_files() as $file)
        {
            if (str_starts_with($file, $self))
            {
                continue;
            }

            foreach ($roots as $root)
            {
                $path = realpath($root);

                if ($path !== false && str_starts_with($file, $path . '/'))
                {
                    throw new RuntimeException('El arranque aislado ha cargado código de plugins, mu-plugins o temas del destino. Revisa wp-config.php y los drop-ins antes de importar.');
                }
            }
        }
    }

}
