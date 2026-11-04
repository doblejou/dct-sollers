<?php

final class DCTWPHero_Checks
{
    private static int $count = 0;

    ################################################
    #
    #  that
    #
    #  Registra una comprobación estricta y detiene la prueba con un diagnóstico útil.
    #
    ################################################
    public static function that(bool $condition, string $message): void
    {
        if (!$condition)
        {
            throw new RuntimeException('Fallo: ' . $message);
        }

        self::$count++;
    }

    ################################################
    #
    #  rejects
    #
    #  Exige rechazo controlado sin aceptar errores de programación como validación.
    #
    ################################################
    public static function rejects(callable $operation, string $message): void
    {
        try
        {
            $operation();
        }
        catch (RuntimeException | JsonException $error)
        {
            self::that(true, $message);

            return;
        }

        throw new RuntimeException('No se rechazó: ' . $message);
    }

    ################################################
    #
    #  changed
    #
    #  Genera variantes de documentos por rutas de prueba separadas con >.
    #
    ################################################
    public static function changed(array $document, string $path, mixed $value): array
    {
        $destination =& $document;

        foreach (explode('>', $path) as $key)
        {
            $destination =& $destination[$key];
        }

        $destination = $value;

        return $document;
    }

    ################################################
    #
    #  report
    #
    #  Devuelve el número de aserciones realmente completadas, sin inferir cobertura.
    #
    ################################################
    public static function report(): void
    {
        echo 'OK: ' . self::$count . " aserciones completadas.\n";
    }

}
