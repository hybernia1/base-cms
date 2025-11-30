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
$router->get('/', 'App\\Controller\\Front\\HomeController@index');
$router->get('/register', 'App\\Controller\\Front\\AuthController@registerForm');
$router->post('/register', 'App\\Controller\\Front\\AuthController@register');

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
$router->post('/admin/content/([\\w-]+)/(\\d+)/delete', 'App\\Controller\\Admin\\ContentController@delete');
$router->get('/admin/terms', 'App\\Controller\\Admin\\TermController@index');
$router->get('/admin/terms/type/([\\w-]+)', 'App\\Controller\\Admin\\TermController@indexByType');
$router->get('/admin/terms/create', 'App\\Controller\\Admin\\TermController@createForm');
$router->post('/admin/terms/create', 'App\\Controller\\Admin\\TermController@create');
$router->get('/admin/terms/(\\d+)/edit', 'App\\Controller\\Admin\\TermController@editForm');
$router->post('/admin/terms/(\\d+)/edit', 'App\\Controller\\Admin\\TermController@update');
$router->post('/admin/terms/(\\d+)/delete', 'App\\Controller\\Admin\\TermController@delete');
$router->get('/admin/media', 'App\\Controller\\Admin\\MediaController@index');
$router->post('/admin/media/upload', 'App\\Controller\\Admin\\MediaController@upload');
$router->post('/admin/media/(\\d+)/delete', 'App\\Controller\\Admin\\MediaController@delete');
$router->get('/admin/settings', 'App\\Controller\\Admin\\SettingController@index');
$router->post('/admin/settings', 'App\\Controller\\Admin\\SettingController@update');
$router->get('/admin/email-templates', 'App\\Controller\\Admin\\EmailTemplateController@index');
$router->get('/admin/email-templates/(.+)', 'App\\Controller\\Admin\\EmailTemplateController@editForm');
$router->post('/admin/email-templates/(.+)', 'App\\Controller\\Admin\\EmailTemplateController@update');
