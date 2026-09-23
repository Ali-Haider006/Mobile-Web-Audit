<?php
declare(strict_types=1);

namespace Wva;

/**
 * Settings stored in the `settings` table, falling back to Config (.env).
 */
final class Settings
{
    /** @var array<string,string>|null */
    private static ?array $cache = null;

    public static function get(string $name, mixed $default = null): mixed
    {
        if (self::$cache === null) {
            self::$cache = [];
            foreach (Database::all('SELECT name, value FROM settings') as $row) {
                self::$cache[(string) $row['name']] = (string) $row['value'];
            }
        }
        if (isset(self::$cache[$name]) && self::$cache[$name] !== '') {
            return self::$cache[$name];
        }
        return Config::get($name, $default);
    }

    public static function set(string $name, string $value): void
    {
        Database::run(
            'INSERT INTO settings (name, value, updated_at) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE value = VALUES(value), updated_at = VALUES(updated_at)',
            [$name, $value, Database::now()]
        );
        self::$cache = null;
    }

    /** Global pass mark. A site may override it. */
    public static function threshold(): int
    {
        $value = (int) self::get('score_threshold', 80);
        return $value > 0 && $value <= 100 ? $value : 80;
    }

    /**
     * Secrets are read from .env / config/local.php, never written or edited
     * through the UI. The settings table is still consulted as a fallback so
     * an install that saved the key before this change keeps working - Doctor
     * flags that case and asks for it to be moved.
     */
    public static function apiKey(): string
    {
        $fromFile = trim((string) Config::get('psi_api_key', ''));
        if ($fromFile !== '') {
            return $fromFile;
        }
        return trim((string) self::storedOnly('psi_api_key'));
    }

    /** A value that exists only in the settings table, ignoring config files. */
    public static function storedOnly(string $name): string
    {
        if (self::$cache === null) {
            self::get($name);   // primes the cache
        }
        return trim((string) (self::$cache[$name] ?? ''));
    }
}
