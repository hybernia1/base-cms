<?php
namespace App\Service;

class RequestHelper
{
    public static function clientIp(): string
    {
        return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    }
}
