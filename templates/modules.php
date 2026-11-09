<?php defined('ABSPATH') || exit; ?>
<h2><?php esc_html_e('Módulos', 'dctwpsollers'); ?></h2>
<?php settings_errors('dctwpsollers_modules'); ?>
<p><?php esc_html_e('Activa o desactiva las funcionalidades del plugin. Las pestañas contienen ajustes o ejemplos de uso; las operaciones CLI solo se ejecutan desde la terminal. Desactivar un módulo conserva sus ajustes y datos.', 'dctwpsollers'); ?></p>
<form method="post" action="options.php">
    <?php settings_fields('dctwpsollers_modules'); ?>
    <table class="form-table" role="presentation">
        <?php foreach ($modules as $id => $dctwpsollers_module): ?>
            <tr>
                <th scope="row"><?php echo esc_html($dctwpsollers_module['label']); ?></th>
                <td>
                    <input type="hidden" name="dctwpsollers_modules[<?php echo esc_attr($id); ?>]" value="0">
                    <label for="dctwpsollers-module-<?php echo esc_attr($id); ?>">
                        <input type="checkbox" id="dctwpsollers-module-<?php echo esc_attr($id); ?>" name="dctwpsollers_modules[<?php echo esc_attr($id); ?>]" value="1" <?php checked($states[$id]); ?> aria-describedby="dctwpsollers-description-<?php echo esc_attr($id); ?>">
                        <?php
                        /* translators: %s: module name. */
                        echo esc_html(sprintf(__('Activar %s', 'dctwpsollers'), $dctwpsollers_module['label']));
                        ?>
                    </label>
                    <p class="description" id="dctwpsollers-description-<?php echo esc_attr($id); ?>"><?php echo esc_html($dctwpsollers_module['description']); ?></p>
                </td>
            </tr>
        <?php endforeach; ?>
    </table>
    <?php submit_button(__('Guardar módulos', 'dctwpsollers')); ?>
</form>
