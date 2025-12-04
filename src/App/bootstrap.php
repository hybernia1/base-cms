<?php

use Bramus\Router\Router;
use RedBeanPHP\R as R;
use Twig\Loader\FilesystemLoader;
use Twig\Environment;
use App\Service\Setting;
use App\Service\MediaHelper;
use App\Service\ThemeManager;
use App\Service\Auth;
use Twig\TwigFunction;

session_start();

try {
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
            throw new \RuntimeException('Database connection error.');
        }

        // prod/dev režim
        R::freeze(($config['env'] ?? 'prod') === 'prod');

        $timezone = Setting::get('timezone', Setting::DEFAULTS['timezone']);
        if ($timezone) {
            date_default_timezone_set($timezone);
        }
    }

    $activeThemeKey = ($isInstalled && !empty($config['db'])) ? ThemeManager::activeThemeKey() : ThemeManager::DEFAULT_THEME;
    $themePath = ThemeManager::resolveActivePath($activeThemeKey);
    // Twig
    $loader = new FilesystemLoader([
        $themePath,
        __DIR__ . '/View',
    ]);
    $twig = new Environment($loader, [
        // 'cache' => __DIR__ . '/../../cache/twig',
    ]);

    $twig->addFunction(new TwigFunction('file_icon', fn (?string $mime) => MediaHelper::mimeToIcon($mime)));
    $twig->addFunction(new TwigFunction('human_size', fn ($bytes) => MediaHelper::humanSize($bytes)));
    $twig->addFunction(new TwigFunction('protected_content', function ($html, string $fallback = '') {
        if (Auth::user()) {
            return $html;
        }

        if ($fallback !== '') {
            return $fallback;
        }

        return '<a href="/login">Přihlaste se pro zobrazení obsahu</a>';
    }, ['is_safe' => ['html']]));

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
    $twig->addGlobal('active_theme', [
        'key' => $activeThemeKey,
        'path' => $themePath,
        'meta' => ThemeManager::readMetadata($themePath, basename($themePath)),
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
} catch (\Throwable $e) {
    error_log((string) $e);
    http_response_code(500);

    $config = $config ?? [];
    $isDebug = ($config['env'] ?? 'prod') !== 'prod';
    $publicMessage = 'Něco se pokazilo. Zkuste to prosím znovu.';
    $debugDetails = $isDebug ? $e->getMessage() : null;

    if (isset($twig)) {
        echo $twig->render('error.twig', [
            'message' => $publicMessage,
            'debug' => $debugDetails,
        ]);
        return;
    }

    echo '<h1>Chyba</h1>'; 
    echo '<p>' . htmlspecialchars($publicMessage, ENT_QUOTES, 'UTF-8') . '</p>';
    if ($debugDetails) {
        echo '<pre>' . htmlspecialchars($debugDetails, ENT_QUOTES, 'UTF-8') . '</pre>';
    }
}
