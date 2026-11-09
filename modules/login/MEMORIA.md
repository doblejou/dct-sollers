# Memoria técnica: protección de login

## Alcance y entrada rápida

Protege únicamente `wp-login.php` por IP. Módulo `login`, activo por defecto.
Consultar primero el `APP.md` raíz para reglas comunes. Esta memoria permite
localizar el cambio sin estudiar el módulo de migración ni toda la infraestructura.
Contrastar en el código los detalles afectados y actualizarla al cambiarlos.

## Mapa de responsabilidades

| Archivo | Responsabilidad / punto de entrada |
|---|---|
| `class-module.php` | `DCTWPHero_Login_Module`: ciclo de vida, IP, hooks y protección. |
| `class-settings.php` | `DCTWPHero_Login_Settings`: valores iniciales, límites, validación y lectura. |
| `class-store.php` | `DCTWPHero_Store`: esquema, migraciones, transacciones, bloqueos y limpieza. El nombre histórico se conserva. |
| `class-admin.php` | `DCTWPHero_Login_Admin`: Settings API, validación administrativa, listado y desbloqueo. |
| `templates/settings.php` | Presentación de ajustes y bloqueos; recibe datos preparados por `render()`. Sin consultas ni reglas de negocio. |

Conexiones externas (rutas desde la raíz):

- `dctwphero.php`: carga las clases y declara callbacks en `DCTWPHero::modules()`.
  `boot()` ejecuta `initialize_modules()` antes de registrar módulos. La administración
  se registra incluso con login desactivado; la protección, solo si está activo.
- No hay delegados de login en `DCTWPHero`: usar `DCTWPHero_Login_Settings::get()`
  y `DCTWPHero_Login_Module::client_ip()` directamente.
- `includes/class-admin.php` y `templates/`: navegación y estados generales,
  no lógica específica de login.
- El callback de registro `initialize` actualiza el almacén y conecta
  `dctwphero_cleanup` a `DCTWPHero_Store::cleanup()`, también con login desactivado.
  Usa un callable estable para no duplicar el hook al reinicializar. Los callbacks
  `activate` y `deactivate` programan o retiran su cron horario; son del ciclo de
  vida del plugin completo, no de la casilla del módulo. Activar prepara el esquema
  antes de programar y no duplica eventos. El coordinador no conoce tabla ni cron.
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

- Opción `dctwphero_settings`: `threshold` (3), `window` (1 minuto), `duration`
  (15 minutos). Mínimo 1; máximos 10000, 10080 y 10080 respectivamente.
  Los valores iniciales no sobrescriben ajustes válidos ya guardados.
- `Settings::validate()` acepta enteros o cadenas de dígitos; rechaza booleanos,
  decimales, incompletos y fuera de rango. `limits()` centraliza los máximos.
- `Settings::get()` completa ajustes persistidos parciales con valores iniciales;
  ante valores inválidos usa todos los iniciales. `sanitize()` rechaza un envío
  inválido, muestra error y conserva los ajustes anteriores normalizados.
- Estado general separado en `dctwphero_modules['login']`; sin estado guardado
  o con un valor inválido se aplica el valor inicial del registro central.
  La normalización compartida reside en `DCTWPHero::module_states()`.
- Tabla InnoDB `$wpdb->prefix . 'dctwphero_ips'`: `ip` (PK varchar(45)),
  `failures` (JSON de timestamps en longtext), `blocked_until` y `updated_at`
  (segundos Unix, bigint unsigned). Índices en ambas columnas temporales.
- `cleanup()` elimina filas sin bloqueo vigente y con `updated_at` anterior a
  la ventana configurada. Un retraso de WP-Cron no prolonga los bloqueos.
- `DB_VERSION = 1`, opción `dctwphero_db_version`. `upgrade()` usa `GET_LOCK`,
  ejecuta pasos secuenciales y guarda la versión tras cada éxito. Rechaza downgrade.
  El paso 1 usa `dbDelta()` y verifica motor, columnas e índices sin borrar filas.
  Para cambiar esquema, aumentar versión y añadir paso; no editar solo el inicial.
  Un fallo llega a `Module::initialize()` y detiene la petición con 503.

## Administración

- Permiso `manage_options`. Settings API: grupo `dctwphero`, opción de ajustes anterior.
  Registra `type=array` y callback explícito `DCTWPHero_Login_Admin::sanitize`.
  `tests/modules.php` verifica el registro y la ejecución vía `sanitize_option()`.
- `render()` prepara paginación de 50 bloqueos por página; orden por expiración
  descendente e IP ascendente en el almacén. También prepara el estado del módulo;
  la plantilla no consulta opciones para decidir su aviso de desactivación.
- `admin_post_dctwphero_unlock`: solo POST, IP validada/normalizada y nonce
  `dctwphero_unlock_<ip>`. Error SQL devuelve 500; éxito redirige a `tab=login`.
- La paginación elimina las barras de WordPress antes de convertir a entero y
  limita la página al rango disponible. La navegación y los avisos GET son de
  solo lectura, sin nonce; el desbloqueo sigue exigiendo POST y nonce.
- Controles nativos de WordPress con CSS3 compartido en `assets/admin.css` (raíz),
  limitado a la pantalla del plugin. Sin JavaScript propio. El listado tiene un
  contenedor desplazable por teclado para pantallas estrechas.
- Los formularios de desbloqueo generan el nonce con `wp_create_nonce()` en un
  campo oculto sin ID, para no repetir identificadores al listar varias IP.

## Dónde tocar y cómo comprobar

Comandos desde la raíz, **solo en pruebas**: las integraciones modifican datos.

- Hooks, alcance o IP: `class-module.php`; regresión HTTP:
  `python3 tests/login/integration.py --wp-path /var/www/wpdev4.local --url http://wpdev4.local`.
- Ajustes: `class-settings.php`, `class-admin.php` y plantilla;
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
  El formulario HTTP de estados incluye todos los módulos actuales, también
  `frontend`, aunque la prueba solo altere la protección de login.
- Entrada independiente recomendada:
  `python3 -B tests/login/run.py --wp-path /var/www/wpdev4.local`.
  Crea su instalación y prefijo SQL propios con `tests/support/`, ejecuta registro,
  `tests/lifecycle.php`, administración, ajustes, almacén, esquema y regresión HTTP.
  El sitio de referencia solo aporta núcleo y credenciales; no se modifica.
  La prueba de ciclo de vida comprueba inicialización con módulos desactivados,
  conservación de ajustes/estados y cron de activación/desactivación reejecutable.
- La suite desechable `python3 -B tests/migration/integration.py --wp-path /var/www/wpdev4.local --http-host wpdev4.local`
  ejecuta también pruebas de ajustes y almacenamiento de login. Véase
  `../../tests/migration/README.md` para cobertura y límites.

Las pruebas comparten `tests/checks.php` (ruta raíz). Los comandos anteriores son
referencias de ejecución, no constancia de pruebas aprobadas en esta tarea.
