<?php
declare(strict_types=1);

require __DIR__ . '/_init.php';
wva_require_schema();

use Wva\Config;
use Wva\Helpers;
use Wva\Http;
use Wva\Settings;

$checkResult = null;

if (wva_is_post()) {
    Helpers::checkCsrf();
    $action = wva_str('action');

    if ($action === 'save') {
        $threshold = max(1, min(100, (int) wva_str('score_threshold', '80')));
        Settings::set('score_threshold', (string) $threshold);
        Settings::set('flap_band', (string) max(0, min(20, (int) wva_str('flap_band', '3'))));
        Settings::set('error_alert_streak', (string) max(1, min(10, (int) wva_str('error_alert_streak', '3'))));
        Settings::set('clickup_close_on_recovery', wva_str('clickup_close_on_recovery') !== '' ? '1' : '0');
        Helpers::flash('Settings saved.', 'ok');
        Helpers::redirect('settings.php');
    }

    if ($action === 'save_clickup') {
        Settings::set('clickup_default_list_id', wva_str('clickup_default_list_id'));
        Settings::set('clickup_default_assignee', wva_str('clickup_default_assignee'));
        Settings::set('clickup_public_link', wva_str('clickup_public_link') !== '' ? '1' : '0');
        Settings::set('clickup_tags', wva_str('clickup_tags'));
        Settings::set('app_url', rtrim(wva_str('app_url'), '/'));
        Helpers::flash('ClickUp settings saved.', 'ok');
        Helpers::redirect('settings.php');
    }

    if ($action === 'refresh_lists') {
        try {
            $lists  = Wva\ClickUp::refreshLists();
            $before = trim((string) Settings::get('clickup_default_assignee', ''));
            $people = Wva\ClickUp::refreshMembers();
            $after  = trim((string) Settings::get('clickup_default_assignee', ''));

            $note = 'Loaded ' . count($lists) . ' list(s) and ' . count($people) . ' person/people from ClickUp.';
            if ($before === '' && $after !== '') {
                // First load: the person holding the token gets the tasks until told otherwise.
                $note .= ' Default assignee set to ' . Wva\ClickUp::memberName((int) $after) . ' - change it below at any time.';
            }
            Helpers::flash($note, $lists === [] ? 'warn' : 'ok');
        } catch (Throwable $e) {
            Helpers::flash($e->getMessage(), 'error');
        }
        Helpers::redirect('settings.php');
    }

    if ($action === 'test') {
        try {
            $url = Wva\PageSpeed::ENDPOINT . '?' . http_build_query([
                'url'      => 'https://example.com',
                'strategy' => 'mobile',
                'category' => 'PERFORMANCE',
            ]) . (Settings::apiKey() !== '' ? '&key=' . rawurlencode(Settings::apiKey()) : '');
            $response = Http::get($url, 60, 1);
            $payload  = json_decode($response['body'], true);
            if (isset($payload['error'])) {
                $checkResult = ['type' => 'error', 'message' => 'API says: ' . (string) $payload['error']['message']];
            } elseif ($response['status'] === 200) {
                $score = $payload['lighthouseResult']['categories']['performance']['score'] ?? null;
                $checkResult = ['type' => 'ok', 'message' => 'Working — example.com scored '
                    . ($score === null ? 'n/a' : (int) round(((float) $score) * 100)) . ' on mobile.'];
            } else {
                $checkResult = ['type' => 'error', 'message' => 'HTTP ' . $response['status'] . ' from the API.'];
            }
        } catch (Throwable $e) {
            $checkResult = ['type' => 'error', 'message' => $e->getMessage()];
        }
    }
}

$apiKey          = Settings::apiKey();
$clickUpToken    = Wva\ClickUp::token();
$clickUpLists    = Wva\ClickUp::cachedLists();
$clickUpDefault  = (string) Settings::get('clickup_default_list_id', '');
$clickUpCachedAt = (string) Settings::get('clickup_list_cache_at', '');
$clickUpMembers  = Wva\ClickUp::cachedMembers();
$secretsFile     = is_readable(WVA_ROOT . '/config/local.php') ? 'config/local.php' : '.env';

