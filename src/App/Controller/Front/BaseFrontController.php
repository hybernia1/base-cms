<?php
namespace App\Controller\Front;

use App\Service\Flash;
use App\Service\ContentType;
use App\Service\Auth;
use App\Service\Setting;
use App\Service\Navigation;
use App\Service\Recaptcha;
use RedBeanPHP\R as R;

abstract class BaseFrontController
{
    protected $twig;

    public function __construct()
    {
        $this->twig = $GLOBALS['app']['twig'];
    }

    protected function render(string $template, array $context = []): void
    {
        $flash = Flash::consume();

        $currentUser = Auth::user();
        $adminBar = [];

        $siteName = Setting::get('site_name', Setting::DEFAULTS['site_name']);
        $siteLogo = Setting::mediaDetails((int) Setting::get('site_logo_id', 0));
        $siteFavicon = Setting::mediaDetails((int) Setting::get('site_favicon_id', 0));
        $indexingEnabled = Setting::get('indexing_enabled', Setting::DEFAULTS['indexing_enabled']) === '1';
        $googleAnalyticsId = Setting::get('google_analytics_id', '');
        $recaptchaSiteKey = Setting::get('recaptcha_site_key', '');
        $recaptchaEnabled = Recaptcha::isEnabled();
        $navigation = Navigation::tree();

        $hooks = [
            'head' => [],
            'footer' => [],
        ];

        if ($googleAnalyticsId !== '') {
            $escapedAnalyticsId = htmlspecialchars($googleAnalyticsId, ENT_QUOTES, 'UTF-8');
            $hooks['head'][] = <<<HTML
<!-- Google tag (gtag.js) -->
<script async src="https://www.googletagmanager.com/gtag/js?id={$escapedAnalyticsId}"></script>
<script>
  window.dataLayer = window.dataLayer || [];
  function gtag(){dataLayer.push(arguments);}
  gtag('js', new Date());
  gtag('config', '{$escapedAnalyticsId}');
</script>
HTML;
        }

        if ($recaptchaEnabled && $recaptchaSiteKey !== '') {
            $escapedRecaptchaKey = htmlspecialchars($recaptchaSiteKey, ENT_QUOTES, 'UTF-8');
            $hooks['head'][] = '<script src="https://www.google.com/recaptcha/api.js?render=' . $escapedRecaptchaKey . '"></script>';
            $hooks['footer'][] = <<<HTML
<script>
    window.cmsRecaptcha = window.cmsRecaptcha || {};
    window.cmsRecaptcha.siteKey = '{$escapedRecaptchaKey}';
    window.cmsRecaptcha.getToken = function(action = 'submit') {
        return new Promise((resolve) => {
            if (!window.grecaptcha || !window.cmsRecaptcha.siteKey) {
                resolve(null);
                return;
            }

            grecaptcha.ready(function() {
                grecaptcha.execute(window.cmsRecaptcha.siteKey, {action: action}).then(function(token) {
                    resolve(token || null);
                }).catch(function() {
                    resolve(null);
                });
            });
        });
    };

    window.cmsRecaptcha.showError = function(form, message) {
        if (!form) { return; }
        const alertBox = form.querySelector('[data-recaptcha-alert]');
        if (alertBox) {
            alertBox.textContent = message;
            alertBox.classList.remove('d-none');
            alertBox.classList.add('alert', 'alert-danger');
            return;
        }

        window.alert(message);
    };

    window.cmsRecaptcha.bindForms = function() {
        const forms = document.querySelectorAll('form[method="post"]:not([data-recaptcha="disabled"]):not([data-recaptcha="manual"])');
        forms.forEach(function(form) {
            if (form.dataset.recaptchaBound === '1') {
                return;
            }

            form.dataset.recaptchaBound = '1';
            form.addEventListener('submit', async function(event) {
                const action = form.dataset.recaptchaAction || 'submit';
                const token = await window.cmsRecaptcha.getToken(action);
                if (!token) {
                    event.preventDefault();
                    window.cmsRecaptcha.showError(form, 'Ověření reCAPTCHA se nezdařilo. Zkuste to prosím znovu.');
                    return;
                }

                let input = form.querySelector('input[name="recaptcha_token"]');
                if (!input) {
                    input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = 'recaptcha_token';
                    form.appendChild(input);
                }

                input.value = token;
            }, true);
        });
    };

    document.addEventListener('DOMContentLoaded', window.cmsRecaptcha.bindForms);
</script>
HTML;
        }

        if ($currentUser) {
            $contentTypes = ContentType::definitions();
            $createLinks = [];

            if (Auth::hasRole(['admin', 'editor'])) {
                foreach ($contentTypes as $type) {
                    $createLinks[] = [
                        'label' => $type['menu_label'] ?? ($type['plural_name'] ?? ($type['name'] ?? $type['slug'] ?? 'Obsah')),
                        'url' => '/admin/content/' . $type['slug'] . '/create',
                    ];
                }
            }

            $roleLabelMap = [
                'admin' => 'Administrátor',
                'editor' => 'Editor',
                'user' => 'Uživatel',
            ];

            $adminBar = [
                'dashboard_url' => Auth::hasRole(['admin', 'editor']) ? '/admin' : null,
                'create_links' => $createLinks,
                'user_label' => $currentUser->nickname ?: ($currentUser->email ?? 'Uživatel'),
                'user_role' => $currentUser->role ?? 'user',
                'user_role_label' => $roleLabelMap[$currentUser->role] ?? ($currentUser->role ?? 'uživatel'),
                'user_links' => [
                    ['label' => 'Můj profil', 'url' => '/profile', 'icon' => 'bi-person'],
                    ['label' => 'Nastavení profilu', 'url' => '/profile/edit', 'icon' => 'bi-gear'],
                    ['label' => 'Odhlásit se', 'url' => '/logout', 'icon' => 'bi-box-arrow-right'],
                ],
            ];
        }

        $templateContext = array_merge([
            'flash_success' => $flash['success'],
            'flash_error'   => $flash['error'],
            'post_archive_slug' => ContentType::defaultSlug('post'),
            'current_user' => $currentUser,
            'site' => [
                'name' => $siteName,
                'logo' => $siteLogo,
                'favicon' => $siteFavicon,
            ],
            'navigation' => $navigation,
            'seo' => [
                'indexing_enabled' => $indexingEnabled,
            ],
            'recaptcha' => [
                'enabled' => $recaptchaEnabled,
                'site_key' => $recaptchaSiteKey,
            ],
            'hooks' => $hooks,
        ], $context);

        if ($adminBar || isset($context['admin_bar'])) {
            $templateContext['admin_bar'] = array_merge($adminBar, $context['admin_bar'] ?? []);
        }

        try {
            echo $this->twig->render($template, $templateContext);
        } catch (\Throwable $e) {
            error_log('[Theme render error] ' . $e->getMessage());
            http_response_code(500);

            $config = $GLOBALS['app']['config'] ?? [];
            $isDebug = ($config['env'] ?? 'prod') !== 'prod';

            try {
                echo $this->twig->render('front/theme-error.twig', [
                    'title' => 'Chyba šablony',
                    'message' => 'Při načítání zvolené šablony došlo k chybě.',
                    'details' => $isDebug ? $e->getMessage() : null,
                ]);
            } catch (\Throwable $fallback) {
                echo '<h1>Chyba šablony</h1>';
                echo '<p>Při vykreslování šablony došlo k problému.</p>';
                if ($isDebug) {
                    echo '<pre>' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</pre>';
                }
            }
        }
    }

