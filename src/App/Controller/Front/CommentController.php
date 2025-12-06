<?php
namespace App\Controller\Front;

use App\Service\Auth;
use App\Service\ContentType;
use App\Service\Comment;
use App\Service\CommentNotifier;
use App\Service\Csrf;
use App\Service\RequestHelper;
use App\Service\Setting;
use RedBeanPHP\R as R;

class CommentController extends BaseFrontController
{
    public function index(string $typeSlug, string $contentSlug): void
    {
        $typeKey = ContentType::keyFromSlug($typeSlug);
        if (!$typeKey) {
            $this->renderNotFound([
                'message' => 'Zvolený typ obsahu neexistuje.',
            ]);
            return;
        }

        $content = R::findOne(
            'content',
            ' slug = ? AND type = ? AND status = ? AND publish_at <= ? AND deleted_at IS NULL ',
            [$contentSlug, $typeKey, 'published', date('Y-m-d H:i:s')]
        );

        if (!$content || !$content->id) {
            $this->renderNotFound([
                'title' => 'Diskuse nenalezena',
                'message' => 'Požadovaná diskuse nebyla nalezena.',
            ]);
            return;
        }

        $commentAllowed = Setting::get('comments_enabled', '1') === '1'
            && (string) ($content->allow_comments ?? '1') !== '0';
        $commentSettings = [
            'allow_replies' => Setting::get('comments_allow_replies', '1') === '1',
            'allow_anonymous' => Setting::get('comments_allow_anonymous', '0') === '1',
            'max_depth' => (int) Setting::get('comments_max_depth', 0),
        ];

        $comments = $commentAllowed ? Comment::findByContent((int) $content->id) : [];
        $currentUser = Auth::user();
        $commentingEnabled = $commentAllowed && ($commentSettings['allow_anonymous'] || $currentUser);

        $contentUrl = '/' . ContentType::slug((string) $content->type) . '/' . $content->slug;
        $typeDefinitions = ContentType::definitions();
        $type = $typeDefinitions[$content->type] ?? ['name' => (string) $content->type, 'slug' => (string) $content->type];

        $this->render('front/comments/index.twig', [
            'content' => $content,
            'type' => $type,
            'content_url' => $contentUrl,
            'comments' => $comments,
            'comment_allowed' => $commentAllowed,
            'comment_settings' => $commentSettings,
            'commenting_enabled' => $commentingEnabled,
            'current_user' => $currentUser,
        ]);
    }

    public function store(): void
    {
        header('Content-Type: application/json');

        if (!Csrf::validate('comment_form', $_POST['_csrf'] ?? null)) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Platnost formuláře vypršela. Obnovte stránku a zkuste to znovu.']);
            return;
        }

        $contentId = (int) ($_POST['content_id'] ?? 0);
        $parentId = $_POST['parent_id'] ?? '';
        $body = trim($_POST['body'] ?? '');
        $authorName = trim($_POST['author_name'] ?? '');
        $authorEmail = trim($_POST['author_email'] ?? '');
        $currentUser = Auth::user();
        $ipAddress = RequestHelper::clientIp();
        $allowAnonymous = Setting::get('comments_allow_anonymous', '0') === '1';

        $content = $contentId ? R::load('content', $contentId) : null;
        if (!$content || !$content->id) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Obsah nebyl nalezen.']);
            return;
        }

        if ((string) $content->allow_comments === '0' || Setting::get('comments_enabled', '1') !== '1') {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Komentáře jsou zakázány.']);
            return;
        }

        if (!$currentUser && !$allowAnonymous) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => 'Komentovat mohou pouze přihlášení uživatelé.']);
            return;
        }

        if ($body === '') {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Text komentáře je povinný.']);
            return;
        }

        if (!$currentUser && $allowAnonymous) {
            if ($authorName === '' || $authorEmail === '' || !filter_var($authorEmail, FILTER_VALIDATE_EMAIL)) {
                http_response_code(400);
                echo json_encode(['status' => 'error', 'message' => 'Zadejte jméno a platný e-mail.']);
                return;
            }
        }

        if ($currentUser) {
            $authorName = $currentUser->nickname ?: ($currentUser->email ?? 'Registrovaný uživatel');
            $authorEmail = $currentUser->email ?? '';
        }

        if (!$currentUser) {
            $rateLimitSeconds = (int) Setting::get(
                'comments_rate_limit_seconds',
                Setting::DEFAULTS['comments_rate_limit_seconds'] ?? 60
            );
            $rateLimitSeconds = max(0, $rateLimitSeconds);

            if ($rateLimitSeconds > 0) {
                $recentAnonymousComment = R::findOne(
                    'comment',
                    ' ip_address = ? AND (user_id IS NULL OR user_id = 0) AND deleted_at IS NULL AND created_at >= ? ',
                    [$ipAddress, date('Y-m-d H:i:s', time() - $rateLimitSeconds)]
                );

                if ($recentAnonymousComment) {
                    http_response_code(429);
                    echo json_encode([
                        'status' => 'error',
                        'message' => 'Píšete příliš rychle. Zkuste to prosím znovu za chvíli.',
                    ]);
                    return;
                }
            }
        }

        $maxDepth = (int) Setting::get('comments_max_depth', 0);
        $parentDepth = 0;
        if ($parentId) {
            $parent = R::load('comment', (int) $parentId);
            if (!$parent || !$parent->id || (int) $parent->content_id !== $contentId) {
                http_response_code(400);
                echo json_encode(['status' => 'error', 'message' => 'Neplatný rodič.']);
                return;
            }
            $parentDepth = (int) $parent->depth;
            if (Setting::get('comments_allow_replies', '1') !== '1' || ($parentDepth + 1) > $maxDepth) {
                http_response_code(400);
                echo json_encode(['status' => 'error', 'message' => 'Reakce nejsou povoleny.']);
                return;
            }
        }

        $moderationEnabled = Setting::get('comments_moderation', '1') === '1' && $allowAnonymous;
        $status = ($currentUser || !$moderationEnabled) ? 'approved' : 'pending';
        $comment = Comment::create([
            'content_id' => $contentId,
            'parent_id' => $parentId,
            'user_id' => $currentUser->id ?? null,
            'author_name' => $authorName,
            'author_email' => $authorEmail,
            'body' => $body,
            'status' => $status,
            'depth' => $parentDepth + ($parentId ? 1 : 0),
            'ip_address' => $ipAddress,
        ]);

        if ($status === 'approved') {
            CommentNotifier::sendReplyNotification($comment);
        }

        echo json_encode([
            'status' => 'success',
            'message' => $status === 'approved' ? 'Komentář byl přidán.' : 'Komentář čeká na schválení.',
            'comment_id' => $comment->id,
        ]);
    }
}

