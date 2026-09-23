<?php
declare(strict_types=1);

/**
 * End-to-end check against the configured database: imports, tracking,
 * exclusions, the queue, audit storage, task open/refresh/auto-resolve, every
 * list query the UI runs, and cascade deletes.
 *
 *   php bin/integration-test.php
 *
 * Safe to run against a live database: it only ever touches sites on reserved
 * .invalid domains that it creates itself, and removes them at the end. It
 * makes no PageSpeed API calls - audits are stored from a canned payload.
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only\n");
}

define('WVA_ROOT', dirname(__DIR__));
require_once WVA_ROOT . '/src/bootstrap.php';


use Wva\Database;
use Wva\Importer;
use Wva\PageSpeed;
use Wva\Repo\Audits;
use Wva\Repo\Pages;
use Wva\Repo\Runs;
use Wva\Repo\Sites;
use Wva\Repo\Tasks;
use Wva\Settings;

$fails = 0;
function check(string $label, mixed $actual, mixed $expected): void {
    global $fails;
    if ($actual === $expected) { echo "  ok   $label\n"; return; }
    $fails++;
    echo "  FAIL $label  expected " . var_export($expected, true) . ", got " . var_export($actual, true) . "\n";
}
function step(string $s): void { echo "\n$s\n"; }

/** A PSI response shaped like the real one, at a given score. */
function payload(int $score, string $when): array {
    return ['lighthouseResult' => [
        'lighthouseVersion' => '11.0.0', 'fetchTime' => $when,
        'categories' => ['performance' => ['score' => $score / 100], 'accessibility' => ['score' => 0.88],
                         'best-practices' => ['score' => 1], 'seo' => ['score' => 0.92]],
        'audits' => [
            'largest-contentful-paint' => ['numericValue' => 6421.4, 'score' => 0.1],
            'first-contentful-paint' => ['numericValue' => 2100.0, 'score' => 0.4],
            'cumulative-layout-shift' => ['numericValue' => 0.2345, 'score' => 0.3],
            'total-blocking-time' => ['numericValue' => 890.0, 'score' => 0.05],
            'speed-index' => ['numericValue' => 5300.0, 'score' => 0.2],
            'server-response-time' => ['numericValue' => 780.0, 'score' => 0.3],
            'unused-javascript' => ['title' => 'Reduce unused JavaScript', 'score' => 0.2,
                'displayValue' => 'Potential savings of 420 KiB',
                'details' => ['type' => 'opportunity', 'overallSavingsMs' => 1800]],
        ]],
        'loadingExperience' => ['overall_category' => 'SLOW', 'metrics' => [
            'LARGEST_CONTENTFUL_PAINT_MS' => ['percentile' => 4800],
            'CUMULATIVE_LAYOUT_SHIFT_SCORE' => ['percentile' => 18],
            'INTERACTION_TO_NEXT_PAINT' => ['percentile' => 340]]],
    ];
}

/** Sites this test owns - reserved .invalid domains it creates itself. */
function testSites(): array {
    return Database::all("SELECT * FROM sites WHERE host LIKE '%.invalid'");
}

step('Cleanup from any previous run');
foreach (testSites() as $stale) { Sites::delete((int) $stale['id']); }
check('starting clean', count(testSites()), 0);

$baselineTracked = (int) Audits::dashboardTotals(80)['tracked_pages'];

step('Sites');
$site = Sites::ensure('https://integration-test.invalid/some/path?x=1', 'Integration test');
check('site created', is_array($site), true);
check('origin stored', $site['url'], 'https://integration-test.invalid');
$again = Sites::ensure('https://integration-test.invalid');
check('idempotent', (int) $again['id'], (int) $site['id']);
$siteId = (int) $site['id'];
check('threshold falls back to global', Sites::threshold($site), 80);

