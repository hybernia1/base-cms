<?php
namespace App\Controller\Front;

use RedBeanPHP\R as R;
use App\Service\ContentType;
use App\Service\Setting;
use App\Service\Comment;
use App\Service\Auth;

class HomeController extends BaseFrontController
{

    public function index()
    {
        $customHomepageId = (int) Setting::get('theme_homepage_id', 0);
        if ($customHomepageId > 0) {
            $page = R::findOne('content', ' id = ? AND status = ? AND deleted_at IS NULL ', [$customHomepageId, 'published']);
            if ($page) {
                $this->renderCustomHomepage($page);
                return;
            }
        }

        $posts = R::findAll('content', ' type = ? AND status = ? ORDER BY created_at DESC ', ['post', 'published']);
        $posts = $this->attachAuthors($posts);

        $this->render('front/home.twig', [
            'posts' => $posts,
            'content_types' => ContentType::definitions(),
            'heading' => 'Aktuální příspěvky',
        ]);
    }

    public function listByType(string $slug)
    {
        $definitions = ContentType::definitions();
        $typeKey = ContentType::keyFromSlug($slug);

        if (!$typeKey || !isset($definitions[$typeKey])) {
            $this->renderNotFound([
                'message' => 'Zvolený typ obsahu neexistuje.',
            ]);
            return;
        }

        $items = R::findAll('content', ' type = ? AND status = ? ORDER BY created_at DESC ', [$typeKey, 'published']);
        $items = $this->attachAuthors($items);
        $typeDef = $definitions[$typeKey];

        $this->render('front/home.twig', [
            'posts' => $items,
            'content_types' => $definitions,
            'heading' => $typeDef['plural_name'] ?? ($typeDef['menu_label'] ?? $typeDef['name'] ?? $slug),
            'empty_message' => 'Pro tento typ zatím nic není publikováno.',
        ]);
    }

    private function renderCustomHomepage($page): void
    {
        $definitions = ContentType::definitions();
        $typeDef = $definitions[$page->type] ?? ['name' => $page->type, 'slug' => ContentType::slug($page->type)];

        $termIds = R::getCol('SELECT term_id FROM content_term WHERE content_id = ?', [$page->id]);
        $terms = [];
        if ($termIds) {
            $placeholders = implode(',', array_fill(0, count($termIds), '?'));
            $terms = R::findAll('term', ' id IN (' . $placeholders . ') ORDER BY type, name ', $termIds);
        }

        $thumbnail = null;
        if ($page->thumbnail_id) {
            $loaded = R::load('media', (int) $page->thumbnail_id);
            if ($loaded && $loaded->id) {
                $thumbnail = $loaded;
            }
        }

        $commentSettings = [
            'allow_replies' => Setting::get('comments_allow_replies', '1') === '1',
            'allow_anonymous' => Setting::get('comments_allow_anonymous', '0') === '1',
            'max_depth' => (int) Setting::get('comments_max_depth', 0),
        ];

        $commentAllowed = Setting::get('comments_enabled', '1') === '1' && ((string) ($page->allow_comments ?? '1') !== '0');
        $comments = $commentAllowed ? Comment::findByContent((int) $page->id) : [];
        $currentUser = Auth::user();
        $commentingEnabled = $commentAllowed && ($commentSettings['allow_anonymous'] || $currentUser);

        $adminBarContext = [];
        if (Auth::hasRole(['admin', 'editor'])) {
            $adminBarContext['edit_url'] = '/admin/content/' . ($typeDef['slug'] ?? $page->type) . '/' . $page->id . '/edit';
            $adminBarContext['current_title'] = $page->title;
        }

        $this->render('front/content/detail.twig', [
            'item' => $page,
            'type' => $typeDef,
            'terms' => $terms,
            'thumbnail' => $thumbnail,
            'comments' => $comments,
            'comment_allowed' => $commentAllowed,
            'comment_settings' => $commentSettings,
            'commenting_enabled' => $commentingEnabled,
            'author' => $this->loadAuthor((int) ($page->author_id ?? 0)),
            'admin_bar' => $adminBarContext,
        ]);
    }

    private function loadAuthor(int $userId): ?array
    {
        if ($userId <= 0) {
            return null;
        }

        $user = R::load('user', $userId);
        if (!$user || !$user->id) {
            return null;
        }

        return [
            'id' => (int) $user->id,
            'email' => $user->email,
            'nickname' => $user->nickname ?: $user->email,
            'profile_url' => (int) ($user->is_profile_public ?? 1) === 1 ? '/users/' . $user->id : null,
        ];
    }
}
