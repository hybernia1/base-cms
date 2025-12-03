<?php
namespace App\Service;

use RedBeanPHP\R as R;

class CommentNotifier
{
    public static function sendApprovedNotification($comment): void
    {
        $recipient = self::resolveAuthorEmail($comment);
        if ($recipient === '') {
            return;
        }

        $context = self::buildCommentContext($comment);

        EmailTemplateManager::send('comment_approved', $recipient, [
            'comment_body' => $comment->body,
            'comment_url' => $context['url'],
            'content_title' => $context['title'],
            'site_name' => Setting::get('site_name', 'Web'),
        ]);
    }

    public static function sendReplyNotification($comment): void
    {
        if (!$comment->parent_id) {
            return;
        }

        $parent = R::load('comment', (int) $comment->parent_id);
        if (!$parent || !$parent->id) {
            return;
        }

        $recipient = self::resolveAuthorEmail($parent);
        $authorEmail = self::resolveAuthorEmail($comment);

        if ($recipient === '' || $recipient === $authorEmail) {
            return;
        }

        $context = self::buildCommentContext($comment);

        EmailTemplateManager::send('comment_reply', $recipient, [
            'reply_body' => $comment->body,
            'comment_url' => $context['url'],
            'content_title' => $context['title'],
            'site_name' => Setting::get('site_name', 'Web'),
        ]);
    }

    public static function sendDeletedNotification($comment): void
    {
        $recipient = self::resolveAuthorEmail($comment);
        if ($recipient === '') {
            return;
        }

        EmailTemplateManager::send('comment_deleted', $recipient, [
            'comment_body' => $comment->body,
            'site_name' => Setting::get('site_name', 'Web'),
        ]);
    }

    private static function resolveAuthorEmail($comment): string
    {
        if (!empty($comment->author_email)) {
            return (string) $comment->author_email;
        }

        if (!empty($comment->user_id)) {
            $user = R::load('user', (int) $comment->user_id);
            if ($user && $user->id && !empty($user->email)) {
                return (string) $user->email;
            }
        }

        return '';
    }

    private static function buildCommentContext($comment): array
    {
        $content = $comment->content_id ? R::load('content', (int) $comment->content_id) : null;

        if ($content && $content->id) {
            $typeSlug = ContentType::slug((string) $content->type);
            $contentSlug = (string) ($content->slug ?? '');
            $baseUrl = $typeSlug && $contentSlug ? '/' . $typeSlug . '/' . $contentSlug : '';

            return [
                'title' => (string) ($content->title ?? ''),
                'url' => $comment->id && $baseUrl !== '' ? $baseUrl . '#comment-' . $comment->id : $baseUrl,
            ];
        }

        return [
            'title' => 'Obsah',
            'url' => $comment->id ? '#comment-' . $comment->id : '',
        ];
    }
}
