<?php
declare(strict_types=1);

namespace Wva\Repo;

use Wva\Database;
use Wva\Helpers;

final class Tasks
{
    public const OPEN_STATES = ['open', 'in_progress'];

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        return Database::one(
            'SELECT t.*, p.url, p.path, s.name AS site_name
             FROM tasks t
             INNER JOIN pages p ON p.id = t.page_id
             INNER JOIN sites s ON s.id = t.site_id
             WHERE t.id = ?',
            [$id]
        );
    }

    /** @return array<string,mixed>|null */
    public static function openForPage(int $pageId): ?array
    {
        return Database::one(
            'SELECT * FROM tasks WHERE page_id = ? AND status IN (\'open\',\'in_progress\')
             ORDER BY id DESC LIMIT 1',
            [$pageId]
        );
    }

    /**
     * @param array{status?:string,site_id?:int,priority?:string} $filters
     * @return array<int,array<string,mixed>>
     */
    public static function search(array $filters = []): array
    {
        $where  = [];
        $params = [];

        $status = $filters['status'] ?? 'open';
        if ($status === 'open') {
            $where[] = 't.status IN (\'open\',\'in_progress\')';
        } elseif ($status !== 'all') {
            $where[]  = 't.status = ?';
            $params[] = $status;
        }
        if (!empty($filters['site_id'])) {
            $where[]  = 't.site_id = ?';
            $params[] = (int) $filters['site_id'];
        }
        if (!empty($filters['priority'])) {
            $where[]  = 't.priority = ?';
            $params[] = (string) $filters['priority'];
        }

        $sql = 'SELECT t.*, p.url, p.path, p.last_score, s.name AS site_name
                FROM tasks t
                INNER JOIN pages p ON p.id = t.page_id
                INNER JOIN sites s ON s.id = t.site_id';
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= " ORDER BY FIELD(t.status,'open','in_progress','resolved','ignored'),
                           FIELD(t.priority,'critical','high','normal'),
                           t.latest_score ASC, t.opened_at DESC
                  LIMIT 500";

        return Database::all($sql, $params);
    }

    /** @return array<int,array<string,mixed>> */
    public static function forPage(int $pageId): array
    {
        return Database::all('SELECT * FROM tasks WHERE page_id = ? ORDER BY id DESC LIMIT 25', [$pageId]);
    }

    /**
     * Open a task for a failing page, or refresh the one already open.
     * Returns [taskId, 'created'|'updated'].
     *
     * @param array<string,mixed> $page
     * @param array<string,mixed> $audit
     * @return array{0:int,1:string}
     */
    public static function openOrRefresh(array $page, array $audit, int $auditId, int $threshold): array
    {
        $score    = (int) $audit['performance_score'];
        $priority = $score < 50 ? 'critical' : 'high';
        $now      = Database::now();
        $details  = self::buildDetails($audit, $threshold);
        $existing = self::openForPage((int) $page['id']);

        if ($existing !== null) {
            Database::run(
                'UPDATE tasks SET latest_score = ?, audit_id = ?, priority = ?, details = ?,
                        threshold = ?, updated_at = ? WHERE id = ?',
                [$score, $auditId, $priority, $details, $threshold, $now, (int) $existing['id']]
            );
            return [(int) $existing['id'], 'updated'];
        }

        $title = sprintf(
            'Mobile Core Web Vitals below %d (scored %d) - %s',
            $threshold,
            $score,
            Helpers::pathOf((string) $page['url'])
        );

        Database::run(
            'INSERT INTO tasks (site_id, page_id, audit_id, title, details, threshold, score_at_open,
                                latest_score, priority, status, opened_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, \'open\', ?, ?)',
            [
                (int) $page['site_id'],
                (int) $page['id'],
                $auditId,
                substr($title, 0, 255),
                $details,
                $threshold,
                $score,
                $score,
                $priority,
                $now,
                $now,
            ]
        );

        return [Database::insertId(), 'created'];
    }

    /** A page that came back above the threshold closes its open task. */
    public static function autoResolve(int $pageId, int $score, int $threshold): bool
    {
        $existing = self::openForPage($pageId);
        if ($existing === null) {
            return false;
        }
        $now = Database::now();
        Database::run(
            'UPDATE tasks SET status = \'resolved\', latest_score = ?, resolved_at = ?, updated_at = ?,
                    resolution_note = ? WHERE id = ?',
            [
                $score,
                $now,
                $now,
                sprintf('Auto-resolved: re-audited at %d, at or above the %d threshold.', $score, $threshold),
                (int) $existing['id'],
            ]
        );
        return true;
    }

    public static function updateStatus(int $id, string $status, ?string $assignee, ?string $note): void
    {
        $allowed = ['open', 'in_progress', 'resolved', 'ignored'];
        if (!in_array($status, $allowed, true)) {
            return;
        }
        $now        = Database::now();
        $resolvedAt = in_array($status, ['resolved', 'ignored'], true) ? $now : null;
        Database::run(
            'UPDATE tasks SET status = ?, assignee = ?, resolution_note = ?, updated_at = ?, resolved_at = ?
             WHERE id = ?',
            [$status, $assignee, $note !== null ? substr($note, 0, 255) : null, $now, $resolvedAt, $id]
        );
    }

    /** @param array<string,mixed> $audit */
    private static function buildDetails(array $audit, int $threshold): string
    {
        $lines = [];
        $lines[] = sprintf(
            'Mobile performance score %d (target %d+). Audited %s UTC.',
            (int) $audit['performance_score'],
            $threshold,
            (string) $audit['fetched_at']
        );
        $lines[] = '';
        $lines[] = 'Lab metrics (mobile):';
        $lines[] = '  LCP  ' . Helpers::ms(isset($audit['lcp_ms']) ? (int) $audit['lcp_ms'] : null) . '   (good: under 2.5 s)';
        $lines[] = '  CLS  ' . Helpers::cls($audit['cls'] ?? null) . '   (good: under 0.1)';
        $lines[] = '  TBT  ' . Helpers::ms(isset($audit['tbt_ms']) ? (int) $audit['tbt_ms'] : null) . '   (proxy for INP)';
        $lines[] = '  FCP  ' . Helpers::ms(isset($audit['fcp_ms']) ? (int) $audit['fcp_ms'] : null);
        $lines[] = '  TTFB ' . Helpers::ms(isset($audit['ttfb_ms']) ? (int) $audit['ttfb_ms'] : null);

        $opportunities = $audit['opportunities'] ?? [];
        if (is_string($opportunities)) {
            $opportunities = json_decode($opportunities, true) ?: [];
        }
        if (is_array($opportunities) && $opportunities !== []) {
            $lines[] = '';
            $lines[] = 'Biggest wins Lighthouse found:';
            foreach ($opportunities as $item) {
                $saving = (int) ($item['savings_ms'] ?? 0);
                $lines[] = '  - ' . (string) ($item['title'] ?? '')
                    . ($saving > 0 ? ' (saves ~' . Helpers::ms($saving) . ')' : '');
            }
        }

        return implode("\n", $lines);
    }
}
