#!/usr/bin/env python3
"""Prueba CDN en un WordPress desechable; el sitio de referencia solo aporta núcleo y credenciales."""

import argparse
import http.cookiejar
import json
import os
from pathlib import Path
import re
import secrets
import shutil
import socket
import subprocess
import sys
import tempfile
import time
import urllib.error
import urllib.parse
import urllib.request

# Reutiliza únicamente creación de configuración privada y limpieza ya acotadas.
sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'migration'))
from integration import run, cleanup


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--wp-path', required=True, type=Path)
    parser.add_argument('--http-host', default='wpdev4.local', help='Host .local que resuelva a 127.0.0.1')
    arguments = parser.parse_args()
    source = arguments.wp_path.resolve()
    project = Path(__file__).resolve().parents[2]
    if not (source / 'wp-load.php').is_file():
        parser.error('--wp-path debe contener WordPress')
    if not arguments.http_host.endswith('.local') or socket.gethostbyname(arguments.http_host) != '127.0.0.1':
        parser.error('--http-host debe ser un host .local que resuelva a 127.0.0.1')
    temporary = '/tmp/opencode' if os.access('/tmp/opencode', os.W_OK) else tempfile.gettempdir()
    fixture = Path(tempfile.mkdtemp(prefix='dct-migration-test-', dir=temporary))
    root = fixture / 'wordpress'
    root.mkdir()
    ready = []
    server = None

    def wp(*args):
        return run(['wp', f'--path={root}', *args]).strip()

    try:
        for directory in ('wp-admin', 'wp-includes'):
            shutil.copytree(source / directory, root / directory)
        for file in source.glob('*.php'):
            if file.name != 'wp-config.php':
                shutil.copy2(file, root / file.name)
        content = root / 'wp-content'
        shutil.copytree(project, content / 'plugins/dctwphero', ignore=shutil.ignore_patterns('.ai-rules', 'release', '__pycache__'))
        theme = content / 'themes/cdn-fixture'
        theme.mkdir(parents=True)
        (theme / 'style.css').write_text('/* Theme Name: CDN Fixture */')
        shutil.copy2(project / 'tests/cdn/fixture.php', theme / 'index.php')
        prefix = 'dcttest_' + secrets.token_hex(6) + '_'
        run(['wp', f'--path={source}', 'eval-file', str(project / 'tests/migration/bootstrap.php'), str(root), prefix])
        ready.append(root)
        with socket.socket() as listener:
            listener.bind(('127.0.0.1', 0))
            port = listener.getsockname()[1]
        url = f'http://{arguments.http_host}:{port}'
        password = secrets.token_hex(20)
        wp('core', 'install', '--url=' + url, '--title=CDN Test', '--admin_user=cdnadmin',
           '--admin_email=test@example.test', '--admin_password=' + password, '--skip-email')
        wp('plugin', 'activate', 'dctwphero')
        wp('theme', 'activate', 'cdn-fixture')
        for script in ('modules.php', 'cdn/settings.php', 'cdn/admin.php'):
            print(wp('eval-file', str(project / 'tests' / script)))
        wp('eval', '$s = DCTWPHero_CDN_Module::defaults(); $s["hostname"] = "cdn.example.test"; '
           '$s["exclusions"] = "/private/"; update_option("dctwphero_cdn_settings", $s);')
        post = wp('post', 'create', '--post_status=publish', '--post_title=CDN',
                  '--post_content=<img src="/rest.jpg">', '--porcelain')
        with (fixture / 'server.log').open('w') as log:
            server = subprocess.Popen(['php', '-S', f'127.0.0.1:{port}', '-t', str(root)], stdout=log, stderr=log)
            for attempt in range(50):
                try:
                    with socket.create_connection(('127.0.0.1', port), timeout=1):
                        break
                except OSError:
                    time.sleep(0.1)
            anonymous = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
            admin = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))

            def request(path='/', data=None, opener=anonymous, method=None):
                encoded = urllib.parse.urlencode(data).encode() if data is not None else None
                req = urllib.request.Request(url + path, data=encoded, method=method)
                try:
                    with opener.open(req, timeout=20) as response:
                        return response.status, response.read().decode(), response.headers
                except urllib.error.HTTPError as error:
                    return error.code, error.read().decode(), error.headers

            assert 'cdn.example.test' not in request()[1], 'Desactivado no reescribe'
            wp('eval', '$m = get_option("dctwphero_modules", array()); $m["cdn"] = 1; update_option("dctwphero_modules", $m);')
            status, body, headers = request()
            assert status == 200 and 'http://cdn.example.test/wp-content/uploads/cdn-fixture.jpg' in body
            assert '//cdn.example.test/first.jpg 1x' in body and '//cdn.example.test/font.woff2' in body
            assert 'https://external.example.test/external.jpg' in body and 'src="/private/excluded.jpg"' in body
            assert 'cdn.example.test' not in request('/?cdn_mode=json')[1], 'JSON arbitrario intacto'
            assert 'cdn.example.test' not in request('/?cdn_mode=download')[1], 'Descarga intacta'
            assert 'cdn.example.test' not in request('/', data={})[1], 'POST intacto'
            assert 'cdn.example.test' not in request('/?preview=true')[1], 'Previsualización intacta'
            assert 'cdn.example.test' not in request('/wp-login.php')[1], 'Login intacto'
            rest_path = '/?rest_route=/wp/v2/posts/' + post
            status, body, _ = request(rest_path)
            data = json.loads(body)
            assert status == 200 and '//cdn.example.test/rest.jpg' in data['content']['rendered']
            assert data['link'].startswith(url), 'REST no reescribe enlaces ajenos al contenido'
            assert request('/wp-admin/admin-post.php?action=dctwphero_cdn_check')[0] in (400, 403)
            assert request('/wp-admin/admin-post.php', data={'action': 'dctwphero_cdn_check'})[0] in (400, 403)
            request('/wp-login.php', opener=admin)
            status, _, _ = request('/wp-login.php', data={'log': 'cdnadmin', 'pwd': password, 'testcookie': '1'}, opener=admin)
            assert status == 200
            admin_path = '/wp-admin/options-general.php?page=dctwphero&tab=cdn'
            status, body, _ = request(admin_path, opener=admin)
            assert status == 200 and 'Access-Control-Allow-Origin' in body and '.woff2' in body
            assert 'cdn.example.test/wp-admin' not in body, 'Administración intacta'
            forms = re.findall(r'<form\b[^>]*>.*?</form>', body, re.S)
            settings_form = next(form for form in forms if 'dctwphero_cdn_settings[hostname]' in form)
            nonce = re.search(r'name="_wpnonce" value="([^"]+)"', settings_form).group(1)
            values = {'option_page': 'dctwphero_cdn', 'action': 'update', '_wpnonce': 'invalid',
                      '_wp_http_referer': admin_path, 'dctwphero_cdn_settings[hostname]': 'saved.example.test',
                      'dctwphero_cdn_settings[extensions]': '.jpg\n.woff\n.woff2',
                      'dctwphero_cdn_settings[exclusions]': '/private/', 'dctwphero_cdn_settings[relative]': '1'}
            assert request('/wp-admin/options.php', data=values, opener=admin)[0] == 403
            values['_wpnonce'] = nonce
            assert request('/wp-admin/options.php', data=values, opener=admin)[0] == 200
            assert wp('eval', 'echo DCTWPHero_CDN_Module::settings()["hostname"];') == 'saved.example.test'
            values['dctwphero_cdn_settings[hostname]'] = 'https://invalid.example.test/path'
            assert request('/wp-admin/options.php', data=values, opener=admin)[0] == 200
            assert wp('eval', 'echo DCTWPHero_CDN_Module::settings()["hostname"];') == 'saved.example.test'
            assert request('/wp-admin/admin-post.php', data={'action': 'dctwphero_cdn_check', '_wpnonce': 'invalid'}, opener=admin)[0] == 403
            # La comprobación autorizada usa dominio vacío: verifica el flujo sin salir a Internet.
            wp('eval', '$s = DCTWPHero_CDN_Module::settings(); $s["hostname"] = ""; update_option("dctwphero_cdn_settings", $s);')
            check_form = next(form for form in forms if 'dctwphero_cdn_check' in form)
            check_nonce = re.search(r'name="_wpnonce" value="([^"]+)"', check_form).group(1)
            status, checked, _ = request('/wp-admin/admin-post.php', data={'action': 'dctwphero_cdn_check', '_wpnonce': check_nonce}, opener=admin)
            assert status == 200 and 'Guarda primero un dominio CDN válido' in checked
            assert wp('eval', 'echo DCTWPHero_CDN_Module::settings()["hostname"];') == ''
            wp('eval', '$s = DCTWPHero_CDN_Module::settings(); $s["hostname"] = "saved.example.test"; update_option("dctwphero_cdn_settings", $s);')
            wp('user', 'create', 'cdnsubscriber', 'subscriber@example.test', '--role=subscriber', '--user_pass=' + password)
            subscriber = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
            request('/wp-login.php', opener=subscriber)
            request('/wp-login.php', data={'log': 'cdnsubscriber', 'pwd': password, 'testcookie': '1'}, opener=subscriber)
            assert request(admin_path, opener=subscriber)[0] == 403
            assert request('/wp-admin/options.php', data=values, opener=subscriber)[0] == 403
            assert request('/wp-admin/admin-post.php', data={'action': 'dctwphero_cdn_check'}, opener=subscriber)[0] == 403
            wp('eval', '$m = get_option("dctwphero_modules", array()); $m["cdn"] = 0; update_option("dctwphero_modules", $m);')
            assert 'saved.example.test' not in request()[1]
            assert 'El módulo CDN está desactivado' in request(admin_path, opener=admin)[1]
            assert 'cdn.example.test' not in request(rest_path)[1], 'REST sin módulo queda intacto'
            assert wp('eval', 'echo DCTWPHero_CDN_Module::settings()["hostname"];') == 'saved.example.test'
            # Regresión general sobre la misma instalación desechable.
            print(run(['python3', str(project / 'tests/login/integration.py'), '--wp-path', str(root), '--url', url]))
        print('OK: CDN HTML/REST, fuentes, exclusiones, contextos omitidos, permisos, nonce, persistencia y desactivación.')
    finally:
        if server is not None:
            server.terminate()
            server.wait(timeout=10)
        cleanup(project, fixture, ready)


if __name__ == '__main__':
    main()
