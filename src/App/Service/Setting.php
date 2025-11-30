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
    ];

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
