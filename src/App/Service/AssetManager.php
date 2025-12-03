<?php
namespace App\Service;

use RuntimeException;

class AssetManager
{
    public const SOURCE_CDN = 'cdn';
    public const SOURCE_LOCAL = 'local';

    private const SETTING_KEY = 'asset_source';
    private const VERSIONS_KEY = 'asset_library_versions';

    private const LIBRARIES = [
        'bootstrap' => [
            'label' => 'Bootstrap',
            'package' => 'bootstrap',
            'version' => '5.3.3',
            'files' => [
                [
                    'handle' => 'bootstrap_css',
                    'type' => 'css',
                    'path' => 'dist/css/bootstrap.min.css',
                    'local' => 'assets/vendor/bootstrap/bootstrap.min.css',
                ],
                [
                    'handle' => 'bootstrap_js',
                    'type' => 'js',
                    'path' => 'dist/js/bootstrap.bundle.min.js',
                    'local' => 'assets/vendor/bootstrap/bootstrap.bundle.min.js',
                ],
            ],
        ],
        'bootstrap-icons' => [
            'label' => 'Bootstrap Icons',
            'package' => 'bootstrap-icons',
            'version' => '1.11.3',
            'files' => [
                [
                    'handle' => 'bootstrap_icons_css',
                    'type' => 'css',
                    'path' => 'font/bootstrap-icons.min.css',
                    'local' => 'assets/vendor/bootstrap-icons/font/bootstrap-icons.min.css',
                ],
                [
                    'handle' => 'bootstrap_icons_woff2',
                    'type' => 'font',
                    'path' => 'font/fonts/bootstrap-icons.woff2',
                    'local' => 'assets/vendor/bootstrap-icons/font/fonts/bootstrap-icons.woff2',
                ],
                [
                    'handle' => 'bootstrap_icons_woff',
                    'type' => 'font',
                    'path' => 'font/fonts/bootstrap-icons.woff',
                    'local' => 'assets/vendor/bootstrap-icons/font/fonts/bootstrap-icons.woff',
                ],
            ],
        ],
        'tagify' => [
            'label' => 'Tagify',
            'package' => '@yaireo/tagify',
            'version' => '4.21.4',
            'files' => [
                [
                    'handle' => 'tagify_css',
                    'type' => 'css',
                    'path' => 'dist/tagify.css',
                    'local' => 'assets/vendor/tagify/tagify.css',
                ],
                [
                    'handle' => 'tagify_js',
                    'type' => 'js',
                    'path' => 'dist/tagify.min.js',
                    'local' => 'assets/vendor/tagify/tagify.min.js',
                ],
            ],
        ],
        'jquery' => [
            'label' => 'jQuery',
            'package' => 'jquery',
            'version' => '3.7.1',
            'files' => [
                [
                    'handle' => 'jquery_js',
                    'type' => 'js',
                    'path' => 'dist/jquery.min.js',
                    'local' => 'assets/vendor/jquery/jquery.min.js',
                ],
            ],
        ],
        'summernote' => [
            'label' => 'Summernote',
            'package' => 'summernote',
            'version' => '0.8.20',
            'files' => [
                [
                    'handle' => 'summernote_css',
                    'type' => 'css',
                    'path' => 'dist/summernote-lite.min.css',
                    'local' => 'assets/vendor/summernote/summernote-lite.min.css',
                ],
                [
                    'handle' => 'summernote_js',
                    'type' => 'js',
                    'path' => 'dist/summernote-lite.min.js',
                    'local' => 'assets/vendor/summernote/summernote-lite.min.js',
                ],
            ],
        ],
    ];

    private static array $resolvedVersions = [];

    public static function assetUrls(): array
    {
        $source = self::getSource();
        $urls = [];

        foreach (self::LIBRARIES as $library) {
            foreach ($library['files'] as $file) {
                if (!in_array($file['type'], ['css', 'js'], true)) {
                    continue;
                }

                $urls[$file['handle']] = self::resolveFileUrl($library, $file, $source);
            }
        }

        return $urls;
    }

