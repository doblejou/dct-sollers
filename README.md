# DCT WP Hero

Plugin para WordPress 7.0+ y PHP 8.4+. Sin dependencias externas ni build.
Sus funcionalidades se organizan en módulos independientes; el primero es la
protección de login por IP.
Incluye un módulo de migración exclusivamente WP-CLI, activo por defecto.
Incluye un módulo CDN genérico, desactivado por defecto, sin integración con proveedores.
Incluye Frontend, desactivado por defecto, con limpieza y optimización de carga explícitas.

## Instalación

Copiar `dctwphero.php`, `includes/`, `modules/`, `templates/`, `uninstall.php`,
`readme.txt` y `review-notes.txt` a
`wp-content/plugins/dctwphero/` y activar el plugin. No admite activación en red
multisite en esta versión: la activación se rechaza en esas instalaciones.

En **Ajustes → DCT WP Hero** se configuran:

La pantalla utiliza controles nativos de WordPress, navegación por secciones y
tarjetas con el estado guardado de cada módulo. Su CSS3 propio se carga solo en
la pantalla del plugin; no utiliza frameworks CSS ni JavaScript propio. Es
adaptable a móvil y mantiene indicadores de foco para la navegación por teclado.
La primera, **Módulos**, contiene únicamente la activación de funcionalidades.
Los módulos con ajustes tienen su pestaña dedicada: **Protección de login** muestra
sus ajustes y bloqueos, incluso cuando está desactivado. La versión aparece en todas
las pestañas. Guardar ajustes, paginar o desbloquear conserva la pestaña de login.

- **Módulos:** activa o desactiva cada funcionalidad mediante su casilla y
  «Guardar módulos». La protección de login está activa inicialmente, también
  al actualizar instalaciones anteriores.

- Fallos para bloquear (X): inicialmente 3; rango 1–10000.
- Ventana móvil (Y): inicialmente 1 minuto; rango 1–10080.
- Duración del bloqueo: inicialmente 15 minutos; rango 1–10080.

Estos valores iniciales no sobrescriben los ajustes ya guardados.

Los cambios se aplican a nuevos fallos; los bloqueos activos mantienen su fecha de
expiración. La pantalla incluye IP bloqueadas, fechas y desbloqueo individual.
Solo se aceptan enteros o cadenas de dígitos dentro del rango; booleanos y
decimales se rechazan. Al leer ajustes persistidos incompletos se añaden los
valores iniciales; si contienen tipos o rangos inválidos se usan los valores
iniciales completos, también tras una importación.

Desactivar la protección de login deja de bloquear accesos y de registrar fallos.
No borra los ajustes ni las filas existentes: al reactivarla se aplican los
bloqueos que todavía no hayan expirado. La limpieza de datos caducados y las
migraciones siguen funcionando aunque el módulo esté desactivado. Los ajustes y
el desbloqueo manual siguen disponibles. Los módulos se guardan separadamente
de sus ajustes, mediante la Settings API y con permiso `manage_options` y nonce.
La lectura y la validación comparten el contrato de estados `0`/`1` (enteros o
cadenas). Los valores persistidos inválidos recuperan el valor inicial del módulo:
login y migración activos, CDN desactivado, sin alterar otros estados válidos.

Para añadir un módulo, crear `modules/<id>/class-module.php` con un método `register()`
que conecte sus hooks, cargarla desde `dctwphero.php` y declararla en
`DCTWPHero::modules()` con etiqueta, descripción, estado inicial y callback de
registro, además de callbacks opcionales `admin_register` para conectar su
administración (incluso desactivado) y `render` para su pantalla de ajustes. El listado
de administración y las pestañas se generan a partir de ese registro.
Los callbacks opcionales `initialize`, `activate` y `deactivate` pertenecen al
ciclo de vida de cada módulo: inicialización en cada arranque, incluso desactivado,
y activación/desactivación del plugin completo, respectivamente. Cambiar la casilla
no ejecuta estos dos últimos callbacks ni elimina datos. La inicialización precede
al registro de ejecución; el arranque CLI aislado usa la misma inicialización.
Cada módulo agrupa su lógica, administración, almacenamiento y plantillas en su
propia carpeta. `modules/login/class-module.php` contiene la protección de login;
los ajustes se separan en `class-settings.php`, igual que en CDN y Frontend,
con `defaults()`, `validate()` y `get()`. El login también posee la actualización
de su almacenamiento y su cron desde los callbacks del registro. El coordinador
general ya no expone los antiguos delegados de login `settings()` y `client_ip()`;
usar `DCTWPHero_Login_Settings::get()` y `DCTWPHero_Login_Module::client_ip()`.
Su almacenamiento está en `modules/login/class-store.php`, su administración en
`modules/login/class-admin.php` y su plantilla en `modules/login/templates/settings.php`.
La administración general permanece en `includes/class-admin.php` y sus plantillas
en `templates/admin.php` y `templates/modules.php`. Las plantillas se limitan a la
presentación, sin consultas ni validaciones de negocio.
Reservar `includes/` para infraestructura general y código realmente reutilizado
entre módulos; no introducir dependencias directas entre módulos ni extraer
utilidades compartidas para necesidades hipotéticas. Las pruebas específicas se
organizan en `tests/<id>/`; `tests/modules.php` comprueba el registro general.

