<?php

defined('ABSPATH') || exit;

final class DCTWPSollers_Migration_Database
{
    private array $staged = array();
    private array $retired = array();

    ################################################
    #
    #  identifier
    #
    #  Valida y delimita identificadores de tablas o columnas, nunca valores SQL.
    #
    ################################################
    public static function identifier(string $name): string
    {
        if (!preg_match('/^[a-zA-Z0-9_$]+$/D', $name) || strlen($name) > 64)
        {
            throw new RuntimeException('Identificador de base de datos no compatible.');
        }

        return '`' . $name . '`';
    }

    ################################################
    #
    #  query
    #
    #  Detiene la migración ante un error SQL sin volcar datos sensibles al terminal.
    #
    ################################################
    public static function query(string $sql): int|bool
    {
        global $wpdb;
        $previous = $wpdb->suppress_errors(true);

        try
        {
            // Internal CLI executor: callers prepare values, validate identifiers/DDL and encode binary values with bin2hex(). See review-notes.txt.
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $result = $wpdb->query($sql);

            if ($result === false)
            {
                throw new RuntimeException('Operación SQL fallida. Revisa permisos y compatibilidad de la base de datos.');
            }

            return $result;
        }
        finally
        {
            $wpdb->suppress_errors($previous);
        }
    }

    ################################################
    #
    #  tables
    #
    #  Enumera solo tablas del prefijo actual y rechaza vistas.
    #
    ################################################
    public static function tables(): array
    {
        global $wpdb;
        self::query('SHOW FULL TABLES');
        $tables = array();

        foreach ($wpdb->last_result as $item)
        {
            $row = array_values((array) $item);

            if (!str_starts_with($row[0], $wpdb->prefix))
            {
                continue;
            }

            if ($row[1] !== 'BASE TABLE')
            {
                throw new RuntimeException('No se admiten vistas dentro del prefijo del sitio.');
            }

            self::identifier($row[0]);
            $tables[] = $row[0];
        }

        sort($tables);

        return $tables;
    }

    ################################################
    #
    #  foreign_boundary
    #
    #  Rechaza relaciones que crucen el conjunto sustituido, eliminado o exportado.
    #
    ################################################
    public static function foreign_boundary(array $tables): void
    {
        global $wpdb;
        self::query('SELECT TABLE_SCHEMA, TABLE_NAME, REFERENCED_TABLE_SCHEMA, REFERENCED_TABLE_NAME '
            . 'FROM information_schema.KEY_COLUMN_USAGE WHERE REFERENCED_TABLE_NAME IS NOT NULL '
            . 'AND (TABLE_SCHEMA = DATABASE() OR REFERENCED_TABLE_SCHEMA = DATABASE())');

        foreach ($wpdb->last_result as $reference)
        {
            $child = $reference->TABLE_SCHEMA === DB_NAME && in_array($reference->TABLE_NAME, $tables, true);
            $parent = $reference->REFERENCED_TABLE_SCHEMA === DB_NAME && in_array($reference->REFERENCED_TABLE_NAME, $tables, true);

            if ($child !== $parent)
            {
                // CLI-only diagnostic; Command::terminal() escapes controls at the output boundary, not HTML here.
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
                throw new RuntimeException('Una clave externa cruza tablas incluidas y conservadas, excluidas o ajenas: ' . $reference->TABLE_NAME . ' → ' . $reference->REFERENCED_TABLE_NAME . '.');
            }
        }
    }

