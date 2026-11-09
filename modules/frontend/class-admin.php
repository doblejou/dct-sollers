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
            add_settings_error('dctwpsollers_frontend_settings', 'invalid_frontend', __('Ajustes de Frontend no válidos. Revisa formatos y límites. Se conservan los ajustes anteriores.', 'dctwpsollers'));

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
            wp_die(esc_html__('No tienes permisos.', 'dctwpsollers'), '', 403);
        }

        $settings = DCTWPSollers_Frontend_Settings::get();
        $enabled = DCTWPSollers::module_enabled('frontend');
        $conflict = is_plugin_active('wpo-tweaks/wpo-tweaks.php');
        $cleanup = self::cleanup_options();
        $fields = array();
        $fields['preconnect'] = __('Preconnect: hasta 10 orígenes HTTP(S), uno por línea, sin rutas. Ejemplo: https://cdn.ejemplo.com. Usa conexiones anónimas, adecuadas para fuentes; no anticipa necesariamente conexiones con credenciales.', 'dctwpsollers');
        $fields['dns_prefetch'] = __('DNS prefetch: hasta 10 dominios, uno por línea, sin protocolo, puerto ni ruta. Ejemplo: cdn.ejemplo.com. No repitas dominios ya incluidos en preconnect.', 'dctwpsollers');
        $fields['fonts'] = __('Precarga de fuentes: hasta 5 URL absolutas HTTP(S) de archivos .woff2, una por línea. Solo fuentes utilizadas en el primer contenido visible. Las fuentes externas necesitan CORS.', 'dctwpsollers');
        $fields['image'] = __('Imagen principal: una URL absoluta HTTP(S) de imagen, solo se precarga en la portada no paginada. Debe coincidir con la imagen realmente utilizada; no detecta variantes de srcset ni imágenes distintas por pantalla.', 'dctwpsollers');

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
            __('Desactivar recursos de emojis', 'dctwpsollers'),
            __('Los scripts y estilos de compatibilidad de emojis de WordPress y su DNS prefetch en el frontend.', 'dctwpsollers'),
            __('Los emojis escritos en el contenido y su representación nativa en el dispositivo.', 'dctwpsollers'),
            __('Los dispositivos antiguos pueden mostrar algunos emojis de forma distinta. No modifica correos, feeds ni el editor.', 'dctwpsollers')
        );
        $options['rsd'] = self::cleanup_option(
            __('Eliminar enlace RSD', 'dctwpsollers'),
            __('La etiqueta del HTML que ayuda a los clientes de publicación a descubrir XML-RPC.', 'dctwpsollers'),
            __('XML-RPC y su URL siguen disponibles; no se bloquea la publicación remota.', 'dctwpsollers'),
            __('Un cliente que dependa del descubrimiento automático puede necesitar que configures la URL manualmente.', 'dctwpsollers')
        );
        $options['shortlinks'] = self::cleanup_option(
            __('Eliminar shortlinks', 'dctwpsollers'),
            __('Los enlaces cortos anunciados por WordPress en el HTML y las cabeceras HTTP.', 'dctwpsollers'),
            __('Los enlaces permanentes, las URL cortas existentes y los enlaces del contenido.', 'dctwpsollers'),
            __('Las herramientas que busquen automáticamente el shortlink dejarán de encontrar esa referencia.', 'dctwpsollers')
        );
        $options['dashicons'] = self::cleanup_option(
            __('Desactivar Dashicons para visitantes', 'dctwpsollers'),
            __('La carga directa de la hoja de iconos Dashicons para visitantes no autenticados.', 'dctwpsollers'),
            __('Los iconos para usuarios autenticados y las dependencias declaradas por otros estilos.', 'dctwpsollers'),
            __('Puede dejar iconos del tema sin dibujar. Otros estilos que dependan de Dashicons pueden volver a cargarlo. Revisa la web sin iniciar sesión.', 'dctwpsollers')
        );
        $options['rest_links'] = self::cleanup_option(
            __('Eliminar enlaces de descubrimiento REST', 'dctwpsollers'),
            __('Las referencias de descubrimiento de la API REST en el HTML y las cabeceras HTTP del frontend.', 'dctwpsollers'),
            __('La API REST, sus endpoints y las integraciones que ya conocen sus URL.', 'dctwpsollers'),
            __('Algunos clientes usan estos enlaces para localizar la API. No desactiva REST ni añade protección de acceso.', 'dctwpsollers')
        );
        $options['oembed_links'] = self::cleanup_option(
            __('Eliminar enlaces de descubrimiento oEmbed', 'dctwpsollers'),
            __('Las etiquetas de descubrimiento oEmbed JSON y XML de la cabecera HTML.', 'dctwpsollers'),
            __('Los endpoints oEmbed, sus scripts y el contenido incrustado que ya existe en tus páginas.', 'dctwpsollers'),
            __('Otras webs pueden dejar de descubrir cómo incrustar tus entradas. No desactiva todo el sistema oEmbed.', 'dctwpsollers')
        );
        $options['generator'] = self::cleanup_option(
            __('Eliminar meta generator', 'dctwpsollers'),
            __('La etiqueta meta generator que WordPress imprime en la cabecera HTML.', 'dctwpsollers'),
            __('El resto de metadatos y el generator de los feeds; no oculta todas las referencias a la versión.', 'dctwpsollers'),
            __('Es limpieza cosmética, no una medida de seguridad. No sustituye mantener WordPress, temas y plugins actualizados.', 'dctwpsollers')
        );
        $options['feed_links'] = self::cleanup_option(
            __('Eliminar enlaces de descubrimiento RSS de contenido', 'dctwpsollers'),
            __('Los enlaces RSS que WordPress anuncia en el HTML para entradas, categorías, etiquetas, taxonomías, autores, búsquedas y archivos de tipos de contenido.', 'dctwpsollers'),
            __('Los feeds siguen accesibles por su URL. Los enlaces RSS de comentarios tienen una opción independiente.', 'dctwpsollers'),
            __('Los lectores y navegadores pueden dejar de descubrir las suscripciones automáticamente. Conserva estos enlaces si ofreces suscripción RSS.', 'dctwpsollers')
        );
        $options['comments_feed_links'] = self::cleanup_option(
            __('Eliminar enlaces de descubrimiento RSS de comentarios', 'dctwpsollers'),
            __('Los enlaces RSS de comentarios que WordPress anuncia en el HTML, tanto el general como el de cada entrada.', 'dctwpsollers'),
            __('Los comentarios, sus formularios y los feeds de comentarios siguen funcionando por su URL.', 'dctwpsollers'),
            __('Puede dificultar descubrir la suscripción a conversaciones. No desactiva comentarios ni elimina datos.', 'dctwpsollers')
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
