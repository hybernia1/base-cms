<?php
namespace App\Controller\Admin;

use App\Service\Auth;
use App\Service\Flash;
use App\Service\ContentType;
use App\Service\TermType;

abstract class BaseAdminController
{
    protected $twig;

    public function __construct()
    {
        $this->twig = $GLOBALS['app']['twig'];
    }

    protected function render(string $template, array $context = []): void
    {
        $user = Auth::user();
        $flash = Flash::consume();

        echo $this->twig->render($template, array_merge([
            'app_user'      => $user,
            'flash_success' => $flash['success'],
            'flash_error'   => $flash['error'],
            'content_type_menu' => ContentType::definitions(),
            'term_type_menu' => TermType::definitions(),
        ], $context));
    }

    protected function wantsJson(): bool
    {
        $requestedWith = strtolower(trim($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));
        $accept = strtolower($_SERVER['HTTP_ACCEPT'] ?? '');

        return $requestedWith === 'xmlhttprequest' || str_contains($accept, 'application/json');
    }

    protected function json(array $payload, int $status = 200): void
    {
        header('Content-Type: application/json', true, $status);
        echo json_encode($payload);
        exit;
    }
}
