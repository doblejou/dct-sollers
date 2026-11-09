<?php
// Ejecutar con wp eval-file tests/login/store.php en un WordPress de pruebas.

require_once dirname(__DIR__) . '/checks.php';
$check = DCTWPSollers_Checks::that(...);

global $wpdb;
$table = DCTWPSollers_Store::table();
$ip = '192.0.2.240';
$ipv6 = '2001:db8::240';
$saved = $wpdb->get_results($wpdb->prepare('SELECT * FROM %i WHERE ip IN (%s, %s)', $table, $ip, $ipv6), ARRAY_A);
$server = $_SERVER;
$settings = array();
$settings['threshold'] = 3;
$settings['window'] = 2;
$settings['duration'] = 4;

try
{
    $wpdb->query($wpdb->prepare('DELETE FROM %i WHERE ip IN (%s, %s)', $table, $ip, $ipv6));
    $check(DCTWPSollers_Store::record_failure($ip, $settings), 'Primer fallo registrado');
    DCTWPSollers_Store::record_failure($ip, $settings);
    $check(DCTWPSollers_Store::blocked_until($ip) === 0, 'No bloquea antes de X');
    DCTWPSollers_Store::record_failure($ip, $settings);
    $until = DCTWPSollers_Store::blocked_until($ip);
    $check($until >= time() + 239, 'Bloquea exactamente al alcanzar X');
    DCTWPSollers_Store::record_failure($ip, $settings);
    $check(DCTWPSollers_Store::blocked_until($ip) === $until, 'No prolonga el bloqueo');
    $check(DCTWPSollers_Store::blocked_until($ipv6) === 0, 'Independencia entre IP');
    DCTWPSollers_Store::unlock($ip);
    $check(DCTWPSollers_Store::blocked_until($ip) === 0, 'Desbloqueo manual');
    DCTWPSollers_Store::record_failure($ip, $settings);
    $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE ip = %s', $table, $ip));
    $check(count(json_decode($row->failures, true)) === 1, 'Desbloqueo reinicia fallos');

    $timestamps = array
    (
        time() - 120,
        time() - 60,

    );
    $wpdb->query($wpdb->prepare(
        'UPDATE %i SET failures = %s, blocked_until = 0 WHERE ip = %s',
        $table, wp_json_encode($timestamps), $ip
    ));
    DCTWPSollers_Store::record_failure($ip, $settings);
    $check(DCTWPSollers_Store::blocked_until($ip) === 0, 'Ventana móvil descarta también el límite exacto y conserva fallos recientes');
    DCTWPSollers_Store::record_failure($ip, $settings);
    $check(DCTWPSollers_Store::blocked_until($ip) > time(), 'Ventana móvil conserva fallos recientes');

    $wpdb->query($wpdb->prepare('UPDATE %i SET blocked_until = %d WHERE ip = %s', $table, time() - 1, $ip));
    DCTWPSollers_Store::record_failure($ip, $settings);
    $check(DCTWPSollers_Store::blocked_until($ip) === 0, 'Expiración reinicia el periodo');
    DCTWPSollers_Store::record_failure($ipv6, $settings);
    DCTWPSollers_Store::record_failure($ipv6, $settings);
    DCTWPSollers_Store::record_failure($ipv6, $settings);
    $check(DCTWPSollers_Store::blocked_until($ipv6) > time(), 'Bloqueo IPv6');

    $_SERVER['REMOTE_ADDR'] = '2001:0db8:0000:0000:0000:0000:0000:0240';
    $_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.1';
    $check(DCTWPSollers::client_ip() === $ipv6, 'Normaliza IPv6 e ignora cabecera falsa');
    $_SERVER['REMOTE_ADDR'] = 'invalid';
    $check(DCTWPSollers::client_ip() === '', 'Rechaza IP inválida');

    foreach (array('192.0.2.1', '::1') as $address)
    {
        $_SERVER['REMOTE_ADDR'] = $address;
        $check(DCTWPSollers::client_ip() === $address, 'Acepta IP válida del servidor');
    }

    foreach (array(null, array(), false, '', '256.0.0.1', '127.0.0.1:80', '2001:db8::1%eth0') as $address)
    {
        $_SERVER['REMOTE_ADDR'] = $address;
        $check(DCTWPSollers::client_ip() === '', 'Rechaza tipos y formatos de IP no admitidos');
    }

    unset($_SERVER['REMOTE_ADDR']);
    $check(DCTWPSollers::client_ip() === '', 'Cabeceras no suplen REMOTE_ADDR ausente');

    $invalid = array();
    $invalid['threshold'] = '-1';
    $check(DCTWPSollers_Login_Admin::sanitize($invalid) === DCTWPSollers::settings(), 'Rechaza ajustes inválidos');
    $check(DCTWPSollers_Login_Admin::sanitize($settings) === $settings, 'Acepta ajustes válidos');

    $wpdb->query($wpdb->prepare('UPDATE %i SET blocked_until = 0, updated_at = %d WHERE ip = %s', $table, time() - 604801, $ip));
    DCTWPSollers_Store::cleanup();
    $check(!$wpdb->get_var($wpdb->prepare('SELECT ip FROM %i WHERE ip = %s', $table, $ip)), 'Limpieza elimina datos caducados');
    $check(DCTWPSollers_Store::blocked_until($ipv6) > time(), 'Limpieza conserva bloqueos activos');
    DCTWPSollers_Checks::report();
}
finally
{
    $_SERVER = $server;
    $wpdb->query($wpdb->prepare('DELETE FROM %i WHERE ip IN (%s, %s)', $table, $ip, $ipv6));

    foreach ($saved as $row)
    {
        $wpdb->insert($table, $row);
    }
}
