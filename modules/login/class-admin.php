<?php

defined('ABSPATH') || exit;

final class DCTWPHero_Login_Admin
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
        add_action('admin_post_dctwphero_unlock', self::unlock(...));
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
        $arguments['sanitize_callback'] = 'DCTWPHero_Login_Admin::sanitize';
        register_setting('dctwphero', 'dctwphero_settings', $arguments);
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
        $result = DCTWPHero_Login_Settings::validate($input);

        if ($result === null)
        {
            add_settings_error('dctwphero_settings', 'invalid_settings', __('Introduce números enteros dentro de los rangos indicados.', 'dctwphero'));

            return DCTWPHero_Login_Settings::get();
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
            wp_die(esc_html__('No tienes permisos.', 'dctwphero'), '', 403);
        }

        $settings = DCTWPHero_Login_Settings::get();
        $enabled = DCTWPHero::module_enabled('login');
        $limits = DCTWPHero_Login_Settings::limits();
        $count = DCTWPHero_Store::active_count();
        $pages = max(1, (int) ceil($count / 50));
        // Read-only pagination; no state changes, so a nonce is not required.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $page = isset($_GET['paged']) && is_scalar($_GET['paged']) ? min($pages, max(1, absint(wp_unslash($_GET['paged'])))) : 1;
        $blocks = DCTWPHero_Store::active_blocks($page);

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
            wp_die(esc_html__('No tienes permisos.', 'dctwphero'), '', 403);
        }

        $ip = isset($_POST['ip']) && is_string($_POST['ip']) ? wp_unslash($_POST['ip']) : '';

        if (!filter_var($ip, FILTER_VALIDATE_IP))
        {
            wp_die(esc_html__('Dirección IP no válida.', 'dctwphero'), '', 400);
        }

        $ip = inet_ntop(inet_pton($ip));
        check_admin_referer('dctwphero_unlock_' . $ip);

        if (!DCTWPHero_Store::unlock($ip))
        {
            wp_die(esc_html__('No se ha podido retirar el bloqueo.', 'dctwphero'), '', 500);
        }

        wp_safe_redirect(admin_url('options-general.php?page=dctwphero&tab=login&unlocked=1'));
        exit;
    }
}
