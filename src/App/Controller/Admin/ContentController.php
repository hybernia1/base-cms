<?php
namespace App\Controller\Admin;

use App\Service\Auth;
use App\Service\ContentType;
use App\Service\Flash;
use App\Service\Slugger;
use App\Service\Comment;
use App\Service\Meta;
use DateTime;
use RedBeanPHP\R as R;
use App\Service\Upload;
use App\Service\TermType;
use App\Service\Shortcode;

class ContentController extends AjaxController
{
    private const SCHEMA_TYPES = [
        'Article' => 'Obecný článek (Article)',
        'NewsArticle' => 'Zpravodajský článek (NewsArticle)',
        'BlogPosting' => 'Blogový příspěvek (BlogPosting)',
        'WebPage' => 'Webová stránka (WebPage)',
    ];

    public function index($slug)
    {
        Auth::requireRole(['admin', 'editor']);

        [$typeKey] = $this->resolveType($slug);

        $statusFilter = trim($_GET['status'] ?? 'all');
        $allowedStatuses = ['published', 'draft', 'trash', 'all'];
        $status = in_array($statusFilter, $allowedStatuses, true) ? $statusFilter : 'all';

        $search = trim($_GET['q'] ?? '');
        $counts = $this->contentStatusCounts($typeKey);

        $query = ' type = ? ';
        $params = [$typeKey];

        if ($search !== '') {
            $query .= ' AND (title LIKE ? OR slug LIKE ? OR body LIKE ?) ';
            $like = '%' . $search . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

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

        $viewContext = [
            'items' => $items,
            'types' => ContentType::all(),
            'current_type' => $definition,
            'current_status' => $status ?? 'all',
            'pagination' => $pagination,
            'counts' => $counts,
            'search' => $search,
        ];

        if ($this->respondAjax(
            'admin/content/_container.twig',
            $this->prepareContentAjaxPayload($items, $viewContext),
            $pagination['current_url']
        )) {
            return;
        }

        $this->render('admin/content/index.twig', [
            'items' => $viewContext['items'],
            'types' => $viewContext['types'],
            'current_menu' => $definition['slug'],
            'current_type' => $viewContext['current_type'],
            'all_type_definitions' => ContentType::definitions(),
            'current_status' => $viewContext['current_status'],
            'pagination' => $viewContext['pagination'],
            'counts' => $viewContext['counts'],
            'search' => $viewContext['search'],
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
        $context['search'] = $context['search'] ?? '';

        return $context;
    }

    private function contentStatusCounts(string $typeKey): array
    {
        $counts = [
            'published' => (int) R::count('content', ' type = ? AND status = ? AND deleted_at IS NULL ', [$typeKey, 'published']),
            'draft' => (int) R::count('content', ' type = ? AND status = ? AND deleted_at IS NULL ', [$typeKey, 'draft']),
            'trash' => (int) R::count('content', ' type = ? AND deleted_at IS NOT NULL ', [$typeKey]),
        ];

        $counts['all'] = (int) R::count('content', ' type = ? AND deleted_at IS NULL ', [$typeKey]);

        return $counts;
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
            'excerpt' => $content->excerpt,
            'thumbnail_id' => $content->thumbnail_id ? (int) $content->thumbnail_id : null,
            'media_ids' => $this->loadMediaIdsForContent((int) $content->id),
            'schema_type' => $content->schema_type ?: $this->defaultSchemaType(),
            'publish_at' => $content->publish_at,
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

        [$typeKey] = $this->resolveType($slug);
        $allowedTermTypes = $this->allowedTermTypes($typeKey);

        $this->render('admin/content/form.twig', [
            'values' => [
                'title' => '',
                'slug'  => '',
                'excerpt' => '',
                'body'  => '',
                'status' => 'draft',
                'thumbnail_id' => '',
                'media_ids' => [],
                'publish_at' => date('Y-m-d H:i:s'),
                'schema_type' => $this->defaultSchemaType(),
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
            'shortcodes' => Shortcode::definitions(),
            'meta_keys' => Meta::allKeys(),
            'meta_values' => [],
            'schema_types' => $this->schemaTypes(),
        ]);
    }

    public function create($slug)
    {
        Auth::requireRole(['admin', 'editor']);

        [$typeKey] = $this->resolveType($slug);
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
                'shortcodes' => Shortcode::definitions(),
                'meta_keys' => Meta::allKeys(),
                'meta_values' => $data['meta'],
                'schema_types' => $this->schemaTypes(),
            ]);
            return;
        }

        $bean = R::dispense('content');
        $bean->title = $data['title'];
        $bean->slug = $data['slug'];
        $bean->type = $data['type'];
        $bean->status = $data['status'] ?: 'draft';
        $bean->allow_comments = $data['allow_comments'];
        $bean->excerpt = $data['excerpt'];
        $bean->body = $data['body'];
        $bean->thumbnail_id = $data['thumbnail_id'] ?: null;
        $bean->thumbnail_alt = null;
        $bean->schema_type = $data['schema_type'] ?: $this->defaultSchemaType();
        $bean->publish_at = $data['publish_at'] ?? date('Y-m-d H:i:s');
        $currentUser = Auth::user();
        $bean->author_id = $currentUser ? (int) $currentUser->id : null;
        $bean->created_at = date('Y-m-d H:i:s');
        $bean->updated_at = date('Y-m-d H:i:s');
        R::store($bean);

        $this->syncTerms((int) $bean->id, $data['terms']);
        $this->syncMediaAttachments((int) $bean->id, $data['media_ids'], $data['thumbnail_id'] ?: null);
        Meta::saveValues(Meta::TARGET_CONTENT, (int) $bean->id, $data['meta']);

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
        $metaValues = Meta::valuesFor(Meta::TARGET_CONTENT, (int) $content->id);

        $this->render('admin/content/form.twig', [
            'values' => [
                'title' => $content->title,
                'slug'  => $content->slug,
                'excerpt' => $content->excerpt,
                'body'  => $content->body,
                'status' => $content->status,
                'allow_comments' => (string) $content->allow_comments,
                'thumbnail_id' => $content->thumbnail_id,
                'media_ids' => $this->loadMediaIdsForContent((int) $content->id),
                'publish_at' => $content->publish_at ?: $content->created_at,
                'schema_type' => $content->schema_type ?: $this->defaultSchemaType(),
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
            'shortcodes' => Shortcode::definitions(),
            'meta_keys' => Meta::allKeys(),
            'meta_values' => $metaValues,
            'schema_types' => $this->schemaTypes(),
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
                'shortcodes' => Shortcode::definitions(),
                'meta_keys' => Meta::allKeys(),
                'meta_values' => $data['meta'],
                'schema_types' => $this->schemaTypes(),
            ]);
            return;
        }

        $content->title = $data['title'];
        $content->slug = $data['slug'];
        $content->type = $data['type'];
        $content->status = $data['status'] ?: 'draft';
        $content->allow_comments = $data['allow_comments'];
        $content->excerpt = $data['excerpt'];
        $content->body = $data['body'];
        $content->thumbnail_id = $data['thumbnail_id'] ?: null;
        $content->thumbnail_alt = null;
        $content->schema_type = $data['schema_type'] ?: $this->defaultSchemaType();
        $content->publish_at = $data['publish_at'] ?? date('Y-m-d H:i:s');
        $currentUser = Auth::user();
        $content->author_id = $content->author_id ?: ($currentUser ? (int) $currentUser->id : null);
        $content->updated_at = date('Y-m-d H:i:s');
        R::store($content);

        $this->syncTerms((int) $content->id, $data['terms']);
        $this->syncMediaAttachments((int) $content->id, $data['media_ids'], $data['thumbnail_id'] ?: null);
        Meta::saveValues(Meta::TARGET_CONTENT, (int) $content->id, $data['meta']);

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
            Meta::deleteForTarget(Meta::TARGET_CONTENT, (int) $content->id);
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

    public function bulkDelete($slug)
    {
        Auth::requireRole(['admin', 'editor']);

        [$typeKey] = $this->resolveType($slug);
        $ids = array_values(array_unique(array_map('intval', $_POST['ids'] ?? [])));
        $redirect = $_POST['redirect'] ?? null;

        if (!$ids) {
            Flash::addError('Vyberte alespoň jeden obsah pro hromadné mazání.');
            header('Location: ' . $this->redirectToList($typeKey, $redirect));
            exit;
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $items = $placeholders
            ? R::findAll('content', ' type = ? AND id IN (' . $placeholders . ') ', array_merge([$typeKey], $ids))
            : [];

        $trashed = 0;
        $deleted = 0;

        foreach ($items as $item) {
            if ($item->deleted_at !== null) {
                $this->forceDeleteContent($item);
                $deleted++;
                continue;
            }

            Meta::deleteForTarget(Meta::TARGET_CONTENT, (int) $item->id);
            $item->deleted_at = date('Y-m-d H:i:s');
            R::store($item);
            $trashed++;
        }

        $message = 'Vybrané položky byly zpracovány.';
        if ($deleted > 0 && $trashed > 0) {
            $message = 'Vybrané položky byly přesunuty do koše nebo nenávratně smazány.';
        } elseif ($deleted > 0) {
            $message = 'Vybrané položky byly nenávratně smazány.';
        } elseif ($trashed > 0) {
            $message = 'Vybrané položky byly přesunuty do koše.';
        }

        if ($this->wantsJson()) {
            $this->respondApi([], $message, 200, [
                'redirect_to' => $this->redirectToList($typeKey, $redirect),
            ]);
        }

        Flash::addSuccess($message);
        header('Location: ' . $this->redirectToList($typeKey, $redirect));
        exit;
    }

    public function restore($slug, $id)
    {
        Auth::requireRole(['admin', 'editor']);

        [$typeKey, $definition] = $this->resolveType($slug);

        $content = $this->findContent($id, true);
        if (!$content || $content->type !== $typeKey || $content->deleted_at === null) {
            if ($this->wantsJson()) {
                $this->jsonError('Obsah nebyl nalezen nebo není v koši.', 404);
            }

            Flash::addError('Obsah nebyl nalezen nebo není v koši.');
            header('Location: /admin/content/' . $definition['slug'] . '?status=trash');
            exit;
        }

        $content->deleted_at = null;
        R::store($content);

        $redirect = $_POST['redirect'] ?? null;

        if ($this->wantsJson()) {
            $this->respondApi([], 'Obsah byl obnoven.', 200, [
                'redirect_to' => $this->redirectToList($content->type, $redirect),
            ]);
        }

        Flash::addSuccess('Obsah byl obnoven.');
        header('Location: ' . $this->redirectToList($content->type, $redirect));
        exit;
    }

    public function emptyTrash($slug): void
    {
        Auth::requireRole(['admin', 'editor']);

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

    private function schemaTypes(): array
    {
        return self::SCHEMA_TYPES;
    }

    private function defaultSchemaType(): string
    {
        return array_key_first(self::SCHEMA_TYPES);
    }

    private function sanitizeInput(string $type): array
    {
        return [
            'title' => trim($_POST['title'] ?? ''),
            'slug'  => trim($_POST['slug'] ?? ''),
            'type'  => $type,
            'status' => trim($_POST['status'] ?? 'draft'),
            'excerpt' => trim(strip_tags($_POST['excerpt'] ?? '')),
            'body'  => trim($_POST['body'] ?? ''),
            'thumbnail_id' => (int) ($_POST['thumbnail_id'] ?? 0),
            'media_ids' => $this->filterIds($_POST['media_ids'] ?? []),
            'terms' => $this->extractTermIds($_POST['terms'] ?? []),
            'allow_comments' => isset($_POST['allow_comments']) ? '1' : '0',
            'publish_at' => trim($_POST['publish_at'] ?? ''),
            'schema_type' => trim($_POST['schema_type'] ?? ''),
            'meta' => Meta::sanitizeValues($_POST['meta'] ?? []),
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

        if ($data['excerpt'] !== '' && mb_strlen($data['excerpt']) > 500) {
            $errors['excerpt'] = 'Perex může mít maximálně 500 znaků.';
        }

        if ($data['schema_type'] === '') {
            $data['schema_type'] = $this->defaultSchemaType();
        }

        if (!array_key_exists($data['schema_type'], $this->schemaTypes())) {
            $errors['schema_type'] = 'Vyber platný typ obsahu pro strukturovaná data.';
        }

        if (!empty($data['terms'])) {
            $invalid = $this->validateTerms($data['terms'], $data['type']);
            if ($invalid !== null) {
                $errors['terms'] = $invalid;
            }
        }

        $data['publish_at'] = $this->normalizePublishAt($data['publish_at']);
        if ($data['publish_at'] === null) {
            $errors['publish_at'] = 'Zadej platný datum a čas publikace.';
        }

        return $errors;
    }

    private function normalizePublishAt(string $input): ?string
    {
        $value = $input !== '' ? $input : date('Y-m-d H:i:s');

        $dateTime = DateTime::createFromFormat('Y-m-d H:i', str_replace('T', ' ', substr($value, 0, 16)));
        if ($dateTime === false) {
            $dateTime = DateTime::createFromFormat('Y-m-d H:i:s', str_replace('T', ' ', $value));
        }

        if ($dateTime === false) {
            return null;
        }

        return $dateTime->format('Y-m-d H:i:s');
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

    private function forceDeleteContent($content): void
    {
        Meta::deleteForTarget(Meta::TARGET_CONTENT, (int) $content->id);
        R::exec('DELETE FROM content_term WHERE content_id = ?', [(int) $content->id]);
        R::exec('DELETE FROM content_media WHERE content_id = ?', [(int) $content->id]);
        Comment::deleteForContent((int) $content->id);
        R::trash($content);
    }
}
