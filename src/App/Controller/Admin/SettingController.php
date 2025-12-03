<?php
namespace App\Controller\Admin;

use App\Service\Auth;
use App\Service\Flash;
use App\Service\Setting;
use App\Service\ContentType;
use App\Service\TermType;
use App\Service\Slugger;
use DateTimeZone;

class SettingController extends AjaxController
{
    private const SECTION_DEFINITIONS = [
        'main' => ['label' => 'Základní', 'icon' => 'bi-gear'],
        'uploads' => ['label' => 'Uploady', 'icon' => 'bi-upload'],
        'email' => ['label' => 'E-maily', 'icon' => 'bi-envelope'],
        'content-types' => ['label' => 'Typy obsahu', 'icon' => 'bi-journal-text'],
        'term-types' => ['label' => 'Typy termů', 'icon' => 'bi-tags'],
        'comments' => ['label' => 'Komentáře', 'icon' => 'bi-chat-dots'],
        'security' => ['label' => 'Bezpečnost', 'icon' => 'bi-shield-lock'],
    ];

    public function index($section = 'main')
    {
        Auth::requireRole(['admin']);

        $section = $this->normalizeSection($section);
        $values = Setting::all();
        $timezones = DateTimeZone::listIdentifiers();
        $dateFormats = ['d.m.Y', 'j. n. Y', 'Y-m-d', 'm/d/Y'];
        $timeFormats = ['H:i', 'H:i:s', 'g:i A'];

        $this->render('admin/settings/form.twig', [
            'values' => $values,
            'content_types' => ContentType::definitions(),
            'term_types' => TermType::definitions(),
            'current_menu' => 'settings:' . $section,
            'timezones' => $timezones,
            'date_formats' => $dateFormats,
            'time_formats' => $timeFormats,
            'active_section' => $section,
            'settings_sections' => self::SECTION_DEFINITIONS,
        ]);
    }

    public function update($section = 'main')
    {
        Auth::requireRole(['admin']);

        $section = $this->normalizeSection($section);

        switch ($section) {
            case 'main':
                $this->updateMainSettings();
                break;
            case 'uploads':
                $this->updateUploadSettings();
                break;
            case 'email':
                $this->updateEmailSettings();
                break;
            case 'comments':
                $this->updateCommentSettings();
                break;
            case 'security':
                $this->updateSecuritySettings();
                break;
            case 'content-types':
                $this->updateContentTypes();
                break;
            case 'term-types':
                $this->updateTermTypes();
                break;
            default:
                Flash::addError('Neplatná sekce nastavení.');
                break;
        }

        header('Location: /admin/settings/' . $section);
        exit;
    }

    private function updateMainSettings(): void
    {
        $siteName = trim($_POST['site_name'] ?? '');
        $allowRegistration = isset($_POST['allow_registration']) ? '1' : '0';
        $timezone = trim($_POST['timezone'] ?? Setting::DEFAULTS['timezone']);
        $dateFormat = trim($_POST['date_format'] ?? Setting::DEFAULTS['date_format']);
        $timeFormat = trim($_POST['time_format'] ?? Setting::DEFAULTS['time_format']);

        $validTimezones = DateTimeZone::listIdentifiers();
        if (!in_array($timezone, $validTimezones, true)) {
            $timezone = Setting::DEFAULTS['timezone'];
        }

        if ($siteName === '') {
            Flash::addError('Název webu je povinný.');
            return;
        }

        Setting::set('site_name', $siteName);
        Setting::set('allow_registration', $allowRegistration);
        Setting::set('timezone', $timezone ?: Setting::DEFAULTS['timezone']);
        Setting::set('date_format', $dateFormat ?: Setting::DEFAULTS['date_format']);
        Setting::set('time_format', $timeFormat ?: Setting::DEFAULTS['time_format']);

        Flash::addSuccess('Základní nastavení bylo uloženo.');
    }

    private function updateUploadSettings(): void
    {
        $allowWebp = isset($_POST['allow_webp']) ? '1' : '0';
        $allowedTypes = trim($_POST['allowed_upload_types'] ?? '');

        Setting::set('allow_webp', $allowWebp);
        Setting::set('allowed_upload_types', $allowedTypes ?: Setting::DEFAULTS['allowed_upload_types']);

        Flash::addSuccess('Nastavení uploadů bylo uloženo.');
    }

    private function updateEmailSettings(): void
    {
        $smtpHost = trim($_POST['smtp_host'] ?? '');
        $smtpPort = (int) ($_POST['smtp_port'] ?? Setting::DEFAULTS['smtp_port']);
        $smtpUsername = trim($_POST['smtp_username'] ?? '');
        $smtpPassword = trim($_POST['smtp_password'] ?? '');
        $smtpEncryption = trim($_POST['smtp_encryption'] ?? Setting::DEFAULTS['smtp_encryption']);
        $smtpFromEmail = trim($_POST['smtp_from_email'] ?? '');
        $smtpFromName = trim($_POST['smtp_from_name'] ?? '');

        Setting::set('smtp_host', $smtpHost);
        Setting::set('smtp_port', $smtpPort > 0 ? (string) $smtpPort : Setting::DEFAULTS['smtp_port']);
        Setting::set('smtp_username', $smtpUsername);
        Setting::set('smtp_password', $smtpPassword);
        Setting::set('smtp_encryption', $smtpEncryption ?: Setting::DEFAULTS['smtp_encryption']);
        Setting::set('smtp_from_email', $smtpFromEmail);
        Setting::set('smtp_from_name', $smtpFromName);

        Flash::addSuccess('E-mailové nastavení bylo uloženo.');
    }

