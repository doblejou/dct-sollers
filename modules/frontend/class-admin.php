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
        $cleanup = self::cleanup_options();
        $fields = array();
        $fields['preconnect'] = __('Preconnect: hasta 10 orígenes HTTP(S), uno por línea, sin rutas. Ejemplo: https://cdn.ejemplo.com. Usa conexiones anónimas, adecuadas para fuentes; no anticipa necesariamente conexiones con credenciales.', 'dctwphero');
        $fields['dns_prefetch'] = __('DNS prefetch: hasta 10 dominios, uno por línea, sin protocolo, puerto ni ruta. Ejemplo: cdn.ejemplo.com. No repitas dominios ya incluidos en preconnect.', 'dctwphero');
        $fields['fonts'] = __('Precarga de fuentes: hasta 5 URL absolutas HTTP(S) de archivos .woff2, una por línea. Solo fuentes utilizadas en el primer contenido visible. Las fuentes externas necesitan CORS.', 'dctwphero');
        $fields['image'] = __('Imagen principal: una URL absoluta HTTP(S) de imagen, solo se precarga en la portada no paginada. Debe coincidir con la imagen realmente utilizada; no detecta variantes de srcset ni imágenes distintas por pantalla.', 'dctwphero');

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
            __('Desactivar recursos de emojis', 'dctwphero'),
            __('Los scripts y estilos de compatibilidad de emojis de WordPress y su DNS prefetch en el frontend.', 'dctwphero'),
            __('Los emojis escritos en el contenido y su representación nativa en el dispositivo.', 'dctwphero'),
            __('Los dispositivos antiguos pueden mostrar algunos emojis de forma distinta. No modifica correos, feeds ni el editor.', 'dctwphero')
        );
        $options['rsd'] = self::cleanup_option(
            __('Eliminar enlace RSD', 'dctwphero'),
            __('La etiqueta del HTML que ayuda a los clientes de publicación a descubrir XML-RPC.', 'dctwphero'),
            __('XML-RPC y su URL siguen disponibles; no se bloquea la publicación remota.', 'dctwphero'),
            __('Un cliente que dependa del descubrimiento automático puede necesitar que configures la URL manualmente.', 'dctwphero')
        );
        $options['shortlinks'] = self::cleanup_option(
            __('Eliminar shortlinks', 'dctwphero'),
            __('Los enlaces cortos anunciados por WordPress en el HTML y las cabeceras HTTP.', 'dctwphero'),
            __('Los enlaces permanentes, las URL cortas existentes y los enlaces del contenido.', 'dctwphero'),
            __('Las herramientas que busquen automáticamente el shortlink dejarán de encontrar esa referencia.', 'dctwphero')
        );
        $options['dashicons'] = self::cleanup_option(
            __('Desactivar Dashicons para visitantes', 'dctwphero'),
            __('La carga directa de la hoja de iconos Dashicons para visitantes no autenticados.', 'dctwphero'),
            __('Los iconos para usuarios autenticados y las dependencias declaradas por otros estilos.', 'dctwphero'),
            __('Puede dejar iconos del tema sin dibujar. Otros estilos que dependan de Dashicons pueden volver a cargarlo. Revisa la web sin iniciar sesión.', 'dctwphero')
        );
        $options['rest_links'] = self::cleanup_option(
            __('Eliminar enlaces de descubrimiento REST', 'dctwphero'),
            __('Las referencias de descubrimiento de la API REST en el HTML y las cabeceras HTTP del frontend.', 'dctwphero'),
            __('La API REST, sus endpoints y las integraciones que ya conocen sus URL.', 'dctwphero'),
            __('Algunos clientes usan estos enlaces para localizar la API. No desactiva REST ni añade protección de acceso.', 'dctwphero')
        );
        $options['oembed_links'] = self::cleanup_option(
            __('Eliminar enlaces de descubrimiento oEmbed', 'dctwphero'),
            __('Las etiquetas de descubrimiento oEmbed JSON y XML de la cabecera HTML.', 'dctwphero'),
            __('Los endpoints oEmbed, sus scripts y el contenido incrustado que ya existe en tus páginas.', 'dctwphero'),
            __('Otras webs pueden dejar de descubrir cómo incrustar tus entradas. No desactiva todo el sistema oEmbed.', 'dctwphero')
        );
        $options['generator'] = self::cleanup_option(
            __('Eliminar meta generator', 'dctwphero'),
            __('La etiqueta meta generator que WordPress imprime en la cabecera HTML.', 'dctwphero'),
            __('El resto de metadatos y el generator de los feeds; no oculta todas las referencias a la versión.', 'dctwphero'),
            __('Es limpieza cosmética, no una medida de seguridad. No sustituye mantener WordPress, temas y plugins actualizados.', 'dctwphero')
        );
        $options['feed_links'] = self::cleanup_option(
            __('Eliminar enlaces de descubrimiento RSS de contenido', 'dctwphero'),
            __('Los enlaces RSS que WordPress anuncia en el HTML para entradas, categorías, etiquetas, taxonomías, autores, búsquedas y archivos de tipos de contenido.', 'dctwphero'),
            __('Los feeds siguen accesibles por su URL. Los enlaces RSS de comentarios tienen una opción independiente.', 'dctwphero'),
            __('Los lectores y navegadores pueden dejar de descubrir las suscripciones automáticamente. Conserva estos enlaces si ofreces suscripción RSS.', 'dctwphero')
        );
        $options['comments_feed_links'] = self::cleanup_option(
            __('Eliminar enlaces de descubrimiento RSS de comentarios', 'dctwphero'),
            __('Los enlaces RSS de comentarios que WordPress anuncia en el HTML, tanto el general como el de cada entrada.', 'dctwphero'),
            __('Los comentarios, sus formularios y los feeds de comentarios siguen funcionando por su URL.', 'dctwphero'),
            __('Puede dificultar descubrir la suscripción a conversaciones. No desactiva comentarios ni elimina datos.', 'dctwphero')
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
