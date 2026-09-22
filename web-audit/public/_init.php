<?php
declare(strict_types=1);

/**
 * Shared front-end bootstrap: autoloader, session, auth gate, friendly errors.
 */

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

define('WVA_ROOT', dirname(__DIR__));
require_once WVA_ROOT . '/src/bootstrap.php';

use Wva\Auth;
use Wva\Database;
use Wva\Helpers;

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (!defined('WVA_PUBLIC_PAGE')) {
    Auth::require();
}

/** Render a fatal problem in a way that explains what to do about it. */
function wva_fail(string $heading, string $detail): void
{
    http_response_code(500);
    echo '<!doctype html><meta charset="utf-8"><title>Setup needed</title>';
    echo '<link rel="stylesheet" href="assets/app.css">';
    echo '<main><div class="card"><h1>' . Helpers::h($heading) . '</h1><p class="sub">'
        . nl2br(Helpers::h($detail)) . '</p></div></main>';
    exit;
}

/** Confirms the schema is installed before a page tries to query it. */
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

function wva_int(string $key, int $default = 0): int
{
    $value = $_GET[$key] ?? $_POST[$key] ?? null;
    return is_numeric($value) ? (int) $value : $default;
}

function wva_str(string $key, string $default = ''): string
{
    $value = $_GET[$key] ?? $_POST[$key] ?? null;
    return is_string($value) ? trim($value) : $default;
}

function wva_is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}
