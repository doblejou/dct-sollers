<?php defined('ABSPATH') || exit; ?>
<h2><?php esc_html_e('Elige las herramientas que necesitas', 'dctwphero'); ?></h2>
<?php settings_errors('dctwphero_modules'); ?>
<p><?php esc_html_e('Activa solo lo que vayas a utilizar. Puedes configurar cada módulo antes de activarlo; al desactivarlo se conservan sus ajustes y datos.', 'dctwphero'); ?></p>
<p class="dctwphero-hint"><?php esc_html_e('Marca o desmarca los módulos y pulsa «Guardar módulos» para aplicar los cambios. La migración solo se ejecuta desde la terminal.', 'dctwphero'); ?></p>
<form method="post" action="options.php">
    <?php settings_fields('dctwphero_modules'); ?>
    <div class="dctwphero-module-grid">
        <?php foreach ($modules as $id => $dctwphero_module): ?>
            <section class="dctwphero-module-card" aria-labelledby="dctwphero-title-<?php echo esc_attr($id); ?>">
                <span class="dctwphero-status <?php echo $states[$id] ? 'is-enabled' : ''; ?>"><?php echo $states[$id] ? esc_html__('Activo', 'dctwphero') : esc_html__('Desactivado', 'dctwphero'); ?></span>
                <h3 id="dctwphero-title-<?php echo esc_attr($id); ?>"><?php echo esc_html($dctwphero_module['label']); ?></h3>
                <p id="dctwphero-description-<?php echo esc_attr($id); ?>"><?php echo esc_html($dctwphero_module['description']); ?></p>
                <div class="dctwphero-card-actions">
                    <input type="hidden" name="dctwphero_modules[<?php echo esc_attr($id); ?>]" value="0">
                    <label for="dctwphero-module-<?php echo esc_attr($id); ?>">
                        <input type="checkbox" id="dctwphero-module-<?php echo esc_attr($id); ?>" name="dctwphero_modules[<?php echo esc_attr($id); ?>]" value="1" <?php checked($states[$id]); ?> aria-describedby="dctwphero-description-<?php echo esc_attr($id); ?>">
                        <?php
                        /* translators: %s: module name. */
                        echo esc_html(sprintf(__('Activar %s', 'dctwphero'), $dctwphero_module['label']));
                        ?>
                    </label>
                    <?php if (isset($dctwphero_module['render'])): ?>
                        <a href="<?php echo esc_url(add_query_arg('tab', $id, admin_url('options-general.php?page=dctwphero'))); ?>" aria-labelledby="dctwphero-open-<?php echo esc_attr($id); ?> dctwphero-title-<?php echo esc_attr($id); ?>"><span id="dctwphero-open-<?php echo esc_attr($id); ?>"><?php esc_html_e('Ver detalles', 'dctwphero'); ?></span> <span aria-hidden="true">&rarr;</span></a>
                    <?php endif; ?>
                </div>
            </section>
        <?php endforeach; ?>
    </div>
    <p class="description"><?php esc_html_e('Los indicadores muestran el estado guardado, no los cambios pendientes.', 'dctwphero'); ?></p>
    <?php submit_button(__('Guardar módulos', 'dctwphero')); ?>
</form>