    private function updateCommentSettings(): void
    {
        $commentsEnabled = isset($_POST['comments_enabled']) ? '1' : '0';
        $allowReplies = isset($_POST['comments_allow_replies']) ? '1' : '0';
        $maxDepth = (int) ($_POST['comments_max_depth'] ?? Setting::DEFAULTS['comments_max_depth']);
        $moderation = isset($_POST['comments_moderation']) ? '1' : '0';
        $allowAnonymous = isset($_POST['comments_allow_anonymous']) ? '1' : '0';

        $maxDepth = max(0, min(5, $maxDepth));

        Setting::set('comments_enabled', $commentsEnabled);
        Setting::set('comments_allow_replies', $allowReplies);
        Setting::set('comments_max_depth', (string) $maxDepth);
        Setting::set('comments_moderation', $moderation);
        Setting::set('comments_allow_anonymous', $allowAnonymous);

        Flash::addSuccess('Nastavení komentářů bylo uloženo.');
    }

    private function updateSecuritySettings(): void
    {
        $captchaEnabled = isset($_POST['captcha_enabled']) ? '1' : '0';
        $captchaForms = $_POST['captcha_forms'] ?? [];
        $captchaLength = (int) ($_POST['captcha_length'] ?? Setting::DEFAULTS['captcha_length']);
        $captchaWidth = (int) ($_POST['captcha_width'] ?? Setting::DEFAULTS['captcha_width']);
        $captchaHeight = (int) ($_POST['captcha_height'] ?? Setting::DEFAULTS['captcha_height']);

        $allowedForms = ['register', 'login', 'comments'];
        $selectedForms = array_values(array_intersect($allowedForms, array_map('strval', (array) $captchaForms)));

        $captchaLength = max(3, min(8, $captchaLength));
        $captchaWidth = max(80, min(400, $captchaWidth));
        $captchaHeight = max(30, min(200, $captchaHeight));

        Setting::set('captcha_enabled', $captchaEnabled);
        Setting::set('captcha_forms', json_encode($selectedForms));
        Setting::set('captcha_length', (string) $captchaLength);
        Setting::set('captcha_width', (string) $captchaWidth);
        Setting::set('captcha_height', (string) $captchaHeight);

        Flash::addSuccess('Bezpečnostní nastavení bylo uloženo.');
    }

    private function updateContentTypes(): void
    {
        $contentTypes = $this->parseContentTypes($_POST['content_types'] ?? []);
        Setting::set('content_types', json_encode($contentTypes ?: ContentType::defaults()));

        Flash::addSuccess('Typy obsahu byly uloženy.');
    }

    private function updateTermTypes(): void
    {
        $termTypes = $this->parseTermTypes($_POST['term_types'] ?? []);
        Setting::set('term_types', json_encode($termTypes ?: TermType::defaults()));

        Flash::addSuccess('Typy termů byly uloženy.');
    }

    private function normalizeSection(?string $section): string
    {
        $section = $section ?: 'main';

        return array_key_exists($section, self::SECTION_DEFINITIONS) ? $section : 'main';
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

            $rawSlug = trim((string) ($slugs[$index] ?? $key)) ?: $key;
            $slug = Slugger::slugify($rawSlug);
            if ($slug === '') {
                $slug = Slugger::slugify($key) ?: $key;
            }
            $slug = Slugger::uniqueInCollection($slug, array_column($result, 'slug'));

            $result[] = [
                'key' => $key,
                'name' => trim((string) ($names[$index] ?? $key)) ?: $key,
                'plural_name' => trim((string) ($pluralNames[$index] ?? $key)) ?: $key,
                'slug' => $slug,
                'menu_label' => trim((string) ($menuLabels[$index] ?? ($pluralNames[$index] ?? $key))) ?: $key,
            ];
        }

        return $result;
    }

    private function parseTermTypes(array $input): array
    {
        $keys = $input['key'] ?? [];
        $labels = $input['label'] ?? [];
        $contentTypes = $input['content_types'] ?? [];
        $allowedContentTypes = array_keys(ContentType::definitions());

        $result = [];

        foreach ($keys as $index => $key) {
            $key = trim((string) $key);
            if ($key === '') {
                continue;
            }

            $selectedContentTypes = $contentTypes[$index] ?? [];
            if (!is_array($selectedContentTypes)) {
                $selectedContentTypes = [];
            }

            $cleanContentTypes = [];
            foreach ($selectedContentTypes as $value) {
                $value = trim((string) $value);
                if ($value !== '' && in_array($value, $allowedContentTypes, true)) {
                    $cleanContentTypes[] = $value;
                }
            }

            $result[] = [
                'key' => $key,
                'label' => trim((string) ($labels[$index] ?? $key)) ?: $key,
                'content_types' => array_values(array_unique($cleanContentTypes)),
            ];
        }

        return $result;
    }
}
