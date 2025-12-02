<?php
namespace App\Service;

use RedBeanPHP\R as R;

class UserProfile
{
    public static function ensureColumns(): void
    {
        $columns = R::inspect('user');

        if (!isset($columns['nickname'])) {
            R::exec("ALTER TABLE `user` ADD COLUMN `nickname` VARCHAR(191) NOT NULL DEFAULT '' AFTER `email`");
        }

        if (!isset($columns['is_profile_public'])) {
            R::exec("ALTER TABLE `user` ADD COLUMN `is_profile_public` TINYINT(1) NOT NULL DEFAULT 1 AFTER `role`");
        }
    }

    public static function generateNickname(string $email): string
    {
        $localPart = explode('@', $email)[0] ?? '';
        $cleanLocal = preg_replace('/[^a-z0-9]+/i', '', strtolower($localPart));
        $base = $cleanLocal !== '' ? $cleanLocal : 'uzivatel';
        $base = substr($base, 0, 170);

        return $base . '-' . substr(hash('sha256', $email . microtime(true)), 0, 6);
    }
}
