<?php
declare(strict_types=1);

/**
 * Loads db/schema.sql into the database named in .env.
 *   php bin/install.php
 * Safe to re-run: every statement is CREATE TABLE IF NOT EXISTS.
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only\n");
}

define('WVA_ROOT', dirname(__DIR__));
require_once WVA_ROOT . '/src/bootstrap.php';

use Wva\Config;
use Wva\Doctor;

printf("Installing into %s@%s...\n", (string) Config::get('db_name'), (string) Config::get('db_host'));

$result = Doctor::installSchema();

printf("Applied %d statement(s)%s.\n", $result['applied'], $result['failed'] ? ", {$result['failed']} failed" : '');
foreach ($result['errors'] as $error) {
    fwrite(STDERR, "  FAILED  " . $error . "\n");
}

if ($result['missing'] !== [] || $result['failed'] > 0) {
    fwrite(STDERR, "\nInstall incomplete. Missing: " . (implode(', ', $result['missing']) ?: 'none') . "\n");
    fwrite(STDERR, "Fix the errors above and re-run - every statement is CREATE TABLE IF NOT EXISTS,\n");
    fwrite(STDERR, "so re-running is safe and will not touch tables that already exist.\n");
    exit(1);
}

echo "Schema is complete. Next: php bin/doctor.php --api\n";
exit(0);
