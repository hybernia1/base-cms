<?php
namespace App\Service;

use Verot\Upload\Upload as UploadHandler;

class Avatar
{
    private const ALLOWED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif'];

    public static function upload(array $file): array
    {
        if (!isset($file['tmp_name']) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return [null, null];
        }

        if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            return [null, 'Soubor se nepodařilo nahrát.'];
        }

        $extension = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
        if (!in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            return [null, 'Nepodporovaný formát obrázku.'];
        }

        $handler = new UploadHandler($file);
        if (!$handler->uploaded) {
            return [null, 'Upload není platný.'];
        }

        $targetDir = rtrim(Upload::baseUploadPath(), '/') . '/avatar/' . date('Y/m');
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0775, true);
        }

        $handler->allowed = Upload::mimeTypesFor(self::ALLOWED_EXTENSIONS);
        $handler->file_auto_rename = true;
        $handler->file_safe_name = true;
        $handler->file_new_name_body = 'avatar_' . substr(hash('sha256', microtime(true) . ($file['name'] ?? '')), 0, 12);

        $handler->process($targetDir);
        if (!$handler->processed) {
            return [null, $handler->error ?? 'Zpracování selhalo.'];
        }

        $relativeDir = Upload::relativePath($targetDir);
        $storedFilename = $handler->file_dst_name;
        $handler->clean();

        $newPath = trim($relativeDir, '/') . '/' . $storedFilename;

        return [$newPath, null];
    }

    public static function delete(?string $relativePath): void
    {
        if (!$relativePath) {
            return;
        }

        $absolute = rtrim(Upload::baseUploadPath(), '/') . '/' . ltrim(str_replace('uploads/', '', $relativePath), '/');
        if (is_file($absolute)) {
            @unlink($absolute);
        }
    }

    public static function url(?string $relativePath): ?string
    {
        if (!$relativePath) {
            return null;
        }

        return '/' . ltrim($relativePath, '/');
    }

    public static function forUser($user, ?string $fallbackName = null): array
    {
        $displayName = $fallbackName ?: ($user->nickname ?? ($user->email ?? 'Uživatel'));

        return [
            'url' => self::url($user->avatar_path ?? null),
            'initials' => self::initials($displayName, $user->email ?? ''),
        ];
    }

    public static function forName(?string $name): array
    {
        $displayName = $name ?: 'Uživatel';

        return [
            'url' => null,
            'initials' => self::initials($displayName),
        ];
    }

    public static function initials(string $primary, string $secondary = ''): string
    {
        $source = trim($primary !== '' ? $primary : $secondary);
        if ($source === '') {
            $source = 'Uživatel';
        }

        $letters = mb_strtoupper(mb_substr($source, 0, 2, 'UTF-8'), 'UTF-8');

        return $letters !== '' ? $letters : 'NA';
    }
}
