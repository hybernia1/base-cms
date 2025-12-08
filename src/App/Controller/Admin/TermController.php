<?php
namespace App\Controller\Admin;

use App\Service\Auth;
use App\Service\Flash;
use App\Service\TermType;
use App\Service\ContentType;
use App\Service\Slugger;
use App\Service\Meta;
use RedBeanPHP\R as R;

class TermController extends AjaxController
{
    public function index()
    {
        $definitions = TermType::definitions();
        $firstType = array_key_first($definitions);

        if ($firstType === null) {
            Flash::addError('Nebyl nalezen žádný typ termů.');
            header('Location: /admin');
            exit;
        }

        header('Location: /admin/terms/type/' . $firstType);
        exit;
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

        $typeLabel = $definitions[$preferredType]['label'] ?? $preferredType;

        $this->render('admin/terms/form.twig', [
            'types' => TermType::all(),
            'type_locked' => $typeLocked,
            'type_label' => $typeLabel,
            'values' => [
                'name' => '',
                'slug' => '',
                'type' => $preferredType,
                'description' => '',
            ],
            'errors' => [],
            'heading' => 'Nový ' . $typeLabel,
            'form_action' => '/admin/terms/create',
            'current_menu' => 'terms:' . $preferredType,
            'meta_keys' => Meta::allKeys(),
            'meta_values' => [],
        ]);
    }

    public function create()
    {
        Auth::requireRole(['admin', 'editor']);

        $data = $this->sanitizeInput();
        $errors = $this->validate($data);

        $definitions = TermType::definitions();
        $typeLabel = $definitions[$data['type']]['label'] ?? $data['type'];

        if ($errors) {
            $this->render('admin/terms/form.twig', [
                'types' => TermType::all(),
                'values' => $data,
                'errors' => $errors,
                'heading' => 'Nový ' . $typeLabel,
                'form_action' => '/admin/terms/create',
                'current_menu' => 'terms:' . $data['type'],
                'meta_keys' => Meta::allKeys(),
                'meta_values' => $data['meta'],
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

        Meta::saveValues(Meta::TARGET_TERM, (int) $term->id, $data['meta']);

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
        $typeLabel = $definitions[$term->type]['label'] ?? $term->type;
        $metaValues = Meta::valuesFor(Meta::TARGET_TERM, (int) $term->id);

        $this->render('admin/terms/form.twig', [
            'types' => TermType::all(),
            'type_locked' => true,
            'type_label' => $typeLabel,
            'values' => [
                'name' => $term->name,
                'slug' => $term->slug,
                'type' => $term->type,
                'description' => $term->description,
            ],
            'errors' => [],
            'heading' => 'Upravit ' . $typeLabel,
            'form_action' => "/admin/terms/{$term->id}/edit",
            'current_menu' => 'terms:' . $term->type,
            'term_id' => $term->id,
            'meta_keys' => Meta::allKeys(),
            'meta_values' => $metaValues,
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

        $definitions = TermType::definitions();
        $typeLabel = $definitions[$term->type]['label'] ?? $term->type;

        if ($errors) {
            $this->render('admin/terms/form.twig', [
                'types' => TermType::all(),
                'values' => $data,
                'errors' => $errors,
                'heading' => 'Upravit ' . $typeLabel,
                'form_action' => "/admin/terms/{$term->id}/edit",
                'current_menu' => 'terms:' . $term->type,
                'term_id' => $term->id,
                'meta_keys' => Meta::allKeys(),
                'meta_values' => $data['meta'],
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

        Meta::saveValues(Meta::TARGET_TERM, (int) $term->id, $data['meta']);

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

        Meta::deleteForTarget(Meta::TARGET_TERM, (int) $term->id);
        R::trash($term);

        if ($this->wantsJson()) {
            $this->respondAjaxMessage('Term byl smazán.', ['success' => true]);
        }

        Flash::addSuccess('Term byl smazán.');
        header('Location: ' . $this->redirectToList($term->type, $_POST['redirect'] ?? null));
        exit;
    }

    private function renderIndex(string $typeKey): void
    {
        Auth::requireRole(['admin', 'editor']);

        $termTypeDefinitions = TermType::definitions();
        $contentTypeDefinitions = ContentType::definitions();
        $search = trim($_GET['q'] ?? '');
        $query = ' 1 = 1 ';
        $params = [];

        $query .= ' AND type = ? ';
        $params[] = $typeKey;

        if ($search !== '') {
            $query .= ' AND (name LIKE ? OR slug LIKE ?) ';
            $like = '%' . $search . '%';
            $params[] = $like;
            $params[] = $like;
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
            'current_term_type' => $termTypeDefinitions[$typeKey] ?? null,
            'pagination' => $pagination,
            'search' => $search,
        ]), $pagination['current_url'])) {
            return;
        }

        $this->render('admin/terms/index.twig', [
            'items' => $items,
            'types' => TermType::all(),
            'content_types' => $contentTypeDefinitions,
            'current_menu' => 'terms:' . $typeKey,
            'current_term_type' => $termTypeDefinitions[$typeKey] ?? null,
            'pagination' => $pagination,
            'search' => $search,
        ]);
    }

    private function prepareTermsAjaxPayload(array $items, array $context): array
    {
        $serializedItems = [];
        foreach ($items as $item) {
            $serializedItems[] = [
                'id' => (int) $item->id,
                'name' => $item->name,
                'slug' => $item->slug,
                'type' => $item->type,
                'allowed_for' => $item->allowed_for ?? [],
            ];
        }

        $context['items'] = $serializedItems;
        $context['search'] = $context['search'] ?? '';

        return $context;
    }

    private function sanitizeInput(?string $forcedType = null): array
    {
        return [
            'name' => trim($_POST['name'] ?? ''),
            'slug' => trim($_POST['slug'] ?? ''),
            'type' => $forcedType !== null ? $forcedType : trim($_POST['type'] ?? ''),
            'description' => trim($_POST['description'] ?? ''),
            'meta' => Meta::sanitizeValues($_POST['meta'] ?? []),
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
