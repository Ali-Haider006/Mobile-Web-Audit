<?php
declare(strict_types=1);

require __DIR__ . '/_init.php';

use Wva\Doctor;
use Wva\Helpers;

/**
 * The same checks bin/doctor.php runs, in the browser - the only option on
 * hosting without SSH. Read-only apart from the schema installer, which only
 * ever runs CREATE TABLE IF NOT EXISTS.
 */

$liveApiCall = false;
$install     = null;

if (wva_is_post()) {
    Helpers::checkCsrf();
    $action = wva_str('action');
    if ($action === 'test_api') {
        $liveApiCall = true;
    }
    if ($action === 'install_schema') {
        try {
            $install = Doctor::installSchema();
        } catch (Throwable $e) {
            $install = ['applied' => 0, 'failed' => 1, 'errors' => [$e->getMessage()], 'missing' => Doctor::TABLES];
        }
    }
}

// Behind a proxy or a host's load balancer, HTTPS shows up in a header.
$isHttps = (($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off')
    || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'
    || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443;

$host      = (string) ($_SERVER['HTTP_HOST'] ?? '');
$isLocal   = $host === '' || preg_match('~^(localhost|127\.0\.0\.1|\[::1\])(:\d+)?$~i', $host) === 1;
$isPublic  = !$isLocal;

$checks = Doctor::run($liveApiCall, $isPublic);
$totals = Doctor::counts($checks);

$title  = 'Setup';
$active = 'settings';
require WVA_ROOT . '/src/views/header.php';
?>
<h1>Setup</h1>
<p class="sub">Everything this tool needs, checked on the server it is actually running on.
    <?= $isPublic ? 'This looks like a public deployment, so the login gate is judged as required.' : 'This looks like a local install.' ?></p>

<?php if ($totals[Doctor::FAIL] > 0): ?>
    <div class="flash error"><strong><?= $totals[Doctor::FAIL] ?></strong> problem(s) to fix before this will work<?= $totals[Doctor::WARN] ? ', plus ' . $totals[Doctor::WARN] . ' warning(s)' : '' ?>.</div>
<?php elseif ($totals[Doctor::WARN] > 0): ?>
    <div class="flash warn">Working, with <strong><?= $totals[Doctor::WARN] ?></strong> warning(s) worth reading.</div>
<?php else: ?>
    <div class="flash ok">Everything checks out.</div>
<?php endif; ?>

<?php if ($install !== null): ?>
    <div class="flash <?= $install['missing'] === [] && $install['failed'] === 0 ? 'ok' : 'error' ?>">
        Schema install: <?= (int) $install['applied'] ?> statement(s) applied<?= $install['failed'] ? ', ' . (int) $install['failed'] . ' failed' : '' ?>.
        <?php if ($install['missing'] !== []): ?>
            Still missing: <?= Helpers::h(implode(', ', $install['missing'])) ?>.
        <?php endif; ?>
        <?php foreach ($install['errors'] as $error): ?>
            <div class="small muted"><?= Helpers::h($error) ?></div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php
$section = '';
foreach ($checks as $check):
    if ($check['section'] !== $section):
        if ($section !== '') {
            echo '</tbody></table></div></div>';
        }
        $section = $check['section'];
        ?>
        <h2><?= Helpers::h($section) ?></h2>
        <div class="card">
        <div class="table-wrap"><table><tbody>
    <?php endif; ?>
        <tr>
            <td style="width:88px">
                <span class="pill <?= $check['status'] === Doctor::OK ? 'tracked' : ($check['status'] === Doctor::WARN ? 'in_progress' : 'open') ?>">
                    <?= $check['status'] === Doctor::OK ? 'OK' : ($check['status'] === Doctor::WARN ? 'Warn' : 'Fail') ?>
                </span>
            </td>
            <td>
                <strong><?= Helpers::h($check['label']) ?></strong>
                <?php if ($check['detail'] !== ''): ?>
                    <div class="small muted"><?= Helpers::h($check['detail']) ?></div>
                <?php endif; ?>
                <?php if ($check['status'] !== Doctor::OK && $check['remedy'] !== ''): ?>
                    <div class="small" style="margin-top:6px"><?= Helpers::h($check['remedy']) ?></div>
                <?php endif; ?>
            </td>
        </tr>
<?php endforeach; ?>
</tbody></table></div></div>

<h2>Actions</h2>
<div class="card">
    <div class="actions">
        <form method="post" class="inline">
            <?= Helpers::csrfField() ?>
            <input type="hidden" name="action" value="test_api">
            <button class="btn primary" type="submit">Test the PageSpeed API</button>
        </form>
        <span class="small muted">Spends one request. Proves this host allows outbound HTTPS and that the key works — the first thing to check on any new host.</span>
    </div>
    <div class="actions" style="margin-top:14px">
        <form method="post" class="inline"
              onsubmit="return confirm('Create any missing tables in this database? Existing tables are left alone.');">
            <?= Helpers::csrfField() ?>
            <input type="hidden" name="action" value="install_schema">
            <button class="btn" type="submit">Install the schema</button>
        </form>
        <span class="small muted">Runs db/schema.sql. Every statement is CREATE TABLE IF NOT EXISTS, so it never drops or overwrites anything.</span>
    </div>
</div>
<?php require WVA_ROOT . '/src/views/footer.php'; ?>
