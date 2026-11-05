<?php

defined('ABSPATH') || exit;

final class DCTWPHero_Login_Settings
{
    ################################################
    #
    #  defaults
    #
    #  Define los ajustes iniciales de la protección de login.
    #
    ################################################
    public static function defaults(): array
    {
        $settings = array();
        $settings['threshold'] = 3;
        $settings['window'] = 1;
        $settings['duration'] = 15;

        return $settings;
    }

    ################################################
    #
    #  get
    #
    #  Completa opciones parciales y recupera valores seguros ante datos inválidos.
    #
    ################################################
    public static function get(): array
    {
        $defaults = self::defaults();
        $saved = get_option('dctwphero_settings', array());

        return self::validate(is_array($saved) ? array_replace($defaults, $saved) : null) ?? $defaults;
    }

    ################################################
    #
    #  limits
    #
    #  Define los máximos compartidos por validación y formulario de ajustes.
    #
    ################################################
    public static function limits(): array
    {
        $limits = array();
        $limits['threshold'] = 10000;
        $limits['window'] = 10080;
        $limits['duration'] = 10080;

        return $limits;
    }

    ################################################
    #
    #  validate
    #
    #  Normaliza ajustes completos o rechaza valores que no sean enteros en rango.
    #
    ################################################
    public static function validate(mixed $input): ?array
    {
        $result = array();

        foreach (self::limits() as $key => $maximum)
        {
            $value = is_array($input) ? ($input[$key] ?? null) : null;

            if ((!is_int($value) && !is_string($value)) || !ctype_digit((string) $value)
                || (int) $value < 1 || (int) $value > $maximum)
            {
                return null;
            }

            $result[$key] = (int) $value;
        }

        return $result;
    }
}
