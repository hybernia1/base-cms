<?php
namespace App\Service;

use Gregwar\Captcha\CaptchaBuilder;
use Gregwar\Captcha\PhraseBuilder;

class Captcha
{
    private const ALLOWED_CONTEXTS = ['register', 'login', 'comments'];
    private const SESSION_PREFIX = 'captcha_phrase_';

    public static function isEnabledFor(string $context): bool
    {
        if (Setting::get('captcha_enabled', '0') !== '1') {
            return false;
        }

        $context = self::normalizeContext($context);
        if ($context === null) {
            return false;
        }

        $enabledForms = self::getEnabledForms();

        return in_array($context, $enabledForms, true);
    }

    public static function output(string $context): void
    {
        $context = self::normalizeContext($context);
        if ($context === null || !self::isEnabledFor($context)) {
            http_response_code(404);
            echo 'Captcha není dostupná.';
            return;
        }

        $length = max(3, min(8, (int) Setting::get('captcha_length', 5)));
        $width = max(80, min(400, (int) Setting::get('captcha_width', 180)));
        $height = max(30, min(200, (int) Setting::get('captcha_height', 50)));

        $phraseBuilder = new PhraseBuilder(null, $length);
        $builder = new CaptchaBuilder(null, $phraseBuilder);
        $builder
            ->setBackgroundColor(255, 255, 255)
            ->setTextColor(40, 40, 40)
            ->setMaxBehindLines(0)
            ->setMaxFrontLines(0)
            ->setDistortion(false)
            ->setMaxAngle(0)
            ->setInterpolation(false)
            ->setIgnoreAllEffects(true)
            ->build($width, $height);

        $_SESSION[self::sessionKey($context)] = $builder->getPhrase();

        header('Cache-Control: no-cache, must-revalidate');
        header('Pragma: no-cache');
        header('Content-Type: image/jpeg');

        $builder->output();
    }

    public static function validate(string $context, ?string $input): bool
    {
        $context = self::normalizeContext($context);
        if ($context === null || $input === null) {
            return false;
        }

        $stored = $_SESSION[self::sessionKey($context)] ?? '';
        $isValid = $stored !== '' && strcasecmp(trim($input), (string) $stored) === 0;

        if ($isValid) {
            unset($_SESSION[self::sessionKey($context)]);
        }

        return $isValid;
    }

    public static function refreshKey(string $context): string
    {
        $context = self::normalizeContext($context) ?? 'default';

        return '/captcha/' . $context . '?t=' . microtime(true);
    }

    private static function getEnabledForms(): array
    {
        $value = Setting::get('captcha_forms', '[]');
        $decoded = json_decode((string) $value, true);

        if (!is_array($decoded)) {
            return [];
        }

        return array_values(array_intersect(self::ALLOWED_CONTEXTS, array_map('strval', $decoded)));
    }

    private static function sessionKey(string $context): string
    {
        return self::SESSION_PREFIX . $context;
    }

    private static function normalizeContext(string $context): ?string
    {
        $context = strtolower(trim($context));

        return in_array($context, self::ALLOWED_CONTEXTS, true) ? $context : null;
    }
}
