<?php
namespace App\Controller\Admin;

use App\Service\Auth;

class DashboardController extends BaseAdminController
{
    public function index()
    {
        $user = Auth::user();

        $this->render('admin/dashboard.twig', [
            'user' => $user,
            'current_menu' => 'dashboard',
        ]);
    }
}
