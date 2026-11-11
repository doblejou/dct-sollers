# Memoria técnica: CDN

## Alcance y entrada rápida

Módulo `cdn`, desactivado por defecto incluso en instalaciones anteriores. CDN
genérico de un solo dominio: no configura el proveedor, no sincroniza archivos ni
purga cachés. No tiene claves API ni integraciones con proveedores. Leer primero
el `APP.md` raíz; contrastar los contratos afectados con el código.

## Responsabilidades

| Archivo | Responsabilidad |
|---|---|
| `class-module.php` | `DCTWPSollers_CDN_Module`: valores iniciales, validación, lectura, hooks, alcance, búfer y respuestas REST. |
| `class-rewriter.php` | `DCTWPSollers_CDN_Rewriter`: transformación de cadenas con configuración ya validada y orígenes explícitos; sin hooks, opciones ni red. |
| `class-admin.php` | `DCTWPSollers_CDN_Admin`: Settings API, pantalla y comprobación remota explícita. |
| `templates/settings.php` | Formulario y avisos con datos preparados; sin consultas ni lógica de negocio. |

Conexiones desde la raíz: `dct-sollers.php` carga clases y declara callbacks en
`DCTWPSollers::modules()`; administración disponible incluso desactivado. El registro
general y sus plantillas generan automáticamente activación y pestaña. `uninstall.php`
elimina la opción. No hay clase de almacenamiento ni tablas nuevas.

## Persistencia y validación

- Estado: `dctwpsollers_modules['cdn']`; ausencia equivale a desactivado.
  Un estado persistido inválido también queda desactivado mediante la normalización
  central de `DCTWPSollers::module_states()`.
- Opción `dctwpsollers_cdn_settings`: `hostname` vacío, `relative` 1, `exclusions`
  vacío y `extensions` con `.avif`, `.css`, `.gif`, `.jpeg`, `.jpg`, `.js`, `.json`,
  `.mp3`, `.mp4`, `.pdf`, `.png`, `.svg`, `.webp`, `.woff` y `.woff2`.
- Dominio ASCII normalizado a minúsculas, sin protocolo, ruta, puerto, credenciales
  ni IP; los IDN se introducen como punycode. Las URL de origen sí admiten puerto.
- Listas con LF normalizado, sin entradas vacías ni duplicadas. Extensiones
  minúsculas con punto y 1–10 caracteres alfanuméricos; exclusiones literales
  sensibles a mayúsculas. Máximo 16384 bytes por campo de texto y dominio de 253.
- `relative` acepta 0/1 enteros o cadenas. Formularios incompletos, claves extra y
  tipos inválidos se rechazan conservando ajustes anteriores. La lectura completa
  opciones parciales; datos persistidos inválidos vuelven a todos los iniciales,
  con dominio vacío para evitar reescrituras inseguras tras una importación.
- Desactivar conserva la opción; desinstalar la elimina. No hay migración automática
  de ajustes de CDN Enabler ni cambio de opciones al comprobar conectividad.

## Flujo de ejecución e invariantes

1. Solo un módulo activo registra ejecución. CLI no registra captura ni REST.
2. `setup_theme` inicia el búfer si hay dominio. Se omiten peticiones distintas de
   GET, administración, AJAX, cron, login y XML-RPC.
3. El callback acumula fragmentos hasta FINAL y respeta CLEAN. En la salida final
   también omite REST, preview, robots, trackbacks, feeds, estados HTTP >=300,
   descargas, codificación de contenido y respuestas no HTML. Sin Content-Type,
   exige inicio de documento HTML. Con cabeceras ya enviadas deja todo intacto.
4. Una sola instancia del motor por petición compara autoridades exactas de
   `home_url()` y `site_url()`, nunca de `HTTP_HOST`. Reconoce URL http/https,
   sin protocolo, raíz y barras escapadas. Mantiene protocolo, ruta, parámetros,
   fragmentos y escape; no cambia credenciales, autoridades externas, CDN existente,
   rutas relativas sin barra ni extensiones/exclusiones no elegibles.
   Reconoce entidades HTML dentro de los candidatos completos para no ignorar
   exclusiones en parámetros posteriores a `&amp;`, `&#38;` o `&#x26;`.
