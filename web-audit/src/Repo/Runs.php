<?php
declare(strict_types=1);

namespace Wva\Repo;

use Wva\Database;

final class Runs
{
    /**
     * Queue a run for the given pages. Returns the run id (0 when nothing to do).
     *
     * @param array<int,array<string,mixed>> $pages
     */
    public static function create(int $siteId, array $pages, string $type = 'full'): int
    {
        if ($pages === []) {
            return 0;
        }
        $now = Database::now();
        Database::run(
            'INSERT INTO scan_runs (site_id, type, status, total_items, created_at) VALUES (?, ?, \'queued\', ?, ?)',
            [$siteId, $type, count($pages), $now]
        );
        $runId = Database::insertId();

        $stmt = Database::pdo()->prepare(
            'INSERT INTO scan_items (run_id, page_id, url, status) VALUES (?, ?, ?, \'pending\')'
        );
        foreach ($pages as $page) {
            $stmt->execute([$runId, (int) $page['id'], (string) $page['url']]);
        }

        return $runId;
    }

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        return Database::one('SELECT * FROM scan_runs WHERE id = ?', [$id]);
    }

    /** @return array<int,array<string,mixed>> */
    public static function recent(int $limit = 10, ?int $siteId = null): array
    {
        $limit = max(1, min(100, $limit));
        $sql = 'SELECT r.*, s.name AS site_name FROM scan_runs r INNER JOIN sites s ON s.id = r.site_id';
        $params = [];
        if ($siteId !== null) {
            $sql .= ' WHERE r.site_id = ?';
            $params[] = $siteId;
        }
        $sql .= ' ORDER BY r.id DESC LIMIT ' . $limit;
        return Database::all($sql, $params);
    }

    /** @return array<int,array<string,mixed>> */
    public static function unfinished(): array
    {
        return Database::all(
            'SELECT r.*, s.name AS site_name FROM scan_runs r
             INNER JOIN sites s ON s.id = r.site_id
             WHERE r.status IN (\'queued\',\'running\') ORDER BY r.id'
        );
    }

    /**
     * Claim the next pending item of a run (or of any run when $runId is null).
     * The conditional UPDATE keeps two workers off the same URL.
     *
     * @return array<string,mixed>|null
     */
    public static function claimNext(?int $runId = null): ?array
    {
        $sql    = 'SELECT * FROM scan_items WHERE status = \'pending\'';
        $params = [];
        if ($runId !== null) {
            $sql .= ' AND run_id = ?';
            $params[] = $runId;
        }
        $sql .= ' ORDER BY id LIMIT 5';

        foreach (Database::all($sql, $params) as $item) {
            $claimed = Database::run(
                'UPDATE scan_items SET status = \'running\', attempts = attempts + 1, started_at = ?
                 WHERE id = ? AND status = \'pending\'',
                [Database::now(), (int) $item['id']]
            )->rowCount();

            if ($claimed === 1) {
                Database::run(
                    'UPDATE scan_runs SET status = \'running\', started_at = COALESCE(started_at, ?) WHERE id = ?',
                    [Database::now(), (int) $item['run_id']]
                );
                return $item;
            }
        }

        return null;
    }

    public static function finishItem(int $itemId, bool $ok, ?string $error = null): void
    {
        $now = Database::now();
        Database::run(
            'UPDATE scan_items SET status = ?, error = ?, finished_at = ? WHERE id = ?',
            [$ok ? 'done' : 'error', $error !== null ? substr($error, 0, 500) : null, $now, $itemId]
        );
        $item = Database::one('SELECT run_id FROM scan_items WHERE id = ?', [$itemId]);
        if ($item !== null) {
            self::refresh((int) $item['run_id']);
        }
    }

    /** Recount a run's progress and close it when nothing is left. */
    public static function refresh(int $runId): array
    {
        $counts = Database::one(
            'SELECT COUNT(*) AS total,
                    SUM(status = \'done\')    AS done,
                    SUM(status = \'error\')   AS failed,
                    SUM(status IN (\'pending\',\'running\')) AS remaining
             FROM scan_items WHERE run_id = ?',
            [$runId]
        ) ?? ['total' => 0, 'done' => 0, 'failed' => 0, 'remaining' => 0];

        $remaining = (int) ($counts['remaining'] ?? 0);
        Database::run(
            'UPDATE scan_runs SET total_items = ?, done_items = ?, failed_items = ? WHERE id = ?',
            [(int) $counts['total'], (int) $counts['done'], (int) $counts['failed'], $runId]
        );

        if ($remaining === 0) {
            Database::run(
                'UPDATE scan_runs SET status = \'completed\', finished_at = COALESCE(finished_at, ?)
                 WHERE id = ? AND status IN (\'queued\',\'running\')',
                [Database::now(), $runId]
            );
        }

        return $counts;
    }

    public static function cancel(int $runId): void
    {
        Database::run('DELETE FROM scan_items WHERE run_id = ? AND status = \'pending\'', [$runId]);
        Database::run(
            'UPDATE scan_runs SET status = \'cancelled\', finished_at = COALESCE(finished_at, ?) WHERE id = ?',
            [Database::now(), $runId]
        );
    }

    /** Items stuck in `running` (worker died mid-call) go back in the queue. */
    public static function requeueStale(int $minutes = 15): int
    {
        $minutes = max(1, min(1440, $minutes));
        return Database::run(
            'UPDATE scan_items SET status = \'pending\'
             WHERE status = \'running\' AND attempts < 3
               AND started_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL ' . $minutes . ' MINUTE)'
        )->rowCount();
    }

    /** @return array<int,array<string,mixed>> */
    public static function items(int $runId, int $limit = 200): array
    {
        $limit = max(1, min(1000, $limit));
        return Database::all(
            'SELECT * FROM scan_items WHERE run_id = ? ORDER BY id LIMIT ' . $limit,
            [$runId]
        );
    }
}
