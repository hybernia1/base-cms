<?php
namespace App\Controller\Front;

use App\Service\Flash;
use App\Service\ContentType;
use App\Service\Auth;
use App\Service\Setting;
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
        ], $context);

        if ($adminBar || isset($context['admin_bar'])) {
            $templateContext['admin_bar'] = array_merge($adminBar, $context['admin_bar'] ?? []);
        }

        echo $this->twig->render($template, $templateContext);
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
