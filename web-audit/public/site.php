<?php
declare(strict_types=1);

require __DIR__ . '/_init.php';
wva_require_schema();

use Wva\AuditRunner;
use Wva\Helpers;
use Wva\Repo\Pages;
use Wva\Repo\Runs;
use Wva\Repo\Sites;
use Wva\Settings;

$siteId = wva_int('id') ?: wva_int('site_id');
$site   = $siteId > 0 ? Sites::find($siteId) : null;
if ($site === null) {
    Helpers::flash('Site not found.', 'warn');
    Helpers::redirect('sites.php');
}
$threshold = Sites::threshold($site);

if (wva_is_post()) {
    Helpers::checkCsrf();
    $action  = wva_str('action');
    $pageIds = array_map('intval', (array) ($_POST['page_ids'] ?? []));
    $csv     = wva_str('page_ids_csv');
    if ($csv !== '') {
        $pageIds = array_map('intval', array_filter(explode(',', $csv), 'is_numeric'));
    }

    try {
        switch ($action) {
            case 'track':
                $n = Pages::setTracked($siteId, $pageIds, true);
                Helpers::flash($n . ' page(s) now tracked.', 'ok');
                break;

            case 'exclude':
                $n = Pages::setTracked($siteId, $pageIds, false);
                Helpers::flash($n . ' page(s) excluded. They keep their history but are never re-audited.', 'ok');
                break;

            case 'delete':
                $n = Pages::delete($siteId, $pageIds);
                Helpers::flash($n . ' page(s) deleted with their history.', 'ok');
                break;

            case 'scan_all':
                $queued = AuditRunner::queueSite($siteId, 'full');
                if ($queued['run_id'] === 0) {
                    Helpers::flash('Nothing to scan — no tracked pages on this site.', 'warn');
                    break;
                }
                Helpers::redirect('run.php?id=' . $queued['run_id']);

            case 'scan_selected':
                $pages = [];
                foreach ($pageIds as $pageId) {
                    $page = Pages::find($pageId);
                    if ($page !== null && (int) $page['site_id'] === $siteId) {
                        $pages[] = $page;
                    }
                }
                $runId = Runs::create($siteId, $pages, 'selected');
                if ($runId === 0) {
                    Helpers::flash('Tick at least one page first.', 'warn');
                    break;
                }
                Helpers::redirect('run.php?id=' . $runId);

            case 'save_site':
                $thresholdInput = wva_str('threshold');
                Sites::update(
                    $siteId,
                    wva_str('name', (string) $site['name']),
                    $thresholdInput === '' ? null : max(1, min(100, (int) $thresholdInput)),
                    wva_str('sitemap_url') ?: null,
                    wva_str('is_active') !== '',
                    wva_str('clickup_list_id') ?: null
                );
                Helpers::flash('Site settings saved.', 'ok');
                break;
        }
    } catch (Throwable $e) {
        Helpers::flash($e->getMessage(), 'error');
    }

    Helpers::redirect('site.php?id=' . $siteId . '&' . http_build_query([
        'tracked' => wva_str('tracked', 'all'),
        'band'    => wva_str('band', 'all'),
        'q'       => wva_str('q'),
        'sort'    => wva_str('sort', 'score'),
    ]));
}

$filters = [
    'tracked'   => wva_str('tracked', 'all'),
    'band'      => wva_str('band', 'all'),
    'q'         => wva_str('q'),
    'sort'      => wva_str('sort', 'score'),
    'threshold' => $threshold,
];
$pages   = Pages::forSite($siteId, $filters);
$latestByPage = \Wva\Repo\Audits::latestForPages(array_map(static fn (array $p): int => (int) $p['id'], $pages));
$trend   = Sites::scoreTrend($siteId, 90);
$runs    = Runs::recent(5, $siteId);
$summary = [
    'tracked'  => 0,
    'failing'  => 0,
    'passing'  => 0,
    'unaudited' => 0,
];
foreach (Pages::tracked($siteId) as $trackedPage) {
    $summary['tracked']++;
    if ($trackedPage['last_score'] === null) {
        $summary['unaudited']++;
    } elseif ((int) $trackedPage['last_score'] < $threshold) {
        $summary['failing']++;
    } else {
        $summary['passing']++;
    }
}

