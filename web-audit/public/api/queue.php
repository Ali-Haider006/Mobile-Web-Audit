<?php
declare(strict_types=1);

/**
 * Processes queued audits one at a time so a browser tab can drive a scan
 * without a cron job. Each call claims one URL, runs PageSpeed, stores the
 * result and returns progress.
 */

define('WVA_ROOT', dirname(__DIR__, 2));
require_once WVA_ROOT . '/src/bootstrap.php';

use Wva\Auth;
use Wva\AuditRunner;
use Wva\Helpers;
use Wva\Repo\Runs;

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (!Auth::isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Not signed in']);
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST only']);
    exit;
}
$token = (string) ($_POST['_csrf'] ?? '');
if ($token === '' || !hash_equals(Helpers::csrfToken(), $token)) {
    http_response_code(400);
    echo json_encode(['error' => 'Bad token']);
    exit;
}

// PSI calls take tens of seconds; holding the session lock would freeze every
// other tab in this browser.
session_write_close();

$runId = isset($_POST['run_id']) && is_numeric($_POST['run_id']) ? (int) $_POST['run_id'] : null;
$batch = isset($_POST['batch']) && is_numeric($_POST['batch']) ? max(1, min(5, (int) $_POST['batch'])) : 1;

@set_time_limit(300);
ignore_user_abort(true);

try {
    Runs::requeueStale(15);
    $processed = AuditRunner::processQueue($runId, $batch);
    $counts    = $runId !== null ? Runs::refresh($runId) : ['total' => 0, 'done' => 0, 'failed' => 0, 'remaining' => 0];
    $run       = $runId !== null ? Runs::find($runId) : null;

    echo json_encode([
        'processed' => array_map(static function (array $item): array {
            return [
                'url'   => $item['url'],
                'ok'    => $item['ok'],
                'score' => $item['score'],
                'task'  => $item['task'],
                'error' => $item['error'],
            ];
        }, $processed),
        'total'     => (int) ($counts['total'] ?? 0),
        'done'      => (int) ($counts['done'] ?? 0),
        'failed'    => (int) ($counts['failed'] ?? 0),
        'remaining' => (int) ($counts['remaining'] ?? 0),
        'status'    => $run['status'] ?? 'unknown',
    ], JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
