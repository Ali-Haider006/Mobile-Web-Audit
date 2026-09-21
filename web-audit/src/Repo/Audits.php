<?php
declare(strict_types=1);

namespace Wva\Repo;

use Wva\Database;

final class Audits
{
    /** @param array<string,mixed> $row */
    public static function insert(int $siteId, int $pageId, ?int $runId, array $row): int
    {
        Database::run(
            'INSERT INTO audits (page_id, site_id, run_id, strategy, status, error_message,
                performance_score, accessibility_score, best_practices_score, seo_score,
                lcp_ms, fcp_ms, cls, tbt_ms, si_ms, ttfb_ms,
                field_lcp_ms, field_cls, field_inp_ms, field_verdict,
                opportunities, lighthouse_version, duration_ms, fetched_at, created_at)
             VALUES (?, ?, ?, \'mobile\', ?, ?,
                ?, ?, ?, ?,
                ?, ?, ?, ?, ?, ?,
                ?, ?, ?, ?,
                ?, ?, ?, ?, ?)',
            [
                $pageId,
                $siteId,
                $runId,
                (string) ($row['status'] ?? 'ok'),
                isset($row['error_message']) ? substr((string) $row['error_message'], 0, 500) : null,
                $row['performance_score']    ?? null,
                $row['accessibility_score']  ?? null,
                $row['best_practices_score'] ?? null,
                $row['seo_score']            ?? null,
                $row['lcp_ms']  ?? null,
                $row['fcp_ms']  ?? null,
                $row['cls']     ?? null,
                $row['tbt_ms']  ?? null,
                $row['si_ms']   ?? null,
                $row['ttfb_ms'] ?? null,
                $row['field_lcp_ms']  ?? null,
                $row['field_cls']     ?? null,
                $row['field_inp_ms']  ?? null,
                $row['field_verdict'] ?? null,
                isset($row['opportunities']) && $row['opportunities'] !== []
                    ? json_encode($row['opportunities'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                    : null,
                $row['lighthouse_version'] ?? null,
                $row['duration_ms'] ?? null,
                (string) ($row['fetched_at'] ?? Database::now()),
                Database::now(),
            ]
        );

        return Database::insertId();
    }

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        return Database::one('SELECT * FROM audits WHERE id = ?', [$id]);
    }

    /**
     * Latest SUCCESSFUL audit - what the metric tiles and the site table show.
     * A failed re-audit must not blank out the last known-good numbers; the
     * failure is still visible in the audit log (recentForPage).
     *
     * @return array<string,mixed>|null
     */
    public static function latestForPage(int $pageId): ?array
    {
        return Database::one(
            'SELECT * FROM audits WHERE page_id = ? AND status = \'ok\'
             ORDER BY fetched_at DESC, id DESC LIMIT 1',
            [$pageId]
        );
    }

    /**
     * Latest OK audit for many pages in one query - the site table would
     * otherwise fire one query per row.
     *
     * @param array<int,int> $pageIds
     * @return array<int,array<string,mixed>> keyed by page_id
     */
    public static function latestForPages(array $pageIds): array
    {
        $pageIds = array_values(array_unique(array_filter(array_map('intval', $pageIds))));
        if ($pageIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($pageIds), '?'));
        $rows = Database::all(
            'SELECT a.* FROM audits a
             INNER JOIN (
                 SELECT page_id, MAX(id) AS max_id FROM audits
                 WHERE page_id IN (' . $placeholders . ') AND status = \'ok\'
                 GROUP BY page_id
             ) latest ON latest.max_id = a.id',
            $pageIds
        );

        $byPage = [];
        foreach ($rows as $row) {
            $byPage[(int) $row['page_id']] = $row;
        }
        return $byPage;
    }

    /**
     * Full history for one page, oldest first - this is what the graph plots.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function historyForPage(int $pageId, int $limit = 365): array
    {
        $limit = max(1, min(2000, $limit));
        $rows = Database::all(
            'SELECT * FROM audits WHERE page_id = ? AND status = \'ok\'
             ORDER BY fetched_at DESC, id DESC LIMIT ' . $limit,
            [$pageId]
        );
        return array_reverse($rows);
    }

    /** @return array<int,array<string,mixed>> */
    public static function recentForPage(int $pageId, int $limit = 25): array
    {
        $limit = max(1, min(200, $limit));
        return Database::all(
            'SELECT * FROM audits WHERE page_id = ? ORDER BY fetched_at DESC, id DESC LIMIT ' . $limit,
            [$pageId]
        );
    }

    /** @return array<int,array<string,mixed>> */
    public static function forRun(int $runId): array
    {
        return Database::all(
            'SELECT a.*, p.url, p.path FROM audits a
             INNER JOIN pages p ON p.id = a.page_id
             WHERE a.run_id = ? ORDER BY a.performance_score IS NULL, a.performance_score ASC',
            [$runId]
        );
    }

    /** Small overview numbers for the dashboard. */
    public static function dashboardTotals(int $threshold): array
    {
        $row = Database::one(
            'SELECT COUNT(*) AS tracked_pages,
                    SUM(last_score IS NOT NULL) AS audited_pages,
                    SUM(last_score IS NOT NULL AND last_score < ?) AS failing_pages,
                    ROUND(AVG(last_score)) AS avg_score
             FROM pages WHERE is_tracked = 1',
            [$threshold]
        ) ?? [];

        $row['open_tasks'] = (int) Database::value(
            'SELECT COUNT(*) FROM tasks WHERE status IN (\'open\',\'in_progress\')'
        );

        return $row;
    }
}
