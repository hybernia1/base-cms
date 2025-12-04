<?php

namespace App\Service;

class ContentProtection
{
    public static function render(string $body): string
    {
        return Shortcode::render($body);
    }
}
