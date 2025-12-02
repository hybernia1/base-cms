<?php
namespace App\Controller\Admin;

use App\Service\Auth;
use App\Service\Flash;
use App\Service\AssetManager;
use RedBeanPHP\R as R;

class ExtraController extends BaseAdminController
{
    public function assets(): void
    {
        Auth::requireRole(['admin']);

        $assetSource = AssetManager::getSource();

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $requestedSource = $_POST['asset_source'] ?? $assetSource;
            $requestedSource = $requestedSource === AssetManager::SOURCE_LOCAL
                ? AssetManager::SOURCE_LOCAL
                : AssetManager::SOURCE_CDN;

            if ($requestedSource !== $assetSource) {
                AssetManager::setSource($requestedSource);
                $assetSource = $requestedSource;
                Flash::addSuccess('Způsob načítání knihoven byl uložen.');
            }

            if (isset($_POST['download_libraries'])) {
                $download = AssetManager::downloadLibraries();

                foreach ($download['messages'] as $message) {
                    Flash::addSuccess($message);
                }

                foreach ($download['errors'] as $error) {
                    Flash::addError($error);
                }

                if ($download['success']) {
                    AssetManager::setSource(AssetManager::SOURCE_LOCAL);
                    $assetSource = AssetManager::SOURCE_LOCAL;
                    Flash::addSuccess('Knihovny byly staženy a budou načítány ze serveru.');
                }
            }

            header('Location: /admin/extra/assets');
            exit;
        }

        $this->render('admin/extra/assets.twig', [
            'current_menu' => 'extra:assets',
            'asset_source' => $assetSource,
            'libraries' => AssetManager::getLibrariesStatus(),
        ]);
    }

    public function info(): void
    {
        Auth::requireRole(['admin']);

        $config = $GLOBALS['app']['config'] ?? [];
        $dsn = $config['db']['dsn'] ?? '';
        $dbName = $this->parseDsnValue($dsn, 'dbname');

        $this->render('admin/extra/info.twig', [
            'current_menu' => 'extra:info',
            'info' => [
                'php_version' => PHP_VERSION,
                'mysql_version' => $this->fetchMysqlVersion(),
                'server' => php_uname('a'),
                'db_name' => $dbName ?: 'N/A',
                'db_size' => $this->formatDatabaseSize($dbName),
            ],
        ]);
    }

    public function backup(): void
    {
        Auth::requireRole(['admin']);

        $config = $GLOBALS['app']['config'] ?? [];
        $dbConfig = $config['db'] ?? [];
        $dsn = $dbConfig['dsn'] ?? '';
        $dbName = $this->parseDsnValue($dsn, 'dbname');
        $host = $this->parseDsnValue($dsn, 'host') ?: 'localhost';
        $port = $this->parseDsnValue($dsn, 'port');
        $canDownload = $dbName && ($dbConfig !== []);

        if (isset($_GET['download'])) {
            if (!$canDownload) {
                Flash::addError('Záloha databáze není dostupná. Zkontroluj konfiguraci připojení.');
                header('Location: /admin/extra/backup');
                exit;
            }

            $filename = sprintf('%s-zaloha-%s.sql', $dbName, date('Ymd-His'));
            $commandParts = [
                'mysqldump',
                '--user=' . escapeshellarg($dbConfig['user'] ?? ''),
                '--password=' . escapeshellarg($dbConfig['pass'] ?? ''),
                '--host=' . escapeshellarg($host),
            ];

            if ($port) {
                $commandParts[] = '--port=' . escapeshellarg($port);
            }

            $commandParts[] = escapeshellarg($dbName);
            $command = implode(' ', $commandParts) . ' 2>&1';

            exec($command, $output, $exitCode);

            if ($exitCode !== 0 || empty($output)) {
                Flash::addError('Záloha databáze se nezdařila. Ověř přístupové údaje a dostupnost mysqldump.');
                header('Location: /admin/extra/backup');
                exit;
            }

            header('Content-Type: application/sql');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            echo implode(PHP_EOL, $output);
            exit;
        }

        $this->render('admin/extra/backup.twig', [
            'current_menu' => 'extra:backup',
            'database_name' => $dbName ?: 'N/A',
            'can_download' => $canDownload,
        ]);
    }

    public function debug(): void
    {
        Auth::requireRole(['admin']);

        $configPath = dirname(__DIR__, 2) . '/Config/config.php';
        $config = $GLOBALS['app']['config'] ?? [];
        $currentEnv = $config['env'] ?? 'prod';

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['toggle_debug'])) {
            $configData = $this->loadConfig($configPath);

            if ($configData === null) {
                Flash::addError('Konfiguraci se nepodařilo načíst.');
                header('Location: /admin/extra/debug');
                exit;
            }

            $newEnv = ($configData['env'] ?? 'prod') === 'prod' ? 'dev' : 'prod';
            $configData['env'] = $newEnv;

            if (!$this->saveConfig($configPath, $configData)) {
                Flash::addError('Nepodařilo se uložit konfiguraci.');
                header('Location: /admin/extra/debug');
                exit;
            }

            $GLOBALS['app']['config'] = $configData;

            $message = $newEnv === 'dev'
                ? 'Debug mód byl zapnut (env=dev).'
                : 'Debug mód byl vypnut (env=prod).';

            Flash::addSuccess($message);
            header('Location: /admin/extra/debug');
            exit;
        }

        $this->render('admin/extra/debug.twig', [
            'current_menu' => 'extra:debug',
            'current_env' => $currentEnv,
            'is_debug' => $currentEnv !== 'prod',
        ]);
    }

    private function fetchMysqlVersion(): string
    {
        try {
            return (string) R::getCell('SELECT VERSION()');
        } catch (\Throwable $e) {
            return 'Nedostupné';
        }
    }

    private function formatDatabaseSize(?string $dbName): string
    {
        if (!$dbName) {
            return 'Nedostupné';
        }

        try {
            $bytes = R::getCell(
                'SELECT SUM(data_length + index_length) FROM information_schema.tables WHERE table_schema = ?',
                [$dbName]
            );
        } catch (\Throwable $e) {
            return 'Nedostupné';
        }

        if ($bytes === null) {
            return 'Nedostupné';
        }

        return $this->formatBytes((int) $bytes);
    }

    private function parseDsnValue(string $dsn, string $key): ?string
    {
        $dsn = str_replace('mysql:', '', $dsn);
        $parts = explode(';', $dsn);

        foreach ($parts as $part) {
            [$k, $v] = array_pad(explode('=', $part, 2), 2, null);
            if ($k === $key) {
                return $v;
            }
        }

        return null;
    }

    private function loadConfig(string $configPath): ?array
    {
        if (!is_file($configPath)) {
            return null;
        }

        $configData = require $configPath;

        return is_array($configData) ? $configData : null;
    }

    private function saveConfig(string $configPath, array $configData): bool
    {
        $configContent = "<?php\nreturn " . var_export($configData, true) . ";\n";

        return file_put_contents($configPath, $configContent) !== false;
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes <= 0) {
            return '0 B';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $power = min((int) floor(log($bytes, 1024)), count($units) - 1);

        return round($bytes / (1024 ** $power), 2) . ' ' . $units[$power];
    }
}
