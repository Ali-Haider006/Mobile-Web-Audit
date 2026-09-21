<?php
declare(strict_types=1);

namespace Wva;

/**
 * Config = config/config.php defaults, overlaid with .env.
 */
final class Config
{
    /** @var array<string,mixed>|null */
    private static ?array $values = null;

    /** @return array<string,mixed> */
    public static function all(): array
    {
        if (self::$values === null) {
            $values = require WVA_ROOT . '/config/config.php';
            foreach (self::readEnvFile(WVA_ROOT . '/.env') as $key => $value) {
                $values[$key] = $value;
            }
            self::$values = $values;
        }
        return self::$values;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $all = self::all();
        return array_key_exists($key, $all) && $all[$key] !== '' ? $all[$key] : $default;
    }

    /**
     * Minimal .env reader: KEY=value, # comments, optional quotes.
     *
     * @return array<string,string>
     */
    private static function readEnvFile(string $path): array
    {
        if (!is_readable($path)) {
            return [];
        }
        $out = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $key   = strtolower(trim($key));
            $value = trim($value);
            if (strlen($value) > 1 && ($value[0] === '"' || $value[0] === "'") && $value[0] === substr($value, -1)) {
                $value = substr($value, 1, -1);
            }
            $out[$key] = $value;
        }
        return $out;
    }
}
