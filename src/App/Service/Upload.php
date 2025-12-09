<?php
namespace App\Service;

use RedBeanPHP\R as R;
use Verot\Upload\Upload as UploadHandler;

class Upload
{
    private const MIME_MAP = [
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
        'gif' => ['image/gif'],
        'webp' => ['image/webp'],
        'avif' => ['image/avif'],
        'pdf' => ['application/pdf'],
        'zip' => ['application/zip', 'application/x-zip-compressed'],
        'txt' => ['text/plain'],
        'svg' => ['image/svg+xml'],
        'mp4' => ['video/mp4'],
        'mp3' => ['audio/mpeg'],
    ];

    public static function handle(array $file, string $targetType = 'auto', ?int $uploadedBy = null)
    {
        if (!isset($file['tmp_name']) || $file['error'] !== UPLOAD_ERR_OK) {
            return [null, 'Soubor se nepodařilo nahrát.'];
        }

        $allowed = array_filter(array_map('trim', explode(',', Setting::get('allowed_upload_types'))));
        $allowWebp = Setting::get('allow_webp') === '1';
        $maxImageWidth = (int) Setting::get('upload_image_max_width', Setting::DEFAULTS['upload_image_max_width']);
        $maxImageSizeKb = (int) Setting::get('upload_image_max_kb', Setting::DEFAULTS['upload_image_max_kb']);

        $normalizedAllowed = array_map('strtolower', $allowed);
        $extension = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));

        if (!empty($normalizedAllowed) && !in_array($extension, $normalizedAllowed, true)) {
            return [null, 'Nepovolený typ souboru.'];
        }

        $handler = new UploadHandler($file);
        if (!$handler->uploaded) {
            return [null, 'Upload není platný.'];
        }

        if (!empty($normalizedAllowed)) {
            $handler->allowed = self::mimeTypesFor($normalizedAllowed);
        }

        if ($handler->file_is_image && $maxImageWidth > 0) {
            $handler->image_resize = true;
            $handler->image_ratio_y = true;
            $handler->image_x = $maxImageWidth;
            $handler->image_ratio_no_zoom_in = true;
        }

        $year = date('Y');
        $month = date('m');
        $targetFolder = $targetType === 'auto'
            ? ($handler->file_is_image ? 'images' : 'files')
            : trim($targetType, '/');

        $targetDir = self::baseUploadPath() . "/{$targetFolder}/{$year}/{$month}/";
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0775, true);
        }

        $handler->file_auto_rename = true;
        $handler->process($targetDir);

        if (!$handler->processed) {
            return [null, $handler->error ?? 'Zpracování selhalo.'];
        }

        $filename = $handler->file_dst_name;
        $webpVariant = null;
        $absolutePath = rtrim($targetDir, '/') . '/' . $filename;

        if ($handler->file_is_image && $maxImageSizeKb > 0) {
            self::shrinkImageToMaxSize(
                $absolutePath,
                $handler->file_dst_mime ?: $handler->file_src_mime,
                $maxImageSizeKb * 1024
            );
        }

        if ($allowWebp && $handler->file_is_image) {
            $webpVariant = self::createWebpVariant($absolutePath, $targetDir, $maxImageSizeKb);
        }

        $handler->clean();

        $media = R::dispense('media');
        $media->path = self::relativePath($targetDir);
        $media->filename = $filename;
        $media->webp_filename = $webpVariant;
        $media->mime_type = $handler->file_src_mime;
        clearstatcache(true, $absolutePath);
        $media->size = is_file($absolutePath) ? filesize($absolutePath) : $handler->file_src_size;
        $media->is_image = $handler->file_is_image ? 1 : 0;
        $media->original_name = $file['name'] ?? '';
        $media->uploaded_by = $uploadedBy;
        $media->created_at = date('Y-m-d H:i:s');
        R::store($media);

        return [$media, null];
    }

    public static function mimeTypesFor(array $extensions): array
    {
        $allowed = [];

        foreach ($extensions as $extension) {
            $extension = strtolower($extension);
            if (isset(self::MIME_MAP[$extension])) {
                $allowed = array_merge($allowed, self::MIME_MAP[$extension]);
                continue;
            }

            $allowed[] = '*/*';
        }

        return $allowed ?: ['*/*'];
    }

    public static function baseUploadPath(): string
    {
        return dirname(__DIR__, 3) . '/uploads';
    }

    public static function relativePath(string $absolute): string
    {
        $absolute = rtrim($absolute, '/');
        $base = rtrim(self::baseUploadPath(), '/');
        return 'uploads' . substr($absolute, strlen($base));
    }

    private static function createWebpVariant(string $sourcePath, string $targetDir, int $maxImageSizeKb): ?string
    {
        if (!is_file($sourcePath)) {
            return null;
        }

        $fileInfo = [
            'name' => basename($sourcePath),
            'tmp_name' => $sourcePath,
            'type' => mime_content_type($sourcePath) ?: 'application/octet-stream',
            'size' => filesize($sourcePath),
            'error' => UPLOAD_ERR_OK,
        ];

        $handlerWebp = new UploadHandler($fileInfo);
        if (!$handlerWebp->uploaded) {
            return null;
        }

        $handlerWebp->file_new_name_body = pathinfo($sourcePath, PATHINFO_FILENAME);
        $handlerWebp->image_convert = 'webp';
        $handlerWebp->process($targetDir);
        if (!$handlerWebp->processed) {
            $handlerWebp->clean();
            return null;
        }

        $webpPath = rtrim($targetDir, '/') . '/' . $handlerWebp->file_dst_name;
        if ($maxImageSizeKb > 0) {
            self::shrinkImageToMaxSize($webpPath, 'image/webp', $maxImageSizeKb * 1024);
        }

        $webpFilename = $handlerWebp->file_dst_name;
        $handlerWebp->clean();

        return $webpFilename;
    }

    private static function shrinkImageToMaxSize(string $filePath, string $mimeType, int $maxBytes): void
    {
        if ($maxBytes <= 0 || !is_file($filePath)) {
            return;
        }

        clearstatcache(true, $filePath);
        if (filesize($filePath) <= $maxBytes) {
            return;
        }

        $dimensions = @getimagesize($filePath);
        if (!$dimensions) {
            return;
        }

        [$width, $height] = $dimensions;
        $quality = 90;
        $scale = 1.0;

        while (filesize($filePath) > $maxBytes && $quality >= 40) {
            $image = self::createImageResource($filePath, $mimeType);
            if (!$image) {
                break;
            }

            $targetWidth = max(1, (int) round($width * $scale));
            $targetHeight = max(1, (int) round($height * $scale));
            $method = defined('IMG_BICUBIC') ? IMG_BICUBIC : (defined('IMG_BILINEAR_FIXED') ? IMG_BILINEAR_FIXED : IMG_NEAREST_NEIGHBOUR);
            $resized = imagescale($image, $targetWidth, $targetHeight, $method);
            imagedestroy($image);

            if (!$resized) {
                break;
            }

            self::saveImageResource($resized, $filePath, $mimeType, $quality);
            imagedestroy($resized);

            clearstatcache(true, $filePath);
            $quality -= 5;
            $scale *= 0.9;
        }
    }

    private static function createImageResource(string $filePath, string $mimeType)
    {
        if (strpos($mimeType, 'png') !== false) {
            return @imagecreatefrompng($filePath);
        }

        if (strpos($mimeType, 'gif') !== false) {
            return @imagecreatefromgif($filePath);
        }

        if (strpos($mimeType, 'webp') !== false) {
            return function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($filePath) : false;
        }

        return @imagecreatefromjpeg($filePath);
    }

    private static function saveImageResource($image, string $filePath, string $mimeType, int $quality): void
    {
        if (strpos($mimeType, 'png') !== false) {
            $compression = max(0, min(9, 9 - (int) round($quality / 10)));
            imagepng($image, $filePath, $compression);
            return;
        }

        if (strpos($mimeType, 'gif') !== false) {
            imagegif($image, $filePath);
            return;
        }

        if (strpos($mimeType, 'webp') !== false && function_exists('imagewebp')) {
            imagewebp($image, $filePath, $quality);
            return;
        }

        imagejpeg($image, $filePath, $quality);
    }
}