    protected function renderNotFound(array $context = []): void
    {
        http_response_code(404);

        $this->render('front/404.twig', array_merge([
            'title' => 'Stránka nenalezena',
            'message' => 'Omlouváme se, ale požadovanou stránku se nepodařilo najít.',
            'content_types' => ContentType::definitions(),
        ], $context));
    }

    protected function attachAuthors(array $items): array
    {
        $authorIds = [];

        foreach ($items as $item) {
            $authorId = (int) ($item->author_id ?? 0);
            if ($authorId > 0) {
                $authorIds[] = $authorId;
            }
        }

        $authorIds = array_values(array_unique($authorIds));
        if (!$authorIds) {
            return $items;
        }

        $placeholders = implode(',', array_fill(0, count($authorIds), '?'));
        $authors = R::findAll('user', ' id IN (' . $placeholders . ') ', $authorIds);

        $map = [];
        foreach ($authors as $author) {
            $map[(int) $author->id] = [
                'id' => (int) $author->id,
                'email' => $author->email,
                'nickname' => $author->nickname ?: $author->email,
                'profile_url' => (int) ($author->is_profile_public ?? 1) === 1 ? '/users/' . $author->id : null,
            ];
        }

        $result = [];
        foreach ($items as $item) {
            $authorId = (int) ($item->author_id ?? 0);

            $result[] = [
                'id' => (int) ($item->id ?? 0),
                'title' => $item->title ?? '',
                'slug' => $item->slug ?? '',
                'body' => $item->body ?? '',
                'created_at' => $item->created_at ?? null,
                'type' => $item->type ?? '',
                'author' => $map[$authorId] ?? null,
            ];
        }

        return $result;
    }
}
