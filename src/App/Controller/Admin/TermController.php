<?php
namespace App\Controller\Admin;

use App\Service\Auth;
use App\Service\Flash;
use App\Service\TermType;
use App\Service\ContentType;
use App\Service\Slugger;
use RedBeanPHP\R as R;

class TermController extends BaseAdminController
{
    public function index()
    {
        $this->renderIndex();
    }

    public function indexByType($typeKey)
    {
        $definitions = TermType::definitions();
        $typeKey = trim((string) $typeKey);

        if (!isset($definitions[$typeKey])) {
            Flash::addError('Zvolený typ termů nebyl nalezen.');
            header('Location: /admin/terms');
            exit;
        }

        $this->renderIndex($typeKey);
    }

    public function createForm()
    {
        Auth::requireRole(['admin', 'editor']);

        $preferredType = trim($_GET['type'] ?? '');
        $definitions = TermType::definitions();
        if ($preferredType === '' || !isset($definitions[$preferredType])) {
            $preferredType = array_key_first($definitions) ?: 'tag';
        }

        $this->render('admin/terms/form.twig', [
            'types' => TermType::all(),
            'values' => [
                'name' => '',
                'slug' => '',
                'type' => $preferredType,
                'description' => '',
            ],
            'errors' => [],
            'heading' => 'Nový term',
            'form_action' => '/admin/terms/create',
            'current_menu' => 'terms:' . $preferredType,
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
                'current_menu' => 'terms:' . $data['type'],
            ]);
            return;
        }

        $term = R::dispense('term');
        $term->name = $data['name'];
        $term->slug = $data['slug'];
        $term->type = $data['type'];
        $term->description = $data['description'];
        $term->content_types = null;
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
            'current_menu' => 'terms:' . $term->type,
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
                'current_menu' => 'terms:' . $term->type,
                'term_id' => $term->id,
            ]);
            return;
        }

        $term->name = $data['name'];
        $term->slug = $data['slug'];
        $term->type = $data['type'];
        $term->description = $data['description'];
        $term->content_types = null;
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

    private function renderIndex(?string $typeFilter = null): void
    {
        Auth::requireRole(['admin', 'editor']);

        $termTypeDefinitions = TermType::definitions();
        $contentTypeDefinitions = ContentType::definitions();
        $query = ' ORDER BY updated_at DESC ';
        $params = [];

        if ($typeFilter !== null) {
            $query = ' type = ? ORDER BY updated_at DESC ';
            $params[] = $typeFilter;
        }

        $items = R::findAll('term', $query, $params);
        foreach ($items as $item) {
            $item->allowed_for = $termTypeDefinitions[$item->type]['content_types'] ?? [];
        }

        $this->render('admin/terms/index.twig', [
            'items' => $items,
            'types' => TermType::all(),
            'content_types' => $contentTypeDefinitions,
            'current_menu' => $typeFilter ? 'terms:' . $typeFilter : 'terms',
            'current_term_type' => $typeFilter ? ($termTypeDefinitions[$typeFilter] ?? null) : null,
        ]);
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

        $slugSource = $data['slug'] !== '' ? $data['slug'] : $data['name'];
        $data['slug'] = Slugger::slugify($slugSource);

        if ($data['slug'] === '') {
            $errors['slug'] = 'Slug musí být vyplněn.';
        } elseif ($data['type'] !== '' && empty($errors['type'])) {
            $data['slug'] = Slugger::uniqueForTerm($data['slug'], $data['type'], $ignoreId);
        }

        return $errors;
    }

    private function findTerm($id)
    {
        $term = R::load('term', (int) $id);
        return $term && $term->id ? $term : null;
    }
}
