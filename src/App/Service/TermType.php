<?php
namespace App\Service;

class TermType
{
    private const TYPES = [
        'tag' => 'Tag',
        'category' => 'Kategorie',
    ];

    public static function all(): array
    {
        return self::TYPES;
    }

    public static function exists(string $type): bool
    {
        return array_key_exists($type, self::TYPES);
    }

    public static function label(string $type): string
    {
        return self::TYPES[$type] ?? $type;
    }
}
