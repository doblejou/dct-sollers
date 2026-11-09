# Memoria técnica: protección de login

## Alcance y entrada rápida

Protege únicamente `wp-login.php` por IP. Módulo `login`, activo por defecto.
Consultar primero el `APP.md` raíz para reglas comunes. Esta memoria permite
localizar el cambio sin estudiar el módulo de migración ni toda la infraestructura.
Contrastar en el código los detalles afectados y actualizarla al cambiarlos.

## Mapa de responsabilidades

| Archivo | Responsabilidad / punto de entrada |
|---|---|
| `class-module.php` | `DCTWPSollers_Login_Module`: ajustes, límites, IP, hooks y protección. |
| `class-store.php` | `DCTWPSollers_Store`: esquema, migraciones, transacciones, bloqueos y limpieza. El nombre histórico se conserva. |
| `class-admin.php` | `DCTWPSollers_Login_Admin`: Settings API, validación administrativa, listado y desbloqueo. |
| `templates/settings.php` | Presentación de ajustes y bloqueos; recibe datos preparados por `render()`. Sin consultas ni reglas de negocio. |

Conexiones externas (rutas desde la raíz):

- `dctwpsollers.php`: carga las clases y declara callbacks en `DCTWPSollers::modules()`.
  `boot()` ejecuta `maybe_upgrade()` antes de registrar módulos. La administración
  se registra incluso con login desactivado; la protección, solo si está activo.
- `DCTWPSollers::settings()` y `DCTWPSollers::client_ip()` delegan en este módulo.
- `includes/class-admin.php` y `templates/`: navegación y estados generales,
  no lógica específica de login.
- El hook global `dctwpsollers_cleanup` llama a `DCTWPSollers_Store::cleanup()`.
  La activación del plugin programa su ejecución horaria; la desactivación la retira.
- `uninstall.php`: elimina tabla, opciones del plugin y tarea periódica.

## Flujo y contratos de seguridad

1. `register()` conecta `login_init` con `check_access()` en prioridad 0 y
   `wp_login_failed` con `failed_login()`.
2. `client_ip()` valida y normaliza IPv4/IPv6 exclusivamente desde `REMOTE_ADDR`.
   No interpretar cabeceras de proxy: la IP real corresponde a Apache/mod_remoteip.
3. `check_access()` devuelve 403 si no hay IP válida o el bloqueo sigue vigente.
   Afecta a todas las acciones de esa ruta, incluida recuperación de contraseña.
4. `failed_login()` exige módulo activo, `$pagenow === 'wp-login.php'` y ejecución
   previa de `login_init`; si falla la escritura registra un error.
5. `record_failure()` abre transacción, asegura la fila y lee con `FOR UPDATE`.
   Toma de nuevo la hora tras obtener la fila: la espera SQL no acorta el bloqueo
   ni conserva fallos que hayan salido de la ventana durante esa espera.
   Conserva fallos con timestamp estrictamente mayor que el inicio de la ventana,
   añade el actual y bloquea al alcanzar el umbral. Comprueba también `COMMIT`;
   ante errores cancela la transacción.

Invariantes:

- El fallo que alcanza el umbral ya se procesó; el 403 empieza en la petición siguiente.
- Un bloqueo vigente no se prolonga con nuevos accesos. Al expirar se reinicia el
  historial en el siguiente fallo; el desbloqueo manual también lo vacía.
- Un login correcto no borra fallos. Una IP compartida comparte contador.
- No ampliar a REST, XML-RPC ni formularios externos sin un cambio explícito de alcance.
- Desactivar el módulo conserva ajustes y filas. Siguen disponibles administración,
  desbloqueo, migraciones de esquema y limpieza; al reactivar rigen bloqueos vigentes.

## Ajustes y datos persistidos

- Opción `dctwpsollers_settings`: `threshold` (3), `window` (1 minuto), `duration`
  (15 minutos). Mínimo 1; máximos 10000, 10080 y 10080 respectivamente.
  Los valores iniciales no sobrescriben ajustes válidos ya guardados.
- `validate_settings()` acepta enteros o cadenas de dígitos; rechaza booleanos,
  decimales, incompletos y fuera de rango. `limits()` centraliza los máximos.
