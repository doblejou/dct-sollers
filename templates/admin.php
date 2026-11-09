<?php defined('ABSPATH') || exit; ?>
<div class="wrap dctwpsollers">
    <header class="dctwpsollers-header">
    <div>
        <h1>DCTWP Sollers</h1>
        <p><?php esc_html_e('Las herramientas de tu web, en un solo lugar.', 'dctwpsollers'); ?></p>
    </div>
    <p class="dctwpsollers-version"><?php
        /* translators: %s: plugin version. */
        echo esc_html(sprintf(__('Versión %s', 'dctwpsollers'), $version));
    ?></p>
    </header>
    <nav class="nav-tab-wrapper dctwpsollers-nav" aria-label="<?php esc_attr_e('Secciones de DCTWP Sollers', 'dctwpsollers'); ?>">
        <a class="nav-tab<?php echo $tab === 'modules' ? ' nav-tab-active' : ''; ?>" href="<?php echo esc_url(admin_url('options-general.php?page=dctwpsollers&tab=modules')); ?>" <?php if ($tab === 'modules'): ?>aria-current="page"<?php endif; ?>><?php esc_html_e('Módulos', 'dctwpsollers'); ?></a>
        <?php foreach ($modules as $id => $dctwpsollers_module): ?>
            <?php if (isset($dctwpsollers_module['render'])): ?>
            <a class="nav-tab<?php echo $tab === $id ? ' nav-tab-active' : ''; ?>" href="<?php echo esc_url(add_query_arg('tab', $id, admin_url('options-general.php?page=dctwpsollers'))); ?>" <?php if ($tab === $id): ?>aria-current="page"<?php endif; ?>><?php echo esc_html($dctwpsollers_module['label']); ?></a>
            <?php endif; ?>
        <?php endforeach; ?>
    </nav>
    <div class="dctwpsollers-content">
    <?php if ($tab !== 'modules'): ?>
        <div class="dctwpsollers-module-context">
            <span class="dctwpsollers-status <?php echo $states[$tab] ? 'is-enabled' : ''; ?>"><?php echo $states[$tab] ? esc_html__('Módulo activo', 'dctwpsollers') : esc_html__('Módulo desactivado', 'dctwpsollers'); ?></span>
            <a href="<?php echo esc_url(admin_url('options-general.php?page=dctwpsollers&tab=modules')); ?>"><?php esc_html_e('Gestionar módulos', 'dctwpsollers'); ?></a>
        </div>
    <?php endif; ?>
    <?php if ($tab === 'modules'): ?>
        <?php require __DIR__ . '/modules.php'; ?>
    <?php else: ?>
        <?php ($modules[$tab]['render'])(); ?>
    <?php endif; ?>
    </div>
</div>
