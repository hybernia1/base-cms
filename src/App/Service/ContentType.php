<?php
namespace App\Service;

use App\Service\Setting;
use App\Service\Slugger;

class ContentType
{
    private const DEFAULT_TYPES = [
        [
            'key' => 'post',
            'name' => 'Příspěvek',
            'plural_name' => 'Příspěvky',
            'slug' => 'prispevky',
            'menu_label' => 'Příspěvky',
        ],
        [
            'key' => 'page',
            'name' => 'Stránka',
            'plural_name' => 'Stránky',
            'slug' => 'stranky',
            'menu_label' => 'Stránky',
        ],
    ];

    public static function all(): array
    {
        return array_map(function ($definition) {
            return $definition['name'];
        }, self::definitions());
    }

    public static function exists(string $type): bool
    {
        return array_key_exists($type, self::definitions());
    }

    public static function label(string $type): string
    {
        $definitions = self::definitions();
        return $definitions[$type]['name'] ?? $type;
    }

    public static function menuLabel(string $type): string
    {
        $definitions = self::definitions();
        return $definitions[$type]['menu_label'] ?? self::label($type);
    }

    public static function slug(string $type): string
    {
        $definitions = self::definitions();
        return $definitions[$type]['slug'] ?? $type;
    }

    public static function keyFromSlug(?string $slug): ?string
    {
        if ($slug === null) {
            return null;
        }

        foreach (self::definitions() as $key => $definition) {
            if ($definition['slug'] === $slug) {
                return $key;
            }
        }

        return null;
    }

    public static function defaultSlug(string $type): string
    {
        return self::slug($type);
    }

    public static function definitions(): array
    {
        $raw = Setting::get('content_types', '');
        $decoded = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;

        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded) && $decoded) {
            $valid = [];
            foreach ($decoded as $item) {
                if (!isset($item['key'])) {
                    continue;
                }
                $key = trim((string) $item['key']);
                if ($key === '') {
                    continue;
                }

                $slug = Slugger::slugify(trim((string) ($item['slug'] ?? $key)) ?: $key);
                if ($slug === '') {
                    $slug = $key;
                }

                $slug = Slugger::uniqueInCollection($slug, array_column($valid, 'slug'));

                $valid[$key] = [
                    'key' => $key,
                    'name' => trim((string) ($item['name'] ?? $key)) ?: $key,
                    'plural_name' => trim((string) ($item['plural_name'] ?? ($item['menu_label'] ?? $key))) ?: $key,
                    'slug' => $slug,
                    'menu_label' => trim((string) ($item['menu_label'] ?? ($item['plural_name'] ?? $key))) ?: $key,
                ];
            }

            if ($valid) {
                return $valid;
            }
        }

        return array_reduce(self::DEFAULT_TYPES, function ($carry, $item) {
            $carry[$item['key']] = $item;
            return $carry;
        }, []);
    }

    public static function defaults(): array
    {
        return self::DEFAULT_TYPES;
    }
}
