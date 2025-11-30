<?php
namespace App\Controller\Admin;

use App\Service\Auth;
use App\Service\Flash;
use App\Service\ContentType;

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
        ], $context));
    }
}
