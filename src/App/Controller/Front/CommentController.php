<?php
namespace App\Controller\Front;

use App\Service\Comment;
use App\Service\EmailTemplateManager;
use App\Service\Setting;
use RedBeanPHP\R as R;

class CommentController extends BaseFrontController
{
    public function store(): void
    {
        $contentId = (int) ($_POST['content_id'] ?? 0);
        $parentId = $_POST['parent_id'] ?? '';
        $body = trim($_POST['body'] ?? '');
        $authorName = trim($_POST['author_name'] ?? '');
        $authorEmail = trim($_POST['author_email'] ?? '');

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

        if ($body === '') {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Text komentáře je povinný.']);
            return;
        }

        if (Setting::get('comments_allow_anonymous', '0') !== '1') {
            if ($authorName === '' || $authorEmail === '' || !filter_var($authorEmail, FILTER_VALIDATE_EMAIL)) {
                http_response_code(400);
                echo json_encode(['status' => 'error', 'message' => 'Zadejte jméno a platný e-mail.']);
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

        $status = Setting::get('comments_moderation', '1') === '1' ? 'pending' : 'approved';
        $comment = Comment::create([
            'content_id' => $contentId,
            'parent_id' => $parentId,
            'user_id' => $_SESSION['user_id'] ?? null,
            'author_name' => $authorName,
            'author_email' => $authorEmail,
            'body' => $body,
            'status' => $status,
            'depth' => $parentDepth + ($parentId ? 1 : 0),
        ]);

        if ($status === 'approved') {
            EmailTemplateManager::send('comment_approved', $authorEmail ?: Setting::get('smtp_from_email', ''), [
                'comment_body' => $body,
                'site_name' => Setting::get('site_name', 'Web'),
            ]);
        }

        echo json_encode([
            'status' => 'success',
            'message' => $status === 'approved' ? 'Komentář byl přidán.' : 'Komentář čeká na schválení.',
            'comment_id' => $comment->id,
        ]);
    }
}