$clickUpLists = Wva\ClickUp::cachedLists();
$clickUpDefaultDefault = (string) Settings::get('clickup_default_list_id', '');
$clickUpDefaultName = $clickUpDefaultDefault !== '' ? Wva\ClickUp::listName($clickUpDefaultDefault) : '';

$title  = (string) $site['name'];
$active = 'sites';
require WVA_ROOT . '/src/views/header.php';
?>
<h1><?= Helpers::h($site['name']) ?></h1>
<p class="sub"><a href="<?= Helpers::h($site['url']) ?>" target="_blank" rel="noopener"><?= Helpers::h($site['url']) ?></a>
    · target score <?= $threshold ?> · <a href="import.php?site_id=<?= $siteId ?>">import sitemap</a></p>

<div class="grid cols-4">
    <div class="card tile">
        <div class="label">Tracked pages</div>
        <div class="value"><?= $summary['tracked'] ?></div>
    </div>
    <div class="card tile">
        <div class="label">At or above <?= $threshold ?></div>
        <div class="value"><?= $summary['passing'] ?></div>
    </div>
    <div class="card tile">
        <div class="label">Below <?= $threshold ?></div>
        <div class="value"><?= $summary['failing'] ?></div>
        <div class="delta">tasks opened automatically</div>
    </div>
    <div class="card tile">
        <div class="label">Never audited</div>
        <div class="value"><?= $summary['unaudited'] ?></div>
    </div>
</div>

<div class="card">
    <div class="actions">
        <form method="post" class="inline">
            <?= Helpers::csrfField() ?>
            <input type="hidden" name="action" value="scan_all">
            <input type="hidden" name="id" value="<?= $siteId ?>">
            <button class="btn primary" type="submit">Scan all tracked pages (mobile)</button>
        </form>
        <span class="small muted">Each URL takes 10–40 s through the PageSpeed API.</span>
    </div>
</div>

<?php if (count($trend) > 1): ?>
<h2>Average mobile score over time</h2>
<div class="card">
    <div class="chart-legend">
        <span class="key"><span class="swatch"></span> Average performance score of tracked pages</span>
        <span class="key muted">Hairline at <?= $threshold ?> = pass mark</span>
    </div>
    <div class="chart-box" id="site-trend"></div>
</div>
<?php endif; ?>

