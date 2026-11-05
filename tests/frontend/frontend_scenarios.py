"""Escenarios Frontend ejecutados por su runner independiente en un entorno desechable."""

import json
import re
from html.parser import HTMLParser
from urllib.parse import quote


class Links(HTMLParser):
    def __init__(self, body):
        super().__init__()
        self.links = []
        self.feed(body)

    def handle_starttag(self, tag, attrs):
        if tag == 'link':
            self.links.append(dict(attrs))


def scenarios(wp, request, admin, subscriber, post, url):
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
    wp('eval', '$s = DCTWPHero_CDN_Settings::defaults(); $s["hostname"] = "cdn.example.test"; '
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
    cleanup_scenarios(wp, request, admin, path, post, url)
    print('OK: Frontend HTTP, portada estática/blog, CDN, contextos, permisos, nonce y persistencia.')


def cleanup_scenarios(wp, request, admin, path, post, url):
    wp('post', 'update', post, '--comment_status=open')
    wp('option', 'update', 'show_on_front', 'posts')
    wp('eval', 'update_option("dctwphero_frontend_settings", DCTWPHero_Frontend_Settings::defaults()); '
       '$m = get_option("dctwphero_modules"); $m["frontend"] = 1; update_option("dctwphero_modules", $m);')
    category = wp('term', 'create', 'category', 'Frontend RSS', '--slug=frontend-rss', '--porcelain')
    tag = wp('term', 'create', 'post_tag', 'Frontend tag', '--slug=frontend-tag', '--porcelain')
    wp('post', 'term', 'set', post, 'category', category, '--by=id')
    wp('post', 'term', 'set', post, 'post_tag', tag, '--by=id')
    author = wp('post', 'get', post, '--field=post_author')

    def markers(body, headers):
        links = Links(body).links
        feeds = [link for link in links if link.get('type') in ('application/rss+xml', 'application/atom+xml')]
        comments = [link for link in feeds if 'comments' in link.get('href', '') or 'p=' in link.get('href', '')]
        return {
            'rest': any(link.get('rel') == 'https://api.w.org/' for link in links),
            'rest_header': 'https://api.w.org/' in str(headers),
            'oembed': any(link.get('type', '').startswith('application/json+oembed') or link.get('type', '').startswith('text/xml+oembed') for link in links),
            'generator': bool(re.search(r'<meta\s+name=[\'"]generator[\'"]', body)),
            'feed_links': len(feeds) > len(comments),
            'comments_feed_links': bool(comments),
        }

    _, body, headers = request(path)
    baseline = markers(body, headers)
    assert all(baseline.values()), ('Fixture debe anunciar todos los descubrimientos', baseline)
    new_flags = ('rest_links', 'oembed_links', 'generator', 'feed_links', 'comments_feed_links')
    rest_path = '/?rest_route=/wp/v2/posts/' + post
    oembed_path = '/?rest_route=/oembed/1.0/embed&url=' + quote(url + path, safe='')

    for key in new_flags:
        wp('eval', '$s = DCTWPHero_Frontend_Settings::defaults(); $s["' + key + '"] = 1; update_option("dctwphero_frontend_settings", $s);')
        _, body, headers = request(path)
        expected = baseline.copy()
        if key == 'rest_links':
            expected['rest'] = expected['rest_header'] = False
        elif key == 'oembed_links':
            expected['oembed'] = False
        else:
            expected[key] = False
        assert markers(body, headers) == expected, ('Cada opción actúa independientemente', key)
        assert request(rest_path)[0] == 200, 'La API REST sigue disponible'
        status, response, _ = request(oembed_path)
        assert status == 200 and 'html' in json.loads(response), 'oEmbed sigue disponible'
        for feed_path in ('/?feed=rss2', '/?feed=comments-rss2', '/?feed=rss2&p=' + post):
            status, response, _ = request(feed_path)
            assert status == 200 and '<rss' in response, ('Feeds accesibles directamente', key, feed_path)
            if key == 'generator':
                assert '<generator>' in response, 'No retira generator de los feeds'
        if key == 'feed_links':
            for archive in ('/?cat=' + category, '/?tag=frontend-tag', '/?author=' + author, '/?s=CDN'):
                status, archive_body, _ = request(archive)
                assert status == 200
                assert not any(link.get('type') == 'application/rss+xml' and 'comments' not in link.get('href', '') for link in Links(archive_body).links), archive
        # POST y previews no reciben la limpieza de metadatos.
        assert all(markers(request(path, data={})[1], {})[name] for name in ('rest', 'oembed', 'generator', 'feed_links', 'comments_feed_links'))

    wp('eval', '$s = DCTWPHero_Frontend_Settings::defaults(); '
       '$s["rest_links"] = $s["oembed_links"] = $s["generator"] = $s["feed_links"] = $s["comments_feed_links"] = 1; '
       'update_option("dctwphero_frontend_settings", $s);')
    _, body, headers = request(path)
    assert not any(markers(body, headers).values()), 'Opciones combinadas retiran solo descubrimientos seleccionados'
    admin_path = '/wp-admin/options-general.php?page=dctwphero&tab=frontend'
    body = request(admin_path, opener=admin)[1]
    assert body.count('Qué elimina') == body.count('Qué conserva') == body.count('Precaución') == 9
    assert 'no una medida de seguridad' in body and 'su ahorro de rendimiento es pequeño' in body
    for key in new_flags:
        control = re.search(r'<input\b[^>]*type="checkbox"[^>]*id="dctwphero-frontend-' + key + r'"[^>]*>', body).group(0)
        assert 'checked=' in control and f'aria-describedby="dctwphero-frontend-{key}-help"' in control
        assert f'id="dctwphero-frontend-{key}-help"' in body
    ids = re.findall(r'\bid="([^"]+)"', body)
    assert len(ids) == len(set(ids)), 'Identificadores de la pantalla únicos'
    draft = wp('post', 'create', '--post_status=draft', '--post_title=Cleanup preview', '--porcelain')
    preview = request('/?p=' + draft + '&preview=true', opener=admin)[1]
    assert all(markers(preview, {})[name] for name in ('rest', 'oembed', 'generator', 'feed_links', 'comments_feed_links'))
    wp('eval', '$m = get_option("dctwphero_modules"); $m["frontend"] = 0; update_option("dctwphero_modules", $m);')
    _, body, headers = request(path)
    assert markers(body, headers) == baseline, 'Desactivar restituye descubrimientos'
    assert wp('eval', 'echo DCTWPHero_Frontend_Settings::get()["rest_links"];') == '1', 'Desactivar conserva opciones'
    wp('eval', '$m = get_option("dctwphero_modules"); $m["frontend"] = 1; update_option("dctwphero_modules", $m);')
    _, body, headers = request(path)
    assert not any(markers(body, headers).values()), 'Reactivar recupera las opciones guardadas'
    print('OK: REST/oEmbed/generator/RSS independientes, servicios conservados, ayuda accesible y reversibilidad.')
