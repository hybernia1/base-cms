<?php
namespace App\Controller\Admin;

use App\Service\Auth;
use App\Service\Flash;
use App\Service\Setting;
use App\Service\ContentType;
use App\Service\TermType;
use App\Service\Slugger;
use App\Service\ThemeManager;
use RedBeanPHP\R as R;
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
        'seo' => ['label' => 'SEO', 'icon' => 'bi-search'],
    ];

    public function index($section = 'main')
    {
        Auth::requireRole(['admin']);

        $section = $this->normalizeSection($section);
        $values = Setting::all();
        $timezones = DateTimeZone::listIdentifiers();
        $dateFormats = ['d.m.Y', 'j. n. Y', 'Y-m-d', 'm/d/Y'];
        $timeFormats = ['H:i', 'H:i:s', 'g:i A'];

        $mediaLibrary = [];
        $selectedLogo = null;
        $selectedFavicon = null;
        $themes = [];
        $activeTheme = Setting::get('theme', Setting::DEFAULTS['theme']);
        if ($section === 'main') {
            $mediaLibrary = $this->mediaList();
            $selectedLogo = Setting::mediaDetails((int) ($values['site_logo_id'] ?? 0));
            $selectedFavicon = Setting::mediaDetails((int) ($values['site_favicon_id'] ?? 0));
        }
        if ($section === 'themes') {
            $themes = ThemeManager::availableThemes();
        }

        $this->render('admin/settings/form.twig', [
            'values' => $values,
            'content_types' => ContentType::definitions(),
            'term_types' => TermType::definitions(),
            'media' => $mediaLibrary,
            'selected_logo' => $selectedLogo,
            'selected_favicon' => $selectedFavicon,
            'current_menu' => $section === 'themes' ? 'themes' : 'settings:' . $section,
            'timezones' => $timezones,
            'date_formats' => $dateFormats,
            'time_formats' => $timeFormats,
            'active_section' => $section,
            'settings_sections' => self::SECTION_DEFINITIONS,
            'themes' => $themes,
            'active_theme' => $activeTheme,
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
            case 'content-types':
                $this->updateContentTypes();
                break;
            case 'term-types':
                $this->updateTermTypes();
                break;
            case 'seo':
                $this->updateSeoSettings();
                break;
            case 'themes':
                $this->updateThemeSettings();
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
        $siteLogoId = $this->normalizeImageId((int) ($_POST['site_logo_id'] ?? 0));
        $siteFaviconId = $this->normalizeImageId((int) ($_POST['site_favicon_id'] ?? 0));

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
        Setting::set('site_logo_id', $siteLogoId ?: '');
        Setting::set('site_favicon_id', $siteFaviconId ?: '');

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
        $mailTransport = $_POST['mail_transport'] ?? Setting::DEFAULTS['mail_transport'];
        $mailTransport = in_array($mailTransport, ['smtp', 'mail'], true) ? $mailTransport : Setting::DEFAULTS['mail_transport'];
        $smtpHost = trim($_POST['smtp_host'] ?? '');
        $smtpPort = (int) ($_POST['smtp_port'] ?? Setting::DEFAULTS['smtp_port']);
        $smtpUsername = trim($_POST['smtp_username'] ?? '');
        $smtpPassword = trim($_POST['smtp_password'] ?? '');
        $smtpEncryption = trim($_POST['smtp_encryption'] ?? Setting::DEFAULTS['smtp_encryption']);
        $smtpFromEmail = trim($_POST['smtp_from_email'] ?? '');
        $smtpFromName = trim($_POST['smtp_from_name'] ?? '');

        Setting::set('mail_transport', $mailTransport);
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
        $rateLimitSeconds = (int) ($_POST['comments_rate_limit_seconds'] ?? Setting::DEFAULTS['comments_rate_limit_seconds']);

        $maxDepth = max(0, min(5, $maxDepth));
        $rateLimitSeconds = max(0, min(86400, $rateLimitSeconds));

        Setting::set('comments_enabled', $commentsEnabled);
        Setting::set('comments_allow_replies', $allowReplies);
        Setting::set('comments_max_depth', (string) $maxDepth);
        Setting::set('comments_moderation', $moderation);
        Setting::set('comments_allow_anonymous', $allowAnonymous);
        Setting::set('comments_rate_limit_seconds', (string) $rateLimitSeconds);

        Flash::addSuccess('Nastavení komentářů bylo uloženo.');
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

    private function updateSeoSettings(): void
    {
        $indexingEnabled = isset($_POST['indexing_enabled']) ? '1' : '0';
        $googleAnalyticsId = trim($_POST['google_analytics_id'] ?? '');

        if ($googleAnalyticsId !== '' && !preg_match('/^[A-Za-z0-9\-]+$/', $googleAnalyticsId)) {
            Flash::addError('Neplatné Google Analytics ID. Použijte formát například G-XXXXXXX.');
            return;
        }

        Setting::set('indexing_enabled', $indexingEnabled);
        Setting::set('google_analytics_id', $googleAnalyticsId);

        Flash::addSuccess('Nastavení SEO bylo uloženo.');
    }

    private function updateThemeSettings(): void
    {
        $selectedTheme = trim($_POST['theme'] ?? '');
        $upload = $_FILES['theme_package'] ?? null;

        if ($upload && ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE && ($upload['tmp_name'] ?? '')) {
            $result = ThemeManager::installFromUpload($upload);
            if ($result['success'] ?? false) {
                $selectedTheme = $result['theme'] ?? $selectedTheme;
                Flash::addSuccess('Nová šablona byla nahrána a je připravena k použití.');
            } else {
                Flash::addError($result['error'] ?? 'Nahrávání šablony se nezdařilo.');
            }
        }

        if ($selectedTheme !== '') {
            if (ThemeManager::themeExists($selectedTheme)) {
                Setting::set('theme', $selectedTheme);
                Flash::addSuccess('Šablona byla nastavena jako výchozí.');
            } else {
                Flash::addError('Zvolená šablona neexistuje.');
            }
        }
    }

    private function normalizeSection(?string $section): string
    {
        $section = $section ?: 'main';

        $allowedSections = array_merge(array_keys(self::SECTION_DEFINITIONS), ['themes']);

        return in_array($section, $allowedSections, true) ? $section : 'main';
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

    private function mediaList(): array
    {
        return R::findAll('media', ' is_image = 1 ORDER BY created_at DESC LIMIT 100 ');
    }

    private function normalizeImageId(int $id): int
    {
        if ($id <= 0) {
            return 0;
        }

        $media = R::findOne('media', ' id = ? AND is_image = 1 ', [$id]);

        return $media && $media->id ? (int) $media->id : 0;
    }
}
