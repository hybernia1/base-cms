<?php
namespace App\Controller\Front;

use App\Service\Flash;
use App\Service\ContentType;
use App\Service\Auth;

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

        if (Auth::hasRole(['admin', 'editor'])) {
            $contentTypes = ContentType::definitions();
            $createLinks = [];

            foreach ($contentTypes as $type) {
                $createLinks[] = [
                    'label' => $type['menu_label'] ?? ($type['plural_name'] ?? ($type['name'] ?? $type['slug'] ?? 'Obsah')),
                    'url' => '/admin/content/' . $type['slug'] . '/create',
                ];
            }

            $adminBar = [
                'dashboard_url' => '/admin',
                'create_links' => $createLinks,
                'user_label' => $currentUser ? ($currentUser->nickname ?: ($currentUser->email ?? 'Uživatel')) : 'Administrátor',
            ];
        }

        $templateContext = array_merge([
            'flash_success' => $flash['success'],
            'flash_error'   => $flash['error'],
            'post_archive_slug' => ContentType::defaultSlug('post'),
            'current_user' => $currentUser,
        ], $context);

        if ($adminBar || isset($context['admin_bar'])) {
            $templateContext['admin_bar'] = array_merge($adminBar, $context['admin_bar'] ?? []);
        }

        echo $this->twig->render($template, $templateContext);
    }
}
