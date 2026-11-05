<?php

defined('ABSPATH') || exit;

final class DCTWPHero_CDN_Module
{
    private static ?DCTWPHero_CDN_Rewriter $rewriter = null;
    private static bool $buffering = false;
    private static string $pending = '';

    ################################################
    #
    #  register
    #
    #  Conecta la captura web y los campos renderizados de REST sin intervenir en CLI.
    #
    ################################################
    public static function register(): void
    {
        if (defined('WP_CLI') && WP_CLI)
        {
            return;
        }

        add_action('setup_theme', self::start_buffer(...));
        add_action('rest_api_init', self::register_rest(...));
    }

    ################################################
    #
    #  rewriter
    #
    #  Construye una sola instancia por petición con los orígenes de WordPress.
    #
    ################################################
    private static function rewriter(): DCTWPHero_CDN_Rewriter
    {
        return self::$rewriter ??= new DCTWPHero_CDN_Rewriter(DCTWPHero_CDN_Settings::get(), array(home_url(), site_url()));
    }

    ################################################
    #
    #  eligible_request
    #
    #  Descarta contextos que no deben pasar por la reescritura pública.
    #
    ################################################
    private static function eligible_request(): bool
    {
        return ($_SERVER['REQUEST_METHOD'] ?? '') === 'GET'
            && !is_admin()
            && !wp_doing_ajax()
            && !wp_doing_cron()
            && ($GLOBALS['pagenow'] ?? '') !== 'wp-login.php'
            && !(defined('XMLRPC_REQUEST') && XMLRPC_REQUEST)
            && !(defined('WP_CLI') && WP_CLI);
    }

    ################################################
    #
    #  start_buffer
    #
    #  Captura la respuesta antes del tema, únicamente con configuración efectiva.
    #
    ################################################
    public static function start_buffer(): void
    {
        if (self::$buffering || !self::eligible_request() || DCTWPHero_CDN_Settings::get()['hostname'] === '')
        {
            return;
        }

        self::$buffering = true;
        ob_start(self::finish_buffer(...));
    }

    ################################################
    #
    #  finish_buffer
    #
    #  Acumula fragmentos y transforma solo HTML final no comprimido ni descargable.
    #
    ################################################
    public static function finish_buffer(string $contents, int $phase): string
    {
        if ($phase & PHP_OUTPUT_HANDLER_CLEAN)
        {
            self::$pending = '';

            if ($phase & PHP_OUTPUT_HANDLER_FINAL)
            {
                self::$buffering = false;
            }

            return '';
        }

        self::$pending .= $contents;

        if (!($phase & PHP_OUTPUT_HANDLER_FINAL))
        {
            return '';
        }

        $contents = self::$pending;
        self::$pending = '';
        self::$buffering = false;

        if (!self::eligible_request() || (defined('REST_REQUEST') && REST_REQUEST) || is_preview() || is_robots() || is_trackback() || is_feed() || http_response_code() >= 300)
        {
            return $contents;
        }

        $html = false;

        foreach (headers_list() as $header)
        {
            if (stripos($header, 'Content-Type:') === 0)
            {
                $html = (bool) preg_match('~^Content-Type:\s*text/html(?:\s*;|\s*$)~i', $header);

                if (!$html)
                {
                    return $contents;
                }
            }

            if (stripos($header, 'Content-Disposition:') === 0 || stripos($header, 'Content-Encoding:') === 0)
            {
                return $contents;
            }
        }

        if (!$html && !preg_match('/^\s*(?:<!doctype\s+html\b|<html\b)/i', $contents))
        {
            return $contents;
        }

        if (headers_sent())
        {
            return $contents;
        }

        $rewritten = self::rewriter()->rewrite($contents);

        if ($rewritten !== $contents)
        {
            header_remove('Content-Length');
            header_remove('ETag');
            header_remove('Content-MD5');
        }

        return $rewritten;
    }

    ################################################
    #
    #  register_rest
    #
    #  Conecta únicamente las respuestas de tipos de contenido expuestos en REST.
    #
    ################################################
    public static function register_rest(): void
    {
        $arguments = array();
        $arguments['show_in_rest'] = true;

        foreach (get_post_types($arguments) as $post_type)
        {
            add_filter('rest_prepare_' . $post_type, self::rewrite_rest(...), PHP_INT_MAX, 3);
        }
    }

    ################################################
    #
    #  rewrite_rest
    #
    #  Reescribe contenido y extracto renderizados sin tocar raw ni otros campos JSON.
    #
    ################################################
    public static function rewrite_rest(WP_REST_Response $response, WP_Post $post, WP_REST_Request $request): WP_REST_Response
    {
        if (!self::eligible_request() || $request->get_method() !== 'GET' || $request->get_param('context') === 'edit' || $request->get_param('preview'))
        {
            return $response;
        }

        $data = $response->get_data();

        foreach (array('content', 'excerpt') as $field)
        {
            if (isset($data[$field]['rendered']) && is_string($data[$field]['rendered']))
            {
                $data[$field]['rendered'] = self::rewriter()->rewrite($data[$field]['rendered']);
            }
        }

        $response->set_data($data);

        return $response;
    }
}
