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
    <p><?php esc_html_e('Cada casilla es independiente y empieza desmarcada. Activa solo lo que no necesites y comprueba tu web después. Quitar enlaces de descubrimiento limpia metadatos: no desactiva los servicios y su ahorro de rendimiento es pequeño.', 'dctwphero'); ?></p>
    <div class="dctwphero-cleanup-grid">
    <?php foreach ($cleanup as $dctwphero_frontend_key => $dctwphero_frontend_option): ?>
        <input type="hidden" name="dctwphero_frontend_settings[<?php echo esc_attr($dctwphero_frontend_key); ?>]" value="0">
        <fieldset class="dctwphero-cleanup-card">
            <legend><strong><label for="dctwphero-frontend-<?php echo esc_attr($dctwphero_frontend_key); ?>"><input type="checkbox" id="dctwphero-frontend-<?php echo esc_attr($dctwphero_frontend_key); ?>" name="dctwphero_frontend_settings[<?php echo esc_attr($dctwphero_frontend_key); ?>]" value="1" aria-describedby="dctwphero-frontend-<?php echo esc_attr($dctwphero_frontend_key); ?>-help" <?php checked($settings[$dctwphero_frontend_key], 1); ?>> <?php echo esc_html($dctwphero_frontend_option['label']); ?></label></strong></legend>
            <dl id="dctwphero-frontend-<?php echo esc_attr($dctwphero_frontend_key); ?>-help">
                <dt><strong><?php esc_html_e('Qué elimina', 'dctwphero'); ?></strong></dt>
                <dd><?php echo esc_html($dctwphero_frontend_option['removes']); ?></dd>
                <dt><strong><?php esc_html_e('Qué conserva', 'dctwphero'); ?></strong></dt>
                <dd><?php echo esc_html($dctwphero_frontend_option['preserves']); ?></dd>
                <dt><strong><?php esc_html_e('Precaución', 'dctwphero'); ?></strong></dt>
                <dd><?php echo esc_html($dctwphero_frontend_option['caution']); ?></dd>
            </dl>
        </fieldset>
    <?php endforeach; ?>
    </div>
    <h3><?php esc_html_e('Optimización', 'dctwphero'); ?></h3>
    <p><?php esc_html_e('Los campos vacíos no añaden optimizaciones. Anticipar recursos innecesarios puede empeorar la carga. No se añaden dominios de terceros automáticamente ni se realizan descargas desde el servidor.', 'dctwphero'); ?></p>
    <?php foreach ($fields as $dctwphero_frontend_key => $dctwphero_frontend_label): ?>
        <div class="dctwphero-field">
        <p><label for="dctwphero-frontend-<?php echo esc_attr($dctwphero_frontend_key); ?>"><?php echo esc_html($dctwphero_frontend_label); ?></label></p>
        <p><textarea class="large-text code" id="dctwphero-frontend-<?php echo esc_attr($dctwphero_frontend_key); ?>" name="dctwphero_frontend_settings[<?php echo esc_attr($dctwphero_frontend_key); ?>]" rows="3" maxlength="16384"><?php echo esc_textarea($settings[$dctwphero_frontend_key]); ?></textarea></p>
        </div>
    <?php endforeach; ?>
    <?php submit_button(__('Guardar ajustes', 'dctwphero')); ?>
</form>
<p class="description"><?php esc_html_e('Usa las mismas URL finales que el tema y el CDN, incluidos parámetros. La reescritura CDN del HTML también alcanza estas precargas cuando el recurso cumple sus reglas. Comprueba en el navegador que no haya descargas duplicadas. Limpia la caché de página tras cambiar ajustes o estado. Desactivar conserva los ajustes.', 'dctwphero'); ?></p>
<p class="description"><?php esc_html_e('Los hints y precargas pueden conectar el navegador con terceros antes de que se utilice el recurso. Configura solo destinos de confianza compatibles con tu política de privacidad y consentimiento. Se admiten dominios ASCII o IDN en punycode.', 'dctwphero'); ?></p>
