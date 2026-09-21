<?php
declare(strict_types=1);

namespace Wva;

final class Helpers
{
    /** Escape for HTML output. */
    public static function h(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Normalise a URL for storage/comparison: lowercase scheme+host, strip the
     * fragment, drop a trailing "/" on non-root paths, drop a default port.
     */
    public static function normalizeUrl(string $url): ?string
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }
        if (!preg_match('~^https?://~i', $url)) {
            // Any other scheme (mailto:, tel:, javascript:) is not a page.
            if (preg_match('~^[a-z][a-z0-9+.\-]*:~i', $url)) {
                return null;
            }
            $url = 'https://' . ltrim($url, '/');
        }
        $parts = parse_url($url);
        if ($parts === false || empty($parts['host'])) {
            return null;
        }
        $scheme = strtolower($parts['scheme'] ?? 'https');
        if ($scheme !== 'http' && $scheme !== 'https') {
            return null;
        }
        $host = strtolower($parts['host']);
        $port = '';
        if (!empty($parts['port']) && !(($scheme === 'http' && (int) $parts['port'] === 80) || ($scheme === 'https' && (int) $parts['port'] === 443))) {
            $port = ':' . (int) $parts['port'];
        }
        $path = $parts['path'] ?? '/';
        if ($path === '') {
            $path = '/';
        }
        if ($path !== '/' && str_ends_with($path, '/')) {
            $path = rtrim($path, '/');
        }
        $query = isset($parts['query']) && $parts['query'] !== '' ? '?' . $parts['query'] : '';

        return $scheme . '://' . $host . $port . $path . $query;
    }

    public static function hash(string $url): string
    {
        return sha1($url);
    }

    public static function pathOf(string $url): string
    {
        $parts = parse_url($url);
        $path  = ($parts['path'] ?? '/');
        if (!empty($parts['query'])) {
            $path .= '?' . $parts['query'];
        }
        return $path === '' ? '/' : substr($path, 0, 500);
    }

    public static function hostOf(string $url): string
    {
        return strtolower((string) (parse_url($url, PHP_URL_HOST) ?: ''));
    }

    /** Google's own score bands. */
    public static function scoreBand(?int $score): string
    {
        if ($score === null) {
            return 'none';
        }
        if ($score >= 90) {
            return 'good';
        }
        if ($score >= 50) {
            return 'average';
        }
        return 'poor';
    }

    public static function scoreLabel(?int $score): string
    {
        return match (self::scoreBand($score)) {
            'good'    => 'Good',
            'average' => 'Needs work',
            'poor'    => 'Poor',
            default   => 'Not audited',
        };
    }

    public static function ms(?int $value): string
    {
        if ($value === null) {
            return '—';
        }
        return $value >= 1000
            ? number_format($value / 1000, $value >= 10000 ? 1 : 2) . ' s'
            : $value . ' ms';
    }

    public static function cls(float|string|null $value): string
    {
        return $value === null ? '—' : number_format((float) $value, 3);
    }

    public static function ago(?string $datetime): string
    {
        if (!$datetime) {
            return 'never';
        }
        $then = strtotime($datetime . ' UTC');
        if ($then === false) {
            return 'never';
        }
        $diff = time() - $then;
        if ($diff < 60) {
            return 'just now';
        }
        foreach ([[31536000, 'y'], [2592000, 'mo'], [86400, 'd'], [3600, 'h'], [60, 'm']] as [$secs, $unit]) {
            if ($diff >= $secs) {
                return intdiv($diff, $secs) . $unit . ' ago';
            }
        }
        return 'just now';
    }

    /** Wildcard match used by exclusion rules: "*" matches any run of chars. */
    public static function matchesPattern(string $subject, string $pattern): bool
    {
        $pattern = trim($pattern);
        if ($pattern === '') {
            return false;
        }
        $regex = '~' . str_replace('\*', '.*', preg_quote($pattern, '~')) . '~i';
        return (bool) preg_match($regex, $subject);
    }

    public static function csrfToken(): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(16));
        }
        return (string) $_SESSION['csrf'];
    }

    public static function checkCsrf(): void
    {
        $token = (string) ($_POST['_csrf'] ?? '');
        if ($token === '' || !hash_equals(self::csrfToken(), $token)) {
            http_response_code(400);
            exit('Invalid or expired form token. Go back and try again.');
        }
    }

    public static function csrfField(): string
    {
        return '<input type="hidden" name="_csrf" value="' . self::h(self::csrfToken()) . '">';
    }

    public static function redirect(string $location): never
    {
        header('Location: ' . $location);
        exit;
    }

    public static function flash(?string $message = null, string $type = 'ok'): ?array
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        if ($message !== null) {
            $_SESSION['flash'] = ['message' => $message, 'type' => $type];
            return null;
        }
        $flash = $_SESSION['flash'] ?? null;
        unset($_SESSION['flash']);
        return $flash;
    }
}
