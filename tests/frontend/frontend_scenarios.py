"""Escenarios Frontend ejecutados dentro del entorno desechable de tests/cdn/integration.py."""

import json
import re
import shutil
from html.parser import HTMLParser


class Links(HTMLParser):
    def __init__(self, body):
        super().__init__()
        self.links = []
        self.feed(body)

    def handle_starttag(self, tag, attrs):
        if tag == 'link':
            self.links.append(dict(attrs))


def scenarios(wp, request, project, theme, admin, subscriber, post, url):
    shutil.copy2(project / 'tests/frontend/fixture.php', theme / 'index.php')
    path = '/?p=' + post
    admin_path = '/wp-admin/options-general.php?page=dctwphero&tab=frontend'
    body = request(path)[1]
    assert 'application/rsd+xml' in body and 'wp-emoji' in body and 'dashicons-css' in body
    assert any(link.get('rel') == 'shortlink' for link in Links(body).links)
    assert 'El módulo Frontend está desactivado' in request(admin_path, opener=admin)[1]
    wp('eval', '$m = get_option("dctwphero_modules", array()); $m["frontend"] = 1; update_option("dctwphero_modules", $m);')
    body = request(path)[1]
    assert 'application/rsd+xml' in body and 'wp-emoji' in body and 'dashicons-css' in body, 'Activar con valores iniciales no aplica limpieza'
    assert not any(link.get('rel') == 'preload' for link in Links(body).links)
    wp('eval', '$m = get_option("dctwphero_modules"); $m["frontend"] = 0; update_option("dctwphero_modules", $m);')
    wp('eval', '$s = DCTWPHero_Frontend_Settings::defaults(); '
       '$s["emojis"] = $s["rsd"] = $s["shortlinks"] = $s["dashicons"] = 1; '
       '$s["preconnect"] = "https://connections.example.test"; $s["dns_prefetch"] = "dns.example.test"; '
       '$s["fonts"] = home_url("/font.woff2?v=1&x=2"); $s["image"] = home_url("/hero.webp"); '
       'update_option("dctwphero_frontend_settings", $s);')
    assert 'application/rsd+xml' in request(path)[1], 'Configurar no activa el módulo'
    wp('eval', '$m = get_option("dctwphero_modules"); $m["frontend"] = 1; update_option("dctwphero_modules", $m);')
    body = request(path)[1]
    links = Links(body).links
    assert 'application/rsd+xml' not in body and 'wp-emoji' not in body and 'dashicons-css' not in body
    assert not any(link.get('rel') == 'shortlink' for link in links)
    assert 'shortlink' not in str(request(path)[2]).lower(), 'También retira cabecera shortlink'
    assert any(link.get('rel') == 'preconnect' and link.get('href') == 'https://connections.example.test' for link in links)
    assert any(link.get('rel') == 'dns-prefetch' and link.get('href') == '//dns.example.test' for link in links)
    fonts = [link for link in links if link.get('rel') == 'preload' and link.get('as') == 'font']
    assert len(fonts) == 1 and fonts[0]['href'] == url + '/font.woff2?v=1&x=2'
    assert fonts[0]['crossorigin'] == 'anonymous' and fonts[0]['type'] == 'font/woff2'
    assert not any(link.get('rel') == 'preload' and link.get('as') == 'image' for link in links)
    assert any(link.get('as') == 'image' and link.get('fetchpriority') == 'high' for link in Links(request()[1]).links)
    # Portada estática, página de entradas separada y paginación.
    front = wp('post', 'create', '--post_type=page', '--post_status=publish', '--post_title=Portada', '--porcelain')
    blog = wp('post', 'create', '--post_type=page', '--post_status=publish', '--post_title=Blog', '--porcelain')
    wp('option', 'update', 'show_on_front', 'page')
    wp('option', 'update', 'page_on_front', front)
    wp('option', 'update', 'page_for_posts', blog)
    assert any(link.get('as') == 'image' for link in Links(request()[1]).links)
    assert not any(link.get('as') == 'image' for link in Links(request('/?page_id=' + blog)[1]).links)
    assert not any(link.get('as') == 'image' for link in Links(request('/?paged=2')[1]).links)
    assert 'dashicons-css' in request(path, opener=admin)[1], 'Mantiene iconos para autenticados'
    draft = wp('post', 'create', '--post_status=draft', '--post_title=Preview', '--porcelain')
    preview = request('/?p=' + draft + '&preview=true', opener=admin)
    assert 'application/rsd+xml' in preview[1], 'Preview real de borrador intacta'
    assert 'application/rsd+xml' in request(path, data={})[1], 'POST intacto'
    for excluded in ('/wp-login.php', '/?feed=rss2', '/?rest_route=/wp/v2/posts/' + post):
        assert 'connections.example.test' not in request(excluded)[1]
    # CDN procesa tanto la precarga como la referencia real de la imagen.
    wp('eval', '$s = DCTWPHero_CDN_Module::defaults(); $s["hostname"] = "cdn.example.test"; '
       'update_option("dctwphero_cdn_settings", $s); '
       '$m = get_option("dctwphero_modules"); $m["cdn"] = 1; update_option("dctwphero_modules", $m);')
    body = request()[1]
    links = Links(body).links
    assert any(link.get('as') == 'font' and link['href'] == 'http://cdn.example.test/font.woff2?v=1&x=2' for link in links)
    assert any(link.get('as') == 'image' and link['href'] == 'http://cdn.example.test/hero.webp' for link in links)
    assert 'src="http://cdn.example.test/hero.webp"' in body
    # Permisos y nonce reales, con el módulo desactivado.
    wp('eval', '$m = get_option("dctwphero_modules"); $m["frontend"] = $m["cdn"] = 0; update_option("dctwphero_modules", $m);')
    body = request(admin_path, opener=admin)[1]
    nonce = re.search(r'name="_wpnonce" value="([^"]+)"', body).group(1)
    settings = json.loads(wp('eval', 'echo wp_json_encode(DCTWPHero_Frontend_Settings::get());'))
    values = {'option_page': 'dctwphero_frontend', 'action': 'update', '_wpnonce': 'invalid', '_wp_http_referer': admin_path}
    values.update({f'dctwphero_frontend_settings[{key}]': value for key, value in settings.items()})
    values['dctwphero_frontend_settings[preconnect]'] = 'https://saved.example.test'
    assert request('/wp-admin/options.php', data=values, opener=admin)[0] == 403
    values['_wpnonce'] = nonce
    assert request('/wp-admin/options.php', data=values, opener=admin)[0] == 200
    assert wp('eval', 'echo DCTWPHero_Frontend_Settings::get()["preconnect"];') == 'https://saved.example.test'
    values['dctwphero_frontend_settings[preconnect]'] = 'javascript:alert(1)'
    assert request('/wp-admin/options.php', data=values, opener=admin)[0] == 200
    assert wp('eval', 'echo DCTWPHero_Frontend_Settings::get()["preconnect"];') == 'https://saved.example.test'
    assert request(admin_path, opener=subscriber)[0] == 403
    assert request('/wp-admin/options.php', data=values, opener=subscriber)[0] == 403
    assert 'id="loginform"' in request('/wp-admin/options.php', data=values)[1], 'Anónimo redirigido al login'
    assert wp('eval', 'echo DCTWPHero_Frontend_Settings::get()["preconnect"];') == 'https://saved.example.test'
    body = request(path)[1]
    assert 'application/rsd+xml' in body and 'dashicons-css' in body and 'wp-emoji' in body
    assert 'connections.example.test' not in body and 'saved.example.test' not in body
    print('OK: Frontend HTTP, portada estática/blog, CDN, contextos, permisos, nonce y persistencia.')
