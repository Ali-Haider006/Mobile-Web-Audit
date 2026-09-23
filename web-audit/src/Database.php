<?php
declare(strict_types=1);

namespace Wva;

use PDO;
use PDOException;
use RuntimeException;

final class Database
{
    /**
     * Seconds to wait for the SQL server to answer. Without a limit PDO waits
     * on the OS, which on a filtered port means every page hangs until
     * max_execution_time and returns a blank 500 - the least diagnosable
     * failure there is. A remote SQL server that has not allow-listed the web
     * server's IP fails exactly this way.
     */
    private const CONNECT_TIMEOUT = 10;

    private static ?PDO $pdo = null;

    /**
     * Connect with no database selected and report what this account can see.
     *
     * A host hands you four things - hostname, IP, username, password - and
     * never the database name, which is the one setting that cannot be
     * guessed. Rather than leave someone typing names until one sticks, ask
     * the server.
     *
     * Read-only: it lists and reads grants, it never creates anything.
     *
     * @return array{ok:bool,error:string,version:string,databases:array<int,string>,can_create:bool,grants:array<int,string>}
     */
    public static function probe(): array
    {
        $out = ['ok' => false, 'error' => '', 'version' => '', 'databases' => [], 'can_create' => false, 'grants' => []];

        $dsn = sprintf(
            'mysql:host=%s;port=%s;charset=utf8mb4',
            (string) Config::get('db_host', '127.0.0.1'),
            (string) Config::get('db_port', '3306')
        );

        try {
            $pdo = new PDO($dsn, (string) Config::get('db_user', 'root'), (string) Config::get('db_pass', ''), [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_TIMEOUT            => self::CONNECT_TIMEOUT,
            ]);
        } catch (PDOException $e) {
            $out['error'] = $e->getMessage();
            return $out;
        }

        $out['ok']      = true;
        $out['version'] = (string) $pdo->query('SELECT VERSION()')->fetchColumn();

        // The server's own databases are never where an app's tables belong.
        $system = ['information_schema', 'performance_schema', 'mysql', 'sys'];
        foreach ($pdo->query('SHOW DATABASES')->fetchAll(PDO::FETCH_COLUMN) as $name) {
            if (!in_array((string) $name, $system, true)) {
                $out['databases'][] = (string) $name;
            }
        }

        try {
            foreach ($pdo->query('SHOW GRANTS')->fetchAll(PDO::FETCH_COLUMN) as $grant) {
                $out['grants'][] = (string) $grant;
                if (preg_match('/\b(ALL PRIVILEGES|CREATE)\b[^@]*\bON\s+(\*\.\*|`?%`?\.)/i', (string) $grant) === 1) {
                    $out['can_create'] = true;
                }
            }
        } catch (PDOException $e) {
            // Some managed hosts revoke SHOW GRANTS. Not knowing is fine.
        }

        return $out;
    }

    public static function pdo(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            (string) Config::get('db_host', '127.0.0.1'),
            (string) Config::get('db_port', '3306'),
            (string) Config::get('db_name', 'web_audit')
        );

        try {
            self::$pdo = new PDO($dsn, (string) Config::get('db_user', 'root'), (string) Config::get('db_pass', ''), [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_TIMEOUT            => self::CONNECT_TIMEOUT,
            ]);
            self::$pdo->exec("SET time_zone = '+00:00'");
        } catch (PDOException $e) {
            throw new RuntimeException(
                'Cannot connect to MySQL. Check the database settings in config/local.php or .env. (' . $e->getMessage() . ')',
                0,
                $e
            );
        }

        return self::$pdo;
    }

    /** @param array<string|int,mixed> $params */
    public static function run(string $sql, array $params = []): \PDOStatement
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    /**
     * @param array<string|int,mixed> $params
     * @return array<int,array<string,mixed>>
     */
    public static function all(string $sql, array $params = []): array
    {
        return self::run($sql, $params)->fetchAll();
    }

    /**
     * @param array<string|int,mixed> $params
     * @return array<string,mixed>|null
     */
    public static function one(string $sql, array $params = []): ?array
    {
        $row = self::run($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    /** @param array<string|int,mixed> $params */
    public static function value(string $sql, array $params = []): mixed
    {
        $value = self::run($sql, $params)->fetchColumn();
        return $value === false ? null : $value;
    }

    public static function insertId(): int
    {
        return (int) self::pdo()->lastInsertId();
    }

    public static function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }
}