<h2>Pages</h2>
<div class="card">
    <form method="get" class="row" style="margin-bottom:16px">
        <input type="hidden" name="id" value="<?= $siteId ?>">
        <div class="field">
            <label for="q">Search URL</label>
            <input type="text" id="q" name="q" value="<?= Helpers::h($filters['q']) ?>" placeholder="/blog/">
        </div>
        <div class="field">
            <label for="tracked">Tracking</label>
            <select id="tracked" name="tracked">
                <?php foreach (['all' => 'All pages', 'tracked' => 'Tracked only', 'excluded' => 'Excluded only'] as $key => $label): ?>
                    <option value="<?= $key ?>" <?= $filters['tracked'] === $key ? 'selected' : '' ?>><?= $label ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label for="band">Score</label>
            <select id="band" name="band">
                <?php foreach ([
                    'all'       => 'Any score',
                    'failing'   => 'Below target',
                    'passing'   => 'At or above target',
                    'unaudited' => 'Never audited',
                ] as $key => $label): ?>
                    <option value="<?= $key ?>" <?= $filters['band'] === $key ? 'selected' : '' ?>><?= $label ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label for="sort">Sort</label>
            <select id="sort" name="sort">
                <?php foreach ([
                    'score'  => 'Worst score first',
                    'url'    => 'URL',
                    'recent' => 'Most recently audited',
                    'change' => 'Biggest drop',
                ] as $key => $label): ?>
                    <option value="<?= $key ?>" <?= $filters['sort'] === $key ? 'selected' : '' ?>><?= $label ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div><button class="btn" type="submit">Apply</button></div>
    </form>

    <?php if (!$pages): ?>
        <div class="empty">No pages match. <a href="import.php?site_id=<?= $siteId ?>">Import the sitemap</a> to get started.</div>
    <?php else: ?>
    <form method="post" id="pages-form">
        <?= Helpers::csrfField() ?>
        <input type="hidden" name="id" value="<?= $siteId ?>">
        <input type="hidden" name="page_ids_csv" id="page_ids_csv" value="">
        <input type="hidden" name="tracked" value="<?= Helpers::h($filters['tracked']) ?>">
        <input type="hidden" name="band" value="<?= Helpers::h($filters['band']) ?>">
        <input type="hidden" name="q" value="<?= Helpers::h($filters['q']) ?>">
        <input type="hidden" name="sort" value="<?= Helpers::h($filters['sort']) ?>">

        <div class="table-wrap">
        <table id="pages-table">
            <thead><tr>
                <th class="checkbox-cell"><input type="checkbox" id="check-all"></th>
                <th>Page</th>
                <th class="num">Score</th>
                <th class="num">Change</th>
                <th class="num">LCP</th>
                <th class="num">CLS</th>
                <th>Last audit</th>
                <th>Tracking</th>
            </tr></thead>
            <tbody>
            <?php foreach ($pages as $page): ?>
                <?php
                $score    = $page['last_score'] === null ? null : (int) $page['last_score'];
                $previous = $page['previous_score'] === null ? null : (int) $page['previous_score'];
                $delta    = $score !== null && $previous !== null ? $score - $previous : null;
                $latest   = $latestByPage[(int) $page['id']] ?? null;
                ?>
                <tr>
                    <td class="checkbox-cell">
                        <input type="checkbox" name="page_ids[]" value="<?= (int) $page['id'] ?>" data-id="<?= (int) $page['id'] ?>">
                    </td>
                    <td class="url-cell"><a href="page.php?id=<?= (int) $page['id'] ?>"><?= Helpers::h($page['path']) ?></a></td>
                    <td class="num"><?php require WVA_ROOT . '/src/views/score.php'; ?></td>
                    <td class="num <?= $delta === null ? '' : ($delta >= 0 ? 'delta up' : 'delta down') ?>">
                        <?= $delta === null ? '—' : ($delta > 0 ? '+' . $delta : $delta) ?>
                    </td>
                    <td class="num small"><?= Helpers::h(Helpers::ms($latest && $latest['lcp_ms'] !== null ? (int) $latest['lcp_ms'] : null)) ?></td>
                    <td class="num small"><?= Helpers::h(Helpers::cls($latest['cls'] ?? null)) ?></td>
                    <td class="small muted"><?= Helpers::h(Helpers::ago($page['last_audit_at'])) ?></td>
                    <td><span class="pill <?= (int) $page['is_tracked'] === 1 ? 'tracked' : 'excluded' ?>">
                        <?= (int) $page['is_tracked'] === 1 ? 'Tracked' : 'Excluded' ?></span></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>

        <div class="sticky-bar">
            <span class="small muted"><span id="sel-count">0</span> selected</span>
            <button class="btn small" type="submit" name="action" value="track" data-action>Track</button>
            <button class="btn small" type="submit" name="action" value="exclude" data-action>Exclude</button>
            <button class="btn small primary" type="submit" name="action" value="scan_selected" data-action>Scan selected</button>
            <button class="btn small danger" type="submit" name="action" value="delete" data-action
                    data-confirm="Delete the selected pages and their history?">Delete</button>
            <span class="small muted">Showing <?= count($pages) ?> page(s)</span>
        </div>
    </form>
    <script>
    (function () {
        var form = document.getElementById('pages-form');
        var boxes = Array.prototype.slice.call(document.querySelectorAll('#pages-table tbody input[type=checkbox]'));
        var count = document.getElementById('sel-count');

        function refresh() { count.textContent = boxes.filter(function (b) { return b.checked; }).length; }
        document.getElementById('pages-table').addEventListener('change', refresh);
        document.getElementById('check-all').addEventListener('change', function () {
            boxes.forEach(function (b) { b.checked = this.checked; }, this);
            refresh();
        });

        form.querySelectorAll('button[data-action]').forEach(function (btn) {
            btn.addEventListener('click', function (event) {
                var picked = boxes.filter(function (b) { return b.checked; });
                if (!picked.length) {
                    event.preventDefault();
                    alert('Tick at least one page first.');
                    return;
                }
                if (btn.dataset.confirm && !confirm(btn.dataset.confirm)) {
                    event.preventDefault();
                    return;
                }
                document.getElementById('page_ids_csv').value = picked.map(function (b) { return b.dataset.id; }).join(',');
                boxes.forEach(function (b) { b.removeAttribute('name'); });
            });
        });
        refresh();
    })();
    </script>
    <?php endif; ?>
