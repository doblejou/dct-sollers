<?php defined('ABSPATH') || exit; ?>
<h2><?php esc_html_e('CDN', 'dctwpsollers'); ?></h2>
<?php settings_errors('dctwpsollers_cdn_settings'); ?>
<?php if (!$enabled): ?>
    <div class="notice notice-info inline"><p><?php esc_html_e('El módulo CDN está desactivado. Puedes configurar y comprobar el CDN antes de activarlo desde Módulos.', 'dctwpsollers'); ?></p></div>
<?php endif; ?>
<?php if ($conflict): ?>
    <div class="notice notice-warning inline"><p><?php esc_html_e('CDN Enabler está activo. No actives ambos motores de reescritura simultáneamente. Este módulo no desactiva otros plugins automáticamente.', 'dctwpsollers'); ?></p></div>
<?php endif; ?>
<?php if (is_array($notice) && isset($notice['success'], $notice['message'])): ?>
    <div class="notice <?php echo $notice['success'] ? 'notice-success' : 'notice-error'; ?> inline"><p><?php echo esc_html($notice['message']); ?></p></div>
<?php endif; ?>
<p><?php esc_html_e('Reescribe las URL de recursos locales en el HTML generado y en el contenido renderizado de REST. Configura previamente el CDN para servir las mismas rutas desde tu sitio de origen.', 'dctwpsollers'); ?></p>
<form method="post" action="options.php">
    <?php settings_fields('dctwpsollers_cdn'); ?>
    <table class="form-table" role="presentation">
        <tr>
            <th scope="row"><label for="dctwpsollers-cdn-hostname"><?php esc_html_e('Dominio CDN', 'dctwpsollers'); ?></label></th>
            <td>
                <input type="text" class="regular-text code" id="dctwpsollers-cdn-hostname" name="dctwpsollers_cdn_settings[hostname]" value="<?php echo esc_attr($settings['hostname']); ?>" maxlength="253" aria-describedby="dctwpsollers-cdn-hostname-help">
                <p class="description" id="dctwpsollers-cdn-hostname-help"><?php esc_html_e('Ejemplo: cdn.ejemplo.com. Sin protocolo, puerto ni ruta. Vacío no reescribe recursos. Se conserva el protocolo de las URL originales.', 'dctwpsollers'); ?></p>
            </td>
        </tr>
        <tr>
            <th scope="row"><label for="dctwpsollers-cdn-extensions"><?php esc_html_e('Extensiones incluidas', 'dctwpsollers'); ?></label></th>
            <td>
                <textarea class="regular-text code" id="dctwpsollers-cdn-extensions" name="dctwpsollers_cdn_settings[extensions]" rows="9" maxlength="16384" aria-describedby="dctwpsollers-cdn-extensions-help dctwpsollers-cdn-cors"><?php echo esc_textarea($settings['extensions']); ?></textarea>
                <p class="description" id="dctwpsollers-cdn-extensions-help"><?php esc_html_e('Una extensión con punto por línea, por ejemplo .webp. Una lista vacía no reescribe recursos. Incluye .woff y .woff2 por defecto.', 'dctwpsollers'); ?></p>
                <p id="dctwpsollers-cdn-cors"><strong><?php esc_html_e('Fuentes y CORS:', 'dctwpsollers'); ?></strong> <?php esc_html_e('Para servir fuentes desde otro dominio, el CDN debe enviar una cabecera Access-Control-Allow-Origin que permita el dominio de tu web. Si no está configurada correctamente, el navegador puede bloquear las fuentes. Configúrala en el CDN o en el servidor de origen según el proveedor; este módulo no modifica esas cabeceras.', 'dctwpsollers'); ?></p>
            </td>
        </tr>
        <tr>
            <th scope="row"><label for="dctwpsollers-cdn-exclusions"><?php esc_html_e('Cadenas excluidas', 'dctwpsollers'); ?></label></th>
            <td>
                <textarea class="regular-text code" id="dctwpsollers-cdn-exclusions" name="dctwpsollers_cdn_settings[exclusions]" rows="5" maxlength="16384" aria-describedby="dctwpsollers-cdn-exclusions-help"><?php echo esc_textarea($settings['exclusions']); ?></textarea>
                <p class="description" id="dctwpsollers-cdn-exclusions-help"><?php esc_html_e('Una cadena por línea, por ejemplo /privado/. Coincidencias literales sensibles a mayúsculas; no son expresiones regulares.', 'dctwpsollers'); ?></p>
            </td>
        </tr>
        <tr>
            <th scope="row"><?php esc_html_e('URL relativas a la raíz', 'dctwpsollers'); ?></th>
            <td>
                <input type="hidden" name="dctwpsollers_cdn_settings[relative]" value="0">
                <label for="dctwpsollers-cdn-relative"><input type="checkbox" id="dctwpsollers-cdn-relative" name="dctwpsollers_cdn_settings[relative]" value="1" <?php checked($settings['relative'], 1); ?>> <?php esc_html_e('Reescribir rutas que empiezan por /, como /wp-content/uploads/foto.webp.', 'dctwpsollers'); ?></label>
            </td>
        </tr>
    </table>
    <?php submit_button(__('Guardar ajustes', 'dctwpsollers')); ?>
</form>
<h3><?php esc_html_e('Comprobar CDN', 'dctwpsollers'); ?></h3>
<p><?php esc_html_e('Comprueba mediante HEAD el archivo wp-includes/images/blank.gif con el dominio guardado. Guarda primero tus cambios. No modifica la configuración ni comprueba todos los recursos o las cabeceras CORS. Se rechazan destinos privados y redirecciones.', 'dctwpsollers'); ?></p>
<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
    <input type="hidden" name="action" value="dctwpsollers_cdn_check">
    <?php wp_nonce_field('dctwpsollers_cdn_check'); ?>
    <?php submit_button(__('Comprobar CDN', 'dctwpsollers'), 'secondary', 'submit', false); ?>
</form>
<p class="description"><?php esc_html_e('Después de cambiar ajustes o activar/desactivar el módulo, limpia las cachés de página que utilices. La reescritura no modifica las URL almacenadas en la base de datos, los archivos ni las referencias internas de CSS o JavaScript externos. El CDN y su caché se administran fuera de este plugin.', 'dctwpsollers'); ?></p>
