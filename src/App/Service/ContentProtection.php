<?php

namespace App\Service;

class ContentProtection
{
    public static function render(string $body): string
    {
        if ($body === '') {
            return $body;
        }

        return preg_replace_callback('/\[(logged|members_only)\](.*?)\[\/\1\]/si', function ($matches) {
            $innerContent = $matches[2] ?? '';

            if (Auth::user()) {
                return $innerContent;
            }

            return '<div class="alert alert-info my-3">Tato část obsahu je dostupná jen přihlášeným uživatelům. <a href="/login">Přihlásit se</a></div>';
        }, $body);
    }
}
