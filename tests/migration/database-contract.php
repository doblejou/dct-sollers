<?php

// Dobles de consulta: cubren rechazos sin necesitar permisos CREATE VIEW o TRIGGER.
require_once dirname(__DIR__) . '/checks.php';

final class DCTWPHero_Migration_Test_Database
{
    public string $prefix = 'fixture_';
    public array $last_result = array();
    public bool $suppressed = false;
    private array $responses;
    private object $original;

    ################################################
    #
    #  __construct
    #
    #  Prepara únicamente las respuestas esperadas de una prueba de contrato SQL.
    #
    ################################################
    public function __construct(array $responses, object $original)
    {
        $this->responses = $responses;
        $this->original = $original;
    }

    ################################################
    #
    #  query
    #
    #  Rechaza consultas inesperadas y no ejecuta SQL real.
    #
    ################################################
    public function query(string $sql): int|bool
    {
        $response = array_shift($this->responses);

        if ($response === null || !str_starts_with($sql, $response[0]))
        {
            throw new LogicException('Consulta no prevista en el doble de prueba.');
        }

        if ($response[1] === false)
        {
            return false;
        }

        $this->last_result = array_map(static fn(array $row): object => (object) $row, $response[1]);

        return count($this->last_result);
    }

    ################################################
    #
    #  suppress_errors
    #
    #  Permite verificar que la gestión de errores restaura el estado anterior.
    #
    ################################################
    public function suppress_errors(bool $value): bool
    {
        $previous = $this->suppressed;
        $this->suppressed = $value;

        return $previous;
    }

    ################################################
    #
    #  prepare
    #
    #  Reutiliza el escapado real de WordPress sin ejecutar ninguna consulta.
    #
    ################################################
    public function prepare(string $sql, mixed ...$arguments): string
    {
        return $this->original->prepare($sql, ...$arguments);
    }

    ################################################
    #
    #  run
    #
    #  Sustituye temporalmente el acceso SQL y restaura siempre el objeto original.
    #
    ################################################
    public static function run(array $responses, callable $test): void
    {
        global $wpdb;
        $original = $wpdb;
        $wpdb = new self($responses, $original);

        try
        {
            $test($wpdb);
            DCTWPHero_Checks::that(!$wpdb->suppressed, 'Restaura la supresión de errores SQL');
        }
        finally
        {
            $wpdb = $original;
        }
    }

}

$table = array('fixture_posts', 'BASE TABLE');
$view = array('fixture_view', 'VIEW');
$responses = array();
$responses[] = array('SHOW FULL TABLES', array($table, $view));
DCTWPHero_Migration_Test_Database::run($responses, static function (): void
{
    DCTWPHero_Checks::rejects(DCTWPHero_Migration_Database::tables(...), 'Rechaza vistas del prefijo');
});

$responses = array();
$responses[] = array('SHOW FULL TABLES', array($table, array('other_view', 'VIEW')));
DCTWPHero_Migration_Test_Database::run($responses, static function (): void
{
    DCTWPHero_Checks::that(DCTWPHero_Migration_Database::tables() === array('fixture_posts'), 'Ignora vistas de otros prefijos');
});

$reference = array();
$reference['TABLE_SCHEMA'] = DB_NAME;
$reference['TABLE_NAME'] = 'other_child';
$reference['REFERENCED_TABLE_SCHEMA'] = DB_NAME;
$reference['REFERENCED_TABLE_NAME'] = 'fixture_posts';
$responses = array();
$responses[] = array('SELECT TABLE_SCHEMA', array($reference));
DCTWPHero_Migration_Test_Database::run($responses, static function (): void
{
    DCTWPHero_Checks::rejects(static fn() => DCTWPHero_Migration_Database::foreign_boundary(array('fixture_posts')), 'Rechaza claves entrantes desde tablas conservadas');
});

$reference['TABLE_NAME'] = 'fixture_posts';
$reference['REFERENCED_TABLE_SCHEMA'] = DB_NAME . '_external';
$responses = array();
$responses[] = array('SELECT TABLE_SCHEMA', array($reference));
DCTWPHero_Migration_Test_Database::run($responses, static function (): void
{
    DCTWPHero_Checks::rejects(static fn() => DCTWPHero_Migration_Database::foreign_boundary(array('fixture_posts')), 'Rechaza referencias a otras bases aunque coincida el nombre de tabla');
});

