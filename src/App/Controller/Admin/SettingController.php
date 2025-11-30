<?php
namespace App\Controller\Admin;

use App\Service\Auth;
use App\Service\Flash;
use App\Service\Setting;

class SettingController extends BaseAdminController
{
    public function index()
    {
        Auth::requireRole(['admin']);
        $values = Setting::all();

        $this->render('admin/settings/form.twig', [
            'values' => $values,
            'current_menu' => 'settings',
        ]);
    }

    public function update()
    {
        Auth::requireRole(['admin']);

        $siteName = trim($_POST['site_name'] ?? '');
        $allowWebp = isset($_POST['allow_webp']) ? '1' : '0';
        $allowedTypes = trim($_POST['allowed_upload_types'] ?? '');

        if ($siteName === '') {
            Flash::addError('Název webu je povinný.');
            header('Location: /admin/settings');
            exit;
        }

        Setting::set('site_name', $siteName);
        Setting::set('allow_webp', $allowWebp);
        Setting::set('allowed_upload_types', $allowedTypes ?: Setting::DEFAULTS['allowed_upload_types']);

        Flash::addSuccess('Nastavení bylo uloženo.');
        header('Location: /admin/settings');
        exit;
    }
}
