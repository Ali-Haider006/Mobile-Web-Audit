<?php
declare(strict_types=1);

require __DIR__ . '/_init.php';
wva_require_schema();

use Wva\Database;
use Wva\Helpers;
use Wva\Repo\Audits;
use Wva\Repo\Runs;
use Wva\Repo\Sites;
use Wva\Repo\Tasks;
use Wva\Settings;

$threshold = Settings::threshold();
$totals    = Audits::dashboardTotals($threshold);
$sites     = Sites::all();
$openTasks = Tasks::search(['status' => 'open']);
$running   = Runs::unfinished();

$worstPages = Database::all(
    'SELECT p.*, s.name AS site_name
     FROM pages p INNER JOIN sites s ON s.id = p.site_id
     WHERE p.is_tracked = 1 AND p.last_score IS NOT NULL AND p.last_score < ?
     ORDER BY p.last_score ASC LIMIT 10',
    [$threshold]
);

$title  = 'Dashboard';
$active = 'dashboard';
require WVA_ROOT . '/src/views/header.php';
?>
<h1>Dashboard</h1>
<p class="sub">Mobile Core Web Vitals across every tracked page. Pass mark: <strong><?= $threshold ?></strong>.</p>

<?php if ($running): ?>
    <div class="flash warn">
        <?= count($running) ?> scan<?= count($running) === 1 ? '' : 's' ?> still in progress —
        <?php foreach ($running as $run): ?>
            <a href="run.php?id=<?= (int) $run['id'] ?>"><?= Helpers::h($run['site_name']) ?>
                (<?= (int) $run['done_items'] + (int) $run['failed_items'] ?>/<?= (int) $run['total_items'] ?>)</a>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="grid cols-4">
    <div class="card tile">
        <div class="label">Average mobile score</div>
        <div class="value hero"><?= $totals['avg_score'] === null ? '—' : (int) $totals['avg_score'] ?></div>
        <div class="delta">across <?= (int) ($totals['audited_pages'] ?? 0) ?> audited pages</div>
    </div>
    <div class="card tile">
        <div class="label">Pages below <?= $threshold ?></div>
        <div class="value"><?= (int) ($totals['failing_pages'] ?? 0) ?></div>
        <div class="delta">need fixing</div>
    </div>
    <div class="card tile">
        <div class="label">Open tasks</div>
        <div class="value"><?= (int) ($totals['open_tasks'] ?? 0) ?></div>
        <div class="delta"><a href="tasks.php">work queue</a></div>
    </div>
    <div class="card tile">
        <div class="label">Tracked pages</div>
        <div class="value"><?= (int) ($totals['tracked_pages'] ?? 0) ?></div>
        <div class="delta">on <?= count($sites) ?> site<?= count($sites) === 1 ? '' : 's' ?></div>
    </div>
</div>

<h2>Sites</h2>
<div class="card">
    <?php if (!$sites): ?>
        <div class="empty">
            No sites yet. <a href="sites.php">Add a website</a> and import its sitemap,
            or run a <a href="quick.php">quick check</a> on a handful of URLs.
        </div>
    <?php else: ?>
    <div class="table-wrap">
    <table>
        <thead><tr>
            <th>Site</th><th class="num">Avg score</th><th class="num">Tracked</th>
            <th class="num">Below target</th><th class="num">Open tasks</th><th>Last audit</th><th></th>
        </tr></thead>
        <tbody>
        <?php foreach ($sites as $site): ?>
            <tr>
                <td><a href="site.php?id=<?= (int) $site['id'] ?>"><?= Helpers::h($site['name']) ?></a>
                    <div class="small muted"><?= Helpers::h($site['url']) ?></div></td>
                <td class="num"><?php $score = $site['avg_score'] === null ? null : (int) $site['avg_score'];
                    require WVA_ROOT . '/src/views/score.php'; ?></td>
                <td class="num"><?= (int) $site['tracked_count'] ?> / <?= (int) $site['page_count'] ?></td>
                <td class="num"><?= (int) $site['failing_count'] ?></td>
                <td class="num"><?= (int) $site['open_tasks'] ?></td>
                <td class="small muted"><?= Helpers::h(Helpers::ago($site['last_audit_at'] ?? null)) ?></td>
                <td class="num"><a class="btn small" href="site.php?id=<?= (int) $site['id'] ?>">Open</a></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</div>

<?php if ($worstPages): ?>
<h2>Worst performing pages</h2>
<div class="card">
    <div class="table-wrap">
    <table>
        <thead><tr><th>Page</th><th>Site</th><th class="num">Score</th><th class="num">Change</th><th>Last audit</th></tr></thead>
        <tbody>
        <?php foreach ($worstPages as $page): ?>
            <?php
            $score   = $page['last_score'] === null ? null : (int) $page['last_score'];
            $previous = $page['previous_score'] === null ? null : (int) $page['previous_score'];
            $delta   = $score !== null && $previous !== null ? $score - $previous : null;
            ?>
            <tr>
                <td class="url-cell"><a href="page.php?id=<?= (int) $page['id'] ?>"><?= Helpers::h($page['path']) ?></a></td>
                <td class="small muted"><?= Helpers::h($page['site_name']) ?></td>
                <td class="num"><?php require WVA_ROOT . '/src/views/score.php'; ?></td>
                <td class="num <?= $delta === null ? '' : ($delta >= 0 ? 'delta up' : 'delta down') ?>">
                    <?= $delta === null ? '—' : ($delta > 0 ? '+' . $delta : $delta) ?>
                </td>
                <td class="small muted"><?= Helpers::h(Helpers::ago($page['last_audit_at'])) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>
<?php endif; ?>

<?php if ($openTasks): ?>
<h2>Newest open tasks</h2>
<div class="card">
    <div class="table-wrap">
    <table>
        <thead><tr><th>Task</th><th>Priority</th><th class="num">Score</th><th>Opened</th></tr></thead>
        <tbody>
        <?php foreach (array_slice($openTasks, 0, 8) as $task): ?>
            <tr>
                <td class="url-cell"><a href="tasks.php#task-<?= (int) $task['id'] ?>"><?= Helpers::h($task['title']) ?></a>
                    <div class="small muted"><?= Helpers::h($task['site_name']) ?></div></td>
                <td><span class="pill <?= Helpers::h($task['priority']) ?>"><?= Helpers::h(ucfirst($task['priority'])) ?></span></td>
                <td class="num"><?php $score = $task['latest_score'] === null ? null : (int) $task['latest_score'];
                    require WVA_ROOT . '/src/views/score.php'; ?></td>
                <td class="small muted"><?= Helpers::h(Helpers::ago($task['opened_at'])) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>
<?php endif; ?>

<?php require WVA_ROOT . '/src/views/footer.php'; ?>
