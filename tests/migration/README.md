# Pruebas de migración

```sh
python3 -B tests/migration/integration.py --wp-path /var/www/wpdev4.local --http-host wpdev4.local
```

El WordPress indicado solo proporciona núcleo y credenciales SQL. Se restauran
dos instalaciones privadas desechables con prefijos aleatorios propios. El runner
elimina sus tablas y directorios al terminar; una interrupción forzada puede
impedirlo. No se conceden privilegios SQL ni se instalan dependencias.

`--keep-on-failure` conserva el entorno para diagnóstico si falla una prueba. La
ruta mostrada contiene configuración privada: no publicarla y limpiarla después
con las salvaguardas de `cleanup.php`, nunca mediante comodines amplios.

## Organización

- `../checks.php`: aserciones compartidas con login y módulos, con recuento real.
- `values.php`: gramática serializada, referencias y reemplazos.
- `libraries.php`: contrato ZIP/NDJSON, esquemas, archivos y limpieza.
  Incluye identificadores SQL rechazados y escape de controles de terminal sin
  cambiar texto UTF-8 normal.
- `database-contract.php`: respuestas SQL controladas para ramas que requieren
  privilegios no siempre disponibles; los dobles no ejecutan SQL.
- `bootstrap-contract.php`: filtros de caché antes de cargar las funciones de
  WordPress, ejecutado directamente con PHP y sin modificar ningún sitio.
- `cache.php`: caché de objetos y contrato de Elementor con dobles, incluidos fallos.
- `elementor.py`: invalidación CSS y metadatos con una copia real de Elementor.
- `mirror.py`: limpieza de directorios e importación con plugins, temas y
  mu-plugins del destino que cargan código adicional durante el cierre.
- `scenarios.py`: argumentos inválidos, paquetes alterados y errores SQL reales.
- `foreign_keys.py`: claves compuestas, ciclos, autorreferencias, CASCADE/SET NULL,
  cambio de prefijo, exclusiones y referencias de otros prefijos; huérfanos y
  limpieza de tablas temporales que ya tienen relaciones tras una carga fallida.
- `operation_guard.py`, `operation-loss.php`: pérdida real de GET_LOCK durante
  exportación y antes de publicar tablas; ZIP no publicado o diagnóstico explícito
  de fallo de limpieza posterior, y mantenimiento según la fase de importación.
- `integration.py`: ciclo completo, aislamiento y regresión HTTP opcional.
- `bootstrap.php` y `cleanup.php`: configuración y limpieza limitadas a pruebas.
  El entorno activa `WP_CACHE` para ejecutar también el filtro temprano que impide
  cargar `advanced-cache.php` durante la importación aislada.

## Matriz de escenarios

| Área | Comprobaciones |
|---|---|
| Registro | Activación inicial y sin estado previo, recuperación de estados corruptos, desactivación y ausencia de clases CLI por HTTP |
| Administración | Pestaña de ejemplos, también desactivado, sin operaciones web; permisos, nonce y regresión de login |
| Comandos | Ayuda, argumentos desconocidos, rutas relativas, valores inválidos y modo obligatorio |
| Exportación | ZIP privado, exclusiones duplicadas, rechazo de sobrescritura y destino público |
| Componentes | Plugins activos, tema, mu-plugin y uploads; código del migrador conservado |
| Integridad | SHA-256, tamaños, versión, archivos ausentes/extra, ZIP ilegible y NDJSON incoherente |
| Rutas | Traversal, rutas especiales, enlaces y colisiones archivo/directorio o por mayúsculas |
| Modos | merge conserva extras; mirror elimina archivos y directorios; segunda ejecución sin extras; exclusiones y sus ancestros preservados |
| Datos | IDs, decimales, NULL, bigint unsigned, binarios, texto con apariencia SQL tratado como datos y columnas generadas |
| Archivos | Contenido de 6 MiB, permisos de ejecución, fecha y copia atómica por archivo |
| Reemplazos | Dominios/rutas/prefijos distintos; URLs escapadas/codificadas, rechazo de controles y barras invertidas en URLs, y permisos de usuario |
| Serialización | Escalares, arrays, objetos sin instanciación, referencias R/r, enums, C, formatos inválidos, truncados sin delimitador final y profundidad |
| SQL | MyISAM, vistas, triggers y errores/avisos por conversión fuera de rango; claves externas internas conservadas y relaciones con tablas ajenas/conservadas rechazadas |
| Concurrencia | Rechazo de segunda operación con bloqueo ocupado; timeout de sesión ampliado/restaurado sin cambiar el global, reconexión detectada, pérdida de bloqueo impide publicar y no lo readquiere |
| Recursos | Limpieza idempotente; error original conservado y bloqueo liberado si falla limpiar temporales |
| Permisos | Bits especiales rechazados; fechas y ejecución conservadas; directorios transitables con umask restrictiva |
| Fallos | Cancelación, mantenimiento previo conservado, fallo antes de publicar y fallo posterior con mantenimiento |
| Dry-run | Sin sustituir tablas, borrar extras ni cambiar contenido del sitio |
| Cachés | Flush de objetos; Elementor ausente/inactivo/activo, CSS y metadatos invalidados, fallo con mantenimiento |
| Aislamiento | Ningún cierre antiguo intenta cargar clases borradas; comprobaciones y cachés arrancan sin heredar el trabajador aislado |
| Arranque | Sin pcntl_exec se rechaza antes de escribir y funciona el arranque explícito; no permite saltarse un módulo desactivado |
| Diagnósticos | Rutas rechazadas con secuencias de terminal se imprimen como texto visible; helpers sin WordPress/WP_CLI no ejecutan operaciones |
| Ajustes de login | Mínimos/máximos por campo, tipos rechazados, normalización, ajustes incompletos y valores persistidos inválidos |
| Almacenamiento de login | Fallos SQL reales en inicio, inserción, lectura, actualización y COMMIT; rollback, reintento, duración tras espera SQL simulada, desbloqueo fallido y paginación de 51 bloqueos sobre tabla independiente |

Para comprobar además una copia real de Elementor, sin modificar su instalación:

```sh
python3 -B tests/migration/integration.py --wp-path /var/www/wpdev4.local --http-host wpdev4.local --elementor-path /var/www/wpdev4.local/wp-content/plugins/elementor
```

Se copia Elementor al destino desechable, se bloquean las solicitudes HTTP de
WordPress durante esa prueba y se comprueban los estados activo/desactivado y un
fallo deliberado de la limpieza CSS. También se elimina Elementor activo mediante
mirror y se comprueba que desaparece su directorio sin errores al cerrar. Sin `--elementor-path`, esa integración se
marca como `SKIP`; las pruebas del contrato con dobles se ejecutan siempre.

## Límites

Los recuentos son aserciones ejecutadas, **no porcentajes de cobertura de líneas,
ramas ni de todos los casos posibles**. Sin Xdebug, PCOV u otro motor de cobertura
no se publica un porcentaje PHP ni se instalan herramientas automáticamente.

Si falta permiso para crear vistas o triggers, se marca la integración como `SKIP`;
sus rechazos se comprueban además con dobles de consulta. No se presenta una prueba
omitida como una integración real aprobada.

No se llenan discos reales ni se dan por comprobados ZIP mayores de 4 GiB, todas
las versiones MySQL/MariaDB/PHP, servidores SQL remotos, todas las collations,
sistemas de archivos, plugins de terceros ni terminaciones SIGKILL. Los casos
fuera del contrato se rechazan; una interrupción forzada requiere revisión manual.
