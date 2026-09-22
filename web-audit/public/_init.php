<?php
declare(strict_types=1);

/**
 * Shared front-end bootstrap: autoloader, session, auth gate, friendly errors.
 */

/*
 * Including this file twice must not be fatal - it declares functions, and a
 * "Cannot redeclare" error is a blank 500 on a host that hides errors.
 */
if (defined('WVA_INIT_DONE')) {
    return;
}

/*
 * Version guard FIRST, in syntax every PHP parses. Without it, an older PHP
 * hits 8.0-only syntax in the files below and dies with a bare 500 that says
 * nothing. Keep this file free of 8.x-only syntax.
 */
if (PHP_VERSION_ID < 80000) {
    header('Content-Type: text/html; charset=utf-8');
    http_response_code(500);
    echo '<!doctype html><meta charset="utf-8"><title>PHP too old</title>'
        . '<body style="font:16px system-ui;max-width:40em;margin:60px auto;padding:0 20px">'
        . '<h1>This PHP is too old</h1><p>Mobile Web Audit needs <strong>PHP 8.0 or newer</strong>. '
        . 'This server is running <strong>' . PHP_VERSION . '</strong>.</p>'
        . '<p>On shared hosting the PHP version is a setting in the control panel '
        . '(often "PHP Version" or "Select PHP Version") — set it to 8.1 or newer and reload.</p>';
    exit;
}

/*
 * Two supported layouts:
 *   public/ holds the pages and src/ sits beside it   (development, VPS)
 *   everything in one folder                          (shared hosting, where
 *                                                      the web root is all you get)
 * Detect which one we are in rather than making the operator configure it.
 */
$wvaRoot = dirname(__DIR__);
if (!is_dir($wvaRoot . '/src') && is_dir(__DIR__ . '/src')) {
    $wvaRoot = __DIR__;
}
define('WVA_ROOT', $wvaRoot);
require_once WVA_ROOT . '/src/bootstrap.php';

use Wva\Auth;
use Wva\Config;
use Wva\Database;
use Wva\Helpers;

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

/*
 * Shared hosting hides PHP errors, so every fault looks like the same blank
 * 500. Turn an uncaught error into a readable page - with the detail when
 * 'debug' is on in config/local.php, and a short note when it is not.
 */
$wvaDebug = (bool) Config::get('debug', false);
if ($wvaDebug) {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
}

if (!function_exists('wva_error_page')) {
    function wva_error_page(string $summary, string $detail): void
    {
        static $rendered = false;
        if ($rendered) {
            return;             // an exception AND a shutdown fatal both fire
        }
        $rendered = true;

        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: text/html; charset=utf-8');
        }
        // Reading config must not be able to throw from inside the handler.
        try {
            $debug = (bool) Config::get('debug', false);
        } catch (Throwable $ignored) {
            $debug = false;
        }
        echo '<!doctype html><meta charset="utf-8"><title>Something broke</title>'
            . '<body style="font:15px/1.6 system-ui;max-width:52em;margin:50px auto;padding:0 20px">'
            . '<h1 style="font-family:Georgia,serif">Something broke</h1>'
            . '<p>' . htmlspecialchars($summary, ENT_QUOTES) . '</p>';
        if ($debug) {
            echo '<pre style="background:#f4f4f2;border:1px solid #ddd;padding:14px;'
                . 'white-space:pre-wrap;font-size:13px">' . htmlspecialchars($detail, ENT_QUOTES) . '</pre>';
        } else {
            echo '<p style="color:#555">Set <code>\'debug\' => true</code> in '
                . '<code>config/local.php</code> and reload to see the details.</p>';
        }
        echo '<p><a href="index.php">Back to the dashboard</a> · <a href="setup.php">Setup checks</a></p>';
    }
}

set_exception_handler(static function (Throwable $e): void {
    wva_error_page(
        'The page stopped with an unhandled error.',
        get_class($e) . ': ' . $e->getMessage() . "\n\n"
        . $e->getFile() . ':' . $e->getLine() . "\n\n" . $e->getTraceAsString()
    );
});

register_shutdown_function(static function (): void {
    $fatal = error_get_last();
    if ($fatal === null || !in_array($fatal['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        return;
    }
    wva_error_page(
        'The page stopped with a fatal PHP error.',
        $fatal['message'] . "\n\n" . $fatal['file'] . ':' . $fatal['line']
    );
});

define('WVA_INIT_DONE', true);

if (!defined('WVA_PUBLIC_PAGE')) {
    Auth::require();
}

/** Render a fatal problem in a way that explains what to do about it. */
if (!function_exists('wva_fail')) {
    function wva_fail(string $heading, string $detail): void
    {
        http_response_code(500);
        echo '<!doctype html><meta charset="utf-8"><title>Setup needed</title>';
        echo '<link rel="stylesheet" href="assets/app.css">';
        echo '<main><div class="card"><h1>' . Helpers::h($heading) . '</h1><p class="sub">'
            . nl2br(Helpers::h($detail)) . '</p></div></main>';
        exit;
    }
}

/** Confirms the schema is installed before a page tries to query it. */
if (!function_exists('wva_require_schema')) {
    function wva_require_schema(): void
    {
        static $checked = false;
        if ($checked) {
            return;
        }
        try {
            Database::value('SELECT 1 FROM settings LIMIT 1');
        } catch (Throwable $e) {
            wva_fail(
                'Database not ready',
                "Could not query the database.\n\n" . $e->getMessage()
                . "\n\nCreate the database, load db/schema.sql, and set DB_* in .env."
            );
        }
        $checked = true;
    }
}

if (!function_exists('wva_int')) {
    function wva_int(string $key, int $default = 0): int
    {
        $value = $_GET[$key] ?? $_POST[$key] ?? null;
        return is_numeric($value) ? (int) $value : $default;
    }
}

if (!function_exists('wva_str')) {
    function wva_str(string $key, string $default = ''): string
    {
        $value = $_GET[$key] ?? $_POST[$key] ?? null;
        return is_string($value) ? trim($value) : $default;
    }
}

if (!function_exists('wva_is_post')) {
    function wva_is_post(): bool
    {
        return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
    }
}
