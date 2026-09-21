<?php
declare(strict_types=1);

require __DIR__ . '/_init.php';
wva_require_schema();

use Wva\AuditRunner;
use Wva\Helpers;
use Wva\Repo\Audits;
use Wva\Repo\Runs;
use Wva\Repo\Sites;

$runId = wva_int('id');
$run   = $runId > 0 ? Runs::find($runId) : null;
if ($run === null) {
    Helpers::flash('Scan not found.', 'warn');
    Helpers::redirect('index.php');
}
$site = Sites::find((int) $run['site_id']);

if (wva_is_post()) {
    Helpers::checkCsrf();
    if (wva_str('action') === 'cancel') {
        Runs::cancel($runId);
        Helpers::flash('Scan cancelled. Finished URLs keep their results.', 'ok');
        Helpers::redirect('run.php?id=' . $runId);
    }
}

$counts  = Runs::refresh($runId);
$results = Audits::forRun($runId);
$warning = AuditRunner::warnIfNoApiKey();

$title  = 'Scan';
$active = 'sites';
require WVA_ROOT . '/src/views/header.php';
?>
<h1>Scanning <?= Helpers::h($site['name'] ?? '') ?></h1>
<p class="sub">Mobile strategy · started <?= Helpers::h(Helpers::ago($run['created_at'])) ?>
    · <a href="site.php?id=<?= (int) $run['site_id'] ?>">back to site</a></p>

<?php if ($warning): ?><div class="flash warn"><?= Helpers::h($warning) ?></div><?php endif; ?>

<div class="card">
    <div class="actions">
        <strong id="progress-text"><?= (int) $counts['done'] + (int) $counts['failed'] ?> of <?= (int) $counts['total'] ?> done</strong>
        <span class="small muted" id="progress-note">Keep this tab open — it drives the queue.</span>
        <span class="spacer"></span>
        <button class="btn small" id="pause-btn" type="button">Pause</button>
        <form method="post" class="inline">
            <?= Helpers::csrfField() ?>
            <input type="hidden" name="action" value="cancel">
            <input type="hidden" name="id" value="<?= $runId ?>">
            <button class="btn small danger" type="submit">Cancel remaining</button>
        </form>
    </div>
    <div class="progress"><i id="progress-bar"></i></div>
    <div class="log" id="log"></div>
</div>

<h2>Results</h2>
<div class="card">
    <div id="results-wrap" class="table-wrap">
    <?php if (!$results): ?>
        <div class="empty">Nothing finished yet.</div>
    <?php else: ?>
        <table>
            <thead><tr><th>Page</th><th class="num">Score</th><th class="num">LCP</th><th class="num">CLS</th><th class="num">TBT</th><th>Result</th></tr></thead>
            <tbody>
            <?php foreach ($results as $audit): ?>
                <tr>
                    <td class="url-cell"><a href="page.php?id=<?= (int) $audit['page_id'] ?>"><?= Helpers::h($audit['path']) ?></a></td>
                    <td class="num"><?php $score = $audit['performance_score'] === null ? null : (int) $audit['performance_score'];
                        require WVA_ROOT . '/src/views/score.php'; ?></td>
                    <td class="num small"><?= Helpers::h(Helpers::ms($audit['lcp_ms'] === null ? null : (int) $audit['lcp_ms'])) ?></td>
                    <td class="num small"><?= Helpers::h(Helpers::cls($audit['cls'] ?? null)) ?></td>
                    <td class="num small"><?= Helpers::h(Helpers::ms($audit['tbt_ms'] === null ? null : (int) $audit['tbt_ms'])) ?></td>
                    <td class="small"><?= $audit['status'] === 'error' ? Helpers::h((string) $audit['error_message']) : 'OK' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
    </div>
</div>

<script>
(function () {
    var runId = <?= $runId ?>;
    var csrf = <?= json_encode(Helpers::csrfToken()) ?>;
    var total = <?= (int) $counts['total'] ?>;
    var remaining = <?= (int) $counts['remaining'] ?>;
    var log = document.getElementById('log');
    var bar = document.getElementById('progress-bar');
    var text = document.getElementById('progress-text');
    var note = document.getElementById('progress-note');
    var pause = document.getElementById('pause-btn');
    var paused = false;

    function line(message) {
        var row = document.createElement('div');
        row.textContent = message;
        log.appendChild(row);
        log.scrollTop = log.scrollHeight;
    }

    function paint(done, failed, totalCount) {
        var finished = done + failed;
        bar.style.width = totalCount ? Math.round((finished / totalCount) * 100) + '%' : '0%';
        text.textContent = finished + ' of ' + totalCount + ' done' + (failed ? ' (' + failed + ' failed)' : '');
    }

    function step() {
        if (paused) { return; }
        var body = new URLSearchParams();
        body.set('run_id', String(runId));
        body.set('batch', '1');
        body.set('_csrf', csrf);

        fetch('api/queue.php', { method: 'POST', body: body, credentials: 'same-origin' })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (data.error) { line('Error: ' + data.error); note.textContent = 'Stopped.'; return; }
                paint(data.done, data.failed, data.total);
                (data.processed || []).forEach(function (item) {
                    line(item.ok
                        ? '✓ ' + item.score + '  ' + item.url + (item.task ? '   [task ' + item.task + ']' : '')
                        : '✗ ' + item.url + '  — ' + item.error);
                });
                if (data.remaining > 0 && data.status !== 'cancelled') {
                    setTimeout(step, 400);
                } else {
                    note.textContent = 'Finished. Reloading results…';
                    setTimeout(function () { window.location.reload(); }, 1200);
                }
            })
            .catch(function (err) {
                line('Network error: ' + err.message + ' — retrying in 5s');
                setTimeout(step, 5000);
            });
    }

    pause.addEventListener('click', function () {
        paused = !paused;
        pause.textContent = paused ? 'Resume' : 'Pause';
        note.textContent = paused ? 'Paused. The queue is saved — resume any time.' : 'Keep this tab open — it drives the queue.';
        if (!paused) { step(); }
    });

    paint(<?= (int) $counts['done'] ?>, <?= (int) $counts['failed'] ?>, total);
    if (remaining > 0) {
        step();
    } else {
        note.textContent = 'Scan complete.';
    }
})();
</script>
<?php require WVA_ROOT . '/src/views/footer.php'; ?>
