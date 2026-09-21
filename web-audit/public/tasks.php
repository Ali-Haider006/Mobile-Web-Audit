<?php
declare(strict_types=1);

require __DIR__ . '/_init.php';
wva_require_schema();

use Wva\Helpers;
use Wva\Repo\Sites;
use Wva\Repo\Tasks;

if (wva_is_post()) {
    Helpers::checkCsrf();
    if (wva_str('action') === 'update') {
        Tasks::updateStatus(
            wva_int('task_id'),
            wva_str('status'),
            wva_str('assignee') ?: null,
            wva_str('note') ?: null
        );
        Helpers::flash('Task updated.', 'ok');
    }
    Helpers::redirect('tasks.php?' . http_build_query([
        'status'  => wva_str('filter_status', 'open'),
        'site_id' => wva_int('filter_site'),
    ]));
}

$filters = [
    'status'  => wva_str('status', 'open'),
    'site_id' => wva_int('site_id'),
];
$tasks = Tasks::search($filters);
$sites = Sites::all();

$title  = 'Tasks';
$active = 'tasks';
require WVA_ROOT . '/src/views/header.php';
?>
<h1>Tasks</h1>
<p class="sub">Opened automatically whenever a tracked page scores below its site's target, and auto-resolved when a
    later audit brings it back up.</p>

<div class="card">
    <form method="get" class="row">
        <div class="field">
            <label for="status">Status</label>
            <select id="status" name="status">
                <?php foreach ([
                    'open'        => 'Open & in progress',
                    'in_progress' => 'In progress',
                    'resolved'    => 'Resolved',
                    'ignored'     => 'Ignored',
                    'all'         => 'All',
                ] as $key => $label): ?>
                    <option value="<?= $key ?>" <?= $filters['status'] === $key ? 'selected' : '' ?>><?= $label ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label for="site_id">Site</label>
            <select id="site_id" name="site_id">
                <option value="0">All sites</option>
                <?php foreach ($sites as $site): ?>
                    <option value="<?= (int) $site['id'] ?>" <?= $filters['site_id'] === (int) $site['id'] ? 'selected' : '' ?>>
                        <?= Helpers::h($site['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div><button class="btn" type="submit">Filter</button></div>
    </form>
</div>

<?php if (!$tasks): ?>
    <div class="card"><div class="empty">Nothing here — every tracked page is at or above its target.</div></div>
<?php endif; ?>

<?php foreach ($tasks as $task): ?>
<div class="card" id="task-<?= (int) $task['id'] ?>">
    <div class="row" style="align-items:flex-start">
        <div style="flex:1 1 420px">
            <div class="actions" style="margin-bottom:6px">
                <span class="pill <?= Helpers::h($task['status']) ?>"><?= Helpers::h(str_replace('_', ' ', (string) $task['status'])) ?></span>
                <span class="pill <?= Helpers::h($task['priority']) ?>"><?= Helpers::h(ucfirst((string) $task['priority'])) ?></span>
                <span class="small muted"><?= Helpers::h($task['site_name']) ?> · opened <?= Helpers::h(Helpers::ago($task['opened_at'])) ?></span>
            </div>
            <h3 style="font-size:15px;color:var(--text-primary)"><?= Helpers::h($task['title']) ?></h3>
            <p class="small"><a href="page.php?id=<?= (int) $task['page_id'] ?>"><?= Helpers::h($task['url']) ?></a></p>
            <details>
                <summary class="small muted" style="cursor:pointer;margin-bottom:8px">Audit detail</summary>
                <pre class="details"><?= Helpers::h((string) $task['details']) ?></pre>
            </details>
            <?php if (!empty($task['resolution_note'])): ?>
                <p class="small muted"><?= Helpers::h((string) $task['resolution_note']) ?></p>
            <?php endif; ?>
        </div>
        <div style="flex:0 0 260px">
            <div class="card tile tight" style="margin-bottom:12px">
                <div class="label">Latest score (target <?= (int) $task['threshold'] ?>)</div>
                <div class="value"><?php $score = $task['latest_score'] === null ? null : (int) $task['latest_score'];
                    require WVA_ROOT . '/src/views/score.php'; ?></div>
                <div class="delta">was <?= (int) $task['score_at_open'] ?> when opened</div>
            </div>
            <form method="post">
                <?= Helpers::csrfField() ?>
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="task_id" value="<?= (int) $task['id'] ?>">
                <input type="hidden" name="filter_status" value="<?= Helpers::h($filters['status']) ?>">
                <input type="hidden" name="filter_site" value="<?= (int) $filters['site_id'] ?>">
                <div class="field">
                    <label for="status-<?= (int) $task['id'] ?>">Status</label>
                    <select id="status-<?= (int) $task['id'] ?>" name="status">
                        <?php foreach (['open' => 'Open', 'in_progress' => 'In progress', 'resolved' => 'Resolved', 'ignored' => 'Ignored'] as $key => $label): ?>
                            <option value="<?= $key ?>" <?= $task['status'] === $key ? 'selected' : '' ?>><?= $label ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="assignee-<?= (int) $task['id'] ?>">Assignee</label>
                    <input type="text" id="assignee-<?= (int) $task['id'] ?>" name="assignee"
                           value="<?= Helpers::h($task['assignee'] ?? '') ?>" placeholder="Who is fixing it?">
                </div>
                <div class="field">
                    <label for="note-<?= (int) $task['id'] ?>">Note</label>
                    <input type="text" id="note-<?= (int) $task['id'] ?>" name="note"
                           value="<?= Helpers::h($task['resolution_note'] ?? '') ?>" placeholder="What was done">
                </div>
                <button class="btn" type="submit">Save</button>
            </form>
        </div>
    </div>
</div>
<?php endforeach; ?>
<?php require WVA_ROOT . '/src/views/footer.php'; ?>
