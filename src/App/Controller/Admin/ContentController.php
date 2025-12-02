<?php
namespace App\Controller\Admin;

use App\Service\Auth;
use App\Service\ContentType;
use App\Service\Flash;
use App\Service\Slugger;
use RedBeanPHP\R as R;
use App\Service\Upload;
use App\Service\TermType;

class ContentController extends AjaxController
{
    public function index($slug)
    {
        Auth::requireRole(['admin', 'editor']);

        $this->ensureTrashColumn();

        [$typeKey, $definition] = $this->resolveType($slug);

        $statusFilter = trim($_GET['status'] ?? 'all');
        $allowedStatuses = ['published', 'draft', 'trash', 'all'];
        $status = in_array($statusFilter, $allowedStatuses, true) ? $statusFilter : 'all';

        $query = ' type = ? ';
        $params = [$typeKey];

        if ($status === 'trash') {
            $query .= ' AND deleted_at IS NOT NULL ';
        } else {
            $query .= ' AND deleted_at IS NULL ';
            if (in_array($status, ['published', 'draft'], true)) {
                $query .= ' AND status = ? ';
                $params[] = $status;
            }
        }

        $total = R::count('content', $query, $params);
        $pagination = $this->buildPagination((int) $total, 12);

        $items = R::findAll(
            'content',
            $query . ' ORDER BY updated_at DESC LIMIT ? OFFSET ? ',
            array_merge($params, [$pagination['per_page'], $pagination['offset']])
        );

        if ($this->respondAjax('admin/content/_list.twig', $this->prepareContentAjaxPayload($items, [
            'types' => ContentType::all(),
            'current_type' => $definition,
            'current_status' => $status ?? 'all',
            'pagination' => $pagination,
        ]), $pagination['current_url'])) {
            return;
        }

        $this->render('admin/content/index.twig', [
            'items' => $items,
            'types' => ContentType::all(),
            'current_menu' => $definition['slug'],
            'current_type' => $definition,
            'all_type_definitions' => ContentType::definitions(),
            'current_status' => $status,
            'pagination' => $pagination,
        ]);
    }

    private function prepareContentAjaxPayload(array $items, array $context): array
    {
        $settings = $this->baseContext(false)['settings'];
        $format = ($settings['date_format'] ?? 'd/m/Y') . ' ' . ($settings['time_format'] ?? 'H:i');

        $serializedItems = [];
        foreach ($items as $item) {
            $serializedItems[] = [
                'id' => (int) $item->id,
                'title' => $item->title,
                'slug' => $item->slug,
                'status' => $item->status,
                'updated_at' => $item->updated_at,
                'updated_at_formatted' => $item->updated_at ? date($format, strtotime($item->updated_at)) : null,
            ];
        }

        $context['items'] = $serializedItems;

        return $context;
    }

    private function serializeContent($content): array
    {
        $author = $this->findAuthor((int) ($content->author_id ?? 0));

        return [
            'id' => (int) $content->id,
            'title' => $content->title,
            'slug' => $content->slug,
            'type' => $content->type,
            'author_id' => $content->author_id ? (int) $content->author_id : null,
            'author' => $author,
            'status' => $content->status,
            'body' => $content->body,
            'thumbnail_id' => $content->thumbnail_id ? (int) $content->thumbnail_id : null,
            'media_ids' => $this->loadMediaIdsForContent((int) $content->id),
            'created_at' => $content->created_at,
            'updated_at' => $content->updated_at,
            'terms' => $this->loadTermIdsForContent((int) $content->id),
            'admin_url' => '/admin/content/' . ContentType::slug($content->type) . '/' . $content->id . '/edit',
        ];
    }

    private function newContentHeading(array $definition): string
    {
        return sprintf('Nový %s', $this->typeLabelLowercase($definition));
    }

    private function editContentHeading(array $definition): string
    {
        return sprintf('Upravit %s', $this->typeLabelLowercase($definition));
    }

