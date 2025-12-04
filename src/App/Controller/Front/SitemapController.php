<?php
namespace App\Controller\Front;

use App\Service\ContentType;
use App\Service\TermType;
use DateTime;
use RedBeanPHP\R as R;

class SitemapController extends BaseFrontController
{
    private const PAGE_LIMIT = 1000;

    public function index(): void
    {
        $items = [];

        foreach (ContentType::definitions() as $typeKey => $definition) {
            $count = (int) R::count(
                'content',
                ' type = ? AND status = ? AND publish_at <= ? AND deleted_at IS NULL ',
                [$typeKey, 'published', date('Y-m-d H:i:s')]
            );
            if ($count === 0) {
                continue;
            }

            $pages = (int) ceil($count / self::PAGE_LIMIT);
            $lastMod = $this->lastModified(
                'content',
                ' type = ? AND status = ? AND publish_at <= ? AND deleted_at IS NULL ',
                [$typeKey, 'published', date('Y-m-d H:i:s')]
            );

            for ($page = 1; $page <= $pages; $page++) {
                $items[] = [
                    'loc' => $this->baseUrl() . '/' . $definition['slug'] . '-content' . ($page > 1 ? '-' . $page : '') . '.xml',
                    'lastmod' => $lastMod,
                ];
            }
        }

        foreach (TermType::definitions() as $typeKey => $definition) {
            $count = (int) R::count('term', ' type = ? ', [$typeKey]);
            if ($count === 0) {
                continue;
            }

            $pages = (int) ceil($count / self::PAGE_LIMIT);
            $lastMod = $this->lastModified('term', ' type = ? ', [$typeKey]);

            for ($page = 1; $page <= $pages; $page++) {
                $items[] = [
                    'loc' => $this->baseUrl() . '/' . $typeKey . '-term' . ($page > 1 ? '-' . $page : '') . '.xml',
                    'lastmod' => $lastMod,
                ];
            }
        }

        $userCount = (int) R::count('user', ' is_profile_public = 1 ');
        if ($userCount > 0) {
            $userPages = (int) ceil($userCount / self::PAGE_LIMIT);
            $userLastMod = $this->lastModified('user', ' is_profile_public = 1 ', []);

            for ($page = 1; $page <= $userPages; $page++) {
                $items[] = [
                    'loc' => $this->baseUrl() . '/users-sitemap' . ($page > 1 ? '-' . $page : '') . '.xml',
                    'lastmod' => $userLastMod,
                ];
            }
        }

        $this->renderXml('front/sitemap/index.twig', ['items' => $items]);
    }

    public function content(string $typeSlug, ?string $page = null): void
    {
        $typeKey = ContentType::keyFromSlug($typeSlug);
        if (!$typeKey) {
            $this->renderNotFoundXml();
            return;
        }

        $pageNumber = max(1, (int) ($page ?? 1));
        $count = (int) R::count(
            'content',
            ' type = ? AND status = ? AND publish_at <= ? AND deleted_at IS NULL ',
            [$typeKey, 'published', date('Y-m-d H:i:s')]
        );
        if ($count === 0 || $this->isOutOfRange($count, $pageNumber)) {
            $this->renderNotFoundXml();
            return;
        }

        $urls = $this->loadContentUrls($typeKey, $typeSlug, $pageNumber);
        $this->renderXml('front/sitemap/list.twig', ['urls' => $urls]);
    }

    public function term(string $typeKey, ?string $page = null): void
    {
        $definitions = TermType::definitions();
        if (!isset($definitions[$typeKey])) {
            $this->renderNotFoundXml();
            return;
        }

        $pageNumber = max(1, (int) ($page ?? 1));
        $count = (int) R::count('term', ' type = ? ', [$typeKey]);
        if ($count === 0 || $this->isOutOfRange($count, $pageNumber)) {
            $this->renderNotFoundXml();
            return;
        }

        $urls = $this->loadTermUrls($typeKey, $pageNumber);
        $this->renderXml('front/sitemap/list.twig', ['urls' => $urls]);
    }

