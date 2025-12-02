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
        $status = $_GET['status'] ?? 'pending';
        $allowedStatuses = array_merge(['all'], Comment::statuses());
        if (!in_array($status, $allowedStatuses, true)) {
            $status = 'pending';
        }

        $comments = Comment::all($status === 'all' ? null : $status);
        $counts = Comment::statusCounts();

        $contentMap = [];
        $parentIds = [];
        $childCounts = [];
        if ($comments) {
            $ids = array_unique(array_map(fn($c) => (int) $c->content_id, $comments));
            if ($ids) {
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $contents = R::findAll('content', ' id IN (' . $placeholders . ') ', $ids);
                foreach ($contents as $content) {
                    $contentMap[$content->id] = $content;
                }
            }

            foreach ($comments as $comment) {
                if ($comment->parent_id) {
                    $parentIds[] = (int) $comment->parent_id;
                    $childCounts[(int) $comment->parent_id] = ($childCounts[(int) $comment->parent_id] ?? 0) + 1;
                }
            }
        }

        $parentComments = [];
        if ($parentIds) {
            $uniqueParentIds = array_values(array_unique($parentIds));
            $placeholders = implode(',', array_fill(0, count($uniqueParentIds), '?'));
            $parents = R::findAll('comment', ' id IN (' . $placeholders . ') ', $uniqueParentIds);
            foreach ($parents as $parent) {
                $parentComments[(int) $parent->id] = $parent;
            }
        }

        $this->render('admin/comments/index.twig', [
            'comments' => $comments,
            'status' => $status,
            'counts' => $counts,
            'content_map' => $contentMap,
            'parent_map' => $parentComments,
            'child_counts' => $childCounts,
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

    public function editForm($id): void
    {
        Auth::requireRole(['admin', 'editor']);

        $comment = Comment::find((int) $id);
        if (!$comment) {
            Flash::addError('Komentář nenalezen.');
            header('Location: /admin/comments');
            exit;
        }

        $content = $comment->content_id ? R::load('content', (int) $comment->content_id) : null;
        $contentSlug = null;
        if ($content && $content->id) {
            $definitions = \App\Service\ContentType::definitions();
            $definition = $definitions[$content->type] ?? null;
            $contentSlug = $definition['slug'] ?? $content->type;
        }

        $parentComment = $comment->parent_id ? Comment::find((int) $comment->parent_id) : null;
        $childComments = R::findAll('comment', ' parent_id = ? ORDER BY created_at ASC ', [(int) $comment->id]);

        $this->render('admin/comments/edit.twig', [
            'comment' => $comment,
            'content' => $content && $content->id ? $content : null,
            'content_slug' => $contentSlug,
            'statuses' => Comment::statuses(),
            'parent_comment' => $parentComment,
            'child_comments' => array_values($childComments),
            'current_menu' => 'comments',
        ]);
    }

    public function update($id): void
    {
        Auth::requireRole(['admin', 'editor']);

        $comment = Comment::find((int) $id);
        if (!$comment) {
            Flash::addError('Komentář nenalezen.');
            header('Location: /admin/comments');
            exit;
        }

        $data = [
            'author_name' => $_POST['author_name'] ?? $comment->author_name,
            'author_email' => $_POST['author_email'] ?? $comment->author_email,
            'body' => $_POST['body'] ?? $comment->body,
            'status' => $_POST['status'] ?? $comment->status,
        ];

        $errors = [];
        if (trim((string) $data['body']) === '') {
            $errors[] = 'Text komentáře je povinný.';
        }

        if ($data['author_email'] && !filter_var($data['author_email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Zadejte platný e-mail autora.';
        }

        if ($errors) {
            Flash::addError(implode(' ', $errors));
            header('Location: /admin/comments/' . $comment->id . '/edit');
            exit;
        }

        Comment::update((int) $id, $data);

        Flash::addSuccess('Komentář byl aktualizován.');
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