### Estructura de carpetas

```text
assets/               CSS3 propio, exclusivo de la pantalla administrativa
includes/             Infraestructura general y código compartido
modules/
  login/              Protección de login, administración y almacenamiento
    templates/        Presentación del módulo de login
  migration/          Migración WP-CLI y administración informativa
    templates/        Ejemplos de uso de migración
  cdn/                Coordinación, reescritura y administración del CDN
    templates/        Ajustes y aviso CORS
  frontend/           Limpieza, hints y precargas mediante API nativas
    templates/        Ajustes y advertencias
templates/            Pantalla general y activación de módulos
tests/
  login/              Pruebas específicas de login
  migration/          Pruebas específicas de migración
  cdn/                Motor, ajustes, diagnóstico e integración HTTP desechable
  frontend/           Validación y escenarios HTTP con runner desechable propio
  support/            Configuración, ejecución, servidor y limpieza compartidos
release/              ZIP instalables versionados
```

## Frontend

En **Ajustes → DCT WP Hero → Frontend**, configurar y activar desde **Módulos**.
Desactivado por defecto, también al actualizar. Todas las casillas empiezan
desmarcadas y todos los campos vacíos. Los ajustes permanecen al desactivar.

- **Limpieza:** recursos de compatibilidad de emojis (no los emojis nativos),
  enlace RSD (no desactiva XML-RPC), shortlinks HTML/cabecera HTTP y Dashicons
  para visitantes no autenticados. No modifica enlaces permanentes ni datos.
  Si el tema usa Dashicons pueden faltar iconos; otros estilos que dependan de
  esa hoja pueden volver a cargarla. No se desregistran esas dependencias.
- **Descubrimiento REST:** retira referencias a la API en HTML y cabeceras HTTP;
  no desactiva sus endpoints. Puede dificultar a clientes descubrir la API.
- **Descubrimiento oEmbed:** retira las etiquetas JSON/XML del HTML. No desactiva
  endpoints, scripts ni incrustaciones existentes; otras webs pueden dejar de
  descubrir cómo incrustar tus entradas.
- **Meta generator:** retira la etiqueta de la cabecera HTML, no el generator
  de feeds ni todas las referencias a versiones. Es cosmético, no seguridad.
- **Descubrimiento RSS de contenido:** retira enlaces HTML generales y de
  categorías, etiquetas, taxonomías, autores, búsquedas y archivos de tipos.
- **Descubrimiento RSS de comentarios:** opción independiente para el enlace
  general y el de cada entrada. No desactiva comentarios ni elimina datos.
  Ambos controles RSS conservan los feeds por URL; lectores y navegadores pueden
  dejar de descubrir suscripciones automáticamente.
- **Preconnect:** hasta 10 orígenes HTTP(S), uno por línea, sin ruta ni parámetros;
  ejemplo `https://cdn.ejemplo.com`. Conexiones anónimas, adecuadas para fuentes;
  no necesariamente reutilizables para peticiones con credenciales.
- **DNS prefetch:** hasta 10 dominios sin protocolo, puerto ni ruta, uno por línea.
  Usar ASCII/punycode. No repetir dominios ya anticipados por preconnect.
- **Fuentes:** hasta 5 URL absolutas HTTP(S) de `.woff2`, una por línea; precarga
  `as=font`, `type=font/woff2` y `crossorigin=anonymous`. Necesitan CORS cuando
  proceda. Usar únicamente fuentes importantes del primer contenido visible.
- **Imagen principal:** una URL absoluta HTTP(S), con extensión avif, gif, jpg,
  jpeg, png, svg o webp. Solo se precarga en la portada no paginada, no en una
  página de entradas separada. No detecta variantes responsive ni el elemento LCP.

