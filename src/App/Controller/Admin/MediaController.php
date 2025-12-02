<?php
namespace App\Controller\Admin;

use App\Service\Auth;
use App\Service\Flash;
use App\Service\Setting;
use App\Service\Upload;
use RedBeanPHP\R as R;

class MediaController extends AjaxController
{
    public function index()
    {
        header('Location: /admin/media/gallery');
        exit;
    }

    public function gallery()
    {
        $this->renderMediaPage(true, 'admin/media/gallery.twig', 'admin/media/_gallery_list.twig', 'media:gallery');
    }

    public function files()
    {
        $this->renderMediaPage(false, 'admin/media/files.twig', 'admin/media/_files_list.twig', 'media:files');
    }

    private function prepareMediaAjaxPayload(array $items, array $context): array
    {
        $serializedItems = [];
        foreach ($items as $item) {
            $serializedItems[] = [
                'id' => (int) $item->id,
                'path' => $item->path,
                'filename' => $item->filename,
                'webp_filename' => $item->webp_filename,
                'original_name' => $item->original_name,
                'mime_type' => $item->mime_type,
                'size' => (int) $item->size,
                'is_image' => (bool) $item->is_image,
                'alt' => $item->alt,
                'created_at' => $item->created_at,
                'updated_at' => $item->updated_at,
            ];
        }

        $context['items'] = $serializedItems;

        return $context;
    }

    private function renderMediaPage(bool $onlyImages, string $template, string $partial, string $currentMenu): void
    {
        Auth::requireRole(['admin', 'editor']);

        $condition = $onlyImages ? ' is_image = 1 ' : ' is_image = 0 ';
        $total = R::count('media', $condition);
        $pagination = $this->buildPagination((int) $total, 18);

        $items = R::findAll(
            'media',
            $condition . ' ORDER BY created_at DESC LIMIT ? OFFSET ? ',
            [$pagination['per_page'], $pagination['offset']]
        );

        $payload = $this->prepareMediaAjaxPayload($items, [
            'pagination' => $pagination,
        ]);

        if ($this->respondAjax($partial, $payload, $pagination['current_url'])) {
            return;
        }

        $this->render($template, array_merge($payload, [
            'current_menu' => $currentMenu,
        ]));
    }

    public function upload()
    {
        Auth::requireRole(['admin', 'editor']);

        if (!isset($_FILES['file'])) {
            return $this->handleUploadError('Nebyl vybrán žádný soubor.');
        }

        $currentUser = Auth::user();
        [$media, $error] = Upload::handle(
            $_FILES['file'],
            'auto',
            $currentUser ? (int) $currentUser->id : null
        );
        if ($error) {
            return $this->handleUploadError($error);
        }

        $media->alt = trim($_POST['alt'] ?? '');
        R::store($media);

        if ($this->wantsJson()) {
            $this->respondApi([
                'id' => (int) $media->id,
                'path' => '/' . $media->path . '/' . ($media->webp_filename ?: $media->filename),
                'is_image' => (bool) $media->is_image,
                'original_name' => $media->original_name ?: $media->filename,
            ], 'Soubor byl nahrán.', 201);
        }

        Flash::addSuccess('Soubor byl nahrán.');
        $redirectTarget = $media->is_image ? '/admin/media/gallery' : '/admin/media/files';
        header('Location: ' . $redirectTarget);
        exit;
    }

    public function update($id)
    {
        Auth::requireRole(['admin', 'editor']);

        $media = R::load('media', (int) $id);
        if (!$media || !$media->id) {
            return $this->jsonError('Soubor nebyl nalezen.', 404);
        }

        $alt = trim($_POST['alt'] ?? '');
        $convertToWebp = isset($_POST['convert_webp']) && $_POST['convert_webp'] === '1';

        $messages = [];

        if ($media->alt !== $alt) {
            $media->alt = $alt;
            $messages[] = 'Alt text byl aktualizován.';
        }

        if ($convertToWebp) {
            if ($media->webp_filename) {
                $messages[] = 'WebP varianta už existuje.';
            } else {
                [$webpFilename, $error] = $this->createWebpVariant($media);
                if ($error) {
                    return $this->jsonError($error);
                }

                if ($webpFilename) {
                    $media->webp_filename = $webpFilename;
                    $messages[] = 'WebP varianta byla vytvořena.';
                }
            }
        }

        $media->updated_at = date('Y-m-d H:i:s');
        R::store($media);

        return $this->respondApi([
            'id' => (int) $media->id,
            'alt' => $media->alt,
            'webp_filename' => $media->webp_filename,
            'filename' => $media->filename,
            'path' => '/' . ltrim($media->path, '/'),
            'preview_url' => '/' . ltrim($media->path, '/') . '/' . ($media->webp_filename ?: $media->filename),
        ], $messages ? implode(' ', $messages) : 'Změny byly uloženy.');
    }

