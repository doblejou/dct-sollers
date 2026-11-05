<?php

// Ejecutar desde el WordPress de referencia; únicamente escribe el wp-config del entorno desechable.
$directory = $args[0] ?? '';
$prefix = $args[1] ?? '';

if (!preg_match('/^dcttest_[a-f0-9]{12}_$/D', $prefix)
    || !preg_match('~^/tmp/(?:opencode/)?dctwphero-test-[^/]+/wordpress(?:-target)?$~D', realpath($directory) ?: ''))
{
    throw new RuntimeException('Destino de pruebas inválido.');
}

$config = "<?php\n";

foreach (array('DB_NAME', 'DB_USER', 'DB_PASSWORD', 'DB_HOST', 'DB_CHARSET', 'DB_COLLATE') as $name)
{
    $config .= 'define(' . var_export($name, true) . ', ' . var_export(defined($name) ? constant($name) : '', true) . ");\n";
}

$config .= '$table_prefix = ' . var_export($prefix, true) . ";\n";
$config .= "define('WP_DEBUG', false);\n";
$config .= "define('WP_CACHE', true);\n";
$config .= "define('DISABLE_WP_CRON', true);\n";
$config .= "define('WP_ENVIRONMENT_TYPE', 'local');\n";
$config .= "if (!defined('ABSPATH')) { define('ABSPATH', __DIR__ . '/'); }\nrequire_once ABSPATH . 'wp-settings.php';\n";

if (file_put_contents($directory . '/wp-config.php', $config) === false || !chmod($directory . '/wp-config.php', 0600))
{
    throw new RuntimeException('No se puede crear la configuración de pruebas.');
}

echo "Configuración privada de pruebas creada.\n";
