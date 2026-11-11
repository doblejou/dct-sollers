<?php defined('ABSPATH') || exit; ?>
<h2><?php esc_html_e('Elige las herramientas que necesitas', 'dct-sollers'); ?></h2>
<?php settings_errors('dctwpsollers_modules'); ?>
<p><?php esc_html_e('Activa solo lo que vayas a utilizar. Puedes configurar cada módulo antes de activarlo; al desactivarlo se conservan sus ajustes y datos.', 'dct-sollers'); ?></p>
<p class="dctwpsollers-hint"><?php esc_html_e('Marca o desmarca los módulos y pulsa «Guardar módulos» para aplicar los cambios. La migración solo se ejecuta desde la terminal.', 'dct-sollers'); ?></p>
<form method="post" action="options.php">
    <?php settings_fields('dctwpsollers_modules'); ?>
    <div class="dctwpsollers-module-grid">
        <?php foreach ($modules as $id => $dctwpsollers_module): ?>
            <section class="dctwpsollers-module-card" aria-labelledby="dctwpsollers-title-<?php echo esc_attr($id); ?>">
                <span class="dctwpsollers-status <?php echo $states[$id] ? 'is-enabled' : ''; ?>"><?php echo $states[$id] ? esc_html__('Activo', 'dct-sollers') : esc_html__('Desactivado', 'dct-sollers'); ?></span>
                <h3 id="dctwpsollers-title-<?php echo esc_attr($id); ?>"><?php echo esc_html($dctwpsollers_module['label']); ?></h3>
                <p id="dctwpsollers-description-<?php echo esc_attr($id); ?>"><?php echo esc_html($dctwpsollers_module['description']); ?></p>
                <div class="dctwpsollers-card-actions">
                    <input type="hidden" name="dctwpsollers_modules[<?php echo esc_attr($id); ?>]" value="0">
                    <label for="dctwpsollers-module-<?php echo esc_attr($id); ?>">
                        <input type="checkbox" id="dctwpsollers-module-<?php echo esc_attr($id); ?>" name="dctwpsollers_modules[<?php echo esc_attr($id); ?>]" value="1" <?php checked($states[$id]); ?> aria-describedby="dctwpsollers-description-<?php echo esc_attr($id); ?>">
                        <?php
                        /* translators: %s: module name. */
                        echo esc_html(sprintf(__('Activar %s', 'dct-sollers'), $dctwpsollers_module['label']));
                        ?>
                    </label>
                    <?php if (isset($dctwpsollers_module['render'])): ?>
                        <a href="<?php echo esc_url(add_query_arg('tab', $id, admin_url('options-general.php?page=dctwpsollers'))); ?>" aria-labelledby="dctwpsollers-open-<?php echo esc_attr($id); ?> dctwpsollers-title-<?php echo esc_attr($id); ?>"><span id="dctwpsollers-open-<?php echo esc_attr($id); ?>"><?php esc_html_e('Ver detalles', 'dct-sollers'); ?></span> <span aria-hidden="true">&rarr;</span></a>
                    <?php endif; ?>
                </div>
            </section>
        <?php endforeach; ?>
    </div>
    <p class="description"><?php esc_html_e('Los indicadores muestran el estado guardado, no los cambios pendientes.', 'dct-sollers'); ?></p>
    <?php submit_button(__('Guardar módulos', 'dct-sollers')); ?>
</form>
