<?php

// Ejecutar mediante wp eval-file; no modifica datos de WordPress.
if (!class_exists('DCTWPHero_Migration_Values'))
{
    require_once dirname(__DIR__, 2) . '/modules/migration/class-values.php';
    require_once dirname(__DIR__, 2) . '/modules/migration/class-files.php';
}


require_once dirname(__DIR__) . '/checks.php';

final class DCTWPHero_Migration_Wakeup_Probe
{
    public string $url = 'http://origen.test';

    ################################################
    #
    #  __wakeup
    #
    #  Detecta cualquier deserialización accidental de clases durante el reemplazo.
    #
    ################################################
    public function __wakeup(): void
    {
        throw new LogicException('El reemplazo no debe instanciar objetos.');
    }

}

$check = DCTWPHero_Checks::that(...);

$source = array();
$source['home'] = 'http://origen.test';
$source['root'] = '/srv/origen';
$source['wordpress'] = '7.0';
$target = array();
$target['home'] = 'https://destino.example/sub';
$target['root'] = '/var/www/destino';
$target['wordpress'] = '7.1';
$map = DCTWPHero_Migration_Values::map($source, $target);
$old = array();
$old['url'] = 'http://origen.test/imagen.jpg';
$old['path'] = '/srv/origen/wp-content';
$old['binary'] = "\0\xff";
$old['nested'] = serialize('http://origen.test');
$old['unicode'] = 'áé 🐘 http://origen.test/ñ';
$settings = array();
$settings['allowed_classes'] = false;
$new = unserialize(DCTWPHero_Migration_Values::replace(serialize($old), $map), $settings);
$check($new['url'] === 'https://destino.example/sub/imagen.jpg', 'URL serializada y longitudes');
$check($new['path'] === '/var/www/destino/wp-content', 'Ruta absoluta serializada');
$check($new['binary'] === "\0\xff", 'Contenido binario preservado');
$check(unserialize($new['nested']) === $target['home'], 'Serialización anidada');
$check($new['unicode'] === 'áé 🐘 https://destino.example/sub/ñ', 'Longitudes serializadas en bytes con UTF-8');
$object = new stdClass();
$object->url = $source['home'];
$changed = DCTWPHero_Migration_Values::replace(serialize($object), $map);
$check(unserialize($changed)->url === $target['home'], 'Propiedades de objetos sin instanciación durante el reemplazo');
$changed = DCTWPHero_Migration_Values::replace(serialize(new DCTWPHero_Migration_Wakeup_Probe()), $map);
$check(str_contains($changed, $target['home']), 'No ejecuta __wakeup durante la transformación');
$check(DCTWPHero_Migration_Values::replace('7.0', $map) === '7.0', 'No reemplaza versiones del entorno');
$check(DCTWPHero_Migration_Values::replace('s:section http://origen.test', $map) === 's:section https://destino.example/sub', 'No confunde texto ordinario con una longitud serializada');
$check(DCTWPHero_Migration_Values::replace('http:\/\/origen.test', $map) === 'https:\/\/destino.example\/sub', 'URL JSON escapada');
$check(DCTWPHero_Migration_Values::replace(urlencode($source['home']), $map) === urlencode($target['home']), 'URL codificada');
DCTWPHero_Checks::rejects(
    static fn() => DCTWPHero_Migration_Values::replace('C:7:"Example":18:{http://origen.test}', $map),
    'Rechaza serialización personalizada que necesita reemplazo'
);

foreach (array('../escape', '/absolute', 'foo//bar', 'foo/./bar', 'foo\\bar', "foo\0bar", 'php:stream') as $path)
{
    DCTWPHero_Checks::rejects(static fn() => DCTWPHero_Migration_Files::path($path), 'Rechaza ruta no segura: ' . json_encode($path));
}

$check(DCTWPHero_Migration_Files::excluded('uploads/private/file', array('uploads/private')), 'Exclusión de subárbol');
$check(!DCTWPHero_Migration_Files::excluded('uploads/private-other/file', array('uploads/private')), 'Exclusión respeta límites');

$samples = array
(
    null,
    true,
    false,
    0,
    -10,
    PHP_INT_MAX,
    1.25,
    INF,
    -INF,
    NAN,
    '',
    'a string containing s:3:"foo"; and C:7:"Example"',
    "binary\0\xff",
    array(),
    array(1, false, null, 'http://origen.test'),

);

