<?php
namespace App\Service;

use RuntimeException;

class AssetManager
{
    public const SOURCE_CDN = 'cdn';
    public const SOURCE_LOCAL = 'local';

    private const SETTING_KEY = 'asset_source';

    private const LIBRARIES = [
        'bootstrap' => [
            'label' => 'Bootstrap',
            'version' => '5.3.3',
            'files' => [
                [
                    'handle' => 'bootstrap_css',
                    'type' => 'css',
                    'cdn' => 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css',
                    'local' => 'assets/vendor/bootstrap/bootstrap.min.css',
                ],
                [
                    'handle' => 'bootstrap_js',
                    'type' => 'js',
                    'cdn' => 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js',
                    'local' => 'assets/vendor/bootstrap/bootstrap.bundle.min.js',
                ],
            ],
        ],
        'bootstrap-icons' => [
            'label' => 'Bootstrap Icons',
            'version' => '1.11.3',
            'files' => [
                [
                    'handle' => 'bootstrap_icons_css',
                    'type' => 'css',
                    'cdn' => 'https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css',
                    'local' => 'assets/vendor/bootstrap-icons/font/bootstrap-icons.min.css',
                ],
                [
                    'handle' => 'bootstrap_icons_woff2',
                    'type' => 'font',
                    'cdn' => 'https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/fonts/bootstrap-icons.woff2',
                    'local' => 'assets/vendor/bootstrap-icons/font/fonts/bootstrap-icons.woff2',
                ],
                [
                    'handle' => 'bootstrap_icons_woff',
                    'type' => 'font',
                    'cdn' => 'https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/fonts/bootstrap-icons.woff',
                    'local' => 'assets/vendor/bootstrap-icons/font/fonts/bootstrap-icons.woff',
                ],
            ],
        ],
        'tagify' => [
            'label' => 'Tagify',
            'version' => '4.21.4',
            'files' => [
                [
                    'handle' => 'tagify_css',
                    'type' => 'css',
                    'cdn' => 'https://cdn.jsdelivr.net/npm/@yaireo/tagify@4.21.4/dist/tagify.css',
                    'local' => 'assets/vendor/tagify/tagify.css',
                ],
                [
                    'handle' => 'tagify_js',
                    'type' => 'js',
                    'cdn' => 'https://cdn.jsdelivr.net/npm/@yaireo/tagify@4.21.4/dist/tagify.min.js',
                    'local' => 'assets/vendor/tagify/tagify.min.js',
                ],
            ],
        ],
        'jquery' => [
            'label' => 'jQuery',
            'version' => '3.7.1',
            'files' => [
                [
                    'handle' => 'jquery_js',
                    'type' => 'js',
                    'cdn' => 'https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js',
                    'local' => 'assets/vendor/jquery/jquery.min.js',
                ],
            ],
        ],
        'summernote' => [
            'label' => 'Summernote',
            'version' => '0.8.20',
            'files' => [
                [
                    'handle' => 'summernote_css',
                    'type' => 'css',
                    'cdn' => 'https://cdn.jsdelivr.net/npm/summernote@0.8.20/dist/summernote-lite.min.css',
                    'local' => 'assets/vendor/summernote/summernote-lite.min.css',
                ],
                [
                    'handle' => 'summernote_js',
                    'type' => 'js',
                    'cdn' => 'https://cdn.jsdelivr.net/npm/summernote@0.8.20/dist/summernote-lite.min.js',
                    'local' => 'assets/vendor/summernote/summernote-lite.min.js',
                ],
            ],
        ],
    ];

    public static function assetUrls(): array
    {
        $source = self::getSource();
        $urls = [];

        foreach (self::LIBRARIES as $library) {
            foreach ($library['files'] as $file) {
                if (!in_array($file['type'], ['css', 'js'], true)) {
                    continue;
                }

                $urls[$file['handle']] = self::resolveFileUrl($file, $source);
            }
        }

        return $urls;
    }

    public static function getLibrariesStatus(): array
    {
        $libraries = [];

        foreach (self::LIBRARIES as $key => $library) {
            $files = [];
            $allLocal = true;

            foreach ($library['files'] as $file) {
                $localPath = self::absolutePath($file['local']);
                $exists = is_file($localPath);

                $files[] = [
                    'handle' => $file['handle'],
                    'type' => $file['type'],
                    'cdn_url' => $file['cdn'],
                    'local_url' => '/' . ltrim($file['local'], '/'),
                    'local_path' => $localPath,
                    'exists' => $exists,
                    'size' => $exists ? filesize($localPath) : null,
                ];

                if (!$exists) {
                    $allLocal = false;
                }
            }

            $libraries[] = [
                'key' => $key,
                'label' => $library['label'],
                'version' => $library['version'],
                'files' => $files,
                'local_complete' => $allLocal,
            ];
        }

        return $libraries;
    }

    public static function setSource(string $source): void
    {
        $normalized = self::normalizeSource($source);
        Setting::set(self::SETTING_KEY, $normalized);
    }

    public static function getSource(): string
    {
        try {
            $value = Setting::get(self::SETTING_KEY, self::SOURCE_CDN);
        } catch (\Throwable $e) {
            return self::SOURCE_CDN;
        }

        return self::normalizeSource((string) $value ?: self::SOURCE_CDN);
    }

    public static function downloadLibraries(): array
    {
        $results = [];
        $errors = [];

        foreach (self::LIBRARIES as $libraryKey => $library) {
            foreach ($library['files'] as $file) {
                try {
                    self::downloadFile($file['cdn'], $file['local']);
                    $results[] = sprintf('%s: %s staženo.', $library['label'], $file['handle']);
                } catch (RuntimeException $e) {
                    $errors[] = $e->getMessage();
                }
            }
        }

        return [
            'success' => $errors === [],
            'messages' => $results,
            'errors' => $errors,
        ];
    }

    private static function resolveFileUrl(array $file, string $source): string
    {
        if ($source === self::SOURCE_LOCAL && is_file(self::absolutePath($file['local']))) {
            return '/' . ltrim($file['local'], '/');
        }

        return $file['cdn'];
    }

    private static function downloadFile(string $sourceUrl, string $relativePath): void
    {
        $contents = @file_get_contents($sourceUrl);
        if ($contents === false) {
            throw new RuntimeException(sprintf('Nepodařilo se stáhnout %s.', $sourceUrl));
        }

        $targetPath = self::absolutePath($relativePath);
        $directory = dirname($targetPath);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException(sprintf('Nelze vytvořit složku pro %s.', $relativePath));
        }

        if (file_put_contents($targetPath, $contents) === false) {
            throw new RuntimeException(sprintf('Nelze uložit soubor %s.', $relativePath));
        }
    }

    private static function absolutePath(string $relativePath): string
    {
        return dirname(__DIR__, 3) . '/' . ltrim($relativePath, '/');
    }

    private static function normalizeSource(string $source): string
    {
        return $source === self::SOURCE_LOCAL ? self::SOURCE_LOCAL : self::SOURCE_CDN;
    }
}
