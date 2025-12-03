<?php
namespace App\Controller\Admin;

use App\Service\Auth;
use App\Service\EmailTemplateManager;
use App\Service\Flash;

class EmailTemplateController extends AjaxController
{
    public function index()
    {
        Auth::requireRole('admin');

        $templates = EmailTemplateManager::all();
        $defaults = EmailTemplateManager::defaults();

        $this->render('admin/email_templates/index.twig', [
            'templates' => $templates,
            'defaults' => $defaults,
            'current_menu' => 'email_templates',
        ]);
    }

    public function editForm($event)
    {
        Auth::requireRole('admin');

        $template = EmailTemplateManager::findByEvent($event);

        if (!$template) {
            Flash::addError('Šablona nenalezena.');
            header('Location: /admin/email-templates');
            exit;
        }

        $this->render('admin/email_templates/form.twig', [
            'template' => $template,
            'defaults' => EmailTemplateManager::defaults(),
            'current_menu' => 'email_templates',
        ]);
    }

    public function update($event)
    {
        Auth::requireRole('admin');

        $data = [
            'subject' => trim($_POST['subject'] ?? ''),
            'body_html' => trim($_POST['body_html'] ?? ''),
            'body_text' => trim($_POST['body_text'] ?? ''),
            'enabled' => isset($_POST['enabled']) ? 1 : 0,
        ];

        EmailTemplateManager::updateTemplate($event, $data);

        Flash::addSuccess('Šablona byla uložena.');
        header("Location: /admin/email-templates/{$event}");
        exit;
    }

    public function toggle($event)
    {
        Auth::requireRole('admin');

        $template = EmailTemplateManager::findByEvent($event);
        if (!$template) {
            Flash::addError('Šablona nenalezena.');
            header('Location: /admin/email-templates');
            exit;
        }

        $enabled = isset($_POST['enabled']) && $_POST['enabled'] === '1' ? 1 : 0;
        EmailTemplateManager::updateTemplate($event, ['enabled' => $enabled]);

        Flash::addSuccess($enabled ? 'Šablona bude odesílána.' : 'Šablona byla deaktivována.');
        header('Location: /admin/email-templates');
        exit;
    }
}
