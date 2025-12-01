<?php
namespace App\Controller\Admin;

use App\Service\Auth;
use App\Service\Flash;

class AuthController extends AjaxController
{
    public function loginForm()
    {
        $flash = Flash::consume();

        $this->render('admin/login.twig', [
            'error' => $flash['error'][0] ?? null,
        ]);
    }

    public function login()
    {
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';

        if ($email === '' || $password === '') {
            Flash::addError('Vyplň e-mail i heslo.');
            header('Location: /admin/login');
            exit;
        }

        $result = Auth::attemptDetailed($email, $password);
        if (!$result['success']) {
            Flash::addError($result['message'] ?? 'Přihlášení se nezdařilo.');
            header('Location: /admin/login');
            exit;
        }

        if (!Auth::hasRole(['admin', 'editor'])) {
            Flash::addError('Nemáš oprávnění pro přístup do administrace.');
            Auth::logout();
        }

        header('Location: /admin');
        exit;
    }

    public function logout()
    {
        Auth::logout();
    }
}
