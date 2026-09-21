<?php
declare(strict_types=1);

/**
 * Pre-flight check. Run it before the first scan - every failure prints what
 * to do about it.
 *
 *   php bin/doctor.php          checks PHP, .env, the database and the schema
 *   php bin/doctor.php --api    also spends one API call proving the key works
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only\n");
}

define('WVA_ROOT', dirname(__DIR__));
require_once WVA_ROOT . '/src/bootstrap.php';

use Wva\Config;
use Wva\Database;
use Wva\Http;
use Wva\PageSpeed;

$failures = 0;
$warnings = 0;

function ok(string $label, string $detail = ''): void
{
    echo "  \033[32mOK\033[0m   " . $label . ($detail !== '' ? '  - ' . $detail : '') . PHP_EOL;
}

function bad(string $label, string $remedy): void
{
    global $failures;
    $failures++;
    echo "  \033[31mFAIL\033[0m " . $label . PHP_EOL . "       -> " . $remedy . PHP_EOL;
}

function warn(string $label, string $remedy): void
{
    global $warnings;
    $warnings++;
    echo "  \033[33mWARN\033[0m " . $label . PHP_EOL . "       -> " . $remedy . PHP_EOL;
}

echo "PHP\n";
if (version_compare(PHP_VERSION, '8.1.0', '>=')) {
    ok('PHP ' . PHP_VERSION);
} else {
    bad('PHP ' . PHP_VERSION . ' is too old', 'This tool needs PHP 8.1 or newer.');
}

foreach (['pdo_mysql', 'curl', 'simplexml', 'mbstring', 'json'] as $extension) {
    if (extension_loaded($extension)) {
        ok('ext ' . $extension);
    } else {
        bad('ext ' . $extension . ' missing', 'Enable it in php.ini (XAMPP/MAMP: uncomment extension=' . $extension . '), then restart PHP.');
    }
}

echo "\nConfig\n";
if (is_readable(WVA_ROOT . '/.env')) {
    ok('.env found', WVA_ROOT . '/.env');
} else {
    bad('.env missing', 'cp .env.example .env  and fill in DB_* and PSI_API_KEY.');
}

$key = trim((string) Config::get('psi_api_key', ''));
if ($key === '') {
    warn('No PSI_API_KEY in .env', 'Scans still run, but Google rate-limits unkeyed requests to a trickle. You can also set it on the Settings screen.');
} else {
    ok('PSI_API_KEY set', substr($key, 0, 6) . str_repeat('*', max(0, strlen($key) - 6)));
}

echo "\nDatabase\n";
$dbUp = false;
try {
    $pdo = Database::pdo();
    $version = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
    ok('Connected', (string) Config::get('db_name') . '@' . (string) Config::get('db_host') . ' - MySQL ' . $version);
    $dbUp = true;
} catch (Throwable $e) {
    bad('Cannot connect: ' . $e->getMessage(),
        'Start MySQL, create the database, and check DB_HOST/DB_PORT/DB_NAME/DB_USER/DB_PASS in .env.');
}

if ($dbUp) {
    $expected = ['sites', 'pages', 'exclusion_rules', 'audits', 'scan_runs', 'scan_items', 'tasks', 'settings'];
    $present  = array_map('strval', $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN));
    $missing  = array_values(array_diff($expected, $present));
    if ($missing === []) {
        $sites = (int) Database::value('SELECT COUNT(*) FROM sites');
        $pages = (int) Database::value('SELECT COUNT(*) FROM pages');
        $audits = (int) Database::value('SELECT COUNT(*) FROM audits');
        ok('Schema loaded', count($expected) . ' tables - ' . $sites . ' site(s), ' . $pages . ' page(s), ' . $audits . ' audit(s)');
    } else {
        bad('Missing tables: ' . implode(', ', $missing), 'php bin/install.php   (or: mysql -u USER -p DBNAME < db/schema.sql)');
    }
}

echo "\nNetwork\n";
if (in_array('--api', $argv, true)) {
    try {
        $url = PageSpeed::ENDPOINT . '?url=https%3A%2F%2Fexample.com&strategy=mobile&category=PERFORMANCE'
            . ($key !== '' ? '&key=' . rawurlencode($key) : '');
        $response = Http::get($url, 90, 1);
        $payload  = json_decode($response['body'], true);
        if (isset($payload['error'])) {
            bad('PageSpeed API rejected the call: ' . (string) $payload['error']['message'],
                'Check the key, and that "PageSpeed Insights API" is enabled for its Google Cloud project.');
        } elseif ($response['status'] === 200) {
            $score = $payload['lighthouseResult']['categories']['performance']['score'] ?? null;
            ok('PageSpeed API answered', 'example.com scored ' . ($score === null ? 'n/a' : (int) round(((float) $score) * 100)) . ' on mobile');
        } else {
            bad('PageSpeed API returned HTTP ' . $response['status'], 'Check outbound HTTPS access and the key.');
        }
    } catch (Throwable $e) {
        bad('Cannot reach the PageSpeed API: ' . $e->getMessage(),
            'Check this machine can make outbound HTTPS calls (proxy? firewall?).');
    }
} else {
    echo "  ..   Skipped the live API call. Re-run with --api to spend one request proving the key works.\n";
}

echo "\n";
if ($failures > 0) {
    echo $failures . " problem(s) to fix" . ($warnings ? ", " . $warnings . " warning(s)" : "") . ".\n";
    exit(1);
}
echo "Ready to go" . ($warnings ? " (" . $warnings . " warning(s))" : "") . ". Start the UI with:\n";
echo "  php -S 127.0.0.1:8000 -t " . WVA_ROOT . "/public\n";
exit(0);
