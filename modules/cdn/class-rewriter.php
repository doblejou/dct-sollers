<?php

defined('ABSPATH') || exit;

final class DCTWPSollers_CDN_Rewriter
{
    private array $origins = array();
    private array $extensions;
    private array $exclusions;
    private string $hostname;
    private bool $relative;

    ################################################
    #
    #  __construct
    #
    #  Prepara una configuración ya validada y autoridades de origen exactas.
    #
    ################################################
    public function __construct(array $settings, array $origin_urls)
    {
        $this->hostname = $settings['hostname'];
        $this->relative = (bool) $settings['relative'];
        $this->extensions = array_filter(explode("\n", $settings['extensions']));
        $this->exclusions = array_filter(explode("\n", $settings['exclusions']), static fn(string $line): bool => $line !== '');

        foreach ($origin_urls as $url)
        {
            $parts = wp_parse_url($url);

            if (is_array($parts) && isset($parts['host']))
            {
                $this->origins[] = strtolower($parts['host']) . (isset($parts['port']) ? ':' . $parts['port'] : '');
            }
        }
    }

    ################################################
    #
    #  rewrite
    #
    #  Reescribe candidatos completos sin interpretar ni persistir el documento.
    #
    ################################################
    public function rewrite(string $contents): string
    {
        if ($this->hostname === '' || $this->extensions === array())
        {
            return $contents;
        }

        $pattern = '~(?<![^\s"\'(<>=,;])(?:https?:)?(?:/|\\\\/)(?:&(?:\#\d+|\#x[a-f0-9]+|[a-z][a-z0-9]*);|[^\s"\'<>(),;])*~i';
        $result = preg_replace_callback($pattern, fn(array $match): string => $this->rewrite_url($match[0]), $contents);

        return $result ?? $contents;
    }

    ################################################
    #
    #  rewrite_url
    #
    #  Sustituye solo autoridades locales y rutas raíz con extensión permitida.
    #
    ################################################
    private function rewrite_url(string $original): string
    {
        $escaped = str_contains($original, '\\/');
        $url = str_replace('\\/', '/', $original);

        foreach ($this->exclusions as $excluded)
        {
            if (str_contains($url, $excluded) || str_contains($original, $excluded))
            {
                return $original;
            }
        }

        $parts = wp_parse_url($url);

        if (!is_array($parts) || isset($parts['user']) || isset($parts['pass']))
        {
            return $original;
        }

        $extension = '.' . strtolower(pathinfo($parts['path'] ?? '', PATHINFO_EXTENSION));

        if (!in_array($extension, $this->extensions, true))
        {
            return $original;
        }

        if (isset($parts['host']))
        {
            $authority = strtolower($parts['host']) . (isset($parts['port']) ? ':' . $parts['port'] : '');

            if ($authority === $this->hostname || !in_array($authority, $this->origins, true))
            {
                return $original;
            }

            $url = preg_replace('~^((?:https?:)?//)[^/]+~i', '${1}' . $this->hostname, $url);
        }
        elseif ($this->relative && str_starts_with($url, '/') && !str_starts_with($url, '//'))
        {
            $url = '//' . $this->hostname . $url;
        }
        else
        {
            return $original;
        }

        return $escaped ? str_replace('/', '\\/', $url) : $url;
    }
}
