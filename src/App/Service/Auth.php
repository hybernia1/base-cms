<?php
namespace App\Service;

use RedBeanPHP\R as R;
use App\Service\Flash;
use App\Service\PasswordReset;
use App\Service\EmailTemplateManager;

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
        $genericError = 'Neplatný e-mail nebo heslo.';

        if (!$user) {
            return ['success' => false, 'message' => $genericError];
        }

        if (self::isLocked($user)) {
            return [
                'success' => false,
                'message' => 'Účet je dočasně uzamčen kvůli opakovaným neúspěšným pokusům. Zkuste to prosím později nebo použijte odkaz pro reset hesla.',
            ];
        }

        if (!password_verify($password, $user->password)) {
            self::recordFailedAttempt($user);
            return ['success' => false, 'message' => $genericError];
        }

        if ((int) ($user->is_banned ?? 0) === 1) {
            return ['success' => false, 'message' => 'Účet je zablokovaný.'];
        }

        $_SESSION['user_id'] = $user->id;
        self::recordSuccessfulLogin($user);
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

    private static function recordSuccessfulLogin($user): void
    {
        $user->failed_attempts = 0;
        $user->locked_until = null;
        $user->last_login_at = date('Y-m-d H:i:s');
        $user->last_login_ip = RequestHelper::clientIp();
        R::store($user);

        $log = R::dispense('loginlog');
        $log->user_id = $user->id;
        $log->ip_address = $user->last_login_ip;
        $log->created_at = $user->last_login_at;
        R::store($log);
    }

    private static function recordFailedAttempt($user): void
    {
        $user->failed_attempts = (int) ($user->failed_attempts ?? 0) + 1;

        if ($user->failed_attempts >= 5) {
            $user->locked_until = date('Y-m-d H:i:s', time() + 2 * 60 * 60);
            $token = PasswordReset::createToken($user);
            $resetLink = PasswordReset::buildResetLink($token);
            EmailTemplateManager::send('user_password_reset', $user->email, [
                'email' => $user->email,
                'reset_link' => $resetLink,
            ]);
        }

        R::store($user);
    }

    private static function isLocked($user): bool
    {
        if (empty($user->locked_until)) {
            return false;
        }

        return strtotime((string) $user->locked_until) > time();
    }
}
