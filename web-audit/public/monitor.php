<?php
declare(strict_types=1);

require __DIR__ . '/_init.php';
wva_require_schema();

use Wva\AuditRunner;
use Wva\ClickUp;
use Wva\Helpers;
use Wva\Monitor;
use Wva\Repo\Pages;
use Wva\Repo\Runs;
use Wva\Repo\Sites;
use Wva\Settings;

/**
 * The whole tool on one screen: the URLs being watched, what they scored, and
 * where their task goes when they drop. Everything else is drill-down.
 */

if (wva_is_post()) {
    Helpers::checkCsrf();
    $action = wva_str('action');

    if ($action === 'add') {
        $raw   = (string) ($_POST['urls'] ?? '');
        $lines = array_filter(array_map('trim', preg_split('/[\r\n,\s]+/', $raw) ?: []));
        $added = 0;
        $skipped = [];

        foreach (array_slice($lines, 0, 50) as $line) {
            $url = Helpers::normalizeUrl($line);
            if ($url === null) {
                $skipped[] = $line;
                continue;
            }
            $site   = Sites::ensure(Wva\Sitemap::origin($url));
            $pageId = Pages::upsert((int) $site['id'], $url, null, true, 'manual');
            Pages::setTracked((int) $site['id'], [$pageId], true);
            Pages::setClickUpTarget($pageId, wva_str('list_id') ?: null, (int) wva_str('assignee_id') ?: null);
            $added++;
        }

        Helpers::flash(
            $added . ' URL(s) added.' . ($skipped !== [] ? ' Skipped ' . count($skipped) . ' that were not valid URLs.' : ''),
            $added > 0 ? 'ok' : 'warn'
        );
        Helpers::redirect('monitor.php');
    }

    if ($action === 'save_row') {
        $pageId = wva_int('page_id');
        $page   = Pages::find($pageId);
        if ($page !== null) {
            Pages::setClickUpTarget($pageId, wva_str('list_id') ?: null, (int) wva_str('assignee_id') ?: null);
            Pages::setTracked((int) $page['site_id'], [$pageId], wva_str('active') !== '');
            Helpers::flash('Saved.', 'ok');
        }
        Helpers::redirect('monitor.php');
    }

    if ($action === 'resume_page') {
        $pageId = wva_int('page_id');
        $page   = Pages::find($pageId);
        if ($page !== null) {
            Pages::setTracked((int) $page['site_id'], [$pageId], true);
            // A paused site would keep it dormant, so lift that too.
            Sites::setActive((int) $page['site_id'], true);
            Helpers::flash('Auditing resumed for this URL.', 'ok');
        }
        Helpers::redirect('monitor.php');
    }

    if ($action === 'audit_now') {
        $pages = Pages::monitored();
        $bySite = [];
        foreach ($pages as $page) {
            $bySite[(int) $page['site_id']][] = $page;
        }
        $runId = 0;
        foreach ($bySite as $siteId => $sitePages) {
            $runId = Runs::create((int) $siteId, $sitePages, 'scheduled') ?: $runId;
        }
        if ($runId === 0) {
            Helpers::flash('Nothing to audit yet — add some URLs first.', 'warn');
            Helpers::redirect('monitor.php');
        }
        Helpers::redirect('run.php?id=' . $runId);
    }
}

$rows    = Pages::monitored();
$dormant = Pages::dormant();
$lists   = ClickUp::cachedLists();
$members = ClickUp::cachedMembers();
$warning = AuditRunner::warnIfNoApiKey();

$failing = 0;
$never   = 0;
foreach ($rows as $row) {
    if ($row['last_score'] === null) {
        $never++;
    } elseif ((int) $row['last_score'] < (int) $row['threshold']) {
        $failing++;
    }
}

$title  = 'Monitor';
$active = 'monitor';
require WVA_ROOT . '/src/views/header.php';
?>
<h1>URLs we watch</h1>
<p class="sub">Mobile Core Web Vitals, audited on a schedule. A URL below its target opens a ClickUp task
    in the list you pick here, assigned to the person you pick here — no one has to be watching.</p>

