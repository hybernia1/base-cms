<?php
namespace App\Controller\Admin;

use App\Service\Auth;
use App\Service\ContentType;
use App\Service\Flash;
use App\Service\Slugger;
use RedBeanPHP\R as R;
use App\Service\Upload;
use App\Service\TermType;

class ContentController extends BaseAdminController
{
    public function index($slug)
    {
        Auth::requireRole(['admin', 'editor']);

        [$typeKey, $definition] = $this->resolveType($slug);

        $statusFilter = trim($_GET['status'] ?? '');
        $allowedStatuses = ['published', 'draft'];
        $status = in_array($statusFilter, $allowedStatuses, true) ? $statusFilter : null;

        $query = ' type = ? ';
        $params = [$typeKey];

        if ($status !== null) {
            $query .= ' AND status = ? ';
            $params[] = $status;
        }

        $total = R::count('content', $query, $params);
        $pagination = $this->buildPagination((int) $total, 12);

        $items = R::findAll(
            'content',
            $query . ' ORDER BY updated_at DESC LIMIT ? OFFSET ? ',
            array_merge($params, [$pagination['per_page'], $pagination['offset']])
        );

        $this->render('admin/content/index.twig', [
            'items' => $items,
            'types' => ContentType::all(),
            'current_menu' => $definition['slug'],
            'current_type' => $definition,
            'all_type_definitions' => ContentType::definitions(),
            'current_status' => $status ?? 'all',
            'pagination' => $pagination,
        ]);
    }

    public function createForm($slug)
    {
        Auth::requireRole(['admin', 'editor']);

        [$typeKey, $definition] = $this->resolveType($slug);
        $allowedTermTypes = $this->allowedTermTypes($typeKey);

        $this->render('admin/content/form.twig', [
            'values' => [
                'title' => '',
                'slug'  => '',
                'body'  => '',
                'status' => 'draft',
                'thumbnail_id' => '',
            ],
            'errors' => [],
            'heading' => 'Nový obsah',
            'form_action' => '/admin/content/' . $definition['slug'] . '/create',
            'current_menu' => $definition['slug'],
            'media' => $this->mediaList(),
            'terms' => $this->termsByType($typeKey, array_keys($allowedTermTypes)),
            'selected_terms' => [],
            'term_types' => TermType::definitions(),
            'current_type' => $definition,
            'allowed_term_types' => $allowedTermTypes,
        ]);
    }

    public function create($slug)
    {
        Auth::requireRole(['admin', 'editor']);

        [$typeKey, $definition] = $this->resolveType($slug);
        $data = $this->sanitizeInput($typeKey);

        [$data, $uploadError] = $this->handleThumbnailUpload($data);
        $errors = $this->validate($data);
        if ($uploadError) {
            $errors['thumbnail'] = $uploadError;
        }

        if ($errors) {
            $this->render('admin/content/form.twig', [
                'values' => $data,
                'errors' => $errors,
                'heading' => 'Nový obsah',
                'form_action' => '/admin/content/' . $definition['slug'] . '/create',
                'current_menu' => $definition['slug'],
                'media' => $this->mediaList(),
                'terms' => $this->termsByType($data['type'] ?: $typeKey, array_keys($this->allowedTermTypes($typeKey))),
                'selected_terms' => $data['terms'],
                'term_types' => TermType::definitions(),
                'current_type' => $definition,
                'allowed_term_types' => $this->allowedTermTypes($typeKey),
            ]);
            return;
        }

        $bean = R::dispense('content');
        $bean->title = $data['title'];
        $bean->slug = $data['slug'];
        $bean->type = $data['type'];
        $bean->status = $data['status'] ?: 'draft';
        $bean->body = $data['body'];
        $bean->thumbnail_id = $data['thumbnail_id'] ?: null;
        $bean->thumbnail_alt = null;
        $bean->created_at = date('Y-m-d H:i:s');
        $bean->updated_at = date('Y-m-d H:i:s');
        R::store($bean);

        $this->syncTerms((int) $bean->id, $data['terms']);

        Flash::addSuccess('Obsah byl vytvořen.');
        header('Location: /admin/content/' . ContentType::slug($bean->type));
        exit;
    }

