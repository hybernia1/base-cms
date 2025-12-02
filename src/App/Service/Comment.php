<?php
namespace App\Service;

use RedBeanPHP\R as R;

class Comment
{
    private const TABLE = 'comment';
    private const STATUSES = ['pending', 'approved'];

    public static function ensureSchema(): void
    {
        R::exec(
            "CREATE TABLE IF NOT EXISTS `" . self::TABLE . "` (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `content_id` INT UNSIGNED NOT NULL,
                `parent_id` INT UNSIGNED DEFAULT NULL,
                `user_id` INT UNSIGNED DEFAULT NULL,
                `author_name` VARCHAR(191) DEFAULT '',
                `author_email` VARCHAR(191) DEFAULT '',
                `body` TEXT NOT NULL,
                `status` VARCHAR(20) NOT NULL DEFAULT 'pending',
                `depth` TINYINT UNSIGNED NOT NULL DEFAULT 0,
                `created_at` DATETIME NOT NULL,
                `updated_at` DATETIME NOT NULL,
                `deleted_at` DATETIME DEFAULT NULL,
                KEY `idx_content` (`content_id`),
                KEY `idx_parent` (`parent_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $hasColumn = R::getCell(
            'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = ? AND COLUMN_NAME = ? AND TABLE_SCHEMA = DATABASE()',
            [self::TABLE, 'deleted_at']
        );

        if ((int) $hasColumn === 0) {
            R::exec("ALTER TABLE `" . self::TABLE . "` ADD COLUMN `deleted_at` DATETIME DEFAULT NULL AFTER `updated_at`");
        }
    }

    public static function create(array $data)
    {
        self::ensureSchema();

        $comment = R::dispense(self::TABLE);
        $comment->content_id = (int) ($data['content_id'] ?? 0);
        $comment->parent_id = $data['parent_id'] !== '' ? (int) $data['parent_id'] : null;
        $comment->user_id = $data['user_id'] !== '' ? (int) $data['user_id'] : null;
        $comment->author_name = trim($data['author_name'] ?? '');
        $comment->author_email = trim($data['author_email'] ?? '');
        $comment->body = trim($data['body'] ?? '');
        $comment->status = $data['status'] ?? 'pending';
        $comment->depth = (int) ($data['depth'] ?? 0);
        $comment->created_at = date('Y-m-d H:i:s');
        $comment->updated_at = date('Y-m-d H:i:s');

        R::store($comment);

        return $comment;
    }

    public static function approve(int $id): void
    {
        self::ensureSchema();
        $comment = self::find($id);
        if ($comment) {
            $comment->status = 'approved';
            $comment->updated_at = date('Y-m-d H:i:s');
            R::store($comment);
        }
    }

    public static function find(int $id)
    {
        self::ensureSchema();
        $comment = R::load(self::TABLE, $id);
        return $comment && $comment->id && $comment->deleted_at === null ? $comment : null;
    }

    public static function update(int $id, array $data)
    {
        self::ensureSchema();

        $comment = self::find($id);
        if (!$comment) {
            return null;
        }

        if (isset($data['author_name'])) {
            $comment->author_name = trim($data['author_name']);
        }

        if (isset($data['author_email'])) {
            $comment->author_email = trim($data['author_email']);
        }

        if (isset($data['body'])) {
            $comment->body = trim($data['body']);
        }

        if (isset($data['status']) && in_array($data['status'], self::STATUSES, true)) {
            $comment->status = $data['status'];
        }

        $comment->updated_at = date('Y-m-d H:i:s');
        R::store($comment);

        return $comment;
    }

    public static function delete(int $id): void
    {
        self::ensureSchema();
        $comment = R::load(self::TABLE, $id);
        if (!$comment || !$comment->id) {
            return;
        }

        if ($comment->deleted_at !== null) {
            R::trash($comment);
            return;
        }

        $comment->deleted_at = date('Y-m-d H:i:s');
        R::store($comment);
    }

    public static function findByContent(int $contentId): array
    {
        self::ensureSchema();
        $items = R::findAll(
            self::TABLE,
            ' content_id = ? AND status = ? AND deleted_at IS NULL ORDER BY created_at ASC ',
            [$contentId, 'approved']
        );

        $users = [];
        $result = [];

        foreach ($items as $item) {
            $displayName = $item->author_name ?: 'Anonym';
            $profileUrl = null;

            if ($item->user_id) {
                if (!array_key_exists($item->user_id, $users)) {
                    $users[$item->user_id] = R::load('user', (int) $item->user_id);
                }

                $user = $users[$item->user_id];
                if ($user && $user->id) {
                    $displayName = $user->nickname ?: ($item->author_name ?: ($user->email ?? 'Uživatel'));

                    if ((int) ($user->is_profile_public ?? 1) === 1) {
                        $profileUrl = '/users/' . $user->id;
                    }
                }
            }

            $result[] = [
                'id' => (int) $item->id,
                'content_id' => (int) $item->content_id,
                'parent_id' => $item->parent_id,
                'user_id' => $item->user_id,
                'author_name' => $displayName,
                'profile_url' => $profileUrl,
                'body' => $item->body,
                'status' => $item->status,
                'depth' => (int) $item->depth,
                'created_at' => $item->created_at,
            ];
        }

        return $result;
    }

    public static function allPending(): array
    {
        self::ensureSchema();
        $items = R::findAll(self::TABLE, ' status = ? AND deleted_at IS NULL ORDER BY created_at DESC ', ['pending']);
        return array_values($items);
    }

    public static function all(?string $status = null): array
    {
        self::ensureSchema();

        if ($status === 'trash') {
            $items = R::findAll(self::TABLE, ' deleted_at IS NOT NULL ORDER BY created_at DESC ');
        } elseif ($status && $status !== 'all' && in_array($status, self::STATUSES, true)) {
            $items = R::findAll(self::TABLE, ' status = ? AND deleted_at IS NULL ORDER BY created_at DESC ', [$status]);
        } else {
            $items = R::findAll(self::TABLE, ' deleted_at IS NULL ORDER BY created_at DESC ');
        }

        return array_values($items);
    }

    public static function statusCounts(): array
    {
        self::ensureSchema();
        $counts = [];
        foreach (self::STATUSES as $status) {
            $counts[$status] = (int) R::count(self::TABLE, ' status = ? AND deleted_at IS NULL ', [$status]);
        }

        $counts['trash'] = (int) R::count(self::TABLE, ' deleted_at IS NOT NULL ');
        $counts['all'] = array_sum($counts) - $counts['trash'];

        return $counts;
    }

    public static function statuses(): array
    {
        return self::STATUSES;
    }

    public static function emptyTrash(): void
    {
        self::ensureSchema();
        $trashed = R::findAll(self::TABLE, ' deleted_at IS NOT NULL ');
        if ($trashed) {
            R::trashAll($trashed);
        }
    }
}
