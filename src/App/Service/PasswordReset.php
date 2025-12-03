<?php
namespace App\Service;

use RedBeanPHP\R as R;

class PasswordReset
{
    private const TABLE = 'passwordreset';

    public static function createToken($user): string
    {
        self::ensureSchema();
        $token = bin2hex(random_bytes(32));
        $hash = hash('sha256', $token);

        $existing = R::findOne(self::TABLE, ' user_id = ? AND used_at IS NULL AND expires_at > NOW() ', [$user->id]);
        if ($existing) {
            $existing->token_hash = $hash;
            $existing->expires_at = date('Y-m-d H:i:s', time() + 2 * 60 * 60);
            $existing->created_at = date('Y-m-d H:i:s');
            R::store($existing);
        } else {
            $reset = R::dispense(self::TABLE);
            $reset->user_id = $user->id;
            $reset->token_hash = $hash;
            $reset->expires_at = date('Y-m-d H:i:s', time() + 2 * 60 * 60);
            $reset->created_at = date('Y-m-d H:i:s');
            $reset->used_at = null;
            R::store($reset);
        }

        return $token;
    }

    public static function buildResetLink(string $token): string
    {
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        return $scheme . '://' . $host . '/password-reset/' . $token;
    }

    public static function findValidToken(string $token)
    {
        self::ensureSchema();
        $hash = hash('sha256', $token);
        return R::findOne(
            self::TABLE,
            ' token_hash = ? AND used_at IS NULL AND expires_at > NOW() ',
            [$hash]
        );
    }

    public static function markUsed($tokenRow, $user): void
    {
        $tokenRow->used_at = date('Y-m-d H:i:s');
        R::store($tokenRow);

        $user->failed_attempts = 0;
        $user->locked_until = null;
        R::store($user);
    }

    private static function ensureSchema(): void
    {
        // Spravováno instalační logikou.
    }
}
