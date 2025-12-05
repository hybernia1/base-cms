<?php
namespace App\Controller\Front;

use App\Service\Auth;
use App\Service\Comment;
use App\Service\CommentNotifier;
use App\Service\Setting;
use RedBeanPHP\R as R;

class CommentController extends BaseFrontController
{
    public function store(): void
    {
        header('Content-Type: application/json');

        $contentId = (int) ($_POST['content_id'] ?? 0);
        $parentId = $_POST['parent_id'] ?? '';
        $body = trim($_POST['body'] ?? '');
        $authorName = trim($_POST['author_name'] ?? '');
        $authorEmail = trim($_POST['author_email'] ?? '');
        $currentUser = Auth::user();
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

        $rateLimitSeconds = $currentUser ? 5 : 60;
        $lastComment = null;

        if ($currentUser) {
            $lastComment = R::findOne(
                'comment',
                ' user_id = ? AND deleted_at IS NULL AND (status = ? OR status = ? OR status IS NULL) ORDER BY created_at DESC ',
                [$currentUser->id, 'approved', 'pending']
            );
        } elseif ($allowAnonymous) {
            $lastComment = R::findOne(
                'comment',
                ' user_id IS NULL AND author_email = ? AND deleted_at IS NULL AND (status = ? OR status = ? OR status IS NULL) ORDER BY created_at DESC ',
                [$authorEmail, 'approved', 'pending']
            );
        }

        if ($lastComment) {
            $lastCreatedAt = strtotime($lastComment->created_at ?? '');
            $elapsedSeconds = $lastCreatedAt ? time() - $lastCreatedAt : $rateLimitSeconds;

            if ($elapsedSeconds < $rateLimitSeconds) {
                $waitFor = $rateLimitSeconds - $elapsedSeconds;
                http_response_code(429);
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Komentáře můžete odesílat s prodlevou. Zkuste to prosím znovu za ' . $waitFor . ' s.',
                ]);
                return;
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

