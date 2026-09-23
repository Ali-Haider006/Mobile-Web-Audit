<?php
declare(strict_types=1);

/**
 * Public, read-only report behind an unguessable token.
 *
 * No login: the whole point is handing a developer or a client the audit
 * without an account. So it shows one page's (or one scan's) results and
 * nothing else - no navigation into the rest of the tool, no site list, no
 * settings. Search engines are told to stay away, since this is client data.
 */

define('WVA_PUBLIC_PAGE', true);
require __DIR__ . '/_init.php';
wva_require_schema();

use Wva\Helpers;
use Wva\Repo\Audits;
use Wva\Repo\Pages;
use Wva\Repo\Runs;
use Wva\Repo\Sites;
use Wva\ShareLink;

header('X-Robots-Tag: noindex, nofollow, noarchive');

$link = ShareLink::resolve(wva_str('t'));

if ($link === null) {
    http_response_code(404);
    $notFound = true;
} else {
    $notFound = false;
    ShareLink::recordView((int) $link['id']);
}

$kind     = $link['kind'] ?? '';
$targetId = (int) ($link['target_id'] ?? 0);

$page = $kind === ShareLink::KIND_PAGE ? Pages::find($targetId) : null;
$run  = $kind === ShareLink::KIND_RUN ? Runs::find($targetId) : null;
$site = $page !== null ? Sites::find((int) $page['site_id'])
    : ($run !== null ? Sites::find((int) $run['site_id']) : null);

if (!$notFound && $page === null && $run === null) {
    http_response_code(404);
    $notFound = true;
}

$threshold = $site !== null ? Sites::threshold($site) : 80;
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow, noarchive">
<title><?= $notFound ? 'Link not available' : 'Mobile audit · ' . Helpers::h($site['name'] ?? '') ?></title>
<script>
(function () {
    try {
        var saved = localStorage.getItem('wva-theme');
        if (saved === 'dark' || saved === 'light') { document.documentElement.setAttribute('data-theme', saved); }
    } catch (e) {}
})();
</script>
<link rel="stylesheet" href="assets/theme.css?v=1">
<link rel="stylesheet" href="assets/app.css?v=3">
</head>
<body>
<header class="masthead">
    <div class="inner">
        <span class="brand">Mobile Web Audit <span>· Core Web Vitals</span></span>
        <span class="spacer"></span>
        <span class="small muted">Shared report · read only</span>
    </div>
</header>
<main>

<?php if ($notFound): ?>
    <h1>Link not available</h1>
    <p class="sub">This share link has been revoked, has expired, or never existed.
        Ask whoever sent it for a new one.</p>
