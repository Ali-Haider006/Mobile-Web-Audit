<?php
declare(strict_types=1);

require __DIR__ . '/_init.php';
wva_require_schema();

use Wva\Helpers;
use Wva\Repo\Audits;
use Wva\Repo\Pages;
use Wva\Repo\Runs;
use Wva\Repo\Sites;
use Wva\Repo\Tasks;

$pageId = wva_int('id');
$page   = $pageId > 0 ? Pages::find($pageId) : null;
if ($page === null) {
    Helpers::flash('Page not found.', 'warn');
    Helpers::redirect('index.php');
}
$site      = Sites::find((int) $page['site_id']);
$threshold = $site ? Sites::threshold($site) : 80;

if (wva_is_post()) {
    Helpers::checkCsrf();
    $action = wva_str('action');
    if ($action === 'audit') {
        $runId = Runs::create((int) $page['site_id'], [$page], 'selected');
        Helpers::redirect('run.php?id=' . $runId);
    }
    if ($action === 'share') {
        Wva\ShareLink::ensure(Wva\ShareLink::KIND_PAGE, $pageId, (string) $page['url']);
        Helpers::flash('Public link ready — anyone with it can read this report.', 'ok');
        Helpers::redirect('page.php?id=' . $pageId);
    }
    if ($action === 'revoke_share') {
        Wva\ShareLink::revoke(wva_int('link_id'));
        Helpers::flash('Public link revoked. It now shows "link not available".', 'ok');
        Helpers::redirect('page.php?id=' . $pageId);
    }
    if ($action === 'toggle_tracking') {
        Pages::setTracked((int) $page['site_id'], [$pageId], (int) $page['is_tracked'] !== 1);
        Helpers::flash('Tracking updated.', 'ok');
        Helpers::redirect('page.php?id=' . $pageId);
    }
}

$shareLink = Wva\ShareLink::liveFor(Wva\ShareLink::KIND_PAGE, $pageId);
$history = Audits::historyForPage($pageId, 365);
$recent  = Audits::recentForPage($pageId, 25);
$latest  = Audits::latestForPage($pageId);
$tasks   = Tasks::forPage($pageId);

$labels = [];
$scores = [];
$lcp    = [];
$cls    = [];
$tbt    = [];
foreach ($history as $audit) {
    $labels[] = gmdate('j M', strtotime((string) $audit['fetched_at'] . ' UTC'));
    $scores[] = $audit['performance_score'] === null ? null : (int) $audit['performance_score'];
    $lcp[]    = $audit['lcp_ms'] === null ? null : round(((int) $audit['lcp_ms']) / 1000, 2);
    $cls[]    = $audit['cls'] === null ? null : (float) $audit['cls'];
    $tbt[]    = $audit['tbt_ms'] === null ? null : (int) $audit['tbt_ms'];
}

$first = $history[0]['performance_score'] ?? null;
$last  = $latest['performance_score'] ?? null;
$delta = $first !== null && $last !== null ? (int) $last - (int) $first : null;

$opportunities = [];
if ($latest && !empty($latest['opportunities'])) {
    $decoded = json_decode((string) $latest['opportunities'], true);
    if (is_array($decoded)) {
        $opportunities = $decoded;
    }
}

$title  = Helpers::pathOf((string) $page['url']);
$active = 'sites';
require WVA_ROOT . '/src/views/header.php';
?>
<h1><?= Helpers::h(Helpers::pathOf((string) $page['url'])) ?></h1>
<p class="sub">
    <a href="<?= Helpers::h($page['url']) ?>" target="_blank" rel="noopener"><?= Helpers::h($page['url']) ?></a>
    <?php if ($site): ?> · <a href="site.php?id=<?= (int) $site['id'] ?>"><?= Helpers::h($site['name']) ?></a><?php endif; ?>
    · <span class="pill <?= (int) $page['is_tracked'] === 1 ? 'tracked' : 'excluded' ?>">
        <?= (int) $page['is_tracked'] === 1 ? 'Tracked' : 'Excluded' ?></span>
