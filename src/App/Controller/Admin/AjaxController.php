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
            'success' => true,
            'view' => [
                'template' => $template,
                'context' => array_merge($this->baseContext(false), $context),
            ],
            'state_url' => $stateUrl,
        ], $extra);

        $this->jsonResponse($payload);

        return true;
    }

    protected function respondAjaxMessage(string $message, array $extra = [], int $status = 200): void
    {
        $this->jsonResponse(array_merge([
            'success' => $status >= 200 && $status < 300,
            'message' => $message,
        ], $extra), $status);
    }
}