    public function editForm($slug, $id)
    {
        Auth::requireRole(['admin', 'editor']);

        $content = $this->findContent($id);
        if (!$content) {
            Flash::addError('Obsah nebyl nalezen.');
            header('Location: /admin/content');
            exit;
        }

        $definitions = ContentType::definitions();
        $definition = $definitions[$content->type] ?? ['slug' => $slug, 'name' => ContentType::label($content->type)];
        $menuSlug = $definition['slug'] ?? $slug;
        $allowedTermTypes = $this->allowedTermTypes($content->type);

        $this->render('admin/content/form.twig', [
            'values' => [
                'title' => $content->title,
                'slug'  => $content->slug,
                'body'  => $content->body,
                'status' => $content->status,
                'thumbnail_id' => $content->thumbnail_id,
            ],
            'errors' => [],
            'heading' => 'Upravit obsah',
            'form_action' => "/admin/content/{$menuSlug}/{$content->id}/edit",
            'current_menu' => $menuSlug,
            'content_id' => $content->id,
            'media' => $this->mediaList(),
            'terms' => $this->termsByType($content->type, array_keys($allowedTermTypes)),
            'selected_terms' => $this->loadTermIdsForContent((int) $content->id),
            'term_types' => TermType::definitions(),
            'current_type' => $definition,
            'allowed_term_types' => $allowedTermTypes,
        ]);
    }

    public function update($slug, $id)
    {
        Auth::requireRole(['admin', 'editor']);

        $content = $this->findContent($id);
        if (!$content) {
            Flash::addError('Obsah nebyl nalezen.');
            header('Location: /admin/content');
            exit;
        }

        $definitions = ContentType::definitions();
        $definition = $definitions[$content->type] ?? ['slug' => $slug, 'name' => ContentType::label($content->type)];
        $menuSlug = $definition['slug'] ?? $slug;

        $data = $this->sanitizeInput($content->type);
        [$data, $uploadError] = $this->handleThumbnailUpload($data);
        $errors = $this->validate($data, (int) $content->id);
        if ($uploadError) {
            $errors['thumbnail'] = $uploadError;
        }

        if ($errors) {
            $this->render('admin/content/form.twig', [
                'values' => $data,
                'errors' => $errors,
                'heading' => 'Upravit obsah',
                'form_action' => "/admin/content/{$menuSlug}/{$content->id}/edit",
                'current_menu' => $menuSlug,
                'content_id' => $content->id,
                'media' => $this->mediaList(),
                'terms' => $this->termsByType($data['type'] ?: $content->type, array_keys($this->allowedTermTypes($content->type))),
                'selected_terms' => $data['terms'],
                'term_types' => TermType::definitions(),
                'current_type' => $definition,
                'allowed_term_types' => $this->allowedTermTypes($content->type),
            ]);
            return;
        }

        $content->title = $data['title'];
        $content->slug = $data['slug'];
        $content->type = $data['type'];
        $content->status = $data['status'] ?: 'draft';
        $content->body = $data['body'];
        $content->thumbnail_id = $data['thumbnail_id'] ?: null;
        $content->thumbnail_alt = null;
        $content->updated_at = date('Y-m-d H:i:s');
        R::store($content);

        $this->syncTerms((int) $content->id, $data['terms']);

        Flash::addSuccess('Obsah byl upraven.');
        header('Location: /admin/content/' . ContentType::slug($content->type));
        exit;
    }

    public function delete($slug, $id)
    {
        Auth::requireRole(['admin', 'editor']);

        $content = $this->findContent($id);
        if (!$content) {
            Flash::addError('Obsah nebyl nalezen.');
            header('Location: /admin/content');
            exit;
        }

        R::exec('DELETE FROM content_term WHERE content_id = ?', [(int) $content->id]);
        R::trash($content);
        Flash::addSuccess('Obsah byl smazán.');
        header('Location: /admin/content/' . ContentType::slug($content->type));
        exit;
    }

