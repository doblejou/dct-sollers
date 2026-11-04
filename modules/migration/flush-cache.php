<?php

defined('ABSPATH') || exit;
defined('WP_CLI') && WP_CLI || exit;

require_once __DIR__ . '/class-cache.php';

DCTWPHero_Migration_Cache::flush();
