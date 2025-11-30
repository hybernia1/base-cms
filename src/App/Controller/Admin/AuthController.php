<?php
namespace App\Controller\Admin;

use App\Service\Auth;
use App\Service\Flash;

class AuthController extends BaseAdminController
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

        if (!Auth::attempt($email, $password)) {
            Flash::addError('Neplatný e-mail nebo heslo.');
            header('Location: /admin/login');
            exit;
        }

        header('Location: /admin');
        exit;
    }

    public function logout()
    {
        Auth::logout();
    }
}
