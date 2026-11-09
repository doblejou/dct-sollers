<?php

defined('ABSPATH') || exit;

final class DCTWPSollers_Frontend_Module
{
    ################################################
    #
    #  register
    #
    #  Espera a la consulta pública antes de aplicar cambios al frontend.
    #
    ################################################
    public static function register(): void
    {
        add_action('template_redirect', self::prepare(...), 0);
    }

    ################################################
    #
    #  prepare
    #
    #  Excluye contextos especiales y modifica únicamente hooks del frontend.
    #
    ################################################
    public static function prepare(): void
    {
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Exact GET comparison only; never stored or rendered. Sanitizing could turn rejected input into an accepted method.
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET' || is_admin() || wp_doing_ajax() || wp_doing_cron()
            || (defined('WP_CLI') && WP_CLI) || (defined('REST_REQUEST') && REST_REQUEST)
            || (defined('XMLRPC_REQUEST') && XMLRPC_REQUEST) || ($GLOBALS['pagenow'] ?? '') === 'wp-login.php'
            || is_feed() || is_preview() || is_customize_preview() || is_embed() || is_robots() || is_trackback() || is_404())
        {
            return;
        }

        $settings = DCTWPSollers_Frontend_Settings::get();

        if ($settings['emojis'])
        {
            remove_action('wp_head', 'print_emoji_detection_script', 7);
            remove_action('wp_enqueue_scripts', 'wp_enqueue_emoji_styles');
            remove_action('wp_print_styles', 'print_emoji_styles');
        }

        if ($settings['rsd'])
        {
            remove_action('wp_head', 'rsd_link');
        }

        if ($settings['shortlinks'])
        {
            remove_action('wp_head', 'wp_shortlink_wp_head');
            remove_action('template_redirect', 'wp_shortlink_header', 11);
        }

        if ($settings['dashicons'] && !is_user_logged_in())
        {
            add_action('wp_enqueue_scripts', self::remove_dashicons(...), PHP_INT_MAX);
            add_action('wp_print_styles', self::remove_dashicons(...), PHP_INT_MAX);
        }

        if ($settings['rest_links'])
        {
            remove_action('wp_head', 'rest_output_link_wp_head');
            remove_action('template_redirect', 'rest_output_link_header', 11);
        }

        if ($settings['oembed_links'])
        {
            remove_action('wp_head', 'wp_oembed_add_discovery_links', 4);
            remove_action('wp_head', 'wp_oembed_add_discovery_links');
        }

        if ($settings['generator'])
        {
            remove_action('wp_head', 'wp_generator');
        }

        if ($settings['feed_links'])
        {
            $filters = array
            (
                'feed_links_show_posts_feed',
                'feed_links_extra_show_post_type_archive_feed',
                'feed_links_extra_show_category_feed',
                'feed_links_extra_show_tag_feed',
                'feed_links_extra_show_tax_feed',
                'feed_links_extra_show_author_feed',
                'feed_links_extra_show_search_feed',

            );

            foreach ($filters as $filter)
            {
                add_filter($filter, '__return_false');
            }
        }

        if ($settings['comments_feed_links'])
        {
            add_filter('feed_links_show_comments_feed', '__return_false');
            add_filter('feed_links_extra_show_post_comments_feed', '__return_false');
        }

        add_filter('wp_resource_hints', self::resource_hints(...), 20, 2);
        add_filter('wp_preload_resources', self::preload_resources(...), 20);
    }

    ################################################
    #
    #  remove_dashicons
    #
    #  Retira la hoja de iconos sin desregistrar dependencias de otros recursos.
    #
    ################################################
    public static function remove_dashicons(): void
    {
        wp_dequeue_style('dashicons');
    }

    ################################################
    #
    #  resource_hints
    #
    #  Amplía los hints nativos y retira solo el origen del CDN de emojis.
    #
    ################################################
    public static function resource_hints(array $urls, string $relation): array
    {
        $settings = DCTWPSollers_Frontend_Settings::get();

        if ($relation === 'dns-prefetch' && $settings['emojis'])
        {
            $urls = array_filter($urls, static function ($entry): bool
            {
                $url = is_array($entry) ? ($entry['href'] ?? '') : $entry;

                // phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- Compares the emoji CDN host only to remove its DNS hint; no content is loaded or offloaded.
                return !is_string($url) || strtolower((string) wp_parse_url($url, PHP_URL_HOST)) !== 's.w.org';
            });
        }

        $key = $relation === 'dns-prefetch' ? 'dns_prefetch' : 'preconnect';

        if (!in_array($relation, array('dns-prefetch', 'preconnect'), true) || $settings[$key] === '')
        {
            return $urls;
        }

        foreach (explode("\n", $settings[$key]) as $url)
        {
            if ($relation === 'dns-prefetch')
            {
                // phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- Prevents reintroducing a disabled emoji DNS hint from the saved list; no remote request.
                if (!$settings['emojis'] || $url !== 's.w.org')
                {
                    $urls[] = '//' . $url;
                }
            }
            else
            {
                $entry = array();
                $entry['href'] = $url;
                $entry['crossorigin'] = 'anonymous';
                $urls[] = $entry;
            }
        }

        return $urls;
    }

    ################################################
    #
    #  preload_resources
    #
    #  Añade fuentes WOFF2 y una imagen solo en la portada real del sitio.
    #
    ################################################
    public static function preload_resources(array $resources): array
    {
        $settings = DCTWPSollers_Frontend_Settings::get();

        if ($settings['fonts'] !== '')
        {
            foreach (explode("\n", $settings['fonts']) as $url)
            {
                $font = array();
                $font['href'] = $url;
                $font['as'] = 'font';
                $font['type'] = 'font/woff2';
                $font['crossorigin'] = 'anonymous';
                $resources[] = $font;
            }
        }

        if ($settings['image'] !== '' && is_front_page() && !is_paged())
        {
            $image = array();
            $image['href'] = $settings['image'];
            $image['as'] = 'image';
            $image['fetchpriority'] = 'high';
            $resources[] = $image;
        }

        return $resources;
    }
}