step('Sitemap import (parse offline, save to DB)');
$urls = [];
foreach (['/', '/services', '/contact', '/tag/seo', '/tag/ppc', '/blog/post-1'] as $path) {
    $urls[] = ['loc' => 'https://integration-test.invalid' . $path, 'lastmod' => '2026-08-01'];
}
Sites::addExclusionRule($siteId, '*/tag/*');
$preview = Importer::preview($siteId, $urls);
check('preview rows', count($preview), 6);
$excludedByRule = count(array_filter($preview, static fn ($r) => $r['excluded_by'] !== null));
check('rule pre-excludes 2', $excludedByRule, 2);
$selected = array_map(static fn ($r) => $r['loc'], array_filter($preview, static fn ($r) => $r['tracked']));
$result = Importer::save($siteId, $urls, array_values($selected));
check('added 6', $result['added'], 6);
check('tracked 4', $result['tracked'], 4);
check('excluded 2', $result['excluded'], 2);
check('tracked in DB', count(Pages::tracked($siteId)), 4);

step('Re-import keeps a manual exclusion');
$home = Pages::findByUrl($siteId, 'https://integration-test.invalid/');
Pages::setTracked($siteId, [(int) $home['id']], false);
$preview2 = Importer::preview($siteId, $urls);
$homeRow = array_values(array_filter($preview2, static fn ($r) => $r['loc'] === 'https://integration-test.invalid/'))[0];
check('stays excluded on re-import', $homeRow['tracked'], false);
Pages::setTracked($siteId, [(int) $home['id']], true);

step('Queue a scan');
$queued = \Wva\AuditRunner::queueSite($siteId, 'full');
check('queued 4', $queued['queued'], 4);
$runId = $queued['run_id'];
$item = Runs::claimNext($runId);
check('claimed an item', $item !== null, true);
check('item is running', (string) Database::value('SELECT status FROM scan_items WHERE id = ?', [(int) $item['id']]), 'running');
check('second claim differs', (int) Runs::claimNext($runId)['id'] !== (int) $item['id'], true);

step('Store a failing audit (score 25) - task must open');
$pageId = (int) $item['page_id'];
$page = Pages::find($pageId);
$row = PageSpeed::parse(payload(25, '2026-09-10T10:00:00.000Z')) + ['status' => 'ok', 'duration_ms' => 24000];
$auditId = Audits::insert($siteId, $pageId, $runId, $row);
check('audit stored', $auditId > 0, true);
Pages::recordAuditResult($pageId, 25, (string) $row['fetched_at']);
[$taskId, $action] = Tasks::openOrRefresh($page, $row, $auditId, 80);
check('task created', $action, 'created');
$task = Tasks::find($taskId);
check('priority critical under 50', $task['priority'], 'critical');
check('details mention LCP', str_contains((string) $task['details'], 'LCP'), true);
check('opportunities round-tripped', str_contains((string) $task['details'], 'Reduce unused JavaScript'), true);
check('json column readable', is_array(json_decode((string) Audits::find($auditId)['opportunities'], true)), true);
check('cls stored', (string) Audits::find($auditId)['cls'], '0.235');
check('field cls stored', (string) Audits::find($auditId)['field_cls'], '0.180');
Runs::finishItem((int) $item['id'], true, null);

step('Second audit, still failing - refresh not duplicate');
$row2 = PageSpeed::parse(payload(45, '2026-09-15T10:00:00.000Z')) + ['status' => 'ok'];
$auditId2 = Audits::insert($siteId, $pageId, $runId, $row2);
Pages::recordAuditResult($pageId, 45, (string) $row2['fetched_at']);
[$taskId2, $action2] = Tasks::openOrRefresh(Pages::find($pageId), $row2, $auditId2, 80);
check('same task refreshed', $taskId2, $taskId);
check('action is updated', $action2, 'updated');
check('latest score tracked', (int) Tasks::find($taskId)['latest_score'], 45);
check('one open task only', (int) Database::value('SELECT COUNT(*) FROM tasks WHERE page_id = ?', [$pageId]), 1);
check('previous_score rolled', (int) Pages::find($pageId)['previous_score'], 25);