</p>

<div class="card">
    <div class="actions">
        <form method="post" class="inline">
            <?= Helpers::csrfField() ?>
            <input type="hidden" name="action" value="audit">
            <input type="hidden" name="id" value="<?= $pageId ?>">
            <button class="btn primary" type="submit">Audit this page now</button>
        </form>
        <?php if ($shareLink === null): ?>
            <form method="post" class="inline">
                <?= Helpers::csrfField() ?>
                <input type="hidden" name="action" value="share">
                <input type="hidden" name="id" value="<?= $pageId ?>">
                <button class="btn" type="submit">Create a public link</button>
            </form>
        <?php endif; ?>
        <form method="post" class="inline">
            <?= Helpers::csrfField() ?>
            <input type="hidden" name="action" value="toggle_tracking">
            <input type="hidden" name="id" value="<?= $pageId ?>">
            <button class="btn" type="submit"><?= (int) $page['is_tracked'] === 1 ? 'Exclude from tracking' : 'Start tracking' ?></button>
        </form>
    </div>
</div>

<?php if ($shareLink !== null): ?>
    <div class="card">
        <div class="row">
            <div class="field" style="flex:1 1 420px">
                <label for="share-url">Public read-only link — no login needed</label>
                <input type="text" id="share-url" readonly value="<?= Helpers::h(Wva\ShareLink::url((string) $shareLink['token'])) ?>"
                       onclick="this.select()">
            </div>
            <div class="actions">
                <button class="btn small" type="button" id="copy-share">Copy</button>
                <form method="post" class="inline"
                      onsubmit="return confirm('Revoke this link? Anyone holding it loses access.');">
                    <?= Helpers::csrfField() ?>
                    <input type="hidden" name="action" value="revoke_share">
                    <input type="hidden" name="id" value="<?= $pageId ?>">
                    <input type="hidden" name="link_id" value="<?= (int) $shareLink['id'] ?>">
                    <button class="btn small danger" type="submit">Revoke</button>
                </form>
            </div>
        </div>
        <div class="small muted" style="margin-top:8px">
            Created <?= Helpers::h(Helpers::ago((string) $shareLink['created_at'])) ?> ·
            viewed <?= (int) $shareLink['views'] ?> time(s)<?= $shareLink['last_viewed_at'] ? ', last ' . Helpers::h(Helpers::ago((string) $shareLink['last_viewed_at'])) : '' ?>.
            Anyone with the link can read this page's report — it is marked noindex, but treat it as public.
        </div>
    </div>
    <script>
    document.getElementById('copy-share').addEventListener('click', function () {
        var field = document.getElementById('share-url');
        field.select();
        try { navigator.clipboard.writeText(field.value); } catch (e) { document.execCommand('copy'); }
        this.textContent = 'Copied';
        setTimeout(function () { document.getElementById('copy-share').textContent = 'Copy'; }, 1500);
    });
    </script>
<?php endif; ?>

<div class="grid cols-4">
    <div class="card tile">
        <div class="label">Mobile performance</div>
        <div class="value hero"><?= $last === null ? '—' : (int) $last ?></div>
        <div class="delta <?= $delta === null ? '' : ($delta >= 0 ? 'up' : 'down') ?>">
            <?= $delta === null ? 'first audit' : (($delta > 0 ? '+' . $delta : $delta) . ' since ' . gmdate('j M Y', strtotime((string) $history[0]['fetched_at'] . ' UTC'))) ?>
        </div>
    </div>
    <div class="card tile">
        <div class="label">LCP (lab)</div>
        <div class="value"><?= Helpers::h(Helpers::ms($latest && $latest['lcp_ms'] !== null ? (int) $latest['lcp_ms'] : null)) ?></div>
        <div class="delta">good: under 2.5 s</div>
    </div>
    <div class="card tile">
        <div class="label">CLS (lab)</div>
        <div class="value"><?= Helpers::h(Helpers::cls($latest['cls'] ?? null)) ?></div>
        <div class="delta">good: under 0.1</div>
    </div>
    <div class="card tile">
        <div class="label">TBT (lab)</div>
        <div class="value"><?= Helpers::h(Helpers::ms($latest && $latest['tbt_ms'] !== null ? (int) $latest['tbt_ms'] : null)) ?></div>
        <div class="delta">proxy for INP</div>
    </div>
