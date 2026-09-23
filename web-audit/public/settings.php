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
        Settings::set('psi_api_key', wva_str('psi_api_key'));
        Helpers::flash('Settings saved.', 'ok');
        Helpers::redirect('settings.php');
    }

    if ($action === 'save_clickup') {
        Settings::set('clickup_token', wva_str('clickup_token'));
        Settings::set('clickup_default_list_id', wva_str('clickup_default_list_id'));
        Settings::set('clickup_auto_create', wva_str('clickup_auto_create') !== '' ? '1' : '');
        Settings::set('clickup_tags', wva_str('clickup_tags'));
        Settings::set('app_url', rtrim(wva_str('app_url'), '/'));
        Helpers::flash('ClickUp settings saved.', 'ok');
        Helpers::redirect('settings.php');
    }

    if ($action === 'refresh_lists') {
        try {
            $lists = Wva\ClickUp::refreshLists();
            Helpers::flash('Loaded ' . count($lists) . ' list(s) from ClickUp.', $lists === [] ? 'warn' : 'ok');
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
                <label for="psi_api_key">PageSpeed Insights API key</label>
                <input type="text" id="psi_api_key" name="psi_api_key" value="<?= Helpers::h($apiKey) ?>"
                       placeholder="paste your API key" autocomplete="off">
            </div>
            <div><button class="btn primary" type="submit">Save</button></div>
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
    <p class="small muted" style="margin-top:0">Tasks this tool opens can be pushed into ClickUp.
        Create a personal API token in ClickUp under <strong>Settings → Apps</strong>, paste it here,
        then load your lists and choose where tasks should go. A site can override the default list
        under its own settings, so each client can have its own list.</p>

    <form method="post">
        <?= Helpers::csrfField() ?>
        <input type="hidden" name="action" value="save_clickup">
        <div class="row">
            <div class="field">
                <label for="clickup_token">API token</label>
                <input type="text" id="clickup_token" name="clickup_token" autocomplete="off"
                       value="<?= Helpers::h($clickUpToken) ?>" placeholder="pk_...">
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
                <label for="app_url">This tool's URL (for links back from ClickUp)</label>
                <input type="text" id="app_url" name="app_url"
                       value="<?= Helpers::h((string) Settings::get('app_url', '')) ?>"
                       placeholder="https://audit.internal.example">
            </div>
            <div class="field">
                <label>Automatic</label>
                <label class="choice">
                    <input type="checkbox" name="clickup_auto_create" value="1" style="width:auto"
                        <?= Wva\ClickUpSync::autoCreateEnabled() ? 'checked' : '' ?>>
                    Create a ClickUp task automatically whenever a page drops below target
                </label>
            </div>
            <div><button class="btn primary" type="submit">Save</button></div>
        </div>
    </form>

    <form method="post" style="margin-top:14px">
        <?= Helpers::csrfField() ?>
        <input type="hidden" name="action" value="refresh_lists">
        <div class="actions">
            <button class="btn" type="submit">Load lists from ClickUp</button>
            <span class="small muted">
                <?php if ($clickUpLists === []): ?>
                    No lists loaded yet. Save the token first, then press this.
                <?php else: ?>
                    <?= count($clickUpLists) ?> list(s) cached<?= $clickUpCachedAt !== '' ? ', refreshed ' . Helpers::h(Helpers::ago($clickUpCachedAt)) : '' ?>.
                    Press again after adding lists in ClickUp.
                <?php endif; ?>
            </span>
        </div>
    </form>
</div>

<div class="card">
    <h3>Scheduling</h3>
    <p class="small">Run the worker from cron so scans finish without a browser tab open:</p>
    <pre class="details"># queue every active site once a week, then drain the queue
0 3 * * 1 php <?= Helpers::h(WVA_ROOT) ?>/bin/scan.php --all
*/5 * * * * php <?= Helpers::h(WVA_ROOT) ?>/bin/worker.php --max=20</pre>
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
