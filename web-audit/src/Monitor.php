<?php
declare(strict_types=1);

namespace Wva;

use Throwable;
use Wva\Repo\Pages;
use Wva\Repo\Sites;
use Wva\Repo\Tasks;

/**
 * What happens after each scheduled audit.
 *
 * The rules, as agreed:
 *   - below target            -> open a task, or comment on the one already open
 *   - recovered               -> comment on the task; a human closes it
 *   - fails again after a fix -> a NEW task, linked back to the previous one
 *   - flapping around target  -> ignored, so a weekly run stays quiet
 *   - audit itself failed     -> no task; alert only after N in a row
 *
 * Everything here is best effort: a ClickUp problem is recorded and never
 * stops an audit from being saved.
 */
final class Monitor
{
    /** Hysteresis: open below target, but only resolve once clearly above it. */
    public static function flapBand(): int
    {
        $band = (int) Settings::get('flap_band', 3);
        return max(0, min(20, $band));
    }

    /** Consecutive failed audits before we say something. */
    public static function errorStreakLimit(): int
    {
        $limit = (int) Settings::get('error_alert_streak', 3);
        return max(1, min(10, $limit));
    }

    /** Off by default: a person closes the ClickUp task, not the tool. */
    public static function closeOnRecovery(): bool
    {
        return (string) Settings::get('clickup_close_on_recovery', '0') === '1';
    }

    public static function recoveredStatus(): string
    {
        $status = trim((string) Settings::get('clickup_recovered_status', ''));
        return $status !== '' ? $status : 'complete';
    }

    /**
     * Called once per stored audit.
     *
     * @param array<string,mixed> $result the audit row, or an error row
     * @return array{action:string, detail:string}
     */
    public static function afterAudit(int $pageId, array $result, ?int $auditId = null): array
    {
        $page = Pages::find($pageId);
        if ($page === null) {
            return ['action' => 'skipped', 'detail' => 'page not found'];
        }
        $site = Sites::find((int) $page['site_id']);

        // The site may have been archived while its queue was still draining,
        // or the URL switched off. Record the score, but raise nothing.
        if (($site !== null && (int) $site['is_active'] !== 1) || (int) $page['is_tracked'] !== 1) {
            return ['action' => 'skipped', 'detail' => 'this URL is no longer audited'];
        }

        if (($result['status'] ?? 'ok') !== 'ok' || ($result['performance_score'] ?? null) === null) {
            return self::handleAuditFailure($page, (string) ($result['error_message'] ?? 'audit failed'));
        }

        Pages::clearErrorStreak($pageId);

        $score     = (int) $result['performance_score'];
        $threshold = $site !== null ? Sites::threshold($site) : Settings::threshold();
        $open      = Tasks::openForPage($pageId);

        if ($score < $threshold) {
            return $open === null
                ? self::openTask($page, $site, $result, $auditId, $threshold, $score)
                : self::stillFailing($open, $score, $threshold);
        }

        // At or above target. Only clear once clearly above, so a page sitting
        // on the line does not open and close a task every run.
        if ($open !== null && $score >= $threshold + self::flapBand()) {
            return self::recovered($open, $score, $threshold);
        }

        if ($open !== null) {
            return ['action' => 'held', 'detail' => 'score ' . $score . ' is within the flap band of ' . $threshold];
        }

        return ['action' => 'none', 'detail' => 'passing at ' . $score];
    }

    /**
     * @param array<string,mixed> $page
     * @param array<string,mixed>|null $site
     * @param array<string,mixed> $result
     * @return array{action:string, detail:string}
     */
    private static function openTask(array $page, ?array $site, array $result, ?int $auditId, int $threshold, int $score): array
    {
        $previous = Tasks::lastClosedForPage((int) $page['id']);

        [$taskId] = Tasks::openOrRefresh($page, $result, $auditId ?? 0, $threshold);
        if ($previous !== null) {
            Tasks::linkPrevious($taskId, (int) $previous['id']);
        }

        $listId = self::listFor($page, $site);
        if ($listId === '' || !ClickUp::configured()) {
            return ['action' => 'task opened', 'detail' => 'no ClickUp list configured for this URL'];
        }

        $push = ClickUpSync::push($taskId, $listId, [
            'assignee'    => self::assigneeFor($page, $site),
            'description' => self::descriptionWithHistory($taskId),
        ]);

        return $push['ok']
            ? ['action' => 'task opened', 'detail' => 'ClickUp task created' . ($previous !== null ? ', linked to the previous one' : '')]
            : ['action' => 'task opened', 'detail' => 'ClickUp failed: ' . (string) ($push['error'] ?? $push['skipped'])];
    }

    /**
     * @param array<string,mixed> $task
     * @return array{action:string, detail:string}
     */
    private static function stillFailing(array $task, int $score, int $threshold): array
    {
        Tasks::touchScore((int) $task['id'], $score);

        if (empty($task['clickup_task_id'])) {
            return ['action' => 'still failing', 'detail' => 'task already open, nothing in ClickUp to comment on'];
        }

        $was = $task['latest_score'] === null ? null : (int) $task['latest_score'];
        $move = $was === null ? '' : ($score === $was ? ' (unchanged)' : ' (was ' . $was . ')');

        return self::tryComment(
            (string) $task['clickup_task_id'],
            'Re-audited: still **' . $score . '** against a target of ' . $threshold . $move . '.',
            'still failing'
        );
    }