    ################################################
    #
    #  split_schema
    #
    #  Separa solo claves canónicas SHOW CREATE; no acepta SQL arbitrario en ellas.
    #
    ################################################
    public static function split_schema(string $schema): array
    {
        $keys = array();
        $identifier = '`[a-zA-Z0-9_$]{1,64}`';
        $columns = $identifier . '(?:, ?' . $identifier . ')*';
        $action = '(?:RESTRICT|CASCADE|SET NULL|NO ACTION)';
        $quoted = "'(?:[^'\\\\]|\\\\.|'')*'";
        $pattern = '/' . $quoted . '(*SKIP)(*F)|,\n[ \t]*CONSTRAINT (' . $identifier . ') FOREIGN KEY \((' . $columns . ')\) '
            . 'REFERENCES (' . $identifier . ') \((' . $columns . ')\)'
            . '((?: ON DELETE ' . $action . ')?(?: ON UPDATE ' . $action . ')?)(?=,?\n)/s';
        $schema = preg_replace_callback($pattern, static function (array $match) use (&$keys): string
        {
            $key = array();
            $key['table'] = trim($match[3], '`');
            $key['columns'] = $match[2];
            $key['references'] = $match[4];
            $key['actions'] = $match[5];
            $keys[] = $key;

            return '';
        }, $schema);
        $result = array();
        $result['schema'] = $schema;
        $result['keys'] = $keys;

        return $result;
    }

    ################################################
    #
    #  schema
    #
    #  Obtiene estructuras InnoDB sin triggers para una restauración aislada.
    #
    ################################################
    public static function schema(string $table): string
    {
        global $wpdb;
        self::query('SHOW CREATE TABLE ' . self::identifier($table));
        $row = array_values((array) $wpdb->last_result[0]);
        $schema = $row[1];
        self::validate_schema($schema, $table);
        self::query($wpdb->prepare('SHOW TRIGGERS WHERE `Table` = %s', $table));

        if ($wpdb->last_result !== array())
        {
            // CLI-only diagnostic; Command::terminal() escapes controls before output.
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
            throw new RuntimeException('No se admiten tablas con triggers: ' . $table);
        }

        return $schema;
    }

    ################################################
    #
    #  validate_schema
    #
    #  Limita los esquemas admitidos al contrato del formato de migración.
    #
    ################################################
    public static function validate_schema(string $schema, string $table): void
    {
        $schema = self::split_schema($schema)['schema'];
        $structure = preg_replace("/'(?:[^'\\\\]|\\\\.|'')*'|`(?:``|[^`])*`/s", "''", $schema);

        if (!str_starts_with($schema, 'CREATE TABLE ' . self::identifier($table) . ' (')
            || !preg_match('/\)\s+ENGINE=InnoDB\b/i', $structure)
            || preg_match('/FOREIGN\s+KEY|REFERENCES\b|DATA\s+DIRECTORY|INDEX\s+DIRECTORY|TABLESPACE|PARTITION\s+BY/i', $structure))
        {
            throw new RuntimeException('Esquema no compatible: se requiere InnoDB sin particiones, rutas externas ni claves externas no canónicas.');
        }
    }

    ################################################
    #
    #  write
    #
    #  Escribe un registro completo y comprueba los errores de disco.
    #
    ################################################
    public static function write($handle, array $record): void
    {
        $line = json_encode($record, JSON_THROW_ON_ERROR) . "\n";

        // Streaming NDJSON must detect partial writes without buffering an entire table.
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
        if (fwrite($handle, $line) !== strlen($line))
        {
            throw new RuntimeException('No se ha podido escribir el volcado de base de datos.');
        }
    }

