<?php
namespace App\Controller\Front;

class ErrorController extends BaseFrontController
{
    public function notFound(): void
    {
        $this->renderNotFound();
    }
}
