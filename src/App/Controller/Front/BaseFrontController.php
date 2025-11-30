<?php
namespace App\Controller\Front;

use App\Service\Flash;
use App\Service\ContentType;

abstract class BaseFrontController
{
    protected $twig;

    public function __construct()
    {
        $this->twig = $GLOBALS['app']['twig'];
    }

    protected function render(string $template, array $context = []): void
    {
        $flash = Flash::consume();

        echo $this->twig->render($template, array_merge([
            'flash_success' => $flash['success'],
            'flash_error'   => $flash['error'],
            'post_archive_slug' => ContentType::defaultSlug('post'),
        ], $context));
    }
}
