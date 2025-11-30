<?php
namespace App\Service;

use RedBeanPHP\R as R;

class Slugger
{
    public static function slugify(string $text): string
    {
        $text = trim($text);
        if ($text === '') {
            return '';
        }

        if (class_exists('\Transliterator')) {
            $text = transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $text);
        } else {
            $text = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
            $text = strtolower((string) $text);
        }

        $text = preg_replace('/[^a-z0-9]+/i', '-', (string) $text);
        $text = trim((string) $text, '-');

        return $text;
    }

    public static function uniqueForContent(string $text, string $type, int $ignoreId = 0): string
    {
        return self::uniqueInTable(self::slugify($text), 'content', ['type' => $type], $ignoreId);
    }

    public static function uniqueForTerm(string $text, string $type, int $ignoreId = 0): string
    {
        return self::uniqueInTable(self::slugify($text), 'term', ['type' => $type], $ignoreId);
    }

    public static function uniqueInCollection(string $slug, array $existing): string
    {
        $base = $slug !== '' ? $slug : 'item';
        $candidate = $base;
        $counter = 2;

        while (in_array($candidate, $existing, true)) {
            $candidate = sprintf('%s-%d', $base, $counter);
            $counter++;
        }

        return $candidate;
    }

    private static function uniqueInTable(string $slug, string $table, array $conditions, int $ignoreId): string
    {
        $base = $slug !== '' ? $slug : 'item';
        $candidate = $base;
        $counter = 1;

        while (self::exists($candidate, $table, $conditions, $ignoreId)) {
            $counter++;
            $candidate = sprintf('%s-%d', $base, $counter);
        }

        return $candidate;
    }

    private static function exists(string $slug, string $table, array $conditions, int $ignoreId): bool
    {
        $queryParts = [' slug = ? '];
        $params = [$slug];

        foreach ($conditions as $field => $value) {
            $queryParts[] = sprintf(' %s = ? ', $field);
            $params[] = $value;
        }

        if ($ignoreId > 0) {
            $queryParts[] = ' id != ? ';
            $params[] = $ignoreId;
        }

        $query = implode(' AND ', $queryParts);

        return (bool) R::findOne($table, $query, $params);
    }
}
