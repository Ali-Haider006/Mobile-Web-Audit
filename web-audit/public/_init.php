<?php
declare(strict_types=1);

/**
 * Shared front-end bootstrap: autoloader, session, auth gate, friendly errors.
 */

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
function wva_fail(string $heading, string $detail): never
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
