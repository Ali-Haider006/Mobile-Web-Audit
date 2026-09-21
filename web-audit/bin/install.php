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
use Wva\Database;

$sql = file_get_contents(WVA_ROOT . '/db/schema.sql');
if ($sql === false) {
    exit("Cannot read db/schema.sql\n");
}

printf("Installing into %s@%s...\n", (string) Config::get('db_name'), (string) Config::get('db_host'));

$pdo = Database::pdo();

/**
 * Split the file into statements. Comment lines are stripped FIRST - a banner
 * comment sits in front of most CREATE TABLEs, and dropping a chunk because it
 * opens with "--" would silently skip the table that follows it.
 *
 * @return array<int,string>
 */
function wva_split_sql(string $sql): array
{
    $sql = preg_replace('~^\s*--[^\n]*$~m', '', $sql) ?? $sql;
    $parts = preg_split('/;\s*(?:\r?\n|$)/', $sql) ?: [];

    return array_values(array_filter(
        array_map('trim', $parts),
        static fn (string $statement): bool => $statement !== ''
    ));
}

$statements = wva_split_sql($sql);
printf("Found %d statement(s) in db/schema.sql\n", count($statements));

$applied = 0;
$failed  = 0;
foreach ($statements as $statement) {
    // First meaningful line, for readable progress output.
    $label = trim(explode("\n", $statement)[0]);
    try {
        $pdo->exec($statement);
        $applied++;
    } catch (Throwable $e) {
        $failed++;
        fwrite(STDERR, "  FAILED  " . $label . "\n           " . $e->getMessage() . "\n");
    }
}

printf("Applied %d statement(s)%s.\n", $applied, $failed ? ", {$failed} failed" : '');

$expected = ['sites', 'pages', 'exclusion_rules', 'audits', 'scan_runs', 'scan_items', 'tasks', 'settings'];
$tables   = array_map('strval', $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN));
$missing  = array_values(array_diff($expected, $tables));

echo "Tables: " . (implode(', ', $tables) ?: '(none)') . "\n";

if ($missing !== [] || $failed > 0) {
    fwrite(STDERR, "\nInstall incomplete. Missing: " . (implode(', ', $missing) ?: 'none') . "\n");
    fwrite(STDERR, "Fix the errors above and re-run - every statement is CREATE TABLE IF NOT EXISTS,\n");
    fwrite(STDERR, "so re-running is safe and will not touch tables that already exist.\n");
    exit(1);
}

echo "Schema is complete. Next: php bin/doctor.php --api\n";
exit(0);
