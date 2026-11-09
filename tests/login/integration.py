"""Pruebas locales HTTP y concurrentes; restaura ajustes, IP y usuarios al salir."""

import argparse
import concurrent.futures
import html
import http.cookiejar
import json
import pathlib
import re
import secrets
import socket
import subprocess
import urllib.error
import urllib.parse
import urllib.request


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--wp-path', required=True)
    parser.add_argument('--url', required=True)
    args = parser.parse_args()
    url = args.url.rstrip('/')
    target = urllib.parse.urlsplit(url)
    if target.scheme != 'http' or not target.hostname.endswith('.local'):
        parser.error('Solo se admiten sitios de prueba http://*.local')

    def wp(code):
        result = subprocess.run(
            ['wp', '--path=' + args.wp_path, 'eval', code],
            capture_output=True, text=True, check=True,
        )
        if result.stderr:
            raise RuntimeError(result.stderr)
        return result.stdout.strip()

    def check(condition, message):
        if not condition:
            raise AssertionError(message)
        print('OK:', message)

    def request(path, data=None, cookies='', headers=None):
        opener = urllib.request.build_opener(
            urllib.request.ProxyHandler({}),
            urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()),
        )
        request_headers = {'Cookie': cookies}
        request_headers.update(headers or {})
        req = urllib.request.Request(
            url + path,
            data=urllib.parse.urlencode(data).encode() if data is not None else None,
            headers=request_headers,
        )
        try:
            response = opener.open(req, timeout=30)
        except urllib.error.HTTPError as error:
            response = error
        return response.status, response.read().decode('utf-8')

    def nonce(body, name):
        match = re.search(r'name="' + name + r'"[^>]*value="([^"]+)"', body)
        check(match is not None, 'Nonce presente: ' + name)
        return html.unescape(match.group(1))

    with socket.create_connection((target.hostname, target.port or 80)) as connection:
        client_ip = connection.getsockname()[0]
    concurrent_ip = '192.0.2.241'
    fixture_ips = json.dumps([client_ip, concurrent_ip])
    fixture_expr = 'json_decode(' + json.dumps(fixture_ips) + ', true)'
    snapshot = wp(
        '$ips = ' + fixture_expr + '; global $wpdb; '
        '$rows = $wpdb->get_results($wpdb->prepare('
        '"SELECT * FROM %i WHERE ip IN (%s, %s)", DCTWPSollers_Store::table(), ...$ips), ARRAY_A); '
        'echo wp_json_encode(array("option" => get_option("dctwpsollers_settings", null), '
        '"modules" => get_option("dctwpsollers_modules", null), "rows" => $rows));'
    )
    user_ids = []
    try:
        wp('$m = array(); $m["login"] = 1; update_option("dctwpsollers_modules", $m);')
        wp('$s = array(); $s["threshold"]=3; $s["window"]=2; $s["duration"]=4; '
           'update_option("dctwpsollers_settings", $s); '
           'foreach (' + fixture_expr + ' as $ip) { DCTWPSollers_Store::unlock($ip); }')
        check(request('/wp-login.php')[0] == 200, 'Login accesible antes de los fallos')
        for attempt in range(3):
            status, _ = request('/wp-login.php', {
                'log': 'dctwpsollers_missing_' + secrets.token_hex(8),
                'pwd': secrets.token_hex(16),
            }, headers={'X-Forwarded-For': '198.51.100.40'})
            check(status == 200, f'Fallo {attempt + 1} procesado antes del bloqueo siguiente')
        for route in ('/wp-login.php', '/wp-login.php?action=lostpassword',
                      '/wp-login.php?action=register', '/wp-login.php?action=logout'):
            check(request(route)[0] == 403, 'Bloquea acceso a ' + route)
        check(request('/wp-login.php', {'log': 'any', 'pwd': 'any'})[0] == 403,
              'Bloquea también POST')
        count = wp('echo DCTWPSollers_Store::active_count();')
        check(int(count) >= 1, 'Bloqueo HTTP registrado con REMOTE_ADDR')
        wp('global $wpdb; $wpdb->query($wpdb->prepare('
           '"UPDATE %i SET blocked_until = %d WHERE ip = %s", '
           'DCTWPSollers_Store::table(), time()-1, ' + json.dumps(client_ip) + '));')
        check(request('/wp-login.php')[0] == 200, 'Recupera acceso tras expirar el bloqueo')
        wp('DCTWPSollers_Store::unlock(' + json.dumps(client_ip) + ');')
        check(request('/wp-login.php')[0] == 200, 'Recupera acceso al desbloquear')

        cookies = []
        for role in ('administrator', 'subscriber'):
            created = json.loads(wp(
                '$id = wp_create_user("dctsollers_test_' + secrets.token_hex(8) + '", wp_generate_password(40)); '
                'if (is_wp_error($id)) { throw new RuntimeException($id->get_error_message()); } '
                '$u = new WP_User($id); $u->set_role(' + json.dumps(role) + '); '
                'echo wp_json_encode(array("id"=>$id, "cookie"=>'
                'AUTH_COOKIE . "=" . wp_generate_auth_cookie($id, time()+600, "auth") . "; " . '
                'LOGGED_IN_COOKIE . "=" . wp_generate_auth_cookie($id, time()+600, "logged_in")));'
            ))
            user_ids.append(created['id'])
            cookies.append(created['cookie'])
        admin_cookie, subscriber_cookie = cookies
        admin_path = '/wp-admin/options-general.php?page=dctwpsollers'
        modules_path = admin_path + '&tab=modules'
        login_path = admin_path + '&tab=login'
        migration_path = admin_path + '&tab=migration'
        status, body = request(admin_path, cookies=admin_cookie)
        check(status == 200 and 'dctwpsollers_modules[login]' in body
              and 'dctwpsollers_settings[threshold]' not in body,
              'Primera pestaña muestra solo activación de módulos')
        check('nav-tab-active' in body and 'aria-current="page"' in body,
              'Navegación indica la pestaña activa')
        check('dctwpsollers_modules[migration]' in body and 'tab=migration' in body,
              'Migración tiene activación y pestaña informativa propia')
        status, migration_body = request(migration_path, cookies=admin_cookie)
        check(status == 200 and 'wp dctwpsollers export' in migration_body
              and '--mode=merge --dry-run' in migration_body and '--mode=mirror' in migration_body,
              'Pestaña de migración muestra ejemplos de exportación, validación e importación')
        usage = re.search(r'<section id="dctwpsollers-migration-usage"[^>]*>(.*?)</section>', migration_body, re.S)
        check(usage is not None and '<form' not in usage.group(1) and '<button' not in usage.group(1),
              'Pestaña informativa no contiene formularios ni botones de ejecución')
        check(request(migration_path, cookies=subscriber_cookie)[0] == 403,
              'Suscriptor no accede a los ejemplos de migración')
        previous_modules = wp('echo wp_json_encode(get_option("dctwpsollers_modules", array()));')
        try:
            wp('$m = get_option("dctwpsollers_modules", array()); $m["migration"] = 0; update_option("dctwpsollers_modules", $m);')
            status, disabled_examples = request(migration_path, cookies=admin_cookie)
            check(status == 200 and 'El módulo está desactivado' in disabled_examples
                  and 'wp dctwpsollers export' in disabled_examples,
                  'Ejemplos disponibles con aviso cuando migración está desactivada')
        finally:
            wp('update_option("dctwpsollers_modules", json_decode(' + json.dumps(previous_modules) + ', true));')
        version = wp('$headers = array(); $headers["version"] = "Version"; '
                     '$data = get_file_data(WP_PLUGIN_DIR . "/dctwpsollers/dctwpsollers.php", $headers); '
                     'echo $data["version"];')
        check('Versión ' + version in body, 'Ajustes muestra la versión instalada del plugin')
        check(request(admin_path, cookies=subscriber_cookie)[0] == 403,
              'Suscriptor no accede a la administración')
        check(request(login_path, cookies=subscriber_cookie)[0] == 403,
              'Suscriptor no accede a la pestaña de login')
        for invalid_tab in ('&tab=unknown', '&tab[]=login'):
            status, fallback = request(admin_path + invalid_tab, cookies=admin_cookie)
            check(status == 200 and 'dctwpsollers_modules[login]' in fallback
                  and 'dctwpsollers_settings[threshold]' not in fallback,
                  'Pestaña inválida vuelve a Módulos: ' + invalid_tab)
        check('dctwpsollers_modules[login]' in body, 'Administrador ve la sección de módulos')
        forms = re.findall(r'<form\b[^>]*>.*?</form>', body, re.S)
        modules_form = next(form for form in forms if 'dctwpsollers_modules[login]' in form)
        status, login_body = request(login_path, cookies=admin_cookie)
        check(status == 200 and 'dctwpsollers_settings[threshold]' in login_body
              and 'dctwpsollers_modules[login]' not in login_body,
              'Pestaña de login muestra solo sus ajustes y bloqueos')
        for page_query in ('&paged[]=1', '&paged=0', '&paged=-1', '&paged=999999', '&paged=invalid', '&paged=%5C1'):
            status, paged_body = request(login_path + page_query, cookies=admin_cookie)
            check(status == 200 and 'dctwpsollers_settings[threshold]' in paged_body,
                  'Paginación de solo lectura tolera entradas no válidas: ' + page_query)
        forms = re.findall(r'<form\b[^>]*>.*?</form>', login_body, re.S)
        settings_form = next(form for form in forms if 'dctwpsollers_settings[threshold]' in form)
        modules_nonce = nonce(modules_form, '_wpnonce')
        modules_post = {
            'option_page': 'dctwpsollers_modules', 'action': 'update', '_wpnonce': 'invalid',
            '_wp_http_referer': modules_path, 'dctwpsollers_modules[login]': '0',
            'dctwpsollers_modules[migration]': '1',
            'dctwpsollers_modules[cdn]': '0',
            'dctwpsollers_modules[frontend]': '0',
        }
        check(request('/wp-admin/options.php', modules_post, admin_cookie)[0] == 403,
              'Rechaza cambio de módulos sin nonce válido')
        modules_post['_wpnonce'] = modules_nonce
        check(request('/wp-admin/options.php', modules_post, subscriber_cookie)[0] == 403,
              'Suscriptor no cambia módulos')
        wp('$s = DCTWPSollers_Login_Settings::get(); $s["threshold"] = 1; '
           'DCTWPSollers_Store::record_failure(' + json.dumps(client_ip) + ', $s);')
        check(request('/wp-login.php')[0] == 403, 'Bloqueo activo antes de desactivar módulo')
        status, saved_body = request('/wp-admin/options.php', modules_post, admin_cookie)
        check(status == 200 and 'dctwpsollers_modules[login]' in saved_body
              and 'dctwpsollers_settings[threshold]' not in saved_body,
              'Desactiva módulo y permanece en su pestaña')
        status, disabled_body = request(login_path, cookies=admin_cookie)
        check(status == 200 and 'La protección de login está desactivada' in disabled_body
              and 'dctwpsollers_settings[threshold]' in disabled_body,
              'Pestaña de login disponible y con aviso al desactivar')
        check(wp('echo (int) DCTWPSollers::module_enabled("login");') == '0', 'Estado desactivado persistido')
        check(request('/wp-login.php')[0] == 200, 'Módulo desactivado permite entrar desde IP bloqueada')
        before = wp('global $wpdb; echo wp_json_encode($wpdb->get_row($wpdb->prepare('
                    '"SELECT * FROM %i WHERE ip = %s", DCTWPSollers_Store::table(), '
                    + json.dumps(client_ip) + '), ARRAY_A));')
        check(request('/wp-login.php', {'log': 'missing_disabled', 'pwd': 'wrong'})[0] == 200,
              'Login fallido no bloqueado con módulo desactivado')
        after = wp('global $wpdb; echo wp_json_encode($wpdb->get_row($wpdb->prepare('
                   '"SELECT * FROM %i WHERE ip = %s", DCTWPSollers_Store::table(), '
                   + json.dumps(client_ip) + '), ARRAY_A));')
        check(before == after, 'Módulo desactivado no registra nuevos fallos y conserva datos')
        modules_post['dctwpsollers_modules[login]'] = '1'
        check(request('/wp-admin/options.php', modules_post, admin_cookie)[0] == 200,
              'Reactiva módulo mediante POST autorizado')
        check(request('/wp-login.php')[0] == 403, 'Reactivación vuelve a aplicar el bloqueo conservado')
        wp('DCTWPSollers_Store::unlock(' + json.dumps(client_ip) + ');')
        settings_nonce = nonce(settings_form, '_wpnonce')
        settings_post = {
            'option_page': 'dctwpsollers', 'action': 'update', '_wpnonce': 'invalid',
            '_wp_http_referer': login_path,
            'dctwpsollers_settings[threshold]': '4',
            'dctwpsollers_settings[window]': '3',
            'dctwpsollers_settings[duration]': '5',
        }
        check(request('/wp-admin/options.php', settings_post, admin_cookie)[0] == 403,
              'Rechaza ajustes sin nonce válido')
        settings_post['_wpnonce'] = settings_nonce
        check(request('/wp-admin/options.php', settings_post, subscriber_cookie)[0] == 403,
              'Suscriptor no modifica ajustes')
        before = wp('echo wp_json_encode(DCTWPSollers_Login_Settings::get());')
        for invalid in ('0', '10001', '1.5', '1e2'):
            settings_post['dctwpsollers_settings[threshold]'] = invalid
            status, invalid_body = request('/wp-admin/options.php', settings_post, admin_cookie)
            check(status == 200 and 'Introduce números enteros' in invalid_body
                  and wp('echo wp_json_encode(DCTWPSollers_Login_Settings::get());') == before,
                  'Ajustes HTTP inválidos conservan configuración: ' + invalid)
        settings_post['dctwpsollers_settings[threshold]'] = '4'
        status, saved_body = request('/wp-admin/options.php', settings_post, admin_cookie)
        check(status == 200 and json.loads(wp('echo wp_json_encode(DCTWPSollers_Login_Settings::get());'))
               == {'threshold': 4, 'window': 3, 'duration': 5}, 'Guarda ajustes por HTTP')
        check('dctwpsollers_settings[threshold]' in saved_body
              and 'dctwpsollers_modules[login]' not in saved_body,
              'Guardar ajustes conserva la pestaña de login')
        wp('$s = DCTWPSollers_Login_Settings::get(); $s["threshold"]=1; '
           'DCTWPSollers_Store::record_failure(' + json.dumps(concurrent_ip) + ', $s);')
        _, body = request(login_path, cookies=admin_cookie)
        check(concurrent_ip in body, 'Lista la IP bloqueada')
        row = re.search(r'<tr>\s*<td>' + re.escape(concurrent_ip) + r'</td>.*?</tr>', body, re.S).group(0)
        unlock_nonce = nonce(row, '_wpnonce')
        action = {'action': 'dctwpsollers_unlock', 'ip': concurrent_ip, '_wpnonce': 'invalid'}
        check(request('/wp-admin/admin-post.php', action, admin_cookie)[0] == 403,
              'Rechaza desbloqueo sin nonce válido')
        action['_wpnonce'] = unlock_nonce
        check(request('/wp-admin/admin-post.php', action, subscriber_cookie)[0] == 403,
              'Rechaza desbloqueo sin permisos')
        check(request('/wp-admin/admin-post.php?action=dctwpsollers_unlock&ip=' + concurrent_ip,
                      cookies=admin_cookie)[0] == 403, 'Rechaza desbloqueo mediante GET')
        for invalid_ip in ('', 'invalid', '192.0.2.999'):
            invalid_action = dict(action, ip=invalid_ip)
            check(request('/wp-admin/admin-post.php', invalid_action, admin_cookie)[0] == 400,
                  'Rechaza IP de desbloqueo inválida: ' + repr(invalid_ip))
        status, unlocked_body = request('/wp-admin/admin-post.php', action, admin_cookie)
        check(status == 200 and 'IP desbloqueada e historial reiniciado.' in unlocked_body
              and 'dctwpsollers_settings[threshold]' in unlocked_body,
              'Desbloquea mediante POST y vuelve a la pestaña de login')
        check(wp('echo DCTWPSollers_Store::blocked_until(' + json.dumps(concurrent_ip) + ');') == '0',
              'Desbloqueo persistido')

        code = ('$s = DCTWPSollers_Login_Settings::get(); $s["threshold"]=6; '
                'if (!DCTWPSollers_Store::record_failure(' + json.dumps(concurrent_ip) +
                ', $s)) { throw new RuntimeException("Fallo concurrente"); }')
        with concurrent.futures.ThreadPoolExecutor(max_workers=6) as pool:
            list(pool.map(lambda _: wp(code), range(6)))
        check(int(wp('echo DCTWPSollers_Store::blocked_until(' + json.dumps(concurrent_ip) + ');')) > 0,
              'Seis fallos simultáneos activan el bloqueo sin perder incrementos')
        result = subprocess.run(
            ['wp', '--path=' + args.wp_path, 'eval-file', str(pathlib.Path(__file__).with_name('store.php').resolve())],
            text=True, capture_output=True, check=True,
        )
        print(result.stdout, end='')
        if result.stderr:
            raise RuntimeError(result.stderr)
    finally:
        wp('$snapshot = json_decode(' + json.dumps(snapshot) + ', true); '
           'global $wpdb; $ips = ' + fixture_expr + '; '
           '$wpdb->query($wpdb->prepare("DELETE FROM %i WHERE ip IN (%s, %s)", '
           'DCTWPSollers_Store::table(), ...$ips)); '
           'foreach ($snapshot["rows"] as $row) { $wpdb->insert(DCTWPSollers_Store::table(), $row); } '
           'if ($snapshot["option"] === null) { delete_option("dctwpsollers_settings"); } '
            'else { update_option("dctwpsollers_settings", $snapshot["option"]); } '
            'if ($snapshot["modules"] === null) { delete_option("dctwpsollers_modules"); } '
            'else { update_option("dctwpsollers_modules", $snapshot["modules"]); }')
        for user_id in user_ids:
            wp('require_once ABSPATH . "wp-admin/includes/user.php"; wp_delete_user(' + str(user_id) + ');')
        print('Restaurados ajustes, filas de prueba y usuarios temporales.')


if __name__ == '__main__':
    main()
