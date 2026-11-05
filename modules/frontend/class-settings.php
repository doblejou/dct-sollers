<?php

defined('ABSPATH') || exit;

final class DCTWPHero_Frontend_Settings
{
    ################################################
    #
    #  defaults
    #
    #  Mantiene toda limpieza y optimización desactivada inicialmente.
    #
    ################################################
    public static function defaults(): array
    {
        $settings = array();
        $settings['emojis'] = 0;
        $settings['rsd'] = 0;
        $settings['shortlinks'] = 0;
        $settings['dashicons'] = 0;
        $settings['rest_links'] = 0;
        $settings['oembed_links'] = 0;
        $settings['generator'] = 0;
        $settings['feed_links'] = 0;
        $settings['comments_feed_links'] = 0;
        $settings['preconnect'] = '';
        $settings['dns_prefetch'] = '';
        $settings['fonts'] = '';
        $settings['image'] = '';

        return $settings;
    }

    ################################################
    #
    #  get
    #
    #  Completa ajustes parciales y recupera valores seguros ante corrupción.
    #
    ################################################
    public static function get(): array
    {
        $saved = get_option('dctwphero_frontend_settings', array());

        return is_array($saved) ? (self::validate(array_replace(self::defaults(), $saved)) ?? self::defaults()) : self::defaults();
    }

    ################################################
    #
    #  validate
    #
    #  Valida documentos completos, normaliza listas y limita precargas explícitas.
    #
    ################################################
    public static function validate(mixed $input): ?array
    {
        $defaults = self::defaults();

        if (!is_array($input) || array_diff_key($input, $defaults) || array_diff_key($defaults, $input))
        {
            return null;
        }

        $result = array();

        foreach ($defaults as $key => $default)
        {
            $value = $input[$key];

            if (is_int($default))
            {
                if (!DCTWPHero::valid_module_state($value))
                {
                    return null;
                }

                $result[$key] = (int) $value;
                continue;
            }

            if (!is_string($value) || strlen($value) > 16384)
            {
                return null;
            }

            $lines = array();

            foreach (preg_split('/\r\n|\r|\n/', $value) as $line)
            {
                $line = trim($line);

                if ($line === '')
                {
                    continue;
                }

                $url = self::normalize_url($line, $key);

                if ($url === null)
                {
                    return null;
                }

                $lines[] = $url;
            }

            $lines = array_unique($lines);
            $limit = $key === 'image' ? 1 : ($key === 'fonts' ? 5 : 10);

            if (count($lines) > $limit)
            {
                return null;
            }

            $result[$key] = implode("\n", $lines);
        }

        return $result;
    }

    ################################################
    #
    #  normalize_url
    #
    #  Acepta orígenes HTTP(S), hosts DNS y recursos absolutos sin credenciales.
    #
    ################################################
    private static function normalize_url(string $value, string $key): ?string
    {
        if (preg_match('/[\x00-\x20\x7f<>"\'\\\\]/', $value))
        {
            return null;
        }

        if ($key === 'dns_prefetch')
        {
            $host = strtolower($value);

            return strlen($host) <= 253 && str_contains($host, '.') && filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) && !filter_var($host, FILTER_VALIDATE_IP) ? $host : null;
        }

        $parts = wp_parse_url($value);

        if (!is_array($parts) || !isset($parts['scheme'], $parts['host']) || !in_array($parts['scheme'], array('http', 'https'), true)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment']) || !filter_var($value, FILTER_VALIDATE_URL)
            || !filter_var($parts['host'], FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME))
        {
            return null;
        }

        if ($key === 'preconnect')
        {
            if (isset($parts['query']) || !in_array($parts['path'] ?? '', array('', '/'), true))
            {
                return null;
            }

            return $parts['scheme'] . '://' . strtolower($parts['host']) . (isset($parts['port']) ? ':' . $parts['port'] : '');
        }

        $path = $parts['path'] ?? '';

        if ($key === 'fonts' && !preg_match('/\.woff2$/iD', $path))
        {
            return null;
        }

        if ($key === 'image' && !preg_match('/\.(?:avif|gif|jpe?g|png|svg|webp)$/iD', $path))
        {
            return null;
        }

        return $value;
    }
}
