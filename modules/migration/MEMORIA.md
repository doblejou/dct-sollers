# Memoria técnica: migración CLI

## Alcance y entrada rápida

Módulo `migration`, activo por defecto. Exporta/importa sitios WordPress individuales
mediante WP-CLI; nunca por HTTP. Consultar el `APP.md` raíz para las reglas comunes
y restricciones de migración. Esta memoria orienta hacia el archivo implicado;
contrastar allí el detalle del cambio y actualizarla cuando cambie el contrato.

**Importar sobrescribe datos, usuarios y código. No hay copia automática ni rollback.**
Probar restauraciones únicamente en instalaciones desechables, nunca sobre el
WordPress de referencia `/var/www/wpdev4.local`.

## Mapa de responsabilidades

Clases con prefijo `DCTWPSollers_Migration_`:

| Archivo | Clase / responsabilidad |
|---|---|
| `class-module.php` | `Module`: carga operativa solo con `WP_CLI`; registra export/import. |
| `class-admin.php`, `templates/usage.php` | `Admin`: pestaña informativa con ejemplos, incluso desactivado; sin controles de ejecución. |
| `class-command.php` | `Command`: argumentos, coordinación, confirmación y secuencia de importación/finalización. |
| `class-import.php` | `Import`: contexto destino, plan validado de importación y aplicación de archivos; sin confirmaciones, mantenimiento ni salida CLI. |
| `class-operation.php` | `Operation`: propietario de bloqueo, temporales y objeto Database; limpieza centralizada. |
| `class-process.php` | `Process`: sustitución del proceso y comprobación del aislamiento. |
| `import-bootstrap.php` | Arranque temprano con `--require`, sin plugins, mu-plugins, temas ni drop-ins de caché antiguos. |
| `class-package.php` | `Package`: requisitos, contexto, creación, extracción y validación de ZIP/manifiesto. |
| `class-files.php` | `Files`: rutas, exclusiones, inventario, plan de cambios, copia y permisos. |
| `class-database.php` | `Database`: esquema/NDJSON, tablas temporales, publicación y regla única de tablas extra. |
| `class-values.php` | `Values`: URLs, mapa de reemplazos y parser seguro de serialización. |
| `finalize.php` | Ajustes finales en proceso nuevo, URLs, uploads y migrador activo. |
| `verify.php` | Verificación SQL sobre las tablas del sitio. |
| `class-cache.php`, `flush-cache.php` | `Cache`: invalidación CSS de Elementor activo y limpieza final de objetos. |

Conexiones externas (rutas raíz): `dctwpsollers.php` carga Module/Admin y declara
el módulo en `DCTWPSollers::modules()`; la infraestructura de administración genera
su pestaña desde el registro. No introducir dependencias directas con otros módulos.
Desactivar retira los comandos, no la pestaña informativa ni los datos.

`../../review-notes.txt` se distribuye con el plugin y explica en inglés las
excepciones puntuales para revisión. No se desactivan sniffs por archivo ni por
proyecto. El bootstrap anterior a WordPress exige SAPI `cli`, `WP_CLI === true`
y clase `WP_CLI` ya cargada, sin autoload; conserva su guardia real,
no una comprobación ficticia de `ABSPATH` para complacer el analizador.

## Interfaz CLI

```sh
wp dctwpsollers export --file=/ruta/privada/sitio.zip
wp dctwpsollers export --file=/ruta/privada/sitio.zip --exclude=uploads/privado --exclude-tables=wp_temporal
wp dctwpsollers import --file=/ruta/privada/sitio.zip --mode=merge --dry-run
wp dctwpsollers import --file=/ruta/privada/sitio.zip --mode=mirror --yes
```

- `--file`: ruta absoluta; exportación requiere ZIP nuevo y directorio existente,
  escribible y fuera de rutas públicas conocidas. El operador debe revisar alias web.
  `DOCUMENT_ROOT` ausente o null usa ABSPATH; vacío sigue admitido para WP-CLI.
  Los valores de tipo distinto de cadena se rechazan antes de llamar a realpath.
- `--mode=merge|mirror` obligatorio, sin valor inicial. Ambos reemplazan tablas
  incluidas completas; merge **no fusiona filas** y conserva extras. Mirror elimina
  archivos/tablas extra no excluidos dentro del ámbito migrado.
