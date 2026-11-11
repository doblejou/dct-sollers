<?php defined('ABSPATH') || exit; ?>
<section id="dctwpsollers-migration-usage" aria-labelledby="dctwpsollers-migration-title">
<h2 id="dctwpsollers-migration-title"><?php esc_html_e('Migración CLI', 'dct-sollers'); ?></h2>
<p><?php esc_html_e('Ejemplos para ejecutar desde la terminal, en el directorio de WordPress. Esta pestaña es solo informativa: no exporta ni importa datos.', 'dct-sollers'); ?></p>
<?php if (!$enabled): ?>
    <div class="notice notice-warning inline">
        <p><?php esc_html_e('El módulo está desactivado. Actívalo en la pestaña Módulos para disponer de estos comandos.', 'dct-sollers'); ?></p>
    </div>
<?php endif; ?>
<p><strong><?php esc_html_e('Importar sobrescribe datos. No se crea copia automática previa ni se garantiza rollback. Utiliza únicamente ZIP de confianza generados por DCT Sollers.', 'dct-sollers'); ?></strong></p>

<h3><?php esc_html_e('Exportar el sitio', 'dct-sollers'); ?></h3>
<p><?php esc_html_e('Elige una ruta absoluta fuera del directorio público y un nombre de archivo que todavía no exista.', 'dct-sollers'); ?></p>
<pre><code><?php echo esc_html('wp dct-sollers export --file=/ruta/privada/sitio.zip'); ?></code></pre>

<h3><?php esc_html_e('Validar sin importar', 'dct-sollers'); ?></h3>
<pre><code><?php echo esc_html('wp dct-sollers import --file=/ruta/privada/sitio.zip --mode=merge --dry-run'); ?></code></pre>

<h3><?php esc_html_e('Importar conservando archivos y tablas adicionales', 'dct-sollers'); ?></h3>
<p><?php esc_html_e('merge sustituye los archivos coincidentes y las tablas incluidas completas; no fusiona filas.', 'dct-sollers'); ?></p>
<pre><code><?php echo esc_html('wp dct-sollers import --file=/ruta/privada/sitio.zip --mode=merge'); ?></code></pre>

<h3><?php esc_html_e('Importar eliminando archivos y tablas adicionales', 'dct-sollers'); ?></h3>
<p><?php esc_html_e('mirror elimina los elementos adicionales dentro del ámbito migrado. Las exclusiones se conservan.', 'dct-sollers'); ?></p>
<pre><code><?php echo esc_html('wp dct-sollers import --file=/ruta/privada/sitio.zip --mode=mirror'); ?></code></pre>

<h3><?php esc_html_e('Consultar la ayuda', 'dct-sollers'); ?></h3>
<pre><code><?php echo esc_html("wp help dct-sollers export\nwp help dct-sollers import"); ?></code></pre>
<p><?php esc_html_e('La importación conserva por defecto las URLs del destino. Puedes indicar --home-url y --site-url para cambiarlas. Añade --yes únicamente si quieres confirmar automáticamente la importación.', 'dct-sollers'); ?></p>
<p><?php esc_html_e('Al finalizar se limpia la caché de objetos de WordPress y, si Elementor está activo, se invalida su CSS para regenerarlo en las siguientes visitas.', 'dct-sollers'); ?></p>
</section>
