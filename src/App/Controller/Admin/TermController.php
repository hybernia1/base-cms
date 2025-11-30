<?php
namespace App\Controller\Admin;

use App\Service\Auth;
use App\Service\Flash;
use App\Service\TermType;
use RedBeanPHP\R as R;

class TermController extends BaseAdminController
{
    public function index()
    {
        Auth::requireRole(['admin', 'editor']);

        $items = R::findAll('term', ' ORDER BY updated_at DESC ');

        $this->render('admin/terms/index.twig', [
            'items' => $items,
            'types' => TermType::all(),
            'current_menu' => 'terms',
        ]);
    }

    public function createForm()
    {
        Auth::requireRole(['admin', 'editor']);

        $this->render('admin/terms/form.twig', [
            'types' => TermType::all(),
            'values' => [
                'name' => '',
                'slug' => '',
                'type' => 'tag',
                'description' => '',
            ],
            'errors' => [],
            'heading' => 'Nový term',
            'form_action' => '/admin/terms/create',
            'current_menu' => 'terms',
        ]);
    }

    public function create()
    {
        Auth::requireRole(['admin', 'editor']);

        $data = $this->sanitizeInput();
        $errors = $this->validate($data);

        if ($errors) {
            $this->render('admin/terms/form.twig', [
                'types' => TermType::all(),
                'values' => $data,
                'errors' => $errors,
                'heading' => 'Nový term',
                'form_action' => '/admin/terms/create',
                'current_menu' => 'terms',
            ]);
            return;
        }

        $term = R::dispense('term');
        $term->name = $data['name'];
        $term->slug = $data['slug'];
        $term->type = $data['type'];
        $term->description = $data['description'];
        $term->created_at = date('Y-m-d H:i:s');
        $term->updated_at = date('Y-m-d H:i:s');
        R::store($term);

        Flash::addSuccess('Term byl vytvořen.');
        header('Location: /admin/terms');
        exit;
    }

    public function editForm($id)
    {
        Auth::requireRole(['admin', 'editor']);

        $term = $this->findTerm($id);
        if (!$term) {
            Flash::addError('Term nebyl nalezen.');
            header('Location: /admin/terms');
            exit;
        }

        $this->render('admin/terms/form.twig', [
            'types' => TermType::all(),
            'values' => [
                'name' => $term->name,
                'slug' => $term->slug,
                'type' => $term->type,
                'description' => $term->description,
            ],
            'errors' => [],
            'heading' => 'Upravit term',
            'form_action' => "/admin/terms/{$term->id}/edit",
            'current_menu' => 'terms',
            'term_id' => $term->id,
        ]);
    }

    public function update($id)
    {
        Auth::requireRole(['admin', 'editor']);

        $term = $this->findTerm($id);
        if (!$term) {
            Flash::addError('Term nebyl nalezen.');
            header('Location: /admin/terms');
            exit;
        }

        $data = $this->sanitizeInput();
        $errors = $this->validate($data, (int) $term->id);

        if ($errors) {
            $this->render('admin/terms/form.twig', [
                'types' => TermType::all(),
                'values' => $data,
                'errors' => $errors,
                'heading' => 'Upravit term',
                'form_action' => "/admin/terms/{$term->id}/edit",
                'current_menu' => 'terms',
                'term_id' => $term->id,
            ]);
            return;
        }

        $term->name = $data['name'];
        $term->slug = $data['slug'];
        $term->type = $data['type'];
        $term->description = $data['description'];
        $term->updated_at = date('Y-m-d H:i:s');
        R::store($term);

        Flash::addSuccess('Term byl upraven.');
        header('Location: /admin/terms');
        exit;
    }

    public function delete($id)
    {
        Auth::requireRole(['admin', 'editor']);

        $term = $this->findTerm($id);
        if (!$term) {
            Flash::addError('Term nebyl nalezen.');
            header('Location: /admin/terms');
            exit;
        }

        R::trash($term);
        Flash::addSuccess('Term byl smazán.');
        header('Location: /admin/terms');
        exit;
    }

    private function sanitizeInput(): array
    {
        return [
            'name' => trim($_POST['name'] ?? ''),
            'slug' => trim($_POST['slug'] ?? ''),
            'type' => trim($_POST['type'] ?? ''),
            'description' => trim($_POST['description'] ?? ''),
        ];
    }

    private function validate(array &$data, int $ignoreId = 0): array
    {
        $errors = [];

        if ($data['name'] === '') {
            $errors['name'] = 'Název je povinný.';
        }

        if ($data['type'] === '' || !TermType::exists($data['type'])) {
            $errors['type'] = 'Vyber platný typ.';
        }

        if ($data['slug'] === '') {
            $data['slug'] = $this->slugify($data['name']);
        }

        if ($data['slug'] === '') {
            $errors['slug'] = 'Slug musí být vyplněn.';
        } elseif ($this->slugExists($data['slug'], $data['type'], $ignoreId)) {
            $errors['slug'] = 'Slug je již použit pro tento typ.';
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

        return (bool) R::findOne('term', $query, $params);
    }

    private function findTerm($id)
    {
        $term = R::load('term', (int) $id);
        return $term && $term->id ? $term : null;
    }
}