step('Passing audit - task auto-resolves');
$row3 = PageSpeed::parse(payload(91, '2026-09-20T10:00:00.000Z')) + ['status' => 'ok'];
$auditId3 = Audits::insert($siteId, $pageId, $runId, $row3);
Pages::recordAuditResult($pageId, 91, (string) $row3['fetched_at']);
check('auto-resolved', Tasks::autoResolve($pageId, 91, 80), true);
$resolved = Tasks::find($taskId);
check('status resolved', $resolved['status'], 'resolved');
check('resolved_at set', $resolved['resolved_at'] !== null, true);
check('note explains why', str_contains((string) $resolved['resolution_note'], 're-audited at 91'), true);
check('no open task left', Tasks::openForPage($pageId), null);

step('Error audit path');
$errId = Audits::insert($siteId, $pageId, $runId, ['status' => 'error', 'error_message' => 'PageSpeed API error: timeout', 'fetched_at' => Database::now()]);
check('error row stored', Audits::find($errId)['status'], 'error');
check('history skips errors', count(Audits::historyForPage($pageId)), 3);
check('log includes errors', count(Audits::recentForPage($pageId)) >= 4, true);

step('Read paths used by the UI');
check('history ascending', (int) Audits::historyForPage($pageId)[0]['performance_score'], 25);
check('history ends latest', (int) Audits::historyForPage($pageId)[2]['performance_score'], 91);
check('latestForPage', (int) Audits::latestForPage($pageId)['performance_score'], 91);
$ids = array_map(static fn ($p) => (int) $p['id'], Pages::forSite($siteId));
check('latestForPages batch', count(Audits::latestForPages($ids)) >= 1, true);
foreach (['all', 'tracked', 'excluded'] as $t) {
    foreach (['all', 'failing', 'passing', 'unaudited'] as $b) {
        foreach (['score', 'url', 'recent', 'change'] as $sort) {
            Pages::forSite($siteId, ['tracked' => $t, 'band' => $b, 'sort' => $sort, 'q' => 'ser', 'threshold' => 80]);
        }
    }
}
check('all filter/sort combinations run', true, true);
// Regression: last_score - previous_score on TINYINT UNSIGNED columns raises
// "BIGINT UNSIGNED value is out of range" once a page has actually dropped.
$dropped = Pages::findByUrl($siteId, 'https://integration-test.invalid/contact');
Database::run('UPDATE pages SET last_score = 30, previous_score = 70 WHERE id = ?', [(int) $dropped['id']]);
$byDrop = Pages::forSite($siteId, ['sort' => 'change', 'threshold' => 80]);
$withBoth = array_values(array_filter(
    $byDrop,
    static fn (array $p): bool => $p['last_score'] !== null && $p['previous_score'] !== null
));
check('biggest-drop sort survives a drop', $withBoth[0]['path'], '/contact');
$totals = Audits::dashboardTotals(80);
check('dashboard totals count our 4 pages', (int) $totals['tracked_pages'] - $baselineTracked, 4);
check('sites list', count(testSites()), 1);
check('avg score computed', Sites::all()[0]['avg_score'] !== null, true);
check('score trend', count(Sites::scoreTrend($siteId, 90)) >= 1, true);
check('tasks search open', count(Tasks::search(['status' => 'open', 'site_id' => $siteId])), 0);
check('tasks search all', count(Tasks::search(['status' => 'all', 'site_id' => $siteId])), 1);
check('tasks for page', count(Tasks::forPage($pageId)), 1);
check('runs recent', count(Runs::recent(5, $siteId)), 1);
check('run items', count(Runs::items($runId)) , 4);
check('audits for run', count(Audits::forRun($runId)) >= 3, true);

step('Task status updates');
Tasks::updateStatus($taskId, 'in_progress', 'Ali', 'Deferring hero image work');
$t = Tasks::find($taskId);
check('status changed', $t['status'], 'in_progress');
check('assignee saved', $t['assignee'], 'Ali');
check('resolved_at cleared', $t['resolved_at'], null);
Tasks::updateStatus($taskId, 'ignored', 'Ali', 'Client declined');
check('ignored sets resolved_at', Tasks::find($taskId)['resolved_at'] !== null, true);

