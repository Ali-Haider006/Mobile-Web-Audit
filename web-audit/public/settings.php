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

$apiKey = Settings::apiKey();
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
