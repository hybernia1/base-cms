<?php
namespace App\Controller\Front;

use App\Service\Auth;
use App\Service\Captcha;
use App\Service\EmailTemplateManager;
use App\Service\Flash;
use App\Service\Setting;
use App\Service\UserProfile;
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
            'captcha' => $this->captchaContext('register'),
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
                'captcha' => $this->captchaContext('register'),
            ]);
            return;
        }

        $user = R::dispense('user');
        $user->email = $data['email'];
        $user->password = password_hash($data['password'], PASSWORD_DEFAULT);
        $user->role = 'user';
        $user->nickname = UserProfile::generateNickname($data['email']);
        $user->is_profile_public = 1;
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

    public function loginForm()
    {
        $this->render('front/login.twig', [
            'values' => [
                'email' => '',
            ],
            'errors' => [],
            'captcha' => $this->captchaContext('login'),
        ]);
    }

    public function login()
    {
        $data = $this->sanitizeLogin();
        $errors = [];

        if ($data['email'] === '' || !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Zadejte platný e-mail.';
        }

        if ($data['password'] === '') {
            $errors['password'] = 'Zadejte heslo.';
        }

        if (Captcha::isEnabledFor('login') && !Captcha::validate('login', $data['captcha'] ?? null)) {
            $errors['captcha'] = 'Prosím opište text z obrázku.';
        }

        if ($errors) {
            $this->render('front/login.twig', [
                'values' => $data,
                'errors' => $errors,
                'captcha' => $this->captchaContext('login'),
            ]);
            return;
        }

        $result = Auth::attemptDetailed($data['email'], $data['password']);
        if (!$result['success']) {
            Flash::addError($result['message'] ?? 'Přihlášení se nezdařilo.');
            $this->render('front/login.twig', [
                'values' => ['email' => $data['email']],
                'errors' => [],
                'captcha' => $this->captchaContext('login'),
            ]);
            return;
        }

        Flash::addSuccess('Přihlášení proběhlo úspěšně.');
        header('Location: /profile');
        exit;
    }

    public function logout(): void
    {
        unset($_SESSION['user_id']);
        Flash::addSuccess('Byli jste odhlášeni.');
        header('Location: /');
        exit;
    }

    private function sanitize(): array
    {
        return [
            'email' => trim($_POST['email'] ?? ''),
            'password' => $_POST['password'] ?? '',
            'password_confirm' => $_POST['password_confirm'] ?? '',
            'captcha' => trim($_POST['captcha'] ?? ''),
        ];
    }

    private function sanitizeLogin(): array
    {
        return [
            'email' => trim($_POST['email'] ?? ''),
            'password' => $_POST['password'] ?? '',
            'captcha' => trim($_POST['captcha'] ?? ''),
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

        if (Captcha::isEnabledFor('register') && !Captcha::validate('register', $data['captcha'] ?? null)) {
            $errors['captcha'] = 'Prosím opište text z obrázku.';
        }

        return $errors;
    }

    private function emailExists(string $email): bool
    {
        return (bool) R::findOne('user', ' email = ? ', [$email]);
    }

    private function captchaContext(string $context): array
    {
        return [
            'enabled' => Captcha::isEnabledFor($context),
            'src' => Captcha::refreshKey($context),
            'width' => (int) Setting::get('captcha_width', Setting::DEFAULTS['captcha_width']),
            'height' => (int) Setting::get('captcha_height', Setting::DEFAULTS['captcha_height']),
        ];
    }

    private function isRegistrationEnabled(): bool
    {
        return Setting::get('allow_registration', '1') === '1';
    }
}
