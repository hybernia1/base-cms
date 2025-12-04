<?php

use Bramus\Router\Router;
use App\Service\Auth;
use App\Service\ContentType;

/** @var Router $router */
$router = $router ?? new Router();
$isInstalled = $GLOBALS['app']['isInstalled'] ?? false;

if (!$isInstalled) {
    $router->get('/', 'App\\Controller\\Install\\InstallController@form');
    $router->post('/', 'App\\Controller\\Install\\InstallController@handle');
    $router->get('/install', 'App\\Controller\\Install\\InstallController@form');
    $router->post('/install', 'App\\Controller\\Install\\InstallController@handle');
    return;
}

// FRONT
$router->get('/robots\\.txt', 'App\\Controller\\Front\\RobotsController@index');
$router->get('/sitemap\\.xml', 'App\\Controller\\Front\\SitemapController@index');
$router->get('/([\\w-]+)-content(?:-(\\d+))?\\.xml', 'App\\Controller\\Front\\SitemapController@content');
$router->get('/([\\w-]+)-term(?:-(\\d+))?\\.xml', 'App\\Controller\\Front\\SitemapController@term');
$router->get('/users-sitemap(?:-(\\d+))?\\.xml', 'App\\Controller\\Front\\SitemapController@users');
$router->get('/', 'App\\Controller\\Front\\HomeController@index');
$router->get('/login', 'App\\Controller\\Front\\AuthController@loginForm');
$router->post('/login', 'App\\Controller\\Front\\AuthController@login');
$router->get('/logout', 'App\\Controller\\Front\\AuthController@logout');
$router->get('/register', 'App\\Controller\\Front\\AuthController@registerForm');
$router->post('/register', 'App\\Controller\\Front\\AuthController@register');
$router->get('/password-reset', 'App\\Controller\\Front\\PasswordResetController@requestForm');
$router->post('/password-reset', 'App\\Controller\\Front\\PasswordResetController@sendLink');
$router->get('/password-reset/([^/]+)', 'App\\Controller\\Front\\PasswordResetController@resetForm');
$router->post('/password-reset/([^/]+)', 'App\\Controller\\Front\\PasswordResetController@reset');
$router->get('/profile', 'App\\Controller\\Front\\UserController@profile');
$router->get('/profile/edit', 'App\\Controller\\Front\\UserController@editForm');
$router->post('/profile/edit', 'App\\Controller\\Front\\UserController@update');
$router->get('/users/(\\d+)', 'App\\Controller\\Front\\UserController@show');
$router->get('/search', 'App\\Controller\\Front\\SearchController@index');
$router->get('/terms/([\\w-]+)/([^/]+)', 'App\\Controller\\Front\\TermController@show');

foreach (ContentType::definitions() as $definition) {
    $slug = $definition['slug'];
    $router->get('/' . $slug, function () use ($slug) {
        (new \App\Controller\Front\HomeController())->listByType($slug);
    });

    $router->get('/' . $slug . '/([^/]+)', function ($contentSlug) use ($slug) {
        (new \App\Controller\Front\ContentController())->show($slug, $contentSlug);
    });
}

// ADMIN login – veřejné
$router->get('/admin/login', 'App\\Controller\\Admin\\AuthController@loginForm');
$router->post('/admin/login', 'App\\Controller\\Admin\\AuthController@login');

// ochrana všech /admin* kromě /admin/login
$router->before('GET|POST', '/admin(?!/login).*', function () {
    \App\Service\Auth::requirePanelAccess();
});

// ADMIN routy
$router->get('/admin', 'App\\Controller\\Admin\\DashboardController@index');
$router->get('/admin/logout', 'App\\Controller\\Admin\\AuthController@logout');
$router->get('/admin/users', 'App\\Controller\\Admin\\UserController@index');
$router->get('/admin/users/create', 'App\\Controller\\Admin\\UserController@createForm');
$router->post('/admin/users/create', 'App\\Controller\\Admin\\UserController@create');
$router->get('/admin/users/(\\d+)/edit', 'App\\Controller\\Admin\\UserController@editForm');
$router->post('/admin/users/(\\d+)/edit', 'App\\Controller\\Admin\\UserController@update');
$router->post('/admin/users/(\\d+)/ban', 'App\\Controller\\Admin\\UserController@ban');
$router->post('/admin/users/(\\d+)/unban', 'App\\Controller\\Admin\\UserController@unban');
$router->post('/admin/users/(\\d+)/delete', 'App\\Controller\\Admin\\UserController@delete');
$router->get('/admin/content', function () {
    $types = ContentType::definitions();
    $first = reset($types);
    $target = $first['slug'] ?? 'content';
    header('Location: /admin/content/' . $target);
    exit;
});

$router->get('/admin/pages', function () {
    $slug = ContentType::defaultSlug('page');
    header('Location: /admin/content/' . $slug);
    exit;
});
$router->get('/admin/pages/create', function () {
    $slug = ContentType::defaultSlug('page');
    header('Location: /admin/content/' . $slug . '/create');
    exit;
});
$router->post('/admin/pages/create', function () {
    $slug = ContentType::defaultSlug('page');
    header('Location: /admin/content/' . $slug . '/create');
    exit;
});
$router->get('/admin/pages/(\\d+)/edit', function ($id) {
    $slug = ContentType::defaultSlug('page');
    header('Location: /admin/content/' . $slug . '/' . $id . '/edit');
    exit;
});
$router->post('/admin/pages/(\\d+)/edit', function ($id) {
    $slug = ContentType::defaultSlug('page');
    header('Location: /admin/content/' . $slug . '/' . $id . '/edit');
    exit;
});
$router->post('/admin/pages/(\\d+)/delete', function ($id) {
    $slug = ContentType::defaultSlug('page');
    header('Location: /admin/content/' . $slug . '/' . $id . '/delete');
    exit;
});

