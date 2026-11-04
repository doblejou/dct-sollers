#!/usr/bin/env python3
"""Prueba migraciones solo en una copia desechable con prefijo aleatorio propio."""

import argparse
import hashlib
import json
import os
from pathlib import Path
import secrets
import shutil
import socket
import subprocess
import tempfile
import time
import urllib.request
import warnings
import zipfile
from scenarios import arguments as argument_scenarios, packages as package_scenarios, database_failures, rewrite_package, set_entry
from elementor import cache_scenarios
from mirror import mirror_scenarios
from foreign_keys import create as create_foreign_keys, verify as verify_foreign_keys, failures as foreign_key_failures
from operation_guard import scenarios as operation_guard_scenarios


def run(arguments, expected=0, stdin=None):
    result = subprocess.run(arguments, input=stdin, text=True, capture_output=True)
    if result.returncode != expected:
        raise RuntimeError(f"Fallo ({result.returncode}): {' '.join(arguments[:4])}\n{result.stdout}\n{result.stderr}")
    return result.stdout + result.stderr


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--wp-path', required=True, type=Path)
    parser.add_argument('--http-host', help='Host .local que resuelve a 127.0.0.1 para la regresión HTTP opcional')
    parser.add_argument('--keep-on-failure', action='store_true', help='Conserva el entorno privado si falla una prueba para diagnosticarla')
    parser.add_argument('--elementor-path', type=Path, help='Copia local de Elementor para validar la limpieza real de CSS sin modificarla')
    arguments = parser.parse_args()
    source = arguments.wp_path.resolve()

    if not (source / 'wp-load.php').is_file():
        parser.error('--wp-path debe contener una instalación de WordPress')
    if arguments.elementor_path and not (arguments.elementor_path / 'elementor.php').is_file():
        parser.error('--elementor-path debe contener una copia completa de Elementor con elementor.php')

    project = Path(__file__).resolve().parents[2]
    print(run(['php', str(project / 'tests/migration/bootstrap-contract.php')]))
    for helper in ('import-bootstrap.php', 'finalize.php', 'verify.php', 'flush-cache.php'):
        assert run(['php', str(project / 'modules/migration' / helper)]) == '', 'Acceso directo sin WordPress ni WP_CLI no ejecuta operaciones'
    temporary = '/tmp/opencode' if os.access('/tmp/opencode', os.W_OK) else tempfile.gettempdir()
    fixture = Path(tempfile.mkdtemp(prefix='dct-migration-test-', dir=temporary))
    prefix = 'dcttest_' + secrets.token_hex(6) + '_'
    wp_root = fixture / 'wordpress'
    wp_root.mkdir()
    ready = []
    completed = False

    def wp(*args, expected=0, stdin=None):
        return run(['wp', f'--path={wp_root}', *args], expected, stdin)

    try:
        for name in ('wp-admin', 'wp-includes'):
            shutil.copytree(source / name, wp_root / name)
        for file in source.glob('*.php'):
            if file.name != 'wp-config.php':
                shutil.copy2(file, wp_root / file.name)
        content = wp_root / 'wp-content'
        plugin = content / 'plugins' / 'dctwphero'
        shutil.copytree(project, plugin, ignore=shutil.ignore_patterns('.ai-rules', 'release', '__pycache__'))
        (content / 'themes').mkdir()
        (content / 'uploads').mkdir()
        run(['wp', f'--path={source}', 'eval-file', str(project / 'tests/migration/bootstrap.php'), str(wp_root), prefix])
        ready.append(wp_root)
        wp('core', 'install', '--url=http://origin.test', '--title=Migration test', '--admin_user=testadmin',
           '--admin_email=test@example.test', '--admin_password=' + secrets.token_hex(20), '--skip-email')
        wp('plugin', 'activate', 'dctwphero')
        for script in ('migration/values.php', 'migration/libraries.php', 'migration/database-contract.php',
                       'migration/cache.php', 'modules.php', 'login/settings.php', 'login/store.php',
                       'login/store-contract.php', 'login/migrations.php'):
            print(wp('eval-file', str(project / 'tests' / script)))
        wp('help', 'dctwphero', 'export', '--format=json')
        sql_literal = b'\x00\xff\'"\\`; DROP TABLE users; -- %s %i\n'
        wp('eval', 'global $wpdb; $wpdb->query("CREATE TABLE `{$wpdb->prefix}fixture` '
           '(id int PRIMARY KEY, value longblob, amount decimal(20,4) DEFAULT 0, nullable text NULL, '
           'large_number bigint unsigned DEFAULT 18446744073709551615, computed int AS (id + 1) STORED) ENGINE=InnoDB"); '
           '$row = array(); $row["id"] = 1; $row["value"] = "\\0\\xff"; $wpdb->insert($wpdb->prefix . "fixture", $row); '
           '$row["id"] = 2; $row["value"] = hex2bin("' + sql_literal.hex() + '"); $wpdb->insert($wpdb->prefix . "fixture", $row); '
           '$value = array(); $value["url"] = "http://origin.test/image"; $value["path"] = ABSPATH . "wp-content"; '
           '$value["media"] = content_url("/uploads/image.jpg"); '
           'update_option("migration_fixture", $value); '
           '$wpdb->query("CREATE TABLE `{$wpdb->prefix}preserved` (id int) ENGINE=InnoDB"); '
           '$wpdb->query("INSERT INTO `{$wpdb->prefix}preserved` VALUES (7)");')
        (content / 'uploads' / 'fixture.txt').write_text('original')
        os.chmod(content / 'uploads/fixture.txt', 0o750)
        os.utime(content / 'uploads/fixture.txt', (1700000000, 1700000000))
        example_plugin = content / 'plugins/fixture-plugin/fixture-plugin.php'
        example_plugin.parent.mkdir()
        example_plugin.write_text('<?php /* Plugin Name: Fixture */ add_filter("dct_fixture_loaded", "__return_true");')
        wp('plugin', 'activate', 'fixture-plugin')
        theme = content / 'themes/fixture-theme'
        theme.mkdir()
        (theme / 'style.css').write_text('/* Theme Name: Fixture Theme */')
        (theme / 'index.php').write_text('<?php // Test theme.')
        wp('theme', 'activate', 'fixture-theme')
        mu = content / 'mu-plugins'
        mu.mkdir()
        (mu / 'fixture.php').write_text('<?php add_filter("dct_mu_fixture_loaded", "__return_true");')
        large = content / 'uploads' / 'large.bin'
        with large.open('wb') as output:
            for chunk in range(6):
                output.write(os.urandom(1024 * 1024))
        large_hash = hashlib.sha256(large.read_bytes()).hexdigest()
        (content / 'uploads' / 'private').mkdir()
        (content / 'uploads' / 'private' / 'keep.txt').write_text('private original')
        archive = fixture / 'backup.zip'
        create_foreign_keys(wp)
        wp('dctwphero', 'export', f'--file={archive}', '--exclude=cache,uploads/private', f'--exclude-tables={prefix}preserved')
        assert archive.stat().st_mode & 0o077 == 0, 'ZIP con permisos privados'
        with zipfile.ZipFile(archive) as package:
            manifest = json.loads(package.read('manifest.json'))
            assert manifest['format'] == 'dctwphero'
            assert not any(name.startswith('content/plugins/dctwphero/') for name in package.namelist())
        wp('dctwphero', 'export', f'--file={archive}', expected=1)
        wp('dctwphero', 'export', f'--file={content}/bad.zip', expected=1)
        wp('dctwphero', 'import', f'--file={archive}', expected=1)
        argument_scenarios(wp, archive, fixture, content)
        package_scenarios(wp, archive, fixture)
        database_failures(wp, archive, fixture, content)
        foreign_key_failures(wp, archive, fixture, content, prefix)
        operation_guard_scenarios(wp, wp_root, archive, fixture)
        legacy = fixture / 'legacy.zip'

        def legacy_metadata(manifest, files):
            for metadata in manifest['entries'].values():
                metadata.pop('mode', None)
                metadata.pop('mtime', None)

        rewrite_package(archive, legacy, legacy_metadata)
        wp('dctwphero', 'import', f'--file={legacy}', '--mode=merge', '--yes')
        verify_foreign_keys(wp)
        assert (content / 'uploads/fixture.txt').stat().st_mode & 0o777 == 0o644
        (content / 'uploads' / 'fixture.txt').write_text('changed')
        (content / 'uploads' / 'extra.txt').write_text('extra')
        (content / 'uploads' / 'private' / 'keep.txt').write_text('private destination')
        wp('eval', 'global $wpdb; $wpdb->query("CREATE TABLE `{$wpdb->prefix}extra` (id int) ENGINE=InnoDB"); '
           '$wpdb->query("UPDATE `{$wpdb->prefix}preserved` SET id = 9");')
        lock_code = ('global $wpdb; $key = "dct-migration-" . substr(hash("sha256", DB_NAME . ":" . $wpdb->prefix), 0, 40); '
                     'echo $wpdb->get_var($wpdb->prepare("SELECT GET_LOCK(%s, 0)", $key)) . "\\n"; flush(); sleep(20);')
        locker = subprocess.Popen(['wp', f'--path={wp_root}', 'eval', lock_code], stdout=subprocess.PIPE, stderr=subprocess.DEVNULL, text=True)
        try:
            assert locker.stdout.readline().strip() == '1'
            wp('dctwphero', 'export', f'--file={fixture}/locked.zip', expected=1)
        finally:
            locker.terminate()
            locker.wait(timeout=10)
        before = wp('option', 'get', 'migration_fixture', '--format=json')
        wp('dctwphero', 'import', f'--file={archive}', '--mode=mirror', '--dry-run')
        assert (content / 'uploads' / 'fixture.txt').read_text() == 'changed'
        assert (content / 'uploads' / 'extra.txt').exists()
        assert wp('option', 'get', 'migration_fixture', '--format=json') == before
        wp('dctwphero', 'import', f'--file={archive}', '--mode=merge', expected=1, stdin='n\n')
        assert not (wp_root / '.maintenance').exists()
        wp('dctwphero', 'import', f'--file={archive}', '--mode=merge', '--yes', '--home-url=https://target.test/sub', '--site-url=https://target.test/sub/wp')
        assert (content / 'uploads' / 'fixture.txt').read_text() == 'original'
        assert (content / 'uploads/fixture.txt').stat().st_mode & 0o777 == 0o750
        assert int((content / 'uploads/fixture.txt').stat().st_mtime) == 1700000000
        assert (content / 'uploads' / 'extra.txt').exists()
        assert (content / 'uploads' / 'private' / 'keep.txt').read_text() == 'private destination'
        restored = json.loads(wp('option', 'get', 'migration_fixture', '--format=json').splitlines()[0])
        assert restored['url'] == 'https://target.test/sub/image'
        assert restored['media'] == 'https://target.test/sub/wp/wp-content/uploads/image.jpg'
        assert wp('option', 'get', 'siteurl').strip() == 'https://target.test/sub/wp'
        assert wp('user', 'get', 'testadmin', '--field=ID').strip() == '1'
        assert wp('eval', 'global $wpdb; echo bin2hex($wpdb->get_var("SELECT value FROM `{$wpdb->prefix}fixture` WHERE id = 1"));').strip() == '00ff'
        assert wp('eval', 'global $wpdb; echo bin2hex($wpdb->get_var("SELECT value FROM `{$wpdb->prefix}fixture` WHERE id = 2"));').strip() == sql_literal.hex(), 'SQL aparente y bytes arbitrarios se importan como datos, no como instrucciones'
        assert wp('eval', 'global $wpdb; $r = $wpdb->get_row("SELECT * FROM `{$wpdb->prefix}fixture` WHERE id = 1"); '
                  'echo $r->amount . ":" . $r->large_number . ":" . $r->computed . ":" . (int) is_null($r->nullable);').strip() == '0.0000:18446744073709551615:2:1'
        assert hashlib.sha256(large.read_bytes()).hexdigest() == large_hash
        assert prefix + 'extra' in wp('db', 'tables', '--all-tables-with-prefix')
        wp('dctwphero', 'import', f'--file={archive}', '--mode=mirror', '--yes')
        verify_foreign_keys(wp)
        assert not (content / 'uploads' / 'extra.txt').exists()
        assert prefix + 'extra' not in wp('db', 'tables', '--all-tables-with-prefix')
        assert wp('eval', 'global $wpdb; echo $wpdb->get_var("SELECT id FROM `{$wpdb->prefix}preserved`");').strip() == '9'
        assert (content / 'uploads' / 'private' / 'keep.txt').read_text() == 'private destination'
        assert not (wp_root / '.maintenance').exists()
        mirror_scenarios(wp, wp_root, archive, fixture)
        for name in ('traversal', 'duplicate', 'corrupt', 'symlink'):
            malicious = fixture / f'{name}.zip'
            if name == 'corrupt':
                with zipfile.ZipFile(archive) as original, zipfile.ZipFile(malicious, 'w') as modified:
                    for item in original.infolist():
                        data = b'tampered' if item.filename == 'content/uploads/fixture.txt' else original.read(item.filename)
                        modified.writestr(item, data)
                wp('dctwphero', 'import', f'--file={malicious}', '--mode=mirror', '--dry-run', expected=1)
                continue
            shutil.copy2(archive, malicious)
            with zipfile.ZipFile(malicious, 'a') as package:
                if name == 'traversal':
                    package.writestr('../escape', 'bad')
                elif name == 'duplicate':
                    with warnings.catch_warnings():
                        warnings.simplefilter('ignore', UserWarning)
                        package.writestr('manifest.json', '{}')
                else:
                    info = zipfile.ZipInfo('content/uploads/link')
                    info.create_system = 3
                    info.external_attr = 0o120777 << 16
                    package.writestr(info, '/etc/passwd')
            wp('dctwphero', 'import', f'--file={malicious}', '--mode=mirror', '--dry-run', expected=1)
        wp('eval', '$m = get_option("dctwphero_modules", array()); $m["migration"] = 0; update_option("dctwphero_modules", $m);')
        wp('dctwphero', 'export', f'--file={fixture}/disabled.zip', expected=1)
        bootstrap = wp_root / 'wp-content/plugins/dctwphero/modules/migration/import-bootstrap.php'
        wp('--skip-plugins', '--skip-themes', f'--require={bootstrap}', 'dctwphero', 'import', f'--file={archive}', '--mode=mirror', '--dry-run', expected=1)
        wp('eval', '$m = get_option("dctwphero_modules", array()); $m["migration"] = 1; update_option("dctwphero_modules", $m);')
        destination = fixture / 'wordpress-target'
        shutil.copytree(wp_root, destination)
        destination_prefix = 'dcttest_' + secrets.token_hex(6) + '_'
        run(['wp', f'--path={source}', 'eval-file', str(project / 'tests/migration/bootstrap.php'), str(destination), destination_prefix])
        ready.append(destination)
        wp_root = destination
        wp('core', 'install', '--url=https://different.test', '--title=Destination', '--admin_user=destination',
           '--admin_email=destination@example.test', '--admin_password=' + secrets.token_hex(20), '--skip-email')
        wp('plugin', 'activate', 'dctwphero')
        wp('eval', 'global $wpdb; $wpdb->query("CREATE TABLE `{$wpdb->prefix}preserved` (id int) ENGINE=InnoDB"); '
           '$wpdb->query("INSERT INTO `{$wpdb->prefix}preserved` VALUES (11)");')
        wp('dctwphero', 'import', f'--file={archive}', '--mode=mirror', '--yes')
        restored = json.loads(wp('option', 'get', 'migration_fixture', '--format=json').splitlines()[0])
        verify_foreign_keys(wp)
        assert restored['url'] == 'https://different.test/image'
        assert restored['path'] == str(destination) + '/wp-content'
        assert destination_prefix + 'fixture' in wp('db', 'tables', '--all-tables-with-prefix')
        assert wp('option', 'get', 'stylesheet').strip() == 'fixture-theme'
        assert wp('eval', 'echo (int) apply_filters("dct_fixture_loaded", false) . (int) apply_filters("dct_mu_fixture_loaded", false);').strip() == '11'
        assert 'administrator' in wp('user', 'get', 'testadmin', '--field=roles')
        assert wp('eval', 'global $wpdb; echo $wpdb->get_var("SELECT id FROM `{$wpdb->prefix}preserved`");').strip() == '11'
        # Un fallo posterior a publicar datos debe conservar mantenimiento y devolver error.
        failing = fixture / 'failing.zip'
        payload = b'<?php throw new RuntimeException("Fallo intencional de pruebas");'
        rewrite_package(archive, failing, lambda manifest, files: set_entry(manifest, files, 'content/mu-plugins/dct-fixture-failure.php', payload))
        output = wp('dctwphero', 'import', f'--file={failing}', '--mode=merge', '--yes', expected=1)
        assert 'mantenimiento' in output and (wp_root / '.maintenance').exists()
        (wp_root / 'wp-content/mu-plugins/dct-fixture-failure.php').unlink()
        (wp_root / '.maintenance').unlink()
        wp('dctwphero', 'import', f'--file={archive}', '--mode=mirror', '--yes')
        cache_scenarios(wp, wp_root, arguments.elementor_path, archive, fixture)
        if arguments.http_host:
            with socket.socket() as listener:
                listener.bind(('127.0.0.1', 0))
                port = listener.getsockname()[1]
            url = f'http://{arguments.http_host}:{port}'
            wp('option', 'update', 'home', url)
            wp('option', 'update', 'siteurl', url)
            with (fixture / 'server.log').open('w') as log:
                server = subprocess.Popen(['php', '-S', f'127.0.0.1:{port}', '-t', str(wp_root)], stdout=log, stderr=log)
                try:
                    for attempt in range(50):
                        try:
                            with socket.create_connection(('127.0.0.1', port), timeout=1):
                                break
                        except OSError:
                            time.sleep(0.1)
                    probe = wp_root / 'migration-probe.php'
                    probe.write_text('<?php require __DIR__ . "/wp-load.php"; echo class_exists("DCTWPHero_Migration_Command", false) ? "CLI_LOADED" : "CLI_NOT_LOADED";')
                    with urllib.request.urlopen(f'http://127.0.0.1:{port}/migration-probe.php') as response:
                        assert response.read().decode().endswith('CLI_NOT_LOADED')
                    for script in ('finalize.php', 'verify.php', 'flush-cache.php', 'import-bootstrap.php', 'class-command.php'):
                        with urllib.request.urlopen(f'http://127.0.0.1:{port}/wp-content/plugins/dctwphero/modules/migration/{script}') as response:
                            assert response.read() == b'', f'{script} no es un punto de entrada HTTP'
                    print(run(['python3', str(project / 'tests/login/integration.py'), '--wp-path', str(wp_root), '--url', url]))
                finally:
                    server.terminate()
                    server.wait(timeout=10)
        print('OK: ZIP privado, round-trip, dominios/rutas/prefijos diferentes, permisos de usuario, merge/mirror, exclusiones, dry-run, cancelación, paquetes inseguros y módulo desactivado.')
        completed = True
    finally:
        if not completed and arguments.keep_on_failure:
            print(f'Entorno privado conservado para diagnóstico: {fixture}')
        else:
            cleanup(project, fixture, ready)


def cleanup(project, fixture, installations):
    for installation in installations:
        result = subprocess.run(['wp', f'--path={installation}', '--skip-plugins', '--skip-themes',
                                 'eval-file', str(project / 'tests/migration/cleanup.php')], capture_output=True, text=True)
        if result.returncode:
            raise RuntimeError(f'Limpieza incompleta; se conserva {fixture}\n{result.stderr}')
    shutil.rmtree(fixture)


if __name__ == '__main__':
    main()
