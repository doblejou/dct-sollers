<?php

defined('WP_UNINSTALL_PLUGIN') || exit;

global $wpdb;

wp_clear_scheduled_hook('dctwphero_cleanup');
delete_option('dctwphero_settings');
delete_option('dctwphero_modules');
delete_option('dctwphero_cdn_settings');
delete_option('dctwphero_frontend_settings');
delete_option('dctwphero_db_version');
$wpdb->query($wpdb->prepare('DROP TABLE IF EXISTS %i', $wpdb->prefix . 'dctwphero_ips'));
