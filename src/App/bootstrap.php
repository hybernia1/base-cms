<?php

use Bramus\Router\Router;
use RedBeanPHP\R as R;
use Twig\Loader\FilesystemLoader;
use Twig\Environment;

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
}

// Twig
$loader = new FilesystemLoader(__DIR__ . '/View');
$twig = new Environment($loader, [
    // 'cache' => __DIR__ . '/../../cache/twig',
]);

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
