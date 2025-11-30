<?php

use Bramus\Router\Router;
use RedBeanPHP\R as R;
use Twig\Loader\FilesystemLoader;
use Twig\Environment;
use App\Service\Setting;

session_start();

$configFile = __DIR__ . '/Config/config.php';
$isInstalled = file_exists($configFile);

// když config existuje, načteme ho
$config = $isInstalled ? require $configFile : null;

// DB jen když je v configu definovaná
if ($isInstalled && !empty($config['db'])) {
    R::setup(
        $config['db']['dsn'],
        $config['db']['user'],
        $config['db']['pass']
    );

    if (!R::testConnection()) {
        // tady si můžeš udělat vlastní error stránku přes Twig
        die('Database connection error.');
    }

    // prod/dev režim
    R::freeze(($config['env'] ?? 'prod') === 'prod');

    $timezone = Setting::get('timezone', Setting::DEFAULTS['timezone']);
    if ($timezone) {
        date_default_timezone_set($timezone);
    }
}

// Twig
$loader = new FilesystemLoader(__DIR__ . '/View');
$twig = new Environment($loader, [
    // 'cache' => __DIR__ . '/../../cache/twig',
]);

$appSettings = [
    'timezone' => Setting::DEFAULTS['timezone'],
    'date_format' => Setting::DEFAULTS['date_format'],
    'time_format' => Setting::DEFAULTS['time_format'],
];

if ($isInstalled && !empty($config['db'])) {
    $appSettings['timezone'] = Setting::get('timezone', $appSettings['timezone']);
    $appSettings['date_format'] = Setting::get('date_format', $appSettings['date_format']);
    $appSettings['time_format'] = Setting::get('time_format', $appSettings['time_format']);
}

$twig->addGlobal('app_settings', $appSettings);

// router
$router = new Router();

// sdílené věci
$GLOBALS['app'] = [
    'twig'   => $twig,
    'config' => $config,
    'isInstalled' => $isInstalled && (($config['installed'] ?? false) === true),
];

// načtení rout
require __DIR__ . '/routes.php';

// spuštění routeru
$router->run();
