<?php
namespace App\Controller\Admin;

use App\Service\Auth;
use App\Service\Comment;
use App\Service\Flash;
use RedBeanPHP\R as R;

class CommentController extends AjaxController
{
    public function index(): void
    {
        Auth::requireRole(['admin', 'editor']);
        $pending = Comment::allPending();

        $contentMap = [];
        if ($pending) {
            $ids = array_unique(array_map(fn($c) => (int) $c->content_id, $pending));
            if ($ids) {
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $contents = R::findAll('content', ' id IN (' . $placeholders . ') ', $ids);
                foreach ($contents as $content) {
                    $contentMap[$content->id] = $content;
                }
            }
        }

        $this->render('admin/comments/index.twig', [
            'pending_comments' => $pending,
            'content_map' => $contentMap,
            'current_menu' => 'comments',
        ]);
    }

    public function approve($id): void
    {
        Auth::requireRole(['admin', 'editor']);
        Comment::approve((int) $id);
        Flash::addSuccess('Komentář byl schválen.');
        header('Location: /admin/comments');
        exit;
    }

    public function delete($id): void
    {
        Auth::requireRole(['admin', 'editor']);
        Comment::delete((int) $id);
        Flash::addSuccess('Komentář byl smazán.');
        header('Location: /admin/comments');
        exit;
    }
}