<?php if ($warning): ?><div class="flash warn"><?= Helpers::h($warning) ?></div><?php endif; ?>

<div class="grid cols-4">
    <div class="card tile"><div class="label">URLs watched</div><div class="value"><?= count($rows) ?></div></div>
    <div class="card tile"><div class="label">Below target</div><div class="value"><?= $failing ?></div>
        <div class="delta">tasks opened automatically</div></div>
    <div class="card tile"><div class="label">Never audited</div><div class="value"><?= $never ?></div></div>
    <div class="card tile"><div class="label">Target</div><div class="value"><?= Settings::threshold() ?></div>
        <div class="delta">resolves at <?= Settings::threshold() + Monitor::flapBand() ?>+</div></div>
</div>

<div class="card">
    <h3>Add URLs</h3>
    <form method="post">
        <?= Helpers::csrfField() ?>
        <input type="hidden" name="action" value="add">
        <div class="field">
            <label for="urls">One per line</label>
            <textarea id="urls" name="urls" rows="4" required
placeholder="https://whitefish.example/
https://whitefish.example/services"></textarea>
        </div>
        <div class="row">
            <div class="field">
                <label for="list_id">ClickUp list for these</label>
                <select id="list_id" name="list_id">
                    <option value="">Use the site / global default</option>
                    <?php foreach ($lists as $list): ?>
                        <option value="<?= Helpers::h($list['id']) ?>"><?= Helpers::h($list['path']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label for="assignee_id">Assign to</label>
                <select id="assignee_id" name="assignee_id">
                    <option value="">Default assignee</option>
                    <?php foreach ($members as $member): ?>
                        <option value="<?= (int) $member['id'] ?>"><?= Helpers::h(ClickUp::memberLabel($member)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div><button class="btn primary" type="submit">Add</button></div>
        </div>
    </form>
</div>

<div class="card">
    <div class="actions" style="margin-bottom:14px">
        <form method="post" class="inline">
            <?= Helpers::csrfField() ?>
            <input type="hidden" name="action" value="audit_now">
            <button class="btn primary" type="submit">Audit everything now</button>
        </form>
        <span class="small muted">The schedule does this automatically — see Settings for the cron lines.</span>
    </div>

    <?php if ($rows === []): ?>
        <div class="empty">No URLs yet. Paste three or four above to get started.</div>
    <?php else: ?>
    <?php foreach ($rows as $row): ?>
        <form method="post" id="row-<?= (int) $row['id'] ?>">
            <?= Helpers::csrfField() ?>
            <input type="hidden" name="action" value="save_row">
            <input type="hidden" name="page_id" value="<?= (int) $row['id'] ?>">
        </form>
    <?php endforeach; ?>

    <div class="table-wrap">
    <table>
        <thead><tr>
            <th>URL</th><th class="num">Score</th><th class="num">Change</th>
            <th>ClickUp list</th><th>Assignee</th><th>Task</th><th>Last audit</th><th></th>
        </tr></thead>
        <tbody>
        <?php foreach ($rows as $row): ?>
            <?php
            $pageId    = (int) $row['id'];
            $score     = $row['last_score'] === null ? null : (int) $row['last_score'];
            $previous  = $row['previous_score'] === null ? null : (int) $row['previous_score'];
            $delta     = $score !== null && $previous !== null ? $score - $previous : null;
            $effective = Monitor::listFor($row, ['clickup_list_id' => $row['site_list_id']]);
            ?>
            <tr>
                <td class="url-cell">
                    <a href="page.php?id=<?= $pageId ?>"><?= Helpers::h($row['path']) ?></a>
                    <div class="small muted"><?= Helpers::h($row['site_name']) ?></div>
                    <?php if ((int) $row['consecutive_errors'] > 0): ?>
                        <div class="small" style="color:var(--amber)"><?= (int) $row['consecutive_errors'] ?> failed audit(s) in a row</div>
                    <?php endif; ?>
                </td>
                <td class="num"><?php require WVA_ROOT . '/src/views/score.php'; ?></td>
                <td class="num <?= $delta === null ? '' : ($delta >= 0 ? 'delta up' : 'delta down') ?>">
                    <?= $delta === null ? '—' : ($delta > 0 ? '+' . $delta : $delta) ?>
                </td>
                <td>
                    <select name="list_id" form="row-<?= $pageId ?>" style="min-width:190px">
                        <option value="">Default<?= $effective !== '' ? ' (' . Helpers::h(ClickUp::listName($effective)) . ')' : '' ?></option>
                        <?php foreach ($lists as $list): ?>
                            <option value="<?= Helpers::h($list['id']) ?>"
                                <?= (string) ($row['clickup_list_id'] ?? '') === (string) $list['id'] ? 'selected' : '' ?>>
                                <?= Helpers::h($list['path']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </td>
                <td>
                    <select name="assignee_id" form="row-<?= $pageId ?>" style="min-width:150px">
                        <option value="">Default</option>
                        <?php foreach ($members as $member): ?>
                            <option value="<?= (int) $member['id'] ?>"
                                <?= (int) ($row['clickup_assignee_id'] ?? 0) === (int) $member['id'] ? 'selected' : '' ?>>
                                <?= Helpers::h(ClickUp::memberLabel($member)) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </td>
                <td class="small">
                    <?php if (!empty($row['open_task_url'])): ?>
                        <a href="<?= Helpers::h($row['open_task_url']) ?>" target="_blank" rel="noopener">Open in ClickUp</a>
                    <?php elseif (!empty($row['open_task_id'])): ?>
                        <span class="pill open">Open here</span>
                    <?php else: ?>
                        <span class="muted">—</span>
                    <?php endif; ?>
                </td>
                <td class="small muted"><?= Helpers::h(Helpers::ago($row['last_audit_at'])) ?></td>
                <td class="num">
                    <label class="choice" style="margin:0 0 6px">
                        <input type="checkbox" name="active" value="1" form="row-<?= $pageId ?>"
                               <?= (int) $row['is_tracked'] === 1 ? 'checked' : '' ?>> On
                    </label>
                    <button class="btn small" type="submit" form="row-<?= $pageId ?>">Save</button>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</div>

<?php if ($dormant !== []): ?>
<div class="card">
    <h3>Not being audited (<?= count($dormant) ?>)</h3>
    <p class="sub">Switched off, or belonging to a site we have stopped auditing. Their history is kept
       and nothing here opens tasks. Delete a site on the <a href="sites.php">Sites</a> screen to erase it for good.</p>
    <div class="table-wrap">
    <table>
        <thead><tr><th>URL</th><th class="num">Last score</th><th>Last audit</th><th>Why</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($dormant as $row): ?>
            <tr>
                <td><a href="page.php?id=<?= (int) $row['id'] ?>"><?= Helpers::h($row['url']) ?></a>
                    <div class="small muted"><?= Helpers::h($row['site_name']) ?></div></td>
                <td class="num"><?= $row['last_score'] === null ? '—' : (int) $row['last_score'] ?></td>
                <td class="small muted"><?= Helpers::h(Helpers::ago($row['last_audit_at'])) ?></td>
                <td class="small muted">
                    <?= (int) $row['site_active'] === 0 ? 'Site not audited' : 'URL switched off' ?>
                </td>
                <td class="num">
                    <form method="post" class="inline">
                        <?= Helpers::csrfField() ?>
                        <input type="hidden" name="action" value="resume_page">
                        <input type="hidden" name="page_id" value="<?= (int) $row['id'] ?>">
                        <button class="btn small" type="submit">Resume</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>
<?php endif; ?>
<?php require WVA_ROOT . '/src/views/footer.php'; ?>
