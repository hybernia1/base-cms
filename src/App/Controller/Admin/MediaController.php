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
        Auth::requireRole(['admin', 'editor']);

        $total = R::count('media');
        $pagination = $this->buildPagination((int) $total, 18);

        $items = R::findAll(
            'media',
            ' ORDER BY created_at DESC LIMIT ? OFFSET ? ',
            [$pagination['per_page'], $pagination['offset']]
        );

        if ($this->respondAjax('admin/media/_list.twig', $this->prepareMediaAjaxPayload($items, [
            'pagination' => $pagination,
        ]), $pagination['current_url'])) {
            return;
        }

        $this->render('admin/media/index.twig', [
            'items' => $items,
            'current_menu' => 'media',
            'pagination' => $pagination,
        ]);
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

    public function upload()
    {
        Auth::requireRole(['admin', 'editor']);

        if (!isset($_FILES['file'])) {
            return $this->handleUploadError('Nebyl vybrán žádný soubor.');
        }

        [$media, $error] = Upload::handle($_FILES['file']);
        if ($error) {
            return $this->handleUploadError($error);
        }

        $media->alt = trim($_POST['alt'] ?? '');
        R::store($media);

        if ($this->wantsJson()) {
            header('Content-Type: application/json');
            echo json_encode([
                'id' => $media->id,
                'path' => '/' . $media->path . '/' . ($media->webp_filename ?: $media->filename),
                'is_image' => (bool) $media->is_image,
                'original_name' => $media->original_name ?: $media->filename,
            ]);
            exit;
        }

        Flash::addSuccess('Soubor byl nahrán.');
        header('Location: /admin/media');
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

        return $this->jsonResponse([
            'id' => (int) $media->id,
            'alt' => $media->alt,
            'webp_filename' => $media->webp_filename,
            'filename' => $media->filename,
            'path' => '/' . ltrim($media->path, '/'),
            'preview_url' => '/' . ltrim($media->path, '/') . '/' . ($media->webp_filename ?: $media->filename),
            'message' => $messages ? implode(' ', $messages) : 'Změny byly uloženy.',
        ]);
    }

    public function delete($id)
    {
        Auth::requireRole(['admin', 'editor']);

        $media = R::load('media', (int) $id);
        if (!$media || !$media->id) {
            Flash::addError('Soubor nebyl nalezen.');
            header('Location: /admin/media');
            exit;
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
        R::trash($media);

        if ($this->wantsJson()) {
            $this->respondAjaxMessage('Soubor byl smazán.', ['success' => true]);
        }

        Flash::addSuccess('Soubor byl smazán.');
        header('Location: /admin/media');
        exit;
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

    private function handleUploadError(string $message)
    {
        if ($this->wantsJson()) {
            header('Content-Type: application/json', true, 400);
            echo json_encode(['error' => $message]);
            exit;
        }

        Flash::addError($message);
        header('Location: /admin/media');
        exit;
    }

}