    public function users(?string $page = null): void
    {
        $pageNumber = max(1, (int) ($page ?? 1));
        $count = (int) R::count('user', ' is_profile_public = 1 ');
        if ($count === 0 || $this->isOutOfRange($count, $pageNumber)) {
            $this->renderNotFoundXml();
            return;
        }

        $urls = $this->loadUserUrls($pageNumber);
        $this->renderXml('front/sitemap/list.twig', ['urls' => $urls]);
    }

    private function loadContentUrls(string $typeKey, string $typeSlug, int $page): array
    {
        $offset = ($page - 1) * self::PAGE_LIMIT;
        $rows = R::getAll(
            'SELECT slug, COALESCE(updated_at, created_at) AS last_change '
            . 'FROM content WHERE type = ? AND status = ? AND publish_at <= ? AND deleted_at IS NULL '
            . 'ORDER BY publish_at DESC LIMIT ? OFFSET ?',
            [$typeKey, 'published', date('Y-m-d H:i:s'), self::PAGE_LIMIT, $offset]
        );

        return array_map(function ($row) use ($typeSlug) {
            return [
                'loc' => $this->baseUrl() . '/' . $typeSlug . '/' . $row['slug'],
                'lastmod' => $this->formatDate($row['last_change'] ?? null),
            ];
        }, $rows);
    }

    private function loadTermUrls(string $typeKey, int $page): array
    {
        $offset = ($page - 1) * self::PAGE_LIMIT;
        $rows = R::getAll(
            'SELECT slug, COALESCE(updated_at, created_at) AS last_change '
            . 'FROM term WHERE type = ? ORDER BY created_at DESC LIMIT ? OFFSET ?',
            [$typeKey, self::PAGE_LIMIT, $offset]
        );

        return array_map(function ($row) use ($typeKey) {
            return [
                'loc' => $this->baseUrl() . '/terms/' . $typeKey . '/' . $row['slug'],
                'lastmod' => $this->formatDate($row['last_change'] ?? null),
            ];
        }, $rows);
    }

    private function loadUserUrls(int $page): array
    {
        $offset = ($page - 1) * self::PAGE_LIMIT;
        $rows = R::getAll(
            'SELECT id, COALESCE(updated_at, created_at) AS last_change '
            . 'FROM user WHERE is_profile_public = 1 ORDER BY created_at DESC LIMIT ? OFFSET ?',
            [self::PAGE_LIMIT, $offset]
        );

        return array_map(function ($row) {
            return [
                'loc' => $this->baseUrl() . '/users/' . $row['id'],
                'lastmod' => $this->formatDate($row['last_change'] ?? null),
            ];
        }, $rows);
    }

    private function renderXml(string $template, array $context = []): void
    {
        header('Content-Type: application/xml; charset=utf-8');
        echo $this->twig->render($template, $context);
    }

    private function renderNotFoundXml(): void
    {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Sitemap nenalezena.';
    }

    private function baseUrl(): string
    {
        static $baseUrl = null;
        if ($baseUrl !== null) {
            return $baseUrl;
        }

        $scheme = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $baseUrl = $scheme . '://' . $host;

        return $baseUrl;
    }

    private function isOutOfRange(int $count, int $page): bool
    {
        if ($count === 0) {
            return true;
        }

        $maxPage = (int) ceil($count / self::PAGE_LIMIT);
        return $page > $maxPage;
    }

    private function lastModified(string $table, string $where, array $params): ?string
    {
        $value = R::getCell('SELECT MAX(COALESCE(updated_at, created_at)) FROM ' . $table . ' WHERE ' . $where, $params);
        return $this->formatDate($value ?: null);
    }

    private function formatDate(?string $value): ?string
    {
        if (!$value) {
            return null;
        }

        try {
            $date = new DateTime($value);
        } catch (\Throwable $e) {
            return null;
        }

        return $date->format('c');
    }
}

