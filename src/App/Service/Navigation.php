<?php
namespace App\Service;

use RedBeanPHP\R as R;

class Navigation
{
    public const TYPE_CUSTOM = 'custom';
    public const TYPE_CONTENT = 'content';
    public const TYPE_TERM = 'term';
    public const TYPE_ARCHIVE = 'archive';
    public const TYPE_CORE_HOME = 'core_home';
    public const TYPE_CORE_LOGIN = 'core_login';
    public const TYPE_CORE_REGISTER = 'core_register';
    public const TYPE_CORE_SEARCH = 'core_search';
    public const TYPE_CORE_PROFILE = 'core_profile';
    public const TYPE_CORE_LOGOUT = 'core_logout';

    public static function typeOptions(): array
    {
        return [
            self::TYPE_CUSTOM => 'Vlastní odkaz',
            self::TYPE_CONTENT => 'Obsah',
            self::TYPE_TERM => 'Term',
            self::TYPE_ARCHIVE => 'Archiv obsahu',
            self::TYPE_CORE_HOME => 'Domovská stránka',
            self::TYPE_CORE_LOGIN => 'Přihlášení',
            self::TYPE_CORE_REGISTER => 'Registrace',
            self::TYPE_CORE_SEARCH => 'Vyhledávání',
            self::TYPE_CORE_PROFILE => 'Můj profil',
            self::TYPE_CORE_LOGOUT => 'Odhlášení',
        ];
    }

    public static function allowedTypes(): array
    {
        return array_keys(self::typeOptions());
    }

    public static function tree(bool $skipInvalid = true): array
    {
        if (!self::tableReady()) {
            return [];
        }

        $items = R::findAll('navigationitem', ' ORDER BY position ASC, id ASC ');

        return self::buildTree($items, $skipInvalid);
    }

    public static function flatList(): array
    {
        if (!self::tableReady()) {
            return [];
        }

        $tree = self::tree(false);
        $result = [];

        self::flatten($tree, $result);

        return $result;
    }

    public static function resolveUrl(array $item): ?string
    {
        return $item['url'] ?? null;
    }

    private static function buildTree(array $items, bool $skipInvalid): array
    {
        $prepared = [];

        foreach ($items as $bean) {
            $prepared[(int) $bean->id] = self::prepareItem($bean);
        }

        foreach ($prepared as $id => &$item) {
            $parentId = $item['parent_id'];
            if ($parentId !== null && isset($prepared[$parentId])) {
                $prepared[$parentId]['children'][] =& $item;
            }
        }
        unset($item);

        $tree = [];
        foreach ($prepared as $id => $item) {
            if ($item['parent_id'] === null || !isset($prepared[$item['parent_id']])) {
                $tree[] = $item;
            }
        }

        self::sortTree($tree);

        if ($skipInvalid) {
            $tree = self::filterInvalid($tree);
        }

        return $tree;
    }

    private static function filterInvalid(array $items): array
    {
        $result = [];

        foreach ($items as $item) {
            if (!$item['is_valid']) {
                continue;
            }

            if (!empty($item['children'])) {
                $item['children'] = self::filterInvalid($item['children']);
            }

            $result[] = $item;
        }

        return $result;
    }

    private static function sortTree(array &$items): void
    {
        usort($items, function ($a, $b) {
            $positionCmp = ($a['position'] ?? 0) <=> ($b['position'] ?? 0);
            if ($positionCmp !== 0) {
                return $positionCmp;
            }

            return ($a['id'] ?? 0) <=> ($b['id'] ?? 0);
        });

        foreach ($items as &$item) {
            if (!empty($item['children'])) {
                self::sortTree($item['children']);
            }
        }
    }

    private static function flatten(array $items, array &$result, string $prefix = ''): void
    {
        foreach ($items as $item) {
            $result[] = [
                'id' => $item['id'],
                'label' => $prefix . $item['label'],
            ];

            if (!empty($item['children'])) {
                self::flatten($item['children'], $result, $prefix . '— ');
            }
        }
    }

    private static function prepareItem($bean): array
    {
        $resolved = self::resolveTarget($bean);

        return [
            'id' => (int) $bean->id,
            'label' => $bean->label ?: 'Odkaz',
            'type' => $bean->type ?: self::TYPE_CUSTOM,
            'parent_id' => $bean->parent_id ? (int) $bean->parent_id : null,
            'position' => (int) ($bean->position ?? 0),
            'open_in_new_tab' => (int) ($bean->open_in_new_tab ?? 0) === 1,
            'url' => $resolved['url'],
            'is_valid' => $resolved['is_valid'],
            'note' => $resolved['note'],
            'children' => [],
        ];
    }

    private static function tableReady(): bool
    {
        try {
            R::inspect('navigationitem');
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private static function resolveTarget($bean): array
    {
        $type = $bean->type ?? self::TYPE_CUSTOM;
        $note = null;
        $url = null;

        switch ($type) {
            case self::TYPE_CUSTOM:
                $url = trim((string) ($bean->url ?? ''));
                if ($url === '') {
                    $note = 'Chybí cílová URL.';
                }
                break;

            case self::TYPE_CONTENT:
                $contentId = (int) ($bean->target_id ?? 0);
                if ($contentId > 0) {
                    $content = R::findOne(
                        'content',
                        ' id = ? AND status = ? AND deleted_at IS NULL ',
                        [$contentId, 'published']
                    );
                    if ($content) {
                        $typeSlug = ContentType::slug($content->type ?? '');
                        if ($typeSlug !== '') {
                            $url = '/' . $typeSlug . '/' . ($content->slug ?? '');
                        } else {
                            $note = 'Typ obsahu postrádá slug.';
                        }
                    } else {
                        $note = 'Obsah nenalezen nebo není publikován.';
                    }
                } else {
                    $note = 'Není vybrán obsah.';
                }
                break;

            case self::TYPE_TERM:
                $termId = (int) ($bean->target_id ?? 0);
                if ($termId > 0) {
                    $term = R::findOne('term', ' id = ? ', [$termId]);
                    if ($term) {
                        $url = '/terms/' . $term->type . '/' . $term->slug;
                    } else {
                        $note = 'Term nebyl nalezen.';
                    }
                } else {
                    $note = 'Není vybrán term.';
                }
                break;

            case self::TYPE_ARCHIVE:
                $targetKey = trim((string) ($bean->target_key ?? ''));
                if ($targetKey !== '' && ContentType::exists($targetKey)) {
                    $url = '/' . ContentType::slug($targetKey);
                } else {
                    $note = 'Typ obsahu nebyl nalezen.';
                }
                break;

            case self::TYPE_CORE_HOME:
                $url = '/';
                break;

            case self::TYPE_CORE_LOGIN:
                $url = '/login';
                break;

            case self::TYPE_CORE_REGISTER:
                $url = '/register';
                break;

            case self::TYPE_CORE_SEARCH:
                $url = '/search';
                break;

            case self::TYPE_CORE_PROFILE:
                $url = '/profile';
                break;

            case self::TYPE_CORE_LOGOUT:
                $url = '/logout';
                break;

            default:
                $note = 'Typ odkazu není podporován.';
                break;
        }

        return [
            'url' => $url ?: null,
            'is_valid' => $url !== null,
            'note' => $note,
        ];
    }
}
