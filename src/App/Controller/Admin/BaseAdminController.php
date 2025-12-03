<?php
namespace App\Controller\Admin;

use App\Service\Auth;
use App\Service\Flash;
use App\Service\Setting;
use App\Service\ContentType;
use App\Service\TermType;

abstract class BaseAdminController
{
    protected $twig;

    public function __construct()
    {
        $this->twig = $GLOBALS['app']['twig'];
    }

    protected function render(string $template, array $context = []): void
    {
        echo $this->twig->render($template, array_merge(
            $this->baseContext(),
            $context
        ));
    }

    protected function baseContext(bool $consumeFlash = true): array
    {
        $user   = Auth::user();
        $flash  = $consumeFlash ? Flash::consume() : ['success' => [], 'error' => []];
        $settings = Setting::all();
        $config = $GLOBALS['app']['config'] ?? [];

        return [
            'app_user'          => $user,
            'flash_success'     => $flash['success'],
            'flash_error'       => $flash['error'],
            'content_type_menu' => ContentType::definitions(),
            'term_type_menu'    => TermType::definitions(),
            'settings'          => $settings,
            'app_env'           => $config['env'] ?? 'prod',
            'app_is_debug'      => ($config['env'] ?? 'prod') !== 'prod',
        ];
    }


   protected function buildPagination(int $totalItems, int $perPage = 15): array
{
    $perPage = max(1, $perPage);
    $pages = max(1, (int) ceil($totalItems / $perPage));
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $page = min($page, $pages);
    $offset = ($page - 1) * $perPage;

    $queryParams = $_GET;
    unset($queryParams['page'], $queryParams['ajax']); // <<< přidej ajax

    $basePath = strtok($_SERVER['REQUEST_URI'] ?? '', '?') ?: '';

    $buildUrl = function (int $targetPage) use ($basePath, $queryParams): string {
        $query = http_build_query(array_merge($queryParams, ['page' => $targetPage]));
        return $query ? $basePath . '?' . $query : $basePath;
    };

    $pageUrls = [];
    for ($i = 1; $i <= $pages; $i++) {
        $pageUrls[$i] = $buildUrl($i);
    }

    return [
        'page'         => $page,
        'pages'        => $pages,
        'per_page'     => $perPage,
        'total'        => $totalItems,
        'offset'       => $offset,
        'has_prev'     => $page > 1,
        'has_next'     => $page < $pages,
        'prev_url'     => $page > 1 ? $buildUrl($page - 1) : null,
        'next_url'     => $page < $pages ? $buildUrl($page + 1) : null,
        'page_numbers' => range(1, $pages),
        'page_urls'    => $pageUrls,
        'current_url'  => $buildUrl($page),
    ];
}


    protected function wantsJson(): bool
    {
        if (isset($_GET['ajax']) && $_GET['ajax'] === '1') {
            return true;
        }

        return (
            isset($_SERVER['HTTP_X_REQUESTED_WITH'])
            && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest'
        );
    }

    protected function jsonResponse(array $data, int $status = 200): void
    {
        header('Content-Type: application/json', true, $status);
        echo json_encode($data);
        exit;
    }

    protected function jsonError(string $message, int $status = 400): void
    {
        $this->jsonResponse(['error' => $message], $status);
    }
}