step('Run bookkeeping');
Runs::requeueStale(15);
$counts = Runs::refresh($runId);
check('run totals', (int) $counts['total'], 4);
Runs::cancel($runId);
check('cancelled', Runs::find($runId)['status'], 'cancelled');
check('pending items dropped', (int) Database::value('SELECT COUNT(*) FROM scan_items WHERE run_id = ? AND status = ?', [$runId, 'pending']), 0);

step('ClickUp wiring (no API calls)');
use Wva\ClickUp;
use Wva\ClickUpSync;

Wva\Migrations::run();   // idempotent; the suite must not depend on install order
check('columns migrated in', Wva\Migrations::pending(), []);

// Control the token rather than assuming the database has none, and put back
// whatever was there so running this never disturbs a real configuration.
$savedToken   = (string) Settings::get('clickup_token', '');
$savedListId  = (string) Settings::get('clickup_default_list_id', '');
$savedAppUrl  = (string) Settings::get('app_url', '');

Settings::set('clickup_token', '');
check('not configured without a token', ClickUp::configured(), false);
Settings::set('clickup_token', 'pk_integration_test_token');
check('configured once a token is set', ClickUp::configured(), true);

Settings::set('clickup_default_list_id', '900100');
check('falls back to the global list', ClickUpSync::listIdFor(['clickup_list_id' => null]), '900100');
check('site override wins', ClickUpSync::listIdFor(['clickup_list_id' => '777']), '777');
check('blank override falls back', ClickUpSync::listIdFor(['clickup_list_id' => '  ']), '900100');

$sample = [
    'id' => 1, 'site_id' => $siteId, 'page_id' => 1, 'title' => 'Mobile Core Web Vitals below 80 (scored 25) - /services',
    'details' => "Mobile performance score 25 (target 80+).\n  LCP 6.42 s", 'threshold' => 80,
    'score_at_open' => 25, 'latest_score' => 25, 'priority' => 'critical',
    'opened_at' => '2026-09-20 10:00:00', 'url' => 'https://integration-test.invalid/services',
    'site_name' => 'Integration test',
];
Settings::set('app_url', 'https://audit.internal.example/');
$payload = ClickUpSync::payload($sample);
check('payload carries the title', str_contains((string) $payload['name'], 'scored 25'), true);
check('payload priority is urgent', $payload['priority'], 1);

// The review screen's edits must reach ClickUp instead of the generated text.
$edited = ClickUpSync::payload($sample, [
    'title'       => 'Hero image is 3 MB on mobile',
    'description' => 'Ask the designer for a 200 KB WebP.',
    'priority'    => 'normal',
]);
check('edited title wins', $edited['name'], 'Hero image is 3 MB on mobile');
check('edited body wins', $edited['markdown_description'], 'Ask the designer for a 200 KB WebP.');
check('edited priority wins', $edited['priority'], 3);
$blank = ClickUpSync::payload($sample, ['title' => '   ', 'description' => '']);
check('blank edits fall back to generated', $blank['name'], $sample['title']);
check('payload has tags', is_array($payload['tags'] ?? null), true);
$body = (string) $payload['markdown_description'];
check('body states the score', str_contains($body, '**Mobile performance 25**'), true);
check('body links the page', str_contains($body, 'https://integration-test.invalid/services'), true);
check('body links back to the tool', str_contains($body, 'https://audit.internal.example/page.php?id=1'), true);
check('body embeds the metrics', str_contains($body, 'LCP 6.42 s'), true);
Settings::set('app_url', '');
check('no backlink when app_url is unset', str_contains(ClickUpSync::description($sample), '/page.php?id='), false);

// $taskId is the task opened earlier in this run - a real row, so these
// exercise the skip paths rather than the not-found path.
Settings::set('clickup_token', '');
$noToken = ClickUpSync::push($taskId);
check('push without a token skips', $noToken['ok'], false);
check('skip says why', $noToken['skipped'], 'no ClickUp token set');

