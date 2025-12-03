<?php
namespace App\Controller\Front;

use App\Service\ContentType;
use App\Service\Comment;
use App\Service\Setting;
use App\Service\Auth;
use RedBeanPHP\R as R;

class ContentController extends BaseFrontController
{
    public function show(string $typeSlug, string $contentSlug): void
    {
        $typeKey = ContentType::keyFromSlug($typeSlug);
        if (!$typeKey) {
            http_response_code(404);
            $this->render('front/content/detail.twig', [
                'item' => null,
                'type' => null,
            ]);
            return;
        }

        $definitions = ContentType::definitions();
        $typeDef = $definitions[$typeKey] ?? ['name' => $typeSlug, 'slug' => $typeSlug];

        $item = R::findOne(
            'content',
            ' slug = ? AND type = ? AND status = ? AND deleted_at IS NULL ',
            [$contentSlug, $typeKey, 'published']
        );

        $terms = [];
        $thumbnail = null;
        $comments = [];
        $commentAllowed = false;
        $author = null;
        $commentSettings = [
            'allow_replies' => Setting::get('comments_allow_replies', '1') === '1',
            'allow_anonymous' => Setting::get('comments_allow_anonymous', '0') === '1',
            'max_depth' => (int) Setting::get('comments_max_depth', 0),
        ];
        $currentUser = Auth::user();
        $adminBarContext = [];
        if ($item) {
            $termIds = R::getCol('SELECT term_id FROM content_term WHERE content_id = ?', [$item->id]);
            if ($termIds) {
                $placeholders = implode(',', array_fill(0, count($termIds), '?'));
                $terms = R::findAll('term', ' id IN (' . $placeholders . ') ORDER BY type, name ', $termIds);
            }

            if ($item->thumbnail_id) {
                $loaded = R::load('media', (int) $item->thumbnail_id);
                if ($loaded && $loaded->id) {
                    $thumbnail = $loaded;
                }
            }

            $commentAllowed = Setting::get('comments_enabled', '1') === '1' && ((string) ($item->allow_comments ?? '1') !== '0');
            if ($commentAllowed) {
                $comments = Comment::findByContent((int) $item->id);
            }

            $author = $this->loadAuthor((int) ($item->author_id ?? 0));

            if (Auth::hasRole(['admin', 'editor'])) {
                $adminBarContext['edit_url'] = '/admin/content/' . $typeDef['slug'] . '/' . $item->id . '/edit';
                $adminBarContext['current_title'] = $item->title;
            }
        } else {
            http_response_code(404);
        }

        $commentingEnabled = $commentAllowed && ($commentSettings['allow_anonymous'] || $currentUser);

        $this->render('front/content/detail.twig', [
            'item' => $item,
            'type' => $typeDef,
            'terms' => $terms,
            'thumbnail' => $thumbnail,
            'comments' => $comments,
            'comment_allowed' => $commentAllowed,
            'comment_settings' => $commentSettings,
            'commenting_enabled' => $commentingEnabled,
            'author' => $author,
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