Recursos sin credenciales ni fragmentos; se conservan parámetros de recursos.
Campos limitados a 16384 bytes, listas normalizadas sin duplicados. Formularios
incompletos o inválidos conservan todos los ajustes anteriores.
Las cinco casillas nuevas se completan desactivadas al actualizar configuraciones
anteriores, conservando las opciones ya guardadas. Todas las limpiezas muestran
«Qué elimina», «Qué conserva» y «Precaución» junto a la casilla, con ayuda accesible
y sin JavaScript propio. Retirar etiquetas de descubrimiento aporta un ahorro
pequeño, no deshabilita los servicios y no debe presentarse como protección.

Solo actúa en GET del frontend; omite administración, login, REST, AJAX, cron,
CLI, XML-RPC, feeds, previews, personalizador, embeds, robots, trackbacks y 404.
No toca correos ni editor, no añade CSS/JS al sitio público ni modifica `.htaccess`.
Usa `wp_resource_hints` y `wp_preload_resources`, sin servicios ni descargas.
Los hints pueden contactar con terceros desde el navegador antes de usar un recurso:
revisar privacidad y consentimiento. No configura destinos automáticamente.

Usar las mismas URL finales que el tema/CDN, incluidos parámetros; la reescritura
CDN del HTML también procesa precargas elegibles sin acoplar ambos módulos.
No se garantiza coincidencia con URL transformadas por otros plugins o CSS externo.
Verificar descargas duplicadas en el navegador. Precargar demasiado puede empeorar
la carga. Invalidar cachés de página tras cambiar opciones o estado. Si DietPress
está activo se muestra un aviso, sin modificarlo ni importar su configuración.

Memoria: `modules/frontend/MEMORIA.md`. Validación, permisos, nonce, HTTP y
coexistencia con CDN se prueban desde su propia entrada desechable:

```sh
python3 -B tests/frontend/integration.py --wp-path /var/www/wpdev4.local
```

## CDN

Configurar **Ajustes → DCT WP Hero → CDN** y activar después en **Módulos**.
Está desactivado por defecto, también al actualizar. Necesita un CDN ya configurado
para servir las mismas rutas del origen; no configura DNS, sube archivos ni purga
cachés. Desactivar conserva ajustes y deja de reescribir.

- Un dominio ASCII sin protocolo, puerto ni ruta, como `cdn.ejemplo.com` (IDN en
  punycode). Vacío no reescribe. Conserva el protocolo de URL absolutas.
- Extensiones con punto, una por línea. Iniciales: `.avif`, `.css`, `.gif`, `.jpeg`,
  `.jpg`, `.js`, `.json`, `.mp3`, `.mp4`, `.pdf`, `.png`, `.svg`, `.webp`, **`.woff`
  y `.woff2`**. Lista vacía no reescribe.
- Exclusiones literales por cadenas, una por línea y sensibles a mayúsculas.
- URL relativas a la raíz activadas inicialmente; se pueden desmarcar.

**Fuentes y CORS:** el CDN debe enviar `Access-Control-Allow-Origin` permitiendo
el dominio de la web; de lo contrario el navegador puede bloquear las fuentes.
Configurar esas cabeceras en CDN/origen según el proveedor. El módulo muestra un
aviso, pero no modifica ni certifica las cabeceras CORS.

Captura HTML público GET y transforma URL locales absolutas, sin protocolo,
relativas a raíz y con barras escapadas, incluidas las de srcset y CSS inline.
Utiliza `home_url()` y `site_url()` como autoridades exactas, nunca HTTP_HOST.
Conserva rutas, parámetros y fragmentos, omitiendo dominios externos y CDN existente.
No reescribe rutas como `imagenes/foto.jpg` sin barra inicial, administración,
login, AJAX, CLI, cron, XML-RPC, previews, robots, trackbacks, feeds, descargas,
respuestas comprimidas ni tipos distintos de HTML. En REST solo transforma
`content.rendered` y `excerpt.rendered` de tipos de contenido, no context=edit,
raw ni otros campos JSON. El búfer retiene la salida hasta el final y no está
destinado a streaming.

Los ajustes están en `dctwphero_cdn_settings` mediante Options API; la reescritura
no altera las URL guardadas en la base de datos ni archivos. No procesa referencias
internas de CSS/JS externos. **Invalidar la caché de página tras cambiar ajustes o
estado:** el HTML servido sin ejecutar WordPress no puede ser reescrito por el módulo.
No ejecutar simultáneamente con CDN Enabler; la pestaña avisa si está activo,
pero no lo desactiva ni importa sus ajustes automáticamente.

