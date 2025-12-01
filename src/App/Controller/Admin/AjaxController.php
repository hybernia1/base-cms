<?php
namespace App\Controller\Admin;

abstract class AjaxController extends BaseAdminController
{
    protected function respondAjax(string $template, array $context = [], ?string $stateUrl = null, array $extra = []): bool
    {
        if (!$this->wantsJson()) {
            return false;
        }

        $payload = array_merge([
            'html' => $this->twig->render($template, $context),
        ], $extra);

        if ($stateUrl !== null) {
            $payload['state_url'] = $stateUrl;
        }

        $this->jsonResponse($payload);

        return true;
    }

    protected function respondAjaxMessage(string $message, array $extra = [], int $status = 200): void
    {
        $this->jsonResponse(array_merge([
            'message' => $message,
        ], $extra), $status);
    }
}
