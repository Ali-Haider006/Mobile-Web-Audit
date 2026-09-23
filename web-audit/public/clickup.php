<?php
declare(strict_types=1);

require __DIR__ . '/_init.php';
wva_require_schema();

use Wva\ClickUp;
use Wva\ClickUpSync;
use Wva\Helpers;
use Wva\Repo\Runs;
use Wva\Repo\Sites;
use Wva\Repo\Tasks;

/**
 * Review and create ClickUp tasks.
 *
 * Reached after a scan finishes (?run_id=N) or from a single task
 * (?task_id=N). Nothing is sent until the operator presses Create, and every
 * title, description and priority is editable first.
 */

$runId   = wva_int('run_id');
$taskId  = wva_int('task_id');
$results = [];

if ($runId === 0 && $taskId === 0) {
    Helpers::flash('Nothing to send to ClickUp.', 'warn');
    Helpers::redirect('tasks.php');
}

/** @return array<int,array<string,mixed>> */
function wva_candidates(int $runId, int $taskId): array
{
    if ($runId > 0) {
        return Tasks::forRun($runId);
    }
    $task = Tasks::find($taskId);
    if ($task === null || !empty($task['clickup_task_id'])) {
        return [];
    }
    $site = Sites::find((int) $task['site_id']);
    $task['clickup_list_id'] = $site['clickup_list_id'] ?? null;
    return [$task];
}

if (wva_is_post()) {
    Helpers::checkCsrf();

    if (wva_str('action') === 'create') {
        $listId   = wva_str('list_id');
        $selected = array_map('intval', (array) ($_POST['include'] ?? []));
        $titles   = (array) ($_POST['title'] ?? []);
        $bodies   = (array) ($_POST['description'] ?? []);
        $prios    = (array) ($_POST['priority'] ?? []);
        $assignees = (array) ($_POST['assignee'] ?? []);

        foreach ($selected as $id) {
            $result = ClickUpSync::push($id, $listId, [
                'title'       => (string) ($titles[$id] ?? ''),
                'description' => (string) ($bodies[$id] ?? ''),
                'priority'    => (string) ($prios[$id] ?? ''),
                'assignee'    => (int) ($assignees[$id] ?? 0),
            ]);
            $task = Tasks::find($id);
            $results[] = $result + ['task' => $task['title'] ?? ('#' . $id)];
        }

        if ($results === []) {
            Helpers::flash('Nothing was ticked, so nothing was created.', 'warn');
        }
    }
}

$candidates = wva_candidates($runId, $taskId);
$run        = $runId > 0 ? Runs::find($runId) : null;
$site       = $run !== null ? Sites::find((int) $run['site_id'])
    : ($candidates !== [] ? Sites::find((int) $candidates[0]['site_id']) : null);

$lists         = ClickUp::cachedLists();
$members       = ClickUp::cachedMembers();
$defaultMember = (string) Wva\Settings::get('clickup_default_assignee', '');
$suggestedList = ClickUpSync::listIdFor($site);

$title  = 'Create ClickUp tasks';
$active = 'tasks';
require WVA_ROOT . '/src/views/header.php';
?>
<h1>Create ClickUp tasks</h1>
<p class="sub">
    <?php if ($run !== null): ?>
        From the scan of <?= Helpers::h($site['name'] ?? '') ?>,
        <?= Helpers::h(Helpers::ago((string) $run['created_at'])) ?>.
    <?php endif; ?>
    Nothing is sent until you press Create. Edits here apply to the ClickUp task only —
    this tool keeps its own audit detail, which it rewrites on the next scan.
</p>

<?php if ($results !== []): ?>
    <div class="card">
        <h3>Result</h3>
        <div class="table-wrap">
        <table>
            <thead><tr><th>Task</th><th>Outcome</th></tr></thead>
            <tbody>
            <?php foreach ($results as $result): ?>
                <tr>
                    <td class="url-cell"><?= Helpers::h($result['task']) ?></td>
                    <td class="small">
                        <?php if ($result['ok'] && $result['url']): ?>
                            <a href="<?= Helpers::h($result['url']) ?>" target="_blank" rel="noopener">Created — open in ClickUp</a>
                        <?php elseif ($result['skipped'] !== null): ?>
                            <span class="muted">Skipped: <?= Helpers::h($result['skipped']) ?></span>
                        <?php else: ?>
                            <span style="color:var(--magenta)"><?= Helpers::h((string) $result['error']) ?></span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <div class="actions" style="margin-top:14px">
            <a class="btn" href="tasks.php">Back to tasks</a>
            <?php if ($run !== null): ?><a class="btn" href="run.php?id=<?= $runId ?>">Back to the scan</a><?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<?php if (!ClickUp::configured()): ?>
    <div class="card">
        <div class="flash error" style="margin:0">
            No ClickUp token is configured. Add <code>CLICKUP_TOKEN</code> to your
            <code>.env</code> (or <code>clickup_token</code> in <code>config/local.php</code>) and reload.
            Tokens are never stored in the database or editable here.
        </div>
    </div>
