<?php
namespace App\Controller\Admin;

use App\Service\Auth;
use App\Service\Flash;
use App\Service\Setting;
use App\Service\ContentType;

class SettingController extends BaseAdminController
{
    public function index()
    {
        Auth::requireRole(['admin']);
        $values = Setting::all();

        $this->render('admin/settings/form.twig', [
            'values' => $values,
            'content_types' => ContentType::definitions(),
            'current_menu' => 'settings',
        ]);
    }

    public function update()
    {
        Auth::requireRole(['admin']);

        $siteName = trim($_POST['site_name'] ?? '');
        $allowWebp = isset($_POST['allow_webp']) ? '1' : '0';
        $allowedTypes = trim($_POST['allowed_upload_types'] ?? '');
        $allowRegistration = isset($_POST['allow_registration']) ? '1' : '0';
        $smtpHost = trim($_POST['smtp_host'] ?? '');
        $smtpPort = (int) ($_POST['smtp_port'] ?? Setting::DEFAULTS['smtp_port']);
        $smtpUsername = trim($_POST['smtp_username'] ?? '');
        $smtpPassword = trim($_POST['smtp_password'] ?? '');
        $smtpEncryption = trim($_POST['smtp_encryption'] ?? Setting::DEFAULTS['smtp_encryption']);
        $smtpFromEmail = trim($_POST['smtp_from_email'] ?? '');
        $smtpFromName = trim($_POST['smtp_from_name'] ?? '');
        $contentTypes = $this->parseContentTypes($_POST['content_types'] ?? []);

        if ($siteName === '') {
            Flash::addError('Název webu je povinný.');
            header('Location: /admin/settings');
            exit;
        }

        Setting::set('site_name', $siteName);
        Setting::set('allow_webp', $allowWebp);
        Setting::set('allowed_upload_types', $allowedTypes ?: Setting::DEFAULTS['allowed_upload_types']);
        Setting::set('allow_registration', $allowRegistration);
        Setting::set('smtp_host', $smtpHost);
        Setting::set('smtp_port', $smtpPort > 0 ? (string) $smtpPort : Setting::DEFAULTS['smtp_port']);
        Setting::set('smtp_username', $smtpUsername);
        Setting::set('smtp_password', $smtpPassword);
        Setting::set('smtp_encryption', $smtpEncryption ?: Setting::DEFAULTS['smtp_encryption']);
        Setting::set('smtp_from_email', $smtpFromEmail);
        Setting::set('smtp_from_name', $smtpFromName);
        Setting::set('content_types', json_encode($contentTypes ?: ContentType::defaults()));

        Flash::addSuccess('Nastavení bylo uloženo.');
        header('Location: /admin/settings');
        exit;
    }

    private function parseContentTypes(array $input): array
    {
        $keys = $input['key'] ?? [];
        $names = $input['name'] ?? [];
        $pluralNames = $input['plural_name'] ?? [];
        $slugs = $input['slug'] ?? [];
        $menuLabels = $input['menu_label'] ?? [];

        $result = [];

        foreach ($keys as $index => $key) {
            $key = trim((string) $key);
            if ($key === '') {
                continue;
            }

            $result[] = [
                'key' => $key,
                'name' => trim((string) ($names[$index] ?? $key)) ?: $key,
                'plural_name' => trim((string) ($pluralNames[$index] ?? $key)) ?: $key,
                'slug' => trim((string) ($slugs[$index] ?? $key)) ?: $key,
                'menu_label' => trim((string) ($menuLabels[$index] ?? ($pluralNames[$index] ?? $key))) ?: $key,
            ];
        }

        return $result;
    }
}
