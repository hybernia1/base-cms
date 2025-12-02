<?php
namespace App\Controller\Admin;

use App\Service\Auth;
use App\Service\ContentType;
use RedBeanPHP\R as R;

class SearchController extends BaseAdminController
{
    private const RESULT_LIMIT = 8;

    public function index(): void
    {
        Auth::requireRole(['admin', 'editor']);

        $query = trim((string) ($_GET['q'] ?? ''));
        $likeQuery = '%' . $query . '%';

        $contents = [];
        $terms = [];
        $media = [];
        $files = [];
        $comments = [];

        if ($query !== '') {
            $contents = R::findAll(
                'content',
                ' (title LIKE ? OR slug LIKE ?) AND deleted_at IS NULL ORDER BY updated_at DESC LIMIT ? ',
                [$likeQuery, $likeQuery, self::RESULT_LIMIT]
            );

            $terms = R::findAll(
                'term',
                ' name LIKE ? OR slug LIKE ? ORDER BY updated_at DESC LIMIT ? ',
                [$likeQuery, $likeQuery, self::RESULT_LIMIT]
            );

            $media = R::findAll(
                'media',
                ' is_image = 1 AND (original_name LIKE ? OR filename LIKE ?) ORDER BY updated_at DESC LIMIT ? ',
                [$likeQuery, $likeQuery, self::RESULT_LIMIT]
            );

            $files = R::findAll(
                'media',
                ' is_image = 0 AND (original_name LIKE ? OR filename LIKE ?) ORDER BY updated_at DESC LIMIT ? ',
                [$likeQuery, $likeQuery, self::RESULT_LIMIT]
            );

            $comments = R::findAll(
                'comment',
                ' (body LIKE ? OR author_name LIKE ? OR author_email LIKE ?) AND deleted_at IS NULL '
                . ' ORDER BY created_at DESC LIMIT ? ',
                [$likeQuery, $likeQuery, $likeQuery, self::RESULT_LIMIT]
            );
        }

        $this->render('admin/search/index.twig', [
            'query' => $query,
            'contents' => $this->serializeContents($contents),
            'terms' => $this->serializeTerms($terms),
            'media' => $this->serializeMedia($media),
            'files' => $this->serializeMedia($files),
            'comments' => $this->serializeComments($comments),
            'current_menu' => 'search',
        ]);
    }

    private function serializeContents(array $items): array
    {
        $definitions = ContentType::definitions();

        $serialized = [];
        foreach ($items as $item) {
            $type = $item->type ?? '';
            $definition = $definitions[$type] ?? null;
            $slug = $definition['slug'] ?? ContentType::slug($type);

            $serialized[] = [
                'id' => (int) $item->id,
                'title' => (string) ($item->title ?: 'Bez názvu'),
                'slug' => (string) $item->slug,
                'status' => (string) $item->status,
                'type' => $type,
                'type_label' => $definition['name'] ?? $type,
                'type_slug' => $slug,
                'updated_at' => $item->updated_at,
            ];
        }

        return $serialized;
    }

    private function serializeTerms(array $items): array
    {
        $serialized = [];
        foreach ($items as $item) {
            $serialized[] = [
                'id' => (int) $item->id,
                'name' => (string) $item->name,
                'slug' => (string) $item->slug,
                'type' => (string) $item->type,
                'updated_at' => $item->updated_at,
            ];
        }

        return $serialized;
    }

    private function serializeMedia(array $items): array
    {
        $serialized = [];
        foreach ($items as $item) {
            $serialized[] = [
                'id' => (int) $item->id,
                'path' => (string) $item->path,
                'filename' => (string) $item->filename,
                'webp_filename' => (string) $item->webp_filename,
                'original_name' => (string) ($item->original_name ?: $item->filename),
                'mime_type' => (string) $item->mime_type,
                'size' => (int) $item->size,
                'is_image' => (bool) $item->is_image,
                'updated_at' => $item->updated_at,
            ];
        }

        return $serialized;
    }

    private function serializeComments(array $items): array
    {
        $serialized = [];
        foreach ($items as $item) {
            $serialized[] = [
                'id' => (int) $item->id,
                'author_name' => (string) ($item->author_name ?: 'Anonym'),
                'author_email' => (string) $item->author_email,
                'body' => (string) $item->body,
                'status' => (string) $item->status,
                'content_id' => (int) $item->content_id,
                'created_at' => $item->created_at,
            ];
        }

        return $serialized;
    }
}
