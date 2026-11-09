<?php

defined('ABSPATH') || exit;

final class DCTWPSollers_Migration_Import
{
    ################################################
    #
    #  target
    #
    #  Resuelve las URLs finales del destino sin contradecir su configuración.
    #
    ################################################
    public static function target(array $options): array
    {
        $target = DCTWPSollers_Migration_Package::context();
        $original_site = $target['site'];
        $original_home = $target['home'];
        $target['home'] = DCTWPSollers_Migration_Values::url($options['home-url'] ?? $target['home']);
        $target['site'] = DCTWPSollers_Migration_Values::url($options['site-url'] ?? $target['site']);
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
                // CLI-only diagnostic; Command::terminal() escapes controls at the output boundary.
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
                throw new RuntimeException('La URL solicitada entra en conflicto con ' . $constant . '.');
            }
        }

        return $target;
    }

    ################################################
    #
    #  plan
    #
    #  Valida datos y destinos y prepara un plan sin cambiar archivos ni tablas del sitio.
    #
    ################################################
    public static function plan(string $directory, array $manifest, array $target, string $mode, DCTWPSollers_Migration_Database $database): array
    {
        global $wpdb;
        $map = DCTWPSollers_Migration_Values::map($manifest['context'], $target);
        $existing = DCTWPSollers_Migration_Database::tables();

        foreach ($existing as $table)
        {
            DCTWPSollers_Migration_Database::schema($table);
        }

        $excluded = array_unique(array_merge($manifest['excluded_paths'], DCTWPSollers_Migration_Files::exclusions()));
        $files = DCTWPSollers_Migration_Files::plan(WP_CONTENT_DIR, $manifest['entries'], $excluded, $mode);

        if (!wp_is_writable(ABSPATH))
        {
            throw new RuntimeException('No se puede activar mantenimiento en el destino.');
        }

        $database->prepare($directory, $manifest, $map, true);
        $included_tables = array_map(static fn(string $suffix): string => $wpdb->prefix . $suffix, array_keys($manifest['tables']));
        $extra_tables = DCTWPSollers_Migration_Database::extra_tables($existing, $included_tables, $manifest['excluded_tables'], $mode);
        DCTWPSollers_Migration_Database::foreign_boundary(array_merge($included_tables, $extra_tables));

        $plan = array();
        $plan['map'] = $map;
        $plan['existing'] = $existing;
        $plan['files'] = $files;
        $plan['extra_tables'] = $extra_tables;

        return $plan;
    }

    ################################################
    #
    #  restore_files
    #
    #  Aplica el plan de archivos validado conservando metadatos y exclusiones.
    #
    ################################################
    public static function restore_files(string $directory, array $manifest, array $files): void
    {
        foreach ($files['write'] as $path)
        {
            $metadata = $manifest['entries']['content/' . $path];
            DCTWPSollers_Migration_Files::copy(
                $directory . '/content/' . $path,
                WP_CONTENT_DIR,
                $path,
                $metadata['mode'] ?? 0644,
                $metadata['mtime'] ?? null
            );
        }

        foreach ($files['delete'] as $path)
        {
            $destination = DCTWPSollers_Migration_Files::destination(WP_CONTENT_DIR, $path);

            // Validated mirror target: detect deletion failure, without wp_delete_file() filters changing the path.
            // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
            if (!unlink($destination))
            {
                // Validated mirror path; Command::terminal() escapes controls before CLI output, not HTML.
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
                throw new RuntimeException('No se puede eliminar el archivo extra: ' . $path);
            }
        }

        foreach ($files['directories'] as $path)
        {
            DCTWPSollers_Migration_Files::remove_directory(WP_CONTENT_DIR, $path);
        }
    }
}
