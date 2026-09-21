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
<link rel="stylesheet" href="assets/app.css?v=1">
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
        <?php if (Wva\Auth::enabled()): ?>
            <span class="spacer"></span>
            <a class="small muted" href="login.php?logout=1">Sign out</a>
        <?php endif; ?>
    </div>
</header>
<main>
<?php if ($flash): ?>
    <div class="flash <?= Helpers::h($flash['type']) ?>"><?= Helpers::h($flash['message']) ?></div>
<?php endif; ?>
