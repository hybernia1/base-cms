<?php
namespace App\Service;

use RedBeanPHP\R as R;

class Auth
{
    public static function requireLogin(): void
    {
        if (!self::user()) {
            header('Location: /admin/login');
            exit;
        }
    }

    public static function attempt(string $email, string $password): bool
    {
        $user = R::findOne('user', ' email = ? ', [$email]);
        if (!$user) {
            return false;
        }

        if (!password_verify($password, $user->password)) {
            return false;
        }

        $_SESSION['user_id'] = $user->id;
        return true;
    }

    public static function user()
    {
        if (empty($_SESSION['user_id'])) {
            return null;
        }
        $user = R::load('user', (int) $_SESSION['user_id']);
        return $user && $user->id ? $user : null;
    }

    public static function hasRole($roles): bool
    {
        $user = self::user();
        if (!$user) {
            return false;
        }

        $roleList = is_array($roles) ? $roles : [$roles];
        return in_array($user->role, $roleList, true);
    }

    public static function requireRole($roles): void
    {
        if (self::hasRole($roles)) {
            return;
        }

        Flash::addError('Nemáš oprávnění pro tuto akci.');
        header('Location: /admin');
        exit;
    }

    public static function logout(): void
    {
        unset($_SESSION['user_id']);
        header('Location: /admin/login');
        exit;
    }
}