«Comprobar CDN» usa los ajustes guardados y hace una petición HEAD explícita a
`wp-includes/images/blank.gif` mediante la API HTTP segura de WordPress, sin
redirecciones y rechazando destinos inseguros. Solo HTTP 200 se considera éxito.
Un fallo no borra ajustes; un éxito no garantiza todos los recursos ni CORS.
Puede fallar si el CDN no distribuye wp-includes o no admite HEAD. Guardar ajustes
no realiza peticiones externas. Comprobación restringida a POST con nonce y
`manage_options`, disponible incluso con el módulo desactivado.

Detalles técnicos en `modules/cdn/MEMORIA.md`. Pruebas:

```sh
wp --path=/var/www/wpdev4.local --skip-plugins=dctwphero eval-file tests/cdn/rewriter.php
python3 -B tests/cdn/integration.py --wp-path /var/www/wpdev4.local
```

La integración crea un WordPress desechable con prefijo SQL aleatorio y servidor
local, ejecuta también motor, ajustes, administración general y diagnóstico HTTP simulado,
y limpia sus recursos al terminar. Solo utiliza núcleo y credenciales del sitio
indicado; no modifica sus opciones ni sus plugins. Requiere WP-CLI, PHP y un host
`.local` que resuelva a 127.0.0.1 (`--http-host`, por defecto wpdev4.local).
No prueba un proveedor CDN real ni CORS en navegador.

## Migración mediante WP-CLI

Activar **Migración CLI** en la pestaña **Módulos**. Está activo por defecto,
también al actualizar. Desactivarlo deja de registrar sus comandos. La pestaña
**Migración CLI** muestra ejemplos de uso incluso desactivado, sin formularios ni
controles de ejecución. No hay endpoints de migración REST/AJAX; la implementación está aislada en
`modules/migration/` y sus clases operativas no se cargan mediante HTTP.

```sh
wp dctwphero export --file=/ruta/privada/sitio.zip
wp dctwphero import --file=/ruta/privada/sitio.zip --mode=merge --dry-run
wp dctwphero import --file=/ruta/privada/sitio.zip --mode=merge
wp dctwphero import --file=/ruta/privada/sitio.zip --mode=mirror --yes
```

**Importar sobrescribe datos y no crea copia previa ni garantiza rollback.**
La copia del destino es responsabilidad del operador. Solo importar paquetes de
confianza: contienen código PHP y estructuras de base de datos. SHA-256 detecta
corrupción, pero no autentica el origen. Los ZIP contienen datos sensibles sin
cifrar: almacenarlos fuera de cualquier directorio público, con acceso restringido.

### Contenido y requisitos

El formato ZIP propio versión 1 no es compatible con `.wpress`. Contiene
`manifest.json`, archivos bajo `content/` y un volcado NDJSON por tabla bajo
`database/`: esquema `CREATE TABLE`, columnas y valores base64 para conservar
datos binarios. No necesita los plugins de All-in-One ni Composer.

Incluye las tablas del prefijo del sitio y archivos de `wp-content`, incluidos
plugins, temas, uploads y mu-plugins. No incluye el núcleo, `wp-config.php` ni
configuración del servidor. El destino necesita WordPress y DCT WP Hero instalados.
Los usuarios y contraseñas importados reemplazan los del destino.

Requiere PHP de 64 bits con `ZipArchive` y `mysqli`; tablas InnoDB sin vistas,
triggers, particiones ni rutas de almacenamiento externas. Admite claves externas
canónicas de `SHOW CREATE TABLE` entre tablas incluidas, también compuestas y
cíclicas. Se conservan columnas y acciones ON DELETE/UPDATE, con nombres nuevos
de constraint para evitar colisiones. Se reconstruyen sobre tablas temporales y
SQL valida sus filas antes de publicar. Se rechazan relaciones que crucen el
conjunto exportado o sustituido/eliminado hacia tablas conservadas, excluidas o
de otro sitio/base. Dry-run valida estructura y alcance, no integridad referencial
de las filas: esta se comprueba en la carga real antes de copiar archivos.
Los identificadores de tabla y columna deben usar letras ASCII, números, `_` o `$`.
Plugins, temas, mu-plugins y uploads deben estar en sus rutas estándar dentro de
`wp-content`. No admite multisite, enlaces simbólicos en el ámbito migrado,
paquetes cifrados ni importar un WordPress posterior al instalado.

