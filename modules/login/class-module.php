<?php

defined('ABSPATH') || exit;

final class DCTWPHero_Login_Module
{
    ################################################
    #
    #  initialize
    #
    #  Actualiza el almacenamiento y conecta la limpieza incluso desactivado.
    #
    ################################################
    public static function initialize(): void
    {
        if (!DCTWPHero_Store::upgrade())
        {
            wp_die(
                esc_html__('No se ha podido actualizar el almacenamiento de DCT WP Hero. Revisa el registro del servidor antes de reintentar.', 'dctwphero'),
                esc_html__('Actualización pendiente', 'dctwphero'),
                503
            );
        }

        add_action('dctwphero_cleanup', 'DCTWPHero_Store::cleanup');
    }

    ################################################
    #
    #  activate
    #
    #  Prepara el almacenamiento y programa la limpieza periódica del login.
    #
    ################################################
    public static function activate(): void
    {
        self::initialize();

        if (!wp_next_scheduled('dctwphero_cleanup'))
        {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'hourly', 'dctwphero_cleanup');
        }
    }

    ################################################
    #
    #  deactivate
    #
    #  Retira la tarea periódica sin borrar ajustes ni bloqueos.
    #
    ################################################
    public static function deactivate(): void
    {
        wp_clear_scheduled_hook('dctwphero_cleanup');
    }

    ################################################
    #
    #  register
    #
    #  Conecta la protección de login con los eventos de WordPress.
    #
    ################################################
    public static function register(): void
    {
        add_action('login_init', self::check_access(...), 0);
        add_action('wp_login_failed', self::failed_login(...));
    }

    ################################################
    #
    #  client_ip
    #
    #  Normaliza la dirección del servidor sin interpretar cabeceras de cliente.
    #
    ################################################
    public static function client_ip(): string
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';

        if (!is_string($ip) || !filter_var($ip, FILTER_VALIDATE_IP))
        {
            return '';
        }

        return inet_ntop(inet_pton($ip));
    }

    ################################################
    #
    #  check_access
    #
    #  Detiene el acceso a wp-login.php desde direcciones bloqueadas.
    #
    ################################################
    public static function check_access(): void
    {
        if (!DCTWPHero::module_enabled('login'))
        {
            return;
        }

        $ip = self::client_ip();

        if ($ip === '')
        {
            wp_die(esc_html__('No se puede identificar la dirección del cliente.', 'dctwphero'), '', 403);
        }

        if (DCTWPHero_Store::blocked_until($ip) > time())
        {
            nocache_headers();
            wp_die(
                esc_html__('Acceso temporalmente bloqueado. Inténtalo más tarde.', 'dctwphero'),
                esc_html__('Acceso denegado', 'dctwphero'),
                403
            );
        }
    }

    ################################################
    #
    #  failed_login
    #
    #  Registra los fallos de wp-login.php solo mientras el módulo esté activo.
    #
    ################################################
    public static function failed_login(): void
    {
        global $pagenow;

        if (!DCTWPHero::module_enabled('login') || $pagenow !== 'wp-login.php' || !did_action('login_init'))
        {
            return;
        }

        $ip = self::client_ip();

        if ($ip !== '' && !DCTWPHero_Store::record_failure($ip, DCTWPHero_Login_Settings::get()))
        {
            error_log('DCT WP Hero: no se ha podido registrar un fallo de autenticación.');
        }
    }
}
