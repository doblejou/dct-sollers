<?php

defined('ABSPATH') || exit;

final class DCTWPHero_CDN_Settings
{
    ################################################
    #
    #  defaults
    #
    #  Centraliza los ajustes iniciales sin habilitar un CDN automáticamente.
    #
    ################################################
    public static function defaults(): array
    {
        $settings = array();
        $settings['hostname'] = '';
        $settings['extensions'] = ".avif\n.css\n.gif\n.jpeg\n.jpg\n.js\n.json\n.mp3\n.mp4\n.pdf\n.png\n.svg\n.webp\n.woff\n.woff2";
        $settings['exclusions'] = '';
        $settings['relative'] = 1;

        return $settings;
    }

    ################################################
    #
    #  validate
    #
    #  Normaliza listas y rechaza documentos incompletos o dominios ambiguos.
    #
    ################################################
    public static function validate(mixed $input): ?array
    {
        $defaults = self::defaults();

        if (!is_array($input) || array_diff_key($input, $defaults) !== array() || array_diff_key($defaults, $input) !== array())
        {
            return null;
        }

        foreach (array('hostname', 'extensions', 'exclusions') as $key)
        {
            if (!is_string($input[$key]) || strlen($input[$key]) > 16384)
            {
                return null;
            }
        }

        $hostname = strtolower(trim($input['hostname']));

        if ($hostname !== '' && (strlen($hostname) > 253 || !filter_var($hostname, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) || !str_contains($hostname, '.') || filter_var($hostname, FILTER_VALIDATE_IP)))
        {
            return null;
        }

        if (!in_array($input['relative'], array(0, 1, '0', '1'), true))
        {
            return null;
        }

        $result = array();
        $result['hostname'] = $hostname;

        foreach (array('extensions', 'exclusions') as $key)
        {
            $lines = preg_split('/\r\n|\r|\n/', $input[$key]);
            $normalized = array();

            foreach ($lines as $line)
            {
                $line = trim($line);

                if ($line === '')
                {
                    continue;
                }

                if ($key === 'extensions')
                {
                    $line = strtolower($line);

                    if (!preg_match('/^\.[a-z0-9]{1,10}$/D', $line))
                    {
                        return null;
                    }
                }
                elseif (preg_match('/[\x00-\x1f\x7f]/', $line))
                {
                    return null;
                }

                $normalized[] = $line;
            }

            $result[$key] = implode("\n", array_unique($normalized));
        }

        $result['relative'] = (int) $input['relative'];

        return $result;
    }

    ################################################
    #
    #  get
    #
    #  Completa opciones parciales y usa valores seguros ante datos inválidos.
    #
    ################################################
    public static function get(): array
    {
        $saved = get_option('dctwphero_cdn_settings', array());

        return is_array($saved) ? (self::validate(array_replace(self::defaults(), $saved)) ?? self::defaults()) : self::defaults();
    }
}
