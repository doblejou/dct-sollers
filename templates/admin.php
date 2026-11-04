<?php defined('ABSPATH') || exit; ?>
<div class="wrap">
    <h1>DCT WP Hero</h1>
    <p class="description"><?php
        /* translators: %s: plugin version. */
        echo esc_html(sprintf(__('Versión %s', 'dctwphero'), $version));
    ?></p>
    <nav class="nav-tab-wrapper" aria-label="<?php esc_attr_e('Secciones de DCT WP Hero', 'dctwphero'); ?>">
        <a class="nav-tab<?php echo $tab === 'modules' ? ' nav-tab-active' : ''; ?>" href="<?php echo esc_url(admin_url('options-general.php?page=dctwphero&tab=modules')); ?>" <?php if ($tab === 'modules'): ?>aria-current="page"<?php endif; ?>><?php esc_html_e('Módulos', 'dctwphero'); ?></a>
        <?php foreach ($modules as $id => $dctwphero_module): ?>
            <?php if (isset($dctwphero_module['render'])): ?>
            <a class="nav-tab<?php echo $tab === $id ? ' nav-tab-active' : ''; ?>" href="<?php echo esc_url(add_query_arg('tab', $id, admin_url('options-general.php?page=dctwphero'))); ?>" <?php if ($tab === $id): ?>aria-current="page"<?php endif; ?>><?php echo esc_html($dctwphero_module['label']); ?></a>
            <?php endif; ?>
        <?php endforeach; ?>
    </nav>
    <?php if ($tab === 'modules'): ?>
        <?php require __DIR__ . '/modules.php'; ?>
    <?php else: ?>
        <?php ($modules[$tab]['render'])(); ?>
    <?php endif; ?>
</div>
