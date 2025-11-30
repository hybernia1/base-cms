<?php
namespace App\Service;

use RedBeanPHP\R as R;

class Auth
{
    public static function requireLogin(): void
    {
        if (empty($_SESSION['user_id'])) {
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
        return R::load('user', (int) $_SESSION['user_id']);
    }

    public static function logout(): void
    {
        unset($_SESSION['user_id']);
        header('Location: /admin/login');
        exit;
    }
}