El ZIP de salida requiere una ruta absoluta y un directorio existente. Se rechazan
las rutas públicas conocidas y archivos ya existentes; el operador debe verificar
que el directorio no esté expuesto mediante otros alias del servidor. Se publica
solo tras verificar íntegramente su contenido y con permisos `0600`.
Se conservan fecha y permisos de lectura/ejecución de los archivos, sin bits
especiales ni escritura para grupo u otros. Los ZIP antiguos sin esos metadatos
siguen admitidos y sus archivos usan `0644`.

### Modos, exclusiones y URLs

`--mode` es obligatorio, sin valor predeterminado:

- `merge`: sustituye archivos coincidentes y **tablas incluidas completas**;
  conserva archivos y tablas adicionales. No fusiona filas de tablas.
- `mirror`: hace lo anterior y elimina archivos y tablas extra del ámbito no
  excluido. No borra archivos fuera de `wp-content`, tablas de otros prefijos ni
  componentes excluidos. También elimina directorios sobrantes, de hijos a padres,
  únicamente cuando quedan vacíos. Conserva las raíces `plugins`, `themes`,
  `uploads` y `mu-plugins`, así como los padres de las exclusiones. El paquete no
  registra directorios vacíos del origen; no se reconstruyen salvo esas raíces.

Exportación admite listas separadas por comas:

```sh
wp dctwphero export --file=/ruta/privada/sitio.zip --exclude=uploads/privado,plugins/ejemplo --exclude-tables=wp_tabla_temporal
```

Las rutas son relativas a `wp-content`, sin glob, rutas absolutas ni `..`.
Las tablas se indican con su nombre completo; no se pueden excluir `options`,
`users` ni `usermeta`. Las exclusiones quedan en el manifiesto y se conservan al
importar, también en modo mirror y al cambiar de prefijo.

Exclusiones predeterminadas: `cache`, `upgrade`, `upgrade-temp-backup`,
`ai1wm-backups`, `updraft`, `backups`, `debug.log`, `db.php`, `object-cache.php`,
`advanced-cache.php` y el directorio del propio DCT WP Hero. Las copias de otras
herramientas deben excluirse explícitamente. El código instalado del migrador no
se reemplaza; el plugin permanece activo, pero sus estados de módulos proceden
de los datos importados.

Por defecto conserva `home` y `siteurl` del destino y adapta rutas, URLs de medios,
prefijo y claves de permisos. Se pueden fijar URLs finales con
`--home-url=https://destino.example` y `--site-url=https://destino.example`.
Se rechazan conflictos con `WP_HOME` y `WP_SITEURL`. Los reemplazos contemplan
variantes escapadas/codificadas y longitudes serializadas, sin instanciar objetos.
Si origen comparte home y siteurl pero destino los separa, las coincidencias
ambiguas usan home; las opciones finales se fijan individualmente.
La serialización personalizada `C:` que necesite cambios se rechaza.
No se sustituyen dominios de correo indiscriminadamente.

### Validación y fallos

Antes de la fase destructiva, la importación sustituye el proceso inicial por uno
aislado sin plugins normales, mu-plugins ni temas antiguos. Así se evita ejecutar
al cerrar callbacks de Elementor u otros plugins cuyos archivos se hayan borrado
o reemplazado. Se omiten los drop-ins de caché durante esa fase; los ajustes y
comprobaciones posteriores arrancan procesos normales nuevos con el sitio restaurado.
El trabajador aislado no se hereda a las verificaciones ni a la limpieza de cachés.

Internamente `modules/migration/class-import.php` separa la resolución del destino,
la preparación del plan y la aplicación de archivos. `class-command.php` conserva
la interfaz CLI, la confirmación, el mantenimiento y la secuencia de publicación y
verificación. `class-operation.php` sigue siendo dueño del bloqueo y la limpieza;
`class-files.php` y `class-database.php` conservan sus reglas únicas de archivos y SQL.

El reinicio transparente usa `pcntl_exec` de PHP CLI. Si no está disponible, se
detiene antes de modificar el sitio y se muestra el comando equivalente con
`--skip-plugins --skip-themes --require=.../modules/migration/import-bootstrap.php`.
Ese arranque explícito comprueba también que DCT WP Hero y el módulo estén activos.
Los filtros que omiten los drop-ins no dependen de funciones auxiliares de
WordPress: también funcionan con `WP_CACHE=true`, antes de que esas funciones
estén disponibles.
Si `wp-config.php` o un drop-in carga código de plugins o temas del destino pese
al aislamiento, la importación se rechaza antes de borrar archivos.

