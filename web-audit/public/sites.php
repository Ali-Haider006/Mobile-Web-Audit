<?php
declare(strict_types=1);

require __DIR__ . '/_init.php';
wva_require_schema();

use Wva\Helpers;
use Wva\Repo\Sites;
use Wva\Settings;

if (wva_is_post()) {
    Helpers::checkCsrf();
    $action = wva_str('action');

    try {
        if ($action === 'create') {
            $site = Sites::ensure(wva_str('url'), wva_str('name'));
            Helpers::flash('Site saved. Now import its sitemap.', 'ok');
            Helpers::redirect('import.php?site_id=' . (int) $site['id']);
        }
        if ($action === 'archive') {
            $siteId = wva_int('site_id');
            $reason = wva_str('reason') !== '' ? wva_str('reason') : 'we no longer audit this site';
            $settled = \Wva\Monitor::siteStopped($siteId, $reason);
            Sites::setActive($siteId, false);

            $note = 'Archived. Scheduled audits have stopped; the history is kept.';
            if ($settled['tasks'] > 0) {
                $note .= ' Closed out ' . $settled['tasks'] . ' open task(s)';
                $note .= $settled['commented'] > 0 ? ', commented on ' . $settled['commented'] . ' in ClickUp' : '';
                $note .= $settled['failed'] > 0 ? '; ' . $settled['failed'] . ' ClickUp comment(s) failed' : '';
                $note .= '. Closing them in ClickUp is left to you.';
            }
            Helpers::flash($note, $settled['failed'] > 0 ? 'warn' : 'ok');
            Helpers::redirect('sites.php');
        }
        if ($action === 'resume') {
            Sites::setActive(wva_int('site_id'), true);
            Helpers::flash('Auditing resumed. The next scheduled run picks this site up again.', 'ok');
            Helpers::redirect('sites.php');
        }
        if ($action === 'delete') {
            $siteId = wva_int('site_id');
            // Deleting drops the tasks with everything else, so speak up in
            // ClickUp first - those tasks live somewhere we cannot clean up.
            \Wva\Monitor::siteStopped($siteId, 'the site was deleted from the audit tool');
            Sites::delete($siteId);
            Helpers::flash('Site deleted, along with its pages, history and tasks.', 'ok');
            Helpers::redirect('sites.php');
        }
    } catch (Throwable $e) {
        Helpers::flash($e->getMessage(), 'error');
        Helpers::redirect('sites.php');
    }
}

$sites  = Sites::all();
$title  = 'Sites';
$active = 'sites';
require WVA_ROOT . '/src/views/header.php';
?>
<h1>Sites</h1>
<p class="sub">Add a website, import its sitemap, then pick the pages worth tracking.</p>

<div class="card">
    <h3>Add a website</h3>
    <form method="post">
        <?= Helpers::csrfField() ?>
        <input type="hidden" name="action" value="create">
        <div class="row">
            <div class="field">
                <label for="url">Website URL</label>
                <input type="text" id="url" name="url" placeholder="https://example.com" required>
            </div>
            <div class="field">
                <label for="name">Name (optional)</label>
                <input type="text" id="name" name="name" placeholder="Client name">
            </div>
            <div><button class="btn primary" type="submit">Add &amp; import sitemap</button></div>
        </div>
    </form>
</div>

<div class="card">
    <?php if (!$sites): ?>
        <div class="empty">No sites yet.</div>
    <?php else: ?>
    <div class="table-wrap">
    <table>
        <thead><tr>
            <th>Site</th><th class="num">Pages</th><th class="num">Tracked</th>
            <th class="num">Target</th><th class="num">Below target</th><th></th>
        </tr></thead>
        <tbody>
        <?php foreach ($sites as $site): ?>
            <tr>
                <td><a href="site.php?id=<?= (int) $site['id'] ?>"><?= Helpers::h($site['name']) ?></a>
                    <?php if ((int) $site['is_active'] !== 1): ?>
                        <span class="pill excluded">Not audited</span>
                    <?php endif; ?>
                    <div class="small muted"><?= Helpers::h($site['url']) ?></div></td>
                <td class="num"><?= (int) $site['page_count'] ?></td>
                <td class="num"><?= (int) $site['tracked_count'] ?></td>
                <td class="num"><?= (int) ($site['score_threshold'] ?? Settings::threshold()) ?></td>
                <td class="num"><?= (int) $site['failing_count'] ?></td>
                <td class="num actions" style="justify-content:flex-end">
                    <a class="btn small" href="import.php?site_id=<?= (int) $site['id'] ?>">Import sitemap</a>
                    <?php if ((int) $site['is_active'] === 1): ?>
                        <form method="post" class="inline"
                              onsubmit="return confirm('Stop auditing <?= Helpers::h($site['name']) ?>?\n\nScheduled audits stop and open tasks are closed out here, with a note added in ClickUp. All history is kept and you can resume any time.');">
                            <?= Helpers::csrfField() ?>
                            <input type="hidden" name="action" value="archive">
                            <input type="hidden" name="site_id" value="<?= (int) $site['id'] ?>">
                            <button class="btn small" type="submit">Stop auditing</button>
                        </form>
                    <?php else: ?>
                        <form method="post" class="inline">
                            <?= Helpers::csrfField() ?>
                            <input type="hidden" name="action" value="resume">
                            <input type="hidden" name="site_id" value="<?= (int) $site['id'] ?>">
                            <button class="btn small" type="submit">Resume</button>
                        </form>
                    <?php endif; ?>
                    <form method="post" class="inline"
                          onsubmit="return confirm('Permanently delete <?= Helpers::h($site['name']) ?>?\n\nThis erases <?= (int) $site['page_count'] ?> page(s) and every score ever recorded for them. It cannot be undone.\n\nIf you have simply stopped working on this client, use Stop auditing instead - it keeps the history.');">
                        <?= Helpers::csrfField() ?>
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="site_id" value="<?= (int) $site['id'] ?>">
                        <button class="btn small danger" type="submit">Delete</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</div>
<?php require WVA_ROOT . '/src/views/footer.php'; ?>
