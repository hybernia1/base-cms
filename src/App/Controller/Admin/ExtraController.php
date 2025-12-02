<?php
namespace App\Controller\Admin;

use App\Service\Auth;
use App\Service\Flash;
use RedBeanPHP\R as R;

class ExtraController extends BaseAdminController
{
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
        $currentEnv = $config['env'] ?? 'prod';
        $dsn = $dbConfig['dsn'] ?? '';
        $dbName = $this->parseDsnValue($dsn, 'dbname');
        $host = $this->parseDsnValue($dsn, 'host') ?: 'localhost';
        $port = $this->parseDsnValue($dsn, 'port');
        $canDownload = $dbName && ($dbConfig !== []);
        $configPath = dirname(__DIR__, 2) . '/Config/config.php';

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['toggle_debug'])) {
            if (!is_file($configPath)) {
                Flash::addError('Konfigurační soubor nebyl nalezen.');
                header('Location: /admin/extra/backup');
                exit;
            }

            $configData = require $configPath;

            if (!is_array($configData)) {
                Flash::addError('Konfiguraci se nepodařilo načíst.');
                header('Location: /admin/extra/backup');
                exit;
            }

            $newEnv = ($configData['env'] ?? 'prod') === 'prod' ? 'dev' : 'prod';
            $configData['env'] = $newEnv;

            $configContent = "<?php\nreturn " . var_export($configData, true) . ";\n";

            if (file_put_contents($configPath, $configContent) === false) {
                Flash::addError('Nepodařilo se uložit konfiguraci.');
                header('Location: /admin/extra/backup');
                exit;
            }

            $GLOBALS['app']['config'] = $configData;

            $message = $newEnv === 'dev'
                ? 'Debug mód byl zapnut (env=dev).'
                : 'Debug mód byl vypnut (env=prod).';

            Flash::addSuccess($message);
            header('Location: /admin/extra/backup');
            exit;
        }

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
