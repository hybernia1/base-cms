<?php
namespace App\Service;

class UserProfile
{
    public static function generateNickname(string $email): string
    {
        $localPart = explode('@', $email)[0] ?? '';
        $cleanLocal = preg_replace('/[^a-z0-9]+/i', '', strtolower($localPart));
        $base = $cleanLocal !== '' ? $cleanLocal : 'uzivatel';
        $base = substr($base, 0, 170);

        return $base . '-' . substr(hash('sha256', $email . microtime(true)), 0, 6);
    }
}
