<?php

namespace App\Service;

class SecurityIntegration
{
    private const RECAPTCHA_VERIFY_URL = 'https://www.google.com/recaptcha/api/siteverify';

    public static function isRecaptchaEnabled(): bool
    {
        return Setting::get('recaptcha_enabled', '0') === '1'
            && Setting::get('recaptcha_site_key', '') !== ''
            && Setting::get('recaptcha_secret_key', '') !== '';
    }

    public static function recaptchaSiteKey(): string
    {
        return (string) Setting::get('recaptcha_site_key', '');
    }

    public static function verifyRecaptcha(?string $token): array
    {
        if (!self::isRecaptchaEnabled()) {
            return ['success' => true, 'message' => 'reCAPTCHA není aktivní'];
        }

        if (!$token) {
            return ['success' => false, 'message' => 'Chybí potvrzení reCAPTCHA.'];
        }

        $payload = http_build_query([
            'secret' => Setting::get('recaptcha_secret_key', ''),
            'response' => $token,
            'remoteip' => $_SERVER['REMOTE_ADDR'] ?? null,
        ]);

        $options = [
            'http' => [
                'method' => 'POST',
                'header' => 'Content-type: application/x-www-form-urlencoded',
                'content' => $payload,
                'timeout' => 5,
            ],
        ];

        try {
            $response = @file_get_contents(self::RECAPTCHA_VERIFY_URL, false, stream_context_create($options));
            if ($response === false) {
                throw new \RuntimeException('reCAPTCHA ověření se nepodařilo.');
            }

            $data = json_decode($response, true);
            if (($data['success'] ?? false) !== true) {
                return [
                    'success' => false,
                    'message' => 'Ověření reCAPTCHA selhalo.',
                    'error_codes' => $data['error-codes'] ?? [],
                ];
            }

            return ['success' => true, 'message' => 'Ověření proběhlo.'];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => 'Nepodařilo se kontaktovat reCAPTCHA službu.',
                'error_codes' => [$e->getMessage()],
            ];
        }
    }

    public static function enforceForFrontRequest(): void
    {
        if (!self::isRecaptchaEnabled()) {
            return;
        }

        $verification = self::verifyRecaptcha($_POST['g-recaptcha-response'] ?? null);

        if ($verification['success'] ?? false) {
            return;
        }

        $message = $verification['message'] ?? 'Ověření reCAPTCHA se nezdařilo.';
        $context = [
            'path' => $_SERVER['REQUEST_URI'] ?? null,
            'error_codes' => $verification['error_codes'] ?? [],
        ];

        SecurityLog::record('recaptcha_failed', $message, $context);

        if (Setting::get('abuseipdb_report_recaptcha', '0') === '1' && !empty($_SERVER['REMOTE_ADDR'])) {
            AbuseIpDb::report(
                $_SERVER['REMOTE_ADDR'],
                sprintf('reCAPTCHA ověření selhalo na %s', $_SERVER['REQUEST_URI'] ?? '/'),
                ['21']
            );
        }

        self::respondWithFailure($message);
    }

    private static function respondWithFailure(string $message): void
    {
        $expectsJson = self::requestExpectsJson();

        if ($expectsJson) {
            header('Content-Type: application/json');
            http_response_code(400);
            echo json_encode([
                'status' => 'error',
                'message' => $message,
            ]);
            exit;
        }

        Flash::addError($message);
        header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? '/'));
        exit;
    }

    private static function requestExpectsJson(): bool
    {
        $acceptHeader = $_SERVER['HTTP_ACCEPT'] ?? '';
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';

        return str_contains($acceptHeader, 'application/json')
            || str_contains($contentType, 'application/json')
            || ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest';
    }
}
