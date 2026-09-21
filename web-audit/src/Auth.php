<?php
declare(strict_types=1);

namespace Wva;

/**
 * Optional shared-password gate. Set APP_PASSWORD in .env to switch it on;
 * leave it empty and the tool is open (fine behind a VPN or .htpasswd).
 */
final class Auth
{
    public static function enabled(): bool
    {
        return (string) Config::get('app_password', '') !== '';
    }

    public static function isLoggedIn(): bool
    {
        if (!self::enabled()) {
            return true;
        }
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        return !empty($_SESSION['authed']);
    }

    public static function attempt(string $password): bool
    {
        if (!hash_equals((string) Config::get('app_password', ''), $password)) {
            return false;
        }
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        session_regenerate_id(true);
        $_SESSION['authed'] = true;
        return true;
    }

    public static function logout(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        $_SESSION = [];
        session_destroy();
    }

    public static function require(): void
    {
        if (!self::isLoggedIn()) {
            Helpers::redirect('login.php');
        }
    }
}