$title  = 'Settings';
$active = 'settings';
require WVA_ROOT . '/src/views/header.php';
?>
<h1>Settings</h1>
<p class="sub">Stored in the database; .env supplies the fallback.
    Checking a new install or a new host? <a href="setup.php">Run the setup checks</a>.</p>

<?php if ($checkResult): ?>
    <div class="flash <?= $checkResult['type'] === 'ok' ? 'ok' : 'error' ?>"><?= Helpers::h($checkResult['message']) ?></div>
<?php endif; ?>

<div class="card">
    <form method="post">
        <?= Helpers::csrfField() ?>
        <input type="hidden" name="action" value="save">
        <div class="row">
            <div class="field">
                <label for="score_threshold">Pass mark — a page below this opens a task</label>
                <input type="number" id="score_threshold" name="score_threshold" min="1" max="100"
                       value="<?= Settings::threshold() ?>">
            </div>
            <div class="field">
                <label>PageSpeed Insights API key</label>
                <div class="small"><?= $apiKey === '' ? 'Not set' : Helpers::h(Wva\Doctor::mask($apiKey)) ?>
                    <span class="muted">· read from <?= Helpers::h($secretsFile) ?>, not editable here</span></div>
            </div>
            <div><button class="btn primary" type="submit">Save</button></div>
        </div>
        <div class="row" style="margin-top:14px">
            <div class="field">
                <label for="flap_band">Resolve only once this far above target</label>
                <input type="number" id="flap_band" name="flap_band" min="0" max="20"
                       value="<?= (int) Wva\Monitor::flapBand() ?>">
                <div class="small muted" style="margin-top:6px">Stops a page on the line opening and closing a task every run.</div>
            </div>
            <div class="field">
                <label for="error_alert_streak">Failed audits before saying something</label>
                <input type="number" id="error_alert_streak" name="error_alert_streak" min="1" max="10"
                       value="<?= (int) Wva\Monitor::errorStreakLimit() ?>">
                <div class="small muted" style="margin-top:6px">A timeout is not a performance problem, so it never opens a task.</div>
            </div>
            <div class="field">
                <label>On recovery</label>
                <label class="choice">
                    <input type="checkbox" name="clickup_close_on_recovery" value="1"
                        <?= Wva\Monitor::closeOnRecovery() ? 'checked' : '' ?>>
                    Also close the ClickUp task (off: it comments and a person closes it)
                </label>
            </div>
        </div>
    </form>
    <form method="post" style="margin-top:8px">
        <?= Helpers::csrfField() ?>
        <input type="hidden" name="action" value="test">
        <button class="btn" type="submit">Test the API key</button>
    </form>
</div>

