<?php
/**
 * @var string      $title
 * @var string|null $active
 */
use Wva\Helpers;

$flash  = Helpers::flash();
$active = $active ?? '';
$nav    = [
    'dashboard' => ['index.php', 'Dashboard'],
    'sites'     => ['sites.php', 'Sites'],
    'quick'     => ['quick.php', 'Quick check'],
    'tasks'     => ['tasks.php', 'Tasks'],
    'settings'  => ['settings.php', 'Settings'],
];
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= Helpers::h($title ?? 'Mobile Web Audit') ?> · Mobile Web Audit</title>
<script>
/* Stamp the saved theme before first paint, so a dark-mode user never sees a
   white flash. Storage can throw in a locked-down browser - ignore and fall
   back to the OS setting. */
(function () {
    try {
        var saved = localStorage.getItem('wva-theme');
        if (saved === 'dark' || saved === 'light') {
            document.documentElement.setAttribute('data-theme', saved);
        }
    } catch (e) {}
})();
</script>
<link rel="stylesheet" href="assets/app.css?v=2">
</head>
<body>
<header class="masthead">
    <div class="inner">
        <a class="brand" href="index.php">Mobile Web Audit <span>· Core Web Vitals</span></a>
        <nav>
            <?php foreach ($nav as $key => [$href, $label]): ?>
                <a href="<?= $href ?>" class="<?= $active === $key ? 'active' : '' ?>"><?= $label ?></a>
            <?php endforeach; ?>
        </nav>
        <span class="spacer"></span>
        <?php if (Wva\Auth::enabled()): ?>
            <a class="small muted" href="login.php?logout=1">Sign out</a>
        <?php endif; ?>
        <button type="button" id="themeBtn" aria-pressed="false">Theme</button>
    </div>
</header>
<script>
(function () {
    var btn = document.getElementById('themeBtn');
    var root = document.documentElement;

    function current() {
        var set = root.getAttribute('data-theme');
        if (set) { return set; }
        return window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
    }

    function label() {
        var mode = current();
        btn.textContent = mode === 'dark' ? 'Light' : 'Dark';
        btn.setAttribute('aria-pressed', mode === 'dark' ? 'true' : 'false');
        btn.setAttribute('aria-label', 'Switch to ' + (mode === 'dark' ? 'light' : 'dark') + ' theme');
    }

    btn.addEventListener('click', function () {
        var next = current() === 'dark' ? 'light' : 'dark';
        root.setAttribute('data-theme', next);
        try { localStorage.setItem('wva-theme', next); } catch (e) {}
        label();
        // Chart colours are baked in at draw time.
        if (window.wvaRedrawCharts) { window.wvaRedrawCharts(); }
    });

    label();
})();
</script>
<main>
<?php if ($flash): ?>
    <div class="flash <?= Helpers::h($flash['type']) ?>"><?= Helpers::h($flash['message']) ?></div>
<?php endif; ?>