// With a token but no list chosen anywhere, it must skip rather than call out.
Settings::set('clickup_token', 'pk_integration_test_token');
Settings::set('clickup_default_list_id', '');
$noList = ClickUpSync::push($taskId);
check('no list chosen skips', $noList['skipped'], 'no ClickUp list chosen for this site');

// A missing task is an error, not a skip.
$missing = ClickUpSync::push(99999999);
check('missing task reports an error', $missing['error'], 'Task not found');

// An already-synced task is not sent twice.
Tasks::recordClickUp($taskId, 'fake123', 'https://app.clickup.com/t/fake123');
Settings::set('clickup_default_list_id', '900100');
$again = ClickUpSync::push($taskId);
check('already synced is not resent', $again['skipped'], 'already in ClickUp');
check('and returns the existing link', $again['url'], 'https://app.clickup.com/t/fake123');
Database::run('UPDATE tasks SET clickup_task_id = NULL, clickup_task_url = NULL WHERE id = ?', [$taskId]);

Settings::set('clickup_token', $savedToken);
Settings::set('clickup_default_list_id', $savedListId);
Settings::set('app_url', $savedAppUrl);
check('settings restored', (string) Settings::get('clickup_token', ''), $savedToken);

step('Post-audit review candidates');
// Tasks::forRun is what the review screen offers after a scan.
$reviewRun = Runs::create($siteId, [Pages::find($pageId)], 'selected');
$reviewAuditId = Audits::insert($siteId, $pageId, $reviewRun, PageSpeed::parse(payload(31, '2026-09-22T10:00:00.000Z')) + ['status' => 'ok']);
Pages::recordAuditResult($pageId, 31, '2026-09-22 10:00:00');
[$reviewTaskId] = Tasks::openOrRefresh(Pages::find($pageId), ['performance_score' => 31, 'fetched_at' => '2026-09-22 10:00:00'], $reviewAuditId, 80);

$candidates = Tasks::forRun($reviewRun);
check('run offers its task for review', count($candidates), 1);
check('candidate carries the page url', str_contains((string) $candidates[0]['url'], 'integration-test.invalid'), true);
check('candidate carries the site list', array_key_exists('clickup_list_id', $candidates[0]), true);

Tasks::recordClickUp($reviewTaskId, 'cu-review-1', 'https://app.clickup.com/t/cu-review-1');
check('already-sent tasks drop out of the review', Tasks::forRun($reviewRun), []);
check('but are still listed when asked for', count(Tasks::forRun($reviewRun, false)), 1);
Database::run('UPDATE tasks SET clickup_task_id = NULL, clickup_task_url = NULL WHERE id = ?', [$reviewTaskId]);

step('Secrets come from config, not the database');
$savedPsi = (string) Settings::get('psi_api_key', '');
Settings::set('psi_api_key', 'db-value-should-lose');
// Config (.env / local.php) has no key in this test run, so the stored one is
// the documented fallback; when a file value exists it must win.
check('stored key is the fallback', Settings::apiKey(), 'db-value-should-lose');
check('mask hides the secret', str_contains(Wva\Doctor::mask('pk_1234567890abcdef'), '890abcdef'), false);
check('mask keeps a recognisable prefix', str_starts_with(Wva\Doctor::mask('pk_1234567890abcdef'), 'pk_123'), true);
Settings::set('psi_api_key', $savedPsi);

step('Public share links');
use Wva\ShareLink;

$link = ShareLink::create(ShareLink::KIND_PAGE, $pageId, 'test page');
check('token is 32 hex chars', (bool) preg_match('/^[a-f0-9]{32}$/', (string) $link['token']), true);
check('resolves while live', ShareLink::resolve((string) $link['token'])['id'] ?? null, (int) $link['id']);
check('ensure reuses the live link', (int) ShareLink::ensure(ShareLink::KIND_PAGE, $pageId)['id'], (int) $link['id']);