<h2>ClickUp</h2>
<div class="card">
    <p class="small muted" style="margin-top:0">When a scan finishes you are offered the tasks it opened,
        with every name and description editable, and you choose the list before anything is created.
        The token lives in <?= Helpers::h($secretsFile) ?> — set <code>CLICKUP_TOKEN</code> there.
        The list chosen below is the default; a site can override it under its own settings, so each
        client can have its own list.</p>

    <form method="post">
        <?= Helpers::csrfField() ?>
        <input type="hidden" name="action" value="save_clickup">
        <div class="row">
            <div class="field">
                <label>API token</label>
                <div class="small"><?= $clickUpToken === '' ? 'Not set' : Helpers::h(Wva\Doctor::mask($clickUpToken)) ?>
                    <span class="muted">· read from <?= Helpers::h($secretsFile) ?>, not editable here</span></div>
            </div>
            <div class="field">
                <label for="clickup_default_list_id">Default list</label>
                <select id="clickup_default_list_id" name="clickup_default_list_id">
                    <option value="">— none, do not create tasks —</option>
                    <?php foreach ($clickUpLists as $list): ?>
                        <option value="<?= Helpers::h($list['id']) ?>"
                            <?= $clickUpDefault === (string) $list['id'] ? 'selected' : '' ?>>
                            <?= Helpers::h($list['path']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="row" style="margin-top:14px">
            <div class="field">
                <label for="clickup_tags">Tags (comma separated)</label>
                <input type="text" id="clickup_tags" name="clickup_tags"
                       value="<?= Helpers::h((string) Settings::get('clickup_tags', 'core-web-vitals')) ?>">
            </div>
            <div class="field">
                <label for="clickup_default_assignee">Default assignee</label>
                <select id="clickup_default_assignee" name="clickup_default_assignee">
                    <option value="">Nobody</option>
                    <?php foreach ($clickUpMembers as $member): ?>
                        <option value="<?= (int) $member['id'] ?>"
                            <?= (string) Settings::get('clickup_default_assignee', '') === (string) $member['id'] ? 'selected' : '' ?>>
                            <?= Helpers::h(Wva\ClickUp::memberLabel($member)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label for="app_url">This tool's URL (for share links and links back)</label>
                <input type="text" id="app_url" name="app_url"
                       value="<?= Helpers::h((string) Settings::get('app_url', '')) ?>"
                       placeholder="https://audit.internal.example">
            </div>
            <div class="field">
                <label>Public report link</label>
                <label class="choice">
                    <input type="checkbox" name="clickup_public_link" value="1"
                        <?= Wva\ClickUpSync::publicLinksEnabled() ? 'checked' : '' ?>>
                    Include a shareable read-only report link in each ClickUp task
                </label>
            </div>
            <div><button class="btn primary" type="submit">Save</button></div>
        </div>
    </form>

    <form method="post" style="margin-top:14px">
        <?= Helpers::csrfField() ?>
        <input type="hidden" name="action" value="refresh_lists">
        <div class="actions">
            <button class="btn" type="submit">Load lists &amp; people from ClickUp</button>
            <span class="small muted">
                <?php if ($clickUpLists === []): ?>
                    No lists loaded yet. Save the token first, then press this.
                <?php else: ?>
                    <?= count($clickUpLists) ?> list(s) and <?= count($clickUpMembers) ?> person/people cached<?= $clickUpCachedAt !== '' ? ', refreshed ' . Helpers::h(Helpers::ago($clickUpCachedAt)) : '' ?>.
                    Press again after adding lists in ClickUp.
                <?php endif; ?>
            </span>
        </div>
    </form>
</div>

<div class="card">
    <h3>Scheduling</h3>
    <p class="small">Run the worker from cron so scans finish without a browser tab open:</p>
    <pre class="details"># audit every monitored URL twice a week and act on the results
0 6 * * 1 php <?= Helpers::h(WVA_ROOT) ?>/bin/monitor.php --quiet
0 6 * * 4 php <?= Helpers::h(WVA_ROOT) ?>/bin/monitor.php --quiet</pre>
    <p class="small muted">One command does the lot: audits, opens or comments on ClickUp tasks, and notes
        recoveries. Add <code>--dry-run</code> to see what it would audit without spending API calls.</p>
</div>

<div class="card">
    <h3>Environment</h3>
    <div class="table-wrap">
    <table>
        <tbody>
            <tr><td>PHP</td><td class="num"><?= Helpers::h(PHP_VERSION) ?></td></tr>
            <tr><td>Database</td><td class="num"><?= Helpers::h((string) Config::get('db_name')) ?> @ <?= Helpers::h((string) Config::get('db_host')) ?></td></tr>
            <tr><td>Sitemap URL limit</td><td class="num"><?= (int) Config::get('max_sitemap_urls', 2000) ?></td></tr>
            <tr><td>HTTP timeout</td><td class="num"><?= (int) Config::get('http_timeout', 120) ?>s</td></tr>
            <tr><td>Login required</td><td class="num"><?= Wva\Auth::enabled() ? 'Yes' : 'No (set APP_PASSWORD in .env)' ?></td></tr>
        </tbody>
    </table>
    </div>
</div>
<?php require WVA_ROOT . '/src/views/footer.php'; ?>
