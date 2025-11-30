<?php
namespace App\Controller\Admin;

use App\Service\Auth;

class DashboardController
{
    private $twig;

    public function __construct()
    {
        $this->twig = $GLOBALS['app']['twig'];
    }

    public function index()
    {
        $user = Auth::user();

        echo $this->twig->render('admin/dashboard.twig', [
            'user' => $user,
        ]);
    }
}
