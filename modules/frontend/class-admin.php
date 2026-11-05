<?php

defined('ABSPATH') || exit;

final class DCTWPHero_Frontend_Admin
{
    ################################################
    #
    #  register
    #
    #  Conserva la configuración disponible aunque el módulo esté desactivado.
    #
    ################################################
    public static function register(): void
    {
        add_action('admin_init', self::register_settings(...));
    }

    ################################################
    #
    #  register_settings
    #
    #  Utiliza permisos y nonce nativos de la Settings API.
    #
    ################################################
    public static function register_settings(): void
    {
        $arguments = array();
        $arguments['type'] = 'array';
        $arguments['sanitize_callback'] = self::sanitize(...);
        register_setting('dctwphero_frontend', 'dctwphero_frontend_settings', $arguments);
    }

    ################################################
    #
    #  sanitize
    #
    #  Conserva todos los ajustes anteriores cuando una entrada no es válida.
    #
    ################################################
    public static function sanitize(mixed $input): array
    {
        $result = DCTWPHero_Frontend_Settings::validate($input);

        if ($result === null)
        {
            add_settings_error('dctwphero_frontend_settings', 'invalid_frontend', __('Ajustes de Frontend no válidos. Revisa formatos y límites. Se conservan los ajustes anteriores.', 'dctwphero'));

            return DCTWPHero_Frontend_Settings::get();
        }

        return $result;
    }

    ################################################
    #
    #  render
    #
    #  Prepara campos y avisos sin lógica de negocio en la plantilla.
    #
    ################################################
    public static function render(): void
    {
        if (!current_user_can('manage_options'))
        {
            wp_die(esc_html__('No tienes permisos.', 'dctwphero'), '', 403);
        }

        $settings = DCTWPHero_Frontend_Settings::get();
        $enabled = DCTWPHero::module_enabled('frontend');
        $conflict = is_plugin_active('wpo-tweaks/wpo-tweaks.php');
        $cleanup = array();
        $cleanup['emojis'] = __('Desactivar recursos de emojis. Los emojis nativos del dispositivo siguen funcionando.', 'dctwphero');
        $cleanup['rsd'] = __('Eliminar enlace RSD. No desactiva XML-RPC.', 'dctwphero');
        $cleanup['shortlinks'] = __('Eliminar shortlinks del HTML y las cabeceras HTTP. No cambia enlaces permanentes.', 'dctwphero');
        $cleanup['dashicons'] = __('Desactivar Dashicons para visitantes no autenticados. Puede afectar a iconos del tema; las dependencias de otros estilos pueden volver a cargarlo.', 'dctwphero');
        $fields = array();
        $fields['preconnect'] = __('Preconnect: hasta 10 orígenes HTTP(S), uno por línea, sin rutas. Ejemplo: https://cdn.ejemplo.com. Usa conexiones anónimas, adecuadas para fuentes; no anticipa necesariamente conexiones con credenciales.', 'dctwphero');
        $fields['dns_prefetch'] = __('DNS prefetch: hasta 10 dominios, uno por línea, sin protocolo, puerto ni ruta. Ejemplo: cdn.ejemplo.com. No repitas dominios ya incluidos en preconnect.', 'dctwphero');
        $fields['fonts'] = __('Precarga de fuentes: hasta 5 URL absolutas HTTP(S) de archivos .woff2, una por línea. Solo fuentes utilizadas en el primer contenido visible. Las fuentes externas necesitan CORS.', 'dctwphero');
        $fields['image'] = __('Imagen principal: una URL absoluta HTTP(S) de imagen, solo se precarga en la portada no paginada. Debe coincidir con la imagen realmente utilizada; no detecta variantes de srcset ni imágenes distintas por pantalla.', 'dctwphero');

        require __DIR__ . '/templates/settings.php';
    }
}
