<?php
namespace App\Controller\Admin;

use App\Service\Auth;

class AuthController
{
    private $twig;

    public function __construct()
    {
        $this->twig = $GLOBALS['app']['twig'];
    }

    public function loginForm()
    {
        $error = $_SESSION['flash_error'] ?? null;
        unset($_SESSION['flash_error']);

        echo $this->twig->render('admin/login.twig', [
            'error' => $error,
        ]);
    }

    public function login()
    {
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';

        if ($email === '' || $password === '') {
            $_SESSION['flash_error'] = 'Vyplň e-mail i heslo.';
            header('Location: /admin/login');
            exit;
        }

        if (!Auth::attempt($email, $password)) {
            $_SESSION['flash_error'] = 'Neplatný e-mail nebo heslo.';
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
