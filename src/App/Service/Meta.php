<?php
namespace App\Service;

use RedBeanPHP\R as R;

class Meta
{
    public const TARGET_CONTENT = 'content';
    public const TARGET_TERM = 'term';
    public const TARGET_USER = 'user';

    public static function allKeys(): array
    {
        $items = R::findAll('metakey', ' ORDER BY name ASC ');

        return array_map(static function ($item) {
            return [
                'id' => (int) $item->id,
                'name' => (string) $item->name,
                'key' => (string) $item->key,
                'created_at' => $item->created_at,
            ];
        }, $items);
    }

    public static function allKeysIndexed(): array
    {
        $indexed = [];

        foreach (self::allKeys() as $item) {
            $indexed[$item['key']] = $item;
        }

        return $indexed;
    }

    public static function sanitizeValues($input): array
    {
        if (!is_array($input)) {
            return [];
        }

        $values = [];
        foreach ($input as $key => $value) {
            $cleanKey = trim((string) $key);
            if ($cleanKey === '') {
                continue;
            }

            if (is_array($value)) {
                $cleanValue = trim(implode(', ', array_map('strval', $value)));
            } else {
                $cleanValue = trim((string) $value);
            }

            $values[$cleanKey] = $cleanValue;
        }

        return $values;
    }

    public static function valuesFor(string $targetType, int $targetId): array
    {
        if ($targetId <= 0) {
            return [];
        }

        $definitions = self::allKeys();
        $keyById = [];
        foreach ($definitions as $definition) {
            $keyById[$definition['id']] = $definition['key'];
        }

        $items = R::findAll('meta', ' target_type = ? AND target_id = ? ', [$targetType, $targetId]);
        $result = [];

        foreach ($items as $item) {
            if (!$item->metakey_id || !isset($keyById[$item->metakey_id])) {
                continue;
            }

            $key = $keyById[$item->metakey_id];
            $result[$key] = $item->value ?? '';
        }

        return $result;
    }

    public static function saveValues(string $targetType, int $targetId, array $values): void
    {
        if ($targetId <= 0) {
            return;
        }

        $definitions = self::allKeysIndexed();
        if (!$definitions) {
            R::exec('DELETE FROM meta WHERE target_type = ? AND target_id = ?', [$targetType, $targetId]);
            return;
        }

        $sanitized = self::sanitizeValues($values);
        $keyById = [];
        foreach ($definitions as $definition) {
            $keyById[$definition['id']] = $definition['key'];
        }

        $existing = R::findAll('meta', ' target_type = ? AND target_id = ? ', [$targetType, $targetId]);
        $existingByKey = [];

        foreach ($existing as $item) {
            if (isset($keyById[$item->metakey_id])) {
                $existingByKey[$keyById[$item->metakey_id]] = $item;
            }
        }

        foreach ($definitions as $key => $definition) {
            $value = $sanitized[$key] ?? '';

            if ($value === '') {
                if (isset($existingByKey[$key])) {
                    R::trash($existingByKey[$key]);
                }
                continue;
            }

            if (isset($existingByKey[$key])) {
                $bean = $existingByKey[$key];
                $bean->value = $value;
                $bean->updated_at = date('Y-m-d H:i:s');
                R::store($bean);
                continue;
            }

            $bean = R::dispense('meta');
            $bean->metakey_id = $definition['id'];
            $bean->target_type = $targetType;
            $bean->target_id = $targetId;
            $bean->value = $value;
            $bean->created_at = date('Y-m-d H:i:s');
            $bean->updated_at = date('Y-m-d H:i:s');
            R::store($bean);
        }
    }

    public static function deleteKeyWithValues(int $id): void
    {
        $key = R::load('metakey', $id);
        if (!$key || !$key->id) {
            return;
        }

        R::exec('DELETE FROM meta WHERE metakey_id = ?', [$id]);
        R::trash($key);
    }

    public static function keyExists(string $key, int $ignoreId = 0): bool
    {
        $query = ' `key` = ? ';
        $params = [$key];

        if ($ignoreId > 0) {
            $query .= ' AND id != ? ';
            $params[] = $ignoreId;
        }

        return (bool) R::findOne('metakey', $query, $params);
    }
}
