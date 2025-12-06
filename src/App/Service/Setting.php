<?php
namespace App\Service;

use RedBeanPHP\R as R;

class Setting
{
    public const DEFAULTS = [
        'site_name' => 'CMS',
        'allow_webp' => '0',
        'allowed_upload_types' => 'jpg,jpeg,png,gif,webp,pdf,zip',
        'allow_registration' => '1',
        'breadcrumbs_enabled' => '1',
        'comments_enabled' => '1',
        'comments_allow_replies' => '1',
        'comments_max_depth' => '2',
        'comments_moderation' => '1',
        'comments_allow_anonymous' => '0',
        'comments_rate_limit_seconds' => '60',
        'mail_transport' => 'mail',
        'smtp_host' => '',
        'smtp_port' => '587',
        'smtp_username' => '',
        'smtp_password' => '',
        'smtp_encryption' => 'tls',
        'smtp_from_email' => '',
        'smtp_from_name' => '',
        'timezone' => 'Europe/Prague',
        'date_format' => 'd.m.Y',
        'time_format' => 'H:i',
        'content_types' => '',
        'term_types' => '',
        'site_logo_id' => '',
        'site_favicon_id' => '',
        'theme' => 'blue',
        'indexing_enabled' => '1',
        'google_analytics_id' => '',
    ];

    public static function mediaDetails(?int $mediaId): ?array
    {
        if (!$mediaId || $mediaId <= 0) {
            return null;
        }

        $media = R::findOne('media', ' id = ? ', [$mediaId]);
        if (!$media || !$media->id) {
            return null;
        }

        $filename = $media->webp_filename ?: $media->filename;
        $path = trim((string) $media->path, '/');

        return [
            'id' => (int) $media->id,
            'url' => '/' . ($path ? $path . '/' : '') . $filename,
            'original_name' => $media->original_name ?: $media->filename,
            'alt' => $media->alt ?: ($media->original_name ?: $media->filename),
            'is_image' => (bool) $media->is_image,
            'mime_type' => $media->mime_type,
        ];
    }

    public static function mediaUrl(?int $mediaId): ?string
    {
        $details = self::mediaDetails($mediaId);

        return $details['url'] ?? null;
    }

    public static function get(string $key, $default = null)
    {
        $bean = R::findOne('setting', ' `key` = ? ', [$key]);
        if ($bean && $bean->id) {
            return $bean->value;
        }

        return self::DEFAULTS[$key] ?? $default;
    }

    public static function set(string $key, $value): void
    {
        $bean = R::findOne('setting', ' `key` = ? ', [$key]) ?? R::dispense('setting');
        $bean->key = $key;
        $bean->value = $value;
        if (!$bean->id && !isset($bean->created_at)) {
            $bean->created_at = date('Y-m-d H:i:s');
        }
        $bean->updated_at = date('Y-m-d H:i:s');
        R::store($bean);
    }

    public static function all(): array
    {
        $items = R::findAll('setting');
        $result = self::DEFAULTS;

        foreach ($items as $item) {
            $result[$item->key] = $item->value;
        }

        return $result;
    }
}
