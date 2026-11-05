<?php

defined('ABSPATH') || exit;

final class DCTWPHero_Migration_Module
{
    ################################################
    #
    #  register
    #
    #  Carga y registra los dos comandos únicamente dentro de WP-CLI.
    #
    ################################################
    public static function register(): void
    {
        if (!defined('WP_CLI') || !WP_CLI)
        {
            return;
        }

        require_once __DIR__ . '/class-files.php';
        require_once __DIR__ . '/class-values.php';
        require_once __DIR__ . '/class-database.php';
        require_once __DIR__ . '/class-operation.php';
        require_once __DIR__ . '/class-process.php';
        require_once __DIR__ . '/class-package.php';
        require_once __DIR__ . '/class-import.php';
        require_once __DIR__ . '/class-command.php';
        WP_CLI::add_command('dctwphero export', DCTWPHero_Migration_Command::export(...));
        WP_CLI::add_command('dctwphero import', DCTWPHero_Migration_Command::import(...));
    }

}
