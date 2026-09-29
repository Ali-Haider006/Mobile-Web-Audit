<?php
declare(strict_types=1);

/**
 * The scheduled run, triggered by fetching a URL instead of by a shell.
 *
 * bin/monitor.php is the better way when there is a real cron. This exists
 * for the common case where you have FTP and a database and no control panel
 * at all - point any external scheduler at:
 *
 *   https://your-site/cron.php?key=<cron_key>
 *
 * Off unless 'cron_key' is set in config/local.php. The key is the only
 * protection, so make it long and random; the page is otherwise unauthenticated
 * because a scheduler cannot log in.
 *
 * A web request has a time limit that a shell does not, so this works to a
 * budget: it audits what it can, leaves the rest queued, and picks that queue
 * up on the next call. Calling it more often than the audit schedule is
 * therefore fine and is how a big list gets finished.
 */

define('WVA_PUBLIC_PAGE', true);
require __DIR__ . '/_init.php';

use Wva\AuditRunner;
use Wva\Config;
use Wva\Migrations;
use Wva\Repo\Pages;
use Wva\Repo\Runs;
use Wva\Settings;

header('Content-Type: text/plain; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow, noarchive');
header('Cache-Control: no-store');

$configured = trim((string) Config::get('cron_key', ''));
$supplied   = isset($_GET['key']) ? (string) $_GET['key'] : '';

/*
 * Same answer for "turned off" and "wrong key", so this cannot be used to
 * discover whether a key exists.
 */
if ($configured === '' || !hash_equals($configured, $supplied)) {
    http_response_code(404);
    exit("Not found\n");
}

$started = microtime(true);

/*
 * A scheduler hangs up as soon as it has sent the request on some services,
 * and the run should not die with it.
 */
ignore_user_abort(true);

/*
 * Shared hosts often allow this even when they will not let you edit php.ini.
 * function_exists() matters: where set_time_limit is in disable_functions the
 * call is a fatal Error, which @ does not suppress.
 */
if (function_exists('set_time_limit')) {
    @set_time_limit(300);
}

/**
 * Leave headroom under max_execution_time: an audit killed mid-flight leaves
 * its queue item claimed, and that only clears on the stale sweep 15 minutes
 * later. One audit takes 10-40s, so a short limit means fewer URLs per call
 * rather than a broken run - the queue survives and the next call continues.
 */
$limit  = (int) ini_get('max_execution_time');
$budget = $limit > 0 ? max(20, $limit - 25) : 240;

/*
 * How long one audit may take before it is abandoned - http_timeout, capped
 * the same way PageSpeed::audit caps it. The budget above is checked BEFORE
 * an audit starts, so without reserving this much an audit begun just under
 * the line runs well past the execution limit and is killed mid-flight: the
 * one outcome the budget exists to avoid. With http_timeout at 120 that is
 * two minutes of overrun.
 */
$perAudit = (int) Config::get('http_timeout', 120);
if ($limit > 0) {
    $perAudit = min($perAudit, max(10, $limit - 12));
}
$reserve = $perAudit + 10;          // connecting, storing, and the rules

$pending = Migrations::pending();
if ($pending !== []) {
    http_response_code(500);
    exit('Database is out of date (' . implode(', ', $pending) . "). Open setup.php.\n");
}

echo "Mobile Web Audit - scheduled run\n";
echo 'budget: ' . $budget . 's, reserving ' . $reserve . "s per audit\n\n";

if (Settings::apiKey() === '') {
    echo "WARNING: no PageSpeed API key set - Google will rate-limit this hard.\n\n";
}

// Anything a previous call ran out of time on comes first.
Runs::requeueStale(15);
$runIds = array_map(static fn (array $run): int => (int) $run['id'], Runs::unfinished());

if ($runIds !== []) {
    echo 'resuming ' . count($runIds) . " unfinished run(s)\n";
} else {
    /*
     * This endpoint is meant to be polled far more often than the audit
     * schedule, because the extra calls are what finish a long queue. So it,
     * not the scheduler, decides when a new sweep is due - otherwise hourly
     * polling would audit everything hourly and comment on every open ClickUp
     * task just as often. ?force=1 overrides it for a manual run.
     */
    $interval = max(1, (int) Settings::get('audit_interval_hours', 84));
    $last     = Runs::lastScheduledAt();
    $forced   = isset($_GET['force']) && $_GET['force'] !== '0';

    if ($last !== null && !$forced) {
        $elapsed = (time() - strtotime($last . ' UTC')) / 3600;
        if ($elapsed < $interval) {
            $due = gmdate('Y-m-d H:i', strtotime($last . ' UTC') + $interval * 3600);
            echo 'Last sweep was ' . round($elapsed, 1) . 'h ago; they run every ' . $interval . "h.\n";
            echo 'Next one is due ' . $due . " UTC. Nothing to do.\n";
            echo "Add &force=1 to run one now anyway.\n";
            exit(0);
        }
    }

    $pages = Pages::monitored();
    if ($pages === []) {
        exit("Nothing to audit.\n");
    }
    $bySite = [];
    foreach ($pages as $page) {
        $bySite[(int) $page['site_id']][] = $page;
    }
    foreach ($bySite as $siteId => $sitePages) {
        $runId = Runs::create((int) $siteId, $sitePages, 'scheduled');
        if ($runId > 0) {
            $runIds[] = $runId;
        }
    }
    echo 'queued ' . count($pages) . ' URL(s) across ' . count($runIds) . " run(s)\n";
}

echo "\n";

$done      = 0;
$failed    = 0;
$exhausted = false;

foreach ($runIds as $runId) {
    while (true) {
        /*
         * Always let the first audit of a call start, even where the reserve
         * does not fit the budget - otherwise a host with a short limit and a
         * generous http_timeout would return "out of time" forever without
         * auditing anything, and the queue would never drain.
         */
        if ($done > 0 && microtime(true) - $started + $reserve > $budget) {
            $exhausted = true;
            break 2;
        }
        $processed = AuditRunner::processQueue($runId, 1);
        if ($processed === []) {
            break;
        }
        foreach ($processed as $result) {
            $done++;
            if (!$result['ok']) {
                $failed++;
            }
            printf(
                "%s %-4s %s%s\n",
                $result['ok'] ? 'OK  ' : 'FAIL',
                $result['ok'] ? (string) $result['score'] : '',
                $result['url'],
                $result['task'] ? '  [' . $result['task'] . ']' : ''
            );
            flush();
        }
    }
}

echo "\n" . $done . ' audited, ' . $failed . ' failed, '
    . round(microtime(true) - $started, 1) . "s\n";

if ($exhausted) {
    echo "Out of time - the rest stays queued and resumes on the next call.\n";
    if ($reserve * 2 > $budget) {
        echo 'Only about one URL fits per call here (' . $budget . 's budget, ' . $reserve
            . "s reserved per audit).\n"
            . "Raising max_execution_time, or lowering http_timeout, would fit more.\n";
    }
}
