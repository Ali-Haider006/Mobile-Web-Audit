<?php
declare(strict_types=1);

namespace Wva\Repo;

use Wva\Database;
use Wva\Helpers;
use Wva\Settings;

final class Sites
{
    /** @return array<int,array<string,mixed>> */
    public static function all(): array
    {
        return Database::all(
            'SELECT s.*,
                    (SELECT COUNT(*) FROM pages p WHERE p.site_id = s.id) AS page_count,
                    (SELECT COUNT(*) FROM pages p WHERE p.site_id = s.id AND p.is_tracked = 1) AS tracked_count,
                    (SELECT COUNT(*) FROM pages p WHERE p.site_id = s.id AND p.is_tracked = 1
                        AND p.last_score IS NOT NULL AND p.last_score < COALESCE(s.score_threshold, ?)) AS failing_count,
                    (SELECT ROUND(AVG(p.last_score)) FROM pages p WHERE p.site_id = s.id AND p.is_tracked = 1
                        AND p.last_score IS NOT NULL) AS avg_score,
                    (SELECT COUNT(*) FROM tasks t WHERE t.site_id = s.id AND t.status IN (\'open\',\'in_progress\')) AS open_tasks,
                    (SELECT MAX(p.last_audit_at) FROM pages p WHERE p.site_id = s.id) AS last_audit_at
             FROM sites s
             ORDER BY s.name',
            [Settings::threshold()]
        );
    }

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        return Database::one('SELECT * FROM sites WHERE id = ?', [$id]);
    }

    /** @return array<string,mixed>|null */
    public static function findByUrl(string $url): ?array
    {
        return Database::one('SELECT * FROM sites WHERE url_hash = ?', [Helpers::hash($url)]);
    }

    /** Find the site owning a URL, matching on host. */
    public static function findByHost(string $host): ?array
    {
        return Database::one('SELECT * FROM sites WHERE host = ? ORDER BY id LIMIT 1', [$host]);
    }

    /** Create the site, or return the existing one with the same origin. */
    public static function ensure(string $url, ?string $name = null): array
    {
        $normalized = Helpers::normalizeUrl($url);
        if ($normalized === null) {
            throw new \RuntimeException('Enter a valid website URL, e.g. https://example.com');
        }
        $origin = \Wva\Sitemap::origin($normalized);

        $existing = self::findByUrl($origin);
        if ($existing !== null) {
            return $existing;
        }

        $now = Database::now();
        Database::run(
            'INSERT INTO sites (name, url, url_hash, host, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)',
            [
                $name !== null && trim($name) !== '' ? trim($name) : Helpers::hostOf($origin),
                $origin,
                Helpers::hash($origin),
                Helpers::hostOf($origin),
                $now,
                $now,
            ]
        );

        return self::find(Database::insertId()) ?? throw new \RuntimeException('Could not create the site.');
    }

    public static function threshold(array $site): int
    {
        $override = $site['score_threshold'] ?? null;
        return $override !== null ? (int) $override : Settings::threshold();
    }

    public static function update(
        int $id,
        string $name,
        ?int $threshold,
        ?string $sitemapUrl,
        bool $isActive,
        ?string $clickUpListId = null
    ): void {
        Database::run(
            'UPDATE sites SET name = ?, score_threshold = ?, sitemap_url = ?, is_active = ?,
                    clickup_list_id = ?, updated_at = ? WHERE id = ?',
            [$name, $threshold, $sitemapUrl, $isActive ? 1 : 0, $clickUpListId, Database::now(), $id]
        );
    }

    /** Stop or resume scheduled audits for a whole site. */
    public static function setActive(int $id, bool $active): void
    {
        Database::run(
            'UPDATE sites SET is_active = ?, updated_at = ? WHERE id = ?',
            [$active ? 1 : 0, Database::now(), $id]
        );
    }

    public static function delete(int $id): void
    {
        Database::run('DELETE FROM sites WHERE id = ?', [$id]);
    }

    /** @return array<int,array<string,mixed>> */
    public static function exclusionRules(int $siteId): array
    {
        return Database::all('SELECT * FROM exclusion_rules WHERE site_id = ? ORDER BY pattern', [$siteId]);
    }

    public static function addExclusionRule(int $siteId, string $pattern): void
    {
        $pattern = trim($pattern);
        if ($pattern === '') {
            return;
        }
        Database::run(
            'INSERT IGNORE INTO exclusion_rules (site_id, pattern, created_at) VALUES (?, ?, ?)',
            [$siteId, substr($pattern, 0, 255), Database::now()]
        );
    }

    public static function deleteExclusionRule(int $siteId, int $ruleId): void
    {
        Database::run('DELETE FROM exclusion_rules WHERE id = ? AND site_id = ?', [$ruleId, $siteId]);
    }

    /**
     * Daily average score of the tracked pages - the site trend line.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function scoreTrend(int $siteId, int $days = 90): array
    {
        $days = max(1, min(730, $days)); // inlined below: MySQL will not bind INTERVAL
        return Database::all(
            'SELECT DATE(a.fetched_at) AS day,
                    ROUND(AVG(a.performance_score), 1) AS avg_score,
                    COUNT(*) AS samples
             FROM audits a
             INNER JOIN pages p ON p.id = a.page_id
             WHERE a.site_id = ? AND a.status = \'ok\' AND a.performance_score IS NOT NULL
               AND p.is_tracked = 1
               AND a.fetched_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL ' . $days . ' DAY)
             GROUP BY DATE(a.fetched_at)
             ORDER BY day',
            [$siteId]
        );
    }
}
