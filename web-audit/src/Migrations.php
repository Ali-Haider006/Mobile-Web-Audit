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

            // Scheduled monitoring: where a URL's task goes, who owns it, and
            // how many times its audit has failed in a row.
            ['table' => 'pages', 'column' => 'clickup_list_id',     'definition' => 'VARCHAR(64) DEFAULT NULL'],
            ['table' => 'pages', 'column' => 'clickup_assignee_id', 'definition' => 'INT UNSIGNED DEFAULT NULL'],
            ['table' => 'pages', 'column' => 'consecutive_errors',  'definition' => 'TINYINT UNSIGNED NOT NULL DEFAULT 0'],
            ['table' => 'pages', 'column' => 'error_alerted_at',    'definition' => 'DATETIME DEFAULT NULL'],

            // A re-failure opens a new task that points back at the old one.
            ['table' => 'tasks', 'column' => 'previous_task_id',  'definition' => 'INT UNSIGNED DEFAULT NULL'],
            ['table' => 'tasks', 'column' => 'clickup_task_id',   'definition' => 'VARCHAR(64) DEFAULT NULL'],
            ['table' => 'tasks', 'column' => 'clickup_task_url',  'definition' => 'VARCHAR(500) DEFAULT NULL'],
            ['table' => 'tasks', 'column' => 'clickup_synced_at', 'definition' => 'DATETIME DEFAULT NULL'],
            ['table' => 'tasks', 'column' => 'clickup_error',     'definition' => 'VARCHAR(500) DEFAULT NULL'],
        ];
    }

    /**
     * Tables added after the first release. schema.sql carries these too, for
     * fresh installs; this is what upgrades an existing database.
     *
     * @return array<string,string> table => CREATE statement
     */
    public static function tables(): array
    {
        return [
            'share_links' => "CREATE TABLE IF NOT EXISTS share_links (
                id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
                token          CHAR(32)     NOT NULL,
                kind           ENUM('page','run') NOT NULL,
                target_id      INT UNSIGNED NOT NULL,
                label          VARCHAR(190) DEFAULT NULL,
                created_at     DATETIME     NOT NULL,
                expires_at     DATETIME     DEFAULT NULL,
                revoked_at     DATETIME     DEFAULT NULL,
                views          INT UNSIGNED NOT NULL DEFAULT 0,
                last_viewed_at DATETIME     DEFAULT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_share_token (token),
                KEY idx_share_target (kind, target_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
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

        foreach (self::tables() as $table => $statement) {
            try {
                if (self::tableExists($table)) {
                    $skipped++;
                    continue;
                }
                Database::run($statement);
                self::forgetCache();
                $applied[] = 'table ' . $table;
            } catch (\Throwable $e) {
                $errors[] = 'table ' . $table . ': ' . $e->getMessage();
            }
        }

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

        $tables = array_values(array_unique(array_merge(
            array_column(self::columns(), 'table'),
            array_keys(self::tables())
        )));
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

    /** @return array<int,string> tables and columns still missing after a run */
    public static function pending(): array
    {
        $missing = [];
        foreach (array_keys(self::tables()) as $table) {
            if (!self::tableExists($table)) {
                $missing[] = 'table ' . $table;
            }
        }
        foreach (self::columns() as $change) {
            if (self::tableExists($change['table']) && !self::columnExists($change['table'], $change['column'])) {
                $missing[] = $change['table'] . '.' . $change['column'];
            }
        }
        return $missing;
    }
}