- `settings()` completa ajustes persistidos parciales con valores iniciales;
  ante valores inválidos usa todos los iniciales. `sanitize()` rechaza un envío
  inválido, muestra error y conserva los ajustes anteriores normalizados.
- Estado general separado en `dctwpsollers_modules['login']`; sin estado guardado
  o con un valor inválido se aplica el valor inicial del registro central.
  La normalización compartida reside en `DCTWPSollers::module_states()`.
- Tabla InnoDB `$wpdb->prefix . 'dctwpsollers_ips'`: `ip` (PK varchar(45)),
  `failures` (JSON de timestamps en longtext), `blocked_until` y `updated_at`
  (segundos Unix, bigint unsigned). Índices en ambas columnas temporales.
- `cleanup()` elimina filas sin bloqueo vigente y con `updated_at` anterior a
  la ventana configurada. Un retraso de WP-Cron no prolonga los bloqueos.
- `DB_VERSION = 1`, opción `dctwpsollers_db_version`. `upgrade()` usa `GET_LOCK`,
  ejecuta pasos secuenciales y guarda la versión tras cada éxito. Rechaza downgrade.
  El paso 1 usa `dbDelta()` y verifica motor, columnas e índices sin borrar filas.
  Para cambiar esquema, aumentar versión y añadir paso; no editar solo el inicial.
  Un fallo llega a `maybe_upgrade()` y detiene la petición con 503.

## Administración

- Permiso `manage_options`. Settings API: grupo `dctwpsollers`, opción de ajustes anterior.
- `render()` prepara paginación de 50 bloqueos por página; orden por expiración
  descendente e IP ascendente en el almacén. También prepara el estado del módulo;
  la plantilla no consulta opciones para decidir su aviso de desactivación.
- `admin_post_dctwpsollers_unlock`: solo POST, IP validada/normalizada y nonce
  `dctwpsollers_unlock_<ip>`. Error SQL devuelve 500; éxito redirige a `tab=login`.
- La paginación elimina las barras de WordPress antes de convertir a entero y
  limita la página al rango disponible. La navegación y los avisos GET son de
  solo lectura, sin nonce; el desbloqueo sigue exigiendo POST y nonce.
- Usar estilos y controles nativos de WordPress, sin CSS ni JavaScript propios.

## Dónde tocar y cómo comprobar

La identidad pública es DCTWP Sollers y el prefijo técnico `dctwpsollers`.
No hay lectura ni migración de opciones, tablas o cron del plugin anterior.
`tests/modules.php` verifica cabecera, dominio de traducción y tabla propia.

Comandos desde la raíz, **solo en pruebas**: las integraciones modifican datos.

- Hooks, alcance o IP: `class-module.php`; regresión HTTP:
  `python3 tests/login/integration.py --wp-path /var/www/wpdev4.local --url http://wpdev4.local`.
- Ajustes: `class-module.php`, `class-admin.php` y plantilla;
  `tests/login/settings.php` cubre tipos, límites y lectura de datos inválidos.
  La integración HTTP también comprueba paginación con arrays, cero, negativos,
  páginas excesivas, texto y barras, sin exigir nonce para navegación de solo lectura.
- Concurrencia, ventana y caducidad: `class-store.php`;
  `tests/login/store.php` y `tests/login/store-contract.php` cubren comportamiento
  y fallos SQL, incluidos rollback, reintento y COMMIT.
  El contrato simula también una espera SQL antes de leer la fila para comprobar
  que la duración se calcula después de obtenerla.
- Esquema: `wp --path=/var/www/wpdev4.local eval-file tests/login/migrations.php`.
  Usa tabla independiente y restaura la opción de versión; sin tráfico concurrente.
- Registro general: `wp --path=/var/www/wpdev4.local eval-file tests/modules.php`.
- La suite desechable `python3 -B tests/migration/integration.py --wp-path /var/www/wpdev4.local --http-host wpdev4.local`
  ejecuta también pruebas de ajustes y almacenamiento de login. Véase
  `../../tests/migration/README.md` para cobertura y límites.

Las pruebas comparten `tests/checks.php` (ruta raíz). Los comandos anteriores son
referencias de ejecución, no constancia de pruebas aprobadas en esta tarea.
