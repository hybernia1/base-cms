<?php
namespace App\Controller\Admin;

use App\Service\Auth;
use App\Service\ContentType;
use App\Service\Flash;
use RedBeanPHP\R as R;
use App\Service\Upload;

class ContentController extends BaseAdminController
{
    public function index($slug)
    {
        Auth::requireRole(['admin', 'editor']);

        [$typeKey, $definition] = $this->resolveType($slug);

        $items = R::findAll('content', ' type = ? ORDER BY updated_at DESC ', [$typeKey]);

        $this->render('admin/content/index.twig', [
            'items' => $items,
            'types' => ContentType::all(),
            'current_menu' => $definition['slug'],
            'current_type' => $definition,
            'all_type_definitions' => ContentType::definitions(),
        ]);
    }

    public function createForm($slug)
    {
        Auth::requireRole(['admin', 'editor']);

        [$typeKey, $definition] = $this->resolveType($slug);

        $this->render('admin/content/form.twig', [
            'types' => ContentType::all(),
            'values' => [
                'title' => '',
                'slug'  => '',
                'type'  => $typeKey,
                'body'  => '',
                'thumbnail_id' => '',
                'thumbnail_alt' => '',
            ],
            'errors' => [],
            'heading' => 'Nový obsah',
            'form_action' => '/admin/content/' . $definition['slug'] . '/create',
            'current_menu' => $definition['slug'],
            'media' => $this->mediaList(),
            'current_type' => $definition,
        ]);
    }

    public function create($slug)
    {
        Auth::requireRole(['admin', 'editor']);

        [$typeKey, $definition] = $this->resolveType($slug);
        $data = $this->sanitizeInput();
        if ($data['type'] === '') {
            $data['type'] = $typeKey;
        }

        [$data, $uploadError] = $this->handleThumbnailUpload($data);
        $errors = $this->validate($data);
        if ($uploadError) {
            $errors['thumbnail'] = $uploadError;
        }

        if ($errors) {
            $this->render('admin/content/form.twig', [
                'types' => ContentType::all(),
                'values' => $data,
                'errors' => $errors,
                'heading' => 'Nový obsah',
                'form_action' => '/admin/content/' . $definition['slug'] . '/create',
                'current_menu' => $definition['slug'],
                'current_type' => $definition,
            ]);
            return;
        }

        $bean = R::dispense('content');
        $bean->title = $data['title'];
        $bean->slug = $data['slug'];
        $bean->type = $data['type'];
        $bean->body = $data['body'];
        $bean->thumbnail_id = $data['thumbnail_id'] ?: null;
        $bean->thumbnail_alt = $data['thumbnail_alt'];
        $bean->created_at = date('Y-m-d H:i:s');
        $bean->updated_at = date('Y-m-d H:i:s');
        R::store($bean);

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

        $this->render('admin/content/form.twig', [
            'types' => ContentType::all(),
            'values' => [
                'title' => $content->title,
                'slug'  => $content->slug,
                'type'  => $content->type,
                'body'  => $content->body,
                'thumbnail_id' => $content->thumbnail_id,
                'thumbnail_alt' => $content->thumbnail_alt,
            ],
            'errors' => [],
            'heading' => 'Upravit obsah',
            'form_action' => "/admin/content/{$menuSlug}/{$content->id}/edit",
            'current_menu' => $menuSlug,
            'content_id' => $content->id,
            'media' => $this->mediaList(),
            'current_type' => $definition,
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

        $data = $this->sanitizeInput();
        [$data, $uploadError] = $this->handleThumbnailUpload($data);
        $errors = $this->validate($data, (int) $content->id);
        if ($uploadError) {
            $errors['thumbnail'] = $uploadError;
        }

        if ($errors) {
            $this->render('admin/content/form.twig', [
                'types' => ContentType::all(),
                'values' => $data,
                'errors' => $errors,
                'heading' => 'Upravit obsah',
                'form_action' => "/admin/content/{$menuSlug}/{$content->id}/edit",
                'current_menu' => $menuSlug,
                'content_id' => $content->id,
                'current_type' => $definition,
            ]);
            return;
        }

        $content->title = $data['title'];
        $content->slug = $data['slug'];
        $content->type = $data['type'];
        $content->body = $data['body'];
        $content->thumbnail_id = $data['thumbnail_id'] ?: null;
        $content->thumbnail_alt = $data['thumbnail_alt'];
        $content->updated_at = date('Y-m-d H:i:s');
        R::store($content);

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

        R::trash($content);
        Flash::addSuccess('Obsah byl smazán.');
        header('Location: /admin/content/' . ContentType::slug($content->type));
        exit;
    }

    private function sanitizeInput(): array
    {
        return [
            'title' => trim($_POST['title'] ?? ''),
            'slug'  => trim($_POST['slug'] ?? ''),
            'type'  => trim($_POST['type'] ?? ''),
            'body'  => trim($_POST['body'] ?? ''),
            'thumbnail_id' => (int) ($_POST['thumbnail_id'] ?? 0),
            'thumbnail_alt' => trim($_POST['thumbnail_alt'] ?? ''),
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
        $media->alt = $data['thumbnail_alt'];
        R::store($media);

        return [$data, null];
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
}
