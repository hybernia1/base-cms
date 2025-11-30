<?php

use Bramus\Router\Router;
use App\Service\Auth;

/** @var Router $router */
$router = $router ?? new Router();
$isInstalled = $GLOBALS['app']['isInstalled'] ?? false;

if (!$isInstalled) {
    $router->get('/', 'App\Controller\Install\InstallController@form');
    $router->post('/', 'App\Controller\Install\InstallController@handle');
    $router->get('/install', 'App\Controller\Install\InstallController@form');
    $router->post('/install', 'App\Controller\Install\InstallController@handle');
    return;
}

// FRONT
$router->get('/', 'App\Controller\Front\HomeController@index');

// ADMIN login – veřejné
$router->get('/admin/login', 'App\Controller\Admin\AuthController@loginForm');
$router->post('/admin/login', 'App\Controller\Admin\AuthController@login');

// ochrana všech /admin* kromě /admin/login
$router->before('GET|POST', '/admin(?!/login).*', function () {
    \App\Service\Auth::requireLogin();
});

// ADMIN routy
$router->get('/admin', 'App\Controller\Admin\DashboardController@index');
$router->get('/admin/logout', 'App\Controller\Admin\AuthController@logout');
