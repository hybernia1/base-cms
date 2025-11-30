<?php
namespace App\Service;

use App\Service\ContentType;

class TermType
{
    private const DEFAULT_TYPES = [
        [
            'key' => 'tag',
            'label' => 'Tag',
            'content_types' => [],
        ],
        [
            'key' => 'category',
            'label' => 'Kategorie',
            'content_types' => [],
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

    public static function contentTypesFor(string $type): array
    {
        $definitions = self::definitions();
        return $definitions[$type]['content_types'] ?? [];
    }

    public static function allowsContentType(string $type, string $contentType): bool
    {
        if (!self::exists($type)) {
            return false;
        }

        $allowed = self::contentTypesFor($type);

        if (empty($allowed)) {
            return true;
        }

        return in_array($contentType, $allowed, true);
    }

    public static function definitions(): array
    {
        $raw = Setting::get('term_types', '');
        $decoded = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
        $availableContentTypes = array_keys(ContentType::definitions());

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
                    'content_types' => self::sanitizeContentTypes($item['content_types'] ?? [], $availableContentTypes),
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

    private static function sanitizeContentTypes($raw, array $allowedKeys): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $clean = [];
        foreach ($raw as $value) {
            $value = trim((string) $value);
            if ($value !== '' && in_array($value, $allowedKeys, true)) {
                $clean[] = $value;
            }
        }

        return array_values(array_unique($clean));
    }
}