5. Si cambia HTML, retira Content-Length, ETag y Content-MD5 anteriores. Ante error
   de la expresión regular devuelve el contenido original. El búfer retiene la
   respuesta en memoria; no es un sistema de reescritura para streaming.
6. `rest_api_init` registra `rest_prepare_<tipo>` para tipos `show_in_rest`.
   Reescribe solo `content.rendered` y `excerpt.rendered` de GET; omite context=edit
   y preview. No cambia raw, source_url, links ni JSON arbitrario.

Las URL no se guardan reescritas. No se editan referencias dentro de CSS/JS externos.
Una caché que sirve HTML sin arrancar WordPress no ejecuta el módulo; invalidar caché
de página tras cambiar configuración o estado. Las fuentes necesitan CORS en el
CDN/origen; el aviso no implica configurar o verificar esas cabeceras.

## Administración y comprobación

- Settings API: grupo `dctwpsollers_cdn`, opción anterior, `manage_options` y nonce
  nativos. Guardar no contacta con servidores externos.
- `admin_post_dctwpsollers_cdn_check`: solo POST autenticado, `manage_options` y nonce
  `dctwpsollers_cdn_check`; no existe handler nopriv. Anónimos reciben el rechazo
  nativo de WordPress. Resultados en transient por usuario durante un minuto,
  consumido en la pestaña, sin guardar mensajes remotos sin filtrar.
- `probe()` sustituye la autoridad de `includes_url('images/blank.gif')` y realiza
  `wp_safe_remote_head`, timeout 10s y cero redirecciones. Conserva protocolo del
  origen y Referer del sitio. Se rechazan destinos inseguros según WordPress.
  HTTP 200 solo acredita ese recurso: no certifica contenido, CORS ni todo el CDN.
  HEAD no soportado o una ruta wp-includes no distribuida puede dar fallo aunque
  otros recursos funcionen. No se borra el dominio por un fallo temporal.
- Aviso si `cdn-enabler/cdn-enabler.php` está activo; no desactiva plugins ajenos.
- Sin CSS/JS propios; configuración visible aun desactivado.

## Pruebas (rutas desde la raíz)

- `wp --path=/var/www/wpdev4.local --skip-plugins=dct-sollers eval-file tests/cdn/rewriter.php`:
  motor con el parser `wp_parse_url()` real de WordPress, formatos, extensiones, fuentes,
  srcset, CSS inline, exclusiones, autoridades/puertos e idempotencia.
  Incluye exclusiones tras entidades HTML, también con barras escapadas.
- `tests/cdn/settings.php`: prueba WP que restaura ajustes y avisos.
- `tests/cdn/admin.php`: respuestas HTTP simuladas con `pre_http_request`, sin CDN real.
- `python3 -B tests/cdn/integration.py --wp-path /var/www/wpdev4.local`: copia de
  núcleo y plugin en temporal privado, prefijo SQL aleatorio y servidor PHP local;
  ejecuta módulos, ajustes, diagnóstico simulado y pruebas HTML/REST, nonce,
  permisos, guardado/desactivación y regresión HTTP de login. `--http-host` permite
  otro host .local que resuelva a 127.0.0.1 (por defecto wpdev4.local).
- Reutiliza los helpers acotados `tests/migration/bootstrap.php`, `cleanup.php`
  y funciones Python de ejecución/limpieza. El nombre temporal reservado sigue
  `dctwpsollers-migration-test-*` para sus validaciones de seguridad. Solo lee núcleo y
  credenciales del sitio de referencia; elimina exclusivamente tablas propias y
  su temporal. Una terminación forzada puede impedir la limpieza.

No sustituye pruebas con un proveedor real, CORS en navegador ni cachés/constructores
concretos. Los comandos documentados no implican una ejecución en cada tarea.
