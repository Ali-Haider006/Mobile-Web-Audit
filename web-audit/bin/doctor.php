<?php
declare(strict_types=1);

/**
 * Pre-flight check. Run it before the first scan - every failure prints what
 * to do about it.
 *
 *   php bin/doctor.php            checks PHP, .env, the database and the schema
 *   php bin/doctor.php --api      also spends one API call proving the key works
 *   php bin/doctor.php --public   judge it as an internet-facing deployment
 *
 * No SSH on your host? The same checks are on the Setup screen in the browser.
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only - open public/setup.php in the browser instead\n");
}

define('WVA_ROOT', dirname(__DIR__));
require_once WVA_ROOT . '/src/bootstrap.php';

use Wva\Doctor;

$live   = in_array('--api', $argv, true);
$public = in_array('--public', $argv, true);

$checks  = Doctor::run($live, $public);
$section = '';

foreach ($checks as $check) {
    if ($check['section'] !== $section) {
        $section = $check['section'];
        echo PHP_EOL . $section . PHP_EOL;
    }
    $tag = match ($check['status']) {
        Doctor::OK   => "\033[32mOK\033[0m  ",
        Doctor::WARN => "\033[33mWARN\033[0m",
        default      => "\033[31mFAIL\033[0m",
    };
    echo '  ' . $tag . ' ' . $check['label']
        . ($check['detail'] !== '' ? '  - ' . $check['detail'] : '') . PHP_EOL;
    if ($check['status'] !== Doctor::OK && $check['remedy'] !== '') {
        echo '       -> ' . wordwrap($check['remedy'], 92, PHP_EOL . '          ') . PHP_EOL;
    }
}

$totals = Doctor::counts($checks);
echo PHP_EOL;

if ($totals[Doctor::FAIL] > 0) {
    echo $totals[Doctor::FAIL] . ' problem(s) to fix'
        . ($totals[Doctor::WARN] ? ', ' . $totals[Doctor::WARN] . ' warning(s)' : '') . '.' . PHP_EOL;
    exit(1);
}

echo 'Ready to go' . ($totals[Doctor::WARN] ? ' (' . $totals[Doctor::WARN] . ' warning(s))' : '') . '. Start the UI with:' . PHP_EOL;
echo '  php -S 127.0.0.1:8000 -t ' . WVA_ROOT . '/public' . PHP_EOL;
exit(0);
