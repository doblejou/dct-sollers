<?php

defined('ABSPATH') || exit;

final class DCTWPSollers_Login_Admin
{
    ################################################
    #
    #  register
    #
    #  Conecta la administración del módulo incluso si su protección está inactiva.
    #
    ################################################
    public static function register(): void
    {
        add_action('admin_init', self::register_settings(...));
        add_action('admin_post_dctwpsollers_unlock', self::unlock(...));
    }

    ################################################
    #
    #  register_settings
    #
    #  Registra los ajustes de login y su validación mediante la Settings API.
    #
    ################################################
    public static function register_settings(): void
    {
        $arguments = array();
        $arguments['type'] = 'array';
        $arguments['sanitize_callback'] = 'DCTWPSollers_Login_Admin::sanitize';
        register_setting('dctwpsollers', 'dctwpsollers_settings', $arguments);
    }

    ################################################
    #
    #  sanitize
    #
    #  Rechaza ajustes incompletos o fuera de rango conservando los anteriores.
    #
    ################################################
    public static function sanitize(mixed $input): array
    {
        $result = DCTWPSollers_Login_Module::validate_settings($input);

        if ($result === null)
        {
            add_settings_error('dctwpsollers_settings', 'invalid_settings', __('Introduce números enteros dentro de los rangos indicados.', 'dct-sollers'));

            return DCTWPSollers_Login_Module::settings();
        }

        return $result;
    }

    ################################################
    #
    #  render
    #
    #  Prepara los ajustes y bloqueos de la pestaña de protección de login.
    #
    ################################################
    public static function render(): void
    {
        if (!current_user_can('manage_options'))
        {
            wp_die(esc_html__('No tienes permisos.', 'dct-sollers'), '', 403);
        }

        $settings = DCTWPSollers_Login_Module::settings();
        $enabled = DCTWPSollers::module_enabled('login');
        $limits = DCTWPSollers_Login_Module::limits();
        $count = DCTWPSollers_Store::active_count();
        $pages = max(1, (int) ceil($count / 50));
        // Read-only pagination; no state changes, so a nonce is not required.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $page = isset($_GET['paged']) && is_scalar($_GET['paged']) ? min($pages, max(1, absint(wp_unslash($_GET['paged'])))) : 1;
        $blocks = DCTWPSollers_Store::active_blocks($page);

        require __DIR__ . '/templates/settings.php';
    }

    ################################################
    #
    #  unlock
    #
    #  Verifica la petición administrativa antes de retirar un bloqueo.
    #
    ################################################
    public static function unlock(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !current_user_can('manage_options'))
        {
            wp_die(esc_html__('No tienes permisos.', 'dct-sollers'), '', 403);
        }

        $ip = isset($_POST['ip']) && is_string($_POST['ip']) ? wp_unslash($_POST['ip']) : '';

        if (!filter_var($ip, FILTER_VALIDATE_IP))
        {
            wp_die(esc_html__('Dirección IP no válida.', 'dct-sollers'), '', 400);
        }

        $ip = inet_ntop(inet_pton($ip));
        check_admin_referer('dctwpsollers_unlock_' . $ip);

        if (!DCTWPSollers_Store::unlock($ip))
        {
            wp_die(esc_html__('No se ha podido retirar el bloqueo.', 'dct-sollers'), '', 500);
        }

        wp_safe_redirect(admin_url('options-general.php?page=dctwpsollers&tab=login&unlocked=1'));
        exit;
    }
}
