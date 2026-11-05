"""Infraestructura compartida de pruebas; nunca restaura sobre el sitio de referencia."""

import http.cookiejar
import os
from pathlib import Path
import re
import shutil
import socket
import subprocess
import tempfile
import time
import urllib.error
import urllib.parse
import urllib.request
from contextlib import contextmanager
from types import SimpleNamespace
import secrets


def run(arguments, expected=0, stdin=None):
    result = subprocess.run(arguments, input=stdin, text=True, capture_output=True)
    if result.returncode != expected:
        raise RuntimeError(f"Fallo ({result.returncode}): {' '.join(arguments[:4])}\n{result.stdout}\n{result.stderr}")
    return result.stdout + result.stderr


def cleanup(project, fixture, installations):
    fixture = Path(fixture).resolve()
    if fixture.parent not in (Path('/tmp'), Path('/tmp/opencode')) or not re.fullmatch(r'dctwphero-test-[^/]+', fixture.name):
        raise RuntimeError('Se rechaza limpiar un directorio ajeno a estas pruebas.')
    for installation in installations:
        result = subprocess.run(['wp', f'--path={installation}', '--skip-plugins', '--skip-themes',
                                 'eval-file', str(project / 'tests/support/cleanup.php')], capture_output=True, text=True)
        if result.returncode:
            raise RuntimeError(f'Limpieza incompleta; se conserva {fixture}\n{result.stderr}')
    shutil.rmtree(fixture)


@contextmanager
def web_site(source, host, project, theme_fixture):
    """Crea sitio, servidor y sesiones propios y garantiza su limpieza al salir."""
    source = source.resolve()
    if not (source / 'wp-load.php').is_file():
        raise ValueError('--wp-path debe contener WordPress')
    if not host.endswith('.local') or socket.gethostbyname(host) != '127.0.0.1':
        raise ValueError('--http-host debe ser un host .local que resuelva a 127.0.0.1')
    temporary = '/tmp/opencode' if os.access('/tmp/opencode', os.W_OK) else '/tmp'
    fixture = Path(tempfile.mkdtemp(prefix='dctwphero-test-', dir=temporary))
    root = fixture / 'wordpress'
    root.mkdir()
    ready = []
    server = None

    def wp(*args, expected=0, stdin=None):
        return run(['wp', f'--path={root}', *args], expected, stdin).strip()

    try:
        for directory in ('wp-admin', 'wp-includes'):
            shutil.copytree(source / directory, root / directory)
        for file in source.glob('*.php'):
            if file.name != 'wp-config.php':
                shutil.copy2(file, root / file.name)
        content = root / 'wp-content'
        shutil.copytree(project, content / 'plugins/dctwphero', ignore=shutil.ignore_patterns('.git', '.ai-rules', 'release', '__pycache__'))
        theme = content / 'themes/web-fixture'
        theme.mkdir(parents=True)
        (theme / 'style.css').write_text('/* Theme Name: Web Fixture */')
        shutil.copy2(theme_fixture, theme / 'index.php')
        prefix = 'dcttest_' + secrets.token_hex(6) + '_'
        run(['wp', f'--path={source}', 'eval-file', str(project / 'tests/support/bootstrap.php'), str(root), prefix])
        ready.append(root)
        with socket.socket() as listener:
            listener.bind(('127.0.0.1', 0))
            port = listener.getsockname()[1]
        url = f'http://{host}:{port}'
        password = secrets.token_hex(20)
        wp('core', 'install', '--url=' + url, '--title=Plugin Test', '--admin_user=testadmin',
           '--admin_email=test@example.test', '--admin_password=' + password, '--skip-email')
        wp('plugin', 'activate', 'dctwphero')
        wp('theme', 'activate', 'web-fixture')
        wp('user', 'create', 'testsubscriber', 'subscriber@example.test', '--role=subscriber', '--user_pass=' + password)
        post = wp('post', 'create', '--post_status=publish', '--post_title=Fixture',
                  '--post_content=<img src="/rest.jpg">', '--porcelain')
        with (fixture / 'server.log').open('w') as log:
            server = subprocess.Popen(['php', '-S', f'127.0.0.1:{port}', '-t', str(root)], stdout=log, stderr=log)
            for attempt in range(50):
                try:
                    with socket.create_connection(('127.0.0.1', port), timeout=1):
                        break
                except OSError:
                    time.sleep(0.1)
            else:
                raise RuntimeError('El servidor de pruebas no ha arrancado.')
            anonymous, admin, subscriber = [urllib.request.build_opener(
                urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar())) for _ in range(3)]

            def request(path='/', data=None, opener=anonymous, method=None):
                encoded = urllib.parse.urlencode(data).encode() if data is not None else None
                req = urllib.request.Request(url + path, data=encoded, method=method)
                try:
                    with opener.open(req, timeout=20) as response:
                        return response.status, response.read().decode(), response.headers
                except urllib.error.HTTPError as error:
                    return error.code, error.read().decode(), error.headers

            for username, opener in (('testadmin', admin), ('testsubscriber', subscriber)):
                request('/wp-login.php', opener=opener)
                status, body, _ = request('/wp-login.php', data={'log': username, 'pwd': password, 'testcookie': '1'}, opener=opener)
                if status != 200 or 'id="loginform"' in body:
                    raise RuntimeError('No se ha podido autenticar la sesión de pruebas.')
            yield SimpleNamespace(wp=wp, request=request, root=root, theme=theme, post=post,
                                  url=url, admin=admin, subscriber=subscriber)
    finally:
        try:
            if server is not None:
                server.terminate()
                server.wait(timeout=10)
        finally:
            cleanup(project, fixture, ready)
