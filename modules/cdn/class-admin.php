<?php

defined('ABSPATH') || exit;

final class DCTWPSollers_CDN_Admin
{
    ################################################
    #
    #  register
    #
    #  Mantiene los ajustes y la comprobación disponibles incluso desactivado.
    #
    ################################################
    public static function register(): void
    {
        add_action('admin_init', self::register_settings(...));
        add_action('admin_post_dctwpsollers_cdn_check', self::check(...));
    }

    ################################################
    #
    #  register_settings
    #
    #  Registra una opción independiente para el formulario de CDN.
    #
    ################################################
    public static function register_settings(): void
    {
        $arguments = array();
        $arguments['type'] = 'array';
        $arguments['sanitize_callback'] = 'DCTWPSollers_CDN_Admin::sanitize';
        register_setting('dctwpsollers_cdn', 'dctwpsollers_cdn_settings', $arguments);
    }

    ################################################
    #
    #  sanitize
    #
    #  Rechaza el formulario inválido sin perder la configuración anterior.
    #
    ################################################
    public static function sanitize(mixed $input): array
    {
        $result = DCTWPSollers_CDN_Module::validate_settings($input);

        if ($result === null)
        {
            add_settings_error('dctwpsollers_cdn_settings', 'invalid_cdn', __('Ajustes CDN no válidos. Introduce solo un dominio sin protocolo, puerto ni ruta, extensiones con punto y una entrada por línea. Se conservan los ajustes anteriores.', 'dctwpsollers'));

            return DCTWPSollers_CDN_Module::settings();
        }

        return $result;
    }

    ################################################
    #
    #  render
    #
    #  Prepara configuración, estado y avisos sin consultas en la plantilla.
    #
    ################################################
    public static function render(): void
    {
        if (!current_user_can('manage_options'))
        {
            wp_die(esc_html__('No tienes permisos.', 'dctwpsollers'), '', 403);
        }

        $settings = DCTWPSollers_CDN_Module::settings();
        $enabled = DCTWPSollers::module_enabled('cdn');
        $conflict = is_plugin_active('cdn-enabler/cdn-enabler.php');
        $transient = 'dctwpsollers_cdn_check_' . get_current_user_id();
        $notice = get_transient($transient);
        delete_transient($transient);

        require __DIR__ . '/templates/settings.php';
    }

    ################################################
    #
    #  probe
    #
    #  Comprueba un recurso del núcleo sin redirecciones ni destinos HTTP inseguros.
    #
    ################################################
    public static function probe(array $settings): array
    {
        $result = array();
        $result['success'] = false;

        if ($settings['hostname'] === '')
        {
            $result['message'] = __('Guarda primero un dominio CDN válido.', 'dctwpsollers');

            return $result;
        }

        $resource = includes_url('images/blank.gif');
        $url = preg_replace('~^(https?://)[^/]+~i', '${1}' . $settings['hostname'], $resource);
        $arguments = array();
        $arguments['timeout'] = 10;
        $arguments['redirection'] = 0;
        $arguments['headers'] = array();
        $arguments['headers']['Referer'] = home_url('/');
        $response = wp_safe_remote_head($url, $arguments);

        if (is_wp_error($response))
        {
            $result['message'] = __('No se pudo comprobar el CDN. Revisa DNS, certificado, accesibilidad y configuración del origen.', 'dctwpsollers');

            return $result;
        }

        $status = wp_remote_retrieve_response_code($response);
        $result['success'] = $status === 200;
        $result['message'] = sprintf(
            /* translators: %d: HTTP status code returned by the CDN. */
            __('El recurso de prueba del CDN respondió con HTTP %d. Esta comprobación no verifica todos los archivos ni las cabeceras CORS de las fuentes.', 'dctwpsollers'),
            $status
        );

        return $result;
    }

    ################################################
    #
    #  check
    #
    #  Exige POST, permiso y nonce antes de comprobar los ajustes ya guardados.
    #
    ################################################
    public static function check(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !current_user_can('manage_options'))
        {
            wp_die(esc_html__('No tienes permisos.', 'dctwpsollers'), '', 403);
        }

        check_admin_referer('dctwpsollers_cdn_check');
        $result = self::probe(DCTWPSollers_CDN_Module::settings());
        set_transient('dctwpsollers_cdn_check_' . get_current_user_id(), $result, MINUTE_IN_SECONDS);
        wp_safe_redirect(admin_url('options-general.php?page=dctwpsollers&tab=cdn'));
        exit;
    }
}
