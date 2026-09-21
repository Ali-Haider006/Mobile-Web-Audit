<?php
declare(strict_types=1);

/**
 * Drains the audit queue. Run from cron:
 *   *5 * * * * php /path/to/web-audit/bin/worker.php --max=20
 *
 * Options:
 *   --max=N     stop after N URLs (default 25)
 *   --run=ID    only work on one scan run
 *   --sleep=S   seconds between URLs (default 1; be kind to the API quota)
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only\n");
}

define('WVA_ROOT', dirname(__DIR__));
require_once WVA_ROOT . '/src/bootstrap.php';

use Wva\AuditRunner;
use Wva\Repo\Runs;
use Wva\Settings;

$options = getopt('', ['max::', 'run::', 'sleep::', 'quiet']);
$max     = isset($options['max']) ? max(1, (int) $options['max']) : 25;
$runId   = isset($options['run']) ? (int) $options['run'] : null;
$pause   = isset($options['sleep']) ? max(0, (int) $options['sleep']) : 1;
$quiet   = isset($options['quiet']);

function wva_say(string $message, bool $quiet): void
{
    if (!$quiet) {
        fwrite(STDOUT, '[' . gmdate('H:i:s') . '] ' . $message . PHP_EOL);
    }
}

if (Settings::apiKey() === '') {
    wva_say('WARNING: no PageSpeed API key set - Google will rate-limit this hard.', $quiet);
}

Runs::requeueStale(15);

$done = 0;
for ($i = 0; $i < $max; $i++) {
    $processed = AuditRunner::processQueue($runId, 1);
    if ($processed === []) {
        wva_say('Queue empty.', $quiet);
        break;
    }
    foreach ($processed as $result) {
        $done++;
        wva_say(sprintf(
            '%s %s%s%s',
            $result['ok'] ? 'OK  ' : 'FAIL',
            $result['ok'] ? str_pad((string) $result['score'], 4) : '',
            $result['url'],
            $result['task'] ? '  [task ' . $result['task'] . ']' : ($result['error'] ? '  ' . $result['error'] : '')
        ), $quiet);
    }
    if ($pause > 0) {
        sleep($pause);
    }
}

wva_say('Finished: ' . $done . ' URL(s) audited.', $quiet);
exit(0);
