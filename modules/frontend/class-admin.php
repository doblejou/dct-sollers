<?php

defined('ABSPATH') || exit;

final class DCTWPSollers_Frontend_Admin
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
        $arguments['sanitize_callback'] = 'DCTWPSollers_Frontend_Admin::sanitize';
        register_setting('dctwpsollers_frontend', 'dctwpsollers_frontend_settings', $arguments);
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
        $result = DCTWPSollers_Frontend_Settings::validate($input);

        if ($result === null)
        {
            add_settings_error('dctwpsollers_frontend_settings', 'invalid_frontend', __('Ajustes de Frontend no válidos. Revisa formatos y límites. Se conservan los ajustes anteriores.', 'dct-sollers'));

            return DCTWPSollers_Frontend_Settings::get();
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
            wp_die(esc_html__('No tienes permisos.', 'dct-sollers'), '', 403);
        }

        $settings = DCTWPSollers_Frontend_Settings::get();
        $enabled = DCTWPSollers::module_enabled('frontend');
        $conflict = is_plugin_active('wpo-tweaks/wpo-tweaks.php');
        $cleanup = self::cleanup_options();
        $fields = array();
        $fields['preconnect'] = __('Preconnect: hasta 10 orígenes HTTP(S), uno por línea, sin rutas. Ejemplo: https://cdn.ejemplo.com. Usa conexiones anónimas, adecuadas para fuentes; no anticipa necesariamente conexiones con credenciales.', 'dct-sollers');
        $fields['dns_prefetch'] = __('DNS prefetch: hasta 10 dominios, uno por línea, sin protocolo, puerto ni ruta. Ejemplo: cdn.ejemplo.com. No repitas dominios ya incluidos en preconnect.', 'dct-sollers');
        $fields['fonts'] = __('Precarga de fuentes: hasta 5 URL absolutas HTTP(S) de archivos .woff2, una por línea. Solo fuentes utilizadas en el primer contenido visible. Las fuentes externas necesitan CORS.', 'dct-sollers');
        $fields['image'] = __('Imagen principal: una URL absoluta HTTP(S) de imagen, solo se precarga en la portada no paginada. Debe coincidir con la imagen realmente utilizada; no detecta variantes de srcset ni imágenes distintas por pantalla.', 'dct-sollers');

        require __DIR__ . '/templates/settings.php';
    }

    ################################################
    #
    #  cleanup_options
    #
    #  Explica el alcance y las precauciones de cada limpieza sin prometer seguridad.
    #
    ################################################
    private static function cleanup_options(): array
    {
        $options = array();
        $options['emojis'] = self::cleanup_option(
            __('Desactivar recursos de emojis', 'dct-sollers'),
            __('Los scripts y estilos de compatibilidad de emojis de WordPress y su DNS prefetch en el frontend.', 'dct-sollers'),
            __('Los emojis escritos en el contenido y su representación nativa en el dispositivo.', 'dct-sollers'),
            __('Los dispositivos antiguos pueden mostrar algunos emojis de forma distinta. No modifica correos, feeds ni el editor.', 'dct-sollers')
        );
        $options['rsd'] = self::cleanup_option(
            __('Eliminar enlace RSD', 'dct-sollers'),
            __('La etiqueta del HTML que ayuda a los clientes de publicación a descubrir XML-RPC.', 'dct-sollers'),
            __('XML-RPC y su URL siguen disponibles; no se bloquea la publicación remota.', 'dct-sollers'),
            __('Un cliente que dependa del descubrimiento automático puede necesitar que configures la URL manualmente.', 'dct-sollers')
        );
        $options['shortlinks'] = self::cleanup_option(
            __('Eliminar shortlinks', 'dct-sollers'),
            __('Los enlaces cortos anunciados por WordPress en el HTML y las cabeceras HTTP.', 'dct-sollers'),
            __('Los enlaces permanentes, las URL cortas existentes y los enlaces del contenido.', 'dct-sollers'),
            __('Las herramientas que busquen automáticamente el shortlink dejarán de encontrar esa referencia.', 'dct-sollers')
        );
        $options['dashicons'] = self::cleanup_option(
            __('Desactivar Dashicons para visitantes', 'dct-sollers'),
            __('La carga directa de la hoja de iconos Dashicons para visitantes no autenticados.', 'dct-sollers'),
            __('Los iconos para usuarios autenticados y las dependencias declaradas por otros estilos.', 'dct-sollers'),
            __('Puede dejar iconos del tema sin dibujar. Otros estilos que dependan de Dashicons pueden volver a cargarlo. Revisa la web sin iniciar sesión.', 'dct-sollers')
        );
        $options['rest_links'] = self::cleanup_option(
            __('Eliminar enlaces de descubrimiento REST', 'dct-sollers'),
            __('Las referencias de descubrimiento de la API REST en el HTML y las cabeceras HTTP del frontend.', 'dct-sollers'),
            __('La API REST, sus endpoints y las integraciones que ya conocen sus URL.', 'dct-sollers'),
            __('Algunos clientes usan estos enlaces para localizar la API. No desactiva REST ni añade protección de acceso.', 'dct-sollers')
        );
        $options['oembed_links'] = self::cleanup_option(
            __('Eliminar enlaces de descubrimiento oEmbed', 'dct-sollers'),
            __('Las etiquetas de descubrimiento oEmbed JSON y XML de la cabecera HTML.', 'dct-sollers'),
            __('Los endpoints oEmbed, sus scripts y el contenido incrustado que ya existe en tus páginas.', 'dct-sollers'),
            __('Otras webs pueden dejar de descubrir cómo incrustar tus entradas. No desactiva todo el sistema oEmbed.', 'dct-sollers')
        );
        $options['generator'] = self::cleanup_option(
            __('Eliminar meta generator', 'dct-sollers'),
            __('La etiqueta meta generator que WordPress imprime en la cabecera HTML.', 'dct-sollers'),
            __('El resto de metadatos y el generator de los feeds; no oculta todas las referencias a la versión.', 'dct-sollers'),
            __('Es limpieza cosmética, no una medida de seguridad. No sustituye mantener WordPress, temas y plugins actualizados.', 'dct-sollers')
        );
        $options['feed_links'] = self::cleanup_option(
            __('Eliminar enlaces de descubrimiento RSS de contenido', 'dct-sollers'),
            __('Los enlaces RSS que WordPress anuncia en el HTML para entradas, categorías, etiquetas, taxonomías, autores, búsquedas y archivos de tipos de contenido.', 'dct-sollers'),
            __('Los feeds siguen accesibles por su URL. Los enlaces RSS de comentarios tienen una opción independiente.', 'dct-sollers'),
            __('Los lectores y navegadores pueden dejar de descubrir las suscripciones automáticamente. Conserva estos enlaces si ofreces suscripción RSS.', 'dct-sollers')
        );
        $options['comments_feed_links'] = self::cleanup_option(
            __('Eliminar enlaces de descubrimiento RSS de comentarios', 'dct-sollers'),
            __('Los enlaces RSS de comentarios que WordPress anuncia en el HTML, tanto el general como el de cada entrada.', 'dct-sollers'),
            __('Los comentarios, sus formularios y los feeds de comentarios siguen funcionando por su URL.', 'dct-sollers'),
            __('Puede dificultar descubrir la suscripción a conversaciones. No desactiva comentarios ni elimina datos.', 'dct-sollers')
        );

        return $options;
    }

    ################################################
    #
    #  cleanup_option
    #
    #  Mantiene una estructura común para la ayuda visible de cada casilla.
    #
    ################################################
    private static function cleanup_option(string $label, string $removes, string $preserves, string $caution): array
    {
        $option = array();
        $option['label'] = $label;
        $option['removes'] = $removes;
        $option['preserves'] = $preserves;
        $option['caution'] = $caution;

        return $option;
    }
}