- Export admite listas con comas en `--exclude` y `--exclude-tables`; sin glob.
- Import admite `--home-url`, `--site-url`, `--dry-run` y `--yes` (estos dos sin valor).
  Rechaza argumentos/opciones desconocidos. Sin `--yes` exige respuesta `y`.
  Las URLs se analizan mediante `wp_parse_url()` y rechazan controles ASCII, espacios y barras invertidas para evitar
  interpretaciones distintas entre el parser y el consumidor de la URL.
- Se conservan por defecto home/siteurl del destino; rechaza conflictos con
  `WP_HOME`/`WP_SITEURL`. `Values::map()` adapta rutas y variantes de URLs.
- `Command::terminal()` escapa controles ASCII y C1 UTF-8 como secuencias hex
  visibles en errores/éxitos y en el subcomando verificado; preserva texto normal,
  espacios y acentos, sin escape HTML. Las excepciones internas mantienen el
  diagnóstico original y se tratan al imprimirlo, únicamente en CLI.
  Las dos excepciones dinámicas de `Import::target()` y `Import::restore_files()`
  tienen anotaciones PHPCS solo en la línea del throw: constantes del mapa fijo
  WP_HOME/WP_SITEURL y ruta mirror validada, respectivamente. No aplicar escape
  HTML ni ocultar otros análisis; `scenarios.py` comprueba el diagnóstico de conflicto.

## Secuencia de exportación

1. Validar argumentos, entorno, destino y exclusiones.
2. `Operation::start()` obtiene `GET_LOCK` por base/prefijo y crea temporal privado
   0700. `archive()` crea otro en el sistema de archivos del ZIP final.
   Antes de adquirir el bloqueo guarda CONNECTION_ID y SESSION wait_timeout,
   eleva este último a max(original, 86400) y verifica que el servidor lo aplicó.
3. `Package::create()` exporta tablas y archivos, genera manifiesto e inventario.
   La instantánea InnoDB no es atómica con archivos: evitar escritores concurrentes.
4. La lectura de filas usa `Database::export_rows()`: lotes de 100 con `wpdb`,
   identificadores `%i` y límites `%d` preparados, misma instantánea de solo lectura
   y recorrido clustered InnoDB forzado con `USE INDEX ()`, también sin PK.
   Conserva duplicados, binarios y NULL; OFFSET tiene coste creciente y el límite
   es de filas, no de bytes. `SHOW COUNT(*) WARNINGS` consulta los avisos tras
   INSERT/ALTER sin borrarlos; no hay llamadas directas mysqli en Database.
5. Extraer el ZIP temporal para verificarlo íntegramente; publicar con permiso 0600
   mediante enlace sin sobrescribir el destino. Ejecutar `finish()` siempre.
   `assert_locked()` comprueba conexión original y propietario de GET_LOCK tras
   empaquetar y antes del enlace. Si falla solo la limpieza tras publicar, el error
   aclara que el ZIP ya está verificado y disponible.

## Secuencia de importación

1. Validar argumentos/entorno y rechazar mantenimiento preexistente sin alterarlo.
2. Salvo dry-run, `Process::isolate()` sustituye el proceso con `pcntl_exec` **antes
   de modificar el sitio**. No lanzar un hijo dejando vivo el proceso antiguo:
   sus callbacks de cierre podrían usar código borrado (regresión de Elementor).
3. Sin `pcntl_exec`, detenerse sin escribir y mostrar comando explícito con
   `--skip-plugins --skip-themes --require=.../import-bootstrap.php`.
   El bootstrap comprueba plugin y módulo activos, evita mu-plugins y aplica
   filtros de caché antes de cargar funciones de WordPress. El aislamiento también
   rechaza código de plugins/temas antiguos cargado por configuración externa.
4. Crear contexto destino, obtener bloqueo/temporal, extraer y validar paquete,
   versiones, esquemas existentes, mapa de reemplazos y plan de archivos.
   `Import::target()` resuelve URLs/uploads y conflictos con constantes;
   `Import::plan()` valida esquemas, destinos, filas y fronteras de claves externas,
   y devuelve `map`, `existing`, `files` y `extra_tables`. No cambia archivos ni
   tablas del sitio; la instancia Database sigue siendo propiedad de Operation.
5. `Database::prepare(..., true)` recorre/valida las filas y transformaciones sin
   crear tablas del sitio ni codificar valores SQL innecesariamente. La carga real
   prepara la cabecera INSERT una vez por tabla y reutiliza su lista de columnas.
   Mostrar resumen; dry-run termina aquí con limpieza.