foreach ($samples as $sample)
{
    $serialized = serialize($sample);
    $check(DCTWPHero_Migration_Values::replace($serialized, array()) === $serialized, 'Round-trip de tokens sin reemplazos');
}

$shared = 'http://origen.test';
$references = array();
$references['first'] =& $shared;
$references['second'] =& $shared;
$serialized = DCTWPHero_Migration_Values::replace(serialize($references), $map);
$restored = unserialize($serialized);
$restored['first'] = 'changed';
$check($restored['second'] === 'changed', 'Conserva referencias R:');
$object = new stdClass();
$object->self = $object;
$object->url = 'http://origen.test';
$serialized = DCTWPHero_Migration_Values::replace(serialize($object), $map);
$restored = unserialize($serialized);
$check($restored->self === $restored && $restored->url === $target['home'], 'Conserva referencias de objeto r:');
$custom = 'C:7:"Example":3:{abc}';
$check(DCTWPHero_Migration_Values::replace($custom, $map) === $custom, 'Conserva C: sin reemplazos');

$invalids = array
(
    's:8:"short";',
    'a:2:{i:0;s:1:"a";}',
    'a:1:{b:1;s:1:"a";}',
    'a:1:{i:0;unknown;}',
    'a:1:{i:0;s:999999999999999999999:"x";}',
    'O:8:"stdClass":2:{s:1:"x";i:1;}',
    'C:7:"Example":9:{abc}',
    'a:0:{}garbage;}',
    'a:1:{i:0;s:5:"short";',
    's:5:"short"',
    'O:8:"stdClass":1:{s:3:"url";s:18:"http://origen.test";',
    'C:7:"Example":3:{abc',
    'E:8:"Foo:Case"',
    'b:2;',
    'i:12garbage;',
    'd:1.2.3;',
    'R:0;',
    'r:-1;',

);

foreach ($invalids as $value)
{
    DCTWPHero_Checks::rejects(static fn() => DCTWPHero_Migration_Values::replace($value, $map), 'Serialización truncada o inválida');
}

$deep = 'http://origen.test';

for ($index = 0; $index < 140; $index++)
{
    $deep = array($deep);
}

DCTWPHero_Checks::rejects(static fn() => DCTWPHero_Migration_Values::replace(serialize($deep), $map), 'Límite de anidación');
$check(DCTWPHero_Migration_Values::replace('E:8:"Foo:Case";', $map) === 'E:8:"Foo:Case";', 'Conserva enums sin instanciarlos');
$check(DCTWPHero_Migration_Values::replace('R:1;', $map) === 'R:1;', 'Conserva tokens de referencia');
$overlap = array();
$overlap['http://origen.test'] = 'https://destino.test';
$overlap['http://origen.test/sub'] = 'https://otro.test';
$check(DCTWPHero_Migration_Values::replace('http://origen.test/sub/image', $overlap) === 'https://otro.test/image', 'strtr prioriza coincidencias largas sin ordenar el mapa');
$check(DCTWPHero_Migration_Values::url('https://example.test/sub/') === 'https://example.test/sub', 'URL válida normalizada');

foreach (array(null, '', 'file:///tmp/private', 'https://user:password@example.test', 'https://example.test/?q=1', 'https://example.test/#f') as $url)
{
    DCTWPHero_Checks::rejects(static fn() => DCTWPHero_Migration_Values::url($url), 'URL no admitida');
}

foreach (array("\0", "\x01", "\x1f", "\x7f", '\\', ' ', "\n") as $character)
{
    foreach (array('https://exam' . $character . 'ple.test', 'https://example.test/sub' . $character . 'path') as $url)
    {
        DCTWPHero_Checks::rejects(static fn() => DCTWPHero_Migration_Values::url($url), 'Rechaza controles y barras ambiguas antes de persistir URLs');
    }
}

$source['site'] = $source['home'];
$target['site'] = 'https://different-admin.test';
$check(DCTWPHero_Migration_Values::replace($source['home'], DCTWPHero_Migration_Values::map($source, $target)) === $target['home'], 'Prioridad pública en URLs de origen ambiguas');
$target['home'] = $source['home'];
$check(DCTWPHero_Migration_Values::replace($source['home'], DCTWPHero_Migration_Values::map($source, $target)) === $source['home'], 'Mapas identidad protegen coincidencias ambiguas');
DCTWPHero_Checks::report();
