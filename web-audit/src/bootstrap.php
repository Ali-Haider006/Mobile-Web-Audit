<?php
declare(strict_types=1);

/**
 * Single entry point for every page and CLI script.
 */

if (!defined('WVA_ROOT')) {
    define('WVA_ROOT', dirname(__DIR__));
}

spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'Wva\\')) {
        return;
    }
    $relative = str_replace('\\', '/', substr($class, 4));
    $file     = WVA_ROOT . '/src/' . $relative . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

require_once WVA_ROOT . '/src/Config.php';

date_default_timezone_set((string) Wva\Config::get('app_timezone', 'UTC'));
mb_internal_encoding('UTF-8');
