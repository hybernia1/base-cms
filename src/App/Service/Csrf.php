<?php
namespace App\Service;

class Csrf
{
    private const SESSION_KEY = '_csrf_tokens';

    public static function token(string $key): string
    {
        if (!isset($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY] = [];
        }

        if (!isset($_SESSION[self::SESSION_KEY][$key])) {
            $_SESSION[self::SESSION_KEY][$key] = bin2hex(random_bytes(32));
        }

        return (string) $_SESSION[self::SESSION_KEY][$key];
    }

    public static function regenerate(string $key): string
    {
        unset($_SESSION[self::SESSION_KEY][$key]);

        return self::token($key);
    }

    public static function validate(string $key, ?string $token): bool
    {
        $stored = $_SESSION[self::SESSION_KEY][$key] ?? null;
        if (!$stored || !$token) {
            return false;
        }

        return hash_equals((string) $stored, (string) $token);
    }
}
