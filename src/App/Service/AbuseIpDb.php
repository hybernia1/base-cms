<?php

namespace App\Service;

class AbuseIpDb
{
    private const ENDPOINT = 'https://api.abuseipdb.com/api/v2/report';

    public static function report(string $ip, string $comment, array $categories = ['18']): void
    {
        $apiKey = Setting::get('abuseipdb_api_key', '');
        if ($apiKey === '') {
            return;
        }

        $payload = http_build_query([
            'ip' => $ip,
            'categories' => implode(',', $categories),
            'comment' => $comment,
        ]);

        $options = [
            'http' => [
                'method' => 'POST',
                'header' => [
                    'Content-Type: application/x-www-form-urlencoded',
                    'Key: ' . $apiKey,
                ],
                'content' => $payload,
                'timeout' => 5,
            ],
        ];

        try {
            file_get_contents(self::ENDPOINT, false, stream_context_create($options));
        } catch (\Throwable $e) {
            SecurityLog::record('abuseipdb_error', 'AbuseIPDB požadavek selhal', [
                'error' => $e->getMessage(),
                'ip' => $ip,
            ]);
        }
    }
}
