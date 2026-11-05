<?php defined('ABSPATH') || exit; ?>
<h2><?php esc_html_e('Frontend', 'dctwphero'); ?></h2>
<?php settings_errors('dctwphero_frontend_settings'); ?>
<?php if (!$enabled): ?>
    <div class="notice notice-info inline"><p><?php esc_html_e('El módulo Frontend está desactivado. Configúralo aquí y actívalo desde Módulos.', 'dctwphero'); ?></p></div>
<?php endif; ?>
<?php if ($conflict): ?>
    <div class="notice notice-warning inline"><p><?php esc_html_e('DietPress está activo. No configures la misma limpieza o precarga en ambos plugins. No se modifican sus ajustes automáticamente.', 'dctwphero'); ?></p></div>
<?php endif; ?>
<form method="post" action="options.php">
    <?php settings_fields('dctwphero_frontend'); ?>
    <h3><?php esc_html_e('Limpieza', 'dctwphero'); ?></h3>
    <?php foreach ($cleanup as $dctwphero_frontend_key => $dctwphero_frontend_label): ?>
        <p>
            <input type="hidden" name="dctwphero_frontend_settings[<?php echo esc_attr($dctwphero_frontend_key); ?>]" value="0">
            <label for="dctwphero-frontend-<?php echo esc_attr($dctwphero_frontend_key); ?>"><input type="checkbox" id="dctwphero-frontend-<?php echo esc_attr($dctwphero_frontend_key); ?>" name="dctwphero_frontend_settings[<?php echo esc_attr($dctwphero_frontend_key); ?>]" value="1" <?php checked($settings[$dctwphero_frontend_key], 1); ?>> <?php echo esc_html($dctwphero_frontend_label); ?></label>
        </p>
    <?php endforeach; ?>
    <h3><?php esc_html_e('Optimización', 'dctwphero'); ?></h3>
    <p><?php esc_html_e('Los campos vacíos no añaden optimizaciones. Anticipar recursos innecesarios puede empeorar la carga. No se añaden dominios de terceros automáticamente ni se realizan descargas desde el servidor.', 'dctwphero'); ?></p>
    <?php foreach ($fields as $dctwphero_frontend_key => $dctwphero_frontend_label): ?>
        <p><label for="dctwphero-frontend-<?php echo esc_attr($dctwphero_frontend_key); ?>"><?php echo esc_html($dctwphero_frontend_label); ?></label></p>
        <p><textarea class="large-text code" id="dctwphero-frontend-<?php echo esc_attr($dctwphero_frontend_key); ?>" name="dctwphero_frontend_settings[<?php echo esc_attr($dctwphero_frontend_key); ?>]" rows="3" maxlength="16384"><?php echo esc_textarea($settings[$dctwphero_frontend_key]); ?></textarea></p>
    <?php endforeach; ?>
    <?php submit_button(__('Guardar ajustes', 'dctwphero')); ?>
</form>
<p class="description"><?php esc_html_e('Usa las mismas URL finales que el tema y el CDN, incluidos parámetros. La reescritura CDN del HTML también alcanza estas precargas cuando el recurso cumple sus reglas. Comprueba en el navegador que no haya descargas duplicadas. Limpia la caché de página tras cambiar ajustes o estado. Desactivar conserva los ajustes.', 'dctwphero'); ?></p>
<p class="description"><?php esc_html_e('Los hints y precargas pueden conectar el navegador con terceros antes de que se utilice el recurso. Configura solo destinos de confianza compatibles con tu política de privacidad y consentimiento. Se admiten dominios ASCII o IDN en punycode.', 'dctwphero'); ?></p>