<?php elseif ($page !== null): ?>
    <?php
    $history = Audits::historyForPage((int) $page['id'], 365);
    $latest  = Audits::latestForPage((int) $page['id']);
    $score   = $latest['performance_score'] ?? null;
    $first   = $history[0]['performance_score'] ?? null;
    $delta   = $first !== null && $score !== null ? (int) $score - (int) $first : null;

    $labels = $scores = $lcp = $cls = $tbt = [];
    foreach ($history as $audit) {
        $labels[] = gmdate('j M', strtotime((string) $audit['fetched_at'] . ' UTC'));
        $scores[] = $audit['performance_score'] === null ? null : (int) $audit['performance_score'];
        $lcp[]    = $audit['lcp_ms'] === null ? null : round(((int) $audit['lcp_ms']) / 1000, 2);
        $cls[]    = $audit['cls'] === null ? null : (float) $audit['cls'];
        $tbt[]    = $audit['tbt_ms'] === null ? null : (int) $audit['tbt_ms'];
    }

    $opportunities = [];
    if ($latest && !empty($latest['opportunities'])) {
        $decoded = json_decode((string) $latest['opportunities'], true);
        $opportunities = is_array($decoded) ? $decoded : [];
    }
    ?>
    <h1><?= Helpers::h(Helpers::pathOf((string) $page['url'])) ?></h1>
    <p class="sub">
        <?= Helpers::h($site['name'] ?? '') ?> ·
        <a href="<?= Helpers::h($page['url']) ?>" target="_blank" rel="noopener nofollow"><?= Helpers::h($page['url']) ?></a>
        · mobile · target <?= $threshold ?>
    </p>

    <div class="grid cols-4">
        <div class="card tile">
            <div class="label">Mobile performance</div>
            <div class="value hero"><?= $score === null ? '—' : (int) $score ?></div>
            <div class="delta <?= $delta === null ? '' : ($delta >= 0 ? 'up' : 'down') ?>">
                <?= $delta === null ? 'first audit' : (($delta > 0 ? '+' . $delta : $delta) . ' since the first audit') ?>
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
                <div class="chart-legend"><span class="key"><span class="swatch"></span> CLS</span></div>
                <div class="chart-box short" id="chart-cls"></div>
            </div>
            <div class="card">
                <h3>Total Blocking Time</h3>
                <div class="chart-legend"><span class="key"><span class="swatch"></span> Milliseconds</span></div>
                <div class="chart-box short" id="chart-tbt"></div>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($opportunities): ?>
        <h2>Biggest wins on the last audit</h2>
        <div class="card"><div class="table-wrap">
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
        </div></div>
    <?php endif; ?>

    <h2>Audit log</h2>
    <div class="card"><div class="table-wrap">
        <table>
            <thead><tr><th>When (UTC)</th><th class="num">Score</th><th class="num">LCP</th><th class="num">CLS</th><th class="num">TBT</th></tr></thead>
            <tbody>
            <?php foreach (array_reverse($history) as $audit): ?>
                <tr>
                    <td class="small"><?= Helpers::h(gmdate('Y-m-d H:i', strtotime((string) $audit['fetched_at'] . ' UTC'))) ?></td>
                    <td class="num"><?php $score = $audit['performance_score'] === null ? null : (int) $audit['performance_score'];
                        require WVA_ROOT . '/src/views/score.php'; ?></td>
                    <td class="num small"><?= Helpers::h(Helpers::ms($audit['lcp_ms'] === null ? null : (int) $audit['lcp_ms'])) ?></td>
                    <td class="num small"><?= Helpers::h(Helpers::cls($audit['cls'] ?? null)) ?></td>
                    <td class="num small"><?= Helpers::h(Helpers::ms($audit['tbt_ms'] === null ? null : (int) $audit['tbt_ms'])) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div></div>

    <?php if (count($history) > 1): ?>
    <script src="assets/charts.js?v=2"></script>
    <script>
    var labels = <?= json_encode($labels) ?>;
    wvaLineChart({ el: 'chart-score', label: 'Score', labels: labels, values: <?= json_encode($scores) ?>,
        min: 0, max: 100, threshold: <?= $threshold ?>, thresholdLabel: 'target <?= $threshold ?>' });
    wvaLineChart({ el: 'chart-lcp', label: 'LCP', labels: labels, values: <?= json_encode($lcp) ?>,
        suffix: ' s', decimals: 2, threshold: 2.5, thresholdLabel: '2.5 s budget' });
    wvaLineChart({ el: 'chart-cls', label: 'CLS', labels: labels, values: <?= json_encode($cls) ?>,
        decimals: 3, threshold: 0.1, thresholdLabel: '0.1 budget' });
    wvaLineChart({ el: 'chart-tbt', label: 'TBT', labels: labels, values: <?= json_encode($tbt) ?>,
        suffix: ' ms', threshold: 200, thresholdLabel: '200 ms budget' });
    </script>
    <?php endif; ?>

<?php else: ?>
    <?php $results = Audits::forRun((int) $run['id']); ?>
    <h1>Scan of <?= Helpers::h($site['name'] ?? '') ?></h1>
    <p class="sub">Mobile · <?= Helpers::h(gmdate('j M Y', strtotime((string) $run['created_at'] . ' UTC'))) ?>
        · target <?= $threshold ?> · <?= count($results) ?> page(s)</p>

    <div class="card"><div class="table-wrap">
        <table>
            <thead><tr><th>Page</th><th class="num">Score</th><th class="num">LCP</th><th class="num">CLS</th><th class="num">TBT</th></tr></thead>
            <tbody>
            <?php foreach ($results as $audit): ?>
                <tr>
                    <td class="url-cell"><?= Helpers::h($audit['path']) ?></td>
                    <td class="num"><?php $score = $audit['performance_score'] === null ? null : (int) $audit['performance_score'];
                        require WVA_ROOT . '/src/views/score.php'; ?></td>
                    <td class="num small"><?= Helpers::h(Helpers::ms($audit['lcp_ms'] === null ? null : (int) $audit['lcp_ms'])) ?></td>
                    <td class="num small"><?= Helpers::h(Helpers::cls($audit['cls'] ?? null)) ?></td>
                    <td class="num small"><?= Helpers::h(Helpers::ms($audit['tbt_ms'] === null ? null : (int) $audit['tbt_ms'])) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div></div>
<?php endif; ?>

<p class="small muted" style="margin-top:32px">
    Scores come from Google PageSpeed Insights, mobile strategy.
    <?php if (!$notFound): ?>Shared <?= Helpers::h(Helpers::ago((string) $link['created_at'])) ?>.<?php endif; ?>
</p>
</main>
</body>
</html>
