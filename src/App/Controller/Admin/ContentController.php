<?php
namespace App\Controller\Admin;

use App\Service\Auth;
use App\Service\ContentType;
use App\Service\Flash;
use RedBeanPHP\R as R;

class ContentController extends BaseAdminController
{
    public function index()
    {
        Auth::requireRole(['admin', 'editor']);

        $items = R::findAll('content', ' ORDER BY updated_at DESC ');

        $this->render('admin/content/index.twig', [
            'items' => $items,
            'types' => ContentType::all(),
            'current_menu' => 'pages',
        ]);
    }

    public function createForm()
    {
        Auth::requireRole(['admin', 'editor']);

        $this->render('admin/content/form.twig', [
            'types' => ContentType::all(),
            'values' => [
                'title' => '',
                'slug'  => '',
                'type'  => 'post',
                'body'  => '',
            ],
            'errors' => [],
            'heading' => 'Nový obsah',
            'form_action' => '/admin/pages/create',
            'current_menu' => 'pages',
        ]);
    }

    public function create()
    {
        Auth::requireRole(['admin', 'editor']);

        $data = $this->sanitizeInput();
        $errors = $this->validate($data);

        if ($errors) {
            $this->render('admin/content/form.twig', [
                'types' => ContentType::all(),
                'values' => $data,
                'errors' => $errors,
                'heading' => 'Nový obsah',
                'form_action' => '/admin/pages/create',
                'current_menu' => 'pages',
            ]);
            return;
        }

        $bean = R::dispense('content');
        $bean->title = $data['title'];
        $bean->slug = $data['slug'];
        $bean->type = $data['type'];
        $bean->body = $data['body'];
        $bean->created_at = date('Y-m-d H:i:s');
        $bean->updated_at = date('Y-m-d H:i:s');
        R::store($bean);

        Flash::addSuccess('Obsah byl vytvořen.');
        header('Location: /admin/pages');
        exit;
    }

    public function editForm($id)
    {
        Auth::requireRole(['admin', 'editor']);

        $content = $this->findContent($id);
        if (!$content) {
            Flash::addError('Obsah nebyl nalezen.');
            header('Location: /admin/pages');
            exit;
        }

        $this->render('admin/content/form.twig', [
            'types' => ContentType::all(),
            'values' => [
                'title' => $content->title,
                'slug'  => $content->slug,
                'type'  => $content->type,
                'body'  => $content->body,
            ],
            'errors' => [],
            'heading' => 'Upravit obsah',
            'form_action' => "/admin/pages/{$content->id}/edit",
            'current_menu' => 'pages',
            'content_id' => $content->id,
        ]);
    }

    public function update($id)
    {
        Auth::requireRole(['admin', 'editor']);

        $content = $this->findContent($id);
        if (!$content) {
            Flash::addError('Obsah nebyl nalezen.');
            header('Location: /admin/pages');
            exit;
        }

        $data = $this->sanitizeInput();
        $errors = $this->validate($data, (int) $content->id);

        if ($errors) {
            $this->render('admin/content/form.twig', [
                'types' => ContentType::all(),
                'values' => $data,
                'errors' => $errors,
                'heading' => 'Upravit obsah',
                'form_action' => "/admin/pages/{$content->id}/edit",
                'current_menu' => 'pages',
                'content_id' => $content->id,
            ]);
            return;
        }

        $content->title = $data['title'];
        $content->slug = $data['slug'];
        $content->type = $data['type'];
        $content->body = $data['body'];
        $content->updated_at = date('Y-m-d H:i:s');
        R::store($content);

        Flash::addSuccess('Obsah byl upraven.');
        header('Location: /admin/pages');
        exit;
    }

    public function delete($id)
    {
        Auth::requireRole(['admin', 'editor']);

        $content = $this->findContent($id);
        if (!$content) {
            Flash::addError('Obsah nebyl nalezen.');
            header('Location: /admin/pages');
            exit;
        }

        R::trash($content);
        Flash::addSuccess('Obsah byl smazán.');
        header('Location: /admin/pages');
        exit;
    }

    private function sanitizeInput(): array
    {
        return [
            'title' => trim($_POST['title'] ?? ''),
            'slug'  => trim($_POST['slug'] ?? ''),
            'type'  => trim($_POST['type'] ?? ''),
            'body'  => trim($_POST['body'] ?? ''),
        ];
    }

    private function validate(array &$data, int $ignoreId = 0): array
    {
        $errors = [];

        if ($data['title'] === '') {
            $errors['title'] = 'Název je povinný.';
        }

        if ($data['type'] === '' || !ContentType::exists($data['type'])) {
            $errors['type'] = 'Vyber platný typ obsahu.';
        }

        if ($data['slug'] === '') {
            $data['slug'] = $this->slugify($data['title']);
        }

        if ($data['slug'] === '') {
            $errors['slug'] = 'Slug musí být vyplněn.';
        } elseif ($this->slugExists($data['slug'], $data['type'], $ignoreId)) {
            $errors['slug'] = 'Slug je již použit pro tento typ obsahu.';
        }

        return $errors;
    }

    private function slugify(string $text): string
    {
        $text = strtolower(trim($text));
        $text = preg_replace('~[^\pL\d]+~u', '-', $text);
        $text = trim($text, '-');
        return $text ?? '';
    }

    private function slugExists(string $slug, string $type, int $ignoreId): bool
    {
        $query = ' slug = ? AND type = ? ';
        $params = [$slug, $type];

        if ($ignoreId > 0) {
            $query .= ' AND id != ? ';
            $params[] = $ignoreId;
        }

        return (bool) R::findOne('content', $query, $params);
    }

    private function findContent($id)
    {
        $item = R::load('content', (int) $id);
        return $item && $item->id ? $item : null;
    }
}
