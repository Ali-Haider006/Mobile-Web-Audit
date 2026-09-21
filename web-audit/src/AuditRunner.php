<?php
declare(strict_types=1);

namespace Wva;

use RuntimeException;
use Throwable;
use Wva\Repo\Audits;
use Wva\Repo\Pages;
use Wva\Repo\Runs;
use Wva\Repo\Sites;
use Wva\Repo\Tasks;

/**
 * Runs a page through PageSpeed Insights, stores the result as history, and
 * opens or closes the task that the score implies.
 */
final class AuditRunner
{
    /**
     * Audit a single page.
     *
     * @return array{ok:bool, audit_id:?int, score:?int, task:?string, error:?string}
     */
    public static function auditPage(int $pageId, ?int $runId = null): array
    {
        $page = Pages::find($pageId);
        if ($page === null) {
            return ['ok' => false, 'audit_id' => null, 'score' => null, 'task' => null, 'error' => 'Page not found'];
        }
        $site = Sites::find((int) $page['site_id']);
        if ($site === null) {
            return ['ok' => false, 'audit_id' => null, 'score' => null, 'task' => null, 'error' => 'Site not found'];
        }

        try {
            $result = PageSpeed::audit((string) $page['url'], null, (int) Config::get('http_timeout', 120));
        } catch (Throwable $e) {
            $auditId = Audits::insert((int) $site['id'], $pageId, $runId, [
                'status'        => 'error',
                'error_message' => $e->getMessage(),
                'fetched_at'    => Database::now(),
            ]);
            return [
                'ok'       => false,
                'audit_id' => $auditId,
                'score'    => null,
                'task'     => null,
                'error'    => $e->getMessage(),
            ];
        }

        $result['status'] = 'ok';
        $auditId = Audits::insert((int) $site['id'], $pageId, $runId, $result);

        $score = $result['performance_score'] ?? null;
        Pages::recordAuditResult($pageId, $score === null ? null : (int) $score, (string) $result['fetched_at']);

        $taskAction = null;
        if ($score !== null) {
            $threshold = Sites::threshold($site);
            if ((int) $score < $threshold) {
                [, $taskAction] = Tasks::openOrRefresh($page, $result + ['performance_score' => (int) $score], $auditId, $threshold);
            } elseif (Tasks::autoResolve($pageId, (int) $score, $threshold)) {
                $taskAction = 'resolved';
            }
        }

        return [
            'ok'       => true,
            'audit_id' => $auditId,
            'score'    => $score === null ? null : (int) $score,
            'task'     => $taskAction,
            'error'    => null,
        ];
    }

    /**
     * Process up to $limit queued items. Used by both the browser poller and
     * the CLI worker.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function processQueue(?int $runId = null, int $limit = 1): array
    {
        $processed = [];
        for ($i = 0; $i < max(1, $limit); $i++) {
            $item = Runs::claimNext($runId);
            if ($item === null) {
                break;
            }
            $result = self::auditPage((int) $item['page_id'], (int) $item['run_id']);
            Runs::finishItem((int) $item['id'], $result['ok'], $result['error']);
            $processed[] = $result + ['url' => (string) $item['url'], 'item_id' => (int) $item['id']];
        }
        return $processed;
    }

    /**
     * Queue every tracked page of a site.
     *
     * @return array{run_id:int, queued:int}
     */
    public static function queueSite(int $siteId, string $type = 'full'): array
    {
        $pages = Pages::tracked($siteId);
        if ($pages === []) {
            return ['run_id' => 0, 'queued' => 0];
        }
        return ['run_id' => Runs::create($siteId, $pages, $type), 'queued' => count($pages)];
    }

    /**
     * The "here are 3-4 URLs, score them now" path. URLs are attached to the
     * site that owns their host (created on the fly), so the one-off check
     * still lands in the history and still opens tasks.
     *
     * @param array<int,string> $urls
     * @return array{run_id:int, queued:int, site_ids:array<int,int>, skipped:array<int,string>}
     */
    public static function queueUrls(array $urls, bool $keepTracking = false): array
    {
        $skipped  = [];
        $bySite   = [];

        foreach ($urls as $raw) {
            $url = Helpers::normalizeUrl((string) $raw);
            if ($url === null) {
                if (trim((string) $raw) !== '') {
                    $skipped[] = (string) $raw;
                }
                continue;
            }
            $site   = Sites::ensure(Sitemap::origin($url));
            $pageId = Pages::upsert((int) $site['id'], $url, null, $keepTracking, 'manual');
            if ($keepTracking) {
                Pages::setTracked((int) $site['id'], [$pageId], true);
            }
            $page = Pages::find($pageId);
            if ($page !== null) {
                $bySite[(int) $site['id']][] = $page;
            }
        }

        if ($bySite === []) {
            return ['run_id' => 0, 'queued' => 0, 'site_ids' => [], 'skipped' => $skipped];
        }

        // One run per site; the first is the one we send the browser to.
        $runIds = [];
        $queued = 0;
        foreach ($bySite as $siteId => $pages) {
            $runIds[] = Runs::create((int) $siteId, $pages, 'quick');
            $queued  += count($pages);
        }

        return [
            'run_id'   => (int) $runIds[0],
            'queued'   => $queued,
            'site_ids' => array_map('intval', array_keys($bySite)),
            'skipped'  => $skipped,
        ];
    }

    /** Guard: PSI without an API key is rate-limited to a trickle. */
    public static function warnIfNoApiKey(): ?string
    {
        if (Settings::apiKey() !== '') {
            return null;
        }
        return 'No PageSpeed API key is set, so Google will rate-limit these scans hard. Add one under Settings.';
    }
}
