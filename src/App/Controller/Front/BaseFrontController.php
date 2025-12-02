<?php
namespace App\Controller\Front;

use App\Service\Flash;
use App\Service\ContentType;
use App\Service\Auth;
use App\Service\AssetManager;

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
            'asset_urls' => AssetManager::assetUrls(),
        ], $context);

        if ($adminBar || isset($context['admin_bar'])) {
            $templateContext['admin_bar'] = array_merge($adminBar, $context['admin_bar'] ?? []);
        }

        echo $this->twig->render($template, $templateContext);
    }
}
