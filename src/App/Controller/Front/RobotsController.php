<?php
namespace App\Controller\Front;

use App\Service\Setting;

class RobotsController extends BaseFrontController
{
    public function index(): void
    {
        $indexingEnabled = Setting::get('indexing_enabled', Setting::DEFAULTS['indexing_enabled']) === '1';

        header('Content-Type: text/plain; charset=utf-8');

        $lines = ['User-agent: *'];

        if ($indexingEnabled) {
            $lines[] = 'Allow: /';
            $lines[] = 'Sitemap: ' . $this->baseUrl() . '/sitemap.xml';
        } else {
            $lines[] = 'Disallow: /';
        }

        echo implode("\n", $lines) . "\n";
    }

    private function baseUrl(): string
    {
        static $baseUrl = null;
        if ($baseUrl !== null) {
            return $baseUrl;
        }

        $scheme = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $baseUrl = $scheme . '://' . $host;

        return $baseUrl;
    }
}