    ################################################
    #
    #  export
    #
    #  Vuelca filas binarias en NDJSON dentro de una instantánea consistente InnoDB.
    #
    ################################################
    public static function export(string $directory, array $excluded): array
    {
        global $wpdb;
        $tables = self::tables();

        if (array_diff($excluded, $tables) !== array())
        {
            throw new RuntimeException('Alguna tabla excluida no existe en el prefijo actual.');
        }

        $schemas = array();
        self::foreign_boundary(array_values(array_diff($tables, $excluded)));

        foreach (array_diff($tables, $excluded) as $table)
        {
            $schemas[$table] = self::schema($table);
        }

        $inventory = array();
        self::query('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        self::query('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY');

        try
        {
            foreach ($schemas as $table => $schema)
            {
                self::query('SHOW COLUMNS FROM ' . self::identifier($table));
                $columns = array();

                foreach ($wpdb->last_result as $column)
                {
                    if (!preg_match('/(?:VIRTUAL|STORED) GENERATED/', $column->Extra))
                    {
                        $columns[] = $column->Field;
                    }
                }

                $suffix = substr($table, strlen($wpdb->prefix));
                self::identifier($suffix);
                $entry = 'database/' . $suffix . '.ndjson';
                $file = $directory . '/' . $entry;
                // Exclusive local stream in the operation's private directory; never overwrite an existing dump.
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
                $handle = fopen($file, 'xb');

                if ($handle === false)
                {
                    throw new RuntimeException('No se puede crear el volcado temporal.');
                }

                $header = array();
                $header['table'] = $table;
                $header['schema'] = $schema;
                $header['columns'] = $columns;

                try
                {
                    self::write($handle, $header);
                    foreach (self::export_rows($table, $columns) as $row)
                    {
                        $encoded = array_map(static fn(?string $value): ?string => $value === null ? null : base64_encode($value), $row);
                        self::write($handle, $encoded);
                    }
                }
                finally
                {
                    // Close the NDJSON stream and treat a failed close as an export failure.
                    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
                    if (!fclose($handle))
                    {
                        throw new RuntimeException('No se ha podido cerrar el volcado temporal.');
                    }
                }

                $inventory[$suffix] = $entry;
                WP_CLI::log('Tabla exportada: ' . $table);
            }
        }
        finally
        {
            self::query('ROLLBACK');
        }

        return $inventory;
    }

    ################################################
    #
    #  export_rows
    #
    #  Lee lotes con wpdb sin almacenar una tabla completa en memoria.
    #
    ################################################
    public static function export_rows(string $table, array $columns): Generator
    {
        global $wpdb;
        self::identifier($table);
        $selection = array();

        foreach ($columns as $column)
        {
            self::identifier($column);
            $selection[] = $wpdb->prepare('%i', $column);
        }

        $offset = 0;
        $limit = 100;

        do
        {
            // A full InnoDB clustered scan in the same read-only snapshot also supports tables without a primary key.
            // USE INDEX () prevents switching to a covering secondary index between batches.
            // Column fragments were prepared with %i above; the table and pagination are prepared here.
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            self::query($wpdb->prepare('SELECT ' . implode(',', $selection) . ' FROM %i USE INDEX () LIMIT %d OFFSET %d', $table, $limit, $offset));
            $rows = $wpdb->last_result;

            foreach ($rows as $row)
            {
                yield array_values((array) $row);
            }

            $offset += count($rows);
        }
        while (count($rows) === $limit);
    }

    ################################################
    #
    #  warning_count
    #
    #  Consulta los avisos mediante una sentencia diagnóstica que los conserva.
    #
    ################################################
    private static function warning_count(): int
    {
        global $wpdb;
        self::query('SHOW COUNT(*) WARNINGS');

        return (int) array_values((array) $wpdb->last_result[0])[0];
    }

    ################################################
    #
    #  records
    #
    #  Valida cabecera y filas del volcado sin cargar una tabla completa en memoria.
    #
    ################################################
    public static function records(string $file, string $table): Generator
    {
        // Parse the validated private dump incrementally; WP_Filesystem::get_contents() would load the whole table.
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
        $handle = fopen($file, 'rb');

        if ($handle === false)
        {
            throw new RuntimeException('No se puede leer un volcado de tabla.');
        }

        try
        {
            $line = fgets($handle);
            $header = json_decode($line ?: '', true, 512, JSON_THROW_ON_ERROR);

            if (!is_array($header) || ($header['table'] ?? null) !== $table
                || !is_string($header['schema'] ?? null) || !is_array($header['columns'] ?? null)
                || !array_is_list($header['columns']) || $header['columns'] === array())
            {
                throw new RuntimeException('Cabecera de tabla inválida.');
            }

            self::validate_schema($header['schema'], $table);

            foreach ($header['columns'] as $column)
            {
                if (!is_string($column))
                {
                    throw new RuntimeException('Columna inválida.');
                }

                self::identifier($column);
            }

            if (count(array_unique($header['columns'])) !== count($header['columns']))
            {
                throw new RuntimeException('Columnas duplicadas.');
            }

            yield $header;

            while (($line = fgets($handle)) !== false)
            {
                $row = json_decode($line, true, 512, JSON_THROW_ON_ERROR);

                if (!is_array($row) || !array_is_list($row) || count($row) !== count($header['columns']))
                {
                    throw new RuntimeException('Fila de tabla inválida.');
                }

                foreach ($row as $index => $value)
                {
                    if ($value !== null)
                    {
                        if (!is_string($value) || ($decoded = base64_decode($value, true)) === false || base64_encode($decoded) !== $value)
                        {
                            throw new RuntimeException('Valor binario inválido.');
                        }

                        $row[$index] = $decoded;
                    }
                }

                yield $row;
            }

            if (!feof($handle))
            {
                throw new RuntimeException('Lectura incompleta de una tabla.');
            }
        }
        finally
        {
            // Release the native read stream even when a record is rejected.
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
            fclose($handle);
        }
    }

    ################################################
    #
    #  prepare
    #
    #  Valida o carga tablas aisladas adaptando valores y claves de permisos.
    #
    ################################################
    public function prepare(string $directory, array $manifest, array $map, bool $dry): void
    {
        global $wpdb;
        $foreign = array();

        foreach ($manifest['tables'] as $suffix => $entry)
        {
            $target = $wpdb->prefix . $suffix;
            self::identifier($target);
            $stage = 'dctm_' . bin2hex(random_bytes(12));
            $columns = array();

            foreach (self::records($directory . '/' . $entry, $manifest['prefix'] . $suffix) as $index => $row)
            {
                if ($index === 0)
                {
                    $columns = $row['columns'];
                    $definition = self::split_schema($row['schema']);
                    $foreign[$target] = $definition['keys'];

                    foreach ($foreign[$target] as $key)
                    {
                        $suffix_parent = substr($key['table'], strlen($manifest['prefix']));

                        if (!str_starts_with($key['table'], $manifest['prefix']) || !isset($manifest['tables'][$suffix_parent]))
                        {
                            // CLI-only diagnostic; Command::terminal() escapes controls before output.
                            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
                            throw new RuntimeException('Una clave externa referencia una tabla no incluida: ' . $key['table']);
                        }
                    }

                    if (!$dry)
                    {
                        $schema = 'CREATE TABLE ' . self::identifier($stage);
                        $schema .= substr($definition['schema'], strlen('CREATE TABLE ' . self::identifier($row['table'])));
                        self::query($schema);
                        $this->staged[$target] = $stage;
                        $insert = 'INSERT INTO ' . self::identifier($stage);
                        $insert .= ' (' . implode(',', array_map(self::identifier(...), $columns)) . ') VALUES (';
                        self::query('START TRANSACTION');
                    }

                    continue;
                }

                $values = array();

                foreach ($row as $column => $value)
                {
                    if ($value !== null)
                    {
                        $value = DCTWPSollers_Migration_Values::replace($value, $map);

                        $role_key = $suffix === 'options' && $columns[$column] === 'option_name'
                            && $value === $manifest['prefix'] . 'user_roles';

                        if ($role_key || ($suffix === 'usermeta' && $columns[$column] === 'meta_key'))
                        {
                            if (str_starts_with($value, $manifest['prefix']))
                            {
                                $value = $wpdb->prefix . substr($value, strlen($manifest['prefix']));
                            }
                        }
                    }

                    if (!$dry)
                    {
                        $values[] = $value === null ? 'NULL' : "UNHEX('" . bin2hex($value) . "')";
                    }
                }

                if (!$dry)
                {
                    $inserted = self::query($insert . implode(',', $values) . ')');

                    if ($inserted !== 1 || self::warning_count() !== 0)
                    {
                        throw new RuntimeException('Una fila no se ha importado exactamente: conversión SQL con avisos o recuento incorrecto.');
                    }
                }
            }

            if (!$dry)
            {
                self::query('COMMIT');
            }
        }

        if (!$dry)
        {
            self::query('SELECT @@SESSION.FOREIGN_KEY_CHECKS AS checks_enabled');
            $checks = (int) $wpdb->last_result[0]->checks_enabled;

            try
            {
                self::query('SET SESSION FOREIGN_KEY_CHECKS = 1');

                foreach ($foreign as $target => $keys)
                {
                    foreach ($keys as $key)
                    {
                        $parent = $wpdb->prefix . substr($key['table'], strlen($manifest['prefix']));
                        $sql = 'ALTER TABLE ' . self::identifier($this->staged[$target]);
                        $sql .= ' ADD CONSTRAINT ' . self::identifier('dctfk_' . bin2hex(random_bytes(12)));
                        $sql .= ' FOREIGN KEY (' . $key['columns'] . ') REFERENCES ' . self::identifier($this->staged[$parent]);
                        $sql .= ' (' . $key['references'] . ')' . $key['actions'];
                        self::query($sql);

                        if (self::warning_count() !== 0)
                        {
                            throw new RuntimeException('La reconstrucción de una clave externa produjo avisos SQL.');
                        }
                    }
                }
            }
            finally
            {
                self::query('SET SESSION FOREIGN_KEY_CHECKS = ' . $checks);
            }
        }
    }

    ################################################
    #
    #  extra_tables
    #
    #  Define una única regla para el resumen y el borrado de tablas adicionales.
    #
    ################################################
    public static function extra_tables(array $existing, array $included, array $excluded, string $mode): array
    {
        global $wpdb;

        if ($mode !== 'mirror')
        {
            return array();
        }

        $protected = array_map(static fn(string $suffix): string => $wpdb->prefix . $suffix, $excluded);

        return array_values(array_diff($existing, $included, $protected));
    }

    ################################################
    #
    #  publish
    #
    #  Sustituye las tablas incluidas y, en mirror, elimina extras no excluidas.
    #
    ################################################
    public function publish(array $existing, array $excluded, string $mode): void
    {
        $renames = array();
        $retired = array();
        $published = array_keys($this->staged);
        $extra = self::extra_tables($existing, $published, $excluded, $mode);
        self::foreign_boundary(array_merge($published, $extra));

        foreach ($this->staged as $target => $stage)
        {
            if (in_array($target, $existing, true))
            {
                $old = 'dctm_' . bin2hex(random_bytes(12));
                $renames[] = self::identifier($target) . ' TO ' . self::identifier($old);
                $retired[] = $old;
            }

            $renames[] = self::identifier($stage) . ' TO ' . self::identifier($target);
        }

        self::query('RENAME TABLE ' . implode(', ', $renames));
        $this->staged = array();
        $this->retired = $retired;

        $retired = array_merge($retired, $extra);

        if ($retired !== array())
        {
            self::drop_tables($retired);
            $this->retired = array();
        }
    }

    ################################################
    #
    #  cleanup
    #
    #  Elimina solamente las tablas de preparación creadas por esta instancia.
    #
    ################################################
    public function cleanup(): void
    {
        $temporary = array_merge(array_values($this->staged), $this->retired);

        if ($temporary !== array())
        {
            self::query('ROLLBACK');
            self::drop_tables($temporary);
            $this->staged = array();
            $this->retired = array();
        }
    }

    ################################################
    #
    #  drop_tables
    #
    #  Borra un conjunto cerrado, incluso cíclico, restaurando la comprobación SQL.
    #
    ################################################
    private static function drop_tables(array $tables): void
    {
        global $wpdb;
        self::query('SELECT @@SESSION.FOREIGN_KEY_CHECKS AS checks_enabled');
        $checks = (int) $wpdb->last_result[0]->checks_enabled;

        try
        {
            self::query('SET SESSION FOREIGN_KEY_CHECKS = 0');
            self::query('DROP TABLE IF EXISTS ' . implode(',', array_map(self::identifier(...), $tables)));
        }
        finally
        {
            self::query('SET SESSION FOREIGN_KEY_CHECKS = ' . $checks);
        }
    }

}
