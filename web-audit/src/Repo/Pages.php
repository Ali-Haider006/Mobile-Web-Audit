<?php
declare(strict_types=1);

namespace Wva\Repo;

use Wva\Database;
use Wva\Helpers;

final class Pages
{
    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        return Database::one('SELECT * FROM pages WHERE id = ?', [$id]);
    }

    /** @return array<string,mixed>|null */
    public static function findByUrl(int $siteId, string $url): ?array
    {
        return Database::one(
            'SELECT * FROM pages WHERE site_id = ? AND url_hash = ?',
            [$siteId, Helpers::hash($url)]
        );
    }

    /**
     * Insert the page if new; refresh lastmod/last-seen if we already know it.
     * Returns the page id. Tracking state of an existing page is never
     * overwritten here - an exclusion the team made by hand must survive
     * the next sitemap import.
     */
    public static function upsert(int $siteId, string $url, ?string $lastmod, bool $isTracked, string $source = 'sitemap'): int
    {
        $normalized = Helpers::normalizeUrl($url);
        if ($normalized === null) {
            throw new \RuntimeException('Not a valid URL: ' . $url);
        }
        $now      = Database::now();
        $existing = self::findByUrl($siteId, $normalized);

        if ($existing !== null) {
            Database::run(
                'UPDATE pages SET sitemap_lastmod = COALESCE(?, sitemap_lastmod),
                        last_seen_in_sitemap_at = ? WHERE id = ?',
                [$lastmod, $source === 'sitemap' ? $now : $existing['last_seen_in_sitemap_at'], (int) $existing['id']]
            );
            return (int) $existing['id'];
        }

        Database::run(
            'INSERT INTO pages (site_id, url, url_hash, path, source, is_tracked, sitemap_lastmod,
                                first_seen_at, last_seen_in_sitemap_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $siteId,
                $normalized,
                Helpers::hash($normalized),
                Helpers::pathOf($normalized),
                $source,
                $isTracked ? 1 : 0,
                $lastmod,
                $now,
                $source === 'sitemap' ? $now : null,
            ]
        );

        return Database::insertId();
    }

    /**
     * @param array{tracked?:string,band?:string,q?:string,sort?:string} $filters
     * @return array<int,array<string,mixed>>
     */
    public static function forSite(int $siteId, array $filters = []): array
    {
        $where  = ['p.site_id = ?'];
        $params = [$siteId];

        $tracked = $filters['tracked'] ?? 'all';
        if ($tracked === 'tracked') {
            $where[] = 'p.is_tracked = 1';
        } elseif ($tracked === 'excluded') {
            $where[] = 'p.is_tracked = 0';
        }

        $band = $filters['band'] ?? 'all';
        if ($band === 'failing') {
            $where[] = 'p.last_score IS NOT NULL AND p.last_score < ?';
            $params[] = (int) ($filters['threshold'] ?? 80);
        } elseif ($band === 'passing') {
            $where[] = 'p.last_score IS NOT NULL AND p.last_score >= ?';
            $params[] = (int) ($filters['threshold'] ?? 80);
        } elseif ($band === 'unaudited') {
            $where[] = 'p.last_score IS NULL';
        }

        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $where[]  = 'p.url LIKE ?';
            $params[] = '%' . $q . '%';
        }

        $order = match ($filters['sort'] ?? 'score') {
            'url'     => 'p.path ASC',
            'recent'  => 'p.last_audit_at IS NULL, p.last_audit_at DESC',
            'change'  => '(p.last_score - p.previous_score) ASC, p.last_score ASC',
            default   => 'p.last_score IS NULL, p.last_score ASC, p.path ASC',
        };

        return Database::all(
            'SELECT p.* FROM pages p WHERE ' . implode(' AND ', $where) . ' ORDER BY ' . $order . ' LIMIT 1000',
            $params
        );
    }

    /** @return array<int,array<string,mixed>> */
    public static function tracked(int $siteId): array
    {
        return Database::all(
            'SELECT * FROM pages WHERE site_id = ? AND is_tracked = 1 ORDER BY path',
            [$siteId]
        );
    }

    /** @param array<int,int> $pageIds */
    public static function setTracked(int $siteId, array $pageIds, bool $tracked): int
    {
        $pageIds = array_values(array_filter(array_map('intval', $pageIds)));
        if ($pageIds === []) {
            return 0;
        }
        $placeholders = implode(',', array_fill(0, count($pageIds), '?'));
        $stmt = Database::run(
            'UPDATE pages SET is_tracked = ? WHERE site_id = ? AND id IN (' . $placeholders . ')',
            array_merge([$tracked ? 1 : 0, $siteId], $pageIds)
        );
        return $stmt->rowCount();
    }

    /** @param array<int,int> $pageIds */
    public static function delete(int $siteId, array $pageIds): int
    {
        $pageIds = array_values(array_filter(array_map('intval', $pageIds)));
        if ($pageIds === []) {
            return 0;
        }
        $placeholders = implode(',', array_fill(0, count($pageIds), '?'));
        $stmt = Database::run(
            'DELETE FROM pages WHERE site_id = ? AND id IN (' . $placeholders . ')',
            array_merge([$siteId], $pageIds)
        );
        return $stmt->rowCount();
    }

    /** Apply the site's exclusion patterns to every page it already holds. */
    public static function applyExclusionRules(int $siteId): int
    {
        $rules = Sites::exclusionRules($siteId);
        if ($rules === []) {
            return 0;
        }
        $excluded = 0;
        foreach (Database::all('SELECT id, url FROM pages WHERE site_id = ? AND is_tracked = 1', [$siteId]) as $page) {
            foreach ($rules as $rule) {
                if (Helpers::matchesPattern((string) $page['url'], (string) $rule['pattern'])) {
                    Database::run('UPDATE pages SET is_tracked = 0 WHERE id = ?', [(int) $page['id']]);
                    $excluded++;
                    break;
                }
            }
        }
        return $excluded;
    }

    public static function recordAuditResult(int $pageId, ?int $score, string $fetchedAt): void
    {
        Database::run(
            'UPDATE pages
                SET previous_score = last_score,
                    last_score     = ?,
                    last_audit_at  = ?,
                    audit_count    = audit_count + 1
              WHERE id = ?',
            [$score, $fetchedAt, $pageId]
        );
    }
}