    private function typeLabelLowercase(array $definition): string
    {
        $label = $definition['name'] ?? $definition['key'] ?? 'obsah';

        return mb_strtolower($label, 'UTF-8');
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
                'media_ids' => [],
            ],
            'errors' => [],
            'heading' => $this->newContentHeading($definition),
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
            if ($this->wantsJson()) {
                $this->jsonResponse([
                    'success' => false,
                    'errors' => $errors,
                ], 422);
            }

            $this->render('admin/content/form.twig', [
                'values' => $data,
                'errors' => $errors,
                'heading' => $this->newContentHeading($definition),
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
        $bean->allow_comments = $data['allow_comments'];
        $bean->body = $data['body'];
        $bean->thumbnail_id = $data['thumbnail_id'] ?: null;
        $bean->thumbnail_alt = null;
        $currentUser = Auth::user();
        $bean->author_id = $currentUser ? (int) $currentUser->id : null;
        $bean->created_at = date('Y-m-d H:i:s');
        $bean->updated_at = date('Y-m-d H:i:s');
        R::store($bean);

        $this->syncTerms((int) $bean->id, $data['terms']);
        $this->syncMediaAttachments((int) $bean->id, $data['media_ids'], $data['thumbnail_id'] ?: null);

        // URL na editaci právě vytvořeného obsahu
        $editUrl = '/admin/content/' . ContentType::slug($bean->type) . '/' . $bean->id . '/edit'; // ← změna

        if ($this->wantsJson()) {
            $this->respondApi(
                $this->serializeContent($bean),
                'Obsah byl vytvořen.',
                201,
                ['redirect_to' => $editUrl] // ← změna
            );
        }

        Flash::addSuccess('Obsah byl vytvořen.');
        header('Location: ' . $editUrl); // ← změna
        exit;
    }

    public function editForm($slug, $id)
    {
        Auth::requireRole(['admin', 'editor']);

        $content = $this->findContent($id);
        if (!$content) {
            if ($this->wantsJson()) {
                $this->jsonError('Obsah nebyl nalezen.', 404);
            }

            Flash::addError('Obsah nebyl nalezen.');
            header('Location: /admin/content');
            exit;
        }

        $definitions = ContentType::definitions();
        $definition = $definitions[$content->type] ?? ['slug' => $slug, 'name' => ContentType::label($content->type)];
        $menuSlug = $definition['slug'] ?? $slug;
        $allowedTermTypes = $this->allowedTermTypes($content->type);
        $viewUrl = $content->slug ? '/' . ContentType::slug($content->type) . '/' . $content->slug : null;

        $this->render('admin/content/form.twig', [
            'values' => [
                'title' => $content->title,
                'slug'  => $content->slug,
                'body'  => $content->body,
                'status' => $content->status,
                'allow_comments' => (string) $content->allow_comments,
                'thumbnail_id' => $content->thumbnail_id,
                'media_ids' => $this->loadMediaIdsForContent((int) $content->id),
            ],
            'errors' => [],
            'heading' => $this->editContentHeading($definition),
            'form_action' => "/admin/content/{$menuSlug}/{$content->id}/edit",
            'current_menu' => $menuSlug,
            'content_id' => $content->id,
            'media' => $this->mediaList(),
            'terms' => $this->termsByType($content->type, array_keys($allowedTermTypes)),
            'selected_terms' => $this->loadTermIdsForContent((int) $content->id),
            'term_types' => TermType::definitions(),
            'current_type' => $definition,
            'allowed_term_types' => $allowedTermTypes,
            'view_url' => $viewUrl,
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
            if ($this->wantsJson()) {
                $this->jsonResponse([
                    'success' => false,
                    'errors' => $errors,
                ], 422);
            }

            $this->render('admin/content/form.twig', [
                'values' => $data,
                'errors' => $errors,
                'heading' => $this->editContentHeading($definition),
                'form_action' => "/admin/content/{$menuSlug}/{$content->id}/edit",
                'current_menu' => $menuSlug,
                'content_id' => $content->id,
                'media' => $this->mediaList(),
                'terms' => $this->termsByType($data['type'] ?: $content->type, array_keys($this->allowedTermTypes($content->type))),
                'selected_terms' => $data['terms'],
                'term_types' => TermType::definitions(),
                'current_type' => $definition,
                'allowed_term_types' => $this->allowedTermTypes($content->type),
                'view_url' => $data['slug'] !== '' ? '/' . ContentType::slug($content->type) . '/' . $data['slug'] : null,
            ]);
            return;
        }

        $content->title = $data['title'];
        $content->slug = $data['slug'];
        $content->type = $data['type'];
        $content->status = $data['status'] ?: 'draft';
        $content->allow_comments = $data['allow_comments'];
        $content->body = $data['body'];
        $content->thumbnail_id = $data['thumbnail_id'] ?: null;
        $content->thumbnail_alt = null;
        $currentUser = Auth::user();
        $content->author_id = $content->author_id ?: ($currentUser ? (int) $currentUser->id : null);
        $content->updated_at = date('Y-m-d H:i:s');
        R::store($content);

        $this->syncTerms((int) $content->id, $data['terms']);
        $this->syncMediaAttachments((int) $content->id, $data['media_ids'], $data['thumbnail_id'] ?: null);

        // URL na editaci právě uloženého obsahu
        $editUrl = '/admin/content/' . ContentType::slug($content->type) . '/' . $content->id . '/edit'; // ← změna

        if ($this->wantsJson()) {
            $this->respondApi(
                $this->serializeContent($content),
                'Obsah byl upraven.',
                200,
                ['redirect_to' => $editUrl] // ← změna
            );
        }

        Flash::addSuccess('Obsah byl upraven.');
        header('Location: ' . $editUrl); // ← změna
        exit;
    }

    public function delete($slug, $id)
    {
        Auth::requireRole(['admin', 'editor']);

        $this->ensureTrashColumn();

        $content = $this->findContent($id, true);
        if (!$content) {
            if ($this->wantsJson()) {
                $this->jsonError('Obsah nebyl nalezen.', 404);
            }

            Flash::addError('Obsah nebyl nalezen.');
            header('Location: /admin/content');
            exit;
        }

        $redirect = $_POST['redirect'] ?? null;

        if ($content->deleted_at !== null) {
            $this->forceDeleteContent($content);
            $message = 'Obsah byl nenávratně smazán.';
        } else {
            $content->deleted_at = date('Y-m-d H:i:s');
            R::store($content);
            $message = 'Obsah byl přesunut do koše.';
        }

        if ($this->wantsJson()) {
            $this->respondApi([], $message, 200, [
                'redirect_to' => $this->redirectToList($content->type, $redirect),
            ]);
        }

        Flash::addSuccess($message);
        header('Location: ' . $this->redirectToList($content->type, $redirect));
        exit;
    }

    public function emptyTrash($slug): void
    {
        Auth::requireRole(['admin', 'editor']);

        $this->ensureTrashColumn();
        [$typeKey, $definition] = $this->resolveType($slug);

        $trashed = R::findAll('content', ' type = ? AND deleted_at IS NOT NULL ', [$typeKey]);

        foreach ($trashed as $item) {
            $this->forceDeleteContent($item);
        }

        Flash::addSuccess('Koš byl vysypán.');
        header('Location: /admin/content/' . $definition['slug'] . '?status=trash');
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
            'media_ids' => $this->filterIds($_POST['media_ids'] ?? []),
            'terms' => $this->extractTermIds($_POST['terms'] ?? []),
            'allow_comments' => isset($_POST['allow_comments']) ? '1' : '0',
        ];
    }

    private function filterIds($input): array
    {
        if (!is_array($input)) {
            return [];
        }

        $ids = [];
        foreach ($input as $value) {
            $id = (int) $value;
            if ($id > 0) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
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

    private function findContent($id, bool $withTrashed = false)
    {
        $item = R::load('content', (int) $id);
        if (!$item || !$item->id) {
            return null;
        }

        if ($withTrashed) {
            return $item;
        }

        return $item->deleted_at === null ? $item : null;
    }

    private function findAuthor(int $userId): ?array
    {
        if ($userId <= 0) {
            return null;
        }

        $user = R::load('user', $userId);
        if (!$user || !$user->id) {
            return null;
        }

        return [
            'id' => (int) $user->id,
            'email' => $user->email,
            'nickname' => $user->nickname ?: $user->email,
        ];
    }

    private function loadMediaIdsForContent(int $contentId): array
    {
        return array_map('intval', R::getCol(
            'SELECT media_id FROM content_media WHERE content_id = ? AND relation = ? ORDER BY id DESC',
            [$contentId, 'body']
        ));
    }

    private function mediaList(): array
    {
        return R::findAll('media', ' ORDER BY created_at DESC LIMIT 100 ');
    }

    private function syncMediaAttachments(int $contentId, array $mediaIds, ?int $thumbnailId = null): void
    {
        $normalizedBodyIds = [];
        foreach ($mediaIds as $id) {
            $intId = (int) $id;
            if ($intId > 0 && $intId !== (int) $thumbnailId) {
                $normalizedBodyIds[] = $intId;
            }
        }

        $uniqueBodyIds = array_values(array_unique($normalizedBodyIds));

        R::exec('DELETE FROM content_media WHERE content_id = ? AND relation = ?', [$contentId, 'body']);
        foreach ($uniqueBodyIds as $id) {
            R::exec(
                'INSERT IGNORE INTO content_media (content_id, media_id, relation, created_at) VALUES (?, ?, ?, ?)',
                [$contentId, $id, 'body', date('Y-m-d H:i:s')]
            );
        }

        R::exec('DELETE FROM content_media WHERE content_id = ? AND relation = ?', [$contentId, 'thumbnail']);
        if ($thumbnailId) {
            R::exec(
                'INSERT IGNORE INTO content_media (content_id, media_id, relation, created_at) VALUES (?, ?, ?, ?)',
                [$contentId, (int) $thumbnailId, 'thumbnail', date('Y-m-d H:i:s')]
            );
        }
    }

    private function handleThumbnailUpload(array $data): array
    {
        if (!isset($_FILES['thumbnail_upload']) || !$_FILES['thumbnail_upload']['tmp_name']) {
            return [$data, null];
        }

        $currentUser = Auth::user();
        [$media, $error] = Upload::handle(
            $_FILES['thumbnail_upload'],
            'images',
            $currentUser ? (int) $currentUser->id : null
        );
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

    private function redirectToList(string $type, ?string $redirect): string
    {
        $default = '/admin/content/' . ContentType::slug($type);

        if ($redirect) {
            $parsed = parse_url($redirect);
            $path = $parsed['path'] ?? '';
            if (strpos($path, '/admin/content') === 0) {
                $query = isset($parsed['query']) ? '?' . $parsed['query'] : '';
                return $path . $query;
            }
        }

        return $default;
    }

    private function ensureTrashColumn(): void
    {
        $hasColumn = R::getCell(
            'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = ? AND COLUMN_NAME = ? AND TABLE_SCHEMA = DATABASE()',
            ['content', 'deleted_at']
        );

        if ((int) $hasColumn === 0) {
            R::exec('ALTER TABLE `content` ADD COLUMN `deleted_at` DATETIME DEFAULT NULL');
        }
    }

    private function forceDeleteContent($content): void
    {
        R::exec('DELETE FROM content_term WHERE content_id = ?', [(int) $content->id]);
        R::exec('DELETE FROM content_media WHERE content_id = ?', [(int) $content->id]);
        R::trash($content);
    }
}
