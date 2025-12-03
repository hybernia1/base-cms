<?php
namespace App\Controller\Front;

use App\Service\Captcha;

class CaptchaController
{
    public function show(string $context): void
    {
        Captcha::output($context);
    }
}