$router->get('/admin/content/([\\w-]+)', 'App\\Controller\\Admin\\ContentController@index');
$router->get('/admin/content/([\\w-]+)/create', 'App\\Controller\\Admin\\ContentController@createForm');
$router->post('/admin/content/([\\w-]+)/create', 'App\\Controller\\Admin\\ContentController@create');
$router->post('/admin/content/([\\w-]+)/terms', 'App\\Controller\\Admin\\ContentController@createTerm');
$router->get('/admin/content/([\\w-]+)/(\\d+)/edit', 'App\\Controller\\Admin\\ContentController@editForm');
$router->post('/admin/content/([\\w-]+)/(\\d+)/edit', 'App\\Controller\\Admin\\ContentController@update');
$router->post('/admin/content/([\\w-]+)/(\\d+)/restore', 'App\\Controller\\Admin\\ContentController@restore');
$router->post('/admin/content/([\\w-]+)/(\\d+)/delete', 'App\\Controller\\Admin\\ContentController@delete');
$router->post('/admin/content/([\\w-]+)/trash/empty', 'App\\Controller\\Admin\\ContentController@emptyTrash');
$router->get('/admin/terms', 'App\\Controller\\Admin\\TermController@index');
$router->get('/admin/terms/type/([\\w-]+)', 'App\\Controller\\Admin\\TermController@indexByType');
$router->get('/admin/terms/create', 'App\\Controller\\Admin\\TermController@createForm');
$router->post('/admin/terms/create', 'App\\Controller\\Admin\\TermController@create');
$router->get('/admin/terms/(\\d+)/edit', 'App\\Controller\\Admin\\TermController@editForm');
$router->post('/admin/terms/(\\d+)/edit', 'App\\Controller\\Admin\\TermController@update');
$router->post('/admin/terms/(\\d+)/delete', 'App\\Controller\\Admin\\TermController@delete');
$router->get('/admin/navigation', 'App\\Controller\\Admin\\NavigationController@index');
$router->post('/admin/navigation/create', 'App\\Controller\\Admin\\NavigationController@create');
$router->post('/admin/navigation/(\\d+)/edit', 'App\\Controller\\Admin\\NavigationController@update');
$router->post('/admin/navigation/(\\d+)/delete', 'App\\Controller\\Admin\\NavigationController@delete');
$router->get('/admin/media', 'App\\Controller\\Admin\\MediaController@index');
$router->get('/admin/media/gallery', 'App\\Controller\\Admin\\MediaController@gallery');
$router->get('/admin/media/files', 'App\\Controller\\Admin\\MediaController@files');
$router->post('/admin/media/upload', 'App\\Controller\\Admin\\MediaController@upload');
$router->post('/admin/media/(\\d+)/update', 'App\\Controller\\Admin\\MediaController@update');
$router->post('/admin/media/(\\d+)/delete', 'App\\Controller\\Admin\\MediaController@delete');
$router->get('/admin/settings', function () {
    header('Location: /admin/settings/main');
    exit;
});
$router->get('/admin/settings/([\\w-]+)', 'App\\Controller\\Admin\\SettingController@index');
$router->post('/admin/settings/([\\w-]+)', 'App\\Controller\\Admin\\SettingController@update');
$router->get('/admin/email-templates', 'App\\Controller\\Admin\\EmailTemplateController@index');
$router->get('/admin/email-templates/(.+)', 'App\\Controller\\Admin\\EmailTemplateController@editForm');
$router->post('/admin/email-templates/(.+)/toggle', 'App\\Controller\\Admin\\EmailTemplateController@toggle');
$router->post('/admin/email-templates/(.+)', 'App\\Controller\\Admin\\EmailTemplateController@update');
$router->get('/admin/comments', 'App\\Controller\\Admin\\CommentController@index');
$router->post('/admin/comments/(\\d+)/approve', 'App\\Controller\\Admin\\CommentController@approve');
$router->get('/admin/comments/(\\d+)/edit', 'App\\Controller\\Admin\\CommentController@editForm');
$router->post('/admin/comments/(\\d+)/edit', 'App\\Controller\\Admin\\CommentController@update');
$router->post('/admin/comments/(\\d+)/restore', 'App\\Controller\\Admin\\CommentController@restore');
$router->post('/admin/comments/(\\d+)/delete', 'App\\Controller\\Admin\\CommentController@delete');
$router->post('/admin/comments/trash/empty', 'App\\Controller\\Admin\\CommentController@emptyTrash');
$router->get('/admin/extra/info', 'App\\Controller\\Admin\\ExtraController@info');
$router->get('/admin/extra/debug', 'App\\Controller\\Admin\\ExtraController@debug');
$router->get('/admin/extra/backup', 'App\\Controller\\Admin\\ExtraController@backup');
$router->get('/admin/extra/optimize', 'App\\Controller\\Admin\\ExtraController@optimize');
$router->get('/admin/extra/integrations', 'App\\Controller\\Admin\\ExtraController@integrations');
$router->post('/admin/extra/debug', 'App\\Controller\\Admin\\ExtraController@debug');
$router->post('/admin/extra/backup', 'App\\Controller\\Admin\\ExtraController@backup');
$router->post('/admin/extra/optimize', 'App\\Controller\\Admin\\ExtraController@optimize');
$router->post('/admin/extra/integrations', 'App\\Controller\\Admin\\ExtraController@integrations');

$router->get('/admin/search', 'App\\Controller\\Admin\\SearchController@index');

$router->post('/comments', 'App\\Controller\\Front\\CommentController@store');

$router->set404('App\\Controller\\Front\\ErrorController@notFound');