</div>

<?php if ($latest && ($latest['field_lcp_ms'] !== null || $latest['field_inp_ms'] !== null)): ?>
<div class="card tight small">
    <strong>Field data (real Chrome users, 28 days):</strong>
    LCP <?= Helpers::h(Helpers::ms((int) $latest['field_lcp_ms'])) ?> ·
    CLS <?= Helpers::h(Helpers::cls($latest['field_cls'] ?? null)) ?> ·
    INP <?= Helpers::h(Helpers::ms($latest['field_inp_ms'] === null ? null : (int) $latest['field_inp_ms'])) ?> ·
    verdict <?= Helpers::h(str_replace('_', ' ', strtolower((string) ($latest['field_verdict'] ?? 'n/a')))) ?>
</div>
<?php endif; ?>

<?php if (count($history) > 1): ?>
<h2>History</h2>
<div class="grid cols-2">
    <div class="card">
        <h3>Mobile performance score</h3>
        <div class="chart-legend"><span class="key"><span class="swatch"></span> Score, 0–100</span>
            <span class="key muted">hairline = target <?= $threshold ?></span></div>
        <div class="chart-box" id="chart-score"></div>
    </div>
    <div class="card">
        <h3>Largest Contentful Paint</h3>
        <div class="chart-legend"><span class="key"><span class="swatch"></span> Seconds</span>
            <span class="key muted">hairline = 2.5 s budget</span></div>
        <div class="chart-box" id="chart-lcp"></div>
    </div>
    <div class="card">
        <h3>Cumulative Layout Shift</h3>
        <div class="chart-legend"><span class="key"><span class="swatch"></span> CLS</span>
            <span class="key muted">hairline = 0.1 budget</span></div>
        <div class="chart-box short" id="chart-cls"></div>
    </div>
    <div class="card">
        <h3>Total Blocking Time</h3>
        <div class="chart-legend"><span class="key"><span class="swatch"></span> Milliseconds</span>
            <span class="key muted">hairline = 200 ms budget</span></div>
        <div class="chart-box short" id="chart-tbt"></div>
    </div>
</div>
<?php elseif ($history): ?>
    <div class="card"><div class="empty">One audit so far — the trend graphs appear from the second audit on.</div></div>
<?php endif; ?>

<?php if ($opportunities): ?>
<h2>Biggest wins on the last audit</h2>
<div class="card">
    <div class="table-wrap">
    <table>
        <thead><tr><th>Opportunity</th><th>Detail</th><th class="num">Est. saving</th></tr></thead>
        <tbody>
        <?php foreach ($opportunities as $item): ?>
            <tr>
                <td><?= Helpers::h($item['title'] ?? '') ?></td>
                <td class="small muted"><?= Helpers::h($item['display'] ?? '') ?></td>
                <td class="num"><?= (int) ($item['savings_ms'] ?? 0) > 0 ? Helpers::h(Helpers::ms((int) $item['savings_ms'])) : '—' ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>
<?php endif; ?>

