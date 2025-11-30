<?php
namespace App\Controller\Admin;

use App\Service\Auth;
use App\Service\Flash;
use App\Service\Upload;
use RedBeanPHP\R as R;

class MediaController extends BaseAdminController
{
    public function index()
    {
        Auth::requireRole(['admin', 'editor']);
        $items = R::findAll('media', ' ORDER BY created_at DESC LIMIT 200 ');

        $this->render('admin/media/index.twig', [
            'items' => $items,
            'current_menu' => 'media',
        ]);
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

        Flash::addSuccess('Soubor byl smazán.');
        header('Location: /admin/media');
        exit;
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

    private function wantsJson(): bool
    {
        return (
            (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') ||
            (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false)
        );
    }
}
