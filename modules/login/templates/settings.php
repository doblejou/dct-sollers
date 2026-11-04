<?php defined('ABSPATH') || exit; ?>
    <?php settings_errors('dctwphero_settings'); ?>
    <?php
    // Read-only notice; the unlock handler checks capability, POST and nonce.
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    if (isset($_GET['unlocked'])): ?>
        <div class="notice notice-success is-dismissible"><p><?php esc_html_e('IP desbloqueada e historial reiniciado.', 'dctwphero'); ?></p></div>
    <?php endif; ?>
    <?php if (!$enabled): ?>
        <div class="notice notice-warning inline"><p><?php esc_html_e('La protección de login está desactivada: no bloquea accesos ni registra fallos. Se conservan los ajustes y los datos. Al reactivarla, volverán a aplicarse los bloqueos que no hayan expirado.', 'dctwphero'); ?></p></div>
    <?php endif; ?>
    <h2><?php esc_html_e('Protección de wp-login.php', 'dctwphero'); ?></h2>
    <p><?php esc_html_e('Al alcanzar el umbral de fallos se bloquea todo acceso a wp-login.php. La IP real debe ser proporcionada por el servidor en REMOTE_ADDR.', 'dctwphero'); ?></p>
    <form method="post" action="options.php">
        <?php settings_fields('dctwphero'); ?>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><label for="dctwphero-threshold"><?php esc_html_e('Fallos para bloquear (X)', 'dctwphero'); ?></label></th>
                <td><input id="dctwphero-threshold" type="number" min="1" max="<?php echo esc_attr($limits['threshold']); ?>" required name="dctwphero_settings[threshold]" value="<?php echo esc_attr($settings['threshold']); ?>"> <span class="description">1–<?php echo esc_html($limits['threshold']); ?></span></td>
            </tr>
            <tr>
                <th scope="row"><label for="dctwphero-window"><?php esc_html_e('Ventana de fallos (Y, minutos)', 'dctwphero'); ?></label></th>
                <td><input id="dctwphero-window" type="number" min="1" max="<?php echo esc_attr($limits['window']); ?>" required name="dctwphero_settings[window]" value="<?php echo esc_attr($settings['window']); ?>"> <span class="description">1–<?php echo esc_html($limits['window']); ?></span></td>
            </tr>
            <tr>
                <th scope="row"><label for="dctwphero-duration"><?php esc_html_e('Duración del bloqueo (minutos)', 'dctwphero'); ?></label></th>
                <td><input id="dctwphero-duration" type="number" min="1" max="<?php echo esc_attr($limits['duration']); ?>" required name="dctwphero_settings[duration]" value="<?php echo esc_attr($settings['duration']); ?>"> <span class="description">1–<?php echo esc_html($limits['duration']); ?></span></td>
            </tr>
        </table>
        <?php submit_button(); ?>
    </form>
    <h2><?php esc_html_e('IP bloqueadas', 'dctwphero'); ?> (<?php echo esc_html($count); ?>)</h2>
    <p><?php esc_html_e('Solo se muestran bloqueos activos. Desbloquear también reinicia los fallos de esa IP.', 'dctwphero'); ?></p>
    <table class="widefat striped">
        <caption class="screen-reader-text"><?php esc_html_e('Direcciones IP con bloqueo activo', 'dctwphero'); ?></caption>
        <thead><tr>
            <th scope="col"><?php esc_html_e('IP', 'dctwphero'); ?></th>
            <th scope="col"><?php esc_html_e('Bloqueada desde', 'dctwphero'); ?></th>
            <th scope="col"><?php esc_html_e('Expira', 'dctwphero'); ?></th>
            <th scope="col"><?php esc_html_e('Acción', 'dctwphero'); ?></th>
        </tr></thead>
        <tbody>
        <?php foreach ($blocks as $dctwphero_block): ?>
            <tr>
                <td><?php echo esc_html($dctwphero_block->ip); ?></td>
                <td><?php echo esc_html(wp_date('Y-m-d H:i:s T', (int) $dctwphero_block->updated_at)); ?></td>
                <td><?php echo esc_html(wp_date('Y-m-d H:i:s T', (int) $dctwphero_block->blocked_until)); ?></td>
                <td>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <input type="hidden" name="action" value="dctwphero_unlock">
                        <input type="hidden" name="ip" value="<?php echo esc_attr($dctwphero_block->ip); ?>">
                        <?php wp_nonce_field('dctwphero_unlock_' . $dctwphero_block->ip); ?>
                        <button type="submit" class="button" aria-label="<?php
                            /* translators: %s: blocked IP address. */
                            echo esc_attr(sprintf(__('Desbloquear %s', 'dctwphero'), $dctwphero_block->ip));
                        ?>"><?php esc_html_e('Desbloquear', 'dctwphero'); ?></button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$blocks): ?>
            <tr><td colspan="4"><?php esc_html_e('No hay IP bloqueadas.', 'dctwphero'); ?></td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    <?php if ($pages > 1): ?>
        <nav aria-label="<?php esc_attr_e('Páginas de IP bloqueadas', 'dctwphero'); ?>">
<?php
            $dctwphero_pagination = array();
            $dctwphero_pagination['base'] = add_query_arg('paged', '%#%', admin_url('options-general.php?page=dctwphero&tab=login'));
            $dctwphero_pagination['current'] = $page;
            $dctwphero_pagination['total'] = $pages;
            echo wp_kses_post(paginate_links($dctwphero_pagination));
            ?>
        </nav>
    <?php endif; ?>
