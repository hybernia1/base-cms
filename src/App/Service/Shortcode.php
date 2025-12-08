<?php

namespace App\Service;

class Shortcode
{
    public static function definitions(): array
    {
        return [
            'logged' => [
                'label' => 'Pouze pro přihlášené uživatele',
                'description' => 'Zobrazí obsah jen přihlášeným uživatelům. Nepřihlášeným se ukáže informační hláška s odkazem na přihlášení.',
                'example' => '[logged]Tento obsah uvidí jen přihlášení[/logged]',
            ],
            'pagebreak' => [
                'label' => 'Zalomit stránku',
                'description' => 'Rozdělí článek na více stránek (např. /2, /3).',
                'example' => '[pagebreak]',
            ],
        ];
    }

    public static function render(string $body): string
    {
        if ($body === '') {
            return $body;
        }

        $body = preg_replace('/\[\s*pagebreak\s*\]/i', '', $body);

        $definitions = self::definitions();
        $tags = array_keys($definitions);

        if (empty($tags)) {
            return $body;
        }

        $pattern = '/\\[(' . implode('|', array_map('preg_quote', $tags)) . ')\\](.*?)\\[\\/\\1\\]/si';

        return preg_replace_callback($pattern, function ($matches) {
            $tag = $matches[1] ?? '';
            $innerContent = $matches[2] ?? '';

            switch ($tag) {
                case 'logged':
                    if (Auth::user()) {
                        return $innerContent;
                    }

                    return '<div class="alert alert-info my-3">Tato část obsahu je dostupná jen přihlášeným uživatelům. <a href="/login">Přihlásit se</a></div>';
                case 'pagebreak':
                    return '';
                default:
                    return $matches[0] ?? '';
            }
        }, $body);
    }
}
