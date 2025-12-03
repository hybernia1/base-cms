<?php

namespace App\Service;

class MediaHelper
{
    private const MIME_ICON_MAP = [
        'application/pdf' => 'bi-filetype-pdf text-danger',
        'application/zip' => 'bi-file-earmark-zip',
        'application/x-7z-compressed' => 'bi-file-earmark-zip',
        'application/x-rar-compressed' => 'bi-file-earmark-zip',
        'application/vnd.ms-excel' => 'bi-file-earmark-spreadsheet',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'bi-file-earmark-spreadsheet',
        'application/msword' => 'bi-file-earmark-richtext',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'bi-file-earmark-richtext',
        'application/vnd.ms-powerpoint' => 'bi-file-earmark-slides',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'bi-file-earmark-slides',
        'text/plain' => 'bi-filetype-txt',
        'text/html' => 'bi-filetype-html',
    ];

    public static function mimeToIcon(?string $mimeType): string
    {
        if (!$mimeType) {
            return 'bi-file-earmark';
        }

        if (isset(self::MIME_ICON_MAP[$mimeType])) {
            return self::MIME_ICON_MAP[$mimeType];
        }

        if (str_starts_with($mimeType, 'image/')) {
            return 'bi-file-earmark-image';
        }

        if (str_starts_with($mimeType, 'audio/')) {
            return 'bi-file-earmark-music';
        }

        if (str_starts_with($mimeType, 'video/')) {
            return 'bi-file-earmark-play';
        }

        if (str_starts_with($mimeType, 'text/')) {
            return 'bi-filetype-txt';
        }

        return 'bi-file-earmark';
    }

    public static function humanSize($bytes): string
    {
        if (!is_numeric($bytes) || $bytes < 0) {
            return '';
        }

        $bytes = (float) $bytes;

        if ($bytes < 1024) {
            return sprintf('%d B', $bytes);
        }

        $kilobytes = $bytes / 1024;
        if ($kilobytes < 1024) {
            return sprintf('%.1f kB', $kilobytes);
        }

        $megabytes = $kilobytes / 1024;
        if ($megabytes < 1024) {
            return sprintf('%.1f MB', $megabytes);
        }

        $gigabytes = $megabytes / 1024;
        return sprintf('%.1f GB', $gigabytes);
    }
}
