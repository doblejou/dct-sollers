<?php

defined('WP_UNINSTALL_PLUGIN') || exit;

global $wpdb;

wp_clear_scheduled_hook('dctwpsollers_cleanup');
delete_option('dctwpsollers_settings');
delete_option('dctwpsollers_modules');
delete_option('dctwpsollers_cdn_settings');
delete_option('dctwpsollers_db_version');
$wpdb->query($wpdb->prepare('DROP TABLE IF EXISTS %i', $wpdb->prefix . 'dctwpsollers_ips'));
