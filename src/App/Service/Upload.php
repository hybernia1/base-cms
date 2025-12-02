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

        if ($allowWebp && $handler->file_is_image) {
            $handlerWebp = new UploadHandler($file);
            $handlerWebp->file_new_name_body = pathinfo($filename, PATHINFO_FILENAME);
            $handlerWebp->image_convert = 'webp';
            $handlerWebp->process($targetDir);
            if ($handlerWebp->processed) {
                $webpVariant = $handlerWebp->file_dst_name;
                $handlerWebp->clean();
            }
        }

        $handler->clean();

        $media = R::dispense('media');
        $media->path = self::relativePath($targetDir);
        $media->filename = $filename;
        $media->webp_filename = $webpVariant;
        $media->mime_type = $handler->file_src_mime;
        $media->size = $handler->file_src_size;
        $media->is_image = $handler->file_is_image ? 1 : 0;
        $media->original_name = $file['name'] ?? '';
        $media->uploaded_by = $uploadedBy;
        $media->created_at = date('Y-m-d H:i:s');
        R::store($media);

        return [$media, null];
    }

    private static function mimeTypesFor(array $extensions): array
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
}
