<?php

defined('ABSPATH') || exit;

final class DCTWPSollers_Migration_Values
{
    ################################################
    #
    #  url
    #
    #  Valida URLs de origen y destino sin credenciales, consultas ni fragmentos.
    #
    ################################################
    public static function url(mixed $url): string
    {
        $parts = is_string($url) ? wp_parse_url($url) : false;

        if ($parts === false || (($parts['scheme'] ?? '') !== 'http' && ($parts['scheme'] ?? '') !== 'https')
            || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment']) || preg_match('/[\\\\\x00-\x20\x7f]/', $url))
        {
            throw new RuntimeException('URL HTTP(S) inválida.');
        }

        return untrailingslashit($url);
    }

    ################################################
    #
    #  replace
    #
    #  Adapta cadenas y longitudes serializadas sin instanciar objetos PHP.
    #
    ################################################
    public static function replace(string $value, array $map, int $depth = 0): string
    {
        if ($depth > 128)
        {
            throw new RuntimeException('Serialización demasiado anidada.');
        }

        if (!preg_match('/^(?:N;|[bidrR]:[+\-.0-9NI]|[saOEC]:\d+:)/', $value))
        {
            return strtr($value, $map);
        }

        $offset = 0;
        $result = self::token($value, $offset, $map, $depth);

        if ($offset !== strlen($value))
        {
            throw new RuntimeException('Datos posteriores a una estructura serializada.');
        }

        return $result;
    }

    ################################################
    #
    #  token
    #
    #  Recorre la gramática serializada conservando referencias y nombres de clases.
    #
    ################################################
    private static function token(string $value, int &$offset, array $map, int $depth): string
    {
        if ($depth > 128)
        {
            throw new RuntimeException('Serialización demasiado anidada.');
        }

        $type = $value[$offset] ?? '';

        if (preg_match('/\G(?:N;|b:[01];|i:[+-]?\d+;|d:(?:[+-]?(?:\d+(?:\.\d*)?|\.\d+)(?:[Ee][+-]?\d+)?|NAN|[+-]?INF);|[rR]:[1-9]\d*;)/', $value, $match, 0, $offset))
        {
            $offset += strlen($match[0]);

            return $match[0];
        }

        if ($type === 's' || $type === 'E')
        {
            self::expect($value, $offset, $type . ':');
            $length = self::number($value, $offset);
            self::expect($value, $offset, ':"');
            $body = self::bytes($value, $offset, $length);
            self::expect($value, $offset, '";');
            $body = $type === 's' ? self::replace($body, $map, $depth + 1) : $body;

            return $type . ':' . strlen($body) . ':"' . $body . '";';
        }

        if ($type !== 'a' && $type !== 'O' && $type !== 'C')
        {
            throw new RuntimeException('Token serializado no válido.');
        }

        self::expect($value, $offset, $type . ':');
        $length = self::number($value, $offset);
        $prefix = $type . ':' . $length;

        if ($type === 'O' || $type === 'C')
        {
            self::expect($value, $offset, ':"');
            $class = self::bytes($value, $offset, $length);
            self::expect($value, $offset, '":');
            $length = self::number($value, $offset);
            $prefix .= ':"' . $class . '":' . $length;
        }

        self::expect($value, $offset, ':{');
        $body = '';

        if ($type === 'C')
        {
            $body = self::bytes($value, $offset, $length);

            if (strtr($body, $map) !== $body)
            {
                throw new RuntimeException('Serialización personalizada C: no compatible con los reemplazos.');
            }
        }
        else
        {
            if ($length > strlen($value) - $offset)
            {
                throw new RuntimeException('Número de elementos serializados inválido.');
            }

            for ($index = 0; $index < $length; $index++)
            {
                $key = $value[$offset] ?? '';

                if ($key !== 's' && !($type === 'a' && $key === 'i'))
                {
                    throw new RuntimeException('Clave serializada inválida.');
                }

                $body .= self::token($value, $offset, $map, $depth + 1);
                $body .= self::token($value, $offset, $map, $depth + 1);
            }
        }

        self::expect($value, $offset, '}');

        return $prefix . ':{' . $body . '}';
    }

    ################################################
    #
    #  expect
    #
    #  Consume un delimitador exacto sin aceptar estructuras truncadas.
    #
    ################################################
    private static function expect(string $value, int &$offset, string $expected): void
    {
        if (substr($value, $offset, strlen($expected)) !== $expected)
        {
            throw new RuntimeException('Delimitador serializado inválido.');
        }

        $offset += strlen($expected);
    }

    ################################################
    #
    #  number
    #
    #  Lee longitudes y recuentos sin desbordar el entero de la plataforma.
    #
    ################################################
    private static function number(string $value, int &$offset): int
    {
        if (!preg_match('/\G(?:0|[1-9]\d*)/', $value, $match, 0, $offset)
            || strlen($match[0]) > strlen((string) PHP_INT_MAX)
            || (strlen($match[0]) === strlen((string) PHP_INT_MAX) && strcmp($match[0], (string) PHP_INT_MAX) > 0))
        {
            throw new RuntimeException('Longitud serializada inválida.');
        }

        $offset += strlen($match[0]);

        return (int) $match[0];
    }

    ################################################
    #
    #  bytes
    #
    #  Lee una cadena binaria de longitud declarada, comprobando sus límites.
    #
    ################################################
    private static function bytes(string $value, int &$offset, int $length): string
    {
        if ($length > strlen($value) - $offset)
        {
            throw new RuntimeException('Cadena serializada truncada.');
        }

        $result = substr($value, $offset, $length);
        $offset += $length;

        return $result;
    }

    ################################################
    #
    #  map
    #
    #  Construye reemplazos simultáneos de URLs y rutas, incluidas variantes escapadas.
    #
    ################################################
    public static function map(array $source, array $target): array
    {
        $map = array();

        $keys = array
        (
            'home',
            'site',
            'stored_home',
            'stored_site',
            'root',
            'content',
            'uploads',
            'uploads_url',

        );

        foreach ($keys as $key)
        {
            $old = $source[$key] ?? null;
            $target_key = match ($key)
            {
                'stored_home' => 'home',
                'stored_site' => 'site',
                default => $key,
            };
            $new = $target[$target_key] ?? null;

            if (!is_string($old) || $old === '' || !is_string($new))
            {
                continue;
            }

            $map[$old] ??= $new;
            $map[str_replace('/', '\\/', $old)] ??= str_replace('/', '\\/', $new);
            $map[urlencode($old)] ??= urlencode($new);
            $map[rawurlencode($old)] ??= rawurlencode($new);
        }

        return $map;
    }

}
