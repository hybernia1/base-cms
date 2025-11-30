<?php
namespace App\Service;

use RedBeanPHP\R as R;

class EmailTemplateManager
{
    private const DEFAULT_TEMPLATES = [
        'user_registered' => [
            'subject' => 'Vítejte na {{site_name}}',
            'body_html' => '<p>Dobrý den,</p><p>váš účet {{email}} byl úspěšně vytvořen.</p>',
            'body_text' => "Dobrý den,\nVáš účet {{email}} byl úspěšně vytvořen.",
        ],
        'user_password_reset' => [
            'subject' => 'Instrukce pro reset hesla',
            'body_html' => '<p>Dobrý den,</p><p>pokud jste požádali o reset hesla, použijte odkaz: {{reset_link}}</p>',
            'body_text' => "Pokud jste požádali o reset hesla, použijte odkaz: {{reset_link}}",
        ],
        'user_banned' => [
            'subject' => 'Váš účet byl zablokován',
            'body_html' => '<p>Dobrý den,</p><p>váš účet byl zablokován. Důvod: {{reason}}.</p>',
            'body_text' => "Váš účet byl zablokován. Důvod: {{reason}}.",
        ],
    ];

    public static function send(string $event, string $to, array $data = []): void
    {
        self::ensureSchema();
        $template = self::getOrCreateTemplate($event);
        if (!$template) {
            return;
        }

        $content = self::renderTemplate($template, $data);

        Mail::send($to, $content['subject'], $content['body_html'], $content['body_text']);
    }

    public static function all(): array
    {
        self::ensureSchema();
        $items = R::findAll('email_template', ' ORDER BY event ASC ');
        return array_values($items);
    }

    public static function findByEvent(string $event)
    {
        self::ensureSchema();
        $template = R::findOne('email_template', ' event = ? ', [$event]);
        if ($template) {
            return $template;
        }

        if (isset(self::DEFAULT_TEMPLATES[$event])) {
            return self::createTemplate($event, self::DEFAULT_TEMPLATES[$event]);
        }

        return null;
    }

    public static function updateTemplate(string $event, array $data)
    {
        self::ensureSchema();
        $template = self::findByEvent($event);
        if (!$template) {
            $template = self::createTemplate($event, [
                'subject' => '',
                'body_html' => '',
                'body_text' => '',
            ]);
        }

        $template->subject = $data['subject'];
        $template->body_html = $data['body_html'];
        $template->body_text = $data['body_text'];
        $template->updated_at = date('Y-m-d H:i:s');

        R::store($template);

        return $template;
    }

    public static function defaults(): array
    {
        return self::DEFAULT_TEMPLATES;
    }

    private static function renderTemplate($template, array $data): array
    {
        $globalData = [
            'site_name' => Setting::get('site_name', 'Web'),
        ];

        $replacements = array_merge($globalData, $data);

        $replaceFn = function ($text) use ($replacements) {
            foreach ($replacements as $key => $value) {
                $text = str_replace('{{' . $key . '}}', (string) $value, $text);
            }
            return $text;
        };

        return [
            'subject' => $replaceFn($template->subject),
            'body_html' => $replaceFn($template->body_html),
            'body_text' => $replaceFn($template->body_text),
        ];
    }

    private static function getOrCreateTemplate(string $event)
    {
        $existing = self::findByEvent($event);
        if ($existing) {
            return $existing;
        }

        if (!isset(self::DEFAULT_TEMPLATES[$event])) {
            return null;
        }

        return self::createTemplate($event, self::DEFAULT_TEMPLATES[$event]);
    }

    private static function createTemplate(string $event, array $values)
    {
        $template = R::dispense('email_template');
        $template->event = $event;
        $template->subject = $values['subject'] ?? '';
        $template->body_html = $values['body_html'] ?? '';
        $template->body_text = $values['body_text'] ?? '';
        $template->updated_at = date('Y-m-d H:i:s');
        R::store($template);

        return $template;
    }

    private static function ensureSchema(): void
    {
        R::exec(
            "CREATE TABLE IF NOT EXISTS `email_template` (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `event` VARCHAR(191) NOT NULL UNIQUE,
                `subject` VARCHAR(255) NOT NULL,
                `body_html` TEXT NOT NULL,
                `body_text` TEXT NOT NULL,
                `updated_at` DATETIME NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }
}
