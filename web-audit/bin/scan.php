<?php
declare(strict_types=1);

/**
 * Queues audits for tracked pages, and optionally re-imports sitemaps first.
 *
 *   php bin/scan.php --all                 queue every active site
 *   php bin/scan.php --site=3              queue one site
 *   php bin/scan.php --all --import        re-import sitemaps before queueing
 *   php bin/scan.php --all --run           also drain the queue now
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only\n");
}

define('WVA_ROOT', dirname(__DIR__));
require_once WVA_ROOT . '/src/bootstrap.php';

use Wva\AuditRunner;
use Wva\Config;
use Wva\Database;
use Wva\Importer;
use Wva\Repo\Pages;
use Wva\Repo\Sites;
use Wva\Sitemap;

$options = getopt('', ['all', 'site::', 'import', 'run', 'max::']);

$sites = [];
if (isset($options['site'])) {
    $site = Sites::find((int) $options['site']);
    if ($site === null) {
        exit("No such site\n");
    }
    $sites[] = $site;
} elseif (isset($options['all'])) {
    $sites = Database::all('SELECT * FROM sites WHERE is_active = 1 ORDER BY id');
} else {
    exit("Usage: php bin/scan.php --all | --site=ID [--import] [--run] [--max=N]\n");
}

$totalQueued = 0;

foreach ($sites as $site) {
    $siteId = (int) $site['id'];

    if (isset($options['import'])) {
        try {
            $crawl = Sitemap::crawl(
                (string) $site['url'],
                $site['sitemap_url'] ?: null,
                (int) Config::get('max_sitemap_urls', 2000)
            );
            // Keep the team's existing choices; new URLs follow the exclusion rules.
            $selected = [];
            foreach (Importer::preview($siteId, $crawl['urls']) as $row) {
                if ($row['tracked']) {
                    $selected[] = $row['loc'];
                }
            }
            $result = Importer::save($siteId, $crawl['urls'], $selected);
            Pages::applyExclusionRules($siteId);
            printf("%s: sitemap %d URL(s), %d new%s", $site['name'], count($crawl['urls']), $result['added'], PHP_EOL);
        } catch (Throwable $e) {
            printf("%s: sitemap import failed - %s%s", $site['name'], $e->getMessage(), PHP_EOL);
        }
    }

    $queued = AuditRunner::queueSite($siteId, 'scheduled');
    $totalQueued += $queued['queued'];
    printf("%s: queued %d page(s)%s%s", $site['name'], $queued['queued'],
        $queued['run_id'] ? ' as run #' . $queued['run_id'] : '', PHP_EOL);
}

if (isset($options['run']) && $totalQueued > 0) {
    $max = isset($options['max']) ? max(1, (int) $options['max']) : $totalQueued;
    echo "Draining the queue...\n";
    for ($i = 0; $i < $max; $i++) {
        $processed = AuditRunner::processQueue(null, 1);
        if ($processed === []) {
            break;
        }
        foreach ($processed as $result) {
            printf("  %s %s %s%s", $result['ok'] ? 'OK' : 'FAIL', (string) ($result['score'] ?? '-'), $result['url'], PHP_EOL);
        }
        sleep(1);
    }
}

echo "Done.\n";
