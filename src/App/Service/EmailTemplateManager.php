<?php
namespace App\Service;

use RedBeanPHP\R as R;

class EmailTemplateManager
{
    private const TABLE = 'emailtemplate';
    private const DEFAULT_TEMPLATES = [
        'user_registered' => [
            'subject' => 'Vítejte na {{site_name}}',
            'body_html' => '<p>Dobrý den,</p><p>váš účet {{email}} byl úspěšně vytvořen.</p>',
            'body_text' => "Dobrý den,\nVáš účet {{email}} byl úspěšně vytvořen.",
        ],
        'user_password_reset' => [
            'subject' => 'Instrukce pro reset hesla',
            'body_html' => '<p>Dobrý den,</p><p>pokud jste požádali o reset hesla, použijte odkaz: {{reset_link}}.</p><p>Odkaz je platný 2 hodiny. Pokud jste o změnu nepožádali, prosíme, ignorujte tento e-mail.</p>',
            'body_text' => "Pokud jste požádali o reset hesla, použijte odkaz: {{reset_link}}.\n\nOdkaz je platný 2 hodiny. Pokud jste o změnu nepožádali, prosíme, ignorujte tento e-mail.",
        ],
        'user_banned' => [
            'subject' => 'Váš účet byl zablokován',
            'body_html' => '<p>Dobrý den,</p><p>váš účet byl zablokován. Důvod: {{reason}}.</p>',
            'body_text' => "Váš účet byl zablokován. Důvod: {{reason}}.",
        ],
        'comment_approved' => [
            'subject' => 'Váš komentář byl schválen',
            'body_html' => '<p>Dobrý den,</p><p>váš komentář byl schválen a je nyní viditelný u obsahu <a href="{{comment_url}}">{{content_title}}</a>.</p><blockquote>{{comment_body}}</blockquote>',
            'body_text' => "Váš komentář byl schválen a je nyní viditelný u obsahu {{content_title}}: {{comment_url}}\n\n{{comment_body}}",
        ],
        'comment_reply' => [
            'subject' => 'Nová reakce na váš komentář',
            'body_html' => '<p>Dobrý den,</p><p>na váš komentář byla přidána reakce u obsahu <a href="{{comment_url}}">{{content_title}}</a>.</p><blockquote>{{reply_body}}</blockquote>',
            'body_text' => "Na váš komentář byla přidána reakce u obsahu {{content_title}}: {{comment_url}}\n\n{{reply_body}}",
        ],
        'comment_deleted' => [
            'subject' => 'Váš komentář byl odstraněn',
            'body_html' => '<p>Dobrý den,</p><p>váš komentář byl odstraněn moderátorem.</p>',
            'body_text' => "Váš komentář byl odstraněn moderátorem.",
        ],
    ];

    public static function send(string $event, string $to, array $data = []): void
    {
        self::ensureSchema();
        $template = self::getOrCreateTemplate($event);
        if (!$template || (isset($template->enabled) && (int) $template->enabled !== 1)) {
            return;
        }

        $content = self::renderTemplate($template, $data);

        Mail::send($to, $content['subject'], $content['body_html'], $content['body_text']);
    }

    public static function all(): array
    {
        self::ensureSchema();
        $items = R::findAll(self::TABLE, ' ORDER BY event ASC ');
        return array_values($items);
    }

    public static function findByEvent(string $event)
    {
        self::ensureSchema();
        $template = R::findOne(self::TABLE, ' event = ? ', [$event]);
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

        $template->subject = $data['subject'] ?? $template->subject;
        $template->body_html = $data['body_html'] ?? $template->body_html;
        $template->body_text = $data['body_text'] ?? $template->body_text;
        if (array_key_exists('enabled', $data)) {
            $template->enabled = (int) $data['enabled'] === 1 ? 1 : 0;
        }
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
        $template = R::dispense(self::TABLE);
        $template->event = $event;
        $template->subject = $values['subject'] ?? '';
        $template->body_html = $values['body_html'] ?? '';
        $template->body_text = $values['body_text'] ?? '';
        $template->enabled = $values['enabled'] ?? 1;
        $template->updated_at = date('Y-m-d H:i:s');
        R::store($template);

        return $template;
    }

    private static function ensureSchema(): void
    {
        // Schéma je kompletně spravováno instalační logikou.
    }
}
