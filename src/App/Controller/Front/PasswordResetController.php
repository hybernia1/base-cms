<?php
namespace App\Controller\Front;

use App\Service\EmailTemplateManager;
use App\Service\Flash;
use App\Service\PasswordReset;
use RedBeanPHP\R as R;

class PasswordResetController extends BaseFrontController
{
    public function requestForm(): void
    {
        $this->render('front/password_reset/request.twig', [
            'values' => ['email' => ''],
            'errors' => [],
        ]);
    }

    public function sendLink(): void
    {
        $email = trim($_POST['email'] ?? '');
        $errors = [];

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Zadejte platný e-mail.';
        }

        if ($errors) {
            $this->render('front/password_reset/request.twig', [
                'values' => ['email' => $email],
                'errors' => $errors,
            ]);
            return;
        }

        $user = R::findOne('user', ' email = ? ', [$email]);
        if ($user) {
            $token = PasswordReset::createToken($user);
            $resetLink = PasswordReset::buildResetLink($token);
            EmailTemplateManager::send('user_password_reset', $user->email, [
                'email' => $user->email,
                'reset_link' => $resetLink,
            ]);
        }

        Flash::addSuccess('Pokud účet existuje, posíláme instrukce pro reset hesla na zadaný e-mail.');
        header('Location: /login');
        exit;
    }

    public function resetForm($token): void
    {
        $tokenRow = PasswordReset::findValidToken($token);
        if (!$tokenRow) {
            Flash::addError('Odkaz pro reset hesla je neplatný nebo expirovaný.');
            header('Location: /password-reset');
            exit;
        }

        $this->render('front/password_reset/reset.twig', [
            'token' => $token,
            'errors' => [],
        ]);
    }

    public function reset($token): void
    {
        $tokenRow = PasswordReset::findValidToken($token);
        if (!$tokenRow) {
            Flash::addError('Odkaz pro reset hesla je neplatný nebo expirovaný.');
            header('Location: /password-reset');
            exit;
        }

        $password = $_POST['password'] ?? '';
        $confirm = $_POST['password_confirm'] ?? '';
        $errors = [];

        if (strlen($password) < 6) {
            $errors['password'] = 'Heslo musí mít alespoň 6 znaků.';
        }

        if ($password !== $confirm) {
            $errors['password_confirm'] = 'Hesla se musí shodovat.';
        }

        if ($errors) {
            $this->render('front/password_reset/reset.twig', [
                'token' => $token,
                'errors' => $errors,
            ]);
            return;
        }

        $user = R::load('user', (int) $tokenRow->user_id);
        if (!$user || !$user->id) {
            Flash::addError('Uživatel již neexistuje.');
            header('Location: /password-reset');
            exit;
        }

        $user->password = password_hash($password, PASSWORD_DEFAULT);
        R::store($user);
        PasswordReset::markUsed($tokenRow, $user);

        Flash::addSuccess('Heslo bylo úspěšně změněno. Nyní se můžete přihlásit.');
        header('Location: /login');
        exit;
    }
}
