<?php defined('ABSPATH') || exit; ?>
<div class="wrap">
    <h1>DCT Sollers</h1>
    <p class="description"><?php
        /* translators: %s: plugin version. */
        echo esc_html(sprintf(__('Versión %s', 'dct-sollers'), $version));
    ?></p>
    <nav class="nav-tab-wrapper" aria-label="<?php esc_attr_e('Secciones de DCT Sollers', 'dct-sollers'); ?>">
        <a class="nav-tab<?php echo $tab === 'modules' ? ' nav-tab-active' : ''; ?>" href="<?php echo esc_url(admin_url('options-general.php?page=dctwpsollers&tab=modules')); ?>" <?php if ($tab === 'modules'): ?>aria-current="page"<?php endif; ?>><?php esc_html_e('Módulos', 'dct-sollers'); ?></a>
        <?php foreach ($modules as $id => $dctwpsollers_module): ?>
            <?php if (isset($dctwpsollers_module['render'])): ?>
            <a class="nav-tab<?php echo $tab === $id ? ' nav-tab-active' : ''; ?>" href="<?php echo esc_url(add_query_arg('tab', $id, admin_url('options-general.php?page=dctwpsollers'))); ?>" <?php if ($tab === $id): ?>aria-current="page"<?php endif; ?>><?php echo esc_html($dctwpsollers_module['label']); ?></a>
            <?php endif; ?>
        <?php endforeach; ?>
    </nav>
    <?php if ($tab === 'modules'): ?>
        <?php require __DIR__ . '/modules.php'; ?>
    <?php else: ?>
        <?php ($modules[$tab]['render'])(); ?>
    <?php endif; ?>
</div>
