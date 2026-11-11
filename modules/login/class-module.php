<?php

defined('ABSPATH') || exit;

final class DCTWPSollers_Login_Module
{
    ################################################
    #
    #  settings
    #
    #  Obtiene la configuración de login con los valores iniciales del módulo.
    #
    ################################################
    public static function settings(): array
    {
        $defaults = array();
        $defaults['threshold'] = 3;
        $defaults['window'] = 1;
        $defaults['duration'] = 15;

        $saved = get_option('dctwpsollers_settings', array());

        return self::validate_settings(is_array($saved) ? array_replace($defaults, $saved) : null) ?? $defaults;
    }

    ################################################
    #
    #  limits
    #
    #  Define los máximos compartidos por validación y formulario de ajustes.
    #
    ################################################
    public static function limits(): array
    {
        $limits = array();
        $limits['threshold'] = 10000;
        $limits['window'] = 10080;
        $limits['duration'] = 10080;

        return $limits;
    }

    ################################################
    #
    #  validate_settings
    #
    #  Normaliza ajustes completos o rechaza valores que no sean enteros en rango.
    #
    ################################################
    public static function validate_settings(mixed $input): ?array
    {
        $result = array();

        foreach (self::limits() as $key => $maximum)
        {
            $value = is_array($input) ? ($input[$key] ?? null) : null;

            if ((!is_int($value) && !is_string($value)) || !ctype_digit((string) $value)
                || (int) $value < 1 || (int) $value > $maximum)
            {
                return null;
            }

            $result[$key] = (int) $value;
        }

        return $result;
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
        if (!DCTWPSollers::module_enabled('login'))
        {
            return;
        }

        $ip = self::client_ip();

        if ($ip === '')
        {
            wp_die(esc_html__('No se puede identificar la dirección del cliente.', 'dct-sollers'), '', 403);
        }

        if (DCTWPSollers_Store::blocked_until($ip) > time())
        {
            nocache_headers();
            wp_die(
                esc_html__('Acceso temporalmente bloqueado. Inténtalo más tarde.', 'dct-sollers'),
                esc_html__('Acceso denegado', 'dct-sollers'),
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

        if (!DCTWPSollers::module_enabled('login') || $pagenow !== 'wp-login.php' || !did_action('login_init'))
        {
            return;
        }

        $ip = self::client_ip();

        if ($ip !== '' && !DCTWPSollers_Store::record_failure($ip, self::settings()))
        {
            error_log('DCT Sollers: no se ha podido registrar un fallo de autenticación.');
        }
    }
}
