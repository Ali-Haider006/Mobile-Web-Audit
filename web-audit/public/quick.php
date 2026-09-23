<?php
declare(strict_types=1);

require __DIR__ . '/_init.php';
wva_require_schema();

use Wva\AuditRunner;
use Wva\Helpers;

if (wva_is_post()) {
    Helpers::checkCsrf();
    $raw   = (string) ($_POST['urls'] ?? '');
    $lines = preg_split('/[\r\n,\s]+/', $raw) ?: [];
    $urls  = array_slice(array_values(array_filter(array_map('trim', $lines))), 0, 25);

    if ($urls === []) {
        Helpers::flash('Paste at least one URL.', 'warn');
        Helpers::redirect('quick.php');
    }

    try {
        $queued = AuditRunner::queueUrls($urls, wva_str('keep_tracking') !== '');
        if ($queued['run_id'] === 0) {
            Helpers::flash('None of those looked like valid http(s) URLs.', 'error');
            Helpers::redirect('quick.php');
        }
        if ($queued['skipped']) {
            Helpers::flash('Skipped ' . count($queued['skipped']) . ' line(s) that were not valid URLs.', 'warn');
        }
        Helpers::redirect('run.php?id=' . $queued['run_id']);
    } catch (Throwable $e) {
        Helpers::flash($e->getMessage(), 'error');
        Helpers::redirect('quick.php');
    }
}

$warning = AuditRunner::warnIfNoApiKey();
$title   = 'Quick check';
$active  = 'quick';
require WVA_ROOT . '/src/views/header.php';
?>
<h1>Quick check</h1>
<p class="sub">Paste a handful of URLs and score them now — mobile only. Results are saved to history and any page
    below target opens a task, exactly like a full scan.</p>

<?php if ($warning): ?><div class="flash warn"><?= Helpers::h($warning) ?></div><?php endif; ?>

<div class="card">
    <form method="post">
        <?= Helpers::csrfField() ?>
        <div class="field">
            <label for="urls">URLs (one per line, up to 25)</label>
            <textarea id="urls" name="urls" required
placeholder="https://example.com/
https://example.com/services
https://example.com/contact"></textarea>
        </div>
        <div class="field">
            <label class="choice">
                <input type="checkbox" name="keep_tracking" value="1" style="width:auto">
                Keep tracking these URLs from now on (include them in full site scans)
            </label>
        </div>
        <button class="btn primary" type="submit">Run mobile audit</button>
    </form>
</div>

<div class="card tight small muted">
    URLs are filed under the site that owns their domain — a new site row is created if we have not seen it before,
    so the one-off check still builds history you can graph later.
</div>
<?php require WVA_ROOT . '/src/views/footer.php'; ?>
