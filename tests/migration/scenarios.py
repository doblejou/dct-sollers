"""Escenarios negativos reutilizables sobre el entorno desechable de integración."""

import hashlib
import json
import zipfile


def rewrite_package(source, destination, mutate):
    with zipfile.ZipFile(source) as original:
        manifest = json.loads(original.read('manifest.json'))
        files = {name: original.read(name) for name in original.namelist() if name != 'manifest.json'}
    mutate(manifest, files)
    with zipfile.ZipFile(destination, 'w') as output:
        for name, payload in files.items():
            output.writestr(name, payload)
        output.writestr('manifest.json', json.dumps(manifest))


def set_entry(manifest, files, name, payload):
    files[name] = payload
    manifest['entries'][name] = {'size': len(payload), 'sha256': hashlib.sha256(payload).hexdigest()}


def arguments(wp, archive, fixture, content):
    cases = [
        ('export', f'--file={fixture}/unknown.zip', '--unknown'),
        ('export', '--file=relative.zip'),
        ('export', f'--file={fixture}/missing/out.zip'),
        ('export', f'--file={fixture}/out.txt'),
        ('export', f'--file={fixture}/bad.zip', '--exclude=uploads,,cache'),
        ('export', f'--file={fixture}/bad.zip', '--exclude=../outside'),
        ('export', f'--file={fixture}/bad.zip', '--exclude-tables=missing_table'),
        ('import', f'--file={archive}', '--mode=invalid'),
        ('import', f'--file={archive}', '--mode=merge', '--yes=false'),
        ('import', f'--file={archive}', '--mode=merge', '--dry-run=false'),
        ('import', f'--file={fixture}/missing.zip', '--mode=merge'),
        ('import', f'--file={archive}', '--mode=merge', '--home-url=ftp://example.test'),
        ('import', f'--file={archive}', '--mode=merge', '--site-url=https://user:password@example.test'),
        ('import', f'--file={archive}', '--mode=merge', '--home-url=https://example.test?query=1'),
        ('import', f'--file={archive}', '--mode=merge', '--site-url=https://example.test/#fragment'),
    ]
    for case in cases:
        wp('dct-sollers', *case, expected=1)
    diagnostic = wp('dct-sollers', 'export', f'--file={fixture}/terminal-control.zip', '--exclude=uploads/\x1b[31m', expected=1)
    assert '\x1b' not in diagnostic and '\\x1B' in diagnostic, 'No imprime secuencias de control desde rutas rechazadas'
    assert not (fixture / 'terminal-control.zip').exists(), 'Rechazo previo a publicar el ZIP'
    config = content.parent / 'wp-config.php'
    original_config = config.read_text()
    for constant, option in (('WP_HOME', 'home-url'), ('WP_SITEURL', 'site-url')):
        config.write_text(original_config.replace("if (!defined('ABSPATH'))", f"define('{constant}', 'http://origin.test');\nif (!defined('ABSPATH'))"))
        try:
            wp('dct-sollers', 'import', f'--file={archive}', '--mode=merge', '--dry-run', f'--{option}=https://conflict.test', expected=1)
        finally:
            config.write_text(original_config)
    maintenance = content.parent / '.maintenance'
    maintenance.write_text('<?php $upgrading = time(); // existing fixture')
    try:
        wp('dct-sollers', 'import', f'--file={archive}', '--mode=merge', '--yes', expected=1)
        assert maintenance.read_text().endswith('// existing fixture')
    finally:
        maintenance.unlink()
    link = content / 'uploads' / 'blocked-link'
    link.symlink_to(fixture, target_is_directory=True)
    try:
        wp('dct-sollers', 'import', f'--file={archive}', '--mode=mirror', '--dry-run', expected=1)
    finally:
        link.unlink()
    print(f'OK: {len(cases)} casos de argumentos inválidos, mantenimiento previo y enlaces de destino.')


