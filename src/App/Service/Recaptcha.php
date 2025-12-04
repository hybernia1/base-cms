<?php
namespace App\Service;

class Recaptcha
{
    private const VERIFY_URL = 'https://www.google.com/recaptcha/api/siteverify';
    private const MIN_SCORE = 0.5;

    public static function isEnabled(): bool
    {
        $siteKey = trim((string) Setting::get('recaptcha_site_key', ''));
        $secretKey = trim((string) Setting::get('recaptcha_secret_key', ''));

        return $siteKey !== '' && $secretKey !== '';
    }

    public static function verify(?string $token, string $action = 'submit'): array
    {
        if (!self::isEnabled()) {
            return ['success' => true, 'message' => null];
        }

        if (!$token) {
            SecurityLog::record('recaptcha_missing', 'Chybí reCAPTCHA token.', self::context($action));

            return [
                'success' => false,
                'message' => 'Nepodařilo se ověřit reCAPTCHA. Zkuste to prosím znovu.',
            ];
        }

        $secretKey = (string) Setting::get('recaptcha_secret_key', '');
        $remoteIp = $_SERVER['REMOTE_ADDR'] ?? null;
        $response = self::requestVerification($secretKey, $token, $remoteIp);

        if ($response === null) {
            SecurityLog::record('recaptcha_error', 'Nepodařilo se kontaktovat ověřovací službu reCAPTCHA.', self::context($action, [
                'token_sample' => substr($token, 0, 8),
            ]));

            return [
                'success' => false,
                'message' => 'Nepodařilo se ověřit reCAPTCHA. Zkuste to prosím znovu.',
            ];
        }

        $isValid = self::isValidResponse($response, $action);

        if (!$isValid) {
            SecurityLog::record('recaptcha_failed', 'Ověření reCAPTCHA bylo zamítnuto.', self::context($action, $response));

            return [
                'success' => false,
                'message' => 'Ověření reCAPTCHA se nezdařilo. Zkuste to prosím znovu.',
            ];
        }

        return ['success' => true, 'message' => null];
    }

    private static function requestVerification(string $secretKey, string $token, ?string $remoteIp): ?array
    {
        $postData = http_build_query([
            'secret' => $secretKey,
            'response' => $token,
            'remoteip' => $remoteIp,
        ]);

        $options = [
            'http' => [
                'method' => 'POST',
                'header' => "Content-type: application/x-www-form-urlencoded\r\n",
                'content' => $postData,
                'timeout' => 5,
            ],
        ];

        $context = stream_context_create($options);
        $result = @file_get_contents(self::VERIFY_URL, false, $context);
        if ($result === false) {
            return null;
        }

        $decoded = json_decode($result, true);

        return is_array($decoded) ? $decoded : null;
    }

    private static function isValidResponse(array $response, string $action): bool
    {
        if (!($response['success'] ?? false)) {
            return false;
        }

        $score = $response['score'] ?? null;
        if ($score !== null && $score < self::MIN_SCORE) {
            return false;
        }

        if (isset($response['action']) && $response['action'] !== '' && $action !== '' && $response['action'] !== $action) {
            return false;
        }

        return true;
    }

    private static function context(string $action, array $additional = []): array
    {
        $context = [
            'action' => $action,
            'path' => $_SERVER['REQUEST_URI'] ?? null,
        ];

        if (isset($_SERVER['HTTP_USER_AGENT'])) {
            $context['user_agent'] = $_SERVER['HTTP_USER_AGENT'];
        }

        return array_merge($context, $additional);
    }
}
