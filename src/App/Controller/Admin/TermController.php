<?php
namespace App\Controller\Admin;

use App\Service\Auth;
use App\Service\Flash;
use App\Service\TermType;
use App\Service\ContentType;
use App\Service\Slugger;
use RedBeanPHP\R as R;

class TermController extends AjaxController
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
        $typeLocked = $preferredType !== '' && isset($definitions[$preferredType]);

        if ($preferredType === '' || !isset($definitions[$preferredType])) {
            $preferredType = array_key_first($definitions) ?: 'tag';
        }

        $this->render('admin/terms/form.twig', [
            'types' => TermType::all(),
            'type_locked' => $typeLocked,
            'type_label' => $definitions[$preferredType]['label'] ?? $preferredType,
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
        header('Location: ' . $this->redirectToList($term->type, $_POST['redirect'] ?? null));
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

        $definitions = TermType::definitions();

        $this->render('admin/terms/form.twig', [
            'types' => TermType::all(),
            'type_locked' => true,
            'type_label' => $definitions[$term->type]['label'] ?? $term->type,
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

        $data = $this->sanitizeInput($term->type);
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
        header('Location: ' . $this->redirectToList($term->type, $_POST['redirect'] ?? null));
        exit;
    }

    public function delete($id)
    {
        Auth::requireRole(['admin', 'editor']);

        $term = $this->findTerm($id);
        if (!$term) {
            if ($this->wantsJson()) {
                $this->jsonError('Term nebyl nalezen.', 404);
            }

            Flash::addError('Term nebyl nalezen.');
            header('Location: /admin/terms');
            exit;
        }

        R::trash($term);

        if ($this->wantsJson()) {
            $this->respondAjaxMessage('Term byl smazán.', ['success' => true]);
        }

        Flash::addSuccess('Term byl smazán.');
        header('Location: ' . $this->redirectToList($term->type, $_POST['redirect'] ?? null));
        exit;
    }

    private function renderIndex(?string $typeFilter = null): void
    {
        Auth::requireRole(['admin', 'editor']);

        $termTypeDefinitions = TermType::definitions();
        $contentTypeDefinitions = ContentType::definitions();
        $query = ' 1 = 1 ';
        $params = [];

        if ($typeFilter !== null) {
            $query .= ' AND type = ? ';
            $params[] = $typeFilter;
        }

        $total = R::count('term', $query, $params);
        $pagination = $this->buildPagination((int) $total, 15);

        $items = R::findAll(
            'term',
            $query . ' ORDER BY updated_at DESC LIMIT ? OFFSET ? ',
            array_merge($params, [$pagination['per_page'], $pagination['offset']])
        );
        foreach ($items as $item) {
            $item->allowed_for = $termTypeDefinitions[$item->type]['content_types'] ?? [];
        }

        if ($this->respondAjax('admin/terms/_list.twig', $this->prepareTermsAjaxPayload($items, [
            'types' => TermType::all(),
            'content_types' => $contentTypeDefinitions,
            'current_term_type' => $typeFilter ? ($termTypeDefinitions[$typeFilter] ?? null) : null,
            'pagination' => $pagination,
        ]), $pagination['current_url'])) {
            return;
        }

        $this->render('admin/terms/index.twig', [
            'items' => $items,
            'types' => TermType::all(),
            'content_types' => $contentTypeDefinitions,
            'current_menu' => $typeFilter ? 'terms:' . $typeFilter : 'terms',
            'current_term_type' => $typeFilter ? ($termTypeDefinitions[$typeFilter] ?? null) : null,
            'pagination' => $pagination,
        ]);
    }

    private function prepareTermsAjaxPayload(array $items, array $context): array
    {
        $settings = $this->baseContext(false)['settings'];
        $format = ($settings['date_format'] ?? 'd/m/Y') . ' ' . ($settings['time_format'] ?? 'H:i');

        $serializedItems = [];
        foreach ($items as $item) {
            $serializedItems[] = [
                'id' => (int) $item->id,
                'name' => $item->name,
                'slug' => $item->slug,
                'type' => $item->type,
                'allowed_for' => $item->allowed_for ?? [],
                'updated_at' => $item->updated_at,
                'updated_at_formatted' => $item->updated_at ? date($format, strtotime($item->updated_at)) : null,
            ];
        }

        $context['items'] = $serializedItems;

        return $context;
    }

    private function sanitizeInput(?string $forcedType = null): array
    {
        return [
            'name' => trim($_POST['name'] ?? ''),
            'slug' => trim($_POST['slug'] ?? ''),
            'type' => $forcedType !== null ? $forcedType : trim($_POST['type'] ?? ''),
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

        if ($data['name'] !== '' && empty($errors['type'])) {
            $existingName = R::findOne(
                'term',
                ' name = ? AND type = ? AND id <> ? ',
                [$data['name'], $data['type'], $ignoreId]
            );

            if ($existingName) {
                $errors['name'] = 'Term se stejným názvem už v tomto typu existuje. Použij ho místo vytváření duplicit.';
            }
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

    private function redirectToList(string $type, ?string $redirect): string
    {
        $default = '/admin/terms/type/' . $type;

        if (!$redirect) {
            return $default;
        }

        $parsed = parse_url($redirect);
        $path = $parsed['path'] ?? '';
        if (strpos($path, '/admin/terms') !== 0) {
            return $default;
        }

        $query = isset($parsed['query']) ? '?' . $parsed['query'] : '';
        return $path . $query;
    }

    private function findTerm($id)
    {
        $term = R::load('term', (int) $id);
        return $term && $term->id ? $term : null;
    }
}
