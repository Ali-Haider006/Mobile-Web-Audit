<?php
declare(strict_types=1);

namespace Wva;

use Wva\Repo\Pages;
use Wva\Repo\Sites;

/**
 * Turns a crawled sitemap into rows in `pages`, honouring the site's
 * exclusion patterns and the operator's checkbox selection.
 */
final class Importer
{
    /**
     * Decorate crawled URLs with what we already know about them, so the
     * import screen can pre-tick the right boxes.
     *
     * @param array<int,array{loc:string,lastmod:?string}> $urls
     * @return array<int,array{loc:string,lastmod:?string,known:bool,tracked:bool,excluded_by:?string}>
     */
    public static function preview(int $siteId, array $urls): array
    {
        $rules = array_map(
            static fn (array $rule): string => (string) $rule['pattern'],
            Sites::exclusionRules($siteId)
        );

        $known = [];
        foreach (Database::all('SELECT url_hash, is_tracked FROM pages WHERE site_id = ?', [$siteId]) as $row) {
            $known[(string) $row['url_hash']] = (int) $row['is_tracked'] === 1;
        }

        $out = [];
        foreach ($urls as $entry) {
            $hash        = Helpers::hash($entry['loc']);
            $isKnown     = array_key_exists($hash, $known);
            $excludedBy  = null;
            foreach ($rules as $pattern) {
                if (Helpers::matchesPattern($entry['loc'], $pattern)) {
                    $excludedBy = $pattern;
                    break;
                }
            }
            $out[] = [
                'loc'         => $entry['loc'],
                'lastmod'     => $entry['lastmod'],
                'known'       => $isKnown,
                // Default tick: keep what we had; otherwise track unless a rule excludes it.
                'tracked'     => $isKnown ? $known[$hash] : $excludedBy === null,
                'excluded_by' => $excludedBy,
            ];
        }

        return $out;
    }

    /**
     * Save the import. Every discovered URL is stored so the exclusion is
     * remembered; only the selected ones are tracked.
     *
     * @param array<int,array{loc:string,lastmod:?string}> $urls   everything the sitemap listed
     * @param array<int,string>                           $selected URLs the operator ticked
     * @return array{added:int, updated:int, tracked:int, excluded:int}
     */
    public static function save(int $siteId, array $urls, array $selected): array
    {
        $selectedHashes = [];
        foreach ($selected as $url) {
            $normalized = Helpers::normalizeUrl((string) $url);
            if ($normalized !== null) {
                $selectedHashes[Helpers::hash($normalized)] = true;
            }
        }

        $before = (int) Database::value('SELECT COUNT(*) FROM pages WHERE site_id = ?', [$siteId]);
        $tracked = 0;
        $excluded = 0;
        $seen     = 0;

        Database::pdo()->beginTransaction();
        try {
            foreach ($urls as $entry) {
                $normalized = Helpers::normalizeUrl($entry['loc']);
                if ($normalized === null) {
                    continue;
                }
                $seen++;
                $shouldTrack = isset($selectedHashes[Helpers::hash($normalized)]);
                $pageId = Pages::upsert($siteId, $normalized, $entry['lastmod'], $shouldTrack, 'sitemap');
                Database::run('UPDATE pages SET is_tracked = ? WHERE id = ?', [$shouldTrack ? 1 : 0, $pageId]);
                $shouldTrack ? $tracked++ : $excluded++;
            }
            Database::pdo()->commit();
        } catch (\Throwable $e) {
            Database::pdo()->rollBack();
            throw $e;
        }

        $after = (int) Database::value('SELECT COUNT(*) FROM pages WHERE site_id = ?', [$siteId]);

        return [
            'added'    => max(0, $after - $before),
            'updated'  => $seen - max(0, $after - $before),
            'tracked'  => $tracked,
            'excluded' => $excluded,
        ];
    }
}