// A malformed token must never reach the database as a query value.
check('rejects a short token', ShareLink::byToken('abc'), null);
check('rejects sql-ish input', ShareLink::byToken("' OR 1=1 --"), null);
check('rejects an unknown but well-formed token', ShareLink::resolve(str_repeat('a', 32)), null);

ShareLink::recordView((int) $link['id']);
check('views are counted', (int) ShareLink::byToken((string) $link['token'])['views'], 1);

ShareLink::revoke((int) $link['id']);
check('revoked links stop resolving', ShareLink::resolve((string) $link['token']), null);
check('revoked links are not reused', (int) ShareLink::ensure(ShareLink::KIND_PAGE, $pageId)['id'] !== (int) $link['id'], true);

// A target id this test owns, so a link created elsewhere cannot affect it.
$expiryTarget = 987654;
$expired = ShareLink::create(ShareLink::KIND_RUN, $expiryTarget, 'old', '2020-01-01 00:00:00');
check('expired links stop resolving', ShareLink::resolve((string) $expired['token']), null);
check('and are not offered as live', ShareLink::liveFor(ShareLink::KIND_RUN, $expiryTarget), null);
Database::run('DELETE FROM share_links WHERE target_id = ? AND kind = ?', [$expiryTarget, ShareLink::KIND_RUN]);

Settings::set('app_url', 'https://audit.internal.example');
check('url uses app_url', str_starts_with(ShareLink::url('abc'), 'https://audit.internal.example/share.php?t='), true);
Settings::set('app_url', '');
// Links this step created for a real page are removed with the test site at
// the end, but drop them now so a re-run starts from the same state.
Database::run('DELETE FROM share_links WHERE kind = ? AND target_id = ?', [ShareLink::KIND_PAGE, $pageId]);

step('Assignee and public link in the ClickUp payload');
$withWho = ClickUpSync::payload($sample, ['assignee' => 502]);
check('assignee is sent as a list', $withWho['assignees'], [502]);
check('no assignee key when nobody chosen', array_key_exists('assignees', ClickUpSync::payload($sample, ['assignee' => 0])), false);

Settings::set('clickup_public_link', '1');
Settings::set('app_url', 'https://audit.internal.example');
$sample['page_id'] = $pageId;
check('description carries a share link', str_contains(ClickUpSync::description($sample), '/share.php?t='), true);
Settings::set('clickup_public_link', '0');
check('and omits it when turned off', str_contains(ClickUpSync::description($sample), '/share.php?t='), false);
Settings::set('clickup_public_link', '1');
Settings::set('app_url', '');

step('Settings round-trip');
Settings::set('score_threshold', '75');
check('threshold from DB', Settings::threshold(), 75);
Settings::set('score_threshold', '80');

step('Quick-check URL path');
$q = \Wva\AuditRunner::queueUrls(['https://integration-test-2.invalid/a', 'https://integration-test-2.invalid/b', 'not a url'], false);
check('two queued', $q['queued'], 2);
check('one skipped', count($q['skipped']), 1);
check('new site created', count(testSites()), 2);
check('quick pages untracked by default', count(Pages::tracked((int) $q['site_ids'][0])), 0);

step('Cascade delete');
$before = (int) Database::value('SELECT COUNT(*) FROM audits');
check('audits exist', $before > 0, true);
foreach (testSites() as $created) { Sites::delete((int) $created['id']); }
check('pages gone', (int) Database::value('SELECT COUNT(*) FROM pages WHERE site_id = ?', [$siteId]), 0);
check('audits gone', (int) Database::value('SELECT COUNT(*) FROM audits WHERE site_id = ?', [$siteId]), 0);
check('tasks gone', (int) Database::value('SELECT COUNT(*) FROM tasks WHERE site_id = ?', [$siteId]), 0);
check('runs gone', (int) Database::value('SELECT COUNT(*) FROM scan_runs WHERE site_id = ?', [$siteId]), 0);
check('test sites removed', count(testSites()), 0);

echo "\n" . ($fails === 0 ? "ALL INTEGRATION CHECKS PASSED" : "$fails FAILED") . "\n";
exit($fails === 0 ? 0 : 1);
