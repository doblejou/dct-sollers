#!/usr/bin/env python3
"""Prueba CDN en un WordPress desechable independiente de otras suites."""

import argparse
import json
from pathlib import Path
import re
import sys

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'support'))
from environment import web_site


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--wp-path', required=True, type=Path)
    parser.add_argument('--http-host', default='wpdev4.local', help='Host .local que resuelva a 127.0.0.1')
    arguments = parser.parse_args()
    project = Path(__file__).resolve().parents[2]
    with web_site(arguments.wp_path, arguments.http_host, project, project / 'tests/cdn/fixture.php') as site:
        wp, request, admin, subscriber, post = site.wp, site.request, site.admin, site.subscriber, site.post
        url = site.url
        print(wp('--skip-plugins=dct-sollers', 'eval-file', str(project / 'tests/cdn/rewriter.php')))
        for script in ('modules.php', 'admin-ui.php', 'cdn/settings.php', 'cdn/admin.php'):
            print(wp('eval-file', str(project / 'tests' / script)))
        wp('eval', '$s = DCTWPSollers_CDN_Settings::defaults(); $s["hostname"] = "cdn.example.test"; '
           '$s["exclusions"] = "/private/"; update_option("dctwpsollers_cdn_settings", $s);')
        assert 'cdn.example.test' not in request()[1], 'Desactivado no reescribe'
        wp('eval', '$m = get_option("dctwpsollers_modules", array()); $m["cdn"] = 1; update_option("dctwpsollers_modules", $m);')
        status, body, headers = request()
        assert status == 200 and 'http://cdn.example.test/wp-content/uploads/cdn-fixture.jpg' in body
        assert '//cdn.example.test/first.jpg 1x' in body and '//cdn.example.test/font.woff2' in body
        assert 'https://external.example.test/external.jpg' in body and 'src="/private/excluded.jpg"' in body
        assert 'cdn.example.test' not in request('/?cdn_mode=json')[1], 'JSON arbitrario intacto'
        assert 'cdn.example.test' not in request('/?cdn_mode=download')[1], 'Descarga intacta'
        assert 'cdn.example.test' not in request('/', data={})[1], 'POST intacto'
        assert 'cdn.example.test' not in request('/?preview=true')[1], 'Previsualización intacta'
        assert 'cdn.example.test' not in request('/wp-login.php')[1], 'Login intacto'
        assert 'dctwpsollers-admin-css' not in request()[1], 'Sin CSS administrativo en el sitio público'
        assert 'dctwpsollers-admin-css' not in request('/wp-login.php')[1], 'Sin CSS administrativo en login'
        rest_path = '/?rest_route=/wp/v2/posts/' + post
        status, body, _ = request(rest_path)
        data = json.loads(body)
        assert status == 200 and '//cdn.example.test/rest.jpg' in data['content']['rendered']
        assert data['link'].startswith(url), 'REST no reescribe enlaces ajenos al contenido'
        assert request('/wp-admin/admin-post.php?action=dctwpsollers_cdn_check')[0] in (400, 403)
        assert request('/wp-admin/admin-post.php', data={'action': 'dctwpsollers_cdn_check'})[0] in (400, 403)
        admin_path = '/wp-admin/options-general.php?page=dctwpsollers&tab=cdn'
        status, body, _ = request(admin_path, opener=admin)
        assert status == 200 and 'Access-Control-Allow-Origin' in body and '.woff2' in body
        assert 'cdn.example.test/wp-admin' not in body, 'Administración intacta'
        assert 'dctwpsollers-admin-css' in body, 'CSS propio cargado en la pantalla del plugin'
        assert 'dctwpsollers-admin-css' not in request('/wp-admin/index.php', opener=admin)[1], 'Sin CSS propio en otras pantallas'
        forms = re.findall(r'<form\b[^>]*>.*?</form>', body, re.S)
        settings_form = next(form for form in forms if 'dctwpsollers_cdn_settings[hostname]' in form)
        nonce = re.search(r'name="_wpnonce" value="([^"]+)"', settings_form).group(1)
        values = {'option_page': 'dctwpsollers_cdn', 'action': 'update', '_wpnonce': 'invalid',
                  '_wp_http_referer': admin_path, 'dctwpsollers_cdn_settings[hostname]': 'saved.example.test',
                  'dctwpsollers_cdn_settings[extensions]': '.jpg\n.woff\n.woff2',
                  'dctwpsollers_cdn_settings[exclusions]': '/private/', 'dctwpsollers_cdn_settings[relative]': '1'}
        assert request('/wp-admin/options.php', data=values, opener=admin)[0] == 403
        values['_wpnonce'] = nonce
        assert request('/wp-admin/options.php', data=values, opener=admin)[0] == 200
        assert wp('eval', 'echo DCTWPSollers_CDN_Settings::get()["hostname"];') == 'saved.example.test'
        values['dctwpsollers_cdn_settings[hostname]'] = 'https://invalid.example.test/path'
        assert request('/wp-admin/options.php', data=values, opener=admin)[0] == 200
        assert wp('eval', 'echo DCTWPSollers_CDN_Settings::get()["hostname"];') == 'saved.example.test'
        assert request('/wp-admin/admin-post.php', data={'action': 'dctwpsollers_cdn_check', '_wpnonce': 'invalid'}, opener=admin)[0] == 403
        # La comprobación autorizada usa dominio vacío: verifica el flujo sin salir a Internet.
        wp('eval', '$s = DCTWPSollers_CDN_Settings::get(); $s["hostname"] = ""; update_option("dctwpsollers_cdn_settings", $s);')
        check_form = next(form for form in forms if 'dctwpsollers_cdn_check' in form)
        check_nonce = re.search(r'name="_wpnonce" value="([^"]+)"', check_form).group(1)
        status, checked, _ = request('/wp-admin/admin-post.php', data={'action': 'dctwpsollers_cdn_check', '_wpnonce': check_nonce}, opener=admin)
        assert status == 200 and 'Guarda primero un dominio CDN válido' in checked
        assert wp('eval', 'echo DCTWPSollers_CDN_Settings::get()["hostname"];') == ''
        wp('eval', '$s = DCTWPSollers_CDN_Settings::get(); $s["hostname"] = "saved.example.test"; update_option("dctwpsollers_cdn_settings", $s);')
        assert request(admin_path, opener=subscriber)[0] == 403
        assert request('/wp-admin/options.php', data=values, opener=subscriber)[0] == 403
        assert request('/wp-admin/admin-post.php', data={'action': 'dctwpsollers_cdn_check'}, opener=subscriber)[0] == 403
        wp('eval', '$m = get_option("dctwpsollers_modules", array()); $m["cdn"] = 0; update_option("dctwpsollers_modules", $m);')
        assert 'saved.example.test' not in request()[1]
        assert 'El módulo CDN está desactivado' in request(admin_path, opener=admin)[1]
        assert 'cdn.example.test' not in request(rest_path)[1], 'REST sin módulo queda intacto'
        assert wp('eval', 'echo DCTWPSollers_CDN_Settings::get()["hostname"];') == 'saved.example.test'
        print('OK: CDN HTML/REST, fuentes, exclusiones, contextos omitidos, permisos, nonce, persistencia y desactivación.')


if __name__ == '__main__':
    main()
