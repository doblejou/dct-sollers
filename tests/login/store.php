<?php
// Ejecutar con wp eval-file tests/login/store.php en un WordPress de pruebas.

require_once dirname(__DIR__) . '/checks.php';
$check = DCTWPHero_Checks::that(...);

global $wpdb;
$table = DCTWPHero_Store::table();
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
    $check(DCTWPHero_Store::record_failure($ip, $settings), 'Primer fallo registrado');
    DCTWPHero_Store::record_failure($ip, $settings);
    $check(DCTWPHero_Store::blocked_until($ip) === 0, 'No bloquea antes de X');
    DCTWPHero_Store::record_failure($ip, $settings);
    $until = DCTWPHero_Store::blocked_until($ip);
    $check($until >= time() + 239, 'Bloquea exactamente al alcanzar X');
    DCTWPHero_Store::record_failure($ip, $settings);
    $check(DCTWPHero_Store::blocked_until($ip) === $until, 'No prolonga el bloqueo');
    $check(DCTWPHero_Store::blocked_until($ipv6) === 0, 'Independencia entre IP');
    DCTWPHero_Store::unlock($ip);
    $check(DCTWPHero_Store::blocked_until($ip) === 0, 'Desbloqueo manual');
    DCTWPHero_Store::record_failure($ip, $settings);
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
    DCTWPHero_Store::record_failure($ip, $settings);
    $check(DCTWPHero_Store::blocked_until($ip) === 0, 'Ventana móvil descarta también el límite exacto y conserva fallos recientes');
    DCTWPHero_Store::record_failure($ip, $settings);
    $check(DCTWPHero_Store::blocked_until($ip) > time(), 'Ventana móvil conserva fallos recientes');

    $wpdb->query($wpdb->prepare('UPDATE %i SET blocked_until = %d WHERE ip = %s', $table, time() - 1, $ip));
    DCTWPHero_Store::record_failure($ip, $settings);
    $check(DCTWPHero_Store::blocked_until($ip) === 0, 'Expiración reinicia el periodo');
    DCTWPHero_Store::record_failure($ipv6, $settings);
    DCTWPHero_Store::record_failure($ipv6, $settings);
    DCTWPHero_Store::record_failure($ipv6, $settings);
    $check(DCTWPHero_Store::blocked_until($ipv6) > time(), 'Bloqueo IPv6');

    $_SERVER['REMOTE_ADDR'] = '2001:0db8:0000:0000:0000:0000:0000:0240';
    $_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.1';
    $check(DCTWPHero_Login_Module::client_ip() === $ipv6, 'Normaliza IPv6 e ignora cabecera falsa');
    $_SERVER['REMOTE_ADDR'] = 'invalid';
    $check(DCTWPHero_Login_Module::client_ip() === '', 'Rechaza IP inválida');

    foreach (array('192.0.2.1', '::1') as $address)
    {
        $_SERVER['REMOTE_ADDR'] = $address;
        $check(DCTWPHero_Login_Module::client_ip() === $address, 'Acepta IP válida del servidor');
    }

    foreach (array(null, array(), false, '', '256.0.0.1', '127.0.0.1:80', '2001:db8::1%eth0') as $address)
    {
        $_SERVER['REMOTE_ADDR'] = $address;
        $check(DCTWPHero_Login_Module::client_ip() === '', 'Rechaza tipos y formatos de IP no admitidos');
    }

    unset($_SERVER['REMOTE_ADDR']);
    $check(DCTWPHero_Login_Module::client_ip() === '', 'Cabeceras no suplen REMOTE_ADDR ausente');

    $invalid = array();
    $invalid['threshold'] = '-1';
    $check(DCTWPHero_Login_Admin::sanitize($invalid) === DCTWPHero_Login_Settings::get(), 'Rechaza ajustes inválidos');
    $check(DCTWPHero_Login_Admin::sanitize($settings) === $settings, 'Acepta ajustes válidos');

    $wpdb->query($wpdb->prepare('UPDATE %i SET blocked_until = 0, updated_at = %d WHERE ip = %s', $table, time() - 604801, $ip));
    DCTWPHero_Store::cleanup();
    $check(!$wpdb->get_var($wpdb->prepare('SELECT ip FROM %i WHERE ip = %s', $table, $ip)), 'Limpieza elimina datos caducados');
    $check(DCTWPHero_Store::blocked_until($ipv6) > time(), 'Limpieza conserva bloqueos activos');
    DCTWPHero_Checks::report();
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