`--dry-run` comprueba integridad, rutas, destinos, espacio estimable, esquemas y
transformación de filas. Extrae a temporales privados, pero no escribe archivos
ni tablas del sitio. No garantiza permisos DDL ni espacio en un servidor SQL
remoto, compatibilidad de todos los plugins o ausencia de cambios concurrentes.
Las conversiones SQL que produzcan avisos se consideran errores: no se publican
tablas con valores truncados silenciosamente. La finalización de ajustes se
ejecuta en un proceso nuevo sin los plugins normales antiguos cargados.

Sin `--yes` se pide confirmación después de mostrar el resumen. Durante la
restauración se mantiene `.maintenance`, se preparan tablas aisladas y se publican
por `RENAME TABLE`. Se actualizan URLs, cachés y enlaces permanentes; se comprueban
instalación y base de datos en procesos nuevos. No se modifica `.htaccess`.
La comprobación SQL usa `CHECK TABLE` solo sobre las tablas del prefijo del sitio
y requiere sus permisos; no revisa otras instalaciones de la misma base de datos.
Al terminar, antes de retirar mantenimiento, se limpia la caché de objetos de
WordPress. Si Elementor está activo, primero se invalida su CSS con la misma API
que `wp elementor flush-css`; se regenerará al visitar las páginas. No se activa
Elementor si está desactivado. Esto no purga automáticamente CDN ni todas las
cachés de página de terceros. Un fallo de esta limpieza devuelve error y mantiene
el sitio en mantenimiento.
El usuario SQL debe poder inspeccionar los metadatos relevantes. MySQL/MariaDB
pueden ocultar triggers sin el permiso correspondiente: no se puede certificar la
ausencia de objetos que el servidor no permite ver; comprobar ese requisito al
preparar la migración.

Ante fallo tras comenzar la fase destructiva se devuelve error y se conserva
mantenimiento. Revisar o recuperar manualmente el sitio antes de retirar
`.maintenance`. No hay copia automática. Una terminación forzada puede dejar
temporales privados o tablas aleatorias `dctm_`; no eliminarlos indiscriminadamente,
pues otra migración de otro sitio podría estar en curso. El bloqueo de operación
solo serializa estas migraciones: no detiene cron, workers, WP-CLI ni otros escritores.
Durante la operación se eleva `SESSION wait_timeout` a 24 horas, o se conserva su
valor si ya era mayor, sin cambiar la configuración global. Al terminar se restaura
el valor anterior solo sobre la conexión original. Se verifica la identidad de la
conexión y el propietario de `GET_LOCK` antes de publicar el ZIP y en los puntos
críticos de importación. Una desconexión o pérdida del bloqueo detiene la operación,
sin readquirirlo automáticamente; si ya se iniciaron cambios, conserva mantenimiento.
La limpieza identifica el recurso que falló (temporales, bloqueo o timeout). Si el
ZIP ya estaba publicado y verificado cuando falla la limpieza, el error lo indica:
conservar el paquete y revisar los recursos pendientes antes de repetir la operación.

El procesamiento es por filas/archivos, no por tablas completas en memoria; una
fila muy grande y el inventario sí consumen memoria. La exportación mantiene una
instantánea InnoDB, pero no es atómica con los archivos: evitar escrituras y cambios
de esquema durante toda la operación. Se necesita disco para contenido preparado,
ZIP y verificación; en importación también para las tablas de preparación. No hay
límite comercial de tamaño; el manifiesto se limita a 64 MiB para validación segura.

## Actualizaciones y migraciones

Para distribuir el plugin, crear un ZIP con la carpeta `dctwphero/` que contenga
`dctwphero.php`, `includes/`, `modules/`, `templates/`, `assets/`, `uninstall.php`, `readme.txt`
y `review-notes.txt`.
Excluir pruebas, reglas, releases anteriores y memorias técnicas `MEMORIA.md`. Subirlo desde
**Plugins → Añadir plugin → Subir plugin**. Para actualizar, subir el nuevo ZIP
y confirmar la sustitución del instalado, sin desinstalarlo.

El código se distribuye bajo GPL-2.0-or-later. `readme.txt` contiene la ficha
pública en inglés para WordPress.org; mantener su `Stable tag` sincronizado con
`Version` al preparar una release. Indicar en `Tested up to` únicamente una
versión mayor/menor de WordPress probada.

Antes del envío, ejecutar Plugin Check sobre el paquete de distribución. Para
comprobar el código fuente desde este proyecto sin desplegarlo en el sitio:

