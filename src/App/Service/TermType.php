<?php
namespace App\Service;

class TermType
{
    private const DEFAULT_TYPES = [
        [
            'key' => 'tag',
            'label' => 'Tag',
        ],
        [
            'key' => 'category',
            'label' => 'Kategorie',
        ],
    ];

    public static function all(): array
    {
        return array_map(function ($definition) {
            return $definition['label'];
        }, self::definitions());
    }

    public static function exists(string $type): bool
    {
        return array_key_exists($type, self::definitions());
    }

    public static function label(string $type): string
    {
        $definitions = self::definitions();
        return $definitions[$type]['label'] ?? $type;
    }

    public static function definitions(): array
    {
        $raw = Setting::get('term_types', '');
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

                $valid[$key] = [
                    'key' => $key,
                    'label' => trim((string) ($item['label'] ?? $key)) ?: $key,
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
