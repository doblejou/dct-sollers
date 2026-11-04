"""Fuerza pérdida de bloqueo y verifica que no se publica ZIP ni tablas."""

import os
import zipfile
from pathlib import Path


def scenarios(wp, wp_root, archive, fixture):
    fault = Path(__file__).with_name('operation-loss.php')
    bootstrap = wp_root / 'wp-content/plugins/dctwphero/modules/migration/import-bootstrap.php'
    name = 'DCTWPHERO_TEST_LOSS_AT'
    original = os.environ.get(name)
    try:
        for phase in (2, 4):
            os.environ[name] = str(phase)
            output = fixture / f'lost-lock-{phase}.zip'
            result = wp(f'--require={fault}', 'dctwphero', 'export', f'--file={output}', expected=1)
            assert 'Se ha perdido la conexión SQL original o el bloqueo' in result
            if phase == 2:
                assert not output.exists(), 'No publica ZIP al perder el bloqueo durante el empaquetado'
            else:
                assert 'El ZIP ya se ha publicado y verificado' in result, 'Aclara si el fallo ocurre después de publicar'
                with zipfile.ZipFile(output) as package:
                    assert package.testzip() is None
        wp('option', 'update', 'migration_lock_guard', 'unchanged')
        before = wp('db', 'tables', '--all-tables')
        for phase in (2, 4):
            os.environ[name] = str(phase)
            result = wp('--skip-plugins', '--skip-themes', f'--require={bootstrap}', f'--require={fault}',
                        'dctwphero', 'import', f'--file={archive}', '--mode=merge', '--yes', expected=1)
            assert 'Se ha perdido la conexión SQL original o el bloqueo' in result
            assert wp('option', 'get', 'migration_lock_guard').strip() == 'unchanged', 'No publica las tablas preparadas'
            assert before == wp('db', 'tables', '--all-tables'), 'Limpia sus tablas temporales'
            maintenance = wp_root / '.maintenance'
            if phase == 2:
                assert not maintenance.exists(), 'Detecta pérdida antes de activar mantenimiento'
            else:
                assert maintenance.exists(), 'Conserva mantenimiento tras comenzar la fase destructiva'
                maintenance.unlink()
    finally:
        if original is None:
            os.environ.pop(name, None)
        else:
            os.environ[name] = original
    print('OK: pérdida real de GET_LOCK impide publicar ZIP y tablas; limpieza y mantenimiento según fase.')