$reference['REFERENCED_TABLE_SCHEMA'] = DB_NAME;
$responses = array();
$responses[] = array('SELECT TABLE_SCHEMA', array($reference));
DCTWPHero_Migration_Test_Database::run($responses, static function (): void
{
    DCTWPHero_Migration_Database::foreign_boundary(array('fixture_posts'));
    DCTWPHero_Checks::that(true, 'Admite relaciones internas y autorreferencias');
});

$schema = array();
$schema['Table'] = 'fixture_posts';
$schema['Create Table'] = 'CREATE TABLE `fixture_posts` (`id` int) ENGINE=InnoDB';
$trigger = array();
$trigger['Trigger'] = 'fixture_trigger';
$responses = array();
$responses[] = array('SHOW CREATE TABLE', array($schema));
$responses[] = array('SHOW TRIGGERS', array($trigger));
DCTWPHero_Migration_Test_Database::run($responses, static function (): void
{
    DCTWPHero_Checks::rejects(static fn() => DCTWPHero_Migration_Database::schema('fixture_posts'), 'Rechaza triggers');
});

$responses = array();
$responses[] = array('FAIL', false);
DCTWPHero_Migration_Test_Database::run($responses, static function (): void
{
    DCTWPHero_Checks::rejects(static fn() => DCTWPHero_Migration_Database::query('FAIL'), 'Propaga errores SQL sin datos de consulta');
});

foreach (array(0, 1) as $checks)
{
    $responses = array();
    $responses[] = array('SELECT @@SESSION.FOREIGN_KEY_CHECKS', array(array('checks_enabled' => $checks)));
    $responses[] = array('SET SESSION FOREIGN_KEY_CHECKS = 0', array());
    $responses[] = array('DROP TABLE IF EXISTS', false);
    $responses[] = array('SET SESSION FOREIGN_KEY_CHECKS = ' . $checks, array());
    $responses[] = array('AFTER RESTORE', array());
    DCTWPHero_Migration_Test_Database::run($responses, static function (): void
    {
        $drop = new ReflectionMethod(DCTWPHero_Migration_Database::class, 'drop_tables');
        DCTWPHero_Checks::rejects(static fn() => $drop->invoke(null, array('fixture_posts')), 'Propaga un fallo de borrado con claves externas');
        DCTWPHero_Migration_Database::query('AFTER RESTORE');
        DCTWPHero_Checks::that(true, 'Restaura FOREIGN_KEY_CHECKS incluso cuando falla DROP');
    });
}

$session = array();
$session['connection_id'] = 101;
$session['wait_timeout'] = 30;
$extended = $session;
$extended['wait_timeout'] = 86400;

foreach (array('set', 'clamp', 'busy') as $failure)
{
    $responses = array();
    $responses[] = array('SELECT CONNECTION_ID() AS connection_id, @@SESSION.wait_timeout', array($session));
    $responses[] = array('SET SESSION wait_timeout = 86400', $failure === 'set' ? false : array());

    if ($failure !== 'set')
    {
        $responses[] = array('SELECT CONNECTION_ID() AS connection_id, @@SESSION.wait_timeout', array($failure === 'clamp' ? $session : $extended));
    }

    if ($failure === 'busy')
    {
        $responses[] = array('SELECT GET_LOCK(', array(array('acquired' => 0)));
    }

    $responses[] = array('SELECT CONNECTION_ID() AS connection_id', array($session));
    $responses[] = array('SET SESSION wait_timeout = 30', array());
    $responses[] = array('AFTER RESTORE', array());
    DCTWPHero_Migration_Test_Database::run($responses, static function (): void
    {
        $operation = new DCTWPHero_Migration_Operation();
        DCTWPHero_Checks::rejects($operation->start(...), 'Rechaza inicio sin timeout suficiente o con bloqueo ocupado');
        DCTWPHero_Checks::that($operation->finish(null) === null, 'Restaura el timeout incluso si no llegó a adquirir el bloqueo');
        DCTWPHero_Migration_Database::query('AFTER RESTORE');
    });
}