```sh
wp --path=/var/www/wpdev4.local plugin check "$PWD" --exclude-directories=tests,release,.ai-rules --exclude-files=.gitignore,AGENTS.md,APP.md,MEMORIA.md --format=json
```

Plugin Check no sustituye la revisión de seguridad ni las pruebas funcionales.
`.gitignore` es configuración del repositorio, no forma parte de la distribución;
por eso se excluye al comprobar el árbol fuente. No excluir archivos PHP del
paquete ni códigos de error para obtener un resultado limpio. Las excepciones
PHPCS de Frontend son solo de línea: el dominio de emojis se compara para quitar
su DNS prefetch, no para alojar contenido, y REQUEST_METHOD se valida por igualdad
estricta sin modificar la entrada. Las variables de iteración de su plantilla
utilizan prefijo propio.
El bootstrap CLI de migración se carga antes de definir `ABSPATH`: su guardia
`WP_CLI` es intencionada. No añadir una exigencia de `ABSPATH` a ese archivo.
Los avisos sobre operaciones nativas de archivos, SQL dinámico y excepciones CLI
del migrador requieren revisión contextual; no deben ocultarse globalmente ni
resolverse aplicando escape HTML al terminal o cambiando garantías de publicación.
`review-notes.txt`, incluido en el ZIP, describe los contratos que justifican las
excepciones PHPCS de línea concretas. `Command::terminal()` convierte controles
en texto visible al imprimir diagnósticos CLI. No utilizar esas excepciones para
código HTTP ni considerar la documentación una aprobación del equipo de revisión.

Hacer una copia de seguridad antes de actualizar y probar primero en un entorno
de pruebas. Desinstalar elimina los datos; sustituir los archivos no los elimina.

La versión del plugin y la del esquema son independientes. La opción
`dctwphero_db_version` guarda el último paso completado. En la activación y en
`plugins_loaded` se ejecutan los pasos pendientes antes de usar el almacenamiento.
La migración 1 crea o adapta la tabla existente mediante `dbDelta()` sin borrar
filas. Las instalaciones anteriores sin esta opción parten de la versión 0.

Para futuros cambios, aumentar `DCTWPHero_Store::DB_VERSION` y añadir el siguiente
caso en `upgrade()`. Mantener los pasos anteriores para admitir saltos de versión.
Cada paso debe ser reejecutable y comprobar su resultado; usar SQL explícito para
renombrados, eliminaciones o transformaciones que `dbDelta()` no resuelva. No
modificar solo el esquema inicial para introducir una nueva migración.

Un bloqueo de MySQL/MariaDB serializa las migraciones concurrentes. La versión se
guarda solo tras completar cada paso. Si falla, la petición se detiene con HTTP
503 y se registra el error; se reintentará en la siguiente petición. Esto afecta
también al frontal mientras la migración esté pendiente. No hay rollback
automático de DDL: corregir el problema y reintentar, o restaurar la copia completa.
Se rechaza ejecutar código antiguo contra un esquema de versión superior.

## Comportamiento

Al alcanzar X fallos en los últimos Y minutos se bloquean los siguientes accesos
a `wp-login.php` con HTTP 403: visualización, autenticación, recuperación de
contraseña y demás acciones de esa ruta. El fallo que alcanza X ya ha sido
procesado; el 403 se aplica a partir de la siguiente petición.

El bloqueo no se prolonga con accesos posteriores. Al expirar o desbloquear
manualmente se reinicia el historial. Un login correcto no borra fallos previos.
REST, XML-RPC y formularios de login externos no están protegidos.

La única fuente de IP es `REMOTE_ADDR`, normalizada para IPv4 e IPv6. Con CDN o
proxy, Apache debe configurar correctamente `mod_remoteip` y sus proxies de
confianza. El plugin no interpreta cabeceras. Si no hay IP válida, deniega el
acceso al login. Una IP compartida (NAT) comparte también el contador y bloqueo.

Se guarda una fila por IP con marcas temporales de fallos, último cambio y
expiración. No se guardan credenciales. InnoDB y bloqueos de fila serializan los
fallos simultáneos. La limpieza horaria depende de WP-Cron y elimina registros
caducados; su retraso no prolonga bloqueos.

Desactivar conserva los datos. Desinstalar elimina la tabla, los ajustes y la
tarea periódica. Un administrador bloqueado puede entrar desde otra IP o utilizar
WP-CLI para desbloquear:

