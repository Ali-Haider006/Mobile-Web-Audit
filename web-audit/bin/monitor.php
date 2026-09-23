<?php
declare(strict_types=1);

/**
 * The scheduled run. One command, meant for cron:
 *
 *   php bin/monitor.php
 *
 * Audits every active URL, then lets the rules decide: open a ClickUp task,
 * comment on the one already open, or note a recovery. Nothing here needs a
 * person watching.
 *
 * Options:
 *   --max=N    stop after N URLs this run (default: all of them)
 *   --sleep=S  seconds between audits (default 1)
 *   --dry-run  audit nothing; just list what would be audited
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only\n");
}

define('WVA_ROOT', dirname(__DIR__));
require_once WVA_ROOT . '/src/bootstrap.php';

use Wva\AuditRunner;
use Wva\Migrations;
use Wva\Repo\Pages;
use Wva\Repo\Runs;
use Wva\Settings;

$options = getopt('', ['max::', 'sleep::', 'dry-run', 'quiet']);
$quiet   = isset($options['quiet']);
$pause   = isset($options['sleep']) ? max(0, (int) $options['sleep']) : 1;

function say(string $message): void
{
    global $quiet;
    if (!$quiet) {
        fwrite(STDOUT, '[' . gmdate('H:i:s') . '] ' . $message . PHP_EOL);
    }
}

$pending = Migrations::pending();
if ($pending !== []) {
    fwrite(STDERR, "Database is out of date (" . implode(', ', $pending) . "). Run: php bin/install.php\n");
    exit(1);
}

if (Settings::apiKey() === '') {
    say('WARNING: no PageSpeed API key set - Google will rate-limit this hard.');
}

$pages = Pages::monitored();
if ($pages === []) {
    $dormant = count(Pages::dormant());
    say($dormant > 0
        ? 'No URLs are being audited. ' . $dormant . ' are switched off or belong to an archived site.'
        : 'No URLs are being monitored. Add some on the Monitor screen.');
    exit(0);
}

$max = isset($options['max']) ? max(1, (int) $options['max']) : count($pages);
$pages = array_slice($pages, 0, $max);

if (isset($options['dry-run'])) {
    say('Would audit ' . count($pages) . ' URL(s):');
    foreach ($pages as $page) {
        say('  ' . $page['url']);
    }
    exit(0);
}

// One run per site, so each shows up correctly on the scan screens.
$bySite = [];
foreach ($pages as $page) {
    $bySite[(int) $page['site_id']][] = $page;
}
$runIds = [];
foreach ($bySite as $siteId => $sitePages) {
    $runId = Runs::create((int) $siteId, $sitePages, 'scheduled');
    if ($runId > 0) {
        $runIds[] = $runId;
    }
}

say('Queued ' . count($pages) . ' URL(s) across ' . count($runIds) . ' run(s).');

$done = 0;
$failed = 0;
foreach ($runIds as $runId) {
    while (true) {
        $processed = AuditRunner::processQueue($runId, 1);
        if ($processed === []) {
            break;
        }
        foreach ($processed as $result) {
            $done++;
            if (!$result['ok']) {
                $failed++;
            }
            say(sprintf(
                '%s %-4s %s%s',
                $result['ok'] ? 'OK  ' : 'FAIL',
                $result['ok'] ? (string) $result['score'] : '',
                $result['url'],
                $result['task'] ? '  [' . $result['task'] . ']' : ''
            ));
        }
        if ($pause > 0) {
            sleep($pause);
        }
    }
}

say('Finished: ' . $done . ' audited, ' . $failed . ' failed.');
exit($failed > 0 && $done === $failed ? 1 : 0);
