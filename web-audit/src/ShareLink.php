<?php
declare(strict_types=1);

namespace Wva;

/**
 * Read-only public links to a report.
 *
 * The person fixing a page often has no login here - a developer, or the
 * client. A share link gives them the audit without an account, while staying
 * revocable and countable.
 *
 * Tokens are 16 random bytes rendered as 32 hex characters. That is the whole
 * security model, so it is deliberately not derived from anything guessable
 * like a page id or a timestamp.
 */
final class ShareLink
{
    public const KIND_PAGE = 'page';
    public const KIND_RUN  = 'run';

    /** Existing live link for a target, or a new one. */
    public static function ensure(string $kind, int $targetId, ?string $label = null): array
    {
        $existing = self::liveFor($kind, $targetId);
        if ($existing !== null) {
            return $existing;
        }
        return self::create($kind, $targetId, $label);
    }

    public static function create(string $kind, int $targetId, ?string $label = null, ?string $expiresAt = null): array
    {
        $token = bin2hex(random_bytes(16));
        Database::run(
            'INSERT INTO share_links (token, kind, target_id, label, created_at, expires_at)
             VALUES (?, ?, ?, ?, ?, ?)',
            [$token, $kind, $targetId, $label !== null ? substr($label, 0, 190) : null, Database::now(), $expiresAt]
        );

        return self::byToken($token) ?? throw new \RuntimeException('Could not create the share link.');
    }

    /** @return array<string,mixed>|null */
    public static function byToken(string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
            return null;   // never let a malformed token reach the database
        }
        return Database::one('SELECT * FROM share_links WHERE token = ?', [$token]);
    }

    /** A token that is usable right now: exists, not revoked, not expired. */
    public static function resolve(string $token): ?array
    {
        $link = self::byToken($token);
        if ($link === null || $link['revoked_at'] !== null) {
            return null;
        }
        if ($link['expires_at'] !== null && strtotime((string) $link['expires_at'] . ' UTC') < time()) {
            return null;
        }
        return $link;
    }

    /** @return array<string,mixed>|null */
    public static function liveFor(string $kind, int $targetId): ?array
    {
        return Database::one(
            'SELECT * FROM share_links
             WHERE kind = ? AND target_id = ? AND revoked_at IS NULL
               AND (expires_at IS NULL OR expires_at > UTC_TIMESTAMP())
             ORDER BY id DESC LIMIT 1',
            [$kind, $targetId]
        );
    }

    public static function revoke(int $id): void
    {
        Database::run(
            'UPDATE share_links SET revoked_at = ? WHERE id = ? AND revoked_at IS NULL',
            [Database::now(), $id]
        );
    }

    public static function recordView(int $id): void
    {
        Database::run(
            'UPDATE share_links SET views = views + 1, last_viewed_at = ? WHERE id = ?',
            [Database::now(), $id]
        );
    }

    /**
     * The absolute URL. Needs app_url, because a background job has no request
     * to infer the host from.
     */
    public static function url(string $token): string
    {
        $base = rtrim((string) Settings::get('app_url', ''), '/');
        if ($base === '') {
            $base = self::guessBaseUrl();
        }
        return $base === '' ? 'share.php?t=' . $token : $base . '/share.php?t=' . $token;
    }

    /** Best effort from the current request; empty in CLI. */
    public static function guessBaseUrl(): string
    {
        $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
        if ($host === '') {
            return '';
        }
        $https = (($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off')
            || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
        $dir = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/'))), '/');

        return ($https ? 'https://' : 'http://') . $host . $dir;
    }
}
