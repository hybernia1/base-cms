<?php
namespace App\Controller\Admin;

use App\Service\Auth;
use App\Service\ContentType;
use RedBeanPHP\R as R;

class DashboardController extends AjaxController
{
    public function index()
    {
        Auth::requirePanelAccess();
        $user = Auth::user();

        $contentTypes = ContentType::definitions();

        $latestPosts = array_values(R::findAll(
            'content',
            ' type = ? AND status = ? AND deleted_at IS NULL ORDER BY updated_at DESC LIMIT ? ',
            ['post', 'published', 5]
        ));

        foreach ($latestPosts as $post) {
            $post->type_slug = $contentTypes[$post->type]['slug'] ?? $post->type;
        }

        $pendingComments = array_values(R::findAll(
            'comment',
            ' status = ? AND deleted_at IS NULL ORDER BY created_at DESC LIMIT ? ',
            ['pending', 5]
        ));

        $commentContentMap = [];
        if ($pendingComments) {
            $contentIds = array_values(array_unique(array_map(fn($c) => (int) $c->content_id, $pendingComments)));
            if ($contentIds) {
                $placeholders = implode(',', array_fill(0, count($contentIds), '?'));
                $contents = R::findAll('content', ' id IN (' . $placeholders . ') ', $contentIds);
                foreach ($contents as $content) {
                    $content->type_slug = $contentTypes[$content->type]['slug'] ?? $content->type;
                    $commentContentMap[(int) $content->id] = $content;
                }
            }
        }

        $recentLogins = R::getAll(
            'SELECT l.id, l.user_id, l.ip_address, l.created_at, u.email, u.nickname'
            . ' FROM loginlog l JOIN user u ON u.id = l.user_id'
            . ' ORDER BY l.created_at DESC LIMIT 5'
        );

        $this->render('admin/dashboard.twig', [
            'user' => $user,
            'current_menu' => 'dashboard',
            'latest_posts' => $latestPosts,
            'pending_comments' => $pendingComments,
            'comment_content_map' => $commentContentMap,
            'recent_logins' => $recentLogins,
        ]);
    }
}