6. Confirmar y crear `.maintenance` de forma exclusiva. Preparar tablas aleatorias
   `dctm_` con filas transformadas; rechazar errores y avisos de conversión SQL.
7. Copiar archivos del plan, borrar extras y retirar directorios extra vacíos de
   hijos a padres con `rmdir`. Preservar raíces estructurales y padres de exclusiones.
   Esta fase pertenece a `Import::restore_files()`; Command conserva los controles
   de bloqueo antes/después, mantenimiento, publicación y finalización. No mover
   la finalización al proceso que cargó el sitio antiguo ni omitir el prepare real.
8. `Database::publish()` publica tablas mediante `RENAME TABLE` y elimina las
   retiradas/extras pertinentes. `extra_tables()` sirve tanto al resumen como al borrado.
9. `Command::finalize()` lanza procesos nuevos: `finalize.php`, `core update-db`,
   `rewrite flush`, `core is-installed`, `verify.php` y `flush-cache.php`.
   No heredar `--require` ni el aislamiento a esos procesos; algunos pasos omiten
   plugins normales/temas expresamente, cachés finales cargan el sitio restaurado.
10. Solo tras éxito retirar mantenimiento. Ante fallo posterior a su creación,
    conservarlo y advertir de estado parcial. `Operation::finish()` intenta todas
     las limpiezas y liberar bloqueo sin ocultar el error original.
     Comprueba conexión/bloqueo antes del mantenimiento, tras cargar las tablas,
     antes de publicar y tras finalizar, antes de retirar mantenimiento.

`Operation::finish()` distingue tablas temporales, ZIP temporal, directorio de
trabajo, bloqueo SQL y restauración del timeout. Intenta cada acción aunque falle
otra; conserva el primer error. Antes de RELEASE_LOCK verifica el propietario y
no readquiere un bloqueo perdido. Restaura el timeout original incluso si falló el
inicio, pero nunca lo aplica a una conexión nueva tras una reconexión de wpdb.
La ampliación de sesión evita desconexiones por inactividad durante compresión,
extracción o copia nativas; no garantiza sobrevivir a fallos de red ni pausas que
superen el timeout ampliado. No modifica GLOBAL wait_timeout.

## Formato y límites que no deben romperse

- ZIP propio `format=dctwpsollers`, versión 1; no compatible con `.wpress`, sin Composer.
  `manifest.json`: contexto, prefijo, exclusiones, tablas e inventario de entradas
  (tamaño, SHA-256, permisos y fecha). Manifiesto limitado a 64 MiB.
- Archivos bajo `content/`; un NDJSON bajo `database/` por tabla, con esquema,
  columnas y valores base64 (NULL separado). No ejecutar un volcado SQL arbitrario.
- Validar rutas, integridad, inventario y colisiones antes de escribir; rechazar
  enlaces simbólicos y paquetes cifrados. SHA-256 no autentica origen: importar
  solo paquetes confiables, que contienen código PHP. ZIP sensible y sin cifrar.
- PHP de 64 bits, ZipArchive, mysqli, InnoDB; sin multisite, vistas, triggers,
  particiones ni almacenamiento externo. Rutas estándar dentro
  de wp-content. No importar WordPress posterior al instalado.
- No migrar núcleo, wp-config.php ni servidor. Conservar código instalado del
  migrador y exclusiones de entorno/cachés/copias. `Files::exclusions()` es la
  referencia de exclusiones predeterminadas, combinadas con las del paquete.
- No excluir options/users/usermeta. Exclusiones de tablas se almacenan como
  sufijos para conservarlas al cambiar de prefijo. No tocar tablas de otros sitios.
- Claves externas: `split_schema()` separa únicamente cláusulas canónicas de
  SHOW CREATE TABLE; el esquema NDJSON v1 conserva las cláusulas originales (los
  lectores antiguos las rechazan). `prepare()` carga sin relaciones y las añade
  después entre tablas temporales con nombres `dctfk_` aleatorios, comprobación
  SQL activa y referencias adaptadas; admite ciclos, autorreferencias y columnas
  compuestas. No preserva nombres de constraints. Una fila huérfana falla antes
  de copiar archivos. Dry-run comprueba sintaxis y padres incluidos, no filas SQL.
  `foreign_boundary()` rechaza relaciones entrantes/salientes que crucen el
  conjunto exportado o el sustituido/eliminado en destino, incluidas otras bases.
  `drop_tables()` permite borrar conjuntos cerrados con ciclos desactivando solo
  en esa sesión las comprobaciones, que restaura en finally.
