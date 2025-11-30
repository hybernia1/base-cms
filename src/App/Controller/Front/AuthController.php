<?php
namespace App\Controller\Front;

use App\Service\EmailTemplateManager;
use App\Service\Flash;
use App\Service\Setting;
use RedBeanPHP\R as R;

class AuthController extends BaseFrontController
{
    public function registerForm()
    {
        if (!$this->isRegistrationEnabled()) {
            Flash::addError('Registrace jsou aktuálně vypnuté.');
            header('Location: /');
            exit;
        }

        $this->render('front/register.twig', [
            'values' => [
                'email' => '',
            ],
            'errors' => [],
        ]);
    }

    public function register()
    {
        if (!$this->isRegistrationEnabled()) {
            Flash::addError('Registrace jsou aktuálně vypnuté.');
            header('Location: /');
            exit;
        }

        $data = $this->sanitize();
        $errors = $this->validate($data);

        if ($this->emailExists($data['email'])) {
            $errors['email'] = 'Uživatel s tímto e-mailem již existuje.';
        }

        if ($errors) {
            $this->render('front/register.twig', [
                'values' => $data,
                'errors' => $errors,
            ]);
            return;
        }

        $user = R::dispense('user');
        $user->email = $data['email'];
        $user->password = password_hash($data['password'], PASSWORD_DEFAULT);
        $user->role = 'user';
        $user->is_banned = 0;
        $user->created_at = date('Y-m-d H:i:s');
        $user->updated_at = date('Y-m-d H:i:s');
        R::store($user);

        EmailTemplateManager::send('user_registered', $user->email, [
            'email' => $user->email,
        ]);

        Flash::addSuccess('Registrace proběhla úspěšně. Nyní se můžete přihlásit.');
        header('Location: /');
        exit;
    }

    private function sanitize(): array
    {
        return [
            'email' => trim($_POST['email'] ?? ''),
            'password' => $_POST['password'] ?? '',
            'password_confirm' => $_POST['password_confirm'] ?? '',
        ];
    }

    private function validate(array $data): array
    {
        $errors = [];

        if ($data['email'] === '' || !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Zadejte platný e-mail.';
        }

        if (strlen($data['password']) < 6) {
            $errors['password'] = 'Heslo musí mít alespoň 6 znaků.';
        }

        if ($data['password'] !== $data['password_confirm']) {
            $errors['password_confirm'] = 'Hesla se neshodují.';
        }

        return $errors;
    }

    private function emailExists(string $email): bool
    {
        return (bool) R::findOne('user', ' email = ? ', [$email]);
    }

    private function isRegistrationEnabled(): bool
    {
        return Setting::get('allow_registration', '1') === '1';
    }
}
