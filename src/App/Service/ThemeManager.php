<?php
namespace App\Service;

use RuntimeException;
use ZipArchive;

class ThemeManager
{
    public const DEFAULT_THEME = 'blue';

    public static function themesDirectory(): string
    {
        $dir = dirname(__DIR__, 3) . '/themes';

        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        return $dir;
    }

    public static function availableThemes(): array
    {
        $themes = [];
        $baseDir = self::themesDirectory();

        foreach (glob($baseDir . '/*', GLOB_ONLYDIR) as $dir) {
            $key = basename($dir);
            $meta = self::readMetadata($dir, $key);

            $themes[$key] = array_merge([
                'key' => $key,
                'name' => ucfirst($key),
                'description' => '',
                'version' => '1.0.0',
                'author' => '',
            ], $meta, [
                'path' => $dir,
            ]);
        }

        ksort($themes);

        return $themes;
    }

    public static function activeThemeKey(): string
    {
        return Setting::get('theme', Setting::DEFAULTS['theme'] ?? self::DEFAULT_THEME);
    }

    public static function resolveActivePath(?string $theme = null): string
    {
        $theme = $theme ?: self::activeThemeKey();
        $baseDir = self::themesDirectory();
        $path = realpath($baseDir . '/' . $theme);

        if ($path && is_dir($path)) {
            return $path;
        }

        $fallback = realpath($baseDir . '/' . self::DEFAULT_THEME);

        return $fallback && is_dir($fallback) ? $fallback : $baseDir;
    }

    public static function themeExists(string $theme): bool
    {
        $path = self::themesDirectory() . '/' . $theme;

        return is_dir($path);
    }

    public static function installFromUpload(array $file): array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return ['success' => false, 'error' => 'Nepodařilo se nahrát soubor se šablonou.'];
        }

        $extension = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
        if ($extension !== 'zip') {
            return ['success' => false, 'error' => 'Podporován je pouze ZIP archiv.'];
        }

        $tmpDir = sys_get_temp_dir() . '/theme_upload_' . uniqid();
        mkdir($tmpDir, 0775, true);

        $zip = new ZipArchive();
        if ($zip->open($file['tmp_name']) !== true) {
            return ['success' => false, 'error' => 'Archiv se šablonou nelze otevřít.'];
        }

        if (!$zip->extractTo($tmpDir)) {
            $zip->close();

            return ['success' => false, 'error' => 'Archiv se šablonou nelze rozbalit.'];
        }

        $zip->close();

        try {
            $root = self::detectThemeRoot($tmpDir);
            $folderName = basename($root);
            $configPath = $root . '/' . $folderName . '.json';

            if (!file_exists($configPath)) {
                throw new RuntimeException('V kořenové složce šablony chybí soubor ' . $folderName . '.json.');
            }

            $meta = self::readMetadata($root, $folderName);
            $themeKey = Slugger::slugify($meta['key'] ?? $meta['name'] ?? $folderName);
            $themeKey = $themeKey ?: $folderName;

            if ($themeKey === '') {
                throw new RuntimeException('Název šablony nelze určit.');
            }

            $targetDir = self::themesDirectory() . '/' . $themeKey;
            self::removeDirectory($targetDir);

            if (!rename($root, $targetDir)) {
                throw new RuntimeException('Šablonu se nepodařilo přesunout do themes/.');
            }

            self::cleanupTemp($tmpDir);

            return ['success' => true, 'theme' => $themeKey];
        } catch (\Throwable $e) {
            self::cleanupTemp($tmpDir);

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    public static function readMetadata(string $dir, ?string $fallbackKey = null): array
    {
        $metaFile = null;

        if (file_exists($dir . '/theme.json')) {
            $metaFile = $dir . '/theme.json';
        }

        $fallbackCandidate = $fallbackKey ? $dir . '/' . $fallbackKey . '.json' : null;
        if (!$metaFile && $fallbackCandidate && file_exists($fallbackCandidate)) {
            $metaFile = $fallbackCandidate;
        }

        if (!$metaFile) {
            return [];
        }

        $content = file_get_contents($metaFile);
        $data = json_decode($content, true);

        return is_array($data) ? $data : [];
    }

    private static function detectThemeRoot(string $tmpDir): string
    {
        $subdirs = glob($tmpDir . '/*', GLOB_ONLYDIR);

        if (count($subdirs) === 1) {
            return $subdirs[0];
        }

        if (count($subdirs) === 0) {
            throw new RuntimeException('Archiv musí obsahovat složku se šablonou.');
        }

        throw new RuntimeException('Archiv musí obsahovat právě jednu složku se šablonou.');
    }

    private static function cleanupTemp(string $dir): void
    {
        self::removeDirectory($dir);
    }

    private static function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($files as $file) {
            if ($file->isDir()) {
                rmdir($file->getRealPath());
            } else {
                unlink($file->getRealPath());
            }
        }

        rmdir($dir);
    }
}
