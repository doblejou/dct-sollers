# Memoria técnica: Frontend

## Alcance y responsabilidades

Módulo `frontend`, desactivado inicialmente también al actualizar. Implementación
propia basada en API de WordPress; DietPress es solo inspiración conceptual.
Consultar el `APP.md` raíz antes de modificarlo.

| Archivo | Responsabilidad |
|---|---|
| `class-settings.php` | Valores iniciales, validación completa, normalización y lectura de Options API. |
| `class-module.php` | Contextos públicos, retirada de hooks/estilos, hints y precargas nativas. |
| `class-admin.php` | Registro Settings API, validación del formulario, preparación de campos y avisos. |
| `templates/settings.php` | Presentación escapada sin consultas ni lógica de negocio. |

`dctwphero.php` carga las clases y declara registro/administración/pestaña. La
administración general sigue sin lógica del módulo. No hay dependencias directas
con CDN ni otros módulos; el búfer CDN transforma el HTML que genera WordPress.

## Persistencia y contratos

- Estado en `dctwphero_modules['frontend']`, valor inicial 0.
- Opción `dctwphero_frontend_settings`, sin tablas ni cron. Desactivar conserva;
  `uninstall.php` elimina la opción.
- `emojis`, `rsd`, `shortlinks`, `dashicons`: 0/1 entero o cadena, inicialmente 0.
- `preconnect`, `dns_prefetch`, `fonts`, `image`: cadenas vacías inicialmente,
  máximo 16384 bytes cada una. Se normalizan saltos de línea y duplicados.
- Hasta 10 preconnect HTTP(S) sin ruta (barra final admitida y normalizada),
  query, fragmento ni credenciales; hostname ASCII, puerto explícito admitido.
- Hasta 10 DNS hostnames con punto, ASCII/punycode, sin IP, protocolo o puerto.
- Hasta 5 fuentes absolutas HTTP(S), extensión `.woff2`; una imagen absoluta
  avif/gif/jpg/jpeg/png/svg/webp. Parámetros conservados, fragmentos/credenciales
  rechazados. No se verifican MIME, existencia, CORS ni contenido remoto.
- Formularios completos, sin claves extra; validación inválida conserva la opción
  anterior. Lectura parcial completa valores; corrupción recupera todos los iniciales.
- Settings API grupo `dctwphero_frontend`, permiso `manage_options`, nonce nativo.
  Pestaña disponible desactivado; avisa si `wpo-tweaks/wpo-tweaks.php` está activo.

## Flujo e invariantes

1. Registro activo conecta `template_redirect` prioridad 0. Solo GET público:
   excluye admin, AJAX, cron, CLI, REST, XML-RPC, login, feeds, preview,
   personalizador, embeds, robots, trackbacks y 404.
2. Retira hooks frontend de emojis (incluido `wp_enqueue_emoji_styles`, usado por
   WordPress actual), RSD y shortlink. La cabecera shortlink se evita retirando
   el callback de prioridad 11, antes de que se ejecute. No se bloquea XML-RPC.
3. Dashicons solo para anónimos: dequeue tardío en enqueue y antes de imprimir;
   no deregister, por lo que una dependencia puede volver a exigir la hoja.
4. `wp_resource_hints` amplía listas nativas. Emojis retira exactamente `s.w.org`
   del DNS prefetch, admitiendo entradas string/array, sin coincidencias por prefijo.
   Preconnect anónimo; no garantiza reutilización de peticiones con credenciales.
5. `wp_preload_resources` añade fuentes con tipo/CORS y una imagen con prioridad
   alta únicamente `is_front_page() && !is_paged()`. La API nativa escapa y
   deduplica por href. No inspecciona srcset ni decide automáticamente el LCP.
6. No modifica bases de datos de contenido, archivos, servidor ni plugins ajenos.
   No realiza peticiones remotas. El navegador puede contactar con destinos
   configurados; documentar privacidad/consentimiento y no anticipar terceros sin necesidad.

La plantilla utiliza variables de iteración prefijadas. Las excepciones PHPCS
son de línea y específicas: dos comparaciones del host de emojis para retirar
hints (no offloading) y la comprobación estricta de REQUEST_METHOD (no se refleja
ni persiste). No se ocultan sniffs por archivo ni se añaden saneamientos que
conviertan un método rechazado en GET. Justificación pública en `../../review-notes.txt`.

El CDN reescribe precargas si coinciden origen, extensión y exclusiones. Una URL
precargada debe coincidir con el recurso real, incluidos parámetros. No afirmar
que otros plugins o CSS externo tendrán siempre las mismas URL. Invalidar caché
de página tras cambios; no hay purga de proveedores. No se comparte código de
DietPress ni se importan sus ajustes.

## Pruebas (rutas desde raíz)

- `tests/frontend/settings.php`: validación, corrupción, límites, rechazo y conservación.
- `tests/frontend/fixture.php`: tema mínimo con `wp_head`, `wp_footer` y Dashicons.
- `tests/frontend/frontend_scenarios.py`: HTTP real, portada estática/blog,
  precargas, CDN, permisos, nonce y desactivación conservando configuración.
- `python3 -B tests/cdn/integration.py --wp-path /var/www/wpdev4.local` ejecuta
  estos escenarios tras la regresión CDN/login en su instalación desechable.
  Usa prefijo aleatorio y helpers acotados de migración para crear/limpiar el entorno;
  no modifica la instalación de referencia. Las pruebas no descargan destinos externos.
- `tests/modules.php` y `tests/login/integration.py` incluyen el nuevo estado.

No sustituye mediciones de rendimiento, pruebas de red del navegador, CORS real,
consentimiento, temas/constructores arbitrarios ni pruebas con DietPress activo.
