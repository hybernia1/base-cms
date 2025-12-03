<?php
namespace App\Controller\Admin;

use App\Service\Auth;
use App\Service\Flash;
use App\Service\Comment;
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
            $backupContent = $this->exportDatabase($dbConfig, $host, $port, $dbName);

            if ($backupContent === null) {
                Flash::addError('Záloha databáze se nezdařila. Ověř přístupové údaje a dostupnost databáze.');
                header('Location: /admin/extra/backup');
                exit;
            }

            header('Content-Type: application/sql');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            echo $backupContent;
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

    public function optimize(): void
    {
        Auth::requireRole(['admin']);

        $config = $GLOBALS['app']['config'] ?? [];
        $dsn = $config['db']['dsn'] ?? '';
        $dbName = $this->parseDsnValue($dsn, 'dbname');

        $beforeSizeBytes = $this->fetchDatabaseSizeBytes($dbName);

        $stats = [
            'login_logs' => (int) R::count('loginlog'),
            'reset_tokens' => (int) R::count('passwordreset', ' used_at IS NOT NULL OR expires_at < NOW() '),
            'content_trash' => (int) R::count('content', ' deleted_at IS NOT NULL '),
            'comment_trash' => (int) R::count('comment', ' deleted_at IS NOT NULL '),
            'db_size' => $this->formatSize($beforeSizeBytes),
            'db_size_bytes' => $beforeSizeBytes,
        ];

        $selectedActions = [
            'clean_login_logs' => true,
            'clean_reset_tokens' => true,
            'clean_content_trash' => true,
            'clean_comment_trash' => true,
        ];

        $results = null;

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $selectedActions = [
                'clean_login_logs' => isset($_POST['clean_login_logs']),
                'clean_reset_tokens' => isset($_POST['clean_reset_tokens']),
                'clean_content_trash' => isset($_POST['clean_content_trash']),
                'clean_comment_trash' => isset($_POST['clean_comment_trash']),
            ];

            if (!array_filter($selectedActions)) {
                Flash::addError('Vyber prosím alespoň jednu oblast optimalizace.');
                header('Location: /admin/extra/optimize');
                exit;
            }

            $deleted = [];

            if ($selectedActions['clean_login_logs']) {
                $deleted['login_logs'] = $stats['login_logs'];
                R::exec('DELETE FROM loginlog');
            }

            if ($selectedActions['clean_reset_tokens']) {
                $deleted['reset_tokens'] = $stats['reset_tokens'];
                R::exec('DELETE FROM passwordreset WHERE used_at IS NOT NULL OR expires_at < NOW()');
            }

            if ($selectedActions['clean_content_trash']) {
                $deleted['content_trash'] = $this->emptyAllContentTrash();
            }

            if ($selectedActions['clean_comment_trash']) {
                $deleted['comment_trash'] = $stats['comment_trash'];
                Comment::emptyTrash();
            }

            $afterCounts = [
                'login_logs' => (int) R::count('loginlog'),
                'reset_tokens' => (int) R::count('passwordreset', ' used_at IS NOT NULL OR expires_at < NOW() '),
                'content_trash' => (int) R::count('content', ' deleted_at IS NOT NULL '),
                'comment_trash' => (int) R::count('comment', ' deleted_at IS NOT NULL '),
            ];

            $afterSizeBytes = $this->fetchDatabaseSizeBytes($dbName);

            $results = [
                'deleted' => $deleted,
                'after_counts' => $afterCounts,
                'before_size' => $this->formatSize($beforeSizeBytes),
                'after_size' => $this->formatSize($afterSizeBytes),
                'freed_size' => $this->formatFreedSize($beforeSizeBytes, $afterSizeBytes),
                'before_size_bytes' => $beforeSizeBytes,
                'after_size_bytes' => $afterSizeBytes,
                'timestamp' => date('Y-m-d H:i:s'),
                'actions_executed' => array_keys(array_filter($selectedActions)),
            ];

            Flash::addSuccess('Optimalizace databáze byla dokončena.');
        }

        $this->render('admin/extra/optimize.twig', [
            'current_menu' => 'extra:optimize',
            'db_name' => $dbName ?: 'N/A',
            'stats' => $stats,
            'results' => $results,
            'selected_actions' => $selectedActions,
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
        $bytes = $this->fetchDatabaseSizeBytes($dbName);

        return $this->formatSize($bytes);
    }

    private function exportDatabase(array $dbConfig, string $host, ?string $port, string $dbName): ?string
    {
        $mysqldumpBackup = $this->runMysqldump($dbConfig, $host, $port, $dbName);

        if ($mysqldumpBackup !== null) {
            return $mysqldumpBackup;
        }

        return $this->exportViaPdo($dbName);
    }

    private function runMysqldump(array $dbConfig, string $host, ?string $port, string $dbName): ?string
    {
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

        if ($exitCode === 0 && !empty($output)) {
            return implode(PHP_EOL, $output);
        }

        return null;
    }

    private function exportViaPdo(string $dbName): ?string
    {
        try {
            $pdo = R::getDatabaseAdapter()->getDatabase()->getPDO();
            $tables = R::getCol('SHOW TABLES');
        } catch (\Throwable $e) {
            return null;
        }

        if ($tables === []) {
            return '-- Databáze neobsahuje žádné tabulky.';
        }

        $lines = [
            sprintf('-- Záloha databáze `%s` vytvořená %s', $dbName, date('Y-m-d H:i:s')),
            'SET FOREIGN_KEY_CHECKS=0;',
        ];

        foreach ($tables as $table) {
            try {
                $safeTable = str_replace('`', '``', $table);
                $createResult = R::getRow('SHOW CREATE TABLE `' . $safeTable . '`');
                $createSql = $createResult['Create Table'] ?? array_values($createResult)[1] ?? null;
                $rows = R::getAll('SELECT * FROM `' . $safeTable . '`');
            } catch (\Throwable $e) {
                return null;
            }

            if ($createSql === null) {
                return null;
            }

            $lines[] = 'DROP TABLE IF EXISTS `' . $safeTable . '`;';
            $lines[] = $createSql . ';';

            foreach ($rows as $row) {
                $columns = array_map(fn ($column) => '`' . $column . '`', array_keys($row));
                $values = array_map(function ($value) use ($pdo) {
                    if ($value === null) {
                        return 'NULL';
                    }

                    return $pdo->quote($value);
                }, array_values($row));

                $lines[] = sprintf(
                    'INSERT INTO `%s` (%s) VALUES (%s);',
                    $safeTable,
                    implode(', ', $columns),
                    implode(', ', $values)
                );
            }
        }

        $lines[] = 'SET FOREIGN_KEY_CHECKS=1;';

        return implode(PHP_EOL, $lines) . PHP_EOL;
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

    private function formatSize(?int $bytes): string
    {
        if ($bytes === null) {
            return 'Nedostupné';
        }

        return $this->formatBytes($bytes);
    }

    private function fetchDatabaseSizeBytes(?string $dbName): ?int
    {
        if (!$dbName) {
            return null;
        }

        try {
            $bytes = R::getCell(
                'SELECT SUM(data_length + index_length) FROM information_schema.tables WHERE table_schema = ?',
                [$dbName]
            );
        } catch (\Throwable $e) {
            return null;
        }

        return $bytes !== null ? (int) $bytes : null;
    }

    private function formatFreedSize(?int $beforeBytes, ?int $afterBytes): string
    {
        if ($beforeBytes === null || $afterBytes === null) {
            return 'Nedostupné';
        }

        $freed = max(0, $beforeBytes - $afterBytes);

        return $this->formatBytes($freed);
    }

    private function emptyAllContentTrash(): int
    {
        $trashed = R::findAll('content', ' deleted_at IS NOT NULL ');
        $count = count($trashed);

        foreach ($trashed as $item) {
            R::exec('DELETE FROM content_term WHERE content_id = ?', [(int) $item->id]);
            R::exec('DELETE FROM content_media WHERE content_id = ?', [(int) $item->id]);
            R::trash($item);
        }

        return $count;
    }
}