    public function delete($id)
    {
        Auth::requireRole(['admin', 'editor']);

        $media = R::load('media', (int) $id);
        if (!$media || !$media->id) {
            if ($this->wantsJson()) {
                $this->jsonError('Soubor nebyl nalezen.', 404);
            }

            Flash::addError('Soubor nebyl nalezen.');
            $this->redirectToMedia();
        }

        $absoluteBase = rtrim(Upload::baseUploadPath(), '/');
        $relative = $media->path ? substr($media->path, strlen('uploads')) : '';
        $directory = $absoluteBase . $relative;

        $paths = [
            $directory . '/' . $media->filename,
        ];

        if ($media->webp_filename) {
            $paths[] = $directory . '/' . $media->webp_filename;
        }

        foreach ($paths as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }

        R::exec('UPDATE content SET thumbnail_id = NULL WHERE thumbnail_id = ?', [$media->id]);
        R::exec('DELETE FROM content_media WHERE media_id = ?', [$media->id]);
        R::trash($media);

        if ($this->wantsJson()) {
            $this->respondApi([], 'Soubor byl smazán.');
        }

        Flash::addSuccess('Soubor byl smazán.');
        $this->redirectToMedia((bool) $media->is_image);
    }

    private function createWebpVariant($media): array
    {
        if (!$media->is_image) {
            return [null, 'Soubor není obrázek.'];
        }

        if (Setting::get('allow_webp') !== '1') {
            return [null, 'WebP konverze je v nastavení vypnutá.'];
        }

        $directory = $this->mediaDirectory($media);
        $sourcePath = rtrim($directory, '/') . '/' . $media->filename;

        if (!is_file($sourcePath)) {
            return [null, 'Zdrojový soubor nebyl nalezen.'];
        }

        $mime = strtolower((string) $media->mime_type);
        $image = match (true) {
            str_contains($mime, 'jpeg') => imagecreatefromjpeg($sourcePath),
            str_contains($mime, 'png') => imagecreatefrompng($sourcePath),
            str_contains($mime, 'gif') => imagecreatefromgif($sourcePath),
            str_contains($mime, 'webp') => imagecreatefromwebp($sourcePath),
            default => @imagecreatefromstring(file_get_contents($sourcePath)),
        };

        if (!$image) {
            return [null, 'Konverze do WebP se nezdařila.'];
        }

        imagepalettetotruecolor($image);
        imagealphablending($image, true);
        imagesavealpha($image, true);

        $baseName = pathinfo($media->filename, PATHINFO_FILENAME);
        $targetName = $baseName . '.webp';
        $targetPath = rtrim($directory, '/') . '/' . $targetName;
        $counter = 1;

        while (is_file($targetPath)) {
            $targetName = $baseName . '-' . $counter . '.webp';
            $targetPath = rtrim($directory, '/') . '/' . $targetName;
            $counter++;
        }

        $saved = imagewebp($image, $targetPath, 85);
        imagedestroy($image);

        if (!$saved) {
            return [null, 'Konverze do WebP se nezdařila.'];
        }

        return [$targetName, null];
    }

    private function mediaDirectory($media): string
    {
        $absoluteBase = rtrim(Upload::baseUploadPath(), '/');
        $relative = $media->path ? substr($media->path, strlen('uploads')) : '';

        return rtrim($absoluteBase . $relative, '/');
    }

    private function redirectToMedia(?bool $isImage = null): void
    {
        $referer = $_SERVER['HTTP_REFERER'] ?? '';

        if ($referer) {
            if (str_contains($referer, '/admin/media/files')) {
                header('Location: /admin/media/files');
                exit;
            }

            if (str_contains($referer, '/admin/media/gallery')) {
                header('Location: /admin/media/gallery');
                exit;
            }
        }

        if ($isImage === false) {
            header('Location: /admin/media/files');
            exit;
        }

        header('Location: /admin/media/gallery');
        exit;
    }

    private function handleUploadError(string $message)
    {
        if ($this->wantsJson()) {
            $this->respondApi([], $message, 400);
        }

        Flash::addError($message);
        $this->redirectToMedia();
    }

}