    public function createTerm($slug)
    {
        Auth::requireRole(['admin', 'editor']);

        [$typeKey, $definition] = $this->resolveType($slug);
        $allowedTypes = $this->allowedTermTypes($typeKey);

        if (!$allowedTypes) {
            return $this->jsonError('Pro tento typ obsahu nejsou povolené žádné termy.');
        }

        $name = trim($_POST['name'] ?? '');
        $termType = trim($_POST['type'] ?? '');

        if ($name === '') {
            return $this->jsonError('Název je povinný.');
        }

        if ($termType === '' || !isset($allowedTypes[$termType])) {
            return $this->jsonError('Vyber platný typ termu.');
        }

        $slugValue = Slugger::slugify($name);

        if ($slugValue === '') {
            return $this->jsonError('Název je neplatný.');
        }

        $existing = R::findOne(
            'term',
            ' (slug = ? OR name = ?) AND type = ? ',
            [$slugValue, $name, $termType]
        );

        if ($existing) {
            header('Content-Type: application/json');
            echo json_encode([
                'id' => $existing->id,
                'name' => $existing->name,
                'type' => $existing->type,
                'type_label' => $allowedTypes[$existing->type]['label'] ?? $existing->type,
                'existing' => true,
            ]);
            exit;
        }

        $slugValue = Slugger::uniqueForTerm($slugValue, $termType);

        $term = R::dispense('term');
        $term->name = $name;
        $term->slug = $slugValue;
        $term->type = $termType;
        $term->description = '';
        $term->content_types = null;
        $term->created_at = date('Y-m-d H:i:s');
        $term->updated_at = date('Y-m-d H:i:s');
        R::store($term);

        header('Content-Type: application/json');
        echo json_encode([
            'id' => $term->id,
            'name' => $term->name,
            'type' => $term->type,
            'type_label' => $allowedTypes[$term->type]['label'] ?? $term->type,
        ]);
        exit;
    }

    private function sanitizeInput(string $type): array
    {
        return [
            'title' => trim($_POST['title'] ?? ''),
            'slug'  => trim($_POST['slug'] ?? ''),
            'type'  => $type,
            'status' => trim($_POST['status'] ?? 'draft'),
            'body'  => trim($_POST['body'] ?? ''),
            'thumbnail_id' => (int) ($_POST['thumbnail_id'] ?? 0),
            'terms' => $this->extractTermIds($_POST['terms'] ?? []),
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

        $slugSource = $data['slug'] !== '' ? $data['slug'] : $data['title'];
        $data['slug'] = Slugger::slugify($slugSource);

        if ($data['slug'] === '') {
            $errors['slug'] = 'Slug musí být vyplněn.';
        } elseif ($data['type'] !== '' && empty($errors['type'])) {
            $data['slug'] = Slugger::uniqueForContent($data['slug'], $data['type'], $ignoreId);
        }

        $allowedStatuses = ['draft', 'published'];
        if ($data['status'] === '') {
            $data['status'] = 'draft';
        }

        if (!in_array($data['status'], $allowedStatuses, true)) {
            $errors['status'] = 'Vyber platný stav.';
        }

        if (!empty($data['terms'])) {
            $invalid = $this->validateTerms($data['terms'], $data['type']);
            if ($invalid !== null) {
                $errors['terms'] = $invalid;
            }
        }

        return $errors;
    }

    private function findContent($id)
    {
        $item = R::load('content', (int) $id);
        return $item && $item->id ? $item : null;
    }

    private function mediaList(): array
    {
        return R::findAll('media', ' ORDER BY created_at DESC LIMIT 100 ');
    }

    private function handleThumbnailUpload(array $data): array
    {
        if (!isset($_FILES['thumbnail_upload']) || !$_FILES['thumbnail_upload']['tmp_name']) {
            return [$data, null];
        }

        [$media, $error] = Upload::handle($_FILES['thumbnail_upload']);
        if ($error) {
            Flash::addError($error);
            return [$data, $error];
        }

        $data['thumbnail_id'] = $media->id;
        $media->alt = '';
        R::store($media);

        return [$data, null];
    }

    private function termsByType(string $contentType, array $forcedTypes = []): array
    {
        $definitions = TermType::definitions();
        $allowedTypes = [];

        foreach ($definitions as $key => $definition) {
            if (TermType::allowsContentType($key, $contentType)) {
                $allowedTypes[] = $key;
            }
        }

        foreach ($forcedTypes as $forced) {
            if (!in_array($forced, $allowedTypes, true)) {
                $allowedTypes[] = $forced;
            }
        }

        if (!$allowedTypes) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($allowedTypes), '?'));
        $terms = R::findAll('term', ' type IN (' . $placeholders . ') ORDER BY type, name ', $allowedTypes);
        $grouped = [];

