<?php defined('ABSPATH') || exit; ?>
    <?php settings_errors('dctwpsollers_settings'); ?>
    <?php
    // Read-only notice; the unlock handler checks capability, POST and nonce.
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    if (isset($_GET['unlocked'])): ?>
        <div class="notice notice-success is-dismissible"><p><?php esc_html_e('IP desbloqueada e historial reiniciado.', 'dct-sollers'); ?></p></div>
    <?php endif; ?>
    <?php if (!$enabled): ?>
        <div class="notice notice-warning inline"><p><?php esc_html_e('La protección de login está desactivada: no bloquea accesos ni registra fallos. Se conservan los ajustes y los datos. Al reactivarla, volverán a aplicarse los bloqueos que no hayan expirado.', 'dct-sollers'); ?></p></div>
    <?php endif; ?>
    <h2><?php esc_html_e('Protección de wp-login.php', 'dct-sollers'); ?></h2>
    <p><?php esc_html_e('Al alcanzar el umbral de fallos se bloquea todo acceso a wp-login.php. La IP real debe ser proporcionada por el servidor en REMOTE_ADDR.', 'dct-sollers'); ?></p>
    <form method="post" action="options.php">
        <?php settings_fields('dctwpsollers'); ?>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><label for="dctwpsollers-threshold"><?php esc_html_e('Fallos para bloquear (X)', 'dct-sollers'); ?></label></th>
                <td><input id="dctwpsollers-threshold" type="number" min="1" max="<?php echo esc_attr($limits['threshold']); ?>" required name="dctwpsollers_settings[threshold]" value="<?php echo esc_attr($settings['threshold']); ?>"> <span class="description">1–<?php echo esc_html($limits['threshold']); ?></span></td>
            </tr>
            <tr>
                <th scope="row"><label for="dctwpsollers-window"><?php esc_html_e('Ventana de fallos (Y, minutos)', 'dct-sollers'); ?></label></th>
                <td><input id="dctwpsollers-window" type="number" min="1" max="<?php echo esc_attr($limits['window']); ?>" required name="dctwpsollers_settings[window]" value="<?php echo esc_attr($settings['window']); ?>"> <span class="description">1–<?php echo esc_html($limits['window']); ?></span></td>
            </tr>
            <tr>
                <th scope="row"><label for="dctwpsollers-duration"><?php esc_html_e('Duración del bloqueo (minutos)', 'dct-sollers'); ?></label></th>
                <td><input id="dctwpsollers-duration" type="number" min="1" max="<?php echo esc_attr($limits['duration']); ?>" required name="dctwpsollers_settings[duration]" value="<?php echo esc_attr($settings['duration']); ?>"> <span class="description">1–<?php echo esc_html($limits['duration']); ?></span></td>
            </tr>
        </table>
        <?php submit_button(); ?>
    </form>
    <h2><?php esc_html_e('IP bloqueadas', 'dct-sollers'); ?> (<?php echo esc_html($count); ?>)</h2>
    <p><?php esc_html_e('Solo se muestran bloqueos activos. Desbloquear también reinicia los fallos de esa IP.', 'dct-sollers'); ?></p>
    <div class="dctwpsollers-table-scroll" role="region" aria-label="<?php esc_attr_e('Listado de IP bloqueadas', 'dct-sollers'); ?>" tabindex="0">
    <table class="widefat striped">
        <caption class="screen-reader-text"><?php esc_html_e('Direcciones IP con bloqueo activo', 'dct-sollers'); ?></caption>
        <thead><tr>
            <th scope="col"><?php esc_html_e('IP', 'dct-sollers'); ?></th>
            <th scope="col"><?php esc_html_e('Bloqueada desde', 'dct-sollers'); ?></th>
            <th scope="col"><?php esc_html_e('Expira', 'dct-sollers'); ?></th>
            <th scope="col"><?php esc_html_e('Acción', 'dct-sollers'); ?></th>
        </tr></thead>
        <tbody>
        <?php foreach ($blocks as $dctwpsollers_block): ?>
            <tr>
                <td><?php echo esc_html($dctwpsollers_block->ip); ?></td>
                <td><?php echo esc_html(wp_date('Y-m-d H:i:s T', (int) $dctwpsollers_block->updated_at)); ?></td>
                <td><?php echo esc_html(wp_date('Y-m-d H:i:s T', (int) $dctwpsollers_block->blocked_until)); ?></td>
                <td>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <input type="hidden" name="action" value="dctwpsollers_unlock">
                        <input type="hidden" name="ip" value="<?php echo esc_attr($dctwpsollers_block->ip); ?>">
                        <input type="hidden" name="_wpnonce" value="<?php echo esc_attr(wp_create_nonce('dctwpsollers_unlock_' . $dctwpsollers_block->ip)); ?>">
                        <button type="submit" class="button" aria-label="<?php
                            /* translators: %s: blocked IP address. */
                            echo esc_attr(sprintf(__('Desbloquear %s', 'dct-sollers'), $dctwpsollers_block->ip));
                        ?>"><?php esc_html_e('Desbloquear', 'dct-sollers'); ?></button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$blocks): ?>
            <tr><td colspan="4"><?php esc_html_e('No hay IP bloqueadas.', 'dct-sollers'); ?></td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
    <?php if ($pages > 1): ?>
        <nav aria-label="<?php esc_attr_e('Páginas de IP bloqueadas', 'dct-sollers'); ?>">
<?php
            $dctwpsollers_pagination = array();
            $dctwpsollers_pagination['base'] = add_query_arg('paged', '%#%', admin_url('options-general.php?page=dctwpsollers&tab=login'));
            $dctwpsollers_pagination['current'] = $page;
            $dctwpsollers_pagination['total'] = $pages;
            echo wp_kses_post(paginate_links($dctwpsollers_pagination));
            ?>
        </nav>
    <?php endif; ?>
