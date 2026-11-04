<?php defined('ABSPATH') || exit; ?>
<section id="dctwphero-migration-usage" aria-labelledby="dctwphero-migration-title">
<h2 id="dctwphero-migration-title"><?php esc_html_e('Migración CLI', 'dctwphero'); ?></h2>
<p><?php esc_html_e('Ejemplos para ejecutar desde la terminal, en el directorio de WordPress. Esta pestaña es solo informativa: no exporta ni importa datos.', 'dctwphero'); ?></p>
<?php if (!$enabled): ?>
    <div class="notice notice-warning inline">
        <p><?php esc_html_e('El módulo está desactivado. Actívalo en la pestaña Módulos para disponer de estos comandos.', 'dctwphero'); ?></p>
    </div>
<?php endif; ?>
<p><strong><?php esc_html_e('Importar sobrescribe datos. No se crea copia automática previa ni se garantiza rollback. Utiliza únicamente ZIP de confianza generados por DCT WP Hero.', 'dctwphero'); ?></strong></p>

<h3><?php esc_html_e('Exportar el sitio', 'dctwphero'); ?></h3>
<p><?php esc_html_e('Elige una ruta absoluta fuera del directorio público y un nombre de archivo que todavía no exista.', 'dctwphero'); ?></p>
<pre><code><?php echo esc_html('wp dctwphero export --file=/ruta/privada/sitio.zip'); ?></code></pre>

<h3><?php esc_html_e('Validar sin importar', 'dctwphero'); ?></h3>
<pre><code><?php echo esc_html('wp dctwphero import --file=/ruta/privada/sitio.zip --mode=merge --dry-run'); ?></code></pre>

<h3><?php esc_html_e('Importar conservando archivos y tablas adicionales', 'dctwphero'); ?></h3>
<p><?php esc_html_e('merge sustituye los archivos coincidentes y las tablas incluidas completas; no fusiona filas.', 'dctwphero'); ?></p>
<pre><code><?php echo esc_html('wp dctwphero import --file=/ruta/privada/sitio.zip --mode=merge'); ?></code></pre>

<h3><?php esc_html_e('Importar eliminando archivos y tablas adicionales', 'dctwphero'); ?></h3>
<p><?php esc_html_e('mirror elimina los elementos adicionales dentro del ámbito migrado. Las exclusiones se conservan.', 'dctwphero'); ?></p>
<pre><code><?php echo esc_html('wp dctwphero import --file=/ruta/privada/sitio.zip --mode=mirror'); ?></code></pre>

<h3><?php esc_html_e('Consultar la ayuda', 'dctwphero'); ?></h3>
<pre><code><?php echo esc_html("wp help dctwphero export\nwp help dctwphero import"); ?></code></pre>
<p><?php esc_html_e('La importación conserva por defecto las URLs del destino. Puedes indicar --home-url y --site-url para cambiarlas. Añade --yes únicamente si quieres confirmar automáticamente la importación.', 'dctwphero'); ?></p>
<p><?php esc_html_e('Al finalizar se limpia la caché de objetos de WordPress y, si Elementor está activo, se invalida su CSS para regenerarlo en las siguientes visitas.', 'dctwphero'); ?></p>
</section>