    public static function getLibrariesStatus(): array
    {
        $storedVersions = self::getStoredLibraryVersions();
        $libraries = [];

        foreach (self::LIBRARIES as $key => $library) {
            $resolvedVersion = self::resolveLibraryVersion($library);
            $downloadedVersion = $storedVersions[$key] ?? null;
            $apiVersion = self::fetchPackageVersion($library['package'], null);
            $files = [];
            $allLocal = true;

            foreach ($library['files'] as $file) {
                $cdnUrl = self::buildCdnUrl($library, $file, $resolvedVersion);
                $localPath = self::absolutePath($file['local']);
                $exists = is_file($localPath);

                $files[] = [
                    'handle' => $file['handle'],
                    'type' => $file['type'],
                    'cdn_url' => $cdnUrl,
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
                'version' => $resolvedVersion,
                'downloaded_version' => $downloadedVersion,
                'api_version' => $apiVersion,
                'version_mismatch' => $downloadedVersion && $apiVersion && $downloadedVersion !== $apiVersion,
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
        $downloadedVersions = self::getStoredLibraryVersions();

        foreach (self::LIBRARIES as $libraryKey => $library) {
            $resolvedVersion = self::resolveLibraryVersion($library);
            $latestVersion = self::fetchPackageVersion($library['package'], null);
            $usedVersion = $resolvedVersion;
            $libraryDownloaded = true;

            foreach ($library['files'] as $file) {
                $cdnUrl = self::buildCdnUrl($library, $file, $resolvedVersion);

                try {
                    self::downloadFile($cdnUrl, $file['local']);
                    $results[] = sprintf('%s (%s): %s staženo.', $library['label'], $resolvedVersion, $file['handle']);
                } catch (RuntimeException $e) {
                    if ($latestVersion && $latestVersion !== $resolvedVersion) {
                        $fallbackUrl = self::buildCdnUrl($library, $file, $latestVersion);

                        try {
                            self::downloadFile($fallbackUrl, $file['local']);
                            $results[] = sprintf('%s (%s): %s staženo přes fallback.', $library['label'], $latestVersion, $file['handle']);
                            $usedVersion = $latestVersion;
                            continue;
                        } catch (RuntimeException $fallbackException) {
                            $errors[] = $e->getMessage();
                            $errors[] = $fallbackException->getMessage();
                            $libraryDownloaded = false;
                            continue;
                        }
                    }

                    $errors[] = $e->getMessage();
                    $libraryDownloaded = false;
                }
            }

            if ($libraryDownloaded) {
                $downloadedVersions[$libraryKey] = $usedVersion;
            }
        }

        self::storeLibraryVersions($downloadedVersions);

        return [
            'success' => $errors === [],
            'messages' => $results,
            'errors' => $errors,
        ];
    }

    private static function resolveFileUrl(array $library, array $file, string $source): string
    {
        if ($source === self::SOURCE_LOCAL && is_file(self::absolutePath($file['local']))) {
            return '/' . ltrim($file['local'], '/');
        }

        $version = self::resolveLibraryVersion($library);

        return self::buildCdnUrl($library, $file, $version);
    }

    private static function buildCdnUrl(array $library, array $file, ?string $version = null): string
    {
        $package = $library['package'] ?? null;
        $path = $file['path'] ?? null;

        if (!$package || !$path) {
            throw new RuntimeException('Chybí konfigurace balíčku nebo cesty pro CDN odkaz.');
        }

        $resolvedVersion = $version ?: self::resolveLibraryVersion($library);

        return sprintf(
            'https://cdn.jsdelivr.net/npm/%s@%s/%s',
            $package,
            $resolvedVersion,
            ltrim($path, '/')
        );
    }

    private static function resolveLibraryVersion(array $library): string
    {
        $package = $library['package'] ?? '';
        $preferred = $library['version'] ?? '';
        $cacheKey = $package . '@' . $preferred;

        if (isset(self::$resolvedVersions[$cacheKey])) {
            return self::$resolvedVersions[$cacheKey];
        }

        $version = self::fetchPackageVersion($package, $preferred)
            ?? self::fetchPackageVersion($package, null)
            ?? $preferred
            ?? 'latest';

        self::$resolvedVersions[$cacheKey] = $version;

        return $version;
    }

    private static function fetchPackageVersion(string $package, ?string $version): ?string
    {
        if ($package === '') {
            return null;
        }

        $packageSlug = $version ? sprintf('%s@%s', $package, $version) : $package;
        $url = sprintf('https://data.jsdelivr.com/v1/package/npm/%s', rawurlencode($packageSlug));
        $json = @file_get_contents($url);

        if ($json === false) {
            return null;
        }

        $data = json_decode($json, true);

        if (!is_array($data)) {
            return null;
        }

        if (isset($data['version']) && is_string($data['version'])) {
            return $data['version'];
        }

        if (isset($data['tags']['latest']) && is_string($data['tags']['latest'])) {
            return $data['tags']['latest'];
        }

        return null;
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

    private static function getStoredLibraryVersions(): array
    {
        $raw = Setting::get(self::VERSIONS_KEY, '{}');

        if (is_array($raw)) {
            return $raw;
        }

        $decoded = json_decode((string) $raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    private static function storeLibraryVersions(array $versions): void
    {
        Setting::set(self::VERSIONS_KEY, json_encode($versions));
    }
}
