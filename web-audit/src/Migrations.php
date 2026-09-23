<?php
declare(strict_types=1);

namespace Wva;

/**
 * Column additions for databases created by an older schema.sql.
 *
 * schema.sql only uses CREATE TABLE IF NOT EXISTS, so it never alters a table
 * that already exists - an install done before a column was added would
 * silently stay behind. These run after it, add only what is missing, and are
 * safe to run repeatedly.
 */
final class Migrations
{
    /** @return array<int,array{table:string,column:string,definition:string}> */
    public static function columns(): array
    {
        return [
            // ClickUp integration
            ['table' => 'sites', 'column' => 'clickup_list_id',   'definition' => 'VARCHAR(64) DEFAULT NULL'],
            ['table' => 'tasks', 'column' => 'clickup_task_id',   'definition' => 'VARCHAR(64) DEFAULT NULL'],
            ['table' => 'tasks', 'column' => 'clickup_task_url',  'definition' => 'VARCHAR(500) DEFAULT NULL'],
            ['table' => 'tasks', 'column' => 'clickup_synced_at', 'definition' => 'DATETIME DEFAULT NULL'],
            ['table' => 'tasks', 'column' => 'clickup_error',     'definition' => 'VARCHAR(500) DEFAULT NULL'],
        ];
    }

    /**
     * @return array{applied:array<int,string>, skipped:int, errors:array<int,string>}
     */
    public static function run(): array
    {
        $applied = [];
        $skipped = 0;
        $errors  = [];

        foreach (self::columns() as $change) {
            $table  = $change['table'];
            $column = $change['column'];

            try {
                if (!self::tableExists($table)) {
                    continue;   // schema.sql will create it with the column already present
                }
                if (self::columnExists($table, $column)) {
                    $skipped++;
                    continue;
                }
                // Identifiers come from the hard-coded list above, never input.
                Database::run('ALTER TABLE `' . $table . '` ADD COLUMN `' . $column . '` ' . $change['definition']);
                self::forgetCache();
                $applied[] = $table . '.' . $column;
            } catch (\Throwable $e) {
                $errors[] = $table . '.' . $column . ': ' . $e->getMessage();
            }
        }

        return ['applied' => $applied, 'skipped' => $skipped, 'errors' => $errors];
    }

    /** @var array<string,array<string,bool>>|null table => column => true */
    private static ?array $schemaCache = null;

    /**
     * Every column of the tables we care about, in one query. pending() runs on
     * every page load, so this must not be five information_schema round trips.
     *
     * @return array<string,array<string,bool>>
     */
    private static function existingColumns(): array
    {
        if (self::$schemaCache !== null) {
            return self::$schemaCache;
        }

        $tables = array_values(array_unique(array_column(self::columns(), 'table')));
        if ($tables === []) {
            return self::$schemaCache = [];
        }
        $placeholders = implode(',', array_fill(0, count($tables), '?'));

        $map = [];
        foreach (Database::all(
            'SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (' . $placeholders . ')',
            $tables
        ) as $row) {
            $map[(string) $row['TABLE_NAME']][(string) $row['COLUMN_NAME']] = true;
        }

        return self::$schemaCache = $map;
    }

    /** Call after altering anything, so the next check re-reads the schema. */
    public static function forgetCache(): void
    {
        self::$schemaCache = null;
    }

    public static function tableExists(string $table): bool
    {
        return isset(self::existingColumns()[$table]);
    }

    public static function columnExists(string $table, string $column): bool
    {
        return isset(self::existingColumns()[$table][$column]);
    }

    /** @return array<int,string> columns still missing after a run */
    public static function pending(): array
    {
        $missing = [];
        foreach (self::columns() as $change) {
            if (self::tableExists($change['table']) && !self::columnExists($change['table'], $change['column'])) {
                $missing[] = $change['table'] . '.' . $change['column'];
            }
        }
        return $missing;
    }
}