<h2>Audit log</h2>
<div class="card">
    <?php if (!$recent): ?>
        <div class="empty">No audits yet.</div>
    <?php else: ?>
    <div class="table-wrap">
    <table>
        <thead><tr>
            <th>When (UTC)</th><th class="num">Score</th><th class="num">LCP</th><th class="num">CLS</th>
            <th class="num">TBT</th><th class="num">FCP</th><th class="num">TTFB</th><th class="num">SEO</th><th>Result</th>
        </tr></thead>
        <tbody>
        <?php foreach ($recent as $audit): ?>
            <tr>
                <td class="small"><?= Helpers::h(gmdate('Y-m-d H:i', strtotime((string) $audit['fetched_at'] . ' UTC'))) ?></td>
                <td class="num"><?php $score = $audit['performance_score'] === null ? null : (int) $audit['performance_score'];
                    require WVA_ROOT . '/src/views/score.php'; ?></td>
                <td class="num small"><?= Helpers::h(Helpers::ms($audit['lcp_ms'] === null ? null : (int) $audit['lcp_ms'])) ?></td>
                <td class="num small"><?= Helpers::h(Helpers::cls($audit['cls'] ?? null)) ?></td>
                <td class="num small"><?= Helpers::h(Helpers::ms($audit['tbt_ms'] === null ? null : (int) $audit['tbt_ms'])) ?></td>
                <td class="num small"><?= Helpers::h(Helpers::ms($audit['fcp_ms'] === null ? null : (int) $audit['fcp_ms'])) ?></td>
                <td class="num small"><?= Helpers::h(Helpers::ms($audit['ttfb_ms'] === null ? null : (int) $audit['ttfb_ms'])) ?></td>
                <td class="num small"><?= $audit['seo_score'] === null ? '—' : (int) $audit['seo_score'] ?></td>
                <td class="small <?= $audit['status'] === 'error' ? 'muted' : '' ?>">
                    <?= $audit['status'] === 'error' ? Helpers::h((string) $audit['error_message']) : 'OK' ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</div>

<?php if ($tasks): ?>
<h2>Tasks for this page</h2>
<div class="card">
    <div class="table-wrap">
    <table>
        <thead><tr><th>Task</th><th>Status</th><th>Priority</th><th class="num">Score</th><th>Opened</th></tr></thead>
        <tbody>
        <?php foreach ($tasks as $task): ?>
            <tr>
                <td><a href="tasks.php?status=all#task-<?= (int) $task['id'] ?>"><?= Helpers::h($task['title']) ?></a></td>
                <td><span class="pill <?= Helpers::h($task['status']) ?>"><?= Helpers::h(str_replace('_', ' ', (string) $task['status'])) ?></span></td>
                <td><span class="pill <?= Helpers::h($task['priority']) ?>"><?= Helpers::h(ucfirst((string) $task['priority'])) ?></span></td>
                <td class="num"><?= $task['latest_score'] === null ? '—' : (int) $task['latest_score'] ?></td>
                <td class="small muted"><?= Helpers::h(Helpers::ago($task['opened_at'])) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>
<?php endif; ?>

<?php if (count($history) > 1): ?>
<script src="assets/charts.js?v=1"></script>
<script>
var labels = <?= json_encode($labels) ?>;
wvaLineChart({
    el: 'chart-score', label: 'Score', labels: labels,
    values: <?= json_encode($scores) ?>,
    min: 0, max: 100, threshold: <?= $threshold ?>, thresholdLabel: 'target <?= $threshold ?>'
});
wvaLineChart({
    el: 'chart-lcp', label: 'LCP', labels: labels,
    values: <?= json_encode($lcp) ?>,
    suffix: ' s', decimals: 2, threshold: 2.5, thresholdLabel: '2.5 s budget'
});
wvaLineChart({
    el: 'chart-cls', label: 'CLS', labels: labels,
    values: <?= json_encode($cls) ?>,
    decimals: 3, threshold: 0.1, thresholdLabel: '0.1 budget'
});
wvaLineChart({
    el: 'chart-tbt', label: 'TBT', labels: labels,
    values: <?= json_encode($tbt) ?>,
    suffix: ' ms', threshold: 200, thresholdLabel: '200 ms budget'
});
</script>
<?php endif; ?>
<?php require WVA_ROOT . '/src/views/footer.php'; ?>