    /**
     * @param array<string,mixed> $task
     * @return array{action:string, detail:string}
     */
    private static function recovered(array $task, int $score, int $threshold): array
    {
        Tasks::autoResolve((int) $task['page_id'], $score, $threshold);

        if (empty($task['clickup_task_id'])) {
            return ['action' => 'recovered', 'detail' => 'resolved here; nothing in ClickUp'];
        }

        $note = 'Recovered: re-audited at **' . $score . '**, at or above the target of ' . $threshold . '. '
            . 'Closing this is left to you.';
        $outcome = self::tryComment((string) $task['clickup_task_id'], $note, 'recovered');

        if (self::closeOnRecovery()) {
            try {
                ClickUp::setStatus((string) $task['clickup_task_id'], self::recoveredStatus());
                $outcome['detail'] .= ', status set to ' . self::recoveredStatus();
            } catch (Throwable $e) {
                $outcome['detail'] .= ', could not set status: ' . $e->getMessage();
            }
        }

        return $outcome;
    }

    /**
     * An audit that did not produce a score. No task - a timeout is not a
     * performance problem - but say something once it keeps happening.
     *
     * @param array<string,mixed> $page
     * @return array{action:string, detail:string}
     */
    private static function handleAuditFailure(array $page, string $message): array
    {
        $streak = Pages::bumpErrorStreak((int) $page['id']);
        $limit  = self::errorStreakLimit();

        if ($streak < $limit) {
            return ['action' => 'audit failed', 'detail' => $streak . ' in a row, alerting at ' . $limit];
        }
        if (!empty($page['error_alerted_at'])) {
            return ['action' => 'audit failed', 'detail' => $streak . ' in a row, already alerted'];
        }

        Pages::markErrorAlerted((int) $page['id']);
        $task = Tasks::openForPage((int) $page['id']);

        if ($task === null || empty($task['clickup_task_id'])) {
            return ['action' => 'audit failing', 'detail' => $streak . ' failed audits in a row - see the monitor screen'];
        }

        return self::tryComment(
            (string) $task['clickup_task_id'],
            'Heads up: the audit itself has failed ' . $streak . ' times in a row. Last error: ' . $message,
            'audit failing'
        );
    }

    /** @return array{action:string, detail:string} */
    private static function tryComment(string $clickUpTaskId, string $text, string $action): array
    {
        try {
            ClickUp::comment($clickUpTaskId, $text);
            return ['action' => $action, 'detail' => 'commented in ClickUp'];
        } catch (Throwable $e) {
            return ['action' => $action, 'detail' => 'ClickUp comment failed: ' . $e->getMessage()];
        }
    }

    /** URL setting first, then the site's, then the global default. */
    public static function listFor(array $page, ?array $site): string
    {
        $perUrl = trim((string) ($page['clickup_list_id'] ?? ''));
        if ($perUrl !== '') {
            return $perUrl;
        }
        return ClickUpSync::listIdFor($site);
    }

    /**
     * A site is no longer being audited - the client left, or the URL was a
     * mistake. Every task still open here is now nobody's job, so say so in
     * ClickUp and settle it on our side.
     *
     * ClickUp tasks are commented on, never closed: whoever owns that list
     * decides what happens to work already assigned to a person. Same rule as
     * a recovery, for the same reason.
     *
     * @return array{tasks:int,commented:int,failed:int}
     */
    public static function siteStopped(int $siteId, string $reason): array
    {
        $result = ['tasks' => 0, 'commented' => 0, 'failed' => 0];

        foreach (Tasks::search(['site_id' => $siteId, 'status' => 'open']) as $task) {
            $result['tasks']++;
            $taskId = (int) $task['id'];

            if (!empty($task['clickup_task_id'])) {
                $note = 'This page is no longer being audited (' . $reason . '), so this task will not be '
                    . 'updated again. Closing it is left to you.';
                $outcome = self::tryComment((string) $task['clickup_task_id'], $note, 'stopped');
                $outcome['detail'] === 'commented in ClickUp' ? $result['commented']++ : $result['failed']++;
            }

            Tasks::closeOut($taskId, 'No longer audited: ' . $reason);
        }

        return $result;
    }

    public static function assigneeFor(array $page, ?array $site): int
    {
        $perUrl = (int) ($page['clickup_assignee_id'] ?? 0);
        if ($perUrl > 0) {
            return $perUrl;
        }
        return (int) Settings::get('clickup_default_assignee', 0);
    }

    /**
     * The task body plus links to the tasks this one succeeds, so the chain of
     * "we fixed this before" is visible from inside ClickUp.
     */
    public static function descriptionWithHistory(int $taskId): string
    {
        $task = Tasks::find($taskId);
        if ($task === null) {
            return '';
        }

        $body    = ClickUpSync::description($task);
        $history = Tasks::chain($taskId);
        if ($history === []) {
            return $body;
        }

        $lines = [$body, '', '**Previously raised for this page**'];
        foreach ($history as $old) {
            $when  = gmdate('j M Y', strtotime((string) $old['opened_at'] . ' UTC'));
            $score = $old['score_at_open'] !== null ? (int) $old['score_at_open'] : '?';
            $link  = !empty($old['clickup_task_url']) ? (string) $old['clickup_task_url'] : '';
            $lines[] = '- ' . $when . ' — scored ' . $score
                . ($old['status'] === 'resolved' ? ', resolved' : ', ' . (string) $old['status'])
                . ($link !== '' ? ' — ' . $link : '');
        }

        return implode("\n", $lines);
    }
}