        foreach ($terms as $term) {
            if (!isset($definitions[$term->type])) {
                continue;
            }

            $grouped[$term->type][] = $term;
        }

        foreach ($allowedTypes as $allowed) {
            if (!isset($grouped[$allowed])) {
                $grouped[$allowed] = [];
            }
        }

        return $grouped;
    }

    private function allowedTermTypes(string $contentType): array
    {
        $definitions = TermType::definitions();
        $allowed = [];

        foreach ($definitions as $key => $definition) {
            if (TermType::allowsContentType($key, $contentType)) {
                $allowed[$key] = $definition;
            }
        }

        return $allowed;
    }

    private function extractTermIds($input): array
    {
        $result = [];

        if (!is_array($input)) {
            return $result;
        }

        $iterator = new \RecursiveIteratorIterator(new \RecursiveArrayIterator($input));
        foreach ($iterator as $value) {
            $value = (int) $value;
            if ($value > 0) {
                $result[] = $value;
            }
        }

        return array_values(array_unique($result));
    }

    private function validateTerms(array $ids, string $contentType): ?string
    {
        $terms = $this->fetchTermsByIds($ids);
        if (count($terms) !== count($ids)) {
            return 'Vyber platné termy.';
        }

        foreach ($terms as $term) {
            if (!TermType::exists($term->type)) {
                return 'Vyber platné termy.';
            }

            if (!TermType::allowsContentType($term->type, $contentType)) {
                return 'Term není dostupný pro zvolený typ obsahu.';
            }
        }

        return null;
    }

    private function fetchTermsByIds(array $ids): array
    {
        if (!$ids) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        return R::findAll('term', ' id IN (' . $placeholders . ') ', $ids);
    }

    private function syncTerms(int $contentId, array $termIds): void
    {
        $termIds = array_values(array_unique(array_filter($termIds)));

        if (!$termIds) {
            R::exec('DELETE FROM content_term WHERE content_id = ?', [$contentId]);
            return;
        }

        $placeholders = implode(',', array_fill(0, count($termIds), '?'));
        R::exec(
            'DELETE FROM content_term WHERE content_id = ? AND term_id NOT IN (' . $placeholders . ')',
            array_merge([$contentId], $termIds)
        );

        $existing = R::getCol(
            'SELECT term_id FROM content_term WHERE content_id = ? AND term_id IN (' . $placeholders . ')',
            array_merge([$contentId], $termIds)
        );
        $existing = array_map('intval', $existing);

        foreach ($termIds as $termId) {
            if (in_array($termId, $existing, true)) {
                continue;
            }

            R::exec('INSERT INTO content_term (content_id, term_id) VALUES (?, ?)', [$contentId, $termId]);
        }
    }

    private function loadTermIdsForContent(int $contentId): array
    {
        return array_map('intval', R::getCol('SELECT term_id FROM content_term WHERE content_id = ?', [$contentId]));
    }

    private function resolveType(string $slug): array
    {
        $typeKey = ContentType::keyFromSlug($slug);
        $definitions = ContentType::definitions();

        if (!$typeKey || !isset($definitions[$typeKey])) {
            $first = reset($definitions);
            if (!$first) {
                Flash::addError('Nenalezen žádný typ obsahu.');
                header('Location: /admin');
                exit;
            }

            return [$first['key'], $first];
        }

        return [$typeKey, $definitions[$typeKey]];
    }

    private function jsonError(string $message, int $status = 400)
    {
        header('Content-Type: application/json', true, $status);
        echo json_encode(['error' => $message]);
        exit;
    }
}
