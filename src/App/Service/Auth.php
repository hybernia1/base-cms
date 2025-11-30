<?php
namespace App\Service;

use RedBeanPHP\R as R;
use App\Service\Flash;

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
        $result = self::attemptDetailed($email, $password);
        return $result['success'];
    }

    public static function attemptDetailed(string $email, string $password): array
    {
        $user = R::findOne('user', ' email = ? ', [$email]);
        if (!$user) {
            return ['success' => false, 'message' => 'Neplatný e-mail nebo heslo.'];
        }

        if (!password_verify($password, $user->password)) {
            return ['success' => false, 'message' => 'Neplatný e-mail nebo heslo.'];
        }

        if ((int) ($user->is_banned ?? 0) === 1) {
            return ['success' => false, 'message' => 'Účet je zablokovaný.'];
        }

        $_SESSION['user_id'] = $user->id;
        return ['success' => true, 'message' => null, 'user' => $user];
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

    public static function requirePanelAccess(): void
    {
        $user = self::user();
        if (!$user) {
            header('Location: /admin/login');
            exit;
        }

        if ((int) ($user->is_banned ?? 0) === 1) {
            Flash::addError('Tvůj účet je zablokován.');
            self::logout();
        }

        if (!self::hasRole(['admin', 'editor'])) {
            Flash::addError('Nemáš oprávnění pro přístup do administrace.');
            header('Location: /');
            exit;
        }
    }

    public static function logout(): void
    {
        unset($_SESSION['user_id']);
        header('Location: /admin/login');
        exit;
    }
}
