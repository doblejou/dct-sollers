<?php

require_once dirname(__DIR__) . '/checks.php';
require_once dirname(__DIR__, 2) . '/modules/migration/class-cache.php';

final class DCTWPHero_Migration_Elementor_Probe
{
    public static object $instance;
}

final class DCTWPHero_Migration_Files_Probe
{
    public int $calls = 0;
    public bool $fail = false;

    ################################################
    #
    #  clear_cache
    #
    #  Registra las llamadas a la API y permite simular un fallo de Elementor.
    #
    ################################################
    public function clear_cache(): void
    {
        if ($this->fail)
        {
            throw new RuntimeException('Fallo de caché simulado.');
        }

        $this->calls++;
    }

}

wp_cache_set('migration-cache-probe', 'cached');
DCTWPHero_Migration_Cache::flush();
DCTWPHero_Checks::that(wp_cache_get('migration-cache-probe') === false, 'Limpia caché sin Elementor');
class_alias(DCTWPHero_Migration_Elementor_Probe::class, 'Elementor\Plugin');
DCTWPHero_Migration_Elementor_Probe::$instance = new stdClass();
$manager = new DCTWPHero_Migration_Files_Probe();
DCTWPHero_Migration_Elementor_Probe::$instance->files_manager = $manager;
do_action('elementor/loaded');
wp_cache_set('migration-cache-probe', 'cached');
DCTWPHero_Migration_Cache::flush();
DCTWPHero_Checks::that($manager->calls === 1, 'Invoca clear_cache de Elementor una vez');
DCTWPHero_Checks::that(wp_cache_get('migration-cache-probe') === false, 'Termina limpiando caché de objetos');
$manager->fail = true;
DCTWPHero_Checks::rejects(DCTWPHero_Migration_Cache::flush(...), 'Propaga errores de Elementor');
DCTWPHero_Migration_Elementor_Probe::$instance->files_manager = null;
DCTWPHero_Checks::rejects(DCTWPHero_Migration_Cache::flush(...), 'Rechaza API incompatible de Elementor cargado');
DCTWPHero_Checks::report();
