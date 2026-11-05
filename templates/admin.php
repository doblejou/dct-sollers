<?php defined('ABSPATH') || exit; ?>
<div class="wrap dctwphero">
    <header class="dctwphero-header">
    <div>
        <h1>DCT WP Hero</h1>
        <p><?php esc_html_e('Las herramientas de tu web, en un solo lugar.', 'dctwphero'); ?></p>
    </div>
    <p class="dctwphero-version"><?php
        /* translators: %s: plugin version. */
        echo esc_html(sprintf(__('Versión %s', 'dctwphero'), $version));
    ?></p>
    </header>
    <nav class="nav-tab-wrapper dctwphero-nav" aria-label="<?php esc_attr_e('Secciones de DCT WP Hero', 'dctwphero'); ?>">
        <a class="nav-tab<?php echo $tab === 'modules' ? ' nav-tab-active' : ''; ?>" href="<?php echo esc_url(admin_url('options-general.php?page=dctwphero&tab=modules')); ?>" <?php if ($tab === 'modules'): ?>aria-current="page"<?php endif; ?>><?php esc_html_e('Módulos', 'dctwphero'); ?></a>
        <?php foreach ($modules as $id => $dctwphero_module): ?>
            <?php if (isset($dctwphero_module['render'])): ?>
            <a class="nav-tab<?php echo $tab === $id ? ' nav-tab-active' : ''; ?>" href="<?php echo esc_url(add_query_arg('tab', $id, admin_url('options-general.php?page=dctwphero'))); ?>" <?php if ($tab === $id): ?>aria-current="page"<?php endif; ?>><?php echo esc_html($dctwphero_module['label']); ?></a>
            <?php endif; ?>
        <?php endforeach; ?>
    </nav>
    <div class="dctwphero-content">
    <?php if ($tab !== 'modules'): ?>
        <div class="dctwphero-module-context">
            <span class="dctwphero-status <?php echo $states[$tab] ? 'is-enabled' : ''; ?>"><?php echo $states[$tab] ? esc_html__('Módulo activo', 'dctwphero') : esc_html__('Módulo desactivado', 'dctwphero'); ?></span>
            <a href="<?php echo esc_url(admin_url('options-general.php?page=dctwphero&tab=modules')); ?>"><?php esc_html_e('Gestionar módulos', 'dctwphero'); ?></a>
        </div>
    <?php endif; ?>
    <?php if ($tab === 'modules'): ?>
        <?php require __DIR__ . '/modules.php'; ?>
    <?php else: ?>
        <?php ($modules[$tab]['render'])(); ?>
    <?php endif; ?>
    </div>
</div>
