<?php
declare(strict_types=1);

define('WVA_PUBLIC_PAGE', true);
require __DIR__ . '/_init.php';

use Wva\Auth;
use Wva\Helpers;

if (!Auth::enabled()) {
    Helpers::redirect('index.php');
}
if (isset($_GET['logout'])) {
    Auth::logout();
    Helpers::redirect('login.php');
}

$error = null;
if (wva_is_post()) {
    Helpers::checkCsrf();
    if (Auth::attempt((string) ($_POST['password'] ?? ''))) {
        Helpers::redirect('index.php');
    }
    $error = 'That password did not match.';
}

$title  = 'Sign in';
$active = '';
require WVA_ROOT . '/src/views/header.php';
?>
<div class="card" style="max-width:380px;margin:60px auto;">
    <h1>Sign in</h1>
    <p class="sub small">Shared password, set as APP_PASSWORD in .env.</p>
    <?php if ($error): ?><div class="flash error"><?= Helpers::h($error) ?></div><?php endif; ?>
    <form method="post">
        <?= Helpers::csrfField() ?>
        <div class="field">
            <label for="password">Password</label>
            <input type="password" id="password" name="password" autofocus required>
        </div>
        <button class="btn primary" type="submit">Sign in</button>
    </form>
</div>
<?php require WVA_ROOT . '/src/views/footer.php'; ?>
