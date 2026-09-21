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
$statements = array_filter(
    array_map('trim', preg_split('/;\s*\n/', $sql) ?: []),
    static fn (string $statement): bool => $statement !== '' && !str_starts_with($statement, '--')
);

$applied = 0;
foreach ($statements as $statement) {
    try {
        $pdo->exec($statement);
        $applied++;
    } catch (Throwable $e) {
        fwrite(STDERR, "Failed: " . substr($statement, 0, 60) . "...\n  " . $e->getMessage() . "\n");
    }
}

printf("Applied %d statement(s).\n", $applied);

$tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
echo "Tables: " . implode(', ', array_map('strval', $tables)) . "\n";