def packages(wp, archive, fixture):
    def change_version(manifest, files):
        manifest['version'] = 999

    def missing(manifest, files):
        del files['content/uploads/fixture.txt']

    def unexpected(manifest, files):
        files['content/unexpected'] = b'not in manifest'

    def case_collision(manifest, files):
        set_entry(manifest, files, 'content/uploads/FIXTURE.txt', b'collision')

    def conflict(manifest, files):
        set_entry(manifest, files, 'content/conflict', b'file')
        set_entry(manifest, files, 'content/conflict/child', b'file')

    def invalid_header(manifest, files):
        entry = manifest['tables']['users']
        lines = files[entry].splitlines()
        header = json.loads(lines[0])
        header['table'] = 'wrong_table'
        set_entry(manifest, files, entry, json.dumps(header).encode() + b'\n' + b'\n'.join(lines[1:]) + b'\n')

    for index, mutate in enumerate((change_version, missing, unexpected, case_collision, conflict, invalid_header)):
        target = fixture / f'contract-{index}.zip'
        rewrite_package(archive, target, mutate)
        wp('dct-sollers', 'import', f'--file={target}', '--mode=mirror', '--dry-run', expected=1)
    target = fixture / 'not-a-zip.zip'
    target.write_text('not a ZIP archive')
    wp('dct-sollers', 'import', f'--file={target}', '--mode=merge', '--dry-run', expected=1)
    print('OK: versiones, entradas ausentes/extra, colisiones de nombre/ruta, cabeceras y ZIP inválidos.')


def database_failures(wp, archive, fixture, content):
    definitions = [
        ('TABLE', 'unsupported', '(id int) ENGINE=MyISAM'),
        ('VIEW', 'unsupported_view', 'AS SELECT 1 AS id'),
        ('TABLE', 'unsupported_fk', '(id int, FOREIGN KEY(id) REFERENCES `{prefix}fixture`(id)) ENGINE=InnoDB'),
    ]
    for kind, name, definition in definitions:
        code = ('global $wpdb; $name = $wpdb->prefix . "' + name + '"; '
                '$sql = "CREATE ' + kind + ' `$name` ' + definition.replace('{prefix}', '{$wpdb->prefix}') + '"; '
                'echo $wpdb->query($sql) === false ? "denied:" . mysqli_errno($wpdb->dbh) : "created";')
        outcome = wp('eval', code).strip()
        if outcome in ('denied:1142', 'denied:1227'):
            print(f'SKIP: creación real de {kind} {name}: el usuario SQL no tiene ese permiso.')
            continue
        assert outcome == 'created', f'No se creó la condición de prueba: {outcome}'
        try:
            wp('dct-sollers', 'import', f'--file={archive}', '--mode=merge', '--dry-run', expected=1)
        finally:
            wp('eval', 'global $wpdb; $wpdb->query("DROP ' + kind + ' `{$wpdb->prefix}' + name + '`");')
    outcome = wp('eval', 'global $wpdb; $sql = "CREATE TRIGGER `{$wpdb->prefix}fixture_trigger` BEFORE INSERT ON `{$wpdb->prefix}fixture` FOR EACH ROW SET @dct_fixture = 1"; '
                 'echo $wpdb->query($sql) === false ? "denied:" . mysqli_errno($wpdb->dbh) : "created";').strip()
    if outcome in ('denied:1142', 'denied:1227'):
        print('SKIP: creación real de TRIGGER: el usuario SQL no tiene ese permiso.')
    else:
        assert outcome == 'created', f'No se creó el trigger de prueba: {outcome}'
        try:
            wp('dct-sollers', 'import', f'--file={archive}', '--mode=merge', '--dry-run', expected=1)
        finally:
            wp('eval', 'global $wpdb; $wpdb->query("DROP TRIGGER `{$wpdb->prefix}fixture_trigger`");')

    def overflow(manifest, files):
        import base64
        entry = manifest['tables']['fixture']
        lines = files[entry].splitlines()
        row = json.loads(lines[1])
        row[0] = base64.b64encode(b'2147483648').decode()
        set_entry(manifest, files, entry, lines[0] + b'\n' + json.dumps(row).encode() + b'\n')

    target = fixture / 'overflow.zip'
    rewrite_package(archive, target, overflow)
    before = wp('db', 'tables', '--all-tables')
    marker = (content / 'uploads/fixture.txt').read_bytes()
    result = wp('dct-sollers', 'import', f'--file={target}', '--mode=merge', '--yes', expected=1)
    assert 'mantenimiento' in result
    assert (content / 'uploads/fixture.txt').read_bytes() == marker
    assert wp('db', 'tables', '--all-tables') == before, 'No quedan tablas de preparación'
    (content.parent / '.maintenance').unlink()
    print('OK: restricciones SQL autorizadas y conversión fallida sin publicar tablas ni archivos; omisiones indicadas como SKIP.')
