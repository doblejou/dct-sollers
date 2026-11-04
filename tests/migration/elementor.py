"""Prueba opcional con una copia real de Elementor, siempre en la instalación desechable."""

import base64
import json
import shutil

from scenarios import rewrite_package, set_entry


def cache_scenarios(wp, wp_root, source, archive, fixture):
    if source is None:
        print('SKIP: Elementor real no indicado; se han ejecutado las pruebas de API con dobles.')
        return
    target = wp_root / 'wp-content/plugins/elementor'
    shutil.copytree(source, target)
    network_guard = wp_root / 'wp-content/mu-plugins/dct-elementor-network-guard.php'
    network_guard.write_text('<?php add_filter("pre_http_request", static fn() => new WP_Error("fixture_offline", "Prueba sin red"));')
    css = wp_root / 'wp-content/uploads/elementor/css/post-1.css'
    css.parent.mkdir(parents=True, exist_ok=True)
    css.write_text('/* old generated CSS */')
    script = wp_root / 'wp-content/plugins/dctwphero/modules/migration/flush-cache.php'
    try:
        output = wp('eval-file', str(script))
        assert 'omitida' in output and css.exists(), 'No activa Elementor instalado pero desactivado'
        wp('plugin', 'activate', 'elementor')
        active_plugins = wp('eval', 'echo base64_encode(serialize(get_option("active_plugins")));', '--skip-plugins', '--skip-themes').strip()

        def enable_elementor(manifest, files):
            entry = manifest['tables']['options']
            lines = files[entry].splitlines()
            header = json.loads(lines[0])
            columns = header['columns']
            rows = [json.loads(line) for line in lines[1:]]
            for row in rows:
                if base64.b64decode(row[columns.index('option_name')]).decode() == 'active_plugins':
                    row[columns.index('option_value')] = active_plugins
            payload = b'\n'.join(json.dumps(row).encode() for row in [header, *rows]) + b'\n'
            set_entry(manifest, files, entry, payload)
            entry = manifest['tables']['postmeta']
            lines = files[entry].splitlines()
            header = json.loads(lines[0])
            values = {'meta_id': '90001', 'post_id': '1', 'meta_key': '_elementor_css', 'meta_value': 'a:1:{s:4:"time";i:1;}'}
            row = [base64.b64encode(values[column].encode()).decode() for column in header['columns']]
            set_entry(manifest, files, entry, files[entry] + json.dumps(row).encode() + b'\n')

        package = fixture / 'elementor-active.zip'
        rewrite_package(archive, package, enable_elementor)
        css.write_text('/* old generated CSS */')
        output = wp('dctwphero', 'import', f'--file={package}', '--mode=merge', '--yes')
        assert 'Caché CSS de Elementor limpiada' in output
        assert 'Caché de objetos de WordPress limpiada' in output
        assert not css.exists(), 'El CSS anterior se invalida'
        assert wp('eval', 'echo metadata_exists("post", 1, "_elementor_css") ? "present" : "absent";', '--skip-plugins', '--skip-themes').strip() == 'absent'

        failing = fixture / 'elementor-failure.zip'
        entry = 'content/mu-plugins/dct-elementor-cache-failure.php'
        payload = b'<?php add_action("elementor/core/files/clear_cache", static function() { if (class_exists("DCTWPHero_Migration_Cache", false)) { throw new RuntimeException("Cache fixture failure"); } });'
        rewrite_package(package, failing, lambda manifest, files: set_entry(manifest, files, entry, payload))
        output = wp('dctwphero', 'import', f'--file={failing}', '--mode=merge', '--yes', expected=1)
        assert 'flush-cache.php' in output and (wp_root / '.maintenance').exists()
        (wp_root / 'wp-content/mu-plugins/dct-elementor-cache-failure.php').unlink()
        (wp_root / '.maintenance').unlink()
        wp('plugin', 'deactivate', 'elementor')
        wp('plugin', 'activate', 'elementor')
        output = wp('dctwphero', 'import', f'--file={archive}', '--mode=mirror', '--yes')
        assert not target.exists(), 'mirror elimina también el directorio de Elementor del destino'
        assert 'Fatal error' not in output and not (wp_root / '.maintenance').exists()
        print('OK: Elementor real inactivo/activo, limpieza CSS, fallo con mantenimiento y eliminación completa de Elementor activo sin fatal al cerrar.')
    finally:
        network_guard.unlink(missing_ok=True)