<?php elseif ($candidates === []): ?>
    <div class="card"><div class="empty">
        Nothing left to create — every task from this scan is already in ClickUp, or none dropped below target.
    </div></div>
<?php else: ?>
<form method="post">
    <?= Helpers::csrfField() ?>
    <input type="hidden" name="action" value="create">
    <input type="hidden" name="run_id" value="<?= $runId ?>">
    <input type="hidden" name="task_id" value="<?= $taskId ?>">

    <div class="card">
        <div class="row">
            <div class="field">
                <label for="list_id">ClickUp list</label>
                <select id="list_id" name="list_id" required>
                    <?php if ($lists === []): ?>
                        <option value="">No lists loaded — press "Load lists from ClickUp" in Settings</option>
                    <?php endif; ?>
                    <?php foreach ($lists as $list): ?>
                        <option value="<?= Helpers::h($list['id']) ?>"
                            <?= $suggestedList === (string) $list['id'] ? 'selected' : '' ?>>
                            <?= Helpers::h($list['path']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label>&nbsp;</label>
                <span class="small muted">
                    <?php if ($suggestedList !== ''): ?>
                        Pre-selected from <?= !empty($site['clickup_list_id']) ? 'this site\'s' : 'the default' ?> setting.
                    <?php else: ?>
                        No default list set — choose one.
                    <?php endif; ?>
                </span>
            </div>
        </div>
    </div>

    <?php foreach ($candidates as $task): ?>
        <?php $id = (int) $task['id']; ?>
        <div class="card">
            <div class="actions" style="margin-bottom:12px">
                <label class="choice" style="margin:0">
                    <input type="checkbox" name="include[]" value="<?= $id ?>" checked>
                    Create this one
                </label>
                <span class="pill <?= Helpers::h($task['priority']) ?>"><?= Helpers::h(ucfirst((string) $task['priority'])) ?></span>
                <span class="small muted">scored <?= (int) ($task['latest_score'] ?? $task['score_at_open']) ?>
                    against <?= (int) $task['threshold'] ?> · <?= Helpers::h((string) $task['url']) ?></span>
            </div>

            <div class="row">
                <div class="field" style="flex:1 1 100%">
                    <label for="title-<?= $id ?>">Task name</label>
                    <input type="text" id="title-<?= $id ?>" name="title[<?= $id ?>]" maxlength="255"
                           value="<?= Helpers::h($task['title']) ?>">
                </div>
            </div>
            <div class="row" style="margin-top:12px">
                <div class="field" style="flex:1 1 100%">
                    <label for="body-<?= $id ?>">Description (markdown)</label>
                    <textarea id="body-<?= $id ?>" name="description[<?= $id ?>]" rows="12"><?= Helpers::h(ClickUpSync::description($task)) ?></textarea>
                </div>
            </div>
            <div class="row" style="margin-top:12px">
                <div class="field" style="max-width:220px">
                    <label for="prio-<?= $id ?>">Priority in ClickUp</label>
                    <select id="prio-<?= $id ?>" name="priority[<?= $id ?>]">
                        <?php foreach (['critical' => 'Urgent', 'high' => 'High', 'normal' => 'Normal'] as $value => $label): ?>
                            <option value="<?= $value ?>" <?= (string) $task['priority'] === $value ? 'selected' : '' ?>><?= $label ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field" style="max-width:280px">
                    <label for="who-<?= $id ?>">Assign to</label>
                    <select id="who-<?= $id ?>" name="assignee[<?= $id ?>]">
                        <option value="0">Nobody</option>
                        <?php foreach ($members as $member): ?>
                            <option value="<?= (int) $member['id'] ?>"
                                <?= $defaultMember === (string) $member['id'] ? 'selected' : '' ?>>
                                <?= Helpers::h($member['name']) ?><?= !empty($member['email']) ? ' · ' . Helpers::h($member['email']) : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if ($members === []): ?>
                        <div class="small muted" style="margin-top:6px">No people loaded — press "Load lists &amp; people" in Settings.</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    <?php endforeach; ?>

    <div class="card">
        <div class="actions">
            <button class="btn primary" type="submit">Create <?= count($candidates) ?> task<?= count($candidates) === 1 ? '' : 's' ?> in ClickUp</button>
            <a class="btn" href="<?= $run !== null ? 'run.php?id=' . $runId : 'tasks.php' ?>">Cancel</a>
            <span class="small muted">Untick anything you do not want.</span>
        </div>
    </div>
</form>
<?php endif; ?>
<?php require WVA_ROOT . '/src/views/footer.php'; ?>
