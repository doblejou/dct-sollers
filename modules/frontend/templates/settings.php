<?php defined('ABSPATH') || exit; ?>
<h2><?php esc_html_e('Frontend', 'dct-sollers'); ?></h2>
<?php settings_errors('dctwpsollers_frontend_settings'); ?>
<?php if (!$enabled): ?>
    <div class="notice notice-info inline"><p><?php esc_html_e('El módulo Frontend está desactivado. Configúralo aquí y actívalo desde Módulos.', 'dct-sollers'); ?></p></div>
<?php endif; ?>
<?php if ($conflict): ?>
    <div class="notice notice-warning inline"><p><?php esc_html_e('DietPress está activo. No configures la misma limpieza o precarga en ambos plugins. No se modifican sus ajustes automáticamente.', 'dct-sollers'); ?></p></div>
<?php endif; ?>
<form method="post" action="options.php">
    <?php settings_fields('dctwpsollers_frontend'); ?>
    <h3><?php esc_html_e('Limpieza', 'dct-sollers'); ?></h3>
    <p><?php esc_html_e('Cada casilla es independiente y empieza desmarcada. Activa solo lo que no necesites y comprueba tu web después. Quitar enlaces de descubrimiento limpia metadatos: no desactiva los servicios y su ahorro de rendimiento es pequeño.', 'dct-sollers'); ?></p>
    <div class="dctwpsollers-cleanup-grid">
    <?php foreach ($cleanup as $dctwpsollers_frontend_key => $dctwpsollers_frontend_option): ?>
        <input type="hidden" name="dctwpsollers_frontend_settings[<?php echo esc_attr($dctwpsollers_frontend_key); ?>]" value="0">
        <fieldset class="dctwpsollers-cleanup-card">
            <legend><strong><label for="dctwpsollers-frontend-<?php echo esc_attr($dctwpsollers_frontend_key); ?>"><input type="checkbox" id="dctwpsollers-frontend-<?php echo esc_attr($dctwpsollers_frontend_key); ?>" name="dctwpsollers_frontend_settings[<?php echo esc_attr($dctwpsollers_frontend_key); ?>]" value="1" aria-describedby="dctwpsollers-frontend-<?php echo esc_attr($dctwpsollers_frontend_key); ?>-help" <?php checked($settings[$dctwpsollers_frontend_key], 1); ?>> <?php echo esc_html($dctwpsollers_frontend_option['label']); ?></label></strong></legend>
            <details class="dctwpsollers-cleanup-help">
            <summary><?php esc_html_e('Qué elimina, qué conserva y precauciones', 'dct-sollers'); ?></summary>
            <dl id="dctwpsollers-frontend-<?php echo esc_attr($dctwpsollers_frontend_key); ?>-help">
                <dt><strong><?php esc_html_e('Qué elimina', 'dct-sollers'); ?></strong></dt>
                <dd><?php echo esc_html($dctwpsollers_frontend_option['removes']); ?></dd>
                <dt><strong><?php esc_html_e('Qué conserva', 'dct-sollers'); ?></strong></dt>
                <dd><?php echo esc_html($dctwpsollers_frontend_option['preserves']); ?></dd>
                <dt><strong><?php esc_html_e('Precaución', 'dct-sollers'); ?></strong></dt>
                <dd><?php echo esc_html($dctwpsollers_frontend_option['caution']); ?></dd>
            </dl>
            </details>
        </fieldset>
    <?php endforeach; ?>
    </div>
    <h3><?php esc_html_e('Optimización', 'dct-sollers'); ?></h3>
    <p><?php esc_html_e('Los campos vacíos no añaden optimizaciones. Anticipar recursos innecesarios puede empeorar la carga. No se añaden dominios de terceros automáticamente ni se realizan descargas desde el servidor.', 'dct-sollers'); ?></p>
    <?php foreach ($fields as $dctwpsollers_frontend_key => $dctwpsollers_frontend_label): ?>
        <div class="dctwpsollers-field">
        <p><label for="dctwpsollers-frontend-<?php echo esc_attr($dctwpsollers_frontend_key); ?>"><?php echo esc_html($dctwpsollers_frontend_label); ?></label></p>
        <p><textarea class="large-text code" id="dctwpsollers-frontend-<?php echo esc_attr($dctwpsollers_frontend_key); ?>" name="dctwpsollers_frontend_settings[<?php echo esc_attr($dctwpsollers_frontend_key); ?>]" rows="3" maxlength="16384"><?php echo esc_textarea($settings[$dctwpsollers_frontend_key]); ?></textarea></p>
        </div>
    <?php endforeach; ?>
    <?php submit_button(__('Guardar ajustes', 'dct-sollers')); ?>
</form>
<p class="description"><?php esc_html_e('Usa las mismas URL finales que el tema y el CDN, incluidos parámetros. La reescritura CDN del HTML también alcanza estas precargas cuando el recurso cumple sus reglas. Comprueba en el navegador que no haya descargas duplicadas. Limpia la caché de página tras cambiar ajustes o estado. Desactivar conserva los ajustes.', 'dct-sollers'); ?></p>
<p class="description"><?php esc_html_e('Los hints y precargas pueden conectar el navegador con terceros antes de que se utilice el recurso. Configura solo destinos de confianza compatibles con tu política de privacidad y consentimiento. Se admiten dominios ASCII o IDN en punycode.', 'dct-sollers'); ?></p>
