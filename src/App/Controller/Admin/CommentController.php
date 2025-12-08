<?php
namespace App\Controller\Admin;

use App\Service\Auth;
use App\Service\Comment;
use App\Service\CommentNotifier;
use App\Service\Flash;
use RedBeanPHP\R as R;

class CommentController extends AjaxController
{
    public function index(): void
    {
        Auth::requireRole(['admin', 'editor']);
        Comment::ensureSchema();
        $status = $_GET['status'] ?? 'pending';
        $allowedStatuses = array_merge(['all', 'trash'], Comment::statuses());
        if (!in_array($status, $allowedStatuses, true)) {
            $status = 'pending';
        }

        $search = trim($_GET['q'] ?? '');
        $query = '';
        $params = [];
        if ($status === 'trash') {
            $query = ' deleted_at IS NOT NULL ';
        } elseif ($status && $status !== 'all') {
            $query = ' status = ? AND deleted_at IS NULL ';
            $params[] = $status;
        } else {
            $query = ' deleted_at IS NULL ';
        }

        if ($search !== '') {
            $query .= ' AND (body LIKE ? OR author_name LIKE ? OR author_email LIKE ?) ';
            $like = '%' . $search . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        $total = R::count('comment', $query, $params);
        $pagination = $this->buildPagination((int) $total, 15);

        $comments = R::findAll(
            'comment',
            $query . ' ORDER BY created_at DESC LIMIT ? OFFSET ? ',
            array_merge($params, [$pagination['per_page'], $pagination['offset']])
        );

        $counts = Comment::statusCounts();

        $contentMap = [];
        $parentIds = [];
        $childCounts = [];
        $commentIds = [];
        if ($comments) {
            $ids = array_values(array_unique(array_map(fn($c) => (int) $c->content_id, $comments)));
            if ($ids) {
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $contents = R::findAll('content', ' id IN (' . $placeholders . ') ', $ids);
                foreach ($contents as $content) {
                    $contentMap[$content->id] = $content;
                }
            }

            foreach ($comments as $comment) {
                $commentIds[] = (int) $comment->id;
                if ($comment->parent_id) {
                    $parentIds[] = (int) $comment->parent_id;
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

        if ($commentIds) {
            $placeholders = implode(',', array_fill(0, count($commentIds), '?'));
            $childParams = $commentIds;
            $childQuery = ' parent_id IN (' . $placeholders . ') ';

            if ($status === 'trash') {
                $childQuery .= ' AND deleted_at IS NOT NULL ';
            } elseif ($status && $status !== 'all') {
                $childQuery .= ' AND status = ? AND deleted_at IS NULL ';
                $childParams[] = $status;
            } else {
                $childQuery .= ' AND deleted_at IS NULL ';
            }

            $childRows = R::getAll(
                'SELECT parent_id, COUNT(*) AS total FROM comment WHERE ' . $childQuery . ' GROUP BY parent_id',
                $childParams
            );

            foreach ($childRows as $row) {
                $childCounts[(int) $row['parent_id']] = (int) $row['total'];
            }
        }

        $viewContext = [
            'comments' => $comments,
            'status' => $status,
            'counts' => $counts,
            'content_map' => $contentMap,
            'parent_map' => $parentComments,
            'child_counts' => $childCounts,
            'pagination' => $pagination,
            'search' => $search,
        ];

        if ($this->respondAjax(
            'admin/comments/_list.twig',
            $this->prepareCommentsAjaxPayload($comments, $viewContext),
            $pagination['current_url']
        )) {
            return;
        }

        $this->render('admin/comments/index.twig', [
            'comments' => $viewContext['comments'],
            'status' => $viewContext['status'],
            'counts' => $viewContext['counts'],
            'content_map' => $viewContext['content_map'],
            'parent_map' => $viewContext['parent_map'],
            'child_counts' => $viewContext['child_counts'],
            'pagination' => $viewContext['pagination'],
            'current_menu' => 'comments',
        ]);
    }

    private function prepareCommentsAjaxPayload(array $comments, array $context): array
    {
        $settings = $this->baseContext(false)['settings'];
        $format = ($settings['date_format'] ?? 'd/m/Y') . ' ' . ($settings['time_format'] ?? 'H:i');

        $serializedComments = [];
        foreach ($comments as $comment) {
            $serializedComments[] = [
                'id' => (int) $comment->id,
                'author_name' => (string) ($comment->author_name ?: 'Anonym'),
                'author_email' => (string) $comment->author_email,
                'body' => (string) $comment->body,
                'status' => $comment->status,
                'content_id' => (int) $comment->content_id,
                'parent_id' => $comment->parent_id ? (int) $comment->parent_id : null,
                'ip_address' => (string) ($comment->ip_address ?? ''),
                'created_at' => $comment->created_at,
                'created_at_formatted' => $comment->created_at ? date($format, strtotime($comment->created_at)) : null,
            ];
        }

        $contentMap = [];
        foreach (($context['content_map'] ?? []) as $id => $content) {
            $contentMap[(int) $id] = [
                'id' => (int) $content->id,
                'title' => $content->title,
                'type' => $content->type,
            ];
        }

        $parentMap = [];
        foreach (($context['parent_map'] ?? []) as $id => $parent) {
            $parentMap[(int) $id] = [
                'id' => (int) $parent->id,
                'body' => $parent->body,
            ];
        }

        $context['comments'] = $serializedComments;
        $context['content_map'] = $contentMap;
        $context['parent_map'] = $parentMap;
        $context['search'] = $context['search'] ?? '';

        return $context;
    }

    public function approve($id): void
    {
        Auth::requireRole(['admin', 'editor']);
        $comment = Comment::find((int) $id);
        if ($comment) {
            $wasApproved = (string) $comment->status === 'approved';
            Comment::approve((int) $id);

            if (!$wasApproved) {
                CommentNotifier::sendApprovedNotification($comment);
                CommentNotifier::sendReplyNotification($comment);
            }
        }
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

        $previousStatus = (string) $comment->status;
        $updated = Comment::update((int) $id, $data);

        if ($updated && $previousStatus !== 'approved' && (string) $updated->status === 'approved') {
            CommentNotifier::sendApprovedNotification($updated);
            CommentNotifier::sendReplyNotification($updated);
        }

        Flash::addSuccess('Komentář byl aktualizován.');
        header('Location: /admin/comments');
        exit;
    }

    public function delete($id): void
    {
        Auth::requireRole(['admin', 'editor']);
        $comment = Comment::find((int) $id);
        if ($comment && $comment->deleted_at === null) {
            CommentNotifier::sendDeletedNotification($comment);
        }

        Comment::delete((int) $id);
        Flash::addSuccess('Komentář byl přesunut do koše nebo nenávratně odstraněn.');
        header('Location: /admin/comments');
        exit;
    }

    public function restore($id): void
    {
        Auth::requireRole(['admin', 'editor']);
        Comment::restore((int) $id);
        Flash::addSuccess('Komentář byl obnoven.');
        header('Location: /admin/comments?status=trash');
        exit;
    }

    public function emptyTrash(): void
    {
        Auth::requireRole(['admin', 'editor']);
        Comment::emptyTrash();
        Flash::addSuccess('Koš komentářů byl vysypán.');
        header('Location: /admin/comments?status=trash');
        exit;
    }
}
