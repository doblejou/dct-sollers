"""Regresión de directorios vacíos y cierres de código que mirror elimina."""

import json
import shutil
import subprocess

from scenarios import rewrite_package


def shutdown_code(class_name, marker, dependency):
    return ('<?php\n'
            f'spl_autoload_register(static function($class) {{ if ($class === "{class_name}") {{ require __DIR__ . "/{dependency}"; }} }});\n'
            f'register_shutdown_function(static function() {{ new {class_name}(); file_put_contents({json.dumps(str(marker))}, "closed"); }});\n')


def mirror_scenarios(wp, wp_root, archive, fixture):
    content = wp_root / 'wp-content'
    plugin = content / 'plugins/shutdown-probe'
    plugin.mkdir()
    marker = fixture / 'plugin-shutdown-marker'
    (plugin / 'shutdown-probe.php').write_text('<?php /* Plugin Name: Shutdown Probe */ ?>' + shutdown_code('DCT_Plugin_Shutdown_Probe', marker, 'late.php'))
    (plugin / 'late.php').write_text('<?php final class DCT_Plugin_Shutdown_Probe {}')
    theme = content / 'themes/shutdown-theme'
    theme.mkdir()
    (theme / 'style.css').write_text('/* Theme Name: Shutdown Theme */')
    (theme / 'index.php').write_text('<?php // Fixture.')
    theme_marker = fixture / 'theme-shutdown-marker'
    (theme / 'functions.php').write_text(shutdown_code('DCT_Theme_Shutdown_Probe', theme_marker, 'late.php'))
    (theme / 'late.php').write_text('<?php final class DCT_Theme_Shutdown_Probe {}')
    mu_marker = fixture / 'mu-shutdown-marker'
    mu_file = content / 'mu-plugins/dct-shutdown-probe.php'
    mu_file.write_text(shutdown_code('DCT_Mu_Shutdown_Probe', mu_marker, 'dct-late.inc'))
    (mu_file.parent / 'dct-late.inc').write_text('<?php final class DCT_Mu_Shutdown_Probe {}')
    empty = content / 'plugins/old-empty-plugin/deep/empty'
    empty.mkdir(parents=True)
    protected = content / 'plugins/protected-parent/cache/empty'
    protected.mkdir(parents=True)
    (protected.parent.parent / 'remove-me').write_text('extra')
    future = content / 'plugins/future-parent'
    future.mkdir()

    def exclusions(manifest, files):
        manifest['excluded_paths'].extend(['plugins/protected-parent/cache', 'plugins/future-parent/missing/cache'])

    package = fixture / 'mirror-directories.zip'
    rewrite_package(archive, package, exclusions)
    wp('plugin', 'activate', 'shutdown-probe')
    wp('theme', 'activate', 'shutdown-theme')
    output = wp('dctwpsollers', 'import', f'--file={package}', '--mode=mirror', '--dry-run')
    assert 'Directorios extra a eliminar tras vaciarlos:' in output
    assert plugin.exists() and theme.exists() and empty.exists() and mu_file.exists()
    php = wp('eval', 'echo PHP_BINARY;').strip()
    invocation = [php, '-d', 'disable_functions=pcntl_exec', shutil.which('wp'), f'--path={wp_root}', '--allow-root']
    rejected = subprocess.run(invocation + ['dctwpsollers', 'import', f'--file={package}', '--mode=mirror', '--yes'], capture_output=True, text=True)
    assert rejected.returncode == 1 and 'pcntl_exec' in rejected.stderr and '--require' in rejected.stderr
    assert plugin.exists() and theme.exists() and not (wp_root / '.maintenance').exists()
    bootstrap = wp_root / 'wp-content/plugins/dctwpsollers/modules/migration/import-bootstrap.php'
    manual = invocation + ['--skip-plugins', '--skip-themes', f'--require={bootstrap}', 'dctwpsollers', 'import', f'--file={package}', '--mode=merge', '--yes']
    result = subprocess.run(manual, capture_output=True, text=True)
    assert result.returncode == 0, result.stdout + result.stderr
    assert plugin.exists() and theme.exists() and empty.exists() and mu_file.exists(), 'merge conserva extras'
    wp('plugin', 'activate', 'shutdown-probe')
    wp('theme', 'activate', 'shutdown-theme')
    for path in (marker, theme_marker, mu_marker):
        path.unlink(missing_ok=True)
    output = wp('dctwpsollers', 'import', f'--file={package}', '--mode=mirror', '--yes')
    assert 'Fatal error' not in output
    assert not plugin.exists() and not theme.exists() and not empty.parents[1].exists()
    assert not mu_file.exists() and not (mu_file.parent / 'dct-late.inc').exists()
    assert not any(path.exists() for path in (marker, theme_marker, mu_marker)), 'No se ejecutan cierres del código eliminado'
    assert protected.is_dir() and future.is_dir(), 'Conserva exclusiones y sus ancestros vacíos'
    assert not (protected.parent.parent / 'remove-me').exists()
    for name in ('plugins', 'themes', 'uploads', 'mu-plugins'):
        assert (content / name).is_dir(), 'Conserva las raíces estructurales de WordPress'
    output = wp('dctwpsollers', 'import', f'--file={package}', '--mode=mirror', '--yes')
    assert 'Archivos extra a eliminar: 0' in output and 'Directorios extra a eliminar tras vaciarlos: 0' in output
    print('OK: mirror borra carpetas completas y vacías, preserva exclusiones, es idempotente, evita cierres antiguos y ofrece arranque explícito sin pcntl_exec.')