```sh
wp eval 'DCTWPHero_Store::unlock("192.0.2.10");'
```

No sustituye una protección de servidor/WAF frente a ataques distribuidos. Si la
base de datos no está disponible, no se garantiza el registro de nuevos fallos.

## Desarrollo y pruebas

Sintaxis: `find . -name '*.php' -exec php -l {} \;`.

Las pruebas PHP comparten las aserciones de `tests/checks.php`. Los recuentos
indican comprobaciones ejecutadas, no un porcentaje de cobertura.

`tests/admin-ui.php` comprueba las cinco secciones, los identificadores únicos,
las tarjetas, la ayuda de limpieza y el carácter informativo de migración.
Lo ejecuta `tests/cdn/integration.py` en su instalación desechable, junto con
comprobaciones HTTP que limitan el CSS propio a la pantalla del plugin.

Las entradas siguientes son independientes. Solo leen núcleo y credenciales del
WordPress local indicado; cada suite crea una instalación desechable con prefijo
SQL aleatorio propio. No requieren el plugin activo en el sitio de referencia ni
restauran sobre él. La infraestructura común reside en `tests/support/`, no en
una suite de módulo. Una interrupción forzada puede impedir su limpieza.

```sh
python3 -B tests/login/run.py --wp-path /var/www/wpdev4.local
python3 -B tests/cdn/integration.py --wp-path /var/www/wpdev4.local
python3 -B tests/frontend/integration.py --wp-path /var/www/wpdev4.local
python3 -B tests/migration/integration.py --wp-path /var/www/wpdev4.local --http-host wpdev4.local
```

Incluye pruebas HTTP de login, administración, permisos, nonce y desbloqueo,
activación y desactivación de módulos, pruebas concurrentes y `tests/login/store.php`
para ventana móvil, expiración e IPv6.
`tests/login/settings.php` comprueba tipos, límites y lectura segura de ajustes;
`tests/login/store-contract.php` provoca errores SQL en una tabla independiente
para verificar propagación de fallos, rollback y reintentos, incluido `COMMIT`.
Se ejecutan desde `tests/login/run.py` y se conservan como regresión de migración.
`tests/lifecycle.php` verifica inicialización desactivada, cron, activación
reejecutable y conservación de ajustes/estados en los runners de login y migración.
`tests/login/integration.py` sigue siendo el escenario HTTP de bajo nivel: recibe
`--wp-path` y `--url` de una instalación de pruebas ya preparada, no crea una copia.

Validación de estados y compatibilidad con instalaciones anteriores:
`wp --path=/var/www/wpdev4.local eval-file tests/modules.php`. Modifica y restaura
temporalmente la opción de módulos; ejecutar solo en pruebas y sin tráfico.

Migraciones: `wp --path=/var/www/wpdev4.local eval-file tests/login/migrations.php`.
Solo en pruebas y sin tráfico concurrente: usa una tabla temporal independiente,
modifica temporalmente la opción de versión y la restaura al terminar. Comprueba
instalación, adaptación de instalaciones antiguas, reejecución y fallos. Los
mensajes de error de migración de estos casos negativos son esperados.

Pruebas de migración en una copia desechable del núcleo, con tablas de prefijo
aleatorio en la misma base de datos y configuración privada. El WordPress indicado
solo proporciona núcleo y credenciales; **no se restaura sobre él**:

```sh
python3 -B tests/migration/integration.py --wp-path /var/www/wpdev4.local --http-host wpdev4.local
```

`--http-host` es opcional y debe ser un host `.local` que resuelva a `127.0.0.1`.
Habilita un servidor PHP temporal en un puerto libre para ejecutar la regresión
HTTP de login/administración y comprobar que las clases CLI no se cargan por web.
Siempre ejecuta valores serializados, pruebas de almacenamiento/migraciones,
round-trip con dominios/rutas/prefijos diferentes, valores numéricos y binarios,
merge/mirror, exclusiones, dry-run, cancelación, paquetes inseguros, fallo parcial
y desactivación del módulo. Limpia sus tablas y directorios al finalizar.
La matriz y las limitaciones de cobertura están en `tests/migration/README.md`.
Para incluir la prueba de caché con Elementor real, añadir
`--elementor-path=/var/www/wpdev4.local/wp-content/plugins/elementor`; se utiliza
una copia desechable y no se modifica el plugin indicado.
Las integraciones sin permisos SQL se señalan como `SKIP`; no se confunden con
pruebas reales aprobadas. `--keep-on-failure` conserva el entorno privado para
diagnosticar un fallo.
