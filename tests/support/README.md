# Infraestructura de pruebas

Código compartido por los runners, sin importar suites de otros módulos:

- `environment.py`: ejecución de comandos, limpieza y contexto `web_site()` con
  copia del núcleo/plugin, tema de prueba, servidor PHP y sesiones HTTP propias.
- `bootstrap.php`: lee credenciales del WordPress de referencia y escribe solo
  el `wp-config.php` privado de la instalación desechable.
- `cleanup.php`: elimina exclusivamente tablas de esa instalación.

## Salvaguardas

Los temporales se crean con `mkdtemp`, bajo `/tmp/opencode` cuando es escribible y
en `/tmp` en otro caso. Se reservan carpetas `dctwphero-test-*` con instalaciones
`wordpress` o `wordpress-target` y prefijos SQL `dcttest_<12 hex>_`.
Bootstrap y limpieza PHP comprueban ruta real y prefijo antes de operar. La
limpieza Python también valida la carpeta antes de borrar; si falla la limpieza
SQL, conserva el temporal para diagnóstico. No ampliar esas rutas a producción.

El contexto HTTP termina su servidor y limpia tablas/directorios incluso ante
errores. Una terminación forzada puede impedirlo. Los temporales contienen
credenciales: no publicarlos ni borrarlos mediante comodines amplios.

## Entradas independientes (desde la raíz)

```sh
python3 -B tests/login/run.py --wp-path /var/www/wpdev4.local
python3 -B tests/cdn/integration.py --wp-path /var/www/wpdev4.local
python3 -B tests/frontend/integration.py --wp-path /var/www/wpdev4.local
python3 -B tests/migration/integration.py --wp-path /var/www/wpdev4.local --http-host wpdev4.local
```

Cada entrada crea su propio entorno; se pueden ejecutar por separado. Frontend
comprueba explícitamente la coexistencia con CDN sin ejecutar su runner. Migración
conserva regresiones de login sobre el sitio restaurado. Sus opciones adicionales
y límites se documentan en `../migration/README.md`.