foreach (array('release', 'timeout') as $failure)
{
    $owner = $session;
    $owner['owner'] = 101;
    $responses = array();
    $responses[] = array('SELECT CONNECTION_ID() AS connection_id, IS_USED_LOCK(', array($owner));
    $responses[] = array('SELECT RELEASE_LOCK(', $failure === 'release' ? false : array(array('released' => 1)));
    $responses[] = array('SELECT CONNECTION_ID() AS connection_id', array($session));
    $responses[] = array('SET SESSION wait_timeout = 30', $failure === 'timeout' ? false : array());
    $responses[] = array('AFTER RESTORE', array());
    DCTWPHero_Migration_Test_Database::run($responses, static function () use ($failure): void
    {
        $operation = new DCTWPHero_Migration_Operation();
        $state = array();
        $state['locked'] = true;
        $state['connection_id'] = 101;
        $state['wait_timeout'] = 30;

        foreach ($state as $name => $value)
        {
            (new ReflectionProperty($operation, $name))->setValue($operation, $value);
        }

        $message = $operation->finish(null);
        $resource = $failure === 'release' ? 'bloqueo SQL' : 'timeout de sesión SQL';
        DCTWPHero_Checks::that(is_string($message) && str_contains($message, $resource), 'Identifica el recurso SQL que no se pudo limpiar');
        DCTWPHero_Migration_Database::query('AFTER RESTORE');
    });
}

foreach (array(0, 100, 201) as $count)
{
    $responses = array();
    $expected = array();

    for ($id = 0; $id < $count; $id++)
    {
        $row = array();
        $row['value'] = "\0\xff'\\%s%i";
        $row['nullable'] = null;
        $expected[] = $row;
    }

    for ($offset = 0; $offset <= $count; $offset += 100)
    {
        $sql = 'SELECT `value`,`nullable` FROM `fixture_posts` USE INDEX () LIMIT 100 OFFSET ' . $offset;
        $responses[] = array($sql, array_slice($expected, $offset, 100));

        if ($count - $offset < 100)
        {
            break;
        }
    }

    DCTWPHero_Migration_Test_Database::run($responses, static function () use ($expected): void
    {
        $rows = iterator_to_array(DCTWPHero_Migration_Database::export_rows('fixture_posts', array('value', 'nullable')));
        $expected = array_map(array_values(...), $expected);
        DCTWPHero_Checks::that($rows === $expected, 'Exportación por lotes conserva duplicados, binarios y NULL sin clave primaria');
    });
}

$responses = array();
$responses[] = array('SELECT `value` FROM `fixture_posts` USE INDEX () LIMIT 100 OFFSET 0', false);
DCTWPHero_Migration_Test_Database::run($responses, static function (): void
{
    DCTWPHero_Checks::rejects(static fn() => iterator_to_array(DCTWPHero_Migration_Database::export_rows('fixture_posts', array('value'))), 'Propaga errores al leer lotes de exportación');
    DCTWPHero_Checks::rejects(static fn() => iterator_to_array(DCTWPHero_Migration_Database::export_rows('fixture_posts; DROP TABLE users', array('value'))), 'Rechaza identificadores de tabla antes de leer');
    DCTWPHero_Checks::rejects(static fn() => iterator_to_array(DCTWPHero_Migration_Database::export_rows('fixture_posts', array('value; SELECT 1'))), 'Rechaza identificadores de columna antes de leer');
});

foreach (array(0, 1) as $warnings)
{
    $responses = array();
    $responses[] = array('SHOW COUNT(*) WARNINGS', array(array('@@session.warning_count' => $warnings)));
    DCTWPHero_Migration_Test_Database::run($responses, static function () use ($warnings): void
    {
        $method = new ReflectionMethod(DCTWPHero_Migration_Database::class, 'warning_count');
        DCTWPHero_Checks::that($method->invoke(null) === $warnings, 'Lee avisos SQL mediante wpdb');
    });
}

DCTWPHero_Checks::report();