</div>

<?php if ($runs): ?>
<h2>Recent scans</h2>
<div class="card">
    <div class="table-wrap">
    <table>
        <thead><tr><th>Started</th><th>Type</th><th>Status</th><th class="num">Done</th><th class="num">Failed</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($runs as $run): ?>
            <tr>
                <td class="small"><?= Helpers::h(Helpers::ago($run['created_at'])) ?></td>
                <td class="small"><?= Helpers::h(ucfirst((string) $run['type'])) ?></td>
                <td class="small"><?= Helpers::h(ucfirst((string) $run['status'])) ?></td>
                <td class="num"><?= (int) $run['done_items'] ?>/<?= (int) $run['total_items'] ?></td>
                <td class="num"><?= (int) $run['failed_items'] ?></td>
                <td class="num"><a class="btn small" href="run.php?id=<?= (int) $run['id'] ?>">View</a></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>
<?php endif; ?>

<h2>Site settings</h2>
<div class="card">
    <form method="post">
        <?= Helpers::csrfField() ?>
        <input type="hidden" name="action" value="save_site">
        <input type="hidden" name="id" value="<?= $siteId ?>">
        <div class="row">
            <div class="field">
                <label for="name">Name</label>
                <input type="text" id="name" name="name" value="<?= Helpers::h($site['name']) ?>">
            </div>
            <div class="field">
                <label for="threshold">Target score (blank = global <?= Settings::threshold() ?>)</label>
                <input type="number" id="threshold" name="threshold" min="1" max="100"
                       value="<?= $site['score_threshold'] === null ? '' : (int) $site['score_threshold'] ?>">
            </div>
            <div class="field">
                <label for="site_sitemap">Sitemap URL</label>
                <input type="text" id="site_sitemap" name="sitemap_url" value="<?= Helpers::h($site['sitemap_url'] ?? '') ?>">
            </div>
            <div class="field">
                <label for="clickup_list_id">ClickUp list for this site's tasks</label>
                <select id="clickup_list_id" name="clickup_list_id">
                    <option value="">Use the default<?= $clickUpDefaultName !== '' ? ' (' . Helpers::h($clickUpDefaultName) . ')' : ' — none set' ?></option>
                    <?php foreach ($clickUpLists as $list): ?>
                        <option value="<?= Helpers::h($list['id']) ?>"
                            <?= (string) ($site['clickup_list_id'] ?? '') === (string) $list['id'] ? 'selected' : '' ?>>
                            <?= Helpers::h($list['path']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label for="is_active">Scheduled scans</label>
                <select id="is_active" name="is_active">
                    <option value="1" <?= (int) $site['is_active'] === 1 ? 'selected' : '' ?>>Included in cron scans</option>
                    <option value="" <?= (int) $site['is_active'] === 1 ? '' : 'selected' ?>>Paused</option>
                </select>
            </div>
            <div><button class="btn" type="submit">Save</button></div>
        </div>
    </form>
</div>

<?php if (count($trend) > 1): ?>
<script src="assets/charts.js?v=1"></script>
<script>
wvaLineChart({
    el: 'site-trend',
    label: 'Average score',
    labels: <?= json_encode(array_map(static fn ($r) => gmdate('j M', strtotime((string) $r['day'] . ' UTC')), $trend)) ?>,
    values: <?= json_encode(array_map(static fn ($r) => (float) $r['avg_score'], $trend)) ?>,
    min: 0, max: 100,
    threshold: <?= $threshold ?>,
    thresholdLabel: 'target <?= $threshold ?>'
});
</script>
<?php endif; ?>
<?php require WVA_ROOT . '/src/views/footer.php'; ?>