- Mantener activo DCTWP Sollers al finalizar, respetando estados de módulos
  importados. Restablecer uploads estándar y fijar las URLs individualmente.
- `Values::replace()` valida gramática serializada y recalcula longitudes sin
  instanciar objetos; conserva referencias. Rechazar estructuras truncadas y `C:`
  que necesite reemplazos. No sustituir dominios de correo indiscriminadamente.
- Conservar fecha y permisos seguros de lectura/ejecución: sin bits especiales
  ni escritura de grupo/otros. Paquetes v1 antiguos sin metadatos usan 0644.
- Comprobar escritura mediante `wp_is_writable()`. Conservar las operaciones
  nativas necesarias para streaming, creación exclusiva, permisos privados,
  publicación por enlace/rename local y borrados exactos sin redirección por filtros.
  `Database::query()` no sanea SQL: los llamadores deben preparar valores,
  validar identificadores/esquemas o codificar valores binarios mediante hex.
- Caché final: Elementor activo usa `files_manager->clear_cache()`; después
  `wp_cache_flush()`. No activar plugins ni regenerar todas las páginas. Fallar
  conserva mantenimiento; esto no purga automáticamente CDN/cachés de terceros.
- El bloqueo solo serializa estas operaciones, no cron ni otros escritores.
  Dry-run no garantiza permisos DDL, espacio SQL remoto ni compatibilidad de plugins.
  No borrar por comodín restos `dctm_`/temporales tras una interrupción forzada.

El bootstrap aislado usa `DCTWPSollers::initialize_modules()` antes de registrar el
migrador: respeta la inicialización modular común sin registrar ejecución de otros
módulos. La actualización del almacén de login ya no reside en el coordinador.

## Pruebas y localización de cambios

Rutas y comandos desde la raíz. Referencia detallada:
[matriz y límites](../../tests/migration/README.md).

```sh
python3 -B tests/migration/integration.py --wp-path /var/www/wpdev4.local --http-host wpdev4.local
```

El sitio indicado aporta solo núcleo y credenciales: el runner crea instalaciones
privadas y prefijos aleatorios propios. `--keep-on-failure` conserva el entorno
para diagnóstico; no publicar su configuración privada.

| Cambio | Pruebas a revisar |
|---|---|
| Serialización/URLs | `tests/migration/values.php` |
| ZIP, NDJSON, rutas, permisos, recursos | `tests/migration/libraries.php` |
| DOCUMENT_ROOT en CLI (ausente, vacío, null, público o tipo inválido) | `tests/migration/libraries.php` |
| Diagnósticos CLI y SQL aparente como datos | `tests/migration/libraries.php`, `scenarios.py`, `integration.py` |
| SQL y publicación | `tests/migration/database-contract.php`, `scenarios.py`, `foreign_keys.py` |
| Timeout y bloqueo | `tests/migration/libraries.php`, `database-contract.php`, `operation_guard.py`, `operation-loss.php` |
| Bootstrap/aislamiento | `tests/migration/bootstrap-contract.php`, `mirror.py`, `integration.py` |
| Mirror y directorios/exclusiones | `tests/migration/mirror.py` |
| Cachés y Elementor | `tests/migration/cache.php`, `elementor.py` |
| CLI, fallos, round-trip y HTTP | `tests/migration/scenarios.py`, `integration.py` |

Para Elementor real, añadir
`--elementor-path=/var/www/wpdev4.local/wp-content/plugins/elementor`; usa una copia
desechable. Sin esa opción la integración real queda SKIP, no aprobada.
La suite también ejecuta regresiones de login. Aserciones comunes en
`tests/checks.php`; los recuentos no son porcentajes de cobertura. Los contratos
con dobles SQL no equivalen a integración con permisos reales. No se certifican
discos llenos, ZIP >4 GiB, SIGKILL ni todas las combinaciones de servidor/plugins.

Infraestructura de instalación/limpieza en `tests/support/bootstrap.php`,
`cleanup.php` y `environment.py`; no importar otro runner para reutilizarla.
Los temporales reservados son `dctwpsollers-test-*` y las tablas `dcttest_<12 hex>_`.
La regresión incluye `tests/lifecycle.php`; login, CDN y Frontend también tienen
entradas propias y no necesitan ejecutar migración para preparar su entorno.

Los comandos son referencias, no constancia de pruebas ejecutadas al crear esta memoria.
